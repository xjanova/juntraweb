<?php

namespace App\Support;

/**
 * 🎧 (2026-11-05) ต่อไฟล์ MP3 หลายไฟล์เป็นไฟล์เดียว + วัดความยาวจริงจากเฟรม
 *
 * ใช้กับเสียงเล่าคำทำนายพรีเมียม: Thaiprompt ให้ไฟล์ทีละบท → เว็บต่อเป็นคลิปเดียว
 * แล้วโรงละครคำทำนายเดินภาพตาม audio.currentTime (ต้องรู้ว่าแต่ละบทเริ่ม/จบวินาทีที่เท่าไร)
 *
 * - ตัดแท็ก ID3v2 (หัวไฟล์) / ID3v1 (ท้ายไฟล์) ทิ้ง เหลือแต่เฟรมเสียง
 * - ตัดเฟรม Xing/Info/VBRI ทิ้ง — เฟรมนี้บอกจำนวนเฟรมของ "ไฟล์เดิม" ถ้าค้างอยู่หัวไฟล์รวม
 *   เบราว์เซอร์จะเชื่อว่าคลิปยาวเท่าบทแรก (เลื่อน/วัดเวลาเพี้ยน)
 * - ไม่ใช้ ffmpeg (เครื่องโฮสต์ไม่มี) — MPEG Layer III ต่อกันแบบเฟรมต่อเฟรมเล่นได้ตามมาตรฐาน
 */
final class Mp3
{
    private const BITRATES = [
        1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];

    private const RATES = [
        3 => [44100, 48000, 32000],   // MPEG1
        2 => [22050, 24000, 16000],   // MPEG2
        0 => [11025, 12000, 8000],    // MPEG2.5
    ];

    /**
     * เฟรมเสียงล้วนของไฟล์ + ความยาว (วินาที) — ไม่ใช่ MP3 ที่อ่านได้ = null
     *
     * @return array{frames:string,duration:float}|null
     */
    public static function parse(string $bytes): ?array
    {
        $pos = 0;
        if (str_starts_with($bytes, 'ID3') && strlen($bytes) >= 10) {
            $size = ((ord($bytes[6]) & 0x7F) << 21) | ((ord($bytes[7]) & 0x7F) << 14)
                | ((ord($bytes[8]) & 0x7F) << 7) | (ord($bytes[9]) & 0x7F);
            $pos = 10 + $size + ((ord($bytes[5]) & 0x10) ? 10 : 0);
        }
        $len = strlen($bytes);
        if ($len >= 128 && substr($bytes, -128, 3) === 'TAG') {
            $len -= 128;
        }

        $out = '';
        $duration = 0.0;
        $first = true;
        while ($pos + 4 <= $len) {
            $frame = self::frame($bytes, $pos);
            if ($frame === null || $pos + $frame['length'] > $len) {
                $pos++;          // ยังไม่ตรงหัวเฟรม — เลื่อนหาจุด sync ถัดไป
                continue;
            }
            $chunk = substr($bytes, $pos, $frame['length']);
            $pos += $frame['length'];
            // เฟรมแรกที่เป็นหัว VBR (Xing/Info/VBRI) = ข้อมูลของไฟล์เดิม ไม่ใช่เสียง
            if ($first && preg_match('/Xing|Info|VBRI/', substr($chunk, 0, 64))) {
                $first = false;
                continue;
            }
            $first = false;
            $out .= $chunk;
            $duration += $frame['samples'] / $frame['rate'];
        }

        return $out === '' ? null : ['frames' => $out, 'duration' => $duration];
    }

    /** @return array{length:int,samples:int,rate:int}|null */
    private static function frame(string $b, int $p): ?array
    {
        $b0 = ord($b[$p]);
        $b1 = ord($b[$p + 1]);
        $b2 = ord($b[$p + 2]);
        if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0) {
            return null;
        }
        $version = ($b1 >> 3) & 3;       // 3 = MPEG1 · 2 = MPEG2 · 0 = MPEG2.5 · 1 = สงวนไว้
        $layer = ($b1 >> 1) & 3;         // 1 = Layer III
        $brIdx = $b2 >> 4;
        $srIdx = ($b2 >> 2) & 3;
        if ($version === 1 || $layer !== 1 || $brIdx === 0 || $brIdx === 15 || $srIdx === 3) {
            return null;
        }
        $mpeg1 = $version === 3;
        $bitrate = self::BITRATES[$mpeg1 ? 1 : 2][$brIdx] * 1000;
        $rate = self::RATES[$version][$srIdx];
        $pad = ($b2 >> 1) & 1;
        $length = intdiv(($mpeg1 ? 144 : 72) * $bitrate, $rate) + $pad;

        return $length > 4 ? ['length' => $length, 'samples' => $mpeg1 ? 1152 : 576, 'rate' => $rate] : null;
    }
}
