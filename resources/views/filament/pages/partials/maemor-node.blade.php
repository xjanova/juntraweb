{{-- หนึ่งคนในผังแม่หมอ + ลูกทีมของเขา (เรียกตัวเองซ้ำ) — ตัวเลขทั้งหมดมาจาก Thaiprompt
     โครงเป็น ul/li แบบผังองค์กร (เส้นเชื่อมวาดด้วย CSS ใน maemor-tree.blade.php)
     พับ/กางสายได้ทีละคน · ผังใหญ่ ($fold) เปิดมาเห็นแค่ต้นสายกับสายตรง แบบเดียวกับหน้า /mlm
     สถานะพับเก็บใน Alpine — Livewire อัปเดตหน้า (เช่นหลังย้ายสาย) แล้วไม่เด้งกลับ --}}
@php
    $kids = $node['children'] ?? [];
    $level ??= 0;
    $open = $level === 0 || ! ($fold ?? false);
@endphp
<li wire:key="n-{{ $node['id'] }}"
    @if ($kids)
        x-data="{ open: @js($open) }"
        x-on:oc-fold.window="open = {{ $level === 0 ? 'true' : '! $event.detail' }}"
    @endif
>
    <div class="maemor-oc__card {{ $level === 0 ? 'is-root' : '' }}">
        <div class="maemor-oc__name">{{ $node['name'] ?? ('#' . $node['id']) }}</div>
        <div class="maemor-oc__meta">สมาชิก #{{ $node['id'] }} · ผู้ใช้ #{{ $node['user_id'] ?? '-' }}</div>
        <div class="maemor-oc__meta">ทีม {{ $node['total_team_members'] ?? 0 }} · ตรง {{ $node['direct_referrals'] ?? 0 }}</div>
        <div class="maemor-oc__money">ค่าแนะนำ ฿{{ number_format((float) ($node['fortune_commission'] ?? 0), 2) }}</div>
        @if (($node['status'] ?? 'active') !== 'active')
            <div style="margin-top:4px"><x-filament::badge color="gray">{{ $node['status'] }}</x-filament::badge></div>
        @endif
        {{-- ย้ายสายได้เฉพาะลูกค้าจันทรา — สมาชิกแม่หมอคนอื่นจัดการที่หลังบ้านแม่หมอ (Thaiprompt ตรวจซ้ำอีกชั้น)
             ต้นผังก็ต้องย้ายได้: ลูกค้าที่สมัครโดยไม่มีผู้เชิญอยู่ใต้ผู้แนะนำเริ่มต้น ซึ่งหลังบ้านนี้เปิดผังไม่ได้
             ซ่อนปุ่มที่ต้นผัง = ลูกค้ากลุ่มนี้ย้ายจากหลังบ้านจันทราไม่ได้เลย
             กดตอนเต็มจออยู่ = ออกจากเต็มจอก่อน ไม่งั้นหน้าต่างยืนยันของ Filament ไปเปิดอยู่หลังผัง มองไม่เห็น --}}
        @if ($node['is_juntra'] ?? false)
            <div class="maemor-oc__action" data-no-pan x-on:click="pz && pz.exitFullscreen()">
                {{ ($this->moveAction)(['member' => $node['id'], 'name' => $node['name'] ?? '']) }}
            </div>
        @endif
    </div>
    @if ($kids)
        <button type="button" class="maemor-oc__toggle" x-on:click="open = ! open"
            x-text="(open ? '▾' : '▸') + ' สาย {{ count($kids) }}'">{{ $open ? '▾' : '▸' }} สาย {{ count($kids) }}</button>
        {{-- hidden ไม่ใช่ x-show: x-show รอเฟรมถัดไปก่อนโชว์ ปุ่มกาง/พับทั้งหมดจะจัดผังให้พอดีจอผิดขนาด --}}
        <ul x-bind:hidden="! open"{{ $open ? '' : ' hidden' }}>
            @foreach ($kids as $child)
                @include('filament.pages.partials.maemor-node', ['node' => $child, 'level' => $level + 1, 'fold' => $fold ?? false])
            @endforeach
        </ul>
    @endif
</li>
