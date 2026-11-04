{{-- ตรา DBD Registered — กดแล้วไปหน้ายืนยันของกรมพัฒนาธุรกิจการค้า (config/business.php) --}}
@php($dbd = config('business.dbd'))
<a {{ $attributes->merge(['class' => 'dbd-badge']) }} href="{{ $dbd['verify_url'] }}" target="_blank" rel="noopener"
   title="ตรวจสอบการจดทะเบียนกับกรมพัฒนาธุรกิจการค้า">
  <img src="{{ asset($dbd['badge']) }}" alt="DBD Registered — จดทะเบียนพาณิชย์อิเล็กทรอนิกส์ กรมพัฒนาธุรกิจการค้า"
       width="104" height="43" loading="lazy" decoding="async">
</a>
