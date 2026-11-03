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

    private static function url(string $path): string
    {
        return asset($path) . '?v=' . filemtime(public_path($path));
    }
}
