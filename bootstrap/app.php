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
        $middleware->alias([
            'block.installed' => \App\Http\Middleware\BlockInstallerWhenInstalled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
