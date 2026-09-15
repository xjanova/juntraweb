@props(['parsed', 'reading'])
{{--
  🔮 (2026-09-15) คำทำนายไพ่แบบการ์ด/ตาราง — ชิ้นมาจาก App\Support\ReadingSections::parse()
  ลำดับ: ฟันธง/ผลวินิจฉัย → ใจเรา-ใจเขา / เทียบทางเลือก → คำทำนายรายใบ (มีรูปไพ่) หรือตาราง 12 เดือน
  → เดือนทอง/ต้องระวัง → เรื่องราว/จังหวะ/ดวงวันเกิด → ทางแก้/คำแนะนำ
  ทุกเนื้อความผ่าน Markdown::safe (ตัด HTML ทิ้ง — ข้อความจาก AI)
--}}
@php
  use App\Support\Markdown;
  use App\Support\ReadingSections;

  $by       = $parsed['by_type'] ?? [];
  $one      = fn (string $t) => $by[$t][0] ?? null;
  $cardsPos = $reading->tarotCards->keyBy('position');
  $verdict  = $one('verdict');
  $diag     = $one('diagnosis');
  $choice   = $one('choice');
  $chosen   = $choice['chosen'] ?? null;
  $toneOf   = ['good' => 'rs-tone-good', 'neutral' => 'rs-tone-mid', 'caution' => 'rs-tone-warn'];
@endphp

<div class="rs">

  {{-- ═══ ฟันธง ═══ --}}
  @if ($verdict)
    @php $vt = ReadingSections::verdictTone($verdict['result'] ?? null); @endphp
    <section class="rs-hero rs-hero-{{ $vt }}">
      <div class="rs-eyebrow">🎯 แม่หมอฟันธง</div>
      @if (! empty($verdict['result']))
        <div class="rs-verdict">{{ $verdict['result'] }}</div>
      @endif
      @if ($verdict['body'] !== '')
        <div class="rs-prose">{!! Markdown::safe($verdict['body']) !!}</div>
      @endif
    </section>
  @endif

  {{-- ═══ ผลวินิจฉัยคุณไสย (ตาราง) ═══ --}}
  @if ($diag)
    @php
      $hit = $diag['fields']['พบของ'] ?? '—';
      $ht  = str_starts_with($hit, 'ใช่') ? 'red' : (str_starts_with($hit, 'ไม่ใช่') ? 'green' : 'amber');
    @endphp
    <section class="rs-hero rs-hero-{{ $ht }}">
      <div class="rs-eyebrow">🪬 ผลวินิจฉัยจากไพ่</div>
      <div class="rs-verdict">{{ $hit === 'ใช่' ? 'พบสัญญาณของ' : ($hit === 'ไม่ใช่' ? 'ไพ่ไม่ชี้ว่าโดนของ' : 'สัญญาณยังไม่ชัด') }}</div>
      @php $rows = array_filter($diag['fields'], fn ($v, $k) => $k !== 'พบของ' && $v !== '' && $v !== '—', ARRAY_FILTER_USE_BOTH); @endphp
      @if ($rows)
        <div class="rs-table-wrap">
          <table class="rs-table">
            @foreach ($rows as $k => $v)
              <tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>
            @endforeach
          </table>
        </div>
      @endif
      @if ($diag['body'] !== '')
        <div class="rs-prose">{!! Markdown::safe($diag['body']) !!}</div>
      @endif
    </section>
  @endif

  {{-- ═══ ใจเรา-ใจเขา ═══ --}}
  @if ($h = $one('hearts'))
    <section class="rs-card">
      <div class="rs-title">💞 {{ $h['title'] }}</div>
      <div class="rs-duo">
        <div class="rs-duo-cell"><div class="rs-duo-k">ใจเรา</div><div>{{ $h['fields']['ใจเรา'] ?? '—' }}</div></div>
        <div class="rs-duo-cell"><div class="rs-duo-k">ใจเขา</div><div>{{ $h['fields']['ใจเขา'] ?? '—' }}</div></div>
      </div>
      @if ($h['body'] !== '')<div class="rs-prose">{!! Markdown::safe($h['body']) !!}</div>@endif
    </section>
  @endif

  {{-- ═══ เทียบทางเลือก (ตาราง 2 ฝั่ง) ═══ --}}
  @if (! empty($by['option']))
    <section class="rs-card">
      <div class="rs-title">🔀 เทียบสองทางเลือก</div>
      <div class="rs-table-wrap">
        <table class="rs-table rs-compare">
          <thead>
            <tr>
              <th></th>
              @foreach ($by['option'] as $o)
                <th class="{{ ($o['n'] ?? 0) === $chosen ? 'is-chosen' : '' }}">
                  ทางเลือกที่ {{ $o['n'] ?? $loop->iteration }}
                  @if (($o['n'] ?? 0) === $chosen) <span class="rs-badge">✅ ไพ่เลือก</span> @endif
                  @if ($o['body'] !== '')<div class="rs-sub">{{ \Illuminate\Support\Str::limit(strip_tags(Markdown::safe($o['body'])), 90) }}</div>@endif
                </th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach (['ข้อดี', 'ข้อควรระวัง', 'ผลที่ตามมา'] as $k)
              <tr>
                <th>{{ $k }}</th>
                @foreach ($by['option'] as $o)
                  <td class="{{ ($o['n'] ?? 0) === $chosen ? 'is-chosen' : '' }}">{{ $o['fields'][$k] ?? '—' }}</td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if ($choice && $choice['body'] !== '')
        <div class="rs-prose" style="margin-top:14px"><strong>ทำไมไพ่เลือกทางนี้:</strong> {!! Markdown::safe($choice['body']) !!}</div>
      @endif
    </section>
  @endif

  {{-- ═══ คำทำนายรายใบ ═══ --}}
  @if (! empty($by['card']))
    <section>
      <div class="rs-eyebrow" style="margin:34px 0 14px">🃏 คำทำนายทีละใบ</div>
      <div class="rs-cards">
        @foreach ($by['card'] as $c)
          @php $rc = $cardsPos[$c['n'] ?? 0] ?? null; @endphp
          <article class="rs-card rs-cardread">
            @if ($rc && $rc->card)
              <div class="rs-thumb" style="{{ $rc->reversed ? 'transform:rotate(180deg)' : '' }}">
                <img src="{{ $rc->card->imageUrl() }}" alt="{{ $rc->card->name_th }}" loading="lazy">
              </div>
            @endif
            <div class="rs-cardread-body">
              <div class="rs-pos">ใบที่ {{ $c['n'] ?? $loop->iteration }} · {{ $c['label'] ?: ($rc->position_label ?? '') }}</div>
              @if ($rc && $rc->card)
                <div class="rs-cardname">{{ $rc->card->name_th }}{{ $rc->reversed ? ' · กลับหัว' : '' }}</div>
              @endif
              <div class="rs-prose">{!! Markdown::safe($c['body']) !!}</div>
            </div>
          </article>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ═══ ตาราง 12 เดือน ═══ --}}
  @if (! empty($by['month']))
    <section>
      <div class="rs-eyebrow" style="margin:34px 0 14px">📅 ดวงทีละเดือน</div>
      <div class="rs-months">
        @foreach ($by['month'] as $i => $mth)
          @php $rc = $cardsPos[$i + 1] ?? null; @endphp
          <article class="rs-month {{ $toneOf[$mth['tone']] ?? 'rs-tone-mid' }}">
            <div class="rs-month-head">
              <span class="rs-month-name">{{ $mth['month'] }}</span>
              <span class="rs-tone">{{ $mth['tone_label'] }}</span>
            </div>
            @if (! empty($mth['theme']))<div class="rs-theme">{{ $mth['theme'] }}</div>@endif
            <div class="rs-prose">{!! Markdown::safe($mth['body']) !!}</div>
            @if ($rc && $rc->card)
              <div class="rs-month-card">🃏 {{ $rc->card->name_th }}{{ $rc->reversed ? ' (กลับหัว)' : '' }}</div>
            @endif
          </article>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ═══ เดือนทอง / ต้องระวัง ═══ --}}
  @if ($one('golden') || $one('caution'))
    <div class="rs-duo" style="margin-top:22px">
      @foreach (['golden' => 'rs-good', 'caution' => 'rs-warn'] as $t => $cls)
        @if ($s = $one($t))
          <section class="rs-card {{ $cls }}">
            <div class="rs-title">{{ $t === 'golden' ? '🌟' : '⚠️' }} {{ $s['title'] }}</div>
            <ul class="rs-list">
              @foreach ($s['items'] as $it)<li>{{ $it }}</li>@endforeach
            </ul>
            @if ($s['body'] !== '')<div class="rs-prose">{!! Markdown::safe($s['body']) !!}</div>@endif
          </section>
        @endif
      @endforeach
    </div>
  @endif

  {{-- ═══ เรื่องราว / จังหวะ / ดวงวันเกิด / เจาะรายละเอียด ═══ --}}
  @foreach (['detail' => '🔍', 'story' => '📖', 'timing' => '⏳', 'birth' => '🌠'] as $t => $ico)
    @if ($s = $one($t))
      <section class="rs-card">
        <div class="rs-title">{{ $ico }} {{ $s['title'] }}</div>
        <div class="rs-prose">{!! Markdown::safe($s['body']) !!}</div>
      </section>
    @endif
  @endforeach

  {{-- ═══ ทางแก้ / ทิศ / คำแนะนำ ═══ --}}
  @foreach (['remedy' => '🙏', 'direction' => '🧿', 'advice' => '🧭'] as $t => $ico)
    @if ($s = $one($t))
      <section class="rs-card rs-good">
        <div class="rs-title">{{ $ico }} {{ $s['title'] }}</div>
        @if ($s['items'])
          <ul class="rs-list rs-check">
            @foreach ($s['items'] as $it)<li>{!! Markdown::safe($it) !!}</li>@endforeach
          </ul>
        @endif
        @if ($s['body'] !== '')<div class="rs-prose">{!! Markdown::safe($s['body']) !!}</div>@endif
      </section>
    @endif
  @endforeach

  {{-- ═══ ถ้าใจไม่ไหว (เฉพาะเมื่อแม่หมอเห็นว่าหนัก) ═══ --}}
  @if ($s = $one('safety'))
    <section class="rs-card rs-warn">
      <div class="rs-title">☎️ {{ $s['title'] }}</div>
      <div class="rs-prose">{!! Markdown::safe($s['body']) !!}</div>
      <p style="margin-top:10px">สายด่วนสุขภาพจิต <a href="tel:1323" style="color:var(--gold);font-weight:700">1323</a> · โทรฟรี 24 ชั่วโมง</p>
    </section>
  @endif
</div>
