@extends('layouts.app')
@section('page-handles-flash', '1')

{{-- 🔮 (2026-11-03) หน้าเลือกผู้พยากรณ์ — ห้องละ 1 คน · การ์ดบอกทุกแบบที่ห้องนั้นเปิดไพ่ให้ (2026-11-11)
     ข้อมูลทั้งหมดมาจาก ChatRooms::cards() (config/chat_rooms.php + ราคาสดจาก Pricing)
     ห้องที่ยังไม่เปิด (Thaiprompt ยังไม่รู้จัก persona / แพ็กเกจปิดขาย) โชว์ "เร็ว ๆ นี้" กดไม่ได้ --}}

@section('title', 'เลือกผู้พยากรณ์')

@section('content')
<section class="canvas">
  <div class="rooms-shell">

    <div style="text-align:center;margin-bottom:22px">
      <div class="eyebrow">CHOOSE YOUR ORACLE</div>
      <h1 class="display" style="font-size:clamp(32px,4.5vw,56px);margin-bottom:10px">เลือก<em>ผู้พยากรณ์</em></h1>
      <p class="lede" style="margin:0 auto">{{ $chatLede ?? 'คุยฟรีทุกวัน' }} · อยากให้เปิดไพ่เมื่อไรค่อยเลือก ราคาไพ่หักตอนเปิดไพ่เท่านั้น</p>
      {{-- 🤖 (2026-11-04, owner) บอกตรง ๆ ว่าผู้พยากรณ์ทุกห้องคือ AI — คำโฆษณาต้องไม่เกินที่ระบบทำจริง:
           ไพ่อ่านจากความหมายของสำรับ 78 ใบ (database/data/tarot_meanings.php → TarotPromptBuilder ส่งความหมายของทุกใบไปด้วย)
           ห้องแชทไม่ได้คำนวณดาว จึงไม่อ้างเรื่องดาวตรงนี้ --}}
      <p class="mx-auto mt-3 max-w-xl text-center text-sm leading-relaxed text-white/75">
        <x-ai-badge class="mr-1" />
        <span>ผู้พยากรณ์ทุกห้องคือ<strong class="font-semibold text-white">หมอดู AI</strong> เปิดไพ่แล้วอ่านจากความหมายในตำราไพ่ 78 ใบ ตามใบและตำแหน่งที่ท่านเปิดได้จริง — ไม่เดา ไม่มโน</span>
      </p>
    </div>

    {{-- 🔴 (2026-11-04, owner) ตัวเลขสดหน้าห้อง — ออนไลน์ตอนนี้ / ดูดวงไปแล้ว / คิวเปิดไพ่ (เลขจริงทั้งหมด) --}}
    <x-live-room-stats />

    @if (session('status'))
      <div class="flash" role="status" style="text-align:center;margin-bottom:18px">{{ session('status') }}</div>
    @endif
    @include('partials.form-errors', ['style' => 'text-align:center;margin-bottom:18px'])

    {{-- มือถือ: ปัดซ้าย-ขวา (scroll-snap ไม่ต้องใช้ JS) · จอใหญ่: วางเรียงกัน --}}
    <div class="rooms-deck" role="list">
      @foreach ($rooms as $i => $r)
        @php
          $open  = $r['open'] && ($gate['allowed'] ?? false);
          $price = $r['product']['price'];
          $priceLabel = '฿' . number_format($price, $price == intval($price) ? 0 : 2);
        @endphp
        <article class="room-card {{ $r['open'] ? '' : 'is-closed' }} {{ ($current ?? null) === $r['key'] ? 'is-current' : '' }}"
                 data-persona="{{ $r['key'] }}" role="listitem">
          <div class="room-art">
            {{-- ไม่ใช้ lazy: การ์ดในแถบปัดแนวนอนบางเบราว์เซอร์ไม่โหลดรูปจนกว่าจะปัดถึง (เห็นการ์ดมืด) · มีแค่ 3 รูป
                 🎬 คลิป idle เล่นเฉพาะการ์ดที่เห็นบนจอ (มือถือปัดถึงใบไหนค่อยโหลดใบนั้น) --}}
            <x-oracle-stage :persona="$r['key']" :poster="$r['image']" :alt="$r['name']" eager />
            <div class="room-art-fade" aria-hidden="true"></div>
            <div class="room-badges">
              <span class="room-badge">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }} / {{ str_pad(count($rooms), 2, '0', STR_PAD_LEFT) }}</span>
              <x-ai-badge size="md" />
              @if ($r['open'])
                <span class="room-badge is-online"><span class="mor-online"></span> ออนไลน์</span>
              @else
                <span class="room-badge">เร็ว ๆ นี้</span>
              @endif
            </div>
            <div class="room-name">
              <div class="room-eyebrow">{{ $r['eyebrow'] }}</div>
              <h2>{{ $r['name'] }}</h2>
              <p>{{ $r['title'] }}</p>
            </div>
          </div>

          <div class="room-body">
            <p class="room-quote">{{ $r['quote'] }}</p>
            @if ($r['tags'])
              <div class="room-tags">
                @foreach ($r['tags'] as $t)<span>{{ $t }}</span>@endforeach
              </div>
            @endif

            {{-- 🌸 (2026-11-11) ทุกแบบที่ห้องนี้เปิดไพ่ให้ พร้อมราคา — ชุดเดียวกับโต๊ะในห้อง (ChatOffers::roomMenu)
                 เจ้าของสั่ง "ทำไมน้องบุษบา ดูได้แค่ไพ่ 3 ใบ เธอต้องดูหมด เลยที่ไพ่ไม่ถึง 10 ใบ"
                 ห้องยังปิด / ไม่มีแบบไหนขายอยู่ → แพ็กเกจประจำห้องใบเดียวแบบเดิม --}}
            @php $menu = $r['open'] ? \App\Services\Chat\ChatOffers::roomMenu($r['key'], auth()->user()) : []; @endphp
            @if ($menu)
              <ul class="room-menu" aria-label="แบบที่{{ $r['short'] }}เปิดไพ่ให้">
                @foreach ($menu as $o)
                  @php
                    $p = (float) $o['price'];
                    $href = $o['kind'] === 'tarot' ? route('tarot.index', ['spread' => $o['spread']]) : $o['url'];
                  @endphp
                  <li>
                    <a href="{{ $open ? $href : route('thaiprompt.redirect', ['to' => '/chat?room=' . $r['key']]) }}" class="room-product">
                      <span class="room-product-cards" aria-hidden="true">{{ $o['cards'] ?: '★' }}</span>
                      <span class="room-product-name">
                        {{ $o['label'] }}
                        @if (! empty($o['locked_until']))<small>เปิดได้อีก {{ $o['locked_until'] }}</small>@endif
                      </span>
                      <span class="room-product-price">{{ $p > 0 ? '฿' . number_format($p, $p == intval($p) ? 0 : 2) : 'ฟรี' }}</span>
                    </a>
                  </li>
                @endforeach
              </ul>
            @else
              {{-- แพ็กเกจเดียวของห้อง — บอกราคาตรงนี้เลย ลูกค้าไม่ต้องเดาว่าห้องไหนแพง --}}
              <div class="room-product">
                <span class="room-product-cards" aria-hidden="true">{{ $r['product']['cards'] ?: '★' }}</span>
                <span class="room-product-name">{{ $r['product']['name'] }}</span>
                <span class="room-product-price">{{ $priceLabel }}</span>
              </div>
            @endif

            @if ($open)
              <a href="{{ route('chat.index', ['room' => $r['key']]) }}" class="btn btn-primary room-go">
                {{-- (2026-11-05) เลือกแล้วเข้าห้องพยากรณ์เต็มจอ — คุย/เปิดไพ่ในห้องเดียวกัน --}}
                เข้าห้อง{{ $r['short'] }}{{ ($chatFree ?? true) ? ' · คุยฟรี' : '' }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
              </a>
            @elseif ($r['open'])
              {{-- ห้องเปิดแต่ยังไม่ได้ล็อกอิน — พาไปล็อกอินแล้วกลับมาห้องนี้ --}}
              <a href="{{ route('thaiprompt.redirect', ['to' => '/chat?room=' . $r['key']]) }}" class="btn btn-primary room-go">
                เข้าสู่ระบบเพื่อเข้าห้อง{{ $r['short'] }}
              </a>
            @else
              <span class="btn btn-ghost room-go" aria-disabled="true">เร็ว ๆ นี้</span>
            @endif
          </div>
        </article>
      @endforeach
    </div>

    @unless ($gate['allowed'] ?? false)
      <p style="text-align:center;margin-top:18px;color:var(--ink-dim);font-size:14px">
        สมาชิกจากบอท Facebook / LINE เข้าใช้ได้ทันทีไม่ต้องสมัครใหม่ ·
        <a href="{{ route('login') }}" style="color:var(--gold);text-decoration:underline">เข้าสู่ระบบด้วยเบอร์โทร / อีเมล</a>
      </p>
    @endunless
  </div>
</section>
@endsection
