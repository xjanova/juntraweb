<x-filament-panels::page>
    <x-filament::section>
        <div style="font-size:14px;line-height:1.7">
            <div style="font-weight:600;margin-bottom:6px">ผังสายงานและค่าแนะนำคำนวณที่ผังแม่หมอ (Thaiprompt) ทั้งหมด</div>
            <ul style="list-style:disc;padding-left:20px;margin:0">
                <li>หน้านี้เปิดหน้า <code>/mlm</code> แบบที่ลูกค้าเห็น (ใช้บัญชีแอดมินของคุณเอง)</li>
                <li>จัดการค่าแนะนำ อัตรา ผังสายงาน และสถานะการส่งบิล อยู่ในเมนูกลุ่ม “ผังแม่หมอ”</li>
                <li>บิลคำทำนายเว็บ+แอพส่งไปแม่หมอทุกนาที (หลังจ่ายเงิน 15 นาที) — คืนเงินลูกค้า = ดึงค่าแนะนำคืนอัตโนมัติ</li>
            </ul>
        </div>
    </x-filament::section>

    <div>
        <x-filament::button tag="a" :href="route('mlm.dashboard')" icon="heroicon-o-arrow-top-right-on-square">
            เปิดหน้าสายงานแบบลูกค้า
        </x-filament::button>
    </div>
</x-filament-panels::page>
