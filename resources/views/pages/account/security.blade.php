@extends('layouts.app')
@section('title', 'ความปลอดภัย & อุปกรณ์')

@section('content')
<section class="canvas" style="padding-top:120px">
  <div class="account-shell">
    @include('partials.account-sidebar', ['active' => 'security'])

    <div class="account-content">
      <div class="eyebrow">SECURITY • DEVICES</div>
      <h1 style="font-family:var(--serif);font-size:clamp(32px,4vw,52px);font-weight:400;margin:8px 0 12px">
        ความปลอดภัย <em style="color:var(--gold)">& อุปกรณ์</em>
      </h1>
      <p class="lede" style="margin:0 0 28px;max-width:640px">เห็นทุกอุปกรณ์ที่กำลังเข้าใช้งานบัญชีของคุณ และเพิกถอนได้เมื่อต้องการ</p>

      @if (session('status'))
        <div class="flash" style="padding:14px 18px;margin-bottom:24px">{{ session('status') }}</div>
      @endif

      {{-- Browser session (current) --}}
      <div class="panel" style="padding:24px;margin-bottom:24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">เซสชันเบราว์เซอร์</div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
          <div>
            <div style="font-weight:600;margin-bottom:4px">
              <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--gold);margin-right:8px;vertical-align:middle"></span>
              อุปกรณ์นี้ <span style="font-size:12px;color:var(--gold);font-family:var(--display);letter-spacing:.1em;text-transform:uppercase;margin-left:6px">CURRENT</span>
            </div>
            <div style="font-size:13px;color:var(--ink-dim)">{{ $user->name }} · IP {{ request()->ip() }}</div>
            <div style="font-size:11px;color:var(--ink-faint);font-family:var(--display);letter-spacing:.06em;margin-top:4px">SESSION: {{ \Illuminate\Support\Str::limit($currentSession, 16, '…') }}</div>
          </div>
        </div>

        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--line-soft)">
          <p style="font-size:13px;color:var(--ink-dim);margin-bottom:14px">ออกจากระบบเบราว์เซอร์อื่นทั้งหมด (เก็บเซสชันนี้ไว้)</p>
          <form method="POST" action="{{ route('account.sessions.logout-others') }}"
                onsubmit="return confirm('ออกจากระบบทุกเบราว์เซอร์อื่นหรือไม่?')"
                style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            @csrf
            <input type="password" name="password" required placeholder="ยืนยันรหัสผ่าน"
              style="flex:1;min-width:200px;padding:10px 14px;border:1px solid var(--line-soft);border-radius:8px;background:transparent;color:inherit">
            <button class="btn btn-ghost" style="border-color:var(--rose);color:var(--rose)">ออกจากเบราว์เซอร์อื่น</button>
          </form>
        </div>
      </div>

      {{-- Mobile app tokens (Sanctum) --}}
      <div class="panel" style="padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px">
          <div class="eyebrow" style="display:inline-flex;margin:0">แอปมือถือ Juntra ({{ $tokens->count() }})</div>
          @if ($tokens->count() > 0)
            <form method="POST" action="{{ route('account.tokens.revoke-all') }}"
                  onsubmit="return confirm('ยกเลิกอุปกรณ์มือถือทั้งหมดหรือไม่? แอป Juntra ทุกเครื่องจะต้องล็อกอินใหม่')"
                  style="margin:0">
              @csrf
              <button class="btn btn-ghost" style="border-color:var(--rose);color:var(--rose);font-size:12px">ยกเลิกทั้งหมด</button>
            </form>
          @endif
        </div>

        @if ($tokens->isEmpty())
          <div style="padding:32px 16px;text-align:center;color:var(--ink-dim);background:rgba(0,0,0,.02);border-radius:10px">
            <p style="margin:0 0 6px">ยังไม่มีอุปกรณ์มือถือที่เชื่อมต่อ</p>
            <p style="margin:0;font-size:12px;color:var(--ink-faint)">ดาวน์โหลดแอป Juntra แล้วเข้าสู่ระบบเพื่อให้แอปขึ้นที่นี่</p>
          </div>
        @else
          <div style="display:flex;flex-direction:column;gap:10px">
            @foreach ($tokens as $t)
              <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border:1px solid var(--line-soft);border-radius:10px;flex-wrap:wrap">
                <div style="min-width:0;flex:1">
                  <div style="font-weight:600;font-size:14px;margin-bottom:4px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="14" height="14" style="vertical-align:middle;margin-right:6px"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>
                    {{ $t->name ?: 'อุปกรณ์มือถือ' }}
                  </div>
                  <div style="font-size:12px;color:var(--ink-dim)">
                    สร้างเมื่อ {{ $t->created_at?->diffForHumans() ?? '—' }}
                    @if ($t->last_used_at)
                      · ใช้ล่าสุด {{ $t->last_used_at->diffForHumans() }}
                    @else
                      · ยังไม่ได้ใช้งาน
                    @endif
                  </div>
                </div>
                <form method="POST" action="{{ route('account.tokens.revoke', $t->id) }}"
                      onsubmit="return confirm('ยกเลิกอุปกรณ์ {{ addslashes($t->name ?: 'นี้') }} หรือไม่?')"
                      style="margin:0">
                  @csrf
                  @method('delete')
                  <button class="btn btn-ghost" style="border-color:var(--rose);color:var(--rose);font-size:12px">ยกเลิก</button>
                </form>
              </div>
            @endforeach
          </div>
        @endif
      </div>

      <div class="panel" style="padding:24px;margin-top:24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">การเชื่อมต่อบัญชี</div>
        <div style="display:flex;flex-direction:column;gap:10px">
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border:1px solid var(--line-soft);border-radius:10px">
            <div>
              <div style="font-weight:600;font-size:14px">Thaiprompt</div>
              <div style="font-size:12px;color:var(--ink-dim)">
                {{ $user->isThaipromptLinked() ? 'เชื่อมต่อแล้ว · ใช้ดู MLM ได้' : 'ยังไม่ได้เชื่อมต่อ' }}
              </div>
            </div>
            <span class="badge" style="display:inline-block;padding:3px 10px;border-radius:999px;font-family:var(--display);font-size:10px;letter-spacing:.18em;text-transform:uppercase;border:1px solid {{ $user->isThaipromptLinked() ? 'var(--gold)' : 'var(--line-soft)' }};color:{{ $user->isThaipromptLinked() ? 'var(--gold)' : 'var(--ink-dim)' }}">
              {{ $user->isThaipromptLinked() ? 'LINKED' : 'NOT LINKED' }}
            </span>
          </div>
          @php $ch = $user->chatLinkChannel(); @endphp
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border:1px solid var(--line-soft);border-radius:10px">
            <div>
              <div style="font-weight:600;font-size:14px">Facebook / LINE</div>
              <div style="font-size:12px;color:var(--ink-dim)">
                {{ $ch ? 'เชื่อมต่อแล้ว ผ่าน ' . strtoupper($ch) . ' · ใช้ AI Chat ได้' : 'ยังไม่ได้เชื่อมต่อ' }}
              </div>
            </div>
            <span class="badge" style="display:inline-block;padding:3px 10px;border-radius:999px;font-family:var(--display);font-size:10px;letter-spacing:.18em;text-transform:uppercase;border:1px solid {{ $ch ? 'var(--gold)' : 'var(--line-soft)' }};color:{{ $ch ? 'var(--gold)' : 'var(--ink-dim)' }}">
              {{ $ch ? strtoupper($ch) : 'NOT LINKED' }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
@endsection
