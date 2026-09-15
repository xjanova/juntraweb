<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsCheckerDevice;
use App\Models\SmsPaymentNotification;
use App\Models\WalletTransaction;
use App\Services\SmsPayment\SmsCheckerService;
use App\Services\SmsPayment\SmsOrderPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * SMS Checker device API (thaiprompt-smschecker-v1). The Android app POSTs an
 * encrypted, HMAC-signed bank-SMS to /notify; we verify the device, decrypt,
 * guard against replay, and hand it to SmsCheckerService to match a reserved
 * wallet top-up and auto-credit the wallet.
 *
 * The order endpoints mirror Thaiprompt-Affiliate's SmsPaymentController
 * response shapes exactly (the app keeps both servers' bills in one table):
 * every order is a RemoteOrderApproval built by SmsOrderPresenter, with
 * server_name / website_name = config('smschecker.site_label').
 *
 * Money moves only through SmsCheckerService → WalletService (locked,
 * idempotent). Endpoints authenticated by X-Api-Key alone (orders, match,
 * stats, settings) never credit on their own — see SmsCheckerService::matchForApp.
 *
 * Full path: /api/v1/sms-payment/*
 */
class SmsPaymentController extends Controller
{
    public function __construct(
        private SmsCheckerService $sms,
        private SmsOrderPresenter $orders,
    ) {}

    public function notify(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');

        $signed = $this->readSignedPayload($request, $device);
        if ($signed instanceof JsonResponse) {
            return $signed;
        }
        [$payload, $nonce] = $signed;

        // Replay guard — nonce may be used once. Cache for the fast path; the DB
        // unique index on `nonce` is the durable backstop.
        $nonceKey = 'smschecker:nonce:' . sha1($nonce);
        if (Cache::has($nonceKey) || SmsPaymentNotification::where('nonce', $nonce)->exists()) {
            return $this->err('Duplicate request', 400);
        }
        if (! isset($payload['amount']) || ! is_numeric($payload['amount']) || (float) $payload['amount'] < 0.01) {
            return $this->err('Invalid payload data', 422);
        }
        // Carry the verified nonce into the stored record for the unique index.
        $payload['nonce'] = $nonce;

        try {
            $result = $this->sms->processNotification($payload, $device, (string) $request->ip());
        } catch (\Illuminate\Database\QueryException $e) {
            // Concurrent same-nonce slipped past the cache check → unique violation.
            return $this->err('Duplicate request', 400);
        } catch (\Throwable $e) {
            Log::error('SmsChecker notify failed', ['err' => $e->getMessage()]);
            return $this->err('Internal error processing notification', 500);
        }

        Cache::put($nonceKey, 1, config('smschecker.nonce_ttl_seconds', 900));

        // Same as Thaiprompt: hand the matched bill back so the app can show it
        // (and which site it belongs to) immediately. The app reads data.order;
        // data.matched_order is the older name, kept for compatibility.
        if ($result['matched'] && $result['matched_transaction_id']) {
            $tx = WalletTransaction::with('user:id,name')->find($result['matched_transaction_id']);
            if ($tx) {
                $order = $this->orders->present($tx, SmsPaymentNotification::find($result['notification_id']));
                $result['order']         = $order;
                $result['matched_order'] = $order;
            }
        }

        return response()->json([
            'success' => true,
            'message' => match ($result['status']) {
                'confirmed' => 'Payment matched and confirmed',
                'matched'   => 'Payment matched — awaiting approval',
                default     => 'Notification recorded',
            },
            'data'    => $result,
        ]);
    }

    /**
     * Encrypted approve/reject from the app (same crypto as /notify).
     * Payload: {action, order_identifier (reference_code or id) | order_id | approval_id,
     *           amount, bank?, sms_reference?, device_id, reason?, nonce, force?}
     */
    public function notifyAction(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');

        $signed = $this->readSignedPayload($request, $device);
        if ($signed instanceof JsonResponse) {
            return $signed;
        }
        [$payload, $nonce] = $signed;

        $v = Validator::make($payload, [
            'action'           => 'required|string',
            'order_identifier' => 'nullable|max:100',
            'order_id'         => 'nullable|max:100',
            'approval_id'      => 'nullable|max:100',
            'amount'           => 'nullable|numeric',
            'bank'             => 'nullable|string|max:32',
            'sms_reference'    => 'nullable|string|max:100',
            'reason'           => 'nullable|string|max:500',
        ]);
        $identifier = (string) ($payload['order_identifier'] ?? $payload['order_id'] ?? $payload['approval_id'] ?? '');
        if ($v->fails() || $identifier === '') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload data',
                'errors'  => $identifier === '' ? ['order_identifier' => ['required']] : $v->errors(),
            ], 422);
        }
        $action = (string) $payload['action'];
        if (! in_array($action, ['approve', 'reject'], true)) {
            // 'void' exists at Thaiprompt for fortune bills only; a credited
            // top-up is reversed by an admin on the web (WalletService::reverseTopup).
            return $this->err('Action not supported on this server: ' . $action, 422);
        }

        // Replay guard (the header nonce is bound by the HMAC). Actions are also
        // idempotent underneath, this just refuses an identical resend early.
        if (! Cache::add('smschecker:action-nonce:' . sha1($nonce), 1, (int) config('smschecker.nonce_ttl_seconds', 900))) {
            return $this->err('Duplicate request (nonce already used)', 409);
        }

        $tx = $this->resolveTopup($identifier);
        if (! $tx) {
            return $this->err('Order not found: ' . $identifier, 404);
        }

        $device->forceFill(['last_active_at' => now(), 'ip_address' => $request->ip()])->save();

        if ($action === 'approve') {
            $r = $this->sms->approveFromDevice($tx, $device, $payload);
            $tx = $r['tx']->loadMissing('user:id,name');

            return match ($r['result']) {
                'approved' => $this->actionOk('Order approved successfully', $tx, $r['notification']),
                'already'  => $this->actionOk('Order already approved', $tx),
                'no_sms'   => response()->json([
                    'success'    => false,
                    'message'    => 'ไม่พบ SMS แจ้งเงินเข้าที่ตรงกับรายการนี้ — ไม่อนุมัติ',
                    'error_code' => 'NO_VALID_SMS_FOR_TRANSACTION',
                ], 422),
                'disabled' => $this->err('SMS payment gateway is disabled on this server', 422),
                default    => $this->err('Transaction cannot be approved in current status: ' . $tx->status, 422),
            };
        }

        $r  = $this->sms->rejectFromDevice($tx, $device, $payload['reason'] ?? null);
        $tx = $r['tx']->loadMissing('user:id,name');

        return match ($r['result']) {
            'rejected' => $this->actionOk('Order rejected', $tx),
            'already'  => $this->actionOk('Order already rejected', $tx),
            default    => $this->err('Transaction cannot be rejected in current status: ' . $tx->status, 422),
        };
    }

    /** GET /orders — top-up bills for the app (default 'waiting'; the app sends status=all). */
    public function orders(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');

        $data = $request->validate([
            'status'    => 'sometimes|nullable|in:waiting,all,pending,success,failed,cancelled,refunded',
            'date_from' => 'sometimes|nullable|string|max:40',
            'date_to'   => 'sometimes|nullable|string|max:40',
            'search'    => 'sometimes|nullable|string|max:64',
            'per_page'  => 'sometimes|integer|min:1|max:100',
            'page'      => 'sometimes|integer|min:1',
        ]);
        $status = $data['status'] ?? 'waiting';

        $q = WalletTransaction::with('user:id,name')->where('type', 'topup');
        if ($status === 'waiting') {
            // Awaiting payment + the ones confirmed in the last hour, so the app
            // sees a bill flip to paid right after an SMS matched it.
            $q->where(function ($w) {
                $w->where('status', 'pending')
                    ->orWhere(fn ($w2) => $w2->where('status', 'success')->where('approved_at', '>=', now()->subHour()));
            });
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($from = $this->parseDate($data['date_from'] ?? null)) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $this->parseDate($data['date_to'] ?? null)) {
            $q->where('created_at', '<=', $to);
        }
        if (! empty($data['search'])) {
            $q->where('reference_code', 'like', '%' . addcslashes($data['search'], '%_\\') . '%');
        }

        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 20));
        $device->forceFill(['last_active_at' => now()])->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'data'         => $this->orders->presentMany($page->getCollection()),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /**
     * GET /orders/sync?since_version=<ms> — bills changed since the app's newest
     * synced_version. Incremental pulls go oldest-first so a burst larger than
     * `limit` is never skipped; the first pull (0) returns the newest.
     */
    public function syncOrders(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');

        $since = (int) ($request->input('since_version') ?? $request->input('since') ?? 0);
        $limit = max(1, min(200, (int) $request->input('limit', 100)));

        $q = WalletTransaction::with('user:id,name')->where('type', 'topup');
        if ($since > 0) {
            // >= : synced_version has 1-second resolution; re-sending the
            // boundary second is harmless (the app upserts), missing it is not.
            $q->where('updated_at', '>=', Carbon::createFromTimestampMs($since)->setTimezone(config('app.timezone')))
                ->orderBy('updated_at')->orderBy('id');
        } else {
            $q->orderByDesc('updated_at')->orderByDesc('id');
        }
        $rows = $q->limit($limit)->get();

        $device->forceFill(['last_active_at' => now()])->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'orders'         => $this->orders->presentMany($rows),
                'latest_version' => (int) round(microtime(true) * 1000),
            ],
        ]);
    }

    /**
     * GET /orders/match?amount=100.37 — is this SMS amount one of our bills?
     * Exact amount, pending, not expired (SmsCheckerService::findMatchingTopup).
     */
    public function matchOrder(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');

        $amount = $request->input('amount');
        if (! is_numeric($amount) || (float) $amount < 0.01) {
            return $this->err('Amount is required and must be numeric', 400);
        }
        $amount = round((float) $amount, 2);

        $r = $this->sms->matchForApp($amount, $device);
        $device->forceFill(['last_active_at' => now()])->save();

        if ($r['state'] === 'none') {
            return response()->json([
                'success' => true,
                'data'    => [
                    'matched' => false,
                    'order'   => null,
                    'message' => 'No pending order found with amount ' . number_format($amount, 2),
                ],
            ]);
        }

        $tx = $r['tx']->loadMissing('user:id,name');

        return response()->json([
            'success' => true,
            'data'    => [
                'matched' => true,
                'order'   => $this->orders->present($tx, $r['notification']),
                'message' => match ($r['state']) {
                    'confirmed' => 'Found matching order — confirmed',
                    'already'   => 'Order already matched',
                    default     => 'Found matching order',
                },
            ],
        ]);
    }

    /** GET /dashboard-stats?days=7 — shape = RemoteDashboardStats (PaymentApiService.kt). */
    public function dashboardStats(Request $request): JsonResponse
    {
        $days  = max(1, min(90, (int) $request->input('days', 7)));
        $since = now()->subDays($days - 1)->startOfDay();

        $rows = WalletTransaction::where('type', 'topup')
            ->where('created_at', '>=', $since)
            ->get(['id', 'status', 'amount', 'approved_by', 'meta', 'created_at']);

        $isApproved = fn ($t) => $t->status === 'success';
        $isManual   = fn ($t) => $t->approved_by !== null || (((array) $t->meta)['confirmed_via'] ?? null) === 'smschecker_app';
        $isRejected = fn ($t) => $t->status === 'failed' && empty(((array) $t->meta)['expired']);
        $sum        = fn ($c) => round((float) $c->sum(fn ($t) => (float) $t->amount), 2);

        $approved = $rows->filter($isApproved);
        $manual   = $approved->filter($isManual)->count();

        $byDay     = $rows->groupBy(fn ($t) => $t->created_at->format('Y-m-d'));
        $breakdown = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $day  = $byDay->get($date, collect());
            $breakdown[] = [
                'date'     => $date,
                'count'    => $day->count(),
                'approved' => $day->filter($isApproved)->count(),
                'rejected' => $day->filter($isRejected)->count(),
                'amount'   => $sum($day->filter($isApproved)),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'total_orders'      => $rows->count(),
                'auto_approved'     => $approved->count() - $manual,
                'manually_approved' => $manual,
                'pending_review'    => WalletTransaction::where('type', 'topup')->where('status', 'pending')->count(),
                'rejected'          => $rows->filter($isRejected)->count(),
                'total_amount'      => $sum($approved),
                'daily_breakdown'   => $breakdown,
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $pending = SmsPaymentNotification::where('device_id', $device->device_id)
            ->where('status', 'pending')->count();

        return response()->json([
            'success'       => true,
            'status'        => $device->status,
            'pending_count' => $pending,
            'message'       => null,
        ]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $data = $request->validate([
            'device_id'   => 'sometimes|string|max:64',
            'device_name' => 'sometimes|nullable|string|max:120',
            'platform'    => 'sometimes|string|max:16',
            'app_version' => 'sometimes|nullable|string|max:32',
            // Column is VARCHAR(255) — same cap as register-fcm-token.
            'fcm_token'   => 'sometimes|nullable|string|max:255',
        ]);
        $device->forceFill(array_filter([
            'device_name'    => $data['device_name'] ?? $device->device_name,
            'platform'       => $data['platform'] ?? $device->platform,
            'app_version'    => $data['app_version'] ?? $device->app_version,
            'fcm_token'      => ! empty($data['fcm_token']) ? $data['fcm_token'] : $device->fcm_token,
            'ip_address'     => $request->ip(),
            'last_active_at' => now(),
        ], fn ($v) => $v !== null))->save();

        return response()->json(['success' => true, 'message' => 'Device registered successfully']);
    }

    public function registerFcmToken(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $data = $request->validate(['fcm_token' => 'required|string|max:255']);
        $device->forceFill(['fcm_token' => $data['fcm_token']])->save();

        return response()->json(['success' => true, 'message' => 'FCM token registered successfully']);
    }

    public function getDeviceSettings(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');

        return response()->json(['success' => true, 'data' => [
            'approval_mode' => $device->getApprovalMode(),
            'device_id'     => $device->device_id,
            'device_name'   => $device->device_name,
            'server_name'   => SmsOrderPresenter::siteName(),
            'status'        => $device->status,
            // Will an exact SMS match credit the wallet on its own for this device?
            'auto_confirm'  => (bool) config('smschecker.enabled') && $device->autoConfirms(),
        ]]);
    }

    public function updateDeviceSettings(Request $request): JsonResponse
    {
        /** @var SmsCheckerDevice $device */
        $device = $request->attributes->get('sms_checker_device');
        $data = $request->validate([
            // 'smart' is stored as sent (so the app's choice round-trips through
            // GET) and behaves like 'auto' here — SmsCheckerDevice::autoConfirms().
            'approval_mode' => 'sometimes|in:auto,manual,smart',
            'device_name'   => 'sometimes|string|max:120',
        ]);
        if ($data) {
            $device->forceFill($data)->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated',
            'data'    => ['approval_mode' => $device->getApprovalMode()],
        ]);
    }

    /* ============================ helpers ============================ */

    /**
     * Verify headers → replay window → HMAC → decrypt. Shared by /notify and
     * /notify-action (identical crypto to the app's CryptoManager).
     *
     * @return array{0:array,1:string}|JsonResponse  [payload, header nonce]
     */
    private function readSignedPayload(Request $request, SmsCheckerDevice $device): array|JsonResponse
    {
        $signature = $request->header('X-Signature');
        $nonce     = $request->header('X-Nonce');
        $timestamp = $request->header('X-Timestamp');
        if (! $signature || ! $nonce || ! $timestamp) {
            return $this->err('Missing required security headers', 400);
        }

        // Reject stale / clock-skewed requests (replay window).
        $window = (int) config('smschecker.timestamp_window_seconds', 300) * 1000;
        if (! is_numeric($timestamp) || abs((int) (microtime(true) * 1000) - (int) $timestamp) > $window) {
            return $this->err('Request timestamp expired', 400);
        }

        $encrypted = (string) $request->input('data', '');
        if ($encrypted === '') {
            return $this->err('No payload data', 400);
        }

        // HMAC over encrypted_payload + nonce + timestamp.
        if (! $this->sms->verifySignature($encrypted . $nonce . $timestamp, $signature, $device->secret_key)) {
            return $this->err('Invalid signature', 401);
        }

        $payload = $this->sms->decryptPayload($encrypted, $device->secret_key);
        if (! is_array($payload)) {
            return $this->err('Failed to decrypt payload', 400);
        }

        return [$payload, (string) $nonce];
    }

    /** reference_code (TUP-XXXXXXXX, what the app sends as order_number) or numeric id. */
    private function resolveTopup(string $identifier): ?WalletTransaction
    {
        $q = WalletTransaction::where('type', 'topup');

        return ctype_digit($identifier)
            ? $q->whereKey((int) $identifier)->first()
            : $q->where('reference_code', $identifier)->first();
    }

    private function actionOk(string $message, WalletTransaction $tx, ?SmsPaymentNotification $sms = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => ['order' => $this->orders->present($tx, $sms)],
        ]);
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function err(string $message, int $code): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }
}
