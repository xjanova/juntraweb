@extends('layouts.app')
@section('page-handles-flash', '1')
@section('title', 'แจ้งเติมเงิน')

@section('content')
<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'topups'])

    <div class="account-content">
      <div class="eyebrow">WALLET • TOP-UPS</div>
      <h1 style="font-family:var(--serif);font-size:clamp(32px,4vw,52px);font-weight:400;margin:8px 0 12px">
        แจ้ง <em style="color:var(--gold)">เติมเงิน</em>
      </h1>
      <p class="lede" style="margin:0 0 24px;max-width:640px">
        สถานะการแจ้งเติมเงินผ่าน PromptPay ทั้งหมด
        @if ($pendingCount > 0)
          — <strong style="color:var(--gold)">{{ $pendingCount }}</strong> รายการกำลังรอแอดมินตรวจสลิป
        @endif
      </p>

      @if (session('status'))
        <div class="flash" style="padding:14px 18px;margin-bottom:24px">{{ session('status') }}</div>
      @endif

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px">
        <a href="{{ route('wallet.topup') }}" class="btn btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 5v14M5 12h14"/></svg>
          เติมเงินใหม่
        </a>
        <a href="{{ route('wallet.index') }}" class="btn btn-ghost">ดูประวัติเครดิตทั้งหมด</a>
      </div>

      <div class="panel" style="padding:0;overflow:hidden">
        @if ($topups->count() === 0)
          <div style="padding:48px 24px;text-align:center;color:var(--ink-dim)">
            <p style="margin:0 0 16px">ยังไม่มีรายการเติมเงิน</p>
            <a href="{{ route('wallet.topup') }}" class="btn btn-primary">เติมเงินครั้งแรก</a>
          </div>
        @else
          {{-- ตารางกว้างกว่าจอมือถือ ถูก overflow:hidden ของ .panel ตัดทิ้ง
               คอลัมน์ขวาสุด (ปุ่ม "รายละเอียด →") จึงหายไปเลย ไม่ใช่แค่ล้น
               ครอบด้วยกล่องที่เลื่อนแนวนอนได้ เพื่อให้เข้าถึงทุกคอลัมน์ --}}
          <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
          <table style="width:100%;min-width:560px;border-collapse:collapse">
            <thead>
              <tr style="background:rgba(0,0,0,.02);border-bottom:1px solid var(--line-soft)">
                <th style="padding:14px;text-align:left;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">วันที่</th>
                <th style="padding:14px;text-align:left;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">รหัส</th>
                <th style="padding:14px;text-align:right;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">จำนวน</th>
                <th style="padding:14px;text-align:center;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500">สถานะ</th>
                <th style="padding:14px;text-align:right;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--ink-dim);font-weight:500"></th>
              </tr>
            </thead>
            <tbody>
              @foreach ($topups as $t)
                <tr style="border-bottom:1px solid var(--line-soft)">
                  <td style="padding:14px;font-size:13px;color:var(--ink-dim);white-space:nowrap">
                    {{ $t->created_at->format('d/m/Y H:i') }}
                  </td>
                  <td style="padding:14px;font-size:13px;font-family:var(--display);letter-spacing:.06em">
                    {{ $t->reference_code ?? '—' }}
                  </td>
                  <td style="padding:14px;text-align:right;font-family:var(--display);white-space:nowrap;color:var(--gold)">
                    +฿{{ number_format(abs($t->amount), 2) }}
                  </td>
                  <td style="padding:14px;text-align:center;white-space:nowrap">
                    @php
                      $color = match($t->status) {
                          'success'  => 'var(--gold)',
                          'pending'  => '#d4a017',
                          'failed'   => '#c2382e',
                          'refunded' => '#888',
                          default    => 'var(--ink-dim)',
                      };
                    @endphp
                    <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-family:var(--display);font-size:10px;letter-spacing:.18em;text-transform:uppercase;border:1px solid {{ $color }};color:{{ $color }}">
                      {{ $t->statusLabel() }}
                    </span>
                  </td>
                  <td style="padding:14px;text-align:right;white-space:nowrap">
                    <a href="{{ route('wallet.topup.show', $t) }}" style="font-size:12px;color:var(--gold);text-decoration:none">รายละเอียด →</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        @endif
      </div>

      <div style="margin-top:24px">{{ $topups->links() }}</div>
    </div>
  </div>
</section>
@endsection
