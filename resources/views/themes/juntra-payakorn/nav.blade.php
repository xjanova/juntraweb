<header class="nav">
  <a class="brand" href="{{ route('home') }}">
    <div class="brand-logo"><img src="{{ asset('images/juntra/logo.png') }}" alt="จันทราพยากรณ์"></div>
    <div class="brand-text">
      <span class="th">{{ config('app.name', 'จันทราพยากรณ์') }}</span>
      <span class="en">JUNTRA PAYAKORN</span>
    </div>
  </a>
  <nav class="nav-links" id="navLinks" x-data x-on:click.outside="$el.classList.remove('open')">
    <a href="{{ route('tarot.index') }}">ไพ่ยิปซี</a>
    <a href="{{ route('horoscope.index') }}">ดวงรายวัน</a>
    <a href="{{ route('numerology.index') }}">เลขศาสตร์</a>
    <a href="{{ route('palmistry.index') }}">ลายมือ</a>
    <a href="{{ route('auspicious.index') }}">ฤกษ์ยาม</a>
    <a href="{{ route('chat.index') }}">AI ทำนาย</a>
    <a href="{{ route('download') }}">แอพมือถือ</a>
    @auth
      <a href="{{ route('wallet.index') }}">วอลเลต</a>
      <a href="{{ route('account.dashboard') }}">บัญชี</a>
      <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button class="nav-cta">ออกจากระบบ</button></form>
    @else
      <a href="{{ route('login') }}">เข้าสู่ระบบ</a>
      <a href="{{ route('register') }}" class="nav-cta">สมัครสมาชิก ✦</a>
    @endauth
  </nav>
  <button class="menu-toggle" id="menuToggle" aria-label="menu" onclick="document.getElementById('navLinks').classList.toggle('open')"><span></span></button>
</header>
