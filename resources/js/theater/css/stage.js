// 🃏 เวทีแบบ CSS 2.5D — ทางถอยของโรงละคร (ไม่มี WebGL / เครื่องอ่อน / ตั้งลดการเคลื่อนไหว / ตัวคุมคุณภาพถอยสุดทาง)
// เนื้อหาเหมือนฉาก WebGL ทุกอย่าง (ไพ่ตำแหน่งเดียวกัน กราฟ วงแหวน กลุ่มดาว) แต่ไม่มี rAF ของตัวเอง:
// ตัวคุมเรียก render(state) ตอนเปลี่ยนบท/ทุก 250 ms แล้วให้ CSS transition ทำส่วนที่เหลือ

import { cardLayout, graphLayout, CARD_W, CARD_H, TONE_COLOR, VERDICT_COLOR, ROOM_COLORS } from '../layout.js';
import { CONSTELLATIONS } from '../constellations.js';

const NS = 'http://www.w3.org/2000/svg';
const svg = (tag, attrs = {}) => {
    const el = document.createElementNS(NS, tag);
    for (const k in attrs) el.setAttribute(k, attrs[k]);
    return el;
};
const div = (cls, parent) => {
    const el = document.createElement('div');
    el.className = cls;
    if (parent) parent.appendChild(el);
    return el;
};

export function createCSSStage(host, cfg, hooks) {
    const colors = ROOM_COLORS[cfg.persona] || ROOM_COLORS.maemor;
    const root = div('rtc', host);
    root.style.setProperty('--rtc-a', colors.a);
    root.style.setProperty('--rtc-b', colors.b);
    root.style.setProperty('--rtc-sky', colors.sky);

    const skyEl = div('rtc-sky', root);
    div('rtc-stars', skyEl);
    const moon = div('rtc-moon', skyEl);

    // กลุ่มดาวราศี
    let constEl = null;
    const shape = cfg.zodiac ? CONSTELLATIONS[cfg.zodiac.slug] : null;
    if (shape) {
        constEl = div('rtc-const', skyEl);
        const s = svg('svg', { viewBox: '-1.2 -1.2 2.4 2.4', 'aria-hidden': 'true' });
        shape.lines.forEach(([a, b]) => s.appendChild(svg('line', {
            x1: shape.stars[a][0], y1: -shape.stars[a][1], x2: shape.stars[b][0], y2: -shape.stars[b][1],
        })));
        shape.stars.forEach(([x, y]) => s.appendChild(svg('circle', { cx: x, cy: -y, r: 0.045 })));
        constEl.appendChild(s);
        const lab = div('rtc-const-label', constEl);
        lab.textContent = `${cfg.zodiac.glyph} ${cfg.zodiac.name}`;
    }

    const positions = cfg.cards.map((c) => c.pos);
    const { slots, bounds } = cardLayout(cfg.layout, positions);
    const graph = graphLayout(cfg.graph.points, bounds);
    const minX = Math.min(bounds.minX, -graph.width / 2) - 0.3;
    const maxX = Math.max(bounds.maxX, graph.width / 2) + 0.3;
    const minY = bounds.minY - 0.3;
    const maxY = graph.top;
    const W = maxX - minX;
    const H = maxY - minY;
    const px = (x) => ((x - minX) / W) * 100;
    const py = (y) => ((maxY - y) / H) * 100;

    const board = div('rtc-board', root);

    // กราฟชีวิต (SVG หน่วยเดียวกับเลย์เอาต์)
    const g = svg('svg', { class: 'rtc-graph', viewBox: `${minX} ${-maxY} ${W} ${H}`, preserveAspectRatio: 'none', 'aria-hidden': 'true' });
    const pts = graph.points.map((p) => [p.x, -p.y]);
    let d = '';
    if (pts.length === 1) {
        d = `M${pts[0][0] - 1.2},${pts[0][1]} L${pts[0][0] + 1.2},${pts[0][1]}`;
    } else {
        d = `M${pts[0][0]},${pts[0][1]}`;
        for (let i = 1; i < pts.length; i++) {
            const [x0, y0] = pts[i - 1];
            const [x1, y1] = pts[i];
            const mx = (x0 + x1) / 2;
            d += ` C${mx},${y0} ${mx},${y1} ${x1},${y1}`;
        }
    }
    const grad = svg('linearGradient', { id: 'rtcg' + cfg.id, x1: '0', x2: '1', y1: '0', y2: '0' });
    graph.points.forEach((p, i) => grad.appendChild(svg('stop', {
        offset: graph.points.length === 1 ? '0.5' : String(i / (graph.points.length - 1)), 'stop-color': TONE_COLOR[p.tone] || TONE_COLOR.neutral,
    })));
    const defs = svg('defs');
    defs.appendChild(grad);
    g.appendChild(defs);
    const path = svg('path', { d, pathLength: '1', class: 'rtc-line', stroke: `url(#rtcg${cfg.id})` });
    g.appendChild(path);
    board.appendChild(g);
    const dots = graph.points.map((p) => {
        const el = div('rtc-dot', board);
        el.style.left = px(p.x) + '%';
        el.style.top = py(p.y) + '%';
        el.style.setProperty('--dot', TONE_COLOR[p.tone] || TONE_COLOR.neutral);
        el.dataset.pos = p.pos;
        return el;
    });

    // ไพ่
    const cards = cfg.cards.map((c, i) => {
        const s = slots[i];
        const el = div('rtc-card', board);
        el.dataset.pos = c.pos;
        el.style.left = px(s.x) + '%';
        el.style.top = py(s.y) + '%';
        el.style.setProperty('--w', ((CARD_W * s.scale) / W) * 100 + '%');
        el.style.setProperty('--rot', s.rot + 'rad');
        el.style.setProperty('--i', i);
        const inner = div('rtc-inner', el);
        const back = div('rtc-face rtc-back', inner);
        if (cfg.back) back.style.backgroundImage = `url("${cfg.back}")`;
        const front = div('rtc-face rtc-front', inner);
        if (c.img) {
            const img = document.createElement('img');
            img.src = c.img;
            img.alt = c.name;
            img.loading = 'lazy';
            img.decoding = 'async';
            if (c.reversed) img.style.transform = 'rotate(180deg)';
            front.appendChild(img);
        }
        const tag = div('rtc-tag', el);
        tag.textContent = c.label;
        return el;
    });

    // วงแหวนฟันธง
    const ringEl = div('rtc-ring', board);
    const rs = svg('svg', { viewBox: '0 0 100 100', 'aria-hidden': 'true' });
    rs.appendChild(svg('circle', { cx: 50, cy: 50, r: 40, class: 'rtc-ring-back' }));
    const arc = svg('circle', { cx: 50, cy: 50, r: 40, class: 'rtc-ring-arc', pathLength: '1', transform: 'rotate(-90 50 50)', stroke: VERDICT_COLOR[cfg.verdict.tone] || VERDICT_COLOR.gold });
    rs.appendChild(arc);
    ringEl.appendChild(rs);
    const rl = div('rtc-ring-label', ringEl);
    rl.textContent = cfg.verdict.label || 'สรุปดวง';
    if (cfg.graph.ring.caption) {
        const cap = document.createElement('small');
        cap.textContent = cfg.graph.ring.caption;
        rl.appendChild(cap);
    }
    ringEl.style.left = px(0) + '%';
    ringEl.style.top = py((bounds.minY + bounds.maxY) / 2) + '%';

    const fit = () => {
        const safe = hooks.safe ? hooks.safe() : { top: 0, bottom: 0 };
        const aw = host.clientWidth * 0.94;
        const ah = Math.max(120, host.clientHeight - safe.top - safe.bottom) * 0.94;
        const unit = Math.min(aw / W, ah / H);
        board.style.width = (W * unit).toFixed(1) + 'px';
        board.style.height = (H * unit).toFixed(1) + 'px';
        board.style.top = (safe.top + (host.clientHeight - safe.top - safe.bottom - H * unit) / 2).toFixed(1) + 'px';
        root.style.setProperty('--unit', unit.toFixed(2) + 'px');
    };
    const ro = new ResizeObserver(fit);
    ro.observe(host);
    fit();

    let last = '';
    return {
        mode: 'css',
        wake() {},
        resize: fit,
        info: () => ({ tier: 'css' }),
        render(st) {
            if (!st) return;
            moon.style.setProperty('--amp', (hooks.amplitude ? hooks.amplitude() : 0).toFixed(2));
            path.style.strokeDashoffset = String(1 - st.graphDraw);
            const key = [st.index, st.dealt, st.ring, st.sky, Math.round(st.graphDraw * 20)].join('|');
            if (key === last) return;
            last = key;
            root.classList.toggle('is-sky', !!st.sky);
            root.classList.toggle('is-ring', !!st.ring);
            root.classList.toggle('is-dealt', !!st.dealt);
            root.classList.toggle('is-focus', st.currentPos != null);
            if (constEl) constEl.classList.toggle('is-on', !!st.sky);
            cards.forEach((el) => {
                const pos = Number(el.dataset.pos);
                el.classList.toggle('is-up', !!st.revealed[pos]);
                el.classList.toggle('is-cur', st.currentPos === pos);
            });
            const n = graph.points.length;
            dots.forEach((el, i) => {
                const at = n === 1 ? 0.5 : i / (n - 1);
                el.classList.toggle('is-on', st.graphDraw >= at - 0.001);
                el.classList.toggle('is-cur', st.currentPos === Number(el.dataset.pos));
            });
            arc.style.strokeDasharray = `${(st.ring ? cfg.graph.ring.fill : 0).toFixed(3)} 1`;
        },
        pick(clientX, clientY) {
            const el = document.elementFromPoint(clientX, clientY);
            const hit = el && el.closest('.rtc-card, .rtc-dot');
            return hit ? Number(hit.dataset.pos) : null;
        },
        dispose() {
            ro.disconnect();
            root.remove();
        },
    };
}
