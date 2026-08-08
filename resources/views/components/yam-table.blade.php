@props([
  'yam',                 // อาเรย์ยามจาก payload: lord, day_seq, night_seq, picked, weights, in_ruek
  'title'   => null,
  'dateLabel' => null,
  'showNight' => true,
  'showWorking' => true,
  'highlight' => null,   // ['side'=>'day'|'night','no'=>1..8] — ใช้ไฮไลต์ "ยามนี้" บนหน้าเปิด
])
{{--
  ตารางยามอัฐกาลแบบที่โหรลงเลขจริง — ๘ ยามกลางวัน ๘ ยามกลางคืน ยามละชั่วโมงครึ่ง

  ทำไมเป็น HTML ไม่ใช่รูป: ตัวเลขในตารางเปลี่ยนทุกวันตามดาวเจ้าวัน ถ้าเป็นภาพต้อง
  เจนใหม่ 7 ใบ (หรือ 7 × ทุกวันที่ลูกค้าถาม) แล้วยังก๊อปตัวเลขไปตรวจกับตำราไม่ได้
  ตารางจริงยังคมทุกความละเอียด อ่านออกด้วยสกรีนรีดเดอร์ และเปลี่ยนธีมตามเว็บได้

  รับ "รูปแบบ payload" (ลำดับเลขล้วน ๆ) แล้วขยายเป็นแถวเต็มด้วย ThaiAstro::yamRows()
  ทั้งหน้าที่คำนวณสดและหน้าที่อ่านย้อนหลังจึงได้ตารางเดียวกันเป๊ะ
--}}
@php
  use App\Support\ThaiAstro;

  $lord      = (int) ($yam['lord'] ?? 1);
  $lordName  = $yam['lord_name'] ?? ThaiAstro::PLANETS[$lord]['name'];
  $daySeq    = $yam['day_seq'] ?? ThaiAstro::yamSequence($lord, false);
  $nightSeq  = $yam['night_seq'] ?? ThaiAstro::yamSequence($lord, true);
  $picked    = $yam['picked'] ?? null;
  $weights   = $yam['weights'] ?? [];
  $inRuek    = $yam['in_ruek'] ?? [];

  $sides = [
    ['key' => 'day',   'rows' => ThaiAstro::yamRows($daySeq, false),  'label' => 'ฟากกลางวัน', 'span' => '๐๖:๐๐ – ๑๘:๐๐', 'step' => ThaiAstro::YAM_STEP_DAY,   'seq' => $daySeq],
    ['key' => 'night', 'rows' => ThaiAstro::yamRows($nightSeq, true), 'label' => 'ฟากกลางคืน', 'span' => '๑๘:๐๐ – ๐๖:๐๐', 'step' => ThaiAstro::YAM_STEP_NIGHT, 'seq' => $nightSeq],
  ];
  if (! $showNight) {
    $sides = [$sides[0]];
  }
@endphp

<div class="panel" style="padding:22px;overflow:hidden">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap;margin-bottom:4px">
    <div class="eyebrow" style="display:inline-flex">{{ $title ?? 'ยามอัฐกาล · ลงเลข' }}</div>
    @if ($dateLabel)
      <div style="font-family:var(--display);font-size:13px;color:var(--ink-faint)">{{ $dateLabel }}</div>
    @endif
  </div>

  <div style="color:var(--ink-dim);font-size:13px;line-height:1.85;margin-bottom:16px">
    ตั้งเลขเจ้าวันที่ <strong style="color:var(--gold);font-size:16px">{{ ThaiAstro::thaiNumber($lord) }}</strong>
    ({{ $lordName }}) ไว้ช่องแรก แล้วเดินเลขทีละช่อง —
    กลางวันบวก <strong style="color:var(--moon)">{{ ThaiAstro::thaiNumber(ThaiAstro::YAM_STEP_DAY) }}</strong>
    กลางคืนบวก <strong style="color:var(--moon)">{{ ThaiAstro::thaiNumber(ThaiAstro::YAM_STEP_NIGHT) }}</strong>
    เกิน ๗ เอา ๗ ลบ ยามละ ๑ ชั่วโมง ๓๐ นาที
  </div>

  @foreach ($sides as $side)
    @php $isNight = $side['key'] === 'night'; @endphp

    <div style="display:flex;align-items:baseline;gap:10px;margin:{{ $loop->first ? '0' : '22px' }} 0 9px">
      <span style="font-family:var(--display);font-size:12px;letter-spacing:.14em;color:{{ $isNight ? 'var(--ink-dim)' : 'var(--gold)' }}">
        {{ $isNight ? '☾' : '☀' }} {{ $side['label'] }}
      </span>
      <span style="font-size:11.5px;color:var(--ink-faint);font-family:var(--display);letter-spacing:.08em">
        {{ $side['span'] }} · ระบบ +{{ ThaiAstro::thaiNumber($side['step']) }}
      </span>
    </div>

    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
      <table style="border-collapse:collapse;width:100%;min-width:620px;table-layout:fixed">
        {{-- คำอธิบายตารางสำหรับสกรีนรีดเดอร์ (ธีมนี้ไม่มีคลาส .sr-only ให้ใช้) --}}
        <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap">
          ตารางยามอัฐกาล{{ $side['label'] }} วัน{{ $lordName }}
        </caption>
        <thead>
          <tr>
            @foreach ($side['rows'] as $w)
              <th scope="col" style="padding:0 3px 6px;font-family:var(--display);font-size:10px;letter-spacing:.12em;color:var(--ink-faint);font-weight:400">
                ยาม {{ $w['thai_no'] }}
              </th>
            @endforeach
          </tr>
        </thead>
        {{-- แถวเดียวที่มีทั้งเลข ชื่อยาม และเวลาซ้อนกันในเซลล์ — ให้แต่ละช่องเป็น
             ก้อนเดียว กวาดตาเทียบกันได้เหมือนตารางในตำรา --}}
        <tbody>
            <tr>
              @foreach ($side['rows'] as $i => $w)
                @php
                  $isPicked = ! $isNight && $picked !== null && $picked === $w['no'];
                  $isNow    = $highlight && ($highlight['side'] ?? null) === $side['key'] && (int) ($highlight['no'] ?? 0) === $w['no'];
                  $weight   = ! $isNight ? ($weights[$i] ?? null) : null;
                  $covered  = ! $isNight ? ($inRuek[$i] ?? null) : null;
                  $accent   = $w['color'];
                  $bg       = $isPicked ? $accent.'26' : ($isNow ? 'rgba(255,255,255,.06)' : ($covered ? $accent.'0d' : 'transparent'));
                  $border   = $isPicked ? $accent : ($isNow ? 'var(--gold)' : 'rgba(255,255,255,.10)');
                @endphp
                <td style="padding:0 3px;vertical-align:top">
                  <div style="border:1px solid {{ $border }};border-width:{{ $isPicked || $isNow ? '2px' : '1px' }};
                              border-radius:10px;background:{{ $bg }};padding:10px 4px 9px;text-align:center;height:100%">
                    <div style="font-family:var(--display);font-size:26px;line-height:1;color:{{ $accent }}">
                      {{ $w['thai_planet'] }}
                    </div>
                    <div style="font-size:12px;color:var(--moon);margin-top:5px;white-space:nowrap">{{ $w['name'] }}</div>
                    <div style="font-size:10.5px;color:var(--ink-faint);margin-top:3px;font-family:var(--display);letter-spacing:.02em;white-space:nowrap">
                      {{ $w['from'] }}<br>{{ $w['to'] }}
                    </div>
                    @if ($weight !== null && $weight !== 0)
                      <div style="margin-top:6px;font-size:10px;font-family:var(--display);letter-spacing:.06em;color:{{ $weight > 0 ? '#7fbf8e' : '#d9a441' }}">
                        {{ $weight > 0 ? 'หนุน' : 'ไม่หนุน' }}
                      </div>
                    @endif
                    @if ($isPicked)
                      <div style="margin-top:6px;font-family:var(--display);font-size:9.5px;letter-spacing:.12em;color:#14100c;background:{{ $accent }};border-radius:999px;padding:2px 0">ตั้งฤกษ์</div>
                    @endif
                  </div>
                </td>
              @endforeach
            </tr>
        </tbody>
      </table>
    </div>

    @if ($showWorking)
      @php $working = ThaiAstro::yamWorking($lord, $isNight); @endphp
      <div style="margin-top:9px;font-family:var(--display);font-size:12px;letter-spacing:.02em;color:var(--ink-faint);line-height:2;word-break:keep-all">
        <span style="color:var(--ink-dim)">ลงเลข:</span>
        <span style="color:var(--gold)">{{ ThaiAstro::thaiNumber($lord) }}</span>
        @foreach ($working as $s)
          <span style="white-space:nowrap">
            → {{ ThaiAstro::thaiNumber($s['from']) }}+{{ ThaiAstro::thaiNumber($s['step']) }}={{ ThaiAstro::thaiNumber($s['sum']) }}@if ($s['carry'])−๗={{ ThaiAstro::thaiNumber($s['to']) }}@endif
          </span>
        @endforeach
      </div>
    @endif
  @endforeach

  <div style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(255,255,255,.08);display:flex;gap:16px;flex-wrap:wrap;font-size:11.5px;color:var(--ink-faint)">
    @foreach (ThaiAstro::PLANETS as $p)
      <span style="display:inline-flex;align-items:center;gap:5px;white-space:nowrap">
        <span style="font-family:var(--display);font-size:14px;color:{{ $p['color'] }}">{{ ThaiAstro::thaiNumber($p['no']) }}</span>
        {{ $p['glyph'] }} {{ $p['name'] }}
      </span>
    @endforeach
  </div>
</div>
