<footer class="footer">
  <div class="footer-grid">
    <div class="footer-col">
      <a class="brand" href="{{ route('home') }}" style="margin-bottom:18px">
        <div class="brand-logo"><img src="{{ asset('images/juntra/logo.webp') }}" alt=""></div>
        <div class="brand-text">
          <span class="th">{{ config('app.name', 'จันทราพยากรณ์') }}</span>
          <span class="en">JUNTRA PAYAKORN</span>
        </div>
      </a>
      <p>ศาสตร์แห่งการพยากรณ์ที่ผสมผสานโหราศาสตร์ ไพ่ยิปซี และพลังจิตวิญญาณสายมูเตลู</p>
    </div>
    <div class="footer-col">
      <h4>บริการ</h4>
      <a href="{{ route('tarot.index') }}">ไพ่ยิปซีออนไลน์</a>
      <a href="{{ route('horoscope.index') }}">ดวงรายวัน 12 ราศี</a>
      <a href="{{ route('horoscope.thai') }}">ปีนักษัตรไทย</a>
      <a href="{{ route('auspicious.index') }}">หาฤกษ์มงคล</a>
      <a href="{{ route('download') }}">📱 ดาวน์โหลดแอพมือถือ</a>
    </div>
    <div class="footer-col">
      <h4>เกี่ยวกับ</h4>
      <a href="{{ route('numerology.index') }}">เลขศาสตร์</a>
      <a href="{{ route('palmistry.index') }}">ดูลายมือ</a>
      <a href="{{ route('chat.index') }}">AI Chat ดูดวง</a>
      @auth
        <a href="{{ route('account.history') }}">ประวัติของฉัน</a>
      @endauth
    </div>
    <div class="footer-col">
      <h4>บัญชี</h4>
      @auth
        <a href="{{ route('account.dashboard') }}">หน้าหลักของฉัน</a>
        <a href="{{ route('profile.edit') }}">โปรไฟล์</a>
      @else
        <a href="{{ route('login') }}">เข้าสู่ระบบ</a>
        <a href="{{ route('register') }}">สมัครสมาชิก</a>
      @endauth
    </div>
  </div>
  <div class="footer-bottom">
    <span>© {{ date('Y') }} Juntra Payakorn. All energies reserved.</span>
    <nav style="display:flex;gap:20px;flex-wrap:wrap">
      <a href="{{ route('legal.privacy') }}" style="color:var(--ink-dim)">นโยบายความเป็นส่วนตัว</a>
      <a href="{{ route('legal.terms') }}" style="color:var(--ink-dim)">ข้อตกลงการใช้งาน</a>
    </nav>
    <span>Made with ✦ xman studio</span>
  </div>
</footer>
