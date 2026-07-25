@extends('layouts.app')
@section('page-handles-flash', '1')
@section('title', 'สถานะการเติมเงิน')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:680px;margin:0 auto">
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
        <dd style="margin:0">{{ $tx->method ?: '—' }}</dd>

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
        <div style="margin-top:24px;text-align:center;border-top:1px solid var(--line);padding-top:24px">
          <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">สแกนเพื่อจ่าย ฿{{ number_format($tx->amount, 2) }}</div>
          <div><img src="{{ $promptpayQr }}" alt="PromptPay QR" style="width:220px;height:220px;background:#fff;border-radius:14px;padding:10px"></div>
          @if (!empty($promptpayName))<div style="color:var(--ink-dim);font-size:13px;margin-top:8px">{{ $promptpayName }}</div>@endif
          <div style="font-size:12px;color:var(--ink-faint);margin-top:6px">
            @if (config('smschecker.enabled'))
              โอนยอด <b style="color:var(--gold)">฿{{ number_format($tx->amount, 2) }}</b> ให้ตรงเป๊ะ —
              ระบบจะเครดิตเข้าวอลเลตอัตโนมัติเมื่อเงินเข้า (หรืออัปโหลดสลิปให้แอดมินตรวจก็ได้)
            @else
              โอนแล้วอัปโหลดสลิป — แอดมินจะอนุมัติเครดิตให้
            @endif
          </div>
        </div>
      @endif

      @if ($slipUrl)
        <div style="margin-top:24px">
          <div class="eyebrow" style="display:inline-flex;margin-bottom:8px">สลิปที่อัปโหลด</div>
          <a href="{{ $slipUrl }}" target="_blank" rel="noopener">
            <img src="{{ $slipUrl }}" alt="สลิปการโอน" style="max-width:100%;border-radius:14px;border:1px solid var(--line)" loading="lazy">
          </a>
          <div style="font-size:11px;color:var(--ink-faint);margin-top:6px">สลิปนี้เก็บแบบส่วนตัว — เฉพาะคุณกับแอดมินเปิดดูได้</div>
        </div>
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
