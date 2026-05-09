<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\WalletController;
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

        // Wallet — balance, history, top-up start
        Route::prefix('wallet')->name('wallet.')->group(function () {
            Route::get('/',                   [WalletController::class, 'index'])->name('index');
            Route::get('transactions',        [WalletController::class, 'transactions'])->name('transactions');
            Route::post('topup/promptpay',    [WalletController::class, 'topupPromptPay'])
                ->middleware('throttle:topup')->name('topup.promptpay');
            Route::get('topup/{tx}',          [WalletController::class, 'topupShow'])->name('topup.show');
        });

        // Mae Mor AI Chat — conversations + send
        Route::prefix('chat')->name('chat.')->group(function () {
            Route::get('conversations',                   [ChatController::class, 'conversations'])->name('conversations');
            Route::post('conversations',                  [ChatController::class, 'startConversation'])->name('conversations.start');
            Route::get('conversations/{conversation}',    [ChatController::class, 'show'])->name('conversations.show');
            Route::post('conversations/{conversation}/send', [ChatController::class, 'send'])
                ->middleware('throttle:chat-send')->name('conversations.send');
        });

        // Reading history (tarot / numerology / palmistry / auspicious)
        Route::prefix('history')->name('history.')->group(function () {
            Route::get('readings',           [HistoryController::class, 'index'])->name('index');
            Route::get('readings/{reading}', [HistoryController::class, 'show'])->name('show');
        });
    });
});
