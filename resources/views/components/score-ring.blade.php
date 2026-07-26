@props(['pct' => 0, 'color' => '#e0b642', 'size' => 96, 'caption' => null])
{{--
  วงแหวนคะแนนฤกษ์ — เปอร์เซ็นต์เดียวกับที่ AuspiciousScorer คำนวณ
  ใช้ stroke-dasharray แทนการวาดเส้นโค้งเอง เพื่อให้ความยาวส่วนโค้งตรงกับ % เป๊ะ
--}}
@php
  $p    = max(0, min(100, (int) $pct));
  $r    = 42;
  $circ = 2 * M_PI * $r;
  $dash = round($circ * $p / 100, 2);
@endphp
<div {{ $attributes->merge(['style' => 'display:inline-flex;flex-direction:column;align-items:center;gap:4px']) }}>
  <svg viewBox="0 0 100 100" width="{{ $size }}" height="{{ $size }}" role="img" aria-label="คะแนนฤกษ์ {{ $p }} เต็ม 100">
    <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="rgba(255,255,255,.10)" stroke-width="8"/>
    <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="{{ $color }}" stroke-width="8"
            stroke-linecap="round" stroke-dasharray="{{ $dash }} {{ round($circ - $dash, 2) }}"
            transform="rotate(-90 50 50)"/>
    <text x="50" y="52" text-anchor="middle" dominant-baseline="middle"
          font-family="var(--display)" font-size="26" font-weight="600" fill="var(--moon)">{{ $p }}</text>
    <text x="50" y="68" text-anchor="middle" font-family="var(--display)" font-size="9"
          letter-spacing="1.5" fill="var(--ink-dim)">/ 100</text>
  </svg>
  @if ($caption)
    <div style="font-family:var(--display);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:{{ $color }}">{{ $caption }}</div>
  @endif
</div>
