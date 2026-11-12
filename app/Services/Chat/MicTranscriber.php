<?php

namespace App\Services\Chat;

use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\ChatRooms;
use Illuminate\Http\UploadedFile;

/**
 * 🎙️ (2026-11-07) ปุ่มไมค์ในห้องพยากรณ์ — ถอดเสียงที่ Thaiprompt (หน้าเว็บ /chat/transcribe + แอพ POST /v1/chat/transcribe)
 *
 * ได้ข้อความกลับไปใส่ช่องพิมพ์ ลูกค้าตรวจ/แก้ก่อนกดส่งเอง (ถอดผิดแล้วส่งเลย = เสียค่าข้อความฟรี ๆ)
 */
final class MicTranscriber
{
    /** ≤ 700 KB ≈ 25 วิของ opus ที่เบราว์เซอร์อัด (Thaiprompt รับไม่เกิน 768 KB) */
    public const RULES = [
        'audio' => 'required|file|max:700|mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mp4,video/mp4,audio/x-m4a,audio/aac,audio/mpeg,audio/wav,audio/x-wav',
        'room'  => 'nullable|string|max:20',
    ];

    public const MESSAGES = [
        'audio.required' => 'ไม่ได้รับเสียงเลยค่ะ ลองกดไมค์แล้วพูดใหม่นะคะ',
        'audio.max'      => 'ยาวเกินไปค่ะ ลองพูดสั้น ๆ ทีละเรื่องนะคะ',
        'audio.*'        => 'ไฟล์เสียงนี้ใช้ไม่ได้ค่ะ ลองพูดใหม่หรือพิมพ์แทนนะคะ',
    ];

    /**
     * @return array{ok: bool, text: ?string, message: ?string, status: int}  status = HTTP ที่ผู้เรียกควรตอบเมื่อไม่สำเร็จ
     */
    public static function run(UploadedFile $file, int $userId, ?string $room): array
    {
        $r = app(JuntraServerClient::class)->voiceTranscribe(
            (string) $file->getRealPath(),
            (string) ($file->getMimeType() ?: 'audio/webm'),
            (string) $userId,
            ChatRooms::normalize((string) $room),
        );

        if ($r['code'] === 200 && $r['text'] !== '') {
            return ['ok' => true, 'text' => mb_substr((string) $r['text'], 0, 2000), 'message' => null, 'status' => 200];
        }

        return [
            'ok'      => false,
            'text'    => null,
            'message' => match (true) {
                $r['code'] === 200 => 'ไม่ได้ยินเสียงพูดเลยค่ะ ลองพูดใกล้ไมค์อีกครั้งนะคะ',
                $r['code'] === 429 => 'วันนี้ใช้ไมค์ครบแล้วค่ะ พิมพ์คุยต่อได้เลยนะคะ',
                default            => 'ถอดเสียงไม่สำเร็จ ลองพูดใหม่หรือพิมพ์แทนนะคะ',
            },
            'status'  => $r['code'] === 429 ? 429 : 422,
        ];
    }
}
