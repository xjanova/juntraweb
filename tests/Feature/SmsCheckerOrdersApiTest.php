<?php

namespace Tests\Feature;

use App\Models\SmsCheckerDevice;
use App\Models\SmsPaymentNotification;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SmsCheckerDeviceRequests;
use Tests\TestCase;

/**
 * SMS-gateway endpoints ที่แอพ SmsChecker เรียก (สัญญา section D)
 *
 * แอพเก็บบิลของทุกเว็บไว้ในตารางเดียว — ทุก order ต้องเป็นรูป RemoteOrderApproval
 * เดียวกับ Thaiprompt เป๊ะ และบอกได้ว่าเป็นของ "จันทรา.online"
 *
 * กติกาเงินที่ล็อกไว้:
 *   - GET ที่ยืนยันตัวด้วย X-Api-Key อย่างเดียว (orders/match) ห้ามเครดิตจากยอดเงินล้วน ๆ
 *   - อนุมัติจากแอพต้องมี SMS ที่ลงลายเซ็นมาแล้ว (หรือแอดมินสั่ง force)
 *   - ทุกการเครดิตผ่าน WalletService::confirmTopupAuto (ล็อก + ครั้งเดียว)
 */
class SmsCheckerOrdersApiTest extends TestCase
{
    use RefreshDatabase;
    use SmsCheckerDeviceRequests;

    private const ORDER_KEYS = [
        'id', 'notification_id', 'matched_transaction_id', 'device_id', 'approval_status',
        'cancellation_reason', 'cancellation_reason_label', 'confidence', 'approved_by', 'approved_at',
        'rejected_at', 'rejection_reason', 'order_details_json', 'server_name', 'synced_version',
        'created_at', 'updated_at', 'notification',
    ];

    private function pendingTopup(User $user, float $amount): WalletTransaction
    {
        return app(WalletService::class)->recordPendingTopup($user, $amount, null, 'promptpay');
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function notify(SmsCheckerDevice $device, string $amount)
    {
        $req = $this->notifyRequest($device, [
            'bank' => 'KBANK', 'type' => 'credit', 'amount' => $amount,
            'sender_or_receiver' => 'ผู้โอน', 'reference_number' => 'REF-' . $amount,
            'sms_timestamp' => $this->nowMs(),
        ]);

        return $this->postJson('/api/v1/sms-payment/notify', $req['body'], $req['headers']);
    }

    private function action(SmsCheckerDevice $device, array $payload)
    {
        $req = $this->notifyRequest($device, $payload + ['device_id' => $device->device_id, 'nonce' => 'n-' . uniqid()]);

        return $this->postJson('/api/v1/sms-payment/notify-action', $req['body'], $req['headers']);
    }

    private function assertOrderShape(array $order): void
    {
        $keys = array_keys($order);
        sort($keys);
        $expected = self::ORDER_KEYS;
        sort($expected);
        $this->assertSame($expected, $keys);
        $this->assertSame('จันทรา.online', $order['server_name']);
        $this->assertSame('จันทรา.online', $order['order_details_json']['website_name']);
        $this->assertSame('เติมเครดิตวอลเลต', $order['order_details_json']['product_name']);
        $this->assertNull($order['approved_by'], 'แอพถือว่า approved_by ที่ไม่ใช่ null = แอดมินกดเองในแอพ');
    }

    /* ═══════════════════════════ /notify ═══════════════════════════ */

    public function test_notify_reply_carries_the_matched_order_for_the_app(): void
    {
        $user = User::factory()->create(['name' => 'สมหญิง ใจดี']);
        $tx = $this->pendingTopup($user, 100.37);

        $res = $this->notify($this->device('auto'), '100.37')
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.status', 'confirmed');

        $order = $res->json('data.order');
        $this->assertOrderShape($order);
        $this->assertSame($order, $res->json('data.matched_order'));
        $this->assertSame($tx->id, $order['id']);
        $this->assertSame('auto_approved', $order['approval_status']);
        $this->assertSame($tx->reference_code, $order['order_details_json']['order_number']);
        $this->assertSame(100.37, $order['order_details_json']['amount']); // ตัวเลข ไม่ใช่ string
        $this->assertSame('สมหญิง ใจดี', $order['order_details_json']['customer_name']);
        $this->assertSame('100.37', $order['notification']['amount']);
        $this->assertSame('KBANK', $order['notification']['bank']);
        $this->assertSame($res->json('data.notification_id'), $order['notification_id']);
        $this->assertSame('high', $order['confidence']);
    }

    public function test_notify_in_manual_mode_returns_the_order_awaiting_review(): void
    {
        $tx = $this->pendingTopup(User::factory()->create(), 100.37);

        $res = $this->notify($this->device('manual'), '100.37')
            ->assertOk()
            ->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.order.approval_status', 'pending_review');

        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertStringContainsString('awaiting', $res->json('message'));
    }

    public function test_notify_without_a_match_has_no_order(): void
    {
        $this->notify($this->device('auto'), '55.00')
            ->assertOk()
            ->assertJsonPath('data.matched', false)
            ->assertJsonMissingPath('data.order')
            ->assertJsonMissingPath('data.matched_order');
    }

    /* ═══════════════════════════ /orders ═══════════════════════════ */

    public function test_orders_lists_every_topup_in_the_app_shape(): void
    {
        $user = User::factory()->create();
        $pending = $this->pendingTopup($user, 100.37);
        $paid = $this->pendingTopup($user, 50.11);
        app(WalletService::class)->confirmTopupAuto($paid, ['confirmed_via' => 'sms']);
        $gone = $this->pendingTopup($user, 20.05);
        app(WalletService::class)->cancelTopup($gone, $user);
        $device = $this->device();

        $res = $this->getJson('/api/v1/sms-payment/orders?status=all&per_page=50', $this->deviceHeaders($device))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.last_page', 1);

        $byId = collect($res->json('data.data'))->keyBy('id');
        foreach ($byId as $order) {
            $this->assertOrderShape($order);
        }
        $this->assertSame('pending_review', $byId[$pending->id]['approval_status']);
        $this->assertSame('medium', $byId[$pending->id]['confidence']);
        $this->assertSame('PROMPTPAY', $byId[$pending->id]['notification']['bank']);
        $this->assertSame('auto_approved', $byId[$paid->id]['approval_status']);
        $this->assertNotNull($byId[$paid->id]['approved_at']);
        $this->assertSame('cancelled', $byId[$gone->id]['approval_status']);
        $this->assertSame('user_cancelled', $byId[$gone->id]['cancellation_reason']);
    }

    public function test_orders_default_waiting_shows_open_and_just_paid_bills_only(): void
    {
        $user = User::factory()->create();
        $open = $this->pendingTopup($user, 100.37);
        $justPaid = $this->pendingTopup($user, 100.38);
        app(WalletService::class)->confirmTopupAuto($justPaid, []);
        $oldPaid = $this->pendingTopup($user, 100.39);
        app(WalletService::class)->confirmTopupAuto($oldPaid, []);
        WalletTransaction::whereKey($oldPaid->id)->update(['approved_at' => now()->subHours(3)]);
        $rejected = $this->pendingTopup($user, 100.40);
        app(WalletService::class)->rejectTopup($rejected, User::factory()->create(['role' => 'admin']), 'x');

        $ids = collect($this->getJson('/api/v1/sms-payment/orders', $this->deviceHeaders($this->device()))
            ->assertOk()->json('data.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$open->id, $justPaid->id], $ids);
    }

    public function test_orders_need_a_device_api_key(): void
    {
        $this->getJson('/api/v1/sms-payment/orders')->assertStatus(401);
        $this->getJson('/api/v1/sms-payment/orders/match?amount=1.00', ['X-Api-Key' => 'nope'])->assertStatus(401);
    }

    public function test_orders_sync_returns_changes_since_the_apps_version(): void
    {
        $user = User::factory()->create();
        $old = $this->pendingTopup($user, 100.37);
        $new = $this->pendingTopup($user, 100.38);
        WalletTransaction::whereKey($old->id)->update(['updated_at' => now()->subMinutes(10)]);
        $device = $this->device();

        $since = now()->subMinutes(5)->getTimestampMs();
        $res = $this->getJson("/api/v1/sms-payment/orders/sync?since_version={$since}", $this->deviceHeaders($device))
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame([$new->id], collect($res->json('data.orders'))->pluck('id')->all());
        $this->assertIsInt($res->json('data.latest_version'));
        $this->assertGreaterThanOrEqual($since, $res->json('data.latest_version'));
        $this->assertOrderShape($res->json('data.orders.0'));

        $all = $this->getJson('/api/v1/sms-payment/orders/sync?since_version=0', $this->deviceHeaders($device))->assertOk();
        $this->assertCount(2, $all->json('data.orders'));
    }

    /* ═══════════════════════════ /orders/match ═══════════════════════════ */

    /** X-Api-Key อย่างเดียวห้ามเคลื่อนเงิน — บอกว่าเจอบิล แต่ให้ /notify ที่ลงลายเซ็นเป็นคนเครดิต */
    public function test_match_reports_the_bill_but_never_credits_on_amount_alone(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);

        $res = $this->getJson('/api/v1/sms-payment/orders/match?amount=100.37', $this->deviceHeaders($this->device('auto')))
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.order.id', $tx->id)
            ->assertJsonPath('data.order.approval_status', 'pending_review');

        $this->assertOrderShape($res->json('data.order'));
        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, app(WalletService::class)->balance($user));
    }

    public function test_match_confirms_when_a_signed_sms_already_exists_in_auto_mode(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        $device = $this->device('auto');
        $sms = SmsPaymentNotification::create([
            'device_id' => $device->device_id, 'bank' => 'SCB', 'type' => 'credit', 'amount' => '100.37',
            'sms_timestamp' => now()->addSecond(), 'status' => 'pending', 'nonce' => 'signed-earlier',
        ]);

        $this->getJson('/api/v1/sms-payment/orders/match?amount=100.37', $this->deviceHeaders($device))
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.order.approval_status', 'auto_approved')
            ->assertJsonPath('data.order.notification_id', $sms->id);

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(100.37, app(WalletService::class)->balance($user));
        $this->assertSame('confirmed', $sms->fresh()->status);
        $this->assertSame($tx->id, (int) $sms->fresh()->matched_transaction_id);
    }

    public function test_match_never_confirms_in_manual_mode(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        $device = $this->device('manual');
        SmsPaymentNotification::create([
            'device_id' => $device->device_id, 'type' => 'credit', 'amount' => '100.37',
            'sms_timestamp' => now()->addSecond(), 'status' => 'pending', 'nonce' => 'signed-earlier',
        ]);

        $this->getJson('/api/v1/sms-payment/orders/match?amount=100.37', $this->deviceHeaders($device))
            ->assertOk()->assertJsonPath('data.order.approval_status', 'pending_review');
        $this->assertSame('pending', $tx->fresh()->status);
    }

    /** SMS เข้าไปแล้วผ่าน /notify → ยังต้องบอกแอพว่าเงินก้อนนี้ "ของเว็บนี้" (attribution) */
    public function test_match_after_notify_reports_already_matched(): void
    {
        $tx = $this->pendingTopup(User::factory()->create(), 100.37);
        $device = $this->device('auto');
        $this->notify($device, '100.37')->assertOk();

        $this->getJson('/api/v1/sms-payment/orders/match?amount=100.37', $this->deviceHeaders($device))
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.message', 'Order already matched')
            ->assertJsonPath('data.order.id', $tx->id)
            ->assertJsonPath('data.order.approval_status', 'auto_approved');
    }

    public function test_match_is_false_for_unknown_expired_or_round_amounts(): void
    {
        $user = User::factory()->create();
        $expired = $this->pendingTopup($user, 100.37);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $device = $this->device();

        foreach (['100.37', '42.42', '100.00'] as $amount) {
            $this->getJson("/api/v1/sms-payment/orders/match?amount={$amount}", $this->deviceHeaders($device))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.matched', false)
                ->assertJsonPath('data.order', null);
        }

        $this->getJson('/api/v1/sms-payment/orders/match', $this->deviceHeaders($device))->assertStatus(400);
        $this->getJson('/api/v1/sms-payment/orders/match?amount=abc', $this->deviceHeaders($device))->assertStatus(400);
    }

    /* ═══════════════════════════ /notify-action ═══════════════════════════ */

    public function test_approve_without_a_matching_sms_is_refused(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);

        $this->action($this->device('auto'), [
            'action' => 'approve', 'order_identifier' => $tx->reference_code, 'amount' => 100.37,
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'NO_VALID_SMS_FOR_TRANSACTION');

        $this->assertSame('pending', $tx->fresh()->status);
        $this->assertSame(0.0, app(WalletService::class)->balance($user));
    }

    /** โหมดตรวจเอง: SMS เข้ามาแปะบิลไว้ → แอดมินกดอนุมัติในแอพ → เครดิตครั้งเดียว */
    public function test_approve_with_the_stamped_sms_credits_once(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        $device = $this->device('manual');
        $this->notify($device, '100.37')->assertJsonPath('data.status', 'matched');

        $res = $this->action($device, [
            'action' => 'approve', 'order_identifier' => $tx->reference_code, 'amount' => 100.37, 'bank' => 'KBANK',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order approved successfully')
            ->assertJsonPath('data.order.approval_status', 'manually_approved');

        $this->assertOrderShape($res->json('data.order'));
        $fresh = $tx->fresh();
        $this->assertSame('success', $fresh->status);
        $this->assertSame('smschecker_app', $fresh->meta['confirmed_via']);
        $this->assertSame($device->device_id, $fresh->meta['device_id']);
        $this->assertSame(100.37, app(WalletService::class)->balance($user));
        $this->assertSame('confirmed', SmsPaymentNotification::where('matched_transaction_id', $tx->id)->value('status'));

        // กดซ้ำ (offline queue ของแอพ) → สำเร็จแบบ idempotent ไม่เครดิตซ้ำ
        $this->action($device, ['action' => 'approve', 'order_identifier' => (string) $tx->id, 'amount' => 100.37])
            ->assertOk()->assertJsonPath('message', 'Order already approved');
        $this->assertSame(100.37, app(WalletService::class)->balance($user));
    }

    public function test_force_approve_is_an_explicit_admin_override(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);

        $this->action($this->device('auto'), [
            'action' => 'approve', 'order_identifier' => $tx->reference_code, 'amount' => 100.37, 'force' => true,
        ])->assertOk()->assertJsonPath('data.order.approval_status', 'manually_approved');

        $this->assertTrue($tx->fresh()->meta['force_approved']);
        $this->assertSame(100.37, app(WalletService::class)->balance($user));
    }

    public function test_reject_from_the_app_moves_no_money_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        $device = $this->device('auto');

        $res = $this->action($device, [
            'action' => 'reject', 'order_identifier' => $tx->reference_code, 'amount' => 100.37, 'reason' => 'ยอดไม่ตรง',
        ])->assertOk()
            ->assertJsonPath('message', 'Order rejected')
            ->assertJsonPath('data.order.approval_status', 'rejected')
            ->assertJsonPath('data.order.rejection_reason', 'ยอดไม่ตรง');

        $this->assertOrderShape($res->json('data.order'));
        $fresh = $tx->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('smschecker_app', $fresh->meta['rejected_via']);
        $this->assertSame($device->device_id, $fresh->meta['rejected_by_device']);
        $this->assertNull($fresh->approved_by);
        $this->assertSame(0.0, app(WalletService::class)->balance($user));

        $this->action($device, ['action' => 'reject', 'order_identifier' => $tx->reference_code, 'amount' => 100.37])
            ->assertOk()->assertJsonPath('message', 'Order already rejected');
    }

    public function test_a_credited_topup_cannot_be_rejected_from_the_app(): void
    {
        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        app(WalletService::class)->confirmTopupAuto($tx, []);

        $this->action($this->device(), ['action' => 'reject', 'order_identifier' => $tx->reference_code, 'amount' => 100.37])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(100.37, app(WalletService::class)->balance($user));
    }

    public function test_notify_action_security_and_lookup_errors(): void
    {
        $tx = $this->pendingTopup(User::factory()->create(), 100.37);
        $device = $this->device();

        // nonce เดิมซ้ำ → 409 (แอพถือว่าทำไปแล้ว)
        $req = $this->notifyRequest($device, ['action' => 'reject', 'order_identifier' => $tx->reference_code,
            'amount' => 100.37, 'device_id' => $device->device_id, 'nonce' => 'n1']);
        $this->postJson('/api/v1/sms-payment/notify-action', $req['body'], $req['headers'])->assertOk();
        $this->postJson('/api/v1/sms-payment/notify-action', $req['body'], $req['headers'])->assertStatus(409);

        // ลายเซ็นปลอม
        $bad = $this->notifyRequest($device, ['action' => 'approve', 'order_identifier' => $tx->reference_code, 'amount' => 1]);
        $bad['headers']['X-Signature'] = base64_encode('forged');
        $this->postJson('/api/v1/sms-payment/notify-action', $bad['body'], $bad['headers'])->assertStatus(401);

        $this->action($device, ['action' => 'approve', 'order_identifier' => 'TUP-NOPE0000', 'amount' => 1])->assertStatus(404);
        $this->action($device, ['action' => 'void', 'order_identifier' => $tx->reference_code, 'amount' => 1])->assertStatus(422);
        $this->action($device, ['action' => 'approve', 'amount' => 1])->assertStatus(422);
    }

    /* ═══════════════════════════ stats / settings / register ═══════════════════════════ */

    public function test_dashboard_stats_match_the_app_model(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);
        $auto = $this->pendingTopup($user, 100.37);
        $wallet->confirmTopupAuto($auto, ['confirmed_via' => 'sms']);
        $manual = $this->pendingTopup($user, 50.11);
        $wallet->confirmTopupAuto($manual, ['confirmed_via' => 'smschecker_app']);
        $this->pendingTopup($user, 20.05);
        $rejected = $this->pendingTopup($user, 30.07);
        $wallet->rejectTopupFromDevice($rejected, 'SMSCHK-TESTONLY', 'x');
        $expired = $this->pendingTopup($user, 40.09);
        $expired->update(['status' => 'failed', 'meta' => ['expired' => true]]);

        $res = $this->getJson('/api/v1/sms-payment/dashboard-stats?days=7', $this->deviceHeaders($this->device()))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_orders', 5)
            ->assertJsonPath('data.auto_approved', 1)
            ->assertJsonPath('data.manually_approved', 1)
            ->assertJsonPath('data.pending_review', 1)
            ->assertJsonPath('data.rejected', 1);

        $this->assertEqualsWithDelta(150.48, $res->json('data.total_amount'), 0.001);
        $days = $res->json('data.daily_breakdown');
        $this->assertCount(7, $days);
        $this->assertSame(['date', 'count', 'approved', 'rejected', 'amount'], array_keys($days[6]));
        $this->assertSame(now()->format('Y-m-d'), $days[6]['date']);
        $this->assertSame(5, $days[6]['count']);
        $this->assertSame(2, $days[6]['approved']);
    }

    public function test_device_settings_expose_site_and_accept_smart_mode(): void
    {
        $device = $this->device('manual');
        $h = $this->deviceHeaders($device);

        $this->getJson('/api/v1/sms-payment/device-settings', $h)
            ->assertOk()
            ->assertJsonPath('data.approval_mode', 'manual')
            ->assertJsonPath('data.device_name', 'Test device')
            ->assertJsonPath('data.server_name', 'จันทรา.online')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.auto_confirm', false);

        $this->putJson('/api/v1/sms-payment/device-settings', ['approval_mode' => 'smart'], $h)
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('message', 'Settings updated');

        // เก็บ 'smart' ตามที่แอพส่ง (แอพ sync กลับไม่เด้งเป็นค่าอื่น) แต่ทำงานแบบ auto
        $this->getJson('/api/v1/sms-payment/device-settings', $h)
            ->assertJsonPath('data.approval_mode', 'smart')
            ->assertJsonPath('data.auto_confirm', true);

        $user = User::factory()->create();
        $tx = $this->pendingTopup($user, 100.37);
        $this->notify($device->fresh(), '100.37')->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('success', $tx->fresh()->status);

        $this->putJson('/api/v1/sms-payment/device-settings', ['approval_mode' => 'turbo'], $h)->assertStatus(422);
    }

    public function test_register_device_stores_the_fcm_token(): void
    {
        $device = $this->device();

        $this->postJson('/api/v1/sms-payment/register-device', [
            'device_id'   => $device->device_id,
            'device_name' => 'Pixel 8',
            'platform'    => 'android',
            'app_version' => '2.9.0',
            'fcm_token'   => 'fcm-token-' . str_repeat('x', 140),
        ], ['X-Api-Key' => $device->api_key])
            ->assertOk()->assertJsonPath('success', true);

        $fresh = $device->fresh();
        $this->assertSame('Pixel 8', $fresh->device_name);
        $this->assertSame('2.9.0', $fresh->app_version);
        $this->assertStringStartsWith('fcm-token-', $fresh->fcm_token);
    }
}
