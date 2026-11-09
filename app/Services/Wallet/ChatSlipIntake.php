<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\Alerts\WalletAlerts;
use App\Support\Pricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 🧾 (2026-11-10) สลิปที่ลูกค้าส่งในแชทเพจ (ระบบเดิม Thaiprompt) → เติมกระเป๋าบนเว็บ
 *
 * เจ้าของ: "อยากให้ระบบเก่า ก็สามารถตรวจสลิปได้อยู่ด้วย แม้จะส่งลูกค้ามาเว็บ
 *          มันจะได้รู้ว่าขณะนั้นใครบิลใคร ชื่อไร"
 *
 * บอท Thaiprompt ตรวจ SlipOK + จองเลขอ้างอิงไว้ใต้ตัวตนแชท ("chat:facebook:<PSID>") แล้วกดกริ่งมาที่นี่
 * (หรือตัวกวาดทุกนาทีดึงเอง) — ที่นี่:
 *   1. ดึงข้อมูลสลิปที่ตรวจแล้วจาก Thaiprompt ด้วย token ของเว็บเอง (กริ่งปลอมทำอะไรไม่ได้)
 *   2. หาลูกค้าจาก facebook_user_id / line_user_id ที่ผูกไว้ตอน SSO
 *   3. หาบิลเติมเงินที่ค้างของลูกค้า แล้วให้ SlipAutoVerifier ตัดสินด้วยด่านเดียวกับสลิปบนเว็บ
 *      (ยอดขาดแค่สตางค์ผ่าน · ต่ำกว่ายอดฐาน = รอแอดมิน · สวิตช์อนุมัติอัตโนมัติด้วยสลิป)
 *   4. ไม่มีบิลค้าง = เปิดรายการรอแอดมินด้วยยอดในสลิป (ไม่เติมเอง)
 *   5. รายงานผลกลับไปที่ Thaiprompt (ครั้งเดียว) ให้บอทตอบลูกค้าในแชทได้ตรงความจริง
 *
 * กันเติมซ้ำ: ล็อกต่อเลขอ้างอิง + ทะเบียนกลางผูกสลิปเข้าบิลได้บิลเดียว + confirmTopupAuto ล็อกแถว
 */
class ChatSlipIntake
{
    public const CREDITED = 'credited';

    public const REVIEW = 'review';

    public const DUPLICATE = 'duplicate';

    public const NO_USER = 'no_user';

    /** ต่อ Thaiprompt ไม่ได้ / มีคนกำลังทำใบนี้อยู่ — สลิปยังพักอยู่ ตัวกวาดมารับใหม่ */
    public const LATER = 'later';

    /** บิลค้างที่ย้อนไปหาได้ (ลูกค้าโอนช้ากว่าบิลได้) */
    private const CANDIDATE_DAYS = 3;

    public function __construct(
        private JuntraServerClient $server,
        private SlipAutoVerifier $verifier,
        private SlipRegistry $registry,
        private WalletService $wallet,
    ) {}

    /**
     * @return array{outcome:string,topup_ref?:?string,credited?:?float}
     */
    public function process(string $transRef): array
    {
        $transRef = trim($transRef);
        if ($transRef === '' || ! preg_match('/^[A-Za-z0-9]{1,64}$/', $transRef)) {
            return ['outcome' => self::LATER];
        }

        $lock = Cache::lock('wallet:chat-slip:' . $transRef, 60);
        if (! $lock->get()) {
            return ['outcome' => self::LATER];
        }

        try {
            return $this->processLocked($transRef);
        } finally {
            $lock->release();
        }
    }

    /** ตัวกวาด: รับทุกใบที่ยังพักอยู่ (กริ่งมาไม่ถึง) */
    public function sweep(): int
    {
        $done = 0;
        foreach ((array) $this->server->pendingChatSlips() as $row) {
            $ref = (string) ($row['trans_ref'] ?? '');
            if ($ref !== '' && $this->process($ref)['outcome'] !== self::LATER) {
                $done++;
            }
        }

        return $done;
    }

    private function processLocked(string $transRef): array
    {
        $res = $this->server->fetchChatSlip($transRef);
        if ($res['status'] !== 'ok') {
            return ['outcome' => self::LATER];
        }
        $slip = (array) $res['data'];

        // มีผลไปแล้ว (กริ่งซ้ำ / ตัวกวาดมาทีหลัง) — ตอบผลเดิม ไม่ทำซ้ำ
        if (($slip['status'] ?? 'parked') !== 'parked') {
            return [
                'outcome'   => (string) $slip['status'],
                'topup_ref' => $slip['topup_ref'] ?? null,
                'credited'  => isset($slip['credited_amount']) ? (float) $slip['credited_amount'] : null,
            ];
        }

        $owner  = (string) ($slip['chat_owner'] ?? '');
        $amount = isset($slip['amount']) ? (float) $slip['amount'] : 0.0;
        if ($owner === '' || $amount <= 0) {
            return ['outcome' => self::LATER];
        }

        // เลขนี้ผูกกับรายการของเว็บไปแล้ว (รอบก่อนเติม/ส่งแอดมินแล้วแต่รายงานกลับไม่สำเร็จ)
        if ($tx = $this->registry->usedLocally($transRef)) {
            $outcome = $tx->status === 'success' ? self::CREDITED : self::REVIEW;

            return $this->report($transRef, $outcome, $tx, $outcome === self::CREDITED ? (float) $tx->amount : null, 'รายการนี้ผูกกับบิลเว็บแล้ว');
        }

        $user = $this->findUser((string) ($slip['platform'] ?? ''), (string) ($slip['platform_user_id'] ?? ''));
        if ($user === null) {
            Log::warning('ChatSlipIntake: ไม่พบบัญชีเว็บที่ผูกกับแชทนี้ — ให้แอดมินเติมเอง', [
                'trans_ref' => $transRef, 'platform' => $slip['platform'] ?? null,
            ]);

            return $this->report($transRef, self::NO_USER, null, null, 'ไม่พบบัญชีเว็บที่ผูกกับแชทนี้');
        }

        $tx = $this->pickBill($user, $amount);

        // ไม่มีบิลค้างเลย = ลูกค้าโอนมาเอง → เปิดรายการรอแอดมินด้วยยอดในสลิป (ไม่เติมเอง)
        if ($tx === null) {
            return $this->queueWithoutBill($user, $slip, $owner);
        }

        $result = $this->verifier->verifyChatSlip($tx, $user, $slip, $owner);
        $fresh  = $tx->fresh();

        if (! empty($result['paid'])) {
            return $this->report($transRef, self::CREDITED, $fresh, (float) ($result['credited'] ?? $fresh->amount), 'เติมอัตโนมัติจากสลิปในแชท');
        }
        if (($result['decision'] ?? null) === SlipAutoVerifier::DUPLICATE) {
            return $this->report($transRef, self::DUPLICATE, null, null, mb_substr((string) ($result['message'] ?? ''), 0, 200));
        }

        // รอแอดมิน — ผูกเลขอ้างอิงไว้กับบิลนี้ ให้รอบหน้า (ตัวกวาด) รู้ว่าทำไปแล้ว
        $fresh->forceFill(['bank_reference' => $transRef])->save();

        return $this->report($transRef, self::REVIEW, $fresh, null, mb_substr((string) (((array) $fresh->meta)['slip_check']['reason'] ?? 'รอแอดมินตรวจ'), 0, 200));
    }

    private function findUser(string $platform, string $platformUserId): ?User
    {
        if ($platformUserId === '') {
            return null;
        }
        $column = match ($platform) {
            'facebook' => 'facebook_user_id',
            'line'     => 'line_user_id',
            default    => null,
        };
        if ($column === null) {
            return null;
        }

        // ผูกได้คนเดียวเท่านั้น — ถ้ามีหลายบัญชีใช้ id เดียวกัน ไม่เดา ส่งแอดมิน
        $users = User::where($column, $platformUserId)->limit(2)->get();

        return $users->count() === 1 ? $users->first() : null;
    }

    /**
     * บิลค้างที่สลิปนี้น่าจะจ่าย
     *  - ยอดในสลิป ≥ ยอดขั้นต่ำของบิล (ยอดฐาน) → บิลที่ "พอดี" ที่สุดก่อน (ห่างจากยอดบิลน้อยสุด) แล้วบิลใหม่สุด
     *  - ไม่มีบิลไหนพอ → บิลใหม่สุด (ด่านยอดของ SlipAutoVerifier จะส่งแอดมินเอง = จ่ายไม่ครบ)
     */
    private function pickBill(User $user, float $amount): ?WalletTransaction
    {
        $pending = WalletTransaction::where('user_id', $user->id)
            ->where('type', 'topup')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(self::CANDIDATE_DAYS))
            ->orderByDesc('id')
            ->get();

        if ($pending->isEmpty()) {
            return null;
        }

        $fits = $pending->filter(fn (WalletTransaction $tx) => $amount + 0.01 >= SlipAutoVerifier::minimumFor($tx));
        if ($fits->isEmpty()) {
            return $pending->first();
        }

        return $fits->sortBy([
            fn ($a, $b) => abs($amount - (float) $a->amount) <=> abs($amount - (float) $b->amount),
            fn ($a, $b) => $b->id <=> $a->id,
        ])->first();
    }

    /** ลูกค้าโอนมาโดยไม่มีบิลค้าง — เปิดรายการรอแอดมิน ผูกสลิปไว้กับรายการนี้ (ใช้ที่อื่นไม่ได้) */
    private function queueWithoutBill(User $user, array $slip, string $owner): array
    {
        $ref    = (string) $slip['trans_ref'];
        $amount = (float) $slip['amount'];

        try {
            $tx = $this->wallet->recordPendingTopup($user, $amount, null, 'promptpay');
        } catch (\Throwable $e) {
            // ติดเพดานรายการค้าง ฯลฯ — ไม่ผูกบิล ให้แอดมินดูจากฝั่งแชท
            Log::warning('ChatSlipIntake: เปิดรายการรอแอดมินไม่ได้', ['trans_ref' => $ref, 'err' => $e->getMessage()]);

            return $this->report($ref, self::REVIEW, null, null, 'ลูกค้าโอนมาโดยไม่มีบิล และเปิดรายการบนเว็บไม่ได้: ' . mb_substr($e->getMessage(), 0, 120));
        }

        $claim = $this->registry->claim($tx, $slip, $owner);
        if ($claim['status'] === SlipRegistry::ALREADY_USED) {
            // รายการที่เพิ่งเปิดให้สลิปใบนี้ — สลิปใช้ไม่ได้ ปิดทิ้ง (ไม่เคยเครดิต)
            $tx->forceFill([
                'status' => 'failed',
                'meta'   => array_merge((array) $tx->meta, ['source' => 'chat_slip', 'closed_reason' => 'สลิปจากแชทถูกใช้ไปแล้ว']),
            ])->save();

            return $this->report($ref, self::DUPLICATE, null, null, 'สลิปถูกใช้ไปแล้วที่ ' . SlipRegistry::whereUsed((array) $claim['used_by']));
        }

        $reason = 'ลูกค้าโอนมาโดยไม่มีบิลค้าง (สลิปจากแชทเพจ ตรวจ SlipOK ผ่านแล้ว) — ตรวจแล้วกดอนุมัติได้';
        $meta   = (array) $tx->meta;
        $meta['source']     = 'chat_slip';
        $meta['base_amount'] = number_format($amount, 2, '.', '');
        $meta['slip_check'] = array_filter([
            'decision'   => SlipAutoVerifier::REVIEW,
            'reason'     => $reason,
            'trans_ref'  => $ref,
            'amount'     => $amount,
            'sender'     => $slip['sender_name'] ?? null,
            'mode'       => 'chat',
            'claimed'    => $claim['status'] === SlipRegistry::CLAIMED,
            'checked_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '');
        $tx->forceFill([
            'meta'           => $meta,
            'bank_reference' => $ref,
            'slip_amount'    => $amount,
            'description'    => 'เติมเงินจากสลิปในแชทเพจ (รอแอดมินยืนยัน)',
        ])->save();

        WalletAlerts::needsReview($tx->fresh(), $reason, ['amount' => $amount, 'sender_name' => $slip['sender_name'] ?? null]);

        return $this->report($ref, self::REVIEW, $tx->fresh(), null, $reason);
    }

    /** รายงานผลกลับ Thaiprompt — ไม่สำเร็จ = สลิปยังพักอยู่ ตัวกวาดมาทำซ้ำได้ (ทุกขั้นข้างบน idempotent) */
    private function report(string $ref, string $outcome, ?WalletTransaction $tx, ?float $credited, string $note): array
    {
        $topupRef = $tx ? ($tx->reference_code ?: ('TX' . $tx->id)) : null;

        $res = $this->server->resolveChatSlip([
            'trans_ref'       => $ref,
            'outcome'         => $outcome,
            'user_ref'        => $tx ? (string) $tx->user_id : null,
            'topup_ref'       => $topupRef,
            'credited_amount' => $credited,
            'note'            => $note,
        ]);

        if ($res['status'] === 'conflict') {
            // อีกทาง (กริ่ง/ตัวกวาด) รายงานไปก่อนแล้ว — เชื่อผลที่ Thaiprompt เก็บไว้
            $data = (array) $res['data'];

            return [
                'outcome'   => (string) ($data['status'] ?? $outcome),
                'topup_ref' => $data['topup_ref'] ?? $topupRef,
                'credited'  => isset($data['credited_amount']) ? (float) $data['credited_amount'] : $credited,
            ];
        }

        Log::info('ChatSlipIntake: สลิปจากแชท ' . $outcome, [
            'trans_ref' => $ref,
            'topup_ref' => $topupRef,
            'credited'  => $credited !== null ? Pricing::format($credited) : null,
            'reported'  => $res['status'],
        ]);

        return ['outcome' => $outcome, 'topup_ref' => $topupRef, 'credited' => $credited];
    }
}
