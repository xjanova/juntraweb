<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'pricing'             => [
                    'tarot_three'   => Pricing::for('tarot_three'),
                    'tarot_celtic'  => Pricing::for('tarot_celtic'),
                    'numerology'    => Pricing::for('numerology'),
                    'palmistry'     => Pricing::for('palmistry'),
                    'auspicious'    => Pricing::for('auspicious'),
                    'chat_message'  => Pricing::for('chat_message'),
                ],
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
    public function topupPromptPay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:' . config('pricing.min_topup', 20)
                                  . '|max:' . config('pricing.max_topup', 50000),
        ]);

        $tx = $this->wallet->recordPendingTopup(
            $request->user(),
            (float) $data['amount'],
            null,
            'promptpay',
        );

        return response()->json([
            'data' => [
                'transaction'    => $this->txPayload($tx),
                'promptpay'      => [
                    'id'   => Setting::get('promptpay_id', config('pricing.promptpay_id')),
                    'name' => Setting::get('promptpay_name', config('pricing.promptpay_name')),
                ],
                'slip_upload_url' => url('/wallet/topup/' . $tx->id),
                'instructions'    => 'โอนตามจำนวนแล้วอัปโหลดสลิปที่หน้าเว็บลิงก์ slip_upload_url '
                                  . 'หลังโอนสำเร็จแอดมินจะอนุมัติภายในไม่กี่นาที',
            ],
        ], 201);
    }

    public function topupShow(Request $request, int $tx): JsonResponse
    {
        $row = WalletTransaction::where('id', $tx)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'data' => array_merge($this->txPayload($row), [
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
