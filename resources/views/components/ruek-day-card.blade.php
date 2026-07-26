@props(['day', 'rank' => null, 'featured' => false])
{{--
  การ์ดวันมงคลหนึ่งใบ — รับ "รูปแบบ payload" (วันที่/เวลาเป็นสตริง) เสมอ
  เพื่อให้หน้าที่เปิดตอนซื้อกับหน้าที่เปิดย้อนหลังใช้โค้ดชุดเดียวกันเป๊ะ
  ถ้ารับ Carbon บ้าง สตริงบ้าง สุดท้ายจะมีหน้าใดหน้าหนึ่งพังโดยไม่มีใครรู้
--}}
@php
  $ruek    = $day['ruek'] ?? [];
  $tithi   = $day['tithi'] ?? [];
  $weekday = $day['weekday'] ?? [];
  $color   = $ruek['color'] ?? 'var(--gold)';
  $grade   = $day['grade'] ?? 'fair';
  $gradeLabel = \App\Services\AuspiciousScorer::gradeLabel($grade);
  $hasSlot = ! empty($day['best_from']) && ! empty($day['best_to']);
  $pad     = $featured ? '30px' : '22px';
@endphp

<div class="panel" style="padding:{{ $pad }};border-color:{{ $color }}33;position:relative;overflow:hidden">
  {{-- แถบสีฤกษ์ด้านซ้าย ทำให้กวาดตาเทียบหลายวันได้เร็ว --}}
  <div style="position:absolute;left:0;top:0;bottom:0;width:4px;background:{{ $color }}"></div>

  <div style="display:flex;gap:{{ $featured ? '26px' : '18px' }};align-items:flex-start;flex-wrap:wrap">
    <div style="flex:1;min-width:200px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
        @if ($rank)
          <span style="font-family:var(--display);font-size:10px;letter-spacing:.16em;background:{{ $color }};color:#14100c;padding:3px 9px;border-radius:999px;font-weight:600">อันดับ {{ $rank }}</span>
        @endif
        <span style="font-family:var(--display);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:{{ $color }};border:1px solid {{ $color }}66;padding:3px 9px;border-radius:999px">{{ $gradeLabel }}</span>
      </div>

      <div style="font-family:var(--display);font-size:{{ $featured ? '38px' : '26px' }};color:var(--moon);line-height:1.15">
        {{ $day['label'] ?? $day['date'] }}
      </div>

      <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;align-items:center">
        <span style="display:inline-flex;align-items:center;gap:6px;color:{{ $weekday['color'] ?? 'var(--ink-dim)' }};font-size:14px">
          <span style="font-size:17px">{{ $weekday['glyph'] ?? '' }}</span> วาร{{ $weekday['name'] ?? '' }}
        </span>
        <span style="color:var(--ink-dim);font-size:14px">🌙 {{ $tithi['label'] ?? '' }}</span>
        <span style="color:var(--ink-dim);font-size:14px">✦ นักษัตร{{ $day['nakshatra'] ?? '' }}</span>
      </div>

      <div style="margin-top:14px;padding:12px 14px;border-radius:10px;background:{{ $color }}14;border:1px solid {{ $color }}2e">
        <div style="font-family:var(--display);font-size:15px;color:{{ $color }};letter-spacing:.02em">
          {{ $ruek['name'] ?? '' }} <span style="color:var(--ink-faint);font-size:12px">· {{ $ruek['deity'] ?? '' }}</span>
        </div>
        <div style="color:var(--ink-dim);font-size:13.5px;line-height:1.75;margin-top:4px">{{ $ruek['summary'] ?? '' }}</div>
      </div>

      @if ($hasSlot)
        <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="font-family:var(--display);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--ink-faint)">ช่วงที่ฤกษ์ครอง</span>
          <span style="font-family:var(--display);font-size:19px;color:var(--gold)">{{ $day['best_from'] }} – {{ $day['best_to'] }} น.</span>
        </div>
        @php
          // แถบเวลา 06:00–18:00 ให้เห็นว่าฤกษ์กินช่วงไหนของวัน
          [$fh, $fm] = array_map('intval', explode(':', $day['best_from']));
          [$th, $tm] = array_map('intval', explode(':', $day['best_to']));
          $startPct = max(0, min(100, (($fh * 60 + $fm) - 360) / 720 * 100));
          $endPct   = max(0, min(100, (($th * 60 + $tm) - 360) / 720 * 100));
        @endphp
        <div style="margin-top:8px">
          <div style="position:relative;height:9px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden">
            <div style="position:absolute;left:{{ round($startPct, 2) }}%;width:{{ round(max($endPct - $startPct, 1.5), 2) }}%;top:0;bottom:0;background:{{ $color }};border-radius:999px"></div>
          </div>
          <div style="display:flex;justify-content:space-between;color:var(--ink-faint);font-size:10px;font-family:var(--display);letter-spacing:.1em;margin-top:4px">
            <span>06:00</span><span>12:00</span><span>18:00</span>
          </div>
        </div>
      @endif
    </div>

    <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:12px">
      <x-score-ring :pct="$day['score_pct'] ?? 0" :color="$color" :size="$featured ? 108 : 84" caption="คะแนนฤกษ์" />
      <x-moon-phase :illumination="$tithi['illumination'] ?? 0.5"
                    :waxing="($tithi['side'] ?? 'waxing') === 'waxing'"
                    :size="$featured ? 54 : 40"
                    :label="$tithi['label'] ?? null" />
    </div>
  </div>

  @if ($featured && (! empty($day['reasons']) || ! empty($day['warnings'])))
    <div style="margin-top:20px;padding-top:18px;border-top:1px solid rgba(255,255,255,.08);display:grid;gap:8px">
      @foreach (($day['reasons'] ?? []) as $reason)
        <div style="display:flex;gap:10px;align-items:flex-start;color:var(--ink);font-size:14px;line-height:1.8">
          <span style="color:#5f9e6e;flex-shrink:0">✓</span><span>{{ $reason }}</span>
        </div>
      @endforeach
      @foreach (($day['warnings'] ?? []) as $warning)
        <div style="display:flex;gap:10px;align-items:flex-start;color:var(--ink-dim);font-size:14px;line-height:1.8">
          <span style="color:#d9a441;flex-shrink:0">⚠</span><span>{{ $warning }}</span>
        </div>
      @endforeach
    </div>
  @endif
</div>
