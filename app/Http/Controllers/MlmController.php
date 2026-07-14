<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Mlm\MlmApiClient;
use Illuminate\Http\Request;

class MlmController extends Controller
{
    public function __construct(private MlmApiClient $api) {}

    /**
     * Member dashboard — KPI strip, earnings chart, downline tree, commission table.
     * Defaults to the logged-in user's tree. Admin can pass ?user_id= to view any.
     */
    public function dashboard(Request $request)
    {
        $auth     = $request->user();
        $targetId = $this->resolveTarget($request, $auth);

        $stats       = $this->api->stats($auth, $targetId);
        $tree        = $this->api->tree($auth, $targetId, depth: 5);
        $commissions = $this->api->commissions($auth, $targetId, page: 1, filters: ['per_page' => 25]);

        return view('pages.mlm.dashboard', [
            'auth'        => $auth,
            'isAdmin'     => $auth->isAdmin(),
            'viewingSelf' => $targetId === null || $targetId === $auth->id,
            'stats'       => $stats,
            'tree'        => $tree,
            'commissions' => $commissions,
            'targetId'    => $targetId,
            'fetchedAt'   => $this->api->lastFetchedAt()?->toIso8601String(),
        ]);
    }

    /**
     * "รีเฟรชยอดสด" — bust the per-user MLM cache so the next dashboard load
     * pulls live numbers from Thaiprompt. Throttled per user in routes.
     */
    public function refresh(Request $request)
    {
        $auth = $request->user();
        $this->api->bustCache($auth);

        return redirect()
            ->route('mlm.dashboard', array_filter(['user_id' => $this->resolveTarget($request, $auth)]))
            ->with('status', 'ดึงยอดล่าสุดจาก Thaiprompt เรียบร้อย — ตัวเลขชุดนี้คือยอดสด ✓');
    }

    /** Page through commissions (XHR target for the table). */
    public function commissions(Request $request)
    {
        $auth     = $request->user();
        $targetId = $this->resolveTarget($request, $auth);
        $page     = max(1, (int) $request->input('page', 1));

        $data = $this->api->commissions($auth, $targetId, $page, [
            'status'   => $request->input('status'),
            'from'     => $request->input('from'),
            'to'       => $request->input('to'),
            'per_page' => $request->input('per_page', 25),
        ]);

        return response()->json($data);
    }

    /** Admin search-box: list users with fortune activity for the picker. */
    public function users(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $this->api->users($request->user(), q: (string) $request->input('q', ''));
        return response()->json($data);
    }

    private function resolveTarget(Request $request, User $auth): ?int
    {
        $explicit = $request->input('user_id');
        if ($explicit !== null && (int) $explicit !== (int) $auth->id) {
            // Only admin can target another user. If they aren't admin we
            // just silently fall back to "self" — Thaiprompt will 403 too.
            return $auth->isAdmin() ? (int) $explicit : null;
        }
        return null;
    }
}
