{{--
  🌸 (2026-11-12, owner) ครั้งแรกที่เข้าเว็บ — หน้าเลือก "ดูดวงฟรีเลย" / "ให้บุษบาพาทัวร์" + ทัวร์เสียงบุษบาข้ามหน้า
  ใครเห็น/เมื่อไร: App\Support\BusabaTour · ข้อความและเสียง: config/busaba_tour.php · ตัวทัวร์: resources/js/busaba-tour.js

  เรนเดอร์ก็ต่อเมื่อ (1) หน้านี้ขึ้นหน้าเลือกได้ หรือ (2) ทัวร์กำลังเดิน / ?tour=1 — นอกนั้นไม่มีอะไรเลย (ไม่โหลดสคริปต์)
  เสียง: มือถือ/แอป FB ไม่ยอมเล่นเสียงจนกว่าจะแตะ → เสียงขั้นแรกเริ่มจากการแตะปุ่ม "ให้บุษบาพาทัวร์" เสมอ
--}}
@php
  use App\Support\BusabaTour;

  $tourUser  = auth()->user();
  $skipTour  = request()->routeIs('install.*', 'filament.*', 'tarot.pick', 'tarot.show', 'login', 'register', 'password.*', 'verification.*');
  $tourOffer = ! $skipTour && BusabaTour::offerable(request(), $tourUser, View::hasSection('busaba-tour-offer'));
  $tourRun   = ! $skipTour && BusabaTour::running(request());

  if ($tourOffer || $tourRun) {
      // ทัวร์/หน้าเลือกอยู่บนจอ — ป๊อปอัป "มีอะไรใหม่" ไม่เด้งทับ
      request()->attributes->set('hide_whats_new', true);
  }

  $freeOn = \App\Support\FreeReadingPolicy::enabled();
  $busabaPoses = ($tourOffer || $tourRun) ? \App\Support\OracleMedia::poses('busaba') : [];
  $tourData = ($tourOffer || $tourRun) ? [
      'offer'   => $tourOffer,
      'start'   => request()->query('tour') === '1',
      'guest'   => $tourUser === null,
      'cookie'  => BusabaTour::cookie(),
      'run'     => BusabaTour::runCookie(),
      'seenUrl' => route('tour.seen'),
      'freeUrl' => BusabaTour::freeUrl($tourUser),
      'freeOn'  => $freeOn,
      'roomUrl' => route('chat.index', ['room' => 'busaba']),
      'loginUrl'=> route('thaiprompt.redirect', ['to' => '/chat?pick=1']),
      'still'   => \App\Support\OracleMedia::asset('images/juntra/theater/sprite-busaba.webp'),
      'poses'   => collect($busabaPoses['clips'] ?? [])->pluck('url', 'name')->all(),
      'poseScale' => (float) ($busabaPoses['scale'] ?? 1),
      'steps'   => BusabaTour::steps(),
  ] : null;
@endphp

@if ($tourData)
  @vite(['resources/css/busaba-tour.css', 'resources/js/busaba-tour.js'])
  <script type="application/json" id="bt-data">@json($tourData)</script>

  @if ($tourOffer)
    {{-- หน้าเลือกสองปุ่ม — ซ่อนไว้จนสคริปต์เช็ก localStorage ของเครื่องนี้ (เคยเลือกแล้วในแท็บอื่น = ไม่ขึ้น) --}}
    <div class="bt-welcome" id="bt-welcome" role="dialog" aria-modal="true" aria-labelledby="bt-welcome-title" hidden>
      <div class="bt-welcome-card">
        <button type="button" class="bt-x" data-bt="later" aria-label="ไว้ทีหลัง">×</button>
        <div class="bt-welcome-busaba" aria-hidden="true">
          <img src="{{ $tourData['still'] }}" alt="" class="bt-still" width="767" height="1200" decoding="async">
        </div>
        <div class="bt-welcome-body">
          <div class="bt-eyebrow">ยินดีต้อนรับสู่จันทรา</div>
          <h2 id="bt-welcome-title" class="bt-welcome-title">สวัสดีค่า บุษบาเองค่ะ ✨</h2>
          <p class="bt-welcome-lede">มาครั้งแรกใช่ไหมคะ อยากเริ่มแบบไหนดี</p>

          <div class="bt-choices">
            <button type="button" class="bt-choice bt-choice-free" data-bt="free">
              <span class="bt-choice-ico" aria-hidden="true">{{ $freeOn ? '🎁' : '🔮' }}</span>
              <span class="bt-choice-txt">
                <b>{{ $freeOn ? 'ดูดวงฟรีเลย' : 'เข้าห้องบุษบา' }}</b>
                <small>{{ $freeOn ? 'รับไพ่ฟรี 1 ใบ บุษบาเปิดให้ทันที' : 'ดูไว ตรงประเด็น ทีละเรื่อง' }}</small>
              </span>
            </button>
            <button type="button" class="bt-choice bt-choice-tour" data-bt="tour">
              <span class="bt-choice-ico" aria-hidden="true">🎧</span>
              <span class="bt-choice-txt">
                <b>ให้บุษบาพาทัวร์</b>
                <small>เสียงพากย์ไทย ประมาณ 2 นาที · ข้ามได้ทุกเมื่อ</small>
              </span>
            </button>
          </div>
          <button type="button" class="bt-later" data-bt="later">ไว้ทีหลัง</button>
        </div>
      </div>
    </div>
  @endif
@endif
