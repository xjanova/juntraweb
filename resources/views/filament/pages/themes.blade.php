<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 p-4 text-sm text-amber-900 dark:text-amber-200">
            <strong>การสลับธีมจะเปลี่ยนเฉพาะหน้าตา</strong> — เมนู, ฟีเจอร์, route, และฐานข้อมูลทั้งหมดยังเหมือนเดิม
            ผู้ใช้ที่ login อยู่ไม่ต้อง logout ใหม่ เพียงรีเฟรชหน้าเว็บก็เห็นการเปลี่ยนแปลงทันที
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($this->getThemes() as $slug => $theme)
                @php
                    $isActive = $slug === $this->active;
                @endphp
                <div class="relative rounded-2xl border-2 transition overflow-hidden
                    {{ $isActive
                        ? 'border-amber-500 ring-4 ring-amber-200 dark:ring-amber-900 shadow-xl'
                        : 'border-gray-200 dark:border-gray-700 hover:border-amber-300' }}">

                    @if ($isActive)
                        <div class="absolute top-3 right-3 z-10 px-3 py-1 rounded-full bg-amber-500 text-white text-xs font-semibold tracking-wide uppercase">
                            กำลังใช้งาน
                        </div>
                    @endif

                    {{-- color-strip preview --}}
                    <div class="h-32 grid grid-cols-6 gap-0">
                        @foreach ($theme['palette'] as $color)
                            <div style="background-color: {{ $color }}"></div>
                        @endforeach
                    </div>

                    <div class="p-5 bg-white dark:bg-gray-900">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ $theme['name'] }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                            {{ $theme['description'] }}
                        </p>

                        <div class="mt-3 text-xs text-gray-500 dark:text-gray-500 font-mono">
                            slug: <code class="text-amber-600 dark:text-amber-400">{{ $slug }}</code>
                        </div>

                        <div class="mt-4 flex gap-2">
                            @if ($isActive)
                                <button disabled
                                    class="flex-1 px-4 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-400 text-sm font-medium cursor-not-allowed">
                                    ✓ ใช้งานอยู่
                                </button>
                            @else
                                <button wire:click="switchTheme('{{ $slug }}')" wire:loading.attr="disabled"
                                    class="flex-1 px-4 py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold transition">
                                    เลือกใช้ธีมนี้
                                </button>
                            @endif
                            <a href="{{ url('/') }}" target="_blank"
                                class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                เปิดหน้าบ้าน ↗
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 p-4 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
            <h4 class="font-semibold mb-2">วิธีเพิ่มธีมใหม่</h4>
            <ol class="list-decimal pl-5 space-y-1">
                <li>สร้างไฟล์ <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">resources/css/themes/&lt;slug&gt;.css</code></li>
                <li>สร้างโฟลเดอร์ <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">resources/views/themes/&lt;slug&gt;/</code> พร้อมไฟล์ <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">layout.blade.php</code>, <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">home.blade.php</code>, <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">nav.blade.php</code>, <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">footer.blade.php</code>, <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">chrome-bg.blade.php</code></li>
                <li>เพิ่ม slug ใน <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">config/themes.php</code> และเพิ่ม CSS path ใน <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">vite.config.js</code></li>
                <li>รัน <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded text-xs">npm run build</code> และรีเฟรช Admin — ธีมจะปรากฏที่นี่</li>
            </ol>
        </div>
    </div>
</x-filament-panels::page>
