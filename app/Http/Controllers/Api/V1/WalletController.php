<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DuplicateSlipException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\SmsPayment\SmsCheckerService;
use App\Services\Wallet\SlipAutoVerifier;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use App\Support\PayoutAccount;
use App\Support\PromptPayQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Mobile wallet API — balance, transaction history, and PromptPay
 * top-up initiation. Slip image upload happens via the existing web
 * `/wallet/topup` form (in-app webview); admin approval is unchanged.
 *
 * The actual debit logic lives in WalletService::debit/credit and is
 * shared with the web ChatController + TarotController, so mobile and
 * web cannot drift on pricing or balance arithmetic.
 */
class WalletController extends Controller
{
    use PreventsDuplicateCharges;

    public function __construct(
        private WalletService $wallet,
        private SlipAutoVerifier $slips,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $balance = $this->wallet->balance($user);

        $recent = WalletTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (WalletTransaction $tx) => $this->txPayload($tx));

        return response()->json([
            'data' => [
                'balance'             => (float) $balance,
                'currency'            => config('pricing.currency', 'THB'),
                'pricing'             => collect(\App\Support\TarotSpreads::keys())
                    ->mapWithKeys(fn ($k) => ["tarot_{$k}" => Pricing::for("tarot_{$k}")])
                    ->merge([
                        'numerology'   => Pricing::for('numerology'),
                        'palmistry'    => Pricing::for('palmistry'),
                        'auspicious'   => Pricing::for('auspicious'),
                        'chat_message' => Pricing::for('chat_message'),
                    ])->all(),
                'recent_transactions' => $recent,
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'limit'  => 'sometimes|integer|min:1|max:100',
            'cursor' => 'sometimes|nullable|string',
        ]);
        $limit = (int) $request->input('limit', 25);

        $q = WalletTransaction::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');
        if ($cursor = $request->input('cursor')) {
            $q->where('id', '<', (int) $cursor);
        }
        $rows = $q->limit($limit + 1)->get();
        $nextCursor = null;
        if ($rows->count() > $limit) {
            $nextCursor = (string) $rows->last()->id;
            $rows = $rows->take($limit);
        }

        return response()->json([
            'data' => $rows->map(fn ($tx) => $this->txPayload($tx))->values(),
            'meta' => ['next_cursor' => $nextCursor],
        ]);
    }

    /**
     * Initiates a PromptPay top-up: returns the receiving info and a
     * pending wallet_transactions row id. The user pays externally
     * (banking app), uploads the slip via web `/wallet/topup/{tx}`, and
     * an admin approves it via Filament.
     *
     * Mobile flow can either:
     *   (a) Open the web `/wallet/topup/{tx}` URL in an in-app webview to
     *       finish the slip upload (recommended — single source of truth).
     *   (b) Implement native slip upload + a future POST .../topup/{tx}/slip
     *       endpoint (not in scope this round).
     */
    public function topupPromptPay(Request $request, SmsCheckerService $sms): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:' . config('pricing.min_topup', 20)
                                  . '|max:' . config('pricing.max_topup', 50000),
        ]);

        $base = (float) $data['amount'];

        // กันรายการซ้ำจากการกดรัว ๆ / RetryInterceptor ของแอพยิงซ้ำเอง
        // (เพดาน pending = 5 ใบ ถ้าปล่อยให้ซ้ำ ผู้ใช้จะสร้างใบใหม่ไม่ได้
        //  และไม่รู้ว่าต้องโอนใบไหน เพราะแต่ละใบยอดสตางค์ไม่เท่ากัน)
        if ($this->guardCharge($request, 'topup') === false) {
            return response()->json([
                'message'     => 'รายการก่อนหน้ากำลังดำเนินการ กรุณารอสักครู่นะคะ',
                'reason_code' => 'in_flight',
            ], 409);
        }

        // เพดาน pending ต้องมาก่อนการคืนใบเดิมเสมอ — คนที่ค้างครบเพดานแล้ว
        // ควรได้ยินความจริงว่า "ค้างเยอะเกิน" เพื่อไปเคลียร์ ไม่ใช่ถูกกลืนเงียบ ๆ
        $pendingCount = WalletTransaction::where('user_id', $request->user()->id)
            ->where('type', 'topup')
            ->where('status', 'pending')
            ->count();

        if ($pendingCount >= (int) config('pricing.max_pending_topups', 5)) {
            return response()->json([
                'message'     => 'มีรายการเติมเงินค้างอยู่หลายรายการ กรุณาชำระหรือยกเลิกก่อนนะคะ',
                'reason_code' => 'too_many_pending',
            ], 409);
        }

        // ด่านที่สอง: ใบ pending ยอดเดียวกันที่เพิ่งสร้าง "ไม่กี่นาทีนี้" = การกดซ้ำ
        // → คืนใบเดิมแทนสร้างใหม่ (ครอบแอพรุ่นเก่าที่ไม่ส่ง Idempotency-Key)
        //
        // จำกัดด้วยเวลาโดยตั้งใจ: ใบ pending ค้างจากเมื่อวานไม่ใช่การกดซ้ำ
        // ต้องปล่อยให้ไปชนเพดาน pending ตามเดิม ไม่ใช่กลืนแล้วคืนใบเก่าเงียบ ๆ
        $existing = WalletTransaction::where('user_id', $request->user()->id)
            ->where('type', 'topup')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(3))
            ->get()
            ->first(fn (WalletTransaction $t) => abs(abs((float) $t->amount) - $base) < 1.0
                && abs((float) (((array) $t->meta)['base_amount'] ?? abs((float) $t->amount)) - $base) < 0.0001);

        if ($existing) {
            return response()->json([
                'data' => [
                    'transaction'    => $this->txPayload($existing),
                    'id'             => $existing->id,
                    'base_amount'    => $base,
                    'payable_amount' => abs((float) $existing->amount),
                    'auto_confirm'   => (bool) config('smschecker.enabled'),
                    'promptpay'      => $this->promptpayBlock($request->user(), abs((float) $existing->amount)),
                    'slip_upload_url' => url('/wallet/topup/' . $existing->id),
                    'reused'         => true,
                    'instructions'   => 'รายการนี้สร้างไว้แล้ว โอนตามยอดนี้ได้เลยค่ะ',
                ],
            ], 200);
        }

        // When the SMS gateway is on, charge a UNIQUE amount (e.g. ฿100.37) so an
        // incoming bank SMS maps to exactly this top-up and auto-credits.
        $payable = config('smschecker.enabled') ? $sms->uniqueAmountFor($base) : $base;

        try {
            $tx = $this->wallet->recordPendingTopup($request->user(), $payable, null, 'promptpay');
        } catch (\RuntimeException $e) {
            // Pending-cap reached.
            return response()->json([
                'message'     => $e->getMessage(),
                'reason_code' => 'too_many_pending',
            ], 409);
        }
        if (abs($payable - $base) > 0.0001) {
            $tx->update(['meta' => array_merge((array) $tx->meta, ['base_amount' => $base])]);
        }

        return response()->json([
            'data' => [
                'transaction'    => $this->txPayload($tx),
                // id ระดับบนสุดด้วย — client บางตัวอ่านตรงนี้ และการต้องขุดเข้า
                // transaction.id เป็นจุดที่พลาดง่าย (QR ขึ้นแต่ poll ไม่ทำงาน)
                'id'             => $tx->id,
                'base_amount'    => $base,
                'payable_amount' => (float) $payable,
                'auto_confirm'   => (bool) config('smschecker.enabled'),
                'promptpay'      => $this->promptpayBlock($request->user(), (float) $payable),
                'slip_upload_url' => url('/wallet/topup/' . $tx->id),
                'instructions'    => config('smschecker.enabled')
                    ? 'โอนยอดให้ตรงเป๊ะตาม QR/ยอดที่ระบุ — ระบบจะเครดิตอัตโนมัติเมื่อเงินเข้า (หรืออัปโหลดสลิปก็ได้)'
                    : 'สแกน QR หรือโอนตามจำนวน แล้วอัปโหลดสลิป — แอดมินจะอนุมัติภายในไม่กี่นาที',
            ],
        ], 201);
    }

    /** List the user's top-up requests (optionally filtered by status). */
    public function topups(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'sometimes|in:pending,success,failed,refunded,cancelled',
        ]);
        $q = WalletTransaction::where('user_id', $request->user()->id)
            ->where('type', 'topup')
            ->orderByDesc('created_at');
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }

        return response()->json([
            'data' => $q->limit(50)->get()->map(fn (WalletTransaction $tx) => array_merge($this->txPayload($tx), [
                'slip_uploaded' => !empty($tx->slip_path),
                'expires_at'    => optional($tx->expires_at)->toIso8601String(),
            ]))->values(),
            'meta' => ['pending_count' => $this->wallet->pendingTopupCount($request->user())],
        ]);
    }

    /** Cancel one of the user's own still-pending top-ups. */
    public function topupCancel(Request $request, int $tx): JsonResponse
    {
        $row = WalletTransaction::where('id', $tx)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        try {
            $this->wallet->cancelTopup($row, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json([
                'message'     => $e->getMessage(),
                'reason_code' => 'cannot_cancel',
            ], 409);
        }
        return response()->json(['data' => $this->txPayload($row->fresh())]);
    }

    public function topupShow(Request $request, int $tx): JsonResponse
    {
        $row = WalletTransaction::where('id', $tx)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $payable = abs((float) $row->amount);

        return response()->json([
            'data' => array_merge($this->txPayload($row), [
                'payable_amount'  => $payable,
                // ต้องเป็นบล็อกเดียวกับตอน initiate เป๊ะ ๆ ไม่งั้น QR ที่ลูกค้า
                // สแกนตอนกลับมาอัปสลิปซ้ำจะชี้คนละบัญชี (หรือว่างเปล่า)
                'promptpay'       => $this->promptpayBlock($request->user(), $payable),
                'slip_upload_url' => url('/wallet/topup/' . $row->id),
                'slip_uploaded'   => !empty($row->slip_path),
                // ชีทเติมเงินในแชท poll เส้นนี้แล้วอ่าน balance เพื่อโชว์ยอดใหม่
                // ตอนเครดิตเข้า — ก่อนหน้านี้ไม่มีคีย์นี้ หน้าจอ "สำเร็จ" จึงไม่มีตัวเลข
                'balance'         => (float) $this->wallet->balance($request->user()),
            ]),
        ]);
    }

    /**
     * บล็อก PromptPay ที่ใช้ร่วมกันระหว่าง initiate กับ show
     *
     * 🔴 เคยเป็นสองแหล่งที่ drift กัน: initiate ใช้ `PayoutAccount::resolve()`
     * (บัญชีเดียวกับแม่หมอใน FB/LINE) แต่ show ไปอ่าน `Setting::get('promptpay_id')`
     * ซึ่ง **ไม่เคยถูกเขียน** — PayoutAccount เก็บลงคีย์ `payout_account_snapshot`
     * คนละคีย์กัน ผลคือ QR ตอนกลับมาอัปสลิปซ้ำว่างเปล่าเสมอ และถ้าแอดมินเผลอ
     * พิมพ์เลขอื่นไว้ใน Setting ลูกค้าจะโอนเข้าบัญชีผิดแล้ว SlipOK ตีกลับ
     * ทั้งที่โอนตามที่แอพบอกเป๊ะ
     *
     * @return array<string,mixed>
     */
    private function promptpayBlock($user, float $payable): array
    {
        $payout      = PayoutAccount::resolve($user);
        $promptpayId = $payout['promptpay_id'] ?? '';

        return [
            'id'   => $promptpayId,
            'name' => $payout['name'] ?? '',
            // EMVCo payload (render natively) + ready-made SVG data URI,
            // both carrying the EXACT payable amount so scan-to-pay matches.
            'qr_payload' => $promptpayId ? PromptPayQr::payload($promptpayId, $payable) : null,
            'qr_svg'     => $promptpayId ? PromptPayQr::svgDataUri($promptpayId, $payable) : null,
        ];
    }

    /**
     * Native slip upload — multipart form, field name `slip`. Mobile
     * Flutter app uses image_picker (camera or gallery) to grab the
     * PromptPay slip after the user finishes paying externally, then
     * POSTs it here so we don't have to bounce them through the web
     * upload form via url_launcher.
     *
     * Mirrors the web TopupSubmit slip storage:
     *   - Validates the file is an image, max 4 MB.
     *   - Stores under PRIVATE 'local' disk at `topup-slips/<random>`.
     *     Slips contain bank info — must never live on the public disk.
     *   - Owner-only; admin-uploaded slips still go through Filament.
     *   - Re-upload is allowed while status=pending so the user can fix
     *     a blurry photo. Once admin has approved/rejected the tx we
     *     refuse further uploads (audit trail integrity).
     *   - Stale old slip path is deleted on re-upload so private disk
     *     doesn't fill up with abandoned images.
     */
    public function topupUploadSlip(Request $request, int $tx): JsonResponse
    {
        $row = WalletTransaction::where('id', $tx)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Defensive — `type` MUST be topup. We didn't constrain that at
        // route level so a creative client can't repurpose this endpoint
        // to attach images to e.g. debit transactions.
        if ($row->type !== 'topup') {
            return response()->json([
                'message'     => 'รายการนี้ไม่ใช่การเติมเงิน',
                'reason_code' => 'invalid_tx_type',
            ], 422);
        }

        if ($row->status !== 'pending') {
            return response()->json([
                'message'     => 'รายการนี้ดำเนินการเสร็จแล้ว ไม่สามารถอัปโหลดสลิปใหม่ได้',
                'reason_code' => 'tx_not_pending',
                'status'      => $row->status,
            ], 409);
        }

        $request->validate([
            'slip' => 'required|image|max:4096', // 4 MB
        ]);

        // sha256 dedup — reject a slip already credited to another top-up
        // (excluding this same row in case of a re-upload).
        $file     = $request->file('slip');
        $slipHash = hash_file('sha256', $file->getRealPath());
        try {
            $this->wallet->assertSlipNotReused($slipHash, $row->id);
        } catch (DuplicateSlipException $e) {
            return response()->json([
                'message'     => $e->getMessage(),
                'reason_code' => 'duplicate_slip',
            ], 409);
        }

        // Snapshot the old path BEFORE we overwrite, so we can delete
        // it after the new save succeeds. If the new save throws, the
        // old slip stays intact — never delete first.
        $oldPath = $row->slip_path;
        $newPath = $file->store('topup-slips', 'local');
        $row->update(['slip_path' => $newPath, 'slip_hash' => $slipHash]);
        if ($oldPath && $oldPath !== $newPath && Storage::disk('local')->exists($oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }

        // ตรวจสลิปอัตโนมัติด้วย SlipOK ชุดเดียวกับเว็บ (SlipAutoVerifier)
        // ก่อนหน้านี้ฝั่งแอพต้องรอแอดมินกดทุกใบ ทั้งที่เว็บตรวจเองได้แล้ว
        // ตรวจไม่ได้ = ส่งต่อให้แอดมิน ไม่ปฏิเสธสลิปของลูกค้า
        $auto = $this->slips->verify($row, $request->user(), $newPath);

        return response()->json([
            'data' => array_merge($this->txPayload($row->fresh()), [
                'slip_uploaded'   => true,
                'slip_upload_url' => url('/wallet/topup/' . $row->id),
                'auto_verified'   => $auto !== null,
                'paid'            => (bool) ($auto['paid'] ?? false),
                'message'         => $auto['message']
                    ?? 'ได้รับสลิปแล้วค่ะ ระบบกำลังตรวจสอบให้นะคะ',
                'balance'         => (float) $this->wallet->balance($request->user()),
            ]),
        ]);
    }

    private function txPayload(WalletTransaction $tx): array
    {
        return [
            'id'             => $tx->id,
            'type'           => $tx->type,
            'status'         => $tx->status,
            'amount'         => (float) $tx->amount,
            'balance_after'  => $tx->balance_after !== null ? (float) $tx->balance_after : null,
            'description'    => $tx->description,
            'reference_code' => $tx->reference_code,
            'method'         => $tx->method,
            'created_at'     => optional($tx->created_at)->toIso8601String(),
        ];
    }
}
