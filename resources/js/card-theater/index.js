// 🃏 โรงละครเปิดไพ่ (2026-11-05) — entry ของ Vite แยกไฟล์ (หน้าเลือกไพ่ + หน้าผลไพ่)
//
// แบบที่เจ้าของเลือก: ฉากโต๊ะเปล่าของแต่ละห้อง + ผู้พยากรณ์ยืนหลังโต๊ะ + ไพ่ 3D + HUD ลายทองแบบเกม
// มือถือแนวตั้งเป็นอีกแบบ (แถบความคืบหน้า · bottom sheet · เปิดไพ่แบบสตอรี่ ปัดซ้าย/ขวา)
//
// โหมด (มาจาก App\Support\CardTheater):
//   pick   — สับไพ่ → เลือกไพ่ (พัด 78 ใบ ลาก/แตะ) → แผ่นยืนยันค่าดูดวง → วางไพ่ → ส่งฟอร์มเดิม (tarot.cast)
//            ไม่มี WebGL = สำรับแบบปุ่ม เลือกได้ครบเหมือนเดิม
//   result — กำลังอ่าน: ไพ่คว่ำบนโต๊ะ + วงยันต์พิธี ถามสถานะทุก 3 วิ เสร็จ = โหลดหน้าใหม่
//            เสร็จแล้ว: เปิดไพ่จริงทีละใบ (ครั้งเดียวต่อคำทำนาย — ดูซ้ำได้จากปุ่มบนหน้าผล) แล้วปิดไปอ่านคำทำนาย
//
// ?app=1 = เปิดในแอป Juntra (WebView) — ส่งเหตุการณ์ผ่าน window.JuntraBridge / parent.postMessage
//   {source:'juntra-theater', type: ready|step|cast|done|close, ...}

import * as THREE from 'three';
import './theater.css';
import { sfx, music, voice, holdVoice, soundOn } from './sound';

const STEPS = ['เข้าห้อง', 'สับไพ่', 'เลือกไพ่', 'วางไพ่', 'รอคำทำนาย', 'เปิดคำทำนาย'];
const CW = 0.62;
const CH = 1;
const CAM_Z = 16;
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const APP = new URLSearchParams(window.location.search).get('app') === '1';

const wait = (ms) => new Promise((r) => setTimeout(r, REDUCED ? Math.min(ms, 120) : ms));
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const pose = (x, y, z, rz = 0, ry = Math.PI, s = 1, o = 1) => ({ x, y, z, rz, ry, s, o });

function storage(key, val) {
    // sessionStorage อาจโยน error (โหมดส่วนตัว / ปิดคุกกี้) — หน้าเว็บต้องทำงานต่อได้
    try {
        if (val === undefined) return window.sessionStorage.getItem(key);
        window.sessionStorage.setItem(key, val);
    } catch (e) {
        return null;
    }
    return null;
}

function bridge(type, data = {}) {
    if (!APP) return;
    const msg = JSON.stringify({ source: 'juntra-theater', type, ...data });
    try { window.JuntraBridge?.postMessage(msg); } catch (e) { /* ไม่ได้อยู่ในแอป */ }
    try { if (window.parent !== window) window.parent.postMessage(msg, '*'); } catch (e) { /* ไม่มี parent */ }
}

function webglOk() {
    try {
        const c = document.createElement('canvas');
        return !!(c.getContext('webgl2') || c.getContext('webgl'));
    } catch (e) {
        return false;
    }
}

// ป้ายข้อความ (สร้าง DOM แบบปลอดภัย — ข้อความของลูกค้า/ชื่อไพ่ไม่ผ่าน innerHTML)
function h(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
}

// ───────── เลย์เอาต์แบบไพ่ (หน่วย = ความสูงไพ่) ─────────
function spreadLayout(layout, n) {
    const P = [];
    const gx = CW + 0.16;
    const gy = CH + 0.32;
    if (layout === 'row') for (let i = 0; i < n; i++) P.push([(i - (n - 1) / 2) * (gx + 0.1), 0, 0]);
    else if (layout === 'arch') for (let i = 0; i < n; i++) { const t = i - (n - 1) / 2; P.push([t * (gx + 0.06), -Math.abs(t) * 0.28 + 0.3, -t * 0.08]); }
    else if (layout === 'grid') for (let i = 0; i < n; i++) P.push([((i % 3) - 1) * (gx + 0.1), (1 - Math.floor(i / 3)) * gy, 0]);
    else if (layout === 'celtic') {
        const sx = gx + 0.14;
        P.push([0, 0, 0], [0, 0, Math.PI / 2], [0, -gy, 0], [-sx - 0.1, 0, 0], [0, gy, 0], [sx + 0.1, 0, 0]);
        for (let k = 0; k < Math.max(0, n - 6); k++) P.push([sx * 2 + 0.55, -1.5 * (gy * 0.86) + k * gy * 0.86, 0]);
    } else if (layout === 'year') {
        for (let i = 0; i < n; i++) { const a = Math.PI / 2 - (i * Math.PI * 2) / n; P.push([Math.cos(a) * 1.95, Math.sin(a) * 1.75, 0]); }
    } else if (layout === 'shrine') {
        const rows = n === 10 ? [4, 3, 3] : [Math.ceil(n / 3), Math.ceil((n - Math.ceil(n / 3)) / 2), 0];
        rows[2] = n - rows[0] - rows[1];
        rows.forEach((c, r) => { for (let i = 0; i < c; i++) P.push([(i - (c - 1) / 2) * (gx + 0.12), (1 - r) * gy, 0]); });
    } else {
        // rows — แถวละไม่เกิน 5 ใบ
        const rowsN = Math.ceil(n / 5);
        const per = Math.ceil(n / rowsN);
        for (let i = 0; i < n; i++) {
            const r = Math.floor(i / per);
            const inRow = Math.min(per, n - r * per);
            P.push([((i % per) - (inRow - 1) / 2) * (gx + 0.1), ((rowsN - 1) / 2 - r) * gy, 0]);
        }
    }
    return P;
}

function fitLayout(P, rect, pad) {
    let x0 = Infinity; let x1 = -Infinity; let y0 = Infinity; let y1 = -Infinity;
    P.forEach(([x, y, r]) => {
        const hw = (Math.abs(Math.cos(r)) * CW) / 2 + (Math.abs(Math.sin(r)) * CH) / 2;
        const hh = (Math.abs(Math.sin(r)) * CW) / 2 + (Math.abs(Math.cos(r)) * CH) / 2;
        x0 = Math.min(x0, x - hw); x1 = Math.max(x1, x + hw); y0 = Math.min(y0, y - hh); y1 = Math.max(y1, y + hh + 0.22);
    });
    const s = Math.min(2.6, pad * Math.min((rect.x1 - rect.x0) / (x1 - x0), (rect.y1 - rect.y0) / (y1 - y0)));
    const cx = (rect.x0 + rect.x1) / 2 - ((x0 + x1) / 2) * s;
    const cy = (rect.y0 + rect.y1) / 2 - ((y0 + y1) / 2) * s;
    return { s, at: P.map(([x, y, r]) => ({ x: cx + x * s, y: cy + y * s, r })) };
}

function glowTexture() {
    const c = document.createElement('canvas');
    c.width = c.height = 128;
    const g = c.getContext('2d');
    const gr = g.createRadialGradient(64, 64, 0, 64, 64, 64);
    gr.addColorStop(0, 'rgba(255,255,255,1)'); gr.addColorStop(0.25, 'rgba(255,240,210,.55)'); gr.addColorStop(1, 'rgba(255,255,255,0)');
    g.fillStyle = gr; g.fillRect(0, 0, 128, 128);
    return new THREE.CanvasTexture(c);
}

// วงยันต์พิธี — วงซ้อน ขีดองศา ดาว 8 แฉก หมุนสวนกันสองชั้น
function sigilTexture(kind) {
    const S = 1024;
    const c = document.createElement('canvas');
    c.width = c.height = S;
    const g = c.getContext('2d');
    g.translate(S / 2, S / 2); g.strokeStyle = '#fff'; g.fillStyle = '#fff'; g.lineCap = 'round';
    const circle = (r, w, a = 1) => { g.globalAlpha = a; g.lineWidth = w; g.beginPath(); g.arc(0, 0, r, 0, Math.PI * 2); g.stroke(); };
    if (kind === 'outer') {
        circle(500, 5); circle(476, 2, 0.7); circle(392, 3, 0.9); circle(372, 1.5, 0.5);
        for (let i = 0; i < 72; i++) {
            const a = (i / 72) * Math.PI * 2; const l = i % 6 === 0 ? 34 : 14;
            g.globalAlpha = i % 6 === 0 ? 1 : 0.55; g.lineWidth = i % 6 === 0 ? 4 : 2;
            g.beginPath(); g.moveTo(Math.cos(a) * (476 - l), Math.sin(a) * (476 - l)); g.lineTo(Math.cos(a) * 476, Math.sin(a) * 476); g.stroke();
        }
        for (let i = 0; i < 12; i++) {
            const a = (i / 12) * Math.PI * 2 + Math.PI / 12; g.globalAlpha = 0.9;
            g.beginPath(); g.arc(Math.cos(a) * 432, Math.sin(a) * 432, 13, 0, Math.PI * 2); g.stroke();
            g.beginPath(); g.arc(Math.cos(a) * 432, Math.sin(a) * 432, 4, 0, Math.PI * 2); g.fill();
        }
    } else {
        circle(330, 4); circle(300, 1.5, 0.6); circle(120, 3, 0.9); circle(60, 2, 0.7);
        const star = (pts, r0, r1, w, a) => {
            g.globalAlpha = a; g.lineWidth = w; g.beginPath();
            for (let i = 0; i <= pts * 2; i++) {
                const ang = (i / (pts * 2)) * Math.PI * 2 - Math.PI / 2; const r = i % 2 ? r1 : r0;
                if (i) g.lineTo(Math.cos(ang) * r, Math.sin(ang) * r); else g.moveTo(Math.cos(ang) * r, Math.sin(ang) * r);
            }
            g.stroke();
        };
        star(8, 300, 150, 3, 0.95); star(8, 230, 120, 1.5, 0.5);
        for (let i = 0; i < 8; i++) { const a = (i / 8) * Math.PI * 2 - Math.PI / 2; g.globalAlpha = 1; g.beginPath(); g.arc(Math.cos(a) * 300, Math.sin(a) * 300, 9, 0, Math.PI * 2); g.fill(); }
    }
    const t = new THREE.CanvasTexture(c);
    t.anisotropy = 4;
    return t;
}

// หน้าไพ่โหลดไม่ได้ (รูปข้ามโดเมนไม่มี CORS / ลิงก์เสีย) — วาดชื่อไพ่บนพื้นทองแทน ไม่ปล่อยเป็นแผ่นขาว
function namedCardTexture(name) {
    const c = document.createElement('canvas');
    c.width = 310; c.height = 500;
    const g = c.getContext('2d');
    const gr = g.createLinearGradient(0, 0, 0, 500);
    gr.addColorStop(0, '#3b1d6e'); gr.addColorStop(1, '#140828');
    g.fillStyle = gr; g.fillRect(0, 0, 310, 500);
    g.strokeStyle = '#e8c477'; g.lineWidth = 8; g.strokeRect(14, 14, 282, 472);
    g.fillStyle = '#f5d89a'; g.font = '600 34px "Noto Serif Thai", serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
    const words = String(name || '').split(/\s+/);
    words.slice(0, 4).forEach((w, i) => g.fillText(w, 155, 250 + (i - (Math.min(4, words.length) - 1) / 2) * 44));
    const t = new THREE.CanvasTexture(c);
    t.colorSpace = THREE.SRGBColorSpace;
    return t;
}

function boot(root) {
    const cfgEl = root.querySelector('script[data-ct-config]');
    if (!cfgEl) return;
    const cfg = JSON.parse(cfgEl.textContent);
    const isPick = cfg.mode === 'pick';
    const gl = webglOk();
    // ย้ายไปไว้ใต้ body — ถ้าบรรพบุรุษมี transform/filter ตัว position:fixed จะไม่เต็มจอ (ถูกเมนู/ฟุตเตอร์ทับ)
    if (root.parentElement !== document.body) document.body.append(root);

    if (isPick) {
        createTheater(root, cfg, gl).open();
        return;
    }
    // หน้าผล: เปิดเองเมื่อกำลังอ่าน / เพิ่งเสร็จและยังไม่เคยดูการเปิดไพ่ของใบนี้
    if (!gl) return;
    let theater = null;
    const seenKey = `ct-revealed-${cfg.readingId}`;
    const get = () => (theater ||= createTheater(root, cfg, true));
    // 🎭 (2026-11-07) มาจากหน้าประวัติด้วย ?theater=1 = ลูกขอดูโรงละครคำทำนาย (กราฟชีวิต ฯลฯ) —
    //   เดิมโรงละครเปิดไพ่เปิดเองทับเต็มจอ (z สูงกว่า) ลูกมองไม่เห็นโรงละครที่กดมาเลย · ดูพิธีเปิดไพ่ได้จากปุ่มเดิม
    const wantsReadingTheater = new URLSearchParams(window.location.search).has('theater');
    if (!wantsReadingTheater && (cfg.status === 'reading' || (cfg.status === 'done' && !storage(seenKey)))) get().open();
    document.querySelectorAll('[data-ct-open]').forEach((b) => {
        b.hidden = false;
        b.addEventListener('click', () => get().open(true));
    });
}

function createTheater(root, cfg, useGL) {
    const isPick = cfg.mode === 'pick';
    const R = cfg.room;
    const SP = cfg.spread;
    const N = SP.labels.length;
    // บทเล่าเรื่องหลังเปิดไพ่ครบ (หน้าผลเท่านั้น) — ไม่มี = จบที่สรุปแบบเดิม
    const STORY = !isPick && cfg.story && Array.isArray(cfg.story.chapters) && cfg.story.chapters.length ? cfg.story : null;
    const tale = { i: -1, p: -1, phrases: [], playing: false, audio: null, voiced: false, timer: 0, tick: 0, wait: 0 };
    const $ = (sel) => root.querySelector(sel);
    const el = {
        bg: $('.ct-bg'), actor: $('.ct-actor'), actorImg: $('.ct-actor img'), tray: $('.ct-tray'), trayCount: $('.ct-count'),
        canvas: $('canvas.ct-gl'), labels: $('.ct-labels'), ritual: $('.ct-ritual'), steps: $('.ct-steps'), storybar: $('.ct-storybar'),
        hud: $('.ct-hud'), sub: $('.ct-sub'), subWho: $('.ct-who-name'), subMeta: $('.ct-who-meta'), subText: $('.ct-sub p'), actions: $('.ct-actions'),
        note: $('.ct-note'), deckCount: $('.ct-deck-count'), spreadPill: $('.ct-spreadpill'), sheet: $('.ct-sheet'), back: $('.ct-back'),
        fallback: $('.ct-fallback'), form: $('form.ct-form'),
    };

    // สีห้อง + ฉากโต๊ะ
    root.style.setProperty('--ct-room-a', R.accent);
    root.style.setProperty('--ct-room-b', R.accent2);
    root.style.setProperty('--ct-plate-y', `${R.plate.posY * 100}%`);
    el.bg.style.backgroundImage = `url("${R.plate.src}")`;
    el.actorImg.src = R.sprite;
    el.subWho.textContent = R.name;

    const state = { run: 0, phase: 'idle', fanOff: 0, fanVel: 0, picked: [], revealAt: -1, playing: false, layout: null, submitting: false, opened: false };
    let W = 1; let H = 1; let visH = 1; let visW = 1; let ppu = 1; let portrait = false; let safeTop = 0;

    // ระยะขอบจอปลอดภัย (ติ่งกล้อง) — อ่านจากกล่องทดสอบ
    const probe = h('div');
    probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;padding-top:env(safe-area-inset-top,0px)';
    root.append(probe);

    // ───────── three.js ─────────
    let renderer = null; let scene = null; let camera = null;
    const cards = [];
    const slots = [];
    let starMat; let moteMat; let moteGeo; let motePos; let moteVel; let moteLife; let ring; let ringA; let ringB; let ringC; let halo;
    const MOTE_N = 420;
    let moteMode = 'off';
    let ritualY = 0; let ringOn = 0;
    const bursts = [];
    let glowTex;

    const DECK = isPick ? cfg.deck.length : N;

    // ภาพไพ่ที่กำลังโหลด — พิธีเปิดไพ่รอให้ครบก่อน (สูงสุด 6 วิ) ไม่งั้นไพ่ค้างกลางจอระหว่างถอดรหัสภาพ
    const texLoads = [];
    const texReady = () => Promise.race([Promise.all(texLoads), new Promise((r) => setTimeout(r, 6000))]);
    // รอจนไพ่บนโต๊ะเข้าที่จริง (ไม่ใช่นับเวลาเปล่า) — เครื่องช้า/เฟรมกระตุกก็ไม่เปิดใบถัดไปก่อนไพ่ถึง
    function settled(maxMs) {
        const until = Date.now() + maxMs;
        return new Promise((resolve) => {
            const check = () => {
                const still = spreadCards().some((c) => {
                    const u = c.userData;
                    return u.delay > 0 || Math.abs(u.tgt.x - u.cur.x) > 0.03 || Math.abs(u.tgt.y - u.cur.y) > 0.03
                        || Math.abs(u.tgt.ry - u.cur.ry) > 0.05 || Math.abs(u.tgt.s - u.cur.s) > 0.02;
                });
                if (!still || Date.now() > until) resolve(); else setTimeout(check, 80);
            };
            check();
        });
    }

    function initGL() {
        renderer = new THREE.WebGLRenderer({ canvas: el.canvas, antialias: true, alpha: true, powerPreference: 'high-performance' });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        scene = new THREE.Scene();
        camera = new THREE.PerspectiveCamera(36, 1, 0.1, 100);
        camera.position.set(0, 0, CAM_Z);

        const loader = new THREE.TextureLoader();
        loader.setCrossOrigin('anonymous');
        const tex = (src, fallbackName) => {
            let done;
            texLoads.push(new Promise((r) => { done = r; }));
            const t = loader.load(src, () => {
                // ส่งภาพขึ้น GPU ทันทีที่โหลดเสร็จ — ไม่ให้เฟรมแรกที่ใช้ภาพนี้สะดุด
                try { renderer.initTexture(t); } catch (e) { /* เบราว์เซอร์เก่า — ให้เฟรมแรกอัปโหลดเอง */ }
                done();
            }, undefined, () => {
                // โหลดไม่ได้ → ให้วัสดุที่ใช้ภาพนี้สลับเป็นไพ่วาดชื่อแทน
                if (fallbackName != null) t.userData.fallback = namedCardTexture(fallbackName);
                done();
            });
            t.colorSpace = THREE.SRGBColorSpace;
            t.anisotropy = 4;
            return t;
        };
        const backTex = tex(cfg.cardBack, '✦');
        glowTex = glowTexture();
        const glow = new THREE.Color(R.glow);

        const cardGeo = new THREE.PlaneGeometry(CW, CH);
        const edgeGeo = new THREE.PlaneGeometry(CW * 1.22, CH * 1.16);
        for (let i = 0; i < DECK; i++) {
            const g = new THREE.Group();
            const back = new THREE.Mesh(cardGeo, new THREE.MeshBasicMaterial({ map: backTex, transparent: true }));
            back.rotation.y = Math.PI;
            const front = new THREE.Mesh(cardGeo, new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true }));
            const edge = new THREE.Mesh(edgeGeo, new THREE.MeshBasicMaterial({ map: glowTex, color: glow, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false }));
            edge.position.z = -0.01;
            g.add(back, front, edge);
            g.userData = { i, id: isPick ? cfg.deck[i] : null, front, back, edge, cur: pose(0, -9, 0), tgt: pose(0, -9, 0), rate: 6, delay: 0, glow: 0, glowT: 0,
                picked: false, flipped: false, hover: false, dim: 1, dimT: 1, shake: 0, face: null };
            g.visible = false;
            scene.add(g);
            cards.push(g);
        }
        // หน้าไพ่จริง (หน้าผล) — ภาพ/ชื่อ/กลับหัว มาจากเซิร์ฟเวอร์
        if (!isPick) {
            cfg.cards.forEach((c, k) => {
                const u = cards[k].userData;
                u.face = c;
                u.front.material.map = tex(c.img, c.name);
                u.front.material.needsUpdate = true;
                u.front.rotation.z = c.reversed ? Math.PI : 0;
            });
        }

        // ช่องวางไพ่ในถาด (เส้นประทอง + เลขลำดับ)
        if (isPick) {
            for (let k = 0; k < N; k++) {
                const c = document.createElement('canvas');
                c.width = 160; c.height = 256;
                const g = c.getContext('2d');
                g.strokeStyle = 'rgba(245,216,154,1)'; g.lineWidth = 5; g.setLineDash([14, 10]);
                g.beginPath(); if (g.roundRect) g.roundRect(8, 8, 144, 240, 16); else g.rect(8, 8, 144, 240); g.stroke();
                g.fillStyle = 'rgba(245,216,154,.12)'; g.fill();
                g.setLineDash([]); g.fillStyle = 'rgba(245,216,154,1)'; g.font = '600 64px "Noto Serif Thai", serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
                g.fillText(String(k + 1), 80, 132);
                const m = new THREE.Mesh(cardGeo, new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(c), transparent: true, opacity: 0, depthWrite: false }));
                m.visible = false; m.userData.o = 0; scene.add(m); slots.push(m);
            }
        }

        // ดาว
        const STAR_N = 700;
        const starGeo = new THREE.BufferGeometry();
        const starPos = new Float32Array(STAR_N * 3);
        for (let i = 0; i < STAR_N; i++) {
            starPos[i * 3] = (Math.random() - 0.5) * 40; starPos[i * 3 + 1] = (Math.random() - 0.5) * 26; starPos[i * 3 + 2] = -6 - Math.random() * 10;
        }
        starGeo.setAttribute('position', new THREE.BufferAttribute(starPos, 3));
        starMat = new THREE.PointsMaterial({ map: glowTex, size: 0.16, transparent: true, opacity: 0.75, depthWrite: false, blending: THREE.AdditiveBlending, color: new THREE.Color(R.star) });
        scene.add(new THREE.Points(starGeo, starMat));

        // อนุภาคพิธี
        moteGeo = new THREE.BufferGeometry();
        motePos = new Float32Array(MOTE_N * 3);
        moteVel = new Float32Array(MOTE_N * 3);
        moteLife = new Float32Array(MOTE_N);
        moteGeo.setAttribute('position', new THREE.BufferAttribute(motePos, 3));
        moteMat = new THREE.PointsMaterial({ map: glowTex, size: 0.12, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending, color: glow });
        scene.add(new THREE.Points(moteGeo, moteMat));

        // วงยันต์
        ring = new THREE.Group();
        const sigilMat = (k) => new THREE.MeshBasicMaterial({ map: sigilTexture(k), color: glow, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false });
        ringA = new THREE.Mesh(new THREE.PlaneGeometry(5, 5), sigilMat('outer'));
        ringB = new THREE.Mesh(new THREE.PlaneGeometry(5, 5), sigilMat('inner'));
        ringC = new THREE.Mesh(new THREE.PlaneGeometry(3.4, 3.4), sigilMat('inner'));
        halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: glow, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false }));
        halo.scale.set(7, 7, 1);
        ring.add(halo, ringA, ringB, ringC);
        ring.position.z = -0.3;
        scene.add(ring);

        el.canvas.addEventListener('webglcontextlost', (e) => { e.preventDefault(); if (isPick) useFallback(); else close(); });
        bindInput();
    }

    function resetMote(i, mode, at) {
        if (mode === 'swirl') {
            const a = Math.random() * Math.PI * 2; const r = 3 + Math.random() * 4;
            motePos[i * 3] = ring.position.x + Math.cos(a) * r; motePos[i * 3 + 1] = Math.sin(a) * r * 0.7 + ritualY; motePos[i * 3 + 2] = Math.random() * 0.6;
            moteLife[i] = 1;
        } else {
            motePos[i * 3] = at.x; motePos[i * 3 + 1] = at.y; motePos[i * 3 + 2] = at.z + 0.2;
            const a = Math.random() * Math.PI * 2; const sp = 1.5 + Math.random() * 3.5;
            moteVel[i * 3] = Math.cos(a) * sp; moteVel[i * 3 + 1] = Math.sin(a) * sp; moteVel[i * 3 + 2] = Math.random() * 1.5;
            moteLife[i] = 0.6 + Math.random() * 0.6;
        }
    }
    function burst(at) {
        const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: new THREE.Color(R.glow), transparent: true, opacity: 1, blending: THREE.AdditiveBlending, depthWrite: false }));
        s.position.set(at.x, at.y, at.z + 0.3); s.scale.set(0.4, 0.4, 1); scene.add(s); bursts.push({ s, t: 0 });
        let n = 0;
        for (let i = 0; i < MOTE_N && n < 70; i++) if (moteLife[i] <= 0 || moteMode !== 'burst') { resetMote(i, 'burst', at); n++; }
        moteMode = 'burst'; moteMat.opacity = 1;
    }

    // ───────── ขนาดฉาก / พื้นที่วาง ─────────
    function tableY() {
        const P = R.plate;
        const sc = Math.max(W / P.w, H / P.h);
        const off = (H - P.h * sc) * P.posY;
        return off + P.table * P.h * sc;
    }
    // ผู้พยากรณ์ยืนหลังโต๊ะ สะโพกจมหลังขอบโต๊ะ — หัวไม่ชนแถบขั้นตอน
    function placeActor() {
        const ty = tableY();
        const hgt = Math.max(60, Math.min(H * (portrait ? 0.44 : 0.5), ty - (portrait ? 118 + safeTop : 124)));
        Object.assign(el.actor.style, { top: `${ty - hgt}px`, height: `${hgt}px`, width: `${Math.round(Math.min(W, hgt * 0.9))}px` });
    }
    function oracle(mode) {
        el.actor.classList.toggle('on', mode !== '');
        el.actor.classList.toggle('faint', mode === 'faint');
    }
    // ผู้พยากรณ์พยักหน้า + เรืองแสงวูบ ทุกครั้งที่พลิกไพ่
    function nod() {
        el.actor.classList.remove('nod'); void el.actor.offsetWidth; el.actor.classList.add('nod');
    }
    let endSaid = false;
    function stageRect(full = false) {
        const short = !portrait && H < 520;
        const top = (portrait ? 70 : short ? 70 : 128) + safeTop;
        const bottom = portrait ? 150 : short ? 84 : 112;
        const side = portrait ? 12 : 40;
        let topPx = top;
        // เลือกไพ่ = พัดวางบนผ้าโต๊ะ · วาง/เปิดไพ่ใช้ความสูงเต็มเวที (ผู้พยากรณ์จางลงแล้ว)
        if (!full && state.phase === 'pick') topPx = Math.max(top, tableY() - 10);
        return { x0: (side - W / 2) / ppu, x1: (W / 2 - side) / ppu, y0: -(H / 2 - bottom) / ppu, y1: (H / 2 - topPx) / ppu };
    }
    function resize() {
        W = root.clientWidth || window.innerWidth; H = root.clientHeight || window.innerHeight;
        portrait = H > W * 1.05;
        safeTop = parseFloat(getComputedStyle(probe).paddingTop) || 0;
        placeActor();
        if (!renderer) return;
        renderer.setSize(W, H, false);
        camera.aspect = W / H; camera.updateProjectionMatrix();
        visH = 2 * CAM_Z * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)); visW = visH * camera.aspect; ppu = H / visH;
        if (state.phase === 'pick' || state.phase === 'confirm') { layoutFan(true); placeTray(true); }
        if (['deal', 'wait', 'reveal', 'done', 'story'].includes(state.phase)) placeSpread(true);
    }

    // ───────── HUD ─────────
    function renderSteps(idx) {
        el.steps.replaceChildren();
        STEPS.forEach((s, i) => {
            if (i) el.steps.append(h('i', 'ct-chev', '›'));
            const c = h('span', `ct-chip${i === idx ? ' on' : i < idx ? ' done' : ''}`);
            const inner = h('span');
            inner.append(h('b', null, String(i + 1)), s);
            c.append(inner);
            el.steps.append(c);
        });
        el.steps.dataset.label = idx < STEPS.length ? `${idx + 1}/${STEPS.length} · ${STEPS[idx]}` : 'จบการเปิดไพ่';
        storyBar();
        bridge('step', { index: idx, phase: state.phase });
    }
    // mode: 'hint' = คำใบ้บรรทัดเดียว · 'story' = กล่องคำบรรยายกรอบทอง
    function say(parts, meta = '', mode = 'story') {
        el.sub.className = `ct-sub ${mode}`;
        el.subText.replaceChildren();
        el.subMeta.textContent = meta;
        (Array.isArray(parts) ? parts : [parts]).forEach((p) => el.subText.append(typeof p === 'string' ? p : h('em', null, p.em)));
        el.subText.animate?.([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 320, easing: 'ease-out' });
    }
    function actions(list) {
        el.actions.replaceChildren();
        list.forEach((a) => {
            let b;
            if (a.href) { b = h('a'); b.href = a.href; } else { b = h('button'); b.type = 'button'; }
            if (a.icon) { b.className = 'ct-icon'; b.textContent = a.label; b.setAttribute('aria-label', a.aria || a.label); }
            else { b.className = `ct-cta${a.ghost ? ' ghost' : ''}${a.pulse ? ' pulse' : ''}`; b.append(h('span', null, a.label)); }
            if (a.disabled) b.disabled = true;
            if (a.id) b.dataset.ctId = a.id;
            if (a.on) b.addEventListener('click', a.on);
            el.actions.append(b);
        });
    }
    const action = (id) => el.actions.querySelector(`[data-ct-id="${id}"]`);
    let noteT = 0;
    function note(t) { el.note.textContent = t; el.note.classList.add('on'); clearTimeout(noteT); noteT = setTimeout(() => el.note.classList.remove('on'), 1800); }
    function setCounter() {
        const t = `${state.picked.length}/${N}`;
        [el.trayCount, el.deckCount].forEach((c) => { if (!c) return; c.textContent = t; c.classList.remove('ct-pop'); void c.offsetWidth; c.classList.add('ct-pop'); });
        const d = action('confirm');
        if (d) { d.disabled = state.picked.length < N; d.classList.toggle('pulse', state.picked.length === N); }
        const g = action('auto');
        if (g) g.firstChild.textContent = state.picked.length ? 'เลือกใหม่' : 'สุ่มให้';
    }
    function storyBar() {
        // เปิดไพ่ = จุดละใบ · เล่าเรื่อง = จุดละบท
        const telling = state.phase === 'story' && STORY;
        const n = telling ? STORY.chapters.length : N;
        const at = telling ? tale.i : state.revealAt;
        const on = portrait && (state.phase === 'reveal' || telling) && n > 1;
        el.storybar.classList.toggle('on', !!on);
        if (!on) return;
        if (el.storybar.children.length !== n) { el.storybar.replaceChildren(); for (let k = 0; k < n; k++) el.storybar.append(h('i')); }
        [...el.storybar.children].forEach((d, k) => d.classList.toggle('on', k <= at));
    }
    function pill() {
        el.spreadPill.replaceChildren(h('i', null, '✦'), `ชุดเปิดไพ่: ${SP.name.replace(/\s*\d+\s*ใบ$/, '')}`, h('span', 'ct-sep'), `${N} ใบ`);
    }

    // ───────── สับไพ่ ─────────
    async function shuffle() {
        const run = state.run;
        state.phase = 'shuffle'; renderSteps(1); oracle('on');
        actions([]); say(`${R.self}กำลังสับไพ่ทั้ง ${DECK} ใบ • ตั้งจิตถึงคำถามไว้`, '', 'hint');
        await texReady();
        if (run !== state.run) return;
        const baseY = portrait ? -visH * 0.05 : -visH * 0.04;
        cards.forEach((c, i) => { const u = c.userData; c.visible = true; u.cur = pose(0, -visH * 0.6, i * 0.004, 0, Math.PI, 1.25, 0); u.delay = i * 0.004; u.rate = 7; u.tgt = pose(0, baseY, i * 0.004, 0, Math.PI, 1.25); });
        await wait(700);
        if (run !== state.run) return;
        for (let round = 0; round < 3; round++) {
            sfx('riffle');
            cards.forEach((c, i) => {
                const u = c.userData; const side = i % 2 ? 1 : -1; u.delay = (i % 26) * 0.006; u.rate = 9;
                u.tgt = pose(side * (1.0 + (i % 7) * 0.05), baseY + Math.sin(i) * 0.15, i * 0.004 + 0.3, side * 0.35, Math.PI, 1.25);
            });
            await wait(520);
            if (run !== state.run) return;
            cards.forEach((c, i) => { const u = c.userData; u.delay = (DECK - i) * 0.004; u.rate = 10; u.tgt = pose(0, baseY + (i % 3) * 0.01, i * 0.004, 0, Math.PI, 1.25); });
            for (let k = 0; k < 40; k++) resetMote(Math.floor(Math.random() * MOTE_N), 'burst', { x: 0, y: baseY, z: 0 });
            moteMode = 'burst'; moteMat.opacity = 0.8;
            await wait(560);
            if (run !== state.run) return;
        }
        startPick();
    }

    // ───────── เลือกไพ่ (พัด ลากเลื่อนได้) ─────────
    let fan = { R: 10, d: 0.1, baseY: -2, s: 1 };
    function layoutFan(snap) {
        const rect = stageRect();
        const s = portrait ? Math.min(1.05, (rect.x1 - rect.x0) / 5.2) : Math.min(1.55, visH / 6.6);
        const apex = rect.y0 + (rect.y1 - rect.y0) * 0.2 + CH * s * 0.5;
        // floor = เส้นที่ไพ่ต้องอยู่เหนือ (กลางไพ่ไม่ต่ำกว่านี้) — ใต้นี้คือแถบปุ่ม/คำใบ้ด้านล่าง
        const floor = rect.y0 + CH * s * 0.3;
        // จอเตี้ย = โค้งพัดให้แบนลง (รัศมีใหญ่ขึ้น) ให้พัดกางได้ ~75% ของความกว้างก่อนแตะ floor · เพดาน 2.2 เท่ายังเห็นเป็นพัดโค้ง
        const base = portrait ? visW * 1.05 : visW * 0.6;
        const drop = Math.max(0.05, apex - floor); const reach = rect.x1 * 0.75;
        const Rr = Math.min(base * 2.2, Math.max(base, (reach * reach + drop * drop) / (2 * drop)));
        fan = { R: Rr, s, d: (CW * s * (portrait ? 0.5 : 0.42)) / Rr, baseY: apex, floor };
        if (snap) updateFanTargets();
    }
    const restCount = () => cards.filter((c) => !c.userData.picked).length;
    // หมุนพัดได้จนไพ่ใบแรก/ใบสุดท้ายขึ้นมาถึงยอดพัด — เจ้าของสั่ง "ต้องหมุนไพ่ขึ้นมาให้คลิ๊กได้จนสุดทุกใบ เพราะเมนูล่างมันบัง"
    // (เดิมล็อกไม่ให้ขอบสำรับเข้ากลางจอ → ไพ่ปลายพัดค้างอยู่ใต้แถบล่าง กดไม่ได้)
    function fanClamp(v) {
        return clamp(v, 0, Math.max(0, restCount() - 1));
    }
    function updateFanTargets() {
        const rest = cards.filter((c) => !c.userData.picked);
        state.fanOff = fanClamp(state.fanOff);
        const maxA = Math.asin(Math.min(0.98, (visW / 2 + 1) / fan.R));
        rest.forEach((c, j) => {
            const u = c.userData; const a = (j - state.fanOff) * fan.d;
            const lift = u.hover ? 0.28 : 0;
            const y = fan.baseY - fan.R + Math.cos(a) * fan.R;
            // ไพ่ที่โค้งลงไปใต้แถบล่าง = จางหาย (กดไม่ได้) จนกว่าจะหมุนขึ้นมา
            const show = Math.abs(a) < maxA && y >= fan.floor;
            u.tgt = pose(Math.sin(a) * fan.R, y + lift, j * 0.003 + (u.hover ? 0.5 : 0), -a, Math.PI, fan.s, show ? 1 : 0);
            u.glowT = u.hover ? 0.9 : 0;
        });
    }
    function placeTray(snap) {
        // แนวนอน = คอลัมน์ขวาบน · มือถือ = แถวเดียวบนขอบโต๊ะหน้าผู้พยากรณ์
        const rect = stageRect(true); const got = state.picked.length;
        const mob = portrait; const ty = tableY();
        const cols = mob ? N : N <= 3 ? 1 : 2;
        const rows = Math.ceil(N / cols);
        const head = mob ? 0 : 50 / ppu; const foot = mob ? 0 : 58 / ppu;
        const availH = (rect.y1 - rect.y0) * 0.7 - head - foot;
        const s = mob ? Math.min(0.55, ((rect.x1 - rect.x0) * 0.96) / (N * (CW + 0.1))) : Math.min(0.6, availH / (rows * (CH + 0.14)));
        const gx = (CW + (mob ? 0.1 : 0.14)) * s; const gy = (CH + 0.14) * s;
        const cx = mob ? 0 : rect.x1 - Math.max((cols * gx) / 2, 85 / ppu) - 0.1;
        const top = mob ? (H / 2 - ty) / ppu + CH * s * 0.4 : rect.y1 - head - CH * s * 0.55;
        const at = (k) => { const col = k % cols; const row = Math.floor(k / cols); const inRow = Math.min(cols, N - row * cols); return [cx + (col - (inRow - 1) / 2) * gx, top - row * gy]; };
        state.picked.forEach((c, k) => { const u = c.userData; const [x, y] = at(k); u.rate = snap ? 30 : 8; u.tgt = pose(x, y, 0.4 + k * 0.005, 0, Math.PI, s); u.glowT = 0.55; });
        const inPick = state.phase === 'pick' || state.phase === 'confirm';
        slots.forEach((m, k) => {
            m.visible = inPick;
            if (!m.visible) return;
            const [x, y] = at(k); m.position.set(x, y, 0.35); m.scale.setScalar(s);
            m.userData.o = k < got ? 0 : k === got ? 0.95 : 0.4;
        });
        // กรอบทอง (DOM ใต้ canvas) ครอบช่องทั้งหมด — แก้มุมมอง perspective ที่ z=.4
        if (!portrait && inPick) {
            const f = CAM_Z / (CAM_Z - 0.4);
            const wPx = cols * gx * f * ppu + 28; const hPx = rows * gy * f * ppu + (head + foot) * ppu + 8;
            const cxPx = W / 2 + cx * f * ppu; const topPx = H / 2 - (top + (CH * s) / 2 + 0.06) * f * ppu - head * ppu;
            Object.assign(el.tray.style, { left: `${cxPx - Math.max(170, wPx) / 2}px`, top: `${topPx}px`, width: `${Math.max(170, wPx)}px`, height: `${hPx}px` });
            el.tray.classList.add('on');
        } else el.tray.classList.remove('on');
    }
    function startPick() {
        state.phase = 'pick'; renderSteps(2);
        state.fanOff = (DECK - 1) / 2; state.fanVel = 0;
        layoutFan(false);
        cards.forEach((c, j) => { c.userData.delay = Math.abs(j - DECK / 2) * 0.012; c.userData.rate = 6; });
        updateFanTargets(); placeTray(true);
        pickActions();
        say('ลากเพื่อเลื่อนสำรับ • แตะเพื่อเลือกไพ่', '', 'hint');
    }
    function pickActions() {
        actions([{ label: 'สุ่มให้', ghost: true, id: 'auto', on: () => (state.picked.length ? resetPicks() : autoPick()) },
            { label: 'ยืนยันการเลือก', id: 'confirm', disabled: true, on: openConfirm }]);
        setCounter();
    }
    function pickCard(c) {
        if (state.picked.length >= N) { note(`เลือกครบ ${N} ใบแล้ว กด “ยืนยันการเลือก” ได้เลย`); c.userData.shake = 0.4; return; }
        c.userData.picked = true; c.userData.hover = false; state.picked.push(c);
        burst({ x: c.position.x, y: c.position.y, z: c.position.z });
        placeTray(false); updateFanTargets(); setCounter();
        const k = state.picked.length;
        sfx('pick', k - 1);
        say(k < N ? [`ใบที่ ${k} · `, { em: SP.labels[k - 1] }, ` • เลือกต่ออีก ${N - k} ใบ`] : ['ครบแล้ว • กด ', { em: 'ยืนยันการเลือก' }], '', 'hint');
        if (k === N) voice('pick_complete');
    }
    async function autoPick() {
        const run = state.run;
        while (state.picked.length < N && state.phase === 'pick' && run === state.run) {
            const rest = cards.filter((c) => !c.userData.picked && c.userData.cur.o > 0.5);
            const pool = rest.length ? rest : cards.filter((c) => !c.userData.picked);
            pickCard(pool[Math.floor(Math.random() * pool.length)]);
            await wait(260);
        }
    }
    function resetPicks() {
        state.run++;
        state.picked.forEach((c) => { c.userData.picked = false; c.userData.glowT = 0; c.userData.rate = 6; });
        state.picked = [];
        closeSheet();
        state.phase = 'pick';
        updateFanTargets(); placeTray(false); pickActions();
        say('ลากเพื่อเลื่อนสำรับ • แตะเพื่อเลือกไพ่', '', 'hint');
    }

    // ───────── ยืนยันก่อนตัดเงิน ─────────
    function openConfirm() {
        if (state.picked.length < N) return;
        state.phase = 'confirm';
        el.sheet.classList.add('on');
        const enough = cfg.balance == null || cfg.enough;
        actions([{ label: 'เลือกใหม่', ghost: true, on: resetPicks },
            enough ? { label: cfg.cost > 0 ? `เปิดไพ่ · ${cfg.costText}` : 'เปิดไพ่ ✦', id: 'cast', pulse: true, on: cast }
                : { label: 'เติมเงิน', href: cfg.topupUrl }]);
        say(enough ? ['ตรวจดูแล้วกด ', { em: 'เปิดไพ่' }, ` • ${R.self}จะวางไพ่และเริ่มอ่านทันที`] : 'ยอดเงินไม่พอ เติมเงินแล้วกลับมาเปิดไพ่ชุดนี้ต่อได้เลย', '', 'hint');
        if (!enough) voice('credit_low');
    }
    // ✨ (2026-11-05) ปุ่มพรีเมียมในแผ่นยืนยัน — ยอดพอ = เปิดไพ่พร้อมเสียง · ไม่พอ = ไปเติมเงิน
    //    ปุ่ม "เปิดไพ่" ปกติต้องส่ง premium=0 เสมอ (กดพรีเมียมแล้วถอยกลับมากดปกติ ห้ามติดค่าเดิมไป)
    {
        const box = el.sheet && el.sheet.querySelector('[data-ct-premium]');
        const go = box && box.querySelector('[data-ct-premium-go]');
        if (go) {
            go.addEventListener('click', () => {
                if (state.submitting || state.phase !== 'confirm') return;
                if (box.dataset.enough !== '1') { window.location.href = cfg.topupUrl; return; }
                const f = el.form.querySelector('input[name="premium"]'); if (f) f.value = '1';
                go.disabled = true;
                cast();
            });
        }
    }
    function closeSheet() { el.sheet.classList.remove('on'); }

    // ───────── วางไพ่ → ส่งฟอร์มเดิม (tarot.cast) ─────────
    async function cast(e) {
        if (state.submitting) return;
        // กดปุ่ม "เปิดไพ่" ปกติ (มาจากคลิก) = ไม่เอาพรีเมียม
        if (e && e.type === 'click') { const f = el.form && el.form.querySelector('input[name="premium"]'); if (f) f.value = '0'; }
        state.submitting = true;
        const btn = action('cast'); if (btn) btn.disabled = true;
        const ids = useGL ? state.picked.map((c) => c.userData.id) : fallbackPicked.slice();
        el.form.querySelectorAll('input[name="picked[]"]').forEach((i) => i.remove());
        ids.forEach((id) => { const i = h('input'); i.type = 'hidden'; i.name = 'picked[]'; i.value = String(id); el.form.append(i); });
        const birth = el.sheet.querySelector('input[type="date"]');
        const hidden = el.form.querySelector('input[name="birth_date"]');
        if (birth && hidden) hidden.value = birth.value;
        closeSheet();
        bridge('cast', { spread: SP.key, picked: ids });
        if (useGL) {
            state.phase = 'deal'; renderSteps(3); actions([]); oracle('faint');
            slots.forEach((m) => { m.visible = false; }); el.tray.classList.remove('on');
            say(`${R.self}วางไพ่ลงตามตำแหน่ง…`, '', 'hint');
            cards.forEach((c) => { if (!c.userData.picked) { const u = c.userData; u.delay = Math.random() * 0.3; u.rate = 4; u.tgt = pose(c.position.x * 1.2, c.position.y - visH * 0.7, -1, c.rotation.z, Math.PI, 0.9, 0); } });
            state.picked.forEach((c, k) => { const u = c.userData; u.delay = k * 0.12; u.rate = 5; u.glowT = 0.35; setTimeout(() => sfx('deal'), 180 + k * 120); });
            placeSpread(false);
            await wait(700 + N * 120);
            showLabels();
            startRing();
            say(`${R.self}เริ่มเพ่งไพ่ของคุณแล้ว…`, SP.name);
        }
        el.form.submit();
    }

    // ───────── ไพ่บนโต๊ะ (วาง/รอ/เปิด) ─────────
    function placeSpread(snap) {
        const rect = stageRect();
        const fit = fitLayout(spreadLayout(SP.layout, N), rect, portrait ? 0.96 : 0.86);
        state.layout = fit;
        // ใบที่กำลังเล่า: ลอยเข้าหากล้อง ขยายให้อ่านภาพได้ ตั้งตรง · ใบอื่นหรี่ลง
        const cx = (rect.x0 + rect.x1) / 2; const cy = (rect.y0 + rect.y1) / 2;
        const zoom = portrait
            ? Math.max(1.5, Math.min(((rect.y1 - rect.y0) * 0.7) / (CH * fit.s), ((rect.x1 - rect.x0) * 0.74) / (CW * fit.s)))
            : Math.max(1.5, Math.min(2.6, ((rect.y1 - rect.y0) * 0.62) / (CH * fit.s)));
        const pull = portrait ? 1 : 0.55;
        spreadCards().forEach((c, k) => {
            const u = c.userData; const p = fit.at[k];
            const up = u.flipped ? 0 : Math.PI;
            const showing = state.phase === 'reveal' || state.phase === 'story';
            const focus = showing && k === state.revealAt;
            // เล่าบทที่ไม่ใช่ไพ่ใบไหน (พื้นดวง/กราฟ/สรุป) = หรี่ไพ่ทั้งโต๊ะ ให้เห็นผู้พยากรณ์ที่กำลังเล่า
            const dim = showing && !focus && (state.revealAt >= 0 || state.phase === 'story');
            u.rate = snap ? 30 : u.rate || 6;
            u.tgt = focus ? pose(p.x + (cx - p.x) * pull, p.y + (cy - p.y) * pull, 2.4, 0, up, fit.s * zoom) : pose(p.x, p.y, k * 0.01, p.r, up, fit.s);
            u.dimT = dim ? (state.phase === 'story' && state.revealAt < 0 ? 0.5 : 0.38) : 1;
        });
    }
    const spreadCards = () => (isPick ? state.picked : cards);
    function showLabels() {
        el.labels.replaceChildren();
        SP.labels.forEach((t, k) => {
            const d = h('div', 'ct-label');
            d.dataset.full = `${k + 1} · ${t}`; d.dataset.short = String(k + 1); d.textContent = d.dataset.full;
            el.labels.append(d);
        });
    }
    function startRing() {
        const fit = state.layout;
        ring.position.x = fit.at.reduce((a, p) => a + p.x, 0) / fit.at.length;
        ritualY = fit.at.reduce((a, p) => a + p.y, 0) / fit.at.length;
        ring.position.y = ritualY;
        let span = 0;
        fit.at.forEach((p) => { span = Math.max(span, Math.hypot(p.x - ring.position.x, p.y - ritualY)); });
        const sc = Math.max(0.9, (span + CH * fit.s * 0.7) / 2.3); ring.scale.set(sc, sc, 1);
        ringOn = 1; moteMode = 'swirl'; moteMat.opacity = 0.9;
        for (let i = 0; i < MOTE_N; i++) resetMote(i, 'swirl');
    }
    function stopRing() { ringOn = 0; moteMode = 'off'; el.ritual.style.opacity = 0; }

    // หน้าผล: ไพ่ลอยจากกองกลางลงตำแหน่ง (คว่ำ)
    function dealResult() {
        state.phase = 'deal';
        cards.forEach((c, k) => { const u = c.userData; c.visible = true; u.flipped = false; u.cur = pose(0, -visH * 0.2, k * 0.01, 0, Math.PI, 0.9, 0); u.delay = k * 0.1; u.rate = 6; u.glowT = 0.35; });
        state.phase = 'wait';
        placeSpread(false);
        showLabels();
    }

    // ───────── รอคำทำนาย (หน้าผลที่ยังอ่านอยู่) ─────────
    let pollT = 0;
    function waitForReading() {
        renderSteps(4); oracle('faint'); dealResult();
        setTimeout(() => { startRing(); sfx('ritual'); }, 600);
        music.start(R.music);
        const started = Date.now();
        const lines = ['กำลังเชื่อมคำถามกับดวงชะตา…', 'ถอดพลังของไพ่ทีละใบ…', 'เรียบเรียงคำทำนาย…'];
        el.ritual.style.opacity = 1;
        actions([{ label: 'ปิดไว้ก่อน', ghost: true, on: () => close() }]);
        const tick = () => {
            const s = Math.round((Date.now() - started) / 1000);
            el.ritual.replaceChildren(lines[Math.floor(s / 6) % lines.length], h('small', null, `${R.self}กำลังอ่านไพ่ ${N} ใบ · ผ่านไป ${s} วินาที`));
            if (s > 240) {
                // เกิน 4 นาที — ตัวกวาดรายการค้างจะคืนเงินให้เอง
                el.ritual.replaceChildren('นานกว่าปกติ', h('small', null, 'กดรีเฟรชหน้าอีกครั้งได้ค่ะ'));
                actions([{ label: 'รีเฟรช', on: () => window.location.reload() }]);
                return;
            }
            if (s && s % 3 === 0) {
                fetch(cfg.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then((r) => (r.ok ? r.json() : null))
                    .then((j) => { if (j && j.status !== 'reading') window.location.reload(); })
                    .catch(() => { /* เน็ตสะดุด — รอบหน้าลองใหม่ */ });
            }
            pollT = setTimeout(tick, 1000);
        };
        say(`${R.self}หลับตาตั้งจิต เพ่งไพ่ทีละใบ… ไพ่ทุกใบกำลังเรืองแสงตามลมหายใจ`, SP.name);
        tick();
    }

    // ───────── เปิดไพ่ทีละใบ ─────────
    let revealTimer = 0;
    async function beginReveal() {
        const run = state.run;
        // ผลฟันธงพูดตอนเปิดไพ่ครบ ไม่ใช่ตอนเข้าหน้า (ก่อนลูกค้าเห็นไพ่)
        holdVoice(); endSaid = false;
        music.start(R.music);
        renderSteps(4); oracle('faint');
        say(`${R.self}อ่านไพ่ของคุณเสร็จแล้ว • กำลังเปิดทีละใบ`, SP.name);
        await texReady();
        if (run !== state.run) return;
        dealResult();
        await wait(400);
        await settled(6000);
        if (run !== state.run) return;
        await wait(300);
        if (run !== state.run) return;
        startReveal();
    }
    function startReveal() {
        storage(`ct-revealed-${cfg.readingId}`, '1');
        state.phase = 'reveal'; renderSteps(5); state.revealAt = -1; state.playing = true; oracle('faint');
        actions([{ label: '⏸', icon: true, aria: 'หยุด/เล่นต่อ', id: 'play', on: togglePlay }, { label: 'เปิดใบถัดไป', on: () => next(true) }]);
        voice('reveal_cards');
        next();
    }
    function togglePlay() {
        state.playing = !state.playing;
        const b = action('play'); if (b) b.textContent = state.playing ? '⏸' : '▶';
        if (state.playing) schedule(); else clearTimeout(revealTimer);
    }
    // จับเวลาใบถัดไปหลังไพ่ใบนี้ลอยเข้าที่แล้วเท่านั้น
    let schedSeq = 0;
    function schedule() {
        clearTimeout(revealTimer);
        if (!state.playing) return;
        const seq = ++schedSeq; const run = state.run;
        settled(3000).then(() => {
            if (seq !== schedSeq || run !== state.run || !state.playing) return;
            // สลับไปแท็บอื่นอยู่ = ยังไม่เปิดใบถัดไป (ลูกค้าจะพลาดคำเล่า) · กลับมาค่อยเล่าต่อ
            revealTimer = setTimeout(() => (document.hidden ? schedule() : next()), REDUCED ? 1800 : 3000);
        });
    }
    function tell(k) {
        const f = cards[k].userData.face;
        say([{ em: `${SP.labels[k]} · ${f.name}${f.reversed ? ' (กลับหัว)' : ''}` }, f.meaning ? ` — ${f.meaning}` : ''], `ใบที่ ${k + 1}/${N}`);
    }
    function next(manual) {
        if (state.phase !== 'reveal') return;
        if (state.revealAt >= N - 1) { finish(true); return; }
        state.revealAt++;
        const c = cards[state.revealAt]; const u = c.userData;
        u.flipped = true; u.rate = 7; u.glowT = 1;
        sfx('flip');
        nod();
        cards.forEach((o, k) => { if (k !== state.revealAt) o.userData.glowT = o.userData.flipped ? 0.25 : 0.35; });
        placeSpread(false);
        const at = state.revealAt;
        setTimeout(() => { burst({ x: c.position.x, y: c.position.y, z: 0.6 }); sfx('chime', at); }, 260);
        tell(state.revealAt);
        if (manual) clearTimeout(revealTimer);
        storyBar();
        if (portrait && state.revealAt === 0) note('ปัดซ้าย = ใบถัดไป · ปัดขวา = ย้อนดู');
        schedule();
    }
    function showCard(k) {
        if (!cards[k] || !cards[k].userData.flipped) return;
        state.revealAt = k; placeSpread(false); storyBar(); tell(k);
    }
    function prev() {
        if (state.phase !== 'reveal' || state.revealAt <= 0) return;
        clearTimeout(revealTimer); state.playing = false;
        const b = action('play'); if (b) b.textContent = '▶';
        showCard(state.revealAt - 1);
    }
    // auto = เพิ่งเปิดไพ่ใบสุดท้าย → ต่อเข้าช่วงเล่าเรื่องเอง · ปุ่ม "กลับไปที่สรุป" = แค่สรุป
    function finish(auto = false) {
        stopTale();
        state.phase = 'done'; state.playing = false; state.revealAt = -1; clearTimeout(revealTimer); renderSteps(6);
        cards.forEach((c) => { c.userData.glowT = 0.3; });
        placeSpread(false); oracle('faint');
        if (auto) {
            sfx('finish');
            bridge('done', { readingId: cfg.readingId, room: R.key, spread: SP.key, cards: cfg.cards.map((c, k) => ({ position: SP.labels[k], name: c.name, reversed: !!c.reversed })) });
        }
        if (auto && STORY) {
            // เว้นจังหวะให้เห็นไพ่ครบทั้งโต๊ะก่อน แล้วผู้พยากรณ์เริ่มเล่าเอง
            say(`เปิดไพ่ครบ ${N} ใบแล้ว • ${R.self}จะร้อยไพ่ทั้งหมดเป็นเรื่องเดียวให้ฟัง…`, SP.name);
            actions([{ label: 'ข้ามไปอ่าน', ghost: true, on: () => close() }, { label: 'ฟังเลย ✦', pulse: true, on: () => tell0() }]);
            const run = state.run;
            tale.wait = setTimeout(() => { if (run === state.run && state.phase === 'done') tell0(); }, REDUCED ? 1800 : 3200);
            return;
        }
        say(`${R.self}เปิดไพ่ครบ ${N} ใบแล้ว • คำทำนายเต็มรออยู่ด้านล่าง แตะไพ่ใบไหนก็ได้เพื่อดูซ้ำ`, SP.name);
        if (!endSaid && !tale.voiced) { endSaid = true; setTimeout(() => voice(cfg.voiceEnd), 900); }
        actions(STORY
            ? [{ label: '↻', icon: true, aria: 'ดูเปิดไพ่อีกครั้ง', on: replay }, { label: 'ฟังเรื่องเล่า', ghost: true, on: () => tell0() }, { label: 'อ่านคำทำนาย ✦', pulse: true, on: () => close() }]
            : [{ label: 'ดูอีกครั้ง', ghost: true, on: replay }, { label: 'อ่านคำทำนาย ✦', pulse: true, on: () => close() }]);
    }

    // ───────── เล่าเรื่อง: ผู้พยากรณ์ร้อยคำทำนายทั้งหมดเป็นเรื่องเดียว บนโต๊ะเดิม ─────────
    // บทมาจาก ReadingTheater (เซิร์ฟเวอร์) · มีเสียงคำทำนาย + เปิดลำโพง = คำบรรยายเดินตามเสียง · ไม่มี = เดินตามความยาวข้อความ
    // บทที่เล่าถึงไพ่ใบไหน = ใบนั้นลอยขึ้นมากลางโต๊ะ · บทอื่น = ไพ่หรี่ลง ผู้พยากรณ์เด่นขึ้น
    // แบ่งข้อความเป็นช่วงสั้นพออ่านทัน (ภาษาไทยเว้นวรรคระหว่างประโยค) · ขึ้นบรรทัดใหม่ = ตัดช่วงเสมอ
    function phrasesOf(text) {
        const max = portrait ? 70 : 96;
        const out = [];
        String(text || '').split(/\n+/).forEach((para) => {
            let cur = '';
            para.split(/\s+/).filter(Boolean).forEach((w) => {
                if (cur && (cur.length + 1 + w.length) > max) { out.push(cur); cur = w; } else cur = cur ? `${cur} ${w}` : w;
            });
            if (cur) out.push(cur);
        });
        return out.length ? out : [''];
    }
    function stopTale() {
        clearTimeout(tale.timer); clearTimeout(tale.wait); clearInterval(tale.tick);
        if (tale.audio) tale.audio.pause();
        tale.playing = false;
        music.lower(false);
    }
    function tell0() {
        if (!STORY) return;
        clearTimeout(tale.wait);
        state.phase = 'story'; renderSteps(5); tale.playing = true; tale.i = -1; tale.voiced = false;
        holdVoice();
        cards.forEach((c) => { c.userData.flipped = true; });
        taleActions();
        if (STORY.voice && soundOn()) voiceFrom(0); else taleGo(0);
    }
    function taleActions() {
        actions([
            { label: tale.playing ? '⏸' : '▶', icon: true, aria: 'หยุด/เล่าต่อ', id: 'tplay', on: taleToggle },
            { label: '⏭', icon: true, aria: 'บทถัดไป', on: () => taleJump(tale.i + 1) },
            { label: 'อ่านคำทำนาย ✦', ghost: true, on: () => close() },
        ]);
    }
    // เสียงคำทำนาย: เล่นจากเวลาเริ่มของบท k · เล่นไม่ได้ (ยังไม่ได้แตะหน้า/ไฟล์เสีย) = เล่าแบบตัวหนังสือต่อ
    function voiceFrom(k) {
        const c = STORY.chapters[k];
        if (!tale.audio) {
            tale.audio = new Audio(STORY.voice);
            tale.audio.preload = 'auto';
            tale.audio.addEventListener('ended', () => { if (state.phase === 'story' && tale.voiced) taleEnd(); });
        }
        const a = tale.audio; const run = state.run;
        tale.voiced = true; music.lower(true);
        try { a.currentTime = Math.max(0, c.start || 0); } catch (e) { /* ยังโหลดไม่ถึง — เริ่มต้นไฟล์ */ }
        taleGo(k, false);
        const p = a.play();
        const fail = () => {
            if (run !== state.run || state.phase !== 'story') return;
            tale.voiced = false; music.lower(false); clearInterval(tale.tick); taleNext();
            // (2026-11-07) ยังไม่ได้แตะหน้า เบราว์เซอร์เลยไม่ให้เล่น — แตะตรงไหนก็ได้ (ที่ไม่ใช่ปุ่ม) = เล่นเสียงต่อจากบทที่ค้าง
            //    ลูกค้าพรีเมียมจ่ายค่าเสียงแล้ว ต้องไม่ติดอยู่ในโหมดตัวหนังสือจนจบ
            const unlock = (e) => {
                if (e.target && e.target.closest && e.target.closest('button, a')) { document.addEventListener('pointerdown', unlock, { once: true, capture: true }); return; }
                if (run !== state.run || state.phase !== 'story' || tale.voiced || !tale.playing || !soundOn()) return;
                voiceFrom(Math.max(0, tale.i));
            };
            document.addEventListener('pointerdown', unlock, { once: true, capture: true });
        };
        if (p && p.catch) p.catch(fail);
        clearInterval(tale.tick);
        tale.tick = setInterval(voiceTick, 200);
    }
    function voiceTick() {
        const a = tale.audio;
        if (!a || state.phase !== 'story' || !tale.voiced || a.paused) return;
        if (!soundOn()) { // ปิดลำโพงกลางเรื่อง → เล่าต่อแบบตัวหนังสือจากช่วงเดิม
            a.pause(); tale.voiced = false; music.lower(false); clearInterval(tale.tick); taleNext(); return;
        }
        const t = a.currentTime; const chs = STORY.chapters;
        const k = chs.findIndex((c) => t < c.end);
        if (k < 0) { taleEnd(); return; }
        if (t < chs[k].start) return; // ช่วงเงียบระหว่างบท
        if (k !== tale.i) taleGo(k, false);
        const c = chs[k];
        const f = (t - c.start) / Math.max(0.1, c.end - c.start);
        phraseAt(Math.max(0, Math.min(tale.phrases.length - 1, Math.floor(f * tale.phrases.length))));
    }
    function taleGo(k, schedule = true) {
        const chs = STORY.chapters;
        if (k >= chs.length) { taleEnd(); return; }
        k = Math.max(0, k);
        const c = chs[k];
        const changed = k !== tale.i;
        tale.i = k; tale.phrases = phrasesOf(c.text); tale.p = -1;
        if (changed) {
            const card = Number.isInteger(c.card) && cards[c.card] ? c.card : -1;
            state.revealAt = card;
            cards.forEach((o, j) => { o.userData.glowT = j === card ? 1 : 0.25; });
            oracle(card >= 0 ? 'faint' : 'on');
            nod();
            placeSpread(false);
            if (card >= 0) sfx('chime', card);
            storyBar();
        }
        phraseAt(0);
        if (schedule) taleNext(true);
    }
    function phraseAt(p) {
        if (p === tale.p) return;
        tale.p = p;
        const c = STORY.chapters[tale.i];
        say(tale.phrases[p] || '', `${c.title} · บทที่ ${tale.i + 1}/${STORY.chapters.length}`);
    }
    // โหมดตัวหนังสือ: ช่วงละ ~12 ตัวอักษร/วินาที (อย่างน้อย 2.6 วิ ไม่เกิน 8 วิ)
    function taleNext(fresh = false) {
        clearTimeout(tale.timer);
        if (!tale.playing || tale.voiced || state.phase !== 'story') return;
        const run = state.run;
        const ms = Math.max(2600, Math.min(8000, (tale.phrases[Math.max(0, tale.p)] || '').length * 85));
        tale.timer = setTimeout(() => {
            if (run !== state.run || state.phase !== 'story' || !tale.playing) return;
            if (document.hidden) { taleNext(); return; } // สลับแท็บอยู่ = รอ ไม่เล่าข้าม
            if (tale.p < tale.phrases.length - 1) { phraseAt(tale.p + 1); taleNext(); return; }
            // จบบท — เปิดลำโพงทีหลังได้ยินเสียงตั้งแต่บทถัดไป
            if (STORY.voice && soundOn() && tale.i + 1 < STORY.chapters.length) voiceFrom(tale.i + 1);
            else taleGo(tale.i + 1);
        }, fresh ? ms + 400 : ms);
    }
    function taleJump(k) {
        if (state.phase !== 'story') return;
        if (k >= STORY.chapters.length) { taleEnd(); return; }
        k = Math.max(0, k);
        if (tale.voiced && tale.audio) {
            try { tale.audio.currentTime = STORY.chapters[k].start || 0; } catch (e) { /* ข้าม */ }
            taleGo(k, false);
            if (tale.playing) tale.audio.play().catch(() => {});
        } else taleGo(k, tale.playing);
    }
    function taleToggle() {
        tale.playing = !tale.playing;
        const b = action('tplay'); if (b) b.textContent = tale.playing ? '⏸' : '▶';
        if (tale.voiced && tale.audio) { if (tale.playing) tale.audio.play().catch(() => {}); else tale.audio.pause(); }
        // (2026-11-07) เบราว์เซอร์บล็อกเสียงตอนเริ่มเล่า (ยังไม่ได้แตะหน้า) → เล่าเป็นตัวหนังสืออยู่ · กด ▶ = แตะแล้ว เล่นเสียงต่อจากบทนี้เลย
        else if (tale.playing && STORY.voice && soundOn()) voiceFrom(Math.max(0, tale.i));
        else if (tale.playing) taleNext(); else clearTimeout(tale.timer);
    }
    function taleEnd() {
        const voiced = tale.voiced;
        stopTale(); tale.voiced = voiced; // เล่าด้วยเสียงไปแล้ว = ไม่ต้องพูดผลซ้ำ
        finish(false);
        say(`${R.self}เล่าจบแล้ว • คำทำนายฉบับเต็มอยู่ด้านล่าง ถามต่อได้เลย`, SP.name);
    }
    function replay() {
        stopTale(); tale.voiced = false;
        state.run++; endSaid = false; holdVoice();
        cards.forEach((c) => { c.userData.flipped = false; });
        state.phase = 'wait'; state.revealAt = -1; placeSpread(false);
        const run = state.run;
        setTimeout(() => { if (run === state.run) startReveal(); }, 700);
    }

    // ───────── อินพุต: ลากพัด / แตะไพ่ / ปัดสตอรี่ ─────────
    function bindInput() {
        const ray = new THREE.Raycaster(); const ndc = new THREE.Vector2();
        let down = null; let hoverCard = null;
        const hit = (x, y, list) => {
            const r = el.canvas.getBoundingClientRect();
            ndc.set(((x - r.left) / W) * 2 - 1, -((y - r.top) / H) * 2 + 1); ray.setFromCamera(ndc, camera);
            const hits = ray.intersectObjects(list.flatMap((c) => [c.userData.back, c.userData.front]), false);
            return hits.length ? hits[0].object.parent : null;
        };
        const pickable = () => cards.filter((k) => !k.userData.picked && k.visible && k.userData.cur.o > 0.5);
        el.canvas.addEventListener('pointerdown', (e) => {
            down = { x: e.clientX, y: e.clientY, off: state.fanOff, moved: 0, lastX: e.clientX, lastT: performance.now() };
            el.canvas.setPointerCapture?.(e.pointerId);
        });
        el.canvas.addEventListener('pointermove', (e) => {
            if (down && state.phase === 'pick') {
                const dx = e.clientX - down.x; down.moved = Math.max(down.moved, Math.abs(dx) + Math.abs(e.clientY - down.y));
                const perCard = fan.d * fan.R * ppu;
                state.fanOff = fanClamp(down.off - dx / perCard);
                const now = performance.now();
                state.fanVel = ((-(e.clientX - down.lastX) / perCard) / Math.max(8, now - down.lastT)) * 1000;
                down.lastX = e.clientX; down.lastT = now;
                updateFanTargets();
            } else if (!down && state.phase === 'pick' && e.pointerType === 'mouse') {
                const c = hit(e.clientX, e.clientY, pickable());
                if (c !== hoverCard) {
                    if (hoverCard) hoverCard.userData.hover = false;
                    hoverCard = c; if (c) c.userData.hover = true;
                    updateFanTargets(); el.canvas.style.cursor = c ? 'pointer' : 'grab';
                }
            }
        });
        el.canvas.addEventListener('pointerup', (e) => {
            const d = down; down = null; if (!d) return;
            const sx = e.clientX - d.x; const sy = e.clientY - d.y;
            if (state.phase === 'reveal' && Math.abs(sx) > 50 && Math.abs(sx) > Math.abs(sy)) { if (sx < 0) next(true); else prev(); return; }
            if (state.phase === 'story' && Math.abs(sx) > 50 && Math.abs(sx) > Math.abs(sy)) { taleJump(tale.i + (sx < 0 ? 1 : -1)); return; }
            if (state.phase === 'story') {
                // แตะไพ่ = ไปบทที่เล่าถึงใบนั้น
                if (d.moved > 8) return;
                const c = hit(e.clientX, e.clientY, cards);
                const j = c ? STORY.chapters.findIndex((ch) => ch.card === cards.indexOf(c)) : -1;
                if (j >= 0) taleJump(j);
                return;
            }
            if (d.moved > 8) return;
            if (state.phase === 'pick') { const c = hit(e.clientX, e.clientY, pickable()); if (c) pickCard(c); return; }
            if (state.phase === 'done' || state.phase === 'reveal') {
                const c = hit(e.clientX, e.clientY, cards);
                if (c) {
                    const k = cards.indexOf(c);
                    if (state.phase === 'done') {
                        state.phase = 'reveal'; state.playing = false; renderSteps(5);
                        actions([{ label: 'กลับไปที่สรุป', ghost: true, on: finish }, { label: 'อ่านคำทำนาย ✦', on: () => close() }]);
                    }
                    if (c.userData.flipped) showCard(k);
                } else if (state.phase === 'reveal') next(true);
            }
        });
        el.canvas.addEventListener('pointercancel', () => { down = null; });
        el.canvas.addEventListener('wheel', (e) => {
            if (state.phase !== 'pick') return;
            e.preventDefault(); state.fanOff = fanClamp(state.fanOff + (e.deltaY + e.deltaX) / 60); updateFanTargets();
        }, { passive: false });
        window.addEventListener('keydown', (e) => {
            if (!state.opened) return;
            if (state.phase === 'pick' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) { state.fanOff = fanClamp(state.fanOff + (e.key === 'ArrowRight' ? 1 : -1)); updateFanTargets(); }
            if (state.phase === 'reveal' && (e.key === ' ' || e.key === 'ArrowRight')) { e.preventDefault(); next(true); }
            if (state.phase === 'reveal' && e.key === 'ArrowLeft') prev();
            if (state.phase === 'story' && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) taleJump(tale.i + (e.key === 'ArrowRight' ? 1 : -1));
            if (state.phase === 'story' && e.key === ' ') { e.preventDefault(); taleToggle(); }
            if (e.key === 'Escape' && !isPick) close();
        });
    }

    // ───────── วาดทุกเฟรม (เฉพาะตอนเปิดอยู่) ─────────
    const clock = new THREE.Clock();
    const tmp = new THREE.Vector3();
    let raf = 0;
    function frame() {
        raf = requestAnimationFrame(frame);
        const dt = Math.min(0.25, clock.getDelta()); const t = clock.elapsedTime;
        if (state.phase === 'pick' && Math.abs(state.fanVel) > 0.05) {
            state.fanOff = fanClamp(state.fanOff + state.fanVel * dt); state.fanVel *= Math.pow(0.04, dt); updateFanTargets();
        }
        cards.forEach((c) => {
            const u = c.userData;
            if (u.delay > 0) { u.delay -= dt; return; }
            const k = 1 - Math.exp(-u.rate * dt); const cur = u.cur; const tg = u.tgt;
            for (const key of ['x', 'y', 'z', 'rz', 'ry', 's', 'o']) cur[key] += (tg[key] - cur[key]) * k;
            const flipLift = Math.sin(Math.max(0, Math.min(1, Math.abs(cur.ry - tg.ry) / Math.PI)) * Math.PI) * 0.9;
            const waiting = state.phase === 'wait' && (u.picked || !isPick);
            const bob = waiting ? Math.sin(t * 2.2 + u.i) * 0.04 : 0;
            let shake = 0;
            if (u.shake > 0) { u.shake -= dt; shake = Math.sin(u.shake * 60) * 0.05 * u.shake; }
            c.position.set(cur.x + shake, cur.y + bob, cur.z + flipLift);
            c.rotation.set(0, cur.ry, cur.rz); c.scale.setScalar(cur.s);
            // หน้าไพ่โหลดไม่ได้ → ใช้ไพ่วาดชื่อ
            const fm = u.front.material;
            if (fm.map && fm.map.userData.fallback) { fm.map = fm.map.userData.fallback; fm.needsUpdate = true; }
            const bm = u.back.material;
            if (bm.map && bm.map.userData.fallback) { bm.map = null; bm.color.set(0x3b1d6e); bm.needsUpdate = true; }
            u.back.material.opacity = u.front.material.opacity = cur.o;
            if (Math.abs(u.dimT - u.dim) > 0.002) { u.dim += (u.dimT - u.dim) * Math.min(1, dt * 6); u.back.material.color.setScalar(u.dim); u.front.material.color.setScalar(u.dim); }
            c.visible = cur.o > 0.02;
            u.glow += (u.glowT + (waiting ? (Math.sin(t * 2.2 + u.i) * 0.5 + 0.5) * 0.6 : 0) - u.glow) * Math.min(1, dt * 6);
            u.edge.material.opacity = u.glow * cur.o;
            c.renderOrder = Math.round(cur.z * 100);
        });
        const ro = ringA.material.opacity + ((ringOn ? 0.8 : 0) - ringA.material.opacity) * Math.min(1, dt * 3);
        ringA.material.opacity = ro; ringB.material.opacity = ro * 0.75; ringC.material.opacity = ro * 0.4; halo.material.opacity = ro * (0.3 + Math.sin(t * 2.2) * 0.1);
        ringA.rotation.z = t * 0.08; ringB.rotation.z = -t * 0.22; ringC.rotation.z = t * 0.35; ringA.scale.setScalar(1 + Math.sin(t * 2.2) * 0.015);
        if (moteMode !== 'off' || moteMat.opacity > 0.01) {
            for (let i = 0; i < MOTE_N; i++) {
                if (moteMode === 'swirl') {
                    const x = motePos[i * 3] - ring.position.x; const y = motePos[i * 3 + 1] - ritualY; const r = Math.hypot(x, y) || 1;
                    motePos[i * 3] += ((-x / r) * 1.4 - (y / r) * 1.1) * dt; motePos[i * 3 + 1] += ((-y / r) * 1.4 + (x / r) * 1.1) * dt;
                    if (r < 0.25) resetMote(i, 'swirl');
                } else if (moteLife[i] > 0) {
                    moteLife[i] -= dt;
                    motePos[i * 3] += moteVel[i * 3] * dt; motePos[i * 3 + 1] += moteVel[i * 3 + 1] * dt; motePos[i * 3 + 2] += moteVel[i * 3 + 2] * dt;
                    moteVel[i * 3] *= 0.96; moteVel[i * 3 + 1] = moteVel[i * 3 + 1] * 0.96 - dt * 0.6;
                    if (moteLife[i] <= 0) motePos[i * 3 + 2] = -50;
                }
            }
            moteGeo.attributes.position.needsUpdate = true;
            if (moteMode === 'burst') moteMat.opacity = Math.max(0, moteMat.opacity - dt * 0.5);
            if (moteMode === 'off') moteMat.opacity = Math.max(0, moteMat.opacity - dt * 2);
        }
        for (let i = bursts.length - 1; i >= 0; i--) {
            const b = bursts[i]; b.t += dt; const s = 0.4 + b.t * 9;
            b.s.scale.set(s, s, 1); b.s.material.opacity = Math.max(0, 1 - b.t * 1.8);
            if (b.t > 0.6) { scene.remove(b.s); b.s.material.dispose(); bursts.splice(i, 1); }
        }
        slots.forEach((m) => { if (m.visible) m.material.opacity += (m.userData.o * (0.75 + Math.sin(t * 3) * 0.25 * (m.userData.o > 0.9 ? 1 : 0)) - m.material.opacity) * Math.min(1, dt * 8); });
        starMat.opacity = 0.6 + Math.sin(t * 0.7) * 0.1;
        // ป้ายตำแหน่ง (ฉายจาก 3D ลงจอ)
        if (el.labels.children.length && state.layout) {
            const show = ['wait', 'reveal', 'done', 'story'].includes(state.phase);
            const list = spreadCards();
            [...el.labels.children].forEach((d, k) => {
                const c = list[k]; if (!c) return;
                tmp.set(c.position.x, c.position.y - CH * c.scale.x * 0.5 - 0.08, c.position.z).project(camera);
                d.style.left = `${(((tmp.x + 1) / 2) * W).toFixed(1)}px`; d.style.top = `${(((1 - tmp.y) / 2) * H).toFixed(1)}px`;
                const cur = (state.phase === 'reveal' || state.phase === 'story') && k === state.revealAt;
                // มือถือ: ตอนซูมใบที่เล่า ซ่อนป้ายใบอื่น
                const hideOther = portrait && (state.phase === 'reveal' || state.phase === 'story') && state.revealAt >= 0 && !cur;
                d.style.opacity = !show || hideOther ? 0 : state.layout.s < 0.55 && !cur && state.phase === 'reveal' ? 0.35 : 1;
                d.classList.toggle('cur', cur);
                const want = CW * c.scale.x * ppu < 74 && !cur ? d.dataset.short : d.dataset.full;
                if (d.textContent !== want) d.textContent = want;
            });
        }
        renderer.render(scene, camera);
    }

    // ───────── ไม่มี WebGL (หน้าเลือกไพ่) — สำรับแบบปุ่ม ─────────
    const fallbackPicked = [];
    function useFallback() {
        useGL = false;
        cancelAnimationFrame(raf);
        el.canvas.hidden = true; el.tray.classList.remove('on');
        state.phase = 'pick'; renderSteps(2); oracle('faint');
        el.fallback.hidden = false; el.fallback.replaceChildren();
        cfg.deck.forEach((id, i) => {
            const b = h('button', 'ct-pick');
            b.type = 'button'; b.style.backgroundImage = `url("${cfg.cardBack}")`;
            b.setAttribute('aria-pressed', 'false'); b.setAttribute('aria-label', `ไพ่ใบที่ ${i + 1}`);
            b.addEventListener('click', () => {
                const at = fallbackPicked.indexOf(id);
                if (at >= 0) { fallbackPicked.splice(at, 1); b.setAttribute('aria-pressed', 'false'); }
                else if (fallbackPicked.length < N) { fallbackPicked.push(id); b.setAttribute('aria-pressed', 'true'); }
                else note(`เลือกครบ ${N} ใบแล้ว`);
                fallbackCounter();
            });
            el.fallback.append(b);
        });
        actions([{ label: 'ยืนยันการเลือก', id: 'confirm', disabled: true, on: fallbackConfirm }]);
        fallbackCounter();
        say('แตะเพื่อเลือกไพ่ แตะซ้ำเพื่อยกเลิก', '', 'hint');
    }
    function fallbackCounter() {
        el.deckCount.textContent = `${fallbackPicked.length}/${N}`;
        const d = action('confirm'); if (d) d.disabled = fallbackPicked.length < N;
        if (fallbackPicked.length === N) window.dispatchEvent(new CustomEvent('oracle-say', { detail: { key: 'pick_complete' } }));
    }
    function fallbackConfirm() {
        if (fallbackPicked.length < N) return;
        state.picked = fallbackPicked.map(() => null);
        openConfirm();
        // "เลือกใหม่" ในโหมดนี้ = ปิดแผ่นยืนยันกลับไปแก้
        const g = el.actions.querySelector('.ct-cta.ghost');
        if (g) { const ng = g.cloneNode(true); ng.addEventListener('click', () => { closeSheet(); state.picked = []; useFallbackActions(); }); g.replaceWith(ng); }
    }
    function useFallbackActions() {
        state.phase = 'pick';
        actions([{ label: 'ยืนยันการเลือก', id: 'confirm', disabled: true, on: fallbackConfirm }]);
        fallbackCounter();
    }

    // ───────── เปิด / ปิด ─────────
    function open(replayed = false) {
        root.hidden = false; root.classList.remove('ct-closing');
        document.documentElement.classList.add('ct-open');
        state.opened = true;
        pill();
        if (!renderer && useGL) {
            try { initGL(); } catch (e) { if (isPick) { useGL = false; } else { close(); return; } }
        }
        resize();
        if (!useGL) { useFallback(); bridge('ready'); return; }
        clock.getDelta();
        cancelAnimationFrame(raf); frame();
        state.run++;
        if (isPick) { shuffle(); music.start(R.music); }
        else if (cfg.status === 'reading') waitForReading();
        else if (replayed && state.phase !== 'idle') replay();
        else beginReveal();
        bridge('ready');
    }
    function close() {
        state.run++; state.opened = false;
        clearTimeout(revealTimer); clearTimeout(pollT);
        stopTale();
        music.stop();
        // ปิดก่อนเปิดไพ่ครบ — ยังให้ผู้พยากรณ์บอกผลตามปกติ
        if (!isPick && cfg.voiceEnd && !endSaid && state.phase !== 'idle' && cfg.status === 'done') { endSaid = true; voice(cfg.voiceEnd); }
        root.classList.add('ct-closing');
        document.documentElement.classList.remove('ct-open');
        setTimeout(() => { if (!state.opened) { root.hidden = true; cancelAnimationFrame(raf); } }, 600);
        bridge('close');
    }

    el.back.addEventListener('click', (e) => {
        if (isPick) { if (APP) { e.preventDefault(); bridge('close'); } return; } // ลิงก์กลับหน้ารวมไพ่ตามปกติ
        e.preventDefault(); close();
    });
    window.addEventListener('resize', () => { if (state.opened) resize(); });
    // กดย้อนกลับหลังส่งฟอร์ม (bfcache) — ให้เลือกใหม่ได้ ไม่ค้างที่ "กำลังวางไพ่"
    window.addEventListener('pageshow', (e) => { if (e.persisted && isPick && state.submitting) window.location.reload(); });

    return { open, close };
}

const root = document.querySelector('[data-card-theater]');
if (root) boot(root);
