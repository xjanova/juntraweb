/*
 * ผังสายงาน — เลื่อน · ซูม · เต็มจอ (ใช้ร่วมกัน: หน้า /mlm และหน้าผังสายงานในหลังบ้าน) — ไม่ต้อง build
 *
 * เจ้าของสั่ง (2026-09-23): "ผังสายงานแม่หมอ ควรดูเต็มจอได้ ซูม ขยาย/ย่อ ได้ด้วยลูกกลิ้งเมาส์"
 *
 *   const pz = OrgPanZoom(viewport, canvas, { fitMin: 0.5, onChange: z => …, fullscreenTarget: panel, onFullscreen: on => … })
 *   pz.fit() · pz.zoomBy(1.2) · pz.toggleFullscreen() · pz.exitFullscreen() · pz.destroy()
 *
 *   - ลูกกลิ้งเมาส์ = ซูมเข้า/ออกตรงจุดที่เมาส์ชี้ (ไม่เลื่อนหน้าเว็บขณะชี้อยู่ในผัง)
 *   - ลาก (เมาส์/นิ้ว) = เลื่อนผัง · ขยับไม่ถึง 4px ถือเป็นคลิก การ์ดยังกดดูรายละเอียดได้
 *   - สองนิ้ว = ซูมบนมือถือ
 *   - เต็มจอ: Fullscreen API · เบราว์เซอร์ที่ไม่รองรับ (iPhone) = ขยายเต็มหน้าแทน กด Esc/ปุ่มเดิมเพื่อออก
 *
 * viewport ต้อง overflow:hidden + position:relative · canvas ต้อง position:absolute; left:0; top:0
 */
(function () {
  'use strict';

  var STYLE_ID = 'orgpz-style';

  function injectStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent =
      '.orgpz-fallback{position:fixed!important;inset:0!important;z-index:2147483000!important;' +
      'margin:0!important;border-radius:0!important;max-width:none!important;overflow:auto}' +
      'html.orgpz-lock,html.orgpz-lock body{overflow:hidden!important}';
    document.head.appendChild(s);
  }

  function OrgPanZoom(viewport, canvas, opts) {
    opts = opts || {};
    var min = opts.min || 0.15;
    var max = opts.max || 3;
    var fitMin = opts.fitMin || 0.5;
    var noPan = opts.noPan || 'a, button, input, select, textarea, label, [data-no-pan]';
    var fsTarget = opts.fullscreenTarget || viewport.parentElement;
    var onChange = opts.onChange || function () {};
    var onFullscreen = opts.onFullscreen || function () {};

    var z = 1, x = 0, y = 0;
    var fallback = false;
    var fsTimer = null, destroyed = false;
    var pendingFit = false; // สั่งจัดตอนกรอบยังซ่อน/ยังไม่มีขนาด — จัดให้ตอนกรอบโผล่
    var moved = false;      // ผู้ใช้ลาก/ซูมเองแล้ว — กรอบเปลี่ยนขนาด (หมุนจอ) ก็ไม่จัดทับให้

    injectStyle();
    canvas.style.transformOrigin = '0 0';

    function clamp(v) { return Math.min(max, Math.max(min, v)); }

    function apply() {
      canvas.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + z + ')';
      onChange(z);
    }

    function zoomAt(nz, cx, cy) {
      moved = true;
      nz = clamp(nz);
      var k = nz / z;
      x = cx - (cx - x) * k;
      y = cy - (cy - y) * k;
      z = nz;
      apply();
    }

    function zoomBy(factor) {
      zoomAt(z * factor, viewport.clientWidth / 2, viewport.clientHeight / 2);
    }

    // ทั้งผังอยู่ในกรอบ (ไม่ขยายเกิน 100%) แต่ไม่ย่อต่ำกว่า fitMin จนอ่านชื่อไม่ออก
    // ผังกว้างกว่านั้น = ต้นสายอยู่กลางด้านบน ลากหรือหมุนลูกกลิ้งดูส่วนที่เหลือเอง
    // กรอบยังซ่อนอยู่ (x-show ของ Alpine โชว์ช้ากว่า $nextTick หนึ่งเฟรม) = จำไว้ แล้วจัดตอนกรอบมีขนาดจริง
    function fit() {
      var vw = viewport.clientWidth, vh = viewport.clientHeight;
      var cw = canvas.offsetWidth, ch = canvas.offsetHeight;
      if (!vw || !vh || !cw || !ch) {
        pendingFit = true;
        return;
      }
      pendingFit = false;
      moved = false;
      z = clamp(Math.max(Math.min((vw - 32) / cw, (vh - 32) / ch, 1), Math.min(fitMin, 1)));
      x = (vw - cw * z) / 2;
      y = 8;
      apply();
    }

    var sizeWatch = window.ResizeObserver ? new ResizeObserver(function (entries) {
      var frameChanged = entries.some(function (en) { return en.target === viewport; });
      if (pendingFit || (frameChanged && !moved)) fit();
    }) : null;
    if (sizeWatch) {
      sizeWatch.observe(viewport);
      sizeWatch.observe(canvas);
    }

    /* ── ลูกกลิ้งเมาส์ ── */
    function onWheel(e) {
      e.preventDefault();
      var r = viewport.getBoundingClientRect();
      var unit = e.deltaMode === 1 ? 33 : (e.deltaMode === 2 ? r.height : 1);
      zoomAt(z * Math.exp(-e.deltaY * unit * 0.0015), e.clientX - r.left, e.clientY - r.top);
    }

    /* ── ลาก / สองนิ้ว ── */
    var pts = new Map();
    var start = null, last = null, panning = false, pinch = null, suppressUntil = 0;

    function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }

    function capture(e) {
      try { viewport.setPointerCapture(e.pointerId); } catch (_) { /* ไม่เป็นไร */ }
    }

    function onDown(e) {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      if (e.target.closest && e.target.closest(noPan)) return;
      pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pts.size === 1) {
        start = { x: e.clientX, y: e.clientY };
        last = { x: e.clientX, y: e.clientY };
        panning = false;
      } else if (pts.size === 2) {
        var p = Array.from(pts.values());
        pinch = { d: dist(p[0], p[1]) || 1, z: z };
        panning = true;
        capture(e);
      }
    }

    function onMove(e) {
      if (!pts.has(e.pointerId)) return;
      pts.set(e.pointerId, { x: e.clientX, y: e.clientY });

      if (pts.size >= 2 && pinch) {
        var p = Array.from(pts.values());
        var r = viewport.getBoundingClientRect();
        zoomAt(pinch.z * dist(p[0], p[1]) / pinch.d, (p[0].x + p[1].x) / 2 - r.left, (p[0].y + p[1].y) / 2 - r.top);
        return;
      }
      if (!start) return;
      if (!panning) {
        if (Math.hypot(e.clientX - start.x, e.clientY - start.y) < 4) return;
        panning = true;
        moved = true;
        capture(e);
        viewport.classList.add('is-panning');
      }
      x += e.clientX - last.x;
      y += e.clientY - last.y;
      last = { x: e.clientX, y: e.clientY };
      apply();
    }

    function onUp(e) {
      if (!pts.has(e.pointerId)) return;
      pts.delete(e.pointerId);
      if (pts.size < 2) pinch = null;
      if (pts.size === 1) {
        var p = Array.from(pts.values())[0];
        start = { x: p.x, y: p.y };
        last = { x: p.x, y: p.y };
      }
      if (pts.size === 0) {
        // เพิ่งลากเสร็จ — คลิกที่ตามมาไม่ใช่การกดการ์ด
        if (panning) suppressUntil = performance.now() + 300;
        panning = false;
        start = null;
        last = null;
        viewport.classList.remove('is-panning');
      }
    }

    function onClickCapture(e) {
      if (performance.now() < suppressUntil) {
        e.stopPropagation();
        e.preventDefault();
        suppressUntil = 0;
      }
    }

    /* ── เต็มจอ ── */
    function isFullscreen() {
      return document.fullscreenElement === fsTarget || fallback;
    }

    function syncFullscreen() {
      var on = isFullscreen();
      fsTarget.classList.toggle('is-fullscreen', on);
      fsTarget.classList.toggle('orgpz-fallback', fallback);
      document.documentElement.classList.toggle('orgpz-lock', fallback);
      onFullscreen(on);
      // จัดผังใหม่ตอนกรอบขยาย/หดเสร็จ (ResizeObserver) · สองเฟรมถัดไปจัดซ้ำอีกรอบเผื่อขนาดไม่เปลี่ยน
      pendingFit = true;
      requestAnimationFrame(function () { requestAnimationFrame(fit); });
    }

    function useFallback() {
      fallback = true;
      syncFullscreen();
    }

    function enterFullscreen() {
      if (!fsTarget.requestFullscreen || !document.fullscreenEnabled) return useFallback();

      // เบราว์เซอร์ในแอปบางตัวรับคำขอแล้วเงียบ ไม่เข้าเต็มจอและไม่ตอบ (เจอจริงใน webview) —
      // รอ 1 วินาทีแล้วขยายเต็มหน้าแทน · เต็มจอจริงมาทีหลังก็ยังใช้ได้ (onFullscreenChange ปิดแบบขยายเอง)
      var settled = false;
      var giveUp = function () {
        if (settled || destroyed) return; // ผังถูกลบไปก่อน (เปลี่ยนคน/ความลึก) — ห้ามไปล็อกการเลื่อนหน้าค้างไว้
        settled = true;
        if (document.fullscreenElement !== fsTarget) useFallback();
      };
      fsTarget.requestFullscreen().then(function () { settled = true; }, giveUp);
      fsTimer = setTimeout(giveUp, 1000);
    }

    function exitFullscreen() {
      if (document.fullscreenElement === fsTarget && document.exitFullscreen) document.exitFullscreen();
      if (fallback) {
        fallback = false;
        syncFullscreen();
      }
    }

    function toggleFullscreen() {
      if (isFullscreen()) exitFullscreen(); else enterFullscreen();
    }

    function onFullscreenChange() {
      if (document.fullscreenElement === fsTarget) fallback = false;
      syncFullscreen();
    }

    function onKey(e) {
      if (fallback && e.key === 'Escape') exitFullscreen();
    }

    viewport.addEventListener('wheel', onWheel, { passive: false });
    viewport.addEventListener('pointerdown', onDown);
    viewport.addEventListener('pointermove', onMove);
    viewport.addEventListener('pointerup', onUp);
    viewport.addEventListener('pointercancel', onUp);
    viewport.addEventListener('click', onClickCapture, true);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('keydown', onKey);

    return {
      fit: fit,
      zoomBy: zoomBy,
      zoomAt: zoomAt,
      zoom: function () { return z; },
      toggleFullscreen: toggleFullscreen,
      exitFullscreen: exitFullscreen,
      isFullscreen: isFullscreen,
      destroy: function () {
        if (isFullscreen()) exitFullscreen();
        destroyed = true;
        clearTimeout(fsTimer);
        if (sizeWatch) sizeWatch.disconnect();
        viewport.removeEventListener('wheel', onWheel);
        viewport.removeEventListener('pointerdown', onDown);
        viewport.removeEventListener('pointermove', onMove);
        viewport.removeEventListener('pointerup', onUp);
        viewport.removeEventListener('pointercancel', onUp);
        viewport.removeEventListener('click', onClickCapture, true);
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('keydown', onKey);
      },
    };
  }

  window.OrgPanZoom = OrgPanZoom;
})();
