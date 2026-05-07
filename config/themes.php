<?php

/**
 * Theme registry — single source of truth for available themes.
 *
 * Each theme has:
 *   slug:       used in DB (Setting::get('theme'))
 *   name:       display name in admin
 *   description: short pitch shown in theme picker
 *   palette:    swatch colors for the picker preview
 *   layout:     Blade view to extend (in themes/{slug}/layout.blade.php)
 *   home:       homepage view (themes/{slug}/home.blade.php)
 *   css:        Vite entry for the theme stylesheet
 *   preview:    image path for the theme picker thumbnail
 *
 * Adding a new theme = add an entry here + create matching files. No code changes elsewhere.
 */

return [
    'default' => 'mae-mor-chantra',

    'themes' => [

        'mae-mor-chantra' => [
            'name' => 'แม่หมอจันทรา · Mae Mor Chantra',
            'description' => 'ธีมจักรวาลศักดิ์สิทธิ์ — gold + violet + ดาวระยิบระยับ ภาพหญิงแม่หมอ + ไพ่ลอย มี 3D parallax + zodiac wheel',
            'palette' => ['#07041a', '#2a1259', '#6a3bd6', '#b07cff', '#f4cf6a', '#fff5d4'],
            'layout' => 'themes.mae-mor-chantra.layout',
            'home' => 'themes.mae-mor-chantra.home',
            'css' => 'resources/css/themes/mae-mor-chantra.css',
            'preview' => 'images/themes/mae-mor-chantra-preview.png',
            'fonts' => 'Cinzel:wght@400;500;600;700;800|Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400|Prompt:wght@200;300;400;500;600;700',
        ],

        'juntra-payakorn' => [
            'name' => 'จันทราพยากรณ์ · Juntra Payakorn',
            'description' => 'ธีมสายมูเตลู — purple + magenta + ทอง พื้นหลังควันลอย floating glyphs (☾ ✦ ☉ ♅) สมัครสมาน อบอุ่น เคล้าหน่อยลึกลับ',
            'palette' => ['#0a0612', '#160a26', '#7a3df0', '#d24ad1', '#e7c97a', '#f3e9ff'],
            'layout' => 'themes.juntra-payakorn.layout',
            'home' => 'themes.juntra-payakorn.home',
            'css' => 'resources/css/themes/juntra-payakorn.css',
            'preview' => 'images/themes/juntra-payakorn-preview.png',
            'fonts' => 'Bai+Jamjuree:ital,wght@0,300;0,400;0,500;0,600;1,400|Charm:wght@400;700|Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500',
        ],

    ],
];
