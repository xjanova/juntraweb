@extends('install.layout')

@section('content')
<h2>ตั้งค่าฐานข้อมูล</h2>
<p class="lede">เลือกชนิดฐานข้อมูลและกรอกข้อมูลการเชื่อมต่อ ระบบจะสร้างฐานข้อมูลให้อัตโนมัติหากยังไม่มี</p>

<form method="POST" action="{{ route('install.database.save') }}">
  @csrf

  <div class="field">
    <label for="connection">ชนิดฐานข้อมูล</label>
    <select name="connection" id="connection" onchange="toggleSqlite(this.value)">
      <option value="mysql"   {{ ($data['connection'] ?? '') === 'mysql' ? 'selected' : '' }}>MySQL (แนะนำ)</option>
      <option value="mariadb" {{ ($data['connection'] ?? '') === 'mariadb' ? 'selected' : '' }}>MariaDB</option>
      <option value="sqlite"  {{ ($data['connection'] ?? '') === 'sqlite' ? 'selected' : '' }}>SQLite (ทดสอบ)</option>
    </select>
  </div>

  <div id="sql-fields">
    <div class="row-3">
      <div class="field">
        <label for="host">Host</label>
        <input type="text" name="host" id="host" value="{{ $data['host'] ?? '127.0.0.1' }}">
      </div>
      <div class="field">
        <label for="port">Port</label>
        <input type="text" name="port" id="port" value="{{ $data['port'] ?? '3306' }}">
      </div>
    </div>
    <div class="field">
      <label for="database">ชื่อฐานข้อมูล</label>
      <input type="text" name="database" id="database" value="{{ $data['database'] ?? 'juntraweb' }}" required>
      <div class="hint">หากยังไม่มี ระบบจะสร้างให้อัตโนมัติ (ผู้ใช้ต้องมีสิทธิ์ CREATE)</div>
    </div>
    <div class="row">
      <div class="field">
        <label for="username">ผู้ใช้</label>
        <input type="text" name="username" id="username" value="{{ $data['username'] ?? 'root' }}">
      </div>
      <div class="field">
        <label for="password">รหัสผ่าน</label>
        <input type="password" name="password" id="password" value="{{ $data['password'] ?? '' }}">
      </div>
    </div>
  </div>

  <div class="actions">
    <a href="{{ route('install.welcome') }}" class="btn btn-secondary">← กลับ</a>
    <button type="submit" class="btn">ทดสอบเชื่อมต่อ + สร้างตาราง →</button>
  </div>
</form>

<script>
  function toggleSqlite(v){
    document.getElementById('sql-fields').style.display = v === 'sqlite' ? 'none' : 'block';
  }
  toggleSqlite(document.getElementById('connection').value);
</script>
@endsection
