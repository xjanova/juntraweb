@props([
    'persona',
    'poster',
    'alt'   => '',
    // แสดงปุ่มลำโพงหรือไม่ (หน้าเลือกห้องไม่ต้องมี) — มีปุ่มก็ต่อเมื่อห้องนี้มีไฟล์เสียงจริงอย่างน้อย 1 ไฟล์
    'sound' => false,
    'eager' => false,
    // ประโยคทักตอนเปิดเสียง — มาจากหน้าผลทำนายใช้ followup_ask แทน room_greet
    'greet' => 'room_greet',
])

{{-- 🎬 (2026-11-03) เวทีของผู้พยากรณ์ — ภาพนิ่งเป็นฐานเสมอ คลิป idle เงียบ ๆ วางทับเมื่อพร้อม
     - คลิปทุกตัวเริ่ม/จบที่ภาพนิ่ง → สลับคลิปตอนจบได้โดยไม่กระตุก (ไม่ใช้ loop attr)
     - เล่นเฉพาะตอนเห็นบนจอ · ไม่โหลดคลิปเลยถ้าตั้งลดการเคลื่อนไหว / ประหยัดเน็ต / ไฟล์ไม่มี
     - เสียงเปิดเป็นค่าเริ่มต้น (เบราว์เซอร์บล็อก = partials.sound-ask เด้งกลางจอให้แตะ) · กดปิดเองแล้วจำไว้ในเครื่อง
       (partials.juntra-voice) ไฟล์ไหนไม่มี = เงียบ
     - ปุ่มอื่น (เช่นแถบหัวห้องบนมือถือ) สั่งเปิด/ปิดได้ด้วยอีเวนต์ oracle-sound-toggle --}}
@php
    $loops = \App\Support\OracleMedia::loops($persona);
    $voice = $sound ? \App\Support\OracleMedia::voice($persona) : [];
@endphp

<div {{ $attributes->class('oracle-stage') }} data-persona="{{ $persona }}"
     x-data="oracleStage(@js(['loops' => $loops, 'voice' => $voice, 'greet' => $greet, 'persona' => $persona]))"
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

@include('partials.juntra-voice')
@if ($voice)
  @include('partials.sound-ask')
@endif

@once
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('oracleStage', (cfg) => ({
    loops: cfg.loops || [],
    voice: cfg.voice || {},
    live: false,       // มีคลิปเล่นอยู่บนจอแล้ว (ซ่อนภาพนิ่ง)
    sound: false,      // เปิดเสียงไว้หรือไม่ — ค่าจริงอ่านจาก JuntraVoice ใน initVoice (เปิดเป็นค่าเริ่มต้น)
    pending: null,     // ประโยคที่เบราว์เซอร์ยังไม่ให้เล่น — รอแตะหน้าครั้งแรก
    idx: -1,
    front: null,       // video ที่โชว์อยู่
    broken: new Set(),
    visible: false,
    audio: null,
    io: null,

    init() {
      this.initVoice();
      const a = this.$refs.a, b = this.$refs.b;
      if (!a || !b || !this.loops.length || !this.motionOk()) return;

      [a, b].forEach((v) => {
        v.muted = true;
        // 🕯️ (2026-11-10) เจ้าของ: "ให้ดูสุขุม ไม่ใช่เต้น ดุกดิ๊กดูไม่ขลังเลย" → เล่นช้ากว่าจริง
        //   (load() คืนค่า playbackRate เป็น defaultPlaybackRate ทุกครั้ง จึงตั้งที่ default)
        v.defaultPlaybackRate = 0.72;
        v.playbackRate = 0.72;
        v.addEventListener('ended', () => { if (v === this.front) this.rest(); });
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
    },

    // เฉพาะเวทีที่มีไฟล์เสียง (sound) — เวทีเล็กบนมือถือ/หน้าเลือกห้องไม่ฟังอีเวนต์ จะได้ไม่พูดซ้อนกัน
    initVoice() {
      if (!Object.keys(this.voice).length) return;
      this.sound = window.JuntraVoice.on();
      this._say = (e) => this.say(e.detail?.key || e.detail, !!e.detail?.once);
      window.addEventListener('oracle-say', this._say);
      this._toggle = () => this.toggleSound();
      window.addEventListener('oracle-sound-toggle', this._toggle);
      this._unlock = () => { if (this.pending) { const k = this.pending; this.pending = null; this.playLine(k); } };
      document.addEventListener('pointerdown', this._unlock, { capture: true });
      // ป๊อปอัปกลางจอ: "แตะเพื่อเปิดเสียง" = เล่นประโยคที่ค้าง · "ไม่ต้องมีเสียง" = ปิด
      window.addEventListener('oracle-sound-unlock', this._unlock);
      this._off = () => { if (this.sound) this.toggleSound(); };
      window.addEventListener('oracle-sound-off', this._off);
      this.announce();
      // เปิดเสียงค้างไว้จากหน้าก่อน → ทักครั้งเดียวต่อห้องต่อแท็บ (ยังไม่แตะหน้า = รอแตะครั้งแรก)
      if (this.sound) this.say(cfg.greet || 'room_greet', true);
    },

    destroy() {
      this.io?.disconnect();
      document.removeEventListener('visibilitychange', this._vis);
      window.removeEventListener('oracle-say', this._say);
      window.removeEventListener('oracle-sound-toggle', this._toggle);
      document.removeEventListener('pointerdown', this._unlock, { capture: true });
      window.removeEventListener('oracle-sound-unlock', this._unlock);
      window.removeEventListener('oracle-sound-off', this._off);
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
      if (this._rest) return;                       // กำลังพักนิ่งอยู่ — ไม่ต้องรีบเล่น
      if (this.front?.ended) { this.rest(); return; } // ถูกหยุดระหว่างพัก → พักต่อก่อนค่อยขยับ
      if (this.front) { this.play(this.front); return; }
      const i = this.pick();
      if (i < 0) return;
      this.idx = i;
      this.front = this.$refs.a;
      this.load(this.front, i);
      this.play(this.front);
    },

    pause() { this.front?.pause(); clearTimeout(this._rest); this._rest = 0; },

    // จบคลิปแล้ว (เฟรมสุดท้าย = ภาพนิ่งของห้อง) ส่วนใหญ่พักนิ่งค้างไว้ 5–12 วิ ก่อนขยับครั้งต่อไป
    //   เดิมเล่นต่อกันรัว ๆ ไม่มีพัก ผู้พยากรณ์เลยดูขยับตลอดเวลา
    rest() {
      clearTimeout(this._rest);
      const ms = Math.random() < 0.7 ? 5000 + Math.random() * 7000 : 0;
      this._rest = setTimeout(() => { this._rest = 0; if (this.visible && !document.hidden) this.next(); }, ms);
    },

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
      window.JuntraVoice.set(this.sound);
      this.announce();
      if (!this.sound) { this.audio?.pause(); this.pending = null; return; }
      // กดเปิด = การแตะของลูกค้า เล่นได้แน่นอน — ทักทุกครั้งที่เปิด ให้รู้ว่าเสียงมาแล้ว
      this.playLine(cfg.greet && this.voice[cfg.greet] ? cfg.greet : 'room_greet');
    },

    // บอกปุ่มลำโพงตัวอื่นในหน้าว่าตอนนี้เปิดหรือปิด
    announce() {
      window.dispatchEvent(new CustomEvent('oracle-sound-state', { detail: { on: this.sound } }));
    },

    say(key, once = false) {
      if (!this.sound || !key || !this.voice[key]) return;   // ไม่มีไฟล์ = เงียบ
      const scope = 'room-' + (cfg.persona || '');
      if (once && window.JuntraVoice.said(scope, key)) return;
      window.JuntraVoice.mark(scope, key);
      this.playLine(key);
    },

    // ⚠️ (2026-11-07) ชื่อต้องไม่ซ้ำ play(v) ของคลิป — เดิมตั้งชื่อ play เหมือนกัน ตัวนี้ทับตัวคลิป
    //    คลิป idle ทุกห้องเลยไม่เคยเล่นตั้งแต่ 4 พ.ย. (play(video) ไปเปิดหาเสียงชื่อ "[object HTMLVideoElement]")
    playLine(key) {
      if (!this.voice[key]) return;
      this.audio?.pause();
      const a = new Audio(this.voice[key]);
      this.audio = a;
      // บอกท่าทางของผู้พยากรณ์ (poses.js) ว่ากำลังพูดประโยคนี้อยู่
      const line = (on) => window.dispatchEvent(new CustomEvent('oracle-line', { detail: { on, key } }));
      a.addEventListener('playing', () => line(true), { once: true });
      ['ended', 'pause', 'error'].forEach((ev) => a.addEventListener(ev, () => line(false), { once: true }));
      const p = a.play();
      // ยังไม่ได้แตะหน้า (นโยบาย autoplay) → เก็บประโยคล่าสุดไว้เล่นตอนแตะครั้งแรก
      if (p && p.catch) p.catch((e) => { if (e && e.name === 'NotAllowedError') this.pending = key; });
    },
  }));
});
</script>
@endpush
@endonce
