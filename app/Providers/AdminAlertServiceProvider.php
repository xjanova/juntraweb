<?php

namespace App\Providers;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Observers\AdminAlertObserver;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\SecurityAlerts;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;
use WeakMap;

/**
 * Wires the admin Telegram alerts to the rest of the app: model events (money, readings, members,
 * role changes), auth events (password guessing, staff sign-ins) and scheduler failures.
 *
 * Everything here is inert until a bot token + chat are saved and switched on in the admin panel
 * (AdminAlerts::wants() says no), so installing it changes nothing on its own.
 */
class AdminAlertServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([WalletTransaction::class, Reading::class, ChatConversation::class, User::class] as $model) {
            $model::observe(AdminAlertObserver::class);
        }

        // The observer runs after commit, when the old role is gone from the model — keep it aside
        // while the save is in flight (a WeakMap, so it can never leak or be saved as a column).
        AdminAlertObserver::$roleBefore = new WeakMap;
        User::updating(function (User $user) {
            if ($user->isDirty('role')) {
                AdminAlertObserver::$roleBefore[$user] = $user->getOriginal('role');
            }
        });

        $lastFailedRequest = null;
        Event::listen(Failed::class, function (Failed $e) use (&$lastFailedRequest) {
            // The web login tries e-mail, then phone, in one request — one guess, not two.
            $rid = app()->bound('request') ? spl_object_id(request()) : null;
            if ($rid !== null && $rid === $lastFailedRequest) {
                return;
            }
            $lastFailedRequest = $rid;
            $c = (array) $e->credentials;
            SecurityAlerts::loginFailed((string) ($c['email'] ?? $c['phone'] ?? $c['login'] ?? ''), request()?->ip());
        });

        Event::listen(Lockout::class, fn (Lockout $e) => SecurityAlerts::lockout(
            (string) ($e->request->input('login') ?? $e->request->input('email') ?? ''),
            $e->request->ip(),
        ));

        Event::listen(Login::class, function (Login $e) {
            if ($e->user instanceof User) {
                SecurityAlerts::staffLogin($e->user, request()?->ip(), request()?->userAgent());
            }
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $e) {
            try {
                $what = trim(preg_replace('~^.*?artisan[\'"]?\s+~', '', (string) $e->task->command) ?? '') ?: ($e->task->description ?: 'งานตั้งเวลา');
                AdminAlerts::send(new Alert(
                    key: 'schedule-failed:' . sha1($what),
                    level: Alert::WARNING,
                    title: 'งานตั้งเวลาล้มเหลว: ' . mb_substr($what, 0, 60),
                    body: mb_substr(\App\Support\Alerts\Redact::text($e->exception->getMessage()), 0, 300),
                    category: 'system',
                ), 360);
            } catch (Throwable) {
            }
        });
    }
}
