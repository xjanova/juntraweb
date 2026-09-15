<?php

namespace App\Support\Alerts;

use App\Support\AdminAlerts;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Throwable;

/**
 * Tells the owner when the site throws — hooked into the exception handler, so it sees every
 * reported error: a page answering 500, a scheduled command dying. (Queued jobs are the exception:
 * see [self::inQueueWorker].) HTTP errors (404, 403, 429) are not reported by Laravel at all, so
 * they never reach here.
 *
 * Deliberately stingy. The same error (same class, same line, same message shape) re-alerts at
 * most every 6 hours, and never more than 6 error alerts go out in any hour — a broken deploy
 * throws on every request at once, and one card saying so is the whole message.
 *
 * Never throws, and never delays a visitor: in a web request AdminAlerts sends after the response.
 * Ported from NetWix.
 */
final class ErrorAlert
{
    private const REPEAT_MINUTES = 360;

    private const HOURLY_CAP = 6;

    public static function report(Throwable $e): void
    {
        try {
            if ($e instanceof CommandNotFoundException || self::inQueueWorker() || ! AdminAlerts::wants('system')) {
                return;
            }
            $fingerprint = self::fingerprint($e);
            if (! Cache::add('alert:error:' . $fingerprint, 1, now()->addMinutes(self::REPEAT_MINUTES))) {
                return;
            }
            $slot = 'alert:error:hour:' . now()->format('YmdH');
            Cache::add($slot, 0, now()->addHours(2));
            if (Cache::increment($slot) > self::HOURLY_CAP) {
                return;
            }

            // Built now, while the request is still here to describe; in a web request send() itself
            // delivers after the response, so a visitor who just got a 500 doesn't also wait on us.
            AdminAlerts::send(self::build($e, $fingerprint), 1);    // the gate above is the real throttle
        } catch (Throwable) {
            // The error handler is the last place allowed to fail.
        }
    }

    /**
     * A queue worker reports every failed ATTEMPT, and most jobs retry — the first try failing is not
     * news. Jobs that fail for good land in failed_jobs, which `alerts:watchdog` reports as a digest.
     */
    private static function inQueueWorker(): bool
    {
        return app()->runningInConsole() && in_array((string) ($_SERVER['argv'][1] ?? ''), ['queue:work', 'queue:listen'], true);
    }

    /** Same class + same throw site + same message shape (numbers and quoted values blanked). */
    private static function fingerprint(Throwable $e): string
    {
        $shape = preg_replace(['~\d+~', "~'[^']*'~", '~"[^"]*"~'], ['#', "'?'", '"?"'], $e->getMessage()) ?? '';

        return substr(sha1($e::class . '|' . $e->getFile() . ':' . $e->getLine() . '|' . mb_substr($shape, 0, 200)), 0, 16);
    }

    private static function build(Throwable $e, string $fingerprint): Alert
    {
        $console = app()->runningInConsole();
        if ($console) {
            $args = array_slice((array) ($_SERVER['argv'] ?? []), 1, 3);
            $where = $args !== [] ? implode(' ', $args) : 'คำสั่งเบื้องหลัง';
        } else {
            $req = request();
            $where = $req->method() . ' /' . ltrim($req->path(), '/');
        }

        $file = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getFile());

        return new Alert(
            key: 'error:' . $fingerprint,
            level: $console ? Alert::WARNING : Alert::CRITICAL,
            title: $console ? 'งานเบื้องหลังเกิด error' : 'เว็บเกิด error ระหว่างเปิดหน้า',
            body: 'ข้อความ: ' . mb_substr(Redact::text($e->getMessage()), 0, 400)
                . "\nไฟล์: " . str_replace('\\', '/', $file) . ':' . $e->getLine()
                . "\n\nerror เดิมจะไม่แจ้งซ้ำภายใน 6 ชม. — รายละเอียดเต็มอยู่ใน storage/logs/laravel.log",
            facts: ['ชนิด' => class_basename($e), 'เกิดที่' => mb_substr(Redact::text($where), 0, 80)],
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'system',
        );
    }
}
