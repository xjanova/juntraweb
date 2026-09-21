<x-filament-panels::page>
    @php
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%';
        $l1 = $rates['fortune_juntra_l1_percent'] ?? null;
        $l2On = (bool) ($rates['fortune_juntra_l2_enabled'] ?? false);
        $l2 = $rates['fortune_juntra_l2_percent'] ?? null;
    @endphp

    <x-filament::section>
        <div style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center; justify-content:space-between;">
            <p class="text-sm text-gray-600 dark:text-gray-300" style="margin:0; max-width:40rem;">
                อัตรานี้ตั้งและคำนวณที่ผังแม่หมอของ Thaiprompt ที่เดียว — หน้านี้แสดงค่าที่ใช้อยู่จริง
                ถ้าจะเปลี่ยน ให้ไปตั้งที่หน้าคอมแม่หมอของ Thaiprompt แล้วบิลถัดไปจะใช้อัตราใหม่ทันที
            </p>
            <x-filament::button tag="a" :href="$this->thaipromptSettingsUrl()" target="_blank" rel="noopener"
                icon="heroicon-o-arrow-top-right-on-square" color="gray">
                ตั้งที่ Thaiprompt
            </x-filament::button>
        </div>
    </x-filament::section>

    @if ($loadError)
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
            {{ $loadError }}
        </div>
    @else
        <x-filament::section heading="บิลเว็บ/แอพจันทรา — % ของยอดบิล"
            description="บิล 99฿ ที่ 10% = 9.90฿ · บิล 9฿ = 0.90฿ (ไม่มีทางจ่ายเกินราคาบิล)">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(11rem, 1fr)); gap:1rem;">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">สายตรง</div>
                    <div class="text-2xl font-semibold">{{ $l1 === null ? '—' : $pct($l1) }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">ชั้นหลาน</div>
                    <div class="text-2xl font-semibold">{{ $l2On && $l2 !== null ? $pct($l2) : 'ไม่จ่าย' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">ระบบค่าแนะนำ</div>
                    <div class="text-2xl font-semibold">
                        {{ ($rates['fortune_affiliate_enabled'] ?? true) ? 'เปิดอยู่' : 'ปิดอยู่' }}
                    </div>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
