<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     *
     * The account is SOFT-deleted so the wallet ledger (wallet_transactions
     * has an FK cascadeOnDelete) survives for bookkeeping retention. To honour
     * the PDPA "right to erasure" we scrub all personally-identifying fields
     * first — the row that remains carries no usable PII, only the financial
     * trail. The email placeholder embeds the user id so it can never collide
     * with the unique constraint, and the whole operation is idempotent.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        DB::transaction(function () use ($user) {
            // Revoke any mobile Sanctum tokens so a stored bearer can't be used.
            $user->tokens()->delete();

            $user->forceFill([
                'name'               => 'ผู้ใช้ที่ลบบัญชี',
                'email'              => 'deleted+' . $user->id . '@deleted.local',
                'email_verified_at'  => null,
                'thaiprompt_user_id' => null,
                'thaiprompt_token'   => null,
                'thaiprompt_synced_at' => null,
                'facebook_user_id'   => null,
                'line_user_id'       => null,
            ])->save();

            $user->delete(); // soft delete — row + wallet ledger are retained
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
