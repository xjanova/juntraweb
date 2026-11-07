@extends('layouts.app')

@php
  // ค่า default กันหน้า history (show()) ที่ไม่ได้ส่งตัวแปรมาครบ
  $dailyLimit  = $dailyLimit ?? 0;
  $dailyLeft   = $dailyLeft ?? 0;
  $suggestions = $suggestions ?? [];
  $topics      = $topics ?? [];
  $awaiting    = $awaiting ?? false;
  $readonly    = $readonly ?? false;
  $isFree      = ($cost ?? 0) <= 0;
  $exhausted   = $isFree && $dailyLimit > 0 && $dailyLeft <= 0;

  // 🔮 (2026-11-03) ห้องของผู้พยากรณ์ — ชื่อ/รูป/คำเรียกตัวเองมาจาก config/chat_rooms.php ที่เดียว
  //    (เดิมเขียน "แม่หมอจันทรา" ตายตัวทั้งหน้า → ห้องบุษบาจะขึ้นชื่อแม่หมอ)
  $room       = \App\Support\ChatRooms::normalize($room ?? null);
  $roomCfg    = \App\Support\ChatRooms::get($room) ?? [];
  $roomImage  = is_file(public_path($roomCfg['image'] ?? '')) ? asset($roomCfg['image']) : asset('images/hero-chantra.webp');
  $P = [
      'key'     => $room,
      'name'    => \App\Support\ChatRooms::name($room),
      'short'   => \App\Support\ChatRooms::short($room),
      'self'    => \App\Support\ChatRooms::selfName($room),
      'eyebrow' => (string) ($roomCfg['eyebrow'] ?? ''),
      'busy'    => (string) ($roomCfg['busy'] ?? 'กำลังเพ่งไพ่...'),
      'image'   => $roomImage,
  ];
  $offerCards = $offerCards ?? ['id' => null, 'offers' => []];
  // ปุ่มเปลี่ยนผู้พยากรณ์ — มีให้เมื่อเปิดมากกว่าห้องเดียว
  // 🔒 (2026-11-07) กำลังทำนาย/ถามต่อจากไพ่อยู่ = ไม่มีปุ่มเปลี่ยนห้องเลย (เซิร์ฟเวอร์บังคับอยู่แล้ว ปุ่มที่กดแล้วเด้งกลับทำให้งง)
  $canSwitch  = ! $readonly && empty($roomLock) && collect($rooms ?? [])->where('open', true)->count() > 1;

  // 🏛️ (2026-11-05) ห้องพยากรณ์เต็มจอ — เจ้าของสั่ง: "เลือกผู้พยากรณ์แล้วต้องเข้าห้องพยากรณ์ ไม่ใช่ห้องแชท
  //    เพราะเราเอาช่องแชทย้ายมาในห้องพยากรณ์" → ห้องสด = ฉากโต๊ะของห้อง ผู้พยากรณ์ยืนหลังโต๊ะ แชทอยู่ในฉาก
  //    ฉาก/สีชุดเดียวกับโรงละครเปิดไพ่ (config/card_theater.php) · หน้าประวัติ (อ่านอย่างเดียว) ใช้หน้าตาเดิม
  $scene       = $readonly ? null : \App\Support\CardTheater::room($room);
  $tableOffers = $tableOffers ?? [];

  // ข้อความเริ่มต้นถูก render markdown ฝั่งเซิร์ฟเวอร์แล้วส่งเป็น HTML ที่ผ่าน
  // การ strip HTML ดิบทิ้ง (Markdown::safe) — ฝั่ง client จึงไม่ต้องมีตัวแปลง
  // markdown ของตัวเอง และข้อความเดียวกันหน้าตาเหมือนกันทั้งก่อน/หลังรีเฟรช
  // การ์ดแพ็กเกจเปิดไพ่ที่แม่หมอยื่นไว้ (offer_topic) ต้องยังอยู่หลังรีเฟรช — ราคาอ่านสด ณ ตอนนี้
  // คำถามของการ์ด = ข้อความล่าสุดของลูกค้าก่อนหน้านั้น (ส่งต่อไปหน้าเลือกไพ่ ไม่ต้องพิมพ์ซ้ำ)
  $lastAsked = null;
  // การ์ดคำนวณมาจาก controller แล้ว (offerCardsFor) เฉพาะข้อความชวนล่าสุด — ไม่ query ต่อข้อความในวิว
  $initialMessages = $conversation->messages->map(function ($m) use (&$lastAsked, $readonly, $offerCards) {
      if ($m->role === 'user') {
          $lastAsked = $m->content;
      }
      $offers = (! $readonly && $offerCards['id'] !== null && $m->id === $offerCards['id'])
          ? $offerCards['offers']
          : [];

      // 🧹 (2026-11-07) "[]" ที่ตัวลอกแท็กของ Thaiprompt เคยทิ้งท้ายคำตอบ (แก้ต้นทางแล้ว) — ข้อความเก่าในห้องไม่ต้องโชว์
      $content = $m->role === 'assistant'
          ? trim((string) preg_replace('/\h*(?:\[+\h*\]+|\{+\h*\}+)\h*/u', ' ', (string) $m->content))
          : $m->content;

      return [
          'role'     => $m->role === 'assistant' ? 'assistant' : 'user',
          'html'     => \App\Support\Markdown::safe($content),
          'text'     => $content,
          'time'     => optional($m->created_at)->format('H:i'),
          'state'    => 'ok',
          'offers'   => $offers,
          'question' => $offers !== [] ? mb_substr((string) $lastAsked, 0, 300) : null,
      ];
  })->values();
@endphp

@section('title', $scene ? ($scene['title'] ?? 'ห้อง' . $P['name']) : ($isFree ? 'คุยกับ' . $P['name'] . ' · ฟรี' : 'คุยกับ' . $P['name']))
@if ($scene)
  {{-- ห้องเต็มจอปิดทับแถบแจ้งเตือนของเลย์เอาต์ — ห้องแสดงข้อความเอง (ป้ายลอยบนฉาก) --}}
  @section('page-handles-flash', '1')
  @push('head')
    @vite('resources/css/oracle-room.css')
  @endpush
@endif

@section('content')
<section class="canvas">
  <div class="chat-shell {{ $scene ? 'is-room' : '' }}" data-persona="{{ $room }}"
       @if ($scene)
         data-plate='@json($scene['plate'])'
         style="--or-a: {{ $scene['accent'] }}; --or-b: {{ $scene['accent2'] }}; --or-glow: {{ $scene['glow'] }}; --or-plate: url('{{ $scene['plate']['src'] }}')"
       @endif
       x-data="chatBot({
         room: @js($room),
         persona: @js(['name' => $P['name'], 'short' => $P['short'], 'self' => $P['self'], 'busy' => $P['busy']]),
         messages: @js($initialMessages),
         suggestions: @js($suggestions),
         aiSuggestions: @js(array_values($aiSuggestions ?? [])),
         roomLock: @js($roomLock ?? null),
         pendingBill: @js($pendingBill ?? null),
         serverNow: @js(now()->getTimestamp()),
         qa: @js($qa ?? null),
         qaEndUrl: @js(route('chat.qa.end')),
         qaOverText: @js(\App\Support\ReadingQaClock::overMessage($room)),
         qaWrap: @js($qaWrap ?? null),
         freshUrl: @js(route('chat.index', ['room' => $room, 'fresh' => 1])),
         idleNudge: @js(($gate['allowed'] ?? false) && ! ($readonly ?? false) ? (int) config('reading_qa.idle_nudge_seconds', 90) : 0),
         topics: @js($topics),
         awaiting: @js((bool) $awaiting),
         crisisRoom: @js((bool) ($crisisRoom ?? false)),
         menuOpen: @js((bool) ($menuOpen ?? false)),
         dailyLimit: @js((int) $dailyLimit),
         dailyLeft: @js((int) $dailyLeft),
         blocked: @js((bool) $exhausted),
         autosend: @js($autosend ?? null),
         sendUrl: @js(route('chat.send')),
         transcribeUrl: @js(route('chat.transcribe')),
         tarotBeginUrl: @js(route('tarot.begin')),
         topupUrl: @js(route('wallet.topup')),
         canTopupInChat: @js((bool) ($gate['allowed'] ?? false) && !($readonly ?? false)),
         topupCreateUrl: @js(route('chat.topup.store')),
         topupStatusUrl: @js(route('chat.topup.status', ['tx' => '__ID__'])),
         topupSlipUrl: @js(route('chat.topup.slip', ['tx' => '__ID__'])),
         bundles: @js(array_slice((array) config('pricing.topup_bundles', [50, 100, 200, 500]), 0, 4)),
         minTopup: @js((int) config('pricing.min_topup', 20)),
         maxTopup: @js((int) config('pricing.max_topup', 50000)),
         {{-- ปุ่มไปต่อหลังเติมเงิน — บริการที่แอดมินปิดไว้ (ServiceGate) ไม่แสดง --}}
         nextSteps: @js(array_values(array_filter([
           ['icon' => '🔮', 'label' => 'เปิดไพ่ยิปซี', 'url' => route('tarot.index')],
           \App\Support\ServiceGate::isClosed('deep') ? null : ['icon' => '🌟', 'label' => 'ดูดวงเชิงลึก', 'url' => route('deep.index')],
           \App\Support\ServiceGate::isClosed('numerology') ? null : ['icon' => '🔢', 'label' => 'เลขศาสตร์', 'url' => route('numerology.index')],
           \App\Support\ServiceGate::isClosed('auspicious') ? null : ['icon' => '📿', 'label' => 'ฤกษ์ยาม', 'url' => route('auspicious.index')],
         ]))),
       })">

    @if ($scene)
      {{-- ═══ ฉากห้อง: โต๊ะเปล่าของห้อง + ผู้พยากรณ์ยืนหลังขอบโต๊ะ (ตำแหน่งคำนวณจากขอบโต๊ะจริงของภาพ) ═══ --}}
      {{-- 🎵 (2026-11-05) เพลงบรรเลงของห้องเล่นวนไม่หยุด (ปุ่มลำโพงปิด = เงียบ) --}}
      @if (\App\Support\OracleMedia::music($room))
        <div hidden data-room-music='@json(\App\Support\OracleMedia::music($room))'></div>
        @vite('resources/js/oracle-room/music.js')
      @endif
      <div class="or-scene" aria-hidden="true">
        <div class="or-plate"></div>
        <div class="or-actor" :class="{ 'is-busy': thinking }"><img src="{{ $scene['sprite'] }}" alt="" decoding="async" fetchpriority="high"></div>
        <div class="or-vignette"></div>
      </div>

      {{-- ═══ แถบบนลายทอง: กลับ/เปลี่ยนผู้พยากรณ์ · ชื่อห้อง · เสียง · เครดิต ═══ --}}
      <header class="or-hud">
        <a href="{{ $canSwitch ? route('chat.index', ['pick' => 1]) : route('home') }}" class="or-btn or-back"
           aria-label="{{ $canSwitch ? 'เปลี่ยนผู้พยากรณ์' : 'กลับหน้าแรก' }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
          <span>{{ $canSwitch ? 'เปลี่ยนผู้พยากรณ์' : 'หน้าแรก' }}</span>
        </a>
        <div class="or-title">
          <small>{{ $P['eyebrow'] ?: 'ห้องพยากรณ์' }}</small>
          <strong>{{ $scene['title'] }}</strong>
          <span class="or-status"><span class="mor-online"></span><span x-text="thinking ? persona.busy : 'พร้อมรับฟังอยู่ค่ะ'">พร้อมรับฟังอยู่ค่ะ</span></span>
        </div>
        <div class="or-hud-end">
          {{-- ⏳ (2026-11-05) เวลาถามต่อของไพ่ชุดนี้ — นับจากคำถามแรก ตามนาฬิกาเซิร์ฟเวอร์ · "พอแล้ว" = จบก่อนเวลา --}}
          @if (! empty($qa) && ! empty($qa['premium']))
            <span class="or-pill or-premium" x-show="qa.premium && !qa.over" x-cloak title="พรีเมียม — ทุกคำตอบพูดออกเสียง">🎙️ <b>พรีเมียม</b></span>
          @endif
          @if (! empty($qa))
            <span class="or-pill or-qa" role="timer" aria-live="off"
                  :class="qa.started && !qa.over && left(qa.ends_at) <= qa.warn * 60 && 'is-low'"
                  title="เวลาถามต่อของไพ่ชุดนี้ · เริ่มนับเมื่อถามคำถามแรก">
              <small x-text="qa.over ? 'ถามต่อ' : (qa.started ? 'เหลือเวลา' : 'ถามต่อได้')">ถามต่อได้</small>
              <b x-text="'⏳ ' + (qa.over ? 'หมดเวลา' : (qa.started ? clock(qa.ends_at) : qa.minutes + ' นาที'))">⏳ {{ $qa['minutes'] }} นาที</b>
            </span>
            <button type="button" class="or-btn or-enough" x-show="!qa.over" x-cloak :disabled="qaBusy"
                    @click="endQa()" x-text="qaConfirm ? 'แตะอีกครั้งเพื่อจบ' : 'พอแล้ว'"
                    aria-label="พอแล้ว — จบการถามต่อก่อนหมดเวลา">พอแล้ว</button>
          @endif
          @if (\App\Support\OracleMedia::voice($room))
            <button type="button" class="or-btn or-icon"
                    x-data="{ on: !!(window.JuntraVoice && window.JuntraVoice.on()) }"
                    @oracle-sound-state.window="on = $event.detail.on"
                    @click="window.dispatchEvent(new CustomEvent('oracle-sound-toggle'))"
                    :aria-pressed="on ? 'true' : 'false'"
                    :aria-label="on ? 'ปิดเสียง' + persona.short : 'เปิดเสียง' + persona.short"
                    aria-label="เปิดเสียง">
              <svg x-show="!on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="m23 9-6 6M17 9l6 6"/></svg>
              <svg x-show="on" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
            </button>
          @endif
          @if ($gate['allowed'] && $isFree && $dailyLimit > 0)
            <span class="or-pill" :class="dailyLeft <= 3 && 'is-low'" title="คุยฟรีวันนี้ · รีเซ็ตทุกเที่ยงคืน">
              <small>คุยฟรีวันนี้</small><b x-text="dailyLeft + '/' + dailyLimit">{{ $dailyLeft }}/{{ $dailyLimit }}</b>
            </span>
          @endif
          @if ($gate['allowed'] && $balance !== null)
            <a href="{{ route('wallet.topup') }}" class="or-pill" title="เครดิตคงเหลือ · แตะเพื่อเติมเงิน">
              <small>เครดิต</small><b x-text="'฿' + Number(balance).toFixed(2)">฿{{ number_format($balance, 2) }}</b>
            </a>
          @endif
        </div>
      </header>

      {{-- ข้อความจากระบบ (เช่น ล็อกห้องระหว่างทำนาย / เปิดไพ่ไม่ได้) — ลอยบนฉาก --}}
      @if (session('status') || $errors->any())
        <div class="or-toast {{ $errors->any() ? 'is-error' : '' }}" role="status" x-data="{ on: true }" x-show="on" x-init="setTimeout(() => on = false, 7000)" @click="on = false">
          {{ session('status') ?: $errors->first() }}
        </div>
      @endif

      {{-- ═══ แพ็กเกจของห้องวางบนโต๊ะ — กดเปิดไพ่ได้ตลอด (คำถามล่าสุดที่พิมพ์ติดไปด้วย) ═══ --}}
      @if ($tableOffers)
        <div class="or-table" x-show="!crisisRoom" role="group" aria-label="เปิดไพ่กับ{{ $P['short'] }}">
          @foreach ($tableOffers as $o)
            <button type="button" class="or-offer" :disabled="!!table.offerBusy" @click="openTable(@js($o))">
              <span class="or-offer-icon" aria-hidden="true">{{ $o['kind'] === 'deep' ? '🌟' : '🔮' }}</span>
              <span class="or-offer-text">
                <strong>{{ $o['label'] }}</strong>
                <small>
                  @if (! empty($o['cards'])){{ $o['cards'] }} ใบ · @endif
                  ฿{{ number_format($o['price'], $o['price'] == intval($o['price']) ? 0 : 2) }}
                </small>
              </span>
              <span class="or-offer-go" x-text="table.offerBusy === @js($o['key']) ? 'กำลังพาไป…' : 'เปิดไพ่'">เปิดไพ่</span>
            </button>
          @endforeach
          @if (! empty($tableMenu))
            {{-- 🔮 (2026-11-06) หน้ารวมแพ็กเกจ /tarot ปิดแล้ว — ไพ่ทุกแบบของห้องอยู่ในแผ่นนี้ --}}
            <button type="button" class="or-offer is-more" @click="table.menu = true" :aria-expanded="table.menu.toString()" aria-controls="or-menu">
              <span class="or-offer-icon" aria-hidden="true">🃏</span>
              <span class="or-offer-text">
                <strong>ไพ่แบบอื่น</strong>
                <small>ทั้งหมด {{ count($tableMenu) }} แบบ</small>
              </span>
            </button>
          @endif
        </div>
      @endif

      @if (! empty($tableMenu))
        <div id="or-menu" class="or-menu" x-show="table.menu && !crisisRoom" x-cloak @keydown.escape.window="table.menu = false"
             role="dialog" aria-modal="true" aria-label="เลือกแบบการเปิดไพ่กับ{{ $P['short'] }}">
          <div class="or-menu-backdrop" @click="table.menu = false"></div>
          <div class="or-menu-sheet">
            <div class="or-menu-head">
              <strong>เลือกแบบการเปิดไพ่กับ{{ $P['short'] }}</strong>
              <button type="button" class="or-menu-close" @click="table.menu = false" aria-label="ปิดรายการไพ่">✕</button>
            </div>
            <div class="or-menu-list">
              @foreach ($tableMenu as $o)
                @php $locked = $o['locked_until'] ?? null; @endphp
                <button type="button" class="or-menu-item {{ $locked ? 'is-locked' : '' }}"
                        @if ($locked) disabled @else :disabled="!!table.offerBusy" @click="openTable(@js($o))" @endif>
                  <span class="or-offer-icon" aria-hidden="true">{{ $o['kind'] === 'deep' ? '🌟' : '🔮' }}</span>
                  <span class="or-menu-text">
                    <strong>{{ $o['label'] }}</strong>
                    <small>
                      @if (! empty($o['cards'])){{ $o['cards'] }} ใบ · @endif
                      ฿{{ number_format($o['price'], $o['price'] == intval($o['price']) ? 0 : 2) }}
                    </small>
                    @if ($locked)
                      <em>เปิดได้อีกครั้ง {{ $locked }}</em>
                    @elseif ($o['blurb'] !== '')
                      <em>{{ $o['blurb'] }}</em>
                    @endif
                  </span>
                  @unless ($locked)
                    <span class="or-offer-go" x-text="table.offerBusy === @js($o['key']) ? 'กำลังพาไป…' : 'เปิดไพ่'">เปิดไพ่</span>
                  @endunless
                </button>
              @endforeach
            </div>
          </div>
        </div>
      @endif

      {{-- 🔒 (2026-11-05) กำลังทำนายอยู่ / 🧾 บิลเติมเงินค้าง — วางบนโต๊ะแทนแพ็กเกจ
           เจ้าของสั่ง "ป้ายเปิดไพ่ในห้องต้องไม่ขึ้นให้เลือกถ้ายังค้างบิลอยู่ ควรแสดงเลขบิลด้วยว่าบิลไหน ได้รับเงินหรือยัง"
           เวลาที่เหลือนับจากนาฬิกาเซิร์ฟเวอร์ (serverNow) ไม่ใช่นาฬิกาเครื่องลูกค้า --}}
      @if (! empty($roomLock) && ! ($roomLock['reason'] === 'qa' && $roomLock['room'] === $room))
        <div class="or-table" role="status" aria-live="polite">
          <div class="or-offer is-hold">
            <span class="or-offer-icon" aria-hidden="true">{{ $roomLock['reason'] === 'reading' ? '📜' : '🔮' }}</span>
            <span class="or-offer-text">
              <strong>{{ $roomLock['message'] }}กับ{{ $roomLock['name'] }}</strong>
              <small>เหลือเวลา <b x-text="clock(hold.lockEnds)">--:--</b></small>
            </span>
            @if ($roomLock['resume_url'])
              <a href="{{ $roomLock['resume_url'] }}" class="or-offer-go">{{ $roomLock['resume_label'] }}</a>
            @endif
            @if ($roomLock['can_end'])
              {{-- จบพิธีก่อนจ่าย ไม่เสียเงิน · แตะสองครั้งกันพลาด --}}
              <form method="POST" action="{{ route('tarot.abandon') }}"
                    @submit="if (!hold.confirmEnd) { $event.preventDefault(); hold.confirmEnd = true; setTimeout(() => hold.confirmEnd = false, 4000); }">
                @csrf
                <button type="submit" class="or-hold-end" x-text="hold.confirmEnd ? 'แตะอีกครั้งเพื่อจบ' : 'พอแล้ว'">พอแล้ว</button>
              </form>
            @endif
          </div>
        </div>
      @elseif (! empty($pendingBill))
        <div class="or-table" role="status" aria-live="polite">
          <div class="or-offer is-hold">
            <span class="or-offer-icon" aria-hidden="true">🧾</span>
            <span class="or-offer-text">
              <strong>บิลเลขที่ {{ $pendingBill['reference_code'] }} · ฿{{ number_format($pendingBill['payable'], 2) }}</strong>
              <small x-text="hold.billPaid ? 'ได้รับเงินแล้ว ✓ เปิดไพ่ต่อได้เลยค่ะ' : (hold.billSlip ? 'ได้รับสลิปแล้ว กำลังตรวจสอบ' : 'ยังไม่ได้รับเงิน · รอชำระ ' + clock(hold.billEnds))">ยังไม่ได้รับเงิน · รอชำระ</small>
            </span>
            <button type="button" class="or-offer-go" :disabled="hold.billBusy"
                    @click="hold.billPaid ? location.reload() : resumeBill()"
                    x-text="hold.billPaid ? 'เปิดไพ่ได้แล้ว' : (hold.billBusy ? 'กำลังเปิด…' : 'ดู QR จ่ายต่อ')">ดู QR จ่ายต่อ</button>
          </div>
        </div>
      @endif
    @endif

    @unless ($scene)
    <x-page-hero art="images/juntra/art/chat.webp" />

    <div style="text-align:center;margin-bottom:26px">
      <div class="eyebrow">{{ $P['eyebrow'] ?: 'CHANTRA AI ORACLE' }}</div>
      <h1 class="display" style="font-size:clamp(34px,4.5vw,60px);margin-bottom:12px">
        คุยกับ <em>{{ $P['name'] }}</em>
        @if ($channel)
          <span class="chip" style="vertical-align:middle;margin-left:12px;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);pointer-events:none">
            @if ($channel === 'facebook')
              <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11 10.13 11.93v-8.44H7.08v-3.5h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.79V24C19.61 23.07 24 18.1 24 12.07z"/></svg>
              Facebook
            @else
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
              LINE
            @endif
          </span>
        @endif
      </h1>
      <p class="lede" style="margin:0 auto">
        @if ($isFree)
          คุยกับ{{ $P['short'] }}ได้<em style="color:var(--gold);font-style:normal">ฟรี</em> — ความรัก การงาน การเงิน ชะตาชีวิต ระบบเดียวกับบอทแม่หมอใน Facebook / LINE
        @else
          ถามได้ทุกเรื่อง — ความรัก การงาน ชะตาดวง คำแนะนำชีวิต ระบบเดียวกับบอทแม่หมอใน Facebook Messenger / LINE OA
        @endif
      </p>
    </div>
    @endunless

    {{-- ป้ายโควตาคุยฟรี / เครดิต — ตัวเลขซิงก์กับเซิร์ฟเวอร์ทุกครั้งที่ส่ง (ห้องพยากรณ์ย้ายขึ้นแถบบน) --}}
    @if ($scene)
    @elseif (!$readonly && $gate['allowed'] && $isFree && $dailyLimit > 0)
      <div class="quota-bar" :class="dailyLeft <= 3 && 'is-low'">
        <span class="quota-label">คุยฟรีวันนี้</span>
        <strong class="quota-value" x-text="dailyLeft + ' / ' + dailyLimit">{{ $dailyLeft }} / {{ $dailyLimit }}</strong>
        <span style="color:var(--ink-dim)">ข้อความ · รีเซ็ตทุกเที่ยงคืน</span>
      </div>
    @elseif (!$readonly && $gate['allowed'] && $balance !== null && $cost > 0)
      @php $low = bccomp(number_format($balance, 2, '.', ''), number_format($cost * 5, 2, '.', ''), 2) < 0; @endphp
      <div class="quota-bar {{ $low ? 'is-low' : '' }}" style="justify-content:space-between">
        <div>
          <span class="quota-label" style="margin-right:10px">เครดิต</span>
          <strong class="quota-value">฿{{ number_format($balance, 2) }}</strong>
          <span style="color:var(--ink-dim);margin-left:10px">หักครั้งละ ฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }} ต่อข้อความ</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          @if ($low)<span style="color:#d4a017;font-size:12px">เครดิตเหลือน้อย</span>@endif
          <a href="{{ route('wallet.topup') }}" class="btn btn-ghost" style="padding:6px 16px;font-size:12px">เติมเงิน</a>
        </div>
      </div>
    @endif

    @unless ($gate['allowed'])
      <div class="flash {{ $scene ? 'or-gate' : '' }}" style="text-align:center;padding:28px 32px;margin-bottom:24px">
        <div class="eyebrow" style="margin-bottom:14px">
          @if ($gate['code'] === 'guest') ต้องเข้าสู่ระบบก่อน
          @else ต่อสาย{{ $P['self'] }}ใหม่ @endif
        </div>
        <p style="font-family:var(--thai);font-size:15px;color:var(--ink-dim);max-width:520px;margin:0 auto 20px;line-height:1.7">
          @if ($gate['code'] === 'guest')
            เข้าสู่ระบบครั้งเดียว {{ $P['self'] }}จะจดจำเรื่องราวของ{{ $roomCfg['customer'] ?? 'ลูก' }}ไว้เสมอ — สมาชิกจากบอท Facebook / LINE ของแม่หมอ เข้าใช้ได้ทันทีไม่ต้องสมัครใหม่
          @else
            {{ $gate['reason'] }}
          @endif
        </p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
          <a href="{{ route('thaiprompt.redirect', ['to' => '/chat']) }}" class="btn btn-primary">
            @if ($gate['code'] === 'guest') เข้าสู่ระบบด้วย Facebook / LINE
            @else เข้าสู่ระบบใหม่ผ่าน Thaiprompt
            @endif
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </a>
          {{-- 🌙 (2026-09-15) ทุกคนที่ล็อกอินคุยได้ — สมาชิกเบอร์โทร/อีเมลก็คุยได้ ไม่ต้องผ่าน FB/LINE --}}
          @if ($gate['code'] === 'guest')
            <a href="{{ route('login') }}" class="btn btn-ghost">เข้าสู่ระบบด้วยเบอร์โทร / อีเมล</a>
          @endif
        </div>
      </div>
    @endunless

    <div class="mor-layout">

      {{-- ฝั่งซ้าย: ภาพแม่หมอ (เดสก์ท็อป) --}}
      <aside class="mor-portrait">
        <div class="mor-glow" aria-hidden="true"></div>
        {{-- 🎬 คลิป idle เงียบ + ปุ่มลำโพง (เสียงประโยคประจำ เล่นเมื่อลูกค้ากดเปิดเท่านั้น) --}}
        <x-oracle-stage :persona="$room" :poster="$P['image']" :alt="$P['name']" sound class="mor-stage"
                        :greet="($fromReading ?? false) ? 'followup_ask' : 'room_greet'" />
        <div class="mor-plate">
          <div style="font-family:var(--thai);font-size:11px;color:var(--gold)">{{ $P['eyebrow'] }}</div>
          <div style="font-family:var(--thai);font-weight:700;font-size:18px;margin-top:2px">{{ $P['name'] }} <x-ai-badge /></div>
          <div style="display:flex;align-items:center;gap:6px;justify-content:center;margin-top:6px;font-size:12px;color:var(--ink-dim)">
            <span class="mor-online"></span>
            <span x-text="thinking ? persona.busy : 'พร้อมรับฟังอยู่ค่ะ'">พร้อมรับฟังอยู่ค่ะ</span>
          </div>
          @if ($canSwitch)
            <a href="{{ route('chat.index', ['pick' => 1]) }}" class="room-switch">เปลี่ยนผู้พยากรณ์</a>
          @endif
        </div>
      </aside>

      {{-- ฝั่งขวา: ห้องแชท --}}
      <div class="panel chat-panel" style="position:relative" :class="{ 'is-open': sheet }">
        @if ($scene)
          {{-- มือถือ: แผงแชทเป็นแผ่นล่างใต้โต๊ะ — แตะแถบนี้เพื่อกางอ่านย้อนหลังเต็มจอ / พับกลับมาเห็นผู้พยากรณ์ --}}
          <button type="button" class="or-grip" @click="sheet = !sheet" :aria-expanded="sheet.toString()"
                  :aria-label="sheet ? 'พับแชทลง' : 'กางแชทเต็มจอ'"><i></i><span x-text="sheet ? 'พับลงดู' + persona.short : 'อ่านแชทย้อนหลัง'"></span></button>
        @endif

        <div class="mor-mini">
          <x-oracle-stage :persona="$room" :poster="$P['image']" :alt="$P['name']" eager />
          <div style="min-width:0;flex:1">
            <div style="font-family:var(--thai);font-weight:700;font-size:15px">{{ $P['name'] }} <x-ai-badge /></div>
            <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--ink-dim)">
              <span class="mor-online"></span>
              <span x-text="thinking ? persona.busy : 'ออนไลน์'">ออนไลน์</span>
            </div>
          </div>
          {{-- มือถือไม่เห็นเวทีใหญ่ (ซ่อนตาม breakpoint แต่ยังทำงาน) → ปุ่มลำโพงตรงนี้สั่งเวทีนั้นเปิด/ปิดเสียง --}}
          @if (\App\Support\OracleMedia::voice($room))
            <button type="button" class="oracle-mini-sound"
                    x-data="{ on: !!(window.JuntraVoice && window.JuntraVoice.on()) }"
                    @oracle-sound-state.window="on = $event.detail.on"
                    @click="window.dispatchEvent(new CustomEvent('oracle-sound-toggle'))"
                    :aria-pressed="on ? 'true' : 'false'"
                    :aria-label="on ? 'ปิดเสียง' + persona.short : 'เปิดเสียง' + persona.short"
                    aria-label="เปิดเสียง">
              <svg x-show="!on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="m23 9-6 6M17 9l6 6"/></svg>
              <svg x-show="on" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
            </button>
          @endif
          @if ($canSwitch)
            <a href="{{ route('chat.index', ['pick' => 1]) }}" class="room-switch">เปลี่ยน</a>
          @endif
        </div>

        <div class="chat-log" x-ref="log" @scroll="onScroll"
             role="log" aria-live="polite" aria-relevant="additions"
             aria-label="บทสนทนากับ{{ $P['name'] }}">

          <template x-for="m in messages" :key="m.id">
            <div class="msg" :class="'is-' + (m.state === 'system' ? 'system' : (m.role === 'user' ? 'user' : 'ai'))">
              <div class="msg-head" x-show="m.role === 'assistant' && m.state !== 'system'" x-text="persona.name">{{ $P['name'] }}</div>
              <div class="msg-body" x-show="m.kind !== 'topup'" x-html="m.html"></div>

              {{-- ═══ การ์ดแพ็กเกจเปิดไพ่ ═══════════════════════════════
                   ลูกค้าขอให้ทำนาย → แม่หมอยื่นแพ็กเกจ (คุยฟรี · หักเครดิตตอนเปิดไพ่จริงเท่านั้น)
                   ไพ่ยิปซี: POST ไปขั้นเลือกไพ่พร้อมคำถามที่เพิ่งพิมพ์ · เครดิตไม่พอ: เติมในแชทได้เลย --}}
              <template x-if="m.offers && m.offers.length">
                <div class="offer-cards">
                  <template x-for="o in m.offers" :key="o.key">
                    <div class="offer-card" :class="o.kind === 'deep' && 'is-deep'">
                      <div class="offer-top">
                        <span class="offer-icon" aria-hidden="true" x-text="o.kind === 'deep' ? '🌟' : '🔮'"></span>
                        <div class="offer-name" x-text="o.label"></div>
                        <span class="offer-meta" x-show="o.cards" x-text="o.cards + ' ใบ'"></span>
                      </div>
                      <div class="offer-blurb" x-show="o.blurb" x-text="o.blurb"></div>
                      <div class="offer-bottom">
                        <span class="offer-price" x-text="priceLabel(o.price)"></span>
                        <button type="button" class="btn btn-primary offer-go" x-show="canAfford(o)"
                                :disabled="!!m.offerBusy" @click="startOffer(m, o)"
                                x-text="m.offerBusy === o.key ? 'กำลังพาไป...' : (o.kind === 'deep' ? 'เริ่มดูดวง' : 'เลือกไพ่เลย')"></button>
                      </div>
                      {{-- เครดิตไม่พอ: ทางง่ายสุด = สแกนจ่ายเท่าที่ขาดแล้วพาไปเปิดไพ่เอง
                           (หลายคนไม่เข้าใจเรื่อง "เติมเครดิต") ส่วนคนคล่องยังเลือกเติมเก็บไว้ได้ --}}
                      <div class="offer-pay" x-show="!canAfford(o)">
                        <button type="button" class="btn btn-primary offer-go offer-pay-go" :disabled="!!m.offerBusy"
                                @click="canTopupInChat ? payAndOpen(m, o) : (window.location.href = topupUrl)"
                                x-text="payLabel(o)"></button>
                        <div class="offer-pay-note" x-show="canTopupInChat"
                             x-text="payAmount(o) > shortfall(o) ? 'จ่ายแล้วพาไปต่อให้เองค่ะ · ส่วนที่เกินเก็บเป็นเครดิตไว้ใช้ครั้งหน้า' : 'จ่ายแล้วพาไปต่อให้เองค่ะ'"></div>
                        <button type="button" class="offer-alt" x-show="canTopupInChat" @click="openTopup()">
                          หรือเติมเครดิตเก็บไว้หลายครั้ง
                        </button>
                      </div>
                    </div>
                  </template>
                  <div class="offer-foot">
                    เครดิตคงเหลือ <strong x-text="'฿' + Number(balance).toFixed(2)"></strong>
                    @if ($isFree) · คุยกับ{{ $P['short'] }}ฟรี หักเครดิตเฉพาะตอนเปิดไพ่ @endif
                  </div>
                </div>
              </template>

              {{-- ═══ การ์ดเติมเงินในแชท ═══════════════════════════════
                   จบในบทสนทนาเดียว ไม่พาลูกค้าออกไปหน้าอื่นแล้วหลงทาง
                   (กลุ่มลูกค้าหลักคือผู้สูงอายุ ทุกการเปลี่ยนหน้าคือจุดที่หลุด) --}}
              <template x-if="m.kind === 'topup'">
                <div class="pay-card">

                  {{-- ขั้น 1: เลือกจำนวนเงิน --}}
                  <template x-if="m.pay.step === 'choose'">
                    <div>
                      <div class="pay-title">เติมเครดิตเข้ากระเป๋า</div>
                      <div class="pay-sub">เลือกจำนวนเงินที่ต้องการ แล้วสแกน QR จ่ายได้เลยค่ะ</div>
                      <div class="pay-amounts">
                        <template x-for="b in bundles" :key="b">
                          <button type="button" class="pay-amount" :disabled="m.pay.busy"
                                  @click="chooseAmount(m, b)" x-text="'฿' + b.toLocaleString()"></button>
                        </template>
                      </div>
                      <div class="pay-sub" style="margin-top:10px;color:#f59999" x-show="m.pay.error" x-text="m.pay.error"></div>
                    </div>
                  </template>

                  {{-- ขั้น 2: สแกนจ่าย --}}
                  <template x-if="m.pay.step === 'qr'">
                    <div>
                      <div class="pay-title">สแกนจ่ายได้เลยค่ะ</div>
                      <div class="pay-sub pay-bill" x-show="m.pay.reference_code">
                        เลขที่บิล <strong x-text="m.pay.reference_code"></strong> · <span>ยังไม่ได้รับเงิน</span>
                      </div>
                      <div class="pay-sub">เปิดแอพธนาคาร → สแกน QR นี้ → โอนตามยอดด้านล่าง</div>

                      <div class="pay-qr">
                        <img :src="m.pay.qr" alt="QR พร้อมเพย์สำหรับเติมเงิน" x-show="m.pay.qr">
                      </div>

                      {{-- ยอดต้องเป๊ะถึงสตางค์ เพราะระบบใช้เศษสตางค์จับคู่กับ
                           SMS ธนาคารเพื่อเครดิตให้อัตโนมัติ --}}
                      <div class="pay-amount-due">
                        <span class="n" x-text="'฿' + Number(m.pay.payable).toFixed(2)"></span>
                        <span class="l" x-show="m.pay.auto_confirm">โอนยอดนี้ให้ตรงเป๊ะนะคะ ระบบจะเติมให้เองทันที</span>
                        <span class="l" x-show="!m.pay.auto_confirm">โอนแล้วแนบสลิปด้านล่างได้เลยค่ะ</span>
                      </div>

                      <div class="pay-sub" style="text-align:center;margin-top:8px" x-show="m.pay.promptpay_name">
                        เข้าบัญชี <strong x-text="m.pay.promptpay_name"></strong>
                      </div>

                      <div class="pay-sub" style="text-align:center;margin-top:8px" x-show="m.pay.then && !m.pay.superseded">
                        จ่ายแล้ว{{ $P['self'] }}พาไป<span x-text="m.pay.then?.offer.kind === 'deep' ? 'ดูดวง' : 'เลือกไพ่'"></span>
                        <strong x-text="m.pay.then?.offer.label"></strong> ให้เองเลยค่ะ
                      </div>

                      {{-- ถูกแทนด้วยการ์ดยอดใหม่ — หยุดรอที่ใบนี้ แต่ถ้าโอนไปแล้วเงินก็ยังเข้าตามปกติ --}}
                      <div class="pay-wait" x-show="!m.pay.superseded">
                        <span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span x-text="m.pay.waited < 600 ? persona.self + 'กำลังรอเงินเข้าอยู่ค่ะ...' : 'ยังไม่พบเงินเข้า — แนบสลิปให้' + persona.self + 'ได้เลยค่ะ'"></span>
                      </div>
                      <div class="pay-sub" style="text-align:center;margin-top:10px" x-show="m.pay.superseded">
                        ใช้ยอดใหม่ด้านล่างแทนนะคะ (ถ้าโอนยอดนี้ไปแล้ว ระบบเติมให้เองค่ะ)
                      </div>

                      <div class="pay-actions">
                        <button type="button" class="chip" @click="copyAmount(m, $event)">
                          <span class="chip-icon">📋</span> คัดลอกยอด
                        </button>
                        {{-- หา input ที่อยู่ในกล่องเดียวกัน แทน x-ref เพราะ x-ref
                             ผูกชื่อแบบคงที่ ใช้กับรายการที่วนซ้ำไม่ได้ --}}
                        <button type="button" class="chip chip-strong" :disabled="m.pay.uploading"
                                @click="$el.parentElement.querySelector('input[type=file]').click()">
                          <span class="chip-icon">🧾</span>
                          <span x-text="m.pay.uploading ? 'กำลังส่ง...' : 'แนบสลิป'"></span>
                        </button>
                        <input type="file" accept="image/*" @change="uploadSlip(m, $event)" style="display:none">
                      </div>
                      <div class="pay-sub" style="margin-top:8px;color:#f59999" x-show="m.pay.error" x-text="m.pay.error"></div>
                      <div class="pay-sub" style="margin-top:8px;color:#9eddae" x-show="m.pay.notice" x-text="m.pay.notice"></div>
                    </div>
                  </template>

                  {{-- ขั้น 3: สำเร็จ + บอกชัด ๆ ว่าไปต่อที่ไหนได้ --}}
                  <template x-if="m.pay.step === 'done'">
                    <div class="pay-done">
                      <div class="tick" aria-hidden="true">✓</div>
                      <div class="pay-title" style="margin-bottom:2px">เติมเครดิตสำเร็จแล้วค่ะ</div>
                      <div class="amt" x-text="'฿' + Number(m.pay.payable).toFixed(2)"></div>
                      <div class="pay-sub pay-bill" x-show="m.pay.reference_code">
                        เลขที่บิล <strong x-text="m.pay.reference_code"></strong> · <span style="color:#9eddae">ได้รับเงินแล้ว ✓</span>
                      </div>
                      {{-- จ่ายมาเพื่อเปิดแพ็กเกจนี้ → พาไปต่อเอง (ปุ่มสำรองเผื่อ redirect ไม่ไป) --}}
                      <template x-if="m.pay.then && canAfford(m.pay.then.offer)">
                        <div>
                          <div class="pay-sub" style="margin-top:8px">
                            <span x-text="m.pay.then.msg.offerBusy ? 'กำลังพาไป...' : 'พร้อมแล้วค่ะ'"></span>
                            <strong x-text="m.pay.then.offer.label"></strong>
                          </div>
                          <div class="pay-actions" style="justify-content:center">
                            <button type="button" class="btn btn-primary offer-go" :disabled="!!m.pay.then.msg.offerBusy"
                                    @click="startOffer(m.pay.then.msg, m.pay.then.offer)"
                                    x-text="m.pay.then.offer.kind === 'deep' ? 'เริ่มดูดวงเลย' : 'ไปเลือกไพ่เลย'"></button>
                          </div>
                        </div>
                      </template>
                      <div class="pay-sub" style="margin-top:8px" x-show="!(m.pay.then && canAfford(m.pay.then.offer))">
                        ยอดคงเหลือ <strong x-text="'฿' + Number(balance).toFixed(2)"></strong> — อยากให้{{ $P['self'] }}ดูเรื่องไหนต่อดีคะ
                      </div>
                      <div class="pay-actions" style="justify-content:center" x-show="!(m.pay.then && canAfford(m.pay.then.offer))">
                        <template x-for="s in nextSteps" :key="s.url">
                          <a :href="s.url" class="chip chip-strong">
                            <span class="chip-icon" x-text="s.icon"></span><span x-text="s.label"></span>
                          </a>
                        </template>
                        <button type="button" class="chip" @click="scrollToBottom(true); $refs.input?.focus()">
                          <span class="chip-icon">💬</span> คุยกับ{{ $P['short'] }}ต่อ
                        </button>
                      </div>
                    </div>
                  </template>
                </div>
              </template>
              <div class="msg-foot">
                <span class="msg-time" x-text="m.time"></span>

                {{-- คัดลอกคำตอบ — ของเดิมต้องลากเลือกเอง --}}
                <button type="button" class="icon-btn" x-show="m.role === 'assistant' && m.state === 'ok'"
                        @click="copy(m, $event)" :aria-label="'คัดลอกคำตอบ'" title="คัดลอก">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>
                  </svg>
                </button>

                {{-- ✨ พรีเมียมเสียง — ฟัง/ฟังซ้ำคำตอบนี้ (เปิดเสียงไว้ = เล่นเองทันที) --}}
                <button type="button" class="chip chip-ai" style="padding:4px 12px;font-size:12px"
                        x-show="m.voiceUrl" x-cloak @click="playReplyVoice(m.voiceUrl)"
                        :aria-label="'ฟังเสียง' + persona.short">🔊 ฟังเสียง</button>

                {{-- ส่งไม่สำเร็จ → ลองใหม่ด้วย idempotency key เดิม จึงไม่ถูกคิดเงินซ้ำ --}}
                <button type="button" class="chip" style="padding:4px 12px;font-size:12px"
                        x-show="m.state === 'failed'" @click="retry(m)">
                  ลองส่งใหม่
                </button>
              </div>
            </div>
          </template>

          <div class="msg is-ai" x-show="thinking" x-cloak>
            <div class="msg-head" x-text="persona.name">{{ $P['name'] }}</div>
            <div class="msg-body" style="display:flex;align-items:center;gap:8px">
              <span style="color:var(--ink-dim);font-size:13.5px" x-text="persona.busy">{{ $P['busy'] }}</span>
              <span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
            </div>
          </div>
        </div>

        <button type="button" class="chat-jump" x-show="!atBottom" x-cloak
                @click="scrollToBottom(true)" aria-label="เลื่อนไปข้อความล่าสุด">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>
        </button>

        @if ($readonly)
          <div style="text-align:center;padding:20px 8px 4px">
            <p style="color:var(--ink-dim);font-size:14px;margin-bottom:14px">นี่คือประวัติการสนทนา — เปิดอ่านได้อย่างเดียว</p>
            <a href="{{ route('chat.index', ['room' => $room]) }}" class="btn btn-primary">ไปคุยกับ{{ $P['short'] }}ต่อ
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
          </div>
        @elseif ($gate['allowed'])

          {{-- ═══ ปุ่มคำถามลัด ═══════════════════════════════════════════
               แถบนี้อยู่ตรงนี้เสมอ ไม่หายไปไหน เปลี่ยนแค่ "เนื้อใน" ตามสถานะ:

               1. คุยต่อไม่ได้ (ครบโควตา/เครดิตหมด) → เปลี่ยนเป็นปุ่มทางออก
                  ที่เป็นลิงก์จริง กดได้เสมอ ไม่ใช่ปุ่มตายที่กดแล้วเงียบ
               2. แม่หมอกำลังถามกลับ → ยุบเหลือปุ่มเดียว เพราะถ้าผู้ใช้กด
                  คำถามทั่วไปตอนนี้ บอทจะตีความเป็นคำตอบของคำถามที่ถามค้างไว้
                  แล้วโฟลว์ทำนายจะพัง — แต่ยังกดกางดูได้ ไม่ได้ล็อกทิ้ง
               3. ปกติ → แถวชิปเลื่อนแนวนอน + ปุ่มกางหมวดคำถามแบบเต็ม
               ระหว่างแม่หมอกำลังพิมพ์ ชิปถูก disable แต่ยัง "อยู่ที่เดิม"
               เพื่อไม่ให้เลย์เอาต์กระโดดและผู้ใช้ไม่งงว่าปุ่มหายไปไหน --}}
          <div class="chat-suggest">

            {{-- 0. 🌙 (2026-11-07) หมดเวลาถามต่อ → การ์ดปิดท้าย: คำแนะนำ · รีวิว · ฟัง/อ่านย้อนที่ไหน · ไปต่อทางไหน
                 ไม่ได้แตะอะไรเลยภายใน 1 นาที = ปิดห้องนี้แล้วเริ่มห้องใหม่ให้ (เจ้าของสั่ง) --}}
            <template x-if="blocked && qa && qa.over && cfg.qaWrap">
              <div class="qa-wrap" role="region" aria-label="จบการถามต่อของไพ่ชุดนี้"
                   @pointerdown="wrapTouch()" @keydown="wrapTouch()" @input="wrapTouch()">
                <div class="qa-wrap-head">
                  <b>🌙 จบการถามต่อของไพ่ชุดนี้แล้วค่ะ</b>
                  <p>ลองทำตามที่ไพ่แนะนำทีละขั้นนะคะ — คำทำนายฉบับเต็ม<span x-text="cfg.qaWrap.listen ? 'และเสียงเล่า' : ''"></span>เก็บไว้ให้แล้ว
                     กลับมา<span x-text="cfg.qaWrap.listen ? 'ฟัง' : 'อ่าน'"></span>ย้อนได้ทุกเมื่อที่ <a :href="cfg.qaWrap.history_url">ประวัติการดูดวง</a></p>
                </div>

                {{-- ⭐ รีวิว — แตะดาวได้ในห้องเลย ไม่ต้องออกไปหน้าอื่น --}}
                <div class="qa-wrap-review" x-show="cfg.qaWrap.review_url">
                  <template x-if="!wrap.reviewed">
                    <div>
                      <div class="qa-wrap-label" x-text="'คำทำนายครั้งนี้ตรงใจไหมคะ ให้ดาวบอก' + persona.self + 'หน่อยนะคะ'"></div>
                      <div class="aftercare-stars" role="radiogroup" aria-label="ให้ดาวคำทำนายนี้" @mouseleave="wrap.hover = 0">
                        <template x-for="i in 5" :key="i">
                          <button type="button" role="radio" :aria-checked="wrap.rating === i" :aria-label="i + ' ดาว'"
                                  :class="(wrap.hover || wrap.rating) >= i ? 'on' : ''"
                                  @mouseenter="wrap.hover = i" @click="wrap.rating = i">★</button>
                        </template>
                      </div>
                      <div class="qa-wrap-more" x-show="wrap.rating" x-cloak>
                        <textarea x-model="wrap.message" maxlength="500" rows="2" placeholder="อยากบอกอะไรเพิ่มไหมคะ (ไม่บังคับ)" aria-label="ข้อความรีวิว"></textarea>
                        <button type="button" class="btn btn-primary" :disabled="wrap.sending" @click="wrapReview()"
                                x-text="wrap.sending ? 'กำลังส่ง...' : 'ส่งรีวิว'">ส่งรีวิว</button>
                      </div>
                      <p class="qa-wrap-err" x-show="wrap.error" x-cloak x-text="wrap.error" role="alert"></p>
                    </div>
                  </template>
                  <template x-if="wrap.reviewed">
                    <p class="aftercare-ok" role="status" x-text="'ขอบคุณที่บอก' + persona.self + 'นะคะ 💜 ' + '★'.repeat(wrap.rating || 0)"></p>
                  </template>
                </div>

                {{-- ไปต่อทางไหน --}}
                <div class="chip-row qa-wrap-go">
                  <a :href="cfg.qaWrap.result_url" class="chip chip-strong"><span class="chip-icon" x-text="cfg.qaWrap.listen ? '🎧' : '📜'"></span> <span x-text="cfg.qaWrap.listen ? 'ฟังคำทำนายย้อน' : 'อ่านคำทำนายย้อน'"></span></a>
                  <a :href="cfg.freshUrl" class="chip chip-strong"><span class="chip-icon">💬</span> คุยเรื่องอื่นต่อ</a>
                  <a href="{{ route('tarot.index') }}" class="chip"><span class="chip-icon">🔮</span> เปิดไพ่ชุดใหม่</a>
                  <a href="{{ route('home') }}" class="chip"><span class="chip-icon">🏠</span> ไปดูอย่างอื่น</a>
                </div>

                <p class="qa-wrap-close" x-show="wrap.left > 0" aria-live="off">
                  ถ้าไม่ได้เลือกอะไร ห้องนี้จะปิดแล้วเริ่มใหม่ให้ในอีก <b x-text="wrap.left">60</b> วินาที
                  <button type="button" class="qa-wrap-now" @click="wrapClose()">ปิดห้องเลย</button>
                </p>
              </div>
            </template>

            {{-- 1. ทางออกเมื่อคุยต่อไม่ได้ --}}
            <template x-if="blocked && !(qa && qa.over && cfg.qaWrap)">
              <div>
                <div class="chat-suggest-label">
                  <span x-text="blockedReason || 'วันนี้คุยครบแล้ว — ลองทางนี้ต่อได้เลยค่ะ'"></span>
                </div>
                <div class="chip-row">
                  <a :href="cfg.freshUrl" class="chip chip-strong" x-show="qa && qa.over"><span class="chip-icon">💬</span> คุยเรื่องอื่นต่อ</a>
                  <a href="{{ route('tarot.index') }}" class="chip chip-strong"><span class="chip-icon">🔮</span> เปิดไพ่ยิปซี</a>
                  <a href="{{ route('tarot.free') }}" class="chip"><span class="chip-icon">✨</span> ดูดวงฟรี 1 ใบ</a>
                  @serviceopen('deep')<a href="{{ route('deep.index') }}" class="chip chip-strong"><span class="chip-icon">🌟</span> ดูดวงเชิงลึก</a>@endserviceopen
                  @serviceopen('horoscope')<a href="{{ route('horoscope.index') }}" class="chip"><span class="chip-icon">🌙</span> ดวงรายวัน</a>@endserviceopen
                  @serviceopen('numerology')<a href="{{ route('numerology.index') }}" class="chip"><span class="chip-icon">🔢</span> เลขศาสตร์</a>@endserviceopen
                  @serviceopen('auspicious')<a href="{{ route('auspicious.index') }}" class="chip"><span class="chip-icon">📿</span> ฤกษ์ยาม</a>@endserviceopen
                  {{-- เติมเงินจบในแชท ไม่พาออกไปหน้าอื่น (ถ้าเปิดการ์ดไม่ได้
                       ค่อยตกไปเป็นลิงก์หน้าเติมเงินตามเดิม) --}}
                  <button type="button" class="chip chip-strong" x-show="canTopupInChat" @click="openTopup()">
                    <span class="chip-icon">💳</span> เติมเครดิตที่นี่
                  </button>
                  <a href="{{ route('wallet.topup') }}" class="chip" x-show="!canTopupInChat"><span class="chip-icon">💳</span> เติมเงิน</a>
                </div>
              </div>
            </template>

            {{-- 2. แม่หมอกำลังรอคำตอบ → ยุบไว้ก่อน --}}
            {{-- 2a. ✨ ผู้พยากรณ์ถามกลับ + AI คิดตัวเลือกคำตอบมาให้ → กดตอบได้เลย --}}
            <template x-if="!blocked && awaiting && !suggestOpen && aiSuggestions.length">
              <div>
                <div class="chat-suggest-label"><span>💬 ตอบ{{ $P['self'] }}ได้เลย หรือพิมพ์เองก็ได้ค่ะ</span></div>
                <div class="chip-row">
                  <template x-for="(q, i) in aiSuggestions" :key="'a' + i">
                    <button type="button" class="chip chip-ai" :disabled="thinking" @click="useChip(q)" x-text="q"></button>
                  </template>
                </div>
              </div>
            </template>
            <template x-if="!blocked && awaiting && !suggestOpen && !aiSuggestions.length">
              <div class="chat-suggest-hint">
                <span>💬 {{ $P['self'] }}กำลังรอคำตอบอยู่ค่ะ</span>
                <button type="button" class="chip" style="padding:5px 12px;font-size:12px" @click="suggestOpen = true">
                  ดูคำถามแนะนำ
                </button>
              </div>
            </template>

            {{-- 3. ปกติ --}}
            <template x-if="!blocked && (!awaiting || suggestOpen)">
              <div>
                {{-- ✨ (2026-11-05) คำถามที่ผู้พยากรณ์คิดล่วงหน้าจากบทสนทนา — เพิ่มจากปุ่มลัดเดิม --}}
                <template x-if="!awaiting && aiSuggestions.length">
                  <div>
                    <div class="chat-suggest-label"><span>✨ ถามต่อจากเรื่องนี้</span></div>
                    <div class="chip-row" style="margin-bottom:8px">
                      <template x-for="(q, i) in aiSuggestions" :key="'q' + i">
                        <button type="button" class="chip chip-ai" :disabled="thinking"
                                :title="thinking ? persona.self + 'กำลังตอบอยู่ — รอสักครู่นะคะ' : q"
                                @click="useChip(q)" x-text="q"></button>
                      </template>
                    </div>
                  </div>
                </template>
                <div class="chat-suggest-label">
                  <span x-text="messages.some(m => m.role === 'user') ? 'ถามต่อได้เลย' : 'เริ่มจากคำถามเหล่านี้ก็ได้ค่ะ'"></span>
                </div>
                <div class="chip-row">
                  <template x-for="(s, i) in suggestions" :key="i">
                    <button type="button" class="chip"
                            :disabled="thinking"
                            :title="thinking ? persona.self + 'กำลังตอบอยู่ — รอสักครู่นะคะ' : s.prompt"
                            @click="useChip(s.prompt)">
                      <span class="chip-icon" x-text="s.icon" aria-hidden="true"></span>
                      <span x-text="s.label"></span>
                    </button>
                  </template>
                  <button type="button" class="chip" x-show="topics.length && !crisisRoom" :disabled="thinking"
                          @click="showTopics = !showTopics"
                          :aria-expanded="showTopics.toString()">
                    <span class="chip-icon" aria-hidden="true">⋯</span>
                    <span x-text="showTopics ? 'ปิดหมวดคำถาม' : 'หมวดคำถาม'"></span>
                  </button>
                </div>

                {{-- ตั้งใจไม่ใส่ x-transition: ถ้า transition ไม่จบ (แท็บพื้นหลัง /
                     เบราว์เซอร์ไม่วาดเฟรม) อิลิเมนต์จะค้างที่ opacity:0 = ผู้ใช้กด
                     ปุ่มแล้วเหมือนไม่มีอะไรเกิดขึ้น ซึ่งคือสิ่งที่ต้องเลี่ยงที่สุดในหน้านี้ --}}
                <div class="chat-topics" x-show="showTopics && !crisisRoom" x-cloak>
                  <template x-for="t in topics" :key="t.key">
                    <div>
                      <div class="chat-topic-name"><span x-text="t.icon"></span> <span x-text="t.label"></span></div>
                      <div class="chip-wrap">
                        <template x-for="(p, i) in t.prompts" :key="i">
                          <button type="button" class="chip" :disabled="thinking"
                                  @click="useChip(p); showTopics = false" x-text="p"></button>
                        </template>
                      </div>
                    </div>
                  </template>
                </div>
              </div>
            </template>
          </div>

          {{-- ═══ ช่องพิมพ์ ═══════════════════════════════════════════ --}}
          <form @submit.prevent="send()" class="field" style="margin-bottom:0">
            @csrf
            <div class="chat-composer">
              <textarea
                x-ref="input"
                x-model="draft"
                @input="autoGrow"
                @keydown.enter="onEnter($event)"
                rows="1"
                maxlength="2000"
                :disabled="blocked"
                :title="blocked ? (blockedReason || 'วันนี้คุยครบโควตาแล้วค่ะ') : ''"
                :placeholder="blocked
                    ? (blockedReason || 'วันนี้คุยครบแล้ว — พรุ่งนี้มาคุยกันใหม่นะคะ')
                    : 'พิมพ์คุยกับ' + persona.short + 'ได้เลย... (Shift+Enter ขึ้นบรรทัดใหม่)'"
                autocomplete="off"
                aria-label="ข้อความถึง{{ $P['short'] }}"></textarea>

              {{-- 🎙️ (2026-11-07) กดพูดแทนพิมพ์ — ได้ข้อความลงช่องพิมพ์ ลูกค้าตรวจแล้วกดส่งเอง · ซ่อนถ้าเครื่องนี้ใช้ไมค์ไม่ได้ --}}
              <button type="button" class="chat-mic" x-show="mic.supported" x-cloak
                      :class="{ 'is-on': mic.state === 'listening', 'is-busy': mic.state === 'working' }"
                      :disabled="thinking || blocked || mic.state === 'working'"
                      @click="micToggle()"
                      :title="mic.state === 'listening' ? 'แตะอีกครั้งเพื่อหยุดฟัง' : (mic.state === 'working' ? 'กำลังแปลงเสียงเป็นข้อความ...' : 'กดแล้วพูดได้เลย')"
                      :aria-label="mic.state === 'listening' ? 'หยุดฟัง' : 'พูดแทนพิมพ์'"
                      :aria-pressed="mic.state === 'listening' ? 'true' : 'false'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
              </button>

              <button type="submit" class="btn btn-primary chat-send"
                      :disabled="thinking || blocked || !draft.trim()"
                      :title="blocked ? (blockedReason || 'วันนี้คุยครบโควตาแล้ว')
                              : (thinking ? persona.self + 'กำลังตอบอยู่' : (!draft.trim() ? 'พิมพ์ข้อความก่อนส่ง' : 'ส่งข้อความ'))"
                      aria-label="ส่งข้อความ">
                <svg x-show="!thinking" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                <svg x-show="thinking" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="animation:morPulse 1.2s infinite"><circle cx="12" cy="12" r="9"/></svg>
              </button>
            </div>
            <div class="chat-mic-note" x-show="mic.note" x-cloak x-text="mic.note" role="status" aria-live="polite"></div>
            <div class="chat-counter" x-show="draft.length > 1700" x-cloak>
              <span x-text="draft.length"></span> / 2000
            </div>
          </form>
        @else
          {{-- gate ไม่ผ่าน: ไม่โชว์ช่องพิมพ์ที่กดไม่ได้ให้เก้อ — CTA อยู่ด้านบนแล้ว --}}
          <div style="text-align:center;padding:18px 8px 4px;color:var(--ink-dim);font-size:14px">
            เข้าสู่ระบบด้านบนเพื่อเริ่มคุยกับ{{ $P['short'] }}
          </div>
        @endif
      </div>
    </div>

    @if ($gate['allowed'] && ! $scene)
      <div style="text-align:center;margin-top:18px;font-family:var(--display);font-size:11px;color:var(--ink-dim);letter-spacing:.22em;text-transform:uppercase">
        powered by Thaiprompt Fortune Bot · ระบบเดียวกับ FB MESSENGER / LINE OA
      </div>
    @endif
  </div>
</section>

@push('scripts')
@if ($scene)
<script>
// 🏛️ จัดฉากห้องพยากรณ์: ภาพโต๊ะเต็มจอ (cover) ให้กลางโต๊ะตรงกับพื้นที่ว่างข้างแผงแชท แล้ววางผู้พยากรณ์ยืนหลังขอบโต๊ะ
(() => {
  const shell = document.querySelector('.chat-shell.is-room');
  if (!shell) return;
  document.documentElement.classList.add('or-open');
  let plate;
  try { plate = JSON.parse(shell.dataset.plate); } catch (e) { return; }
  const scene = shell.querySelector('.or-scene');
  const bg = shell.querySelector('.or-plate');
  const actor = shell.querySelector('.or-actor');
  const panel = shell.querySelector('.chat-panel');
  const place = () => {
    const W = shell.clientWidth; const H = shell.clientHeight;
    const wide = W >= 900;
    // จอกว้าง: แผงแชทชิดขวา → กลางโต๊ะเลื่อนไปกลางพื้นที่ที่เหลือ (ขยายภาพเท่าที่จำเป็นให้ยังเต็มจอ)
    const pw = panel ? panel.getBoundingClientRect().width : 0;   // ยังไม่ล็อกอิน = ไม่มีแผงแชท → ผู้พยากรณ์อยู่กลางจอ
    const side = wide && pw > 0 ? pw + 40 : 0;
    const cx = (W - side) / 2;
    const s = Math.max(W / plate.w, H / plate.h, (2 * Math.max(cx, W - cx)) / plate.w);
    const iw = plate.w * s; const ih = plate.h * s;
    const x = Math.min(0, Math.max(W - iw, cx - iw / 2));
    const y = (H - ih) * plate.posY;
    Object.assign(bg.style, { backgroundSize: `${iw}px ${ih}px`, backgroundPosition: `${x}px ${y}px` });
    const ty = y + plate.table * ih;
    const top = wide ? 96 : 70;
    const hgt = Math.max(90, Math.min(H * (wide ? 0.62 : 0.5), ty - top));
    Object.assign(actor.style, { top: `${ty - hgt}px`, height: `${hgt}px`, width: `${Math.round(Math.min(W, hgt * 0.9))}px`, left: `${x + iw / 2}px` });
    shell.style.setProperty('--or-table', `${ty}px`);
    shell.style.setProperty('--or-cx', `${x + iw / 2}px`);
    scene.classList.add('is-ready');
  };
  const img = new Image();
  img.onload = place; img.src = plate.src;
  window.addEventListener('resize', place);
  if (window.ResizeObserver && panel) new ResizeObserver(place).observe(panel);
  place();
})();
</script>
@endif
<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('chatBot', (cfg) => ({
    messages: cfg.messages || [],
    room: cfg.room || 'maemor',
    persona: cfg.persona || { name: 'แม่หมอจันทรา', short: 'แม่หมอ', self: 'แม่หมอ', busy: 'กำลังเพ่งไพ่...' },
    suggestions: cfg.suggestions || [],
    aiSuggestions: cfg.aiSuggestions || [],
    // 🔒/🧾 ป้ายบนโต๊ะ: การทำนายที่ค้าง (เวลาหมดจากเซิร์ฟเวอร์) และบิลเติมเงินที่ค้าง
    hold: {
      lockEnds: cfg.roomLock ? cfg.roomLock.ends_at : null,
      bill: cfg.pendingBill || null,
      billEnds: cfg.pendingBill ? cfg.pendingBill.expires_at : null,
      billPaid: false, billSlip: !!(cfg.pendingBill && cfg.pendingBill.has_slip), billBusy: false,
      confirmEnd: false,
    },
    // นาฬิกาเซิร์ฟเวอร์ − นาฬิกาเครื่อง (วินาที) — นับถอยหลังตรงกับเวลาจริงแม้เครื่องลูกค้าตั้งเวลาเพี้ยน
    skew: (cfg.serverNow || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000),
    nowSec: Math.floor(Date.now() / 1000),
    clockTimer: null,
    billTimer: null,
    // ⏳ นาฬิกาถามต่อ (null = ห้องคุยทั่วไป) · ยืนยัน "พอแล้ว" ด้วยการแตะสองครั้ง
    qa: cfg.qa || null,
    qaBusy: false,
    qaConfirm: false,
    qaWarned: false,
    // 🌙 การ์ดปิดท้ายหลังหมดเวลาถามต่อ — นับถอยหลังปิดห้อง (แตะอะไรในการ์ด = เริ่มนับใหม่)
    wrap: {
      left: 0, timer: null, hover: 0, message: '', sending: false, error: '',
      rating: (cfg.qaWrap && cfg.qaWrap.rating) || 0,
      reviewed: !!(cfg.qaWrap && cfg.qaWrap.reviewed),
    },
    // 💤 ลูกค้าเงียบนาน → ผู้พยากรณ์ทักตาม (ข้อความในหน้าเว็บ ไม่ถาม AI ไม่คิดเงิน)
    lastActive: Date.now(),
    nudges: 0,
    cfg,
    topics: cfg.topics || [],
    awaiting: !!cfg.awaiting,
    // 🤍 ลูกค้าที่ส่งสัญญาณอยากทำร้ายตัวเอง — ไม่มีแพ็กเกจบนโต๊ะ ปุ่มชวนดูดวง หมวดคำถาม หรือเสียงประโยคประจำ
    crisisRoom: !!cfg.crisisRoom,
    dailyLimit: cfg.dailyLimit || 0,
    dailyLeft: cfg.dailyLeft || 0,
    blocked: !!cfg.blocked,
    blockedReason: '',
    // 🎁 (2026-07-28) เตือนเรื่องเริ่มหักเงินไปแล้วหรือยัง (ครั้งเดียวต่อการเปิดหน้า)
    costNotified: false,
    draft: '',
    thinking: false,
    showTopics: false,
    suggestOpen: false,
    atBottom: true,
    seq: 0,
    balance: @js((float) ($balance ?? 0)),
    bundles: cfg.bundles || [50, 100, 200, 500],
    nextSteps: cfg.nextSteps || [],
    canTopupInChat: !!cfg.canTopupInChat,
    topupUrl: cfg.topupUrl,
    payTimer: null,
    // ห้องพยากรณ์: แพ็กเกจบนโต๊ะ (ทำตัวเหมือนข้อความที่มีการ์ด — ใช้ startOffer/payAndOpen ตัวเดิม) · แผ่นแชทมือถือกางอยู่ไหม
    table: { offerBusy: false, question: '', menu: !!cfg.menuOpen },
    sheet: false,

    // 🎙️ ไมค์: เบราว์เซอร์ถอดเองก่อน (ฟรี) · ถอดเองไม่ได้ → อัดเสียง ≤ 25 วิ ส่งให้เซิร์ฟเวอร์ถอด
    mic: { supported: false, mode: null, state: 'idle', note: '', rec: null, media: null, chunks: [], stopTimer: null, base: '' },

    canRecord() {
      return !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && this.cfg.transcribeUrl);
    },
    micSetup() {
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      this.mic.mode = SR ? 'speech' : (this.canRecord() ? 'record' : null);
      this.mic.supported = !!this.mic.mode;
    },
    micToggle() {
      if (this.mic.state === 'listening') { this.micStop(); return; }
      if (this.mic.state !== 'idle' || this.thinking || this.blocked) return;
      this.mic.note = '';
      this.mic.base = this.draft.trim() ? this.draft.trim() + ' ' : '';
      this.mic.mode === 'speech' ? this.micSpeech() : this.micRecord();
    },
    micDone() {
      this.$nextTick(() => {
        this.autoGrow(); if (this.$refs.input) this.$refs.input.focus();
      });
    },
    micSpeech() {
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      let r;
      try { r = new SR(); } catch (e) { this.micFallback(); return; }
      r.lang = 'th-TH'; r.interimResults = true; r.continuous = false; r.maxAlternatives = 1;
      let finalText = '';
      let fellBack = false;
      r.onresult = (ev) => {
        let interim = '';
        for (let i = ev.resultIndex; i < ev.results.length; i++) {
          const t = ev.results[i][0].transcript;
          if (ev.results[i].isFinal) finalText += t; else interim += t;
        }
        this.draft = (this.mic.base + finalText + interim).slice(0, 2000);
      };
      r.onerror = (ev) => {
        if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') {
          // service-not-allowed = เบราว์เซอร์ในแอป (FB/LINE) มี API แต่ไม่ให้ใช้ → ลองอัดเสียงส่งเซิร์ฟเวอร์แทน
          if (ev.error === 'service-not-allowed' && this.canRecord()) { fellBack = true; return; }
          this.mic.note = 'ยังไม่ได้อนุญาตให้ใช้ไมค์ค่ะ — กดอนุญาตในเบราว์เซอร์ หรือพิมพ์แทนได้เลยนะคะ';
        } else if (ev.error === 'network' || ev.error === 'audio-capture' || ev.error === 'language-not-supported') {
          if (this.canRecord()) { fellBack = true; return; }
          this.mic.note = 'เครื่องนี้ฟังเสียงไม่ได้ตอนนี้ค่ะ พิมพ์แทนได้เลยนะคะ';
        } else if (ev.error === 'no-speech') {
          this.mic.note = 'ยังไม่ได้ยินเสียงเลยค่ะ ลองกดแล้วพูดใหม่นะคะ';
        }
      };
      r.onend = () => {
        if (this.mic.rec !== r) return;   // ออกจากหน้าไปแล้ว
        this.mic.rec = null;
        this.mic.state = 'idle';
        if (fellBack) { this.micFallback(); return; }
        this.micDone();
      };
      this.mic.rec = r;
      this.mic.state = 'listening';
      try { r.start(); } catch (e) { this.mic.rec = null; this.mic.state = 'idle'; this.micFallback(); }
    },
    micFallback() {
      this.mic.rec = null;
      this.mic.state = 'idle';
      if (this.canRecord()) {
        this.mic.mode = 'record';   // ครั้งต่อไปอัดเสียงเลย ไม่ลองถอดในเครื่องซ้ำ
        this.micRecord();
      } else {
        this.mic.note = 'เครื่องนี้ใช้ไมค์กับหน้านี้ไม่ได้ค่ะ พิมพ์คุยได้เลยนะคะ';
        this.mic.supported = false;
      }
    },
    async micRecord() {
      this.mic.state = 'working';   // กันแตะซ้ำระหว่างรอถามสิทธิ์ไมค์
      let stream;
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch (e) {
        this.mic.state = 'idle';
        this.mic.note = 'ยังไม่ได้อนุญาตให้ใช้ไมค์ค่ะ — กดอนุญาตในเบราว์เซอร์ หรือพิมพ์แทนได้เลยนะคะ';
        return;
      }
      const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
      const type = types.find((t) => MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) || '';
      let rec;
      try { rec = new MediaRecorder(stream, type ? { mimeType: type, audioBitsPerSecond: 32000 } : undefined); }
      catch (e) {
        stream.getTracks().forEach((t) => t.stop());
        this.mic.state = 'idle';
        this.mic.note = 'เครื่องนี้อัดเสียงไม่ได้ค่ะ พิมพ์แทนได้เลยนะคะ';
        return;
      }
      this.mic.chunks = [];
      this.mic.media = stream;
      rec.ondataavailable = (ev) => { if (ev.data && ev.data.size) this.mic.chunks.push(ev.data); };
      rec.onstop = () => {
        stream.getTracks().forEach((t) => t.stop());
        this.mic.media = null;
        clearTimeout(this.mic.stopTimer);
        if (this.mic.rec !== rec) return;   // ออกจากหน้าไปแล้ว
        this.mic.rec = null;
        this.micUpload(new Blob(this.mic.chunks, { type: rec.mimeType || type || 'audio/webm' }));
      };
      this.mic.rec = rec;
      this.mic.state = 'listening';
      this.mic.note = 'กำลังฟังอยู่ค่ะ… พูดจบแล้วแตะไมค์อีกครั้ง';
      rec.start();
      this.mic.stopTimer = setTimeout(() => this.micStop(), 25000);   // ไม่เกิน 25 วิ (เพดานไฟล์ของเซิร์ฟเวอร์)
    },
    micStop() {
      const r = this.mic.rec;
      if (!r) { this.mic.state = 'idle'; return; }
      clearTimeout(this.mic.stopTimer);
      if (this.mic.mode === 'record') { this.mic.state = 'working'; this.mic.note = 'กำลังแปลงเสียงเป็นข้อความ…'; }
      try { r.stop(); } catch (e) { this.mic.rec = null; this.mic.state = 'idle'; }
    },
    async micUpload(blob) {
      if (!blob || blob.size < 1500) { this.mic.state = 'idle'; this.mic.note = 'สั้นไปนิดค่ะ ลองกดแล้วพูดใหม่นะคะ'; return; }
      if (blob.size > 700 * 1024) { this.mic.state = 'idle'; this.mic.note = 'ยาวเกินไปค่ะ ลองพูดสั้น ๆ ทีละเรื่องนะคะ'; return; }
      const fd = new FormData();
      fd.append('audio', blob, 'mic.' + (blob.type.includes('mp4') ? 'm4a' : (blob.type.includes('ogg') ? 'ogg' : 'webm')));
      fd.append('room', this.room);
      this.mic.state = 'working';
      try {
        const res = await fetch(this.cfg.transcribeUrl, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
          body: fd,
        });
        const j = await res.json().catch(() => ({}));
        if (res.ok && j.text) {
          this.draft = (this.mic.base + j.text).slice(0, 2000);
          this.mic.note = 'ตรวจข้อความแล้วกดส่งได้เลยค่ะ';
          this.micDone();
        } else {
          this.mic.note = res.status === 419 ? 'หน้านี้เปิดค้างนานไปค่ะ รีเฟรชหน้าแล้วลองใหม่นะคะ'
            : (j.message && res.status !== 429 ? j.message
            : (res.status === 429 ? (j.message && !/Too Many/i.test(j.message) ? j.message : 'ใช้ไมค์ถี่ไปนิดค่ะ รอสักครู่แล้วลองใหม่นะคะ')
            : 'ถอดเสียงไม่สำเร็จ ลองพูดใหม่หรือพิมพ์แทนนะคะ'));
        }
      } catch (e) {
        this.mic.note = 'เน็ตสะดุดค่ะ ลองใหม่อีกครั้งหรือพิมพ์แทนนะคะ';
      } finally {
        this.mic.state = 'idle';
      }
    },

    destroy() {
      // ปิดไมค์ทุกครั้งที่ออกจากหน้า — ไม่งั้นไฟแดงไมค์ค้าง
      const r = this.mic.rec; this.mic.rec = null;
      clearTimeout(this.mic.stopTimer);
      try { r && (r.abort ? r.abort() : r.stop()); } catch (e) {}
      if (this.mic.media) this.mic.media.getTracks().forEach((t) => t.stop());
      clearInterval(this.wrap.timer);
      clearInterval(this.clockTimer);
      clearInterval(this.billTimer);
      clearInterval(this.payTimer);   // ออกจากหน้าแล้วต้องเลิก poll
    },

    /** วินาทีที่เหลือถึงเวลา ts (epoch วินาทีของเซิร์ฟเวอร์) */
    left(ts) { return ts ? Math.max(0, ts - (this.nowSec + this.skew)) : 0; },
    /** mm:ss ที่เหลือถึงเวลา ts */
    clock(ts) {
      const s = this.left(ts);
      // บิลเติมเงินอยู่ได้หลายชั่วโมง — "2878:47" อ่านไม่ออก เกินชั่วโมงแสดงเป็น ชม./นาที
      if (s >= 3600) {
        const h = Math.floor(s / 3600);
        return h + ' ชม. ' + Math.floor((s % 3600) / 60) + ' นาที';
      }
      return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    },
    /** ทุกวินาที: เวลาถามต่อหมด → ปิดห้อง · เหลือ 1 นาที → เตือนครั้งเดียว · แล้วดูว่าลูกค้าเงียบไปนานไหม */
    qaTick() {
      const q = this.qa;
      if (q && q.started && !q.over && q.ends_at) {
        const l = this.left(q.ends_at);
        if (l <= 60 && l > 0 && !this.qaWarned && !this.thinking) {
          this.qaWarned = true;
          this.push('assistant', this.escape('อีกราว 1 นาทีจะหมดเวลาถามต่อของไพ่ชุดนี้แล้วนะคะ มีอะไรค้างใจ ถามมาได้เลยค่ะ ⏳'), 'ok');
          this.scrollToBottom();
        }
        if (l === 0) this.qaOver(true);
      }
      this.idleTick();
    },
    qaOver(announce = false) {
      if (!this.qa) return;
      const was = this.qa.over;
      this.qa.over = true;
      this.wrapOpen();
      this.blocked = true;
      this.blockedReason = 'หมดเวลาถามต่อของไพ่ชุดนี้แล้วค่ะ — ไปต่อทางไหนดี เลือกได้เลยนะคะ';
      if (announce && !was) {
        this.push('assistant', this.escape(cfg.qaOverText), 'ok');
        this.voice('thanks_bye', false);
        this.scrollToBottom(true);
      }
    },
    wrapOpen() {
      const w = cfg.qaWrap;
      if (!w || this.wrap.timer) return;
      this.wrap.left = w.close_seconds || 60;
      this.wrap.timer = setInterval(() => {
        if (this.wrap.sending) return;   // กำลังส่งรีวิว — อย่าปิดห้องทับ
        this.wrap.left = Math.max(0, this.wrap.left - 1);
        if (this.wrap.left === 0) this.wrapClose();
      }, 1000);
    },
    /** ลูกค้ายังอยู่ (แตะดาว/พิมพ์/เลื่อนในการ์ด) → นับใหม่ ไม่ปิดห้องต่อหน้า */
    wrapTouch() {
      if (this.wrap.timer) this.wrap.left = (cfg.qaWrap && cfg.qaWrap.close_seconds) || 60;
    },
    /** ปิดห้องของไพ่ชุดนี้ → ห้องเดิมแบบเริ่มใหม่ (ไพ่ชุดนี้ยังอยู่ในประวัติ) */
    wrapClose() {
      clearInterval(this.wrap.timer);
      this.wrap.timer = null;
      this.wrap.left = 0;
      window.location.href = cfg.freshUrl;
    },
    async wrapReview() {
      const w = cfg.qaWrap;
      if (!w || !w.review_url || !this.wrap.rating || this.wrap.sending) return;
      this.wrap.sending = true;
      this.wrap.error = '';
      try {
        const r = await fetch(w.review_url, {
          method: 'POST', credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: JSON.stringify({ rating: this.wrap.rating, message: this.wrap.message.trim() }),
        });
        const j = await r.json().catch(() => ({}));
        if (r.ok) {
          this.wrap.reviewed = true;
        } else {
          const first = j.errors ? Object.values(j.errors)[0] : null;
          this.wrap.error = r.status === 419 ? 'หน้านี้เปิดค้างนานไปค่ะ รีเฟรชหน้าแล้วลองใหม่นะคะ'
            : (Array.isArray(first) ? first[0] : 'ส่งรีวิวไม่สำเร็จ ลองใหม่อีกครั้งนะคะ');
        }
      } catch (_) {
        this.wrap.error = 'เน็ตสะดุดค่ะ ลองใหม่อีกครั้งนะคะ';
      } finally {
        this.wrap.sending = false;
        this.wrapTouch();
      }
    },
    setQa(q, serverNow) {
      if (serverNow) this.skew = serverNow - Math.floor(Date.now() / 1000);
      if (!q) return;
      this.qa = q;
      if (q.over) this.qaOver(false);
    },
    /** "พอแล้ว" — แตะครั้งแรกถามยืนยัน แตะซ้ำภายใน 4 วิ = จบ */
    async endQa() {
      if (!this.qa || this.qa.over || this.qaBusy) return;
      if (!this.qaConfirm) {
        this.qaConfirm = true;
        setTimeout(() => { this.qaConfirm = false; }, 4000);
        return;
      }
      this.qaConfirm = false;
      this.qaBusy = true;
      try {
        const r = await fetch(cfg.qaEndUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: JSON.stringify({ room: this.room }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { this.say(j.error || 'ยังจบไม่ได้ ลองใหม่อีกครั้งนะคะ'); return; }
        if (j.reply) this.push('assistant', j.reply_html || this.escape(j.reply), 'ok', j.reply);
        this.voice('thanks_bye', false);
        this.setQa(j.qa, j.server_now);
        this.scrollToBottom(true);
      } catch (_) {
        this.say('เครือข่ายมีปัญหาค่ะ ลองใหม่อีกครั้งนะคะ');
      } finally {
        this.qaBusy = false;
      }
    },
    /** ลูกค้าเงียบนาน → ทักตาม 1 ครั้ง (ห้องคุยทั่วไป) / 2 ครั้ง (ระหว่างเวลาถามต่อ ครั้งที่สองชวนกด "พอแล้ว") */
    idleTick() {
      if (!cfg.idleNudge || this.thinking || this.blocked || document.hidden || this.draft.trim()) return;
      const last = this.messages[this.messages.length - 1];
      if (!last || last.role !== 'assistant' || last.kind === 'topup') return;
      const inQa = !!(this.qa && this.qa.started && !this.qa.over);
      const max = inQa ? 2 : 1;
      const need = cfg.idleNudge + (this.nudges > 0 ? 120 : 0);
      if (this.nudges >= max || (Date.now() - this.lastActive) / 1000 < need) return;
      this.nudges++;
      this.lastActive = Date.now();
      const self = this.persona.self;
      let text;
      if (inQa && this.nudges === 1) {
        const mins = Math.max(1, Math.ceil(this.left(this.qa.ends_at) / 60));
        text = 'ยังอยู่กับ' + self + 'ไหมคะ 🌙 ยังถามต่อได้อีกราว ' + mins + ' นาทีนะคะ กดคำถามด้านล่างหรือพิมพ์มาได้เลยค่ะ';
      } else if (inQa) {
        text = 'ถ้าได้คำตอบครบแล้ว กดปุ่ม "พอแล้ว" ด้านบนได้เลยนะคะ ' + self + 'ขอให้ทุกอย่างคลี่คลายค่ะ 🙏';
      } else if (this.crisisRoom) {
        text = self + 'ยังอยู่ตรงนี้นะคะ 🤍 อยากเล่าอะไรเพิ่ม พิมพ์มาได้เลย ' + self + 'รอฟังค่ะ';
      } else {
        text = 'ยังอยู่ไหมคะ 🌙 อยากให้' + self + 'ช่วยดูเรื่องไหนต่อ เลือกจากปุ่มด้านล่าง หรือพิมพ์เล่ามาได้เลยค่ะ';
      }
      this.push('assistant', this.escape(text), 'ok', text, { kind: 'nudge' });
      if (this.nudges === 1 && !this.crisisRoom) this.voice('followup_ask', false);
      this.scrollToBottom();
    },
    startClock() {
      clearInterval(this.clockTimer);
      this.clockTimer = setInterval(() => {
        this.nowSec = Math.floor(Date.now() / 1000);
        // ล็อกหมดเวลา / บิลหมดอายุ → โหลดใหม่ให้เซิร์ฟเวอร์ตัดสินว่าโชว์ป้ายอะไรต่อ (ครั้งเดียว)
        if ((this.hold.lockEnds && this.left(this.hold.lockEnds) === 0)
            || (this.hold.bill && !this.hold.billPaid && this.hold.billEnds && this.left(this.hold.billEnds) === 0)) {
          this.hold.lockEnds = null; this.hold.billEnds = null;
          setTimeout(() => location.reload(), 1200);
        }
        if (this.qaTick) this.qaTick();
      }, 1000);
    },
    /** ถามเป็นระยะว่าบิลที่ค้างได้รับเงินหรือยัง (แม้ลูกค้ายังไม่ได้เปิด QR) */
    watchBill() {
      if (!this.hold.bill) return;
      clearInterval(this.billTimer);
      let n = 0;
      this.billTimer = setInterval(async () => {
        if (this.hold.billPaid || ++n > 120) { clearInterval(this.billTimer); return; }
        try {
          const r = await fetch(cfg.topupStatusUrl.replace('__ID__', this.hold.bill.id), {
            credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          });
          if (!r.ok) return;
          const j = await r.json();
          if (j.paid) this.billPaidNow(j.balance);
          else if (j.status && j.status !== 'pending') { clearInterval(this.billTimer); location.reload(); }
        } catch (_) { /* เน็ตสะดุด — รอบหน้าลองใหม่ */ }
      }, 6000);
    },
    billPaidNow(balance) {
      if (this.hold.billPaid) return;
      const card = this.messages.find(x => x.kind === 'topup' && x.pay?.id === this.hold.bill.id && x.pay.step !== 'done');
      this.hold.billPaid = true;
      clearInterval(this.billTimer);
      if (balance !== undefined && balance !== null && Number.isFinite(Number(balance))) this.balance = Number(balance);
      if (card) this.afterPaid(card, balance);
      else this.voice('topup_done', false);
    },
    /** เปิด QR ของบิลที่ค้างขึ้นมาในแชทอีกครั้ง (หลังรีเฟรช) — ไม่ออกบิลใหม่ซ้อน */
    async resumeBill(then = null) {
      if (!this.hold.bill || this.hold.billBusy) return;
      const had = this.messages.find(x => x.kind === 'topup' && x.pay?.id === this.hold.bill.id && x.pay.step !== 'done');
      if (had) { if (then) had.pay.then = then; this.sheet = true; this.scrollToBottom(true); return; }
      this.hold.billBusy = true;
      try {
        const r = await fetch(this.hold.bill.resume_url, {
          credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const j = await r.json().catch(() => ({}));
        if (r.status === 410) { if (j.paid) this.billPaidNow(); else location.reload(); return; }
        if (!r.ok) { this.say(j.error || 'เปิดบิลไม่สำเร็จ ลองใหม่อีกครั้งนะคะ'); return; }
        const m = this.push('assistant', '', 'ok', '', {
          kind: 'topup',
          pay: { step: 'qr', busy: false, uploading: false, error: '', notice: '', waited: 0, then, superseded: false, ...j },
        });
        this.sheet = true;
        this.watchTopup(m);
        this.scrollToBottom(true);
      } catch (_) {
        this.say('เครือข่ายมีปัญหาค่ะ ลองใหม่อีกครั้งนะคะ');
      } finally {
        this.hold.billBusy = false;
      }
    },

    init() {
      this.micSetup();
      // ให้ทุกข้อความมี id คงที่ เพื่อให้ x-for :key ไม่วาดใหม่ทั้งกองตอน
      // ลบ/เพิ่มข้อความ (ปุ่มคัดลอกจะไม่กระพริบและ scroll ไม่กระตุก)
      this.messages.forEach((m) => { m.id = ++this.seq; });
      this.scrollToBottom();
      // กดการ์ดแพ็กเกจแล้วกด "ย้อนกลับ" — เบราว์เซอร์คืนหน้าจาก bfcache พร้อมปุ่มที่ค้าง "กำลังพาไป..."
      window.addEventListener('pageshow', (e) => {
        if (e.persisted) { this.messages.forEach((m) => { if (m.offerBusy) m.offerBusy = false; }); this.table.offerBusy = false; }
      });
      // ⏳ นาฬิกาของป้ายบนโต๊ะ + ติดตามบิลที่ค้าง (เงินเข้าแล้วป้ายเปลี่ยนเอง ไม่ต้องรีเฟรช)
      if (this.qa && this.qa.over) this.qaOver(false);
      this.$watch('draft', () => { this.lastActive = Date.now(); });
      this.startClock();
      this.watchBill();
      // คำถามที่ติดมาจากหน้าผลไพ่ — ยิงครั้งเดียวให้คำตอบขึ้นเลยโดยไม่ต้องพิมพ์ซ้ำ
      // (เซิร์ฟเวอร์ pull() ออกจาก session แล้ว รีเฟรชจึงไม่ยิงซ้ำ/ไม่คิดเงินซ้ำ)
      if (cfg.autosend && String(cfg.autosend).trim()) {
        this.$nextTick(() => this.send(String(cfg.autosend)));
      }
    },

    /* ---------- การเลื่อน ---------- */
    onScroll() {
      const el = this.$refs.log;
      if (!el) return;
      this.atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    },
    scrollToBottom(force = false) {
      // เคารพผู้ใช้ที่กำลังเลื่อนอ่านย้อน — ไม่กระชากลงล่างสุดใส่หน้า
      if (!force && !this.atBottom) return;
      this.$nextTick(() => {
        const el = this.$refs.log;
        if (el) el.scrollTop = el.scrollHeight;
        this.atBottom = true;
      });
    },

    /* ---------- ช่องพิมพ์ ---------- */
    autoGrow() {
      const el = this.$refs.input;
      if (!el) return;
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 148) + 'px';
    },
    onEnter(e) {
      // Shift+Enter = ขึ้นบรรทัดใหม่ · isComposing = กำลังพิมพ์ผ่าน IME
      // (ภาษาไทยบนบางคีย์บอร์ด) ห้ามส่งกลางคัน ไม่งั้นคำถูกตัดครึ่ง
      if (e.shiftKey || e.isComposing) return;
      e.preventDefault();
      this.send();
    },
    useChip(prompt) {
      if (this.thinking || this.blocked) return;
      this.showTopics = false;
      this.send(prompt);
    },

    /* ---------- ข้อความ ---------- */
    now() {
      return new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
    },
    escape(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },
    push(role, html, state = 'ok', text = '', extra = null) {
      // extra (เช่น offers) ต้องใส่ก่อน push — ใส่ทีหลังบนอ็อบเจกต์ดิบจะไม่ผ่าน proxy ของ Alpine
      const m = Object.assign({ id: ++this.seq, role, html, text, time: this.now(), state }, extra || {});
      this.messages.push(m);
      this.scrollToBottom(role === 'user');
      // 🐞 (2026-11-05) ต้องคืนตัวที่ผ่าน proxy ของ Alpine — คืน m ดิบแล้วผู้เรียกแก้ค่าต่อ
      //    (pay.step = 'qr', state = 'failed') หน้าจอไม่อัปเดต: กดเปิดไพ่ตอนเครดิตไม่พอ
      //    ได้บิลแต่ QR ไม่ขึ้น · ส่งข้อความล้มก็ไม่เห็นปุ่มลองใหม่
      return this.messages[this.messages.length - 1];
    },

    /* ---------- การ์ดแพ็กเกจเปิดไพ่ ---------- */
    priceLabel(p) {
      const n = Number(p) || 0;
      if (n <= 0) return 'ฟรี';
      return '฿' + (Number.isInteger(n) ? n.toLocaleString('th-TH') : n.toFixed(2));
    },
    canAfford(o) {
      // เทียบเป็นสตางค์ — float ตรง ๆ (19.99 vs 19.990000001) ทำให้คนที่เงินพอดีเห็นปุ่มเติมเงิน
      return Number(o.price) <= 0 || Math.round(Number(this.balance) * 100) >= Math.round(Number(o.price) * 100);
    },
    // กดแพ็กเกจบนโต๊ะ — คำถามล่าสุดที่ลูกค้าพิมพ์ติดไปหน้าเลือกไพ่ · เครดิตไม่พอ = จ่ายในแชทแล้วพาไปต่อ
    openTable(o) {
      const last = [...this.messages].reverse().find((x) => x.role === 'user' && x.text);
      this.table.question = last ? String(last.text).slice(0, 300) : '';
      if (this.canAfford(o)) { this.startOffer(this.table, o); return; }
      if (this.canTopupInChat) { this.sheet = true; this.payAndOpen(this.table, o); return; }
      window.location.href = this.topupUrl;
    },
    startOffer(m, o) {
      if (m.offerBusy) return;      // กดซ้ำ = ส่งฟอร์มซ้ำ (ยังไม่หักเงิน แต่พาไปหน้าเดิมสองรอบ)
      m.offerBusy = o.key;
      if (o.kind === 'deep') {
        // คำถามส่งผ่าน sessionStorage ไม่ใส่ใน URL — เรื่องดูดวงเป็นเรื่องส่วนตัว ห้ามค้างใน log
        try { sessionStorage.setItem('juntra:deep_prefill', m.question || ''); } catch (_) {}
        window.location.href = o.url;
        return;
      }
      // ไพ่ยิปซี: POST ไปขั้นเลือกไพ่ (tarot.begin) — เงินถูกหักตอนเปิดไพ่ในหน้านั้น ไม่ใช่ตอนนี้
      const f = document.createElement('form');
      f.method = 'POST';
      f.action = cfg.tarotBeginUrl;
      const add = (name, value) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = name; i.value = value;
        f.appendChild(i);
      };
      add('_token', document.querySelector('meta[name="csrf-token"]').content);
      add('spread', o.spread);
      if (m.question) add('question', String(m.question).slice(0, 500));
      document.body.appendChild(f);
      f.submit();
    },
    // ยอดที่ขาด (บาท ปัดขึ้น) — คิดเป็นสตางค์กัน float เพี้ยน
    shortfall(o) {
      const cents = Math.round(Number(o.price) * 100) - Math.round(Number(this.balance) * 100);
      return cents > 0 ? Math.ceil(cents / 100) : 0;
    },
    // ยอดที่ให้สแกนจ่าย = ที่ขาด แต่ไม่ต่ำกว่าขั้นต่ำเติมเงิน (ระบบรับเติมต่ำสุด min_topup)
    payAmount(o) {
      return Math.min(cfg.maxTopup || 50000, Math.max(cfg.minTopup || 20, this.shortfall(o)));
    },
    payLabel(o) {
      if (!this.canTopupInChat) return 'เติมเครดิต';   // เติมในแชทไม่ได้ → พาไปหน้ากระเป๋าเงินแบบเดิม
      const verb = Number(this.balance) > 0 ? 'จ่ายเพิ่ม' : 'สแกนจ่าย';
      return verb + ' ฿' + this.payAmount(o).toLocaleString('th-TH') + ' แล้ว' + (o.kind === 'deep' ? 'เริ่มดูดวง' : 'เปิดไพ่เลย');
    },
    /**
     * จ่ายตรงยอดแล้วไปต่อเลย — สร้าง QR ยอดที่ขาดทันที ไม่ต้องเลือกจำนวนเอง
     * พอเงินเข้า (SMS หรือสลิป) afterPaid() จะพาไปเปิดแพ็กเกจนี้ให้เอง
     */
    payAndOpen(m, o) {
      if (m.offerBusy) return;
      // 🧾 มีบิลค้างอยู่ → จ่ายบิลเดิมก่อน ไม่ออกบิลใหม่ซ้อน
      if (this.hold.bill && !this.hold.billPaid) { this.resumeBill({ msg: m, offer: o }); return; }
      this.voice('credit_low');
      const then = { msg: m, offer: o };
      const amount = this.payAmount(o);
      const open = this.messages.find(x => x.kind === 'topup' && x.pay?.step !== 'done' && !x.pay?.superseded);
      if (open) {
        // กดซ้ำ/กดแพ็กเกจอื่นระหว่างรอ: ใบเดิมยอดพอ → ใช้ใบเดิม เปลี่ยนแค่ปลายทาง
        if (open.pay.step === 'qr' && Number(open.pay.base_amount) >= amount) {
          open.pay.then = then;
          this.scrollToBottom(true);
          return;
        }
        if (open.pay.step === 'choose') {
          if (open.pay.busy) return;          // กำลังสร้าง QR อยู่ — รอผลก่อน
          open.pay.then = then;
          this.chooseAmount(open, amount);
          return;
        }
        // ใบเดิมยอดไม่พอสำหรับแพ็กเกจนี้ → เลิกรอใบเดิม ออก QR ใหม่
        open.pay.superseded = true;
      }
      const card = this.openTopup(then, true);
      this.chooseAmount(card, amount);
    },
    // ให้แม่หมอพูดประโยคประจำ (ถ้าลูกค้าเปิดเสียงไว้และมีไฟล์) — เวทีเสียงเป็นคนตัดสินใจเล่น
    voice(key, once = true) {
      window.dispatchEvent(new CustomEvent('oracle-say', { detail: { key, once } }));
    },
    /** ✨ เล่นเสียงคำตอบ (พรีเมียม) ทีละเสียง — เพลงห้องหรี่ลงระหว่างพูด */
    playReplyVoice(url) {
      try {
        if (this.replyAudio) this.replyAudio.pause();
        const a = new Audio(url);
        this.replyAudio = a;
        const done = () => {
          if (this.replyAudio !== a) return;
          this.replyAudio = null;
          window.dispatchEvent(new CustomEvent('oracle-voice', { detail: { on: false } }));
        };
        a.addEventListener('ended', done);
        a.addEventListener('error', done);
        a.addEventListener('pause', done);
        window.dispatchEvent(new CustomEvent('oracle-voice', { detail: { on: true } }));
        const p = a.play();
        if (p && p.catch) p.catch(done);
      } catch (_) { /* เบราว์เซอร์เล่นไม่ได้ — ข้อความยังอยู่ครบ */ }
    },
    say(text) {                 // ข้อความจาก "ระบบ" ไม่ใช่คำพูดของแม่หมอ
      return this.push('assistant', this.escape(text).replace(/\n/g, '<br>'), 'system');
    },

    /* ---------- เติมเงินในแชท ---------- */
    openTopup(then = null, fresh = false) {
      // มีการ์ดที่ยังจ่ายไม่เสร็จอยู่แล้วก็เลื่อนไปหาใบเดิม ไม่สร้างซ้อน
      const open = fresh ? null : this.messages.find(x => x.kind === 'topup' && x.pay?.step !== 'done' && !x.pay?.superseded);
      if (open) { this.scrollToBottom(true); return open; }
      if (!fresh && this.hold.bill && !this.hold.billPaid) { this.resumeBill(then); return null; }

      // kind/pay ต้องใส่ตอน push — ใส่ทีหลังบนอ็อบเจกต์ดิบจะไม่ผ่าน proxy ของ Alpine
      const m = this.push('assistant', '', 'ok', '', {
        kind: 'topup',
        pay: { step: 'choose', busy: false, uploading: false, error: '', notice: '', waited: 0, then, superseded: false },
      });
      this.scrollToBottom(true);
      return m;
    },

    /** เงินเข้าแล้ว (จาก poll หรือสลิป) — อัปเดตยอด ปลดล็อกแชท และพาไปแพ็กเกจที่ตั้งใจจ่าย */
    afterPaid(m, balance) {
      clearInterval(this.payTimer);
      m.pay.step = 'done';
      if (this.hold.bill && m.pay.id === this.hold.bill.id) { this.hold.billPaid = true; clearInterval(this.billTimer); }
      if (balance !== undefined && balance !== null && Number.isFinite(Number(balance))) this.balance = Number(balance);
      // เติมเงินผ่านแล้วต้องพิมพ์ต่อได้ทันที ไม่ต้องรีเฟรชหน้า
      if (this.blockedReason.includes('เครดิต')) {
        this.blocked = false;
        this.blockedReason = '';
      }
      this.scrollToBottom(true);
      this.voice('topup_done', false);
      const t = m.pay.then;
      // หน่วงให้เห็นว่า "สำเร็จ" ก่อนเปลี่ยนหน้า — ผู้สูงอายุตกใจถ้าหน้าเด้งทันที
      // เปิดเสียงไว้ = รอให้แม่หมอพูดจบก่อน (เปลี่ยนหน้าแล้วเสียงขาด)
      const wait = window.JuntraVoice && window.JuntraVoice.on() ? 3500 : 1500;
      if (t && this.canAfford(t.offer)) setTimeout(() => this.startOffer(t.msg, t.offer), wait);
    },

    async chooseAmount(m, amount) {
      if (m.pay.busy) return;
      m.pay.busy = true;
      m.pay.error = '';
      try {
        const r = await fetch(cfg.topupCreateUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: JSON.stringify({ amount }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
          m.pay.error = j.error || 'สร้างรายการเติมเงินไม่สำเร็จ ลองใหม่อีกครั้งนะคะ';
          return;
        }
        Object.assign(m.pay, j, { step: 'qr', waited: 0, error: '', notice: '' });
        this.watchTopup(m);
      } catch (e) {
        m.pay.error = 'เครือข่ายมีปัญหาค่ะ ลองใหม่อีกครั้งนะคะ';
      } finally {
        m.pay.busy = false;
        this.scrollToBottom(true);
      }
    },

    /**
     * ถามเซิร์ฟเวอร์เป็นระยะว่าเงินเข้าหรือยัง
     * หยุดเองที่ 10 นาที — ไม่ปล่อยให้ยิงไม่จบถ้าลูกค้าเปิดค้างไว้
     */
    watchTopup(m) {
      clearInterval(this.payTimer);
      this.payTimer = setInterval(async () => {
        m.pay.waited += 5;
        if (m.pay.waited > 600) { clearInterval(this.payTimer); return; }
        try {
          const r = await fetch(cfg.topupStatusUrl.replace('__ID__', m.pay.id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          });
          if (!r.ok) return;
          const j = await r.json();
          if (j.paid && m.pay.step !== 'done') this.afterPaid(m, j.balance);
        } catch (_) { /* เน็ตสะดุดชั่วคราว — รอบหน้าลองใหม่ */ }
      }, 5000);
    },

    async copyAmount(m, e) {
      try {
        await navigator.clipboard.writeText(Number(m.pay.payable).toFixed(2));
        m.pay.notice = 'คัดลอกยอดแล้วค่ะ';
        setTimeout(() => { m.pay.notice = ''; }, 2000);
      } catch (_) {
        m.pay.notice = 'กดค้างที่ตัวเลขเพื่อคัดลอกได้นะคะ';
      }
    },

    async uploadSlip(m, e) {
      const file = e.target.files && e.target.files[0];
      e.target.value = '';           // เลือกไฟล์เดิมซ้ำได้
      if (!file) return;

      m.pay.uploading = true;
      m.pay.error = '';
      m.pay.notice = '';
      try {
        const fd = new FormData();
        fd.append('slip', file);
        const r = await fetch(cfg.topupSlipUrl.replace('__ID__', m.pay.id), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: fd,
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
          m.pay.error = j.error || 'ส่งสลิปไม่สำเร็จ ลองใหม่อีกครั้งนะคะ';
          return;
        }

        // ตรวจสลิปผ่านทันที (SlipOK) → ข้ามการรอ SMS ไปหน้าสำเร็จได้เลย
        if (j.paid) {
          if (m.pay.step !== 'done') this.afterPaid(m, j.balance);
          return;
        }

        m.pay.notice = j.message || 'ได้รับสลิปแล้วค่ะ';
      } catch (_) {
        m.pay.error = 'เครือข่ายมีปัญหาค่ะ ลองส่งสลิปใหม่อีกครั้งนะคะ';
      } finally {
        m.pay.uploading = false;
      }
    },

    async copy(m, e) {
      try {
        await navigator.clipboard.writeText(m.text || m.html.replace(/<[^>]*>/g, ''));
        const btn = e.currentTarget;
        btn.setAttribute('title', 'คัดลอกแล้ว');
        setTimeout(() => btn.setAttribute('title', 'คัดลอก'), 1500);
      } catch (_) { /* เบราว์เซอร์ไม่อนุญาต — ผู้ใช้ยังลากเลือกเองได้ */ }
    },

    retry(m) {
      if (this.thinking || this.blocked) return;
      // เอาฟองเดิมออกก่อนแล้วส่งใหม่ด้วยคีย์เดิม — ไม่งั้นจะเห็นข้อความตัวเอง
      // ซ้ำสองฟอง ทั้งที่เป็นการส่งครั้งเดียวกัน
      const idx = this.messages.indexOf(m);
      if (idx > -1) this.messages.splice(idx, 1);
      this.send(m.text, m.idem);
    },

    async send(overrideText = null, reuseIdem = null) {
      const text = (overrideText !== null ? overrideText : this.draft).trim();
      if (!text || this.thinking || this.blocked) return;

      // ใช้คีย์เดิมเมื่อ "ลองใหม่" ข้อความเดิม — เซิร์ฟเวอร์จึงกันการตัดเงินซ้ำ
      // ได้จริง (ของเดิมสุ่มคีย์ใหม่ทุกครั้ง ตัวกันซ้ำจึงไม่เคยทำงานเลย)
      const idem = reuseIdem || ((window.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : String(Date.now()) + Math.random());

      const mine = this.push('user', this.escape(text).replace(/\n/g, '<br>'), 'ok', text);
      mine.idem = idem;
      if (overrideText === null) {
        this.draft = '';
        this.$nextTick(() => this.autoGrow());
      }
      this.thinking = true;
      this.lastActive = Date.now();
      this.nudges = 0;
      this.scrollToBottom(true);

      try {
        const r = await fetch(cfg.sendUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Idempotency-Key': idem,
          },
          body: JSON.stringify({ message: text, room: this.room }),
        });

        // 419 = CSRF/session หมดอายุ — เดิมขึ้นว่า "ระบบขัดข้อง" ผู้ใช้เลยลองส่งซ้ำเรื่อย ๆ
        if (r.status === 419) {
          mine.state = 'failed';
          this.say('เซสชันหมดอายุค่ะ — กรุณารีเฟรชหน้าแล้วเข้าสู่ระบบใหม่ ข้อความที่พิมพ์ไว้ยังอยู่ ไม่ได้ถูกส่งและไม่ถูกคิดเงิน');
          return;
        }

        let j = {};
        try { j = await r.json(); } catch (_) { /* ไม่ใช่ JSON */ }

        if (r.status === 403 && j.reason_code === 'qa_over') {   // ⏳ หมดเวลาถามต่อ — ไม่ได้ส่ง ไม่คิดเงิน
          mine.state = 'ok';
          this.say(j.error || cfg.qaOverText);
          this.setQa(j.qa);
          this.qaOver(false);
          return;
        }

        if (r.status === 402) {                      // เครดิตไม่พอ
          this.blocked = true;
          this.blockedReason = 'เครดิตไม่พอ — เติมเครดิตแล้วคุยต่อได้เลยค่ะ';
          this.say(j.error || 'เครดิตไม่พอ กรุณาเติมเงินก่อนนะคะ');
          this.voice('credit_low');
          // กาง QR ให้เลยตรงนี้ ลูกค้าไม่ต้องหาว่าต้องกดตรงไหนต่อ
          if (this.canTopupInChat) this.openTopup();
          return;
        }

        if (r.status === 429) {
          if (j.reason_code === 'daily_limit') {     // ครบโควตาคุยฟรีวันนี้
            this.dailyLeft = 0;
            this.blocked = true;
            this.blockedReason = 'วันนี้คุยครบแล้ว — พรุ่งนี้' + this.persona.self + 'รอฟังต่อนะคะ';
            this.say(j.error || 'วันนี้คุยครบแล้วค่ะ พรุ่งนี้มาคุยกันใหม่นะคะ');
            this.voice('daily_limit');
          } else {                                   // ส่งถี่เกินไป
            mine.state = 'failed';
            this.say('พิมพ์เร็วไปนิดนะคะ — รอสักครู่แล้วกด "ลองส่งใหม่" ได้เลย');
          }
          return;
        }

        if (r.status === 409) {
          if (j.reason_code === 'busy_other') {      // อีกแท็บ/แอพกำลังรอคำตอบ — ข้อความนี้ยังไม่ถูกส่ง
            mine.state = 'failed';
            this.say(j.error || 'กำลังตอบข้อความก่อนหน้าอยู่ค่ะ รอสักครู่แล้วกด "ลองส่งใหม่" ได้เลย');
            return;
          }
          this.say('ข้อความก่อนหน้ากำลังส่งอยู่ค่ะ รอสักครู่นะคะ');   // ข้อความเดิมกำลังส่งอยู่
          return;
        }

        if (!r.ok) {
          mine.state = 'failed';
          this.say(j.error || 'ระบบขัดข้องชั่วคราว — กด "ลองส่งใหม่" ได้เลยค่ะ');
          return;
        }

        // สำเร็จ — ใช้ตัวเลขและสถานะจากเซิร์ฟเวอร์ ไม่เดาเอง
        mine.state = 'ok';
        if (typeof j.daily_left === 'number') this.dailyLeft = j.daily_left;
        if (typeof j.daily_limit === 'number') this.dailyLimit = j.daily_limit;
        this.awaiting = !!j.awaiting;
        this.suggestOpen = false;
        if (Array.isArray(j.suggestions) && j.suggestions.length) this.suggestions = j.suggestions;
        this.aiSuggestions = Array.isArray(j.ai_suggestions) ? j.ai_suggestions.filter(q => typeof q === 'string') : [];
        // 🤍 เซิร์ฟเวอร์บอกทุกคำตอบว่าห้องอยู่ในโหมดวิกฤตไหม (ไม่มีฟิลด์ = เว็บรุ่นเก่า ไม่แตะ)
        if (typeof j.crisis_room === 'boolean') this.crisisRoom = j.crisis_room;
        if (this.crisisRoom) { this.suggestions = []; this.aiSuggestions = []; this.showTopics = false; this.table.menu = false; }
        this.setQa(j.qa, j.server_now);
        this.lastActive = Date.now();

        // ลูกค้าขอให้ทำนาย → แนบการ์ดแพ็กเกจไปกับคำชวนของแม่หมอ (ยังไม่หักเงิน)
        const offers = Array.isArray(j.offers) && j.offers.length
          ? { offers: j.offers, question: j.question || text }
          : null;
        const said = this.push('assistant', j.reply_html || this.escape(j.reply || '').replace(/\n/g, '<br>'), 'ok', j.reply || '', offers);
        // ✨ พรีเมียมเสียง — แนบเสียงกับฟองคำตอบ · ลำโพงเปิดอยู่ = พูดทันที
        if (j.voice_url) {
          said.voiceUrl = j.voice_url;
          if (window.JuntraVoice && window.JuntraVoice.on()) this.playReplyVoice(j.voice_url);
        }

        // เสียงประโยคประจำตามจังหวะของบทสนทนา (คำตอบจริงเป็นตัวอักษรเสมอ — เสียงแค่ชวน/ย้ำ)
        // ห้องวิกฤต: ไม่เล่นเสียงประโยคประจำ (เสียงชวน/ร่าเริงทับคำปลอบไม่ได้)
        if (this.crisisRoom) { /* คำตอบเป็นตัวอักษรอย่างเดียว */ }
        else if (offers) this.voice('offer_cards', false);
        else if (/(ขอบคุณ|ขอบใจ|บ๊ายบาย|บายบาย|ลาก่อน|ไปก่อนนะ)/.test(text)) this.voice('thanks_bye', false);
        else if (/(วันเดือนปีเกิด|วันเกิด)/.test(j.reply || '') && /(ขอ|บอก|\?|ไหม|มั้ย)/.test(j.reply || '')) this.voice('ask_birth');

        // 🎁 (2026-07-28) "โควตาฟรีหมด" ไม่ได้แปลว่าคุยต่อไม่ได้เสมอไป
        //    ถ้าตั้งราคาต่อข้อความไว้ ลูกค้าจ่ายเครดิตคุยต่อได้ — ปิดช่องพิมพ์ตรงนี้
        //    = ปิดปากคนที่พร้อมจ่าย เสียทั้งลูกค้าและรายได้
        //    → เชื่อ `blocked` จากเซิร์ฟเวอร์ (เดิม client เดาเองจาก daily_left)
        // เตือนก่อนโดนหักเงินครั้งแรก — แถบโควตาด้านบนเรนเดอร์จากเซิร์ฟเวอร์
        // จึงยังค้างคำว่า "คุยฟรีวันนี้" จนกว่าจะรีเฟรช ถ้าไม่บอกตรงนี้
        // ลูกค้าจะถูกหักเครดิตข้อความถัดไปโดยไม่รู้ตัว = เรื่องร้องเรียน
        if (typeof j.next_cost === 'number' && j.next_cost > 0
            && this.dailyLimit > 0 && !this.costNotified) {
          this.costNotified = true;
          this.say('โควตาคุยฟรีวันนี้หมดแล้วนะคะ 🌙 ข้อความถัดไป' + this.persona.self + 'จะหักจากเครดิตครั้งละ ฿'
            + j.next_cost + ' ค่ะ (พรุ่งนี้ได้โควตาฟรีใหม่)');
        }

        if (j.blocked === true) {
          this.voice('daily_limit');
          this.blocked = true;
          this.blockedReason = 'วันนี้คุยครบแล้ว — พรุ่งนี้' + this.persona.self + 'รอฟังต่อนะคะ';
        } else if (typeof j.blocked === 'undefined' && this.dailyLimit > 0 && this.dailyLeft <= 0) {
          // เซิร์ฟเวอร์รุ่นเก่ายังไม่ส่ง blocked (ช่วง deploy คาบเกี่ยว) → ใช้เกณฑ์เดิม
          this.blocked = true;
          this.blockedReason = 'วันนี้คุยครบแล้ว — พรุ่งนี้' + this.persona.self + 'รอฟังต่อนะคะ';
        }
      } catch (e) {
        // แยกให้ชัดว่า "ส่งไม่ถึงเซิร์ฟเวอร์" — ลองใหม่ได้โดยไม่เสี่ยงโดนคิดเงินซ้ำ
        // เพราะใช้ Idempotency-Key เดิม
        mine.state = 'failed';
        this.say('เครือข่ายมีปัญหาค่ะ — กด "ลองส่งใหม่" ได้เลย ข้อความจะไม่ถูกส่งซ้ำซ้อน');
      } finally {
        this.thinking = false;
        if (!this.blocked) this.$refs.input?.focus();
      }
    },
  }));
});
</script>
@endpush
@endsection
