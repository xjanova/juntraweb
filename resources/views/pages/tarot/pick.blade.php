@extends('layouts.app')
@section('title', 'เลือกไพ่ของคุณ')

{{-- 🃏 (2026-11-05) หน้าเลือกไพ่ = โรงละครเปิดไพ่เต็มจอ (แทนพัดไพ่ HTML เดิม — เจ้าของสั่ง "เอาหน้าเปิดไพ่เดิมออก")
     สับไพ่ → เลือกไพ่ → ยืนยันค่าดูดวง → วางไพ่ → ส่งฟอร์มเดิมไป tarot.cast (ตัดเงิน/สร้างรายการที่เซิร์ฟเวอร์เหมือนเดิม) --}}
@section('content')
{{-- 🔊 ผู้พยากรณ์ชวนตั้งจิตตอนเข้าหน้า · เลือกครบ = "ครบแล้ว" (ถ้าลูกค้าเปิดเสียงไว้) --}}
<x-oracle-voice :persona="\App\Support\OracleMedia::personaFor($spread)" :cues="['shuffle_focus']" />

<x-card-theater :config="\App\Support\CardTheater::forPick($spread, $cards, [
                    'question' => $question,
                    'cost'     => $cost,
                    'balance'  => $balance,
                ])"
                :ask-birth="$askBirth"
                :birth-date="$birthDate" />

<noscript>
  <section class="canvas" style="padding-top:160px;text-align:center">
    <p class="lede">การเลือกไพ่ต้องเปิด JavaScript ในเบราว์เซอร์ก่อนนะคะ</p>
    <a href="{{ route('tarot.index') }}" class="btn btn-ghost">กลับหน้ารวมไพ่</a>
  </section>
</noscript>
@endsection
