// 📈 กราฟชีวิตมหาทักษา (อายุ 0–100) บนโรงละคร — ตัวเลขชุดเดียวกับรูปกราฟที่ลูกค้า LINE ได้ (คำนวณที่ Thaiprompt)
// วาดเป็น SVG ขนาดพิกเซลจริงของจอ (ตัวอักษรไทยคมทุกขนาดจอ ทั้งโหมด WebGL และ CSS)
// อนิเมชันเป็น CSS ล้วน ติดเมื่อกล่องได้คลาส is-on: แถบดาวเสวยงอกขึ้นทีละช่วง → เส้นทองลากตามอายุ
// → จุดรุ่ง/ระวังเด้งตอนเส้นลากผ่าน → เส้น "ตอนนี้" เต้น (ลดการเคลื่อนไหว = แสดงทันที ดู blade)

const GOLD = '#f4d58d';
const GOOD = '#7ee0a1';
const BAD = '#ff8a7a';
const NOW = '#ff9d70';
const SHORT = { 1: 'อา', 2: 'จ', 3: 'อ', 4: 'พ', 5: 'พฤ', 6: 'ศ', 7: 'ส', 8: 'รา' };
/** ใช้เวลาลากเส้นทั้งเส้น (วินาที) — จุดรุ่ง/ระวังเด้งตามตำแหน่งบนเส้นนี้ */
const DRAW = 2.4;
const DRAW_AT = 0.9;

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const n = (v) => Math.round(v * 10) / 10;
const ageText = (a) => (Math.abs(a - Math.round(a)) < 0.05 ? String(Math.round(a)) : a.toFixed(1));

/** สีตัวอักษรบนแถบดาว — พื้นสว่าง (จันทร์ ศุกร์…) ใช้ตัวเข้ม */
function inkOn(hex) {
    const h = hex.replace('#', '');
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.6 ? '#1c1c2e' : '#ffffff';
}

/** เส้นโค้งผ่านทุกจุด (Catmull-Rom → Bézier) ไม่แกว่งเกินจุดจริง */
function smoothPath(pts) {
    if (pts.length < 2) return '';
    let d = `M${n(pts[0][0])},${n(pts[0][1])}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] || pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] || p2;
        const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6];
        const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
        d += ` C${n(c1[0])},${n(c1[1])} ${n(c2[0])},${n(c2[1])} ${n(p2[0])},${n(p2[1])}`;
    }
    return d;
}

/**
 * วาดกราฟลงกล่อง (ล้างของเดิม) — เรียกซ้ำได้ตอนจอเปลี่ยนขนาด
 * @param {HTMLElement} host กล่อง .rt-life-chart
 * @param {object} life ข้อมูลจาก ReadingTheater::life
 */
export function renderLife(host, life) {
    const W = Math.max(280, host.clientWidth);
    const H = Math.max(170, host.clientHeight);
    const small = W < 520;
    const fs = small ? 11 : 13;
    const maxAge = (life.max_age || 100) + 1;
    const pad = { l: small ? 26 : 34, r: small ? 8 : 14, t: small ? 30 : 38, b: small ? 22 : 26 };
    const x0 = pad.l;
    const x1 = W - pad.r;
    const yt = 4;
    const y0 = pad.t;
    const y1 = H - pad.b;
    const X = (age) => x0 + (age / maxAge) * (x1 - x0);
    const Y = (v) => y1 - (Math.max(0, Math.min(100, v)) / 100) * (y1 - y0);
    const delayAt = (age) => (DRAW_AT + (DRAW * age) / maxAge).toFixed(2);
    const out = [];

    out.push(`<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="กราฟชีวิตอายุ 0 ถึง ${maxAge - 1} ปี">`);
    out.push(`<defs><linearGradient id="rtLifeArea" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${GOLD}" stop-opacity=".42"/><stop offset="1" stop-color="${GOLD}" stop-opacity="0"/></linearGradient>`
        + `<filter id="rtLifeGlow" x="-10%" y="-30%" width="120%" height="160%"><feGaussianBlur stdDeviation="3.2"/></filter></defs>`);

    // ช่วงดาวเสวย: พื้นจางเต็มความสูง + ป้ายด้านบน (ชื่อดาว + อายุ)
    (life.periods || []).forEach((p, i) => {
        const a = X(p.from_age);
        const b = X(Math.min(p.to_age, maxAge));
        const w = b - a;
        if (w < 1) return;
        const d = (0.08 * i).toFixed(2);
        out.push(`<g class="rt-life-band" style="--d:${d}s">`);
        out.push(`<rect x="${n(a)}" y="${y0}" width="${n(w)}" height="${n(y1 - y0)}" fill="${esc(p.hex)}" opacity=".13"/>`);
        out.push(`<rect x="${n(a + 1)}" y="${yt}" width="${n(Math.max(0, w - 2))}" height="${y0 - yt - 6}" rx="5" fill="${esc(p.hex)}" opacity=".92"/>`);
        const ink = inkOn(p.hex);
        const label = w > (small ? 62 : 78) ? p.planet_th : (w > 26 ? SHORT[p.planet] || '' : '');
        const ages = `${ageText(p.from_age)}–${ageText(Math.min(p.to_age, maxAge - 1))}`;
        const cy = yt + (y0 - yt - 6) / 2;
        if (label && w > (small ? 54 : 64)) {
            out.push(`<text x="${n((a + b) / 2)}" y="${n(cy - 1)}" fill="${ink}" font-size="${fs}" font-weight="700" text-anchor="middle">${esc(label)}</text>`);
            out.push(`<text x="${n((a + b) / 2)}" y="${n(cy + fs - 1)}" fill="${ink}" font-size="${fs - 2}" text-anchor="middle" opacity=".85">${ages} ปี</text>`);
        } else if (label) {
            out.push(`<text x="${n((a + b) / 2)}" y="${n(cy + fs / 2 - 1)}" fill="${ink}" font-size="${fs - 1}" font-weight="700" text-anchor="middle">${esc(label)}</text>`);
        }
        out.push(`<title>ดาวเสวย${esc(p.planet_th)} อายุ ${ages} ปี · ช่วง${esc(p.tone)}</title></g>`);
    });

    // เส้นตาราง 0/25/50/75/100
    for (let v = 0; v <= 100; v += 25) {
        const y = n(Y(v));
        out.push(`<line class="rt-life-grid" x1="${x0}" x2="${x1}" y1="${y}" y2="${y}"${v === 50 ? ' stroke-dasharray="6 5"' : ''}/>`);
        out.push(`<text class="rt-life-axis" x="${x0 - 5}" y="${n(Y(v) + 4)}" font-size="${fs - 2}" text-anchor="end">${v}</text>`);
    }
    // อายุทุก 10 ปี
    for (let a = 0; a <= maxAge - 1; a += 10) {
        out.push(`<text class="rt-life-axis" x="${n(X(a + 0.5))}" y="${n(y1 + fs + 4)}" font-size="${fs - 1}" text-anchor="middle">${a}</text>`);
    }

    // เส้นคะแนนรายปี
    const pts = (life.years || []).map(([age, score]) => [X(age + 0.5), Y(score)]);
    if (pts.length > 1) {
        const line = smoothPath(pts);
        out.push(`<path class="rt-life-area" d="${line} L${n(pts[pts.length - 1][0])},${y1} L${n(pts[0][0])},${y1} Z" fill="url(#rtLifeArea)"/>`);
        out.push(`<path class="rt-life-line rt-life-glow" d="${line}" pathLength="1" stroke="${GOLD}" stroke-width="7" filter="url(#rtLifeGlow)"/>`);
        out.push(`<path class="rt-life-line" d="${line}" pathLength="1" stroke="${GOLD}" stroke-width="${small ? 2.2 : 2.8}"/>`);
    }

    // จุดรุ่ง/ระวัง — ป้ายเลี่ยงทับกันแบบเดียวกับรูปใน LINE
    const placed = [];
    [[life.peaks || [], true], [life.lows || [], false]].forEach(([list, up]) => {
        [...list].sort((a, b) => a.age - b.age).forEach((pk) => {
            const px = X(pk.age + 0.5);
            const py = Y(pk.score);
            const c = up ? GOOD : BAD;
            let lx = Math.max(x0 + 16, Math.min(x1 - 16, px));
            let ly = up ? py - 12 : py + fs + 10;
            placed.forEach(([qx, qy]) => {
                if (Math.abs(qx - lx) < 40 && Math.abs(qy - ly) < fs + 4) ly = up ? qy - fs - 4 : qy + fs + 4;
            });
            ly = up ? Math.max(y0 + fs, ly) : Math.min(y1 - 3, ly);
            placed.push([lx, ly]);
            out.push(`<g class="rt-life-mark" style="--d:${delayAt(pk.age)}s;transform-origin:${n(px)}px ${n(py)}px">`
                + `<circle cx="${n(px)}" cy="${n(py)}" r="${small ? 4.5 : 5.5}" fill="${c}" stroke="#0b1033" stroke-width="2"/>`
                + `<text x="${n(lx)}" y="${n(ly)}" fill="${c}" font-size="${fs}" font-weight="700" text-anchor="middle">${up ? '▲' : '▼'}${pk.age}</text>`
                + `<title>${up ? 'ช่วงรุ่ง' : 'ช่วงควรระวัง'} อายุ ${pk.age} (พ.ศ. ${pk.year_be}) · ${esc(pk.main_planet_th)}/${esc(pk.sub_planet_th)}</title></g>`);
        });
    });

    // ตอนนี้
    const cur = life.current || {};
    const yearNow = (life.years || []).find(([age]) => age === cur.age);
    if (cur.age_decimal != null && cur.age_decimal <= maxAge && yearNow) {
        const cx = n(X(cur.age_decimal));
        const cy = n(Y(yearNow[1]));
        out.push(`<g class="rt-life-now" style="--d:${(DRAW_AT + DRAW + 0.2).toFixed(2)}s">`
            + `<line x1="${cx}" x2="${cx}" y1="${y0}" y2="${y1}" stroke="${NOW}" stroke-width="1.6" stroke-dasharray="5 4"/>`
            + `<circle class="rt-life-pulse" cx="${cx}" cy="${cy}" r="7" fill="none" stroke="${NOW}" stroke-width="2" style="transform-origin:${cx}px ${cy}px"/>`
            + `<circle cx="${cx}" cy="${cy}" r="${small ? 5 : 6}" fill="${NOW}" stroke="#fff" stroke-width="2"/>`
            + `<text x="${n(Math.min(x1 - 30, Math.max(x0 + 30, X(cur.age_decimal))))}" y="${n(y1 - 6)}" fill="${NOW}" font-size="${fs}" font-weight="700" text-anchor="middle">ตอนนี้ ${cur.age}</text></g>`);
    }

    out.push('</svg>');
    host.innerHTML = out.join('');
}
