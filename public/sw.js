/*
 * 🌙 Service worker ของจันทรา.online (2026-11-11) — ทำให้เว็บติดตั้งเป็นแอป + รับแจ้งเตือน
 *
 * กติกาแคช (เข้มเพราะเว็บนี้มีเงินจริงและคำทำนายส่วนตัว):
 *   - แคชเฉพาะไฟล์นิ่ง: /build/assets/* (ชื่อไฟล์มี hash = cache-first ปลอดภัย), /images/*, ไอคอน, หน้า offline
 *   - หน้าเว็บ (navigate) ไม่แคชเลย — ยิงเน็ตทุกครั้ง หลุดเน็ตค่อยโชว์ /offline.html
 *     ห้ามแคชหน้าบัญชี/วอลเลต/ผลคำทำนาย/ห้องแชท ไม่งั้นเครื่องที่ใช้ร่วมกันเห็นข้อมูลคนอื่น
 *   - API / POST / รูปสลิป / เสียง-วิดีโอ (/media, /videos — ไฟล์ใหญ่ ขอทีละช่วง) = ปล่อยเบราว์เซอร์จัดการเอง
 * เปลี่ยนรายการ precache หรือกติกา → ขยับ VERSION ให้ลบแคชเก่า
 */
const VERSION = 'jw-pwa-2026-11-11a';
const SHELL = VERSION + '-shell';
const ASSETS = VERSION + '-assets';
const IMAGES = VERSION + '-images';
const MAX_IMAGES = 80;

const PRECACHE = [
  '/offline.html',
  '/images/pwa/icon-192.png',
  '/images/pwa/icon-512.png',
  '/images/pwa/badge-96.png',
  '/images/logo.webp',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keep = new Set([SHELL, ASSETS, IMAGES]);
    for (const key of await caches.keys()) {
      if (key.startsWith('jw-pwa-') && !keep.has(key)) await caches.delete(key);
    }
    if (self.registration.navigationPreload) {
      try { await self.registration.navigationPreload.enable(); } catch (e) {}
    }
    await self.clients.claim();
  })());
});

function cacheable(res) {
  return res && res.ok && res.type === 'basic' && !/no-store|private/i.test(res.headers.get('Cache-Control') || '');
}

async function trim(cacheName, max) {
  const c = await caches.open(cacheName);
  const keys = await c.keys();
  for (let i = 0; i < keys.length - max; i++) await c.delete(keys[i]);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // หน้าเว็บ: เน็ตก่อนเสมอ ไม่เก็บลงแคช — หลุดเน็ตค่อยโชว์หน้า offline
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const preload = await event.preloadResponse;
        if (preload) return preload;
        return await fetch(req);
      } catch (e) {
        return (await caches.match('/offline.html')) || new Response('ออฟไลน์', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
      }
    })());
    return;
  }

  // ไฟล์จาก Vite (ชื่อมี hash) — มีในแคชใช้เลย
  if (url.pathname.startsWith('/build/assets/')) {
    event.respondWith((async () => {
      const hit = await caches.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (cacheable(res)) {
        const copy = res.clone();
        caches.open(ASSETS).then((c) => c.put(req, copy));
      }
      return res;
    })());
    return;
  }

  // รูปประกอบเว็บ — ใช้ของในแคชก่อน แล้วอัปเดตเบื้องหลัง (ไม่รวมรูปสลิป/ไฟล์ส่วนตัว ซึ่งไม่ได้อยู่ใต้ /images)
  if (url.pathname.startsWith('/images/') && !url.search) {
    event.respondWith((async () => {
      const hit = await caches.match(req);
      const net = fetch(req).then((res) => {
        if (cacheable(res)) {
          const copy = res.clone();
          caches.open(IMAGES).then((c) => c.put(req, copy)).then(() => trim(IMAGES, MAX_IMAGES));
        }
        return res;
      }).catch(() => hit);
      return hit || net;
    })());
  }
  // อย่างอื่นทั้งหมด (API, /wallet, /reading, /media, /videos, /storage …) ไม่แตะ
});

// ── แจ้งเตือน ───────────────────────────────────────────────────────────

function safeUrl(raw) {
  try {
    const u = new URL(raw || '/', self.location.origin);
    return u.origin === self.location.origin ? u.href : self.location.origin + '/';
  } catch (e) {
    return self.location.origin + '/';
  }
}

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) {
    data = { body: event.data ? event.data.text() : '' };
  }
  const title = data.title || 'จันทราพยากรณ์';
  event.waitUntil((async () => {
    // ผู้ใช้กำลังเปิดแอปอยู่ตรงหน้า → ยังโชว์ (Safari ถอนสิทธิ์ถ้าไม่โชว์) แต่ไม่ส่งเสียง/สั่น
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const looking = wins.some((c) => c.visibilityState === 'visible' && c.focused);
    await self.registration.showNotification(title, {
      body: data.body || '',
      icon: data.icon || '/images/pwa/icon-192.png',
      badge: data.badge || '/images/pwa/badge-96.png',
      tag: data.tag || undefined,
      renotify: Boolean(data.tag) && !looking,
      silent: looking,
      timestamp: data.ts || Date.now(),
      lang: 'th',
      data: { url: safeUrl(data.url), type: data.type || null },
    });
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = safeUrl(event.notification.data && event.notification.data.url);
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of wins) {
      if (new URL(c.url).origin !== self.location.origin) continue;
      try {
        // focus ก่อน — สิทธิ์ดึงหน้าต่างขึ้นหน้าจอหมดอายุถ้ารอ navigate เสร็จก่อน
        const win = await c.focus();
        if (c.url !== target && 'navigate' in (win || c)) await (win || c).navigate(target);
        return;
      } catch (e) { /* หน้าที่ไม่ได้อยู่ใต้ SW นำทางไม่ได้ — เปิดหน้าต่างใหม่แทน */ }
    }
    return self.clients.openWindow(target);
  })());
});

// push service หมุนกุญแจเครื่อง → สมัครใหม่ด้วยกุญแจเดิม · หน้าเว็บที่เปิดครั้งหน้าจะซิงก์ขึ้นเซิร์ฟเวอร์เอง
self.addEventListener('pushsubscriptionchange', (event) => {
  const opts = event.oldSubscription && event.oldSubscription.options;
  if (!opts || !opts.applicationServerKey) return;
  event.waitUntil(self.registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: opts.applicationServerKey,
  }).catch(() => {}));
});
