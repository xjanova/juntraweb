<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
{{-- ทั้งสองธีมเป็นธีมมืด — ประกาศไว้ตั้งแต่ก่อน CSS โหลด ไม่งั้น Chrome Android
     ที่เปิด Auto Dark Theme จะกลับสีปุ่มทองเป็นดำและวาด native control ผิดโทน --}}
<meta name="color-scheme" content="dark">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', config('app.name', 'แม่หมอจันทรา')) — หมอดู AI อ่านจากตำราจริง</title>
{{-- ── SEO (2026-11-04) — กติกาทั้งหมดอยู่ที่ App\Support\Seo
     description: หน้าไหนประกาศ @section('description') เองใช้ของหน้า ไม่งั้นใช้ของ Seo ตาม route
     robots: เก็บเข้าดัชนีเฉพาะหน้าที่อยู่ใน allowlist (Seo::INDEXABLE) — หน้าบัญชี/วอลเลต/ผลคำทำนาย = noindex
     canonical: ตัด query ทิ้ง (/chat?pick=1, ?ref=…) ไม่ให้นับเป็นหน้าซ้ำ --}}
{{-- @section('x', 'ข้อความ') ถูก e() มาแล้วหนึ่งรอบ — decode กลับก่อน ไม่งั้น {{ }} ข้างล่าง escape ซ้ำเป็น &amp;amp; --}}
@php($seoDescription = View::hasSection('description') ? trim(html_entity_decode(View::getSection('description'), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : \App\Support\Seo::description())
<meta name="description" content="{{ $seoDescription }}">
@if (\App\Support\Seo::indexable())
<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1">
<link rel="canonical" href="{{ \App\Support\Seo::canonical() }}">
@else
<meta name="robots" content="noindex,follow">
@endif

{{-- ── Social preview ────────────────────────────────────────────────
     ทั้งเว็บเคยไม่มี og:image เลยสักหน้า (มีหน้า /download หน้าเดียวที่
     ประกาศเอง) ทั้งที่ช่องทางหลักคือแชร์ลิงก์ใน Facebook / LINE —
     ลิงก์ที่แชร์จึงขึ้นเป็นการ์ดเปล่าไม่มีรูปมาตลอด
     หน้าไหนอยากใช้รูปของตัวเองให้ประกาศ @section('og-image', asset(...))
     แต่ต้องเป็นขนาด 1200×630 เท่านั้น ไม่งั้น width/height ข้างล่างจะโกหก --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name', 'จันทราพยากรณ์') }}">
<meta property="og:locale" content="th_TH">
<meta property="og:url" content="{{ \App\Support\Seo::canonical() }}">
<meta property="og:title" content="@yield('title', config('app.name', 'แม่หมอจันทรา'))">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:image" content="@yield('og-image', asset('images/juntra/og-maemor.jpg'))">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#0a0612">
<link rel="sitemap" type="application/xml" href="{{ route('seo.sitemap') }}">
<link rel="alternate" type="text/markdown" title="llms.txt" href="{{ route('seo.llms') }}">
<script type="application/ld+json">{!! \App\Support\Seo::jsonLd(\App\Support\Seo::siteGraph()) !!}</script>

{{-- favicon เดิมชี้ logo.png ขนาด 1 MB เต็ม ๆ (1024×1024) โหลดทุกหน้าเพื่อวาด 16px
     ตอนนี้ย่อไว้ล่วงหน้าแล้ว: favicon 64px = 8 KB, apple-touch 180px = 36 KB
     คง .png ไว้ทั้งคู่เพราะ Safari/หน้าจอโฮมของ iOS ยังไม่รับ .webp ทุกเวอร์ชัน --}}
<link rel="icon" href="{{ asset('images/favicon.png') }}" sizes="64x64" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
{{-- 📱 (2026-11-11) ติดตั้งเป็นแอปได้ (PWA) — manifest + service worker (public/sw.js, ลงทะเบียนใน resources/js/pwa.js)
     iOS อ่าน manifest ไม่ครบ ต้องมี meta ของ Apple ด้วย · แถบสถานะ black-translucent = เนื้อหาเต็มจอ
     ส่วนที่ต้องหลบแถบสถานะดันลงด้วย env(safe-area-inset-top) แล้ว (<x-pwa-install>, oracle-room.css) --}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="จันทรา">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="application-name" content="จันทรา">
@auth
@if (\App\Services\Push\PushNotifier::webReady())
<meta name="jw-push-key" content="{{ config('webpush.public_key') }}" data-user="{{ auth()->id() }}">
@endif
@endauth
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family={{ str_replace('|', '&family=', $themeConfig['fonts']) }}&display=swap" rel="stylesheet">
@vite(['resources/css/app.css', $themeConfig['css'], 'resources/js/app.js'])
@stack('head')
</head>
<body class="theme-{{ $activeTheme }}">

@include('themes.' . $activeTheme . '.chrome-bg')
@include('themes.' . $activeTheme . '.nav')

<main>
  {{-- หน้าที่วางแถบแจ้งเตือนเองแล้ว (ในตำแหน่งที่เข้ากับเลย์เอาต์ของหน้านั้น)
       จะประกาศ section 'page-handles-flash' ไว้ — ไม่งั้นข้อความเดียวกันจะขึ้น
       ซ้ำสองรอบ ทั้งจากเลย์เอาต์และจากตัวหน้าเอง (เกิดกับ 9 หน้า) --}}
  @unless (View::hasSection('page-handles-flash'))
    @if (session('status'))
      <div style="max-width:880px;margin:120px auto -60px;padding:0 24px"><div class="flash">{{ session('status') }}</div></div>
    @endif
    @if ($errors->any())
      <div style="max-width:880px;margin:120px auto -60px;padding:0 24px">
        <div class="flash flash-error">
          <ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
      </div>
    @endif
  @endunless
  @yield('content')
</main>

@include('themes.' . $activeTheme . '.footer')

{{-- 🌸 (2026-11-12) ครั้งแรก: เลือก "ดูดวงฟรีเลย" / "ให้บุษบาพาทัวร์" + ทัวร์เสียงบุษบาข้ามหน้า
     มาก่อน whats-new: ขึ้นหน้าเลือกหรือทัวร์อยู่ = ป๊อปอัปมีอะไรใหม่ไม่เด้งทับ --}}
<x-busaba-tour />

{{-- ✨ (2026-11-07) แนะนำฟีเจอร์ใหม่ ครั้งเดียวต่อเครื่อง --}}
<x-whats-new />

{{-- 📱 (2026-11-11) ชิปติดตั้งแอป + แผ่นสอนเพิ่มลงหน้าจอโฮมบน iPhone/iPad --}}
<x-pwa-install />

@stack('scripts')
</body>
</html>
