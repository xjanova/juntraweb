// ⏱ เส้นเวลาของโรงละคร — ทุกอย่างบนเวทีคำนวณจากเวลา t เดียว (ไม่มีสถานะสะสม)
//   - โหมดเสียง: t = audio.currentTime · เวลาเริ่ม/จบบทมาจาก payload.voice (สัญญา 23.2) ตรง ๆ
//     หยุด/เลื่อน/เร่ง 1.25x ภาพยังตรงเพราะอ่านเวลาเดียวกันทุกเฟรม
//   - โหมดเงียบ: นาฬิกาจำลอง ยาวบทละตามความยาวข้อความ (อ่านทัน) กดเดินบทเองได้
// ย้อนกลับ = แค่ t น้อยลง → ไพ่ที่ยังไม่ถึงบทกลับคว่ำเอง กราฟหดเอง

const READ_CPS = 13;          // ตัวอักษรไทยต่อวินาทีที่อ่านทันแบบสบาย ๆ
const MIN_SILENT = 6;
const MAX_SILENT = 45;

export class Timeline {
    /**
     * @param {Array} chapters บทจากเซิร์ฟเวอร์ (มี start/end เมื่อเป็นโหมดเสียง)
     * @param {HTMLAudioElement|null} audio
     */
    constructor(chapters, audio = null) {
        this.audio = audio;
        this.rate = 1;
        this._playing = false;
        this._clock = 0;
        this._last = 0;
        this.chapters = chapters.map((c) => ({ ...c }));

        if (audio) {
            // บทสุดท้าย "คุยต่อ" ไม่มีเสียงก็ได้ — ไม่มีเวลา = ต่อท้ายไฟล์ (ถึงเมื่อเสียงจบ)
            const last = this.chapters[this.chapters.length - 1];
            if (!last || last.kind !== 'outro') {
                const end = last ? last.end : 0;
                this.chapters.push({ kind: 'outro', short: 'คุยต่อ', title: 'นั่งคุยซักถามต่อ', text: '', pos: null, start: end, end: end + 0.01 });
            }
        } else {
            let t = 0;
            for (const c of this.chapters) {
                const len = (c.text || '').length;
                const d = c.kind === 'outro' ? 8 : Math.min(MAX_SILENT, Math.max(MIN_SILENT, len / READ_CPS));
                c.start = t;
                c.end = t + d;
                t += d;
            }
        }
        this.duration = Math.max(this.chapters[this.chapters.length - 1]?.end || 0, audio && isFinite(audio.duration) ? audio.duration : 0);
    }

    get playing() {
        return this.audio ? !this.audio.paused && !this.audio.ended : this._playing;
    }

    time() {
        if (this.audio) {
            return this.audio.currentTime || 0;
        }
        if (this._playing) {
            const now = performance.now();
            this._clock += ((now - this._last) / 1000) * this.rate;
            this._last = now;
            const end = this.chapters[this.chapters.length - 1].end;
            if (this._clock >= end) {
                this._clock = end;
                this._playing = false;
            }
        }
        return this._clock;
    }

    /** บทที่ t ตกอยู่ + ความคืบหน้าในบท (0–1) */
    locate(t = this.time()) {
        const ch = this.chapters;
        let i = 0;
        for (let k = 0; k < ch.length; k++) {
            if (t + 0.001 >= ch[k].start) {
                i = k;
            } else {
                break;
            }
        }
        // เสียงเล่นจบแล้ว = อยู่บทคุยต่อ
        if (this.audio && this.audio.ended) {
            i = ch.length - 1;
        }
        const c = ch[i];
        const span = Math.max(0.001, c.end - c.start);
        return { index: i, progress: Math.min(1, Math.max(0, (t - c.start) / span)), time: t };
    }

    play() {
        if (this.audio) {
            return this.audio.play();
        }
        const end = this.chapters[this.chapters.length - 1].end;
        if (this._clock >= end - 0.01) {
            this._clock = 0;
        }
        this._last = performance.now();
        this._playing = true;
        return Promise.resolve();
    }

    pause() {
        if (this.audio) {
            this.audio.pause();
        } else {
            this.time();
            this._playing = false;
        }
    }

    seek(t) {
        t = Math.max(0, Math.min(this.duration, t));
        if (this.audio) {
            this.audio.currentTime = t;
        } else {
            this._clock = t;
            this._last = performance.now();
        }
    }

    seekChapter(i) {
        const c = this.chapters[Math.max(0, Math.min(this.chapters.length - 1, i))];
        this.seek(c.start + 0.01);
    }

    setRate(r) {
        this.rate = r;
        if (this.audio) {
            this.audio.playbackRate = r;
            // ให้เสียงไม่เพี้ยนตอนเร่ง (Safari ใช้ชื่อมี prefix)
            this.audio.preservesPitch = true;
            this.audio.webkitPreservesPitch = true;
        }
    }
}

/**
 * สถานะภาพของเวที ณ บท i — ฟังก์ชันล้วน ใช้ทั้ง WebGL และแบบ CSS
 *
 * - revealAt ของไพ่ = บทแรกที่พูดถึงใบนั้น (ไม่มีบทของใบนั้น = เปิดตอนบทสรุป)
 * - กราฟ: ก่อนถึงบทกราฟ = ยังไม่วาด · ในบทกราฟ = ลากตามความคืบหน้า · หลังจากนั้น = เต็มเส้น
 */
export function revealPlan(chapters, positions) {
    const plan = {};
    const summary = chapters.findIndex((c) => c.kind === 'summary' || c.kind === 'outro');
    for (const pos of positions) {
        const i = chapters.findIndex((c) => c.kind === 'card' && c.pos === pos);
        plan[pos] = i >= 0 ? i : (summary >= 0 ? summary : chapters.length - 1);
    }
    return plan;
}

export function visualState(chapters, plan, index, progress) {
    const c = chapters[index] || { kind: 'graph' };
    const graphAt = chapters.findIndex((x) => x.kind === 'graph');
    let graphDraw = 1;
    if (graphAt >= 0) {
        if (index < graphAt) {
            graphDraw = 0;
        } else if (index === graphAt) {
            graphDraw = 0.08 + 0.92 * Math.min(1, progress * 1.15);
        }
    }
    const revealed = {};
    for (const pos in plan) {
        revealed[pos] = index >= plan[pos];
    }
    return {
        kind: c.kind,
        index,
        progress,
        currentPos: c.kind === 'card' ? c.pos : null,
        revealed,
        graphDraw,
        // ไพ่ยังไม่ลงโต๊ะในบทพื้นดวง (เปิดฉาก: ลอยลงมาตอนเข้าบทกราฟ)
        dealt: c.kind !== 'birth',
        ring: c.kind === 'summary' || c.kind === 'outro',
        sky: c.kind === 'birth',
    };
}
