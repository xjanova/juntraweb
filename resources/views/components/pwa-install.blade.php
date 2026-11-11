{{--
  📱 (2026-11-11) ชิป "ติดตั้งแอปจันทรา" — เจ้าของสั่ง "ทำให้เว็บติดตั้งลงมือถือ หรือ ไอแพดได้เหมือน web app"
    - Android / คอม (Chrome, Edge, Samsung): ใช้ beforeinstallprompt ของเบราว์เซอร์ = กดแล้วติดตั้งเลย
    - iPhone / iPad (Safari): ไม่มีปุ่มติดตั้งให้เรียก → แผ่นสอน "แชร์ → เพิ่มลงในหน้าจอโฮม"
    - เปิดอยู่ในแอป LINE / Facebook: เพิ่มลงหน้าจอโฮมไม่ได้ → บอกให้เปิดใน Safari/Chrome ก่อน
    - เปิดจากไอคอนแล้ว (standalone) / ปิดชิปไปแล้ว = ไม่โผล่ 14 วัน (localStorage)
    - ไม่โผล่ทับห้องพยากรณ์ / โรงละครเปิดไพ่ / หน้าผลไพ่ (มี HUD และปุ่มเต็มจอ)
  ตัวคุมอยู่ที่ resources/js/pwa.js (Alpine.data('pwaInstall'))
--}}
@php
  $skip = request()->routeIs('chat.*', 'tarot.pick', 'tarot.show', 'tarot.free', 'install.*', 'filament.*', 'admin-chat.*')
       || request()->has('theater')
       || request()->attributes->get('hide_whats_new');
@endphp

@once

<style>
  /* เปิดจากไอคอนบนหน้าจอโฮมของ iPhone/iPad: แถบสถานะโปร่ง (black-translucent) ทับขอบบน
     → ดันแถบเมนูลงด้วย safe-area ด้วย border โปร่ง (ไม่แตะ padding เดิมของแต่ละธีม/จอ)
     ห้องแชท/โรงละครจัด safe-area เองแล้ว (oracle-room.css, reading-theater) */
  @media (display-mode: standalone) {
    body { padding-bottom: env(safe-area-inset-bottom, 0px); }
    nav.top, header.nav { border-top: env(safe-area-inset-top, 0px) solid transparent; }
    body.theme-mae-mor-chantra > main { padding-top: env(safe-area-inset-top, 0px); }
    /* เมนูมือถือ (dropdown fixed) ของสองธีม — ขยับลงเท่าแถบสถานะ */
    @media (max-width: 980px) { nav.top .nav-collapse { top: calc(74px + env(safe-area-inset-top, 0px)); } }
    @media (max-width: 880px) { header.nav .nav-links { top: calc(90px + env(safe-area-inset-top, 0px)); } }
  }

  .pwa-chip {
    position: fixed; z-index: 70; left: 50%; transform: translateX(-50%);
    bottom: calc(16px + env(safe-area-inset-bottom, 0px));
    display: flex; align-items: center; gap: 4px;
    max-width: calc(100% - 32px); padding: 6px 6px 6px 8px; border-radius: 999px;
    background: rgba(13, 8, 38, .92); border: 1px solid rgba(244, 207, 106, .45);
    box-shadow: 0 14px 40px -12px rgba(0, 0, 0, .7), 0 0 24px rgba(176, 124, 255, .18);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    color: var(--ink, #f3e9ff); font-size: 14px;
  }
  .pwa-chip-go {
    display: inline-flex; align-items: center; gap: 10px; min-height: 44px; padding: 0 14px 0 6px;
    background: transparent; border: 0; color: inherit; font: inherit; cursor: pointer; text-align: left;
  }
  .pwa-chip-go img { width: 34px; height: 34px; border-radius: 10px; flex: none; }
  .pwa-chip-go b { color: var(--gold, #f4cf6a); font-weight: 600; display: block; line-height: 1.25; }
  .pwa-chip-go small { color: var(--ink-dim, #c8b8de); font-size: 12px; display: block; line-height: 1.3; }
  .pwa-x { width: 40px; height: 40px; flex: none; border-radius: 50%; border: 0; background: transparent;
    color: var(--ink-dim, #c8b8de); font-size: 22px; line-height: 1; cursor: pointer; }
  .pwa-chip-go:focus-visible, .pwa-x:focus-visible, .pwa-sheet button:focus-visible { outline: 2px solid var(--gold, #f4cf6a); outline-offset: 2px; }

  .pwa-backdrop { position: fixed; inset: 0; z-index: 2147483500; background: rgba(4, 2, 12, .66);
    display: flex; align-items: flex-end; justify-content: center; }
  .pwa-sheet {
    width: min(460px, 100%); max-height: 88vh; overflow: auto; box-sizing: border-box;
    padding: 22px 22px calc(22px + env(safe-area-inset-bottom, 0px)); border-radius: 24px 24px 0 0;
    background: linear-gradient(180deg, #1a0f33, #0d0826); border: 1px solid rgba(244, 207, 106, .3); border-bottom: 0;
    color: var(--ink, #f3e9ff);
  }
  @media (min-width: 700px) { .pwa-backdrop { align-items: center; } .pwa-sheet { border-radius: 24px; border-bottom: 1px solid rgba(244, 207, 106, .3); } }
  .pwa-sheet h3 { margin: 0 0 6px; font-size: 19px; color: var(--gold, #f4cf6a); font-weight: 600; }
  .pwa-sheet p { margin: 0 0 14px; color: var(--ink-dim, #c8b8de); font-size: 14px; line-height: 1.7; }
  .pwa-steps { list-style: none; margin: 0 0 18px; padding: 0; display: grid; gap: 10px; }
  .pwa-steps li { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 14px;
    background: rgba(255, 255, 255, .04); border: 1px solid rgba(255, 255, 255, .07); font-size: 15px; line-height: 1.6; }
  .pwa-steps .n { flex: none; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center;
    background: rgba(244, 207, 106, .16); color: var(--gold, #f4cf6a); font-size: 13px; font-weight: 600; }
  .pwa-steps svg { display: inline-block; width: 18px; height: 18px; vertical-align: -3px; margin: 0 2px; }
  .pwa-sheet .pwa-done { width: 100%; min-height: 48px; border-radius: 999px; border: 0; cursor: pointer; font: inherit; font-size: 16px;
    color: #1a0f05; background: linear-gradient(180deg, #f7dc8c, #e0b54f); }
</style>

@endonce

@unless ($skip)
<div x-data="pwaInstall" x-cloak>
  <div class="pwa-chip" x-show="show && !sheet" x-transition.opacity role="region" aria-label="ติดตั้งแอปจันทรา">
    <button type="button" class="pwa-chip-go" @click="install()">
      <img src="{{ asset('images/pwa/icon-192.png') }}" alt="" width="34" height="34">
      <span>
        <b>ติดตั้งแอปจันทรา</b>
        <small x-text="mode === 'prompt' ? 'เปิดเร็ว เต็มจอ รับแจ้งเตือนดวงได้' : 'เพิ่มลงหน้าจอโฮม เปิดได้เหมือนแอป'"></small>
      </span>
    </button>
    <button type="button" class="pwa-x" @click="dismiss()" aria-label="ไม่ใช่ตอนนี้">×</button>
  </div>

  <div class="pwa-backdrop" x-show="sheet" x-transition.opacity @click.self="sheet = false" @keydown.escape.window="sheet = false"
       role="dialog" aria-modal="true" aria-labelledby="pwa-sheet-title">
    <div class="pwa-sheet">
      <template x-if="mode === 'inapp'">
        <div>
          <h3 id="pwa-sheet-title">เปิดใน Safari ก่อนนะคะ</h3>
          <p>ตอนนี้คุณเปิดเว็บอยู่ในแอป LINE หรือ Facebook ซึ่งยังเพิ่มลงหน้าจอโฮมไม่ได้</p>
          <ol class="pwa-steps">
            <li><span class="n">1</span><span>แตะปุ่ม <b>⋯</b> หรือ <b>แชร์</b> มุมจอ</span></li>
            <li><span class="n">2</span><span>เลือก <b>เปิดใน Safari</b> (หรือเปิดในเบราว์เซอร์)</span></li>
            <li><span class="n">3</span><span>แตะ “ติดตั้งแอปจันทรา” อีกครั้งในหน้านั้นค่ะ</span></li>
          </ol>
        </div>
      </template>
      <template x-if="mode !== 'inapp'">
        <div>
          <h3 id="pwa-sheet-title">เพิ่มจันทราลงหน้าจอโฮม</h3>
          <p>ทำครั้งเดียว แล้วคุณจะเปิดจันทราได้จากไอคอนเหมือนแอป เต็มจอ และเปิดรับแจ้งเตือนดวงประจำวันได้</p>
          <ol class="pwa-steps">
            <li><span class="n">1</span><span>แตะปุ่ม <b>แชร์</b>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>
              ที่แถบล่าง (iPad อยู่มุมขวาบน)</span></li>
            <li><span class="n">2</span><span>เลื่อนลงแล้วเลือก <b>เพิ่มไปยังหน้าจอโฮม</b>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8v8M8 12h8"/></svg></span></li>
            <li><span class="n">3</span><span>แตะ <b>เพิ่ม</b> มุมขวาบน แล้วเปิดจันทราจากไอคอนบนหน้าจอโฮมได้เลยค่ะ</span></li>
          </ol>
        </div>
      </template>
      <button type="button" class="pwa-done" @click="dismiss()">เข้าใจแล้ว</button>
    </div>
  </div>
</div>
@endunless
