// 🔔 เสียงของโรงละครเปิดไพ่ (2026-11-05)
//
// ใช้สวิตช์เดียวกับปุ่มลำโพงของผู้พยากรณ์ (window.JuntraVoice — เปิดเป็นค่าเริ่มต้น · กดปิดเองจำใน localStorage 'juntra:voice-off')
//   ปิดเสียงอยู่ = เงียบทั้งหมด (เสียงประกอบ · เพลง · เสียงพากย์)
//
// - เสียงประกอบ: สังเคราะห์ด้วย Web Audio ในเบราว์เซอร์ (สับไพ่ / หยิบไพ่ / พลิกไพ่ / กระดิ่ง)
//   ไม่มีไฟล์ ไม่เรียกบริการภายนอก ไม่มีค่าใช้จ่าย
// - เพลง: เพลงบรรเลงของห้อง (config/oracle_media.php) เล่นเบามาก วนต่อกัน เฟดเข้า/ออก
// - เสียงพากย์: ส่งต่อให้ <x-oracle-voice> ผ่านเหตุการณ์ 'oracle-say' (ปุ่มลำโพงเป็นคนเล่น)
//
// เบราว์เซอร์ไม่ให้เล่นเสียงก่อนลูกค้าแตะหน้า — ทุกอย่างในนี้เงียบไปเฉย ๆ ถ้ายังไม่ได้แตะ ไม่โยน error

const MUSIC_VOL = 0.16;

export const soundOn = () => {
    try { return !!(window.JuntraVoice && window.JuntraVoice.on()); } catch (e) { return false; }
};

// ───────── Web Audio (สร้างเมื่อจำเป็น) ─────────
let ctx = null;
let master = null;
let noiseBuf = null;
function audio() {
    if (!ctx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return null;
        try { ctx = new AC(); } catch (e) { return null; }
        master = ctx.createGain();
        master.gain.value = 0.55;
        master.connect(ctx.destination);
        // เสียงซ่า 1 วินาที — ใช้ซ้ำทุกเสียงกระดาษ
        noiseBuf = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
        const d = noiseBuf.getChannelData(0);
        for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    }
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    return ctx.state === 'running' ? ctx : null;
}
// ปลดล็อกเสียงในจังหวะที่ลูกค้าแตะหน้าครั้งแรก (นโยบาย autoplay)
['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, () => { if (soundOn()) audio(); }, { capture: true, passive: true }));
// กดปุ่มลำโพงเปิดเสียง — ปุ่มเปลี่ยนสถานะใน click (หลัง pointerdown) จึงฟังช่วง bubble ด้วย
document.addEventListener('click', () => { if (soundOn()) audio(); });

// เสียงกระดาษ: noise ผ่าน band-pass ที่กวาดความถี่ — สั้น = หยิบ/ดีด · ยาว = ลากผ่าน
function paper(at, { dur = 0.12, from = 2400, to = 5200, q = 0.9, vol = 0.35 } = {}) {
    const a = ctx; const src = a.createBufferSource(); src.buffer = noiseBuf;
    const bp = a.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = q;
    bp.frequency.setValueAtTime(from, at); bp.frequency.exponentialRampToValueAtTime(to, at + dur);
    const g = a.createGain();
    g.gain.setValueAtTime(0.0001, at); g.gain.exponentialRampToValueAtTime(vol, at + Math.min(0.012, dur / 4)); g.gain.exponentialRampToValueAtTime(0.0001, at + dur);
    src.connect(bp).connect(g).connect(master);
    src.start(at, Math.random() * 0.5, dur + 0.05);
}
// กระดิ่ง: sine หลายฮาร์มอนิก (สัดส่วนแบบระฆังโลหะ) ดังขึ้นเร็ว จางช้า
function bell(at, f0, vol = 0.18, decay = 2.2) {
    const a = ctx;
    [[1, 1], [2.76, 0.42], [5.4, 0.2], [8.93, 0.08]].forEach(([m, v]) => {
        const o = a.createOscillator(); o.type = 'sine'; o.frequency.value = f0 * m;
        const g = a.createGain();
        g.gain.setValueAtTime(0.0001, at); g.gain.exponentialRampToValueAtTime(vol * v, at + 0.008); g.gain.exponentialRampToValueAtTime(0.0001, at + decay / Math.sqrt(m));
        o.connect(g).connect(master); o.start(at); o.stop(at + decay + 0.1);
    });
}

// โน้ตห้าเสียง (เพนทาโทนิก) — กระดิ่งของไพ่แต่ละใบไล่ขึ้นทีละขั้น ฟังเป็นทำนองเดียวกันทั้งชุด
const PENTA = [0, 2, 4, 7, 9, 12, 14, 16, 19, 21, 24];
const note = (base, step) => base * Math.pow(2, PENTA[step % PENTA.length] / 12);

/**
 * เล่นเสียงประกอบ
 * @param {'riffle'|'pick'|'deal'|'flip'|'chime'|'finish'|'ritual'} name
 * @param {number} [i] ลำดับ (ไพ่ใบที่ — ใช้เลือกโน้ตกระดิ่ง)
 */
export function sfx(name, i = 0) {
    if (!soundOn()) return;
    const a = audio();
    if (!a) return;
    const t = a.currentTime + 0.01;
    switch (name) {
        case 'riffle': // สับไพ่: ดีดถี่ ๆ ~22 ครั้ง
            for (let k = 0; k < 22; k++) paper(t + k * 0.022 + Math.random() * 0.008, { dur: 0.05, from: 3000, to: 6000, vol: 0.12 + Math.random() * 0.08 });
            paper(t + 0.5, { dur: 0.09, from: 900, to: 400, q: 0.7, vol: 0.25 }); // ไพ่กระทบกันตอนรวบ
            break;
        case 'pick': // หยิบไพ่ขึ้นจากพัด
            paper(t, { dur: 0.16, from: 1800, to: 5600, vol: 0.3 });
            bell(t + 0.04, note(784, i), 0.05, 0.9);
            break;
        case 'deal': // วางไพ่ลงโต๊ะ
            paper(t, { dur: 0.09, from: 5200, to: 1500, vol: 0.28 });
            paper(t + 0.06, { dur: 0.05, from: 700, to: 350, q: 0.6, vol: 0.18 });
            break;
        case 'flip': // พลิกไพ่
            paper(t, { dur: 0.13, from: 1400, to: 6400, q: 1.2, vol: 0.32 });
            break;
        case 'chime': // เปิดเห็นหน้าไพ่
            bell(t, note(523.25, i), 0.16, 2.4);
            break;
        case 'finish': // ครบทุกใบ: อาร์เปจโจขึ้น
            [0, 2, 4, 5, 7].forEach((s, k) => bell(t + k * 0.11, note(523.25, s), 0.12, 2.8));
            break;
        case 'ritual': // เริ่มพิธีรอคำทำนาย: ระฆังต่ำครั้งเดียว
            bell(t, 196, 0.2, 4.5);
            break;
        default:
    }
}

// ───────── เพลงบรรเลงของห้อง ─────────
let track = null;
let list = [];
let at = 0;
let fadeT = 0;
let level = 1;       // ระดับฐานของเพลง (หรี่ค้างไว้ระหว่างผู้พยากรณ์เล่าเรื่องด้วยเสียง)
let watchT = 0;
let fails = 0;       // เพลงที่เล่นไม่ได้ติดกัน — ครบทั้งรายการแล้วพักไว้ ไม่วนลองไม่จบ
function fade(el, to, ms, done) {
    clearInterval(fadeT);
    const from = el.volume; const t0 = performance.now();
    fadeT = setInterval(() => {
        const k = Math.min(1, (performance.now() - t0) / ms);
        el.volume = Math.max(0, Math.min(1, from + (to - from) * k));
        if (k >= 1) { clearInterval(fadeT); if (done) done(); }
    }, 50);
}
function playAt(k) {
    if (!list.length) return;
    at = k % list.length;
    const el = new Audio(list[at]);
    el.volume = 0;
    el.addEventListener('ended', () => { if (track === el) playAt(at + 1); });
    // 🎵 (2026-11-05) เจ้าของสั่ง "เพลงควรเล่นวนไปเรื่อยๆ" — ไฟล์โหลดไม่ได้/ถอดรหัสไม่ได้ เดิมค้างเงียบตลอดไป
    //    (ไม่มี 'ended') → ข้ามไปเพลงถัดไป · เสียครบทุกเพลงแล้วพัก (รอบตรวจถัดไปลองใหม่ตั้งแต่ต้น)
    el.addEventListener('playing', () => { fails = 0; });
    el.addEventListener('error', () => {
        if (track !== el) return;
        track = null;
        if (++fails < list.length) setTimeout(() => { if (!track) playAt(at + 1); }, 800);
    });
    track = el;
    const p = el.play();
    if (p && p.catch) p.catch(() => { if (track === el) track = null; }); // ยังไม่ได้แตะหน้า — รอบตรวจถัดไปค่อยลองใหม่
    fade(el, MUSIC_VOL * level, 2500);
}
function stopTrack(ms = 900) {
    const el = track; track = null;
    if (el) fade(el, 0, ms, () => el.pause());
}
export const music = {
    /** เริ่มเพลงของห้อง (เล่นจริงเฉพาะตอนเปิดเสียง) — ตรวจปุ่มลำโพงทุกวินาทีจนกว่าจะ stop() */
    start(urls) {
        list = Array.isArray(urls) ? urls.filter(Boolean) : [];
        clearInterval(watchT);
        if (!list.length) return;
        fails = 0;
        const tick = () => {
            if (soundOn() && !track) playAt(at);
            else if (!soundOn() && track) stopTrack(400);
            // เครื่องหยุดเสียงเอง (สายเข้า / สลับแอพบนมือถือ) — กลับมาแล้วเล่นต่อจากเดิม
            else if (soundOn() && track && track.paused && !track.ended && !document.hidden) {
                const p = track.play();
                if (p && p.catch) p.catch(() => {});
            }
        };
        tick();
        watchT = setInterval(tick, 1000);
    },
    stop() { clearInterval(watchT); level = 1; stopTrack(); },
    /** หรี่ลงชั่วครู่ตอนผู้พยากรณ์พูด */
    duck(ms = 3500) {
        if (!track) return;
        const el = track;
        fade(el, MUSIC_VOL * 0.35, 300, () => setTimeout(() => { if (track === el) fade(el, MUSIC_VOL * level, 1200); }, ms));
    },
    /** หรี่เพลงค้างไว้ (เล่าเรื่องด้วยเสียง) / คืนระดับเดิม */
    lower(on) {
        level = on ? 0.3 : 1;
        if (track) fade(track, MUSIC_VOL * level, 600);
    },
};

// ───────── เสียงพากย์ (ผ่าน <x-oracle-voice>) ─────────
/** ให้ผู้พยากรณ์พูดประโยคประจำ (ปุ่มลำโพงปิดอยู่ / ไม่มีไฟล์ของห้องนี้ = เงียบ) */
export function voice(key) {
    if (!key) return;
    if (soundOn()) music.duck();
    window.dispatchEvent(new CustomEvent('oracle-say', { detail: { key } }));
}
/** หยุดประโยคที่หน้าเพิ่งพูดตอนเข้า — โรงละครจะพูดเองตามจังหวะพิธี */
export function holdVoice() {
    window.__ctVoiceHold = true;
    window.dispatchEvent(new CustomEvent('oracle-hold'));
}
