@extends('layouts.app')
@section('title', 'จันทราพยากรณ์ · Juntra Payakorn')
@section('description', 'พยากรณ์สายมูเตลู — ไพ่ยิปซี ดวงรายเดือน ฤกษ์มงคล AI Chat ดูดวง บรรยากาศมีมนต์ขลัง')

@section('content')

<section class="hero" id="top">
  <div class="hero-bg">
    <img src="{{ asset('images/juntra/hero-bg.png') }}" alt="">
  </div>
  <div class="hero-orbit" aria-hidden="true">
    <div class="ring"></div>
    <div class="ring r2"></div>
    <div class="ring r3"></div>
  </div>
  <div class="hero-inner">
    <div class="hero-eyebrow"><span class="dot"></span> ศาสตร์แห่งโหราศาสตร์ &amp; มูเตลู</div>
    <h1 style="padding:50px 0 14px">
      เปิดประตู<span class="accent" style="margin:0 20px 0 0"> โชคชะตา </span><br>
      ด้วยพลังแห่งดวงดาว
    </h1>
    <p class="hero-sub">
      เราคือบ้านของหมอดูสายมู ผู้เชื่อมจิตวิญญาณกับจักรวาล —
      อ่านดวง ไพ่ยิปซี ฤกษ์ยาม และพิธีเสริมดวง<br>
      ในบรรยากาศ <em class="thai-italic">มีมนต์ขลัง</em> ที่คุณจะสัมผัสได้ตั้งแต่ก้าวแรก
    </p>
    <div class="hero-cta">
      <a href="{{ route('tarot.index') }}" class="btn btn-primary">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v4M12 18v4M2 12h4M18 12h4M5 5l3 3M16 16l3 3M5 19l3-3M16 8l3-3"/><circle cx="12" cy="12" r="3"/></svg>
        เปิดไพ่ยิปซีออนไลน์
      </a>
      <a href="#services" class="btn btn-ghost">ดูบริการทั้งหมด →</a>
    </div>
  </div>
  <div class="scroll-cue" aria-hidden="true">
    <span>SCROLL</span>
    <div class="line"></div>
  </div>
</section>

<section class="section" id="services">
  <div class="section-head reveal">
    <div class="section-eyebrow">Our Divinations</div>
    <h2 class="section-title" style="padding:30px 0 7px">ศาสตร์พยากรณ์ที่เปิดให้ท่าน</h2>
    <p class="section-sub">ทุกคำตอบมีอยู่ในจักรวาลแล้ว — เราเพียงช่วยท่านอ่านสัญญาณ</p>
  </div>

  <div class="services">
    <div class="svc reveal">
      <div class="svc-icon">✦</div>
      <h3>ไพ่ยิปซี</h3>
      <div class="svc-en">Tarot Reading</div>
      <p>เปิดไพ่ 3 ใบ หรือ Celtic Cross 10 ใบ — อดีต ปัจจุบัน อนาคต พร้อมคำพยากรณ์ AI ที่ละเอียดอ่อน</p>
      <a href="{{ route('tarot.index') }}" class="svc-link">เปิดไพ่ตอนนี้ →</a>
    </div>
    <div class="svc reveal">
      <div class="svc-icon">☽</div>
      <h3>ดวงรายวัน</h3>
      <div class="svc-en">Moon Horoscope</div>
      <p>พยากรณ์ตามฤกษ์จันทรคติ ครอบคลุม ความรัก การเงิน การงาน และสุขภาพจิตวิญญาณ ทั้ง 12 ราศี</p>
      <a href="{{ route('horoscope.index') }}" class="svc-link">อ่านเพิ่มเติม →</a>
    </div>
    <div class="svc reveal">
      <div class="svc-icon">☉</div>
      <h3>ฤกษ์มงคล</h3>
      <div class="svc-en">Auspicious Time</div>
      <p>หาฤกษ์เปิดร้าน แต่งงาน ขึ้นบ้านใหม่ ทำพิธี ตามดวงดาวและธาตุประจำตัวท่าน</p>
      <a href="{{ route('auspicious.index') }}" class="svc-link">ปรึกษาฤกษ์ →</a>
    </div>
    <div class="svc reveal">
      <div class="svc-icon">♅</div>
      <h3>เลขศาสตร์ &amp; ลายมือ</h3>
      <div class="svc-en">Numerology &amp; Palmistry</div>
      <p>คำนวณเลขชะตา เลขนาม จากชื่อและวันเกิด พร้อมวิเคราะห์ลายมือผ่าน AI Vision</p>
      <a href="{{ route('numerology.index') }}" class="svc-link">วิเคราะห์ดวง →</a>
    </div>
  </div>
</section>

{{-- 🎬 คลิปบรรยายแผนสร้างรายได้ — บอทแม่หมอส่งลิงก์มาลงที่ #plan --}}
<section class="section" id="plan">
  <div class="section-head reveal">
    <div class="section-eyebrow">Affiliate Plan · แผนสร้างรายได้</div>
    <h2 class="section-title" style="padding:30px 0 7px">ชวนเพื่อนดูดวง — ได้ <em>ค่าแนะนำ</em></h2>
    <p class="section-sub">คลิปเดียว เข้าใจครบทุกขั้นตอน ตั้งแต่เริ่มจนถอนเงินเข้าบัญชี</p>
  </div>

  <div class="plan-video-wrap reveal">
    {{-- preload="none" + poster: หน้าแรกโหลดรูปหนักอยู่แล้ว ห้ามให้คลิปดูดเน็ตซ้ำ --}}
    <video
      controls
      playsinline
      preload="none"
      poster="{{ asset('images/plan-video-poster.jpg') }}"
      controlsList="nodownload">
      <source src="{{ asset('videos/juntra-affiliate-plan.mp4') }}" type="video/mp4">
      เบราว์เซอร์ของคุณไม่รองรับการเล่นวิดีโอ — <a href="{{ asset('videos/juntra-affiliate-plan.mp4') }}">กดที่นี่เพื่อดาวน์โหลดคลิป</a>
    </video>
  </div>

  {{-- ⚠️ ห้ามฝังตัวเลขค่าแนะนำตรงนี้เด็ดขาด
       เว็บนี้อ่าน fortune_telling_settings ของ Thaiprompt ไม่ได้ (คนละระบบ ไม่มี model)
       ถ้าฝังเลขไว้ แล้วแอดมินปรับเรตในหน้าแอดมินวันหลัง → หน้านี้จะโฆษณาเลขผิดถาวร
       โดยไม่มีอะไรเตือน. ให้คลิป (ซึ่งอัดใหม่ได้เมื่อเรตเปลี่ยน) เป็นคนบอกตัวเลขแทน --}}
  <div class="plan-video-meta reveal">
    <span class="plan-chip">⏱ <b>5 นาที</b> จบครบ</span>
    <span class="plan-chip">ค่าแนะนำ<b>ตามแพคเกจ</b>ที่เพื่อนเลือก</span>
    <span class="plan-chip">ค่าแนะนำ <b>2 ชั้น</b> · ไม่มีเพดาน</span>
    <span class="plan-chip">โอนเข้าบัญชีรอบ <b>วันที่ 2 และ 17</b></span>
  </div>

  <p class="plan-note">
    ตัวเลขในคลิปเป็นตัวอย่างการคำนวณ ไม่ใช่การรับประกันรายได้ —
    รายได้จริงขึ้นกับจำนวนเพื่อนที่ใช้บริการจริง · ถอนเงินต้องยืนยันตัวตน (KYC) ด้วยบัตรประชาชนและบัญชีธนาคารชื่อเดียวกัน
  </p>
</section>

<section class="section" id="featured">
  <div class="featured">
    <div class="featured-art reveal">
      <div class="ring-deco"></div>
      <div class="ring-deco r2"></div>
      <div class="moon-frame"></div>
      <img class="a1" src="{{ asset('images/juntra/ornament.png') }}" alt="ornament">
    </div>
    <div class="featured-text reveal">
      <div class="section-eyebrow">Why Juntra Payakorn</div>
      <h2 style="padding:40px 0 6px;line-height:1.8">เพราะคำพยากรณ์ที่ดี<br>ต้องมาจากใจที่<em class="thai-italic">นิ่ง</em></h2>
      <p>เราไม่ใช่หมอดูทำนายอนาคตให้กลัว — แต่เป็นเพื่อนคู่ใจที่ช่วยให้ท่านเข้าใจตนเอง ผ่านศาสตร์ที่สั่งสมมานานนับศตวรรษ</p>
      <ul class="featured-list">
        <li>หมอดูประสบการณ์มากกว่า 15 ปี ทุกคนเป็นผู้ฝึกตน ไม่ขายความกลัว</li>
        <li>คำทำนายที่อิงข้อเท็จจริงและจิตวิทยา ไม่หลอกลวง</li>
        <li>บรรยากาศปลอดภัย ข้อมูลเป็นความลับ ตลอดเวลา</li>
        <li>เปิดบริการออนไลน์และที่สำนักจริง ครบทุกช่องทาง</li>
      </ul>
      <a href="#voices" class="btn btn-ghost">อ่านเสียงจากผู้ใช้บริการ →</a>
    </div>
  </div>
</section>

<section class="section" id="zodiac-quick">
  <div class="section-head reveal">
    <div class="section-eyebrow">Zodiac · 12 ราศี</div>
    <h2 class="section-title" style="padding:30px 0 7px">เลือกราศี — อ่านดวงประจำวัน</h2>
  </div>
  <div class="zodiac-list" style="grid-template-columns:repeat(6,1fr);max-width:980px;margin:0 auto;gap:10px">
    @foreach (($zodiacs ?? collect()) as $z)
      <a href="{{ route('horoscope.show', $z->slug) }}" class="z reveal">
        <span class="glyph">{{ $z->glyph }}</span>
        <span class="name">{{ $z->name_th }}</span>
      </a>
    @endforeach
  </div>
</section>

<section class="section" id="voices">
  <div class="section-head reveal">
    <div class="section-eyebrow">Voices of Believers</div>
    <h2 class="section-title" style="padding:30px 0 7px">เสียงจากผู้ที่เคยเปิดดวง</h2>
  </div>
  <div class="testimonials">
    @php
      $tList = $testimonials ?? collect([
        (object)['name'=>'คุณปริยา จ.','service'=>'ผู้ใช้บริการไพ่ยิปซี','rating'=>5,'message'=>'คำทำนายเรื่องการงานแม่นมาก หมอใจดีมากค่ะ ฟังจบแล้วใจสงบ ไม่ตื่นตระหนกเหมือนที่เคยไปมา รู้สึกได้แนวทางจริง ๆ'],
        (object)['name'=>'คุณกฤษฎา ส.','service'=>'ลูกค้าออนไลน์','rating'=>5,'message'=>'เปิดไพ่ออนไลน์ครั้งแรกในชีวิต ไม่คิดว่าจะตรงขนาดนี้ ภาพไพ่สวยมาก เหมือนนั่งอยู่ในโต๊ะของหมอดูจริง ๆ'],
        (object)['name'=>'คุณณัฐริกา ม.','service'=>'เจ้าของร้านคาเฟ่','rating'=>5,'message'=>'จัดฤกษ์เปิดร้านให้ค่ะ ตั้งแต่เปิดมาเรื่อย ๆ ดีขึ้นทุกเดือน ขอบคุณอาจารย์มาก ๆ ปังจริง ค่ะ'],
      ]);
    @endphp
    @foreach ($tList as $t)
      <div class="t-card reveal">
        <div class="t-stars">{!! str_repeat('★', (int) $t->rating) !!}</div>
        <p>{{ $t->message }}</p>
        <div class="t-meta">
          <div class="t-avatar">{{ mb_substr($t->name, 3, 1) ?: mb_substr($t->name, 0, 1) }}</div>
          <div>
            <div class="t-name">{{ $t->name }}</div>
            <div class="t-role">{{ $t->service }}</div>
          </div>
        </div>
      </div>
    @endforeach
  </div>
</section>

<section class="section" id="cta">
  <div class="cta-strip reveal">
    <div class="section-eyebrow">Open the Cards</div>
    <h2>พร้อมแล้วหรือยัง<br>ที่จะรู้คำตอบ</h2>
    <p>{{ auth()->check() ? 'ยินดีต้อนรับ ' . auth()->user()->name . ' — เลือกศาสตร์ที่ใจคุณเรียกหา' : 'เปิดโต๊ะไพ่ยิปซี 3 ใบ — ฟรี ไม่ต้องสมัคร พร้อมคำพยากรณ์ละเอียดจาก AI โหรา' }}</p>
    @auth
      <a href="{{ route('tarot.index') }}" class="btn btn-primary">เปิดไพ่ Celtic Cross
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    @else
      <a href="{{ route('tarot.index') }}" class="btn btn-primary">เริ่มเปิดไพ่ทันที
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
    @endauth
  </div>
</section>

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: .12 });
    document.querySelectorAll('.reveal').forEach(el => io.observe(el));
  });
</script>
@endpush

@endsection
