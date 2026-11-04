// ⏲ คุมจังหวะวาด + คุมคุณภาพ — ตาม PLAYBOOK "A 3D web page that costs less GPU" (เจ้าของสั่งใช้ทุกหน้า 3D)
//  1) จอ 120/144 Hz วาดทุก n vsync (≈ 60–72 fps) — นับ vsync ไม่ใช้ตัวสะสมเวลา (กันกระตุก 2/3)
//  2) ลูกค้านั่งฟังนิ่ง ๆ (ไม่มีอะไรขยับเกิน 3.5 วิ) ลดเหลือ ~30 fps
//  3) ตัวคุมคุณภาพลดขั้นเอง: DPR → bloom → ฝุ่นแสง → DPR 0.75 → ถอยไปแบบ CSS
//     ไม่นับเฟรมช่วงนิ่ง (dt 33 ms ดูเหมือน GPU ช้า — กับดักใน playbook)

const IDLE_AFTER = 3500;

export class Pacer {
    constructor() {
        this.hz = 60;
        this._ema = 1000 / 60;
        this._count = 0;
        this._lastRaw = 0;
        this._lastWake = performance.now();
        this.idle = false;
        this.every = 1;
    }

    wake() {
        this._lastWake = performance.now();
    }

    /**
     * เรียกทุก rAF — คืน true เมื่อเฟรมนี้ควรวาด
     */
    tick(now) {
        if (this._lastRaw) {
            const d = now - this._lastRaw;
            // Firefox resistFingerprinting ปัดเวลาเป็น 100 ms — รับเฉพาะ 2–50 ms
            if (d > 2 && d < 50) {
                this._ema += (d - this._ema) * 0.05;
                this.hz = 1000 / this._ema;
            }
        }
        this._lastRaw = now;
        this.idle = now - this._lastWake > IDLE_AFTER;
        const every = this.idle ? Math.max(1, Math.floor(this.hz / 28)) : Math.max(1, Math.floor(this.hz / 55));
        this.every = every;
        if (++this._count < every) {
            return false;
        }
        this._count = 0;
        return true;
    }
}

/**
 * ขั้นคุณภาพ — เริ่มจากขั้นที่เดาจากเครื่อง แล้วลดลงเมื่อวาดไม่ทัน
 * dpr = เพดาน devicePixelRatio · bloom = มีแสงฟุ้งไหม · dust = สัดส่วนฝุ่นแสง
 */
export const TIERS = [
    { dpr: 2, bloom: true, dust: 1 },
    { dpr: 1.5, bloom: true, dust: 1 },
    { dpr: 1.25, bloom: true, dust: 0.6 },
    { dpr: 1, bloom: false, dust: 0.5 },
    { dpr: 0.75, bloom: false, dust: 0.3 },
];

export function startTier() {
    const mem = navigator.deviceMemory || 8;
    const cores = navigator.hardwareConcurrency || 8;
    const coarse = window.matchMedia('(pointer: coarse)').matches;
    if (mem <= 2 || cores <= 2) {
        return 3;
    }
    if (coarse && (mem <= 4 || cores <= 4)) {
        return 2;
    }
    return coarse ? 1 : 0;
}

export class Governor {
    constructor(onStep) {
        this.onStep = onStep;
        this._sum = 0;
        this._n = 0;
        this._since = performance.now();
        this._grace = performance.now() + 2500;   // ช่วงเปิดฉาก (โหลดภาพ/คอมไพล์) ไม่ตัดสิน
    }

    /** dtMs = เวลาจริงระหว่างเฟรมที่วาด · targetMs = เวลาที่ควรเป็นตาม pacer */
    sample(dtMs, targetMs, idle, now) {
        if (idle || now < this._grace) {
            this._sum = 0;
            this._n = 0;
            this._since = now;
            return;
        }
        this._sum += dtMs / targetMs;
        this._n++;
        if (now - this._since < 2000 || this._n < 30) {
            return;
        }
        const ratio = this._sum / this._n;
        this._sum = 0;
        this._n = 0;
        this._since = now;
        // ช้ากว่าเป้าเฉลี่ยเกิน 45% ติดต่อกัน 2 วิ = ลดขั้น แล้วให้เวลาตั้งตัวใหม่
        if (ratio > 1.45) {
            this._grace = now + 1500;
            this.onStep();
        }
    }
}
