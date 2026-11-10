{{--
  🔊 (2026-11-11) เจ้าของ: "มีคำพากย์เสียงด้วย เสียง บัว ว่าถ้าโอนยอดตรงทศนิยม เพื่ออะไร ด้วย"

  เสียง ElevenLabs v3 เสียงไทย "บัว" (UxyS2mR9eUefCFboHnRH) สร้างจากบัญชีของ Thaiprompt
  ไฟล์ public/media/topup/why-satang.mp3 (~34 วิ, 64kbps) — เล่นเมื่อกดเท่านั้น ไม่เล่นเอง
  preload=none: ไม่โหลดไฟล์จนกว่าจะกด (มือถือเน็ตช้าไม่ต้องจ่ายค่าเน็ตก่อนใช้)
--}}
<div class="tp-voice"
     x-data="{
       playing: false,
       failed: false,
       toggle() {
         const a = this.$refs.audio;
         if (this.playing) { a.pause(); return; }
         this.failed = false;
         a.play().catch(() => { this.failed = true; this.playing = false; });
       },
     }">
  <audio x-ref="audio" preload="none" src="{{ asset('media/topup/why-satang.mp3') }}"
         {{-- ใช้ x-on: ไม่ใช่ @ — "@error" ชนกับ directive @error ของ Blade (คอมไพล์เป็น if ไม่มี endif = หน้า 500) --}}
         x-on:play="playing = true" x-on:pause="playing = false" x-on:ended="playing = false; $refs.audio.currentTime = 0"
         x-on:error="failed = true; playing = false"></audio>
  <button type="button" class="tp-voice-btn" :class="playing && 'is-playing'" @click="toggle()"
          :aria-pressed="playing.toString()" aria-label="ฟังคำอธิบายว่าทำไมต้องโอนยอดให้ตรงสตางค์">
    <span class="tp-voice-ico" aria-hidden="true">
      <svg x-show="!playing" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l11-6.5a1 1 0 0 0 0-1.72l-11-6.5A1 1 0 0 0 8 5.5Z"/></svg>
      <svg x-show="playing" x-cloak viewBox="0 0 24 24" fill="currentColor"><rect x="6.5" y="5" width="4" height="14" rx="1.2"/><rect x="13.5" y="5" width="4" height="14" rx="1.2"/></svg>
    </span>
    <span class="tp-voice-txt">
      <b x-text="playing ? 'กำลังเล่า... กดเพื่อหยุด' : 'ฟังคำอธิบาย'">ฟังคำอธิบาย</b>
      <small>ทำไมต้องโอนให้ตรงสตางค์ · ราวครึ่งนาที</small>
    </span>
    <span class="tp-voice-wave" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
  </button>
  <p class="tp-voice-fail" x-show="failed" x-cloak>เล่นเสียงไม่ได้ตอนนี้ — สรุปสั้น ๆ: ยอดมีเศษสตางค์เพื่อให้ระบบรู้ว่าเงินก้อนนี้เป็นของคุณ แล้วเติมให้เองอัตโนมัติค่ะ</p>
</div>
