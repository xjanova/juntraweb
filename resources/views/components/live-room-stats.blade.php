{{-- 🔴 (2026-11-04, owner) แผงตัวเลขสดหน้าห้องผู้พยากรณ์ — ออนไลน์ตอนนี้ · ดูดวงไปแล้ว · คิวเปิดไพ่
     "ทำเป็นว่าวันนี้มีคนดู กำลังดูกี่คน เป็นตัวเลขกระพริบน่าสนใจวิบวับ ... มีบล๊อกคิว เหมือนกำลังจัดคิว"
     → ตกลงกับเจ้าของว่าใช้เลขจริงทั้งหมด: คน DM ทุกช่องทาง + บิลจ่ายแล้วทั้งหมด + คิวที่กำลังเปิดไพ่จริง
     ที่มาของทุกตัวเลขอยู่ใน App\Services\Chat\LiveRoomStats (ห้ามบวกเลขสมมติในหน้านี้)
     ค่าแรกเรนเดอร์จากเซิร์ฟเวอร์ (ไม่กระพริบเป็น 0) แล้ว Alpine ดึง /chat/live ซ้ำ หยุดดึงตอนแท็บซ่อน --}}
@php($live = app(\App\Services\Chat\LiveRoomStats::class)->get())
<div x-data="liveRoomStats(@js($live), @js(route('chat.live')))"
     class="mx-auto mb-6 max-w-3xl rounded-2xl bg-white/5 p-3 ring-1 ring-white/10 backdrop-blur-md sm:p-4 dark:bg-white/5"
     aria-live="polite">
  <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3">

    {{-- ออนไลน์ตอนนี้ --}}
    <div class="relative overflow-hidden rounded-xl bg-gradient-to-br from-emerald-500/15 to-emerald-900/10 p-3 ring-1 ring-emerald-400/25">
      <div class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wider text-emerald-300">
        <span class="relative flex h-2.5 w-2.5">
          <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
          <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
        </span>
        LIVE · ออนไลน์ตอนนี้
      </div>
      <div class="mt-1 flex items-baseline gap-1.5">
        <span class="bg-gradient-to-r from-emerald-200 via-white to-emerald-200 bg-[length:200%_100%] bg-clip-text text-3xl font-extrabold tabular-nums text-transparent transition duration-500 motion-safe:animate-shimmer sm:text-4xl"
              :class="flash.online && 'scale-110 drop-shadow-[0_0_14px_rgba(52,211,153,.9)]'"
              x-text="fmt(shown.online)">{{ number_format($live['online']) }}</span>
        <span class="text-sm text-white/70">คน</span>
      </div>
      <div class="mt-0.5 text-[11px] leading-snug text-white/55">กำลังคุยกับแม่หมอ</div>
    </div>

    {{-- ดูดวงไปแล้ว (บิลจริงทั้งหมด) — นับไม่ได้ = ซ่อนช่อง ไม่เดาเลข --}}
    <div x-show="stats.bills !== null" @if ($live['bills'] === null) x-cloak @endif
         class="relative overflow-hidden rounded-xl bg-gradient-to-br from-amber-400/15 to-fuchsia-900/10 p-3 ring-1 ring-amber-300/25">
      <div class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wider text-amber-200">
        <svg class="h-3 w-3 motion-safe:animate-twinkle" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.1 6.9L21 11l-6.9 2.1L12 20l-2.1-6.9L3 11l6.9-2.1z"/></svg>
        ดูดวงไปแล้ว
      </div>
      <div class="mt-1 flex items-baseline gap-1.5">
        <span class="bg-gradient-to-r from-amber-200 via-white to-fuchsia-200 bg-[length:200%_100%] bg-clip-text text-3xl font-extrabold tabular-nums text-transparent transition duration-500 motion-safe:animate-shimmer sm:text-4xl"
              :class="flash.bills && 'scale-110 drop-shadow-[0_0_14px_rgba(251,191,36,.9)]'"
              x-text="fmt(shown.bills)">{{ number_format((int) $live['bills']) }}</span>
        <span class="text-sm text-white/70">ครั้ง</span>
      </div>
      <div class="mt-0.5 text-[11px] leading-snug text-white/55">นับจากบิลจริงทุกใบ</div>
    </div>

    {{-- บล็อกคิว — ช่องที่สว่าง = คิวที่แม่หมอกำลังเปิดไพ่อยู่จริง · ว่าง = แถบสแกนวิ่ง (กำลังจัดคิว) --}}
    <div class="col-span-2 rounded-xl bg-gradient-to-br from-violet-500/15 to-indigo-900/10 p-3 ring-1 ring-violet-400/25 sm:col-span-1">
      <div class="flex items-center justify-between text-[11px] font-semibold tracking-wider text-violet-200">
        <span>คิวเปิดไพ่</span>
        <span class="tabular-nums" :class="flash.queue && 'text-white'" x-text="stats.queue > 0 ? stats.queue + ' คิว' : 'ว่าง'">{{ $live['queue'] > 0 ? $live['queue'] . ' คิว' : 'ว่าง' }}</span>
      </div>
      <div class="relative mt-2 grid grid-cols-6 gap-1 overflow-hidden rounded-md">
        <template x-for="i in 6" :key="i">
          <span class="h-5 rounded-md transition-all duration-500"
                :class="i <= stats.queue
                  ? 'bg-gradient-to-r from-violet-400 to-fuchsia-400 shadow-[0_0_10px_rgba(192,132,252,.7)] motion-safe:animate-pulse'
                  : 'border border-dashed border-white/15 bg-white/5'"></span>
        </template>
        <span x-show="stats.queue === 0" class="pointer-events-none absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-transparent via-white/25 to-transparent motion-safe:animate-scan" aria-hidden="true"></span>
      </div>
      <div class="mt-1.5 text-[11px] leading-snug text-white/60"
           x-text="stats.queue > 6 ? 'กำลังเปิดไพ่ให้ ' + stats.queue + ' คิว · ระบบเรียงคิวให้อัตโนมัติ'
                 : stats.queue > 0 ? 'แม่หมอกำลังเปิดไพ่ให้คิวก่อนหน้า · ระบบเรียงคิวให้อัตโนมัติ'
                 : 'คิวว่าง · เข้าคุยได้ทันที'">{{ $live['queue'] > 0 ? 'แม่หมอกำลังเปิดไพ่ให้คิวก่อนหน้า · ระบบเรียงคิวให้อัตโนมัติ' : 'คิวว่าง · เข้าคุยได้ทันที' }}</div>
    </div>
  </div>

  <p class="mt-2 text-center text-[11px] text-white/45"
     x-text="(stats.source === 'all' ? 'ตัวเลขจริงจาก Facebook · LINE · เว็บ' : 'ตัวเลขจริงจากเว็บจันทรา') + ' · อัปเดตทุก ' + stats.refresh_seconds + ' วินาที'">
    {{ $live['source'] === 'all' ? 'ตัวเลขจริงจาก Facebook · LINE · เว็บ' : 'ตัวเลขจริงจากเว็บจันทรา' }} · อัปเดตทุก {{ $live['refresh_seconds'] }} วินาที
  </p>
</div>

@once
@push('scripts')
<script>
  // 🔴 แผงตัวเลขสด — นับขึ้น/ลงแบบแอนิเมชันและเรืองแสงเมื่อเลขเปลี่ยน (เลขจาก /chat/live เท่านั้น)
  document.addEventListener('alpine:init', () => {
    Alpine.data('liveRoomStats', (initial, url) => ({
      stats: initial,
      shown: { online: initial.online, bills: initial.bills ?? 0 },
      flash: { online: false, bills: false, queue: false },
      timer: null,

      init() {
        const every = Math.max(10, initial.refresh_seconds || 15) * 1000;
        this.timer = setInterval(() => { if (!document.hidden) this.refresh(); }, every);
        // กลับมาที่แท็บ → ดึงทันที ไม่ต้องรอรอบถัดไป
        this._onVisible = () => { if (!document.hidden) this.refresh(); };
        document.addEventListener('visibilitychange', this._onVisible);
      },

      destroy() {
        clearInterval(this.timer);
        document.removeEventListener('visibilitychange', this._onVisible);
      },

      async refresh() {
        try {
          const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          if (!res.ok) return;
          const next = await res.json();
          for (const k of ['online', 'bills', 'queue']) {
            if (next[k] !== null && next[k] !== this.stats[k]) this.pop(k);
          }
          if (next.online !== this.stats.online) this.tween('online', next.online);
          if (next.bills !== null && next.bills !== this.stats.bills) this.tween('bills', next.bills);
          this.stats = next;
        } catch (e) { /* เครือข่ายหลุด — คงเลขเดิมไว้ รอบหน้าค่อยลองใหม่ */ }
      },

      pop(k) {
        this.flash[k] = true;
        setTimeout(() => { this.flash[k] = false; }, 900);
      },

      tween(k, to) {
        const from = this.shown[k] ?? 0;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { this.shown[k] = to; return; }
        const start = performance.now(), dur = 900;
        const step = (t) => {
          const p = Math.min(1, (t - start) / dur);
          this.shown[k] = Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3)));
          if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      },

      fmt(n) { return Number(n || 0).toLocaleString('th-TH'); },
    }));
  });
</script>
@endpush
@endonce
