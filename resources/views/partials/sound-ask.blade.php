{{-- 🔊 (2026-11-05) ป๊อปอัปกลางจอ "แตะเพื่อเปิดเสียง" — เด้งเฉพาะเครื่องที่เบราว์เซอร์ไม่ยอมเล่นเสียงเอง
     เจ้าของสั่ง: "ให้ผู้ใช้เปิดเสียงหากยังไม่ได้เปิดเด้งมากลางจอเลย" + "ถ้าเครื่องไหนเปิดได้เลย ก็เปิดให้เลย"

     ลำดับ:
       1. ลูกค้าเคยกดปิดเสียงเอง (JuntraVoice.on() = false) → ไม่ทำอะไร
       2. ลองเล่นเสียงเงียบ 25 ms → เล่นได้ = เบราว์เซอร์ยอมแล้ว เสียงของหน้าดังเอง ไม่ต้องเด้ง
       3. NotAllowedError = ต้องแตะก่อน → เด้งกลางจอ
            "เปิดเสียง"      → การแตะนี้ปลดล็อกเสียง + ส่ง oracle-sound-unlock ให้ปุ่มลำโพงเล่นประโยคที่ค้างไว้
            "ไม่ต้องมีเสียง" → ส่ง oracle-sound-off (จำว่าปิด ไม่เด้งอีก)
     ใส่ได้หลายที่ในหน้าเดียว (@once) · ไม่ใช้ Alpine — ทำงานได้แม้ Alpine ยังไม่บูต --}}
@once
@push('scripts')
<div class="sound-ask" id="soundAsk" role="dialog" aria-modal="true" aria-labelledby="soundAskTitle" hidden>
  <div class="sound-ask-card">
    <div class="sound-ask-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
    </div>
    <h2 class="sound-ask-title" id="soundAskTitle">เปิดเสียงห้องพยากรณ์</h2>
    <p class="sound-ask-text">ผู้พยากรณ์จะพูดกับคุณ พร้อมเพลงและเสียงไพ่ · เบราว์เซอร์ของเครื่องนี้ขอให้แตะเปิดเสียงหนึ่งครั้ง</p>
    <button type="button" class="sound-ask-on" data-sound-ask="on">แตะเพื่อเปิดเสียง</button>
    <button type="button" class="sound-ask-off" data-sound-ask="off">ไม่ต้องมีเสียง</button>
  </div>
</div>
<script>
(() => {
  const box = document.getElementById('soundAsk');
  if (!box || !window.JuntraVoice || !window.JuntraVoice.on()) return;
  const SILENT = 'data:audio/wav;base64,UklGRuwAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YcgAAACAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgA==';
  let shown = false;
  const close = () => { box.hidden = true; document.removeEventListener('keydown', onKey); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  const open = () => {
    if (shown || !window.JuntraVoice.on()) return;
    shown = true; box.hidden = false;
    document.addEventListener('keydown', onKey);
    setTimeout(() => box.querySelector('[data-sound-ask="on"]')?.focus({ preventScroll: true }), 50);
  };
  box.addEventListener('click', (e) => {
    const b = e.target.closest('[data-sound-ask]');
    if (!b) return;
    if (b.dataset.soundAsk === 'on') {
      // คลิกนี้คือการแตะของลูกค้า → ปุ่มลำโพงเล่นประโยคที่ค้างไว้ได้แล้ว
      window.JuntraVoice.set(true);
      window.dispatchEvent(new CustomEvent('oracle-sound-unlock'));
    } else {
      window.JuntraVoice.set(false);
      window.dispatchEvent(new CustomEvent('oracle-sound-off'));
    }
    close();
  });
  // แตะตรงไหนของหน้าก่อนเด้ง = ปลดล็อกแล้ว ไม่ต้องเด้ง
  let touched = false;
  document.addEventListener('pointerdown', () => { touched = true; }, { capture: true, once: true });
  const probe = () => {
    if (touched) return;
    let a;
    try { a = new Audio(SILENT); } catch (_) { return; }
    const p = a.play();
    if (!p || !p.then) return;
    p.then(() => a.pause()).catch((e) => { if (e && e.name === 'NotAllowedError' && !touched) open(); });
  };
  // รอหน้าโหลดสักครู่ ให้ฉากขึ้นก่อนค่อยเด้ง
  const go = () => setTimeout(probe, 700);
  if (document.readyState === 'complete') go(); else window.addEventListener('load', go, { once: true });
})();
</script>
@endpush
@endonce
