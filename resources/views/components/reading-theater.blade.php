@props([
    'reading',
    // ผลจาก ReadingSections::parse() ที่หน้าผลแยกไว้แล้ว (ไม่ส่ง = แยกเอง)
    'parsed'  => null,
    // ปุ่ม "คุยต่อ" ในบทสุดท้าย — เฉพาะตอนที่แชทจะรับลูกค้าคนนี้จริง (ChatPolicy::gate)
    'canChat' => false,
])

{{-- 🎭 (2026-11-04) โรงละครคำทำนาย — หน้าผลแบบเต็มจอ เล่าทีละบท: พื้นดวง → กราฟชีวิต → ไพ่ทีละใบ → สรุป → คุยต่อ
     ข้อมูลทั้งหมดมาจาก App\Support\ReadingTheater::build (ไพ่/ตำแหน่ง/สีกราฟจากฐานข้อมูลและป้ายในคำทำนาย — ไม่ผ่าน AI)

     - ปุ่มเปิดอยู่บนหน้าผล (เจ้าของคำทำนายเท่านั้น) · จากหน้าประวัติเปิดด้วย ?theater=1 (ต้องแตะเริ่มอีกครั้ง — เบราว์เซอร์ไม่ให้เล่นเสียงเอง)
     - มีเสียง (payload.voice ตามสัญญา 23.2) = ทุกอย่างเดินตาม audio.currentTime · ไม่มีเสียง = เดินบทเองตามความยาวข้อความ แตะ/ปัด/ลูกศรเปลี่ยนบท
     - three.js โหลดเฉพาะตอนเปิด (resources/js/theater/gl) · ลดการเคลื่อนไหว/ไม่มี WebGL/เครื่องอ่อน = แบบ CSS อัตโนมัติ
     - ปุ่มย้อนของมือถือ/เบราว์เซอร์ = ปิดโรงละคร (pushState) · จำบทที่ค้าง ถามฟังต่อตอนเปิดใหม่

     🪝 จุดต่อของ session เสียง (แผงแชทในโรงละคร):
       window 'reading-theater:outro'  {readingId}         ถึงบทคุยต่อ → เลื่อนแผงแชทขึ้นมาได้
       window 'reading-theater:chat'   {readingId} (cancelable) ลูกค้ากด "คุยต่อ" — preventDefault() = เปิดแผงเอง ไม่ต้องพาออกไปหน้าแชท
       window 'reading-theater:mic'    {on: bool}          ส่งเข้ามาตอนกดไมค์ → เพลงหยุดชั่วคราว (กันเพลงเข้าไมค์)
       แผงแชทใส่ใน slot ของคอมโพเนนต์นี้ได้ (อยู่ในโรงละคร เหนือเวที) --}}
@php
    $theater = \App\Support\ReadingTheater::build($reading, $parsed);
    $room = \App\Support\ChatRooms::get($theater['persona']) ?? [];
    $poster = asset($room['image'] ?? 'images/juntra/rooms/maemor.webp');
    $hasVoice = $theater['voice'] !== null;
@endphp

<div class="rt-launch-wrap" data-persona="{{ $theater['persona'] }}"
     x-data="readingTheater(@js($theater), @js(['autoOpen' => request()->boolean('theater'), 'canChat' => (bool) $canChat]))">
  <button type="button" class="rt-launch" @click="launch()" aria-haspopup="dialog">
    <span class="rt-launch-icon" aria-hidden="true">▶</span>
    <span class="rt-launch-text">
      <strong>{{ $hasVoice ? 'ฟัง'.$theater['oracle'].'เล่าคำทำนาย' : 'ฟังคำทำนาย' }}</strong>
      <small>ดูแบบโรงละคร · เปิดไพ่ทีละใบ</small>
    </span>
  </button>

  <template x-teleport="body">
    <div class="rt" x-show="open" x-cloak data-persona="{{ $theater['persona'] }}"
         role="dialog" aria-modal="true" aria-label="โรงละครคำทำนาย"
         :class="{ 'is-css': stage === 'css', 'is-playing': playing, 'is-outro': kind === 'outro' }"
         @keydown="key($event)" tabindex="-1" x-ref="dialog">

      {{-- เวที (canvas WebGL หรือแบบ CSS) — แตะไพ่/จุดกราฟ = ไปบทนั้น · ปัดซ้าย/ขวา = เปลี่ยนบท --}}
      <div class="rt-stage" x-ref="stage" @pointerdown="pd($event)" @pointerup="pu($event)"></div>

      <header class="rt-top" x-ref="top">
        <div class="rt-bar">
          <button type="button" class="rt-icon" @click="close()" aria-label="ปิดโรงละคร กลับหน้าคำทำนาย">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
          </button>
          <div class="rt-head">
            <div class="rt-eyebrow">โรงละครคำทำนาย · {{ $theater['oracle'] }}</div>
            <div class="rt-chapter-name" x-text="title"></div>
          </div>
          <button type="button" class="rt-icon" x-show="hasMusic" @click="toggleMusic()"
                  :aria-pressed="musicOn ? 'true' : 'false'" :aria-label="musicOn ? 'ปิดเพลงบรรเลง' : 'เปิดเพลงบรรเลง'"
                  :class="{ 'is-off': !musicOn }">🎵</button>
        </div>
        {{-- สารบัญบท — แตะไปบทไหนก็ได้ --}}
        <nav class="rt-index" aria-label="สารบัญบท" x-ref="index">
          <template x-for="(c, i) in chapters" :key="i">
            <button type="button" class="rt-chip" :class="{ 'is-now': i === index, 'is-past': i < index, ['rt-tone-' + (c.tone || 'none')]: true }"
                    @click="go(i)" :aria-current="i === index ? 'step' : null" x-text="c.short"></button>
          </template>
        </nav>
      </header>

      {{-- ผู้พยากรณ์ของห้อง (คลิป idle เดิม) — แสงวงรอบตัวตอนกำลังเล่า --}}
      <div class="rt-oracle" :class="{ 'is-speaking': playing && audioMode }" aria-hidden="true">
        <x-oracle-stage :persona="$theater['persona']" :poster="$poster" :alt="$room['name'] ?? ''" />
      </div>

      <section class="rt-panel" x-ref="panel" aria-live="polite">
        <div class="rt-kind" x-text="kindLabel"></div>
        <div class="rt-sub" x-ref="sub">
          <template x-for="(p, k) in phrases" :key="k">
            <span class="rt-phrase" :class="{ 'is-now': k === phrase, 'is-done': k < phrase }" x-text="p"></span>
          </template>
        </div>
        <div class="rt-outro" x-show="kind === 'outro'">
          @if ($canChat)
            <button type="button" class="btn btn-primary" @click="chat()">💬 คุยต่อกับ{{ $theater['oracle'] }}</button>
          @endif
          <button type="button" class="btn btn-ghost" @click="go(0)">↺ ดูอีกครั้ง</button>
        </div>
        @if (! $hasVoice)
          <div class="rt-hint" x-show="kind !== 'outro'">แตะ ⏭ หรือปัดเพื่อไปบทถัดไป · แตะไพ่เพื่อกระโดดไปใบนั้น</div>
        @endif
      </section>

      <footer class="rt-controls" x-ref="controls">
        {{-- แถบเวลาแบ่งช่วงตามบท — แตะ = เลื่อนไปจุดนั้น --}}
        <div class="rt-track" @click="seek($event)" role="slider" aria-label="ตำแหน่งการเล่า"
             :aria-valuenow="Math.round(time)" aria-valuemin="0" :aria-valuemax="Math.round(duration)">
          <template x-for="(s, i) in segments" :key="i">
            <span class="rt-seg" :style="'flex-grow:' + s"></span>
          </template>
          <span class="rt-fill" x-ref="fill"></span>
        </div>
        <div class="rt-buttons">
          <span class="rt-time" x-text="timeText"></span>
          <button type="button" class="rt-icon" @click="prev()" aria-label="บทก่อนหน้า">⏮</button>
          <button type="button" class="rt-icon rt-play" @click="toggle()" :aria-label="playing ? 'หยุดชั่วคราว' : 'เล่น'">
            <span x-text="playing ? '⏸' : '▶'"></span>
          </button>
          <button type="button" class="rt-icon" @click="next()" aria-label="บทถัดไป">⏭</button>
          <button type="button" class="rt-rate" @click="cycleRate()" :aria-label="'ความเร็ว ' + rate + ' เท่า'" x-text="rate + 'x'"></button>
        </div>
      </footer>

      {{ $slot }}

      {{-- ม่านเริ่ม: เปิดจากลิงก์ (ต้องแตะก่อนจึงเล่นเสียงได้) / มีบทที่ฟังค้างไว้ --}}
      <div class="rt-curtain" x-show="curtain" x-transition.opacity>
        <div class="rt-curtain-box">
          <div class="rt-eyebrow">โรงละครคำทำนาย</div>
          <template x-if="curtain === 'resume'">
            <div>
              <p class="rt-curtain-text">ฟังต่อจากบทที่ค้างไว้ไหม? <strong x-text="resumeName"></strong></p>
              <div class="rt-curtain-actions">
                <button type="button" class="btn btn-primary" @click="begin(resumeIndex)">▶ ฟังต่อ</button>
                <button type="button" class="btn btn-ghost" @click="begin(0)">เริ่มใหม่ตั้งแต่ต้น</button>
              </div>
            </div>
          </template>
          <template x-if="curtain === 'tap'">
            <div>
              <p class="rt-curtain-text">{{ $theater['oracle'] }}พร้อมเล่าคำทำนายของ{{ $theater['customer'] }}แล้ว</p>
              <div class="rt-curtain-actions">
                <button type="button" class="btn btn-primary" @click="begin(0)">▶ เริ่มการแสดง</button>
              </div>
            </div>
          </template>
        </div>
      </div>

      <div class="rt-loading" x-show="loading" x-transition.opacity aria-hidden="true">
        <div class="rt-loading-moon"></div>
        <div>กำลังจัดเวที…</div>
      </div>

      @if ($canChat)
        <form x-ref="chatForm" action="{{ route('chat.from-reading', $reading) }}" method="POST" hidden>@csrf</form>
      @endif
    </div>
  </template>
</div>

@once
@vite('resources/js/theater/index.js')

@push('head')
<style>
  /* ── ปุ่มเปิดบนหน้าผล ─────────────────────────────────────────── */
  .rt-launch-wrap { display: flex; justify-content: center; margin: 28px 0 8px; }
  .rt-launch {
    display: inline-flex; align-items: center; gap: 14px; max-width: 100%;
    padding: 14px 26px 14px 16px; border-radius: 999px; cursor: pointer;
    border: 1px solid color-mix(in srgb, var(--room-1, #b07aff) 60%, transparent);
    background: radial-gradient(120% 140% at 0% 0%, color-mix(in srgb, var(--room-1, #b07aff) 35%, transparent), transparent 70%),
                linear-gradient(135deg, rgba(20, 10, 48, .92), rgba(7, 4, 26, .92));
    color: var(--ink, #f6efe0); font-family: var(--thai, inherit); text-align: left;
    box-shadow: 0 12px 40px -18px var(--room-1, #b07aff), inset 0 1px 0 rgba(255, 255, 255, .08);
    transition: transform .25s, box-shadow .25s;
  }
  .rt-launch:hover { transform: translateY(-2px); box-shadow: 0 18px 48px -16px var(--room-1, #b07aff), inset 0 1px 0 rgba(255, 255, 255, .1); }
  .rt-launch:focus-visible { outline: 2px solid var(--room-2, #f4cf6a); outline-offset: 3px; }
  .rt-launch-icon {
    flex: 0 0 auto; width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; font-size: 18px;
    background: var(--room-2, #f4cf6a); color: #1a0d3d; box-shadow: 0 0 24px -4px var(--room-2, #f4cf6a);
  }
  .rt-launch-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
  .rt-launch-text strong { font-size: 17px; font-weight: 600; }
  .rt-launch-text small { font-size: 12.5px; color: var(--ink-dim, rgba(246, 239, 224, .62)); }

  /* ── โรงละคร (เต็มจอ) ─────────────────────────────────────────── */
  .rt { position: fixed; inset: 0; z-index: 90; overflow: hidden; color: var(--ink, #f6efe0); font-family: var(--thai, inherit);
        background: #07041a; outline: none; overscroll-behavior: contain; }
  .rt[data-persona="busaba"] { background: #0a1430; }
  .rt[data-persona="kunsai"] { background: #14040a; }
  .rt-stage { position: absolute; inset: 0; touch-action: none; }
  .rt-canvas { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
  .rt-labels { position: absolute; inset: 0; pointer-events: none; }
  .rt-label { position: absolute; left: 0; top: 0; white-space: nowrap; transition: opacity .3s; will-change: transform; }
  .rt-label-card { padding: 4px 12px; border-radius: 999px; font-size: 13px; background: rgba(7, 4, 26, .78);
                   border: 1px solid color-mix(in srgb, var(--room-2, #f4cf6a) 50%, transparent); color: var(--room-2, #f4cf6a); }
  .rt-label-point { font-size: 11px; color: rgba(246, 239, 224, .8); margin-top: 22px; text-shadow: 0 1px 4px #000; }
  .rt-label-zodiac { font-size: 16px; color: var(--room-2, #f4cf6a); text-shadow: 0 0 12px rgba(0, 0, 0, .9); letter-spacing: .02em; }
  .rt-label-verdict { display: flex; flex-direction: column; align-items: center; gap: 2px; max-width: 46vw; white-space: normal; text-align: center;
                      font-size: clamp(15px, 3.6vw, 20px); font-weight: 600; text-shadow: 0 2px 10px #000; }
  .rt-label-verdict small { font-size: 11px; font-weight: 400; color: var(--ink-dim, rgba(246, 239, 224, .7)); }

  .rt-top { position: absolute; left: 0; right: 0; top: 0; padding: max(10px, env(safe-area-inset-top)) 12px 8px;
            background: linear-gradient(180deg, rgba(5, 3, 18, .92) 0%, rgba(5, 3, 18, .55) 70%, transparent); }
  .rt-bar { display: flex; align-items: center; gap: 10px; }
  .rt-head { flex: 1; min-width: 0; text-align: center; }
  .rt-eyebrow { font-size: 11px; letter-spacing: .08em; color: var(--room-2, #f4cf6a); opacity: .9; }
  .rt-chapter-name { font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .rt-icon { flex: 0 0 auto; width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; cursor: pointer;
             border: 1px solid rgba(255, 255, 255, .14); background: rgba(255, 255, 255, .06); color: inherit; font-size: 17px; }
  .rt-icon svg { width: 20px; height: 20px; }
  .rt-icon:focus-visible, .rt-chip:focus-visible, .rt-rate:focus-visible { outline: 2px solid var(--room-2, #f4cf6a); outline-offset: 2px; }
  .rt-icon.is-off { opacity: .45; text-decoration: line-through; }
  .rt-index { display: flex; gap: 6px; overflow-x: auto; padding: 8px 2px 2px; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
  .rt-index::-webkit-scrollbar { display: none; }
  .rt-chip { flex: 0 0 auto; min-width: 34px; height: 30px; padding: 0 10px; border-radius: 999px; cursor: pointer; font-size: 12.5px;
             border: 1px solid rgba(255, 255, 255, .14); background: rgba(255, 255, 255, .05); color: rgba(246, 239, 224, .75); font-family: inherit; }
  .rt-chip.is-past { color: rgba(246, 239, 224, .95); background: rgba(255, 255, 255, .1); }
  .rt-chip.is-now { background: var(--room-2, #f4cf6a); color: #1a0d3d; border-color: transparent; font-weight: 600; }
  .rt-chip.rt-tone-good:not(.is-now) { border-color: rgba(244, 207, 106, .6); }
  .rt-chip.rt-tone-caution:not(.is-now) { border-color: rgba(255, 95, 138, .6); }

  .rt-oracle { position: absolute; left: 12px; top: 112px; width: 64px; height: 64px; border-radius: 50%; overflow: hidden;
               border: 2px solid color-mix(in srgb, var(--room-2, #f4cf6a) 55%, transparent); box-shadow: 0 6px 24px -8px #000;
               transition: box-shadow .4s; pointer-events: none; }
  .rt-oracle .oracle-stage { width: 100%; height: 100%; }
  .rt-oracle .oracle-still, .rt-oracle .oracle-clip { width: 100%; height: 100%; object-fit: cover; object-position: 50% 18%; }
  .rt-oracle.is-speaking { box-shadow: 0 0 0 3px color-mix(in srgb, var(--room-2, #f4cf6a) 45%, transparent), 0 0 28px 4px color-mix(in srgb, var(--room-2, #f4cf6a) 50%, transparent); }

  .rt-panel { position: absolute; left: 0; right: 0; bottom: 104px; margin: 0 auto; width: min(720px, calc(100% - 24px));
              max-height: 34vh; display: flex; flex-direction: column; padding: 12px 16px; border-radius: 18px;
              background: linear-gradient(160deg, rgba(22, 12, 52, .86), rgba(7, 4, 26, .9));
              border: 1px solid rgba(255, 255, 255, .1); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .06), 0 18px 40px -20px #000; }
  .rt-kind { font-size: 11px; letter-spacing: .08em; color: var(--room-2, #f4cf6a); margin-bottom: 4px; }
  .rt-sub { overflow-y: auto; font-size: 15.5px; line-height: 1.85; overscroll-behavior: contain; white-space: pre-line; }
  .rt-phrase { color: rgba(246, 239, 224, .5); transition: color .35s; }
  .rt-phrase.is-done { color: rgba(246, 239, 224, .78); }
  .rt-phrase.is-now { color: #fff; text-shadow: 0 0 14px color-mix(in srgb, var(--room-2, #f4cf6a) 40%, transparent); }
  .rt-outro { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; padding-top: 10px; }
  .rt-hint { font-size: 11.5px; color: rgba(246, 239, 224, .5); padding-top: 6px; text-align: center; }

  .rt-controls { position: absolute; left: 0; right: 0; bottom: 0; padding: 10px 16px max(12px, env(safe-area-inset-bottom));
                 background: linear-gradient(0deg, rgba(5, 3, 18, .95) 30%, rgba(5, 3, 18, 0)); }
  .rt-track { position: relative; display: flex; gap: 3px; height: 14px; align-items: center; cursor: pointer; width: min(720px, 100%); margin: 0 auto; }
  .rt-seg { height: 4px; border-radius: 2px; background: rgba(255, 255, 255, .16); flex-basis: 0; min-width: 3px; }
  .rt-fill { position: absolute; left: 0; top: 5px; height: 4px; width: 0; border-radius: 2px; background: var(--room-2, #f4cf6a);
             box-shadow: 0 0 10px var(--room-2, #f4cf6a); pointer-events: none; }
  .rt-buttons { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 6px; }
  .rt-play { width: 56px; height: 56px; font-size: 20px; background: var(--room-2, #f4cf6a); color: #1a0d3d; border: 0; }
  .rt-time { width: 84px; white-space: nowrap; font-size: 12px; color: rgba(246, 239, 224, .6); font-variant-numeric: tabular-nums; text-align: right; }
  .rt-rate { width: 64px; height: 36px; border-radius: 999px; border: 1px solid rgba(255, 255, 255, .16); background: transparent; color: inherit; cursor: pointer; font-family: inherit; }

  .rt-curtain, .rt-loading { position: absolute; inset: 0; display: grid; place-items: center; padding: 24px; text-align: center; }
  .rt-curtain { background: rgba(5, 3, 18, .72); z-index: 3; }
  .rt-curtain-box { max-width: 420px; padding: 24px; border-radius: 22px; background: linear-gradient(160deg, rgba(30, 16, 70, .95), rgba(7, 4, 26, .95));
                    border: 1px solid rgba(255, 255, 255, .12); }
  .rt-curtain-text { margin: 10px 0 18px; line-height: 1.8; }
  .rt-curtain-text strong { display: block; color: var(--room-2, #f4cf6a); }
  .rt-curtain-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
  .rt-loading { gap: 14px; align-content: center; color: rgba(246, 239, 224, .7); font-size: 14px; pointer-events: none; z-index: 2; }
  .rt-loading-moon { width: 54px; height: 54px; border-radius: 50%; background: #fff5d4; box-shadow: 0 0 40px 8px rgba(255, 245, 212, .35);
                     animation: rt-breathe 2.4s ease-in-out infinite; }
  @keyframes rt-breathe { 50% { transform: scale(.88); opacity: .7; } }

  @media (min-width: 900px) {
    .rt-oracle { left: 24px; top: auto; bottom: 40px; width: 150px; height: 266px; border-radius: 22px; }
    .rt-panel { bottom: 118px; max-height: 28vh; width: min(720px, calc(100% - 2 * 196px)); }
    .rt-sub { font-size: 17px; }
  }
  @media (max-height: 560px) and (orientation: landscape) {
    .rt-panel { max-height: 30vh; bottom: 92px; }
    .rt-oracle { display: none; }
  }

  /* ── แบบ CSS 2.5D (ทางถอย) ─────────────────────────────────────── */
  .rtc { position: absolute; inset: 0; overflow: hidden; --amp: 0; }
  .rtc-sky { position: absolute; inset: 0; background: radial-gradient(120% 80% at 50% 0%, color-mix(in srgb, var(--rtc-a) 22%, var(--rtc-sky)), var(--rtc-sky) 70%); }
  .rtc-stars { position: absolute; inset: 0; opacity: .8;
               background-image: radial-gradient(1.2px 1.2px at 12% 18%, #fff, transparent 60%), radial-gradient(1px 1px at 32% 8%, #fff, transparent 60%),
                 radial-gradient(1.4px 1.4px at 58% 22%, #fff, transparent 60%), radial-gradient(1px 1px at 76% 12%, #fff, transparent 60%),
                 radial-gradient(1.2px 1.2px at 88% 34%, #fff, transparent 60%), radial-gradient(1px 1px at 22% 42%, #fff, transparent 60%),
                 radial-gradient(1px 1px at 46% 36%, #fff, transparent 60%), radial-gradient(1.3px 1.3px at 66% 46%, #fff, transparent 60%);
               background-size: 340px 340px; }
  .rtc-moon { position: absolute; right: 8%; top: 9%; width: 72px; height: 72px; border-radius: 50%; background: #fff5d4;
              box-shadow: 0 0 calc(30px + var(--amp, 0) * 50px) calc(6px + var(--amp, 0) * 16px) color-mix(in srgb, var(--rtc-b) 45%, transparent);
              transition: box-shadow .25s; }
  .rtc-const { position: absolute; left: 50%; top: 22%; width: min(60vw, 300px); transform: translate(-50%, -30%); opacity: 0; transition: opacity .8s; text-align: center; }
  .rtc-const.is-on { opacity: 1; }
  .rtc-const svg { width: 100%; height: auto; overflow: visible; }
  .rtc-const line { stroke: var(--rtc-b); stroke-width: .02; opacity: .8; }
  .rtc-const circle { fill: #fff6dc; filter: drop-shadow(0 0 .05px #fff); }
  .rtc-const-label { margin-top: 8px; color: var(--rtc-b); font-size: 16px; }
  .rtc-board { position: absolute; left: 50%; transform: translateX(-50%); transition: opacity .6s; }
  .rtc.is-sky .rtc-board { opacity: .15; }
  .rtc-graph { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
  .rtc-line { fill: none; stroke-width: calc(4px / var(--unit, 40px) * 1px); stroke-width: .07; stroke-dasharray: 1 1; stroke-dashoffset: 1; transition: stroke-dashoffset .4s linear;
              filter: drop-shadow(0 0 .08px #fff); }
  .rtc-dot { position: absolute; width: 12px; height: 12px; margin: -6px 0 0 -6px; border-radius: 50%; background: var(--dot); box-shadow: 0 0 12px var(--dot);
             transform: scale(0); transition: transform .4s; }
  .rtc-dot.is-on { transform: scale(1); }
  .rtc-dot.is-cur { transform: scale(1.7); }
  .rtc-card { position: absolute; width: var(--w); aspect-ratio: 1 / 1.7; transform: translate(-50%, -50%) rotate(var(--rot)) translateY(-120vh);
              transition: transform .7s cubic-bezier(.2, .7, .2, 1) calc(var(--i) * 50ms), filter .4s, opacity .4s; perspective: 800px; cursor: pointer; }
  .rtc.is-dealt .rtc-card { transform: translate(-50%, -50%) rotate(var(--rot)); }
  .rtc.is-focus .rtc-card:not(.is-cur), .rtc.is-ring .rtc-card { filter: brightness(.45); }
  .rtc .rtc-card.is-cur { transform: translate(-50%, -50%) scale(1.25) translateY(-6%); z-index: 2; filter: none; }
  .rtc-inner { position: absolute; inset: 0; transform-style: preserve-3d; transition: transform .7s cubic-bezier(.2, .7, .2, 1); transform: rotateY(180deg); }
  .rtc-card.is-up .rtc-inner { transform: rotateY(0deg); }
  .rtc-face { position: absolute; inset: 0; border-radius: 7%; overflow: hidden; backface-visibility: hidden; -webkit-backface-visibility: hidden;
              border: 1px solid color-mix(in srgb, var(--rtc-b) 60%, transparent); }
  .rtc-face img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .rtc-back { transform: rotateY(180deg); background: radial-gradient(circle at 50% 50%, var(--rtc-b) 0 14%, transparent 15%), linear-gradient(160deg, #1a0d3d, #07041a);
              background-size: cover; background-position: center; }
  .rtc-card.is-cur .rtc-face { box-shadow: 0 0 22px 2px color-mix(in srgb, var(--rtc-b) 60%, transparent); }
  .rtc-tag { position: absolute; left: 50%; bottom: 100%; transform: translate(-50%, -6px); white-space: nowrap; font-size: 11px; padding: 2px 9px; border-radius: 999px;
             background: rgba(7, 4, 26, .8); color: var(--rtc-b); opacity: 0; transition: opacity .3s; pointer-events: none; }
  .rtc-card.is-cur .rtc-tag { opacity: 1; }
  .rtc-ring { position: absolute; width: 36%; max-width: 220px; aspect-ratio: 1; transform: translate(-50%, -50%) scale(.6); opacity: 0;
              transition: opacity .6s, transform .6s; display: grid; place-items: center; z-index: 3; pointer-events: none; }
  .rtc.is-ring .rtc-ring { opacity: 1; transform: translate(-50%, -50%) scale(1); }
  .rtc-ring svg { position: absolute; inset: 0; width: 100%; height: 100%; }
  .rtc-ring-back { fill: rgba(7, 4, 26, .82); stroke: rgba(255, 255, 255, .1); stroke-width: 6; }
  .rtc-ring-arc { fill: none; stroke-width: 6; stroke-linecap: round; stroke-dasharray: 0 1; transition: stroke-dasharray 1.2s ease-out; }
  .rtc-ring-label { position: relative; padding: 0 14%; text-align: center; font-weight: 600; font-size: clamp(13px, 3.4vw, 18px); display: flex; flex-direction: column; gap: 2px; }
  .rtc-ring-label small { font-weight: 400; font-size: 11px; opacity: .7; }

  @media (prefers-reduced-motion: reduce) {
    .rt *, .rt *::before, .rt *::after { transition-duration: .01ms !important; animation: none !important; }
  }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
  // ตัวคุมของโมดูล resources/js/theater (ไม่เก็บใน state ของ Alpine — กัน proxy ห่อวัตถุ three.js)
  Alpine.data('readingTheater', (cfg, opts) => {
    let ctrl = null;
    let down = null;
    const KIND_LABEL = { birth: '🌠 พื้นดวง', graph: '📈 กราฟชีวิต', card: '🃏 ไพ่', summary: '🎯 สรุปดวงเบื้องต้น', outro: '💬 นั่งคุยซักถาม' };
    const storeKey = 'theater:r' + cfg.id;
    const fmt = (s) => { s = Math.max(0, Math.floor(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
    // แบ่งคำบรรยายเป็นวลีตามช่องว่าง/ขึ้นบรรทัด (ภาษาไทยเว้นวรรคระหว่างประโยค) แล้วรวมวลีสั้นให้ยาวพอ
    const phrasesOf = (text) => {
      const parts = String(text || '').split(/(\s+)/);
      const out = [];
      let buf = '';
      for (const p of parts) {
        buf += p;
        if (/\s/.test(p) && buf.trim().length >= 24) { out.push(buf); buf = ''; }
      }
      if (buf.trim()) out.push(buf);
      return out.length ? out : [''];
    };
    const plainChapters = () => {
      const list = (cfg.voice && cfg.voice.chapters && cfg.voice.chapters.length) ? cfg.voice.chapters : cfg.chapters;
      return list.map((c) => ({ kind: c.kind, short: c.short, title: c.title, text: c.text, tone: c.tone || null }));
    };

    return {
      open: false,
      loading: false,
      stage: '',
      curtain: null,
      chapters: plainChapters(),
      index: 0,
      kind: '',
      title: '',
      kindLabel: '',
      phrases: [],
      phrase: 0,
      playing: false,
      audioMode: !!(cfg.voice && cfg.voice.chapters && cfg.voice.chapters.length),
      hasMusic: (cfg.music || []).length > 0,
      musicOn: true,
      rate: 1,
      time: 0,
      duration: 0,
      timeText: '',
      segments: [],
      resumeIndex: 0,
      resumeName: '',
      pushed: false,

      init() {
        try { this.musicOn = localStorage.getItem('theater:music') !== '0'; } catch (e) {}
        this.onPop = () => { if (this.open) { this.pushed = false; this.teardown(); } };
        window.addEventListener('popstate', this.onPop);
        this.onResize = () => this.measure();
        window.addEventListener('resize', this.onResize);
        // เปิดจากหน้าประวัติ (?theater=1) — ต้องมีการแตะก่อนเล่นเสียง จึงขึ้นม่านให้แตะ
        if (opts.autoOpen) {
          this.$nextTick(() => this.launch(true));
        }
      },

      saved() {
        try {
          const i = parseInt(localStorage.getItem(storeKey) || '0', 10);
          return i > 0 && i < this.chapters.length - 1 ? i : 0;
        } catch (e) { return 0; }
      },

      show() {
        this.open = true;
        document.documentElement.style.overflow = 'hidden';
        if (!this.pushed) {
          history.pushState({ readingTheater: cfg.id }, '');
          this.pushed = true;
        }
        this.$nextTick(() => { this.$refs.dialog && this.$refs.dialog.focus({ preventScroll: true }); this.measure(); });
      },

      /** ปุ่มบนหน้าผล — มีบทค้าง = ถามก่อน · เปิดจากลิงก์ = ขึ้นม่านให้แตะ · กดเอง = เริ่มเล่นเลยในจังหวะแตะนี้ */
      launch(fromLink = false) {
        this.show();
        const r = this.saved();
        if (r > 0) {
          this.resumeIndex = r;
          this.resumeName = this.chapters[r] ? this.chapters[r].title : '';
          this.curtain = 'resume';
        } else if (fromLink || !window.JuntraTheater) {
          this.curtain = 'tap';
          if (!window.JuntraTheater) {
            this.loading = true;
            window.addEventListener('juntra-theater:loaded', () => { this.loading = false; }, { once: true });
          }
        } else {
          this.begin(0);
        }
      },

      begin(i) {
        if (!window.JuntraTheater) { return; }
        this.curtain = null;
        if (ctrl) { ctrl.dispose(); ctrl = null; }
        this.loading = true;
        const ui = {
          onChapter: (k) => this.setChapter(k),
          onTick: (t) => this.onTick(t),
          onPlaying: (p) => { this.playing = p; },
          onStage: (m) => { this.stage = m; this.measure(); },
          onReady: () => { this.loading = false; this.measure(); },
        };
        ctrl = window.JuntraTheater.openTheater({ host: this.$refs.stage, cfg, ui, startIndex: i });
        ctrl.setRate(this.rate);
        // บทจริงของตัวคุม (โหมดเสียงต่อท้ายบท "คุยต่อ" ให้เองถ้าไม่มี) + ความยาวช่วงของแถบเวลา
        this.chapters = ctrl.chapters.map((c) => ({ kind: c.kind, short: c.short, title: c.title, text: c.text, tone: c.tone || null }));
        this.segments = ctrl.chapters.map((c) => Math.max(0.5, c.end - c.start));
        this.musicOn = ctrl.musicOn;
        this.hasMusic = ctrl.hasMusic;
        this.setChapter(i);
        window.__readingTheater = ctrl;   // ให้ QA/หน้าทดสอบอ่านสถานะเวทีได้ (info())
      },

      setChapter(k) {
        const c = this.chapters[k];
        if (!c) return;
        const changed = k !== this.index || !this.phrases.length;
        this.index = k;
        this.kind = c.kind;
        this.title = c.title;
        this.kindLabel = KIND_LABEL[c.kind] || '';
        if (changed) {
          this.phrases = phrasesOf(c.text);
          this.phrase = 0;
          this.$refs.sub && (this.$refs.sub.scrollTop = 0);
        }
        try {
          if (c.kind === 'outro') localStorage.removeItem(storeKey);
          else localStorage.setItem(storeKey, String(k));
        } catch (e) {}
        // เลื่อนชิปของบทปัจจุบันให้อยู่ในสายตา (ในแถบสารบัญเท่านั้น ไม่เลื่อนหน้า)
        this.$nextTick(() => {
          this.measure();   // แผงคำบรรยายสูงไม่เท่ากันทุกบท (บทคุยต่อมีปุ่ม) — ให้เวทีจัดกรอบใหม่
          const nav = this.$refs.index;
          const chip = nav && nav.children[k + 1];
          if (chip) nav.scrollLeft = chip.offsetLeft - nav.clientWidth / 2 + chip.clientWidth / 2;
        });
      },

      onTick(t) {
        this.playing = t.playing;
        if (Math.floor(t.time) !== Math.floor(this.time) || t.duration !== this.duration) {
          this.timeText = fmt(t.time) + ' / ' + fmt(t.duration);
        }
        this.time = t.time;
        this.duration = t.duration;
        if (this.$refs.fill) this.$refs.fill.style.width = (t.duration ? (t.time / t.duration) * 100 : 0).toFixed(2) + '%';
        // วลีที่กำลังพูด — ประมาณตามสัดส่วนตัวอักษร (ข้อ 19.4 ระดับบท)
        const total = this.phrases.reduce((a, p) => a + p.length, 0);
        let acc = 0, k = 0;
        const at = t.progress * total;
        for (; k < this.phrases.length - 1; k++) { acc += this.phrases[k].length; if (acc > at) break; }
        if (k !== this.phrase) {
          this.phrase = k;
          this.$nextTick(() => {
            const sub = this.$refs.sub;
            const el = sub && sub.querySelector('.rt-phrase.is-now');
            if (el) sub.scrollTop = Math.max(0, el.offsetTop - sub.offsetTop - sub.clientHeight / 3);
          });
        }
      },

      /** ส่งพื้นที่ที่ถูกแถบบน/แผงล่างบังให้เวที จัดไพ่ไว้ในช่องที่มองเห็น */
      measure() {
        if (!ctrl || !this.$refs.top) return;
        const H = this.$refs.dialog.clientHeight;
        const panelTop = this.$refs.panel.getBoundingClientRect().top;
        ctrl.setSafe({ top: this.$refs.top.offsetHeight, bottom: Math.max(0, H - panelTop + 8) });
      },

      toggle() { ctrl && ctrl.toggle(); },
      next() { ctrl && ctrl.next(); },
      prev() { ctrl && ctrl.prev(); },
      go(i) { if (ctrl) ctrl.go(i); else this.begin(i); },
      cycleRate() { this.rate = this.rate === 1 ? 1.25 : 1; ctrl && ctrl.setRate(this.rate); },
      toggleMusic() { this.musicOn = !this.musicOn; ctrl && ctrl.setMusic(this.musicOn); },
      seek(e) {
        if (!ctrl) return;
        const r = e.currentTarget.getBoundingClientRect();
        ctrl.seekFraction(Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)));
      },

      pd(e) { down = { x: e.clientX, y: e.clientY, t: performance.now() }; },
      pu(e) {
        if (!down || !ctrl) return;
        const dx = e.clientX - down.x, dy = e.clientY - down.y;
        down = null;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.3) { dx < 0 ? this.next() : this.prev(); return; }
        if (Math.abs(dx) < 10 && Math.abs(dy) < 10) {
          const i = ctrl.pickChapter(e.clientX, e.clientY);
          if (i != null) this.go(i);
        }
      },

      key(e) {
        if (e.target.closest('input, textarea, [contenteditable]')) return;
        if (e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); this.prev(); }
        else if (e.key === ' ' && !e.target.closest('button')) { e.preventDefault(); this.toggle(); }
        else if (e.key === 'Escape') { e.preventDefault(); this.close(); }
      },

      /** "คุยต่อ" — ให้แผงแชทในโรงละครรับไปก่อน (session เสียง) ไม่มีใครรับ = ไปห้องแชทจากคำทำนายนี้ */
      chat() {
        const ev = new CustomEvent('reading-theater:chat', { detail: { readingId: cfg.id }, cancelable: true });
        window.dispatchEvent(ev);
        if (!ev.defaultPrevented && this.$refs.chatForm) {
          this.teardown();
          this.$refs.chatForm.submit();
        }
      },

      /** ปุ่มปิด = ย้อนประวัติที่ push ไว้ (ให้ปุ่มย้อนของเบราว์เซอร์กับปุ่มนี้ทำงานเหมือนกัน) */
      close() {
        if (this.pushed) { history.back(); } else { this.teardown(); }
      },

      teardown() {
        if (ctrl) { ctrl.dispose(); ctrl = null; }
        window.__readingTheater = null;
        this.open = false;
        this.curtain = null;
        this.loading = false;
        this.playing = false;
        document.documentElement.style.overflow = '';
      },

      destroy() {
        window.removeEventListener('popstate', this.onPop);
        window.removeEventListener('resize', this.onResize);
        this.teardown();
      },
    };
  });
});
</script>
@endpush
@endonce
