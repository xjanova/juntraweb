{{--
  ✨ (2026-11-07) ป๊อปอัป "มีอะไรใหม่" — แนะนำฟีเจอร์ใหม่ครั้งเดียวต่อเครื่อง
  เจ้าของสั่ง "เพิ่มลูกเล่นที่แนะนำฟีเจอร์ใหม่ไปด้วยเลย"
    - จำว่าเห็นแล้วใน localStorage (คีย์ตามรุ่น — ฟีเจอร์รอบหน้าเปลี่ยน KEY ก็เด้งใหม่)
    - localStorage ใช้ไม่ได้ (โหมดส่วนตัว/บล็อก) = ไม่เด้งเลย ดีกว่าเด้งทุกหน้า
    - ไม่เด้งทับพิธีเปิดไพ่ / โรงละคร / ห้องแชท / หน้าผลไพ่ (มีโรงละครเปิดเอง)
--}}
@php
  // แสดงเฉพาะสมาชิกที่ล็อกอิน (ฟีเจอร์ใหม่ทั้งหมดอยู่ในบัญชี) — คนเพิ่งมาจากโฆษณาไม่โดนเด้งทับ
  // 💬 (2026-11-07) เจ้าของหาไม่เจอ "ฟีเจอร์ที่คุณทำเพิ่มอยู่ไหน" — ลูกค้าอยู่ในห้องแชทเกือบตลอด (หน้ารวมไพ่ปิดแล้ว)
  //    ห้องแชทจึงเด้งได้ ยกเว้นช่วงกำลังทำนาย/ถามต่อ (มีล็อกห้อง) ที่ห้ามมีอะไรมาบัง
  $inReading = request()->routeIs('chat.index') && auth()->check() && \App\Support\ReadingRoomLock::active(request()) !== null;
  $skip = ! auth()->check() || request()->routeIs('tarot.pick', 'tarot.show', 'tarot.free', 'install.*', 'filament.*')
       || (request()->routeIs('chat.*') && (! request()->routeIs('chat.index') || $inReading || request()->boolean('readonly')))
       || request()->has('theater');
  $art  = file_exists(public_path('images/juntra/art/whats-new.webp')) ? asset('images/juntra/art/whats-new.webp') : null;
  $items = [
    ['ico' => '🌙', 'title' => 'ดวงประจำวัน', 'text' => 'เช็กอินทุกวันรับสีเสริมดวง เลขนำโชค ทิศมงคล และคำอวยพร มาต่อเนื่องได้ป้ายสะสม', 'href' => route('account.dashboard').'#daily-luck', 'cta' => 'รับดวงวันนี้'],
    ['ico' => '🃏', 'title' => 'สมุดสะสมไพ่ 78 ใบ', 'text' => 'ไพ่ทุกใบที่เคยเปิดได้จะหงายหน้าในสมุด ลองสะสมให้ครบสำรับ', 'href' => route('account.collection'), 'cta' => 'เปิดสมุด'],
    ['ico' => '🎙️', 'title' => 'กดไมค์พูดแทนพิมพ์', 'text' => 'ในห้องพยากรณ์ แตะปุ่มไมค์ข้างช่องพิมพ์แล้วพูดได้เลย ข้อความขึ้นให้ตรวจก่อนกดส่ง', 'href' => route('chat.index'), 'cta' => 'ไปห้องพยากรณ์'],
    ['ico' => '⭐', 'title' => 'ให้ดาวคำทำนาย', 'text' => 'อ่านจบแล้วบอกแม่หมอได้ว่าตรงใจแค่ไหน ช่วยให้คำทำนายครั้งต่อไปดีขึ้น', 'href' => null, 'cta' => null],
    ['ico' => '🔗', 'title' => 'แชร์ไพ่ให้เพื่อน', 'text' => 'เปิดลิงก์แชร์คำทำนายส่งทาง LINE หรือ Facebook ได้ ปิดกลับเป็นส่วนตัวได้ทุกเมื่อ', 'href' => null, 'cta' => null],
  ];
@endphp

@unless ($skip)
<div x-data="{
       open: false,
       key: 'jw-whats-new-2026-11-07b',
       init() {
         let seen = true;
         try { seen = localStorage.getItem(this.key) === '1'; } catch (e) {}
         if (!seen) setTimeout(() => { this.open = true }, 1400);
       },
       close() { this.open = false; try { localStorage.setItem(this.key, '1'); } catch (e) {} }
     }"
     x-show="open" x-cloak x-transition.opacity
     @keydown.escape.window="if (open) close()"
     class="wn-backdrop" role="dialog" aria-modal="true" aria-labelledby="wn-title" @click.self="close()">
  <div class="wn-card" x-show="open" x-transition>
    <button type="button" class="wn-x" @click="close()" aria-label="ปิด">×</button>
    @if ($art)
      <img src="{{ $art }}" alt="" class="wn-art" loading="lazy" decoding="async" width="1600" height="686">
    @endif
    <div class="eyebrow" style="display:inline-flex">มีอะไรใหม่ที่จันทรา</div>
    <h3 id="wn-title" class="wn-title">ของเล่นใหม่ให้กลับมาหาแม่หมอทุกวัน</h3>
    <ul class="wn-list">
      @foreach ($items as $it)
        <li>
          <span class="wn-ico" aria-hidden="true">{{ $it['ico'] }}</span>
          <div>
            <strong>{{ $it['title'] }}</strong>
            <p>{{ $it['text'] }}</p>
            @if ($it['href'])
              <a href="{{ $it['href'] }}" @click="close()">{{ $it['cta'] }} →</a>
            @endif
          </div>
        </li>
      @endforeach
    </ul>
    <button type="button" class="btn btn-primary wn-ok" @click="close()">เข้าใจแล้ว</button>
  </div>
</div>

{{-- รวมไว้ในเลย์เอาต์หลัง @stack('head') แล้ว → ใช้ <style> ในตัว ไม่ใช่ @push --}}
<style>
  .wn-backdrop { position: fixed; inset: 0; z-index: 9000; display: grid; place-items: center; padding: 16px;
                 background: rgba(4,2,16,.72); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
  .wn-card { position: relative; width: min(520px, 100%); max-height: calc(100dvh - 32px); overflow-y: auto; padding: 24px 24px 22px;
             border: 1px solid rgba(244,207,106,.4); border-radius: 22px; text-align: center;
             background: radial-gradient(120% 90% at 50% 0%, rgba(244,207,106,.10), transparent 60%), linear-gradient(170deg, #1a1036, #0a0620); box-shadow: 0 30px 80px rgba(0,0,0,.6); }
  /* .wn-x.wn-x (0,3,0) ชนะ body.theme-… button:not(.btn) ที่ล้างพื้นหลังปุ่ม */
  .wn-card .wn-x.wn-x { position: absolute; top: 10px; right: 12px; width: 36px; height: 36px; border-radius: 50%; border: 0; background: rgba(255,255,255,.06); color: var(--ink); font-size: 22px; cursor: pointer; z-index: 1; }
  .wn-art { width: calc(100% + 48px); margin: -24px -24px 16px; height: auto; aspect-ratio: 1600/686; object-fit: cover; border-radius: 22px 22px 0 0; display: block; }
  .wn-title { font-family: var(--serif); font-weight: 400; font-size: 22px; margin: 8px 0 14px; }
  .wn-list { list-style: none; margin: 0 0 18px; padding: 0; display: grid; gap: 12px; text-align: left; }
  .wn-list li { display: flex; gap: 12px; padding: 12px 14px; border: 1px solid var(--line-soft); border-radius: 14px; background: rgba(7,4,26,.4); }
  .wn-ico { font-size: 22px; line-height: 1.2; }
  .wn-list strong { color: var(--gold); font-weight: 600; font-size: 15px; }
  .wn-list p { margin: 3px 0 0; font-size: 13px; color: var(--ink-dim); line-height: 1.6; }
  .wn-list a { display: inline-block; margin-top: 6px; font-size: 13px; color: var(--gold); }
  .wn-ok { min-width: 160px; }
</style>
@endunless
