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
 * Pre-flight: every method short-circuits with 403 `thaiprompt_not_linked`
 * when the user hasn't completed the OAuth SSO to Thaiprompt yet — there's
 * literally no upstream session to pull commissions for. The Flutter
 * affiliate screen reads that reason_code and renders the "เชื่อมต่อบัญชี
 * Thaiprompt" CTA instead of an empty dashboard.
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
        if (!$user->isThaipromptUsable()) {
            return $this->notLinked();
        }
        $stats = $this->api->stats($user);
        return response()->json([
            'linked' => true,
            'data'   => $stats,
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $request->validate([
            'depth' => 'sometimes|integer|min:1|max:10',
        ]);
        $user = $request->user();
        if (!$user->isThaipromptUsable()) {
            return $this->notLinked();
        }
        $tree = $this->api->tree($user, null, (int) $request->input('depth', 5));
        return response()->json([
            'linked' => true,
            'data'   => $tree,
        ]);
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
        if (!$user->isThaipromptUsable()) {
            return $this->notLinked();
        }

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

    /**
     * Standard envelope when the user hasn't linked Thaiprompt yet.
     * The Flutter app branches on `reason_code` to render the CTA.
     */
    private function notLinked(): JsonResponse
    {
        return response()->json([
            'linked'      => false,
            'reason_code' => 'thaiprompt_not_linked',
            'message'     => 'เชื่อมต่อบัญชี Thaiprompt ก่อนเพื่อดูข้อมูลสายงาน — ทำผ่านเว็บไซต์ จันทรา.online แล้วกลับมาที่แอพได้เลย',
        ], 403);
    }
}
