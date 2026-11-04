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
 *  - 🎵 เพลงบรรเลงโรงละคร: ทุกห้องมี 2 เพลง ไฟล์มีจริง เป็น mp3 ขนาดพอดีมือถือ (2026-11-04)
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

    public function test_every_room_has_two_theater_music_tracks_on_disk(): void
    {
        foreach (ChatRooms::keys() as $persona) {
            $paths = OracleMedia::musicPaths($persona);
            $this->assertCount(2, $paths, "ห้อง {$persona} ต้องมีเพลงบรรเลง 2 เพลง (สลับเฟดข้ามกัน)");

            foreach ($paths as $path) {
                $full = public_path($path);
                $this->assertFileExists($full, "manifest อ้างถึง {$path} แต่ไม่มีไฟล์");
                $this->assertStringEndsWith('.mp3', $path);
                // 96 kbps ≈ 0.72 MB/นาที — เพลงยาวสุดราว 4 นาทีต้องไม่เกิน 4 MB (มือถือโหลดตอนเปิดโรงละคร)
                $this->assertLessThanOrEqual(4_000_000, filesize($full), "{$path} ใหญ่เกินไปสำหรับมือถือ");
                $head = (string) file_get_contents($full, false, null, 0, 3);
                $this->assertTrue(str_starts_with($head, 'ID3') || ord($head[0] ?? 'x') === 0xFF, "{$path} ไม่ใช่ mp3 (ต้องขึ้นต้นด้วย ID3 หรือ frame sync)");
            }

            $this->assertCount(2, OracleMedia::music($persona));
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

    /** (2026-11-04) เสียงครบทุกประโยคของทุกห้องแล้ว — ประโยคใหม่ใน manifest ต้องมาพร้อมไฟล์เสียงเสมอ */
    public function test_every_voice_line_has_a_real_mp3(): void
    {
        foreach (ChatRooms::keys() as $persona) {
            $paths = OracleMedia::voicePaths($persona);
            $this->assertNotEmpty($paths);
            foreach ($paths as $path) {
                $full = public_path($path);
                $this->assertFileExists($full, "ประโยคเสียง {$path} ยังไม่มีไฟล์ — สร้างผ่านเสียงตัวละครของ Thaiprompt (ElevenLabs)");
                $this->assertGreaterThan(5000, filesize($full), "{$path} เล็กผิดปกติ");
                $head = (string) file_get_contents($full, false, null, 0, 3);
                $this->assertTrue(str_starts_with($head, 'ID3') || (ord($head[0] ?? "\0") === 0xFF), "{$path} ไม่ใช่ mp3");
            }
            $this->assertCount(count($paths), OracleMedia::voice($persona), "ห้อง {$persona} เสียงไม่ครบ");
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

    /** ประโยคที่พูดบนหน้าผลทำนาย — ตามสถานะและผลของใบนั้น ห้ามเดาว่า "มาดี" เมื่อไม่รู้โทน */
    public function test_reading_cues_follow_the_reading_state_and_verdict(): void
    {
        $r = fn (?string $status, ?string $result = null) => new \App\Models\Reading(['status' => $status, 'result' => $result]);
        $cues = fn (\App\Models\Reading $x) => OracleMedia::readingCues($x, \App\Support\ReadingSections::parse($x->result));

        $this->assertSame(['reveal_cards', 'reading_wait'], $cues($r('pending')));
        $this->assertSame(['reveal_cards', 'reading_wait'], $cues($r('working')));
        $this->assertSame(['reading_failed'], $cues($r('failed')));

        $verdict = fn (string $res) => "## 🎯 ผลฟันธง\n**ผล: {$res}**\nเหตุผล\n\n## 🃏 ใบที่ 1 · ปัจจุบัน\nความหมาย";
        $this->assertSame(['verdict_good'], $cues($r(null, $verdict('ใช่ — มาแน่'))));
        $this->assertSame(['verdict_caution'], $cues($r(null, $verdict('ไม่ใช่คนนี้'))));
        $this->assertSame(['verdict_caution'], $cues($r(null, $verdict('ยังไม่ใช่ตอนนี้'))));
        $this->assertSame(['followup_ask'], $cues($r(null, $verdict('ขึ้นกับการตัดสินใจ'))));
        $this->assertSame(['followup_ask'], $cues($r(null, 'คำทำนายแบบข้อความยาวไม่มีหัวข้อ')));

        $diag = fn (string $found) => "## 🪬 ผลตรวจคุณไสย\nพบของ: {$found}\nชนิด: —\n\n## 🃏 ใบที่ 1 · พลังที่ครอบคุณอยู่\nความหมาย";
        $this->assertSame(['kunsai_found'], $cues($r(null, $diag('พบ — ของเสน่ห์'))));
        $this->assertSame(['kunsai_clear'], $cues($r(null, $diag('ไม่พบ'))));
        $this->assertSame(['kunsai_unclear'], $cues($r(null, $diag('ไม่ชัดเจน'))));
    }

    public function test_spread_voice_belongs_to_the_right_room(): void
    {
        $this->assertSame('kunsai', OracleMedia::personaFor('kunsai'));
        $this->assertSame('maemor', OracleMedia::personaFor('three'));

        session(['chat_room' => 'busaba']);
        $this->assertSame('busaba', OracleMedia::personaFor('three'));
        $this->assertSame('kunsai', OracleMedia::personaFor('kunsai'));

        session(['chat_room' => 'ไม่มีห้องนี้']);
        $this->assertSame('maemor', OracleMedia::personaFor('three'));
    }

    /** ปุ่มลำโพงลอยบนหน้าเลือกไพ่/ผล: ไม่มีไฟล์ = ไม่มีปุ่ม · ส่งเฉพาะประโยคที่มีไฟล์ · ไม่เล่นเอง */
    public function test_voice_button_only_appears_with_files_and_never_autoplays(): void
    {
        $dir = 'media/oracle-test-' . uniqid();
        config(['oracle_media.dir' => $dir]);

        try {
            $none = (string) $this->blade('<x-oracle-voice persona="maemor" :cues="[\'shuffle_focus\']" />');
            $this->assertStringNotContainsString('oracle-voice-fab', $none);

            File::ensureDirectoryExists(public_path("{$dir}/maemor/voice"));
            File::put(public_path("{$dir}/maemor/voice/shuffle_focus.mp3"), "ID3\x03fake");
            $html = (string) $this->blade('<x-oracle-voice persona="maemor" :cues="[\'shuffle_focus\', \'pick_complete\']" scope="reading-1" />');

            $this->assertStringContainsString('oracle-voice-fab', $html);
            $this->assertStringContainsString('shuffle_focus.mp3', $html);
            $this->assertStringNotContainsString('pick_complete.mp3', $html);
            $this->assertStringContainsString('aria-pressed', $html);
            $this->assertStringNotContainsString('autoplay', $html);
        } finally {
            File::deleteDirectory(public_path($dir));
        }
    }
}
