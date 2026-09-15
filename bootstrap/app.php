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
        $middleware->alias([
            'block.installed' => \App\Http\Middleware\BlockInstallerWhenInstalled::class,
            // บริการที่แอดมินปิดไว้ (ServiceGate) — ใช้ไม่ได้ทั้งเว็บ/แอพในจุดเดียว
            'service.open' => \App\Http\Middleware\EnsureServiceOpen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Tell the owner on Telegram when something throws (a 500, a dying command) — throttled
        // hard and sent after the response. Returns nothing, so normal logging still happens.
        $exceptions->report(function (\Throwable $e): void {
            \App\Support\Alerts\ErrorAlert::report($e);
        });
    })->create();
