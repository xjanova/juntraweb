// 🎭 ตำแหน่งไพ่/กราฟของโรงละคร — ใช้ร่วมกันทั้งฉาก WebGL และแบบ CSS (ทางถอย)
// หน่วย = ความกว้างไพ่ 1 ใบ (สูง 1.7) · แกน y ขึ้นบน · จุดกึ่งกลางของชุดไพ่อยู่ที่ (0, 0)

export const CARD_W = 1;
export const CARD_H = 1.7;

/**
 * ตำแหน่งไพ่ตามแบบจริงของแพ็กเกจ
 *  - celtic (เซลติก/คุณไสย 10 ใบ): กากบาท 6 ใบ + เสา 4 ใบด้านขวา (7 ล่างสุด → 10 บนสุด) · ใบที่ 2 วางขวางทับใบที่ 1
 *  - wheel (รายปี 12 ใบ): วงล้อรอบดวงจันทร์ เดือนแรกอยู่บนสุด เวียนตามเข็มนาฬิกา
 *  - row: แถวเดียว (1–5 ใบ) · มากกว่า 5 ใบตัดเป็น 2 แถว
 *
 * @returns {{slots: Array<{pos:number,x:number,y:number,rot:number,scale:number,z:number}>, bounds:{minX:number,maxX:number,minY:number,maxY:number}}}
 */
export function cardLayout(kind, positions) {
    let slots;
    if (kind === 'celtic') {
        const map = {
            1: [0, 0, 0, 0], 2: [0, 0, Math.PI / 2, 0.04], 3: [0, -1.95, 0, 0], 4: [-1.3, 0, 0, 0],
            5: [0, 1.95, 0, 0], 6: [1.3, 0, 0, 0],
            7: [2.85, -2.7, 0, 0], 8: [2.85, -0.9, 0, 0], 9: [2.85, 0.9, 0, 0], 10: [2.85, 2.7, 0, 0],
        };
        slots = positions.map((pos, i) => {
            const m = map[pos] || [(i - 4.5) * 1.2, -4, 0, 0];
            return { pos, x: m[0], y: m[1], rot: m[2], z: m[3], scale: 1 };
        });
    } else if (kind === 'wheel') {
        const n = positions.length;
        slots = positions.map((pos, i) => {
            const a = Math.PI / 2 - (i / n) * Math.PI * 2;
            return { pos, x: Math.cos(a) * 3.1, y: Math.sin(a) * 3.1, rot: 0, z: 0, scale: 0.6 };
        });
    } else {
        const n = positions.length;
        const perRow = n > 5 ? Math.ceil(n / 2) : n;
        const rows = Math.ceil(n / perRow);
        const scale = n === 1 ? 1.35 : 1;
        slots = positions.map((pos, i) => {
            const r = Math.floor(i / perRow);
            const inRow = Math.min(perRow, n - r * perRow);
            const c = i - r * perRow;
            return {
                pos,
                x: (c - (inRow - 1) / 2) * 1.3,
                y: ((rows - 1) / 2 - r) * 2.05,
                rot: 0, z: 0, scale,
            };
        });
    }

    // จัดให้กึ่งกลางของชุดอยู่ที่ (0,0)
    const b = boundsOf(slots);
    const cx = (b.minX + b.maxX) / 2;
    const cy = (b.minY + b.maxY) / 2;
    slots.forEach((s) => { s.x -= cx; s.y -= cy; });

    return { slots, bounds: boundsOf(slots) };
}

function boundsOf(slots) {
    const b = { minX: Infinity, maxX: -Infinity, minY: Infinity, maxY: -Infinity };
    for (const s of slots) {
        // ใบที่วางขวาง (หมุน 90°) กินพื้นที่ตามแนวนอน
        const across = Math.abs(Math.sin(s.rot)) > 0.5;
        const hw = ((across ? CARD_H : CARD_W) * s.scale) / 2;
        const hh = ((across ? CARD_W : CARD_H) * s.scale) / 2;
        b.minX = Math.min(b.minX, s.x - hw);
        b.maxX = Math.max(b.maxX, s.x + hw);
        b.minY = Math.min(b.minY, s.y - hh);
        b.maxY = Math.max(b.maxY, s.y + hh);
    }
    return b;
}

/**
 * จุดของกราฟชีวิต — วางเหนือชุดไพ่ กว้างเท่าชุดไพ่ (อย่างน้อย 4.6)
 * h (0–1) จากเซิร์ฟเวอร์ = ความสูงของจุดในแถบกราฟ
 */
export function graphLayout(points, cardBounds) {
    const width = Math.max(4.6, cardBounds.maxX - cardBounds.minX);
    const height = 1.5;
    const baseY = cardBounds.maxY + 0.75;
    const n = points.length;
    const out = points.map((p, i) => ({
        ...p,
        x: n === 1 ? 0 : -width / 2 + (i / (n - 1)) * width,
        y: baseY + p.h * height,
    }));
    return { points: out, baseY, height, width, top: baseY + height + 0.35 };
}

/** สีตามดวง — ทอง = ดี · ม่วง = กลาง · แดงอมชมพู = ระวัง */
export const TONE_COLOR = { good: '#f4cf6a', neutral: '#b07aff', caution: '#ff5f8a' };

/** สีวงแหวนฟันธง (ReadingSections::verdictTone) */
export const VERDICT_COLOR = { green: '#5fe0a0', red: '#ff5f7a', amber: '#ffb347', gold: '#f4cf6a' };

/** สีห้อง — ค่าเดียวกับ --room-1/--room-2 ใน app.css */
export const ROOM_COLORS = {
    busaba: { a: '#9fd3ff', b: '#e6ecff', sky: '#0a1430' },
    maemor: { a: '#b07aff', b: '#f4cf6a', sky: '#0c0624' },
    kunsai: { a: '#e0455f', b: '#f4cf6a', sky: '#14040a' },
};
