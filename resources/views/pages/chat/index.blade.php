@extends('layouts.app')
@section('title', 'AI Chat · แม่หมอจันทรา')

@section('content')
<section class="canvas" style="padding-top: 160px">
  <div class="chat-shell" x-data="chatBot()">
    <div style="text-align:center;margin-bottom:36px">
      <div class="eyebrow" style="display:inline-flex">CHANTRA AI ORACLE</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,72px);margin-bottom:16px">
        คุยกับ <em>แม่หมอ AI</em>
        @if ($channel)
          <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 14px;border-radius:999px;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;border:1px solid var(--line);background:linear-gradient(135deg,rgba(244,207,106,.15),rgba(244,207,106,.04));color:var(--gold);vertical-align:middle;margin-left:14px">
            @if ($channel === 'facebook')
              <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11 10.13 11.93v-8.44H7.08v-3.5h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.79V24C19.61 23.07 24 18.1 24 12.07z"/></svg> Facebook
            @else
              <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755z"/></svg> LINE
            @endif
          </span>
        @endif
      </h1>
      <p class="lede" style="margin:0 auto">
        ถามได้ทุกเรื่อง — ความรัก การงาน ชะตาดวง คำแนะนำชีวิต ระบบเดียวกับบอทแม่หมอใน Facebook Messenger / LINE OA
      </p>
    </div>

    @if ($gate['allowed'] && $balance !== null && $cost > 0)
      @php $low = bccomp(number_format($balance, 2, '.', ''), number_format($cost * 5, 2, '.', ''), 2) < 0; @endphp
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 20px;border:1px solid {{ $low ? 'rgba(212,160,23,.6)' : 'var(--line-soft)' }};border-radius:14px;margin-bottom:18px;background:{{ $low ? 'rgba(212,160,23,.08)' : 'rgba(244,207,106,.04)' }};font-size:13px;flex-wrap:wrap">
        <div>
          <span style="font-family:var(--display);letter-spacing:.18em;text-transform:uppercase;font-size:11px;color:var(--gold);margin-right:10px">เครดิต</span>
          <strong style="font-family:var(--display);color:var(--gold);font-size:16px">฿{{ number_format($balance, 2) }}</strong>
          <span style="color:var(--ink-dim);margin-left:10px">หักครั้งละ ฿{{ number_format($cost, $cost == intval($cost) ? 0 : 2) }} ต่อข้อความ</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          @if ($low)<span style="color:#d4a017;font-size:12px">เครดิตเหลือน้อย</span>@endif
          <a href="{{ route('wallet.topup') }}" class="btn btn-ghost" style="padding:6px 16px;font-size:12px">เติมเงิน</a>
        </div>
      </div>
    @endif

    @unless ($gate['allowed'])
      <div class="flash" style="text-align:center;padding:28px 32px;margin-bottom:24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">
          @if ($gate['code'] === 'guest') ต้องเข้าสู่ระบบก่อน
          @elseif ($gate['code'] === 'no_link') เชื่อม FACEBOOK หรือ LINE ก่อน
          @else ต่อสายแม่หมอใหม่ @endif
        </div>
        <p style="font-family:var(--thai);font-size:15px;color:var(--ink-dim);max-width:520px;margin:0 auto 20px;line-height:1.7">
          @if ($gate['code'] === 'guest')
            เพื่อให้แม่หมอจดจำเรื่องราวของลูก ระบบให้คุยกับแม่หมอเฉพาะ <em style="color:var(--gold);font-style:normal">สมาชิกที่เชื่อม Facebook หรือ LINE</em> ผ่าน Thaiprompt — สมัครครั้งเดียว ใช้ได้ทุกที่
          @else
            {{ $gate['reason'] }}
          @endif
        </p>
        <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary">
          @if ($gate['code'] === 'guest') เชื่อมบัญชี Thaiprompt
          @else เข้าสู่ระบบใหม่ผ่าน Thaiprompt
          @endif
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
      </div>
    @endunless

    <div class="panel">
      <div class="chat-list" id="chat-list" x-ref="list">
        @forelse ($conversation->messages as $msg)
          <div class="bubble {{ $msg->role === 'assistant' ? 'ai' : 'user' }}">
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
            <span style="display:inline-flex;gap:5px;padding:4px 0;vertical-align:middle">
              <span class="dot"></span><span class="dot"></span><span class="dot"></span>
            </span>
          </div>
        </template>
      </div>

      <form @submit.prevent="send" class="chat-input-row field" style="margin-bottom:0">
        @csrf
        <input
          type="text"
          x-model="message"
          x-ref="input"
          placeholder="{{ $gate['allowed'] ? 'พิมพ์คำถามของคุณ...' : 'เชื่อม Facebook/LINE ก่อนเพื่อพิมพ์ถาม' }}"
          autocomplete="off"
          maxlength="2000"
          {{ $gate['allowed'] ? '' : 'disabled' }}
          style="{{ $gate['allowed'] ? '' : 'opacity:.5;cursor:not-allowed' }}"
        >
        <button class="btn btn-primary" :disabled="thinking || !message.trim()" {{ $gate['allowed'] ? '' : 'disabled' }}>
          <span x-show="!thinking">ส่ง</span>
          <span x-show="thinking">กำลังส่ง</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </button>
      </form>
    </div>

    @if ($gate['allowed'])
      <div style="text-align:center;margin-top:18px;font-family:var(--display);font-size:11px;color:var(--ink-faint);letter-spacing:.22em;text-transform:uppercase">
        powered by Thaiprompt Fortune Bot · ระบบเดียวกับ FB MESSENGER / LINE OA
      </div>
    @endif
  </div>
</section>

@push('head')
{{-- Just the typing-dot animation — everything else uses theme classes/vars. --}}
<style>
  .dot { width:5px;height:5px;border-radius:50%;background:var(--gold);opacity:.35;animation:dotPulse 1.2s infinite; }
  .dot:nth-child(2){ animation-delay:.18s }
  .dot:nth-child(3){ animation-delay:.36s }
  @keyframes dotPulse { 0%,60%,100%{opacity:.3;transform:translateY(0)} 30%{opacity:1;transform:translateY(-3px)} }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('chatBot', () => ({
    message: '',
    thinking: false,

    init() { this.scrollToBottom(); },
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

        // 402 = insufficient funds. Show the explanation as a bubble and
        // bounce the user to the top-up page so they don't have to hunt
        // for the "เติมเงิน" button in the banner.
        if (r.status === 402) {
          const msg = j.error || 'เครดิตไม่พอ — กำลังพาไปหน้าเติมเงิน';
          this.appendBubble('assistant', msg);
          setTimeout(() => { window.location.href = @js(route('wallet.topup')); }, 1500);
          return;
        }

        // 429 = rate-limited. Tell the user to slow down.
        if (r.status === 429) {
          this.appendBubble('assistant', 'พิมพ์เร็วเกินไปนะคะ ลูก — รอสักครู่แล้วลองใหม่อีกครั้ง');
          return;
        }

        this.appendBubble('assistant', r.ok ? (j.reply || 'แม่หมอกำลังพักสายตา · ลองใหม่อีกครั้งนะคะ') : (j.error || 'ระบบขัดข้องชั่วคราว'));
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
