<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use App\Services\Voice\ReadingVoiceBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ✨ (2026-11-05) เสียงเล่าคำทำนายพรีเมียม — หน้าผลเรียก next ซ้ำจนเสียงครบ · file = ไฟล์รวมให้โรงละครเล่น
 */
class ReadingVoiceController extends Controller
{
    public function next(Request $request, Reading $reading, ReadingVoiceBuilder $builder): JsonResponse
    {
        abort_unless($request->user() && $reading->user_id === $request->user()->id, 403);
        if ($reading->isInProgress() || $reading->isFailed() || blank($reading->result)) {
            return response()->json(ReadingVoiceBuilder::view($reading) + ['wait' => true], 409);
        }

        // สองแท็บ/กดรัว — ทำทีละก้าวต่อคำทำนาย อีกคำขอได้สถานะปัจจุบันกลับไปเฉย ๆ
        $lock = Cache::lock('reading-voice:' . $reading->id, 60);
        if (! $lock->get()) {
            return response()->json(ReadingVoiceBuilder::view($reading) + ['busy' => true]);
        }
        try {
            @set_time_limit(90);

            return response()->json($builder->step($reading));
        } finally {
            $lock->release();
        }
    }

    public function file(Request $request, Reading $reading)
    {
        $user = $request->user();
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        abort_unless($user && ($reading->user_id === $user->id || $isAdmin), 403);
        $path = ReadingVoiceBuilder::file($reading);
        abort_unless(is_file($path), 404);

        // BinaryFileResponse รองรับ Range — เลื่อนไปบทไหนก็ได้โดยไม่ต้องโหลดทั้งไฟล์
        return response()->file($path, [
            'Content-Type'  => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
