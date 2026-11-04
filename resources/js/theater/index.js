// 🎭 โรงละครคำทำนาย — ตัวคุมหลัก (entry ของ Vite แยกไฟล์ · หน้าเว็บ import เมื่อเจ้าของคำทำนายจะเปิดเท่านั้น)
//
// ไฟล์นี้เล็ก (ไม่มี three) — โหลดล่วงหน้าตอนเครื่องว่างได้ เพื่อให้การแตะ "ฟังคำทำนาย" เริ่มเสียงได้ทันที
// ในจังหวะแตะเดียวกัน (iPhone ปลดล็อกเสียงเฉพาะในอีเวนต์แตะ) · three โหลดทีหลังเฉพาะเครื่องที่ใช้ WebGL
//
// เลือกเวที: ตั้งลดการเคลื่อนไหว / ไม่มี WebGL2 / ตัวเรนเดอร์ซอฟต์แวร์ → แบบ CSS ทันที
//            WebGL ช้าจนตัวคุมคุณภาพถอยสุด / context หาย → สลับเป็นแบบ CSS กลางเรื่องได้ (เวลาเดิม บทเดิม)

import { Timeline, revealPlan, visualState } from './timeline.js';
import { AudioRig } from './audio.js';
import { createCSSStage } from './css/stage.js';

export function webglUsable() {
    try {
        const c = document.createElement('canvas');
        const gl = c.getContext('webgl2', { failIfMajorPerformanceCaveat: true });
        if (!gl) {
            return false;
        }
        const ext = gl.getExtension('WEBGL_debug_renderer_info');
        const name = ext ? String(gl.getParameter(ext.UNMASKED_RENDERER_WEBGL)) : '';
        gl.getExtension('WEBGL_lose_context')?.loseContext();
        return !/swiftshader|llvmpipe|software|basic render/i.test(name);
    } catch (e) {
        return false;
    }
}

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * @param {{host:HTMLElement, cfg:object, ui:object, autoplay?:boolean, startIndex?:number, forceCss?:boolean}} o
 *   ui: onChapter(i), onTick({time,duration,progress,playing}), onPlaying(bool), onStage(mode), onReady(), onOutro()
 */
export function openTheater({ host, cfg, ui, autoplay = true, startIndex = 0, forceCss = false }) {
    const voice = cfg.voice && cfg.voice.chapters && cfg.voice.chapters.length ? cfg.voice : null;
    const rig = new AudioRig({ voiceUrl: voice ? voice.url : null, music: cfg.music || [] });
    const timeline = new Timeline(voice ? voice.chapters : cfg.chapters, rig.narration);
    const chapters = timeline.chapters;
    const plan = revealPlan(chapters, cfg.cards.map((c) => c.pos));

    let stage = null;
    let disposed = false;
    let lastIndex = -1;
    let outroSent = false;
    let safe = { top: 0, bottom: 0 };

    const state = () => {
        const loc = timeline.locate();
        const vs = visualState(chapters, plan, loc.index, loc.progress);
        vs.playing = timeline.playing;
        return vs;
    };

    const hooks = {
        getState: state,
        amplitude: () => rig.amplitude(),
        safe: () => safe,
        onFallback: (why) => {
            if (disposed || !stage || stage.mode === 'css') {
                return;
            }
            console.info('[theater] เปลี่ยนเป็นแบบ CSS:', why);
            stage.dispose();
            stage = createCSSStage(host, cfg, hooks);
            ui.onStage && ui.onStage('css');
            tick(true);
        },
    };

    const tick = (force = false) => {
        if (disposed) {
            return;
        }
        const loc = timeline.locate();
        if (loc.index !== lastIndex || force) {
            lastIndex = loc.index;
            ui.onChapter && ui.onChapter(loc.index);
            stage && stage.wake();
            if (chapters[loc.index].kind === 'outro' && !outroSent) {
                outroSent = true;
                ui.onOutro && ui.onOutro();
                // 🪝 ให้แผงแชทในโรงละคร (งานของ session เสียง) ฟังอีเวนต์นี้แล้วเลื่อนขึ้นมาเอง
                window.dispatchEvent(new CustomEvent('reading-theater:outro', { detail: { readingId: cfg.id } }));
            }
        }
        ui.onTick && ui.onTick({ time: loc.time, duration: timeline.duration, progress: loc.progress, index: loc.index, playing: timeline.playing });
        if (stage && stage.render) {
            stage.render(state());
        }
    };
    const timer = setInterval(tick, 250);

    const onAudio = () => {
        rig.sync();
        ui.onPlaying && ui.onPlaying(timeline.playing);
        tick();
    };
    if (rig.narration) {
        ['play', 'pause', 'ended', 'seeked', 'loadedmetadata'].forEach((ev) => rig.narration.addEventListener(ev, onAudio));
    }

    // ── เริ่มในจังหวะแตะเดียวกัน (ก่อน await ใด ๆ) ──
    rig.unlock();
    if (startIndex > 0) {
        timeline.seekChapter(startIndex);
    }
    if (autoplay) {
        Promise.resolve(timeline.play()).catch(() => ui.onPlaying && ui.onPlaying(false)).then(onAudio);
    }

    // ── เวที ──
    const useGl = !forceCss && !reducedMotion() && webglUsable();
    const ready = (async () => {
        if (useGl) {
            try {
                const { createGLStage } = await import('./gl/stage.js');
                if (disposed) {
                    return;
                }
                stage = await createGLStage(host, cfg, hooks);
                if (disposed) {
                    stage.dispose();
                    return;
                }
                ui.onStage && ui.onStage('gl');
            } catch (e) {
                console.warn('[theater] WebGL ใช้ไม่ได้ ใช้แบบ CSS แทน', e);
                stage = null;
            }
        }
        if (!stage && !disposed) {
            stage = createCSSStage(host, cfg, hooks);
            ui.onStage && ui.onStage('css');
        }
        tick(true);
        ui.onReady && ui.onReady();
    })();

    const go = (i) => {
        timeline.seekChapter(i);
        if (!timeline.playing) {
            // กดเลือกบทตอนหยุดอยู่ = เล่นบทนั้นเลย (แตะ = ปลดล็อกเสียงไปแล้ว)
            Promise.resolve(timeline.play()).catch(() => {}).then(onAudio);
        }
        tick(true);
    };

    return {
        chapters,
        audioMode: !!voice,
        hasMusic: rig.hasMusic,
        musicOn: rig.musicOn,
        ready,
        get index() {
            return timeline.locate().index;
        },
        play() {
            rig.unlock();
            return Promise.resolve(timeline.play()).catch(() => {}).then(onAudio);
        },
        pause() {
            timeline.pause();
            onAudio();
        },
        toggle() {
            return timeline.playing ? this.pause() : this.play();
        },
        next() {
            go(Math.min(chapters.length - 1, timeline.locate().index + 1));
        },
        prev() {
            const loc = timeline.locate();
            // กลางบทเกิน 3 วิ = กลับต้นบทเดิม (แบบเครื่องเล่นเพลงทั่วไป)
            const back = loc.time - chapters[loc.index].start > 3 && timeline.audio ? loc.index : loc.index - 1;
            go(Math.max(0, back));
        },
        go,
        seekFraction(f) {
            timeline.seek(f * timeline.duration);
            tick(true);
        },
        setRate(r) {
            timeline.setRate(r);
        },
        setMusic(on) {
            rig.setMusic(on);
        },
        /** แตะเวที → บทของไพ่/จุดกราฟที่โดน (ไม่โดนอะไร = null) */
        pickChapter(x, y) {
            const pos = stage && stage.pick ? stage.pick(x, y) : null;
            if (pos == null) {
                return null;
            }
            const i = chapters.findIndex((c) => c.kind === 'card' && c.pos === pos);
            return i >= 0 ? i : null;
        },
        setSafe(s) {
            safe = s;
            stage && stage.resize && stage.resize();
        },
        info() {
            return stage ? { mode: stage.mode, ...stage.info() } : null;
        },
        dispose() {
            disposed = true;
            clearInterval(timer);
            if (rig.narration) {
                ['play', 'pause', 'ended', 'seeked', 'loadedmetadata'].forEach((ev) => rig.narration.removeEventListener(ev, onAudio));
            }
            rig.dispose();
            stage && stage.dispose();
            stage = null;
        },
    };
}

// หน้าเว็บ (Alpine ใน components/reading-theater) เรียกผ่านตัวแปรนี้ — โมดูลโหลดแบบ defer จึงแจ้งเมื่อพร้อม
window.JuntraTheater = { openTheater, webglUsable };
window.dispatchEvent(new Event('juntra-theater:loaded'));
