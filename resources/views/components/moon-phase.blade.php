@props(['illumination' => 0.5, 'waxing' => true, 'size' => 48, 'label' => null])
{{--
  พระจันทร์ตามดิถีจริงของวันนั้น — วาดจากค่าส่องสว่างที่ ThaiAstro คำนวณได้
  ไม่ใช่ไอคอนสำเร็จรูป จึงตรงกับ "ขึ้น/แรม กี่ค่ำ" ที่เขียนกำกับไว้เสมอ

  วิธีวาด: จานมืดเต็มดวง แล้วครอบส่วนสว่างด้วย path 2 ส่วน — ขอบนอกครึ่งวงกลม
  (คงที่) + ขอบในที่เป็นวงรีซึ่งความกว้างแปรตามค่าส่องสว่าง ทำให้ได้เสี้ยวจริง
  ตั้งแต่จันทร์ดับจนเพ็ญโดยไม่ต้องใช้รูปภาพ
--}}
@php
  $r      = 100;
  $ill    = max(0.0, min(1.0, (float) $illumination));
  // ครึ่งความกว้างของ terminator: 0 = ครึ่งดวงพอดี, 1 = ขอบตรงข้าม
  $k      = abs(2 * $ill - 1) * $r;
  // ส่วนสว่างอยู่ขวาเมื่อข้างขึ้น (ซีกโลกเหนือ) และอยู่ซ้ายเมื่อข้างแรม
  $sweepOuter = $waxing ? 1 : 0;
  // เมื่อสว่างเกินครึ่ง ขอบในโค้งออกทางเดียวกับขอบนอก
  $sweepInner = $ill > 0.5 ? $sweepOuter : 1 - $sweepOuter;
  $uid    = 'mn'.substr(md5($ill.'-'.($waxing ? 'w' : 'n').'-'.$size), 0, 6);
@endphp
<svg viewBox="-110 -110 220 220" width="{{ $size }}" height="{{ $size }}" role="img"
     aria-label="{{ $label ?? 'ดวงจันทร์ ส่องสว่าง '.round($ill * 100).'%' }}"
     {{ $attributes }}>
  <defs>
    <radialGradient id="{{ $uid }}" cx="38%" cy="34%">
      <stop offset="0%"   stop-color="#fffdf3"/>
      <stop offset="70%"  stop-color="#f2e6c4"/>
      <stop offset="100%" stop-color="#d8c48d"/>
    </radialGradient>
  </defs>
  <circle r="{{ $r }}" fill="rgba(255,255,255,.06)" stroke="rgba(224,182,66,.35)" stroke-width="3"/>
  @if ($ill > 0.005)
    <path fill="url(#{{ $uid }})"
          d="M 0 -{{ $r }}
             A {{ $r }} {{ $r }} 0 0 {{ $sweepOuter }} 0 {{ $r }}
             A {{ $k }} {{ $r }} 0 0 {{ $sweepInner }} 0 -{{ $r }} Z"/>
  @endif
  <circle r="{{ $r }}" fill="none" stroke="rgba(224,182,66,.5)" stroke-width="3"/>
</svg>
