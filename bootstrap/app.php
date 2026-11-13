<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Mobile API for the juntra Flutter app — Sanctum bearer tokens,
        // stateless. Routes registered under prefix '/api' automatically.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // EnsureInstalled is web-only (it redirects guest browsers to the
        // installer). The /api/* prefix never goes through web middleware,
        // so we don't need to exempt anything explicitly.
        $middleware->append(\App\Http\Middleware\EnsureInstalled::class);
        // Dead man's switch for the cron — checked after the response is sent (terminate()).
        $middleware->web(append: [\App\Http\Middleware\WatchScheduler::class]);
        // 🌸 ทัวร์บุษบา: หน้าเว็บเขียน cookie ขั้นปัจจุบันเอง (JS) — ถ้าเข้ารหัส เซิร์ฟเวอร์จะถอดไม่ออก
        //    ค่าข้างในไม่มีความลับ (ชื่อทางเลือก / เลขขั้น) ปลอมได้ก็แค่เห็นหรือไม่เห็นทัวร์
        $middleware->encryptCookies(except: ['jw_tour_v1', 'jw_tour_v1_run']);
        $middleware->alias([
            'block.installed' => \App\Http\Middleware\BlockInstallerWhenInstalled::class,
            // บริการที่แอดมินปิดไว้ (ServiceGate) — ใช้ไม่ได้ทั้งเว็บ/แอพในจุดเดียว
            'service.open' => \App\Http\Middleware\EnsureServiceOpen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // App contract: every /api/v1 validation failure carries reason_code validation_failed.
        // message stays the first field message (Thai via lang/th), as the v1 endpoints that
        // build their own 422 already do — additive for app 0.6.0, which shows message as-is.
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first() ?? 'ข้อมูลไม่ถูกต้อง กรุณาตรวจสอบอีกครั้งค่ะ',
                    'reason_code' => 'validation_failed',
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });
        // Tell the owner on Telegram when something throws (a 500, a dying command) — throttled
        // hard and sent after the response. Returns nothing, so normal logging still happens.
        $exceptions->report(function (\Throwable $e): void {
            \App\Support\Alerts\ErrorAlert::report($e);
        });
    })->create();
