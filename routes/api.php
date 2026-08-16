<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\FortuneController;
use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\HoroscopeController;
use App\Http\Controllers\Api\V1\MlmController;
use App\Http\Controllers\Api\V1\SmsPaymentController;
use App\Http\Controllers\Api\V1\TarotController;
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
    // สองเส้นนี้เป็นประตูหน้าที่ไม่ต้องล็อกอิน — ต้องมี throttle เสมอ
    // (Laravel 11 ไม่ใส่ throttle:api ให้เอง ดู AppServiceProvider)
    Route::post('auth/login',    [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')->name('auth.login');
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register')->name('auth.register');

    Route::get('app/health', fn () => response()->json([
        'status'  => 'ok',
        'app'     => config('app.name'),
        'version' => '1',
        'time'    => now()->toIso8601String(),
    ]))->name('app.health');

    // ปฏิทินโหรของวันนี้ (ดิถี · ราศีที่จันทร์เสวย · ยาม) — คำนวณจาก ThaiAstro
    // ตัวเดียวกับเว็บ ใช้แทนข้อความดวงจันทร์ที่เคย hardcode ไว้ในหน้าแรกของแอพ
    Route::get('almanac/today', [\App\Http\Controllers\Api\V1\AlmanacController::class, 'today'])
        ->name('almanac.today');

    // Daily horoscope — free + read-only (no auth, no debit).
    Route::get('horoscope',           [HoroscopeController::class, 'index'])->name('horoscope.index');
    // ปีนักษัตร — เว็บมีหน้านี้มานาน แต่แอพเข้าถึงไม่ได้เพราะไม่มี endpoint
    // (ต้องมาจากเซิร์ฟเวอร์ ห้ามให้แอพคำนวณปีนักษัตรเอง)
    Route::get('horoscope/thai-zodiac', [HoroscopeController::class, 'thaiZodiac'])->name('horoscope.thai');
    Route::get('horoscope/{zodiac}',  [HoroscopeController::class, 'show'])->name('horoscope.show');

    // หมวดงานมงคล — ข้อมูลอ้างอิงคงที่ ให้ฟอร์มฤกษ์ยามในแอพกาง dropdown
    // ได้เหมือนเว็บ แทนที่จะปล่อยให้เซิร์ฟเวอร์เดาหมวดจากข้อความล้วน
    Route::get('fortune/occasions', [FortuneController::class, 'occasions'])->name('fortune.occasions');

    // Tarot card catalog — public face-image URLs so the mobile app can pull
    // the real จันทรา.online art (and fall back to its own drawing per card).
    Route::get('tarot/cards', [TarotController::class, 'cards'])->name('tarot.cards');

    // ─── Authenticated (Sanctum bearer) ───────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // สับไพ่หนึ่งกองต่อหนึ่งเกม — เซิร์ฟเวอร์เป็นคนสับและตัดสินหัวตั้ง/หัวกลับ
        // (แอพเคยใช้สำรับ const เรียงเดิมตลอดกาล และสุ่มหัวกลับเอง 30% ต่างจากเว็บ 50%)
        Route::post('tarot/deal', [TarotController::class, 'deal'])
            ->middleware('throttle:reading')->name('tarot.deal');

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
            // การเปิดห้องใหม่ยิง upstream AI ทุกครั้ง (ขอคำทักทาย) — ไม่ throttle
            // เท่ากับให้ยิงต้นทุน AI และสร้างแถวได้ไม่จำกัด
            Route::post('conversations',                  [ChatController::class, 'startConversation'])
                ->middleware('throttle:chat-send')->name('conversations.start');
            Route::get('conversations/{conversation}',    [ChatController::class, 'show'])->name('conversations.show');
            Route::post('conversations/{conversation}/send', [ChatController::class, 'send'])
                ->middleware('throttle:chat-send')->name('conversations.send');
            // หมวดคำถามแบบเต็ม (5 หมวด 24 คำถาม) — ชุดเดียวกับที่เว็บกางในแชท
            Route::get('topics', [ChatController::class, 'topics'])->name('topics');
        });

        // ดูดวงเชิงลึก 39฿ — แพ็กเดียวกับเว็บและบอท FB/LINE
        Route::prefix('deep')->name('deep.')->group(function () {
            Route::get('/',  [\App\Http\Controllers\Api\V1\DeepReadingController::class, 'show'])->name('show');
            Route::post('/', [\App\Http\Controllers\Api\V1\DeepReadingController::class, 'store'])
                ->middleware('throttle:reading')->name('store');
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
            // Pull-to-refresh: bust the per-user MLM cache so the next GETs
            // return live Thaiprompt numbers (same totals as the web).
            Route::post('refresh',    [MlmController::class, 'refresh'])->middleware('throttle:6,1')->name('refresh');
        });

        // Paid non-tarot readings — same `reading` rate-limit + debit/refund.
        Route::prefix('fortune')->name('fortune.')->middleware('throttle:reading')->group(function () {
            Route::post('numerology', [FortuneController::class, 'numerology'])->name('numerology');
            Route::post('auspicious', [FortuneController::class, 'auspicious'])->name('auspicious');
            Route::post('palmistry',  [FortuneController::class, 'palmistry'])->name('palmistry');
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
