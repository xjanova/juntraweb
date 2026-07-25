<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateSlipException;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\SmsPayment\SmsCheckerService;
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
        private SmsCheckerService $sms,
        private FortuneBotClient $bot,
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

        // ยอดที่ต้องโอนจริงมีเศษสตางค์ไม่ซ้ำใคร เพื่อให้ SMS ธนาคารจับคู่กับ
        // รายการนี้ได้แม่นยำโดยไม่ต้องให้แอดมินมานั่งดูสลิป
        $base    = (float) $data['amount'];
        $payable = config('smschecker.enabled') ? $this->sms->uniqueAmountFor($base) : $base;

        try {
            $tx = $this->wallet->recordPendingTopup($user, $payable, null, 'promptpay');
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason_code' => 'pending_cap'], 422);
        }

        if (abs($payable - $base) > 0.0001) {
            $tx->update(['meta' => array_merge((array) $tx->meta, ['base_amount' => $base, 'source' => 'chat'])]);
        } else {
            $tx->update(['meta' => array_merge((array) $tx->meta, ['source' => 'chat'])]);
        }

        return response()->json($this->payload($tx, $account));
    }

    /** เช็คว่าเงินเข้าหรือยัง — หน้าแชท poll ทุกไม่กี่วินาที */
    public function status(Request $request, WalletTransaction $tx): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $tx->user_id === $user->id, 403);

        return response()->json([
            'id'      => $tx->id,
            'status'  => $tx->status,
            'paid'    => $tx->status === 'success',
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
     * ด่านที่ต้องผ่านครบ (ขาดข้อใดข้อหนึ่ง = ส่งต่อให้แอดมิน ไม่เครดิตเอง):
     *   1. SlipOK อ่านสลิปออกและยืนยันว่าเป็นรายการจริง
     *   2. ปลายทางเป็นบัญชีของร้านเรา — ไม่งั้นสลิปที่โอนให้คนอื่นก็ผ่านได้
     *   3. ยอดในสลิป ≥ ยอดที่ต้องโอน (เผื่อคลาด 1 สตางค์จากการปัดเศษ)
     *   4. เลขอ้างอิงธนาคารนี้ยังไม่เคยถูกใช้เครดิตรายการไหน — กันเอาสลิปใบเดิม
     *      (ถ่ายใหม่/ครอปใหม่ ซึ่ง hash ไม่ซ้ำ) มาเคลมสองรอบ
     *
     * @return array<string,mixed>|null  null = ตรวจอัตโนมัติไม่ได้ ให้แอดมินดูต่อ
     */
    private function autoVerifySlip(WalletTransaction $tx, $user, string $path): ?array
    {
        $abs = Storage::disk('local')->path($path);
        $verify = $this->bot->verifySlip($user, $abs);
        if (! is_array($verify)) {
            return null;
        }

        $reject = function (string $msg) use ($tx): array {
            return ['ok' => true, 'status' => $tx->fresh()->status, 'paid' => false, 'message' => $msg];
        };

        if (empty($verify['ok'])) {
            return $reject('ระบบอ่านสลิปนี้ไม่ออกค่ะ แม่หมอส่งให้แอดมินตรวจให้แล้วนะคะ');
        }
        if (empty($verify['receiver_matches'])) {
            return $reject('สลิปนี้ไม่ได้โอนเข้าบัญชีของร้านค่ะ แอดมินจะตรวจสอบให้อีกครั้งนะคะ');
        }

        $slipAmount = (float) ($verify['amount'] ?? 0);
        $payable    = (float) $tx->amount;
        if ($slipAmount + 0.01 < $payable) {
            return $reject(sprintf(
                'ยอดในสลิป %s น้อยกว่ายอดที่ต้องโอน %s ค่ะ แอดมินจะตรวจสอบให้นะคะ',
                Pricing::format($slipAmount),
                Pricing::format($payable),
            ));
        }

        $transRef = trim((string) ($verify['trans_ref'] ?? ''));
        if ($transRef === '') {
            return $reject('สลิปนี้ไม่มีเลขอ้างอิงธนาคารค่ะ แอดมินจะตรวจสอบให้นะคะ');
        }
        $used = WalletTransaction::where('bank_reference', $transRef)
            ->where('id', '!=', $tx->id)
            ->exists();
        if ($used) {
            return $reject('สลิปนี้ถูกใช้ยืนยันรายการอื่นไปแล้วค่ะ');
        }

        $tx->update(['bank_reference' => $transRef, 'slip_amount' => $slipAmount]);
        $this->wallet->confirmTopupAuto($tx, [
            'source'       => 'slipok',
            'trans_ref'    => $transRef,
            'slip_amount'  => $slipAmount,
            'sender_name'  => $verify['sender_name'] ?? null,
        ]);

        return [
            'ok'      => true,
            'status'  => 'success',
            'paid'    => true,
            'balance' => (float) $this->wallet->balance($user),
            'message' => 'ตรวจสลิปผ่านแล้วค่ะ เติมเครดิตให้เรียบร้อย ✨',
        ];
    }

    /** @return array<string,mixed> */
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
            'auto_confirm'   => (bool) config('smschecker.enabled'),
            'expires_at'     => optional($tx->expires_at)->toIso8601String(),
        ];
    }
}
