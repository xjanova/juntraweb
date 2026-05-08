<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>ติดตั้งแม่หมอจันทรา · Setup</title>
<style>
  *,*::before,*::after{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Sarabun",Helvetica,sans-serif;
    background:radial-gradient(1200px 800px at 70% -10%, #1a0d2e 0%, #0a0613 50%, #050309 100%);
    color:#eadcc1; min-height:100vh; padding:40px 20px;
  }
  .wrap{max-width:780px;margin:0 auto}
  .brand{text-align:center;margin-bottom:32px}
  .brand h1{font-size:28px;letter-spacing:.2em;margin:0 0 6px;color:#f4cf6a;text-transform:uppercase;font-weight:600}
  .brand p{margin:0;color:#9d8fb1;font-size:14px;letter-spacing:.05em}
  .steps{display:flex;justify-content:center;gap:8px;margin-bottom:28px;flex-wrap:wrap}
  .step{padding:6px 14px;border-radius:99px;background:rgba(255,255,255,.05);font-size:12px;letter-spacing:.12em;color:#9d8fb1;text-transform:uppercase;border:1px solid rgba(255,255,255,.05)}
  .step.active{background:#f4cf6a;color:#1a0d2e;border-color:#f4cf6a}
  .step.done{background:rgba(244,207,106,.15);color:#f4cf6a;border-color:rgba(244,207,106,.3)}
  .panel{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:36px;backdrop-filter:blur(16px)}
  .panel h2{margin:0 0 8px;font-size:24px;color:#f4cf6a;letter-spacing:.04em}
  .panel .lede{margin:0 0 24px;color:#bdb0c8;font-size:14px;line-height:1.6}
  label{display:block;margin-bottom:6px;font-size:13px;color:#bdb0c8;letter-spacing:.04em;text-transform:uppercase}
  input[type=text],input[type=email],input[type=password],input[type=number],input[type=url],select,textarea{
    width:100%;padding:12px 14px;background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.12);border-radius:10px;color:#eadcc1;font-size:15px;font-family:inherit;outline:none;transition:border-color .2s
  }
  input:focus,select:focus,textarea:focus{border-color:#f4cf6a}
  .field{margin-bottom:18px}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .row-3{display:grid;grid-template-columns:2fr 1fr;gap:14px}
  .checkbox{display:flex;align-items:center;gap:10px;font-size:14px;color:#bdb0c8;cursor:pointer;margin-bottom:14px}
  .checkbox input{accent-color:#f4cf6a;width:18px;height:18px}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 26px;background:#f4cf6a;color:#1a0d2e;border:none;border-radius:10px;font-size:14px;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;font-weight:600;text-decoration:none;font-family:inherit}
  .btn:hover{background:#fde08c}
  .btn-secondary{background:transparent;color:#bdb0c8;border:1px solid rgba(255,255,255,.12)}
  .btn-secondary:hover{background:rgba(255,255,255,.05);color:#eadcc1}
  .actions{display:flex;justify-content:space-between;gap:12px;margin-top:28px}
  .alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px;line-height:1.6}
  .alert-error{background:rgba(244,80,80,.1);border:1px solid rgba(244,80,80,.3);color:#f59999}
  .alert-success{background:rgba(80,200,120,.1);border:1px solid rgba(80,200,120,.3);color:#9eddae}
  .check-list{list-style:none;padding:0;margin:0 0 20px}
  .check-list li{display:flex;justify-content:space-between;padding:10px 14px;background:rgba(0,0,0,.25);border-radius:8px;margin-bottom:6px;font-size:14px}
  .check-list .ok{color:#9eddae}
  .check-list .fail{color:#f59999}
  .hint{font-size:12px;color:#9d8fb1;margin-top:4px}
  .meta{margin-top:24px;text-align:center;color:#665672;font-size:12px;letter-spacing:.04em}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <h1>แม่หมอจันทรา</h1>
    <p>First-time setup · ติดตั้งระบบครั้งแรก</p>
  </div>

  @php
    $steps = [
      'install.welcome'      => '1 · Requirements',
      'install.database'     => '2 · ฐานข้อมูล',
      'install.admin'        => '3 · ผู้ดูแล',
      'install.integrations' => '4 · เชื่อมต่อ',
      'install.finish'       => '5 · เสร็จ',
    ];
    $current = request()->route()->getName();
    $order   = array_keys($steps);
    $idx     = array_search($current, $order, true);
  @endphp
  <div class="steps">
    @foreach ($steps as $name => $label)
      @php
        $i = array_search($name, $order, true);
        $cls = $i < $idx ? 'done' : ($i === $idx ? 'active' : '');
      @endphp
      <div class="step {{ $cls }}">{{ $label }}</div>
    @endforeach
  </div>

  @if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="alert alert-error">
      <ul style="margin:0;padding-left:18px">
        @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
      </ul>
    </div>
  @endif

  <div class="panel">
    @yield('content')
  </div>

  <div class="meta">
    Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }}
  </div>
</div>
</body>
</html>
