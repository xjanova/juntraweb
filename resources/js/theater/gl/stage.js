// 🌙 เวที WebGL ของโรงละครคำทำนาย (three.js — โหลดเฉพาะตอนเปิดโรงละคร)
//
// ภาพทุกเฟรมคำนวณจากสถานะเดียว (hooks.getState → visualState ของ timeline) แล้ว "ไหล" เข้าหาเป้าแบบนุ่ม
// ย้อนบท = เป้าเปลี่ยน ไพ่คว่ำกลับเอง ไม่มีอนิเมชันค้างครึ่งทาง
// ตัวอักษรไทยทั้งหมดเป็น HTML วางทับตำแหน่ง 3 มิติ (คมชัด สระไม่ลอย) — ใน canvas ไม่มีตัวอักษรเลย

import * as THREE from 'three';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { OutputPass } from 'three/addons/postprocessing/OutputPass.js';
import { cardLayout, graphLayout, CARD_W, CARD_H, TONE_COLOR, VERDICT_COLOR, ROOM_COLORS } from '../layout.js';
import { CONSTELLATIONS, starSize, starDelays } from '../constellations.js';
import { Pacer, Governor, TIERS, startTier } from './pacer.js';
import * as S from './shaders.js';

const BLOOM_SCALE = 0.6;   // บัฟเฟอร์ bloom 0.6 ของจอ — ค่าตั้งต้นของหน้านี้ (ปรับแสงฟุ้งให้พอดีที่สเกลนี้แล้ว)

/** UnrealBloomPass ที่ใช้บัฟเฟอร์เล็กกว่าจอ (แสงฟุ้งเบลออยู่แล้ว ไม่ต้องคมเต็มจอ) */
class ScaledBloomPass extends UnrealBloomPass {
    setSize(w, h) {
        super.setSize(Math.max(1, Math.round(w * BLOOM_SCALE)), Math.max(1, Math.round(h * BLOOM_SCALE)));
    }
}

const damp = (cur, target, k, dt) => cur + (target - cur) * (1 - Math.exp(-k * dt));

/** หลังไพ่ (วาดเองบน canvas — ใช้เมื่อแอดมินยังไม่ได้อัปโหลดรูปหลังไพ่) */
function drawCardBack(colors) {
    const c = document.createElement('canvas');
    c.width = 256;
    c.height = 436;
    const g = c.getContext('2d');
    const bg = g.createLinearGradient(0, 0, 256, 436);
    bg.addColorStop(0, '#1a0d3d');
    bg.addColorStop(1, '#07041a');
    g.fillStyle = bg;
    g.fillRect(0, 0, 256, 436);
    g.strokeStyle = colors.b;
    g.lineWidth = 6;
    g.strokeRect(14, 14, 228, 408);
    g.lineWidth = 1.5;
    g.strokeRect(26, 26, 204, 384);
    g.fillStyle = colors.b;
    g.beginPath();
    g.arc(128, 218, 46, 0, Math.PI * 2);
    g.fill();
    g.fillStyle = '#120a30';
    g.beginPath();
    g.arc(146, 206, 42, 0, Math.PI * 2);
    g.fill();
    for (let i = 0; i < 40; i++) {
        const a = (i / 40) * Math.PI * 2;
        g.fillStyle = colors.a;
        g.globalAlpha = 0.5;
        g.fillRect(128 + Math.cos(a) * 80 - 1, 218 + Math.sin(a) * 80 - 1, 2.5, 2.5);
    }
    g.globalAlpha = 1;
    const t = new THREE.CanvasTexture(c);
    t.colorSpace = THREE.SRGBColorSpace;
    return t;
}

/**
 * @param {HTMLElement} host    กล่องที่ใส่ canvas + ชั้นป้าย HTML
 * @param {object} cfg           ข้อมูลจาก ReadingTheater::build
 * @param {{getState:Function, amplitude:Function, safe:Function, onFallback:Function, onLabel?:Function}} hooks
 */
export async function createGLStage(host, cfg, hooks) {
    const colors = ROOM_COLORS[cfg.persona] || ROOM_COLORS.maemor;
    let tierIndex = startTier();
    let tier = TIERS[tierIndex];

    const canvas = document.createElement('canvas');
    canvas.className = 'rt-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    host.appendChild(canvas);
    const labels = document.createElement('div');
    labels.className = 'rt-labels';
    host.appendChild(labels);

    // composer วาดลงบัฟเฟอร์ของตัวเอง → antialias ของ canvas ไม่มีผลกับภาพ (PLAYBOOK: ปิดฟรี)
    const renderer = new THREE.WebGLRenderer({
        canvas, antialias: false, alpha: false, powerPreference: 'high-performance', failIfMajorPerformanceCaveat: true,
    });
    renderer.setClearColor(new THREE.Color(colors.sky), 1);
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    const pixelRatio = () => Math.min(window.devicePixelRatio || 1, tier.dpr);
    renderer.setPixelRatio(pixelRatio());

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 200);

    // ไม่มี MSAA เมื่อ DPR ≥ 1.75 (จอละเอียดขอบเนียนอยู่แล้ว) — ตัดสินก่อนสร้าง composer
    const samples = pixelRatio() >= 1.75 ? 0 : 4;
    const target = new THREE.WebGLRenderTarget(1, 1, { type: THREE.HalfFloatType, samples });
    const composer = new EffectComposer(renderer, target);
    composer.setPixelRatio(pixelRatio());
    composer.addPass(new RenderPass(scene, camera));
    const bloom = new ScaledBloomPass(new THREE.Vector2(1, 1), 0.6, 0.5, 0.88);
    bloom.enabled = tier.bloom;
    composer.addPass(bloom);
    composer.addPass(new OutputPass());

    const uTime = { value: 0 };
    const disposables = [target];
    const track = (o) => { disposables.push(o); return o; };
    // สร้างก่อนของที่โหลดภาพ — callback ของภาพเรียก pacer.wake() ได้ทุกเมื่อ
    const pacer = new Pacer();
    const billboards = [];   // ของที่หันหน้าเข้ากล้องเสมอ (เปลวเทียนห้องคุณไสย)

    // ───────── ท้องฟ้า + ดวงจันทร์ ─────────
    const sky = new THREE.Mesh(track(new THREE.SphereGeometry(90, 32, 16)), track(new THREE.ShaderMaterial({
        vertexShader: S.SKY_VERT, fragmentShader: S.SKY_FRAG, side: THREE.BackSide, depthWrite: false,
        uniforms: {
            uTime,
            uTop: { value: new THREE.Color(colors.sky).multiplyScalar(0.6) },
            uHorizon: { value: new THREE.Color(colors.sky).lerp(new THREE.Color(colors.a), 0.18) },
            uTint: { value: new THREE.Color(colors.a).lerp(new THREE.Color('#ffffff'), 0.4) },
        },
    })));
    sky.renderOrder = -10;
    scene.add(sky);

    const moonSize = cfg.persona === 'maemor' ? 19 : 14;
    const moonMat = track(new THREE.ShaderMaterial({
        vertexShader: S.QUAD_VERT, fragmentShader: S.MOON_FRAG, transparent: true, depthWrite: false,
        uniforms: { uTime, uAmp: { value: 0 }, uGlow: { value: new THREE.Color(colors.b).lerp(new THREE.Color('#ffffff'), 0.35) }, uMap: { value: null } },
    }));
    const moon = new THREE.Mesh(track(new THREE.PlaneGeometry(1, 1)), moonMat);
    moon.scale.setScalar(moonSize);
    scene.add(moon);

    // ───────── ไพ่ ─────────
    const positions = cfg.cards.map((c) => c.pos);
    const { slots, bounds } = cardLayout(cfg.layout, positions);
    const graph = graphLayout(cfg.graph.points, bounds);
    const stageTop = graph.top;
    const stageBottom = bounds.minY;
    const stageCenterY = (stageTop + stageBottom) / 2;
    const stageW = Math.max(bounds.maxX - bounds.minX, graph.width) + 0.6;
    const stageH = stageTop - stageBottom + 0.5;

    // จันทร์อยู่เหนือขวาของเวที ลึกเข้าไปในฉาก (รายปี: มีจันทร์ดวงเล็กกลางวงล้ออีกดวง)
    moon.position.set(stageW * 0.55 + 9, stageTop + 10, -38);

    const loader = new THREE.TextureLoader();

    // ภาพฉากหลังของห้อง — แผ่นติดกล้อง ไกลกว่าจันทร์ (โหลดไม่ได้ = ใช้ฟ้าวาดเองต่อ)
    const BACKDROP_DIST = 95;
    const backdropMat = track(new THREE.ShaderMaterial({
        vertexShader: S.QUAD_VERT, fragmentShader: S.BACKDROP_FRAG, transparent: true, depthWrite: false,
        uniforms: {
            uMap: { value: null }, uAlpha: { value: 0 }, uAspect: { value: 1 }, uImgAspect: { value: 1.5 },
            uShift: { value: new THREE.Vector2() }, uZoom: { value: 0.92 },
        },
    }));
    const backdrop = new THREE.Mesh(track(new THREE.PlaneGeometry(1, 1)), backdropMat);
    backdrop.position.z = -BACKDROP_DIST;
    backdrop.renderOrder = -9;
    backdrop.frustumCulled = false;
    backdrop.visible = false;
    camera.add(backdrop);
    scene.add(camera);
    if (cfg.art && cfg.art.moon) {
        loader.load(cfg.art.moon, (t) => {
            t.colorSpace = THREE.SRGBColorSpace;
            track(t);
            moonMat.uniforms.uMap.value = t;
            moonMat.defines = { MOON_TEX: '' };
            moonMat.needsUpdate = true;
            pacer.wake();
        }, undefined, () => {});
    }
    if (cfg.art && cfg.art.backdrop) {
        loader.load(cfg.art.backdrop, (t) => {
            t.colorSpace = THREE.SRGBColorSpace;
            track(t);
            backdropMat.uniforms.uMap.value = t;
            backdropMat.uniforms.uImgAspect.value = t.image.width / t.image.height;
            backdrop.visible = true;
            pacer.wake();
        }, undefined, () => {});
    }
    const backTex = drawCardBack(colors);
    track(backTex);
    if (cfg.back) {
        loader.load(cfg.back, (t) => {
            t.colorSpace = THREE.SRGBColorSpace;
            t.anisotropy = 4;
            track(t);
            cards.forEach((c) => { c.backMat.uniforms.uMap.value = t; });
        }, undefined, () => {});
    }

    const cardGeo = track(new THREE.PlaneGeometry(CARD_W, CARD_H));
    const haloGeo = track(new THREE.PlaneGeometry(CARD_W * 1.9, CARD_H * 1.6));
    const rim = new THREE.Color(colors.b);
    const placeholder = track(new THREE.DataTexture(new Uint8Array([40, 24, 80, 255]), 1, 1));
    placeholder.needsUpdate = true;

    const cardMat = (map, reversed) => track(new THREE.ShaderMaterial({
        vertexShader: S.QUAD_VERT, fragmentShader: S.CARD_FRAG, transparent: true,
        defines: reversed ? { REVERSED: '' } : {},
        uniforms: { uMap: { value: map }, uDim: { value: 1 }, uGlow: { value: 0 }, uAlpha: { value: 0 }, uRim: { value: rim } },
    }));

    const cardsGroup = new THREE.Group();
    scene.add(cardsGroup);
    const pickables = [];
    const cards = cfg.cards.map((c, i) => {
        const slot = slots[i];
        const g = new THREE.Group();
        const pivot = new THREE.Group();   // หมุนพลิกรอบแกนตั้ง
        const frontMat = cardMat(placeholder, c.reversed);
        const backMat = cardMat(backTex, false);
        const front = new THREE.Mesh(cardGeo, frontMat);
        const back = new THREE.Mesh(cardGeo, backMat);
        back.rotation.y = Math.PI;
        front.userData.pos = c.pos;
        back.userData.pos = c.pos;
        pickables.push(front, back);
        pivot.add(front, back);
        const haloMat = track(new THREE.ShaderMaterial({
            vertexShader: S.QUAD_VERT, fragmentShader: S.HALO_FRAG, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
            uniforms: { uGlow: { value: 0 }, uColor: { value: rim } },
        }));
        const halo = new THREE.Mesh(haloGeo, haloMat);
        halo.position.z = -0.02;
        g.add(halo, pivot);
        g.rotation.z = slot.rot;
        cardsGroup.add(g);
        if (c.img) {
            loader.load(c.img, (t) => {
                t.colorSpace = THREE.SRGBColorSpace;
                t.anisotropy = 4;
                track(t);
                frontMat.uniforms.uMap.value = t;
            }, undefined, () => {});
        }
        return {
            data: c, slot, g, pivot, frontMat, backMat, haloMat, i,
            flip: 0, lift: 0, dim: 1, deal: 0, wasRevealed: false,
        };
    });

    // รายปี: วงล้อ 12 เดือนรอบดวงจันทร์ดวงเล็กกลางโต๊ะ (ใช้วัสดุเดียวกับจันทร์ใหญ่ — เรืองตามเสียงพูดพร้อมกัน)
    if (cfg.layout === 'wheel') {
        const small = new THREE.Mesh(moon.geometry, moonMat);
        small.scale.setScalar(4.2);
        small.position.set(0, 0, -0.4);
        scene.add(small);
    }

    // ───────── แท่นบูชา ─────────
    const altarR = Math.max(3.2, stageW * 0.62);
    const altar = new THREE.Mesh(track(new THREE.PlaneGeometry(altarR * 2, altarR * 2)), track(new THREE.ShaderMaterial({
        vertexShader: S.QUAD_VERT, fragmentShader: S.ALTAR_FRAG,
        uniforms: {
            uRim: { value: new THREE.Color(colors.b) }, uBase: { value: new THREE.Color(colors.sky).lerp(new THREE.Color('#000000'), 0.35) },
            uMap: { value: placeholder }, uTex: { value: 0 }, uTint: { value: new THREE.Color(colors.a) },
            uRecolor: { value: cfg.persona === 'maemor' ? 0 : 1 },
        },
    })));
    if (cfg.art && cfg.art.altar) {
        loader.load(cfg.art.altar, (t) => {
            t.colorSpace = THREE.SRGBColorSpace;
            t.anisotropy = 8;   // โต๊ะเอียงมากจากมุมกล้อง — ไม่มี anisotropy ลายขอบจะเบลอ
            track(t);
            altar.material.uniforms.uMap.value = t;
            altar.material.uniforms.uTex.value = 1;
            pacer.wake();
        }, undefined, () => {});
    }
    altar.rotation.x = -Math.PI / 2;
    altar.position.set(0, stageBottom - 0.55, 1.2);
    scene.add(altar);

    // ───────── กราฟชีวิต ─────────
    const graphGroup = new THREE.Group();
    scene.add(graphGroup);
    const gPts = graph.points.map((p) => new THREE.Vector3(p.x, p.y, 0.25));
    if (gPts.length === 1) {
        gPts.unshift(gPts[0].clone().add(new THREE.Vector3(-1.2, 0, 0)));
        gPts.push(gPts[gPts.length - 1].clone().add(new THREE.Vector3(1.2, 0, 0)));
    }
    const curve = new THREE.CatmullRomCurve3(gPts, false, 'centripetal');
    const tubeSeg = Math.max(60, graph.points.length * 24);
    const tube = track(new THREE.TubeGeometry(curve, tubeSeg, 0.035, 6, false));
    {
        const toneCol = graph.points.map((p) => new THREE.Color(TONE_COLOR[p.tone] || TONE_COLOR.neutral));
        const n = toneCol.length;
        const cols = new Float32Array(tube.attributes.position.count * 3);
        const uv = tube.attributes.uv;
        const tmp = new THREE.Color();
        for (let k = 0; k < uv.count; k++) {
            const f = uv.getX(k) * (n - 1);
            const a = Math.floor(f);
            tmp.copy(toneCol[Math.min(a, n - 1)]).lerp(toneCol[Math.min(a + 1, n - 1)], f - a);
            cols[k * 3] = tmp.r;
            cols[k * 3 + 1] = tmp.g;
            cols[k * 3 + 2] = tmp.b;
        }
        tube.setAttribute('color', new THREE.BufferAttribute(cols, 3));
    }
    const ribbonMat = track(new THREE.ShaderMaterial({
        vertexShader: S.RIBBON_VERT, fragmentShader: S.RIBBON_FRAG, uniforms: { uTime, uDraw: { value: 0 } },
    }));
    graphGroup.add(new THREE.Mesh(tube, ribbonMat));
    const orbGeo = track(new THREE.SphereGeometry(0.11, 16, 12));
    const orbs = graph.points.map((p, i) => {
        const mat = track(new THREE.MeshBasicMaterial({ color: new THREE.Color(TONE_COLOR[p.tone] || TONE_COLOR.neutral).multiplyScalar(2.2), transparent: true }));
        const m = new THREE.Mesh(orbGeo, mat);
        m.position.set(p.x, p.y, 0.25);
        m.userData.pos = p.pos;
        m.scale.setScalar(0.001);
        graphGroup.add(m);
        pickables.push(m);
        return { m, at: graph.points.length === 1 ? 0.5 : i / (graph.points.length - 1), s: 0 };
    });

    // ───────── วงแหวนฟันธง ─────────
    const ringMat = track(new THREE.ShaderMaterial({
        vertexShader: S.QUAD_VERT, fragmentShader: S.RING_FRAG, transparent: true, depthWrite: false,
        uniforms: { uFill: { value: cfg.graph.ring.fill }, uDraw: { value: 0 }, uColor: { value: new THREE.Color(VERDICT_COLOR[cfg.verdict.tone] || VERDICT_COLOR.gold) } },
    }));
    const ring = new THREE.Mesh(track(new THREE.PlaneGeometry(2.8, 2.8)), ringMat);
    ring.position.set(0, 0, 1.4);
    ring.renderOrder = 5;
    scene.add(ring);

    // ───────── กลุ่มดาวราศี ─────────
    const constel = new THREE.Group();
    constel.position.set(0, stageTop + 5.5, -7);
    scene.add(constel);
    // ตำแหน่งดาวจริง (constellations.js) · ดาวขนาดตามโชติมาตร · เส้นลากต่อเนื่องตามลำดับ มีประกายวิ่งนำหัวเส้น
    // ดาวแต่ละดวงสว่างวาบตอนเส้นมาถึง แล้วกะพริบเบา ๆ — uDraw ตัวเดียวคุมทั้งชุด (0..1)
    const constMat = { stars: null, lines: null, spark: null };
    const constDraw = { value: 0 };
    let constSeg = null;   // [ax, ay, bx, by] ต่อเส้น — หาตำแหน่งหัวเส้นให้ประกาย
    let spark = null;
    const shape = cfg.zodiac ? CONSTELLATIONS[cfg.zodiac.slug] : null;
    if (shape) {
        const scale = 2.6;
        const n = shape.stars.length;
        const sp = new Float32Array(n * 3);
        shape.stars.forEach((s, k) => { sp[k * 3] = s[0] * scale; sp[k * 3 + 1] = s[1] * scale; sp[k * 3 + 2] = 0; });
        const delays = starDelays(shape);
        const sg = track(new THREE.BufferGeometry());
        sg.setAttribute('position', new THREE.BufferAttribute(sp, 3));
        sg.setAttribute('aSize', new THREE.BufferAttribute(Float32Array.from(shape.mag, starSize), 1));
        sg.setAttribute('aDelay', new THREE.BufferAttribute(Float32Array.from(delays), 1));
        sg.setAttribute('aPhase', new THREE.BufferAttribute(Float32Array.from({ length: n }, () => Math.random() * 6.283), 1));
        constMat.stars = track(new THREE.ShaderMaterial({
            vertexShader: S.CONST_STAR_VERT, fragmentShader: S.CONST_STAR_FRAG,
            transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
            uniforms: { uA: { value: 0 }, uTime, uDraw: constDraw, uColor: { value: new THREE.Color('#fff6dc').multiplyScalar(1.6) } },
        }));
        constel.add(new THREE.Points(sg, constMat.stars));

        const L = shape.lines.length;
        const lp = new Float32Array(L * 6);
        const lt = new Float32Array(L * 2);
        constSeg = shape.lines.map(([a, b], k) => {
            lp.set([sp[a * 3], sp[a * 3 + 1], 0, sp[b * 3], sp[b * 3 + 1], 0], k * 6);
            lt.set([k / L, (k + 1) / L], k * 2);
            return [sp[a * 3], sp[a * 3 + 1], sp[b * 3], sp[b * 3 + 1]];
        });
        const lg = track(new THREE.BufferGeometry());
        lg.setAttribute('position', new THREE.BufferAttribute(lp, 3));
        lg.setAttribute('aT', new THREE.BufferAttribute(lt, 1));
        constMat.lines = track(new THREE.ShaderMaterial({
            vertexShader: S.CONST_LINE_VERT, fragmentShader: S.CONST_LINE_FRAG,
            transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
            uniforms: { uA: { value: 0 }, uDraw: constDraw, uColor: { value: new THREE.Color(colors.b).lerp(new THREE.Color('#ffffff'), 0.25).multiplyScalar(1.3) } },
        }));
        constel.add(new THREE.LineSegments(lg, constMat.lines));

        // ประกายหัวเส้น
        const kg = track(new THREE.BufferGeometry());
        kg.setAttribute('position', new THREE.BufferAttribute(new Float32Array(3), 3));
        constMat.spark = track(new THREE.ShaderMaterial({
            vertexShader: `uniform float uA; varying float vAlpha; void main(){ vec4 mv = modelViewMatrix*vec4(position,1.0); gl_Position = projectionMatrix*mv; gl_PointSize = 54.0*(10.0/-mv.z); vAlpha = uA; }`,
            fragmentShader: S.CONST_STAR_FRAG.replace('varying float vTw;', 'const float vTw = 1.0;'),
            transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
            uniforms: { uA: { value: 0 }, uColor: { value: new THREE.Color(colors.b).lerp(new THREE.Color('#ffffff'), 0.6).multiplyScalar(1.8) } },
        }));
        spark = new THREE.Points(kg, constMat.spark);
        spark.frustumCulled = false;
        constel.add(spark);
    }

    // ───────── ฝุ่นแสงจันทร์ ─────────
    const DUST_MAX = 700;
    const dustBox = new THREE.Vector3(stageW * 0.75 + 2, stageH * 0.7 + 2, 4);
    const dustGeo = track(new THREE.BufferGeometry());
    {
        const p = new Float32Array(DUST_MAX * 3);
        const s = new Float32Array(DUST_MAX);
        for (let k = 0; k < DUST_MAX; k++) {
            p[k * 3] = (Math.random() * 2 - 1) * dustBox.x;
            p[k * 3 + 1] = (Math.random() * 2 - 1) * dustBox.y;
            p[k * 3 + 2] = (Math.random() * 2 - 1) * dustBox.z;
            s[k] = Math.random();
        }
        dustGeo.setAttribute('position', new THREE.BufferAttribute(p, 3));
        dustGeo.setAttribute('aSeed', new THREE.BufferAttribute(s, 1));
    }
    const dust = new THREE.Points(dustGeo, track(new THREE.ShaderMaterial({
        vertexShader: S.DUST_VERT, fragmentShader: S.POINT_FRAG, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
        uniforms: { uTime, uSize: { value: 5 }, uBox: { value: dustBox }, uColor: { value: new THREE.Color(colors.b).lerp(new THREE.Color('#ffffff'), 0.5) } },
    })));
    dust.position.y = stageCenterY;
    scene.add(dust);
    const applyDust = () => dustGeo.setDrawRange(0, Math.floor(DUST_MAX * tier.dust));
    applyDust();

    // ───────── ประกายตอนพลิกไพ่ ─────────
    const BURST = 90;
    const burstGeo = track(new THREE.BufferGeometry());
    {
        const d = new Float32Array(BURST * 3);
        for (let k = 0; k < BURST; k++) {
            const v = new THREE.Vector3().randomDirection().multiplyScalar(0.4 + Math.random() * 0.8);
            v.z = Math.abs(v.z) * 0.6;
            d.set([v.x, v.y, v.z], k * 3);
        }
        burstGeo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(BURST * 3), 3));
        burstGeo.setAttribute('aDir', new THREE.BufferAttribute(d, 3));
    }
    const burstMat = track(new THREE.ShaderMaterial({
        vertexShader: S.BURST_VERT, fragmentShader: S.POINT_FRAG, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
        uniforms: { uTime, uStart: { value: -100 }, uOrigin: { value: new THREE.Vector3() }, uColor: { value: new THREE.Color(colors.b).multiplyScalar(1.5) } },
    }));
    const burst = new THREE.Points(burstGeo, burstMat);
    burst.frustumCulled = false;
    scene.add(burst);

    // ───────── ของประกอบตามห้อง ─────────
    const propBase = new THREE.Vector3(0, stageBottom - 0.55, 1.2);
    if (cfg.persona === 'kunsai') {
        // เทียน 2 เล่ม + ธูปมีควัน
        const waxMat = track(new THREE.MeshBasicMaterial({ color: new THREE.Color('#3a0a12') }));
        const waxGeo = track(new THREE.CylinderGeometry(0.16, 0.18, 0.9, 16));
        const flameGeo = track(new THREE.PlaneGeometry(0.5, 0.8));
        [-1, 1].forEach((side, k) => {
            const x = side * (altarR * 0.62);
            const wax = new THREE.Mesh(waxGeo, waxMat);
            wax.position.set(x, propBase.y + 0.45, propBase.z + 0.3);
            scene.add(wax);
            const flame = new THREE.Mesh(flameGeo, track(new THREE.ShaderMaterial({
                vertexShader: S.QUAD_VERT, fragmentShader: S.FLAME_FRAG, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
                uniforms: { uTime, uSeed: { value: k * 3.7 } },
            })));
            flame.position.set(x, propBase.y + 1.18, propBase.z + 0.3);
            billboards.push(flame);
            scene.add(flame);
        });
        const stick = new THREE.Mesh(track(new THREE.CylinderGeometry(0.015, 0.015, 1.1, 6)), track(new THREE.MeshBasicMaterial({ color: new THREE.Color('#7a2a1a') })));
        stick.position.set(-altarR * 0.32, propBase.y + 0.55, propBase.z + 0.6);
        scene.add(stick);
        const SM = 70;
        const sg = track(new THREE.BufferGeometry());
        const seeds = new Float32Array(SM);
        for (let k = 0; k < SM; k++) seeds[k] = k / SM;
        sg.setAttribute('position', new THREE.BufferAttribute(new Float32Array(SM * 3), 3));
        sg.setAttribute('aSeed', new THREE.BufferAttribute(seeds, 1));
        const smoke = new THREE.Points(sg, track(new THREE.ShaderMaterial({
            vertexShader: S.SMOKE_VERT, fragmentShader: S.POINT_FRAG, transparent: true, depthWrite: false,
            uniforms: { uTime, uOrigin: { value: new THREE.Vector3(stick.position.x, propBase.y + 1.1, stick.position.z) }, uColor: { value: new THREE.Color('#c9b8c8') } },
        })));
        smoke.frustumCulled = false;
        scene.add(smoke);
    } else if (cfg.persona === 'busaba') {
        // กลีบบัวร่วงช้า ๆ
        const N = 34;
        const pg = track(new THREE.PlaneGeometry(0.22, 0.36));
        const mat = track(new THREE.ShaderMaterial({
            vertexShader: S.PETAL_VERT, fragmentShader: S.PETAL_FRAG, transparent: true, depthWrite: false, side: THREE.DoubleSide,
            uniforms: { uTime, uBox: { value: new THREE.Vector3(stageW * 0.7 + 1.5, stageH * 0.7 + 2, 2.5) }, uColor: { value: new THREE.Color('#ff86b4') } },
        }));
        const inst = new THREE.InstancedMesh(pg, mat, N);
        const seeds = new Float32Array(N * 4);
        for (let k = 0; k < N * 4; k++) seeds[k] = Math.random();
        pg.setAttribute('aSeed', new THREE.InstancedBufferAttribute(seeds, 4));
        const id = new THREE.Matrix4();
        for (let k = 0; k < N; k++) inst.setMatrixAt(k, id);
        inst.frustumCulled = false;
        inst.position.y = stageCenterY;
        scene.add(inst);
    }

    // ───────── ป้าย HTML ─────────
    const mkLabel = (cls, text) => {
        const el = document.createElement('div');
        el.className = 'rt-label ' + cls;
        el.textContent = text;
        el.style.opacity = '0';
        labels.appendChild(el);
        return el;
    };
    const cardLabel = mkLabel('rt-label-card', '');
    const verdictLabel = mkLabel('rt-label-verdict', cfg.verdict.label || 'สรุปดวง');
    if (cfg.graph.ring.caption) {
        const cap = document.createElement('small');
        cap.textContent = cfg.graph.ring.caption;
        verdictLabel.appendChild(cap);
    }
    const zodiacLabel = cfg.zodiac ? mkLabel('rt-label-zodiac', `${cfg.zodiac.glyph} ${cfg.zodiac.name}`) : null;
    const showPointLabels = cfg.graph.kind === 'year' || graph.points.length <= 5;
    const pointLabels = showPointLabels
        ? graph.points.map((p) => mkLabel('rt-label-point', cfg.graph.kind === 'year' ? p.label.split(' ')[0] : p.label))
        : [];

    // ───────── กล้อง ─────────
    const camTarget = new THREE.Vector3(0, stageCenterY, 0);
    const camPos = new THREE.Vector3(0, stageCenterY + 2, 20);
    const want = { target: new THREE.Vector3(), dist: 14 };
    const parallax = new THREE.Vector2();
    const parallaxTarget = new THREE.Vector2();
    const dir = new THREE.Vector3(0, 0.16, 1).normalize();
    let width = 1;
    let height = 1;
    let safe = { top: 0, bottom: 0 };

    const fitDist = (w, h) => {
        const effH = Math.max(0.35, (height - safe.top - safe.bottom) / height);
        const t = Math.tan(THREE.MathUtils.degToRad(camera.fov / 2));
        return Math.max((h / 2) / (t * effH), (w / 2) / (t * camera.aspect)) * 1.06;
    };

    const resize = () => {
        width = Math.max(1, host.clientWidth);
        height = Math.max(1, host.clientHeight);
        safe = hooks.safe ? hooks.safe() : safe;
        camera.aspect = width / height;
        // เลื่อนกรอบภาพให้ศูนย์กลางเวทีอยู่กลางพื้นที่ที่ไม่ถูกแผงคำบรรยาย/แถบบนบัง
        camera.setViewOffset(width, height, 0, (safe.bottom - safe.top) / 2, width, height);
        camera.updateProjectionMatrix();
        // ฉากหลังเต็มกรอบกล้องที่ระยะ BACKDROP_DIST (+เผื่อขอบที่ view offset เลื่อนไป)
        const bh = 2 * BACKDROP_DIST * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * (1 + Math.abs(safe.bottom - safe.top) / height);
        backdrop.scale.set(bh * camera.aspect, bh, 1);
        backdropMat.uniforms.uAspect.value = camera.aspect;
        renderer.setSize(width, height, false);
        composer.setSize(width, height);
    };
    const ro = new ResizeObserver(() => { resize(); pacer.wake(); });
    ro.observe(host);

    // ───────── วนวาด ─────────
    const applyTier = () => {
        tier = TIERS[tierIndex];
        renderer.setPixelRatio(pixelRatio());
        composer.setPixelRatio(pixelRatio());
        bloom.enabled = tier.bloom;
        applyDust();
        resize();
    };
    const governor = new Governor(() => {
        if (tierIndex >= TIERS.length - 1) {
            hooks.onFallback('slow');
            return;
        }
        tierIndex++;
        applyTier();
    });

    const v3 = new THREE.Vector3();
    const proj = (obj, out) => {
        obj.getWorldPosition(v3);
        v3.project(camera);
        out.x = (v3.x * 0.5 + 0.5) * width;
        out.y = (-v3.y * 0.5 + 0.5) * height;
        out.vis = v3.z < 1;
        return out;
    };
    const place = (el, p, alpha) => {
        el.style.transform = `translate3d(${p.x.toFixed(1)}px, ${p.y.toFixed(1)}px, 0) translate(-50%, -50%)`;
        el.style.opacity = String(p.vis ? alpha : 0);
    };

    let raf = 0;
    let lastDraw = performance.now();
    let elapsed = 0;
    let running = false;
    let lost = false;
    const tmpP = { x: 0, y: 0, vis: true };
    const anchor = new THREE.Object3D();
    scene.add(anchor);

    const frame = (now) => {
        raf = requestAnimationFrame(frame);
        if (!pacer.tick(now)) {
            return;
        }
        // timestamp ของ rAF เฟรมแรกมาก่อน performance.now() ที่จับไว้ได้ — ห้าม dt ติดลบ (damp จะพุ่งเลยเป้า)
        const realDt = Math.max(0, now - lastDraw);
        lastDraw = now;
        governor.sample(realDt, (1000 / pacer.hz) * pacer.every, pacer.idle, now);
        const dt = Math.min(0.05, realDt / 1000);
        elapsed += dt;
        uTime.value = elapsed;
        update(dt);
        composer.render(dt);
        drawn++;
    };
    let drawn = 0;   // นับเฟรมที่วาดจริง — ให้ QA วัดจังหวะ (info().drawn)

    let moving = true;
    let lastDealt = null;
    let dealAt = 0;
    const goalV = new THREE.Vector3();
    const update = (dt) => {
        const st = hooks.getState();
        if (!st) {
            return;
        }
        moving = false;
        const settle = (a, b, eps = 0.002) => { if (Math.abs(a - b) > eps) moving = true; };

        // ไพ่
        let current = null;
        if (st.dealt !== lastDealt) {
            lastDealt = st.dealt;
            dealAt = elapsed;
        }
        cards.forEach((c) => {
            const isCur = st.currentPos === c.data.pos;
            const revealed = !!st.revealed[c.data.pos];
            if (revealed && !c.wasRevealed && st.playing) {
                // พลิกเปิดครั้งแรกระหว่างเล่นไปข้างหน้า → ประกายแสง
                c.g.getWorldPosition(burstMat.uniforms.uOrigin.value);
                burstMat.uniforms.uStart.value = elapsed + 0.25;
            }
            c.wasRevealed = revealed;
            const tFlip = revealed ? 1 : 0;
            const tLift = isCur ? 1 : 0;
            const tDim = st.currentPos != null && !isCur ? 0.42 : (st.ring ? 0.6 : 1);
            const tDeal = st.dealt ? 1 : 0;
            settle(c.flip, tFlip); settle(c.lift, tLift); settle(c.dim, tDim); settle(c.deal, tDeal);
            c.flip = damp(c.flip, tFlip, 5, dt);
            c.lift = damp(c.lift, tLift, 4.2, dt);
            c.dim = damp(c.dim, tDim, 4, dt);
            // แจกไพ่ทีละใบ (หน่วงตามลำดับ) ตอนไพ่ลงโต๊ะ
            if (elapsed > dealAt + c.i * 0.08 || tDeal < c.deal) {
                c.deal = damp(c.deal, tDeal, 3, dt);
            }

            const s = c.slot;
            const ls = s.scale * (1 + c.lift * (cfg.layout === 'wheel' ? 0.75 : 0.22));
            c.g.position.set(s.x * (1 - c.lift * 0.08), s.y + c.lift * 0.18 + (1 - c.deal) * 7, s.z + c.lift * 1.1);
            c.g.scale.setScalar(ls);
            // ใบที่วางขวาง (เซลติกใบที่ 2) หมุนกลับมาตั้งตรงตอนเป็นใบที่กำลังพูด ให้อ่านง่าย
            c.g.rotation.z = s.rot * (1 - c.lift);
            c.pivot.rotation.y = Math.PI * (1 - c.flip);
            c.pivot.rotation.x = Math.sin(c.flip * Math.PI) * -0.25;
            const a = c.deal;
            c.frontMat.uniforms.uAlpha.value = a;
            c.backMat.uniforms.uAlpha.value = a;
            c.frontMat.uniforms.uDim.value = c.dim;
            c.backMat.uniforms.uDim.value = c.dim * 0.9;
            c.frontMat.uniforms.uGlow.value = c.lift;
            c.backMat.uniforms.uGlow.value = c.lift * 0.5;
            c.haloMat.uniforms.uGlow.value = c.lift * 0.9 * a;
            c.g.renderOrder = isCur ? 3 : 1;
            if (isCur) {
                current = c;
            }
        });

        // กราฟ
        const gd = ribbonMat.uniforms.uDraw.value;
        settle(gd, st.graphDraw);
        ribbonMat.uniforms.uDraw.value = damp(gd, st.graphDraw, 3.5, dt);
        orbs.forEach((o, k) => {
            const on = ribbonMat.uniforms.uDraw.value >= o.at - 0.001 ? 1 : 0;
            const pulse = st.currentPos === o.m.userData.pos ? 1.7 + Math.sin(elapsed * 5) * 0.35 : 1;
            o.s = damp(o.s, on * pulse, 6, dt);
            o.m.scale.setScalar(Math.max(0.001, o.s));
            if (pointLabels[k]) {
                place(pointLabels[k], proj(o.m, tmpP), on * (st.sky ? 0 : 0.9));
            }
        });

        // วงแหวน
        const rd = ringMat.uniforms.uDraw.value;
        const tRing = st.ring ? 1 : 0;
        settle(rd, tRing);
        ringMat.uniforms.uDraw.value = damp(rd, tRing, 2.4, dt);
        ring.visible = ringMat.uniforms.uDraw.value > 0.01;
        ring.position.y = (bounds.minY + bounds.maxY) / 2;
        place(verdictLabel, proj(ring, tmpP), ringMat.uniforms.uDraw.value);

        // กลุ่มดาว
        if (constMat.stars) {
            const tA = st.sky ? 1 : 0;
            settle(constMat.stars.uniforms.uA.value, tA);
            const a = damp(constMat.stars.uniforms.uA.value, tA, 2, dt);
            constMat.stars.uniforms.uA.value = a;
            constMat.lines.uniforms.uA.value = a * 0.9;
            // ลากเส้นตามเวลาที่เล่าบทพื้นดวง (จบราว 55% ของบท) — นอกบทนี้ค้างเต็มรูปไว้ตอนจางออก
            const tD = st.sky ? Math.min(1, st.progress * 1.8 + 0.04) : 1;
            settle(constDraw.value, tD, 0.001);
            constDraw.value = damp(constDraw.value, tD, 3.2, dt);
            // ประกายวิ่งนำหัวเส้น จางเมื่อลากครบ
            const L = constSeg.length;
            const head = Math.min(L - 1e-4, Math.max(0, constDraw.value * L));
            const seg = constSeg[Math.floor(head)];
            const f = head - Math.floor(head);
            spark.geometry.attributes.position.setXYZ(0, seg[0] + (seg[2] - seg[0]) * f, seg[1] + (seg[3] - seg[1]) * f, 0.02);
            spark.geometry.attributes.position.needsUpdate = true;
            constMat.spark.uniforms.uA.value = a * (1 - THREE.MathUtils.smoothstep(constDraw.value, 0.97, 1));
            if (st.sky && constDraw.value < 0.999) moving = true;
            if (zodiacLabel) {
                anchor.position.set(constel.position.x, constel.position.y - 2.2, constel.position.z);
                place(zodiacLabel, proj(anchor, tmpP), constMat.stars.uniforms.uA.value);
            }
        }

        // จันทร์เรืองตามเสียงพูด
        moonMat.uniforms.uAmp.value = hooks.amplitude ? hooks.amplitude() : 0;

        // ป้ายใบที่กำลังพูด
        if (current) {
            anchor.position.copy(current.g.position);
            anchor.position.y += (CARD_H / 2) * current.g.scale.x + 0.28;
            cardLabel.textContent = current.data.label;
            place(cardLabel, proj(anchor, tmpP), current.lift);
        } else {
            cardLabel.style.opacity = '0';
        }

        // กล้อง — ไหลช้า ๆ หาเป้า ไม่หมุนเหวี่ยง
        const overviewDist = fitDist(stageW, stageH);
        if (st.sky && shape) {
            want.target.set(constel.position.x, constel.position.y - 0.6, constel.position.z);
            want.dist = fitDist(7.5, 7.5);
        } else if (st.kind === 'graph') {
            want.target.set(0, (graph.baseY + graph.top) / 2 - 0.6, 0);
            want.dist = Math.min(overviewDist, fitDist(graph.width + 1.2, 4.2));
        } else if (current) {
            want.target.set(
                THREE.MathUtils.lerp(0, current.g.position.x, 0.6),
                THREE.MathUtils.lerp(stageCenterY, current.g.position.y, 0.6),
                0,
            );
            want.dist = THREE.MathUtils.lerp(overviewDist, fitDist(3.2, 3.6), 0.5);
        } else if (st.ring) {
            // บทสรุป/คุยต่อ: วงแหวนอยู่กลางโต๊ะ — ตั้งกล้องที่โต๊ะ ไม่นับกราฟ (กันวงแหวนจมใต้แผงคำบรรยายบนมือถือ)
            want.target.set(0, (bounds.minY + bounds.maxY) / 2, 0);
            want.dist = fitDist(bounds.maxX - bounds.minX + 0.6, bounds.maxY - bounds.minY + 0.6);
        } else {
            want.target.set(0, stageCenterY, 0);
            want.dist = overviewDist;
        }
        parallax.x = damp(parallax.x, parallaxTarget.x, 2, dt);
        parallax.y = damp(parallax.y, parallaxTarget.y, 2, dt);
        const goal = goalV.copy(dir).multiplyScalar(want.dist).add(want.target);
        goal.x += parallax.x * 0.5;
        goal.y += parallax.y * 0.3;
        settle(camPos.distanceTo(goal), 0, 0.01);
        camPos.x = damp(camPos.x, goal.x, 1.6, dt);
        camPos.y = damp(camPos.y, goal.y, 1.6, dt);
        camPos.z = damp(camPos.z, goal.z, 1.6, dt);
        camTarget.x = damp(camTarget.x, want.target.x, 1.6, dt);
        camTarget.y = damp(camTarget.y, want.target.y, 1.6, dt);
        camTarget.z = damp(camTarget.z, want.target.z, 1.6, dt);
        camera.position.copy(camPos);
        camera.lookAt(camTarget);
        moon.lookAt(camera.position);
        if (backdrop.visible) {
            // ค่อย ๆ ปรากฏแทนฟ้าวาดเอง · เลื่อน/ซูมตามกล้องแค่นิดเดียว = อยู่ไกลมาก
            const bu = backdropMat.uniforms;
            settle(bu.uAlpha.value, 1, 0.01);
            bu.uAlpha.value = damp(bu.uAlpha.value, 1, 1.4, dt);
            sky.visible = bu.uAlpha.value < 0.99;
            bu.uShift.value.set(camTarget.x * 0.004 + parallax.x * 0.012, (camTarget.y - stageCenterY) * 0.004 + parallax.y * 0.008);
            bu.uZoom.value = 0.84 + 0.1 * THREE.MathUtils.clamp(camPos.distanceTo(camTarget) / overviewDist, 0.4, 1.2);
        }
        billboards.forEach((o) => o.quaternion.copy(camera.quaternion));

        // มีอะไรกำลังขยับ / ประกายยังไม่จบ / กำลังลากกราฟ = ยังไม่นิ่ง (ไม่ลดเหลือ 30 fps)
        if (moving || elapsed - burstMat.uniforms.uStart.value < 1.5 || (st.kind === 'graph' && st.playing)) {
            pacer.wake();
        }
    };

    const start = () => {
        if (running || lost) {
            return;
        }
        running = true;
        lastDraw = performance.now();
        raf = requestAnimationFrame(frame);
    };
    const stop = () => {
        running = false;
        cancelAnimationFrame(raf);
    };
    // แท็บถูกซ่อน = หยุดวาด
    const onVis = () => (document.hidden ? stop() : start());
    document.addEventListener('visibilitychange', onVis);
    const onPointer = (e) => {
        pacer.wake();
        if (e.type === 'pointermove' && e.pointerType === 'mouse') {
            parallaxTarget.set((e.clientX / width - 0.5) * 2, -(e.clientY / height - 0.5) * 2);
        }
    };
    host.addEventListener('pointermove', onPointer, { passive: true });
    host.addEventListener('pointerdown', onPointer, { passive: true });
    canvas.addEventListener('webglcontextlost', (e) => {
        e.preventDefault();
        lost = true;
        stop();
        hooks.onFallback('context-lost');
    });

    resize();
    // คอมไพล์ shader กับเป้าของ composer (ไม่ใช่ canvas) — ไม่งั้นได้โปรแกรมผิดรุ่นแล้วคอมไพล์ซ้ำกลางฉาก
    renderer.setRenderTarget(composer.readBuffer);
    try {
        await renderer.compileAsync(scene, camera);
    } finally {
        renderer.setRenderTarget(null);
    }
    start();

    return {
        mode: 'gl',
        wake: () => pacer.wake(),
        resize,
        info: () => ({ tier: tierIndex, hz: Math.round(pacer.hz), every: pacer.every, idle: pacer.idle, bloom: bloom.enabled, msaa: samples, drawn }),
        /** แตะจอ → ไพ่/จุดบนกราฟที่โดน (ตำแหน่งไพ่) หรือ null */
        pick(clientX, clientY) {
            const r = canvas.getBoundingClientRect();
            const ndc = new THREE.Vector2(((clientX - r.left) / r.width) * 2 - 1, -((clientY - r.top) / r.height) * 2 + 1);
            const ray = new THREE.Raycaster();
            ray.setFromCamera(ndc, camera);
            const hit = ray.intersectObjects(pickables, false).find((h) => h.object.visible && (h.object.scale.x > 0.01));
            return hit ? hit.object.userData.pos : null;
        },
        dispose() {
            stop();
            ro.disconnect();
            document.removeEventListener('visibilitychange', onVis);
            host.removeEventListener('pointermove', onPointer);
            host.removeEventListener('pointerdown', onPointer);
            disposables.forEach((d) => d.dispose && d.dispose());
            composer.dispose();
            renderer.dispose();
            renderer.forceContextLoss();
            canvas.remove();
            labels.remove();
        },
    };
}
