import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

document.addEventListener('DOMContentLoaded', () => {
    const wrap = document.getElementById('twinkle');
    if (wrap) {
        for (let i = 0; i < 60; i++) {
            const s = document.createElement('i');
            s.style.left = Math.random() * 100 + '%';
            s.style.top = Math.random() * 100 + '%';
            s.style.animationDelay = (Math.random() * 3) + 's';
            s.style.animationDuration = (2 + Math.random() * 4) + 's';
            s.style.opacity = (0.3 + Math.random() * 0.7);
            wrap.appendChild(s);
        }
    }

    document.querySelectorAll('.tarot').forEach(card => {
        card.addEventListener('click', () => card.classList.toggle('flipped'));
    });

    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.style.opacity = '1';
                e.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.feature, .service, .test, .zodiac-list .z').forEach((el, i) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = `all .8s cubic-bezier(.2,.7,.2,1) ${i * 0.05}s`;
        io.observe(el);
    });

    window.addEventListener('mousemove', (e) => {
        const x = (e.clientX / window.innerWidth - 0.5);
        const y = (e.clientY / window.innerHeight - 0.5);
        const portrait = document.querySelector('.chantra-portrait');
        if (portrait) portrait.style.transform = `translate(${x * -12}px, ${y * -12}px)`;
        document.querySelectorAll('.float-card').forEach((c, i) => {
            const k = (i + 1) * 14;
            c.style.setProperty('--mx', `${x * k}px`);
            c.style.setProperty('--my', `${y * k}px`);
        });
    });
});

Alpine.start();
