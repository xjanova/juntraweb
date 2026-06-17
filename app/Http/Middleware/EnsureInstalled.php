<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip the installer gate under `php artisan test` so the test suite
        // doesn't need to provision storage/app/.installed in CI.
        if (app()->environment('testing') || Installation::isInstalled()) {
            return $next($request);
        }

        // allow installer routes, health check, and built assets through
        if ($request->is('install', 'install/*', 'up', 'build/*', 'images/*', 'storage/*', 'favicon.ico')) {
            return $next($request);
        }

        return redirect('/install');
    }
}
