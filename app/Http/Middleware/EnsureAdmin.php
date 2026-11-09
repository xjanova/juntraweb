<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🧑‍💼 (2026-11-09) เฉพาะแอดมิน (role = admin) — editor เข้าหลังบ้าน Filament ได้ แต่คุยกับลูกค้าไม่ได้
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && method_exists($user, 'isAdmin') && $user->isAdmin(), 403);

        return $next($request);
    }
}
