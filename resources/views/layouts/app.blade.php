<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', config('app.name', 'แม่หมอจันทรา')) — ทำนายดวงด้วย AI ศักดิ์สิทธิ์</title>
<meta name="description" content="@yield('description', 'แม่หมอจันทรา ผสานศาสตร์ดวงดาวโบราณกับปัญญาประดิษฐ์ — ดวงรายวัน ไพ่ยิปซี เลขศาสตร์ AI Chat ดูดวง')">
<link rel="icon" href="{{ asset('images/logo.png') }}" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;500;600;700;800&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=Prompt:wght@200;300;400;500;600;700&display=swap" rel="stylesheet">
@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('head')
</head>
<body>

<div class="cosmos"></div>
<div class="twinkle" id="twinkle"></div>
<div class="shoot"></div>

<nav class="top">
  <a href="{{ route('home') }}" class="brand">
    <div class="brand-mark"><img src="{{ asset('images/logo.png') }}" alt=""></div>
    <div class="brand-name">
      <b>MAE MOR CHANTRA</b>
      <span>{{ config('app.name', 'แม่หมอจันทรา') }}</span>
    </div>
  </a>
  <div class="nav-links">
    <a href="{{ route('tarot.index') }}">ไพ่ยิปซี</a>
    <a href="{{ route('horoscope.index') }}">ดวงรายวัน</a>
    <a href="{{ route('numerology.index') }}">เลขศาสตร์</a>
    <a href="{{ route('palmistry.index') }}">ลายมือ</a>
    <a href="{{ route('auspicious.index') }}">ฤกษ์ยาม</a>
    <a href="{{ route('chat.index') }}">AI ทำนาย</a>
  </div>
  @auth
    <div style="display:flex;gap:10px;align-items:center">
      <a href="{{ route('account.dashboard') }}" class="nav-cta" style="border-color:var(--line);color:var(--ink-dim)">บัญชีของฉัน</a>
      <form method="POST" action="{{ route('logout') }}">@csrf
        <button class="nav-cta">ออกจากระบบ</button>
      </form>
    </div>
  @else
    <div style="display:flex;gap:10px;align-items:center">
      <a href="{{ route('login') }}" class="nav-cta" style="border-color:var(--line);color:var(--ink-dim)">เข้าสู่ระบบ</a>
      <a href="{{ route('register') }}" class="nav-cta">สมัครสมาชิก</a>
    </div>
  @endauth
</nav>

<main>
  @if (session('status'))
    <div style="max-width:880px;margin:120px auto -60px;padding:0 24px">
      <div class="flash">{{ session('status') }}</div>
    </div>
  @endif
  @if ($errors->any())
    <div style="max-width:880px;margin:120px auto -60px;padding:0 24px">
      <div class="flash flash-error">
        <ul style="margin:0;padding-left:18px">
          @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
      </div>
    </div>
  @endif

  @yield('content')
</main>

<footer class="bottom">
  <div class="footer-inner">
    <div class="foot-col foot-brand">
      <div class="brand">
        <div class="brand-mark"><img src="{{ asset('images/logo.png') }}" alt=""></div>
        <div class="brand-name">
          <b>MAE MOR CHANTRA</b>
          <span>แม่หมอจันทรา</span>
        </div>
      </div>
      <p>ผสานศาสตร์โบราณกับเทคโนโลยี AI ล้ำสมัย เพื่อเป็นเข็มทิศนำทางชีวิตคุณ ตั้งแต่อดีตจนปัจจุบัน</p>
    </div>
    <div class="foot-col">
      <h4>บริการ</h4>
      <ul>
        <li><a href="{{ route('horoscope.index') }}">ดวงรายวัน</a></li>
        <li><a href="{{ route('tarot.index') }}">ไพ่ยิปซี AI</a></li>
        <li><a href="{{ route('numerology.index') }}">เลขศาสตร์</a></li>
        <li><a href="{{ route('chat.index') }}">AI Chat ทำนาย</a></li>
      </ul>
    </div>
    <div class="foot-col">
      <h4>ศาสตร์</h4>
      <ul>
        <li><a href="{{ route('horoscope.index') }}">โหราศาสตร์ตะวันตก</a></li>
        <li><a href="{{ route('horoscope.thai') }}">ราศีไทย / ปีนักษัตร</a></li>
        <li><a href="{{ route('tarot.index') }}">ไพ่ยิปซี Rider-Waite</a></li>
        <li><a href="{{ route('palmistry.index') }}">ดูลายมือ</a></li>
      </ul>
    </div>
    <div class="foot-col">
      <h4>บัญชี</h4>
      <ul>
        @auth
          <li><a href="{{ route('account.dashboard') }}">หน้าหลักของฉัน</a></li>
          <li><a href="{{ route('account.history') }}">ประวัติการดูดวง</a></li>
          <li><a href="{{ route('profile.edit') }}">โปรไฟล์</a></li>
          <li><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" style="background:none;border:none;color:inherit;cursor:pointer;padding:0;font-size:14px">ออกจากระบบ</button></form></li>
        @else
          <li><a href="{{ route('login') }}">เข้าสู่ระบบ</a></li>
          <li><a href="{{ route('register') }}">สมัครสมาชิก</a></li>
        @endauth
      </ul>
    </div>
  </div>
  <div class="foot-bottom">
    <span>© {{ date('Y') }} MAE MOR CHANTRA · ALL RIGHTS RESERVED</span>
    <span>CRAFTED UNDER THE MOON ☾</span>
  </div>
</footer>

@stack('scripts')
</body>
</html>
