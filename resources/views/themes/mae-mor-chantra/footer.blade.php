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
        <li><a href="{{ route('download') }}">📱 ดาวน์โหลดแอพมือถือ</a></li>
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
        @else
          <li><a href="{{ route('login') }}">เข้าสู่ระบบ</a></li>
          <li><a href="{{ route('register') }}">สมัครสมาชิก</a></li>
        @endauth
      </ul>
    </div>
  </div>
  <div class="foot-bottom">
    <span>© {{ date('Y') }} MAE MOR CHANTRA · ALL RIGHTS RESERVED</span>
    <nav style="display:flex;gap:20px;flex-wrap:wrap">
      <a href="{{ route('legal.privacy') }}" style="color:var(--ink-dim)">นโยบายความเป็นส่วนตัว</a>
      <a href="{{ route('legal.terms') }}" style="color:var(--ink-dim)">ข้อตกลงการใช้งาน</a>
    </nav>
    <span>CRAFTED UNDER THE MOON ☾</span>
  </div>
</footer>
