<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateSlipException;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\SmsPayment\SmsCheckerService;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use App\Support\PromptPayQr;
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
        $promptpayId = Setting::get('promptpay_id', config('pricing.promptpay_id', ''));

        return view('pages.wallet.topup', [
            'bundles'        => config('pricing.topup_bundles', [50, 100, 200, 500]),
            'min'            => (int) config('pricing.min_topup', 20),
            'max'            => (int) config('pricing.max_topup', 50000),
            'promptpayId'    => $promptpayId,
            'promptpayName'  => Setting::get('promptpay_name', config('pricing.promptpay_name', '')),
            // Static QR (no amount) — user scans then types the amount in their
            // banking app. A dynamic per-amount QR is shown on the status page
            // once the amount is locked in.
            'promptpayQr'    => $promptpayId ? PromptPayQr::svgDataUri($promptpayId) : null,
        ]);
    }

    public function topupSubmit(Request $request, SmsCheckerService $sms)
    {
        $min = (int) config('pricing.min_topup', 20);
        $max = (int) config('pricing.max_topup', 50000);

        $data = $request->validate([
            'amount' => "required|numeric|min:$min|max:$max",
            'method' => 'required|in:promptpay,manual',
            'slip'   => 'nullable|image|max:4096',
            'note'   => 'nullable|string|max:255',
        ]);

        // When the SMS gateway is on, a PromptPay top-up is charged a UNIQUE
        // amount so an incoming bank SMS maps to exactly this request.
        $base    = (float) $data['amount'];
        $payable = (config('smschecker.enabled') && $data['method'] === 'promptpay')
            ? $sms->uniqueAmountFor($base)
            : $base;

        // Slips contain bank info — store on the PRIVATE 'local' disk, not 'public'.
        // The image is served back to the owner/admin via topupSlip() with auth.
        $slipPath = null;
        $slipHash = null;
        if ($request->hasFile('slip')) {
            $file = $request->file('slip');
            // sha256 of the slip → blocks the same payment slip from being
            // credited to two different top-ups. Check BEFORE storing so we
            // don't litter the private disk with a known duplicate.
            $slipHash = hash_file('sha256', $file->getRealPath());
            try {
                $this->wallet->assertSlipNotReused($slipHash);
            } catch (DuplicateSlipException $e) {
                return back()->withInput()->withErrors(['slip' => $e->getMessage()]);
            }
            $slipPath = $file->store('topup-slips', 'local');
        }

        try {
            $tx = $this->wallet->recordPendingTopup(
                $request->user(),
                $payable,
                $slipPath,
                $data['method'],
                $slipHash,
            );
        } catch (DuplicateSlipException $e) {
            $this->discardSlip($slipPath);
            return back()->withInput()->withErrors(['slip' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            // Pending-cap reached, etc.
            $this->discardSlip($slipPath);
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $meta = [];
        if (abs($payable - $base) > 0.0001) {
            $meta['base_amount'] = $base;
        }
        if (!empty($data['note'])) {
            $meta['note'] = $data['note'];
        }
        if ($meta) {
            $tx->update(['meta' => array_merge((array) $tx->meta, $meta)]);
        }

        return redirect()
            ->route('wallet.topup.show', $tx)
            ->with('status', 'ส่งคำขอเติมเงินเรียบร้อย — แอดมินกำลังตรวจสอบสลิป');
    }

    /** Cancel one of the requester's own still-pending top-ups. */
    public function topupCancel(Request $request, WalletTransaction $tx)
    {
        // Owner-only — cancelTopup() re-checks ownership inside the service too.
        abort_unless($tx->user_id === $request->user()->id, 403);
        try {
            $this->wallet->cancelTopup($tx, $request->user());
        } catch (\RuntimeException $e) {
            return redirect()->route('wallet.topups')->with('status', $e->getMessage());
        }
        return redirect()->route('wallet.topups')->with('status', 'ยกเลิกรายการเติมเงินเรียบร้อยแล้ว');
    }

    /** Best-effort delete of a just-stored slip when the top-up gets rejected. */
    private function discardSlip(?string $slipPath): void
    {
        if ($slipPath && Storage::disk('local')->exists($slipPath)) {
            Storage::disk('local')->delete($slipPath);
        }
    }

    public function topupShow(Request $request, WalletTransaction $tx)
    {
        $this->authorizeOwnerOrAdmin($request, $tx);

        // While the top-up is still pending, show a PromptPay QR carrying the
        // exact amount so the user can scan-to-pay without re-typing it.
        $promptpayId = Setting::get('promptpay_id', config('pricing.promptpay_id', ''));
        $qr = ($tx->status === 'pending' && $promptpayId)
            ? PromptPayQr::svgDataUri($promptpayId, (float) $tx->amount)
            : null;

        return view('pages.wallet.topup-show', [
            'tx'      => $tx,
            // Use the auth-gated route, not a direct disk URL — the slip lives
            // on the private disk so direct disk URLs would 404 anyway.
            'slipUrl'       => $tx->slip_path ? route('wallet.topup.slip', $tx) : null,
            'promptpayQr'   => $qr,
            'promptpayName' => Setting::get('promptpay_name', config('pricing.promptpay_name', '')),
            'canCancel'     => $tx->status === 'pending' && $tx->user_id === $request->user()->id,
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
