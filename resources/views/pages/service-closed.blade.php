@extends('layouts.app')
@section('title', ($name ?: 'บริการนี้') . ' · ปิดปรับปรุงชั่วคราว')

@section('content')
{{-- บริการที่แอดมินปิดไว้ (ServiceGate / service.open) — ลิงก์เดิมจากบอท/โพสต์ยังเปิดได้ ไม่เจอหน้า error --}}
<section class="canvas" style="padding-top:160px">
  <div style="max-width:620px;margin:0 auto">
    <div class="panel" style="text-align:center;padding:40px 30px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">ปิดปรับปรุงชั่วคราว</div>
      <h1 style="font-family:var(--thai);font-size:clamp(24px,3.4vw,32px);margin:0 0 14px">{{ $name }}</h1>
      <p style="font-family:var(--thai);color:var(--ink-dim);line-height:1.85;max-width:50ch;margin:0 auto 26px">
        {{ $message }}
      </p>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <a href="{{ route('tarot.index') }}" class="btn btn-primary">เปิดไพ่กับแม่หมอ</a>
        <a href="{{ route('tarot.free') }}" class="btn btn-ghost">ดูดวงฟรี 1 ใบ</a>
        <a href="{{ route('chat.index') }}" class="btn btn-ghost">คุยกับแม่หมอฟรี</a>
      </div>
    </div>
  </div>
</section>
@endsection
