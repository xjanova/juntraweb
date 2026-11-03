{{-- 🔊 ค่ากลางของเสียงผู้พยากรณ์ — ใช้ร่วมกันโดย <x-oracle-stage> (ห้องแชท) และ <x-oracle-voice> (หน้าเลือกไพ่/ผล)
     เปิดเสียงที่ไหนก็จำไว้ทั้งแท็บ เดินจากแชท → เลือกไพ่ → ผล แม่หมอพูดต่อเนื่องโดยไม่ต้องกดใหม่ทุกหน้า
     sessionStorage อาจใช้ไม่ได้ (โหมดส่วนตัว / ปิดคุกกี้) → ถือว่าปิดเสียง ไม่พัง --}}
@once
@push('scripts')
<script>
window.JuntraVoice = window.JuntraVoice || {
  on() { try { return sessionStorage.getItem('juntra:voice') === '1'; } catch (_) { return false; } },
  set(v) { try { v ? sessionStorage.setItem('juntra:voice', '1') : sessionStorage.removeItem('juntra:voice'); } catch (_) {} },
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
