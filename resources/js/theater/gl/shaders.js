// 🎨 shader ของโรงละคร — ตัวละโปรแกรม ไม่มี uber-shader ที่แตกกิ่งด้วย uniform
// (PLAYBOOK "WebGL page that loads every time": ANGLE/FXC บน Windows คลี่ลูปทุกกิ่ง = คอมไพล์หลายวินาที)
// ค่าที่ต่างกันต่อห้องส่งเป็น uniform สี/ตัวเลขเท่านั้น · ส่วนที่เปิด/ปิดได้ใช้ #define

export const SKY_VERT = /* glsl */ `
varying vec3 vDir;
void main() {
  vDir = normalize(position);
  vec4 p = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  gl_Position = p.xyww;
}`;

// ท้องฟ้า: ไล่สี + ดาวระยิบ (hash ต่อช่อง) + ทางช้างเผือกจาง ๆ (value noise 3 ชั้น — ไม่มีลูปแปรผัน)
export const SKY_FRAG = /* glsl */ `
uniform float uTime;
uniform vec3 uTop;
uniform vec3 uHorizon;
uniform vec3 uTint;
varying vec3 vDir;

float hash(vec2 p) { return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
float noise(vec2 p) {
  vec2 i = floor(p), f = fract(p);
  vec2 u = f * f * (3.0 - 2.0 * f);
  return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x), mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);
}

void main() {
  vec3 d = normalize(vDir);
  float a = uTime * 0.006;
  float ca = cos(a), sa = sin(a);
  d.xz = mat2(ca, -sa, sa, ca) * d.xz;

  float h = clamp(d.y * 0.5 + 0.5, 0.0, 1.0);
  vec3 col = mix(uHorizon, uTop, smoothstep(0.35, 0.95, h));

  // ทางช้างเผือก — แถบรอบวงกลมใหญ่ที่เอียง
  vec3 bandN = normalize(vec3(0.35, 0.6, 0.72));
  float band = 1.0 - abs(dot(d, bandN));
  vec2 bp = vec2(atan(d.z, d.x) * 3.0, d.y * 6.0);
  float n = noise(bp * 1.7) * 0.55 + noise(bp * 4.1) * 0.3 + noise(bp * 9.3) * 0.15;
  col += uTint * pow(band, 7.0) * n * 0.55;

  // ดาว — ช่องละดวง กะพริบตามเวลา
  vec2 sp = vec2(atan(d.z, d.x) / 6.2831853, asin(clamp(d.y, -1.0, 1.0)) / 3.1415927) * vec2(520.0, 260.0);
  vec2 cell = floor(sp);
  float r = hash(cell);
  vec2 off = vec2(hash(cell + 7.1), hash(cell + 3.7)) - 0.5;
  float dist = length(fract(sp) - 0.5 - off * 0.6);
  float star = step(0.955, r) * smoothstep(0.16, 0.0, dist);
  float tw = 0.65 + 0.35 * sin(uTime * (1.0 + r * 3.0) + r * 40.0);
  col += vec3(0.9, 0.92, 1.0) * star * tw * (0.6 + 1.6 * pow(r, 30.0)) * smoothstep(0.2, 0.55, h);

  gl_FragColor = vec4(col, 1.0);
}`;

export const QUAD_VERT = /* glsl */ `
varying vec2 vUv;
void main() {
  vUv = uv;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}`;

// ภาพฉากหลังของห้อง — ติดกล้อง ครอบเต็มจอแบบ cover (จอกว้างเก็บครึ่งล่างที่มีรายละเอียดไว้ก่อน)
// uShift/uZoom ขยับช้ากว่าเวที = รู้สึกว่าอยู่ไกล · หรี่ลงให้ตัวหนังสือ/ไพ่เด่น · dither กันฟ้ามืดเป็นแถบ
export const BACKDROP_FRAG = /* glsl */ `
uniform sampler2D uMap;
uniform float uAlpha;
uniform float uAspect;
uniform float uImgAspect;
uniform vec2 uShift;
uniform float uZoom;
varying vec2 vUv;
void main() {
  vec2 vis = uAspect > uImgAspect ? vec2(1.0, uImgAspect / uAspect) : vec2(uAspect / uImgAspect, 1.0);
  vec2 h = 0.5 * vis * uZoom;
  vec2 c = vec2(0.5, mix(0.5, h.y, 0.55)) + uShift;
  c = clamp(c, h, 1.0 - h);
  vec3 col = texture2D(uMap, c + (vUv - 0.5) * 2.0 * h).rgb;
  col *= 0.78 - 0.22 * smoothstep(0.25, 0.85, vUv.y) * (1.0 - abs(vUv.x - 0.5) * 1.4);
  col += (fract(sin(dot(gl_FragCoord.xy, vec2(12.9898, 78.233))) * 43758.5453) - 0.5) / 510.0;
  gl_FragColor = vec4(col, uAlpha);
}`;

// ดวงจันทร์ — แผ่นวงกลมมีหลุมจาง ๆ + รัศมีเรืองแสงที่สว่างขึ้นตามเสียงพูด (uAmp)
export const MOON_FRAG = /* glsl */ `
uniform float uAmp;
uniform float uTime;
uniform vec3 uGlow;
uniform sampler2D uMap;
varying vec2 vUv;
float hash(vec2 p) { return fract(sin(dot(p, vec2(41.3, 289.1))) * 15731.743); }
float noise(vec2 p) {
  vec2 i = floor(p), f = fract(p);
  vec2 u = f * f * (3.0 - 2.0 * f);
  return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x), mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);
}
void main() {
  vec2 p = vUv * 2.0 - 1.0;
  float r = length(p);
  float disc = 1.0 - smoothstep(0.30, 0.31, r);
  float mare = noise(p * 6.0) * 0.6 + noise(p * 15.0) * 0.4;
  vec3 surface = vec3(1.05, 1.0, 0.92) * (0.85 - mare * 0.28) * (1.0 - pow(r / 0.31, 4.0) * 0.35);
  float breathe = 0.5 + 0.5 * sin(uTime * 0.7);
  float halo = exp(-max(r - 0.3, 0.0) * (9.0 - uAmp * 4.0)) * (0.22 + uAmp * 0.7 + breathe * 0.06);
#ifdef MOON_TEX
  // ภาพจันทร์จริง (ขอบจันทร์ = 0.42 ของภาพ) — ลดความเหลืองลงหน่อยแล้วย้อมตามแสงห้อง
  vec3 t = texture2D(uMap, clamp(0.5 + p * (0.42 / 0.31), 0.0, 1.0)).rgb * step(r, 0.369);
  float tl = dot(t, vec3(0.299, 0.587, 0.114));
  surface = mix(t, vec3(tl), 0.3) * mix(vec3(1.0), uGlow, 0.25) * 0.9;
  disc = max(disc, clamp(tl * 2.5, 0.0, 1.0));
#endif
  vec3 col = surface * disc * 1.05 + uGlow * halo * (1.0 - disc) * 0.8;
  float a = clamp(disc + halo, 0.0, 1.0);
  if (a < 0.003) discard;
  gl_FragColor = vec4(col, a);
}`;

// ไพ่ — มุมมน · หรี่ได้ · ขอบทองเรืองตอนเป็นใบที่กำลังพูด · กลับหัว = หมุน UV 180°
export const CARD_FRAG = /* glsl */ `
uniform sampler2D uMap;
uniform float uDim;
uniform float uGlow;
uniform float uAlpha;
uniform vec3 uRim;
varying vec2 vUv;
float rrect(vec2 p, vec2 b, float r) { vec2 q = abs(p) - b + r; return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r; }
void main() {
#ifdef REVERSED
  vec2 uv = 1.0 - vUv;
#else
  vec2 uv = vUv;
#endif
  vec2 p = (vUv - 0.5) * vec2(1.0, 1.7);
  float d = rrect(p, vec2(0.5, 0.85), 0.07);
  float a = 1.0 - smoothstep(-0.006, 0.0, d);
  if (a < 0.01) discard;
  vec3 c = texture2D(uMap, uv).rgb * uDim;
  float edge = smoothstep(-0.03, -0.004, d);
  c = mix(c, uRim * 0.9, edge * 0.55) + uRim * edge * uGlow * 2.2;
  gl_FragColor = vec4(c, a * uAlpha);
}`;

// รัศมีหลังไพ่ (additive)
export const HALO_FRAG = /* glsl */ `
uniform float uGlow;
uniform vec3 uColor;
varying vec2 vUv;
void main() {
  vec2 p = (vUv - 0.5) * 2.0;
  vec2 q = max(abs(p) - vec2(0.55, 0.62), 0.0);
  float g = exp(-length(q) * 7.0) * uGlow;
  if (g < 0.004) discard;
  gl_FragColor = vec4(uColor * g * 1.6, g);
}`;

// วงแหวนฟันธง — วงพื้นจาง + วงสีตามผล ยาวตาม uFill*uDraw (ไม่มีคะแนนแต่ง: รายปี = สัดส่วนเดือนดี)
export const RING_FRAG = /* glsl */ `
uniform float uFill;
uniform float uDraw;
uniform vec3 uColor;
varying vec2 vUv;
void main() {
  vec2 p = vUv * 2.0 - 1.0;
  float r = length(p);
  float ang = atan(p.x, p.y) / 6.2831853;
  ang = ang < 0.0 ? ang + 1.0 : ang;
  float band = smoothstep(0.05, 0.035, abs(r - 0.82));
  float back = smoothstep(0.78, 0.74, r) * 0.55;
  float on = step(ang, uFill * uDraw) * band;
  vec3 col = vec3(0.02, 0.01, 0.06) * back + uColor * on * 1.8 + vec3(1.0) * band * 0.06;
  float a = max(back, band * (0.18 + on * 0.82)) * uDraw;
  if (a < 0.01) discard;
  gl_FragColor = vec4(col, a);
}`;

// แท่นบูชา — แผ่นกลมไล่เงา + ขอบแสงจันทร์
export const ALTAR_FRAG = /* glsl */ `
uniform vec3 uRim;
uniform vec3 uBase;
uniform sampler2D uMap;
uniform float uTex;
uniform vec3 uTint;
uniform float uRecolor;
varying vec2 vUv;
void main() {
  vec2 p = vUv * 2.0 - 1.0;
  float r = length(p);
  if (r > 1.0) discard;
  vec3 c = uBase * (1.0 - r * 0.55);
  c += uRim * smoothstep(0.93, 0.985, r) * (1.0 - smoothstep(0.985, 1.0, r)) * 0.5;
  c += uRim * 0.08 * smoothstep(0.62, 0.6, abs(r - 0.6) + 0.6) ;
  // ภาพโต๊ะลงรักขอบลายกนก (พื้นม่วง) — ห้องอื่นย้อมเฉพาะพื้นมืดเป็นสีห้อง ลายทองคงเดิม
  vec3 t = texture2D(uMap, vUv).rgb;
  float lum = dot(t, vec3(0.299, 0.587, 0.114));
  float base = (1.0 - smoothstep(0.06, 0.2, lum)) * uRecolor;
  t = mix(t, uTint * lum * 3.2, base) * 0.85;
  gl_FragColor = vec4(mix(c, t, uTex), 1.0);
}`;

// เส้นกราฟชีวิต — ลากตาม uDraw (u ของท่อ = ระยะตามเส้น) สีตามจุดดวงผ่าน vertex color
export const RIBBON_VERT = /* glsl */ `
attribute vec3 color;
varying vec2 vUv;
varying vec3 vColor;
void main() {
  vUv = uv;
  vColor = color;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}`;
export const RIBBON_FRAG = /* glsl */ `
uniform float uDraw;
uniform float uTime;
varying vec2 vUv;
varying vec3 vColor;
void main() {
  if (vUv.x > uDraw) discard;
  float head = smoothstep(uDraw - 0.04, uDraw, vUv.x) * step(uDraw, 0.999);
  float shimmer = 0.85 + 0.15 * sin(vUv.x * 40.0 - uTime * 2.0);
  gl_FragColor = vec4(vColor * (1.5 * shimmer + head * 2.0), 1.0);
}`;

// จุดแสง (ดาวกลุ่มราศี / ฝุ่น / ควัน / ประกายตอนพลิกไพ่) — วาดเป็นจุดกลมนุ่ม
export const POINT_FRAG = /* glsl */ `
uniform vec3 uColor;
varying float vAlpha;
void main() {
  vec2 p = gl_PointCoord * 2.0 - 1.0;
  float r = dot(p, p);
  if (r > 1.0) discard;
  float a = exp(-r * 3.5) * vAlpha;
  gl_FragColor = vec4(uColor * a * 1.4, a);
}`;

// ฝุ่นแสงจันทร์ — ลอยเองใน vertex shader (ไม่มีการคำนวณบน CPU ต่อเฟรม)
export const DUST_VERT = /* glsl */ `
uniform float uTime;
uniform float uSize;
uniform vec3 uBox;
attribute float aSeed;
varying float vAlpha;
void main() {
  vec3 p = position;
  float t = uTime * (0.04 + aSeed * 0.05);
  p.y = mod(p.y + t * uBox.y + uBox.y, uBox.y * 2.0) - uBox.y;
  p.x += sin(uTime * 0.21 + aSeed * 31.0) * 0.35;
  p.z += cos(uTime * 0.17 + aSeed * 17.0) * 0.25;
  vec4 mv = modelViewMatrix * vec4(p, 1.0);
  gl_Position = projectionMatrix * mv;
  gl_PointSize = uSize * (0.4 + aSeed) * (10.0 / -mv.z);
  vAlpha = (0.25 + 0.5 * sin(uTime * (0.6 + aSeed) + aSeed * 50.0) * 0.5 + 0.25) * smoothstep(-uBox.y, -uBox.y * 0.6, p.y);
}`;

// ประกายตอนพลิกไพ่ — กระจายออกจากจุดกำเนิดตามเวลาตั้งแต่ uStart
export const BURST_VERT = /* glsl */ `
uniform float uTime;
uniform float uStart;
uniform vec3 uOrigin;
attribute vec3 aDir;
varying float vAlpha;
void main() {
  float t = clamp((uTime - uStart) / 1.4, 0.0, 1.0);
  vec3 p = uOrigin + aDir * (1.0 - pow(1.0 - t, 3.0)) * 1.6 + vec3(0.0, -t * t * 0.4, 0.0);
  vec4 mv = modelViewMatrix * vec4(p, 1.0);
  gl_Position = projectionMatrix * mv;
  gl_PointSize = (1.0 - t) * 9.0 * (10.0 / -mv.z);
  vAlpha = (1.0 - t) * step(0.0001, uTime - uStart);
}`;

// ควันธูป (ห้องคุณไสย) — ลอยขึ้น ส่าย จางหาย
export const SMOKE_VERT = /* glsl */ `
uniform float uTime;
uniform vec3 uOrigin;
attribute float aSeed;
varying float vAlpha;
void main() {
  float life = fract(uTime * 0.12 + aSeed);
  vec3 p = uOrigin + vec3(sin(life * 6.0 + aSeed * 20.0) * 0.18 * life, life * 2.6, cos(life * 5.0 + aSeed * 9.0) * 0.1 * life);
  vec4 mv = modelViewMatrix * vec4(p, 1.0);
  gl_Position = projectionMatrix * mv;
  gl_PointSize = (6.0 + life * 26.0) * (10.0 / -mv.z);
  vAlpha = sin(life * 3.14159) * 0.16;
}`;

// เปลวเทียน (ห้องคุณไสย) — วาดบนแผ่นที่หันหากล้อง สั่นไหวตามเวลา
export const FLAME_FRAG = /* glsl */ `
uniform float uTime;
uniform float uSeed;
varying vec2 vUv;
void main() {
  vec2 p = vUv * 2.0 - 1.0;
  float flick = 0.9 + 0.1 * sin(uTime * 13.0 + uSeed) + 0.06 * sin(uTime * 23.0 + uSeed * 2.0);
  p.x += sin(uTime * 3.0 + p.y * 2.0 + uSeed) * 0.06 * (p.y + 1.0);
  float d = length(vec2(p.x * 2.4, (p.y + 0.35) * 0.95 / flick));
  float core = smoothstep(0.55, 0.0, d);
  float glow = exp(-length(p) * 2.6) * 0.45;
  vec3 col = mix(vec3(1.0, 0.35, 0.08), vec3(1.0, 0.9, 0.6), core) * (core * 2.4 + glow);
  float a = clamp(core + glow, 0.0, 1.0);
  if (a < 0.01) discard;
  gl_FragColor = vec4(col, a);
}`;

// กลีบบัว (ห้องบุษบา) — instanced · ร่วงช้า ๆ ส่ายไปมา ทั้งหมดคำนวณใน vertex shader
export const PETAL_VERT = /* glsl */ `
uniform float uTime;
uniform vec3 uBox;
attribute vec4 aSeed;
varying vec2 vUv;
varying float vShade;
void main() {
  vUv = uv;
  float t = uTime * (0.05 + aSeed.w * 0.04);
  float y = uBox.y - mod(t * uBox.y * 2.0 + aSeed.y * uBox.y * 2.0, uBox.y * 2.0);
  float spin = uTime * (0.3 + aSeed.x) + aSeed.z * 6.28;
  float cs = cos(spin), sn = sin(spin);
  vec3 lp = position;
  lp.xz = mat2(cs, -sn, sn, cs) * lp.xz;
  vec3 center = vec3((aSeed.x - 0.5) * uBox.x * 2.0 + sin(uTime * 0.4 + aSeed.z * 9.0) * 0.6, y, (aSeed.z - 0.5) * uBox.z * 2.0);
  vShade = 0.65 + 0.35 * abs(cs);
  gl_Position = projectionMatrix * modelViewMatrix * vec4(center + lp, 1.0);
}`;
export const PETAL_FRAG = /* glsl */ `
uniform vec3 uColor;
varying vec2 vUv;
varying float vShade;
void main() {
  vec2 p = vUv * 2.0 - 1.0;
  float shape = 1.0 - (p.x * p.x) / max(0.05, (1.0 - p.y * p.y) * 0.35) - p.y * p.y * 0.2;
  if (shape < 0.0) discard;
  // โคนกลีบอ่อนลงนิดเดียว — มากกว่านี้กลีบดูขาวเหมือนหิมะหลัง tone mapping
  vec3 c = mix(uColor, vec3(1.0, 0.9, 0.94), (1.0 - vUv.y) * 0.25) * vShade;
  gl_FragColor = vec4(c * 0.62, 0.78);
}`;
