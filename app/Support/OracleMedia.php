<?php

namespace App\Support;

/**
 * 🎬 สื่อของผู้พยากรณ์ (config/oracle_media.php) — ใช้โดย <x-oracle-stage>
 *
 * ส่งให้หน้าเว็บเฉพาะไฟล์ที่มีอยู่จริงบนดิสก์ — ไฟล์ไหนหาย (deploy ไม่ครบ / ยังไม่ได้ทำเสียง)
 * หน้าเว็บแค่ไม่รู้จักไฟล์นั้น: ไม่มีคลิป = ภาพนิ่ง · ไม่มีเสียง = เงียบ ไม่มีคำขอ 404 ให้เห็น
 * URL ต่อท้าย ?v=<mtime> — Cloudflare เก็บไฟล์ชื่อเดิมไว้นาน ทำคลิปใหม่ต้องได้ URL ใหม่
 */
final class OracleMedia
{
    public static function dir(string $persona): string
    {
        return trim((string) config('oracle_media.dir', 'media/oracle'), '/') . '/' . ChatRooms::normalize($persona);
    }

    /**
     * คลิป idle ที่ใช้ได้ของห้อง — ต้องมีอย่างน้อย mp4 (webm มีก็ใส่เป็นตัวเลือกแรก)
     *
     * @return list<array{webm: ?string, mp4: string}>
     */
    public static function loops(string $persona): array
    {
        $out = [];
        foreach (self::loopPaths($persona) as $files) {
            if (! is_file(public_path($files['mp4']))) {
                continue;
            }
            $out[] = [
                'webm' => is_file(public_path($files['webm'])) ? self::url($files['webm']) : null,
                'mp4'  => self::url($files['mp4']),
            ];
        }

        return $out;
    }

    /**
     * เสียงประโยคประจำที่มีไฟล์แล้ว — key => url
     *
     * 🌸 (2026-11-11) ส่งแบบไพ่มาด้วย = ประโยคเฉพาะแบบนั้น (voice_by_spread) แทนประโยคกลางของ key เดียวกัน
     *    (บุษบาอ่าน 1 · 3 · 5 ใบ — "เลือกไพ่ห้าใบ" ต้องไม่ดังบนโต๊ะที่มีไพ่ใบเดียว) · ไม่มีไฟล์ = ใช้ประโยคกลาง
     *
     * @return array<string, string>
     */
    public static function voice(string $persona, ?string $spread = null): array
    {
        $out = [];
        foreach (self::voicePaths($persona) as $key => $path) {
            if (is_file(public_path($path))) {
                $out[$key] = self::url($path);
            }
        }
        foreach (self::voiceSpreadPaths($persona)[(string) $spread] ?? [] as $key => $path) {
            if (is_file(public_path($path))) {
                $out[$key] = self::url($path);
            }
        }

        return $out;
    }

    /**
     * ประโยคเฉพาะแบบไพ่ตาม manifest (ไม่สนว่ามีไฟล์หรือยัง) — ให้เทสต์ใช้เช็คความครบ
     *
     * @return array<string, array<string, string>> spread => key => path ใต้ public/
     */
    public static function voiceSpreadPaths(string $persona): array
    {
        $dir = self::dir($persona);
        $out = [];
        foreach ((array) config('oracle_media.rooms.' . ChatRooms::normalize($persona) . '.voice_by_spread', []) as $spread => $lines) {
            foreach (array_keys((array) $lines) as $key) {
                $out[(string) $spread][$key] = "{$dir}/voice/{$key}--{$spread}.mp3";
            }
        }

        return $out;
    }

    /**
     * 🎵 เพลงบรรเลงของห้อง (โรงละครคำทำนาย) ที่มีไฟล์แล้ว — ลำดับตาม manifest
     *
     * @return list<string>
     */
    public static function music(string $persona): array
    {
        $out = [];
        foreach (self::musicPaths($persona) as $path) {
            if (is_file(public_path($path))) {
                $out[] = self::url($path);
            }
        }

        return $out;
    }

    /** @return list<string> path ใต้ public/ ของเพลงทุกเพลงตาม manifest */
    public static function musicPaths(string $persona): array
    {
        $dir = self::dir($persona);

        return array_map(fn (string $file) => "{$dir}/music/{$file}",
            array_values((array) config('oracle_media.rooms.'.ChatRooms::normalize($persona).'.music', [])));
    }

    /**
     * 🎭 อิริยาบถของผู้พยากรณ์ที่ยืนหลังโต๊ะ (คลิปพื้นใส) ที่มีไฟล์แล้ว — ใช้โดย resources/js/oracle-room/poses.js
     * ไม่มีคลิปสักท่า = clips ว่าง (หน้าเว็บเหลือภาพนิ่งเหมือนเดิม)
     *
     * @return array{scale: float, clips: list<array{name: string, url: string, moods: list<string>, pp: bool}>}
     */
    public static function poses(string $persona): array
    {
        $cfg = (array) config('oracle_media.rooms.' . ChatRooms::normalize($persona) . '.poses', []);
        $clips = [];
        foreach (self::posePaths($persona) as $name => $path) {
            if (! is_file(public_path($path))) {
                continue;
            }
            $clips[] = [
                'name'  => $name,
                'url'   => self::url($path),
                'moods' => array_values(array_map('strval', (array) ($cfg['clips'][$name] ?? ['idle']))),
                'pp'    => true,   // ต่อไป-กลับทุกคลิป → เร่งกลับท่าตั้งต้นกลางคลิปได้
            ];
        }

        // ภาพนิ่งท่าตั้งต้นของคลิป (ถ้ามี) — ใช้แทน sprite เดิม ให้เฟรมแรก/สุดท้ายของคลิปตรงกับภาพที่ค้างอยู่
        $still = self::dir($persona) . '/poses/still.webp';

        return [
            'scale' => (float) ($cfg['scale'] ?? 1.0),
            'still' => $clips && is_file(public_path($still)) ? self::url($still) : null,
            'clips' => $clips,
        ];
    }

    /** @return array<string, string> ชื่อท่า => path ใต้ public/ ตาม manifest (ไม่สนว่ามีไฟล์หรือยัง) */
    public static function posePaths(string $persona): array
    {
        $dir = self::dir($persona);
        $out = [];
        foreach (array_keys((array) config('oracle_media.rooms.' . ChatRooms::normalize($persona) . '.poses.clips', [])) as $name) {
            $out[$name] = "{$dir}/poses/{$name}.webm";
        }

        return $out;
    }

    /**
     * path ของคลิปทั้งหมดตาม manifest (ไม่สนว่ามีไฟล์หรือยัง) — ให้เทสต์ใช้เช็คความครบ
     *
     * @return list<array{webm: string, mp4: string}>
     */
    public static function loopPaths(string $persona): array
    {
        $dir = self::dir($persona);

        return array_map(fn (string $name) => [
            'webm' => "{$dir}/{$name}.webm",
            'mp4'  => "{$dir}/{$name}.mp4",
        ], array_values((array) config('oracle_media.rooms.' . ChatRooms::normalize($persona) . '.loops', [])));
    }

    /** @return array<string, string> key => path ใต้ public/ */
    public static function voicePaths(string $persona): array
    {
        $dir = self::dir($persona);
        $out = [];
        foreach (array_keys((array) config('oracle_media.rooms.' . ChatRooms::normalize($persona) . '.voice', [])) as $key) {
            $out[$key] = "{$dir}/voice/{$key}.mp3";
        }

        return $out;
    }

    /**
     * ห้องที่ "พูด" บนหน้าเลือกไพ่/ผลทำนาย — ผู้พยากรณ์ที่อ่านไพ่ชุดนั้น (ChatRooms::personaForSpread)
     * แพ็กเกจที่ไม่มีห้องไหนรับ → ห้องที่ลูกค้าเพิ่งคุยอยู่ (session chat_room) ไม่มี = แม่หมอจันทรา
     */
    public static function personaFor(?string $spreadKey): string
    {
        // แบบทำนายประจำห้อง (quick → บุษบา · kunsai → คุณไสย) พูดด้วยเสียงห้องนั้นเสมอ — ห้องยังปิดก็ยังเป็นเสียงเดิมของแบบนั้น
        //   (ไพ่ quick อ่านโดยบุษบาฝั่ง Thaiprompt — เสียงต้องตรงกับคนเขียนคำทำนาย)
        // 🌸 (2026-11-11) ไพ่ที่ห้องรับอ่าน (also) ก็เช่นกัน — 1/3/5 ใบเป็นบุษบา · 12 เดือนเป็นแม่หมอ
        //   เดิมไพ่เหล่านี้ใช้ห้องใน session: ซื้อไพ่ 5 ใบจากห้องบุษบา ได้หน้า/เสียงบุษบา แต่คำทำนายเขียนโดยแม่หมอ
        $owner = ChatRooms::personaForSpread($spreadKey);
        if ($owner !== ChatRooms::DEFAULT || ChatRooms::hasOwner($spreadKey)) {
            return $owner;
        }
        $room = session('chat_room');

        return ChatRooms::normalize(is_string($room) ? $room : null);
    }

    /**
     * ประโยคที่แม่หมอพูดเมื่อเปิดหน้าผลทำนาย ตามสถานะของใบนั้น
     *  - กำลังอ่าน → เปิดไพ่ แล้วบอกให้รอ · อ่านไม่สำเร็จ → ขอโทษ (เครดิตไม่หาย)
     *  - คุณไสย → ผล "พบของ" (เจอ / ไม่เจอ / ไม่ชัด) · ชุดอื่น → ผลฟันธง (ดี / ต้องระวัง)
     *  - ไม่รู้โทน (คำทำนายแบบข้อความยาว) → ชวนถามต่อ — ห้ามเดาว่า "มาดี" แล้วผิด
     *
     * @param  array{ok: bool, by_type: array}  $parsed  ผลจาก ReadingSections::parse()
     * @return list<string>
     */
    public static function readingCues(\App\Models\Reading $reading, array $parsed): array
    {
        if ($reading->isInProgress()) {
            return ['reveal_cards', 'reading_wait'];
        }
        if ($reading->isFailed()) {
            return ['reading_failed'];
        }

        $sections = $parsed['ok'] ? ($parsed['sections'] ?? []) : [];
        foreach ($sections as $s) {
            if (($s['type'] ?? null) === 'diagnosis') {
                $found = (string) ($s['fields']['พบของ'] ?? '');

                return [match (true) {
                    $found === '', str_contains($found, 'ไม่ชัด'), str_contains($found, 'ไม่แน่') => 'kunsai_unclear',
                    str_starts_with($found, 'ไม่') => 'kunsai_clear',
                    default => 'kunsai_found',
                }];
            }
        }
        foreach ($sections as $s) {
            if (($s['type'] ?? null) === 'verdict' && isset($s['result'])) {
                return [match (ReadingSections::verdictTone($s['result'])) {
                    'green' => 'verdict_good',
                    'red', 'amber' => 'verdict_caution',
                    default => 'followup_ask',
                }];
            }
        }

        return ['followup_ask'];
    }

    private static function url(string $path): string
    {
        return asset($path) . '?v=' . filemtime(public_path($path));
    }
}
