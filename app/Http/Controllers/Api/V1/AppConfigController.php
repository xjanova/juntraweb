<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\GooglePlay\GooglePlayBilling;
use App\Support\ServiceGate;
use Illuminate\Http\JsonResponse;

/**
 * GET /v1/app/config — สิ่งที่แอพต้องรู้ก่อนวาดหน้าแรก (สาธารณะ ไม่ต้องล็อกอิน)
 *
 * ก่อนหน้านี้แอพรู้ว่าบริการไหนปิดขายก็ต่อเมื่อลูกค้ากดเข้าไปแล้วโดน 503 service_closed
 * (หน้าเว็บซ่อนลิงก์ของบริการที่ปิดไว้ทุกที่ แต่แอพยังโชว์ครบ) — ที่นี่บอกสถานะชุดเดียวกับ
 * ServiceGate ให้แอพซ่อนเหมือนเว็บ พร้อมลิงก์นโยบาย/ลบบัญชี และสวิตช์การเติมเครดิตผ่าน Play
 */
class AppConfigController extends Controller
{
    public function show(GooglePlayBilling $billing): JsonResponse
    {
        // ไพ่ยิปซีกับแชทแม่หมอปิดไม่ได้โดยเจตนา (ServiceGate) — บริการอื่นตามสวิตช์ในหลังบ้าน
        $services = ['tarot' => true, 'chat' => true];
        foreach (array_keys(ServiceGate::SERVICES) as $service) {
            $services[$service] = ! ServiceGate::isClosed($service);
        }

        $support = trim((string) Setting::get('contact_email', '')) ?: 'hello@xn--82c4af5bzdj.online';

        return response()->json(['data' => [
            'services' => $services,
            'features' => [
                'tarot_async'      => true,
                'tarot_packages'   => true,
                'account_deletion' => true,
                'change_password'  => true,
                'content_reports'  => true,
            ],
            'billing' => [
                // แอพช่อง Google Play ซื้อเครดิตได้เฉพาะเมื่อเปิดอยู่ — ปิด = ใช้เครดิตที่มีได้อย่างเดียว
                'google_play' => $billing->enabled(),
            ],
            'app' => [
                'latest_version' => trim((string) Setting::get('app_version', '')) ?: null,
            ],
            'legal' => [
                'privacy_url'          => route('legal.privacy'),
                'terms_url'            => route('legal.terms'),
                'account_deletion_url' => route('account.deletion'),
            ],
            'support' => [
                'email' => $support,
                'line'  => trim((string) Setting::get('contact_line', '')) ?: null,
            ],
        ]])->header('Cache-Control', 'public, max-age=60');
    }
}
