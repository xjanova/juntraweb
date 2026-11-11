<x-filament-panels::page>
    @php($s = $this->status)
    <div class="flex flex-wrap gap-2 text-sm">
        <x-filament::badge :color="$s['ready'] ? 'success' : 'warning'">
            {{ $s['ready'] ? 'กุญแจ VAPID พร้อม' : 'ยังไม่มีกุญแจ VAPID ใน .env — ระบบแจ้งเตือนปิดอยู่' }}
        </x-filament::badge>
        <x-filament::badge color="info">สมาชิกเปิดแจ้งเตือน {{ number_format($s['users']) }} คน · {{ number_format($s['devices']) }} เครื่อง</x-filament::badge>
        <x-filament::badge color="gray">ส่งถึงเครื่อง 24 ชม. ล่าสุด {{ number_format((int) $s['sent_24h']) }} ครั้ง</x-filament::badge>
        @if ($s['quiet'])
            <x-filament::badge color="gray">ตอนนี้อยู่ในเวลาเงียบ</x-filament::badge>
        @endif
    </div>

    @unless ($s['ready'])
        <x-filament::section>
            <x-slot name="heading">ตั้งกุญแจ VAPID (ทำครั้งเดียวบนเซิร์ฟเวอร์)</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                รัน <code>php artisan webpush:vapid --write</code> แล้ว <code>php artisan config:clear</code> —
                กุญแจลับถูกเขียนลง <code>.env</code> เท่านั้น ไม่เข้า git · เปลี่ยนกุญแจภายหลัง = สมาชิกทุกเครื่องต้องกดเปิดแจ้งเตือนใหม่
            </p>
        </x-filament::section>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">บันทึก</x-filament::button>
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">ประวัติที่ส่งล่าสุด</x-slot>
        @forelse ($this->recent as $row)
            <div class="border-b border-gray-100 dark:border-white/5 py-2 text-sm flex items-center justify-between gap-2">
                <span>
                    <span class="font-medium">{{ \App\Services\Push\PushNotifier::TYPES[$row->type][0] ?? $row->type }}</span>
                    <span class="text-xs text-gray-500">· สมาชิก #{{ $row->user_id }} · {{ $row->ref }} · {{ optional($row->created_at)->format('d/m H:i') }}</span>
                </span>
                <x-filament::badge size="sm" :color="$row->delivered > 0 ? 'success' : 'danger'">
                    {{ $row->delivered }}/{{ $row->devices }} เครื่อง
                </x-filament::badge>
            </div>
        @empty
            <p class="text-sm text-gray-500">ยังไม่มีการส่งแจ้งเตือน</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
