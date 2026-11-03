@props([
    'persona',
    'poster',
    'alt'   => '',
    // แสดงปุ่มลำโพงหรือไม่ (หน้าเลือกห้องไม่ต้องมี) — มีปุ่มก็ต่อเมื่อห้องนี้มีไฟล์เสียงจริงอย่างน้อย 1 ไฟล์
    'sound' => false,
    'eager' => false,
])

{{-- 🎬 (2026-11-03) เวทีของผู้พยากรณ์ — ภาพนิ่งเป็นฐานเสมอ คลิป idle เงียบ ๆ วางทับเมื่อพร้อม
     - คลิปทุกตัวเริ่ม/จบที่ภาพนิ่ง → สลับคลิปตอนจบได้โดยไม่กระตุก (ไม่ใช้ loop attr)
     - เล่นเฉพาะตอนเห็นบนจอ · ไม่โหลดคลิปเลยถ้าตั้งลดการเคลื่อนไหว / ประหยัดเน็ต / ไฟล์ไม่มี
     - เสียงไม่เล่นเองเด็ดขาด: ลูกค้าต้องกดลำโพงก่อน (ไม่จำค่าข้ามหน้า) ไฟล์ไหนไม่มี = เงียบ --}}
@php
    $loops = \App\Support\OracleMedia::loops($persona);
    $voice = $sound ? \App\Support\OracleMedia::voice($persona) : [];
@endphp

<div {{ $attributes->class('oracle-stage') }} data-persona="{{ $persona }}"
     x-data="oracleStage(@js(['loops' => $loops, 'voice' => $voice]))"
     :class="{ 'is-live': live }">
  <img class="oracle-still" src="{{ $poster }}" alt="{{ $alt }}"
       @if ($eager) fetchpriority="high" @else loading="lazy" @endif
       width="720" height="1280">

  @if ($loops)
    <video class="oracle-clip" x-ref="a" muted playsinline preload="none" disablepictureinpicture aria-hidden="true" tabindex="-1"></video>
    <video class="oracle-clip" x-ref="b" muted playsinline preload="none" disablepictureinpicture aria-hidden="true" tabindex="-1"></video>
  @endif

  {{ $slot }}

  @if ($voice)
    <button type="button" class="oracle-sound" @click.stop.prevent="toggleSound()"
            :aria-pressed="sound ? 'true' : 'false'"
            :aria-label="sound ? 'ปิดเสียงผู้พยากรณ์' : 'เปิดเสียงผู้พยากรณ์'"
            aria-label="เปิดเสียงผู้พยากรณ์">
      <svg x-show="!sound" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="m23 9-6 6M17 9l6 6"/></svg>
      <svg x-show="sound" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
    </button>
  @endif
</div>

@once
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('oracleStage', (cfg) => ({
    loops: cfg.loops || [],
    voice: cfg.voice || {},
    live: false,       // มีคลิปเล่นอยู่บนจอแล้ว (ซ่อนภาพนิ่ง)
    sound: false,      // ลูกค้ากดเปิดเสียงในหน้านี้แล้ว — เริ่มปิดเสมอ
    idx: -1,
    front: null,       // video ที่โชว์อยู่
    broken: new Set(),
    visible: false,
    said: {},
    audio: null,
    io: null,

    init() {
      const a = this.$refs.a, b = this.$refs.b;
      if (!a || !b || !this.loops.length || !this.motionOk()) return;

      [a, b].forEach((v) => {
        v.muted = true;
        v.addEventListener('ended', () => { if (v === this.front) this.next(); });
        v.addEventListener('error', () => this.fail(v));
      });

      // เล่นเฉพาะตอนเห็น — การ์ดที่ปัดพ้นจอ / ภาพที่ซ่อนตาม breakpoint ไม่โหลดคลิปเลย
      this.io = new IntersectionObserver((es) => {
        this.visible = es.some((e) => e.isIntersecting);
        this.visible && !document.hidden ? this.resume() : this.pause();
      }, { threshold: 0.25 });
      this.io.observe(this.$el);
      this._vis = () => (document.hidden ? this.pause() : (this.visible && this.resume()));
      document.addEventListener('visibilitychange', this._vis);

      // ผู้พยากรณ์ "คิด" ครั้งแรกของหน้า → ประโยคสั้น (ถ้าเปิดเสียงไว้และมีไฟล์)
      if (typeof this.thinking !== 'undefined') {
        this.$watch('thinking', (on) => { if (on) this.say('thinking_once', true); });
      }
      this._say = (e) => this.say(e.detail?.key || e.detail, !!e.detail?.once);
      window.addEventListener('oracle-say', this._say);
    },

    destroy() {
      this.io?.disconnect();
      document.removeEventListener('visibilitychange', this._vis);
      window.removeEventListener('oracle-say', this._say);
      this.audio?.pause();
    },

    motionOk() {
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
      const c = navigator.connection;
      return !(c && (c.saveData || /(^|-)2g$/.test(c.effectiveType || '')));
    },

    src(i) {
      const l = this.loops[i];
      return l.webm && document.createElement('video').canPlayType('video/webm; codecs="vp9"') ? l.webm : l.mp4;
    },

    // คลิปถัดไป — สุ่มแต่ไม่ซ้ำตัวเดิม (ถ้ามีมากกว่า 1) ข้ามตัวที่พัง
    pick() {
      const ok = this.loops.map((_, i) => i).filter((i) => !this.broken.has(i));
      if (!ok.length) return -1;
      const rest = ok.length > 1 ? ok.filter((i) => i !== this.idx) : ok;
      return rest[Math.floor(Math.random() * rest.length)];
    },

    load(v, i) {
      v.dataset.idx = i;
      v.src = this.src(i);
      v.preload = 'auto';
      v.load();
    },

    resume() {
      if (this.front) { this.play(this.front); return; }
      const i = this.pick();
      if (i < 0) return;
      this.idx = i;
      this.front = this.$refs.a;
      this.load(this.front, i);
      this.play(this.front);
    },

    pause() { this.front?.pause(); },

    play(v) {
      v.currentTime = v.ended ? 0 : v.currentTime;
      const p = v.play();
      if (p && p.catch) {
        // AbortError = ถูก pause ทันทีหลัง play (ปัดผ่าน) ไม่ใช่ไฟล์พัง
        p.catch((e) => { if (e && e.name !== 'AbortError' && e.name !== 'NotAllowedError') this.fail(v); });
      }
      v.addEventListener('playing', () => {
        if (v !== this.front) return;
        v.classList.add('is-on');
        const other = v === this.$refs.a ? this.$refs.b : this.$refs.a;
        other.classList.remove('is-on');
        this.live = true;
        // โหลดคลิปถัดไปรอไว้ในอีกตัว — ตอนจบสลับได้ทันทีไม่เห็นจอดำ
        // (resume หลังปัดกลับมาก็ยิง playing อีก — ถ้ารอไว้แล้วไม่ต้องโหลดใหม่)
        const n = this.pick();
        if (n >= 0 && this.loops.length > 1 && !other.dataset.idx) this.load(other, n);
      }, { once: true });
    },

    next() {
      const cur = this.front;
      const other = cur === this.$refs.a ? this.$refs.b : this.$refs.a;
      if (this.loops.length < 2 || !other.dataset.idx || this.broken.has(+other.dataset.idx)) {
        this.play(cur);           // มีคลิปเดียว: เล่นซ้ำ (จบที่ภาพนิ่ง = เริ่มที่ภาพนิ่ง)
        return;
      }
      this.front = other;
      this.idx = +other.dataset.idx;
      delete cur.dataset.idx;       // ตัวเก่าว่างแล้ว — จะได้คลิปถัดไปตอนตัวใหม่เริ่มเล่น
      other.currentTime = 0;
      this.play(other);
      if (!this.visible || document.hidden) other.pause();
    },

    fail(v) {
      const i = +v.dataset.idx;
      if (!Number.isNaN(i)) this.broken.add(i);
      delete v.dataset.idx;
      v.classList.remove('is-on');
      v.removeAttribute('src');
      if (v === this.front) {
        this.front = null;
        this.live = false;         // กลับไปภาพนิ่งก่อน
        if (this.visible) this.resume();
      }
    },

    // ── เสียง ─────────────────────────────────────────────
    toggleSound() {
      this.sound = !this.sound;
      if (!this.sound) { this.audio?.pause(); return; }
      this.say('room_greet', true);   // เล่นจากการกดของลูกค้าเท่านั้น (นโยบาย autoplay)
    },

    say(key, once = false) {
      if (!this.sound || !key || !this.voice[key]) return;   // ไม่มีไฟล์ = เงียบ
      if (once && this.said[key]) return;
      this.said[key] = true;
      this.audio?.pause();
      this.audio = new Audio(this.voice[key]);
      this.audio.play().catch(() => {});
    },
  }));
});
</script>
@endpush
@endonce
