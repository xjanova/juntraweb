<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\Alerts\WalletAlerts;
use App\Support\Pricing;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ตรวจสลิปอัตโนมัติแล้วเครดิตให้ ถ้าผ่านครบทุกด่าน
 *
 * อยู่ที่นี่ที่เดียวเพราะทั้งเว็บ (ChatTopupController, WalletController) และ API มือถือ
 * (Api\V1\WalletController) ต้องใช้กติกาเดียวกันเป๊ะ — เส้นทางนี้แตะเงินโดยตรง
 *
 * กติกาเดียวกับบอทแม่หมอ (SlipOkService::evaluateForReading ฝั่ง Thaiprompt) เพราะใช้
 * SlipOK ชุดเดียวกัน บัญชีรับเงินเดียวกัน และ (ตั้งแต่ 2026-09-15) ทะเบียนสลิปเดียวกัน:
 *
 *   1. SlipOK อ่านสลิปออกและยืนยันว่าเป็นรายการจริง
 *   2. สลิปยังไม่เคยถูกใช้ — ในเว็บ (เลขอ้างอิง / เงินก้อนที่ SMS ยืนยันไปแล้ว)
 *      และในระบบแม่หมอ (ทะเบียน slip_verifications + บิลที่ SMS ตัดไปแล้ว)
 *   3. ปลายทางเป็นบัญชีของร้านเรา — ไม่งั้นสลิปที่โอนให้คนอื่นก็ผ่านได้
 *   4. สลิปอายุไม่เกิน 3 วัน (เท่ากับบอท)
 *   5. ยอดในสลิป ≥ ยอดที่ต้องโอน (เผื่อคลาด 1 สตางค์จากการปัดเศษ)
 *   6. จองเลขอ้างอิงในทะเบียนกลางสำเร็จ (ใครจองก่อนได้ใช้ก่อน — กันส่งพร้อมกันสองระบบ)
 *
 * ผลลัพธ์มี 3 แบบ (เจ้าของกำหนด: "ถ้าเป็นสลิปที่ไม่รู้จัก ก็ให้รอแอดมินอนุมัติ เหมือนเดิม
 * แต่แจ้งผ่านเทเลแกรมด้วย"):
 *   - approve   → เครดิตทันที
 *   - duplicate → มีหลักฐานว่าสลิปถูกใช้แล้ว → ไม่เครดิต บอกลูกค้าตรง ๆ + แจ้งเตือนความปลอดภัย
 *   - review    → ตรวจไม่ผ่าน/ตรวจไม่ได้ → คงสถานะรอแอดมิน + ส่งการ์ดพร้อมรูปสลิปเข้า Telegram
 * ตรวจไม่ได้ (upstream ล่ม / ยังไม่เชื่อมบัญชี) **ห้ามตีความว่าเป็นการปฏิเสธสลิปของลูกค้า**
 */
class SlipAutoVerifier
{
    public const APPROVE = 'approve';

    public const DUPLICATE = 'duplicate';

    public const REVIEW = 'review';

    /** เท่ากับ SlipOkService::MAX_SLIP_AGE_DAYS ของบอท */
    private const MAX_SLIP_AGE_DAYS = 3;

    public function __construct(
        private FortuneBotClient $bot,
        private WalletService $wallet,
        private JuntraServerClient $server,
        private SlipRegistry $registry,
    ) {}

    /**
     * @return array{paid:bool,message:string,decision:string}|null  null = ตรวจอัตโนมัติไม่ได้ (รอแอดมิน)
     */
    public function verify(WalletTransaction $tx, User $user, string $slipPath): ?array
    {
        if ($tx->type !== 'topup' || $tx->status !== 'pending' || ! Storage::disk('local')->exists($slipPath)) {
            return null;
        }
        $absolute = Storage::disk('local')->path($slipPath);

        [$verify, $mode, $why] = $this->read($tx, $user, $absolute);
        if ($verify === null) {
            $this->remember($tx, self::REVIEW, $why, []);
            WalletAlerts::needsReview($tx->fresh(), $why);

            return null;
        }

        $slip = $this->slipFacts($verify);
        $payable = (float) $tx->amount;

        // ---- ด่าน "ใช้แล้วหรือยัง" — มาก่อนทุกอย่าง เพราะเป็นหลักฐานเชิงบวกที่ปฏิเสธได้ทันที
        if (! empty($verify['used'])) {
            return $this->duplicate($tx, $user, $slip, $verify['used_source'] ?? null, (array) ($verify['used_by'] ?? []));
        }
        $ref = $slip['trans_ref'];
        if ($ref !== '' && ($other = $this->registry->usedLocally($ref, $tx->id))) {
            return $this->duplicate($tx, $user, $slip, 'web', ['platform' => 'juntraweb', 'topup' => $other->reference_code]);
        }

        // ---- ด่าน SlipOK
        if (empty($verify['ok'])) {
            $code = (int) ($verify['error_code'] ?? 0);

            return $this->review($tx, match (true) {
                $code === 1012 => ['SlipOK แจ้งว่าสลิปนี้เคยถูกส่งตรวจแล้ว (ยังไม่พบว่าใช้เครดิตรายการไหน)', 'สลิปนี้เคยถูกส่งตรวจมาก่อนค่ะ แอดมินจะตรวจสอบให้อีกครั้งนะคะ'],
                $code === 1014 => ['ปลายทางในสลิปไม่ใช่บัญชีของร้าน (SlipOK 1014)', 'สลิปนี้ไม่ได้โอนเข้าบัญชีของร้านค่ะ แอดมินจะตรวจสอบให้อีกครั้งนะคะ'],
                in_array($code, [1007, 1008, 1011], true) => ['อ่าน QR ในสลิปไม่ได้ (อาจไม่ใช่สลิป หรือรูปไม่ชัด)', 'ระบบอ่านสลิปนี้ไม่ออกค่ะ แม่หมอส่งให้แอดมินตรวจให้แล้วนะคะ'],
                in_array($code, [1009, 1010], true) => ['ธนาคารยังไม่ยืนยันรายการ (รอสักครู่แล้วลองใหม่ได้)', 'ธนาคารยังไม่ยืนยันรายการนี้ค่ะ แอดมินจะตรวจสอบให้นะคะ'],
                in_array($code, [1003, 1004, 1015], true) => ['โควตาตรวจสลิป SlipOK หมด', 'ระบบตรวจสลิปอัตโนมัติไม่ว่างชั่วคราวค่ะ แอดมินจะตรวจสอบให้นะคะ'],
                default => ['SlipOK ตรวจไม่ผ่าน' . ($code ? " (โค้ด {$code})" : '') . ': ' . mb_substr((string) ($verify['message'] ?? ''), 0, 120), 'ระบบอ่านสลิปนี้ไม่ออกค่ะ แม่หมอส่งให้แอดมินตรวจให้แล้วนะคะ'],
            }, $slip);
        }
        if ($ref === '') {
            return $this->review($tx, ['สลิปไม่มีเลขอ้างอิงธนาคาร', 'สลิปนี้ไม่มีเลขอ้างอิงธนาคารค่ะ แอดมินจะตรวจสอบให้นะคะ'], $slip);
        }

        // เงินก้อนนี้ SMS ธนาคารยืนยันรายการอื่นของเว็บไปแล้ว (รายการ SMS ไม่มีเลขอ้างอิงสลิป)
        if ($this->registry->usedBySms($slip['amount'], $slip['trans_timestamp'], $tx->id)) {
            return $this->duplicate($tx, $user, $slip, 'web_sms', []);
        }

        if (empty($verify['receiver_matches'])) {
            return $this->review($tx, ['ปลายทางในสลิปไม่ตรงกับบัญชีของร้าน', 'สลิปนี้ไม่ได้โอนเข้าบัญชีของร้านค่ะ แอดมินจะตรวจสอบให้อีกครั้งนะคะ'], $slip);
        }
        if (! $this->recentEnough($verify)) {
            return $this->review($tx, ['สลิปเก่าเกิน ' . self::MAX_SLIP_AGE_DAYS . ' วัน', 'สลิปนี้เก่าเกินกว่าที่ระบบตรวจอัตโนมัติได้ค่ะ แอดมินจะตรวจสอบให้นะคะ'], $slip);
        }
        if ((float) $slip['amount'] + 0.01 < $payable) {
            return $this->review($tx, [
                sprintf('ยอดในสลิป %s น้อยกว่ายอดที่ต้องโอน %s', Pricing::format((float) $slip['amount']), Pricing::format($payable)),
                sprintf('ยอดในสลิป %s น้อยกว่ายอดที่ต้องโอน %s ค่ะ แอดมินจะตรวจสอบให้นะคะ', Pricing::format((float) $slip['amount']), Pricing::format($payable)),
            ], $slip);
        }

        // ---- จองในทะเบียนกลาง: ใครจองก่อนได้ใช้ก่อน (ส่งสลิปพร้อมกันสองระบบก็ผ่านได้ใบเดียว)
        $claim = $this->registry->claim($tx, $slip);
        if ($claim['status'] === SlipRegistry::ALREADY_USED) {
            return $this->duplicate($tx, $user, $slip, 'slip_registry', (array) $claim['used_by']);
        }
        if ($claim['status'] === SlipRegistry::UNAVAILABLE) {
            return $this->review($tx, ['ยืนยันกับทะเบียนสลิปของแม่หมอไม่ได้ (Thaiprompt ต่อไม่ติด) — ตรวจแล้วอนุมัติได้ถ้าสลิปถูกต้อง', 'ได้รับสลิปแล้วค่ะ แอดมินจะยืนยันให้อีกครั้งนะคะ'], $slip);
        }

        $tx->update(['bank_reference' => $ref, 'slip_amount' => $slip['amount']]);
        $this->remember($tx, self::APPROVE, 'ผ่านครบทุกด่าน', $slip + ['claimed' => $claim['status'] === SlipRegistry::CLAIMED, 'mode' => $mode]);

        try {
            $this->wallet->confirmTopupAuto($tx, [
                'source'      => 'slipok',
                'trans_ref'   => $ref,
                'slip_amount' => $slip['amount'],
                'sender_name' => $slip['sender_name'],
            ]);
        } catch (\Throwable $e) {
            // เครดิตไม่สำเร็จ (เช่นชนกับ SMS ที่เพิ่งยืนยันไปพร้อมกัน) —
            // ปล่อยให้แอดมินดูต่อ ดีกว่ารายงานว่าสำเร็จทั้งที่เงินยังไม่เข้า
            Log::warning('SlipAutoVerifier: confirmTopupAuto failed', [
                'tx_id' => $tx->id, 'err' => $e->getMessage(),
            ]);

            return ['paid' => false, 'decision' => self::REVIEW, 'message' => 'ระบบกำลังยืนยันรายการนี้อยู่ค่ะ แอดมินจะตรวจสอบให้อีกครั้งนะคะ'];
        }

        return ['paid' => true, 'decision' => self::APPROVE, 'message' => 'ตรวจสลิปผ่านแล้วค่ะ เติมเครดิตให้เรียบร้อย ✨'];
    }

    /**
     * อ่านสลิป — ทางหลักคือ endpoint ของเว็บเอง (ไม่ต้องมี token ของลูกค้า และได้ผลเช็ค
     * "ใช้แล้วหรือยัง" จากทะเบียนแม่หมอมาด้วย) ถ้าฝั่งนั้นยังไม่พร้อม ถอยไปใช้ token ของลูกค้า
     *
     * @return array{0:?array,1:string,2:string} [ผลตรวจ, ทางที่ใช้, เหตุผลถ้าอ่านไม่ได้]
     */
    private function read(WalletTransaction $tx, User $user, string $absolute): array
    {
        $res = $this->server->verifySlip($absolute, $user->id, (float) $tx->amount);
        if ($res['status'] === 'ok') {
            return [$res['data'], 'server', ''];
        }
        if ($res['status'] === 'flood_guard') {
            return [null, 'server', 'ลูกค้าส่งสลิปตรวจถี่เกินเพดาน (กันยิงโควตา SlipOK) — ตรวจด้วยตาแทน'];
        }

        // ทางเดิม: token ของลูกค้าเอง — ได้เฉพาะคนที่เชื่อมบัญชีแม่หมอไว้
        $legacy = $this->bot->verifySlip($user, $absolute);
        if (is_array($legacy)) {
            return [$legacy, 'user', ''];
        }

        return [null, 'none', $res['status'] === 'unavailable'
            ? 'ระบบตรวจสลิป (Thaiprompt/SlipOK) ต่อไม่ติดชั่วคราว'
            : 'ตรวจสลิปอัตโนมัติไม่ได้ (ลูกค้ายังไม่ได้เชื่อมบัญชีแม่หมอ และยังไม่ได้ตั้งค่าการเชื่อมต่อแบบเซิร์ฟเวอร์)'];
    }

    /** @return array{trans_ref:string,amount:?float,sender_name:?string,receiver_account:?string,receiver_name:?string,sending_bank:?string,receiving_bank:?string,trans_timestamp:?string} */
    private function slipFacts(array $v): array
    {
        return [
            'trans_ref'        => trim((string) ($v['trans_ref'] ?? '')),
            'amount'           => isset($v['amount']) ? (float) $v['amount'] : null,
            'sender_name'      => $v['sender_name'] ?? null,
            'receiver_account' => $v['receiver_account'] ?? null,
            'receiver_name'    => $v['receiver_name'] ?? null,
            'sending_bank'     => $v['sending_bank'] ?? null,
            'receiving_bank'   => $v['receiving_bank'] ?? null,
            'trans_timestamp'  => $v['trans_timestamp'] ?? null,
        ];
    }

    /** ด่านอายุสลิป — ใช้ผลของ Thaiprompt ถ้ามี ไม่งั้นคิดเองด้วยกติกาเดียวกัน (ไม่มีเวลา = ไม่บล็อก) */
    private function recentEnough(array $verify): bool
    {
        if (array_key_exists('slip_age_ok', $verify) && $verify['slip_age_ok'] !== null) {
            return (bool) $verify['slip_age_ok'];
        }
        $ts = $verify['trans_timestamp'] ?? null;
        if (empty($ts)) {
            return true;
        }
        try {
            $slipDay = Carbon::parse($ts)->setTimezone('Asia/Bangkok')->startOfDay();

            return $slipDay->gte(Carbon::now('Asia/Bangkok')->startOfDay()->subDays(self::MAX_SLIP_AGE_DAYS));
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param array{0:string,1:string} $why [เหตุผลสำหรับแอดมิน, ข้อความถึงลูกค้า] */
    private function review(WalletTransaction $tx, array $why, array $slip): array
    {
        if ($slip['amount'] !== null) {
            $tx->update(['slip_amount' => $slip['amount']]);
        }
        $this->remember($tx, self::REVIEW, $why[0], $slip);
        WalletAlerts::needsReview($tx->fresh(), $why[0], $slip);

        return ['paid' => false, 'decision' => self::REVIEW, 'message' => $why[1]];
    }

    private function duplicate(WalletTransaction $tx, User $user, array $slip, ?string $source, array $usedBy): array
    {
        $where = SlipRegistry::whereUsed($usedBy, $source);
        $this->remember($tx, self::DUPLICATE, 'สลิปถูกใช้ไปแล้วที่ ' . $where, $slip + ['used_source' => $source, 'used_by' => $usedBy]);
        WalletAlerts::duplicateBlocked($user, $tx->fresh(), $where, $slip['trans_ref'] ?: null, $slip['amount']);

        return [
            'paid'     => false,
            'decision' => self::DUPLICATE,
            'message'  => 'สลิปนี้ถูกใช้ไปแล้วค่ะ (' . $where . ') — ถ้าโอนใหม่แล้ว แนบสลิปใบใหม่ได้เลยนะคะ',
        ];
    }

    /** เก็บผลตรวจล่าสุดไว้ในรายการ ให้แอดมินเห็นเหตุผลในหน้าหลังบ้าน */
    private function remember(WalletTransaction $tx, string $decision, string $reason, array $slip): void
    {
        try {
            $meta = (array) $tx->fresh()->meta;
            $meta['slip_check'] = array_filter([
                'decision'   => $decision,
                'reason'     => mb_substr($reason, 0, 250),
                'trans_ref'  => $slip['trans_ref'] ?? null,
                'amount'     => $slip['amount'] ?? null,
                'sender'     => $slip['sender_name'] ?? null,
                'source'     => $slip['used_source'] ?? null,
                'claimed'    => $slip['claimed'] ?? null,
                'mode'       => $slip['mode'] ?? null,
                'checked_at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null && $v !== '');
            $tx->forceFill(['meta' => $meta])->save();
        } catch (\Throwable $e) {
            Log::warning('SlipAutoVerifier: could not store slip_check', ['tx_id' => $tx->id, 'err' => $e->getMessage()]);
        }
    }
}
