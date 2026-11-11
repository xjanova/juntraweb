{{-- เรื่องราวการดูดวงออนไลน์ (เจ้าของสั่ง 2026-11-04 "เพิ่มคุณค่าเว็บให้ google มองว่ามีเนื้อหาที่คู่ควร")
     ภาพ + คำอธิบายว่าบริการจริงช่วยในสถานการณ์ไหน — เขียนตามที่ระบบทำได้จริงเท่านั้น
     (แชทกับแม่หมอฟรี · เปิดไพ่จากสำรับ 78 ใบ · จ่ายเฉพาะตอนเปิดไพ่) ห้ามอ้างบริการที่ปิดอยู่
     การ์ดที่ยังไม่มีไฟล์ภาพจะถูกข้าม ไม่ครบ 3 ใบ = ไม่แสดงทั้งส่วน --}}
@php
  // 🌸 (2026-11-11) เรื่องที่ชวนเปิดไพ่ชุดไหน ต้องพาไปห้องและเอ่ยชื่อผู้อ่านไพ่ชุดนั้นจริง (ไพ่ไม่ถึง 9 ใบ = บุษบาเมื่อห้องเปิด)
  $reader = fn (string $spread) => \App\Support\ChatRooms::readerName($spread);
  $team = \App\Support\ChatRooms::roomForSpread('single') === \App\Support\ChatRooms::DEFAULT ? 'แม่หมอ' : 'แม่หมอกับ' . \App\Support\ChatRooms::readerName('single');
  $maemorChat = route('chat.index', ['room' => \App\Support\ChatRooms::DEFAULT]);
  $stories = collect([
      ['img' => 'story-video-call', 'eyebrow' => 'ทุกที่ ทุกเวลา', 'title' => 'ปรึกษาแม่หมอได้จากมือถือ',
       'body' => 'นอนไม่หลับเพราะเรื่องค้างในใจตอนดึก ก็เปิดห้องแชทคุยกับแม่หมอได้ทันที เห็นแม่หมอบนจอและฟังเสียงตอบได้ ไม่ต้องเดินทาง ไม่ต้องนัดคิว — ' . \App\Support\Seo::chatPriceText(),
       'href' => $maemorChat, 'cta' => \App\Support\Seo::chatIsFree() ? 'คุยกับแม่หมอฟรี' : 'คุยกับแม่หมอ'],
      ['img' => 'story-meeting', 'eyebrow' => 'การงาน · ธุรกิจ', 'title' => 'ก่อนตัดสินใจเรื่องใหญ่ของทีม',
       'body' => 'จะเปิดตัวสินค้า เซ็นสัญญา หรือขยายกิจการ เปิดไพ่ถามเรื่องการงานเพื่อดูแรงหนุน อุปสรรค และจังหวะที่ควรระวัง ' . $reader('career') . 'อ่านไพ่ทีละตำแหน่งให้เห็นภาพชัดก่อนลงมือ',
       'href' => route('tarot.index', ['spread' => 'career']), 'cta' => 'เปิดไพ่การงาน'],
      ['img' => 'story-couple', 'eyebrow' => 'ความรัก', 'title' => 'ถามเรื่องหัวใจไปด้วยกัน',
       'body' => 'คู่รักหลายคู่เปิดไพ่ความรักด้วยกัน เลือกไพ่ด้วยมือของตัวเองจากสำรับ 78 ใบ แล้วอ่านคำทำนายที่จัดเป็นการ์ดอ่านง่าย คุยกันต่อได้ว่าความสัมพันธ์ควรไปทางไหน',
       'href' => route('tarot.index', ['spread' => 'love']), 'cta' => 'เปิดไพ่ความรัก'],
      ['img' => 'story-cafe', 'eyebrow' => 'การเงิน · ค้าขาย', 'title' => 'เจ้าของร้านถามเรื่องเงินทองและโชคลาภ',
       'body' => 'ยอดขายนิ่ง หรือกำลังคิดลงทุนเพิ่ม ถามแม่หมอเรื่องการเงินได้ตรง ๆ แม่หมอฟันธงให้ชัดและบอกสิ่งที่ควรทำต่อ ไม่ขายความกลัว',
       'href' => $maemorChat, 'cta' => 'ถามแม่หมอ'],
      ['img' => 'story-studio', 'eyebrow' => 'เบื้องหลังคำทำนาย', 'title' => $team . 'อ่านไพ่ให้ท่านอย่างไร',
       'body' => 'ทุกคำทำนายเริ่มจากไพ่ที่ท่านเลือกเอง ' . $team . 'อ่านความหมายของไพ่คู่กับตำแหน่งที่วางทีละใบ แล้วสรุปเป็นคำตอบของคำถามที่ท่านตั้งไว้ หักเครดิตเฉพาะตอนเปิดไพ่เท่านั้น',
       'href' => route('tarot.free'), 'cta' => 'ลองดูดวงฟรี 1 ใบ'],
      ['img' => 'story-family', 'eyebrow' => 'ครอบครัว', 'title' => 'ดูดวงสนุก ๆ กับคนที่บ้าน',
       'body' => 'ดูดวงได้ทั้งครอบครัว สมาชิกแต่ละคนสมัครบัญชีของตัวเอง ประวัติคำทำนายเก็บแยกเป็นความลับ ย้อนกลับมาอ่านได้ตลอด',
       'href' => route('tarot.free'), 'cta' => 'ดูดวงฟรี 1 ใบ'],
  ])->filter(fn ($s) => file_exists(public_path("images/juntra/stories/{$s['img']}.webp")))->values();
@endphp
@if ($stories->count() >= 3)
<section {{ $attributes->merge(['class' => 'section online-stories']) }} id="stories">
  <div class="section-head reveal">
    <div class="section-eyebrow">Online Readings · เรื่องราวจากการดูดวงออนไลน์</div>
    <h2 class="section-title" style="padding:30px 0 7px">ดูดวงออนไลน์ ได้ทุกสถานการณ์ชีวิต</h2>
    <p class="section-sub">จะอยู่บ้าน ที่ทำงาน หรือในร้าน ก็ปรึกษาแม่หมอจันทราได้ผ่านมือถือ — ตัวอย่างสถานการณ์ที่ลูกค้าใช้บริการบ่อย</p>
  </div>
  <div class="story-grid">
    @foreach ($stories as $s)
      <article class="story-card reveal">
        <div class="story-art">
          <img src="{{ asset("images/juntra/stories/{$s['img']}.webp") }}"
               srcset="{{ asset("images/juntra/stories/{$s['img']}-600.webp") }} 600w, {{ asset("images/juntra/stories/{$s['img']}.webp") }} 1200w"
               sizes="(max-width: 720px) 92vw, (max-width: 1100px) 46vw, 380px"
               width="1200" height="800" loading="lazy" decoding="async" alt="{{ $s['title'] }} — {{ $s['eyebrow'] }}">
        </div>
        <div class="story-body">
          <div class="story-eyebrow">{{ $s['eyebrow'] }}</div>
          <h3>{{ $s['title'] }}</h3>
          <p>{{ $s['body'] }}</p>
          <a href="{{ $s['href'] }}" class="story-link">{{ $s['cta'] }} →</a>
        </div>
      </article>
    @endforeach
  </div>
</section>
@endif
