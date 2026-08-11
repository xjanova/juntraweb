@extends('layouts.app')
@section('title', 'ดาวน์โหลดแอพ จันทราพยากรณ์')
@section('description', 'โหลดแอพจันทราพยากรณ์ฟรีบน Google Play — เปิดไพ่ยิปซี 78 ใบ ดวงรายวัน เลขศาสตร์ ลายมือ แชทแม่หมอ AI พร้อมวอลเลตและผังสายงานในเครื่องเดียว')

{{-- og:* ย้ายไปประกาศรวมที่ layouts/app.blade.php แล้ว — ถ้ายัง push ซ้ำตรงนี้
     หน้านี้จะมี og:title/og:image อย่างละสองอันในหน้าเดียว --}}
@push('head')
<style>
/* ── หน้าดาวน์โหลดแอพ — สีอิง CSS vars ของธีม + fallback โทน juntra ── */
.dl-hero { display:grid; grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr); gap:48px; align-items:center; max-width:1080px; margin:0 auto 90px; }
.dl-stores { display:flex; gap:14px; flex-wrap:wrap; margin:34px 0 20px; }
.dl-store-btn {
  display:inline-flex; align-items:center; gap:13px; padding:13px 24px 13px 18px;
  border-radius:16px; text-decoration:none; border:1px solid var(--line, rgba(231,201,122,.35));
  background:linear-gradient(160deg, rgba(255,255,255,.07), rgba(255,255,255,.015));
  color:var(--ink, #f3e9ff); transition:transform .25s, border-color .25s, box-shadow .25s;
}
.dl-store-btn:hover { transform:translateY(-3px); border-color:var(--gold, #e7c97a); box-shadow:0 18px 50px -18px rgba(0,0,0,.65); }
.dl-store-btn small { display:block; font-size:11px; letter-spacing:.08em; color:var(--ink-dim, #c8b8de); }
.dl-store-btn b { display:block; font-size:19px; line-height:1.2; letter-spacing:.02em; }
.dl-store-btn.is-soon { opacity:.45; pointer-events:none; filter:saturate(.3); }
.dl-meta { display:flex; gap:10px; flex-wrap:wrap; }
.dl-chip {
  font-size:12.5px; padding:6px 14px; border-radius:999px; color:var(--ink-dim, #c8b8de);
  border:1px solid var(--line, rgba(231,201,122,.22)); background:rgba(255,255,255,.03);
}
.dl-chip b { color:var(--gold, #e7c97a); font-weight:600; }

/* โทรศัพท์จำลอง (CSS ล้วน — ไม่พึ่งรูป screenshot) */
.dl-visual { display:flex; justify-content:center; position:relative; }
.dl-visual::before { content:''; position:absolute; inset:10% 18%; border-radius:50%;
  background:radial-gradient(closest-side, var(--violet, #7a3df0), transparent 72%); opacity:.4; filter:blur(30px); }
.dl-phone {
  position:relative; width:min(270px, 72vw); aspect-ratio:9/19; border-radius:38px; padding:10px;
  background:linear-gradient(160deg, #2c2340, #0d0918); border:1px solid rgba(255,255,255,.14);
  box-shadow:0 40px 90px -35px rgba(0,0,0,.85), inset 0 0 0 1px rgba(0,0,0,.6);
}
.dl-phone::after { content:''; position:absolute; top:18px; left:50%; transform:translateX(-50%);
  width:84px; height:7px; border-radius:99px; background:rgba(0,0,0,.75); }
.dl-screen { height:100%; border-radius:29px; overflow:hidden; display:flex; flex-direction:column;
  background:linear-gradient(175deg, var(--bg-1, #160a26) 0%, var(--bg-0, #0a0612) 70%); }
.dl-ps-status { display:flex; justify-content:space-between; padding:14px 18px 4px; font-size:9.5px;
  color:var(--ink-dim, #c8b8de); letter-spacing:.1em; }
.dl-ps-brand { text-align:center; padding:16px 0 4px; }
.dl-ps-brand img { width:56px; height:56px; border-radius:16px; box-shadow:0 8px 26px -8px rgba(0,0,0,.7); }
.dl-ps-brand div { font-size:12.5px; margin-top:8px; color:var(--gold, #e7c97a); letter-spacing:.06em; }
.dl-ps-greet { margin:12px 14px 0; padding:10px 13px; border-radius:14px 14px 14px 4px; font-size:11px; line-height:1.6;
  color:var(--ink, #f3e9ff); background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.08); }
.dl-ps-cards { display:flex; justify-content:center; margin:22px 0 6px; height:96px; }
.dl-ps-card { width:58px; height:92px; border-radius:8px; margin:0 -14px;
  background:linear-gradient(160deg, var(--violet, #7a3df0), var(--bg-2, #241038) 80%);
  border:1px solid var(--gold, #e7c97a); box-shadow:0 10px 24px -8px rgba(0,0,0,.7);
  display:flex; align-items:center; justify-content:center; color:var(--gold, #e7c97a); font-size:19px; }
.dl-ps-card:nth-child(1) { transform:rotate(-14deg) translateY(9px); }
.dl-ps-card:nth-child(3) { transform:rotate(14deg) translateY(9px); }
.dl-ps-menu { display:flex; flex-wrap:wrap; gap:7px; justify-content:center; padding:14px 16px 0; }
.dl-ps-menu span { font-size:9.5px; padding:5px 11px; border-radius:999px; color:var(--ink-dim, #c8b8de);
  border:1px solid var(--line, rgba(231,201,122,.28)); }
.dl-ps-foot { margin-top:auto; display:flex; justify-content:center; gap:6px; padding:16px 0 18px; }
.dl-ps-foot i { width:5px; height:5px; border-radius:50%; background:var(--ink-dim, #c8b8de); opacity:.45; }
.dl-ps-foot i:first-child { background:var(--gold, #e7c97a); opacity:1; }

/* QR สำหรับคนเปิดจากคอม */
.dl-qr { display:flex; align-items:center; gap:26px; max-width:640px; margin:0 auto 90px; padding:24px 30px;
  border-radius:22px; border:1px solid var(--line, rgba(231,201,122,.25));
  background:linear-gradient(160deg, rgba(255,255,255,.05), rgba(255,255,255,.01)); }
.dl-qr img { width:132px; height:132px; border-radius:14px; background:#fff; padding:8px; }
.dl-qr h3 { margin:0 0 8px; font-size:19px; color:var(--ink, #f3e9ff); }
.dl-qr p { margin:0; font-size:14px; line-height:1.75; color:var(--ink-dim, #c8b8de); }

/* จุดเด่น 6 ข้อ */
.dl-features { display:grid; grid-template-columns:repeat(3, 1fr); gap:16px; max-width:1080px; margin:0 auto 90px; }
.dl-feature { padding:26px 24px; border-radius:20px; border:1px solid var(--line, rgba(231,201,122,.18));
  background:linear-gradient(170deg, rgba(255,255,255,.045), rgba(255,255,255,.01)); transition:transform .25s, border-color .25s; }
.dl-feature:hover { transform:translateY(-5px); border-color:var(--gold, #e7c97a); }
.dl-feature .ico { font-size:26px; display:block; margin-bottom:14px; }
.dl-feature h3 { margin:0 0 8px; font-size:16.5px; color:var(--ink, #f3e9ff); }
.dl-feature p { margin:0; font-size:13.5px; line-height:1.75; color:var(--ink-dim, #c8b8de); }

/* 3 ขั้นเริ่มใช้งาน */
.dl-steps { display:grid; grid-template-columns:repeat(3, 1fr); gap:16px; max-width:1080px; margin:0 auto 80px; counter-reset:dlstep; }
.dl-step { position:relative; padding:30px 24px 26px; border-radius:20px; text-align:center;
  border:1px dashed var(--line, rgba(231,201,122,.3)); }
.dl-step::before { counter-increment:dlstep; content:counter(dlstep);
  display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; margin-bottom:14px;
  border-radius:50%; font-size:17px; font-weight:700; color:var(--bg-0, #0a0612);
  background:linear-gradient(160deg, var(--gold, #e7c97a), var(--gold-3, #a8854a)); }
.dl-step h3 { margin:0 0 8px; font-size:16px; color:var(--ink, #f3e9ff); }
.dl-step p { margin:0; font-size:13.5px; line-height:1.7; color:var(--ink-dim, #c8b8de); }

.dl-cta { text-align:center; max-width:640px; margin:0 auto; }

@media (max-width: 860px) {
  .dl-hero { grid-template-columns:1fr; gap:40px; text-align:center; }
  .dl-stores, .dl-meta { justify-content:center; }
  .dl-features, .dl-steps { grid-template-columns:1fr; max-width:520px; }
  .dl-qr { display:none; } /* จอมือถือกดปุ่มตรงได้เลย ไม่ต้องสแกน */
}
</style>
@endpush

@section('content')
<section class="canvas" style="padding-top:160px">

  {{-- ── Hero: ข้อความ + ปุ่มสโตร์ / โทรศัพท์จำลอง ── --}}
  <div class="dl-hero">
    <div>
      <div class="eyebrow" style="display:inline-flex">☾ Mobile Application</div>
      <h1 class="display" style="font-size:clamp(42px,5.5vw,72px);margin:18px 0 22px">
        พกหมอดูส่วนตัว<br>ไว้ใน<em>มือถือ</em>ของคุณ
      </h1>
      <p class="lede" style="margin:0">
        แอพจันทราพยากรณ์ — เปิดไพ่ยิปซี ดูดวงรายวัน เลขศาสตร์ ลายมือ
        และแชทกับแม่หมอ AI ได้ทุกที่ทุกเวลา ใช้บัญชีเดียวกับเว็บ
        เครดิตและประวัติคำทำนายซิงก์กันอัตโนมัติ
      </p>

      <div class="dl-stores">
        <a href="{{ route('download.go') }}" class="dl-store-btn" rel="noopener">
          <svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true">
            <path fill="#00D7FE" d="M3.06 2.05A2 2 0 0 0 2 3.84v16.32a2 2 0 0 0 1.06 1.79L13.5 12 3.06 2.05z"/>
            <path fill="#00F076" d="M16.8 8.7 5.9 2.6a2 2 0 0 0-2.84-.55L13.5 12l3.3-3.3z"/>
            <path fill="#FFC900" d="M16.8 15.3 13.5 12 3.06 21.95a2 2 0 0 0 2.84-.55l10.9-6.1z"/>
            <path fill="#F43249" d="m20.9 10.9-4.1-2.2-3.3 3.3 3.3 3.3 4.1-2.2c1.47-.82 1.47-1.38 0-2.2z"/>
          </svg>
          <span><small>ดาวน์โหลดฟรีที่</small><b>Google Play</b></span>
        </a>

        @if ($appStoreUrl)
          <a href="{{ $appStoreUrl }}" class="dl-store-btn" rel="noopener">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true">
              <path d="M17.05 12.54c-.03-2.89 2.36-4.27 2.47-4.34-1.35-1.97-3.44-2.24-4.18-2.27-1.78-.18-3.47 1.05-4.37 1.05-.9 0-2.29-1.02-3.77-1-1.94.03-3.72 1.13-4.72 2.86-2.01 3.49-.51 8.66 1.45 11.5.96 1.39 2.1 2.95 3.6 2.89 1.45-.06 1.99-.93 3.74-.93s2.24.93 3.77.9c1.56-.03 2.54-1.41 3.49-2.81 1.1-1.61 1.55-3.17 1.58-3.25-.04-.02-3.03-1.16-3.06-4.6zM14.16 4.06c.8-.97 1.34-2.32 1.19-3.66-1.15.05-2.55.77-3.38 1.74-.74.85-1.39 2.23-1.22 3.54 1.29.1 2.6-.65 3.41-1.62z"/>
            </svg>
            <span><small>ดาวน์โหลดที่</small><b>App Store</b></span>
          </a>
        @else
          <div class="dl-store-btn is-soon" aria-disabled="true">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true">
              <path d="M17.05 12.54c-.03-2.89 2.36-4.27 2.47-4.34-1.35-1.97-3.44-2.24-4.18-2.27-1.78-.18-3.47 1.05-4.37 1.05-.9 0-2.29-1.02-3.77-1-1.94.03-3.72 1.13-4.72 2.86-2.01 3.49-.51 8.66 1.45 11.5.96 1.39 2.1 2.95 3.6 2.89 1.45-.06 1.99-.93 3.74-.93s2.24.93 3.77.9c1.56-.03 2.54-1.41 3.49-2.81 1.1-1.61 1.55-3.17 1.58-3.25-.04-.02-3.03-1.16-3.06-4.6zM14.16 4.06c.8-.97 1.34-2.32 1.19-3.66-1.15.05-2.55.77-3.38 1.74-.74.85-1.39 2.23-1.22 3.54 1.29.1 2.6-.65 3.41-1.62z"/>
            </svg>
            <span><small>App Store</small><b>เร็ว ๆ นี้</b></span>
          </div>
        @endif
      </div>

      <div class="dl-meta">
        @if ($appVersion)
          <span class="dl-chip">เวอร์ชันล่าสุด <b>{{ $appVersion }}</b></span>
        @endif
        <span class="dl-chip">Android <b>8.0+</b></span>
        <span class="dl-chip">โหลดฟรี <b>ไม่มีค่าแอบแฝง</b></span>
        <span class="dl-chip">บัญชีเดียวกับเว็บ <b>ซิงก์อัตโนมัติ</b></span>
      </div>
    </div>

    <div class="dl-visual" aria-hidden="true">
      <div class="dl-phone">
        <div class="dl-screen">
          <div class="dl-ps-status"><span>จันทรา</span><span>☾ ✦ ⚡</span></div>
          <div class="dl-ps-brand">
            <img src="{{ asset('images/juntra/logo.png') }}" alt="">
            <div>จันทราพยากรณ์</div>
          </div>
          <div class="dl-ps-greet">สวัสดีค่ะ ✨ วันนี้อยากให้แม่หมอดูดวงเรื่องอะไรดีคะ — ความรัก การงาน หรือการเงิน?</div>
          <div class="dl-ps-cards">
            <div class="dl-ps-card">✦</div>
            <div class="dl-ps-card">☾</div>
            <div class="dl-ps-card">☉</div>
          </div>
          <div class="dl-ps-menu">
            <span>ไพ่ยิปซี</span><span>ดวงรายวัน</span><span>แชทแม่หมอ</span><span>วอลเลต</span>
          </div>
          <div class="dl-ps-foot"><i></i><i></i><i></i></div>
        </div>
      </div>
    </div>
  </div>

  {{-- ── QR สำหรับ desktop ── --}}
  @if ($qrDataUri)
    <div class="dl-qr">
      <img src="{{ $qrDataUri }}" alt="QR ดาวน์โหลดแอพจันทราพยากรณ์">
      <div>
        <h3>เปิดจากคอมอยู่ใช่ไหม?</h3>
        <p>สแกน QR นี้ด้วยกล้องมือถือ ระบบจะพาไปหน้าดาวน์โหลดแอพบน Google Play ทันที ไม่ต้องพิมพ์ค้นหาเอง</p>
      </div>
    </div>
  @endif

  {{-- ── ทำไมต้องมีแอพ ── --}}
  <div class="section-head" style="text-align:center;margin-bottom:44px">
    <div class="eyebrow" style="display:inline-flex">ครบทุกศาสตร์ในแอพเดียว</div>
    <h2 class="display" style="font-size:clamp(32px,4vw,52px);margin:14px 0 0">มีอะไรใน<em>แอพ</em>บ้าง</h2>
  </div>
  <div class="dl-features">
    <div class="dl-feature">
      <span class="ico">🔮</span>
      <h3>ไพ่ยิปซี 78 ใบ</h3>
      <p>เปิดไพ่ได้ทุกที่ — ไพ่ 3 ใบ, Celtic Cross และอีกหลายสเปรด พร้อมคำทำนาย AI ละเอียดทุกตำแหน่งไพ่</p>
    </div>
    <div class="dl-feature">
      <span class="ico">💬</span>
      <h3>แชทแม่หมอ AI</h3>
      <p>คุยต่อจากคำทำนายได้ทันที ถามลึกเรื่องความรัก การงาน การเงิน แม่หมอจำบริบทดวงของคุณได้</p>
    </div>
    <div class="dl-feature">
      <span class="ico">☾</span>
      <h3>ดวงรายวัน 12 ราศี</h3>
      <p>เช็คดวงก่อนเริ่มวัน ครบทั้งความรัก การเงิน การงาน พร้อมเลขนำโชคและสีมงคลประจำวัน</p>
    </div>
    <div class="dl-feature">
      <span class="ico">✋</span>
      <h3>เลขศาสตร์ &amp; ลายมือ</h3>
      <p>คำนวณเลขชะตาจากชื่อ-วันเกิด และถ่ายรูปฝ่ามือให้ AI Vision วิเคราะห์เส้นลายมือแบบละเอียด</p>
    </div>
    <div class="dl-feature">
      <span class="ico">💰</span>
      <h3>วอลเลตในตัว</h3>
      <p>เติมเครดิตผ่าน PromptPay สแกนจ่ายแล้วยืนยันอัตโนมัติ เครดิตใช้ได้ทั้งบนแอพและบนเว็บ</p>
    </div>
    <div class="dl-feature">
      <span class="ico">🌙</span>
      <h3>ผังสายงาน &amp; รายได้</h3>
      <p>ชวนเพื่อนด้วยลิงก์ส่วนตัว รับค่าแนะนำ ดูผังสายงานและคอมมิชชันแบบเรียลไทม์ในแอพ</p>
    </div>
  </div>

  {{-- ── เริ่มใช้ 3 ขั้น ── --}}
  <div class="section-head" style="text-align:center;margin-bottom:44px">
    <h2 class="display" style="font-size:clamp(28px,3.5vw,44px);margin:0">เริ่มใช้งานใน <em>3 ขั้นตอน</em></h2>
  </div>
  <div class="dl-steps">
    <div class="dl-step">
      <h3>ดาวน์โหลดแอพ</h3>
      <p>กดปุ่ม Google Play ด้านบน หรือสแกน QR ติดตั้งฟรีในไม่กี่วินาที</p>
    </div>
    <div class="dl-step">
      <h3>เข้าสู่ระบบ</h3>
      <p>ใช้บัญชีเดียวกับเว็บได้เลย หรือสมัครใหม่ฟรีในแอพ ไม่กี่ขั้นตอน</p>
    </div>
    <div class="dl-step">
      <h3>ดูดวงได้ทันที</h3>
      <p>เครดิต ประวัติคำทำนาย และบทสนทนา ซิงก์กันทุกอุปกรณ์อัตโนมัติ</p>
    </div>
  </div>

  {{-- ── CTA ปิดท้าย ── --}}
  <div class="dl-cta">
    <p class="lede" style="margin:0 0 26px">ดวงดาวไม่เคยหยุดหมุน — พกจันทราพยากรณ์ไว้ใกล้ตัว แล้วให้จักรวาลนำทางคุณทุกวัน</p>
    <a href="{{ route('download.go') }}" class="btn btn-primary" rel="noopener">⬇ ดาวน์โหลดบน Google Play</a>
    <a href="{{ route('tarot.index') }}" class="btn btn-ghost" style="margin-left:10px">หรือเปิดไพ่บนเว็บ →</a>
  </div>

</section>
@endsection
