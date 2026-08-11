<nav class="top" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
  <a href="{{ route('home') }}" class="brand">
    <div class="brand-mark"><img src="{{ asset('images/logo.webp') }}" alt=""></div>
    <div class="brand-name">
      <b>MAE MOR CHANTRA</b>
      <span>{{ config('app.name', 'แม่หมอจันทรา') }}</span>
    </div>
  </a>

  {{-- display:contents on desktop keeps the original brand | links | auth
       layout; on mobile this wrapper becomes the dropdown panel. --}}
  <div class="nav-collapse" :class="{ open: open }" x-on:click="open = false">
    <div class="nav-links">
      <a href="{{ route('tarot.index') }}">ไพ่ยิปซี</a>
      <a href="{{ route('deep.index') }}">ดูดวงเชิงลึก</a>
      <a href="{{ route('horoscope.index') }}">ดวงรายวัน</a>
      <a href="{{ route('numerology.index') }}">เลขศาสตร์</a>
      <a href="{{ route('palmistry.index') }}">ลายมือ</a>
      <a href="{{ route('auspicious.index') }}">ฤกษ์ยาม</a>
      <a href="{{ route('chat.index') }}">AI ทำนาย</a>
      <a href="{{ route('download') }}">แอพมือถือ</a>
    </div>
    @auth
      <div class="nav-auth">
        <a href="{{ route('wallet.index') }}" class="nav-cta" style="border-color:var(--line);color:var(--gold)">วอลเลต</a>
        <a href="{{ route('account.dashboard') }}" class="nav-cta" style="border-color:var(--line);color:var(--ink-dim)">บัญชีของฉัน</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-cta">ออกจากระบบ</button></form>
      </div>
    @else
      <div class="nav-auth">
        <a href="{{ route('login') }}" class="nav-cta" style="border-color:var(--line);color:var(--ink-dim)">เข้าสู่ระบบ</a>
        <a href="{{ route('register') }}" class="nav-cta">สมัครสมาชิก</a>
      </div>
    @endauth
  </div>

  {{-- Inside the x-data root (nav.top), so tapping it isn't an "outside" click. --}}
  <button class="burger" type="button" aria-label="เมนู" :aria-expanded="open" x-on:click="open = !open"><span></span></button>
</nav>
