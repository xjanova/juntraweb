<?php

namespace App\Support\Alerts;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\AdminAlerts;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Money alerts: a top-up credited (by SMS, by slip, by an admin), a slip waiting for a human, a
 * slip that had already been spent, and admin corrections to a wallet.
 *
 * Every method is fire-and-forget: it never throws, and in a web request the card is drawn and sent
 * after the response (AdminAlerts::send defers), so a customer never waits on the owner's phone.
 */
final class WalletAlerts
{
    public static function subject(WalletTransaction $tx): string
    {
        return 'topup:' . $tx->id;
    }

    /** Money in: the wallet was credited for a top-up. Replaces the "waiting" card when there was one. */
    public static function credited(WalletTransaction $tx): void
    {
        try {
            if (! AdminAlerts::wants('money')) {
                return;
            }
            $via = Reports::topupVia($tx);
            $meta = (array) $tx->meta;
            $facts = array_filter([
                'ลูกค้า' => self::who($tx),
                'ช่องทาง' => $via,
                'คงเหลือ' => $tx->balance_after !== null ? '฿' . number_format((float) $tx->balance_after, 2) : null,
                'อ้างอิง' => $tx->reference_code,
            ]);
            $body = match ($via) {
                'SMS ธนาคาร' => 'SMS ธนาคารยืนยันยอดตรงเป๊ะกับรายการนี้ — เครดิตเข้าวอลเลตแล้ว',
                'ตรวจสลิปอัตโนมัติ' => 'สลิปผ่าน SlipOK + ไม่ซ้ำทั้งเว็บและแม่หมอ + เข้าบัญชีร้าน — เครดิตเข้าวอลเลตแล้ว'
                    . (! empty($meta['sender_name']) ? "\nผู้โอน: " . $meta['sender_name'] : ''),
                'แอดมินอนุมัติ' => 'อนุมัติโดย ' . ($tx->approver?->name ?? ('#' . $tx->approved_by)),
                default => 'เครดิตเข้าวอลเลตแล้ว',
            };
            if (($slipAmount = (float) ($meta['slip_amount'] ?? $tx->slip_amount ?? 0)) > (float) $tx->amount + 0.99) {
                $body .= "\nลูกค้าโอนมา ฿" . number_format($slipAmount, 2) . ' มากกว่ายอดที่ต้องโอน — ถ้าจะเติมส่วนต่างให้ ใช้ปุ่ม "ปรับยอดผู้ใช้"';
            }

            $alert = new Alert(
                key: 'topup-credited:' . $tx->id,
                level: Alert::MONEY,
                title: 'เงินเข้า ฿' . number_format((float) $tx->amount, 2) . ' — เติมวอลเลตแล้ว',
                body: $body,
                facts: $facts,
                url: self::adminUrl($tx),
                urlLabel: 'ดูรายการนี้',
                category: 'money',
            );

            // A slip that was waiting for a human now shows as done — edit that card instead of
            // leaving a stale "รอตรวจ" in the chat next to a new one.
            if (AdminAlerts::messagesFor(self::subject($tx)) !== []) {
                AdminAlerts::refresh(self::subject($tx), $alert);

                return;
            }
            AdminAlerts::send($alert, 1440, subject: self::subject($tx));
        } catch (Throwable) {
        }
    }

    /**
     * A slip the system could not confirm on its own — it stays "รอแอดมินอนุมัติ" and the owner gets
     * the card plus the slip photo itself, so it can be judged from the phone.
     *
     * @param  array  $slip  what SlipOK read, when it read anything (amount, sender_name, trans_ref…)
     */
    public static function needsReview(?WalletTransaction $tx, string $reason, array $slip = []): void
    {
        try {
            if (! $tx || ! AdminAlerts::wants('money')) {
                return;
            }
            $photo = null;
            if ($tx->slip_path && Storage::disk('local')->exists($tx->slip_path)) {
                $photo = Storage::disk('local')->path($tx->slip_path);
            }

            AdminAlerts::send(new Alert(
                key: 'topup-review:' . $tx->id . ':' . substr(sha1($reason), 0, 8),
                level: Alert::WARNING,
                title: 'สลิปรอแอดมินตรวจ ฿' . number_format((float) $tx->amount, 2),
                body: 'สาเหตุ: ' . $reason . "\nระบบยังไม่เครดิตให้ — เปิดรายการนี้ในหน้าแอดมินแล้วกดอนุมัติหรือปฏิเสธ",
                facts: array_filter([
                    'ลูกค้า' => self::who($tx),
                    'ต้องโอน' => '฿' . number_format((float) $tx->amount, 2),
                    'ในสลิป' => isset($slip['amount']) && $slip['amount'] !== null ? '฿' . number_format((float) $slip['amount'], 2) : null,
                    'ผู้โอน' => ! empty($slip['sender_name']) ? mb_substr((string) $slip['sender_name'], 0, 30) : null,
                ]),
                url: self::adminUrl($tx),
                urlLabel: 'เปิดรายการเพื่ออนุมัติ',
                category: 'money',
                photo: $photo,
            ), 30, subject: self::subject($tx));
        } catch (Throwable) {
        }
    }

    /** Someone tried to spend a slip that was already used — here, or at แม่หมอ. */
    public static function duplicateBlocked(?User $user, ?WalletTransaction $tx, string $where, ?string $transRef, ?float $amount): void
    {
        try {
            if (! AdminAlerts::wants('security')) {
                return;
            }
            AdminAlerts::send(new Alert(
                key: 'slip-dup:' . ($user?->id ?? 0) . ':' . ($transRef ?? ($tx?->id ?? 'x')),
                level: Alert::WARNING,
                title: 'บล็อกสลิปที่ถูกใช้ไปแล้ว',
                body: 'มีคนแนบสลิปที่เคยใช้ไปแล้วที่ ' . $where . " — ระบบไม่เครดิตให้\n"
                    . 'ถ้าเป็นลูกค้าจริงที่ส่งซ้ำโดยไม่ตั้งใจ ไม่ต้องทำอะไร · ถ้าเจอบ่อยจากบัญชีเดิม ควรตรวจสอบ',
                facts: array_filter([
                    'ลูกค้า' => $user ? self::name($user) : null,
                    'เคยใช้ที่' => $where,
                    'ยอดในสลิป' => $amount !== null ? '฿' . number_format($amount, 2) : null,
                    'เลขอ้างอิง' => $transRef ? self::mask($transRef) : null,
                ]),
                url: $tx ? self::adminUrl($tx) : url('/admin'),
                urlLabel: 'ดูรายการ',
                category: 'security',
            ), 360, subject: $tx ? self::subject($tx) : null);
        } catch (Throwable) {
        }
    }

    /** An admin rejected a pending top-up: the waiting card shows the outcome instead. */
    public static function rejected(WalletTransaction $tx): void
    {
        try {
            if (! AdminAlerts::enabled() || AdminAlerts::messagesFor(self::subject($tx)) === []) {
                return;
            }
            $reason = (string) (((array) $tx->meta)['reject_reason'] ?? '');
            AdminAlerts::refresh(self::subject($tx), new Alert(
                key: 'topup-rejected:' . $tx->id,
                level: Alert::INFO,
                title: 'ปฏิเสธการเติมเงิน ฿' . number_format((float) $tx->amount, 2) . ' แล้ว',
                body: 'โดย ' . ($tx->approver?->name ?? ('#' . $tx->approved_by)) . ($reason !== '' ? "\nเหตุผล: " . $reason : ''),
                facts: array_filter(['ลูกค้า' => self::who($tx), 'อ้างอิง' => $tx->reference_code]),
                url: self::adminUrl($tx),
                urlLabel: 'ดูรายการนี้',
                category: 'money',
            ));
        } catch (Throwable) {
        }
    }

    /** An admin changed a balance by hand (promo credit, correction, clawing back a wrong top-up). */
    public static function adjusted(WalletTransaction $adj): void
    {
        try {
            if (! AdminAlerts::wants('money')) {
                return;
            }
            $amount = (float) $adj->amount;
            $reverse = $adj->reference_type === 'wallet_transaction';
            $reason = (string) (((array) $adj->meta)['reason'] ?? '');

            AdminAlerts::send(new Alert(
                key: 'wallet-adjust:' . $adj->id,
                level: Alert::INFO,
                title: ($reverse ? 'เรียกคืนการเติมเงิน ' : ($amount >= 0 ? 'แอดมินเพิ่มเครดิต ' : 'แอดมินหักเครดิต '))
                    . '฿' . number_format(abs($amount), 2),
                body: 'โดย ' . ($adj->approver?->name ?? ('#' . $adj->approved_by)) . ($reason !== '' ? "\nเหตุผล: " . $reason : ''),
                facts: array_filter([
                    'ลูกค้า' => self::who($adj),
                    'คงเหลือ' => $adj->balance_after !== null ? '฿' . number_format((float) $adj->balance_after, 2) : null,
                    'หักไม่พอ' => ! empty(((array) $adj->meta)['shortfall']) ? '฿' . ((array) $adj->meta)['shortfall'] : null,
                ]),
                url: self::adminUrl($adj),
                urlLabel: 'ดูรายการนี้',
                category: 'money',
            ), 1440);
        } catch (Throwable) {
        }
    }

    private static function who(WalletTransaction $tx): string
    {
        return $tx->user ? self::name($tx->user) : ('#' . $tx->user_id);
    }

    private static function name(User $user): string
    {
        return mb_substr((string) ($user->name ?: ('สมาชิก #' . $user->id)), 0, 40);
    }

    /** 0123456789ABCDEF → 0123…CDEF — enough to find it in a bank statement, not to reuse it. */
    private static function mask(string $ref): string
    {
        return strlen($ref) > 10 ? substr($ref, 0, 4) . '…' . substr($ref, -4) : $ref;
    }

    private static function adminUrl(WalletTransaction $tx): string
    {
        return url('/admin/wallet-transactions/' . $tx->id);
    }
}
