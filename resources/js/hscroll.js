/**
 * ↔️ (2026-11-06) แถบปุ่มที่ล้นจอต้อง "เลื่อนสไลด์ได้" — เจ้าของสั่ง
 * "ส่วนของปุ่ม กดเหนือกล่องแชท ต้องเลื่อนสไลด์ไปได้เมื่อมีเมนูเยอะล้น"
 *
 * แถบชิป (.chip-row) กับโต๊ะไพ่ (.or-table) เลื่อนแนวนอนด้วยนิ้วได้อยู่แล้ว แต่ซ่อนสกรอลบาร์ไว้
 * บนคอมที่ใช้เมาส์จึงเลื่อนไม่ได้เลย และไม่มีอะไรบอกว่ายังมีปุ่มซ่อนอยู่ ไฟล์นี้เติม 4 อย่าง:
 *   1. ล้อเมาส์ (แนวตั้ง) เลื่อนแถบไปทางข้าง — เฉพาะตอนแถบล้นจริง ไม่งั้นปล่อยให้หน้าเลื่อนตามปกติ
 *   2. กดลากด้วยเมาส์ — ลากเกิน 6px ไม่นับเป็นการกดปุ่ม (กันเปิดไพ่โดยไม่ตั้งใจ)
 *   3. คลาส hs-l / hs-r ตามขอบที่ยังมีของซ่อนอยู่ → CSS ทำขอบจาง ๆ ให้เห็นว่ามีต่อ
 *   4. juntraHScroll(el, cb) — แถบที่มีปุ่มลูกศรของตัวเองรับสถานะ (ซ้าย, ขวา) ไปโชว์/ซ่อนลูกศร
 * ใช้ event delegation ทั้งหน้า: ชิปที่ Alpine สร้างทีหลังก็ได้ผลเอง ไม่ต้องผูกทีละตัว
 */
const SELECTOR = '.chip-row, .or-table, [data-hscroll]';
const callbacks = new WeakMap();

function overflowing(el) {
    return el.scrollWidth - el.clientWidth > 2;
}

function update(el) {
    const max = el.scrollWidth - el.clientWidth;
    // เผื่อ 8px: scroll-snap ดันแถวไปเท่าระยะ padding (4px) ตั้งแต่ยังไม่ได้เลื่อน → ลูกศรซ้ายขึ้นมาเปล่า ๆ
    const left = max > 8 && el.scrollLeft > 8;
    const right = max > 8 && el.scrollLeft < max - 8;
    el.classList.toggle('hs-l', left);
    el.classList.toggle('hs-r', right);
    const cb = callbacks.get(el);
    if (cb) cb(left, right);
}

let queued = false;
function updateAll() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
        queued = false;
        document.querySelectorAll(SELECTOR).forEach(update);
    });
}

window.juntraHScroll = (el, cb) => {
    if (cb) callbacks.set(el, cb);
    requestAnimationFrame(() => update(el));
};

document.addEventListener('wheel', (e) => {
    const el = e.target instanceof Element ? e.target.closest(SELECTOR) : null;
    if (!el || e.ctrlKey || !overflowing(el) || Math.abs(e.deltaX) >= Math.abs(e.deltaY)) return;
    const max = el.scrollWidth - el.clientWidth;
    // สุดขอบแล้ว = ปล่อยให้หน้าเลื่อนต่อ ไม่ "ติด" ล้อเมาส์ไว้ที่แถบ
    if ((e.deltaY < 0 && el.scrollLeft <= 0) || (e.deltaY > 0 && el.scrollLeft >= max - 1)) return;
    e.preventDefault();
    el.scrollLeft += e.deltaY;
}, { passive: false });

let drag = null;
document.addEventListener('pointerdown', (e) => {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    const el = e.target instanceof Element ? e.target.closest(SELECTOR) : null;
    if (!el || !overflowing(el)) return;
    drag = { el, x: e.clientX, left: el.scrollLeft, moved: false };
});
document.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const dx = e.clientX - drag.x;
    if (!drag.moved && Math.abs(dx) < 6) return;
    drag.moved = true;
    drag.el.classList.add('hs-drag');
    drag.el.scrollLeft = drag.left - dx;
});
document.addEventListener('pointerup', () => {
    if (!drag) return;
    const d = drag;
    drag = null;
    d.el.classList.remove('hs-drag');
    if (d.moved) {
        // ลากแล้วปล่อยบนปุ่ม = ไม่ใช่การกด
        const swallow = (ev) => { ev.stopPropagation(); ev.preventDefault(); };
        window.addEventListener('click', swallow, { capture: true, once: true });
        setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 0);
    }
});

document.addEventListener('scroll', (e) => {
    if (e.target instanceof Element && e.target.matches(SELECTOR)) update(e.target);
}, { capture: true, passive: true });
window.addEventListener('resize', updateAll, { passive: true });
document.addEventListener('DOMContentLoaded', () => {
    updateAll();
    // ชิปที่ Alpine เพิ่ม/ลบ (คำถามแนะนำจาก AI, หมวดคำถาม) เปลี่ยนความกว้างแถบ
    new MutationObserver(updateAll).observe(document.body, { childList: true, subtree: true });
});
