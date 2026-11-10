@extends('layouts.app')
@section('title', 'เติมเงินเข้าวอลเลต')

{{--
  🌙 (2026-11-11) หน้าเติมเงินใหม่ — เจ้าของ: "ทำหน้านี้ใหม่ให้สวยงาม และเพิ่มกสิกรด้วย"

  ซ้าย = ช่องทางโอน + วิธีโอน · ขวา = ฟอร์ม (มือถือ: ช่องทางก่อน แล้วค่อยฟอร์ม)
  ปุ่มเลือกช่องทางอยู่นอก <form> จึงผูกด้วย attribute form="topup-form" — ปิด JS ก็ยังส่งค่าได้

  กสิกรไทย = บัญชีเดียวกับ QR พร้อมเพย์ (Thaiprompt ส่งเลขบัญชีมาเฉพาะตอนเปิดตัวจับ SMS)
  ไม่มีเลขบัญชี = ซ่อนช่องทางนี้ทั้งก้อน ห้ามเดาเลขให้ลูกค้าโอน
--}}

@php
  $hasPromptpay = !empty($promptpayId);
  $hasKbank     = $hasPromptpay && !empty($transfer);
  $oldChannel   = old('method', 'promptpay');
  $channel      = match (true) {
      $oldChannel === 'kbank' && $hasKbank => 'kbank',
      $oldChannel === 'manual' || ! $hasPromptpay => 'manual',
      default => 'promptpay',
  };
@endphp

@section('content')
<section class="canvas tp-page">
  <div class="tp-wrap"
       x-data="{
         amount: @js((string) old('amount', '100')),
         channel: @js($channel),
         slipName: '',
         slipUrl: null,
         copied: '',
         pickSlip(e) {
           const f = e.target.files && e.target.files[0];
           if (this.slipUrl) URL.revokeObjectURL(this.slipUrl);
           this.slipName = f ? f.name : '';
           this.slipUrl = f ? URL.createObjectURL(f) : null;
         },
         clearSlip() {
           if (this.slipUrl) URL.revokeObjectURL(this.slipUrl);
           this.slipName = ''; this.slipUrl = null;
           this.$refs.slip.value = '';
         },
         async copy(text, key) {
           try { await navigator.clipboard.writeText(text); this.copied = key; }
           catch (_) { this.copied = 'fail'; }
           setTimeout(() => { if (this.copied === key || this.copied === 'fail') this.copied = '' }, 2200);
         },
         money(v) { const n = Number(v); return n > 0 ? '฿' + n.toLocaleString('th-TH') : '—'; },
       }"
       x-on:beforeunload.window="slipUrl && URL.revokeObjectURL(slipUrl)">

    <x-page-hero art="images/juntra/art/wallet.webp" />

    <header class="tp-head">
      <div class="eyebrow">เติมเงิน</div>
      <h1 class="display tp-title"><em>เติมเครดิต</em> เข้าวอลเลต</h1>
      <p class="lede tp-lede">
        @if ($autoMatch)
          โอนผ่านพร้อมเพย์หรือบัญชีกสิกรไทย ระบบตรวจยอดให้อัตโนมัติ เครดิตเข้าเองไม่ต้องรอแอดมิน
        @else
          โอนผ่านพร้อมเพย์แล้วแนบสลิป — แอดมินจะเครดิตให้โดยเร็ว
        @endif
      </p>
    </header>

    {{-- สามขั้น — ผู้สูงอายุอ่านทีละข้อ ไม่ต้องเดาว่าต้องทำอะไรก่อน --}}
    <ol class="tp-steps" aria-label="ขั้นตอนเติมเงิน">
      <li><span class="tp-step-no">1</span><span><b>เลือกช่องทาง</b><small>พร้อมเพย์ หรือ กสิกรไทย</small></span></li>
      <li><span class="tp-step-no">2</span><span><b>ใส่ยอด แล้วกดสร้างรายการ</b><small>ระบบแจ้งยอดที่ต้องโอน</small></span></li>
      <li><span class="tp-step-no">3</span><span><b>โอนตามยอด</b><small>{{ $autoMatch ? 'เครดิตเข้าเองอัตโนมัติ' : 'แนบสลิปให้แอดมินตรวจ' }}</small></span></li>
    </ol>

    @if ($errors->any())
      <div class="flash flash-error tp-errors" role="alert">
        <ul>
          @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
        </ul>
      </div>
    @endif

    <div class="tp-grid">
      {{-- ============ ซ้าย: ช่องทาง + วิธีโอน ============ --}}
      <aside class="panel tp-pay" aria-labelledby="tp-pay-h">
        <h2 class="tp-h" id="tp-pay-h"><span class="tp-h-no">1</span> เลือกช่องทางโอน</h2>

        @if ($hasPromptpay)
          <div class="tp-channels" role="radiogroup" aria-label="ช่องทางโอน">
            <label class="tp-channel" :class="channel === 'promptpay' && 'is-on'">
              <input type="radio" name="method" value="promptpay" form="topup-form" x-model="channel" @checked($channel === 'promptpay')>
              <span class="tp-logo tp-logo-pp" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>
              </span>
              <span class="tp-channel-txt"><b>พร้อมเพย์</b><small>สแกน QR ด้วยแอปธนาคาร</small></span>
              <span class="tp-tick" aria-hidden="true"></span>
            </label>

            @if ($hasKbank)
              <label class="tp-channel" :class="channel === 'kbank' && 'is-on'">
                <input type="radio" name="method" value="kbank" form="topup-form" x-model="channel" @checked($channel === 'kbank')>
                <span class="tp-logo tp-logo-kbank" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5v14M8 12.5 15.5 5M10.6 10.2 16 19"/></svg>
                </span>
                <span class="tp-channel-txt"><b>กสิกรไทย</b><small>โอนเข้าเลขบัญชี</small></span>
                <span class="tp-tick" aria-hidden="true"></span>
              </label>
            @endif
          </div>

          {{-- พร้อมเพย์: QR ไม่มียอด — หลังกดสร้างรายการจะได้ QR ที่ใส่ยอดให้แล้ว --}}
          <div class="tp-account tp-account-pp" x-show="channel === 'promptpay'" @if ($channel !== 'promptpay') x-cloak @endif>
            <div class="tp-account-top">
              <span class="tp-account-label">PromptPay</span>
              <span class="tp-account-name">{{ $promptpayName ?: 'แม่หมอจันทรา' }}</span>
            </div>
            @if (!empty($promptpayQr))
              <div class="tp-qr"><img src="{{ $promptpayQr }}" alt="QR พร้อมเพย์ {{ $promptpayName }}" width="200" height="200"></div>
            @endif
            <div class="tp-number-row">
              <span class="tp-number">{{ $promptpayId }}</span>
              <button type="button" class="chip" @click="copy(@js($promptpayId), 'pp')">
                <span class="chip-icon" aria-hidden="true">📋</span>
                <span x-text="copied === 'pp' ? 'คัดลอกแล้ว' : 'คัดลอก'">คัดลอก</span>
              </button>
            </div>
          </div>

          @if ($hasKbank)
            {{-- กสิกรไทย: การ์ดบัญชี + ปุ่มคัดลอกเลขบัญชี --}}
            <div class="tp-account tp-account-kbank" x-show="channel === 'kbank'" @if ($channel !== 'kbank') x-cloak @endif>
              <div class="tp-bankcard">
                <div class="tp-bankcard-top">
                  <span class="tp-logo tp-logo-kbank tp-logo-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5v14M8 12.5 15.5 5M10.6 10.2 16 19"/></svg>
                  </span>
                  <span class="tp-bankcard-bank">{{ $transfer['bank_name'] ?: 'ธนาคารกสิกรไทย' }}</span>
                </div>
                <div class="tp-bankcard-no">{{ $transfer['display_number'] }}</div>
                <div class="tp-bankcard-name">ชื่อบัญชี {{ $transfer['account_name'] ?: $promptpayName }}</div>
              </div>
              <button type="button" class="btn btn-ghost tp-copy-btn" @click="copy(@js($transfer['account_number']), 'kbank')">
                <span aria-hidden="true">🏦</span>
                <span x-text="copied === 'kbank' ? 'คัดลอกเลขบัญชีแล้ว' : 'คัดลอกเลขบัญชี'">คัดลอกเลขบัญชี</span>
              </button>
            </div>
          @endif

          <p class="tp-copy-fail" x-show="copied === 'fail'" x-cloak>คัดลอกไม่ได้ — กดค้างที่ตัวเลขเพื่อคัดลอกได้นะคะ</p>

          {{-- วิธีโอนให้เครดิตเข้าเอง --}}
          <div class="tp-howto">
            <div class="tp-howto-h">วิธีโอนให้เครดิตเข้าเอง</div>
            <ol>
              <li>ใส่ยอดในฟอร์ม แล้วกด <b>สร้างรายการเติมเงิน</b></li>
              @if ($autoMatch)
                <li>ระบบแจ้ง <b>ยอดโอนที่มีเศษสตางค์</b> (เช่น ฿100.37) เป็นยอดเฉพาะของรายการคุณ</li>
                <li>
                  <span x-show="channel !== 'kbank'">สแกน QR ที่ใส่ยอดให้แล้ว</span><span x-show="channel === 'kbank'" x-cloak>โอนเข้าเลขบัญชีกสิกรไทย</span>
                  พิมพ์ยอด <b>ให้ครบรวมสตางค์</b> — เงินเข้าแล้ว เครดิตเข้าวอลเลตเอง
                </li>
              @else
                <li>โอนตามยอด แล้วแนบสลิปที่หน้ารายการ</li>
                <li>แอดมินตรวจแล้วเครดิตเข้าวอลเลตให้</li>
              @endif
            </ol>
            <div class="tp-howto-tip">
              <span aria-hidden="true">🧾</span>
              <span><b>โอนไปแล้ว?</b> แนบสลิปในฟอร์มได้เลย ระบบตรวจสลิปกับธนาคารให้ทันที (สลิปใบเดียวใช้ได้ครั้งเดียว)</span>
            </div>
          </div>

          <label class="tp-other" :class="channel === 'manual' && 'is-on'">
            <input type="radio" name="method" value="manual" form="topup-form" x-model="channel" @checked($channel === 'manual')>
            <span>ช่องทางอื่น — ระบุในหมายเหตุ แล้วรอแอดมินตรวจ</span>
          </label>
        @else
          <div class="tp-unset">
            แอดมินยังไม่ได้ตั้งค่า PromptPay — ติดต่อผู้ดูแลเพื่อขอวิธีโอนเงิน หรือส่งคำขอแบบ "ช่องทางอื่น" แล้วระบุข้อความที่ช่องหมายเหตุ
          </div>
          <input type="hidden" name="method" value="manual" form="topup-form">
        @endif
      </aside>

      {{-- ============ ขวา: ฟอร์ม ============ --}}
      <form id="topup-form" method="POST" action="{{ route('wallet.topup.submit') }}" enctype="multipart/form-data"
            class="panel tp-form" aria-labelledby="tp-form-h">
        @csrf
        <h2 class="tp-h" id="tp-form-h"><span class="tp-h-no">2</span> ใส่ยอดที่จะเติม</h2>

        <div class="tp-amount">
          <label for="amount" class="tp-label">จำนวนเงิน</label>
          <div class="tp-amount-box">
            <span class="tp-amount-cur" aria-hidden="true">฿</span>
            <input type="number" id="amount" name="amount" inputmode="numeric"
                   min="{{ $min }}" max="{{ $max }}" step="1" x-model="amount" required
                   value="{{ old('amount', '100') }}">
          </div>
          {{-- ใช้ :class ไม่ใช่ :style — Alpine ผูก :style แบบสตริงจะเขียนทับ style เดิมทั้งก้อน --}}
          <div class="tp-bundles" role="group" aria-label="ยอดที่ใช้บ่อย">
            @foreach ($bundles as $b)
              <button type="button" class="tp-bundle" @click="amount = '{{ $b }}'"
                      :class="amount === '{{ $b }}' && 'is-on'"
                      :aria-pressed="(amount === '{{ $b }}').toString()">
                ฿{{ number_format($b) }}
              </button>
            @endforeach
          </div>
          <div class="tp-hint">ขั้นต่ำ ฿{{ number_format($min) }} · สูงสุดต่อรายการ ฿{{ number_format($max) }}</div>
        </div>

        <div class="tp-field">
          <span class="tp-label">สลิปการโอน <em>(ไม่บังคับ — แนบเมื่อโอนไปแล้ว)</em></span>
          <label class="tp-drop" :class="slipName && 'has-file'" for="slip">
            <input type="file" id="slip" name="slip" accept="image/*" x-ref="slip" @change="pickSlip($event)">
            <template x-if="slipUrl"><img :src="slipUrl" alt="ตัวอย่างสลิป" class="tp-drop-thumb"></template>
            <span class="tp-drop-ico" x-show="!slipUrl" aria-hidden="true">🧾</span>
            <span class="tp-drop-txt">
              <b x-text="slipName || 'แตะเพื่อเลือกรูปสลิป'">แตะเพื่อเลือกรูปสลิป</b>
              <small>JPG / PNG / WEBP สูงสุด 4MB · เก็บแบบส่วนตัว เฉพาะคุณกับแอดมินเห็น</small>
            </span>
          </label>
          <button type="button" class="tp-drop-clear" x-show="slipName" x-cloak @click="clearSlip()">เอาสลิปออก</button>
        </div>

        <div class="tp-field">
          <label for="note" class="tp-label">หมายเหตุ <em>(ไม่บังคับ)</em></label>
          <textarea id="note" name="note" rows="2" maxlength="255" class="tp-input" placeholder="เช่น โอนจากบัญชี XYZ เวลา 14:23">{{ old('note') }}</textarea>
        </div>

        {{-- สรุปก่อนกด — ช่องทาง + ยอด อยู่ตรงหน้าปุ่ม --}}
        <div class="tp-summary" aria-live="polite">
          <div><span>ช่องทาง</span><b x-text="channel === 'kbank' ? 'โอนเข้าบัญชีกสิกรไทย' : (channel === 'manual' ? 'ช่องทางอื่น' : 'พร้อมเพย์ (QR)')">{{ $channel === 'kbank' ? 'โอนเข้าบัญชีกสิกรไทย' : ($channel === 'manual' ? 'ช่องทางอื่น' : 'พร้อมเพย์ (QR)') }}</b></div>
          <div><span>ยอดเติม</span><b class="tp-summary-amt" x-text="money(amount)">฿{{ number_format((float) old('amount', 100)) }}</b></div>
          @if ($autoMatch)
            <p x-show="channel !== 'manual' && !slipName">ระบบจะเติมเศษสตางค์ให้ยอดโอนไม่ซ้ำใคร เพื่อจับคู่เงินเข้าอัตโนมัติ</p>
          @endif
        </div>

        <button type="submit" class="btn btn-primary tp-submit">
          <span x-text="slipName ? 'ส่งสลิปให้ระบบตรวจ' : (channel === 'manual' ? 'ส่งคำขอเติมเงิน' : 'สร้างรายการเติมเงิน')">สร้างรายการเติมเงิน</span>
        </button>

        <p class="tp-foot">
          ทุกรายการบันทึกพร้อมรหัสอ้างอิง ย้อนดูได้ที่ <a href="{{ route('wallet.topups') }}">ประวัติการเติมเงิน</a>
        </p>
      </form>
    </div>

    <div class="tp-back">
      <a href="{{ route('wallet.index') }}" class="btn btn-ghost">← กลับวอลเลต</a>
    </div>
  </div>
</section>
@endsection
