{{-- หนึ่งคนในผังแม่หมอ + ลูกทีมของเขา (เรียกตัวเองซ้ำ) — ตัวเลขทั้งหมดมาจาก Thaiprompt --}}
<div wire:key="n-{{ $node['id'] }}" style="{{ ($isRoot ?? false) ? '' : 'margin-left:18px;border-left:1px dashed rgba(128,128,128,.35);padding-left:12px' }}">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:6px 0">
        <span style="font-weight:600;font-size:13px">{{ $node['name'] ?? ('#' . $node['id']) }}</span>
        <span style="font-size:11px;opacity:.6">สมาชิก #{{ $node['id'] }} · ผู้ใช้ #{{ $node['user_id'] ?? '-' }}</span>
        <span style="font-size:11px;opacity:.75">ทีม {{ $node['total_team_members'] ?? 0 }} · ตรง {{ $node['direct_referrals'] ?? 0 }}</span>
        <span style="font-size:11px;opacity:.75">ค่าแนะนำ ฿{{ number_format((float) ($node['fortune_commission'] ?? 0), 2) }}</span>
        @if (($node['status'] ?? 'active') !== 'active')
            <x-filament::badge color="gray">{{ $node['status'] }}</x-filament::badge>
        @endif
        {{-- ย้ายสายได้เฉพาะลูกค้าจันทรา — สมาชิกแม่หมอคนอื่นจัดการที่หลังบ้านแม่หมอ (Thaiprompt ตรวจซ้ำอีกชั้น) --}}
        @if (! ($isRoot ?? false) && ($node['is_juntra'] ?? false))
            {{ ($this->moveAction)(['member' => $node['id'], 'name' => $node['name'] ?? '']) }}
        @endif
    </div>
    @foreach ($node['children'] ?? [] as $child)
        @include('filament.pages.partials.maemor-node', ['node' => $child, 'isRoot' => false])
    @endforeach
</div>
