<?php

namespace App\Http\Controllers;

use App\Models\FreeReadingClaim;
use App\Models\Reading;
use App\Models\TarotCard;
use App\Services\FortuneBot\FortuneAiService;
use App\Support\FreeReadingPolicy;
use App\Support\TarotSpreads;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 🎁 (2026-07-26) ดูดวงฟรี 1 ใบ — ด่านแรกของลูกค้าที่บอทเฟซบุ๊กส่งมา
 *
 * ทำไมแยกคอนโทรลเลอร์ ไม่ยัดใน TarotController:
 *   flow ต่างกันคนละเรื่อง — ไม่มีขั้นเลือกสเปรด ไม่มีขั้นกางไพ่ ไม่มีคำถาม
 *   ไม่มีการหักเครดิต/คืนเครดิต และมีกติกาโควตาของตัวเอง
 *   (createReading ของตัวเดิมผูกกับ debit/refund/guardCharge ทั้งดุ้น)
 *
 * ทำไม GET ไม่ทำนายเลยทั้งที่อยากให้ "เปิดหน้าปุ๊บได้เลย":
 *   GET ที่มี side effect (สร้าง reading + ยิง AI) โดนรีเฟรช/prefetch ยิงซ้ำได้
 *   จึงให้ GET เรนเดอร์หน้ารอ แล้วให้หน้าเว็บยิง POST ให้เองครั้งเดียว (PRG)
 *   ลูกค้ายังรู้สึกว่า "เปิดปุ๊บแม่หมอเปิดไพ่ให้เลย" เหมือนเดิม
 *
 * กติกาสำคัญ: **AI ล้ม = คืนสิทธิ์ฟรี** ไม่ใช่เผาทิ้ง (ลูกค้ามีโอกาสเดียว)
 */
class FreeTarotController extends Controller
{
    public function __construct(private FortuneAiService $ai) {}

    /**
     * GET /tarot/free — หน้ารอ + สั่งเปิดไพ่ให้อัตโนมัติ
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $gate = FreeReadingPolicy::gate($user);

        // เคยรับไปแล้วและมีผลอยู่ → พาไปดูผลเดิม ไม่ใช่ขึ้นข้อความปฏิเสธ
        if ($gate['code'] === 'used' && ! empty($gate['reading_id'])) {
            return redirect()->route('tarot.show', $gate['reading_id'])
                ->with('status', 'นี่คือคำทำนายฟรีที่แม่หมอเปิดให้คุณไว้ค่ะ ✨');
        }

        return view('pages.tarot.free', [
            'gate' => $gate,
            // ลูกค้าที่หลุด SSO มาแบบยังไม่ล็อกอิน — ให้กดกลับเข้าระบบแล้วเด้งมาที่นี่
            'loginUrl' => route('thaiprompt.redirect', ['to' => '/tarot/free']),
        ]);
    }

    /**
     * POST /tarot/free/cast — เปิดไพ่ 1 ใบ + ขอคำทำนายสั้นจากคลังความรู้แม่หมอ
     */
    public function cast(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('tarot.free');
        }

        // กันกดรัว/รีเฟรชรัว — guardCharge ของระบบเดิมปล่อยผ่านเมื่อไม่มี _idem
        // จึงต้องล็อกเอง และต้องปล่อยล็อกเสมอ (ของเดิมจับแล้วไม่ปล่อย ค้าง 90 วิ)
        $lock = Cache::lock('free_reading:'.$user->id, 60);
        if (! $lock->get()) {
            return redirect()->route('tarot.free')
                ->with('status', 'แม่หมอกำลังเปิดไพ่ให้อยู่ค่ะ รอสักครู่นะคะ 🔮');
        }

        try {
            // เช็คสิทธิ์ใน lock อีกครั้ง — ตัวที่ตัดสินจริงคือรอบนี้
            $gate = FreeReadingPolicy::gate($user);
            if (! $gate['allowed']) {
                if (! empty($gate['reading_id'])) {
                    return redirect()->route('tarot.show', $gate['reading_id']);
                }

                // ไม่ต้องแนบ status — หน้าปลายทางแสดง reason ให้อยู่แล้ว
                // (ไม่งั้นข้อความเดียวกันขึ้นสองรอบบนจอเดียว)
                return redirect()->route('tarot.free');
            }

            $card = TarotCard::where('active', true)->inRandomOrder()->first();
            if (! $card) {
                Log::error('FreeTarot: ไม่มีไพ่ที่ active ในสำรับ');

                return redirect()->route('tarot.index')
                    ->with('status', 'ขออภัย สำรับไพ่ยังไม่พร้อมค่ะ');
            }

            // ล็อกรายคนกันกดซ้ำได้ แต่กัน "เพดานรวมต่อวัน" ไม่ได้ — คนละ key กัน
            // ตอนบอทบรอดแคสต์ คนกดพร้อมกันหลายสิบ ทุกคนอ่าน usedToday() ได้ค่าเดียวกัน
            // แล้วผ่านไปหมด → ล็อกร่วมสั้น ๆ เฉพาะช่วง "ตรวจเพดาน + จองสลอต"
            // (ไม่ครอบการยิง AI จึงไม่ทำให้คิวตัน)
            $claim = null;
            $capLock = Cache::lock('free_reading:cap', 10);
            try {
                $capLock->block(5);

                if (FreeReadingPolicy::capReached()) {
                    return redirect()->route('tarot.free');
                }

                $claim = $this->reserveClaim($user, $request->ip());
            } catch (LockTimeoutException $e) {
                return redirect()->route('tarot.free')
                    ->with('status', 'ตอนนี้มีคนขอดูดวงพร้อมกันเยอะค่ะ 🙏 รอสักครู่แล้วลองใหม่นะคะ');
            } finally {
                optional($capLock)->release();
            }

            $reading = null;

            try {
                $reading = $this->createReading($user, $card);
                $result = $this->ai->freeTarot($reading, $user, FreeReadingPolicy::maxChars());

                if (! $result) {
                    // 🎁 คืนสิทธิ์: ลูกค้าไม่ควรเสียโอกาสเดียวที่มีเพราะระบบเราล่ม
                    $this->releaseClaim($claim, $reading);

                    return redirect()->route('tarot.free')
                        ->with('free_retry', true)
                        ->with('status', 'ตอนนี้แม่หมอยังเปิดไพ่ให้ไม่ได้ค่ะ 🙏 สิทธิ์ดูฟรียังอยู่ครบ ลองใหม่อีกครั้งนะคะ');
                }

                $payload = $reading->payload ?? [];
                $payload['next_questions'] = array_values(array_slice($result['next_questions'], 0, 2));
                $reading->payload = $payload;
                $reading->result = $result['text'];
                $reading->ai_provider = $result['provider'];
                $reading->ai_model = $result['model'];
                $reading->save();

                $claim->forceFill(['reading_id' => $reading->id])->save();
            } catch (\Throwable $e) {
                Log::error('FreeTarot: เปิดไพ่ฟรีล้มเหลว — คืนสิทธิ์ให้ลูกค้า', [
                    'user_id' => $user->id,
                    'err' => $e->getMessage(),
                ]);
                $this->releaseClaim($claim, $reading);

                return redirect()->route('tarot.free')
                    ->with('free_retry', true)
                    ->with('status', 'ระบบขัดข้องชั่วคราวค่ะ 🙏 สิทธิ์ดูฟรียังอยู่ครบ ลองใหม่อีกครั้งนะคะ');
            }

            return redirect()->route('tarot.show', $reading);
        } finally {
            $lock->release();
        }
    }

    /**
     * จองสิทธิ์ก่อนยิง AI — UNIQUE(user_id,batch) เป็นด่านสุดท้ายกันแจกซ้ำ
     *
     * ถ้ามีแถวค้างจากรอบที่โปรเซสตายกลางคัน (reading_id ว่าง + เก่าเกินกำหนด)
     * ให้ใช้แถวเดิมซ้ำ ไม่ใช่ insert ใหม่ (จะชน unique)
     */
    private function reserveClaim($user, ?string $ip): FreeReadingClaim
    {
        $claim = FreeReadingPolicy::claimOf($user);

        if ($claim) {
            // ใช้แถวเดิมซ้ำ (ไม่งั้นชน UNIQUE) — ล้างสถานะล้มเหลวของรอบก่อน
            // และเลื่อน created_at เพื่อให้หน้าต่าง in-flight เริ่มนับใหม่
            $claim->forceFill([
                'ip' => $ip,
                'failed_at' => null,
                'reading_id' => null,
                'created_at' => now(),
            ])->save();

            return $claim;
        }

        return FreeReadingClaim::create([
            'user_id' => $user->id,
            'batch' => FreeReadingPolicy::batch(),
            'ip' => $ip,
        ]);
    }

    /**
     * คืนสิทธิ์ให้ลูกค้า + เก็บกวาด reading ที่ไม่มีคำทำนาย
     *
     * ⚠️ ไม่ลบแถว claim ทิ้ง — ตั้ง failed_at แทน เพราะ:
     *   1. ลูกค้าได้สิทธิ์คืนอยู่แล้ว (claimBlocks มองแถว failed = ไม่กัน)
     *   2. เพดานต้นทุนต่อวันยังนับครั้งนี้อยู่ — รอบที่ AI ตอบช้าจนหมดเวลา
     *      ฝั่งโน้น generate เสร็จและจ่าย token ไปแล้วจริง ถ้าลบแถวทิ้ง
     *      เพดานจะค้างที่ 0 ตอนที่อัปสตรีมมีปัญหา = ตอนที่ต้องเบรกที่สุด
     */
    private function releaseClaim(?FreeReadingClaim $claim, ?Reading $reading): void
    {
        try {
            if ($reading && $reading->exists) {
                $reading->tarotCards()->delete();
                $reading->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('FreeTarot: ลบ reading ที่ล้มเหลวไม่สำเร็จ', ['err' => $e->getMessage()]);
        }

        try {
            $claim?->forceFill(['failed_at' => now(), 'reading_id' => null])->save();
        } catch (\Throwable $e) {
            Log::warning('FreeTarot: คืนสิทธิ์ไม่สำเร็จ', ['err' => $e->getMessage()]);
        }
    }

    /**
     * สร้าง reading ไพ่ใบเดียว — ใช้สเปรด 'single' ที่ลงทะเบียนอยู่แล้ว
     *
     * ต้องเป็น type ที่ TarotSpreads::isTarotType() รู้จัก ไม่งั้นหน้าผลลัพธ์
     * (tarot.show) จะ abort(404) ทันที
     */
    private function createReading($user, TarotCard $card): Reading
    {
        $key = 'single';
        $type = TarotSpreads::typeFromKey($key);
        $positions = TarotSpreads::positionLabels($key);

        // ห่อ transaction — ถ้าแถวไพ่ insert ไม่ผ่าน จะได้ไม่เหลือ reading กำพร้า
        // ที่ไม่มีคำทำนายและไม่มีใครลบ (releaseClaim จะไม่เห็นมันด้วยซ้ำ)
        return DB::transaction(function () use ($user, $card, $type, $positions) {
            return $this->persistReading($user, $card, $type, $positions);
        });
    }

    /** @param  array<int,string>  $positions */
    private function persistReading($user, TarotCard $card, string $type, array $positions): Reading
    {
        $reading = Reading::create([
            'user_id' => $user->id,
            'session_token' => Str::uuid()->toString(),
            'type' => $type,
            'question' => null,
            'payload' => [
                'positions' => $positions,
                'cost' => 0,
                'wallet_tx_id' => null,
                // ธงนี้คือตัวแยก "ของฟรี" ออกจากไพ่ใบเดียวที่ขาย 9฿
                // (ห้ามใช้ราคาเป็นตัวแยก — แอดมินปรับราคา/ปิด billing ได้ตลอด)
                'free' => true,
                'free_batch' => FreeReadingPolicy::batch(),
            ],
        ]);

        $reading->tarotCards()->create([
            'tarot_card_id' => $card->id,
            'position' => 1,
            'position_label' => $positions[0] ?? 'คำตอบของไพ่',
            'reversed' => (bool) random_int(0, 1),
        ]);

        return $reading->load('tarotCards.card');
    }
}
