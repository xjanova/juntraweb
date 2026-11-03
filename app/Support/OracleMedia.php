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
     * @return array<string, string>
     */
    public static function voice(string $persona): array
    {
        $out = [];
        foreach (self::voicePaths($persona) as $key => $path) {
            if (is_file(public_path($path))) {
                $out[$key] = self::url($path);
            }
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
     * ห้องที่ "พูด" บนหน้าเลือกไพ่/ผลทำนาย — แพ็กเกจคุณไสยเป็นของห้องคุณไสยเสมอ
     * นอกนั้นเป็นห้องที่ลูกค้าเพิ่งคุยอยู่ (session chat_room) ไม่มี = แม่หมอจันทรา
     */
    public static function personaFor(?string $spreadKey): string
    {
        // แบบทำนายประจำห้อง (quick → บุษบา · kunsai → คุณไสย) พูดด้วยเสียงห้องนั้นเสมอ — ห้องยังปิดก็ยังเป็นเสียงเดิมของแบบนั้น
        //   (ไพ่ quick อ่านโดยบุษบาฝั่ง Thaiprompt — เสียงต้องตรงกับคนเขียนคำทำนาย)
        $owner = ChatRooms::personaForSpread($spreadKey);
        if ($owner !== ChatRooms::DEFAULT) {
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
