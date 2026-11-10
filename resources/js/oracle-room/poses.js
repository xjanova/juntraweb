// 🎭 อิริยาบถของผู้พยากรณ์ (2026-11-07) — เจ้าของสั่ง "ทำให้แม่หมอมีอิริยาบถหลากหลาย ดูมีชีวิตมากขึ้น"
//
// ภาพ sprite (พื้นใส) ที่ยืนหลังโต๊ะยังเป็นฐานเสมอ — คลิปพื้นใส (WebM VP9 alpha) วางทับเมื่อพร้อม
//   เจ้าของ (2026-11-07): "มือวางบนโต๊ะ กระพริบตา ขยับศีรษะเล็กน้อย เหมือนรอเรา ไม่ต้องดิ้นเยอะ"
//   → ทุกท่าเป็นการขยับเบา ๆ จากภาพนิ่งมือวางบนโต๊ะ (OracleMedia::poses()['still'] แทน sprite เดิม)
//   - ทุกคลิปเริ่มและจบที่ภาพ sprite (ต่อไป-กลับ ping-pong) → สลับคลิปตอนจบได้โดยไม่กระตุก
//   - แต่ละคลิปมี "อารมณ์" (mood) ที่ใช้ได้: idle · listen · think · talk · reveal · summary
//     ระหว่างรอ = สุ่มท่า idle ไม่ซ้ำท่าเดิม · มีสถานะใหม่กลางคลิป = เร่งคลิปเดิมกลับท่าตั้งต้นก่อน แล้วค่อยเปลี่ยน
//     (คลิป ping-pong สมมาตร: เฟรมที่เวลา t = เฟรมที่เวลา T−t → กระโดดไปฝั่งขากลับได้โดยภาพไม่เปลี่ยน)
//   - โหลดทีละคลิปเมื่อต้องใช้ (preload=none) · เล่นเฉพาะตอนเห็นบนจอ · แท็บซ่อน = หยุด
//   - ไม่เล่นคลิปเลย (เหลือภาพนิ่ง + CSS หายใจ) เมื่อ: ตั้งลดการเคลื่อนไหว / ประหยัดเน็ต / เน็ต 2g-3g /
//     Safari และทุกเบราว์เซอร์บน iPhone-iPad (WebKit วาด VP9 alpha เป็นพื้นดำ) / ไฟล์โหลดไม่ขึ้น
//
// ข้อมูลคลิปมาจาก App\Support\OracleMedia::poses() — { scale, still, clips: [{ name, url, moods, pp }] }
//   scale = ความสูงกล่องคลิปเทียบกับความสูงภาพ sprite (กล่องคลิปมีที่ว่างเหนือหัวและสองข้างไว้ให้ขยับ)

// ลำดับความสำคัญ — สถานะที่อยู่ซ้ายชนะ (กำลังคิดสำคัญกว่าลูกค้ากำลังพิมพ์)
const PRIORITY = ['think', 'talk', 'reveal', 'listen'];
// น้ำหนักการสุ่มของท่า "รอ" (ชื่อท่า → น้ำหนัก ไม่ระบุ = 1) — แม่หมอเหลือท่ารอท่าเดียว (นั่งนิ่ง หายใจ กะพริบตา)
const IDLE_WEIGHT = { wait: 3 };
// 🕯️ (2026-11-10) เจ้าของ: "ให้ดูสุขุม ไม่ใช่เต้น ดุกดิ๊กดูไม่ขลังเลย"
//   เล่นช้ากว่าจริง · เร่งกลับท่าตั้งต้นแค่นิดเดียว (เดิม 1.8 เท่า = สะบัดหัวกลับ) · ส่วนใหญ่พักนิ่งที่ภาพตั้งต้น
const PLAY_RATE = 0.72;
const HURRY_RATE = 1.15;
const REST_CHANCE = 0.7;               // จบท่ารอแล้ว พักนิ่งกี่เปอร์เซ็นต์ของครั้ง
const REST_MS = [5000, 12000];         // พักนาน 5–12 วิ

/** เครื่องนี้เล่นคลิปพื้นใสได้ไหม (และควรเล่นไหม) */
export function posesSupported() {
    try {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
        const c = navigator.connection;
        if (c && (c.saveData || /(^|-)(2g|3g)$/.test(c.effectiveType || ''))) return false;
        const ua = navigator.userAgent || '';
        // iPhone/iPad (ทุกเบราว์เซอร์เป็น WebKit) และ Safari บน Mac — VP9 alpha ขึ้นพื้นดำ
        if (/iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) return false;
        if (/AppleWebKit/.test(ua) && !/Chrome|Chromium|Edg|OPR|Firefox|Android/.test(ua)) return false;
        return document.createElement('video').canPlayType('video/webm; codecs="vp9"') !== '';
    } catch (_) {
        return false;
    }
}

/**
 * ติดคลิปอิริยาบถให้กล่องผู้พยากรณ์ (.or-actor / .ct-actor ที่มี <img> sprite อยู่ข้างใน)
 * คืนตัวควบคุม: base(mood) · flag(mood, on, ms?) · pulse(mood) · active(on) · destroy()
 * เครื่องที่เล่นไม่ได้ / ไม่มีคลิป = ตัวควบคุมเปล่า (เรียกได้ทุกเมธอด ไม่มีผลอะไร)
 */
export function attachPoses(actor, data) {
    const noop = { base() {}, flag() {}, pulse() {}, active() {}, destroy() {}, live: false };
    const clips = (data && Array.isArray(data.clips) ? data.clips : []).filter((c) => c && c.url && Array.isArray(c.moods));
    const img = actor && actor.querySelector('img');
    if (!actor || !img || !clips.length || !posesSupported()) return noop;

    const scale = Number(data.scale) > 0 ? Number(data.scale) : 1;
    const vids = [0, 1].map(() => {
        const v = document.createElement('video');
        v.className = 'oracle-pose';
        v.muted = true; v.defaultMuted = true; v.playsInline = true; v.preload = 'none';
        v.disablePictureInPicture = true;
        v.setAttribute('muted', ''); v.setAttribute('playsinline', ''); v.setAttribute('aria-hidden', 'true'); v.tabIndex = -1;
        v.style.height = `${(scale * 100).toFixed(2)}%`;
        v.defaultPlaybackRate = PLAY_RATE;
        actor.appendChild(v);
        return v;
    });

    const st = {
        base: 'idle', flags: new Map(), pulses: [], front: null, cur: null,
        broken: new Set(), visible: true, enabled: true, hurrying: false, rest: 0, dead: false,
    };
    const byName = new Map(clips.map((c) => [c.name, c]));

    // ───────── เลือกท่า ─────────
    function mood() {
        const now = Date.now();
        for (const m of PRIORITY) {
            const until = st.flags.get(m);
            if (until && until > now) return m;
            if (until) st.flags.delete(m);
        }
        return st.pulses.length ? st.pulses[0] : st.base;
    }
    function pool(m) {
        const ok = clips.filter((c) => !st.broken.has(c.name));
        const hit = ok.filter((c) => c.moods.includes(m));
        return hit.length ? hit : ok.filter((c) => c.moods.includes('idle'));
    }
    function pick(m) {
        const list = pool(m);
        if (!list.length) return null;
        // ไม่ซ้ำท่าเดิมติดกัน (ถ้ามีให้เลือกมากกว่า 1)
        const rest = list.length > 1 ? list.filter((c) => !st.cur || c.name !== st.cur.name) : list;
        const w = rest.map((c) => (m === 'idle' ? (IDLE_WEIGHT[c.name] || 1) : 1));
        let r = Math.random() * w.reduce((a, b) => a + b, 0);
        for (let i = 0; i < rest.length; i++) { r -= w[i]; if (r <= 0) return rest[i]; }
        return rest[rest.length - 1];
    }

    // ───────── เล่น ─────────
    const back = () => (st.front === vids[0] ? vids[1] : vids[0]);
    function load(v, clip) {
        if (v.dataset.clip === clip.name) return;
        v.dataset.clip = clip.name;
        v.src = clip.url;
        v.preload = 'auto';
        v.load();
    }
    function show(v) {
        v.classList.add('is-on');
        vids.forEach((o) => { if (o !== v) o.classList.remove('is-on'); });
        actor.classList.add('has-pose');
    }
    function hideAll() {
        vids.forEach((o) => o.classList.remove('is-on'));
        actor.classList.remove('has-pose');
    }
    function canRun() { return st.enabled && st.visible && !document.hidden && !st.dead; }

    function start() {
        clearTimeout(st.rest); st.rest = 0;
        if (!canRun()) return;
        const m = mood();
        // คลิปที่โหลดรอไว้ในอีกตัวยังเข้ากับสถานะตอนนี้ = ใช้เลย (ไม่สุ่มใหม่ให้เปลืองเน็ต)
        const list = pool(m);
        const pre = byName.get(back().dataset.clip || '');
        const fits = pre && list.includes(pre) && (!st.cur || pre.name !== st.cur.name || list.length === 1);
        const clip = fits ? pre : pick(m);
        if (!clip) { hideAll(); return; }
        if (st.pulses.length && st.pulses[0] === m) st.pulses.shift();
        const v = back().dataset.clip === clip.name ? back() : (st.front || vids[0]);
        st.front = v; st.cur = clip; st.hurrying = false;
        load(v, clip);
        v.playbackRate = PLAY_RATE;
        try { v.currentTime = 0; } catch (_) { /* ยังไม่มีข้อมูล — เริ่มที่ 0 อยู่แล้ว */ }
        const p = v.play();
        if (p && p.catch) {
            // AbortError = ถูกหยุดทันทีหลังสั่งเล่น (ปัดผ่าน/สลับแท็บ) ไม่ใช่ไฟล์พัง
            p.catch((e) => { if (e && e.name !== 'AbortError' && e.name !== 'NotAllowedError') fail(v); });
        }
    }
    function onPlaying(e) {
        const v = e.currentTarget;
        if (v !== st.front) return;
        show(v);
        // โหลดท่าถัดไปรอไว้ในอีกตัว (เดาจากสถานะตอนนี้) — จบแล้วสลับได้ทันที
        //   (เล่นต่อหลังหยุดก็ยิง playing อีก — ตัวที่รอไว้ยังเข้ากับสถานะอยู่ก็ไม่โหลดใหม่)
        const b = back();
        const held = byName.get(b.dataset.clip || '');
        if (!held || held === st.cur || !pool(mood()).includes(held)) {
            const nx = pick(mood());
            if (nx) load(b, nx);
        }
    }
    function onEnded(e) {
        const v = e.currentTarget;
        if (v !== st.front) return;
        st.hurrying = false;
        // ท่ารอ: ส่วนใหญ่พักนิ่งที่ภาพตั้งต้นนาน ๆ (CSS หายใจทำงานต่อ) — นิ่งสุขุม ไม่ขยับตลอดเวลาแบบหุ่น
        const m = mood();
        if (m === 'idle' && !st.pulses.length && Math.random() < REST_CHANCE) {
            hideAll();
            st.rest = setTimeout(start, REST_MS[0] + Math.random() * (REST_MS[1] - REST_MS[0]));
            return;
        }
        start();
    }
    function fail(v) {
        if (v.dataset.clip) st.broken.add(v.dataset.clip);
        delete v.dataset.clip;
        v.removeAttribute('src');
        v.classList.remove('is-on');
        if (v === st.front) {
            st.front = null; st.cur = null;
            if (!vids.some((o) => o.classList.contains('is-on'))) actor.classList.remove('has-pose');
            if (clips.some((c) => !st.broken.has(c.name))) st.rest = setTimeout(start, 400);
        }
    }
    vids.forEach((v) => {
        v.addEventListener('playing', onPlaying);
        v.addEventListener('ended', onEnded);
        v.addEventListener('error', () => fail(v));
    });

    // มีสถานะใหม่ → ถ้าท่าที่เล่นอยู่ไม่ใช่ของสถานะนั้น เร่งกลับท่าตั้งต้น (จบคลิปเร็วขึ้น แล้ว onEnded เลือกท่าใหม่)
    function changed() {
        if (st.dead) return;
        const m = mood();
        const v = st.front;
        if (!v || v.paused || v.ended || !st.cur) { if (!st.rest || m !== 'idle') start(); return; }
        if (st.cur.moods.includes(m) || st.hurrying) return;
        st.hurrying = true;
        const T = v.duration;
        if (st.cur.pp && Number.isFinite(T) && T > 0 && v.currentTime < T / 2) {
            try { v.currentTime = T - v.currentTime; } catch (_) { /* seek ไม่ได้ก็แค่เร่งต่อจากเดิม */ }
        }
        v.playbackRate = HURRY_RATE;
    }

    function pause() { vids.forEach((v) => v.pause()); clearTimeout(st.rest); st.rest = 0; }
    function resume() {
        if (!canRun()) return;
        if (st.front && st.front.dataset.clip && !st.front.ended && st.front.currentTime > 0) {
            const p = st.front.play();
            if (p && p.catch) p.catch(() => {});
        } else start();
    }

    // เล่นเฉพาะตอนเห็น — มือถือที่กางแชทเต็มจอทับผู้พยากรณ์ก็ยังนับว่าเห็น (เบา: คลิปเดียวต่อครั้ง)
    const io = 'IntersectionObserver' in window ? new IntersectionObserver((es) => {
        st.visible = es.some((x) => x.isIntersecting);
        st.visible ? resume() : pause();
    }, { threshold: 0.1 }) : null;
    io && io.observe(actor);
    const onVis = () => (document.hidden ? pause() : resume());
    document.addEventListener('visibilitychange', onVis);

    // เริ่มหลังหน้าโหลดเสร็จ — ไม่แย่งแบนด์วิดท์ภาพฉาก/สคริปต์หลัก
    const boot = () => setTimeout(() => { if (!st.front) start(); }, 600);
    if (document.readyState === 'complete') boot(); else window.addEventListener('load', boot, { once: true });

    return {
        live: true,
        /** สถานะพื้นของฉาก (เช่น idle / listen / summary) */
        base(m) { if (m && m !== st.base) { st.base = m; changed(); } },
        /** สถานะชั่วคราวที่สำคัญกว่าพื้น — ms = หมดเองหลังเวลานี้ (เช่นลูกค้าหยุดพิมพ์) */
        flag(m, on, ms = 0) {
            const had = st.flags.has(m);
            if (on) st.flags.set(m, ms > 0 ? Date.now() + ms : Infinity); else st.flags.delete(m);
            if (on !== had) changed();
            if (on && ms > 0) {
                clearTimeout(st['t_' + m]);
                st['t_' + m] = setTimeout(() => { if (st.flags.get(m) <= Date.now()) { st.flags.delete(m); changed(); } }, ms + 30);
            }
        },
        /** เล่นท่าของสถานะนี้ 1 คลิป แล้วกลับสถานะเดิม (เช่นเปิดไพ่ = ชี้ไพ่ 1 ครั้ง) */
        pulse(m) { if (m && st.pulses.length < 2 && st.pulses[st.pulses.length - 1] !== m) { st.pulses.push(m); changed(); } },
        /** ฉากซ่อนผู้พยากรณ์อยู่ = หยุด (ไม่โหลดเพิ่ม) */
        active(on) { st.enabled = !!on; on ? resume() : pause(); },
        destroy() {
            st.dead = true; pause(); io && io.disconnect();
            document.removeEventListener('visibilitychange', onVis);
            vids.forEach((v) => { v.removeAttribute('src'); v.load(); v.remove(); });
            actor.classList.remove('has-pose');
        },
        _state: st, _byName: byName,
    };
}

/**
 * หน้าแชทสด: ผูกท่ากับสิ่งที่เกิดในห้อง
 *   กำลังคิด (Alpine ใส่ .is-busy ให้ .or-actor)  → think
 *   กำลังพูด (เสียงคำตอบพรีเมียม oracle-voice · ประโยคประจำ oracle-line) → talk
 *   ลูกค้ากำลังพิมพ์ในช่องแชท → listen (หมดเองเมื่อหยุดพิมพ์ 4 วิ)
 *   คำตอบมาถึง → พูด 1 ท่า แล้วยิ้มรับ
 *   หน้าอื่นสั่งเองได้: window.dispatchEvent(new CustomEvent('oracle-mood', { detail: { mood, pulse? } }))
 */
export function wireChatRoom(actor, ctl) {
    if (!ctl.live) return;
    let busy = actor.classList.contains('is-busy');
    if (busy) ctl.flag('think', true);
    new MutationObserver(() => {
        const now = actor.classList.contains('is-busy');
        if (now === busy) return;
        busy = now;
        ctl.flag('think', busy);
        if (!busy) { ctl.flag('listen', false); ctl.pulse('talk'); ctl.pulse('smile'); }
    }).observe(actor, { attributes: true, attributeFilter: ['class'] });

    document.addEventListener('input', (e) => {
        const t = e.target;
        if (t && t.closest && t.closest('.chat-panel') && t.matches('textarea, input[type="text"]') && t.value.trim()) ctl.flag('listen', true, 4000);
    }, true);
    window.addEventListener('oracle-voice', (e) => ctl.flag('talk', !!(e.detail && e.detail.on)));
    window.addEventListener('oracle-line', (e) => ctl.flag('talk', !!(e.detail && e.detail.on), e.detail && e.detail.on ? 15000 : 0));
    window.addEventListener('oracle-mood', (e) => {
        const d = e.detail || {};
        if (d.pulse) ctl.pulse(d.mood); else ctl.base(d.mood || 'idle');
    });
}
