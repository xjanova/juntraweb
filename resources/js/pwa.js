/*
 * 📱 เว็บเป็นแอป (PWA) + แจ้งเตือน (Web Push) — ฝั่งเบราว์เซอร์ (2026-11-11)
 *
 *   registerServiceWorker()  ลง /sw.js ทุกหน้า (ขอบเขต "/")
 *   Alpine.data('pwaInstall')    ชิป "ติดตั้งแอปจันทรา" — Android/คอม = ปุ่มติดตั้งจริง, iPhone/iPad = วิธีเพิ่มลงหน้าจอโฮม
 *   Alpine.data('pushSettings')  การ์ดเปิด/ปิดแจ้งเตือนในหน้าภาพรวมบัญชี
 *
 * ขอสิทธิ์แจ้งเตือนเฉพาะตอนผู้ใช้กดปุ่มเท่านั้น (ห้ามขอเองตอนเปิดหน้า — Safari ไม่ยอม และคนกดบล็อกถาวร)
 * iPhone/iPad รับแจ้งเตือนได้เฉพาะตอนเปิดจากไอคอนบนหน้าจอโฮม (iOS/iPadOS 16.4 ขึ้นไป)
 */

const DISMISS_KEY = 'jw-pwa-install-dismissed';
const DISMISS_DAYS = 14;
const SYNC_KEY = 'jw-push-synced';

let deferredPrompt = null;

function store(key, value) {
  try {
    if (value === undefined) return localStorage.getItem(key);
    if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value);
  } catch (e) { /* โหมดส่วนตัว/บล็อกที่เก็บข้อมูล */ }
  return null;
}

export function isStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches
    || window.matchMedia('(display-mode: minimal-ui)').matches
    || window.navigator.standalone === true;
}

export function isIos() {
  const ua = navigator.userAgent || '';
  // iPadOS 13+ ปลอมตัวเป็น Mac — แยกด้วยจอสัมผัส
  return /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
}

export function isInAppBrowser() {
  return /FBAN|FBAV|FB_IAB|Instagram|Line\/|LIFF|MicroMessenger|TikTok/i.test(navigator.userAgent || '');
}

function csrf() {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.getAttribute('content') : '';
}

async function post(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify(body || {}),
  });
  let data = {};
  try { data = await res.json(); } catch (e) {}
  if (!res.ok) {
    const msg = data.error || (data.errors && Object.values(data.errors)[0]?.[0]) || data.message;
    throw new Error(msg && !/^(Server Error|Unauthenticated)/.test(msg) ? msg : 'ระบบขัดข้องชั่วคราว ลองใหม่อีกครั้งนะคะ');
  }
  return data;
}

function urlB64ToUint8Array(b64) {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

function sameKey(sub, keyBytes) {
  const k = sub && sub.options && sub.options.applicationServerKey;
  if (!k) return true; // เบราว์เซอร์เก่าไม่บอก — ถือว่าตรง
  const a = new Uint8Array(k);
  return a.length === keyBytes.length && a.every((v, i) => v === keyBytes[i]);
}

function swReady(ms = 8000) {
  return Promise.race([
    navigator.serviceWorker.ready,
    new Promise((_, rej) => setTimeout(() => rej(new Error('sw-timeout')), ms)),
  ]);
}

export function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
  });
}

/** ผู้ใช้เปิดแจ้งเตือนไว้แล้ว → ส่ง subscription ขึ้นเซิร์ฟเวอร์วันละครั้ง (เผื่อเครื่องหมุนกุญแจ/สลับบัญชี) */
async function backgroundSync() {
  const meta = document.querySelector('meta[name="jw-push-key"]');
  if (!meta || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
  if (Notification.permission !== 'granted') return;
  const today = new Date().toISOString().slice(0, 10) + ':' + (meta.dataset.user || '');
  if (store(SYNC_KEY) === today) return;
  try {
    const reg = await swReady();
    const sub = await reg.pushManager.getSubscription();
    if (!sub || !sameKey(sub, urlB64ToUint8Array(meta.content))) return;
    await post('/push/subscribe', sub.toJSON());
    store(SYNC_KEY, today);
  } catch (e) { /* ไว้ลองใหม่ครั้งหน้า */ }
}

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault(); // ไม่ให้แถบติดตั้งของ Chrome เด้งเอง — ใช้ชิปของเราที่สุภาพกว่า
  deferredPrompt = e;
  window.dispatchEvent(new CustomEvent('jw-pwa-installable'));
});
window.addEventListener('appinstalled', () => {
  deferredPrompt = null;
  store(DISMISS_KEY, 'installed');
  window.dispatchEvent(new CustomEvent('jw-pwa-installed'));
});

export function pwaInstall() {
  return {
    show: false,
    sheet: false,
    mode: '', // prompt | ios | inapp
    init() {
      if (isStandalone()) return;
      const dismissed = store(DISMISS_KEY);
      if (dismissed === 'installed') return;
      if (dismissed && Date.now() - Number(dismissed) < DISMISS_DAYS * 864e5) return;

      // ป๊อปอัป "มีอะไรใหม่" (<x-whats-new>) ยังเปิดอยู่ → รอให้ปิดก่อน ไม่เด้งซ้อนกันสองอัน
      const busy = () => {
        const wn = document.querySelector('.wn-backdrop');
        return wn && getComputedStyle(wn).display !== 'none';
      };
      const later = () => { if (busy()) setTimeout(later, 3000); else this.show = true; };
      const reveal = (mode) => { this.mode = mode; setTimeout(later, 2500); };
      window.addEventListener('jw-pwa-installed', () => { this.show = false; this.sheet = false; });
      // iPhone/iPad: ทุกเบราว์เซอร์ใช้ WebKit ติดตั้งได้ทางปุ่มแชร์เท่านั้น → แผ่นสอนเสมอ
      if (isIos()) { reveal(isInAppBrowser() ? 'inapp' : 'ios'); return; }
      if (deferredPrompt) reveal('prompt');
      window.addEventListener('jw-pwa-installable', () => { if (!this.show) reveal('prompt'); });
    },
    async install() {
      if (this.mode !== 'prompt' || !deferredPrompt) { this.sheet = true; return; }
      const e = deferredPrompt;
      deferredPrompt = null;
      try {
        e.prompt();
        const choice = await e.userChoice;
        if (choice && choice.outcome === 'accepted') { this.show = false; return; }
      } catch (err) {}
      this.dismiss();
    },
    dismiss() {
      this.show = false;
      this.sheet = false;
      store(DISMISS_KEY, String(Date.now()));
    },
  };
}

export function pushSettings(cfg = {}) {
  return {
    key: cfg.key || '',
    muted: Array.isArray(cfg.muted) ? cfg.muted : [],
    // unsupported | needs-install | blocked | off | on | loading
    state: 'loading',
    busy: false,
    msg: '',
    err: '',
    ios: isIos(),
    async init() {
      const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
      if (!this.key) { this.state = 'unsupported'; return; }
      if (!supported) { this.state = this.ios && !isStandalone() ? 'needs-install' : 'unsupported'; return; }
      if (Notification.permission === 'denied') { this.state = 'blocked'; return; }
      try {
        const reg = await swReady();
        const sub = await reg.pushManager.getSubscription();
        this.state = sub && Notification.permission === 'granted' ? 'on' : 'off';
      } catch (e) {
        this.state = 'off';
      }
    },
    async enable() {
      if (this.busy) return;
      this.busy = true; this.err = ''; this.msg = '';
      try {
        // ต้องเรียกตรงจากการกดปุ่ม (Safari นับเป็น user gesture เฉพาะคำสั่งแรก ๆ)
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
          this.state = perm === 'denied' ? 'blocked' : 'off';
          if (perm !== 'denied') this.err = 'ยังไม่ได้อนุญาตการแจ้งเตือนค่ะ กดเปิดอีกครั้งเมื่อพร้อมนะคะ';
          return;
        }
        const reg = await swReady();
        const keyBytes = urlB64ToUint8Array(this.key);
        let sub = await reg.pushManager.getSubscription();
        if (sub && !sameKey(sub, keyBytes)) { await sub.unsubscribe(); sub = null; }
        if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes });
        await post('/push/subscribe', sub.toJSON());
        store(SYNC_KEY, null);
        this.state = 'on';
        this.msg = 'เปิดแจ้งเตือนบนเครื่องนี้แล้วค่ะ';
      } catch (e) {
        this.err = e && e.message && !/sw-timeout|AbortError|NotAllowed/i.test(e.message)
          ? e.message : 'เปิดแจ้งเตือนไม่สำเร็จ ลองรีเฟรชหน้าแล้วกดใหม่อีกครั้งนะคะ';
      } finally {
        this.busy = false;
      }
    },
    async disable() {
      if (this.busy) return;
      this.busy = true; this.err = ''; this.msg = '';
      try {
        const reg = await swReady();
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
          const endpoint = sub.endpoint;
          await sub.unsubscribe();
          await post('/push/unsubscribe', { endpoint });
        }
        this.state = 'off';
        this.msg = 'ปิดแจ้งเตือนบนเครื่องนี้แล้วค่ะ';
      } catch (e) {
        this.err = 'ปิดแจ้งเตือนไม่สำเร็จ ลองใหม่อีกครั้งนะคะ';
      } finally {
        this.busy = false;
      }
    },
    async test() {
      if (this.busy) return;
      this.busy = true; this.err = ''; this.msg = '';
      try {
        const r = await post('/push/test');
        this.msg = r.delivered > 0 ? 'ส่งแล้วค่ะ รอสักครู่ข้อความจะเด้งขึ้นมา' : 'ส่งไม่ถึงเครื่อง ลองปิดแล้วเปิดแจ้งเตือนใหม่นะคะ';
      } catch (e) {
        this.err = e.message;
      } finally {
        this.busy = false;
      }
    },
    async toggleType(type) {
      const prev = this.muted.slice();
      this.muted = this.muted.includes(type) ? this.muted.filter((t) => t !== type) : this.muted.concat(type);
      try {
        const r = await post('/push/preferences', { muted: this.muted });
        this.muted = r.muted || this.muted;
      } catch (e) {
        this.muted = prev;
        this.err = e.message;
      }
    },
  };
}

registerServiceWorker();
backgroundSync();
