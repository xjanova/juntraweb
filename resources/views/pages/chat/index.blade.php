@extends('layouts.app')
@section('title', 'AI Chat · แม่หมอจันทรา')

@push('head')
<style>
  .chat-gate {
    margin: 0 auto 28px; max-width: 720px;
    padding: 24px 28px; border-radius: 18px;
    background: linear-gradient(135deg, rgba(244,207,106,.10), rgba(176,122,255,.06));
    border: 1px solid rgba(244,207,106,.22);
    text-align: center;
  }
  .chat-gate h3 { font-family: var(--display); font-size: 18px; letter-spacing: .14em; color: var(--gold, #f4cf6a); margin: 0 0 12px; text-transform: uppercase; }
  .chat-gate p  { color: var(--ink, #eadcc1); margin: 0 0 16px; line-height: 1.7; }
  .channel-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 99px; font-size: 12px;
    letter-spacing: .08em; text-transform: uppercase;
    margin-left: 12px; vertical-align: middle;
  }
  .channel-badge.facebook { background: rgba(78,121,216,.18); color: #8aaee5; border: 1px solid rgba(78,121,216,.35); }
  .channel-badge.line     { background: rgba(80,200,80,.18); color: #9ed99e; border: 1px solid rgba(80,200,80,.35); }
  .typing { display: inline-flex; gap: 4px; padding: 4px 0; }
  .typing span {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--gold, #f4cf6a); opacity: .4;
    animation: typing 1.2s infinite;
  }
  .typing span:nth-child(2) { animation-delay: .2s; }
  .typing span:nth-child(3) { animation-delay: .4s; }
  @keyframes typing {
    0%, 60%, 100% { opacity: .3; transform: translateY(0); }
    30% { opacity: 1; transform: translateY(-3px); }
  }
  .ai-attrib {
    text-align: center; margin-top: 18px;
    font-size: 11px; color: var(--ink-dim, #9d8fb1); letter-spacing: .08em;
  }
</style>
@endpush

@section('content')
<section class="canvas" style="padding-top:160px">
  <div class="chat-shell" x-data="chatBot()">
    <div style="text-align:center;margin-bottom:36px">
      <div class="eyebrow" style="display:inline-flex">CHANTRA AI ORACLE</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">
        คุยกับ <em>แม่หมอ AI</em>
        @if ($channel)
          <span class="channel-badge {{ $channel }}">
            @if ($channel === 'facebook') <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11 10.13 11.93v-8.44H7.08v-3.5h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.79V24C19.61 23.07 24 18.1 24 12.07z"/></svg> Facebook
            @else <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg> LINE
            @endif
          </span>
        @endif
      </h1>
      <p class="lede" style="margin:0 auto;max-width:720px">
        ถามได้ทุกเรื่อง — ความรัก การงาน ชะตาดวง คำแนะนำชีวิต ระบบเดียวกับบอทแม่หมอใน Facebook Messenger / LINE OA
      </p>
    </div>

    @unless ($gate['allowed'])
      <div class="chat-gate">
        @if ($gate['code'] === 'guest')
          <h3>ต้องเข้าสู่ระบบก่อน</h3>
          <p>เพื่อให้แม่หมอจดจำเรื่องราวของลูก ระบบให้คุยกับแม่หมอเฉพาะ <strong>สมาชิกที่เชื่อม Facebook หรือ LINE</strong> ผ่าน Thaiprompt — สมัครครั้งเดียว ใช้ได้ทุกที่</p>
          <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary" style="display:inline-flex;gap:10px">
            เชื่อมบัญชี Thaiprompt (Facebook / LINE)
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </a>
        @elseif ($gate['code'] === 'no_link')
          <h3>เชื่อม Facebook หรือ LINE ก่อน</h3>
          <p>{{ $gate['reason'] }}</p>
          <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary">เข้าสู่ระบบใหม่ผ่าน Thaiprompt</a>
        @else
          <h3>ต่อสายแม่หมอใหม่</h3>
          <p>{{ $gate['reason'] }}</p>
          <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary">เข้าสู่ระบบใหม่</a>
        @endif
      </div>
    @endunless

    <div class="panel">
      <div class="chat-list" id="chat-list" x-ref="list">
        @forelse ($conversation->messages as $msg)
          <div class="bubble {{ $msg->role }}">
            @if ($msg->role === 'assistant')<b>แม่หมอจันทรา:</b> @endif
            {!! nl2br(e($msg->content)) !!}
          </div>
        @empty
          <div class="bubble ai">
            <b>แม่หมอจันทรา:</b> สวัสดีค่ะ ลูก — แม่หมอจันทรารับฟังเรื่องราวของหนูเสมอ ไม่ต้องเขินเลย ลองพิมพ์คำถามด้านล่างได้เลยค่ะ
          </div>
        @endforelse

        <template x-if="thinking">
          <div class="bubble ai">
            <b>แม่หมอจันทรา:</b>
            <span class="typing"><span></span><span></span><span></span></span>
          </div>
        </template>
      </div>

      <form @submit.prevent="send" class="chat-input-row">
        @csrf
        <input
          type="text"
          x-model="message"
          x-ref="input"
          placeholder="{{ $gate['allowed'] ? 'พิมพ์คำถามของคุณ...' : 'เชื่อม Facebook/LINE ก่อนเพื่อพิมพ์ถาม' }}"
          autocomplete="off"
          maxlength="2000"
          {{ $gate['allowed'] ? '' : 'disabled' }}
        >
        <button class="btn btn-primary" style="padding:14px 28px"
                :disabled="thinking || !message.trim()"
                {{ $gate['allowed'] ? '' : 'disabled' }}>
          <span x-show="!thinking">ส่ง</span>
          <span x-show="thinking">กำลังส่ง...</span>
        </button>
      </form>
    </div>

    @if ($gate['allowed'])
      <div class="ai-attrib">
        ⚡ ขับเคลื่อนด้วย Thaiprompt Fortune Bot — ระบบเดียวกับ FB Messenger / LINE OA
      </div>
    @endif
  </div>
</section>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('chatBot', () => ({
    message: '',
    thinking: false,

    init() {
      this.scrollToBottom();
    },

    scrollToBottom() {
      this.$nextTick(() => {
        const list = this.$refs.list;
        if (list) list.scrollTop = list.scrollHeight;
      });
    },

    appendBubble(role, text) {
      const list = this.$refs.list;
      if (!list) return;
      const div = document.createElement('div');
      div.className = 'bubble ' + (role === 'user' ? 'user' : 'ai');
      const safe = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      div.innerHTML = role === 'assistant' ? `<b>แม่หมอจันทรา:</b> ${safe}` : safe;
      list.appendChild(div);
      this.scrollToBottom();
    },

    async send() {
      const text = this.message.trim();
      if (!text || this.thinking) return;

      this.appendBubble('user', text);
      this.message = '';
      this.thinking = true;
      this.scrollToBottom();

      try {
        const r = await fetch(@js(route('chat.send')), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: JSON.stringify({ message: text }),
        });
        const j = await r.json();
        if (r.ok) {
          this.appendBubble('assistant', j.reply || 'แม่หมอกำลังพักสายตา · ลองใหม่อีกครั้งนะคะ');
        } else {
          this.appendBubble('assistant', j.error || 'ระบบขัดข้องชั่วคราว · ลองใหม่อีกครั้ง');
        }
      } catch (e) {
        this.appendBubble('assistant', 'เครือข่ายมีปัญหา · ลองใหม่อีกครั้งนะคะ');
      } finally {
        this.thinking = false;
        this.$refs.input?.focus();
      }
    },
  }));
});
</script>
@endpush
@endsection
