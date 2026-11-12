<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushDevice;
use App\Services\Push\FcmSender;
use App\Services\Push\PushNotifier;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 📲 (2026-11-12) แจ้งเตือนในแอป Android ผ่าน FCM — สัญญา API หมวด G
 *
 * POST   /v1/push/devices      {token, platform: "android", channel: "play"|"direct"}
 *        จดแอปเครื่องนี้ไว้กับบัญชีที่ล็อกอิน — เรียกซ้ำได้ (ทุกครั้งที่เปิดแอป/ได้ token ใหม่)
 *        token เดิมที่เคยผูกบัญชีอื่น (สลับบัญชีบนเครื่องเดียวกัน) ย้ายมาเป็นของบัญชีนี้
 * DELETE /v1/push/devices      {token}  เลิกรับบนเครื่องนี้ (ลบได้เฉพาะของบัญชีตัวเอง)
 * GET    /v1/push/preferences  หมวดที่ปิดไว้ + หมวดที่เปิดให้เลือก + เวลาเงียบ
 * PUT    /v1/push/preferences  {muted: [...]}  ชุดเดียวกับหน้าเว็บ (PushNotifier::saveMutedTypes)
 *
 * จด token ได้แม้เซิร์ฟเวอร์ยังไม่ตั้ง FCM (ตอบ fcm: false) — เจ้าของใส่กุญแจเมื่อไรก็ส่งได้ทันที ไม่ต้องรอแอปจดใหม่
 * นโยบายว่าส่งอะไร เมื่อไร กี่ครั้ง อยู่ที่ PushNotifier ที่เดียว แอปกับเว็บใช้กติกาเดียวกัน
 * ออกจากระบบ/เปลี่ยนรหัส/ลบบัญชี = โทเคน Sanctum ถูกลบ แถวเครื่องที่จดด้วยโทเคนนั้นหายตาม (ดู migration push_devices)
 */
class PushController extends Controller
{
    public function storeDevice(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^[A-Za-z0-9_:.\-]+$/'],
            'platform' => ['nullable', 'string', Rule::in(PushDevice::PLATFORMS)],
            'channel' => ['nullable', 'string', Rule::in(PushDevice::CHANNELS)],
            'app_version' => ['nullable', 'string', 'max:32'],
        ], [
            'token.required' => 'ไม่พบรหัสรับแจ้งเตือนของเครื่องนี้',
            'token.*' => 'รหัสรับแจ้งเตือนของเครื่องนี้ไม่ถูกต้อง',
            'platform.*' => 'ตอนนี้แจ้งเตือนในแอปรองรับเฉพาะ Android ค่ะ',
            'channel.*' => 'ช่องทางที่ติดตั้งแอปไม่ถูกต้อง',
            'app_version.*' => 'เวอร์ชันแอปไม่ถูกต้อง',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $data = $v->validated();
        $user = $request->user();
        // ผูกกับโทเคน Sanctum จริงเท่านั้น (TransientToken/โทเคนจำลองในเทสต์ = ไม่ผูก)
        $current = $user->currentAccessToken();
        $tokenId = $current instanceof PersonalAccessToken && $current->exists ? $current->getKey() : null;

        $device = PushDevice::updateOrCreate(
            ['token_hash' => PushDevice::hashToken($data['token'])],
            [
                'user_id' => $user->id,
                'personal_access_token_id' => $tokenId,
                'token' => $data['token'],
                'platform' => $data['platform'] ?? 'android',
                'channel' => $data['channel'] ?? self::headerChannel($request),
                'app_version' => $data['app_version'] ?? null,
                'fail_count' => 0,
            ],
        );
        if (! $device->wasRecentlyCreated && ! $device->wasChanged()) {
            $device->touch(); // เปิดแอปซ้ำ = ยังใช้อยู่ — ไม่ถูกนับเป็น "เครื่องเก่าสุด" ตอนเกินเพดาน
        }

        // เกินเพดานต่อคน → ลบเครื่องที่ไม่ได้เปิดแอปนานที่สุด (ไม่แตะเครื่องที่เพิ่งจด)
        $max = max(1, (int) config('fcm.max_per_user', 10));
        $extra = PushDevice::where('user_id', $user->id)->whereKeyNot($device->getKey())
            ->orderByDesc('updated_at')->orderByDesc('id')->skip($max - 1)->take(100)->pluck('id');
        if ($extra->isNotEmpty()) {
            PushDevice::whereIn('id', $extra)->delete();
        }

        return response()->json(['data' => [
            'registered' => true,
            'devices' => PushDevice::where('user_id', $user->id)->count(),
            'fcm' => FcmSender::configured(),
        ]]);
    }

    public function destroyDevice(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:4096'],
        ], [
            'token.required' => 'ไม่พบรหัสรับแจ้งเตือนของเครื่องนี้',
            'token.*' => 'รหัสรับแจ้งเตือนของเครื่องนี้ไม่ถูกต้อง',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $userId = $request->user()->id;
        $removed = PushDevice::where('user_id', $userId)
            ->where('token_hash', PushDevice::hashToken($v->validated()['token']))
            ->delete();

        return response()->json(['data' => [
            'removed' => $removed > 0,
            'devices' => PushDevice::where('user_id', $userId)->count(),
        ]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferencePayload($request)]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'muted' => ['present', 'array'],
            'muted.*' => ['string', 'max:32'], // หมวดที่ไม่รู้จักถูกกรองทิ้งใน saveMutedTypes
        ], [
            'muted.*' => 'รายการหมวดแจ้งเตือนที่ปิดไว้ไม่ถูกต้อง',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        PushNotifier::saveMutedTypes($request->user(), $v->validated()['muted']);

        return response()->json(['data' => $this->preferencePayload($request)]);
    }

    /** @return array{muted: list<string>, types: list<array{key:string, name:string, description:string}>, quiet: array{start:string, end:string}, fcm: bool} */
    private function preferencePayload(Request $request): array
    {
        $user = $request->user()->fresh() ?? $request->user();
        $channel = self::headerChannel($request);
        $types = [];
        foreach (PushNotifier::TYPES as $key => [$name]) {
            // หมวดที่แอดมินปิดไม่ต้องให้เลือก (เหมือนการ์ดบนเว็บ) · แอปจาก Play ไม่เห็นหมวดยอดค้าง (ไม่ได้รับอยู่แล้ว)
            if (! PushNotifier::typeEnabled($key) || ! PushNotifier::appMayReceive($key, $channel)) {
                continue;
            }
            $types[] = ['key' => $key, 'name' => $name, 'description' => PushNotifier::MEMBER_LABELS[$key] ?? $name];
        }
        [$start, $end] = PushNotifier::quietHours();

        return [
            'muted' => PushNotifier::mutedTypes($user),
            'types' => $types,
            'quiet' => ['start' => $start, 'end' => $end],
            'fcm' => FcmSender::configured(),
        ];
    }

    /** X-App-Channel: play | direct — ค่าอื่นหรือไม่ส่ง = null (ถือเป็น play) */
    private static function headerChannel(Request $request): ?string
    {
        $channel = strtolower(trim((string) $request->header('X-App-Channel')));

        return in_array($channel, PushDevice::CHANNELS, true) ? $channel : null;
    }

    private function invalid(ValidatorContract $v): JsonResponse
    {
        return response()->json([
            'message' => $v->errors()->first(),
            'reason_code' => 'validation_failed',
            'errors' => $v->errors()->toArray(),
        ], 422);
    }
}
