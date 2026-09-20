{{--
    Cloudflare Turnstile บนหน้าล็อกอินหลังบ้าน (Filament = Livewire)

    สามกับดักของการเอา widget ของ Cloudflare มาวางในหน้า Livewire — แก้ครบในไฟล์นี้:

    1. `wire:ignore` — Livewire morph DOM ทุกครั้งที่ฟอร์มตอบกลับ (เช่นกรอกรหัสผิด)
       ถ้าไม่กัน iframe ของ Cloudflare จะถูกสร้างใหม่/หลุดหาย widget ตายกลางทาง

    2. ฟอร์ม Livewire ส่ง "state ของ component" ไม่ใช่ input ดิบในหน้า ดังนั้น
       hidden input `cf-turnstile-response` ที่ Cloudflare แถมมาให้ (ซึ่งพอสำหรับ
       ฟอร์ม POST ธรรมดาอย่าง /register) ไปไม่ถึง PHP เลย ต้องยัด token เข้า
       property `$turnstileToken` เองผ่าน callback → `$wire.set(..., false)`
       (false = เก็บไว้ฝั่ง client ไม่ต้องยิง request ทันที เดี๋ยวไปพร้อมตอน submit)

    3. token ใช้ได้ครั้งเดียว — พอกรอกรหัสผิดแล้วหน้าไม่ได้โหลดใหม่ token เดิม
       จึงค้างอยู่แบบใช้แล้ว ถ้าไม่ `turnstile.reset()` รอบถัดไปจะขึ้นว่า
       "ยืนยันไม่ผ่าน" ทั้งที่ความจริงคือรหัสผิด → AdminLogin ยิง event
       `turnstile-reset` มาให้ทุกครั้งที่ล็อกอินไม่สำเร็จ
--}}
@php
    $siteKey = \App\Support\Turnstile::siteKey();
@endphp

@if ($siteKey !== '')
    <div
        wire:ignore
        x-data="{
            widgetId: null,
            tries: 0,
            failed: false,

            boot() {
                {{-- api.js โหลดแบบ async — รอให้ window.turnstile มาก่อน --}}
                if (! window.turnstile) {
                    {{-- มีเพดาน ไม่วนรอตลอดกาล: เน็ตแอดมินบล็อก challenges.cloudflare.com
                         อยู่ก็ได้ ต้องบอกให้รู้ ไม่ใช่ปล่อยให้กดเข้าไม่ได้แบบไม่มีสาเหตุ --}}
                    if (++this.tries > 80) {
                        this.failed = true;
                        return;
                    }

                    setTimeout(() => this.boot(), 125);

                    return;
                }

                this.widgetId = window.turnstile.render(this.$refs.widget, {
                    sitekey: @js($siteKey),
                    language: 'th',
                    theme: 'auto',
                    callback: (token) => this.$wire.set('turnstileToken', token, false),
                    'expired-callback': () => this.$wire.set('turnstileToken', '', false),
                    'error-callback': () => this.$wire.set('turnstileToken', '', false),
                });
            },

            reset() {
                this.$wire.set('turnstileToken', '', false);

                if (window.turnstile && this.widgetId !== null) {
                    window.turnstile.reset(this.widgetId);
                }
            },
        }"
        x-init="boot()"
        x-on:turnstile-reset.window="reset()"
    >
        <div x-ref="widget" class="flex justify-center"></div>

        <p
            x-show="failed"
            x-cloak
            class="text-sm text-danger-600 dark:text-danger-400"
        >
            โหลดตัวยืนยันตัวตนของ Cloudflare ไม่สำเร็จ — ตรวจการเชื่อมต่ออินเทอร์เน็ต
            หรือตัวกรองเว็บที่บล็อก challenges.cloudflare.com แล้วรีเฟรชหน้านี้
        </p>

        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    </div>
@endif
