<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneBotClient;
use App\Support\Pricing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ตรวจสลิปอัตโนมัติแล้วเครดิตให้ ถ้าผ่านครบทุกด่าน
 *
 * อยู่ที่นี่ที่เดียวเพราะทั้งเว็บ (ChatTopupController) และ API มือถือ
 * (Api\V1\WalletController) ต้องใช้กติกาเดียวกันเป๊ะ — session นี้เจอมาแล้ว
 * ว่าพอมีของสองชุด มัน drift เสมอ (เพดานคุยฟรีเคยมีเฉพาะฝั่งเว็บจนผู้ใช้แอพ
 * ยิง AI ฟรีได้ไม่จำกัด) และนี่คือเส้นทางที่แตะเงินโดยตรง
 *
 * ด่านที่ต้องผ่านครบ (ขาดข้อใดข้อหนึ่ง = ส่งต่อให้แอดมิน ไม่เครดิตเอง):
 *   1. SlipOK อ่านสลิปออกและยืนยันว่าเป็นรายการจริง
 *   2. ปลายทางเป็นบัญชีของร้านเรา — ไม่งั้นสลิปที่โอนให้คนอื่นก็ผ่านได้
 *   3. ยอดในสลิป ≥ ยอดที่ต้องโอน (เผื่อคลาด 1 สตางค์จากการปัดเศษ)
 *   4. เลขอ้างอิงธนาคารยังไม่เคยถูกใช้เครดิตรายการไหน — ตัวกัน hash กันได้แค่
 *      ไฟล์เดิมเป๊ะ ๆ ถ่ายใหม่/ครอปใหม่ hash เปลี่ยนแต่เป็นการโอนครั้งเดียวกัน
 *
 * ตรวจไม่ได้ (upstream ล่ม / ยังไม่เชื่อมบัญชี) = คืน null ให้ผู้เรียกส่งต่อ
 * ให้แอดมินตรวจมือ — **ห้ามตีความว่าเป็นการปฏิเสธสลิปของลูกค้า**
 */
class SlipAutoVerifier
{
    public function __construct(
        private FortuneBotClient $bot,
        private WalletService $wallet,
    ) {}

    /**
     * @return array{paid:bool,message:string}|null  null = ตรวจอัตโนมัติไม่ได้
     */
    public function verify(WalletTransaction $tx, User $user, string $slipPath): ?array
    {
        if (! Storage::disk('local')->exists($slipPath)) {
            return null;
        }

        $verify = $this->bot->verifySlip($user, Storage::disk('local')->path($slipPath));
        if (! is_array($verify)) {
            return null;
        }

        if (empty($verify['ok'])) {
            return $this->reject('ระบบอ่านสลิปนี้ไม่ออกค่ะ แม่หมอส่งให้แอดมินตรวจให้แล้วนะคะ');
        }
        if (empty($verify['receiver_matches'])) {
            return $this->reject('สลิปนี้ไม่ได้โอนเข้าบัญชีของร้านค่ะ แอดมินจะตรวจสอบให้อีกครั้งนะคะ');
        }

        $slipAmount = (float) ($verify['amount'] ?? 0);
        $payable    = (float) $tx->amount;
        if ($slipAmount + 0.01 < $payable) {
            return $this->reject(sprintf(
                'ยอดในสลิป %s น้อยกว่ายอดที่ต้องโอน %s ค่ะ แอดมินจะตรวจสอบให้นะคะ',
                Pricing::format($slipAmount),
                Pricing::format($payable),
            ));
        }

        $transRef = trim((string) ($verify['trans_ref'] ?? ''));
        if ($transRef === '') {
            return $this->reject('สลิปนี้ไม่มีเลขอ้างอิงธนาคารค่ะ แอดมินจะตรวจสอบให้นะคะ');
        }
        $used = WalletTransaction::where('bank_reference', $transRef)
            ->where('id', '!=', $tx->id)
            ->exists();
        if ($used) {
            return $this->reject('สลิปนี้ถูกใช้ยืนยันรายการอื่นไปแล้วค่ะ');
        }

        $tx->update(['bank_reference' => $transRef, 'slip_amount' => $slipAmount]);

        try {
            $this->wallet->confirmTopupAuto($tx, [
                'source'      => 'slipok',
                'trans_ref'   => $transRef,
                'slip_amount' => $slipAmount,
                'sender_name' => $verify['sender_name'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // เครดิตไม่สำเร็จ (เช่นชนกับ SMS ที่เพิ่งยืนยันไปพร้อมกัน) —
            // ปล่อยให้แอดมินดูต่อ ดีกว่ารายงานว่าสำเร็จทั้งที่เงินยังไม่เข้า
            Log::warning('SlipAutoVerifier: confirmTopupAuto failed', [
                'tx_id' => $tx->id, 'err' => $e->getMessage(),
            ]);

            return $this->reject('ระบบกำลังยืนยันรายการนี้อยู่ค่ะ แอดมินจะตรวจสอบให้อีกครั้งนะคะ');
        }

        return ['paid' => true, 'message' => 'ตรวจสลิปผ่านแล้วค่ะ เติมเครดิตให้เรียบร้อย ✨'];
    }

    /** @return array{paid:bool,message:string} */
    private function reject(string $message): array
    {
        return ['paid' => false, 'message' => $message];
    }
}
