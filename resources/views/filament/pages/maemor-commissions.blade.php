@php
    // ตัวเลขตรงจากผังแม่หมอ — จำนวนเต็มไม่แสดงทศนิยม มีเศษสตางค์แสดง 2 ตำแหน่ง
    $money = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) == 0.0 ? 0 : 2);
    $c = $overview['commissions'] ?? [];
    $jb = $overview['juntra_bills'] ?? [];
    $statusLabel = ['pending' => 'รอ', 'approved' => 'อนุมัติ', 'paid' => 'จ่ายแล้ว', 'rejected' => 'ยกเลิก'];
    $statusColor = ['pending' => 'warning', 'approved' => 'info', 'paid' => 'success', 'rejected' => 'danger'];
    $cell = 'padding:8px 10px;border-bottom:1px solid rgba(128,128,128,.18);vertical-align:top;font-size:13px';
    $kpi = 'flex:1;min-width:150px;padding:14px 16px;border-radius:12px;border:1px solid rgba(128,128,128,.22)';
@endphp

<x-filament-panels::page>
    <div style="font-size:13px;opacity:.75">
        เฉพาะค่าแนะนำจากบิลเว็บ/แอพจันทรา — ค่าแนะนำจากบิลบอทแม่หมอจัดการที่หลังบ้านแม่หมอ ·
        ข้อมูลและการคำนวณทั้งหมดอยู่ที่ผังแม่หมอ (Thaiprompt) หน้านี้แสดงสดจากที่นั่น ทุกปุ่มสั่งให้แม่หมอทำ
    </div>

    @if ($error)
        <x-filament::section>
            <span style="color:rgb(220,38,38)">{{ $error }}</span>
        </x-filament::section>
    @endif

    {{-- ── ภาพรวม ─────────────────────────────── --}}
    <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="{{ $kpi }}">
            <div style="font-size:12px;opacity:.7">รอดำเนินการ</div>
            <div style="font-size:20px;font-weight:700">฿{{ $money($c['pending_amount'] ?? 0) }}</div>
            <div style="font-size:12px;opacity:.6">{{ $c['pending_count'] ?? 0 }} รายการ</div>
        </div>
        <div style="{{ $kpi }}">
            <div style="font-size:12px;opacity:.7">อนุมัติแล้ว (ยังไม่จ่าย)</div>
            <div style="font-size:20px;font-weight:700">฿{{ $money($c['approved_amount'] ?? 0) }}</div>
            <div style="font-size:12px;opacity:.6">{{ $c['approved_count'] ?? 0 }} รายการ</div>
        </div>
        <div style="{{ $kpi }}">
            <div style="font-size:12px;opacity:.7">จ่ายเข้ากระเป๋าแล้ว</div>
            <div style="font-size:20px;font-weight:700">฿{{ $money($c['paid_amount'] ?? 0) }}</div>
            <div style="font-size:12px;opacity:.6">{{ $c['paid_count'] ?? 0 }} รายการ · ยกเลิก {{ $c['rejected_count'] ?? 0 }}</div>
        </div>
        <div style="{{ $kpi }}">
            <div style="font-size:12px;opacity:.7">บิลจากเว็บ/แอพจันทรา</div>
            <div style="font-size:20px;font-weight:700">฿{{ $money($jb['paid_amount'] ?? 0) }}</div>
            <div style="font-size:12px;opacity:.6">{{ $jb['paid_count'] ?? 0 }} บิล · คืนเงิน {{ $jb['voided_count'] ?? 0 }}</div>
        </div>
    </div>

    {{-- ── ตัวกรอง ─────────────────────────────── --}}
    <x-filament::section>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:12px">สถานะ
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="status">
                        <option value="all">ทั้งหมด</option>
                        <option value="pending">รอ</option>
                        <option value="approved">อนุมัติ</option>
                        <option value="paid">จ่ายแล้ว</option>
                        <option value="rejected">ยกเลิก</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:12px">ชั้น
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="level">
                        <option value="all">ทั้งหมด</option>
                        <option value="1">สายตรง</option>
                        <option value="2">หลาน</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:12px">ตั้งแต่
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="dateFrom" />
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:12px">ถึง
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="dateTo" />
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;flex:1;min-width:180px">ค้นหาชื่อ/อีเมล
                <x-filament::input.wrapper>
                    <x-filament::input type="search" wire:model.live.debounce.500ms="search" placeholder="ผู้รับ หรือ ลูกค้า" />
                </x-filament::input.wrapper>
            </label>
        </div>
    </x-filament::section>

    {{-- ── รายการ ─────────────────────────────── --}}
    <x-filament::section>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
            <span style="font-size:13px;opacity:.75">เลือกแล้ว {{ count($selected) }} รายการ</span>
            <x-filament::button size="sm" color="info" wire:click="approveSelected"
                wire:confirm="อนุมัติรายการที่เลือก? (อนุมัติได้เฉพาะสถานะ รอ)">อนุมัติที่เลือก</x-filament::button>
            <x-filament::button size="sm" color="success" wire:click="paySelected"
                wire:confirm="จ่ายรายการที่เลือกเข้ากระเป๋าผู้รับเลย? เงินจะเข้ากระเป๋าทันที">จ่ายเข้ากระเป๋า</x-filament::button>
        </div>

        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;font-size:12px;opacity:.7">
                        <th style="{{ $cell }}"></th>
                        <th style="{{ $cell }}">วันที่</th>
                        <th style="{{ $cell }}">ผู้รับ</th>
                        <th style="{{ $cell }}">จากลูกค้า / บิล</th>
                        <th style="{{ $cell }}">ชั้น</th>
                        <th style="{{ $cell }};text-align:right">จำนวน</th>
                        <th style="{{ $cell }}">สถานะ</th>
                        <th style="{{ $cell }}"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($open = in_array($row['status'] ?? '', ['pending', 'approved'], true))
                        <tr wire:key="c-{{ $row['id'] }}">
                            <td style="{{ $cell }}">
                                @if ($open)
                                    <input type="checkbox" value="{{ $row['id'] }}" wire:model.live="selected">
                                @endif
                            </td>
                            <td style="{{ $cell }};white-space:nowrap">
                                {{ \Illuminate\Support\Carbon::parse($row['created_at'] ?? now())->timezone('Asia/Bangkok')->format('d/m/y H:i') }}
                            </td>
                            @php($central = \App\Filament\Pages\MaeMorCommissions::centralFallbackReason($row['notes'] ?? null))
                            <td style="{{ $cell }}" title="{{ $row['notes'] ?? '' }}">
                                @if ($central)
                                    <x-filament::badge color="gray">กระเป๋ากลาง</x-filament::badge>
                                    <div style="font-size:11px;opacity:.75;margin-top:2px">{{ $central }}</div>
                                @else
                                    {{ filled($row['user']['name'] ?? null) ? $row['user']['name'] : '—' }}
                                @endif
                                <div style="font-size:11px;opacity:.6">#{{ $row['user']['id'] ?? '' }}</div>
                            </td>
                            <td style="{{ $cell }}">
                                {{ $row['from_user']['name'] ?? ($row['reading']['customer'] ?? '—') }}
                                <div style="font-size:11px;opacity:.6">
                                    {{ $row['reading']['bill_reference'] ?? ('#' . ($row['reading']['id'] ?? '')) }}
                                    · บิล ฿{{ $money($row['reading']['amount'] ?? 0) }}
                                </div>
                            </td>
                            <td style="{{ $cell }}">{{ ($row['level'] ?? 1) == 1 ? 'สายตรง' : 'หลาน' }}</td>
                            <td style="{{ $cell }};text-align:right;font-weight:600;white-space:nowrap">
                                ฿{{ $money($row['amount'] ?? 0) }}
                                <div style="font-size:11px;opacity:.6;font-weight:400">
                                    {{ ($row['commission_type'] ?? '') === 'percent' ? $money($row['commission_rate'] ?? 0) . '%' : 'คงที่' }}
                                </div>
                            </td>
                            <td style="{{ $cell }}">
                                <x-filament::badge :color="$statusColor[$row['status'] ?? ''] ?? 'gray'">
                                    {{ $statusLabel[$row['status'] ?? ''] ?? ($row['status'] ?? '') }}
                                </x-filament::badge>
                            </td>
                            <td style="{{ $cell }};white-space:nowrap">
                                @if ($open)
                                    {{ ($this->adjustAction)(['id' => $row['id'], 'amount' => $row['amount'] ?? null]) }}
                                @endif
                                @if (($row['status'] ?? '') === 'pending')
                                    {{ ($this->rejectAction)(['id' => $row['id']]) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="{{ $cell }};text-align:center;padding:28px;opacity:.6">ไม่มีรายการ</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (($meta['last_page'] ?? 1) > 1)
            <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:12px;font-size:13px">
                <x-filament::button size="xs" color="gray" wire:click="gotoPage({{ $listPage - 1 }})" :disabled="$listPage <= 1">ก่อนหน้า</x-filament::button>
                <span>หน้า {{ $meta['current_page'] ?? $listPage }} / {{ $meta['last_page'] }} · {{ $meta['total'] ?? 0 }} รายการ</span>
                <x-filament::button size="xs" color="gray" wire:click="gotoPage({{ $listPage + 1 }})" :disabled="$listPage >= ($meta['last_page'] ?? 1)">ถัดไป</x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
