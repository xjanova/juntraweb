@extends('install.layout')

@section('content')
<h2>ติดตั้งเสร็จเรียบร้อย ✨</h2>
<p class="lede">ระบบพร้อมใช้งานแล้ว — เว็บจะเริ่มทำงานปกติทันที</p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px">
  <a href="/" class="btn" style="width:100%">ไปหน้าแรก →</a>
  <a href="/admin" class="btn btn-secondary" style="width:100%">เข้าหลังบ้าน Admin →</a>
</div>

<div style="background:rgba(0,0,0,.25);padding:18px;border-radius:10px;font-size:13px;color:#bdb0c8;line-height:1.7">
  <strong style="color:#f4cf6a">เคล็ดลับ:</strong>
  <ul style="margin:8px 0 0;padding-left:18px">
    <li>ไฟล์ <code>storage/app/.installed</code> เป็นเครื่องหมายว่าติดตั้งแล้ว — ลบไฟล์นี้ถ้าต้องการติดตั้งใหม่</li>
    <li>ตั้งค่าทั้งหมดแก้ได้ที่ <code>/admin → Settings</code></li>
    <li>หากต้องการ rebuild assets: <code>npm run build</code></li>
  </ul>
</div>
@endsection
