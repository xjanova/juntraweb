<?php

namespace App\Services\Voice;

use App\Models\Reading;
use App\Services\Thaiprompt\JuntraServerClient;
use App\Support\Mp3;
use App\Support\OracleMedia;
use App\Support\TarotSpreads;
use App\Support\VoicePremium;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ✨ (2026-11-05) เสียงเล่าคำทำนายของพรีเมียม — ทยอยสร้างทีละบทตามจังหวะที่หน้าผลถาม (voice-mode-plan ข้อ 13.2)
 *
 * ทำไมทีละบท: เว็บนี้ไม่มีคิวงานเบื้องหลัง และคำขอหน้าเว็บมีเพดาน ~60 วิ — หน้าผลเรียก step() ซ้ำ
 * (ครั้งละ 1 บท ≤ ~20 วิ) จนครบ · ปิดหน้ากลางทาง = หยุดสร้าง กลับมาเปิดใหม่สร้างต่อจากบทที่ค้าง
 *
 *   1. ขอบทเล่าจาก Thaiprompt (/voice/narration — ผ่านตัวตรวจไม่ให้แต่งเพิ่มแล้ว)
 *   2. ขอเสียงทีละบท (/voice/speak purpose=narration) → ดาวน์โหลดเก็บในเว็บ
 *   3. ครบ → ต่อเป็นไฟล์เดียว storage/app/reading-voice/{id}/voice.mp3 + เวลาเริ่ม/จบของแต่ละบท
 *      → readings.payload.voice ตามสัญญา 23.2 (โรงละครคำทำนายเล่นตามนั้น)
 *   ไม่ได้เสียงเลยสักบท = คืนเงินพรีเมียมอัตโนมัติ (ค่าเปิดไพ่ไม่เกี่ยว)
 */
class ReadingVoiceBuilder
{
    /** บทละกี่ครั้งก่อนยอมข้ามบทนั้น · ขอบทเล่ากี่ครั้งก่อนยอมแพ้ · คลิปค้าง pending ถามได้กี่รอบ */
    private const TRIES = 2;
    private const SCRIPT_TRIES = 3;
    private const POLLS = 4;

    /** ไฟล์บทหนึ่งใหญ่สุดที่ยอมดาวน์โหลด (~60 วิ ที่ 128kbps = ~1 MB) */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private JuntraServerClient $client) {}

    public static function dir(Reading $reading): string
    {
        return storage_path('app/reading-voice/' . $reading->id);
    }

    public static function file(Reading $reading): string
    {
        return self::dir($reading) . '/voice.mp3';
    }

    /** สถานะให้หน้าผล: building (ยังสร้างอยู่) · ready/partial (เสร็จ) · none (ไม่มีเสียง คืนเงินแล้ว) */
    public static function view(Reading $reading): array
    {
        $voice = data_get($reading->payload, 'voice');
        $build = data_get($reading->payload, 'voice_build.chapters');
        $total = is_array($build) ? count($build) : 0;
        $done = is_array($build) ? count(array_filter($build, fn ($c) => ! empty($c['file']))) : 0;
        $status = is_array($voice) ? (string) ($voice['status'] ?? 'none')
            : (VoicePremium::active($reading) ? 'building' : 'none');

        return [
            'status'   => $status,
            'done'     => $done,
            'total'    => $total,
            'refunded' => data_get($reading->payload, 'premium.status') === 'refunded',
        ];
    }

    /** ทำงาน 1 ก้าว (ขอบท / สร้างเสียง 1 บท / รวมไฟล์) แล้วคืนสถานะล่าสุด */
    public function step(Reading $reading): array
    {
        if (is_array(data_get($reading->payload, 'voice')) || ! VoicePremium::active($reading)) {
            return self::view($reading);
        }

        $build = data_get($reading->payload, 'voice_build');
        if (! is_array($build) || ! is_array($build['chapters'] ?? null)) {
            return $this->script($reading);
        }

        foreach ($build['chapters'] as $i => $c) {
            if (! empty($c['file']) || (int) ($c['tries'] ?? 0) >= self::TRIES) {
                continue;
            }
            $build['chapters'][$i] = $this->speak($reading, $i, $c);
            $this->save($reading, ['voice_build' => $build]);

            return self::view($reading);
        }

        return $this->finish($reading, $build);
    }

    private function script(Reading $reading): array
    {
        $key = TarotSpreads::keyFromType((string) $reading->type) ?? 'three';
        $reading->loadMissing('tarotCards.card');
        $chapters = $this->client->voiceNarration([
            'persona'      => $this->persona($reading),
            'spread'       => $key,
            'reading_text' => mb_substr((string) $reading->result, 0, 20000),
            'cards'        => $reading->tarotCards->map(fn ($rc) => [
                'position' => (int) $rc->position,
                'name_th'  => mb_substr((string) ($rc->card?->name_th ?? ''), 0, 60),
                'reversed' => (bool) $rc->reversed,
            ])->values()->all(),
            'birth_known'  => filled(data_get($reading->payload, 'birth_date')),
            // 0 = ไม่อั้น (เจ้าของสั่ง 2026-11-07) · ค่าบวก 100–2000 = งบรวมทุกบท
            'budget_chars' => max(0, (int) config('reading_voice.narration_budget', 0)),
            'user_ref'     => (string) $reading->user_id,
        ]);

        if (! $chapters) {
            $fails = (int) data_get($reading->payload, 'voice_build_fails', 0) + 1;
            $this->save($reading, ['voice_build_fails' => $fails]);

            return $fails >= self::SCRIPT_TRIES
                ? $this->giveUp($reading, 'สร้างบทเล่าคำทำนายด้วยเสียงไม่สำเร็จ')
                : self::view($reading);
        }

        $this->save($reading, ['voice_build' => ['chapters' => array_map(fn ($c) => [
            'anchor'  => mb_substr((string) ($c['anchor'] ?? ''), 0, 20),
            'kind'    => isset($c['kind']) ? mb_substr((string) $c['kind'], 0, 12) : null,
            'tone'    => $c['tone'] ?? null,
            'energy'  => is_numeric($c['energy'] ?? null) ? (float) $c['energy'] : null,
            'say'     => mb_substr((string) $c['say'], 0, 2000),
            'file'    => null,
            'dur'     => null,
            'clip_id' => null,
            'polls'   => 0,
            'tries'   => 0,
        ], $chapters)]]);

        return self::view($reading);
    }

    /** ขอเสียงของบทที่ $i (หรือถามคลิปที่ค้าง) — คืนบทที่อัปเดตแล้ว */
    private function speak(Reading $reading, int $i, array $c): array
    {
        $r = ! empty($c['clip_id'])
            ? $this->client->voiceClip((int) $c['clip_id'], 15)
            : $this->client->voiceSpeak($this->persona($reading), (string) $c['say'], 'narration', (string) $reading->user_id, 15);

        if ($r['status'] === 'completed' && ($file = $this->download($reading, $i, (string) $r['url'])) !== null) {
            return array_merge($c, ['file' => $file['name'], 'dur' => $file['duration'], 'clip_id' => null]);
        }
        if ($r['status'] === 'pending' && (int) ($c['polls'] ?? 0) < self::POLLS) {
            return array_merge($c, ['clip_id' => $r['clip_id'], 'polls' => (int) ($c['polls'] ?? 0) + 1]);
        }

        return array_merge($c, ['clip_id' => null, 'polls' => 0, 'tries' => (int) ($c['tries'] ?? 0) + 1]);
    }

    /** @return array{name:string,duration:float}|null */
    private function download(Reading $reading, int $i, string $url): ?array
    {
        try {
            $resp = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Reading voice: download threw', ['reading' => $reading->id, 'err' => mb_substr($e->getMessage(), 0, 160)]);

            return null;
        }
        $body = $resp->successful() ? $resp->body() : '';
        if ($body === '' || strlen($body) > self::MAX_BYTES || ($mp3 = Mp3::parse($body)) === null || $mp3['duration'] < 0.3) {
            Log::warning('Reading voice: bad audio', ['reading' => $reading->id, 'status' => $resp->status(), 'bytes' => strlen($body)]);

            return null;
        }
        File::ensureDirectoryExists(self::dir($reading));
        $name = sprintf('part-%02d.mp3', $i);
        File::put(self::dir($reading) . '/' . $name, $mp3['frames']);

        return ['name' => $name, 'duration' => round($mp3['duration'], 3)];
    }

    /** ครบทุกบทแล้ว → ต่อเป็นไฟล์เดียว + เวลาของแต่ละบท */
    private function finish(Reading $reading, array $build): array
    {
        $dir = self::dir($reading);
        $out = '';
        $t = 0.0;
        $chapters = [];
        $total = count($build['chapters']);
        foreach ($build['chapters'] as $c) {
            $path = ! empty($c['file']) ? $dir . '/' . $c['file'] : null;
            if ($path === null || ! is_file($path)) {
                continue;
            }
            $out .= File::get($path);
            $chapters[] = [
                'anchor' => $c['anchor'], 'kind' => $c['kind'], 'tone' => $c['tone'], 'energy' => $c['energy'],
                'say'    => $c['say'], 'start' => round($t, 3), 'end' => round($t + (float) $c['dur'], 3),
            ];
            $t += (float) $c['dur'];
        }

        if ($chapters === []) {
            return $this->giveUp($reading, 'สร้างเสียงเล่าคำทำนายไม่สำเร็จ');
        }

        File::put(self::file($reading), $out);
        foreach ($build['chapters'] as $c) {
            if (! empty($c['file'])) {
                File::delete($dir . '/' . $c['file']);
            }
        }
        $this->save($reading, [
            'voice' => [
                'status'   => count($chapters) === $total ? 'ready' : 'partial',
                'provider' => 'elevenlabs',
                'persona'  => $this->persona($reading),
                'url'      => route('tarot.voice.file', $reading, false),
                'duration' => round($t, 3),
                'chapters' => $chapters,
            ],
            'voice_build' => null,
        ]);

        return self::view($reading);
    }

    /** ไม่ได้เสียงเลย → คืนเงินพรีเมียม + จำไว้ว่าคำทำนายนี้ไม่มีเสียง (โรงละครเดินบทแบบเงียบ) */
    private function giveUp(Reading $reading, string $reason): array
    {
        VoicePremium::refund($reading, $reason);
        $this->save($reading, ['voice' => ['status' => 'none'], 'voice_build' => null]);
        File::deleteDirectory(self::dir($reading));

        return self::view($reading);
    }

    private function persona(Reading $reading): string
    {
        return (string) (data_get($reading->payload, 'room')
            ?: OracleMedia::personaFor(TarotSpreads::keyFromType((string) $reading->type) ?? 'three'));
    }

    /** เขียนทับเฉพาะคีย์ที่ส่งมาใน payload (null = ลบคีย์) — ล็อกแถว กันชนกับการคืนเงิน/นับตัวอักษร */
    private function save(Reading $reading, array $changes): void
    {
        DB::transaction(function () use ($reading, $changes) {
            $r = Reading::whereKey($reading->id)->lockForUpdate()->first();
            if (! $r) {
                return;
            }
            $p = (array) $r->payload;
            foreach ($changes as $k => $v) {
                if ($v === null) {
                    unset($p[$k]);
                } else {
                    $p[$k] = $v;
                }
            }
            $r->payload = $p;
            $r->save();
            $reading->setRawAttributes($r->getAttributes(), true);
        });
    }
}
