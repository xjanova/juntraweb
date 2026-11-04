{{-- ใบอนุญาต DBD Registered บนหน้าเว็บ (เจ้าของสั่ง 2026-11-04) — สำเนาภาพ + ลิงก์ตรวจสอบของจริงที่กรมฯ --}}
@php
  $biz = config('business');
  $dbd = $biz['dbd'];
  $thaiDate = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->locale('th')->translatedFormat('j F') . ' ' . (\Illuminate\Support\Carbon::parse($d)->year + 543);
  // ภาพแม่หมอถือใบรับรอง — ยังไม่มีไฟล์ก็โชว์ใบรับรองเปล่า ๆ แทน (ไม่ปล่อยรูป 404)
  $hasShowcase = file_exists(public_path($dbd['showcase']));
  [$img, $imgW, $imgH] = $hasShowcase ? [$dbd['showcase'], 1200, 1500] : [$dbd['certificate'], 795, 1124];
@endphp
<section {{ $attributes->merge(['class' => 'section dbd-cert-section']) }} id="dbd-registered">
  <div class="dbd-cert reveal">
    <a class="dbd-cert-img{{ $hasShowcase ? ' is-showcase' : '' }}" href="{{ $dbd['verify_url'] }}" target="_blank" rel="noopener" title="เปิดใบรับรองจากกรมพัฒนาธุรกิจการค้า">
      <img src="{{ asset($img) }}" alt="{{ $hasShowcase ? 'แม่หมอจันทราถือใบรับรอง' : 'ใบรับรอง' }} DBD Registered ร้าน{{ $biz['shop_name'] }} จากกรมพัฒนาธุรกิจการค้า กระทรวงพาณิชย์"
           width="{{ $imgW }}" height="{{ $imgH }}" loading="lazy" decoding="async">
    </a>
    <div class="dbd-cert-text">
      <img class="dbd-cert-logo" src="{{ asset($dbd['badge']) }}" alt="" width="104" height="43" loading="lazy">
      <div class="section-eyebrow">Licensed · DBD Registered</div>
      <h2>จดทะเบียนพาณิชย์อิเล็กทรอนิกส์<br>กับกระทรวงพาณิชย์</h2>
      <p>ร้าน{{ $biz['shop_name'] }} ได้รับเครื่องหมาย DBD Registered จากกรมพัฒนาธุรกิจการค้า กระทรวงพาณิชย์ ยืนยันว่าเป็นร้านค้าออนไลน์ที่แจ้งจดทะเบียนถูกต้องตามกฎหมาย</p>
      <dl class="dbd-cert-facts">
        <div><dt>ชื่อร้าน</dt><dd>{{ $biz['shop_name'] }} ({{ $biz['shop_name_en'] }})</dd></div>
        <div><dt>เลขเครื่องหมาย</dt><dd>{{ $dbd['trustmark_id'] }}</dd></div>
        <div><dt>ได้รับเมื่อ</dt><dd>{{ $thaiDate($dbd['registered_at']) }}</dd></div>
        <div><dt>หมดอายุ</dt><dd>{{ $thaiDate($dbd['expires_at']) }}</dd></div>
      </dl>
      <a class="btn btn-ghost" href="{{ $dbd['verify_url'] }}" target="_blank" rel="noopener">ตรวจสอบกับกรมพัฒนาธุรกิจการค้า ↗</a>
    </div>
  </div>
</section>
