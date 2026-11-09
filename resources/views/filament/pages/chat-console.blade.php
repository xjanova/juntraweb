@php
    $rows = $this->rows;
    $open = collect($rows)->firstWhere('id', $openId);
@endphp

<x-filament-panels::page>
    <style>
        /* รายชื่อห้องซ้าย · ห้องของลูกค้าขวา — จอแคบ (มือถือ) เปิดห้องแล้วห้องเต็มจอ มีปุ่มกลับรายชื่อ */
        .cc-grid { display: grid; grid-template-columns: minmax(260px, 340px) minmax(0, 1fr); gap: 16px; align-items: start; }
        .cc-list { max-height: calc(100vh - 230px); overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
        .cc-row {
            display: block; width: 100%; text-align: left; padding: 10px 12px; border-radius: 12px; cursor: pointer;
            border: 1px solid rgba(128, 128, 128, .22); background: transparent;
        }
        .cc-row:hover { background: rgba(128, 128, 128, .08); }
        .cc-row.is-open { border-color: rgb(245 158 11); background: rgba(245, 158, 11, .08); }
        .cc-top { display: flex; align-items: center; gap: 6px; }
        .cc-name { font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cc-at { font-size: 11px; opacity: .6; white-space: nowrap; }
        .cc-last { font-size: 12.5px; opacity: .75; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cc-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
        .cc-tag { font-size: 11px; padding: 1px 8px; border-radius: 999px; border: 1px solid rgba(128, 128, 128, .3); }
        .cc-tag.is-live { color: rgb(22 163 74); border-color: rgba(22, 163, 74, .45); }
        .cc-tag.is-held { color: rgb(13 148 136); border-color: rgba(13, 148, 136, .5); font-weight: 600; }
        .cc-unread { min-width: 20px; padding: 0 6px; border-radius: 999px; background: rgb(220 38 38); color: #fff; font-size: 11px; font-weight: 700; text-align: center; }
        .cc-filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .cc-filters select, .cc-filters input {
            border-radius: 10px; border: 1px solid rgba(128, 128, 128, .35); background: transparent; padding: 6px 10px; font-size: 13px; min-height: 38px;
        }
        .cc-filters input { flex: 1; min-width: 140px; }
        .cc-frame { width: 100%; height: calc(100vh - 190px); min-height: 520px; border: 0; border-radius: 14px; background: #0b0716; }
        .cc-empty { padding: 40px 16px; text-align: center; opacity: .65; border: 1px dashed rgba(128, 128, 128, .35); border-radius: 14px; }
        .cc-bar { display: none; }
        @media (max-width: 1023px) {
            .cc-grid { grid-template-columns: minmax(0, 1fr); }
            .cc-grid.has-open .cc-side { display: none; }
            .cc-grid:not(.has-open) .cc-main { display: none; }
            .cc-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
            .cc-frame { height: calc(100vh - 150px); min-height: 480px; }
            .cc-list { max-height: none; }
        }
    </style>

    <div class="cc-grid {{ $openId ? 'has-open' : '' }}">
        {{-- ═══ รายชื่อห้อง (รีเฟรชทุก 5 วิ) ═══ --}}
        <div class="cc-side" wire:poll.5s>
            <div class="cc-filters">
                <select wire:model.live="room" aria-label="ห้องผู้พยากรณ์">
                    <option value="">ทุกห้อง</option>
                    @foreach ($this->roomOptions as $key => $name)
                        <option value="{{ $key }}">{{ $name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="show" aria-label="แสดง">
                    <option value="all">ทั้งหมด</option>
                    <option value="unread">ยังไม่อ่าน</option>
                    <option value="held">แอดมินคุมอยู่</option>
                    <option value="live">ลูกค้าเปิดห้องอยู่</option>
                </select>
                <input type="search" wire:model.live.debounce.400ms="q" placeholder="ค้นชื่อ / อีเมล / เบอร์ / เลขห้อง" aria-label="ค้นหา">
            </div>
            <div class="cc-filters">
                <label style="font-size:12.5px;display:flex;align-items:center;gap:6px">
                    แอดมินเงียบแล้วคืนบอทเอง
                    <select wire:model.live="idle" aria-label="คืนบอทเองเมื่อแอดมินเงียบ">
                        @foreach (\App\Support\ChatTakeover::IDLE_CHOICES as $m)
                            <option value="{{ $m }}">{{ $m === 0 ? 'ไม่คืนเอง' : ($m >= 60 ? ($m / 60) . ' ชม.' : $m . ' นาที') }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="cc-list">
                @forelse ($rows as $r)
                    <button type="button" wire:key="cc-{{ $r['id'] }}" wire:click="open({{ $r['id'] }})"
                            class="cc-row {{ $openId === $r['id'] ? 'is-open' : '' }}">
                        <div class="cc-top">
                            <span class="cc-name">{{ $r['user'] }}</span>
                            @if ($r['unread'] > 0)
                                <span class="cc-unread" title="ข้อความที่ยังไม่อ่าน">{{ $r['unread'] }}</span>
                            @endif
                            <span class="cc-at">{{ $r['at'] }}</span>
                        </div>
                        <div class="cc-last">{{ $r['lastBy'] === 'user' ? 'ลูกค้า: ' : '' }}{{ $r['last'] }}</div>
                        <div class="cc-tags">
                            <span class="cc-tag">🔮 {{ $r['room'] }}{{ $r['reading'] ? ' · ถามต่อจากไพ่' : '' }}</span>
                            @if ($r['live'])
                                <span class="cc-tag is-live">● เปิดห้องอยู่</span>
                            @endif
                            @if ($r['held'])
                                <span class="cc-tag is-held">🧑‍💼 แอดมินคุม</span>
                            @endif
                        </div>
                    </button>
                @empty
                    <div class="cc-empty">ยังไม่มีห้องที่ตรงเงื่อนไขค่ะ</div>
                @endforelse
            </div>
        </div>

        {{-- ═══ ห้องของลูกค้า — หน้าเดียวกับที่ลูกค้าเห็น + แถบแอดมิน ═══ --}}
        <div class="cc-main">
            @if ($openId)
                <div class="cc-bar">
                    <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left" wire:click="closeRoom">รายชื่อห้อง</x-filament::button>
                    <span style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $open['user'] ?? ('ห้อง #' . $openId) }}</span>
                </div>
                <iframe wire:key="room-frame-{{ $openId }}" class="cc-frame"
                        src="{{ route('admin-chat.room', $openId) }}"
                        title="ห้องของลูกค้า #{{ $openId }}"></iframe>
            @else
                <div class="cc-empty">เลือกห้องทางซ้ายเพื่อดูห้องแบบที่ลูกค้าเห็น แล้วกด “เทคบอท” หรือพิมพ์ตอบได้เลย<br>
                    แอดมินพิมพ์เมื่อไร บอทในห้องนั้นหยุดทันที · กด “คืนให้บอท” ให้บอทตอบต่อ</div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
