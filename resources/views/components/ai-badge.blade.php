{{-- 🤖 (2026-11-04, owner) ป้าย "AI" ของผู้พยากรณ์ — บอกตรง ๆ ว่าห้องนี้คือหมอดู AI
     "เปิดตัวไปเลยว่าเว็บเราคือหมอดู เอไอ … ติดป้าย AI ที่หน้าห้องทั้ง สามด้วย"
     ใช้ที่การ์ดห้องในหน้าเลือกผู้พยากรณ์ และหัวห้องแชท (ทั้งเดสก์ท็อปและมือถือ)
     ทั้งเว็บเป็นธีมมืดเสมอ (color-scheme: dark) สีป้ายจึงตั้งให้อ่านชัดบนพื้นมืด/บนรูป
     title = คำอธิบายสั้นตอนชี้เมาส์ ต้องไม่เกินที่ระบบทำจริง (ห้องแชทอ่านไพ่จากความหมายในตำรา — ไม่ได้คำนวณดาว จึงไม่อ้างเรื่องดาว)
     size: sm = ข้างชื่อในหัวห้องแชท · md = บนการ์ดห้อง (แยก prop ไม่ต่อ class — px-2 กับ px-2.5 ชนกันแล้วผลขึ้นกับลำดับ CSS) --}}
@props(['size' => 'sm'])
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1 rounded-full bg-gradient-to-r from-violet-600 to-fuchsia-600 align-middle font-bold tracking-wider text-white shadow-md shadow-fuchsia-900/40 ring-1 ring-white/30 dark:from-violet-500 dark:to-fuchsia-500 '
    . ($size === 'md' ? 'px-2.5 py-1 text-[11px] leading-none' : 'px-2 py-0.5 text-[10px] leading-4')]) }}
      title="หมอดู AI — อ่านไพ่จากความหมายในตำราไพ่ 78 ใบ ไม่เดา ไม่มโน">
  <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.1 6.9L21 11l-6.9 2.1L12 20l-2.1-6.9L3 11l6.9-2.1z"/></svg>
  AI
  <span class="sr-only">(ผู้พยากรณ์ปัญญาประดิษฐ์)</span>
</span>
