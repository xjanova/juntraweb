<?php

namespace Tests\Unit;

use App\Support\Mp3;
use PHPUnit\Framework\TestCase;

/**
 * 🎧 ตัวอ่าน MP3 ของเสียงเล่าคำทำนาย — fixture จริง 3 วิ (ffmpeg libmp3lame 32k mono, มี ID3v2 + เฟรม Info)
 */
class Mp3Test extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/voice-3s.mp3');
    }

    public function test_duration_of_a_real_mp3_is_within_two_percent(): void
    {
        $mp3 = Mp3::parse($this->fixture());

        $this->assertNotNull($mp3);
        $this->assertEqualsWithDelta(3.0, $mp3['duration'], 3.0 * 0.02);
    }

    public function test_id3_and_the_vbr_info_frame_are_stripped(): void
    {
        $bytes = $this->fixture();
        $this->assertStringStartsWith('ID3', $bytes);

        $frames = Mp3::parse($bytes)['frames'];
        $this->assertSame(0xFF, ord($frames[0]), 'ต้องขึ้นต้นด้วยหัวเฟรมเสียง ไม่ใช่ ID3');
        $this->assertStringNotContainsString('Info', substr($frames, 0, 200));
        $this->assertStringNotContainsString('Xing', substr($frames, 0, 200));
    }

    public function test_joined_frames_add_up_and_parse_again(): void
    {
        $one = Mp3::parse($this->fixture());
        $two = Mp3::parse($one['frames'] . $one['frames']);

        $this->assertEqualsWithDelta($one['duration'] * 2, $two['duration'], 0.001);
    }

    public function test_garbage_is_not_audio(): void
    {
        $this->assertNull(Mp3::parse(''));
        $this->assertNull(Mp3::parse(str_repeat('not an mp3 ', 200)));
        $this->assertNull(Mp3::parse('<html>error</html>'));
    }
}
