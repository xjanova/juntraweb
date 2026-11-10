@extends('layouts.app')
@section('page-handles-flash', '1')
@section('title', 'สถานะการเติมเงิน')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:680px;margin:0 auto">
    <x-page-hero art="images/juntra/art/wallet.webp" />

    <div class="eyebrow">สถานะ</div>
    <h1 class="display" style="font-size:clamp(36px,4.5vw,60px);margin-bottom:16px">
      คำขอเติมเงิน <em style="color:var(--gold)">{{ $tx->reference_code }}</em>
    </h1>

    @if (session('status'))
      <div class="flash" style="padding:14px 18px;margin-bottom:24px">{{ session('status') }}</div>
    @endif

    <div class="panel" style="padding:28px">
      <dl style="display:grid;grid-template-columns:160px 1fr;gap:14px 16px;margin:0">
        <dt style="color:var(--ink-dim)">จำนวน</dt>
        <dd style="margin:0;font-family:var(--display);font-size:22px;color:var(--gold)">฿{{ number_format($tx->amount, 2) }}</dd>

        <dt style="color:var(--ink-dim)">สถานะ</dt>
        <dd style="margin:0">
          @php
            $color = match($tx->status) {
                'success'  => 'var(--gold)',
                'pending'  => '#d4a017',
                'failed'   => '#c2382e',
                'refunded' => '#888',
                default    => 'var(--ink-dim)',
            };
          @endphp
          <span style="display:inline-block;padding:4px 14px;border-radius:999px;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;border:1px solid {{ $color }};color:{{ $color }}">
            {{ $tx->statusLabel() }}
          </span>
        </dd>

        <dt style="color:var(--ink-dim)">วิธีการ</dt>
        <dd style="margin:0">{{ $methodLabel ?? ($tx->method ?: '—') }}</dd>

        <dt style="color:var(--ink-dim)">ส่งคำขอเมื่อ</dt>
        <dd style="margin:0">{{ $tx->created_at->format('d/m/Y H:i:s') }} · <span style="color:var(--ink-dim)">{{ $tx->created_at->diffForHumans() }}</span></dd>

        @if ($tx->approved_at)
          <dt style="color:var(--ink-dim)">{{ $tx->status === 'failed' ? 'ปฏิเสธเมื่อ' : 'อนุมัติเมื่อ' }}</dt>
          <dd style="margin:0">{{ $tx->approved_at->format('d/m/Y H:i:s') }}</dd>
        @endif

        @if (data_get($tx->meta, 'note'))
          <dt style="color:var(--ink-dim)">หมายเหตุของคุณ</dt>
          <dd style="margin:0">{{ data_get($tx->meta, 'note') }}</dd>
        @endif

        @if (data_get($tx->meta, 'reject_reason'))
          <dt style="color:#c2382e">เหตุผลที่ปฏิเสธ</dt>
          <dd style="margin:0;color:#c2382e">{{ data_get($tx->meta, 'reject_reason') }}</dd>
        @endif
      </dl>

      @if (!empty($promptpayQr))
        @php($payable = number_format($tx->amount, 2, '.', ''))
        {{-- 🏦 (2026-11-11) โอนได้สองทาง: สแกน QR ที่ใส่ยอดแล้ว หรือโอนเข้าเลขบัญชีกสิกรไทย (บัญชีเดียวกัน)
             เปิดที่ช่องทางที่ลูกค้าเลือกไว้ตอนสร้างรายการ --}}
        <div class="tp-show-pay"
             x-data="{ channel: @js($channel), copied: '',
                       async copy(t, k) { try { await navigator.clipboard.writeText(t); this.copied = k; } catch (_) { this.copied = 'fail'; }
                                          setTimeout(() => { this.copied = '' }, 2200); } }">
          <div style="text-align:center">
            <div class="eyebrow" style="display:inline-flex;margin-bottom:4px">ยอดที่ต้องโอน</div>
          </div>
          <div class="tp-show-amt">
            <b>฿{{ number_format($tx->amount, 2) }}</b>
            <button type="button" class="chip" @click="copy(@js($payable), 'amt')">
              <span class="chip-icon" aria-hidden="true">📋</span>
              <span x-text="copied === 'amt' ? 'คัดลอกแล้ว' : 'คัดลอกยอด'">คัดลอกยอด</span>
            </button>
          </div>

          @if (!empty($transfer))
            <div class="tp-channels" role="radiogroup" aria-label="ช่องทางโอน" style="margin-top:14px">
              <label class="tp-channel" :class="channel === 'promptpay' && 'is-on'">
                <input type="radio" value="promptpay" x-model="channel" @checked($channel === 'promptpay')>
                <span class="tp-logo tp-logo-pp" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>
                </span>
                <span class="tp-channel-txt"><b>สแกน QR</b><small>ยอดขึ้นเอง</small></span>
              </label>
              <label class="tp-channel" :class="channel === 'kbank' && 'is-on'">
                <input type="radio" value="kbank" x-model="channel" @checked($channel === 'kbank')>
                <span class="tp-logo tp-logo-kbank" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5v14M8 12.5 15.5 5M10.6 10.2 16 19"/></svg>
                </span>
                <span class="tp-channel-txt"><b>กสิกรไทย</b><small>โอนเข้าเลขบัญชี</small></span>
              </label>
            </div>
          @endif

          <div class="tp-account" x-show="channel !== 'kbank'" @if ($channel === 'kbank') x-cloak @endif>
            <div class="tp-qr"><img src="{{ $promptpayQr }}" alt="QR พร้อมเพย์ ยอด ฿{{ number_format($tx->amount, 2) }}" width="220" height="220" style="width:220px;height:220px"></div>
            @if (!empty($promptpayName))<div style="color:var(--ink-dim);font-size:13px">{{ $promptpayName }}</div>@endif
          </div>

          @if (!empty($transfer))
            <div class="tp-account" x-show="channel === 'kbank'" @if ($channel !== 'kbank') x-cloak @endif>
              <div class="tp-bankcard">
                <div class="tp-bankcard-top">
                  <span class="tp-logo tp-logo-kbank tp-logo-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5v14M8 12.5 15.5 5M10.6 10.2 16 19"/></svg>
                  </span>
                  <span class="tp-bankcard-bank">{{ $transfer['bank_name'] ?: 'ธนาคารกสิกรไทย' }}</span>
                </div>
                <div class="tp-bankcard-no">{{ $transfer['display_number'] ?? $transfer['account_number'] }}</div>
                <div class="tp-bankcard-name">ชื่อบัญชี {{ $transfer['account_name'] ?: $promptpayName }}</div>
              </div>
              <button type="button" class="btn btn-ghost tp-copy-btn" @click="copy(@js($transfer['account_number']), 'acct')">
                <span aria-hidden="true">🏦</span>
                <span x-text="copied === 'acct' ? 'คัดลอกเลขบัญชีแล้ว' : 'คัดลอกเลขบัญชี'">คัดลอกเลขบัญชี</span>
              </button>
            </div>
          @endif

          <p class="tp-copy-fail" style="text-align:center" x-show="copied === 'fail'" x-cloak>คัดลอกไม่ได้ — กดค้างที่ตัวเลขเพื่อคัดลอกได้นะคะ</p>
          <p class="tp-show-note">
            @if (config('smschecker.enabled'))
              โอนยอด <b>฿{{ number_format($tx->amount, 2) }}</b> ให้ครบรวมสตางค์ —
              ระบบจะเครดิตเข้าวอลเลตอัตโนมัติเมื่อเงินเข้า · ถ้าโอนยอดกลม แนบสลิปด้านล่าง ระบบตรวจแล้วเติมให้
            @else
              โอนแล้วอัปโหลดสลิป — แอดมินจะอนุมัติเครดิตให้
            @endif
          </p>
        </div>
      @endif

      @if ($slipUrl)
        <div style="margin-top:24px">
          <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">สลิปที่อัปโหลด</div>
          <a href="{{ $slipUrl }}" target="_blank" rel="noopener">
            <img src="{{ $slipUrl }}" alt="สลิปการโอน" style="max-width:100%;border-radius:14px;border:1px solid var(--line)" loading="lazy">
          </a>
          <div style="font-size:11px;color:var(--ink-faint);margin-top:6px">สลิปนี้เก็บแบบส่วนตัว — เฉพาะคุณกับแอดมินเปิดดูได้</div>
          @php($check = data_get($tx->meta, 'slip_check.decision'))
          @if ($tx->status === 'pending' && $check === 'review')
            <div style="font-size:13px;color:#d4a017;margin-top:10px">ระบบตรวจอัตโนมัติไม่ผ่านบางข้อ — แอดมินกำลังตรวจสลิปให้ค่ะ</div>
          @elseif ($tx->status === 'pending' && $check === 'duplicate')
            <div style="font-size:13px;color:#c2382e;margin-top:10px">สลิปนี้ถูกใช้ไปแล้ว — ถ้าโอนใหม่แล้ว แนบสลิปใบใหม่ด้านล่างได้เลย</div>
          @endif
        </div>
      @endif

      @if (!empty($canCancel))
        <form method="POST" action="{{ route('wallet.topup.slip.upload', $tx) }}" enctype="multipart/form-data"
              style="margin-top:24px;border-top:1px solid var(--line);padding-top:20px">
          @csrf
          <label for="slip" class="eyebrow" style="display:inline-flex;margin-bottom:8px">{{ $slipUrl ? 'แนบสลิปใบใหม่' : 'โอนแล้ว? แนบสลิปให้ระบบตรวจทันที' }}</label>
          @error('slip')<div style="color:#c2382e;font-size:13px;margin-bottom:8px">{{ $message }}</div>@enderror
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input type="file" id="slip" name="slip" accept="image/*" required style="flex:1;min-width:0">
            <button type="submit" class="btn btn-primary">ส่งสลิป</button>
          </div>
          <div style="font-size:11px;color:var(--ink-faint);margin-top:6px">ระบบตรวจกับธนาคารอัตโนมัติ (สลิปใบเดียวใช้ได้ครั้งเดียว ทั้งเว็บและแม่หมอใน LINE/Facebook) — ตรวจไม่ได้จะส่งให้แอดมินตรวจ</div>
        </form>
      @endif
    </div>

    <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap">
      <a href="{{ route('wallet.index') }}" class="btn btn-ghost">← กลับวอลเลต</a>
      @if ($tx->status === 'failed')
        <a href="{{ route('wallet.topup') }}" class="btn btn-primary">เติมเงินใหม่</a>
      @endif
      @if (!empty($canCancel))
        <form method="POST" action="{{ route('wallet.topup.cancel', $tx) }}" style="display:inline"
              onsubmit="return confirm('ยืนยันยกเลิกรายการเติมเงินนี้?')">
          @csrf
          <button type="submit" class="btn btn-ghost" style="border-color:#c2382e;color:#c2382e">ยกเลิกรายการนี้</button>
        </form>
      @endif
    </div>
  </div>
</section>
@endsection
