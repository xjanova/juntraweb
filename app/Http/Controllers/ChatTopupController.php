<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateSlipException;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\SmsPayment\AmountReservation;
use App\Services\Wallet\SlipAutoVerifier;
use App\Services\Wallet\WalletService;
use App\Support\ChatPolicy;
use App\Support\PayoutAccount;
use App\Support\Pricing;
use App\Support\PromptPayQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * เติมเงินจบในห้องแชท — ไม่ต้องออกไปหน้าอื่น
 *
 * ทำไมต้องมี: บอทแม่หมอใน Facebook/LINE ให้ลูกค้า "สแกนจ่ายครั้งเดียวจบ"
 * แต่บนเว็บเดิมต้องเดินออกจากแชท → หน้าเติมเงิน → เลือกยอด → จ่าย → รอ →
 * เดินกลับมาเปิดไพ่ใหม่ ซึ่งลูกค้ากลุ่มหลักของเรา (ผู้สูงอายุ) หลุดกลางทาง
 *
 * โฟลว์ใหม่: กดยอดในแชท → QR โผล่ในบับเบิล → สแกนจ่าย → SMS ธนาคารเข้า →
 * ระบบเครดิตให้เอง → บับเบิลเปลี่ยนเป็น "เติมเงินสำเร็จ" พร้อมปุ่มนำทางว่า
 * ใช้บริการอะไรต่อได้บ้าง ทั้งหมดอยู่ในหน้าจอเดียว ไม่มีการพาออกไปไหน
 *
 * ตัวจับเงินเข้าใช้ของเดิมทั้งหมด (unique satang amount + SmsCheckerService)
 * — controller นี้เป็นแค่ "หน้าร้าน" ของกลไกที่ผ่านการ audit มาแล้ว
 */
class ChatTopupController extends Controller
{
    public function __construct(
        private WalletService $wallet,
        private AmountReservation $amounts,
        private FortuneBotClient $bot,
        private SlipAutoVerifier $slips,
    ) {}

    /** สร้างรายการเติมเงิน + QR สำหรับสแกนจ่าย */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'กรุณาเข้าสู่ระบบก่อนเติมเงิน', 'reason_code' => 'guest'], 401);
        }

        $min = (int) config('pricing.min_topup', 20);
        $max = (int) config('pricing.max_topup', 50000);
        $data = $request->validate([
            'amount' => "required|numeric|min:$min|max:$max",
        ]);

        // ใช้บัญชีเดียวกับแม่หมอใน FB/LINE เสมอ (ตกไปใช้ค่าที่ตั้งเองเมื่อ
        // upstream ล่ม) — ถ้าใช้คนละบัญชี ตัวตรวจสลิปจะเห็นว่าปลายทางไม่ใช่
        // บัญชีเราแล้วปฏิเสธทั้งที่ลูกค้าโอนถูก
        $account = PayoutAccount::resolve($user);
        if (! $account) {
            // ตั้งค่าไม่ได้ทั้งสองทาง = สร้าง QR ไม่ได้ ต้องบอกตรง ๆ ไม่ใช่
            // ปล่อยให้ลูกค้าเห็นกล่องว่างแล้วงงว่าต้องโอนไปไหน
            return response()->json([
                'error'       => 'ระบบเติมเงินยังไม่พร้อมชั่วคราว กรุณาติดต่อแอดมินค่ะ',
                'reason_code' => 'promptpay_unset',
            ], 503);
        }

        // ยอดที่ต้องโอนจริงมีเศษสตางค์ไม่ซ้ำใคร (จองข้ามเว็บกับ Thaiprompt) เพื่อให้
        // SMS ธนาคารจับคู่กับรายการนี้ได้แม่นยำโดยไม่ต้องให้แอดมินมานั่งดูสลิป
        // ยอดสุดท้ายอยู่ใน $tx->amount (payload() อ่านจากตรงนั้น) — ห้ามคำนวณเองก่อน
        $base = (float) $data['amount'];

        try {
            $tx = $this->amounts->createPendingTopup($user, $base);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason_code' => 'pending_cap'], 422);
        }

        // จำบัญชีที่ออก QR ไว้กับบิล — รีเฟรชหน้าแล้วออก QR ใบเดิมได้โดยไม่ต้องถาม Thaiprompt ใหม่ (resume)
        $tx->update(['meta' => array_merge((array) $tx->meta, [
            'base_amount'    => $base,
            'source'         => 'chat',
            'promptpay_id'   => $account['promptpay_id'],
            'promptpay_name' => $account['name'],
            'transfer'       => $account['transfer'] ?? null,
        ])]);

        return response()->json($this->payload($tx, $account));
    }

    /**
     * 🧾 (2026-11-05) เปิด QR ของบิลที่ค้างอยู่อีกครั้ง — หน้าแชทเรียกหลังรีเฟรช บิลจะได้ไม่หายจากจอ
     * (เดิมรีเฟรชแล้วบิลหาย ลูกค้ากดเปิดไพ่ใหม่ได้ทั้งที่โอนเงินบิลเก่าไปแล้ว)
     */
    public function resume(Request $request, WalletTransaction $tx): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $tx->user_id === $user->id, 403);
        if ($tx->status !== 'pending' || ($tx->expires_at && $tx->expires_at->isPast())) {
            return response()->json(['error' => 'บิลนี้ไม่ได้รอชำระแล้วค่ะ', 'status' => $tx->status, 'paid' => $tx->status === 'success'], 410);
        }
        $meta = (array) $tx->meta;
        $account = ! empty($meta['promptpay_id'])
            ? ['promptpay_id' => (string) $meta['promptpay_id'], 'name' => (string) ($meta['promptpay_name'] ?? ''), 'source' => 'bill',
                'transfer' => is_array($meta['transfer'] ?? null) ? $meta['transfer'] : null]
            : PayoutAccount::resolve($user);
        // บิลก่อน 2026-11-09 ไม่ได้จดเลขบัญชีไว้ — ยืมบัญชีปัจจุบันมาแสดง เฉพาะเมื่อยังเป็นพร้อมเพย์เดียวกับในบิล
        // (เงินโอนเข้าบัญชีอื่นที่ระบบ SMS ไม่ได้ดู จะไม่ตัดบิล จึงห้ามโชว์บัญชีที่ไม่ใช่ของบิลนี้)
        if ($account && ($account['source'] ?? '') === 'bill' && ! array_key_exists('transfer', $meta)) {
            $current = PayoutAccount::resolve($user);
            if ($current && ($current['promptpay_id'] ?? '') === $account['promptpay_id']) {
                $account['transfer'] = $current['transfer'] ?? null;
            }
        }
        if (! $account) {
            return response()->json(['error' => 'ระบบเติมเงินยังไม่พร้อมชั่วคราว กรุณาติดต่อแอดมินค่ะ', 'reason_code' => 'promptpay_unset'], 503);
        }

        return response()->json($this->payload($tx, $account));
    }

    /** บิลเติมเงินที่ยังรอชำระ (ยังไม่หมดอายุ) ล่าสุดของลูกค้า หรือ null */
    public static function pendingBill($user): ?WalletTransaction
    {
        return WalletTransaction::where('user_id', $user->id)
            ->where('type', 'topup')
            ->where('status', 'pending')
            // รายการจากหลักฐานในแชทเพจ = จ่ายมาแล้ว ไม่ใช่บิลค้างที่ต้องจ่าย
            ->where('method', '!=', WalletTransaction::METHOD_CHAT_SLIP)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();
    }

    /** ข้อมูลบิลค้างสำหรับป้ายบนโต๊ะในห้องพยากรณ์ */
    public static function billView(WalletTransaction $tx): array
    {
        return [
            'id'             => $tx->id,
            'reference_code' => $tx->reference_code,
            'payable'        => (float) $tx->amount,
            'expires_at'     => $tx->expires_at?->getTimestamp(),
            'has_slip'       => ! empty($tx->slip_path),
            'resume_url'     => route('chat.topup.resume', ['tx' => $tx->id]),
        ];
    }

    /** เช็คว่าเงินเข้าหรือยัง — หน้าแชท poll ทุกไม่กี่วินาที */
    public function status(Request $request, WalletTransaction $tx): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $tx->user_id === $user->id, 403);

        return response()->json([
            'id'      => $tx->id,
            'reference_code' => $tx->reference_code,
            'status'  => $tx->status,
            'paid'    => $tx->status === 'success',
            // จ่ายแล้ว = ยอดที่เครดิตจริง (อาจน้อยกว่ายอดบิลแค่เศษสตางค์ เมื่อสลิปโอนยอดกลม)
            'credited' => $tx->status === 'success' ? (float) $tx->amount : null,
            'balance' => (float) $this->wallet->balance($user),
            // ส่งสถานะโควตา/ราคากลับไปด้วย หน้าแชทจะได้ปลดล็อกช่องพิมพ์ทันที
            // ถ้าที่บล็อกอยู่คือเรื่องเครดิตไม่พอ
            'cost'    => ChatPolicy::cost(),
        ]);
    }

    /** แนบสลิปให้ตรวจ — ทางสำรองเมื่อ SMS ไม่เข้า */
    public function slip(Request $request, WalletTransaction $tx): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $tx->user_id === $user->id, 403);

        if ($tx->status !== 'pending') {
            return response()->json(['error' => 'รายการนี้ไม่ได้รอตรวจสอบแล้วค่ะ'], 422);
        }

        $request->validate(['slip' => 'required|image|max:4096']);
        $file = $request->file('slip');

        // สลิปใบเดียวใช้ได้ครั้งเดียว — เช็คก่อนเก็บไฟล์ จะได้ไม่ทิ้งขยะไว้
        $hash = hash_file('sha256', $file->getRealPath());
        try {
            $this->wallet->assertSlipNotReused($hash, $tx->id);
        } catch (DuplicateSlipException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason_code' => 'duplicate_slip'], 422);
        }

        // สลิปมีข้อมูลบัญชี — เก็บบน disk ส่วนตัวเสมอ ไม่ใช่ public
        $path = $file->store('topup-slips', 'local');
        $tx->update(['slip_path' => $path, 'slip_hash' => $hash]);

        // ตรวจสลิปอัตโนมัติด้วย SlipOK ของ Thaiprompt (ตัวเดียวกับบอท FB/LINE)
        // ถ้าตรวจไม่ได้ด้วยเหตุใดก็ตาม → ตกไปให้แอดมินตรวจมือตามเดิม
        // ไม่ปฏิเสธสลิปของลูกค้าเพราะระบบตรวจไม่ทำงาน
        $auto = $this->autoVerifySlip($tx, $user, $path);
        if ($auto !== null) {
            return response()->json($auto);
        }

        return response()->json([
            'ok'      => true,
            'status'  => $tx->fresh()->status,
            'paid'    => false,
            'message' => 'ได้รับสลิปแล้วค่ะ แม่หมอกำลังให้ระบบตรวจสอบให้นะคะ',
        ]);
    }

    /**
     * ตรวจสลิปแล้วเครดิตให้ทันทีถ้าผ่านทุกด่าน
     *
     * กติกาจริงอยู่ใน SlipAutoVerifier เพราะ API มือถือต้องใช้ชุดเดียวกันเป๊ะ
     * (เส้นทางนี้แตะเงิน ถ้ามีสองชุดจะ drift แน่นอน)
     *
     * @return array<string,mixed>|null  null = ตรวจอัตโนมัติไม่ได้ ให้แอดมินดูต่อ
     */
    private function autoVerifySlip(WalletTransaction $tx, $user, string $path): ?array
    {
        $result = $this->slips->verify($tx, $user, $path);
        if ($result === null) {
            return null;
        }

        return [
            'ok'       => true,
            'status'   => $tx->fresh()->status,
            'paid'     => $result['paid'],
            // approve | duplicate | review — หน้าแชทจะได้บอกลูกค้าให้แนบสลิปใบใหม่เมื่อเป็น duplicate
            'decision' => $result['decision'] ?? null,
            // ยอดที่เครดิตจริง (สลิปตัดเศษสตางค์ = ยอดที่โอนจริง ไม่ใช่ยอดบิล)
            'credited' => $result['credited'] ?? null,
            'balance'  => (float) $this->wallet->balance($user),
            'message'  => $result['message'],
        ];
    }

    /** @param array{promptpay_id:string,name:string,source:string} $account */
    private function payload(WalletTransaction $tx, array $account): array
    {
        $payable = (float) $tx->amount;
        $base    = (float) (($tx->meta['base_amount'] ?? null) ?: $payable);

        return [
            'id'             => $tx->id,
            'reference_code' => $tx->reference_code,
            'base_amount'    => $base,
            'payable'        => $payable,
            'qr'             => PromptPayQr::svgDataUri($account['promptpay_id'], $payable),
            'qr_payload'     => PromptPayQr::payload($account['promptpay_id'], $payable),
            'promptpay_name' => $account['name'],
            // 🏦 (2026-11-09) เลขบัญชีให้โอนตรงแทนการสแกน — null = แสดงแค่ QR
            'transfer'       => $account['transfer'] ?? null,
            'auto_confirm'   => \App\Support\TopupAutoApprove::sms(),
            'expires_at'     => optional($tx->expires_at)->toIso8601String(),
        ];
    }
}
