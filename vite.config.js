import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // 🎭 โรงละครคำทำนาย (หน้าผลไพ่) — three.js ถูกแยก chunk และโหลดเมื่อเปิดโรงละครเท่านั้น
                'resources/js/theater/index.js',
                'resources/css/themes/mae-mor-chantra.css',
                'resources/css/themes/juntra-payakorn.css',
            ],
            refresh: true,
        }),
    ],
});
