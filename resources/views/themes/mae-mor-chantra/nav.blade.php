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
    <a href="{{ route('download') }}">แอพมือถือ</a>
  </div>
  @auth
    <div style="display:flex;gap:10px;align-items:center">
      <a href="{{ route('wallet.index') }}" class="nav-cta" style="border-color:var(--line);color:var(--gold)">วอลเลต</a>
      <a href="{{ route('account.dashboard') }}" class="nav-cta" style="border-color:var(--line);color:var(--ink-dim)">บัญชีของฉัน</a>
      <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-cta">ออกจากระบบ</button></form>
    </div>
  @else
    <div style="display:flex;gap:10px;align-items:center">
      <a href="{{ route('login') }}" class="nav-cta" style="border-color:var(--line);color:var(--ink-dim)">เข้าสู่ระบบ</a>
      <a href="{{ route('register') }}" class="nav-cta">สมัครสมาชิก</a>
    </div>
  @endauth
</nav>
