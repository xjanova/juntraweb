<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\QrImage;

/**
 * แอพมือถือ Juntra — landing "ดาวน์โหลดแอพ" ที่ลิงก์ไปสโตร์.
 *
 * ลิงก์สโตร์แก้ได้จาก /admin → ตั้งค่าเว็บไซต์ → แอปพลิเคชันมือถือ
 * (Setting: play_store_url / app_store_url / app_version). ปุ่มโหลดวิ่งผ่าน
 * /download/go เพื่อนับยอดคลิกก่อนเด้งไปสโตร์ — ตัวเลขอยู่ใน Setting
 * app_download_clicks และโชว์ในหน้า admin เดียวกัน.
 */
class DownloadController extends Controller
{
    /** ใช้เมื่อ admin ยังไม่ได้ตั้ง play_store_url — package จริงของแอพ Juntra. */
    private const DEFAULT_PLAY_URL = 'https://play.google.com/store/apps/details?id=com.xjanova.juntra';

    public function show()
    {
        // QR ชี้ไปที่ /download/go (ไม่ใช่สโตร์ตรง ๆ) เพื่อให้ยอดสแกนถูกนับด้วย
        return view('pages.download', [
            'playUrl'     => $this->playUrl(),
            'appStoreUrl' => $this->safeUrl((string) Setting::get('app_store_url', '')),
            'appVersion'  => trim((string) Setting::get('app_version', '')),
            'qrDataUri'   => QrImage::svgDataUri(route('download.go')),
        ]);
    }

    /** นับคลิกแล้วส่งต่อไปสโตร์ (throttle ที่ route กันสแปมยอด). */
    public function go()
    {
        $clicks = (int) Setting::get('app_download_clicks', 0);
        Setting::put('app_download_clicks', (string) ($clicks + 1), 'app', false);

        return redirect()->away($this->playUrl());
    }

    private function playUrl(): string
    {
        return $this->safeUrl((string) Setting::get('play_store_url', ''))
            ?? self::DEFAULT_PLAY_URL;
    }

    /** รับเฉพาะ https:// กันค่า setting เพี้ยนกลายเป็น open redirect / javascript: */
    private function safeUrl(string $url): ?string
    {
        $url = trim($url);

        return str_starts_with($url, 'https://') ? $url : null;
    }
}
