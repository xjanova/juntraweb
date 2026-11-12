<!doctype html>
<html lang="th">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $success ? 'เข้าสู่ระบบสำเร็จ' : 'เข้าสู่ระบบไม่สำเร็จ' }} — จันทรา</title>
<style>body{background:#171026;color:#f9f2df;font:18px system-ui,sans-serif;text-align:center;margin:15vh auto;padding:24px;max-width:560px}a{display:inline-block;background:#e9c778;color:#231633;padding:16px 24px;border-radius:12px;text-decoration:none}p{line-height:1.8}</style>
<h1>{{ $success ? 'เข้าสู่ระบบสำเร็จ' : 'เข้าสู่ระบบไม่สำเร็จ' }}</h1>
<p>{{ $success ? 'กลับไปที่แอปจันทราเพื่อใช้งานต่อได้เลยค่ะ' : 'กรุณากลับไปที่แอปจันทราแล้วลองเข้าสู่ระบบอีกครั้งค่ะ' }}</p>
<a href="juntra://auth/complete">กลับไปแอปจันทรา</a>
</html>
