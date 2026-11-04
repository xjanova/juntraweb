// 🌠 กลุ่มดาว 12 ราศี จากตำแหน่งดาวจริง — RA/Dec (J2000) + โชติมาตรของดาวหลักแต่ละกลุ่ม
// ฉายลงระนาบสัมผัสท้องฟ้า (gnomonic) รอบจุดกึ่งกลางกลุ่ม · ทิศตะวันออกอยู่ซ้าย (มองขึ้นฟ้าจริง) · เหนืออยู่บน
// เส้นเชื่อมตามรูปแบบมาตรฐาน IAU/Sky & Telescope แบบย่อ · ลำดับเส้น = ลำดับที่ลากตอนอนิเมชัน
// ราศีของลูกค้ามาจากเซิร์ฟเวอร์ (ReadingTheater::zodiac — ยึดชื่อราศีในหัวข้อ 🌠 ก่อน)
//
// ผลลัพธ์ต่อราศี: stars [[x,y]] ช่วง -1..1 · mag [โชติมาตร] · names [ชื่อดาว] · lines [[a,b]] ดัชนีดาว

/** [ชื่อ, RA ชั่วโมง, Dec องศา, โชติมาตร] */
const CATALOG = {
    aries: {
        stars: [['Mesarthim', 1.8922, 19.29, 3.9], ['Sheratan', 1.9106, 20.81, 2.64], ['Hamal', 2.1194, 23.46, 2.0], ['41 Ari', 2.8331, 27.26, 3.6]],
        lines: [['Mesarthim', 'Sheratan'], ['Sheratan', 'Hamal'], ['Hamal', '41 Ari']],
    },
    taurus: {
        stars: [['Aldebaran', 4.5987, 16.51, 0.85], ['Elnath', 5.4382, 28.61, 1.65], ['Tianguan', 5.6274, 21.14, 3.0], ['Prima Hyadum', 4.33, 15.63, 3.65],
            ['Secunda Hyadum', 4.3822, 17.54, 3.76], ['Ain', 4.4769, 19.18, 3.5], ['Chamukuy', 4.4778, 15.87, 3.4], ['Lambda Tau', 4.0114, 12.49, 3.4],
            ['Omicron Tau', 3.4136, 9.03, 3.6], ['Alcyone', 3.7914, 24.11, 2.87]],
        lines: [['Prima Hyadum', 'Chamukuy'], ['Chamukuy', 'Aldebaran'], ['Aldebaran', 'Tianguan'], ['Prima Hyadum', 'Secunda Hyadum'],
            ['Secunda Hyadum', 'Ain'], ['Ain', 'Elnath'], ['Prima Hyadum', 'Lambda Tau'], ['Lambda Tau', 'Omicron Tau']],
    },
    gemini: {
        stars: [['Castor', 7.5767, 31.89, 1.58], ['Pollux', 7.7553, 28.03, 1.14], ['Alhena', 6.6286, 16.4, 1.93], ['Wasat', 7.3354, 21.98, 3.5],
            ['Mebsuta', 6.7322, 25.13, 3.0], ['Mekbuda', 7.0686, 20.57, 3.9], ['Tejat', 6.3828, 22.51, 2.9], ['Propus', 6.2481, 22.51, 3.3],
            ['Tau Gem', 7.1856, 30.25, 4.4], ['Iota Gem', 7.4289, 27.8, 3.8], ['Kappa Gem', 7.7408, 24.4, 3.6], ['Theta Gem', 6.8797, 33.96, 3.6]],
        lines: [['Castor', 'Tau Gem'], ['Tau Gem', 'Theta Gem'], ['Tau Gem', 'Mebsuta'], ['Mebsuta', 'Tejat'], ['Tejat', 'Propus'],
            ['Tau Gem', 'Iota Gem'], ['Iota Gem', 'Pollux'], ['Pollux', 'Kappa Gem'], ['Pollux', 'Wasat'], ['Wasat', 'Mekbuda'], ['Mekbuda', 'Alhena']],
    },
    cancer: {
        stars: [['Altarf', 8.2753, 9.19, 3.5], ['Asellus Australis', 8.7448, 18.15, 3.9], ['Acubens', 8.9747, 11.86, 4.25],
            ['Asellus Borealis', 8.7214, 21.47, 4.65], ['Iota Cnc', 8.7783, 28.76, 4.0]],
        lines: [['Altarf', 'Asellus Australis'], ['Asellus Australis', 'Acubens'], ['Asellus Australis', 'Asellus Borealis'], ['Asellus Borealis', 'Iota Cnc']],
    },
    leo: {
        stars: [['Regulus', 10.1395, 11.97, 1.35], ['Denebola', 11.8177, 14.57, 2.1], ['Algieba', 10.3328, 19.84, 2.0], ['Zosma', 11.2351, 20.52, 2.55],
            ['Chertan', 11.2373, 15.43, 3.3], ['Eta Leo', 10.1222, 16.76, 3.5], ['Adhafera', 10.2781, 23.42, 3.4], ['Rasalas', 9.8794, 26.01, 3.9],
            ['Ras Elased', 9.7642, 23.77, 2.98]],
        lines: [['Regulus', 'Eta Leo'], ['Eta Leo', 'Algieba'], ['Algieba', 'Adhafera'], ['Adhafera', 'Rasalas'], ['Rasalas', 'Ras Elased'],
            ['Algieba', 'Zosma'], ['Zosma', 'Denebola'], ['Denebola', 'Chertan'], ['Chertan', 'Regulus'], ['Zosma', 'Chertan']],
    },
    virgo: {
        stars: [['Spica', 13.4199, -11.16, 0.97], ['Zavijava', 11.8449, 1.76, 3.6], ['Porrima', 12.6944, -1.45, 2.74], ['Minelauva', 12.9267, 3.4, 3.4],
            ['Vindemiatrix', 13.0364, 10.96, 2.85], ['Heze', 13.5783, -0.6, 3.37], ['Zaniah', 12.3317, -0.67, 3.9], ['Theta Vir', 13.1658, -5.54, 4.4],
            ['Syrma', 14.2669, -6.0, 4.1], ['Mu Vir', 14.7178, -5.66, 3.9], ['Nu Vir', 11.7644, 6.53, 4.0]],
        lines: [['Nu Vir', 'Zavijava'], ['Zavijava', 'Zaniah'], ['Zaniah', 'Porrima'], ['Porrima', 'Minelauva'], ['Minelauva', 'Vindemiatrix'],
            ['Porrima', 'Theta Vir'], ['Theta Vir', 'Spica'], ['Minelauva', 'Heze'], ['Heze', 'Syrma'], ['Syrma', 'Mu Vir']],
    },
    libra: {
        stars: [['Zubenelgenubi', 14.848, -16.04, 2.75], ['Zubeneschamali', 15.2833, -9.38, 2.6], ['Zubenelhakrabi', 15.5922, -14.79, 3.9],
            ['Brachium', 15.0678, -25.28, 3.25], ['Upsilon Lib', 15.617, -28.14, 3.6], ['Tau Lib', 15.6444, -29.78, 3.65]],
        lines: [['Zubenelgenubi', 'Zubeneschamali'], ['Zubeneschamali', 'Zubenelhakrabi'], ['Zubenelhakrabi', 'Zubenelgenubi'],
            ['Zubenelgenubi', 'Brachium'], ['Zubenelhakrabi', 'Upsilon Lib'], ['Upsilon Lib', 'Tau Lib']],
    },
    scorpio: {
        stars: [['Acrab', 16.0906, -19.81, 2.6], ['Dschubba', 16.0056, -22.62, 2.3], ['Fang', 15.9808, -26.11, 2.9], ['Rho Sco', 15.9481, -29.21, 3.9],
            ['Alniyat', 16.3531, -25.59, 2.9], ['Antares', 16.4901, -26.43, 1.06], ['Paikauhale', 16.5981, -28.22, 2.8], ['Larawag', 16.8361, -34.29, 2.3],
            ['Mu Sco', 16.8644, -38.05, 3.0], ['Zeta Sco', 16.9097, -42.36, 3.6], ['Eta Sco', 17.2025, -43.24, 3.3], ['Sargas', 17.6219, -43.0, 1.86],
            ['Iota Sco', 17.7931, -40.13, 3.0], ['Girtab', 17.7081, -39.03, 2.4], ['Shaula', 17.5601, -37.1, 1.62], ['Lesath', 17.5128, -37.3, 2.7]],
        lines: [['Acrab', 'Dschubba'], ['Dschubba', 'Fang'], ['Fang', 'Rho Sco'], ['Dschubba', 'Alniyat'], ['Alniyat', 'Antares'],
            ['Antares', 'Paikauhale'], ['Paikauhale', 'Larawag'], ['Larawag', 'Mu Sco'], ['Mu Sco', 'Zeta Sco'], ['Zeta Sco', 'Eta Sco'],
            ['Eta Sco', 'Sargas'], ['Sargas', 'Iota Sco'], ['Iota Sco', 'Girtab'], ['Girtab', 'Shaula'], ['Shaula', 'Lesath']],
    },
    sagittarius: {
        stars: [['Kaus Australis', 18.4029, -34.38, 1.85], ['Nunki', 18.9211, -26.3, 2.05], ['Ascella', 19.0436, -29.88, 2.6], ['Kaus Media', 18.3499, -29.83, 2.7],
            ['Kaus Borealis', 18.4661, -25.42, 2.8], ['Alnasl', 18.0968, -30.42, 2.98], ['Phi Sgr', 18.7608, -26.99, 3.2], ['Tau Sgr', 19.1156, -27.67, 3.3],
            ['Eta Sgr', 18.2938, -36.76, 3.1], ['Polis', 18.2294, -21.06, 3.85], ['Albaldah', 19.1628, -21.02, 2.9]],
        lines: [['Alnasl', 'Kaus Media'], ['Alnasl', 'Kaus Australis'], ['Kaus Media', 'Kaus Australis'], ['Kaus Media', 'Kaus Borealis'],
            ['Kaus Borealis', 'Phi Sgr'], ['Phi Sgr', 'Kaus Media'], ['Phi Sgr', 'Nunki'], ['Nunki', 'Tau Sgr'], ['Tau Sgr', 'Ascella'],
            ['Ascella', 'Phi Sgr'], ['Ascella', 'Kaus Australis'], ['Kaus Borealis', 'Polis'], ['Kaus Australis', 'Eta Sgr'], ['Nunki', 'Albaldah']],
    },
    capricorn: {
        stars: [['Algedi', 20.3009, -12.55, 3.6], ['Dabih', 20.3502, -14.78, 3.05], ['Deneb Algedi', 21.784, -16.13, 2.85], ['Nashira', 21.6681, -16.66, 3.7],
            ['Psi Cap', 20.7684, -25.27, 4.1], ['Omega Cap', 20.8639, -26.92, 4.1], ['Zeta Cap', 21.4446, -22.41, 3.7], ['Theta Cap', 21.0991, -17.23, 4.1],
            ['Iota Cap', 21.3708, -16.83, 4.3]],
        lines: [['Algedi', 'Dabih'], ['Dabih', 'Psi Cap'], ['Psi Cap', 'Omega Cap'], ['Omega Cap', 'Zeta Cap'], ['Zeta Cap', 'Deneb Algedi'],
            ['Deneb Algedi', 'Nashira'], ['Nashira', 'Iota Cap'], ['Iota Cap', 'Theta Cap'], ['Theta Cap', 'Dabih']],
    },
    aquarius: {
        stars: [['Albali', 20.7946, -9.5, 3.77], ['Sadalsuud', 21.526, -5.57, 2.9], ['Sadalmelik', 22.0964, -0.32, 2.95], ['Sadachbia', 22.3611, -1.39, 3.85],
            ['Zeta Aqr', 22.4806, -0.02, 3.65], ['Eta Aqr', 22.5893, -0.12, 4.0], ['Seat', 22.4214, 1.38, 4.6], ['Ancha', 22.2806, -7.78, 4.2],
            ['Hydor', 22.8769, -7.58, 3.7], ['Tau Aqr', 22.8266, -13.59, 4.0], ['Skat', 22.9108, -15.82, 3.27], ['88 Aqr', 23.1575, -21.17, 3.65],
            ['98 Aqr', 23.3828, -20.1, 4.0]],
        lines: [['Albali', 'Sadalsuud'], ['Sadalsuud', 'Sadalmelik'], ['Sadalmelik', 'Sadachbia'], ['Sadachbia', 'Zeta Aqr'], ['Zeta Aqr', 'Eta Aqr'],
            ['Zeta Aqr', 'Seat'], ['Sadalmelik', 'Ancha'], ['Ancha', 'Hydor'], ['Hydor', 'Tau Aqr'], ['Tau Aqr', 'Skat'], ['Skat', '88 Aqr'], ['88 Aqr', '98 Aqr']],
    },
    pisces: {
        stars: [['Fumalsamakah', 23.286, 3.28, 3.7], ['Theta Psc', 23.4661, 6.38, 4.3], ['Iota Psc', 23.6658, 5.63, 4.1], ['Lambda Psc', 23.7008, 1.78, 4.5],
            ['Kappa Psc', 23.4489, 1.26, 4.9], ['Omega Psc', 23.9886, 6.86, 4.0], ['Delta Psc', 0.8114, 7.59, 4.4], ['Epsilon Psc', 1.0492, 7.89, 4.3],
            ['Nu Psc', 1.6906, 5.49, 4.4], ['Alrescha', 2.0342, 2.76, 3.8], ['Torcular', 1.7567, 9.16, 4.3], ['Alpherg', 1.5247, 15.35, 3.6],
            ['Phi Psc', 1.2292, 24.58, 4.65], ['Tau Psc', 1.1942, 30.09, 4.5], ['Upsilon Psc', 1.3244, 27.26, 4.75]],
        lines: [['Fumalsamakah', 'Theta Psc'], ['Theta Psc', 'Iota Psc'], ['Iota Psc', 'Lambda Psc'], ['Lambda Psc', 'Kappa Psc'], ['Kappa Psc', 'Fumalsamakah'],
            ['Iota Psc', 'Omega Psc'], ['Omega Psc', 'Delta Psc'], ['Delta Psc', 'Epsilon Psc'], ['Epsilon Psc', 'Nu Psc'], ['Nu Psc', 'Alrescha'],
            ['Alrescha', 'Torcular'], ['Torcular', 'Alpherg'], ['Alpherg', 'Phi Psc'], ['Phi Psc', 'Tau Psc'], ['Tau Psc', 'Upsilon Psc'], ['Upsilon Psc', 'Phi Psc']],
    },
};

const RAD = Math.PI / 180;
const unit = (raH, decD) => {
    const ra = raH * 15 * RAD;
    const dec = decD * RAD;
    return [Math.cos(dec) * Math.cos(ra), Math.cos(dec) * Math.sin(ra), Math.sin(dec)];
};
const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
const norm = (a) => { const l = Math.hypot(a[0], a[1], a[2]); return [a[0] / l, a[1] / l, a[2] / l]; };

/** ฉายดาวทั้งกลุ่มลงระนาบสัมผัสที่จุดกึ่งกลาง แล้วย่อให้พอดี -1..1 โดยคงสัดส่วนจริง */
function project(def) {
    const vs = def.stars.map(([, ra, dec]) => unit(ra, dec));
    const c = norm(vs.reduce((s, v) => [s[0] + v[0], s[1] + v[1], s[2] + v[2]], [0, 0, 0]));
    const east = norm([-c[1], c[0], 0]);                    // ทิศที่ RA เพิ่ม
    const north = [c[1] * east[2] - c[2] * east[1], c[2] * east[0] - c[0] * east[2], c[0] * east[1] - c[1] * east[0]];
    const raw = vs.map((v) => { const k = dot(v, c); return [-dot(v, east) / k, dot(v, north) / k]; });  // ตะวันออกอยู่ซ้าย
    const xs = raw.map((p) => p[0]);
    const ys = raw.map((p) => p[1]);
    const cx = (Math.min(...xs) + Math.max(...xs)) / 2;
    const cy = (Math.min(...ys) + Math.max(...ys)) / 2;
    const half = Math.max(Math.max(...xs) - cx, Math.max(...ys) - cy) || 1;
    const idx = Object.fromEntries(def.stars.map(([name], i) => [name, i]));
    return {
        stars: raw.map(([x, y]) => [Math.round(((x - cx) / half) * 1000) / 1000, Math.round(((y - cy) / half) * 1000) / 1000]),
        mag: def.stars.map((s) => s[3]),
        names: def.stars.map((s) => s[0]),
        lines: def.lines.map(([a, b]) => [idx[a], idx[b]]),
    };
}

export const CONSTELLATIONS = Object.fromEntries(Object.entries(CATALOG).map(([slug, def]) => [slug, project(def)]));

/** ขนาดดาวตามโชติมาตร (สว่างกว่า = ใหญ่กว่า) ราว 0.35–1.05 */
export const starSize = (mag) => Math.max(0.35, Math.min(1.05, 0.35 + (4.7 - mag) * 0.18));

/** เวลาที่ดาวแต่ละดวงปรากฏ (0..1) = จังหวะที่เส้นแรกซึ่งแตะดาวนั้นเริ่มลาก · ดาวไม่มีเส้น = ขึ้นตั้งแต่ต้น */
export function starDelays(shape) {
    const n = shape.lines.length || 1;
    const d = shape.stars.map(() => 0);
    const seen = new Set();
    shape.lines.forEach(([a, b], k) => {
        if (!seen.has(a)) { seen.add(a); d[a] = k / n; }
        if (!seen.has(b)) { seen.add(b); d[b] = (k + 1) / n; }
    });
    return d;
}
