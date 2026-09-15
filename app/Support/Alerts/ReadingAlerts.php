<?php

namespace App\Support\Alerts;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\WalletTransaction;
use App\Support\AdminAlerts;
use Throwable;

/**
 * "มีคนดูดวง" — every reading, silently (INFO: it lands in the chat without a buzz), so the owner can
 * watch the shop floor without being woken by it. A failed reading that had to be refunded is louder:
 * two in a row usually means the AI upstream is down and every customer is getting refunds.
 *
 * Deliberately never includes the customer's question — that is their private matter, and a
 * Telegram chat is not the place to copy it to.
 */
final class ReadingAlerts
{
    /** A reading was created. The card is built after the response, once the result is written. */
    public static function created(Reading $reading): void
    {
        try {
            if (! AdminAlerts::wants('readings')) {
                return;
            }
            AdminAlerts::afterResponse(function () use ($reading) {
                $r = $reading->fresh(['user']) ?? $reading;
                $label = Reports::readingLabel($r->type);
                $cost = (float) (((array) $r->payload)['cost'] ?? 0);
                if ($cost <= 0 && ($txId = ((array) $r->payload)['wallet_tx_id'] ?? null)) {
                    $cost = abs((float) WalletTransaction::whereKey($txId)->value('amount'));
                }

                AdminAlerts::send(new Alert(
                    key: 'reading:' . $r->id,
                    level: Alert::INFO,
                    title: 'มีคนดูดวง: ' . $label,
                    body: $r->result === null || $r->result === ''
                        ? 'กำลังทำนาย…'
                        : 'ทำนายเสร็จแล้ว' . ($r->ai_provider ? ' (' . $r->ai_provider . ')' : ''),
                    facts: array_filter([
                        'ลูกค้า' => $r->user ? mb_substr((string) $r->user->name, 0, 40) : 'ผู้เยี่ยมชม',
                        'บริการ' => $label,
                        'ราคา' => $cost > 0 ? '฿' . number_format($cost, 2) : 'ฟรี',
                        'วันนี้' => number_format(Reading::where('created_at', '>=', now('Asia/Bangkok')->startOfDay()->setTimezone(config('app.timezone')))->count()) . ' ครั้ง',
                    ]),
                    url: url('/admin/readings/' . $r->id),
                    urlLabel: 'ดูคำทำนายนี้',
                    category: 'readings',
                ), 60);
            }, 'reading-alert:' . $reading->id);
        } catch (Throwable) {
        }
    }

    /** A new conversation with แม่หมอ — once per customer per 6 hours, silently. */
    public static function chatStarted(ChatConversation $c): void
    {
        try {
            if (! AdminAlerts::wants('readings') || ! $c->user_id) {
                return;
            }
            $name = mb_substr((string) ($c->user?->name ?? ('#' . $c->user_id)), 0, 40);
            AdminAlerts::send(new Alert(
                key: 'chat-start:' . $c->user_id,
                level: Alert::INFO,
                title: 'มีคนเริ่มคุยกับแม่หมอ',
                body: $name . ' เปิดห้องสนทนาใหม่',
                facts: ['ลูกค้า' => $name],
                url: url('/admin'),
                urlLabel: 'เปิดหน้าแอดมิน',
                category: 'readings',
            ), 360);
        } catch (Throwable) {
        }
    }

    /** A paid reading failed and the credit went back. Loud only when it keeps happening. */
    public static function refunded(WalletTransaction $refund): void
    {
        try {
            if (! AdminAlerts::wants('readings')) {
                return;
            }
            $lastHour = WalletTransaction::where('type', 'refund')->where('created_at', '>=', now()->subHour())->count();
            AdminAlerts::send(new Alert(
                key: 'reading-refund',
                level: $lastHour >= 3 ? Alert::WARNING : Alert::INFO,
                title: $lastHour >= 3 ? 'ดูดวงล้มเหลวซ้ำ ๆ — คืนเครดิตไป ' . $lastHour . ' ครั้งในชั่วโมงนี้' : 'ดูดวงล้มเหลว คืนเครดิตให้ลูกค้าแล้ว',
                body: mb_substr((string) $refund->description, 0, 160)
                    . ($lastHour >= 3 ? "\nน่าจะเป็นที่ระบบ AI/Thaiprompt — ตรวจคีย์และสถานะ upstream" : ''),
                facts: array_filter([
                    'ลูกค้า' => $refund->user ? mb_substr((string) $refund->user->name, 0, 40) : null,
                    'คืน' => '฿' . number_format((float) $refund->amount, 2),
                    'ชั่วโมงนี้' => $lastHour . ' ครั้ง',
                ]),
                url: url('/admin/wallet-transactions/' . $refund->id),
                urlLabel: 'ดูรายการ',
                category: 'readings',
            ), 30);
        } catch (Throwable) {
        }
    }
}
