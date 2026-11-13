<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Push\PushNotifier;
use App\Services\Push\Vapid;
use App\Services\Push\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 📱 เว็บเป็นแอป (PWA) — manifest + สมัคร/ยกเลิกรับแจ้งเตือน (Web Push)
 *
 * เจ้าของสั่ง (2026-11-11) "ทำให้เว็บติดตั้งลงมือถือ หรือ ไอแพดได้เหมือน web app
 *   ทำให้แจ้งเตือนได้เมื่อติดตั้งแล้ว"
 * service worker อยู่ที่ public/sw.js (ไฟล์นิ่ง ขอบเขต "/") · ปุ่มติดตั้งอยู่ที่ <x-pwa-install>
 * · ปุ่มเปิดแจ้งเตือนอยู่ที่ <x-push-settings> ในหน้าภาพรวมบัญชี
 */
class PwaController extends Controller
{
    public function manifest(): Response
    {
        $name = (string) config('app.name', 'จันทราพยากรณ์');
        $manifest = [
            'id' => '/',
            'name' => $name.' — หมอดู AI',
            'short_name' => 'จันทรา',
            'description' => 'ดูดวงไพ่ยิปซี ดวงรายวัน เลขศาสตร์ ฤกษ์ยาม กับแม่หมอ AI ที่อ่านจากตำราจริง',
            'lang' => 'th',
            'dir' => 'ltr',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['standalone', 'minimal-ui'],
            'orientation' => 'any',
            'theme_color' => '#0a0612',
            'background_color' => '#0a0612',
            'categories' => ['lifestyle', 'entertainment'],
            'icons' => [
                ['src' => asset('images/pwa/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/pwa/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/pwa/maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('images/pwa/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'ห้องพยากรณ์', 'short_name' => 'ห้องพยากรณ์', 'url' => '/chat?pick=1&source=pwa',
                    'icons' => [['src' => asset('images/pwa/icon-192.png'), 'sizes' => '192x192']]],
                ['name' => 'ดวงประจำวัน', 'short_name' => 'ดวงวันนี้', 'url' => '/account/dashboard?source=pwa#daily-luck',
                    'icons' => [['src' => asset('images/pwa/icon-192.png'), 'sizes' => '192x192']]],
            ],
            // มีแอป Android บน Play อยู่แล้ว — ประกาศไว้ แต่ไม่บังคับให้ไปโหลดแอปแทนการติดตั้งเว็บ
            'related_applications' => [
                ['platform' => 'play', 'id' => (string) config('google_play.package_name', 'com.xjanova.juntra')],
            ],
            'prefer_related_applications' => false,
        ];

        return response(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** เบราว์เซอร์สมัครรับแล้ว → จดเครื่องนี้ไว้กับผู้ใช้ (เรียกซ้ำได้ — ใช้ซิงก์ทุกครั้งที่เปิดแอป) */
    public function subscribe(Request $request): JsonResponse
    {
        if (! PushNotifier::webReady()) { // ตั้งแค่ FCM (แอป) ไว้ = เบราว์เซอร์ยังสมัครไม่ได้
            return response()->json(['error' => 'ระบบแจ้งเตือนยังไม่เปิดใช้งานค่ะ'], 503);
        }
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
        ], [
            'endpoint.*' => 'ข้อมูลการสมัครรับแจ้งเตือนไม่ถูกต้อง',
            'keys.*' => 'ข้อมูลการสมัครรับแจ้งเตือนไม่ถูกต้อง',
        ]);

        if (! WebPushSender::allowedEndpoint($data['endpoint'])) {
            return response()->json(['error' => 'เบราว์เซอร์นี้ยังไม่รองรับการแจ้งเตือนของจันทราค่ะ'], 422);
        }
        try {
            $p256dh = Vapid::b64uDecode(rtrim($data['keys']['p256dh'], '='));
            $auth = Vapid::b64uDecode(rtrim($data['keys']['auth'], '='));
            Vapid::publicKey($p256dh); // ต้องเป็นจุดบนเส้นโค้งจริง ไม่งั้นส่งไม่ได้ทุกครั้ง
        } catch (\Throwable) {
            return response()->json(['error' => 'ข้อมูลการสมัครรับแจ้งเตือนไม่ถูกต้อง'], 422);
        }
        if (strlen($auth) !== 16) {
            return response()->json(['error' => 'ข้อมูลการสมัครรับแจ้งเตือนไม่ถูกต้อง'], 422);
        }

        $user = $request->user();
        // เครื่องเดิมเคยผูกกับบัญชีอื่น (ออกจากระบบแล้วเข้าบัญชีใหม่) → ย้ายมาเป็นของบัญชีที่ล็อกอินอยู่
        $sub = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $user->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => Vapid::b64u($p256dh),
                'auth' => Vapid::b64u($auth),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'fail_count' => 0,
            ],
        );

        // เกินเพดานต่อคน → ลบเครื่องที่เก่าที่สุด
        $max = (int) config('webpush.max_per_user', 10);
        $extra = PushSubscription::where('user_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')->skip($max)->take(100)->pluck('id');
        if ($extra->isNotEmpty()) {
            PushSubscription::whereIn('id', $extra)->delete();
        }

        return response()->json(['ok' => true, 'id' => $sub->id, 'devices' => PushSubscription::where('user_id', $user->id)->count()]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json(['ok' => true, 'devices' => PushSubscription::where('user_id', $request->user()->id)->count()]);
    }

    /** ปุ่ม "ลองส่งแจ้งเตือน" — ส่งถึงทุกเครื่องของผู้ใช้คนนี้ */
    public function test(Request $request, PushNotifier $notifier): JsonResponse
    {
        if (! PushNotifier::ready()) {
            return response()->json(['error' => 'ระบบแจ้งเตือนยังไม่เปิดใช้งานค่ะ'], 503);
        }
        $r = $notifier->test($request->user());
        if ($r['devices'] === 0) {
            return response()->json(['error' => 'ยังไม่มีเครื่องที่เปิดแจ้งเตือนไว้ค่ะ'], 422);
        }

        return response()->json(['ok' => $r['delivered'] > 0] + $r);
    }

    /** ผู้ใช้เลือกหมวดที่ไม่อยากรับ */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'muted' => ['present', 'array'],
            'muted.*' => ['string', 'max:32'], // หมวดที่ไม่รู้จักถูกกรองทิ้งใน saveMutedTypes
        ]);
        PushNotifier::saveMutedTypes($request->user(), $data['muted']);

        return response()->json(['ok' => true, 'muted' => PushNotifier::mutedTypes($request->user()->fresh())]);
    }
}
