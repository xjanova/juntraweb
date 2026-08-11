@extends('layouts.app')
@section('title', 'ฤกษ์ยาม · หาวันมงคลจากตำแหน่งดวงจันทร์จริง')

@section('content')
<section class="canvas" style="padding-top:150px">
  <div style="max-width:880px;margin:0 auto">

    <x-page-hero art="images/juntra/art/auspicious.webp" eyebrow="ฤกษ์ยาม · AUSPICIOUS DATES">
      <x-slot:title>หาวัน <em>มงคล</em></x-slot:title>
      <x-slot:lede>
        คำนวณจากตำแหน่งจริงของดวงจันทร์บนจักรราศี — อ่านฤกษ์บน 9 จากนักษัตรที่ดวงจันทร์สถิต
        ประกอบกับดิถีข้างขึ้น-ข้างแรมและวารประจำวัน แล้วลงเลข <em>ยามอัฐกาล</em>
        หาชั่วโมงที่ควรตั้งพิธีจริง ๆ ถ่วงน้ำหนักตามประเภทงานของคุณ
      </x-slot:lede>
    </x-page-hero>

    @if (session('status'))
      <div class="panel" style="margin-bottom:22px;border-color:rgba(224,182,66,.35);padding:18px 22px;color:var(--gold)">
        {{ session('status') }}
      </div>
    @endif

    {{-- แถบ 14 วันข้างหน้า: ให้เห็นก่อนจ่ายว่าระบบคำนวณจริง วันไม่ซ้ำกัน
         (หน้าเดิมมีแค่ฟอร์มลอย ๆ ลูกค้าไม่รู้เลยว่าจ่ายไปแล้วจะได้อะไร) --}}
    @if (! empty($preview))
      <div style="margin-bottom:34px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">ฤกษ์ 14 วันข้างหน้า (เกณฑ์กลาง)</div>
        <div style="display:flex;gap:7px;overflow-x:auto;padding-bottom:10px">
          @foreach ($preview as $d)
            @php $c = $d['ruek']['color']; @endphp
            <div title="{{ $d['ruek']['name'] }} · นักษัตร{{ $d['nakshatra'] }} · {{ $d['tithi']['label'] }}"
                 style="flex:0 0 62px;text-align:center;padding:11px 5px;border-radius:12px;
                        background:{{ $c }}12;border:1px solid {{ $c }}33">
              <div style="font-family:var(--display);font-size:10px;letter-spacing:.1em;color:var(--ink-faint)">
                {{ ['อา','จ','อ','พ','พฤ','ศ','ส'][$d['weekday']['index']] }}
              </div>
              <div style="font-family:var(--display);font-size:19px;color:var(--moon);margin:2px 0 6px">{{ $d['date']->format('j') }}</div>
              <x-moon-phase :illumination="$d['tithi']['illumination']"
                            :waxing="$d['tithi']['side'] === 'waxing'" :size="20" />
              <div style="height:4px;border-radius:999px;background:rgba(255,255,255,.08);margin-top:7px;overflow:hidden">
                <div style="height:100%;width:{{ $d['score_pct'] }}%;background:{{ $c }}"></div>
              </div>
            </div>
          @endforeach
        </div>
        <p style="color:var(--ink-faint);font-size:12px;margin-top:2px">
          แถบสีคือคะแนนฤกษ์กลาง — เลือกประเภทงานด้านล่างแล้วคะแนนจะเปลี่ยนตามหลักของงานนั้น
        </p>
      </div>
    @endif

    {{-- ตารางลงเลขยามของวันนี้ — โชว์ฟรีก่อนจ่าย ให้ลูกค้าเห็นกับตาว่าเลขในตาราง
         มาจากการคำนวณจริงตามระบบ +๕/+๔ ไม่ใช่ตารางสำเร็จรูปที่แปะไว้เฉย ๆ --}}
    @if (! empty($todayYam))
      <div style="margin-bottom:34px">
        <x-yam-table :yam="$todayYam"
                     title="ยามอัฐกาลวันนี้ · ลงเลขให้ดูฟรี"
                     :date-label="'วัน'.\App\Support\ThaiAstro::WEEKDAY[$todayYam['weekday_index']]['name'].' ที่ '.$todayYam['date']->format('d/m/Y')"
                     :highlight="! empty($nowYam) ? ['side' => $nowYam['side'], 'no' => $nowYam['no']] : null" />
        @if (! empty($nowYam))
          <p style="color:var(--ink-faint);font-size:12px;margin-top:8px">
            ช่องที่ขอบทองคือ <strong style="color:var(--gold)">ยามนี้</strong> —
            ยาม{{ $nowYam['name'] }} ({{ $nowYam['planet_name'] }}) {{ $nowYam['from'] }}–{{ $nowYam['to'] }} น.
            ยามที่ {{ \App\Support\ThaiAstro::thaiNumber($nowYam['no']) }} ของฟาก{{ $nowYam['side'] === 'night' ? 'กลางคืน' : 'กลางวัน' }}
          </p>
        @endif
      </div>
    @endif

    {{-- ฟอร์มคุมความกว้างไว้ที่ 680px เท่าหน้าเลขศาสตร์/ลายมือ/เชิงลึก
         ตัวหน้าเปิดกว้าง 880px เพื่อให้แถบ 14 วันกับตารางฤกษ์บน 9 มีที่พอ แต่ถ้าปล่อยฟอร์ม
         กว้างตามไปด้วย ปุ่ม width:100% จะยืดเป็นแผ่นทองยาว 880px — กว้างกว่าปุ่มเดียวกัน
         ในหน้าอื่นของเว็บ 200px และดูใหญ่เกินเหตุ --}}
    <div style="max-width:680px;margin:0 auto">
    @if (isset($cost) && $cost > 0)
      <div style="text-align:center;margin-bottom:18px;font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase">
        ค่าบริการ ฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }} / ครั้ง
      </div>
    @endif

    <form action="{{ route('auspicious.find') }}" method="POST" class="panel"
          x-data="{submitting:false}" @submit="submitting=true">
      @csrf
      <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::uuid() }}">

      <div class="field">
        <label for="occasion_type">ประเภทงาน</label>
        <select id="occasion_type" name="occasion_type">
          @foreach ($occasions as $key => $o)
            <option value="{{ $key }}" @selected(old('occasion_type') === $key)>{{ $o['icon'] }} {{ $o['label'] }}</option>
          @endforeach
        </select>
        <p style="color:var(--ink-faint);font-size:12px;margin-top:6px">
          ฤกษ์ที่ดีกับงานแต่งไม่ใช่ฤกษ์เดียวกับที่ดีกับการเปิดร้าน — ระบบให้น้ำหนักต่างกันตามหมวดนี้
        </p>
      </div>

      <div class="field">
        <label for="occasion">รายละเอียดงานของคุณ</label>
        <input type="text" id="occasion" name="occasion" value="{{ old('occasion') }}"
               placeholder="เช่น แต่งงานลูกสาว, เปิดร้านกาแฟ, ออกรถกระบะ, ขึ้นบ้านใหม่" required>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="field">
          <label for="from_date">เริ่มจากวันที่</label>
          <input type="date" id="from_date" name="from_date"
                 min="{{ now()->toDateString() }}"
                 value="{{ old('from_date', now()->toDateString()) }}">
        </div>
        <div class="field">
          <label for="to_date">ถึงวันที่</label>
          <input type="date" id="to_date" name="to_date"
                 min="{{ now()->toDateString() }}"
                 value="{{ old('to_date', now()->addDays(59)->toDateString()) }}">
        </div>
      </div>

      <button class="btn btn-primary" style="width:100%;justify-content:center" :disabled="submitting">
        <span x-show="!submitting">ค้นหาวันมงคล</span>
        <span x-show="submitting">กำลังคำนวณตำแหน่งดวงจันทร์ ⋯</span>
        <svg x-show="!submitting" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
      <p style="color:var(--ink-faint);font-size:12px;margin-top:12px;text-align:center">
        ถ้าในช่วงที่เลือกไม่มีวันไหนผ่านเกณฑ์ฤกษ์ ระบบจะไม่หักเครดิต
      </p>
    </form>
    </div>

    {{-- ตำราฤกษ์บน 9 — ทำให้ลูกค้าตรวจสอบผลที่ได้กับตำราได้เอง --}}
    @if (! empty($ruekList))
      <div style="margin-top:48px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:16px">ฤกษ์บน 9 ตามตำราไทย</div>
        <div class="auto-grid" style="--col-min:260px;gap:12px">
          @foreach ($ruekList as $r)
            <div class="panel" style="padding:18px;border-color:{{ $r['color'] }}33">
              <div style="display:flex;align-items:center;gap:9px;margin-bottom:7px">
                <span style="width:9px;height:9px;border-radius:50%;background:{{ $r['color'] }};flex-shrink:0"></span>
                <span style="font-family:var(--display);font-size:15px;color:{{ $r['color'] }}">{{ $r['name'] }}</span>
              </div>
              <div style="color:var(--ink-dim);font-size:13px;line-height:1.75">{{ $r['summary'] }}</div>
              <div style="color:var(--ink-faint);font-size:12px;line-height:1.7;margin-top:8px">
                <strong style="color:#5f9e6e">เหมาะ:</strong> {{ $r['good'] }}
              </div>
            </div>
          @endforeach
        </div>
      </div>
    @endif

    @if (isset($upcoming) && count($upcoming))
      <div style="margin-top:48px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:20px">วันสำคัญที่แม่หมอปักหมุดไว้</div>
        @foreach ($upcoming as $a)
          <div class="panel" style="margin-bottom:14px;padding:24px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
              <div>
                <div style="font-family:var(--display);font-size:13px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">{{ $a->type }}</div>
                <h3 style="font-family:var(--serif);font-size:22px;color:var(--moon);font-weight:500">{{ $a->title }}</h3>
                @if($a->description)<p style="margin-top:8px;color:var(--ink-dim);font-size:14px">{{ $a->description }}</p>@endif
              </div>
              <div style="font-family:var(--display);font-size:24px;color:var(--gold)">{{ $a->date->format('d/m/Y') }}</div>
            </div>
          </div>
        @endforeach
      </div>
    @endif
  </div>
</section>
@endsection
