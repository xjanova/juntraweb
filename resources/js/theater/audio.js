// 🔊 เสียงในโรงละคร — เสียงเล่าคำทำนาย + เพลงบรรเลงของห้อง (WebAudio)
//
// เสียงเล่า: <audio> → analyser (วัดความดังให้จันทร์เรืองแสงตาม) → ลำโพง
//   ไฟล์ต้องอยู่โดเมนเดียวกับเว็บ (ReadingTheater ตรวจแล้ว) ไม่งั้น analyser อ่านได้แต่ศูนย์
// เพลง: 2 เพลงของห้อง สลับกันแบบเฟดข้าม 3 วิ เบามาก และหรี่ลงเองตอนผู้พยากรณ์พูด (ducking)
//   ใช้ GainNode ไม่ใช้ audio.volume — iPhone ไม่สนค่า volume ของ <audio>
//   ปุ่ม 🎵 จำค่าไว้ในเครื่อง · แท็บถูกซ่อน = หยุดเพลง · อีเวนต์ reading-theater:mic {on} = หยุดเพลงระหว่างกดไมค์

const MUSIC_LEVEL = 0.16;      // ระดับเพลงตอนไม่มีเสียงพูด
const MUSIC_DUCKED = 0.05;     // ระดับเพลงขณะผู้พยากรณ์พูด
const FADE = 3;                // วินาทีเฟดข้ามระหว่างเพลง
const PREF_KEY = 'theater:music';

function readPref() {
    try {
        return localStorage.getItem(PREF_KEY) !== '0';
    } catch (e) {
        return true;
    }
}

function writePref(on) {
    try {
        localStorage.setItem(PREF_KEY, on ? '1' : '0');
    } catch (e) { /* โหมดส่วนตัว/ปิดที่เก็บข้อมูล — แค่จำไม่ได้ */ }
}

export class AudioRig {
    /**
     * ต้องสร้างใน event ของการแตะ (ปลดล็อกเสียงบน iPhone)
     * @param {{voiceUrl:?string, music:string[]}} opts
     */
    constructor({ voiceUrl, music }) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        this.ctx = Ctx ? new Ctx() : null;
        this.narration = null;
        this.analyser = null;
        this._buf = null;
        this._amp = 0;
        this.musicOn = readPref();
        this.musicUrls = (music || []).slice(0, 4);
        this.tracks = [];
        this._cur = 0;
        this._micHold = false;
        this._hidden = document.hidden;
        this._open = true;

        if (voiceUrl) {
            const el = new Audio();
            el.preload = 'auto';
            el.src = voiceUrl;
            el.setAttribute('playsinline', '');
            this.narration = el;
            if (this.ctx) {
                try {
                    const src = this.ctx.createMediaElementSource(el);
                    this.analyser = this.ctx.createAnalyser();
                    this.analyser.fftSize = 512;
                    this._buf = new Float32Array(this.analyser.fftSize);
                    src.connect(this.analyser);
                    this.analyser.connect(this.ctx.destination);
                } catch (e) {
                    this.analyser = null;   // เบราว์เซอร์ไม่ยอม — เสียงยังเล่นตรงจาก <audio>
                }
            }
        }

        if (this.ctx && this.musicUrls.length) {
            this.bus = this.ctx.createGain();
            this.bus.gain.value = 0;
            this.bus.connect(this.ctx.destination);
            // ใช้ 2 ช่องสลับกันเสมอ (เพลงเดียวก็เฟดข้ามกับตัวเองได้ ไม่มีช่วงเงียบ)
            for (let k = 0; k < 2; k++) {
                const el = new Audio();
                el.preload = k === 0 ? 'auto' : 'none';
                el.setAttribute('playsinline', '');
                const g = this.ctx.createGain();
                g.gain.value = k === 0 ? 1 : 0;
                try {
                    this.ctx.createMediaElementSource(el).connect(g);
                    g.connect(this.bus);
                    this.tracks.push({ el, g, url: null });
                } catch (e) { /* ไม่มีเพลงดีกว่าเพลงดังเต็มเสียงข้ามตัวคุมระดับ */ }
            }
            if (this.tracks.length === 2) {
                this._load(0, this.musicUrls[0]);
                this._tick = setInterval(() => this._crossfadeCheck(), 400);
            } else {
                this.tracks = [];
            }
        }

        this._onVis = () => {
            this._hidden = document.hidden;
            this._applyMusic();
        };
        this._onMic = (e) => {
            this._micHold = !!(e.detail && e.detail.on);
            this._applyMusic();
        };
        document.addEventListener('visibilitychange', this._onVis);
        window.addEventListener('reading-theater:mic', this._onMic);
    }

    get hasMusic() {
        return this.tracks.length === 2;
    }

    async unlock() {
        if (this.ctx && this.ctx.state !== 'running') {
            try {
                await this.ctx.resume();
            } catch (e) { /* ยังไม่ได้แตะ — รอบหน้าลองใหม่ */ }
        }
        this._applyMusic();
    }

    /** ความดังของเสียงพูดตอนนี้ (0–1, ปรับให้นุ่ม) — ไม่มีเสียง = 0 */
    amplitude() {
        if (!this.analyser || !this.narration || this.narration.paused) {
            this._amp *= 0.9;
            return this._amp;
        }
        this.analyser.getFloatTimeDomainData(this._buf);
        let sum = 0;
        for (let i = 0; i < this._buf.length; i += 2) {
            sum += this._buf[i] * this._buf[i];
        }
        const rms = Math.sqrt(sum / (this._buf.length / 2));
        const target = Math.min(1, rms * 5);
        // ขึ้นไว ลงช้า — แสงจันทร์ไม่กะพริบตามพยางค์
        this._amp += (target - this._amp) * (target > this._amp ? 0.35 : 0.08);
        return this._amp;
    }

    setMusic(on) {
        this.musicOn = on;
        writePref(on);
        this._applyMusic();
    }

    /** เรียกทุกครั้งที่สถานะเสียงพูดเปลี่ยน (เล่น/หยุด/จบ) */
    sync() {
        this._applyMusic();
    }

    _load(k, url) {
        const t = this.tracks[k];
        if (t.url !== url) {
            t.url = url;
            t.el.src = url;
        }
    }

    _applyMusic() {
        if (!this.hasMusic) {
            return;
        }
        const want = this._open && this.musicOn && !this._hidden && !this._micHold;
        const cur = this.tracks[this._cur];
        if (want) {
            if (cur.el.paused) {
                cur.el.play().catch(() => {});
            }
        } else {
            this.tracks.forEach((t) => t.el.pause());
        }
        const speaking = this.narration && !this.narration.paused && !this.narration.ended;
        const level = want ? (speaking ? MUSIC_DUCKED : MUSIC_LEVEL) : 0;
        const now = this.ctx.currentTime;
        this.bus.gain.cancelScheduledValues(now);
        this.bus.gain.setTargetAtTime(level, now, speaking ? 0.25 : 0.8);
    }

    _crossfadeCheck() {
        if (!this.hasMusic || !this.musicOn || this._hidden || this._micHold) {
            return;
        }
        // ducking ตามสถานะเสียงพูด (event ของ <audio> บางตัวมาช้า — เช็คซ้ำทุก 0.4 วิ)
        this._applyMusic();
        const a = this.tracks[this._cur];
        if (a.el.paused || !isFinite(a.el.duration) || a.el.duration - a.el.currentTime > FADE) {
            return;
        }
        const nextK = 1 - this._cur;
        const b = this.tracks[nextK];
        const idx = this.musicUrls.indexOf(a.url);
        this._load(nextK, this.musicUrls[(idx + 1) % this.musicUrls.length]);
        b.el.currentTime = 0;
        const now = this.ctx.currentTime;
        b.g.gain.cancelScheduledValues(now);
        b.g.gain.setValueAtTime(0, now);
        b.g.gain.linearRampToValueAtTime(1, now + FADE);
        a.g.gain.cancelScheduledValues(now);
        a.g.gain.setValueAtTime(1, now);
        a.g.gain.linearRampToValueAtTime(0, now + FADE);
        b.el.play().catch(() => {});
        this._cur = nextK;
        setTimeout(() => { if (this.tracks[1 - this._cur]) this.tracks[1 - this._cur].el.pause(); }, FADE * 1000 + 200);
    }

    dispose() {
        this._open = false;
        clearInterval(this._tick);
        document.removeEventListener('visibilitychange', this._onVis);
        window.removeEventListener('reading-theater:mic', this._onMic);
        if (this.narration) {
            this.narration.pause();
            this.narration.removeAttribute('src');
            this.narration.load();
        }
        this.tracks.forEach((t) => {
            t.el.pause();
            t.el.removeAttribute('src');
            t.el.load();
        });
        if (this.ctx) {
            this.ctx.close().catch(() => {});
        }
    }
}
