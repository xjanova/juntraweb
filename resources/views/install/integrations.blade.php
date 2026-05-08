@extends('install.layout')

@section('content')
<h2>เชื่อมต่อบริการภายนอก</h2>
<p class="lede">ตั้งค่าระบบสมาชิก Thaiprompt และ AI ตอนนี้ได้เลย หรือข้ามไปก่อน เปลี่ยนทีหลังในหน้าแอดมินได้</p>

<form method="POST" action="{{ route('install.integrations.save') }}">
  @csrf

  <h3 style="color:#f4cf6a;margin:8px 0 14px;font-size:16px;letter-spacing:.1em;text-transform:uppercase">ระบบสมาชิก Thaiprompt</h3>
  <label class="checkbox">
    <input type="checkbox" name="thaiprompt_enabled" value="1" {{ !empty($data['thaiprompt_enabled']) ? 'checked' : '' }}>
    เปิดใช้งาน Single Sign-On ผ่าน Thaiprompt
  </label>

  <div class="field">
    <label for="thaiprompt_base_url">Base URL</label>
    <input type="url" name="thaiprompt_base_url" id="thaiprompt_base_url" value="{{ $data['thaiprompt_base_url'] ?? 'https://thaiprompt.com' }}">
    <div class="hint">เช่น https://thaiprompt.com — ระบบจะเรียก {base}/oauth/authorize, /oauth/token, /api/user</div>
  </div>

  <div class="row">
    <div class="field">
      <label for="thaiprompt_client_id">Client ID</label>
      <input type="text" name="thaiprompt_client_id" id="thaiprompt_client_id" value="{{ $data['thaiprompt_client_id'] ?? '' }}">
    </div>
    <div class="field">
      <label for="thaiprompt_client_secret">Client Secret</label>
      <input type="password" name="thaiprompt_client_secret" id="thaiprompt_client_secret" value="">
      <div class="hint">เก็บแบบ encrypted ใน DB · เว้นว่างหากไม่อยากเปลี่ยน</div>
    </div>
  </div>

  <h3 style="color:#f4cf6a;margin:24px 0 14px;font-size:16px;letter-spacing:.1em;text-transform:uppercase">AI · ตัวพยากรณ์</h3>
  <div class="row">
    <div class="field">
      <label for="ai_provider">Provider</label>
      <select name="ai_provider" id="ai_provider">
        <option value="gemini" {{ ($data['ai_provider'] ?? '') === 'gemini' ? 'selected' : '' }}>Google Gemini</option>
        <option value="openai" {{ ($data['ai_provider'] ?? '') === 'openai' ? 'selected' : '' }}>OpenAI</option>
        <option value="anthropic" {{ ($data['ai_provider'] ?? '') === 'anthropic' ? 'selected' : '' }}>Anthropic</option>
      </select>
    </div>
    <div class="field">
      <label for="ai_model">Model</label>
      <input type="text" name="ai_model" id="ai_model" value="{{ $data['ai_model'] ?? 'gemini-2.0-flash-exp' }}">
    </div>
  </div>

  <div class="field">
    <label for="ai_api_key">API Key</label>
    <input type="password" name="ai_api_key" id="ai_api_key" value="" placeholder="วาง API key ที่นี่">
    <div class="hint">ถ้าไม่ใส่ ระบบจะใช้คำตอบ heuristic แทน · กรอกทีหลังที่ /admin ได้</div>
  </div>

  <div class="actions">
    <a href="{{ route('install.admin') }}" class="btn btn-secondary">← กลับ</a>
    <button type="submit" class="btn">บันทึก + ดำเนินการต่อ →</button>
  </div>
</form>
@endsection
