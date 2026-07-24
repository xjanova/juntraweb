<header class="nav" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
  <a class="brand" href="{{ route('home') }}">
    <div class="brand-logo"><img src="{{ asset('images/juntra/logo.png') }}" alt="จันทราพยากรณ์"></div>
    <div class="brand-text">
      <span class="th">{{ config('app.name', 'จันทราพยากรณ์') }}</span>
      <span class="en">JUNTRA PAYAKORN</span>
    </div>
  </a>
  {{-- :class binds the .open state; x-on:click closes the menu after tapping a
       link so navigation on mobile doesn't leave the panel hanging open. --}}
  <nav class="nav-links" id="navLinks" :class="{ open: open }" x-on:click="open = false">
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
  {{-- Button lives inside the x-data root (<header>), so tapping it is NOT an
       "outside" click — it toggles instead of being cancelled by click.outside.
       (The old markup put click.outside on #navLinks with the button as a
       sibling, so every tap opened then instantly closed the menu.) --}}
  <button class="menu-toggle" id="menuToggle" type="button" aria-label="เมนู"
          :aria-expanded="open" x-on:click="open = !open"><span></span></button>
</header>
