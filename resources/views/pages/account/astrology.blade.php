@extends('layouts.app')
@section('page-handles-flash', '1')
@section('title', 'ข้อมูลโหราศาสตร์')

@section('content')
<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'astrology'])

    <div class="account-content">
      <x-page-hero art="images/juntra/art/account.webp" />

      <div class="eyebrow">PROFILE • ASTROLOGY</div>
      <h1 style="font-family:var(--serif);font-size:clamp(32px,4vw,52px);font-weight:400;margin:8px 0 12px">
        ข้อมูล <em style="color:var(--gold)">โหราศาสตร์</em>
      </h1>
      <p class="lede" style="margin:0 0 28px;max-width:640px">กรอกข้อมูลครั้งเดียว ใช้กับทุกบริการ — เลขศาสตร์ ฤกษ์ยาม ดวงรายวัน และ AI ทำนายจะใช้ข้อมูลนี้แทนให้คุณกรอกซ้ำทุกครั้ง</p>

      @if (session('status'))
        <div class="flash" style="padding:14px 18px;margin-bottom:24px">{{ session('status') }}</div>
      @endif

      <form method="POST" action="{{ route('account.astrology.update') }}" class="panel" style="padding:28px">
        @csrf
        @method('patch')

        <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">ข้อมูลการเกิด</div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px" class="form-grid-2">
          <div class="field">
            <label for="birth_date">วันเกิด</label>
            <input id="birth_date" type="date" name="birth_date"
              value="{{ old('birth_date', optional($profile->birth_date)->format('Y-m-d')) }}"
              max="{{ now()->format('Y-m-d') }}" min="1900-01-01">
          </div>
          <div class="field">
            <label for="birth_time">เวลาเกิด <span style="color:var(--ink-faint);font-size:12px">(ไม่จำเป็น)</span></label>
            <input id="birth_time" type="time" name="birth_time"
              value="{{ old('birth_time', $profile->birth_time ? \Illuminate\Support\Carbon::parse($profile->birth_time)->format('H:i') : '') }}">
          </div>
        </div>

        <div class="field">
          <label for="birth_place">สถานที่เกิด <span style="color:var(--ink-faint);font-size:12px">(จังหวัด/อำเภอ)</span></label>
          <input id="birth_place" type="text" name="birth_place" placeholder="เช่น เชียงใหม่"
            value="{{ old('birth_place', $profile->birth_place) }}" maxlength="191">
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin:8px 0 24px;padding:12px 14px;border:1px solid var(--line-soft);border-radius:10px;background:rgba(244,207,106,.04)">
          <input id="auto_zodiac" type="checkbox" name="auto_zodiac" value="1" checked style="margin:0">
          <label for="auto_zodiac" style="margin:0;font-size:13px;color:var(--ink-dim)">คำนวณราศี/ปีนักษัตรจากวันเกิดให้อัตโนมัติ</label>
        </div>

        <div class="eyebrow" style="display:inline-flex;margin:8px 0 18px">ราศีและปีนักษัตร</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px" class="form-grid-2">
          <div class="field">
            <label for="zodiac_slug">ราศี (Western)</label>
            <select id="zodiac_slug" name="zodiac_slug">
              <option value="">— เลือกราศี —</option>
              @foreach ($zodiacs as $z)
                <option value="{{ $z->slug }}" @selected(old('zodiac_slug', $profile->zodiac_slug) === $z->slug)>
                  {{ $z->glyph }} {{ $z->name_th }} ({{ $z->name_en }}) · {{ $z->date_range }}
                </option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="chinese_zodiac_slug">ปีนักษัตร (จีน)</label>
            <select id="chinese_zodiac_slug" name="chinese_zodiac_slug">
              <option value="">— เลือกปีนักษัตร —</option>
              @foreach ($chineseZodiacs as $cz)
                <option value="{{ $cz->slug }}" @selected(old('chinese_zodiac_slug', $profile->chinese_zodiac_slug) === $cz->slug)>
                  {{ $cz->glyph }} ปี{{ $cz->name_th }} ({{ $cz->name_en }})
                </option>
              @endforeach
            </select>
          </div>
        </div>

        <div class="eyebrow" style="display:inline-flex;margin:24px 0 18px">ช่องทางติดต่อ</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px" class="form-grid-2">
          <div class="field">
            <label for="phone">เบอร์โทรศัพท์</label>
            <input id="phone" type="tel" name="phone" inputmode="tel" placeholder="081-234-5678"
              value="{{ old('phone', $profile->phone) }}" maxlength="32">
          </div>
          <div class="field">
            <label for="line_id">LINE ID</label>
            <input id="line_id" type="text" name="line_id" placeholder="@yourline"
              value="{{ old('line_id', $profile->line_id) }}" maxlength="64">
          </div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin:18px 0 24px;padding:12px 14px;border:1px solid var(--line-soft);border-radius:10px">
          <input id="subscribed" type="checkbox" name="subscribed" value="1"
            @checked(old('subscribed', $profile->subscribed))
            style="margin:0">
          <label for="subscribed" style="margin:0;font-size:13px;color:var(--ink-dim)">รับคำทำนายรายวันของราศีฉันทางอีเมล/LINE</label>
        </div>

        <button class="btn btn-primary">บันทึก</button>
        <a href="{{ route('account.dashboard') }}" class="btn btn-ghost" style="margin-left:8px">ยกเลิก</a>
      </form>
    </div>
  </div>
</section>

@push('head')
<style>
  @media (max-width: 680px) {
    .form-grid-2 { grid-template-columns: 1fr !important }
  }
</style>
@endpush
@endsection
