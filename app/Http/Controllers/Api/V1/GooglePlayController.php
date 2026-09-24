<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GooglePlay\GooglePlayBilling;
use App\Services\GooglePlay\RedeemRejected;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * เติมเครดิตจากแอพช่อง Google Play — ดู App\Services\GooglePlay\GooglePlayBilling
 *
 * GET  /v1/wallet/google-play         แพ็กที่ขาย + รหัสบัญชีแบบปิดบัง (ส่งให้ Google ตอนซื้อ)
 * POST /v1/wallet/google-play/redeem  ส่ง purchase token มาให้ตรวจ → เติมเครดิต (ครั้งเดียวต่อ token)
 */
class GooglePlayController extends Controller
{
    public function __construct(
        private GooglePlayBilling $billing,
        private WalletService $wallet,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $enabled = $this->billing->enabled();

        return response()->json(['data' => [
            'enabled'    => $enabled,
            // ปิดอยู่ = แอพไม่แสดงหน้าซื้อเลย (ใช้เครดิตที่มีได้อย่างเดียว) — ไม่มีการชี้ไปจ่ายช่องทางอื่น
            'products'   => $enabled
                ? collect($this->billing->products())
                    ->map(fn (float $credits, string $id) => ['product_id' => $id, 'credits' => $credits])
                    ->values()
                : [],
            'account_id' => $this->billing->accountIdFor($user),
            'balance'    => (float) $this->wallet->balance($user),
        ]]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id'     => 'required|string|max:100',
            'purchase_token' => 'required|string|min:10|max:4096',
        ]);
        $user = $request->user();

        try {
            $result = $this->billing->redeem($user, $data['product_id'], $data['purchase_token']);
        } catch (RedeemRejected $e) {
            return response()->json([
                'message'     => $e->getMessage(),
                'reason_code' => $e->reasonCode,
            ], $e->status);
        }

        $purchase = $result['purchase'];

        if ($result['state'] === 'pending') {
            // จ่ายแบบรอชำระ — แอพเก็บการซื้อไว้ (ไม่ consume) แล้วส่งมาใหม่เมื่อ Play แจ้งว่าจ่ายแล้ว
            return response()->json([
                'data' => [
                    'state'   => 'pending',
                    'message' => 'รอการชำระเงินกับ Google Play — เครดิตจะเข้าอัตโนมัติเมื่อชำระเรียบร้อย',
                    'balance' => (float) $this->wallet->balance($user),
                ],
            ], 202);
        }

        return response()->json([
            'data' => [
                'state'       => $result['state'],   // credited | already
                'credits'     => (float) $purchase->credits,
                'consumed'    => $purchase->consumed_at !== null,
                'purchase_id' => $purchase->id,
                'balance'     => (float) $this->wallet->balance($user),
                'message'     => $result['state'] === 'credited'
                    ? 'เติมเครดิต ' . rtrim(rtrim(number_format((float) $purchase->credits, 2), '0'), '.') . ' เครดิตเรียบร้อยแล้วค่ะ'
                    : 'การซื้อนี้เติมเครดิตเข้าบัญชีแล้ว',
            ],
        ], $result['state'] === 'credited' ? 201 : 200);
    }
}
