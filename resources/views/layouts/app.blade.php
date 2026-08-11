<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
{{-- ทั้งสองธีมเป็นธีมมืด — ประกาศไว้ตั้งแต่ก่อน CSS โหลด ไม่งั้น Chrome Android
     ที่เปิด Auto Dark Theme จะกลับสีปุ่มทองเป็นดำและวาด native control ผิดโทน --}}
<meta name="color-scheme" content="dark">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', config('app.name', 'แม่หมอจันทรา')) — ทำนายดวงด้วย AI ศักดิ์สิทธิ์</title>
<meta name="description" content="@yield('description', 'แม่หมอจันทรา / จันทราพยากรณ์ ผสานศาสตร์ดวงดาวโบราณกับปัญญาประดิษฐ์ — ดวงรายวัน ไพ่ยิปซี เลขศาสตร์ AI Chat ดูดวง')">

{{-- ── Social preview ────────────────────────────────────────────────
     ทั้งเว็บเคยไม่มี og:image เลยสักหน้า (มีหน้า /download หน้าเดียวที่
     ประกาศเอง) ทั้งที่ช่องทางหลักคือแชร์ลิงก์ใน Facebook / LINE —
     ลิงก์ที่แชร์จึงขึ้นเป็นการ์ดเปล่าไม่มีรูปมาตลอด
     หน้าไหนอยากใช้รูปของตัวเองให้ประกาศ @section('og-image', asset(...))
     แต่ต้องเป็นขนาด 1200×630 เท่านั้น ไม่งั้น width/height ข้างล่างจะโกหก --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name', 'จันทราพยากรณ์') }}">
<meta property="og:locale" content="th_TH">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:title" content="@yield('title', config('app.name', 'แม่หมอจันทรา'))">
<meta property="og:description" content="@yield('description', 'แม่หมอจันทรา / จันทราพยากรณ์ ผสานศาสตร์ดวงดาวโบราณกับปัญญาประดิษฐ์ — ดวงรายวัน ไพ่ยิปซี เลขศาสตร์ AI Chat ดูดวง')">
<meta property="og:image" content="@yield('og-image', asset('images/juntra/og-default.jpg'))">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">

{{-- favicon เดิมชี้ logo.png ขนาด 1 MB เต็ม ๆ (1024×1024) โหลดทุกหน้าเพื่อวาด 16px
     ตอนนี้ย่อไว้ล่วงหน้าแล้ว: favicon 64px = 8 KB, apple-touch 180px = 36 KB
     คง .png ไว้ทั้งคู่เพราะ Safari/หน้าจอโฮมของ iOS ยังไม่รับ .webp ทุกเวอร์ชัน --}}
<link rel="icon" href="{{ asset('images/favicon.png') }}" sizes="64x64" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
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

@stack('scripts')
</body>
</html>
