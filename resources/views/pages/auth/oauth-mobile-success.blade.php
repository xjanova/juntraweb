@extends('layouts.app')

{{-- Shown after a Juntra mobile app user completes the Thaiprompt OAuth.
     The web session HAS been established (so refresh-this-page won't
     re-trigger OAuth) but mobile-bootstrap sessions are short — they
     exist only to bind the right user to the OAuth callback. The user's
     real session is on their phone; tell them to return there. --}}

@section('title', 'เชื่อมต่อสำเร็จ')

@section('content')
<section class="canvas" style="padding-top:140px;padding-bottom:120px">
  <div style="max-width:560px;margin:0 auto;padding:0 24px;text-align:center">

    <div style="width:96px;height:96px;margin:0 auto 24px;border-radius:50%;
                background:radial-gradient(circle,
                    rgba(240,199,94,.5) 0%, rgba(240,199,94,0) 70%);
                display:flex;align-items:center;justify-content:center">
      <div style="font-size:48px">✓</div>
    </div>

    <h1 class="display" style="font-size:clamp(32px,4.5vw,52px);margin:0 0 12px;color:var(--gold)">
      เชื่อมต่อสำเร็จ
    </h1>

    <p class="lede" style="font-size:16px;color:var(--ink);margin:0 0 20px;line-height:1.7">
      ลูก <em style="font-style:normal;color:var(--gold)">{{ $user->name ?? 'ลูกศิษย์' }}</em>
      เชื่อมต่อบัญชี Thaiprompt กับ จันทราพยากรณ์ เรียบร้อยแล้ว — กลับสู่แอพเพื่อดูข้อมูลสายงานและเปิดใช้งานคุณสมบัติพรีเมียมได้เลย
    </p>

    <div class="panel" style="padding:20px;margin:24px 0;text-align:left">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">ขั้นตอนต่อไป</div>
      <ol style="margin:0;padding-left:22px;color:var(--ink-dim);font-size:14px;line-height:1.8">
        <li>กลับไปที่แอพ จันทราพยากรณ์ บนมือถือของลูก</li>
        <li>เปิดเมนูสายงาน Affiliate — ลูกจะเห็นข้อมูลทีมและคอมมิชชั่นทันที</li>
        <li>หากยังเห็นหน้าเดิมอยู่ ให้กดปุ่มรีเฟรช (🔄) ที่มุมขวาบน</li>
      </ol>
    </div>

    <button onclick="window.close()" class="btn btn-primary"
            style="padding:14px 28px;font-size:14px;letter-spacing:.05em;
                   font-weight:700;border:none;cursor:pointer;
                   background:var(--gold);color:#1A0F2E;border-radius:12px">
      ปิดหน้านี้
    </button>

    <div style="margin-top:14px;font-size:12px;color:var(--ink-faint);letter-spacing:.04em">
      (บางเบราว์เซอร์อาจไม่อนุญาตให้ปิดอัตโนมัติ — สามารถปิดด้วยตนเองได้)
    </div>

  </div>
</section>
@endsection
