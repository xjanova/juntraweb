import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // 🔴 (2026-11-04) แผงตัวเลขสดหน้าห้อง (components/live-room-stats) — เลขวิบวับ / ดาวกระพริบ / แถบสแกนคิว
            keyframes: {
                shimmer: { '0%': { backgroundPosition: '200% 0' }, '100%': { backgroundPosition: '-200% 0' } },
                twinkle: { '0%, 100%': { opacity: '1', transform: 'scale(1)' }, '50%': { opacity: '.35', transform: 'scale(.75)' } },
                scan: { '0%': { transform: 'translateX(-100%)' }, '100%': { transform: 'translateX(300%)' } },
            },
            animation: {
                shimmer: 'shimmer 3.5s linear infinite',
                twinkle: 'twinkle 1.6s ease-in-out infinite',
                scan: 'scan 2.2s ease-in-out infinite',
            },
        },
    },

    plugins: [forms],
};
