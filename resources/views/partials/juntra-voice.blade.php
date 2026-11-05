{{-- 🔊 ค่ากลางของเสียงผู้พยากรณ์ — ใช้ร่วมกันโดย <x-oracle-stage> (ห้องแชท) และ <x-oracle-voice> (หน้าเลือกไพ่/ผล)
     (2026-11-05) เจ้าของสั่ง "ถ้าเครื่องไหนเปิดได้เลย ก็เปิดให้เลย":
       - เสียงเปิดเป็นค่าเริ่มต้น · เครื่องที่เบราว์เซอร์ยอมให้เล่นเอง = ดังทันที
       - เบราว์เซอร์บล็อก = partials.sound-ask เด้งกลางจอให้แตะเปิดครั้งเดียว
       - ลูกค้ากดปิดเอง = จำไว้ในเครื่อง (localStorage 'juntra:voice-off') ไม่เปิดให้เองอีก ไม่เด้งกวน
     storage ใช้ไม่ได้ (โหมดส่วนตัวบางแบบ) → ถือว่าเปิด ไม่พัง --}}
@once
@push('scripts')
<script>
window.JuntraVoice = window.JuntraVoice || {
  on() { try { return localStorage.getItem('juntra:voice-off') !== '1'; } catch (_) { return true; } },
  set(v) { try { v ? localStorage.removeItem('juntra:voice-off') : localStorage.setItem('juntra:voice-off', '1'); } catch (_) {} },
  // "พูดครั้งเดียว" ต่อ scope (เช่น ใบผลทำนายใบเดียวกัน) — ไม่มี scope = ครั้งเดียวต่อการโหลดหน้า
  _mem: {},
  _k(scope, key) { return 'juntra:said:' + scope + ':' + key; },
  said(scope, key) {
    if (!scope) return !!this._mem[key];
    try { return sessionStorage.getItem(this._k(scope, key)) === '1'; } catch (_) { return !!this._mem[scope + key]; }
  },
  mark(scope, key) {
    if (!scope) { this._mem[key] = true; return; }
    try { sessionStorage.setItem(this._k(scope, key), '1'); } catch (_) { this._mem[scope + key] = true; }
  },
};
</script>
@endpush
@endonce
