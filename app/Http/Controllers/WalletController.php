<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateSlipException;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\SmsPayment\AmountReservation;
use App\Services\Wallet\WalletService;
use App\Support\Pricing;
use App\Support\PayoutAccount;
use App\Support\PromptPayQr;
use App\Support\TopupChannel;
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
        // บัญชีเดียวกับแม่หมอใน FB/LINE (ดู App\Support\PayoutAccount)
        $payout      = PayoutAccount::resolve($request->user());
        $promptpayId = $payout['promptpay_id'] ?? '';

        $limits = TopupChannel::limits();

        return view('pages.wallet.topup', [
            'bundles'        => $limits['bundles'],
            'min'            => $limits['min'],
            'max'            => $limits['max'],
            'promptpayId'    => $promptpayId,
            'promptpayName'  => $payout['name'] ?? '',
            // Static QR (no amount) — user scans then types the amount in their
            // banking app. A dynamic per-amount QR is shown on the status page
            // once the amount is locked in.
            'promptpayQr'    => $promptpayId ? PromptPayQr::svgDataUri($promptpayId) : null,
            // 🏦 (2026-11-11) เจ้าของ: "ทำหน้านี้ใหม่ให้สวยงาม และเพิ่มกสิกรด้วย"
            // บัญชีกสิกรไทยของบัญชีเดียวกับ QR (Thaiprompt ส่งมาเฉพาะตอนเปิดตัวจับ SMS)
            // null = ไม่โชว์ช่องทางกสิกรเลย — ห้ามเดาเลขบัญชีให้ลูกค้าโอน
            'transfer'       => $payout['transfer'] ?? null,
            // ตัวจับ SMS เปิดอยู่ = ยอดที่ต้องโอนจะมีเศษสตางค์เฉพาะรายการ → เครดิตเข้าเอง
            'autoMatch'      => (bool) config('smschecker.enabled'),
        ]);
    }

    public function topupSubmit(Request $request, AmountReservation $amounts)
    {
        ['min' => $min, 'max' => $max] = TopupChannel::limits();

        $data = $request->validate([
            'amount' => "required|numeric|min:$min|max:$max",
            'method' => 'required|in:promptpay,kbank,manual',
            'slip'   => 'nullable|image|max:4096',
            'note'   => 'nullable|string|max:255',
        ]);

        // 🏦 'kbank' = บัญชีเดียวกับ QR พร้อมเพย์ → ภายในเป็น method 'promptpay' (กติกาใน TopupChannel ใช้ร่วมกับแอพ)
        $channel = $data['method'];
        $picked  = null;
        if ($channel !== 'manual') {
            $picked = TopupChannel::pick($request->user(), $channel);
            if (! $picked) {
                return back()->withInput()->withErrors(['method' => TopupChannel::KBANK_UNAVAILABLE]);
            }
            $data['method'] = 'promptpay';
        }

        // A slip attached at submit time = the customer ALREADY paid the plain
        // typed amount → charge it as-is under 'promptpay_slip', which SMS
        // auto-matching (method='promptpay' only) can never FIFO-match to the
        // wrong customer. Only a PromptPay top-up WITHOUT a slip gets a UNIQUE
        // amount (reserved cross-site at Thaiprompt by AmountReservation).
        $base   = (float) $data['amount'];
        $method = ($data['method'] === 'promptpay' && $request->hasFile('slip'))
            ? 'promptpay_slip'
            : $data['method'];

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
            $tx = $method === 'promptpay'
                ? $amounts->createPendingTopup($request->user(), $base)
                : $this->wallet->recordPendingTopup(
                    $request->user(),
                    $base,
                    $slipPath,
                    $method,
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

        $payable = (float) $tx->amount; // final amount — never one computed before the row existed
        $meta = [];
        if (abs($payable - $base) > 0.0001) {
            $meta['base_amount'] = $base;
        }
        if (!empty($data['note'])) {
            $meta['note'] = $data['note'];
        }
        if ($picked) {
            // จดบัญชีที่โชว์ให้ลูกค้าโอนไว้กับรายการ (เหมือนบิลในห้องแชท) — หน้าสถานะจะโชว์
            // บัญชีของรายการนี้ ไม่ใช่บัญชีใหม่ที่แอดมินอาจสลับไปทีหลัง
            $meta += TopupChannel::meta($picked);
        }
        if ($meta) {
            $tx->update(['meta' => array_merge((array) $tx->meta, $meta)]);
        }

        // โอนแล้ว + แนบสลิปมาพร้อมฟอร์ม → ตรวจอัตโนมัติทันที (SlipOK + ทะเบียนสลิปของแม่หมอ)
        // ตรวจไม่ได้/ไม่ผ่าน = รอแอดมินตามเดิม (แจ้ง Telegram แล้วใน SlipAutoVerifier)
        if ($slipPath && $method === 'promptpay_slip') {
            $result = app(\App\Services\Wallet\SlipAutoVerifier::class)->verify($tx->fresh(), $request->user(), $slipPath);

            return redirect()
                ->route('wallet.topup.show', $tx)
                ->with('status', $result['message'] ?? 'ส่งคำขอเติมเงินเรียบร้อย — แอดมินกำลังตรวจสอบสลิป');
        }

        return redirect()
            ->route('wallet.topup.show', $tx)
            ->with('status', $slipPath
                ? 'ส่งคำขอเติมเงินเรียบร้อย — แอดมินกำลังตรวจสอบสลิป'
                : ($channel === 'kbank'
                    ? 'สร้างรายการแล้ว — โอนเข้าบัญชีกสิกรไทยด้านล่างด้วยยอดนี้ให้ครบรวมสตางค์ ระบบจะเครดิตให้อัตโนมัติเมื่อเงินเข้า'
                    : 'สร้างรายการแล้ว — สแกน QR ด้านล่างโอนยอดให้ตรงเป๊ะ ระบบจะเครดิตให้อัตโนมัติเมื่อเงินเข้า'));
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

    /**
     * แนบสลิปจากหน้าสถานะรายการ (โอนตาม QR ยอดเฉพาะแล้ว แต่ SMS ยังไม่เข้า / อยากให้เร็วขึ้น)
     *
     * กติกาเดียวกับห้องแชทและแอพ: สลิปใบเดียวใช้ได้ครั้งเดียว → ตรวจอัตโนมัติด้วย
     * SlipAutoVerifier (SlipOK + ทะเบียนสลิปของแม่หมอ) → ตรวจไม่ได้ = รอแอดมินตามเดิม
     */
    public function topupSlipUpload(Request $request, WalletTransaction $tx, \App\Services\Wallet\SlipAutoVerifier $slips)
    {
        abort_unless($tx->user_id === $request->user()->id, 403);
        if ($tx->type !== 'topup' || $tx->status !== 'pending') {
            return redirect()->route('wallet.topup.show', $tx)->with('status', 'รายการนี้ไม่ได้รอตรวจสอบแล้ว');
        }

        $request->validate(['slip' => 'required|image|max:4096']);
        $file = $request->file('slip');
        $hash = hash_file('sha256', $file->getRealPath());
        try {
            $this->wallet->assertSlipNotReused($hash, $tx->id);
        } catch (DuplicateSlipException $e) {
            return back()->withErrors(['slip' => $e->getMessage()]);
        }

        // เก็บใบใหม่ก่อน แล้วค่อยลบใบเก่า — ถ้าเก็บพลาด ใบเดิมต้องยังอยู่
        $old = $tx->slip_path;
        $path = $file->store('topup-slips', 'local');
        $tx->update(['slip_path' => $path, 'slip_hash' => $hash]);
        if ($old && $old !== $path) {
            $this->discardSlip($old);
        }

        $result = $slips->verify($tx->fresh(), $request->user(), $path);

        return redirect()->route('wallet.topup.show', $tx)->with('status', $result['message']
            ?? 'ได้รับสลิปแล้ว — แอดมินจะตรวจสอบและเครดิตให้โดยเร็ว');
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
        $payout      = PayoutAccount::resolve($request->user());
        $promptpayId = $payout['promptpay_id'] ?? '';
        // 🧾 รายการจากหลักฐานในแชทเพจ = ลูกค้าจ่ายมาแล้ว → ไม่มี QR (เคสจริง 2026-11-11: เห็น QR แล้วจ่ายซ้ำ)
        $qr = ($tx->awaitsCustomerPayment() && $promptpayId)
            ? PromptPayQr::svgDataUri($promptpayId, (float) $tx->amount)
            : null;
        $fromChat = $tx->method === WalletTransaction::METHOD_CHAT_SLIP;

        // 🏦 เลขบัญชีกสิกรสำหรับโอนยอดนี้ — ใช้ใบที่จดไว้กับรายการก่อน (TopupChannel ใช้ร่วมกับแอพ)
        $meta  = (array) $tx->meta;
        $wired = TopupChannel::ofTransaction($tx, $payout);

        return view('pages.wallet.topup-show', [
            'tx'      => $tx,
            'transfer'      => $wired['transfer'],
            'channel'       => $wired['channel'],
            'methodLabel'   => match (true) {
                $fromChat                              => 'ส่งหลักฐานในแชทเพจ',
                ($meta['channel'] ?? null) === 'kbank' => 'โอนเข้าบัญชีกสิกรไทย',
                $tx->method === 'promptpay'            => 'พร้อมเพย์ (สแกน QR)',
                $tx->method === 'promptpay_slip'       => 'พร้อมเพย์ + แนบสลิป',
                $tx->method === 'manual'               => 'ช่องทางอื่น (แอดมินตรวจ)',
                default                                => $tx->method ?: '—',
            },
            // Use the auth-gated route, not a direct disk URL — the slip lives
            // on the private disk so direct disk URLs would 404 anyway.
            'slipUrl'       => $tx->slip_path ? route('wallet.topup.slip', $tx) : null,
            'promptpayQr'   => $qr,
            'promptpayName' => $payout['name'] ?? '',
            // ยกเลิก/แนบใหม่ได้เฉพาะบิลที่ยังรอจ่าย — รายการจากแชทเงินเข้าแล้ว ให้แอดมินตัดสินเท่านั้น
            'canCancel'     => $tx->awaitsCustomerPayment() && $tx->user_id === $request->user()->id,
            'fromChat'      => $fromChat,
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
