@php
    $money = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) == 0.0 ? 0 : 2);
    $totals = $stats['totals'] ?? [];
    $mlm = $stats['mlm'] ?? [];
    $kpi = 'flex:1;min-width:140px;padding:12px 14px;border-radius:12px;border:1px solid rgba(128,128,128,.22)';
    // ผังเกิน 40 คน = เปิดมาเห็นแค่ต้นสายกับสายตรง (กางต่อทีละสายได้) — กางหมดแล้วย่อให้พอดีกรอบจะเล็กจนอ่านไม่ออก
    $countNodes = function (array $n) use (&$countNodes): int {
        return 1 + array_sum(array_map($countNodes, $n['children'] ?? []));
    };
@endphp

<x-filament-panels::page>
    @assets
        <script src="{{ asset('js/org-chart-panzoom.js') }}?v={{ @filemtime(public_path('js/org-chart-panzoom.js')) }}"></script>
    @endassets
    <style>
        /* รายชื่อซ้าย ผังขวา · จอแคบ (มือถือ) รายชื่ออยู่บน ผังได้เต็มความกว้าง — เดิมผังเหลือกว้าง ~90px */
        .maemor-tree-grid { display: grid; grid-template-columns: minmax(220px, 300px) minmax(0, 1fr); gap: 16px; align-items: start; }
        .maemor-tree-list { margin-top: 10px; max-height: 560px; overflow-y: auto; }
        @media (max-width: 767px) {
            .maemor-tree-grid { grid-template-columns: minmax(0, 1fr); }
            .maemor-tree-list { max-height: 240px; } /* รายชื่อยาวดันผังลงไปไกล */
        }

        /* ผังองค์กร — โครง/เส้นเชื่อมแบบเดียวกับหน้า /mlm สีเป็นกลางใช้ได้ทั้งโหมดสว่างและมืด */
        .maemor-oc { --oc-line: rgba(128, 128, 128, .55); position: relative; }
        .maemor-oc__bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .maemor-oc__hint { font-size: 12px; opacity: .65; }
        .maemor-oc__tools { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
        .maemor-oc__tools .fi-icon-btn { margin: 0; } /* ปุ่มไอคอน Filament มี margin ติดลบ วางเรียงกันแล้วทับกัน */
        .maemor-oc__pct { min-width: 54px; padding: 4px 8px; font-size: 12px; border-radius: 8px; border: 1px solid rgba(128, 128, 128, .3); background: transparent; cursor: pointer; }
        .maemor-oc__viewport {
            position: relative; overflow: hidden; height: clamp(360px, 62vh, 680px);
            border-radius: 12px; border: 1px solid rgba(128, 128, 128, .25); background: rgba(128, 128, 128, .06);
            cursor: grab; user-select: none; touch-action: pan-y;
        }
        .maemor-oc__viewport.is-panning { cursor: grabbing; }
        .maemor-oc__canvas { position: absolute; left: 0; top: 0; transform-origin: 0 0; padding: 24px 28px 36px; width: max-content; }

        .maemor-oc__tree, .maemor-oc__tree ul { display: flex; justify-content: center; list-style: none; margin: 0; padding: 22px 0 0; position: relative; }
        .maemor-oc__tree { padding-top: 0; }
        .maemor-oc__tree ul[hidden] { display: none; }
        .maemor-oc__tree li { display: flex; flex-direction: column; align-items: center; position: relative; padding: 22px 8px 0; }
        .maemor-oc__tree li::before, .maemor-oc__tree li::after {
            content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 22px; border-top: 1.5px solid var(--oc-line);
        }
        .maemor-oc__tree li::after { right: auto; left: 50%; border-left: 1.5px solid var(--oc-line); }
        .maemor-oc__tree li:only-child { padding-top: 0; }
        .maemor-oc__tree li:only-child::before, .maemor-oc__tree li:only-child::after { display: none; }
        .maemor-oc__tree li:first-child::before, .maemor-oc__tree li:last-child::after { border-top: none; }
        .maemor-oc__tree li:last-child::before { border-right: 1.5px solid var(--oc-line); border-radius: 0 10px 0 0; }
        .maemor-oc__tree li:first-child::after { border-radius: 10px 0 0 0; }
        .maemor-oc__tree ul::before { content: ''; position: absolute; top: 0; left: 50%; width: 0; height: 22px; border-left: 1.5px solid var(--oc-line); }
        .maemor-oc__tree > li { padding-top: 0; }
        .maemor-oc__tree > li::before, .maemor-oc__tree > li::after { display: none; }

        .maemor-oc__card {
            position: relative; z-index: 1; min-width: 150px; max-width: 210px; padding: 10px 12px; border-radius: 12px; text-align: center;
            border: 1px solid rgba(128, 128, 128, .35); background: #fff; box-shadow: 0 6px 18px -12px rgba(0, 0, 0, .35);
        }
        .dark .maemor-oc__card { background: rgb(var(--gray-900)); }
        .maemor-oc__card.is-root { border-color: rgb(var(--primary-500)); box-shadow: 0 0 0 1px rgb(var(--primary-500)); }
        .maemor-oc__name { font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
        .maemor-oc__meta { font-size: 11px; opacity: .65; }
        .maemor-oc__money { margin-top: 2px; font-size: 12px; font-weight: 600; }
        .maemor-oc__action { margin-top: 4px; }
        .maemor-oc__toggle {
            position: relative; z-index: 1; margin-top: 6px; padding: 2px 10px; font-size: 11px; border-radius: 999px;
            border: 1px solid rgba(128, 128, 128, .35); background: #fff; cursor: pointer;
        }
        .dark .maemor-oc__toggle { background: rgb(var(--gray-900)); }

        /* เต็มจอ (Fullscreen API หรือขยายเต็มหน้าบนเบราว์เซอร์ที่ไม่รองรับ) */
        .maemor-oc.is-fullscreen { display: flex; flex-direction: column; width: 100vw; height: 100vh; padding: 16px; background: rgb(var(--gray-50)); }
        .dark .maemor-oc.is-fullscreen { background: rgb(var(--gray-950)); }
        .maemor-oc.is-fullscreen .maemor-oc__viewport { flex: 1; height: auto; min-height: 0; touch-action: none; }
    </style>

    @if ($error)
        <x-filament::section>
            <span style="color:rgb(220,38,38)">{{ $error }}</span>
        </x-filament::section>
    @endif

    <div class="maemor-tree-grid">
        {{-- ── รายชื่อ ─────────────────────────────── --}}
        <x-filament::section>
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.400ms="q" placeholder="ค้นชื่อหรืออีเมล" />
            </x-filament::input.wrapper>
            <div class="maemor-tree-list">
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
                        {{-- ลาก · ลูกกลิ้งเมาส์ซูมตรงจุดที่ชี้ · สองนิ้ว · เต็มจอ (public/js/org-chart-panzoom.js ตัวเดียวกับหน้า /mlm)
                             wire:key เปลี่ยนตามคน/ความลึก = ผังใหม่เริ่มพอดีจอ · wire:ignore.self กัน Livewire ลบ
                             transform/คลาสเต็มจอที่สคริปต์ใส่ไว้ตอนหน้าอัปเดต (เช่นหลังย้ายสาย) --}}
                        <div class="maemor-oc" wire:key="oc-{{ $userId }}-{{ $depth }}" wire:ignore.self
                            x-data="{
                                zoom: 1,
                                fs: false,
                                pz: null,
                                init() {
                                    this.$nextTick(() => {
                                        if (! window.OrgPanZoom) return;
                                        this.pz = window.OrgPanZoom(this.$refs.vp, this.$refs.canvas, {
                                            fitMin: 0.7,
                                            fullscreenTarget: this.$el,
                                            onChange: (z) => { this.zoom = z },
                                            onFullscreen: (on) => { this.fs = on },
                                        });
                                        this.pz.fit();
                                    });
                                },
                                destroy() { this.pz && this.pz.destroy() },
                            }">
                            <div class="maemor-oc__bar">
                                <span class="maemor-oc__hint">ลากเพื่อเลื่อน · หมุนลูกกลิ้งเมาส์เพื่อซูม · ปุ่มขวาสุดเพื่อดูเต็มจอ</span>
                                <div class="maemor-oc__tools">
                                    <button type="button" class="maemor-oc__pct" x-on:click="$dispatch('oc-fold', false); $nextTick(() => pz && pz.fit())">กางทั้งหมด</button>
                                    <button type="button" class="maemor-oc__pct" x-on:click="$dispatch('oc-fold', true); $nextTick(() => pz && pz.fit())">พับเหลือสายตรง</button>
                                    <x-filament::icon-button icon="heroicon-m-minus" color="gray" size="sm" label="ซูมออก" x-on:click="pz && pz.zoomBy(1 / 1.2)" />
                                    <button type="button" class="maemor-oc__pct" title="พอดีจอ" x-on:click="pz && pz.fit()" x-text="Math.round(zoom * 100) + '%'">100%</button>
                                    <x-filament::icon-button icon="heroicon-m-plus" color="gray" size="sm" label="ซูมเข้า" x-on:click="pz && pz.zoomBy(1.2)" />
                                    <x-filament::icon-button icon="heroicon-m-arrows-pointing-out" color="gray" size="sm" label="ดูเต็มจอ" x-show="! fs" x-on:click="pz && pz.toggleFullscreen()" />
                                    <x-filament::icon-button icon="heroicon-m-arrows-pointing-in" color="gray" size="sm" label="ออกจากเต็มจอ (Esc)" x-show="fs" x-cloak x-on:click="pz && pz.toggleFullscreen()" />
                                </div>
                            </div>
                            <div class="maemor-oc__viewport" x-ref="vp">
                                <div class="maemor-oc__canvas" x-ref="canvas" wire:ignore.self>
                                    <ul class="maemor-oc__tree">
                                        @include('filament.pages.partials.maemor-node', ['node' => $tree['tree'], 'level' => 0, 'fold' => $countNodes($tree['tree']) > 40])
                                    </ul>
                                </div>
                            </div>
                        </div>
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
