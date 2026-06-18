<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Mobile auth — email/password Sanctum tokens for the juntra Flutter app.
 *
 * Web flow (Breeze + Thaiprompt SSO) is untouched. This controller adds
 * a parallel JSON path so mobile can authenticate without the SSO
 * redirect dance, then later (optionally) link the same user to
 * Thaiprompt by visiting the web auth/thaiprompt/redirect from inside
 * an in-app webview.
 */
class AuthController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'device'   => 'sometimes|string|max:64',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            ], 401);
        }

        $deviceName = $data['device'] ?? ($request->userAgent() ?: 'mobile');
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->profile($user),
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'device'   => 'sometimes|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'ข้อมูลไม่ถูกต้อง',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $user = User::create([
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'signup_via' => 'mobile',
        ]);

        // Pre-create wallet so the first GET /wallet doesn't lazy-create
        // mid-request and leak the latency to the user.
        $this->wallet->getOrCreate($user);

        $token = $user->createToken($data['device'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->profile($user->fresh()),
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profile($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revokes the *current* token only — other devices stay logged in.
        $request->user()->currentAccessToken()->delete();
        return response()->json(['data' => ['message' => 'ออกจากระบบเรียบร้อย']]);
    }

    /**
     * Mint a SHORT-LIVED, single-use handoff code for the web OAuth bootstrap
     * (Thaiprompt SSO link).
     *
     * The app must hand its session to the browser to finish the SSO link, but
     * putting the long-lived Sanctum bearer in the mobile-start URL leaks it
     * into browser history / server logs. Instead the app calls THIS with the
     * bearer in the Authorization header and passes the returned code (valid
     * 120s, usable once) in the URL. Even if the code is logged it's useless
     * after it expires and can't be replayed. The raw code is never stored —
     * only its SHA-256 — so a cache dump doesn't reveal usable codes.
     */
    public function handoff(Request $request): JsonResponse
    {
        $code = Str::random(48);
        Cache::put(
            'mobile_handoff:' . hash('sha256', $code),
            $request->user()->id,
            now()->addSeconds(120),
        );

        return response()->json([
            'data' => ['code' => $code, 'expires_in' => 120],
        ]);
    }

    /**
     * Compact user payload — same shape as /api/v1/auth/me so the Flutter
     * client can cache it from the login response without a second call.
     */
    private function profile(User $user): array
    {
        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'display_name'      => $user->name,
            'email'             => $user->email,
            'role'              => $user->role,
            'is_admin'          => $user->isAdmin(),
            'thaiprompt_linked' => $user->isThaipromptLinked(),
            'chat_link_channel' => $user->chatLinkChannel(),
            'wallet'            => [
                'balance'  => (float) $this->wallet->balance($user),
                'currency' => config('pricing.currency', 'THB'),
            ],
        ];
    }
}
