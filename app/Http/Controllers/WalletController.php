<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function index(Request $request)
    {
        $user   = $request->user();
        $wallet = $this->wallet->getOrCreate($user);

        // Filters: type ∈ {topup, debit, refund, adjustment}, status ∈ all
        // wallet statuses, from/to are inclusive date bounds (Y-m-d).
        $type   = $request->input('type');
        $status = $request->input('status');
        $from   = $request->input('from');
        $to     = $request->input('to');

        $query = WalletTransaction::where('user_id', $user->id);
        if (in_array($type,   ['topup', 'debit', 'refund', 'adjustment'], true)) {
            $query->where('type', $type);
        }
        if (in_array($status, ['pending', 'success', 'failed', 'refunded'], true)) {
            $query->where('status', $status);
        }
        if ($from) {
            try { $query->where('created_at', '>=', \Carbon\Carbon::parse($from)->startOfDay()); } catch (\Throwable) {}
        }
        if ($to) {
            try { $query->where('created_at', '<=', \Carbon\Carbon::parse($to)->endOfDay()); } catch (\Throwable) {}
        }

        $tx = $query->latest()->paginate(20)->withQueryString();

        return view('pages.wallet.index', [
            'wallet'      => $wallet,
            'transactions'=> $tx,
            'pricingMap'  => Pricing::labels(),
            'pricing'     => collect(Pricing::labels())->mapWithKeys(
                fn ($label, $key) => [$key => Pricing::for($key)]
            ),
            'filters'     => compact('type', 'status', 'from', 'to'),
        ]);
    }

    /** List of top-up requests with their status — focused on PromptPay slip flow. */
    public function topups(Request $request)
    {
        $user = $request->user();
        $tx = WalletTransaction::where('user_id', $user->id)
            ->where('type', 'topup')
            ->latest()
            ->paginate(20);

        $pendingCount = WalletTransaction::where('user_id', $user->id)
            ->where('type', 'topup')->where('status', 'pending')->count();

        return view('pages.wallet.topups', [
            'topups'       => $tx,
            'pendingCount' => $pendingCount,
        ]);
    }

    public function topupForm(Request $request)
    {
        // Read PromptPay info from Setting (admin-editable via /admin/wallet-settings)
        // and fall back to config (env-driven). Without this fallback, admin's live
        // edits don't reach the user-facing top-up form until the next deploy.
        return view('pages.wallet.topup', [
            'bundles'        => config('pricing.topup_bundles', [50, 100, 200, 500]),
            'min'            => (int) config('pricing.min_topup', 20),
            'max'            => (int) config('pricing.max_topup', 50000),
            'promptpayId'    => Setting::get('promptpay_id',   config('pricing.promptpay_id', '')),
            'promptpayName'  => Setting::get('promptpay_name', config('pricing.promptpay_name', '')),
        ]);
    }

    public function topupSubmit(Request $request)
    {
        $min = (int) config('pricing.min_topup', 20);
        $max = (int) config('pricing.max_topup', 50000);

        $data = $request->validate([
            'amount' => "required|numeric|min:$min|max:$max",
            'method' => 'required|in:promptpay,manual',
            'slip'   => 'nullable|image|max:4096',
            'note'   => 'nullable|string|max:255',
        ]);

        // Slips contain bank info — store on the PRIVATE 'local' disk, not 'public'.
        // The image is served back to the owner/admin via topupSlip() with auth.
        $slipPath = null;
        if ($request->hasFile('slip')) {
            $slipPath = $request->file('slip')->store('topup-slips', 'local');
        }

        $tx = $this->wallet->recordPendingTopup(
            $request->user(),
            (float) $data['amount'],
            $slipPath,
            $data['method'],
        );

        if (!empty($data['note'])) {
            $tx->update(['meta' => array_merge((array) $tx->meta, ['note' => $data['note']])]);
        }

        return redirect()
            ->route('wallet.topup.show', $tx)
            ->with('status', 'ส่งคำขอเติมเงินเรียบร้อย — แอดมินกำลังตรวจสอบสลิป');
    }

    public function topupShow(Request $request, WalletTransaction $tx)
    {
        $this->authorizeOwnerOrAdmin($request, $tx);

        return view('pages.wallet.topup-show', [
            'tx'      => $tx,
            // Use the auth-gated route, not a direct disk URL — the slip lives
            // on the private disk so direct disk URLs would 404 anyway.
            'slipUrl' => $tx->slip_path ? route('wallet.topup.slip', $tx) : null,
        ]);
    }

    /**
     * Stream a top-up slip from the PRIVATE disk after verifying the requester
     * owns the transaction (or is an admin). Slips can include bank account
     * numbers, names, and the last 4 digits of the sender's account — they
     * must never be served from a guessable public URL.
     */
    public function topupSlip(Request $request, WalletTransaction $tx): StreamedResponse
    {
        $this->authorizeOwnerOrAdmin($request, $tx);
        abort_unless($tx->slip_path && Storage::disk('local')->exists($tx->slip_path), 404);

        return Storage::disk('local')->response($tx->slip_path);
    }

    private function authorizeOwnerOrAdmin(Request $request, WalletTransaction $tx): void
    {
        $user = $request->user();
        $isOwner = $tx->user_id === $user->id;
        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();
        abort_unless($isOwner || $isAdmin, 403);
    }
}
