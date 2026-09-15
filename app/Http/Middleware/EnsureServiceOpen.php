<?php

namespace App\Http\Middleware;

use App\Support\ServiceGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 'service.open:<service>' — บริการที่แอดมินปิดไว้ (ServiceGate) ใช้ไม่ได้ทุกช่องทางในจุดเดียว
 *
 *   - แอพ/JSON → 503 + reason_code service_closed (แอพโชว์ข้อความแทน error ดิบ)
 *   - เปิดหน้าเว็บ (GET) → หน้า "ปิดปรับปรุงชั่วคราว" + ทางไปไพ่/แชท (ลิงก์เดิมจากบอท/โพสต์ไม่พัง)
 *   - ส่งฟอร์ม (POST) → กลับหน้าบริการพร้อมเหตุผล — ไม่คำนวณ ไม่หักเงิน
 */
class EnsureServiceOpen
{
    public function handle(Request $request, Closure $next, string $service): Response
    {
        if (! ServiceGate::isClosed($service)) {
            return $next($request);
        }

        $message = ServiceGate::message($service);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message, 'reason_code' => 'service_closed'], 503);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $to = \Illuminate\Support\Facades\Route::has("{$service}.index") ? route("{$service}.index") : url('/');

            return redirect()->to($to)->with('status', $message);
        }

        return response()->view('pages.service-closed', [
            'service' => $service,
            'name' => ServiceGate::SERVICES[$service] ?? '',
            'message' => $message,
        ]);
    }
}
