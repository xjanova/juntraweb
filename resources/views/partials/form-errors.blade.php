{{-- ข้อความผิดพลาดของฟอร์ม — ใช้ในหน้าที่ประกาศ page-handles-flash
     (หน้าพวกนี้ปิดแถบแจ้งเตือนของเลย์เอาต์ไว้ เดิมจึงแสดงแค่ status แล้ว error หายเงียบ:
     ใส่รหัสผิด/SSO ถูกปฏิเสธ = เด้งกลับหน้าเดิมโดยไม่บอกอะไรเลย) --}}
@if ($errors->any())
  <div class="flash flash-error" role="alert" style="{{ $style ?? 'margin-bottom:20px' }}">
    @if (count($errors->all()) === 1)
      {{ $errors->first() }}
    @else
      <ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
  </div>
@endif
