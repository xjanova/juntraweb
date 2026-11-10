{{--
  🔔 (2026-11-11) การ์ด "แจ้งเตือนบนเครื่องนี้" — หน้าภาพรวมบัญชี
  เจ้าของสั่ง "ทำให้แจ้งเตือนได้เมื่อติดตั้งแล้ว เช่น แจ้งเตือนยอดค้าง หรือตามมาดูดวงได้"
    - ขอสิทธิ์แจ้งเตือนเฉพาะตอนกดปุ่ม "เปิดแจ้งเตือน" เท่านั้น
    - iPhone/iPad: ต้องเปิดจากไอคอนบนหน้าจอโฮมก่อน (iOS 16.4+) — เปิดใน Safari ธรรมดาจะบอกวิธีติดตั้ง
    - ผู้ใช้ปิดรายหมวดได้ (บันทึกที่ users.push_muted) — หมวดที่แอดมินปิดไว้ไม่แสดง
  ไม่มีกุญแจ VAPID บนเซิร์ฟเวอร์ = การ์ดนี้ไม่แสดงเลย
--}}
@php
  use App\Services\Push\PushNotifier;
  $ready = PushNotifier::ready();
  $types = collect(PushNotifier::TYPES)->filter(fn ($t, $k) => PushNotifier::typeEnabled($k));
  $muted = auth()->check() ? PushNotifier::mutedTypes(auth()->user()) : [];
  $devices = auth()->check() ? \App\Models\PushSubscription::where('user_id', auth()->id())->count() : 0;
  $copy = [
    'bill' => 'บิลเติมเงินที่ยังไม่ได้โอน',
    'reading_ready' => 'คำทำนายอ่านเสร็จแล้ว',
    'daily' => 'ดวงประจำวันช่วงเช้า (วันละครั้ง)',
  ];
@endphp

@if ($ready && $types->isNotEmpty())
<div class="panel push-card" id="notifications"
     x-data="pushSettings({ key: @js((string) config('webpush.public_key')), muted: @js($muted) })">
  <div class="push-head">
    <div>
      <div class="eyebrow" style="display:inline-flex">แจ้งเตือนบนเครื่องนี้</div>
      <p class="push-lead">ให้แม่หมอเตือนคุณเมื่อคำทำนายพร้อม มียอดรอชำระ หรือดวงวันใหม่มาถึง — เงียบช่วง {{ implode('–', PushNotifier::quietHours()) }} น.</p>
    </div>
    <span class="push-pill" :class="state === 'on' ? 'is-on' : ''"
          x-text="state === 'on' ? 'เปิดอยู่' : (state === 'loading' ? '…' : 'ปิดอยู่')"></span>
  </div>

  <template x-if="state === 'needs-install'">
    <div class="push-note">
      บน iPhone / iPad ต้อง<b>เพิ่มจันทราลงหน้าจอโฮม</b>ก่อน แล้วเปิดจากไอคอนนั้น จึงจะเปิดแจ้งเตือนได้ค่ะ
      (แตะปุ่มแชร์ → เพิ่มไปยังหน้าจอโฮม · ต้องเป็น iOS 16.4 ขึ้นไป)
    </div>
  </template>
  <template x-if="state === 'unsupported'">
    <div class="push-note">เบราว์เซอร์นี้ยังไม่รองรับการแจ้งเตือน ลองเปิดด้วย Chrome หรือ Safari ล่าสุดนะคะ</div>
  </template>
  <template x-if="state === 'blocked'">
    <div class="push-note">
      เครื่องนี้ตั้งค่าไม่รับแจ้งเตือนจากจันทราไว้ — เปิดได้ที่การตั้งค่าเว็บไซต์ของเบราว์เซอร์
      <span x-show="ios">(iPhone/iPad: การตั้งค่า → การแจ้งเตือน → จันทรา)</span>
    </div>
  </template>

  <div class="push-actions" x-show="state === 'off' || state === 'on'">
    <button type="button" class="btn btn-primary" x-show="state === 'off'" @click="enable()" :disabled="busy">
      <span x-text="busy ? 'กำลังเปิด…' : 'เปิดแจ้งเตือน'"></span>
    </button>
    <button type="button" class="btn btn-ghost" x-show="state === 'on'" @click="test()" :disabled="busy">ลองส่งแจ้งเตือน</button>
    <button type="button" class="btn btn-ghost" x-show="state === 'on'" @click="disable()" :disabled="busy">ปิดบนเครื่องนี้</button>
  </div>

  <p class="push-msg" x-show="msg" x-text="msg" role="status"></p>
  <p class="push-err" x-show="err" x-text="err" role="alert"></p>

  <div class="push-types" x-show="state === 'on'">
    <div class="push-types-h">เรื่องที่อยากให้เตือน</div>
    @foreach ($types as $key => $t)
      <label class="push-type">
        <input type="checkbox" :checked="!muted.includes(@js($key))" @change="toggleType(@js($key))">
        <span>{{ $copy[$key] ?? $t[0] }}</span>
      </label>
    @endforeach
    @if ($devices > 1)
      <p class="push-devices">เปิดไว้ {{ $devices }} เครื่อง — การเลือกหมวดมีผลกับทุกเครื่อง</p>
    @endif
  </div>
</div>

@once
@push('head')
<style>
  .push-card { padding: 20px 22px; margin-bottom: 24px; }
  .push-head { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; }
  .push-lead { margin: 8px 0 0; font-size: 14px; line-height: 1.7; color: var(--ink-dim, #c8b8de); }
  .push-pill { flex: none; font-size: 12px; padding: 5px 12px; border-radius: 999px; color: var(--ink-dim, #c8b8de);
    border: 1px solid var(--line, rgba(231, 201, 122, .25)); }
  .push-pill.is-on { color: #9be7b8; border-color: rgba(120, 220, 160, .45); background: rgba(80, 200, 130, .08); }
  .push-note { margin-top: 14px; padding: 12px 14px; border-radius: 14px; font-size: 14px; line-height: 1.7;
    background: rgba(244, 207, 106, .06); border: 1px solid rgba(244, 207, 106, .2); }
  .push-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
  .push-actions .btn { min-height: 44px; }
  .push-msg, .push-err { margin: 12px 0 0; font-size: 14px; }
  .push-msg { color: #9be7b8; }
  .push-err { color: #ffb4b4; }
  .push-types { margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255, 255, 255, .07); display: grid; gap: 8px; }
  .push-types-h { font-size: 13px; color: var(--ink-dim, #c8b8de); }
  .push-type { display: flex; align-items: center; gap: 10px; min-height: 40px; font-size: 15px; cursor: pointer; }
  .push-type input { width: 20px; height: 20px; accent-color: var(--gold, #f4cf6a); }
  .push-devices { margin: 4px 0 0; font-size: 12.5px; color: var(--ink-dim, #c8b8de); }
  @media (max-width: 480px) { .push-actions .btn { flex: 1 1 100%; justify-content: center; } }
</style>
@endpush
@endonce
@endif
