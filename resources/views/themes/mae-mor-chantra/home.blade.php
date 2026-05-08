@extends('layouts.app')

@section('title', config('app.name', 'แม่หมอจันทรา'))
@section('description', 'ทำนายดวงด้วย AI ศักดิ์สิทธิ์ — ไพ่ยิปซี Celtic Cross, ดวงรายวัน 12 ราศี, เลขศาสตร์, ลายมือ, ฤกษ์ยาม, AI Chat ดูดวง')

@section('content')

<section class="canvas hero" id="hero">
  <svg class="ornament orn-tl" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width=".4">
    <circle cx="50" cy="50" r="48"/>
    <circle cx="50" cy="50" r="36"/>
    <path d="M50 2 L52 50 L50 98 L48 50 Z M2 50 L50 52 L98 50 L50 48 Z"/>
    <circle cx="50" cy="50" r="4" fill="currentColor"/>
  </svg>
  <svg class="ornament orn-br" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width=".4">
    <polygon points="50,2 61,38 98,38 68,60 80,96 50,74 20,96 32,60 2,38 39,38"/>
    <circle cx="50" cy="56" r="20"/>
  </svg>

  <div class="hero-copy">
    <div class="hero-thai-mark">· ศาสตร์แห่งจันทรา · ตั้งแต่อดีตจนปัจจุบัน ·</div>
    <h1 class="display">
      <span class="line"><span>ทำนายดวง</span></span>
      <span class="line"><span><em>ด้วยพลัง</em></span></span>
      <span class="line"><span>แห่ง AI &amp; ไพ่ยิปซี</span></span>
    </h1>
    <p class="lede hero-lede">แม่หมอจันทรา ผสานศาสตร์ดวงดาวโบราณกับปัญญาประดิษฐ์ล้ำสมัย อ่านดวงชะตามนุษย์จากอดีต ปัจจุบัน สู่อนาคต ด้วยความแม่นยำที่เหนือคำบรรยาย</p>
    <div class="cta-row">
      <a href="{{ route('tarot.index') }}" class="btn btn-primary">เริ่มดูดวงฟรี
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
      <a href="#services" class="btn btn-ghost">ดูบริการทั้งหมด</a>
    </div>
    <div class="hero-stats">
      <div class="stat"><b>{{ $stats['readings'] ?? '250K+' }}</b><span>ครั้งที่ทำนาย</span></div>
      <div class="stat"><b>{{ $stats['accuracy'] ?? '97.8%' }}</b><span>ความแม่นยำ</span></div>
      <div class="stat"><b>{{ $stats['cards'] ?? '78' }}</b><span>ไพ่ยิปซีครบชุด</span></div>
    </div>
  </div>

  <div class="hero-visual">
    <div class="aura">
      <div class="ring r2"></div>
      <div class="ring r1"></div>
      <div class="ring r3"></div>
    </div>
    <img src="{{ asset('images/hero-chantra.png') }}" class="chantra-portrait" alt="แม่หมอจันทรา">
    <div class="float-card fc1"><img src="{{ asset('images/card-magician.png') }}" alt=""></div>
    <div class="float-card fc2"><img src="{{ asset('images/card-world.png') }}" alt=""></div>
    <div class="float-card fc3"><img src="{{ asset('images/card-sun.png') }}" alt=""></div>
  </div>
</section>

<div class="marquee">
  <div class="marquee-track">
    <span>· The Magician <i></i> The Sun <i></i> The World <i></i> The Moon <i></i> The Star <i></i> Wheel of Fortune <i></i> Lovers <i></i> The Empress <i></i></span>
    <span>· The Magician <i></i> The Sun <i></i> The World <i></i> The Moon <i></i> The Star <i></i> Wheel of Fortune <i></i> Lovers <i></i> The Empress <i></i></span>
  </div>
</div>

<section class="canvas" id="ai">
  <div class="ai-grid">
    <div class="ai-viz">
      <div class="ai-orb"></div>
      <div class="ai-rune r1">✦ ASTRA ✦</div>
      <div class="ai-rune r2">✧ LUNA ✧</div>
      <div class="ai-rune r3">✦ FATUM ✦</div>
      <div class="ai-rune r4">✧ ORACLE ✧</div>
    </div>
    <div>
      <div class="eyebrow">AI Oracle Engine</div>
      <h2 class="section-title">ปัญญาประดิษฐ์ <em>ที่หยั่งรู้</em> ดวงชะตามนุษย์</h2>
      <p class="lede" style="margin-top:24px">ระบบ AI ของแม่หมอจันทราเรียนรู้จากตำราโหราศาสตร์โบราณกว่า 3,000 ปี ผสานข้อมูลดวงชะตาจริงกว่า 250,000 ดวง วิเคราะห์ตำแหน่งดาวเคราะห์ ราศี เรือนชะตา และไพ่ยิปซี ในเสี้ยววินาที</p>

      <div class="feature-list">
        <div class="feature"><div class="feature-num">01</div><div class="feature-body"><h3>วิเคราะห์ดวงชะตา 360°</h3><p>ตั้งแต่ดวงกำเนิด ดวงปัจจุบัน ทรานสิตดาว ไปจนถึงดวงคู่สมพงศ์ — ครบทุกมุมของชีวิตในที่เดียว</p></div></div>
        <div class="feature"><div class="feature-num">02</div><div class="feature-body"><h3>เรียนรู้จากปรมาจารย์</h3><p>เทรนด้วยตำราโหราศาสตร์ตะวันตก ตะวันออก และไพ่ยิปซี Rider-Waite ฉบับสมบูรณ์ จากหลายสำนัก</p></div></div>
        <div class="feature"><div class="feature-num">03</div><div class="feature-body"><h3>คำพยากรณ์เฉพาะคุณ</h3><p>ไม่ใช่คำตอบสำเร็จรูป — AI สังเคราะห์คำทำนายเฉพาะบุคคล ภาษาเข้าใจง่าย พร้อมแนวทางแก้ไขและเสริมดวง</p></div></div>
        <div class="feature"><div class="feature-num">04</div><div class="feature-body"><h3>แม่นยำระดับ 97.8%</h3><p>ตรวจสอบจากผลย้อนหลัง 5 ปี เทียบเคียงกับเหตุการณ์จริงที่เกิดขึ้นกับผู้ใช้งานทั่วประเทศ</p></div></div>
      </div>
    </div>
  </div>
</section>

<section class="canvas reading" id="reading">
  <div class="reading-head">
    <div class="eyebrow" style="display:inline-flex">เลือกไพ่ของคุณ</div>
    <h2 class="section-title" style="margin:0 auto;text-align:center">สามใบ <em>สามชะตา</em><br>อดีต · ปัจจุบัน · อนาคต</h2>
    <p class="lede">หลับตา ตั้งจิตอธิษฐาน นึกถึงสิ่งที่อยากรู้ แล้วแตะที่ไพ่ทีละใบ ปล่อยให้พลังของจักรวาลนำทางคุณ</p>
  </div>

  <div class="reading-stage">
    @php
      $previewCards = [
        ['name' => 'THE MAGICIAN · I', 'img' => 'images/card-magician.png', 'meaning' => 'พลังสร้างสรรค์และการเริ่มต้นใหม่ที่กำลังจะเกิดขึ้น'],
        ['name' => 'THE SUN · XIX',     'img' => 'images/card-sun.png',      'meaning' => 'ความรุ่งโรจน์ ความสุข และพลังบวกที่ส่องสว่างชีวิตคุณ'],
        ['name' => 'THE WORLD · XXI',   'img' => 'images/card-world.png',    'meaning' => 'ความสำเร็จที่สมบูรณ์ การปิดวงจรเก่าเพื่อก้าวสู่บทใหม่'],
      ];
    @endphp
    @foreach ($previewCards as $card)
      <div class="tarot">
        <div class="tarot-glow"></div>
        <div class="face back">
          <div class="back-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width=".7">
              <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/>
              <path d="M12 2v20M2 12h20M5 5l14 14M19 5L5 19"/>
            </svg>
          </div>
        </div>
        <div class="face front">
          <img src="{{ asset($card['img']) }}" alt="">
          <div class="meaning">{{ $card['meaning'] }}</div>
          <div class="label">{{ $card['name'] }}</div>
        </div>
      </div>
    @endforeach
  </div>

  <div class="reading-prompt"><b>คำใบ้</b> · แตะที่ไพ่เพื่อเปิด — ทำใจให้สงบก่อนเลือกแต่ละใบ</div>
  <div style="text-align:center;margin-top:48px">
    <a href="{{ route('tarot.index') }}" class="btn btn-primary">เริ่มเปิดไพ่จริง 78 ใบ
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
    </a>
  </div>
</section>

<section class="canvas" id="zodiac">
  <div class="zodiac-section">
    <div class="zodiac-wheel">
      <svg viewBox="-100 -100 200 200">
        <circle r="98" fill="none" stroke="rgba(244,207,106,.4)" stroke-width=".4"/>
        <circle r="86" fill="none" stroke="rgba(244,207,106,.2)" stroke-width=".3" stroke-dasharray="1 2"/>
        <circle r="62" fill="none" stroke="rgba(176,124,255,.4)" stroke-width=".3"/>
        <g stroke="rgba(244,207,106,.25)" stroke-width=".3">
          <line x1="0" y1="-98" x2="0" y2="-62"/>
          <line x1="84.9" y1="-49" x2="53.7" y2="-31"/>
          <line x1="98" y1="0" x2="62" y2="0"/>
          <line x1="84.9" y1="49" x2="53.7" y2="31"/>
          <line x1="49" y1="84.9" x2="31" y2="53.7"/>
          <line x1="0" y1="98" x2="0" y2="62"/>
          <line x1="-49" y1="84.9" x2="-31" y2="53.7"/>
          <line x1="-84.9" y1="49" x2="-53.7" y2="31"/>
          <line x1="-98" y1="0" x2="-62" y2="0"/>
          <line x1="-84.9" y1="-49" x2="-53.7" y2="-31"/>
          <line x1="-49" y1="-84.9" x2="-31" y2="-53.7"/>
        </g>
        <g font-family="Cinzel, serif" font-size="11" fill="#f4cf6a" text-anchor="middle" dominant-baseline="middle">
          <text x="0" y="-78">♈</text><text x="39" y="-67.5">♉</text><text x="67.5" y="-39">♊</text>
          <text x="78" y="0">♋</text><text x="67.5" y="39">♌</text><text x="39" y="67.5">♍</text>
          <text x="0" y="78">♎</text><text x="-39" y="67.5">♏</text><text x="-67.5" y="39">♐</text>
          <text x="-78" y="0">♑</text><text x="-67.5" y="-39">♒</text><text x="-39" y="-67.5">♓</text>
        </g>
        <g fill="none" stroke="rgba(244,207,106,.5)" stroke-width=".3">
          <polygon points="0,-40 11.7,-12.4 38,-12.4 16.2,4.7 24.7,32 0,16 -24.7,32 -16.2,4.7 -38,-12.4 -11.7,-12.4"/>
          <circle r="40"/>
        </g>
      </svg>
      <div class="zodiac-center">
        <div class="moon-mark"><b>☾</b>CHANTRA<br>ZODIAC</div>
      </div>
    </div>
    <div>
      <div class="eyebrow">ราศีของคุณ · ZODIAC</div>
      <h2 class="section-title">ดวงดาวเขียนชะตา <em>ตั้งแต่</em> วันที่คุณเกิด</h2>
      <p class="lede" style="margin-top:24px">เลือกราศีของคุณเพื่ออ่านคำพยากรณ์ประจำเดือน — ความรัก การงาน การเงิน และสุขภาพ พร้อมเลขนำโชคและสีเสริมดวงเฉพาะวัน</p>

      <div class="zodiac-list">
        @foreach (($zodiacs ?? collect()) as $z)
          <a href="{{ route('horoscope.show', $z->slug) }}" class="z" style="text-decoration:none">
            <span class="glyph">{{ $z->glyph }}</span>
            <span class="name">{{ $z->name_th }}</span>
          </a>
        @endforeach
      </div>
    </div>
  </div>
</section>

<section class="canvas" id="services">
  <div style="text-align:center;max-width:760px;margin:0 auto 0">
    <div class="eyebrow" style="display:inline-flex">บริการของแม่หมอ</div>
    <h2 class="section-title" style="margin:0 auto;text-align:center">เลือกศาสตร์ <em>ที่ใจคุณเรียกหา</em></h2>
  </div>

  <div class="services-grid">
    <div class="service">
      <div class="price-tag">฿ FREE</div>
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="12" cy="12" r="10"/><path d="M12 7v5l3 3"/></svg></div>
      <div class="duration">~ 3 นาที</div>
      <h3>ดวงรายวัน 12 ราศี</h3>
      <p>คำพยากรณ์ประจำวัน พร้อมเลขนำโชค สีมงคล และไพ่ประจำวัน อ่านได้ทุกเช้าก่อนเริ่มวันใหม่</p>
      <a href="{{ route('horoscope.index') }}" class="arrow">เริ่มดูดวง →</a>
    </div>

    <div class="service" style="border-color:var(--gold);background:linear-gradient(180deg,rgba(244,207,106,.08),rgba(7,4,26,.4))">
      <div class="price-tag" style="background:var(--gold);color:#1a0d3d">POPULAR</div>
      <div class="icon" style="border-color:var(--gold);box-shadow:0 0 32px rgba(244,207,106,.4) inset, 0 0 32px rgba(244,207,106,.4)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M2 12 L7 7 L12 12 L17 7 L22 12 L17 17 L12 12 L7 17 Z"/><circle cx="12" cy="12" r="2.5" fill="currentColor"/></svg></div>
      <div class="duration">~ 15 นาที</div>
      <h3>ไพ่ยิปซี Celtic Cross</h3>
      <p>เปิด 10 ใบครบชุด Celtic Cross — อดีต ปัจจุบัน อนาคต ความหวัง ความกลัว ผลลัพธ์ พร้อม AI วิเคราะห์</p>
      <a href="{{ route('tarot.index') }}" class="arrow">เริ่มเปิดไพ่ →</a>
    </div>

    <div class="service">
      <div class="price-tag">฿ FREE</div>
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 12 12 3l9 9-9 9z"/><path d="M12 8v8"/></svg></div>
      <div class="duration">~ 5 นาที</div>
      <h3>เลขศาสตร์ + ลายมือ</h3>
      <p>คำนวณเลขนาม เลขชะตา จากวัน-เดือน-ปีเกิดและชื่อจริง พร้อมวิเคราะห์ลายมือผ่าน AI</p>
      <a href="{{ route('numerology.index') }}" class="arrow">วิเคราะห์ดวง →</a>
    </div>

    <div class="service">
      <div class="price-tag">฿ FREE</div>
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="12" cy="12" r="9"/><path d="M12 3v9l5 3"/></svg></div>
      <div class="duration">~ 2 นาที</div>
      <h3>ฤกษ์ยาม</h3>
      <p>หาฤกษ์ดี วันมงคล เวลามงคล สำหรับงานบุญ งานแต่ง เปิดร้าน หรือเริ่มต้นสิ่งสำคัญในชีวิต</p>
      <a href="{{ route('auspicious.index') }}" class="arrow">หาฤกษ์ →</a>
    </div>

    <div class="service" style="border-color:var(--orchid);background:linear-gradient(180deg,rgba(176,124,255,.1),rgba(7,4,26,.4))">
      <div class="price-tag" style="background:var(--orchid);color:#1a0d3d;border-color:var(--orchid)">AI</div>
      <div class="icon" style="border-color:var(--orchid);box-shadow:0 0 32px rgba(176,124,255,.4) inset, 0 0 32px rgba(176,124,255,.4)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M21 12c0 4.97-4.03 9-9 9-1.5 0-2.91-.37-4.15-1.02L3 21l1.02-4.85A8.96 8.96 0 0 1 3 12a9 9 0 1 1 18 0z"/></svg></div>
      <div class="duration">~ 10 นาที</div>
      <h3>AI Chat ดูดวง</h3>
      <p>คุยกับ "แม่หมอจันทรา AI" แบบ 1-on-1 ถามได้ทุกเรื่อง ตอบทันที ภาษาเข้าใจง่าย ไม่ขู่ ไม่กดดัน</p>
      <a href="{{ route('chat.index') }}" class="arrow">เริ่มสนทนา →</a>
    </div>

    <div class="service">
      <div class="price-tag">SOON</div>
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M5 21V5a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v16l-7-4z"/></svg></div>
      <div class="duration">รายงาน PDF</div>
      <h3>ดวงชะตาตลอดชีวิต</h3>
      <p>รายงานเชิงลึก 30+ หน้า แผนภูมิดาว 12 เรือน คำชี้แนะตามช่วงอายุ — เปิดบริการเร็ว ๆ นี้</p>
      <a href="#" class="arrow">รอเปิดบริการ →</a>
    </div>
  </div>
</section>

<section class="canvas testimonials" id="voices">
  <div style="text-align:center;max-width:760px;margin:0 auto 0">
    <div class="eyebrow" style="display:inline-flex">เสียงจากผู้ที่เคยใช้บริการ</div>
    <h2 class="section-title" style="margin:0 auto;text-align:center">คำพยากรณ์ <em>ที่เปลี่ยน</em> ชีวิตจริง</h2>
  </div>

  <div class="test-grid">
    @php
      $testimonials = $testimonials ?? collect([
        (object)['name'=>'คุณปิ่นมณี','service'=>'ดูดวงชะตาตลอดชีวิต','rating'=>5,'message'=>'แม่หมอจันทราทำนายว่าจะได้งานใหม่ภายใน 2 เดือน — เดือนต่อมาผมได้ออฟเฟอร์จากบริษัทในฝันจริงๆ ขนลุกตอนอ่านคำทำนายอีกรอบ'],
        (object)['name'=>'คุณนภัสวรรณ','service'=>'ไพ่ยิปซี Celtic Cross','rating'=>5,'message'=>'ใช้บริการมา 3 ครั้ง แม่นทุกครั้ง โดยเฉพาะเรื่องความรัก แม่หมอบอกว่าจะเจอคนที่ใช่ในเดือนกันยายน — ปรากฏว่าเจอจริงๆ ที่งานสัมมนา'],
        (object)['name'=>'คุณธีรพล','service'=>'AI Chat ดูดวง','rating'=>5,'message'=>'AI ของแม่หมอจันทราล้ำมาก อธิบายดวงดาวได้เข้าใจง่าย ไม่ใช้คำขู่หรือแสร้งให้กลัว — แนะนำทางออกชัดเจน'],
      ]);
    @endphp
    @foreach ($testimonials as $t)
      <div class="test">
        <div class="stars">{!! str_repeat('★', (int) $t->rating) !!}</div>
        <p>"{{ $t->message }}"</p>
        <div class="who"><div class="avatar">{{ mb_substr($t->name, 3, 1) ?: mb_substr($t->name, 0, 1) }}</div><div><b>{{ $t->name }}</b><span>{{ $t->service }}</span></div></div>
      </div>
    @endforeach
  </div>
</section>

<section style="padding:0 24px">
  <div class="cta-band">
    <div class="eyebrow" style="display:inline-flex">เริ่มต้นการเดินทางของคุณ</div>
    <h2>พร้อมเปิดประตู<br>สู่<em>ชะตาที่แท้จริง</em>?</h2>
    <p>{{ auth()->check() ? 'ยินดีต้อนรับ ' . auth()->user()->name . ' — เลือกศาสตร์ที่ต้องการเริ่มดูดวงได้ทันที' : 'สมัครสมาชิกฟรี วันนี้ บันทึกประวัติการดูดวง รับคำพยากรณ์ประจำสัปดาห์ส่งตรงถึงคุณทุกวันจันทร์' }}</p>
    <div class="cta-row">
      @auth
        <a href="{{ route('tarot.index') }}" class="btn btn-primary">เปิดไพ่ Celtic Cross
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
        <a href="{{ route('chat.index') }}" class="btn btn-ghost">พูดคุยกับแม่หมอ AI</a>
      @else
        <a href="{{ route('register') }}" class="btn btn-primary">สมัครสมาชิกฟรี
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
        <a href="{{ route('tarot.index') }}" class="btn btn-ghost">ลองเปิดไพ่ก่อน</a>
      @endauth
    </div>
  </div>
</section>

@endsection
