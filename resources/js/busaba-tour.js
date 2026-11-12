// 🌸 (2026-11-12, owner) หน้าเลือกครั้งแรก + ทัวร์เว็บที่บุษบาพากย์เสียงเอง (ข้ามหน้า)
//
// ข้อมูลทั้งหมดมาจาก <script id="bt-data"> (resources/views/components/busaba-tour.blade.php)
//   steps: ข้อความ + ไฟล์เสียง + หน้าที่ต้องอยู่ + data-tour ของชิ้นที่ชี้ (config/busaba_tour.php)
//
// กติกาที่ต้องรู้ก่อนแก้:
//   - เสียง: มือถือ/แอป Facebook ไม่ยอมเล่นเสียงจนกว่าจะแตะ → ขั้นแรกเริ่มจากการแตะปุ่มเสมอ
//     ย้ายหน้าแล้วเบราว์เซอร์ลืมการแตะ → ลองเล่นเอง ไม่ได้ = ปุ่ม "แตะเพื่อฟัง" กะพริบ (ข้อความอยู่บนจอครบเสมอ)
//   - ขั้นปัจจุบันเก็บใน cookie `{cookie}_run` (ไม่ใช่ localStorage อย่างเดียว) — เซิร์ฟเวอร์ต้องรู้ว่าหน้าถัดไป
//     ต้องใส่ทัวร์ด้วย · จบ/ข้าม = ลบ cookie นี้ + จำว่าเลือกแล้ว (POST /tour/seen)
//   - คนที่ยังไม่ล็อกอิน: ขั้นของหน้าสมาชิก (auth) ไม่พาไปหน้านั้น (จะเด้งไปหน้าล็อกอิน) — ขึ้นเป็นการ์ดอธิบายแทน
//   - ทัวร์สอนใช้เว็บเท่านั้น ไม่มีคำทำนาย

const dataEl = document.getElementById('bt-data');

if (dataEl) {
    let D = null;
    try { D = JSON.parse(dataEl.textContent || '{}'); } catch (_) { D = null; }
    if (D && Array.isArray(D.steps) && D.steps.length) {
        boot(D);
    }
}

function boot(D) {
    const steps = D.steps;
    const last = steps.length - 1;
    const RUN_MAX_AGE = 60 * 60 * 3;           // ทัวร์ค้างได้ 3 ชม. (ไม่ค้างข้ามวัน)
    const SEEN_MAX_AGE = 60 * 60 * 24 * 365;

    // ── storage / cookie (FB in-app browser + โหมดส่วนตัว: ใช้ไม่ได้ต้องไม่พัง) ─────────
    const ls = {
        get(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (_) { /* ignore */ } },
    };
    const getCookie = (name) => {
        const hit = document.cookie.split('; ').find((c) => c.startsWith(name + '='));
        return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
    };
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    const setCookie = (name, value, maxAge) => {
        document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; SameSite=Lax${secure}`;
    };
    const clearRun = () => { document.cookie = `${D.run}=; Max-Age=0; Path=/; SameSite=Lax${secure}`; };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const markSeen = (choice) => {
        setCookie(D.cookie, choice, SEEN_MAX_AGE);
        ls.set(D.cookie, choice);
        try {
            fetch(D.seenUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ choice }),
            }).catch(() => {});
        } catch (_) { /* ignore */ }
    };

    // ── คลิปท่าทางพื้นใส (WebM VP9 alpha) — iPhone/iPad/Safari วาดเป็นพื้นดำ → ใช้ภาพนิ่ง ─────────
    const ua = navigator.userAgent || '';
    const isApple = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
        || (/Safari/.test(ua) && !/Chrome|Chromium|CriOS|Android|Edg/.test(ua));
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const canAlpha = !isApple && !reduceMotion && (() => {
        try { return document.createElement('video').canPlayType('video/webm; codecs="vp9"') !== ''; } catch (_) { return false; }
    })();

    const poseUrl = (name) => (canAlpha ? (D.poses?.[name] || D.poses?.wait || null) : null);

    // กรอบคลิปสูงกว่าภาพนิ่งกี่เท่า — CSS คูณขนาดคลิปด้วยค่านี้ ตัวในคลิปจึงทับภาพนิ่งพอดี (ไม่เห็นภาพซ้อน)
    document.documentElement.style.setProperty('--bt-ps', String(Number(D.poseScale) > 0 ? Number(D.poseScale) : 1));

    // คลิปเล่นจริงแล้วเท่านั้นถึงซ่อนภาพนิ่ง (.is-moving บนกล่องแม่) · หยุด/พัง/ซ่อน = ภาพนิ่งกลับมา
    const moving = (video, on) => video.parentElement?.classList.toggle('is-moving', on);
    const setPose = (video, name) => {
        const url = poseUrl(name);
        if (!video) return;
        if (!video.dataset.wired) {
            video.dataset.wired = '1';
            video.addEventListener('playing', () => { video.hidden = false; moving(video, true); });
            video.addEventListener('error', () => { video.hidden = true; moving(video, false); });
        }
        if (!url) { video.hidden = true; moving(video, false); return; }
        if (video.dataset.pose !== name) {
            video.dataset.pose = name;
            video.src = url;
        }
        video.hidden = false;
        const p = video.play();
        // AbortError = ถูกสั่งเล่นท่าใหม่ทับ (พูดจบ/เปลี่ยนขั้นไว ๆ) ไม่ใช่เล่นไม่ได้ — ห้ามซ่อน ไม่งั้นวงกลมว่าง
        if (p && p.catch) p.catch((e) => { if (e && e.name === 'AbortError') return; video.hidden = true; moving(video, false); });
    };

    // ── หน้าเลือกสองปุ่ม ───────────────────────────────────────────────────────────
    const welcome = document.getElementById('bt-welcome');
    const runIdx = parseInt(getCookie(D.run) ?? '', 10);
    const running = Number.isInteger(runIdx) && runIdx >= 0 && runIdx <= last;

    if (welcome && D.offer && !running && !D.start) {
        if (ls.get(D.cookie)) {
            // เลือกไปแล้วบนเครื่องนี้ (cookie หาย / คนละโดเมนย่อย) — จำกลับเข้า cookie แล้วไม่ขึ้น
            setCookie(D.cookie, ls.get(D.cookie), SEEN_MAX_AGE);
            welcome.remove();
        } else {
            openWelcome();
        }
    }

    function openWelcome() {
        welcome.hidden = false;
        requestAnimationFrame(() => welcome.classList.add('is-open'));
        const box = welcome.querySelector('.bt-welcome-busaba');
        if (box && canAlpha && D.poses?.wait) {
            const v = document.createElement('video');
            v.className = 'bt-pose';
            v.muted = true; v.loop = true; v.playsInline = true; v.setAttribute('playsinline', ''); v.setAttribute('aria-hidden', 'true');
            box.appendChild(v);
            setPose(v, 'wait');
        }
        welcome.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-bt]');
            if (!btn) return;
            const choice = btn.dataset.bt;
            markSeen(choice);
            if (choice === 'free') {
                btn.classList.add('is-busy');
                location.href = D.freeUrl;
                return;
            }
            closeWelcome();
            if (choice === 'tour') tour.start(0);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !welcome.hidden) { markSeen('later'); closeWelcome(); }
        });
        welcome.querySelector('.bt-choice-free')?.focus({ preventScroll: true });
    }

    function closeWelcome() {
        welcome.classList.remove('is-open');
        setTimeout(() => welcome.remove(), 250);
    }

    // ── ตัวทัวร์ ─────────────────────────────────────────────────────────────────
    const tour = makeTour();

    if (D.start) {
        // ?tour=1 — ขอดูซ้ำ: ขึ้นการ์ดเริ่ม (ยังไม่มีการแตะ = ยังเล่นเสียงไม่ได้)
        tour.ready(0);
    } else if (running) {
        tour.resume(runIdx);
    }

    function makeTour() {
        let root = null;
        let idx = 0;
        let target = null;
        let raf = 0;
        let muted = ls.get('jw-tour-mute') === '1';
        const audio = new Audio();
        audio.preload = 'auto';

        const $ = (sel) => root.querySelector(sel);
        const samePage = (step) => !step.path || normPath(step.path) === normPath(location.pathname);
        const preview = (step) => step.auth && D.guest;

        function build() {
            if (root) return;
            root = document.createElement('div');
            root.className = 'bt-tour';
            root.innerHTML = `
                <div class="bt-veil" aria-hidden="true"></div>
                <div class="bt-spot" aria-hidden="true"></div>
                <section class="bt-dock" role="dialog" aria-modal="false" aria-labelledby="bt-title" aria-live="polite">
                    <div class="bt-avatar" aria-hidden="true">
                        <span class="bt-av-face">
                            <img class="bt-av-still" src="${escAttr(D.still)}" alt="" decoding="async">
                            <video class="bt-av-pose" muted loop playsinline hidden></video>
                        </span>
                        <span class="bt-wave"><i></i><i></i><i></i></span>
                    </div>
                    <div class="bt-main">
                        <div class="bt-meta">
                            <span class="bt-who">บุษบาพาทัวร์</span>
                            <span class="bt-count"></span>
                            <button type="button" class="bt-icon bt-mute" aria-pressed="false"></button>
                            <button type="button" class="bt-icon bt-close" aria-label="ข้ามทัวร์">ข้าม ×</button>
                        </div>
                        <h3 class="bt-title" id="bt-title"></h3>
                        <p class="bt-text"></p>
                        <p class="bt-lock" hidden>🔒 หน้านี้เป็นของสมาชิก — เข้าสู่ระบบแล้วจะเจอตรงนี้ค่ะ</p>
                        <div class="bt-steps" aria-hidden="true"><i></i></div>
                        <div class="bt-nav">
                            <button type="button" class="bt-btn bt-back" aria-label="ขั้นก่อนหน้า">‹ ย้อน</button>
                            <button type="button" class="bt-btn bt-play">🔊 ฟังอีกครั้ง</button>
                            <button type="button" class="bt-btn bt-next">ถัดไป ›</button>
                        </div>
                        <div class="bt-end" hidden>
                            <a class="bt-btn bt-next bt-end-free" href="${escAttr(D.freeUrl)}">${D.freeOn ? '🎁 ดูดวงฟรีเลย' : '🔮 เข้าห้องบุษบา'}</a>
                            <a class="bt-btn bt-end-room" href="${escAttr(D.roomUrl)}">💬 เข้าห้องบุษบา</a>
                        </div>
                    </div>
                </section>`;
            document.body.appendChild(root);
            if (!D.freeOn) $('.bt-end-room').remove();

            $('.bt-next:not(.bt-end-free)').addEventListener('click', () => go(idx + 1));
            $('.bt-back').addEventListener('click', () => go(idx - 1));
            $('.bt-play').addEventListener('click', () => speak(true));
            $('.bt-close').addEventListener('click', () => finish('skip'));
            $('.bt-mute').addEventListener('click', toggleMute);
            root.querySelectorAll('.bt-end a').forEach((a) => a.addEventListener('click', () => finish('done', false)));
            $('.bt-veil').addEventListener('click', () => {
                const dock = $('.bt-dock');
                dock.classList.remove('is-nudge');
                void dock.offsetWidth;
                dock.classList.add('is-nudge');
            });
            document.addEventListener('keydown', onKey);
            window.addEventListener('resize', place, { passive: true });

            audio.addEventListener('playing', () => talking(true));
            audio.addEventListener('pause', () => talking(false));
            audio.addEventListener('ended', () => {
                talking(false);
                $('.bt-next')?.classList.add('is-pulse');
            });
            paintMute();
            requestAnimationFrame(() => root.classList.add('is-open'));
        }

        function onKey(e) {
            if (!root) return;
            if (e.key === 'Escape') finish('skip');
            else if (e.key === 'ArrowRight' && idx < last) go(idx + 1);
            else if (e.key === 'ArrowLeft' && idx > 0) go(idx - 1);
        }

        function talking(on) {
            if (!root) return;
            root.classList.toggle('is-talking', on);
            setPose($('.bt-av-pose'), on ? steps[idx].pose : 'wait');
        }

        function paintMute() {
            const b = $('.bt-mute');
            b.textContent = muted ? '🔇' : '🔊';
            b.setAttribute('aria-pressed', muted ? 'true' : 'false');
            b.setAttribute('aria-label', muted ? 'เปิดเสียงบุษบา' : 'ปิดเสียงบุษบา');
            $('.bt-play').hidden = muted;
        }

        function toggleMute() {
            muted = !muted;
            ls.set('jw-tour-mute', muted ? '1' : '0');
            paintMute();
            if (muted) audio.pause();
            else speak(true);
        }

        function speak(fromTap) {
            const step = steps[idx];
            root.classList.remove('needs-tap');
            if (muted || !step.audio) { talking(false); return; }
            if (!audio.src.endsWith(step.audio) || fromTap) {
                audio.src = step.audio;
            }
            try { audio.currentTime = 0; } catch (_) { /* not loaded yet */ }
            const p = audio.play();
            if (p && p.catch) {
                p.catch(() => {
                    // ย้ายหน้ามาแล้วเบราว์เซอร์ไม่ให้เล่นเอง — ให้แตะ
                    root.classList.add('needs-tap');
                    $('.bt-play').textContent = '▶ แตะฟังเสียง';
                });
            }
        }

        // ไปขั้น i — คนละหน้า = ย้ายหน้า (ขั้นถัดไปเล่นต่อที่หน้านั้น)
        function go(i) {
            if (i < 0 || i > last) return;
            const step = steps[i];
            if (step.url && !preview(step) && !samePage(step)) {
                audio.pause();
                setCookie(D.run, String(i), RUN_MAX_AGE);
                root?.classList.add('is-leaving');
                location.href = step.url;
                return;
            }
            show(i, true);
        }

        function show(i) {
            build();
            idx = i;
            const step = steps[i];
            const isLast = i === last;
            setCookie(D.run, String(i), RUN_MAX_AGE);

            root.classList.remove('is-wander', 'is-ready');
            $('.bt-count').textContent = `${i + 1}/${steps.length}`;
            $('.bt-title').textContent = `${step.icon} ${step.title}`;
            $('.bt-text').textContent = step.text;
            $('.bt-lock').hidden = !preview(step);
            $('.bt-steps i').style.width = `${((i + 1) / steps.length) * 100}%`;
            $('.bt-back').disabled = i === 0;
            $('.bt-nav').hidden = isLast;
            $('.bt-end').hidden = !isLast;
            $('.bt-next')?.classList.remove('is-pulse');
            $('.bt-play').textContent = '🔊 ฟังอีกครั้ง';

            if (isLast) {
                // ถึงขั้นสุดท้าย = จบทัวร์แล้ว (เปิดหน้าใหม่ไม่ขึ้นทัวร์ซ้ำ) — การ์ดยังอยู่ให้กดไปดูดวงฟรี
                clearRun();
                markSeen('done');
            }

            menu(step.menu && !preview(step));
            // เมนูมือถือเปิดเป็นแผงเลื่อนลง — รอให้กางเสร็จก่อนหาตำแหน่ง
            setTimeout(() => aim(step), step.menu ? 260 : 30);
            speak(false);
        }

        function aim(step) {
            target = null;
            if (step.target && !preview(step)) {
                target = [...document.querySelectorAll(`[data-tour="${cssEsc(step.target)}"]`)].find(visible) || null;
            }
            if (target) {
                // ชิ้นที่สูงเกินครึ่งจอ (การ์ดห้องบนมือถือ) → ชิดบนใต้แถบเมนู ให้เห็นหัวการ์ด/ชื่อห้องก่อน
                const tall = isTall(target);
                if (tall) {
                    const nav = document.querySelector('nav.top, [data-tour-nav]');
                    const navH = nav ? Math.round(nav.getBoundingClientRect().height) : 0;
                    target.style.scrollMarginTop = `${navH + 12}px`;
                }
                try { target.scrollIntoView({ block: tall ? 'start' : 'center', inline: 'center', behavior: reduceMotion ? 'auto' : 'smooth' }); }
                catch (_) { target.scrollIntoView(); }
            }
            root.classList.toggle('is-centered', !target);
            loop();
        }

        // วาดกรอบสปอตไลต์ตามชิ้นที่ชี้ทุกเฟรม (เลื่อนจอ / แถบปัดห้อง / เมนูกาง) — หยุดเมื่อทัวร์ปิด
        function loop() {
            cancelAnimationFrame(raf);
            const tick = () => { place(); raf = requestAnimationFrame(tick); };
            raf = requestAnimationFrame(tick);
        }

        function place() {
            if (!root) return;
            const spot = $('.bt-spot');
            const dock = $('.bt-dock');
            if (!target || !visible(target)) {
                spot.style.opacity = '0';
                dock.classList.remove('is-top');
                return;
            }
            const r = target.getBoundingClientRect();
            const pad = 8;
            const top = Math.max(4, r.top - pad);
            const left = Math.max(4, r.left - pad);
            const width = Math.min(window.innerWidth - left - 4, r.width + pad * 2);
            const height = Math.min(window.innerHeight - top - 4, r.height + pad * 2);
            spot.style.opacity = '1';
            spot.style.transform = `translate(${left}px, ${top}px)`;
            spot.style.width = `${Math.max(24, width)}px`;
            spot.style.height = `${Math.max(24, height)}px`;
            // มือถือ: ชิ้นที่ชี้อยู่ครึ่งล่างของจอ → ย้ายการ์ดบุษบาขึ้นบน ไม่ให้บังของที่ชี้
            // ชิ้นที่สูงเกินจอ: หัวของมันอยู่บน → การ์ดบุษบาอยู่ล่างเสมอ (ไม่บังชื่อห้อง)
            const narrow = window.innerWidth < 768;
            const lowHalf = isTall(target) ? r.top > window.innerHeight * 0.5 : (r.top + r.height / 2) > window.innerHeight * 0.5;
            dock.classList.toggle('is-top', narrow && lowHalf);
        }

        function isTall(el) {
            return el.getBoundingClientRect().height > window.innerHeight * 0.55;
        }

        function menu(open) {
            const nav = document.querySelector('[data-tour-nav]');
            const burger = document.querySelector('[data-tour="burger"]');
            if (!nav || !burger || !visible(burger) || !window.Alpine) return;
            // เปิดหลังคลิกนี้จบ — click.outside ของเมนูจะปิดเมนูทันทีถ้าเปิดในคลิกเดียวกัน
            setTimeout(() => {
                try { window.Alpine.$data(nav).open = open; } catch (_) { /* ignore */ }
            }, 60);
        }

        function finish(choice, close = true) {
            audio.pause();
            cancelAnimationFrame(raf);
            clearRun();
            markSeen(choice);
            menu(false);
            if (!close || !root) return;
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', place);
            root.classList.remove('is-open');
            const r = root;
            root = null;
            setTimeout(() => r.remove(), 250);
        }

        return {
            // เริ่มจากการแตะปุ่ม (มีการแตะ = เล่นเสียงได้) — อยู่หน้าไหนก็เริ่มที่นั่น ขั้นแรกไม่ผูกหน้า
            start(i) { show(i, true); },

            // ?tour=1 — การ์ดเริ่ม รอแตะ
            ready(i) {
                show(i, false);
                audio.pause();
                root.classList.add('is-ready', 'needs-tap');
                $('.bt-play').textContent = '▶ เริ่มทัวร์กับบุษบา';
                $('.bt-play').hidden = false;
            },

            // ย้ายหน้ามาแล้ว — ขั้นนี้อยู่หน้านี้ไหม (ลูกค้าอาจกดลิงก์อื่นระหว่างทัวร์)
            resume(i) {
                const step = steps[i];
                if (preview(step) || samePage(step)) {
                    show(i, false);
                    return;
                }
                build();
                idx = i;
                root.classList.add('is-wander', 'is-centered');
                $('.bt-count').textContent = `${i + 1}/${steps.length}`;
                $('.bt-title').textContent = 'ทัวร์ของบุษบายังค้างอยู่';
                $('.bt-text').textContent = `ขั้นถัดไป: ${step.title} — ไปต่อเลยไหมคะ`;
                $('.bt-lock').hidden = true;
                $('.bt-end').hidden = true;
                $('.bt-play').hidden = true;
                $('.bt-back').disabled = true;
                const next = $('.bt-next');
                next.textContent = 'ไปต่อ ›';
                next.addEventListener('click', (e) => {
                    e.stopImmediatePropagation();
                    setCookie(D.run, String(i), RUN_MAX_AGE);
                    location.href = step.url;
                }, { capture: true, once: true });
            },
        };
    }
}

function visible(el) {
    if (!el || !el.isConnected) return false;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    const cs = getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0.05;
}

function normPath(p) {
    const s = String(p || '/').replace(/\/+$/, '');
    return s === '' ? '/' : s;
}

function escAttr(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function cssEsc(s) {
    return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/[^a-zA-Z0-9_-]/g, '');
}
