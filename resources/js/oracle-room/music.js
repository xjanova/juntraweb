// 🎵 เพลงบรรเลงในห้องพยากรณ์ (หน้าแชทสด) — 2026-11-05
//
// เจ้าของสั่ง "เพลงควรเล่นวนไปเรื่อยๆ" — ใช้เครื่องเล่นตัวเดียวกับโรงละครเปิดไพ่ (card-theater/sound.js)
//   - เล่นวนเพลงของห้องต่อกันไม่หยุด เฟดเข้า/ออก · ไฟล์เสียเล่นไม่ได้ = ข้ามไปเพลงถัดไป
//   - ปุ่มลำโพงของผู้พยากรณ์ปิดอยู่ = เงียบ (เครื่องเล่นตรวจสวิตช์ทุกวินาทีเอง)
//   - ผู้พยากรณ์พูดประโยคประจำ (oracle-say) = หรี่ลงชั่วครู่
// รายการเพลงมาจาก <div data-room-music='[...]'> ที่หน้าแชทวางไว้ (OracleMedia::music)
import { music } from '../card-theater/sound.js';

const host = document.querySelector('[data-room-music]');
if (host) {
    let urls = [];
    try { urls = JSON.parse(host.dataset.roomMusic || '[]'); } catch (_) { urls = []; }
    if (Array.isArray(urls) && urls.length) {
        music.start(urls);
        window.addEventListener('oracle-say', () => music.duck());
        // ออกจากหน้า (รวม bfcache) — เฟดเพลงออก กลับมาหน้าเดิมแล้วเริ่มใหม่
        window.addEventListener('pagehide', () => music.stop());
        window.addEventListener('pageshow', (e) => { if (e.persisted) music.start(urls); });
    }
}
