// 🎭 อิริยาบถของผู้พยากรณ์ในห้องแชทเต็มจอ (2026-11-07) — entry ของ Vite แยกไฟล์ โหลดเฉพาะห้องที่มีคลิป
// ข้อมูลคลิปมาจาก <div class="or-actor" data-poses='...'> (OracleMedia::poses) · ท่าทั้งหมดอยู่ใน poses.js
import { attachPoses, wireChatRoom } from './poses.js';

const actor = document.querySelector('.or-actor[data-poses]');
if (actor) {
    let data = null;
    try { data = JSON.parse(actor.dataset.poses || 'null'); } catch (_) { data = null; }
    const ctl = attachPoses(actor, data);
    wireChatRoom(actor, ctl);
    window.addEventListener('pagehide', () => ctl.active(false));
    window.addEventListener('pageshow', (e) => { if (e.persisted) ctl.active(true); });
}
