const slideInterval = 5500;
const fadeMs = 1200; // matches .hero-slide.is-active transition in juntra-payakorn.css

export default function heroSlides() {
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const pending = new Map();
    let slides, timer, onMotionChange, revision = 0, loading = false, switchedAt = -Infinity;

    const load = (img) => {
        if (img.dataset.src) {
            if (img.dataset.srcset) img.srcset = img.dataset.srcset;
            img.src = img.dataset.src;
            delete img.dataset.src;
            delete img.dataset.srcset;
        }
        return img.decode();
    };

    const ready = (index) => {
        if (!pending.has(index)) {
            const slide = slides[index];
            const portrait = slide.querySelector('.hero-slide-image');
            const ambient = slide.querySelector('.hero-slide-ambient');
            const request = Promise.all([
                load(portrait),
                ambient && load(ambient).catch(() => {}),
            ]).then(() => true, () => false).finally(() => pending.delete(index));
            pending.set(index, request);
        }
        return pending.get(index);
    };

    return {
        i: 0,
        previous: -1,
        init() {
            slides = [...this.$root.querySelectorAll('.hero-slide')];
            onMotionChange = () => {
                clearTimeout(timer);
                if (!motion.matches && !loading) this.schedule();
            };
            motion.addEventListener?.('change', onMotionChange);
            if (slides.length > 1) ready(0).then(() => {
                if (revision === 0) this.schedule();
            });
        },
        schedule(next = (this.i + 1) % slides.length) {
            clearTimeout(timer);
            if (slides.length < 2 || motion.matches || loading) return;
            // Start fetching only the next portrait; the first keeps network priority.
            timer = setTimeout(() => {
                if (!document.hidden && next !== this.i) ready(next);
                timer = setTimeout(() => {
                    if (document.hidden) this.schedule(next);
                    else if (next === this.i) this.schedule();
                    else this.go(next, true);
                }, slideInterval - 2000);
            }, 2000);
        },
        async go(index, automatic = false) {
            const current = ++revision;
            clearTimeout(timer);
            loading = true;
            let timeout;
            const loaded = index !== this.i && await Promise.race([
                ready(index),
                new Promise(resolve => { timeout = setTimeout(resolve, 15000, false); }),
            ]);
            clearTimeout(timeout);
            if (current !== revision) return; // A newer click wins over a slow request.
            // A switch during a fade would drop the fully visible slide underneath and
            // fade the new one in over a half-faded one (the hero dims). Let the running
            // fade finish first; at most fadeMs, and a newer click still wins.
            const fadeLeft = motion.matches ? 0 : switchedAt + fadeMs - performance.now();
            if (loaded && fadeLeft > 0) {
                await new Promise(resolve => { timer = setTimeout(resolve, fadeLeft); });
                if (current !== revision) return;
            }
            loading = false;
            if (loaded && (!automatic || (!motion.matches && !document.hidden))) {
                this.previous = this.i;
                this.i = index;
                switchedAt = performance.now();
                this.$dispatch('hero-slide', index);
            }
            this.schedule(automatic && !loaded ? (index + 1) % slides.length : undefined);
        },
        destroy() {
            ++revision;
            clearTimeout(timer);
            motion.removeEventListener?.('change', onMotionChange);
        },
    };
}
