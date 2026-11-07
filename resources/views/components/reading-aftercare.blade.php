{{--
  🌟 (2026-11-07) หลังจบคำทำนาย — ให้ดาว/รีวิว + แชร์ลิงก์
  แสดงเฉพาะเจ้าของคำทำนายที่อ่านเสร็จแล้ว (หน้าผลเป็นคนเช็ก) · ตัวรับ: ReadingEngagementController
--}}
@props(['reading', 'oracle' => 'แม่หมอ'])

@php
  $review   = $reading->review;
  $rating   = (int) old('rating', $review->rating ?? 0);
  $shareUrl = route('tarot.show', $reading);
  $isPublic = (bool) $reading->shared_public;
  $art      = file_exists(public_path('images/juntra/art/review.webp')) ? asset('images/juntra/art/review.webp') : null;
@endphp

<div class="aftercare">
  {{-- ── รีวิว ─────────────────────────────────────────── --}}
  <div id="review" class="followup-box aftercare-card"
       x-data="{ rating: {{ $rating }}, hover: 0, editing: {{ $review && ! $errors->has('rating') && ! $errors->has('message') ? 'false' : 'true' }}, sending: false }">
    @if ($art)
      <img src="{{ $art }}" alt="" class="aftercare-art" loading="lazy" decoding="async" width="1600" height="686">
    @endif
    <div class="eyebrow" style="display:inline-flex">คำทำนายนี้ตรงใจไหมคะ</div>

    @if (session('review_saved'))
      <p class="aftercare-ok" role="status">ขอบคุณที่บอก{{ $oracle }}นะคะ 💜 รีวิวของคุณบันทึกแล้ว</p>
    @endif

    {{-- รีวิวที่ให้ไว้แล้ว --}}
    <template x-if="!editing">
      <div>
        <div class="aftercare-stars is-static" aria-label="ให้ไว้ {{ $review->rating ?? 0 }} ดาว">
          @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= ($review->rating ?? 0) ? 'on' : '' }}">★</span>@endfor
        </div>
        @if ($review && $review->message !== '')
          <p class="followup-hint" style="font-style:italic">“{{ $review->message }}”</p>
        @endif
        <p class="aftercare-note">
          @if ($review && $review->allow_public)
            {{ $review->approved ? 'รีวิวนี้ขึ้นหน้าแรกแล้ว' : 'รอแอดมินตรวจก่อนขึ้นหน้าแรก' }} · แสดงชื่อเป็น “{{ $review->name }}”
          @else
            เก็บไว้ให้ทีมงานอ่านเท่านั้น ไม่ขึ้นหน้าแรก
          @endif
        </p>
        <button type="button" class="btn btn-ghost" @click="editing = true">แก้รีวิว</button>
      </div>
    </template>

    {{-- ฟอร์มรีวิว --}}
    <template x-if="editing">
      <form method="POST" action="{{ route('tarot.review', $reading) }}" @submit="if (!rating) { $event.preventDefault(); return; } sending = true">
        @csrf
        <input type="hidden" name="rating" :value="rating">
        <div class="aftercare-stars" role="radiogroup" aria-label="ให้ดาวคำทำนายนี้" @mouseleave="hover = 0">
          @for ($i = 1; $i <= 5; $i++)
            <button type="button" role="radio" :aria-checked="rating === {{ $i }}" aria-label="{{ $i }} ดาว"
                    :class="(hover || rating) >= {{ $i }} ? 'on' : ''"
                    @mouseenter="hover = {{ $i }}" @click="rating = {{ $i }}">★</button>
          @endfor
        </div>
        <p class="aftercare-note" x-text="['แตะดาวเพื่อให้คะแนน','ยังไม่ตรงเลย','ตรงบางส่วน','พอใช้ได้','ตรงมาก','ตรงใจที่สุด'][hover || rating]"></p>
        @error('rating')<p class="aftercare-err">{{ $message }}</p>@enderror

        <textarea name="message" rows="3" maxlength="500" class="aftercare-text"
                  placeholder="เล่าให้{{ $oracle }}ฟังหน่อย ตรงตรงไหน หรืออยากให้ปรับอะไร (ไม่บังคับ)">{{ old('message', $review->message ?? '') }}</textarea>
        @error('message')<p class="aftercare-err">{{ $message }}</p>@enderror

        <label class="aftercare-check">
          <input type="checkbox" name="allow_public" value="1" @checked(old('allow_public', $review->allow_public ?? false))>
          <span>ยอมให้นำรีวิวนี้ขึ้นหน้าแรก (แสดงชื่อเป็น “{{ \App\Models\Testimonial::displayNameFor(auth()->user()) }}” ไม่แสดงคำถามหรือคำทำนาย)</span>
        </label>

        <button type="submit" class="btn btn-primary" :disabled="!rating || sending">
          <span x-show="!sending">ส่งรีวิว</span><span x-show="sending">กำลังบันทึก ⋯</span>
        </button>
      </form>
    </template>
  </div>

  {{-- ── แชร์ ─────────────────────────────────────────── --}}
  <div id="share" class="followup-box aftercare-card"
       x-data="{ copied: false, copy() { try { navigator.clipboard.writeText(@js($shareUrl)).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000) }) } catch (e) {} } }">
    <div class="eyebrow" style="display:inline-flex">แชร์ไพ่ชุดนี้</div>

    @if ($isPublic)
      <p class="followup-hint">ใครมีลิงก์ก็เปิดดูไพ่และคำทำนายนี้ได้ (ไม่ต้องล็อกอิน)</p>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:14px">
        <a class="btn btn-primary" target="_blank" rel="noopener"
           href="https://social-plugins.line.me/lineit/share?url={{ urlencode($shareUrl) }}">ส่งทาง LINE</a>
        <a class="btn btn-ghost" target="_blank" rel="noopener"
           href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}">แชร์ Facebook</a>
        <button type="button" class="btn btn-ghost" @click="copy()">
          <span x-show="!copied">คัดลอกลิงก์</span><span x-show="copied">คัดลอกแล้ว ✓</span>
        </button>
      </div>
      <form method="POST" action="{{ route('tarot.share', $reading) }}">
        @csrf
        <input type="hidden" name="public" value="0">
        <button type="submit" class="aftercare-link">ปิดลิงก์แชร์ (กลับเป็นส่วนตัว)</button>
      </form>
    @else
      <p class="followup-hint">
        ตอนนี้คำทำนายนี้เป็นของส่วนตัว เห็นได้แค่คุณ — ถ้าเปิดลิงก์แชร์ ใครที่ได้ลิงก์จะเห็น
        คำถาม ไพ่ และคำทำนายทั้งหมด รวมพื้นดวงและอายุจากวันเกิดของคุณ ปิดกลับได้ทุกเมื่อ
      </p>
      <form method="POST" action="{{ route('tarot.share', $reading) }}">
        @csrf
        <input type="hidden" name="public" value="1">
        <button type="submit" class="btn btn-ghost">เปิดลิงก์แชร์</button>
      </form>
    @endif
  </div>
</div>

@once
@push('head')
<style>
  .aftercare { display: grid; gap: 20px; max-width: 640px; margin: 56px auto 0; }
  .aftercare .aftercare-card { margin: 0; }
  .aftercare-art { width: 100%; height: auto; aspect-ratio: 1600/686; object-fit: cover; border-radius: 14px; margin-bottom: 18px; opacity: .92; }
  .aftercare-stars { display: flex; gap: 6px; justify-content: center; margin: 14px 0 4px; }
  /* ธีม juntra-payakorn ล้างสีปุ่มด้วย body.theme-… button:not(.btn) (0,2,2) → ผูกกับ #review/#share ให้ชนะ */
  #review .aftercare-stars button, #review .aftercare-stars span {
    font-size: 34px; line-height: 1; background: none; border: 0; padding: 4px; cursor: pointer;
    color: rgba(244,207,106,.22); transition: color .15s, transform .15s;
  }
  #review .aftercare-stars.is-static span { cursor: default; font-size: 26px; }
  #review .aftercare-stars .on { color: var(--gold); text-shadow: 0 0 14px rgba(244,207,106,.45); }
  #review .aftercare-stars button:hover { transform: scale(1.12); }
  #review .aftercare-stars button:focus-visible { outline: 2px solid var(--gold); border-radius: 8px; }
  .aftercare-note { font-size: 13px; color: var(--ink-dim); margin: 4px 0 14px; min-height: 1.4em; }
  .aftercare-ok { color: var(--gold); font-size: 14px; margin: 8px 0 0; }
  .aftercare-err { color: #ff8c8c; font-size: 13px; margin: 0 0 10px; }
  .aftercare-text {
    width: 100%; padding: 12px 16px; border: 1px solid var(--line); border-radius: 14px;
    background: rgba(7,4,26,.5); color: var(--ink); font-family: var(--thai); font-size: 15px; resize: vertical;
  }
  .aftercare-text:focus { outline: none; border-color: var(--gold); }
  .aftercare-check { display: flex; gap: 10px; align-items: flex-start; text-align: left; font-size: 13px; color: var(--ink-dim); margin: 12px 0 18px; line-height: 1.6; cursor: pointer; }
  .aftercare-check input { margin-top: 4px; accent-color: var(--gold); }
  #share .aftercare-link { background: none; border: 0; color: var(--ink-faint); font-size: 13px; text-decoration: underline; cursor: pointer; font-family: inherit; }
</style>
@endpush
@endonce
