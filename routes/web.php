<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\ThaipromptController;
use App\Http\Controllers\AuspiciousController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HoroscopeController;
use App\Http\Controllers\Install\InstallerController;
use App\Http\Controllers\MlmController;
use App\Http\Controllers\NumerologyController;
use App\Http\Controllers\PalmistryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TarotController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// Installer wizard — only reachable until storage/app/.installed exists.
Route::middleware('block.installed')->prefix('install')->name('install.')->group(function () {
    Route::get('/',             [InstallerController::class, 'welcome'])->name('welcome');
    Route::get('/database',     [InstallerController::class, 'databaseForm'])->name('database');
    Route::post('/database',    [InstallerController::class, 'databaseSave'])->name('database.save');
    Route::get('/admin',        [InstallerController::class, 'adminForm'])->name('admin');
    Route::post('/admin',       [InstallerController::class, 'adminSave'])->name('admin.save');
    Route::get('/integrations', [InstallerController::class, 'integrationsForm'])->name('integrations');
    Route::post('/integrations',[InstallerController::class, 'integrationsSave'])->name('integrations.save');
    Route::get('/finish',       [InstallerController::class, 'finish'])->name('finish');
});

// Thaiprompt SSO
Route::prefix('auth/thaiprompt')->name('thaiprompt.')->group(function () {
    Route::get('/redirect', [ThaipromptController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [ThaipromptController::class, 'callback'])->name('callback');
    // Mobile bootstrap — Juntra Flutter app passes its Sanctum bearer
    // token to establish the web session for the right user, then
    // forwards into the normal /redirect flow. The session is tagged
    // so the callback ends on a "Return to app" success page instead
    // of redirecting to /dashboard (mobile users don't want the web).
    Route::get('/mobile-start', [ThaipromptController::class, 'mobileStart'])->name('mobile-start');
});

Route::get('/', [HomeController::class, 'index'])->name('home');

// Static legal pages — theme-agnostic Blade (the ThemeServiceProvider view
// composer injects $activeTheme/$themeConfig into every view, so Route::view works).
Route::view('/privacy', 'pages.legal.privacy')->name('legal.privacy');
Route::view('/terms',   'pages.legal.terms')->name('legal.terms');

// Tarot — paid actions throttled (per-user, see AppServiceProvider) so a
// rapid double-submit can't double-charge.
Route::prefix('tarot')->name('tarot.')->controller(TarotController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/begin', 'begin')->name('begin');                  // step 1 → save spread+question, redirect to pick
    Route::get('/pick', 'pick')->name('pick');                      // step 2 → fan of 78 cards
    Route::post('/three-card', 'threeCardSpread')->middleware('throttle:reading')->name('three-card');
    Route::post('/celtic-cross', 'celticCross')->middleware('throttle:reading')->name('celtic-cross');
    Route::get('/result/{reading}', 'show')->name('show');
});

// Horoscope
Route::prefix('horoscope')->name('horoscope.')->controller(HoroscopeController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/thai-zodiac', 'thai')->name('thai');
    Route::get('/{zodiac:slug}', 'show')->name('show');
});

// Numerology — calculate is paid (debits wallet) so per-user throttle applies
Route::prefix('numerology')->name('numerology.')->controller(NumerologyController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/calculate', 'calculate')->middleware('throttle:reading')->name('calculate');
});

// Palmistry — analyze is paid + AI image upload, throttled per user
Route::prefix('palmistry')->name('palmistry.')->controller(PalmistryController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/analyze', 'analyze')->middleware('throttle:reading')->name('analyze');
});

// Auspicious dates — find is paid + AI advice, throttled per user
Route::prefix('auspicious')->name('auspicious.')->controller(AuspiciousController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/find', 'find')->middleware('throttle:reading')->name('find');
});

// AI Chat — send is throttled (per-user, named limiter in AppServiceProvider)
// to prevent burst-debit + upstream abuse.
Route::prefix('chat')->name('chat.')->controller(ChatController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/send', 'send')->middleware('throttle:chat-send')->name('send');
    Route::get('/conversation/{conversation}', 'show')
        ->middleware('auth')
        ->name('show');
});

// Account / member area
Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/dashboard', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/history',   [AccountController::class, 'history'])->name('history');
    Route::get('/chats',     [AccountController::class, 'chats'])->name('chats');

    // Astrology profile (DOB / birth time / zodiacs) — feeds horoscope/numerology etc.
    Route::get('/astrology',   [AccountController::class, 'astrology'])->name('astrology');
    Route::patch('/astrology', [AccountController::class, 'astrologyUpdate'])->name('astrology.update');

    // Sessions & API tokens (Sanctum). The throttle prevents brute-forcing
    // the current-password gate on logoutOtherBrowsers.
    Route::get('/security',                [AccountController::class, 'security'])->name('security');
    Route::delete('/tokens/{tokenId}',     [AccountController::class, 'revokeToken'])->whereNumber('tokenId')->name('tokens.revoke');
    Route::post('/tokens/revoke-all',      [AccountController::class, 'revokeAllTokens'])->name('tokens.revoke-all');
    Route::post('/sessions/logout-others', [AccountController::class, 'logoutOtherBrowsers'])
        ->middleware('throttle:6,1')
        ->name('sessions.logout-others');
});

// Wallet — credit balance, top-up via PromptPay slip, transaction history.
// Slip uploads live on the private 'local' disk; the slip route streams them
// back through PHP after verifying the requester is the owner or an admin.
Route::middleware('auth')->prefix('wallet')->name('wallet.')->controller(WalletController::class)->group(function () {
    Route::get('/',                   'index')->name('index');
    Route::get('/topups',             'topups')->name('topups');
    Route::get('/topup',              'topupForm')->name('topup');
    Route::post('/topup',             'topupSubmit')->middleware('throttle:topup')->name('topup.submit');
    Route::get('/topup/{tx}',         'topupShow')->name('topup.show');
    Route::get('/topup/{tx}/slip',    'topupSlip')->name('topup.slip');
    Route::post('/topup/{tx}/cancel', 'topupCancel')->middleware('throttle:topup')->name('topup.cancel');
});

// MLM dashboard — reads canonical data from Thaiprompt-Affiliate via OAuth bearer.
Route::middleware('auth')->prefix('mlm')->name('mlm.')->group(function () {
    Route::get('/',             [MlmController::class, 'dashboard'])->name('dashboard');
    Route::get('/commissions',  [MlmController::class, 'commissions'])->name('commissions');
    Route::get('/users',        [MlmController::class, 'users'])->name('users');
});

// Breeze-compatible dashboard route (alias of account.dashboard)
Route::get('/dashboard', [AccountController::class, 'dashboard'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Profile (Breeze)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

if (file_exists(__DIR__ . '/auth.php')) {
    require __DIR__ . '/auth.php';
}
