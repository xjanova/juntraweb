@props([
    'persona',
    // ประโยคที่พูดเมื่อเข้าหน้านี้ เรียงตามลำดับ (เช่น ['reveal_cards', 'reading_wait'])
    'cues'  => [],
    // ขอบเขตของ "พูดครั้งเดียว" — เช่น reading-123 กันรีเฟรชหน้าผลแล้วพูดซ้ำ
    'scope' => null,
])

{{-- 🔊 (2026-11-04) เสียงผู้พยากรณ์บนหน้าที่ไม่มีเวที (เลือกไพ่ / ผลทำนาย)
     - ไม่มีไฟล์เสียงของห้องนี้เลย = ไม่แสดงอะไร
     - เปิดเป็นค่าเริ่มต้น · ลูกค้ากดปิดเองแล้วจำไว้ในเครื่อง (ค่าเดียวกับปุ่มลำโพงในห้องแชท — partials.juntra-voice)
     - เบราว์เซอร์ไม่ให้เล่นเสียงก่อนลูกค้าแตะหน้า → เก็บประโยคล่าสุดไว้ เล่นตอนแตะครั้งแรก
       และ partials.sound-ask เด้งกลางจอชวนแตะเปิดเสียง
     - หน้าอื่นสั่งพูดได้ด้วย window.dispatchEvent(new CustomEvent('oracle-say', {detail: {key, once}})) --}}
@php
    $voice = \App\Support\OracleMedia::voice($persona);
@endphp

@if ($voice)
  @include('partials.juntra-voice')
  @include('partials.sound-ask')

  <button type="button" class="oracle-voice-fab"
          x-data="oracleVoice(@js(['voice' => $voice, 'cues' => array_values($cues), 'scope' => $scope]))"
          @click="toggle()"
          :aria-pressed="sound ? 'true' : 'false'"
          :aria-label="sound ? 'ปิดเสียงแม่หมอ' : 'เปิดเสียงแม่หมอ'"
          aria-label="เปิดเสียงแม่หมอ">
    <svg x-show="!sound" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="m23 9-6 6M17 9l6 6"/></svg>
    <svg x-show="sound" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
    <span class="oracle-voice-label" x-text="sound ? 'เสียงเปิด' : 'ฟังเสียงแม่หมอ'"></span>
  </button>

  @once
  @push('scripts')
  <script>
  document.addEventListener('alpine:init', () => {
    Alpine.data('oracleVoice', (cfg) => ({
      voice: cfg.voice || {},
      queue: [],
      audio: null,
      sound: false,
      pending: null,       // ประโยคที่รอการแตะครั้งแรก (นโยบาย autoplay)

      init() {
        this.sound = window.JuntraVoice.on();
        this._say = (e) => this.say(e.detail?.key || e.detail, !!e.detail?.once);
        window.addEventListener('oracle-say', this._say);
        this._unlock = () => { if (this.pending) { const k = this.pending; this.pending = null; this.play(k); } };
        document.addEventListener('pointerdown', this._unlock, { once: true, capture: true });
        // โรงละครเปิดไพ่ขอพูดเองตามจังหวะพิธี (เช่น ผลฟันธงหลังเปิดไพ่ครบ) — หยุดประโยคเข้าหน้า
        this._hold = () => { this.audio?.pause(); this.queue = []; this.pending = null; };
        window.addEventListener('oracle-hold', this._hold);
        // ป๊อปอัปกลางจอ: "แตะเพื่อเปิดเสียง" = เล่นประโยคที่ค้าง · "ไม่ต้องมีเสียง" = ปิด
        this._unlockAsk = () => this._unlock();
        window.addEventListener('oracle-sound-unlock', this._unlockAsk);
        this._off = () => { if (this.sound) this.toggle(); };
        window.addEventListener('oracle-sound-off', this._off);
        // ประโยคเข้าหน้า: พูดต่อกันทีละประโยค ครั้งเดียวต่อ scope
        // นับว่า "พูดแล้ว" เฉพาะตอนเปิดเสียงอยู่ — ปิดเสียงไว้แล้วค่อยเปิดทีหลังต้องยังได้ยิน
        this.queue = window.__ctVoiceHold ? [] : (cfg.cues || []).filter((k) => this.voice[k] && !window.JuntraVoice.said(cfg.scope, k));
        if (this.sound) {
          this.queue.forEach((k) => window.JuntraVoice.mark(cfg.scope, k));
          this.next();
        }
      },

      destroy() {
        window.removeEventListener('oracle-say', this._say);
        window.removeEventListener('oracle-hold', this._hold);
        window.removeEventListener('oracle-sound-unlock', this._unlockAsk);
        window.removeEventListener('oracle-sound-off', this._off);
        document.removeEventListener('pointerdown', this._unlock, { capture: true });
        this.audio?.pause();
      },

      toggle() {
        this.sound = !this.sound;
        window.JuntraVoice.set(this.sound);
        if (!this.sound) { this.audio?.pause(); this.queue = []; this.pending = null; return; }
        this.pending = null;
        // กดเปิดตอนไหนก็ได้ยินประโยคของหน้านี้ — ปุ่มคือการแตะของลูกค้า จึงเล่นได้แน่นอน
        // (ระหว่างโรงละครเปิดไพ่คุมจังหวะอยู่ ไม่พูดประโยคเข้าหน้า — โรงละครพูดเองตอนถึงจังหวะ)
        const first = window.__ctVoiceHold ? null : (cfg.cues || []).find((k) => this.voice[k]);
        if (!this.queue.length && first) this.queue = [first];
        this.next();
      },

      next() {
        const k = this.queue.shift();
        if (k) this.play(k, () => this.next());
      },

      say(key, once = false) {
        if (!this.sound || !key || !this.voice[key]) return;
        if (once && window.JuntraVoice.said(cfg.scope, key)) return;
        window.JuntraVoice.mark(cfg.scope, key);
        this.queue = [];
        this.play(key);
      },

      play(key, after = null) {
        this.audio?.pause();
        this.audio = new Audio(this.voice[key]);
        if (after) this.audio.addEventListener('ended', after, { once: true });
        const p = this.audio.play();
        // ยังไม่ได้แตะหน้า → รอแตะครั้งแรก (เก็บแค่ประโยคล่าสุด ไม่พูดย้อนหลังเป็นชุด)
        if (p && p.catch) p.catch((e) => { if (e && e.name === 'NotAllowedError') this.pending = key; });
      },
    }));
  });
  </script>
  @endpush
  @endonce
@endif
