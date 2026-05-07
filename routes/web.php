<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuspiciousController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HoroscopeController;
use App\Http\Controllers\NumerologyController;
use App\Http\Controllers\PalmistryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TarotController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Tarot
Route::prefix('tarot')->name('tarot.')->controller(TarotController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/begin', 'begin')->name('begin');                  // step 1 → save spread+question, redirect to pick
    Route::get('/pick', 'pick')->name('pick');                      // step 2 → fan of 78 cards
    Route::post('/three-card', 'threeCardSpread')->name('three-card');
    Route::post('/celtic-cross', 'celticCross')->name('celtic-cross');
    Route::get('/result/{reading}', 'show')->name('show');
});

// Horoscope
Route::prefix('horoscope')->name('horoscope.')->controller(HoroscopeController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/thai-zodiac', 'thai')->name('thai');
    Route::get('/{zodiac:slug}', 'show')->name('show');
});

// Numerology
Route::prefix('numerology')->name('numerology.')->controller(NumerologyController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/calculate', 'calculate')->name('calculate');
});

// Palmistry
Route::prefix('palmistry')->name('palmistry.')->controller(PalmistryController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/analyze', 'analyze')->name('analyze');
});

// Auspicious dates
Route::prefix('auspicious')->name('auspicious.')->controller(AuspiciousController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/find', 'find')->name('find');
});

// AI Chat
Route::prefix('chat')->name('chat.')->controller(ChatController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/send', 'send')->name('send');
    Route::get('/conversation/{conversation}', 'show')->name('show');
});

// Account / member area
Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/dashboard', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/history', [AccountController::class, 'history'])->name('history');
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
