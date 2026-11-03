@extends('layouts.app')
@section('page-handles-flash', '1')

{{-- 🔮 (2026-11-03) หน้าเลือกผู้พยากรณ์ — ห้องละ 1 คน 1 แบบทำนาย
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
    </div>

    @if (session('status'))
      <div class="flash" role="status" style="text-align:center;margin-bottom:18px">{{ session('status') }}</div>
    @endif

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

            {{-- แพ็กเกจเดียวของห้อง — บอกราคาตรงนี้เลย ลูกค้าไม่ต้องเดาว่าห้องไหนแพง --}}
            <div class="room-product">
              <span class="room-product-cards" aria-hidden="true">{{ $r['product']['cards'] ?: '★' }}</span>
              <span class="room-product-name">{{ $r['product']['name'] }}</span>
              <span class="room-product-price">{{ $priceLabel }}</span>
            </div>

            @if ($open)
              <a href="{{ route('chat.index', ['room' => $r['key']]) }}" class="btn btn-primary room-go">
                คุยกับ{{ $r['short'] }}{{ ($chatFree ?? true) ? 'ฟรี' : '' }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
              </a>
            @elseif ($r['open'])
              {{-- ห้องเปิดแต่ยังไม่ได้ล็อกอิน — พาไปล็อกอินแล้วกลับมาห้องนี้ --}}
              <a href="{{ route('thaiprompt.redirect', ['to' => '/chat?room=' . $r['key']]) }}" class="btn btn-primary room-go">
                เข้าสู่ระบบเพื่อคุยกับ{{ $r['short'] }}
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
