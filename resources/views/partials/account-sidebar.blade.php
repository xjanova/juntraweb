@php
  /**
   * Variables:
   *   $active             — current section key (string)
   *   $sidebarUser        — auth user (shared via view composer)
   *   $sidebarBalance     — float|null
   *   $sidebarPendingTopups — int
   *   $sidebarHasMlm      — bool (only show MLM if linked to Thaiprompt)
   */
  $u = $sidebarUser ?? auth()->user();
  $a = $active ?? '';
  $balance = $sidebarBalance ?? 0;
  $pending = (int) ($sidebarPendingTopups ?? 0);
  $hasMlm  = (bool) ($sidebarHasMlm ?? false);
  $items = [
    ['key'=>'dashboard','label'=>'ภาพรวม',           'icon'=>'M3 13l2-2 4 4 8-8 4 4', 'route'=>'account.dashboard'],
    ['key'=>'astrology','label'=>'ข้อมูลโหราศาสตร์','icon'=>'M12 2l2 7h7l-5.5 4 2 7L12 16l-5.5 4 2-7L3 9h7z','route'=>'account.astrology'],
    ['key'=>'wallet',   'label'=>'วอลเลต',            'icon'=>'M3 7h18v10H3zM3 10h18 M16 13h2','route'=>'wallet.index'],
    ['key'=>'topups',   'label'=>'แจ้งเติมเงิน',       'icon'=>'M12 5v14M5 12h14',              'route'=>'wallet.topups'],
    ['key'=>'history',  'label'=>'ประวัติดูดวง',      'icon'=>'M12 8v4l3 2 M21 12a9 9 0 1 1-9-9c2.5 0 4.8 1 6.5 2.6L21 8 M21 3v5h-5','route'=>'account.history'],
    ['key'=>'chats',    'label'=>'บทสนทนา',           'icon'=>'M21 12c0 5-4 9-9 9-1.5 0-3-.4-4.2-1L3 21l1-3.8A8.9 8.9 0 0 1 3 12a9 9 0 1 1 18 0z','route'=>'account.chats'],
    ['key'=>'security', 'label'=>'ความปลอดภัย',       'icon'=>'M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z','route'=>'account.security'],
    ['key'=>'profile',  'label'=>'โปรไฟล์ & รหัสผ่าน','icon'=>'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 20c0-4 4-6 8-6s8 2 8 6','route'=>'profile.edit'],
  ];
  if ($hasMlm) {
    $items[] = ['key'=>'mlm','label'=>'เครือข่ายของฉัน','icon'=>'M12 4a3 3 0 1 1 0 6 3 3 0 0 1 0-6z M4 20a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4 M6 11a3 3 0 1 1 0 6 3 3 0 0 1 0-6z M18 11a3 3 0 1 1 0 6 3 3 0 0 1 0-6z','route'=>'mlm.dashboard'];
  }
@endphp

<aside class="account-sidebar">
  <div class="sidebar-card">
    <div class="sidebar-avatar">{{ mb_substr($u->name ?? '?', 0, 1) }}</div>
    <div class="sidebar-id">
      <div class="sidebar-name">{{ $u->name }}</div>
      <div class="sidebar-email">{{ $u->email }}</div>
    </div>
  </div>

  <div class="sidebar-balance">
    <div class="eyebrow">เครดิตคงเหลือ</div>
    <div class="sidebar-balance-num">฿{{ number_format((float) $balance, 2) }}</div>
    <a href="{{ route('wallet.topup') }}" class="btn btn-primary" style="width:100%;justify-content:center">เติมเงิน</a>
  </div>

  <nav class="sidebar-nav">
    @foreach ($items as $it)
      <a href="{{ route($it['route']) }}" class="sidebar-link @if($a === $it['key']) is-active @endif">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $it['icon'] }}"/></svg>
        <span>{{ $it['label'] }}</span>
        @if ($it['key'] === 'topups' && $pending > 0)
          <span class="sidebar-badge">{{ $pending }}</span>
        @endif
      </a>
    @endforeach

    <form method="POST" action="{{ route('logout') }}" class="sidebar-logout">
      @csrf
      <button type="submit" class="sidebar-link sidebar-link-danger">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9"/></svg>
        <span>ออกจากระบบ</span>
      </button>
    </form>
  </nav>
</aside>

@once
@push('head')
<style>
  .account-shell {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 32px;
    max-width: 1240px;
    margin: 0 auto;
  }
  .account-content { min-width: 0 }
  .account-sidebar {
    position: sticky;
    top: 120px;
    align-self: start;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  .sidebar-card {
    background: var(--panel-bg, rgba(255,255,255,.02));
    border: 1px solid var(--line-soft);
    border-radius: 14px;
    padding: 18px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .sidebar-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(244,207,106,.25), rgba(176,122,255,.25));
    color: var(--gold);
    font-family: var(--display);
    font-size: 22px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--line-soft);
    flex-shrink: 0;
    text-transform: uppercase;
  }
  .sidebar-id { min-width: 0 }
  .sidebar-name { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
  .sidebar-email { font-size: 11px; color: var(--ink-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
  .sidebar-balance {
    background: linear-gradient(135deg, rgba(244,207,106,.06), rgba(176,122,255,.04));
    border: 1px solid var(--line-soft);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex; flex-direction: column; gap: 10px;
  }
  .sidebar-balance-num {
    font-family: var(--display);
    font-size: 28px;
    color: var(--gold);
    letter-spacing: .02em;
    font-weight: 300;
    line-height: 1;
  }
  .sidebar-nav {
    display: flex; flex-direction: column;
    background: var(--panel-bg, rgba(255,255,255,.02));
    border: 1px solid var(--line-soft);
    border-radius: 14px;
    overflow: hidden;
  }
  .sidebar-link {
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--ink, currentColor);
    text-decoration: none;
    font-size: 14px;
    border: none;
    background: transparent;
    text-align: left;
    cursor: pointer;
    width: 100%;
    border-bottom: 1px solid var(--line-soft);
    transition: background .15s, color .15s;
    font-family: inherit;
  }
  .sidebar-link:last-child { border-bottom: none }
  .sidebar-link svg { width: 18px; height: 18px; flex-shrink: 0; color: var(--ink-dim) }
  .sidebar-link span { flex: 1 }
  .sidebar-link:hover { background: rgba(244,207,106,.05); color: var(--gold) }
  .sidebar-link:hover svg { color: var(--gold) }
  .sidebar-link.is-active { background: rgba(244,207,106,.08); color: var(--gold) }
  .sidebar-link.is-active svg { color: var(--gold) }
  .sidebar-link-danger:hover { background: rgba(220,80,80,.06); color: var(--rose, #c2382e) }
  .sidebar-link-danger:hover svg { color: var(--rose, #c2382e) }
  .sidebar-badge {
    background: var(--gold);
    color: #1a0f06;
    font-family: var(--display);
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    font-weight: 600;
  }
  .sidebar-logout { margin: 0; padding: 0 }

  @media (max-width: 980px) {
    .account-shell { grid-template-columns: 1fr; gap: 20px }
    .account-sidebar { position: static; top: auto }
    .sidebar-nav { flex-direction: row; flex-wrap: wrap; overflow-x: auto }
    .sidebar-link {
      flex: 1 1 auto;
      min-width: 140px;
      border-bottom: 1px solid var(--line-soft);
      border-right: 1px solid var(--line-soft);
    }
  }
</style>
@endpush
@endonce
