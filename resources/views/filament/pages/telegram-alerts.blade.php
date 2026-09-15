<x-filament-panels::page>
    @php($s = $this->status)
    <div class="flex flex-wrap gap-2 text-sm">
        <x-filament::badge :color="$s['enabled'] ? 'success' : 'gray'">
            {{ $s['enabled'] ? 'แจ้งเตือนเปิดอยู่' : 'แจ้งเตือนปิดอยู่' }}
        </x-filament::badge>
        <x-filament::badge :color="$s['configured'] ? 'success' : 'warning'">
            {{ $s['configured'] ? 'ตั้งบอท + แชทแล้ว' . ($s['bot'] ? ' (@' . $s['bot'] . ')' : '') : 'ยังตั้งบอท/แชทไม่ครบ' }}
        </x-filament::badge>
        <x-filament::badge :color="$s['card'] ? 'info' : 'gray'">
            {{ $s['card'] ? 'วาดการ์ดกราฟิกได้' : 'เซิร์ฟเวอร์วาดการ์ดไม่ได้ — ส่งเป็นข้อความแทน' }}
        </x-filament::badge>
    </div>

    @if (! empty($chats))
        <x-filament::section>
            <x-slot name="heading">แชทที่พบ — กดเพื่อเลือก</x-slot>
            <div class="flex flex-col gap-2">
                @foreach ($chats as $c)
                    <button type="button" wire:click="useChat('{{ $c['id'] }}')"
                            class="text-left rounded-lg border border-gray-200 dark:border-white/10 px-4 py-3 hover:bg-gray-50 dark:hover:bg-white/5">
                        <div class="font-medium">{{ $c['title'] }}</div>
                        <div class="text-xs text-gray-500">{{ $c['type'] }} · {{ $c['id'] }}</div>
                    </button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">บันทึก</x-filament::button>
        </div>
    </form>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">ตัวอย่างการ์ดที่จะได้รับ</x-slot>
            @if ($this->preview)
                <img src="{{ $this->preview }}" alt="ตัวอย่างการ์ดแจ้งเตือน" class="w-full rounded-xl">
            @else
                <p class="text-sm text-gray-500">เซิร์ฟเวอร์นี้ไม่มี GD/FreeType หรือฟอนต์ — แจ้งเตือนจะส่งเป็นข้อความตัวอักษร (ข้อมูลครบเหมือนเดิม)</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">ประวัติที่ส่งล่าสุด</x-slot>
            @forelse ($this->recent as $row)
                <div class="border-b border-gray-100 dark:border-white/5 py-2 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium">{{ \Illuminate\Support\Str::limit($row->title, 70) }}</span>
                        <x-filament::badge size="sm" :color="$row->ok ? 'success' : 'danger'">{{ $row->ok ? 'ส่งแล้ว' : 'ไม่สำเร็จ' }}</x-filament::badge>
                    </div>
                    <div class="text-xs text-gray-500">
                        {{ \App\Support\AdminAlerts::CATEGORIES[$row->category][0] ?? $row->category }}
                        · {{ \Illuminate\Support\Carbon::parse($row->created_at)->format('d/m H:i') }}
                        @if (! $row->ok && $row->error) · <span class="text-danger-600">{{ $row->error }}</span>@endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">ยังไม่มีการแจ้งเตือน</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
