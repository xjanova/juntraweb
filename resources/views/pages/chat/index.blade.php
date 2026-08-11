@extends('layouts.app')

@php
  // ค่า default กันหน้า history (show()) ที่ไม่ได้ส่งตัวแปรมาครบ
  $dailyLimit  = $dailyLimit ?? 0;
  $dailyLeft   = $dailyLeft ?? 0;
  $suggestions = $suggestions ?? [];
  $topics      = $topics ?? [];
  $awaiting    = $awaiting ?? false;
  $readonly    = $readonly ?? false;
  $isFree      = ($cost ?? 0) <= 0;
  $exhausted   = $isFree && $dailyLimit > 0 && $dailyLeft <= 0;

  // ข้อความเริ่มต้นถูก render markdown ฝั่งเซิร์ฟเวอร์แล้วส่งเป็น HTML ที่ผ่าน
  // การ strip HTML ดิบทิ้ง (Markdown::safe) — ฝั่ง client จึงไม่ต้องมีตัวแปลง
  // markdown ของตัวเอง และข้อความเดียวกันหน้าตาเหมือนกันทั้งก่อน/หลังรีเฟรช
  $initialMessages = $conversation->messages->map(fn ($m) => [
      'role'  => $m->role === 'assistant' ? 'assistant' : 'user',
      'html'  => \App\Support\Markdown::safe($m->content),
      'text'  => $m->content,
      'time'  => optional($m->created_at)->format('H:i'),
      'state' => 'ok',
  ])->values();
@endphp

@section('title', $isFree ? 'คุยกับแม่หมอจันทรา · ฟรี' : 'คุยกับแม่หมอจันทรา')

@section('content')
<section class="canvas">
  <div class="chat-shell"
       x-data="chatBot({
         messages: @js($initialMessages),
         suggestions: @js($suggestions),
         topics: @js($topics),
         awaiting: @js((bool) $awaiting),
         dailyLimit: @js((int) $dailyLimit),
         dailyLeft: @js((int) $dailyLeft),
         blocked: @js((bool) $exhausted),
         autosend: @js($autosend ?? null),
         sendUrl: @js(route('chat.send')),
         topupUrl: @js(route('wallet.topup')),
         canTopupInChat: @js((bool) ($gate['allowed'] ?? false) && !($readonly ?? false)),
         topupCreateUrl: @js(route('chat.topup.store')),
         topupStatusUrl: @js(route('chat.topup.status', ['tx' => '__ID__'])),
         topupSlipUrl: @js(route('chat.topup.slip', ['tx' => '__ID__'])),
         bundles: @js(array_slice((array) config('pricing.topup_bundles', [50, 100, 200, 500]), 0, 4)),
         nextSteps: @js([
           ['icon' => '🔮', 'label' => 'เปิดไพ่ยิปซี', 'url' => route('tarot.index')],
           ['icon' => '🌟', 'label' => 'ดูดวงเชิงลึก', 'url' => route('deep.index')],
           ['icon' => '🔢', 'label' => 'เลขศาสตร์',   'url' => route('numerology.index')],
           ['icon' => '📿', 'label' => 'ฤกษ์ยาม',     'url' => route('auspicious.index')],
         ]),
       })">

    <x-page-hero art="images/juntra/art/chat.webp" />

    <div style="text-align:center;margin-bottom:26px">
      <div class="eyebrow">CHANTRA AI ORACLE</div>
      <h1 class="display" style="font-size:clamp(34px,4.5vw,60px);margin-bottom:12px">
        คุยกับ <em>แม่หมอจันทรา</em>
        @if ($channel)
          <span class="chip" style="vertical-align:middle;margin-left:12px;font-family:var(--display);font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);pointer-events:none">
            @if ($channel === 'facebook')
              <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11 10.13 11.93v-8.44H7.08v-3.5h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.79V24C19.61 23.07 24 18.1 24 12.07z"/></svg>
              Facebook
            @else
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
              LINE
            @endif
          </span>
        @endif
      </h1>
      <p class="lede" style="margin:0 auto">
        @if ($isFree)
          คุยกับแม่หมอได้<em style="color:var(--gold);font-style:normal">ฟรี</em> — ความรัก การงาน การเงิน ชะตาชีวิต ระบบเดียวกับบอทแม่หมอใน Facebook / LINE
        @else
          ถามได้ทุกเรื่อง — ความรัก การงาน ชะตาดวง คำแนะนำชีวิต ระบบเดียวกับบอทแม่หมอใน Facebook Messenger / LINE OA
        @endif
      </p>
    </div>

    {{-- ป้ายโควตาคุยฟรี / เครดิต — ตัวเลขซิงก์กับเซิร์ฟเวอร์ทุกครั้งที่ส่ง --}}
    @if (!$readonly && $gate['allowed'] && $isFree && $dailyLimit > 0)
      <div class="quota-bar" :class="dailyLeft <= 3 && 'is-low'">
        <span class="quota-label">คุยฟรีวันนี้</span>
        <strong class="quota-value" x-text="dailyLeft + ' / ' + dailyLimit">{{ $dailyLeft }} / {{ $dailyLimit }}</strong>
        <span style="color:var(--ink-dim)">ข้อความ · รีเซ็ตทุกเที่ยงคืน</span>
      </div>
    @elseif (!$readonly && $gate['allowed'] && $balance !== null && $cost > 0)
      @php $low = bccomp(number_format($balance, 2, '.', ''), number_format($cost * 5, 2, '.', ''), 2) < 0; @endphp
      <div class="quota-bar {{ $low ? 'is-low' : '' }}" style="justify-content:space-between">
        <div>
          <span class="quota-label" style="margin-right:10px">เครดิต</span>
          <strong class="quota-value">฿{{ number_format($balance, 2) }}</strong>
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
        <div class="eyebrow" style="margin-bottom:14px">
          @if ($gate['code'] === 'guest') ต้องเข้าสู่ระบบก่อน
          @elseif ($gate['code'] === 'no_link') เชื่อม FACEBOOK หรือ LINE ก่อน
          @else ต่อสายแม่หมอใหม่ @endif
        </div>
        <p style="font-family:var(--thai);font-size:15px;color:var(--ink-dim);max-width:520px;margin:0 auto 20px;line-height:1.7">
          @if ($gate['code'] === 'guest')
            เข้าสู่ระบบครั้งเดียว แม่หมอจะจดจำเรื่องราวของลูกไว้เสมอ — สมาชิกจากบอท Facebook / LINE ของแม่หมอ เข้าใช้ได้ทันทีไม่ต้องสมัครใหม่
          @else
            {{ $gate['reason'] }}
          @endif
        </p>
        <a href="{{ route('thaiprompt.redirect', ['to' => '/chat']) }}" class="btn btn-primary">
          @if ($gate['code'] === 'guest') เข้าสู่ระบบ / เชื่อมบัญชี
          @else เข้าสู่ระบบใหม่ผ่าน Thaiprompt
          @endif
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
      </div>
    @endunless

    <div class="mor-layout">

      {{-- ฝั่งซ้าย: ภาพแม่หมอ (เดสก์ท็อป) --}}
      <aside class="mor-portrait">
        <div class="mor-glow" aria-hidden="true"></div>
        <img src="{{ asset('images/hero-chantra.png') }}" alt="แม่หมอจันทรา" loading="lazy" width="288" height="384">
        <div class="mor-plate">
          <div style="font-family:var(--display);letter-spacing:.22em;text-transform:uppercase;font-size:10px;color:var(--gold)">Oracle of the Moon</div>
          <div style="font-family:var(--thai);font-weight:700;font-size:18px;margin-top:2px">แม่หมอจันทราพยากรณ์</div>
          <div style="display:flex;align-items:center;gap:6px;justify-content:center;margin-top:6px;font-size:12px;color:var(--ink-dim)">
            <span class="mor-online"></span>
            <span x-text="thinking ? 'กำลังเพ่งไพ่ให้ลูกอยู่...' : 'พร้อมรับฟังลูกอยู่ค่ะ'">พร้อมรับฟังลูกอยู่ค่ะ</span>
          </div>
        </div>
      </aside>

      {{-- ฝั่งขวา: ห้องแชท --}}
      <div class="panel chat-panel" style="position:relative">

        <div class="mor-mini">
          <img src="{{ asset('images/hero-chantra.png') }}" alt="แม่หมอจันทรา" width="44" height="44">
          <div style="min-width:0">
            <div style="font-family:var(--thai);font-weight:700;font-size:15px">แม่หมอจันทราพยากรณ์</div>
            <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--ink-dim)">
              <span class="mor-online"></span>
              <span x-text="thinking ? 'กำลังเพ่งไพ่...' : 'ออนไลน์'">ออนไลน์</span>
            </div>
          </div>
        </div>

        <div class="chat-log" x-ref="log" @scroll="onScroll"
             role="log" aria-live="polite" aria-relevant="additions"
             aria-label="บทสนทนากับแม่หมอจันทรา">

          <template x-for="m in messages" :key="m.id">
            <div class="msg" :class="'is-' + (m.state === 'system' ? 'system' : (m.role === 'user' ? 'user' : 'ai'))">
              <div class="msg-head" x-show="m.role === 'assistant' && m.state !== 'system'">แม่หมอจันทรา</div>
              <div class="msg-body" x-show="m.kind !== 'topup'" x-html="m.html"></div>

              {{-- ═══ การ์ดเติมเงินในแชท ═══════════════════════════════
                   จบในบทสนทนาเดียว ไม่พาลูกค้าออกไปหน้าอื่นแล้วหลงทาง
                   (กลุ่มลูกค้าหลักคือผู้สูงอายุ ทุกการเปลี่ยนหน้าคือจุดที่หลุด) --}}
              <template x-if="m.kind === 'topup'">
                <div class="pay-card">

                  {{-- ขั้น 1: เลือกจำนวนเงิน --}}
                  <template x-if="m.pay.step === 'choose'">
                    <div>
                      <div class="pay-title">เติมเครดิตเข้ากระเป๋า</div>
                      <div class="pay-sub">เลือกจำนวนเงินที่ต้องการ แล้วสแกน QR จ่ายได้เลยค่ะ</div>
                      <div class="pay-amounts">
                        <template x-for="b in bundles" :key="b">
                          <button type="button" class="pay-amount" :disabled="m.pay.busy"
                                  @click="chooseAmount(m, b)" x-text="'฿' + b.toLocaleString()"></button>
                        </template>
                      </div>
                      <div class="pay-sub" style="margin-top:10px;color:#f59999" x-show="m.pay.error" x-text="m.pay.error"></div>
                    </div>
                  </template>

                  {{-- ขั้น 2: สแกนจ่าย --}}
                  <template x-if="m.pay.step === 'qr'">
                    <div>
                      <div class="pay-title">สแกนจ่ายได้เลยค่ะ</div>
                      <div class="pay-sub">เปิดแอพธนาคาร → สแกน QR นี้ → โอนตามยอดด้านล่าง</div>

                      <div class="pay-qr">
                        <img :src="m.pay.qr" alt="QR พร้อมเพย์สำหรับเติมเงิน" x-show="m.pay.qr">
                      </div>

                      {{-- ยอดต้องเป๊ะถึงสตางค์ เพราะระบบใช้เศษสตางค์จับคู่กับ
                           SMS ธนาคารเพื่อเครดิตให้อัตโนมัติ --}}
                      <div class="pay-amount-due">
                        <span class="n" x-text="'฿' + Number(m.pay.payable).toFixed(2)"></span>
                        <span class="l" x-show="m.pay.auto_confirm">โอนยอดนี้ให้ตรงเป๊ะนะคะ ระบบจะเติมให้เองทันที</span>
                        <span class="l" x-show="!m.pay.auto_confirm">โอนแล้วแนบสลิปด้านล่างได้เลยค่ะ</span>
                      </div>

                      <div class="pay-sub" style="text-align:center;margin-top:8px" x-show="m.pay.promptpay_name">
                        เข้าบัญชี <strong x-text="m.pay.promptpay_name"></strong>
                      </div>

                      <div class="pay-wait">
                        <span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span x-text="m.pay.waited < 600 ? 'แม่หมอกำลังรอเงินเข้าอยู่ค่ะ...' : 'ยังไม่พบเงินเข้า — แนบสลิปให้แม่หมอได้เลยค่ะ'"></span>
                      </div>

                      <div class="pay-actions">
                        <button type="button" class="chip" @click="copyAmount(m, $event)">
                          <span class="chip-icon">📋</span> คัดลอกยอด
                        </button>
                        {{-- หา input ที่อยู่ในกล่องเดียวกัน แทน x-ref เพราะ x-ref
                             ผูกชื่อแบบคงที่ ใช้กับรายการที่วนซ้ำไม่ได้ --}}
                        <button type="button" class="chip chip-strong" :disabled="m.pay.uploading"
                                @click="$el.parentElement.querySelector('input[type=file]').click()">
                          <span class="chip-icon">🧾</span>
                          <span x-text="m.pay.uploading ? 'กำลังส่ง...' : 'แนบสลิป'"></span>
                        </button>
                        <input type="file" accept="image/*" @change="uploadSlip(m, $event)" style="display:none">
                      </div>
                      <div class="pay-sub" style="margin-top:8px;color:#f59999" x-show="m.pay.error" x-text="m.pay.error"></div>
                      <div class="pay-sub" style="margin-top:8px;color:#9eddae" x-show="m.pay.notice" x-text="m.pay.notice"></div>
                    </div>
                  </template>

                  {{-- ขั้น 3: สำเร็จ + บอกชัด ๆ ว่าไปต่อที่ไหนได้ --}}
                  <template x-if="m.pay.step === 'done'">
                    <div class="pay-done">
                      <div class="tick" aria-hidden="true">✓</div>
                      <div class="pay-title" style="margin-bottom:2px">เติมเครดิตสำเร็จแล้วค่ะ</div>
                      <div class="amt" x-text="'฿' + Number(m.pay.payable).toFixed(2)"></div>
                      <div class="pay-sub" style="margin-top:8px">
                        ยอดคงเหลือ <strong x-text="'฿' + Number(balance).toFixed(2)"></strong> — อยากให้แม่หมอดูเรื่องไหนต่อดีคะ
                      </div>
                      <div class="pay-actions" style="justify-content:center">
                        <template x-for="s in nextSteps" :key="s.url">
                          <a :href="s.url" class="chip chip-strong">
                            <span class="chip-icon" x-text="s.icon"></span><span x-text="s.label"></span>
                          </a>
                        </template>
                        <button type="button" class="chip" @click="scrollToBottom(true); $refs.input?.focus()">
                          <span class="chip-icon">💬</span> คุยกับแม่หมอต่อ
                        </button>
                      </div>
                    </div>
                  </template>
                </div>
              </template>
              <div class="msg-foot">
                <span class="msg-time" x-text="m.time"></span>

                {{-- คัดลอกคำตอบ — ของเดิมต้องลากเลือกเอง --}}
                <button type="button" class="icon-btn" x-show="m.role === 'assistant' && m.state === 'ok'"
                        @click="copy(m, $event)" :aria-label="'คัดลอกคำตอบ'" title="คัดลอก">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>
                  </svg>
                </button>

                {{-- ส่งไม่สำเร็จ → ลองใหม่ด้วย idempotency key เดิม จึงไม่ถูกคิดเงินซ้ำ --}}
                <button type="button" class="chip" style="padding:4px 12px;font-size:12px"
                        x-show="m.state === 'failed'" @click="retry(m)">
                  ลองส่งใหม่
                </button>
              </div>
            </div>
          </template>

          <div class="msg is-ai" x-show="thinking" x-cloak>
            <div class="msg-head">แม่หมอจันทรา</div>
            <div class="msg-body" style="display:flex;align-items:center;gap:8px">
              <span style="color:var(--ink-dim);font-size:13.5px">กำลังเพ่งไพ่</span>
              <span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
            </div>
          </div>
        </div>

        <button type="button" class="chat-jump" x-show="!atBottom" x-cloak
                @click="scrollToBottom(true)" aria-label="เลื่อนไปข้อความล่าสุด">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>
        </button>

        @if ($readonly)
          <div style="text-align:center;padding:20px 8px 4px">
            <p style="color:var(--ink-dim);font-size:14px;margin-bottom:14px">นี่คือประวัติการสนทนา — เปิดอ่านได้อย่างเดียว</p>
            <a href="{{ route('chat.index') }}" class="btn btn-primary">ไปคุยกับแม่หมอต่อ
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
          </div>
        @elseif ($gate['allowed'])

          {{-- ═══ ปุ่มคำถามลัด ═══════════════════════════════════════════
               แถบนี้อยู่ตรงนี้เสมอ ไม่หายไปไหน เปลี่ยนแค่ "เนื้อใน" ตามสถานะ:

               1. คุยต่อไม่ได้ (ครบโควตา/เครดิตหมด) → เปลี่ยนเป็นปุ่มทางออก
                  ที่เป็นลิงก์จริง กดได้เสมอ ไม่ใช่ปุ่มตายที่กดแล้วเงียบ
               2. แม่หมอกำลังถามกลับ → ยุบเหลือปุ่มเดียว เพราะถ้าผู้ใช้กด
                  คำถามทั่วไปตอนนี้ บอทจะตีความเป็นคำตอบของคำถามที่ถามค้างไว้
                  แล้วโฟลว์ทำนายจะพัง — แต่ยังกดกางดูได้ ไม่ได้ล็อกทิ้ง
               3. ปกติ → แถวชิปเลื่อนแนวนอน + ปุ่มกางหมวดคำถามแบบเต็ม
               ระหว่างแม่หมอกำลังพิมพ์ ชิปถูก disable แต่ยัง "อยู่ที่เดิม"
               เพื่อไม่ให้เลย์เอาต์กระโดดและผู้ใช้ไม่งงว่าปุ่มหายไปไหน --}}
          <div class="chat-suggest">

            {{-- 1. ทางออกเมื่อคุยต่อไม่ได้ --}}
            <template x-if="blocked">
              <div>
                <div class="chat-suggest-label">
                  <span x-text="blockedReason || 'วันนี้คุยครบแล้ว — ลองทางนี้ต่อได้เลยค่ะ'"></span>
                </div>
                <div class="chip-row">
                  <a href="{{ route('tarot.index') }}" class="chip chip-strong"><span class="chip-icon">🔮</span> เปิดไพ่ยิปซี</a>
                  <a href="{{ route('deep.index') }}" class="chip chip-strong"><span class="chip-icon">🌟</span> ดูดวงเชิงลึก</a>
                  <a href="{{ route('horoscope.index') }}" class="chip"><span class="chip-icon">🌙</span> ดวงรายวัน</a>
                  <a href="{{ route('numerology.index') }}" class="chip"><span class="chip-icon">🔢</span> เลขศาสตร์</a>
                  <a href="{{ route('auspicious.index') }}" class="chip"><span class="chip-icon">📿</span> ฤกษ์ยาม</a>
                  {{-- เติมเงินจบในแชท ไม่พาออกไปหน้าอื่น (ถ้าเปิดการ์ดไม่ได้
                       ค่อยตกไปเป็นลิงก์หน้าเติมเงินตามเดิม) --}}
                  <button type="button" class="chip chip-strong" x-show="canTopupInChat" @click="openTopup()">
                    <span class="chip-icon">💳</span> เติมเครดิตที่นี่
                  </button>
                  <a href="{{ route('wallet.topup') }}" class="chip" x-show="!canTopupInChat"><span class="chip-icon">💳</span> เติมเงิน</a>
                </div>
              </div>
            </template>

            {{-- 2. แม่หมอกำลังรอคำตอบ → ยุบไว้ก่อน --}}
            <template x-if="!blocked && awaiting && !suggestOpen">
              <div class="chat-suggest-hint">
                <span>💬 แม่หมอกำลังรอคำตอบของลูกอยู่ค่ะ</span>
                <button type="button" class="chip" style="padding:5px 12px;font-size:12px" @click="suggestOpen = true">
                  ดูคำถามแนะนำ
                </button>
              </div>
            </template>

            {{-- 3. ปกติ --}}
            <template x-if="!blocked && (!awaiting || suggestOpen)">
              <div>
                <div class="chat-suggest-label">
                  <span x-text="messages.some(m => m.role === 'user') ? 'ถามต่อได้เลย' : 'เริ่มจากคำถามเหล่านี้ก็ได้ค่ะ'"></span>
                </div>
                <div class="chip-row">
                  <template x-for="(s, i) in suggestions" :key="i">
                    <button type="button" class="chip"
                            :disabled="thinking"
                            :title="thinking ? 'แม่หมอกำลังตอบอยู่ — รอสักครู่นะคะ' : s.prompt"
                            @click="useChip(s.prompt)">
                      <span class="chip-icon" x-text="s.icon" aria-hidden="true"></span>
                      <span x-text="s.label"></span>
                    </button>
                  </template>
                  <button type="button" class="chip" x-show="topics.length" :disabled="thinking"
                          @click="showTopics = !showTopics"
                          :aria-expanded="showTopics.toString()">
                    <span class="chip-icon" aria-hidden="true">⋯</span>
                    <span x-text="showTopics ? 'ปิดหมวดคำถาม' : 'หมวดคำถาม'"></span>
                  </button>
                </div>

                {{-- ตั้งใจไม่ใส่ x-transition: ถ้า transition ไม่จบ (แท็บพื้นหลัง /
                     เบราว์เซอร์ไม่วาดเฟรม) อิลิเมนต์จะค้างที่ opacity:0 = ผู้ใช้กด
                     ปุ่มแล้วเหมือนไม่มีอะไรเกิดขึ้น ซึ่งคือสิ่งที่ต้องเลี่ยงที่สุดในหน้านี้ --}}
                <div class="chat-topics" x-show="showTopics" x-cloak>
                  <template x-for="t in topics" :key="t.key">
                    <div>
                      <div class="chat-topic-name"><span x-text="t.icon"></span> <span x-text="t.label"></span></div>
                      <div class="chip-wrap">
                        <template x-for="(p, i) in t.prompts" :key="i">
                          <button type="button" class="chip" :disabled="thinking"
                                  @click="useChip(p); showTopics = false" x-text="p"></button>
                        </template>
                      </div>
                    </div>
                  </template>
                </div>
              </div>
            </template>
          </div>

          {{-- ═══ ช่องพิมพ์ ═══════════════════════════════════════════ --}}
          <form @submit.prevent="send()" class="field" style="margin-bottom:0">
            @csrf
            <div class="chat-composer">
              <textarea
                x-ref="input"
                x-model="draft"
                @input="autoGrow"
                @keydown.enter="onEnter($event)"
                rows="1"
                maxlength="2000"
                :disabled="blocked"
                :title="blocked ? (blockedReason || 'วันนี้คุยครบโควตาแล้วค่ะ') : ''"
                :placeholder="blocked
                    ? (blockedReason || 'วันนี้คุยครบแล้ว — พรุ่งนี้มาคุยกันใหม่นะคะ')
                    : 'พิมพ์คุยกับแม่หมอได้เลย... (Shift+Enter ขึ้นบรรทัดใหม่)'"
                autocomplete="off"
                aria-label="ข้อความถึงแม่หมอ"></textarea>

              <button type="submit" class="btn btn-primary chat-send"
                      :disabled="thinking || blocked || !draft.trim()"
                      :title="blocked ? (blockedReason || 'วันนี้คุยครบโควตาแล้ว')
                              : (thinking ? 'แม่หมอกำลังตอบอยู่' : (!draft.trim() ? 'พิมพ์ข้อความก่อนส่ง' : 'ส่งข้อความ'))"
                      aria-label="ส่งข้อความ">
                <svg x-show="!thinking" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                <svg x-show="thinking" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="animation:morPulse 1.2s infinite"><circle cx="12" cy="12" r="9"/></svg>
              </button>
            </div>
            <div class="chat-counter" x-show="draft.length > 1700" x-cloak>
              <span x-text="draft.length"></span> / 2000
            </div>
          </form>
        @else
          {{-- gate ไม่ผ่าน: ไม่โชว์ช่องพิมพ์ที่กดไม่ได้ให้เก้อ — CTA อยู่ด้านบนแล้ว --}}
          <div style="text-align:center;padding:18px 8px 4px;color:var(--ink-dim);font-size:14px">
            เข้าสู่ระบบด้านบนเพื่อเริ่มคุยกับแม่หมอ
          </div>
        @endif
      </div>
    </div>

    @if ($gate['allowed'])
      <div style="text-align:center;margin-top:18px;font-family:var(--display);font-size:11px;color:var(--ink-dim);letter-spacing:.22em;text-transform:uppercase">
        powered by Thaiprompt Fortune Bot · ระบบเดียวกับ FB MESSENGER / LINE OA
      </div>
    @endif
  </div>
</section>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('chatBot', (cfg) => ({
    messages: cfg.messages || [],
    suggestions: cfg.suggestions || [],
    topics: cfg.topics || [],
    awaiting: !!cfg.awaiting,
    dailyLimit: cfg.dailyLimit || 0,
    dailyLeft: cfg.dailyLeft || 0,
    blocked: !!cfg.blocked,
    blockedReason: '',
    // 🎁 (2026-07-28) เตือนเรื่องเริ่มหักเงินไปแล้วหรือยัง (ครั้งเดียวต่อการเปิดหน้า)
    costNotified: false,
    draft: '',
    thinking: false,
    showTopics: false,
    suggestOpen: false,
    atBottom: true,
    seq: 0,
    balance: @js((float) ($balance ?? 0)),
    bundles: cfg.bundles || [50, 100, 200, 500],
    nextSteps: cfg.nextSteps || [],
    canTopupInChat: !!cfg.canTopupInChat,
    payTimer: null,

    destroy() {
      clearInterval(this.payTimer);   // ออกจากหน้าแล้วต้องเลิก poll
    },

    init() {
      // ให้ทุกข้อความมี id คงที่ เพื่อให้ x-for :key ไม่วาดใหม่ทั้งกองตอน
      // ลบ/เพิ่มข้อความ (ปุ่มคัดลอกจะไม่กระพริบและ scroll ไม่กระตุก)
      this.messages.forEach((m) => { m.id = ++this.seq; });
      this.scrollToBottom();
      // คำถามที่ติดมาจากหน้าผลไพ่ — ยิงครั้งเดียวให้คำตอบขึ้นเลยโดยไม่ต้องพิมพ์ซ้ำ
      // (เซิร์ฟเวอร์ pull() ออกจาก session แล้ว รีเฟรชจึงไม่ยิงซ้ำ/ไม่คิดเงินซ้ำ)
      if (cfg.autosend && String(cfg.autosend).trim()) {
        this.$nextTick(() => this.send(String(cfg.autosend)));
      }
    },

    /* ---------- การเลื่อน ---------- */
    onScroll() {
      const el = this.$refs.log;
      if (!el) return;
      this.atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    },
    scrollToBottom(force = false) {
      // เคารพผู้ใช้ที่กำลังเลื่อนอ่านย้อน — ไม่กระชากลงล่างสุดใส่หน้า
      if (!force && !this.atBottom) return;
      this.$nextTick(() => {
        const el = this.$refs.log;
        if (el) el.scrollTop = el.scrollHeight;
        this.atBottom = true;
      });
    },

    /* ---------- ช่องพิมพ์ ---------- */
    autoGrow() {
      const el = this.$refs.input;
      if (!el) return;
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 148) + 'px';
    },
    onEnter(e) {
      // Shift+Enter = ขึ้นบรรทัดใหม่ · isComposing = กำลังพิมพ์ผ่าน IME
      // (ภาษาไทยบนบางคีย์บอร์ด) ห้ามส่งกลางคัน ไม่งั้นคำถูกตัดครึ่ง
      if (e.shiftKey || e.isComposing) return;
      e.preventDefault();
      this.send();
    },
    useChip(prompt) {
      if (this.thinking || this.blocked) return;
      this.showTopics = false;
      this.send(prompt);
    },

    /* ---------- ข้อความ ---------- */
    now() {
      return new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
    },
    escape(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },
    push(role, html, state = 'ok', text = '') {
      const m = { id: ++this.seq, role, html, text, time: this.now(), state };
      this.messages.push(m);
      this.scrollToBottom(role === 'user');
      return m;
    },
    say(text) {                 // ข้อความจาก "ระบบ" ไม่ใช่คำพูดของแม่หมอ
      return this.push('assistant', this.escape(text).replace(/\n/g, '<br>'), 'system');
    },

    /* ---------- เติมเงินในแชท ---------- */
    openTopup() {
      // มีการ์ดที่ยังจ่ายไม่เสร็จอยู่แล้วก็เลื่อนไปหาใบเดิม ไม่สร้างซ้อน
      const open = this.messages.find(x => x.kind === 'topup' && x.pay?.step !== 'done');
      if (open) { this.scrollToBottom(true); return; }

      const m = this.push('assistant', '', 'ok');
      m.kind = 'topup';
      m.pay = { step: 'choose', busy: false, uploading: false, error: '', notice: '', waited: 0 };
      this.scrollToBottom(true);
    },

    async chooseAmount(m, amount) {
      if (m.pay.busy) return;
      m.pay.busy = true;
      m.pay.error = '';
      try {
        const r = await fetch(cfg.topupCreateUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: JSON.stringify({ amount }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
          m.pay.error = j.error || 'สร้างรายการเติมเงินไม่สำเร็จ ลองใหม่อีกครั้งนะคะ';
          return;
        }
        Object.assign(m.pay, j, { step: 'qr', waited: 0, error: '', notice: '' });
        this.watchTopup(m);
      } catch (e) {
        m.pay.error = 'เครือข่ายมีปัญหาค่ะ ลองใหม่อีกครั้งนะคะ';
      } finally {
        m.pay.busy = false;
        this.scrollToBottom(true);
      }
    },

    /**
     * ถามเซิร์ฟเวอร์เป็นระยะว่าเงินเข้าหรือยัง
     * หยุดเองที่ 10 นาที — ไม่ปล่อยให้ยิงไม่จบถ้าลูกค้าเปิดค้างไว้
     */
    watchTopup(m) {
      clearInterval(this.payTimer);
      this.payTimer = setInterval(async () => {
        m.pay.waited += 5;
        if (m.pay.waited > 600) { clearInterval(this.payTimer); return; }
        try {
          const r = await fetch(cfg.topupStatusUrl.replace('__ID__', m.pay.id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          });
          if (!r.ok) return;
          const j = await r.json();
          if (j.paid) {
            clearInterval(this.payTimer);
            m.pay.step = 'done';
            this.balance = j.balance;
            // เติมเงินผ่านแล้วต้องพิมพ์ต่อได้ทันที ไม่ต้องรีเฟรชหน้า
            if (this.blockedReason.includes('เครดิต')) {
              this.blocked = false;
              this.blockedReason = '';
            }
            this.scrollToBottom(true);
          }
        } catch (_) { /* เน็ตสะดุดชั่วคราว — รอบหน้าลองใหม่ */ }
      }, 5000);
    },

    async copyAmount(m, e) {
      try {
        await navigator.clipboard.writeText(Number(m.pay.payable).toFixed(2));
        m.pay.notice = 'คัดลอกยอดแล้วค่ะ';
        setTimeout(() => { m.pay.notice = ''; }, 2000);
      } catch (_) {
        m.pay.notice = 'กดค้างที่ตัวเลขเพื่อคัดลอกได้นะคะ';
      }
    },

    async uploadSlip(m, e) {
      const file = e.target.files && e.target.files[0];
      e.target.value = '';           // เลือกไฟล์เดิมซ้ำได้
      if (!file) return;

      m.pay.uploading = true;
      m.pay.error = '';
      m.pay.notice = '';
      try {
        const fd = new FormData();
        fd.append('slip', file);
        const r = await fetch(cfg.topupSlipUrl.replace('__ID__', m.pay.id), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: fd,
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
          m.pay.error = j.error || 'ส่งสลิปไม่สำเร็จ ลองใหม่อีกครั้งนะคะ';
          return;
        }

        // ตรวจสลิปผ่านทันที (SlipOK) → ข้ามการรอ SMS ไปหน้าสำเร็จได้เลย
        if (j.paid) {
          clearInterval(this.payTimer);
          m.pay.step = 'done';
          if (typeof j.balance === 'number') this.balance = j.balance;
          if (this.blockedReason.includes('เครดิต')) {
            this.blocked = false;
            this.blockedReason = '';
          }
          this.scrollToBottom(true);
          return;
        }

        m.pay.notice = j.message || 'ได้รับสลิปแล้วค่ะ';
      } catch (_) {
        m.pay.error = 'เครือข่ายมีปัญหาค่ะ ลองส่งสลิปใหม่อีกครั้งนะคะ';
      } finally {
        m.pay.uploading = false;
      }
    },

    async copy(m, e) {
      try {
        await navigator.clipboard.writeText(m.text || m.html.replace(/<[^>]*>/g, ''));
        const btn = e.currentTarget;
        btn.setAttribute('title', 'คัดลอกแล้ว');
        setTimeout(() => btn.setAttribute('title', 'คัดลอก'), 1500);
      } catch (_) { /* เบราว์เซอร์ไม่อนุญาต — ผู้ใช้ยังลากเลือกเองได้ */ }
    },

    retry(m) {
      if (this.thinking || this.blocked) return;
      // เอาฟองเดิมออกก่อนแล้วส่งใหม่ด้วยคีย์เดิม — ไม่งั้นจะเห็นข้อความตัวเอง
      // ซ้ำสองฟอง ทั้งที่เป็นการส่งครั้งเดียวกัน
      const idx = this.messages.indexOf(m);
      if (idx > -1) this.messages.splice(idx, 1);
      this.send(m.text, m.idem);
    },

    async send(overrideText = null, reuseIdem = null) {
      const text = (overrideText !== null ? overrideText : this.draft).trim();
      if (!text || this.thinking || this.blocked) return;

      // ใช้คีย์เดิมเมื่อ "ลองใหม่" ข้อความเดิม — เซิร์ฟเวอร์จึงกันการตัดเงินซ้ำ
      // ได้จริง (ของเดิมสุ่มคีย์ใหม่ทุกครั้ง ตัวกันซ้ำจึงไม่เคยทำงานเลย)
      const idem = reuseIdem || ((window.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : String(Date.now()) + Math.random());

      const mine = this.push('user', this.escape(text).replace(/\n/g, '<br>'), 'ok', text);
      mine.idem = idem;
      if (overrideText === null) {
        this.draft = '';
        this.$nextTick(() => this.autoGrow());
      }
      this.thinking = true;
      this.scrollToBottom(true);

      try {
        const r = await fetch(cfg.sendUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Idempotency-Key': idem,
          },
          body: JSON.stringify({ message: text }),
        });

        // 419 = CSRF/session หมดอายุ — เดิมขึ้นว่า "ระบบขัดข้อง" ผู้ใช้เลยลองส่งซ้ำเรื่อย ๆ
        if (r.status === 419) {
          mine.state = 'failed';
          this.say('เซสชันหมดอายุค่ะ — กรุณารีเฟรชหน้าแล้วเข้าสู่ระบบใหม่ ข้อความของลูกยังอยู่ ไม่ได้ถูกส่งและไม่ถูกคิดเงิน');
          return;
        }

        let j = {};
        try { j = await r.json(); } catch (_) { /* ไม่ใช่ JSON */ }

        if (r.status === 402) {                      // เครดิตไม่พอ
          this.blocked = true;
          this.blockedReason = 'เครดิตไม่พอ — เติมเครดิตแล้วคุยต่อได้เลยค่ะ';
          this.say(j.error || 'เครดิตไม่พอ กรุณาเติมเงินก่อนนะคะ');
          // กาง QR ให้เลยตรงนี้ ลูกค้าไม่ต้องหาว่าต้องกดตรงไหนต่อ
          if (this.canTopupInChat) this.openTopup();
          return;
        }

        if (r.status === 429) {
          if (j.reason_code === 'daily_limit') {     // ครบโควตาคุยฟรีวันนี้
            this.dailyLeft = 0;
            this.blocked = true;
            this.blockedReason = 'วันนี้คุยครบแล้ว — พรุ่งนี้แม่หมอรอฟังต่อนะคะ';
            this.say(j.error || 'วันนี้คุยครบแล้วค่ะ พรุ่งนี้มาคุยกันใหม่นะคะ');
          } else {                                   // ส่งถี่เกินไป
            mine.state = 'failed';
            this.say('พิมพ์เร็วเกินไปนะคะลูก — รอสักครู่แล้วกด "ลองส่งใหม่" ได้เลย');
          }
          return;
        }

        if (r.status === 409) {                      // ข้อความเดิมกำลังส่งอยู่
          this.say('ข้อความก่อนหน้ากำลังส่งอยู่ค่ะ รอสักครู่นะคะ');
          return;
        }

        if (!r.ok) {
          mine.state = 'failed';
          this.say(j.error || 'ระบบขัดข้องชั่วคราว — กด "ลองส่งใหม่" ได้เลยค่ะ');
          return;
        }

        // สำเร็จ — ใช้ตัวเลขและสถานะจากเซิร์ฟเวอร์ ไม่เดาเอง
        mine.state = 'ok';
        if (typeof j.daily_left === 'number') this.dailyLeft = j.daily_left;
        if (typeof j.daily_limit === 'number') this.dailyLimit = j.daily_limit;
        this.awaiting = !!j.awaiting;
        this.suggestOpen = false;
        if (Array.isArray(j.suggestions) && j.suggestions.length) this.suggestions = j.suggestions;

        this.push('assistant', j.reply_html || this.escape(j.reply || '').replace(/\n/g, '<br>'), 'ok', j.reply || '');

        // 🎁 (2026-07-28) "โควตาฟรีหมด" ไม่ได้แปลว่าคุยต่อไม่ได้เสมอไป
        //    ถ้าตั้งราคาต่อข้อความไว้ ลูกค้าจ่ายเครดิตคุยต่อได้ — ปิดช่องพิมพ์ตรงนี้
        //    = ปิดปากคนที่พร้อมจ่าย เสียทั้งลูกค้าและรายได้
        //    → เชื่อ `blocked` จากเซิร์ฟเวอร์ (เดิม client เดาเองจาก daily_left)
        // เตือนก่อนโดนหักเงินครั้งแรก — แถบโควตาด้านบนเรนเดอร์จากเซิร์ฟเวอร์
        // จึงยังค้างคำว่า "คุยฟรีวันนี้" จนกว่าจะรีเฟรช ถ้าไม่บอกตรงนี้
        // ลูกค้าจะถูกหักเครดิตข้อความถัดไปโดยไม่รู้ตัว = เรื่องร้องเรียน
        if (typeof j.next_cost === 'number' && j.next_cost > 0
            && this.dailyLimit > 0 && !this.costNotified) {
          this.costNotified = true;
          this.say('โควตาคุยฟรีวันนี้หมดแล้วนะคะลูก 🌙 ข้อความถัดไปแม่หมอจะหักจากเครดิตครั้งละ ฿'
            + j.next_cost + ' ค่ะ (พรุ่งนี้ได้โควตาฟรีใหม่)');
        }

        if (j.blocked === true) {
          this.blocked = true;
          this.blockedReason = 'วันนี้คุยครบแล้ว — พรุ่งนี้แม่หมอรอฟังต่อนะคะ';
        } else if (typeof j.blocked === 'undefined' && this.dailyLimit > 0 && this.dailyLeft <= 0) {
          // เซิร์ฟเวอร์รุ่นเก่ายังไม่ส่ง blocked (ช่วง deploy คาบเกี่ยว) → ใช้เกณฑ์เดิม
          this.blocked = true;
          this.blockedReason = 'วันนี้คุยครบแล้ว — พรุ่งนี้แม่หมอรอฟังต่อนะคะ';
        }
      } catch (e) {
        // แยกให้ชัดว่า "ส่งไม่ถึงเซิร์ฟเวอร์" — ลองใหม่ได้โดยไม่เสี่ยงโดนคิดเงินซ้ำ
        // เพราะใช้ Idempotency-Key เดิม
        mine.state = 'failed';
        this.say('เครือข่ายมีปัญหาค่ะ — กด "ลองส่งใหม่" ได้เลย ข้อความจะไม่ถูกส่งซ้ำซ้อน');
      } finally {
        this.thinking = false;
        if (!this.blocked) this.$refs.input?.focus();
      }
    },
  }));
});
</script>
@endpush
@endsection
