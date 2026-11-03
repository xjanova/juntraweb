<?php

namespace Tests\Feature;

use App\Support\ChatRooms;
use App\Support\OracleMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 🎬 (2026-11-03) สื่อของผู้พยากรณ์ — config/oracle_media.php + <x-oracle-stage>
 *
 * สิ่งที่ล็อกไว้:
 *  - ทุกห้องมี manifest · ทุกคลิปที่อ้างถึงมีไฟล์จริงทั้ง .mp4 และ .webm (deploy แล้วต้องไม่มี 404)
 *  - คลิปเล็กพอสำหรับมือถือ (≤ 1.5 MB ต่อไฟล์)
 *  - เสียง: ไฟล์ไหนยังไม่มี = เงียบ (ไม่ส่ง URL ไปหน้าเว็บ) · ไม่มีไฟล์เลย = ไม่มีปุ่มลำโพง
 *  - ประโยคเสียงห้ามมีราคา · ไม่มี voice ID ใน manifest (เจ้าของเปลี่ยนเสียงได้ที่หลังบ้าน Thaiprompt)
 *  - คอมโพเนนต์ไม่เล่นเสียงเอง: <video> muted ทุกตัว ไม่มี autoplay
 */
class OracleMediaTest extends TestCase
{
    use RefreshDatabase;   // view composer ของ layout อ่าน settings

    public function test_every_room_has_a_media_manifest(): void
    {
        $this->assertSame(ChatRooms::keys(), array_keys(config('oracle_media.rooms')));
    }

    public function test_every_referenced_loop_file_exists_and_is_small_enough_for_mobile(): void
    {
        foreach (ChatRooms::keys() as $persona) {
            $paths = OracleMedia::loopPaths($persona);
            $this->assertNotEmpty($paths, "ห้อง {$persona} ไม่มีคลิป idle");

            foreach ($paths as $files) {
                foreach ($files as $path) {
                    $full = public_path($path);
                    $this->assertFileExists($full, "manifest อ้างถึง {$path} แต่ไม่มีไฟล์");
                    $this->assertLessThanOrEqual(1_500_000, filesize($full), "{$path} ใหญ่เกินไปสำหรับมือถือ");
                }
            }

            // ทุกคลิปของห้องส่งถึงหน้าเว็บครบ (ไม่มีตัวไหนตกหล่นเพราะไฟล์หาย)
            $this->assertCount(count($paths), OracleMedia::loops($persona));
        }
    }

    public function test_room_posters_exist(): void
    {
        foreach (ChatRooms::all() as $key => $room) {
            $this->assertFileExists(public_path($room['image']), "ภาพนิ่งห้อง {$key} หาย");
        }
    }

    public function test_voice_lines_carry_no_price_and_no_voice_id(): void
    {
        foreach (config('oracle_media.rooms') as $persona => $room) {
            $this->assertNotEmpty($room['voice'] ?? [], "ห้อง {$persona} ไม่มีประโยคเสียง");
            $this->assertArrayHasKey('room_greet', $room['voice']);

            foreach ($room['voice'] as $key => $text) {
                $this->assertDoesNotMatchRegularExpression('/฿|บาท|\d+\s*เครดิต/u', $text, "{$persona}.{$key} มีราคา");
                $this->assertMatchesRegularExpression('/^\[[a-z]+\] \S/u', $text, "{$persona}.{$key} ต้องขึ้นต้นด้วยแท็กน้ำเสียง");
            }
        }

        $this->assertStringNotContainsStringIgnoringCase('voice_id', File::get(config_path('oracle_media.php')));
    }

    public function test_existing_voice_files_are_real_mp3s(): void
    {
        foreach (ChatRooms::keys() as $persona) {
            $paths = OracleMedia::voicePaths($persona);
            $this->assertNotEmpty($paths);
            foreach ($paths as $path) {
                $full = public_path($path);
                if (! is_file($full)) {
                    continue;   // ยังรอ endpoint เสียงของ Thaiprompt — เงียบไว้ก่อน
                }
                $head = (string) file_get_contents($full, false, null, 0, 3);
                $this->assertTrue(str_starts_with($head, 'ID3') || (ord($head[0] ?? "\0") === 0xFF), "{$path} ไม่ใช่ mp3");
            }
        }
    }

    public function test_missing_voice_files_stay_silent_and_existing_ones_are_served(): void
    {
        $dir = 'media/oracle-test-' . uniqid();
        config(['oracle_media.dir' => $dir]);

        try {
            $this->assertSame([], OracleMedia::voice('busaba'));
            $this->assertSame([], OracleMedia::loops('busaba'));

            File::ensureDirectoryExists(public_path("{$dir}/busaba/voice"));
            File::put(public_path("{$dir}/busaba/voice/room_greet.mp3"), "ID3\x03fake");
            File::put(public_path("{$dir}/busaba/idle-breathe.mp4"), 'mp4');   // ไม่มี webm คู่ = ใช้ mp4 อย่างเดียว

            $voice = OracleMedia::voice('busaba');
            $this->assertSame(['room_greet'], array_keys($voice));
            $this->assertStringContainsString("{$dir}/busaba/voice/room_greet.mp3?v=", $voice['room_greet']);

            $loops = OracleMedia::loops('busaba');
            $this->assertCount(1, $loops);
            $this->assertNull($loops[0]['webm']);

            // ห้องอื่นไม่ได้ไฟล์ของบุษบา
            $this->assertSame([], OracleMedia::voice('kunsai'));
        } finally {
            File::deleteDirectory(public_path($dir));
        }
    }

    public function test_stage_renders_muted_loops_and_hides_the_speaker_without_voice_files(): void
    {
        $dir = 'media/oracle-test-' . uniqid();
        config(['oracle_media.dir' => $dir]);
        File::ensureDirectoryExists(public_path("{$dir}/maemor"));
        File::put(public_path("{$dir}/maemor/idle-breathe.mp4"), 'mp4');
        File::put(public_path("{$dir}/maemor/idle-breathe.webm"), 'webm');

        try {
            $html = (string) $this->blade('<x-oracle-stage persona="maemor" poster="/images/x.webp" alt="แม่หมอ" sound />');

            $this->assertStringContainsString('class="oracle-stage"', $html);
            $this->assertStringContainsString('src="/images/x.webp"', $html);
            $this->assertStringContainsString('idle-breathe.webm', $html);
            $this->assertSame(2, substr_count($html, '<video'));
            $this->assertSame(2, substr_count($html, ' muted '));
            $this->assertStringNotContainsString('autoplay', $html);
            // ไม่มีไฟล์เสียง = ไม่มีปุ่มลำโพงให้กดแล้วเงียบ
            $this->assertStringNotContainsString('oracle-sound', $html);

            File::ensureDirectoryExists(public_path("{$dir}/maemor/voice"));
            File::put(public_path("{$dir}/maemor/voice/room_greet.mp3"), "ID3\x03fake");
            $withVoice = (string) $this->blade('<x-oracle-stage persona="maemor" poster="/images/x.webp" sound />');
            $this->assertStringContainsString('oracle-sound', $withVoice);
            $this->assertStringContainsString('aria-pressed', $withVoice);

            // หน้าเลือกห้องไม่ขอเสียง → ไม่มีปุ่มแม้มีไฟล์
            $noSound = (string) $this->blade('<x-oracle-stage persona="maemor" poster="/images/x.webp" />');
            $this->assertStringNotContainsString('oracle-sound', $noSound);
        } finally {
            File::deleteDirectory(public_path($dir));
        }
    }

    public function test_stage_without_any_clip_is_just_the_still(): void
    {
        config(['oracle_media.dir' => 'media/oracle-none-' . uniqid()]);

        $html = (string) $this->blade('<x-oracle-stage persona="kunsai" poster="/images/k.webp" sound />');

        $this->assertStringContainsString('src="/images/k.webp"', $html);
        $this->assertStringNotContainsString('<video', $html);
        $this->assertStringNotContainsString('oracle-sound', $html);
    }
}
