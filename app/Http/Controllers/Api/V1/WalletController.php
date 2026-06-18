<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DuplicateSlipException;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\SmsPayment\SmsCheckerService;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
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
    public function __construct(private WalletService $wallet) {}

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

        // When the SMS gateway is on, charge a UNIQUE amount (e.g. ฿100.37) so an
        // incoming bank SMS maps to exactly this top-up and auto-credits.
        $base    = (float) $data['amount'];
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

        $promptpayId = Setting::get('promptpay_id', config('pricing.promptpay_id'));

        return response()->json([
            'data' => [
                'transaction'    => $this->txPayload($tx),
                'base_amount'    => $base,
                'payable_amount' => (float) $payable,
                'auto_confirm'   => (bool) config('smschecker.enabled'),
                'promptpay'      => [
                    'id'   => $promptpayId,
                    'name' => Setting::get('promptpay_name', config('pricing.promptpay_name')),
                    // EMVCo payload (render natively) + ready-made SVG data URI,
                    // both carrying the EXACT payable amount so scan-to-pay matches.
                    'qr_payload' => $promptpayId ? PromptPayQr::payload($promptpayId, (float) $payable) : null,
                    'qr_svg'     => $promptpayId ? PromptPayQr::svgDataUri($promptpayId, (float) $payable) : null,
                ],
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

        // Include the SAME promptpay block as topupPromptPay so the app's
        // slip RE-upload sheet can render the QR + exact payable amount
        // (previously it opened blank because this block was missing).
        $payable     = abs((float) $row->amount);
        $promptpayId = Setting::get('promptpay_id', config('pricing.promptpay_id'));

        return response()->json([
            'data' => array_merge($this->txPayload($row), [
                'payable_amount'  => $payable,
                'promptpay'       => [
                    'id'         => $promptpayId,
                    'name'       => Setting::get('promptpay_name', config('pricing.promptpay_name')),
                    'qr_payload' => $promptpayId ? PromptPayQr::payload($promptpayId, $payable) : null,
                    'qr_svg'     => $promptpayId ? PromptPayQr::svgDataUri($promptpayId, $payable) : null,
                ],
                'slip_upload_url' => url('/wallet/topup/' . $row->id),
                'slip_uploaded'   => !empty($row->slip_path),
            ]),
        ]);
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

        return response()->json([
            'data' => array_merge($this->txPayload($row->fresh()), [
                'slip_uploaded'   => true,
                'slip_upload_url' => url('/wallet/topup/' . $row->id),
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
