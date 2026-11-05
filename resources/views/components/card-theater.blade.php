@props([
    // ข้อมูลจาก App\Support\CardTheater::forPick() / forReading()
    'config',
    // หน้าเลือกไพ่เท่านั้น — ฟอร์มเดิมของ tarot.cast (picked[] ใส่ด้วย JS ตอนกดเปิดไพ่)
    'askBirth'  => false,
    'birthDate' => null,
    'backUrl'   => null,
])

{{-- 🃏 (2026-11-05) โรงละครเปิดไพ่ — ฉากโต๊ะเต็มจอ + ไพ่ 3D + HUD ลายทอง (resources/js/card-theater)
     หน้าเลือกไพ่: เปิดทันที · หน้าผล: เปิดเองตอนกำลังอ่าน/เพิ่งอ่านเสร็จ (ครั้งเดียวต่อคำทำนาย) แล้วปุ่ม [data-ct-open] ดูซ้ำได้
     ไม่มี WebGL: หน้าเลือกไพ่ใช้สำรับแบบปุ่ม · หน้าผลไม่เปิดโรงละคร (ไพ่และคำทำนายอยู่บนหน้าอยู่แล้ว) --}}
@php
    $isPick = ($config['mode'] ?? '') === 'pick';
    $room = $config['room'];
    $question = trim((string) ($config['question'] ?? ''));
@endphp

<div class="ct" data-card-theater @unless($isPick) hidden @endunless
     role="dialog" aria-modal="true" aria-label="โรงละครเปิดไพ่ · {{ $room['title'] }}">
  <script type="application/json" data-ct-config>@json($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</script>

  <div class="ct-bg" aria-hidden="true"></div>
  <div class="ct-actor" aria-hidden="true"><img alt=""></div>
  <aside class="ct-tray ct-frame" aria-hidden="true"><h4 class="ct-gold-text">ไพ่ที่เลือก</h4><div class="ct-count ct-frame">0/0</div></aside>
  <canvas class="ct-gl" aria-label="เวทีเปิดไพ่"></canvas>
  <div class="ct-vignette" aria-hidden="true"></div>
  <div class="ct-fallback" hidden></div>
  <div class="ct-labels" aria-hidden="true"></div>
  <div class="ct-ritual" aria-live="polite"></div>

  <header class="ct-top">
    @if ($isPick)
      <a class="ct-icon ct-back" href="{{ $backUrl ?? route('tarot.index') }}" aria-label="กลับไปหน้ารวมไพ่">
    @else
      <button class="ct-icon ct-back" type="button" aria-label="ปิดโรงละคร">
    @endif
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          @if ($isPick)<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>@else<path d="M18 6 6 18"/><path d="m6 6 12 12"/>@endif
        </svg>
    @if ($isPick)</a>@else</button>@endif
    <div class="ct-room"><strong class="ct-gold-text">{{ $room['title'] }}</strong><i class="ct-orn" aria-hidden="true">✧</i></div>
    <div class="ct-tools">
      <div class="ct-deck ct-frame" aria-label="จำนวนไพ่">
        <svg viewBox="0 0 32 32" fill="none" stroke="#f5d89a" stroke-width="1.4" aria-hidden="true"><rect x="4" y="7" width="13" height="20" rx="2" transform="rotate(-14 10 17)" fill="rgba(80,40,140,.9)"/><rect x="9" y="5" width="13" height="20" rx="2" transform="rotate(-2 15 15)" fill="rgba(90,46,156,.95)"/><rect x="14" y="5" width="13" height="20" rx="2" transform="rotate(12 20 15)" fill="rgba(104,54,176,1)"/></svg>
        <span class="ct-deck-count">{{ $isPick ? '0/' . count($config['spread']['labels']) : count($config['cards'] ?? []) . ' ใบ' }}</span>
      </div>
    </div>
  </header>
  @if ($question !== '')
    <div class="ct-qcap ct-frame"><small>คำถาม:</small><span>{{ $question }}</span></div>
  @endif
  <nav class="ct-steps" aria-label="ขั้นตอน"></nav>
  <div class="ct-storybar" aria-hidden="true"></div>

  @if ($isPick)
    {{-- ยืนยันก่อนตัดเงิน — ค่าดูดวง · ยอดคงเหลือ · วันเกิด (ไม่บังคับ) --}}
    <section class="ct-sheet ct-frame" aria-live="polite">
      <h3 class="ct-gold-text">พร้อมเปิดไพ่ {{ $config['spread']['name'] }}</h3>
      @if ($config['cost'] > 0)
        <p @class(['warn' => ! $config['enough']])>
          ค่าดูดวง <b>{{ $config['costText'] }}</b>
          @if ($config['balance'] !== null)
            · คงเหลือ <b>{{ \App\Support\Pricing::format($config['balance']) }}</b>
            @unless ($config['enough']) — ยอดไม่พอ <a href="{{ $config['topupUrl'] }}">เติมเงิน</a>@endunless
          @endif
        </p>
      @else
        <p>ครั้งนี้ไม่มีค่าใช้จ่าย</p>
      @endif
      @if ($askBirth)
        <label for="ct-birth">วันเกิดของคุณ (ไม่บังคับ — ใส่แล้ว{{ $room['self'] }}ดูดวงดาวประกอบไพ่ให้)
          <input type="date" id="ct-birth" value="{{ old('birth_date', $birthDate ?? '') }}" max="{{ now()->subDay()->format('Y-m-d') }}" min="1900-01-02">
        </label>
      @endif
    </section>

    {{-- ฟอร์มเดิมของ tarot.cast — _idem กันกดซ้ำ (หักเงินสองครั้ง) ฝั่งเซิร์ฟเวอร์ --}}
    <form class="ct-form" action="{{ route('tarot.cast') }}" method="POST" hidden>
      @csrf
      <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::uuid() }}">
      <input type="hidden" name="spread" value="{{ $config['spread']['key'] }}">
      <input type="hidden" name="question" value="{{ $question }}">
      @if ($askBirth)<input type="hidden" name="birth_date" value="">@endif
    </form>
  @endif

  <footer class="ct-hud">
    <div class="ct-spreadpill ct-frame"></div>
    <div class="ct-sub hint"><div class="ct-who"><span class="ct-who-name"></span><span class="ct-who-meta"></span></div><p></p></div>
    <div class="ct-actions"></div>
  </footer>
  <div class="ct-note" role="status"></div>
</div>

@once
  @vite('resources/js/card-theater/index.js')
@endonce
