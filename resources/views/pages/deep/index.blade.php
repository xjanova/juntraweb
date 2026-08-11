@extends('layouts.app')
@section('title', 'ดูดวงเชิงลึกกับแม่หมอจันทรา')
@section('description', 'ดูดวงเชิงลึกรายบุคคล — ตอบคำถามที่ค้างคาใจตามวันเกิดและดวงชะตาของคุณโดยตรง')

@section('content')
<section class="canvas">
  <div class="shell-read" style="max-width:680px;margin:0 auto">

    <x-page-hero art="images/juntra/art/deep.webp" eyebrow="DEEP READING">
      <x-slot:title>ดูดวง<em>เชิงลึก</em></x-slot:title>
      <x-slot:lede>
        แม่หมออ่านดวงชะตาจากวันเกิดของคุณ แล้วตอบคำถามที่ค้างคาใจทีละข้อ —
        แพ็กเดียวกับที่แม่หมอทำนายให้ลูกค้าในแชท Facebook / LINE
      </x-slot:lede>
    </x-page-hero>

    @if ($cost > 0)
      <div class="quota-bar" style="justify-content:center">
        <span class="quota-label">ค่าบริการ</span>
        <strong class="quota-value">฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }}</strong>
        @if ($balance !== null)
          <span style="color:var(--ink-dim)">· เครดิตคงเหลือ ฿{{ number_format($balance, 2) }}</span>
        @endif
      </div>
    @endif

    <form method="POST" action="{{ route('deep.store') }}" class="panel" style="padding:28px"
          x-data="{ submitting: false }" @submit="submitting = true">
      @csrf
      {{-- โทเคนกันกดซ้ำ: ฟอร์มที่ render ครั้งเดียวจะใช้โทเคนเดิม รีเฟรชแล้ว
           ส่งซ้ำจึงถูกบล็อก แต่ฟอร์มใหม่ได้โทเคนใหม่และทำรายการได้ตามปกติ --}}
      <input type="hidden" name="_idem" value="{{ (string) Str::uuid() }}">

      <div class="field">
        <label for="birth_date">วันเกิดของคุณ <span style="color:var(--ink-faint);font-size:11px">(ไม่บังคับ แต่ใส่แล้วแม่หมอทำนายได้ตรงกว่ามาก)</span></label>
        <input type="date" id="birth_date" name="birth_date" value="{{ old('birth_date') }}"
               max="{{ now()->subDay()->format('Y-m-d') }}">
      </div>

      <div class="field">
        <label>เรื่องที่อยากให้แม่หมอดู <span style="color:var(--ink-faint);font-size:11px">(ถามได้ถึง {{ $maxQ }} ข้อ)</span></label>
        @for ($i = 0; $i < $maxQ; $i++)
          <input type="text" name="questions[]" maxlength="500"
                 value="{{ old('questions.' . $i) }}"
                 placeholder="{{ $i === 0 ? 'เช่น ปีนี้การงานจะเป็นอย่างไร ควรย้ายงานไหม' : 'ข้อที่ ' . ($i + 1) . ' (ไม่บังคับ)' }}"
                 style="margin-bottom:10px" @if($i === 0) required @endif>
        @endfor
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center"
              :disabled="submitting">
        <span x-show="!submitting">
          ให้แม่หมอทำนาย@if ($cost > 0) · ฿{{ number_format($cost, 0) }}@endif
        </span>
        <span x-show="submitting" x-cloak>แม่หมอกำลังเพ่งดวงชะตา ⋯</span>
      </button>

      <p style="font-size:12px;color:var(--ink-faint);text-align:center;margin:14px 0 0;line-height:1.7">
        คำทำนายอาจใช้เวลาสักครู่ กรุณาอย่าปิดหน้านี้นะคะ<br>
        ถ้าระบบขัดข้อง เครดิตจะถูกคืนเข้ากระเป๋าให้อัตโนมัติ
      </p>
    </form>

    <div style="text-align:center;margin-top:32px;display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('tarot.index') }}" class="btn btn-ghost">เปิดไพ่ยิปซีแทน</a>
      <a href="{{ route('chat.index') }}" class="btn btn-ghost">คุยกับแม่หมอฟรีก่อน</a>
    </div>
  </div>
</section>
@endsection
