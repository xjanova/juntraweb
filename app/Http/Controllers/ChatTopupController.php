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

        // 🧾 (2026-11-06) บิล QR ที่ยังรอจ่ายอยู่ = ห้ามออกใบใหม่ซ้อน
        // บิลซ้อนทำให้ลูกค้าจ่ายใบหนึ่ง แต่อีกใบค้างบล็อกโต๊ะไพ่อยู่ 48 ชม. (และงงว่าต้องจ่ายใบไหน)
        //   - ใบเดิมยอดพอ → ส่ง QR ใบเดิมกลับไป (reused) ลูกค้าเห็นบิลเดียวเสมอ
        //   - ใบเดิมยอดไม่พอ → 409 ให้หน้าแชทถามว่า "จ่ายใบเดิม" หรือ "ยกเลิกใบเดิมแล้วออกใหม่"
        //     ไม่ยกเลิกให้เอง: ลูกค้าอาจโอนใบเดิมไปแล้วแต่ SMS ยังไม่เข้า ยกเลิกเอง = เงินก้อนนั้นหาบิลไม่เจอ
        if (($open = self::pendingBill($user)) !== null) {
            if (self::baseOf($open) + 0.001 >= $base) {
                $account = $this->accountFor($open, $user);
                if (! $account) {
                    return response()->json(['error' => 'ระบบเติมเงินยังไม่พร้อมชั่วคราว กรุณาติดต่อแอดมินค่ะ', 'reason_code' => 'promptpay_unset'], 503);
                }

                return response()->json($this->payload($open, $account) + ['reused' => true]);
            }

            return response()->json([
                'error'       => sprintf('ยังมีบิลเลขที่ %s ยอด ฿%s รอชำระอยู่ค่ะ จ่ายบิลนี้ก่อน หรือยกเลิกบิลนี้แล้วออกบิลใหม่ได้เลยนะคะ', $open->reference_code, number_format((float) $open->amount, 2)),
                'reason_code' => 'pending_bill',
                'bill'        => self::billView($open),
            ], 409);
        }

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
        if (! empty($tx->slip_path)) {
            // แนบสลิปแล้ว = โอนแล้ว รอตรวจ — ห้ามโชว์ QR ให้สแกนซ้ำ (จ่ายสองรอบ)
            return response()->json([
                'error'       => 'บิลนี้แนบสลิปแล้ว กำลังตรวจสอบอยู่ค่ะ ไม่ต้องโอนซ้ำนะคะ',
                'reason_code' => 'has_slip',
            ], 409);
        }
        $account = $this->accountFor($tx, $user);
        if (! $account) {
            return response()->json(['error' => 'ระบบเติมเงินยังไม่พร้อมชั่วคราว กรุณาติดต่อแอดมินค่ะ', 'reason_code' => 'promptpay_unset'], 503);
        }

        return response()->json($this->payload($tx, $account));
    }

    /**
     * 🧾 ยกเลิกบิลที่ยังไม่ได้จ่าย — ทางออกของลูกค้าที่เปลี่ยนใจ/อยากออกยอดใหม่ (เดิมบิลค้างบล็อกโต๊ะไพ่ 48 ชม.)
     * บิลที่แนบสลิปแล้วยกเลิกไม่ได้ (โอนแล้ว รอตรวจ — ยกเลิก = ลบหลักฐานการโอนของลูกค้าเอง)
     * ยอดที่จองไว้กับ Thaiprompt คืนเองผ่าน TopupReservationObserver
     */
    public function cancel(Request $request, WalletTransaction $tx): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $tx->user_id === $user->id, 403);
        if (! empty($tx->slip_path)) {
            return response()->json(['error' => 'บิลนี้แนบสลิปแล้ว กำลังตรวจสอบอยู่ ยกเลิกไม่ได้ค่ะ', 'reason_code' => 'has_slip'], 409);
        }
        if ($tx->type !== 'topup' || $tx->status !== 'pending') {
            return response()->json(['error' => 'บิลนี้ไม่ได้รอชำระแล้วค่ะ', 'status' => $tx->status, 'paid' => $tx->status === 'success'], 410);
        }
        // 💸 SMS ธนาคารยอดตรงบิลนี้เข้ามาแล้วแต่ยังไม่ได้ผูก (เครื่องรับ SMS โหมดรอยืนยัน / มาก่อนรอบจับคู่)
        //    ยกเลิกตอนนี้ = เงินที่โอนแล้วหาบิลไม่เจอ → ไม่ให้ยกเลิก รอระบบ/แอดมินยืนยัน
        if (config('smschecker.enabled') && $tx->method === 'promptpay'
            && app(\App\Services\SmsPayment\SmsCheckerService::class)->claimableSmsFor($tx) !== null) {
            return response()->json([
                'error'       => 'ระบบเห็นยอดโอนตรงกับบิลนี้แล้วค่ะ กำลังยืนยันให้ ไม่ต้องยกเลิกนะคะ รอสักครู่เครดิตจะเข้าเองค่ะ',
                'reason_code' => 'payment_seen',
            ], 409);
        }
        try {
            $this->wallet->cancelTopup($tx, $user);
        } catch (\RuntimeException $e) {
            // SMS ยืนยันบิลนี้ไปพร้อมกันพอดี (ล็อกแถวแล้วสถานะไม่ใช่ pending) — บอกตามจริง
            $fresh = $tx->fresh();

            return response()->json(['error' => $e->getMessage(), 'status' => $fresh?->status, 'paid' => $fresh?->status === 'success'], 410);
        }

        return response()->json([
            'ok'      => true,
            'status'  => 'cancelled',
            'balance' => (float) $this->wallet->balance($user),
            'message' => "ยกเลิกบิลเลขที่ {$tx->reference_code} แล้วค่ะ",
        ]);
    }

    /**
     * บิล QR ที่ยังรอจ่าย (ยังไม่หมดอายุ ยังไม่แนบสลิป) ล่าสุดของลูกค้า หรือ null
     *
     * ไม่นับบิลที่แนบสลิปแล้ว (โอนแล้ว รอแอดมิน — ไม่มีอะไรให้จ่ายต่อ ไม่ควรล็อกโต๊ะไพ่หรือโชว์ QR ซ้ำ)
     * และบิลแบบอื่นจากหน้ากระเป๋าเงิน (โอนก่อนแนบสลิป / manual)
     */
    public static function pendingBill($user): ?WalletTransaction
    {
        return WalletTransaction::where('user_id', $user->id)
            ->where('type', 'topup')
            ->where('status', 'pending')
            ->where('method', 'promptpay')
            ->where(fn ($q) => $q->whereNull('slip_path')->orWhere('slip_path', ''))
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
            'base_amount'    => self::baseOf($tx),
            'has_slip'       => ! empty($tx->slip_path),
            'resume_url'     => route('chat.topup.resume', ['tx' => $tx->id]),
            'cancel_url'     => route('chat.topup.cancel', ['tx' => $tx->id]),
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
            'has_slip' => ! empty($tx->slip_path),
            'expired' => $tx->status === 'pending' && $tx->expires_at !== null && $tx->expires_at->isPast(),
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
            'balance'  => (float) $this->wallet->balance($user),
            'message'  => $result['message'],
        ];
    }

    /** ยอดที่ลูกค้าเลือก (ก่อนเติมเศษสตางค์) — ใช้เทียบว่าบิลเดิมพอสำหรับแพ็กเกจที่กดหรือเปล่า */
    private static function baseOf(WalletTransaction $tx): float
    {
        return (float) ((((array) $tx->meta)['base_amount'] ?? null) ?: $tx->amount);
    }

    /** บัญชีที่ออก QR ของบิลนี้ไว้ (ใบเดิมต้องโชว์บัญชีเดิม) — ไม่มีจดไว้ค่อยถามใหม่ */
    private function accountFor(WalletTransaction $tx, $user): ?array
    {
        $meta = (array) $tx->meta;

        return ! empty($meta['promptpay_id'])
            ? ['promptpay_id' => (string) $meta['promptpay_id'], 'name' => (string) ($meta['promptpay_name'] ?? ''), 'source' => 'bill']
            : PayoutAccount::resolve($user);
    }

    /** @param array{promptpay_id:string,name:string,source:string} $account */
    private function payload(WalletTransaction $tx, array $account): array
    {
        $payable = (float) $tx->amount;
        $base    = self::baseOf($tx);

        return [
            'id'             => $tx->id,
            'reference_code' => $tx->reference_code,
            'base_amount'    => $base,
            'payable'        => $payable,
            'qr'             => PromptPayQr::svgDataUri($account['promptpay_id'], $payable),
            'qr_payload'     => PromptPayQr::payload($account['promptpay_id'], $payable),
            'promptpay_name' => $account['name'],
            'auto_confirm'   => (bool) config('smschecker.enabled'),
            'expires_at'     => optional($tx->expires_at)->toIso8601String(),
            'cancel_url'     => route('chat.topup.cancel', ['tx' => $tx->id]),
        ];
    }
}
