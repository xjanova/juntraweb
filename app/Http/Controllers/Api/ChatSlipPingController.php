<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Wallet\ChatSlipIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🧾 (2026-11-10) POST /api/server/chat-slips/ping — กริ่งจากบอท Thaiprompt
 *
 * "มีสลิปจากแชทเพจใบนี้รออยู่" — รับแค่เลขอ้างอิง ไม่เชื่อข้อมูลอะไรจากผู้กดกริ่ง
 * VerifyChatSlipCallback ตรวจ dedicated bearer ก่อน throttle/validation/intake
 * ChatSlipIntake ดึงข้อมูลจริงจาก Thaiprompt ด้วย token ของเว็บเอง
 * คำตอบให้บอทใช้เลือกข้อความตอบลูกค้าในแชทเท่านั้น
 */
class ChatSlipPingController extends Controller
{
    public function __invoke(Request $request, ChatSlipIntake $intake): JsonResponse
    {
        $validated = $request->validate([
            'trans_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $result = $intake->process($validated['trans_ref']);

        return response()->json([
            'outcome'   => $result['outcome'],
            'topup_ref' => $result['topup_ref'] ?? null,
            'credited'  => $result['credited'] ?? null,
        ], $result['outcome'] === ChatSlipIntake::LATER ? 202 : 200);
    }
}
