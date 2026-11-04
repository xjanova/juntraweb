<?php

namespace App\Http\Controllers;

use App\Services\Chat\LiveRoomStats;
use Illuminate\Http\JsonResponse;

/**
 * 🔴 (2026-11-04) GET /chat/live — ตัวเลขสดของแผงหน้าห้องผู้พยากรณ์ (หน้าเว็บดึงซ้ำทุก ~15 วิ)
 * ตัวเลขจริงทั้งหมด ดู LiveRoomStats · ไม่มีข้อมูลส่วนตัวใด ๆ จึงเปิดให้ทุกคนได้
 */
class ChatLiveStatsController extends Controller
{
    public function __invoke(LiveRoomStats $stats): JsonResponse
    {
        return response()->json($stats->get())
            ->header('Cache-Control', 'public, max-age=' . LiveRoomStats::REFRESH_SECONDS);
    }
}
