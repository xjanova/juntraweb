@extends('layouts.app')
@section('title', 'AI Chat ดูดวง')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div class="chat-shell">
    <div style="text-align:center;margin-bottom:48px">
      <div class="eyebrow" style="display:inline-flex">CHANTRA AI ORACLE</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">คุยกับ <em>แม่หมอ AI</em></h1>
      <p class="lede" style="margin:0 auto">ถามได้ทุกเรื่อง — ความรัก การงาน ชะตาดวง คำแนะนำชีวิต แม่หมอจันทรา AI พร้อมตอบทันที ภาษาเข้าใจง่าย</p>
    </div>

    <div class="panel">
      <div class="chat-list" id="chat-list">
        @forelse ($conversation->messages as $msg)
          <div class="bubble {{ $msg->role }}">
            @if ($msg->role === 'assistant')<b>แม่หมอจันทรา:</b> @endif
            {!! nl2br(e($msg->content)) !!}
          </div>
        @empty
          <div class="bubble ai">
            <b>แม่หมอจันทรา:</b> สวัสดีค่ะ ลูก — แม่หมอจันทรารับฟังเรื่องราวของหนูเสมอ ไม่ต้องเขินเลย ลองพิมพ์คำถามด้านล่างได้เลยค่ะ จะถามเรื่องดวง ความรัก การงาน หรือเส้นทางชีวิตก็ได้
          </div>
        @endforelse
      </div>

      <form action="{{ route('chat.send') }}" method="POST" class="chat-input-row">
        @csrf
        <input type="text" name="message" placeholder="พิมพ์คำถามของคุณ..." autocomplete="off" required maxlength="2000">
        <button class="btn btn-primary" style="padding:14px 28px">ส่ง</button>
      </form>
    </div>
  </div>
</section>

@push('scripts')
<script>
  // Auto-scroll chat to bottom on load
  document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('chat-list');
    if (list) list.scrollTop = list.scrollHeight;
  });
</script>
@endpush
@endsection
