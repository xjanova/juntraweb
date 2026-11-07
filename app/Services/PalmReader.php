<?php

namespace App\Services;

use App\Exceptions\PalmNotVisibleException;
use App\Services\FortuneBot\FortuneBotClient;
use App\Services\Thaiprompt\JuntraServerClient;
use Illuminate\Support\Facades\Log;

/**
 * ✋ อ่านลายมือ — ด่าน vision ของบอทแม่หมอ (Thaiprompt fortune/palm) ก่อน แล้วค่อย Gemini ในเครื่อง
 *
 * 🔴 (2026-11-07) เดิมใช้แต่ AiOracle (Gemini ในเครื่อง) ซึ่ง `ai_api_key` บน prod ว่าง ⇒ บริการดูลายมือ
 *    ปฏิเสธลูกค้าทุกคนตั้งแต่ยังไม่หักเงิน ตอนนี้ใช้คีย์ชุดเดียวกับบอท (เคารพสวิตช์ vision ของแอดมินฝั่งนั้น)
 *
 * ใช้ร่วมกันทั้งหน้าเว็บและแอพ — ทั้งสองทางต้องได้ผลแบบเดียวกัน
 */
class PalmReader
{
    /** ด้านยาวสุดหลังย่อ — พอให้เห็นเส้นบนฝ่ามือ และ base64 ไม่เกินเพดานฝั่งนั้น (6.5 MB) */
    private const MAX_SIDE = 1600;

    /** ไฟล์ดิบที่ส่งได้เลยเมื่อย่อเองไม่ได้ (ไม่มี GD) — base64 โตขึ้น ~4/3 */
    private const RAW_MAX_BYTES = 4_500_000;

    private const RAW_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private FortuneBotClient $bot,
        private JuntraServerClient $server,
        private AiOracle $oracle,
    ) {}

    /** มีทางอ่านอย่างน้อยหนึ่งทาง — ไม่มีเลยต้องปฏิเสธก่อนหักเงิน */
    public function isAvailable(): bool
    {
        return $this->server->isConfigured() || $this->oracle->isConfigured();
    }

    /**
     * @return array{reading:string, ai_provider:string, ai_model:string}
     *
     * @throws PalmNotVisibleException รูปไม่เห็นฝ่ามือ
     * @throws \RuntimeException อ่านไม่ได้ทุกทาง (ผู้เรียกคืนเงิน)
     */
    public function read(string $absolutePath, ?string $question, ?string $name = null): array
    {
        $dataUrl = $this->dataUrl($absolutePath);
        if ($dataUrl !== null) {
            $res = $this->bot->palmReading($dataUrl, $question, $name);
            if ($res['status'] === 'ok') {
                return [
                    'reading' => (string) $res['data']['reading'],
                    'ai_provider' => (string) ($res['data']['ai_provider'] ?? 'thaiprompt'),
                    'ai_model' => (string) ($res['data']['ai_model'] ?? 'vision'),
                ];
            }
            if ($res['status'] === 'not_palm') {
                throw new PalmNotVisibleException('palm: not a palm');
            }
        }

        if (! $this->oracle->isConfigured()) {
            throw new \RuntimeException('palm: no reader available');
        }

        return [
            'reading' => $this->oracle->analyzePalmImage($absolutePath, $question),
            'ai_provider' => $this->oracle->provider(),
            'ai_model' => $this->oracle->model(),
        ];
    }

    /** รูป → data URL (ย่อเป็น JPEG ถ้าทำได้) · null = ส่งไม่ได้ (ชนิด/ขนาดไม่ผ่าน) */
    private function dataUrl(string $path): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        if (function_exists('imagecreatefromstring')) {
            $jpeg = $this->downscale($bytes);
            if ($jpeg !== null) {
                return 'data:image/jpeg;base64,'.base64_encode($jpeg);
            }
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! in_array($mime, self::RAW_MIMES, true) || strlen($bytes) > self::RAW_MAX_BYTES) {
            Log::info('PalmReader: ส่งรูปไป Thaiprompt ไม่ได้', ['mime' => $mime, 'bytes' => strlen($bytes)]);

            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function downscale(string $bytes): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        try {
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1.0, self::MAX_SIDE / max($w, $h, 1));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            // PNG โปร่งใส → พื้นขาว (JPEG ไม่มีช่องโปร่งใส ไม่งั้นพื้นจะดำทั้งรูป)
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            imagejpeg($dst, null, 85);
            $out = (string) ob_get_clean();
            imagedestroy($dst);

            return $out !== '' ? $out : null;
        } finally {
            imagedestroy($src);
        }
    }
}
