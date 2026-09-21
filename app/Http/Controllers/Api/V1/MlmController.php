<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mlm\MlmApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile MLM dashboard — wraps {@see MlmApiClient} which calls the
 * upstream Thaiprompt-Affiliate `/api/v1/juntra/mlm/*` endpoints with
 * the user's stored `thaiprompt_token`.
 *
 * 🌙 (2026-09-21) ลูกค้าทุกคนเห็นสายงานของตัวเอง — ไม่ต้องผูก Thaiprompt แล้ว
 *   (อ่านด้วยตัวตนของเซิร์ฟเวอร์จันทรา ดู MlmApiClient) จึงตอบ linked: true เสมอ
 *   แอพรุ่นเก่าที่ยังมีหน้าจอ "เชื่อมต่อบัญชี Thaiprompt" จะไม่เจอ 403 thaiprompt_not_linked อีก
 *
 * Payload shape passes upstream JSON through largely unchanged so the
 * mobile and web dashboards stay in sync if Thaiprompt adds fields. We
 * only annotate with `linked: true` to make the affirmative case
 * trivially detectable on the client.
 */
class MlmController extends Controller
{
    public function __construct(private MlmApiClient $api) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $stats = $this->api->stats($user);
        return response()->json([
            'linked'     => true,
            'data'       => $stats,
            'fetched_at' => $this->api->lastFetchedAt()?->toIso8601String(),
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $request->validate([
            'depth' => 'sometimes|integer|min:1|max:10',
        ]);
        $user = $request->user();
        $tree = $this->api->tree($user, null, (int) $request->input('depth', 5));
        return response()->json([
            'linked'     => true,
            'data'       => $tree,
            'fetched_at' => $this->api->lastFetchedAt()?->toIso8601String(),
        ]);
    }

    /**
     * Pull-to-refresh hook — busts the per-user MLM cache so the GETs that
     * follow return live Thaiprompt numbers (same totals the web sees).
     * Throttled in routes; refreshing triggers real upstream calls.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->api->bustCache($user);
        return response()->json(['refreshed' => true]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'status'   => 'sometimes|nullable|string|max:32',
            'from'     => 'sometimes|nullable|date',
            'to'       => 'sometimes|nullable|date',
        ]);

        $user = $request->user();

        $payload = $this->api->commissions(
            $user,
            null,
            (int) $request->input('page', 1),
            array_filter([
                'status'   => $request->input('status'),
                'from'     => $request->input('from'),
                'to'       => $request->input('to'),
                'per_page' => $request->input('per_page', 25),
            ], fn ($v) => $v !== null),
        );

        return response()->json([
            'linked' => true,
            'data'   => $payload['data'] ?? [],
            'meta'   => $payload['meta'] ?? ['total' => 0, 'last_page' => 1, 'current_page' => 1],
        ]);
    }
}
