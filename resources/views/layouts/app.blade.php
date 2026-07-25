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
<link rel="icon" href="{{ asset('images/logo.png') }}" type="image/png">
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
