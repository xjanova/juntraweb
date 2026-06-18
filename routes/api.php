<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\MlmController;
use App\Http\Controllers\Api\V1\SmsPaymentController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Middleware\VerifySmsCheckerDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Mobile (juntra Flutter app)
|--------------------------------------------------------------------------
|
| Stateless Sanctum bearer-token auth. The Flutter app at
| `xjanova/juntra` calls these endpoints. All paths are absolute under
| the `/api` prefix that Laravel adds automatically when this file is
| registered via `withRouting(api: …)` in bootstrap/app.php.
|
| Web routes still own /chat, /tarot, /wallet etc. for the browser
| experience. These /api/v1/* routes are SEPARATE — same business
| services (WalletService, FortuneBotClient) but different controllers
| so we can return JSON and skip web-side gates that don't apply to
| mobile (FB/LINE link requirement, CSRF, etc.).
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ─── Public ───────────────────────────────────────────────
    Route::post('auth/login',    [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');

    Route::get('app/health', fn () => response()->json([
        'status'  => 'ok',
        'app'     => config('app.name'),
        'version' => '1',
        'time'    => now()->toIso8601String(),
    ]))->name('app.health');

    // ─── Authenticated (Sanctum bearer) ───────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('auth/me',     [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout',[AuthController::class, 'logout'])->name('auth.logout');
        // Short-lived single-use code for the web OAuth bootstrap, so the
        // long-lived bearer never travels in the mobile-start URL.
        Route::post('auth/handoff',[AuthController::class, 'handoff'])
            ->middleware('throttle:10,1')->name('auth.handoff');

        // Wallet — balance, history, top-up start + native slip upload.
        // The slip POST goes through the same `topup` 10/min/user bucket
        // as the initiate call — mobile re-uploads from a flaky network
        // are common, but 10/min is plenty.
        Route::prefix('wallet')->name('wallet.')->group(function () {
            Route::get('/',                   [WalletController::class, 'index'])->name('index');
            Route::get('transactions',        [WalletController::class, 'transactions'])->name('transactions');
            Route::get('topups',              [WalletController::class, 'topups'])->name('topups');
            Route::post('topup/promptpay',    [WalletController::class, 'topupPromptPay'])
                ->middleware('throttle:topup')->name('topup.promptpay');
            Route::get('topup/{tx}',          [WalletController::class, 'topupShow'])->name('topup.show');
            Route::post('topup/{tx}/slip',    [WalletController::class, 'topupUploadSlip'])
                ->middleware('throttle:topup')->name('topup.slip');
            Route::delete('topup/{tx}',       [WalletController::class, 'topupCancel'])
                ->middleware('throttle:topup')->name('topup.cancel');
        });

        // Mae Mor AI Chat — conversations + send
        Route::prefix('chat')->name('chat.')->group(function () {
            Route::get('conversations',                   [ChatController::class, 'conversations'])->name('conversations');
            Route::post('conversations',                  [ChatController::class, 'startConversation'])->name('conversations.start');
            Route::get('conversations/{conversation}',    [ChatController::class, 'show'])->name('conversations.show');
            Route::post('conversations/{conversation}/send', [ChatController::class, 'send'])
                ->middleware('throttle:chat-send')->name('conversations.send');
        });

        // MLM dashboard — wraps the upstream Thaiprompt /juntra/mlm/*
        // endpoints via the user's thaiprompt_token. Returns 403 with
        // `thaiprompt_not_linked` when the user hasn't completed OAuth
        // SSO yet, so the Flutter affiliate screen can show a "link
        // account" CTA instead of an empty dashboard.
        Route::prefix('mlm')->name('mlm.')->group(function () {
            Route::get('stats',       [MlmController::class, 'stats'])->name('stats');
            Route::get('tree',        [MlmController::class, 'tree'])->name('tree');
            Route::get('commissions', [MlmController::class, 'commissions'])->name('commissions');
        });

        // Reading history (tarot / numerology / palmistry / auspicious).
        // POST shares the `reading` 6/min/user rate-limit bucket defined
        // in AppServiceProvider — same protection against rapid-fire
        // wallet drain that the web TarotController relies on.
        Route::prefix('history')->name('history.')->group(function () {
            Route::get('readings',           [HistoryController::class, 'index'])->name('index');
            Route::post('readings',          [HistoryController::class, 'store'])
                ->middleware('throttle:reading')->name('store');
            Route::get('readings/{reading}', [HistoryController::class, 'show'])->name('show');
        });
    });
});

// ─── SMS Checker payment gateway (thaiprompt-smschecker-v1) ──────────
// Device-authenticated (X-Api-Key + AES-GCM/HMAC), NOT Sanctum. The juntra
// SMS Checker Android app POSTs encrypted bank-SMS here; matches credit the
// reserved wallet top-up automatically.
Route::prefix('v1/sms-payment')->middleware(VerifySmsCheckerDevice::class)->name('api.v1.sms.')->group(function () {
    Route::post('notify',           [SmsPaymentController::class, 'notify'])->middleware('throttle:300,1')->name('notify');
    Route::post('register-device',  [SmsPaymentController::class, 'registerDevice'])->middleware('throttle:300,1')->name('register');
    Route::post('register-fcm-token', [SmsPaymentController::class, 'registerFcmToken'])->middleware('throttle:300,1')->name('fcm');
    Route::get('status',            [SmsPaymentController::class, 'status'])->middleware('throttle:120,1')->name('status');
    Route::get('device-settings',   [SmsPaymentController::class, 'getDeviceSettings'])->middleware('throttle:120,1')->name('settings.get');
    Route::put('device-settings',   [SmsPaymentController::class, 'updateDeviceSettings'])->middleware('throttle:120,1')->name('settings.put');
});
