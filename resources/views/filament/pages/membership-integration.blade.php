<x-filament-panels::page>
    <div class="rounded-lg border border-amber-300/30 bg-amber-50/40 dark:bg-amber-900/10 p-4 mb-6 text-sm leading-relaxed">
        <div class="font-semibold text-amber-700 dark:text-amber-300 mb-2">สิ่งที่ต้องตั้งค่าฝั่ง Thaiprompt-Affiliate</div>
        <ol class="list-decimal pl-5 space-y-1 text-gray-700 dark:text-gray-300">
            <li>เปิด OAuth2 provider (laravel/passport หรือ oauth2-server)</li>
            <li>ลงทะเบียน OAuth client ตัวใหม่: ตั้งชื่อ "Junthra Fortune-telling"</li>
            <li>Whitelist redirect URI ตามค่าที่แสดงในฟอร์มข้างล่าง</li>
            <li>คัดลอก <code>client_id</code> และ <code>client_secret</code> มาใส่ที่นี่</li>
            <li>กด "ทดสอบเชื่อมต่อ" เพื่อตรวจว่า Thaiprompt ตอบกลับหรือไม่</li>
        </ol>
    </div>

    {{-- The header actions ("บันทึก" / "ทดสอบเชื่อมต่อ") drive the page —
         no <form> wrapper here so we don't have a wire:submit with no submit button. --}}
    {{ $this->form }}
</x-filament-panels::page>
