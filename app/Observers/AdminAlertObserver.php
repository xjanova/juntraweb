<?php

namespace App\Observers;

use App\Models\ChatConversation;
use App\Models\Reading;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\AdminAlerts;
use App\Support\Alerts\ReadingAlerts;
use App\Support\Alerts\SecurityAlerts;
use App\Support\Alerts\WalletAlerts;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * One observer for every model the owner wants to hear about. Runs AFTER the database transaction
 * commits: a top-up confirmation that rolls back must never have announced "เงินเข้า".
 *
 * Catching money at the model rather than in each controller is deliberate — a wallet can be
 * credited from the SMS gateway, the slip checker, the Filament approve button, the SmsChecker app's
 * approve button… and any path added later is covered without anyone remembering to wire it.
 */
class AdminAlertObserver implements ShouldHandleEventsAfterCommit
{
    /** @var WeakMap<User,?string>|null the role a user had before the save in flight (see AdminAlertServiceProvider) */
    public static ?WeakMap $roleBefore = null;

    public function created(Model $model): void
    {
        match (true) {
            $model instanceof WalletTransaction => $this->walletCreated($model),
            $model instanceof Reading => ReadingAlerts::created($model),
            $model instanceof ChatConversation => ReadingAlerts::chatStarted($model),
            $model instanceof User => AdminAlerts::noteSignup((int) $model->id, (string) ($model->name ?: 'ไม่ระบุชื่อ'), $this->signupVia($model)),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        if ($model instanceof WalletTransaction && $model->type === 'topup' && $model->wasChanged('status')) {
            match ($model->status) {
                'success' => WalletAlerts::credited($model),
                'failed' => WalletAlerts::rejected($model),
                default => null,
            };

            return;
        }

        if ($model instanceof User && $model->wasChanged('role')) {
            $before = self::$roleBefore[$model] ?? null;
            SecurityAlerts::roleChanged($model, $before, $model->role, auth()->user());
        }
    }

    private function walletCreated(WalletTransaction $tx): void
    {
        if ($tx->type === 'adjustment') {
            WalletAlerts::adjusted($tx);
        } elseif ($tx->type === 'refund') {
            ReadingAlerts::refunded($tx);
        } elseif ($tx->type === 'topup' && $tx->status === 'success') {
            WalletAlerts::credited($tx);   // credited in one step (promo/manual credit())
        }
    }

    private function signupVia(User $user): string
    {
        return match (true) {
            ! empty($user->thaiprompt_user_id) => 'บัญชีแม่หมอ (SSO)',
            ! empty($user->line_user_id) => 'LINE',
            ! empty($user->facebook_user_id) => 'Facebook',
            ! empty($user->phone) && empty($user->email) => 'เบอร์โทร',
            default => 'อีเมล',
        };
    }
}
