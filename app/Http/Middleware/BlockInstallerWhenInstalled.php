<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockInstallerWhenInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::isInstalled()) {
            // Once installed, the wizard is closed. Redirect to home.
            return redirect('/');
        }
        return $next($request);
    }
}
