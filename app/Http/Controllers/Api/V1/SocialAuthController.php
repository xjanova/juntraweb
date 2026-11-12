<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MobileSocialLogin;
use App\Services\ThaipromptClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SocialAuthController extends Controller
{
    public function providers(ThaipromptClient $client, MobileSocialLogin $login)
    {
        return response()->json(['data' => ['providers' => $login->browserOrigin() ? $client->socialProviders() : []]])
            ->header('Cache-Control', 'no-store');
    }

    public function start(Request $request, ThaipromptClient $client, MobileSocialLogin $login)
    {
        $origin = $login->browserOrigin();
        abort_if($origin === null, 503, 'ระบบเข้าสู่ระบบยังไม่พร้อม กรุณาลองใหม่ภายหลัง');
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_column($client->socialProviders(), 'id'))],
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'device' => ['required', Rule::in(['mobile'])],
            'referral_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
        ]);
        $id = bin2hex(random_bytes(32));
        DB::table('social_login_requests')->insert([
            'id' => $login->key($id),
            'code_challenge' => $data['code_challenge'],
            'provider' => $data['provider'],
            'device' => $data['device'],
            'referral_code' => $data['referral_code'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addSeconds(MobileSocialLogin::TTL),
        ]);

        return response()->json(['data' => [
            'id' => $id,
            'url' => $origin.route('auth.social.browser', ['id' => $id], false),
            'expires_in' => MobileSocialLogin::TTL,
            'poll_interval' => 2,
        ]])->header('Cache-Control', 'no-store');
    }

    public function browser(Request $request, string $id, ThaipromptClient $client, MobileSocialLogin $login)
    {
        $key = $login->key($id);
        $ticket = $login->pending($key);
        if (! $ticket || ! in_array($ticket->provider, array_column($client->socialProviders(), 'id'), true)) {
            return $login->page();
        }
        $binding = bin2hex(random_bytes(32));
        $claimed = DB::table('social_login_requests')->where('id', $key)
            ->whereNull('browser_hash')->where('status', 'pending')->where('expires_at', '>', now())
            ->update(['browser_hash' => $login->key($binding)]);
        if ($claimed !== 1) {
            return $login->page();
        }

        $login->finish($request, 'cancelled');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $request->session()->put('mobile_social', [
            'id' => $key, 'binding' => $binding, 'state' => $state,
            'provider' => $ticket->provider, 'nonce' => $nonce,
            'referral_code' => $ticket->referral_code,
        ]);

        return redirect()->away($client->socialAuthorizeUrl($state, $ticket->provider, $nonce))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function poll(Request $request, MobileSocialLogin $login, AuthController $auth)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/D'],
        ]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'), '=');
        $result = DB::transaction(function () use ($data, $login, $auth, $challenge) {
            $ticket = DB::table('social_login_requests')->where('id', $login->key($data['id']))->lockForUpdate()->first();
            if (! $ticket || ! hash_equals($ticket->code_challenge, $challenge) || $ticket->status === 'consumed') {
                return null;
            }
            if (now()->greaterThanOrEqualTo($ticket->expires_at)) {
                return ['status' => 'expired'];
            }
            if ($ticket->status !== 'authorized') {
                return ['status' => $ticket->status === 'processing' ? 'pending' : $ticket->status];
            }
            $user = User::find($ticket->user_id);
            if (! $user || in_array($user->role, ['admin', 'editor'], true)) {
                return ['status' => 'failed'];
            }
            $consumed = DB::table('social_login_requests')->where('id', $ticket->id)
                ->where('status', 'authorized')->where('expires_at', '>', now())
                ->update(['status' => 'consumed']);
            if ($consumed !== 1) {
                return null;
            }

            return ['status' => 'authorized', 'token' => $user->createToken($ticket->device)->plainTextToken,
                'user' => $auth->profile($user)];
        }, 3);

        return ($result === null
            ? response()->json(['message' => 'คำขอไม่ถูกต้องหรือหมดอายุ'], 404)
            : response()->json(['data' => $result]))->header('Cache-Control', 'no-store, private');
    }
}
