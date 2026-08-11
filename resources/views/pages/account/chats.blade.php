@extends('layouts.app')
@section('title', 'ประวัติการสนทนา')

@section('content')
<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'chats'])

    <div class="account-content">
    <x-page-hero art="images/juntra/art/chat.webp" />

    <div class="eyebrow">บทสนทนากับแม่หมอ</div>
    <h2 style="font-family:var(--serif);font-size:clamp(32px,4vw,52px);font-weight:400;margin:8px 0 12px">
      <em style="color:var(--gold)">บันทึก</em> การสนทนา
    </h2>
    <p class="lede" style="margin:0 0 24px;color:var(--ink-dim);max-width:640px">เปิดอ่านทุกบทคุยที่ผ่านมา ทั้งจากเว็บนี้และผ่าน Facebook / LINE (ที่เชื่อมไว้)</p>

    <div style="display:grid;gap:14px">
      @forelse ($conversations as $c)
        @php
          $first = $c->messages()->where('role', 'user')->first();
          $last  = $c->messages()->latest()->first();
        @endphp
        <a href="{{ route('chat.show', $c) }}" class="panel" style="padding:24px;text-decoration:none;color:inherit;display:block">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <div style="flex:1;min-width:260px">
              <div style="font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;margin-bottom:6px">
                {{ $c->title }} · {{ $c->messages_count ?? 0 }} ข้อความ
              </div>
              <div style="color:var(--moon);margin-bottom:8px;line-height:1.6">
                {{ $first ? \Illuminate\Support\Str::limit($first->content, 140) : '— ยังไม่มีข้อความจากคุณ —' }}
              </div>
              @if ($last && $last->id !== ($first?->id))
                <div style="color:var(--ink-dim);font-size:13px;line-height:1.6">
                  <span style="color:var(--gold);font-weight:600">แม่หมอ:</span>
                  {{ \Illuminate\Support\Str::limit(strip_tags($last->content), 120) }}
                </div>
              @endif
              <div style="color:var(--ink-faint);font-size:12px;margin-top:8px">
                {{ $c->updated_at->format('d/m/Y H:i') }} · {{ $c->updated_at->diffForHumans() }}
              </div>
            </div>
            <div style="flex-shrink:0;align-self:center">
              <span class="btn btn-ghost" style="padding:10px 22px">เปิดอ่าน →</span>
            </div>
          </div>
        </a>
      @empty
        <div class="panel" style="text-align:center;padding:48px 24px">
          <p style="color:var(--ink-dim);margin-bottom:18px">ยังไม่มีบทสนทนา — ทักแม่หมอเพื่อเริ่มต้นได้เลย</p>
          <a href="{{ route('chat.index') }}" class="btn btn-primary">คุยกับแม่หมอ →</a>
        </div>
      @endforelse
    </div>

    <div style="margin-top:32px">{{ $conversations->links() }}</div>
    </div>
  </div>
</section>
@endsection
