@extends('layouts.app')
@section('title', 'เติมเงินเข้าวอลเลต')

@section('content')
<section class="canvas" style="padding-top:160px">
  <div style="max-width:880px;margin:0 auto">
    <x-page-hero art="images/juntra/art/wallet.webp" />

    <div class="eyebrow">เติมเงิน</div>
    <h1 class="display" style="font-size:clamp(40px,5vw,68px);margin-bottom:16px">
      <em style="color:var(--gold)">เติมเครดิต</em> เข้าวอลเลต
    </h1>
    <p class="lede" style="margin:0 0 36px">โอนผ่าน PromptPay แล้วอัปโหลดสลิป — แอดมินจะเครดิตให้ภายใน 1-30 นาที (เวลาทำการ) หรือไวกว่านั้น</p>

    @if ($errors->any())
      <div class="flash" style="padding:14px 18px;margin-bottom:24px;border-color:#c2382e">
        <ul style="margin:0;padding-left:20px">
          @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
        </ul>
      </div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px" class="wallet-topup-grid">
      <div class="panel" style="padding:28px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">วิธีเติม</div>

        @if (!empty($promptpayId))
          <div style="background:linear-gradient(135deg,rgba(244,207,106,.10),rgba(176,122,255,.04));border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:18px">
            <div style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">PromptPay</div>
            <div style="font-size:22px;font-family:var(--display);letter-spacing:.04em;font-weight:500">{{ $promptpayId }}</div>
            @if (!empty($promptpayName))<div style="color:var(--ink-dim);font-size:13px;margin-top:4px">ชื่อบัญชี: {{ $promptpayName }}</div>@endif
            @if (!empty($promptpayQr))
              <div style="margin-top:16px;text-align:center">
                <img src="{{ $promptpayQr }}" alt="PromptPay QR" style="width:180px;height:180px;background:#fff;border-radius:12px;padding:8px">
                <div style="font-size:11px;color:var(--ink-faint);margin-top:6px">สแกนด้วยแอปธนาคาร แล้วกรอกจำนวนเงินที่ต้องการเติม</div>
              </div>
            @endif
            <div style="margin-top:14px;font-size:13px;color:var(--ink-dim);line-height:1.7">
              1. โอนตามจำนวนที่ระบุด้านขวา<br>
              2. แคปเจอร์สลิปแล้วอัปโหลดด้านขวา<br>
              3. รอแอดมินอนุมัติ — ระบบจะเติมเครดิตอัตโนมัติเมื่อยืนยัน
            </div>
          </div>
        @else
          <div style="border:1px dashed var(--line);border-radius:14px;padding:20px;margin-bottom:18px;color:var(--ink-dim);font-size:13px;line-height:1.7">
            แอดมินยังไม่ได้ตั้งค่า PromptPay — ติดต่อผู้ดูแลเพื่อขอวิธีโอนเงิน หรือเลือก "อื่นๆ" ด้านขวาแล้วระบุข้อความที่ช่อง "หมายเหตุ"
          </div>
        @endif

        <div style="font-size:12px;color:var(--ink-faint);line-height:1.7">
          ทุกรายการจะถูกบันทึกในประวัติพร้อมรหัสอ้างอิง คุณสามารถย้อนดูได้ทุกเมื่อจากหน้า <a href="{{ route('wallet.index') }}" style="color:var(--gold)">วอลเลตของฉัน</a>
        </div>
      </div>

      <form method="POST" action="{{ route('wallet.topup.submit') }}" enctype="multipart/form-data" class="panel" style="padding:28px"
            x-data="{amount: '{{ old('amount', '100') }}'}">
        @csrf

        <div class="field">
          <label for="amount">จำนวนเงิน (฿)</label>
          <input type="number" id="amount" name="amount" min="{{ $min }}" max="{{ $max }}" step="1"
                 x-model="amount" required>
          {{-- ชิปจำนวนเงิน — ใช้ .chip + :class แทน :style
               Alpine ผูก :style ด้วย "สตริง" จะเขียนทับ attribute style ทั้งก้อน
               ของเดิมจึงลบสไตล์คงที่ (padding/ขอบ/มุมมน/ฟอนต์) ทิ้งหมดตั้งแต่
               Alpine บูต ปุ่มกลายเป็นข้อความเปล่า ๆ ไม่เหมือนปุ่ม --}}
          <div class="chip-wrap" style="margin-top:10px">
            @foreach ($bundles as $b)
              <button type="button" class="chip" @click="amount = '{{ $b }}'"
                      :class="amount === '{{ $b }}' && 'chip-strong'"
                      :aria-pressed="(amount === '{{ $b }}').toString()">
                ฿{{ number_format($b) }}
              </button>
            @endforeach
          </div>
          <div style="font-size:12px;color:var(--ink-faint);margin-top:6px">ขั้นต่ำ ฿{{ number_format($min) }} · สูงสุดต่อรายการ ฿{{ number_format($max) }}</div>
        </div>

        <div class="field">
          <label for="method">ช่องทาง</label>
          <select id="method" name="method" required>
            <option value="promptpay" {{ old('method') === 'promptpay' ? 'selected' : '' }}>PromptPay (โอนแล้วอัปโหลดสลิป)</option>
            <option value="manual" {{ old('method') === 'manual' ? 'selected' : '' }}>อื่นๆ (ระบุในหมายเหตุ)</option>
          </select>
        </div>

        <div class="field">
          <label for="slip">สลิปการโอน <span style="color:var(--ink-faint);font-weight:400">(ไม่บังคับ ถ้าโอนแล้วใส่จะอนุมัติเร็วขึ้น)</span></label>
          <input type="file" id="slip" name="slip" accept="image/*">
          <div style="font-size:12px;color:var(--ink-faint);margin-top:4px">JPG/PNG/WEBP สูงสุด 4MB</div>
        </div>

        <div class="field">
          <label for="note">หมายเหตุ (ไม่บังคับ)</label>
          <textarea id="note" name="note" rows="2" maxlength="255" placeholder="เช่น โอนจากบัญชี XYZ เวลา 14:23">{{ old('note') }}</textarea>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
          ส่งคำขอเติมเงิน
        </button>
      </form>
    </div>

    <div style="text-align:center;margin-top:24px">
      <a href="{{ route('wallet.index') }}" class="btn btn-ghost" style="padding:12px 28px">← กลับวอลเลต</a>
    </div>
  </div>
</section>

@push('head')
<style>
  @media (max-width: 720px) {
    .wallet-topup-grid { grid-template-columns: 1fr !important }
  }
</style>
@endpush
@endsection
