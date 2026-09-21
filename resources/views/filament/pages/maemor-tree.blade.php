@php
    $money = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) == 0.0 ? 0 : 2);
    $totals = $stats['totals'] ?? [];
    $mlm = $stats['mlm'] ?? [];
    $kpi = 'flex:1;min-width:140px;padding:12px 14px;border-radius:12px;border:1px solid rgba(128,128,128,.22)';
@endphp

<x-filament-panels::page>
    @if ($error)
        <x-filament::section>
            <span style="color:rgb(220,38,38)">{{ $error }}</span>
        </x-filament::section>
    @endif

    <div style="display:grid;grid-template-columns:minmax(220px,300px) 1fr;gap:16px;align-items:start">
        {{-- ── รายชื่อ ─────────────────────────────── --}}
        <x-filament::section>
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.400ms="q" placeholder="ค้นชื่อหรืออีเมล" />
            </x-filament::input.wrapper>
            <div style="margin-top:10px;max-height:560px;overflow-y:auto">
                @forelse ($users as $u)
                    <button type="button" wire:click="show({{ (int) $u['id'] }})" wire:key="u-{{ $u['id'] }}"
                        style="display:block;width:100%;text-align:left;padding:8px 10px;border-radius:8px;border:0;cursor:pointer;margin-bottom:2px;{{ $userId === (int) $u['id'] ? 'background:rgba(245,158,11,.15)' : 'background:transparent' }}">
                        <div style="font-size:13px;font-weight:600">{{ $u['name'] }}</div>
                        {{-- เฉพาะลูกค้าจันทรา — เลขลูกค้าฝั่งเรา · อีเมลเงา (@thaiprompt.local) ที่ระบบสร้างให้ไม่ต้องโชว์ --}}
                        <div style="font-size:11px;opacity:.6">
                            ลูกค้า #{{ $u['juntra_user_id'] ?? '-' }} · แม่หมอ #{{ $u['id'] }}
                            @unless (str_ends_with((string) ($u['email'] ?? ''), '@thaiprompt.local'))
                                · {{ $u['email'] }}
                            @endunless
                        </div>
                    </button>
                @empty
                    <div style="font-size:13px;opacity:.6;padding:10px">ไม่พบผู้ใช้</div>
                @endforelse
            </div>
        </x-filament::section>

        {{-- ── ยอด + ผัง ─────────────────────────────── --}}
        <div style="display:flex;flex-direction:column;gap:16px">
            @if ($userId && $stats)
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <div style="{{ $kpi }}">
                        <div style="font-size:12px;opacity:.7">{{ $stats['user']['name'] ?? '' }}</div>
                        <div style="font-size:16px;font-weight:700">{{ $mlm['member_code'] ?? 'ยังไม่อยู่ในผัง' }}</div>
                    </div>
                    <div style="{{ $kpi }}">
                        <div style="font-size:12px;opacity:.7">รายได้เดือนนี้</div>
                        <div style="font-size:20px;font-weight:700">฿{{ $money($totals['this_month'] ?? 0) }}</div>
                    </div>
                    <div style="{{ $kpi }}">
                        <div style="font-size:12px;opacity:.7">รายได้สะสม</div>
                        <div style="font-size:20px;font-weight:700">฿{{ $money($totals['all_time'] ?? 0) }}</div>
                        <div style="font-size:11px;opacity:.6">ดึงคืนแล้ว ฿{{ $money($totals['reversed'] ?? 0) }}</div>
                    </div>
                    <div style="{{ $kpi }}">
                        <div style="font-size:12px;opacity:.7">ทีม</div>
                        <div style="font-size:20px;font-weight:700">{{ $mlm['total_team_members'] ?? 0 }}</div>
                        <div style="font-size:11px;opacity:.6">สายตรง {{ $mlm['direct_referrals'] ?? 0 }} คน</div>
                    </div>
                </div>

                <x-filament::section>
                    <x-slot name="heading">ผังสายงาน</x-slot>
                    <x-slot name="headerEnd">
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="depth">
                                @foreach ([1, 2, 3, 5, 7, 10] as $d)
                                    <option value="{{ $d }}">ลึก {{ $d }} ชั้น</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </x-slot>

                    @if (! empty($tree['tree']))
                        @include('filament.pages.partials.maemor-node', ['node' => $tree['tree'], 'isRoot' => true])
                    @else
                        <div style="font-size:13px;opacity:.6">ผู้ใช้นี้ยังไม่อยู่ในผัง</div>
                    @endif
                </x-filament::section>
            @else
                <x-filament::section>
                    <div style="font-size:13px;opacity:.6">เลือกผู้ใช้ทางซ้ายเพื่อดูยอดและผังสายงาน</div>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
