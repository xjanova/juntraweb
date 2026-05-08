<x-filament-panels::page>
    <div class="rounded-lg border border-amber-300/30 bg-amber-50/40 dark:bg-amber-900/10 p-4 mb-4 text-sm leading-relaxed">
        <div class="font-semibold text-amber-700 dark:text-amber-300 mb-2">ระบบ MLM อ่านจาก Thaiprompt-Affiliate (read-only)</div>
        <ul class="list-disc pl-5 space-y-1 text-gray-700 dark:text-gray-300">
            <li>หน้านี้คือ shortcut ไปยังหน้า dashboard เต็มรูปแบบที่ <code>/mlm</code></li>
            <li>ข้อมูล tree + commission ดึงผ่าน API ของ Thaiprompt — ใช้ token จาก SSO</li>
            <li>เฉพาะ <strong>fortune commission</strong> ที่เกี่ยวกับบิลดูดวงเท่านั้น (ไม่รวมร้านค้า/marketplace)</li>
            <li>Cache 5 นาที — ถ้าเพิ่งจ่ายเงินสด ๆ อาจต้องรอให้ refresh</li>
        </ul>
    </div>

    <a href="{{ route('mlm.dashboard') }}"
       class="inline-flex items-center gap-2 px-5 py-3 bg-amber-500 hover:bg-amber-400 text-white font-medium rounded-lg shadow transition">
        เปิด Dashboard เต็มรูปแบบ
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
    </a>
</x-filament-panels::page>
