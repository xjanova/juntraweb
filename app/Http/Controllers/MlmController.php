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

        // เมนู "คอมมิชชั่นดูดวง" ในหลังบ้าน Thaiprompt ลิงก์มาที่ URL นี้ตรง ๆ
        // (config/menus.php → /auth/thaiprompt/redirect?to=/mlm/commissions)
        // แต่ที่นี่เป็นปลายทาง XHR ของตารางในหน้า /mlm ไม่ใช่หน้าเว็บ → คนกดเมนู
        // เลยเจอ JSON ดิบเต็มจอ ส่งไปที่ตารางจริงในแดชบอร์ดแทน
        if (! $this->wantsJson($request)) {
            return $this->redirectToDashboard($targetId, 'commissions');
        }

        $page = max(1, (int) $request->input('page', 1));

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
        // เมนู "ทีมดูดวงของฉัน" ของ Thaiprompt ก็ลิงก์มาที่นี่ตรง ๆ เหมือนกัน
        // สมาชิกทั่วไปจะเจอ 403 ส่วนแอดมินเจอ JSON ดิบ — ทั้งที่สิ่งที่เขาอยากดู
        // คือผังสายงานในหน้าแดชบอร์ด (ซึ่งทุกคนดูของตัวเองได้อยู่แล้ว)
        // เช็คก่อน abort เพื่อไม่ให้สมาชิกทั่วไปโดนปิดประตูตั้งแต่ยังไม่ทันเห็นหน้า
        if (! $this->wantsJson($request)) {
            return $this->redirectToDashboard($this->resolveTarget($request, $request->user()), 'team');
        }

        abort_unless($request->user()->isAdmin(), 403);
        $data = $this->api->users($request->user(), q: (string) $request->input('q', ''));
        return response()->json($data);
    }

    /**
     * เบราว์เซอร์เปิด URL ตรง ๆ หรือ XHR ของหน้าแดชบอร์ดเรียกมา
     *
     * เช็คทั้งสองอย่างเพราะ expectsJson() อย่างเดียวไม่พอ — Accept ของเบราว์เซอร์
     * มี * / * ต่อท้ายเสมอ ทำให้ wantsJson() ตีความว่าเป็น JSON ได้ในบางเคส
     */
    private function wantsJson(Request $request): bool
    {
        return $request->ajax() || $request->expectsJson();
    }

    /** ส่งกลับแดชบอร์ด /mlm แล้วเลื่อนไปที่ส่วนที่ผู้ใช้ตั้งใจจะดู */
    private function redirectToDashboard(?int $targetId, string $anchor)
    {
        return redirect()
            ->route('mlm.dashboard', array_filter(['user_id' => $targetId]))
            ->withFragment($anchor);
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
