<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Concerns\PreventsDuplicateCharges;
use App\Models\AuspiciousDate;
use App\Models\Reading;
use App\Services\AuspiciousAdvisor;
use App\Services\AuspiciousScorer;
use App\Services\Wallet\WalletService;
use App\Support\AuspiciousOccasions;
use App\Support\Pricing;
use App\Support\ThaiAstro;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuspiciousController extends Controller
{
    use PreventsDuplicateCharges;

    /** ช่วงวันสูงสุดที่ยอมให้ขอต่อครั้ง — ตรงกับเพดานสแกนของ scorer */
    private const MAX_RANGE_DAYS = AuspiciousScorer::MAX_SCAN_DAYS;

    public function __construct(private WalletService $wallet) {}

    public function index(AuspiciousScorer $scorer)
    {
        $today = Carbon::today(ThaiAstro::TZ);
        $now   = Carbon::now(ThaiAstro::TZ);

        $upcoming = AuspiciousDate::where('active', true)
            ->whereBetween('date', [$today, $today->copy()->addDays(60)])
            ->orderBy('date')
            ->get();

        // ยามของ "ตอนนี้" — วันของโหรเริ่ม 06:00 ก่อนหน้านั้นยังเป็นยามกลางคืนของเมื่อวาน
        $nowYam = ThaiAstro::yamAt($now);

        return view('pages.auspicious.index', [
            'upcoming'  => $upcoming,
            'cost'      => Pricing::for('auspicious'),
            'occasions' => AuspiciousOccasions::LIST,
            // แถบ 14 วันข้างหน้าให้เห็นก่อนจ่ายว่าระบบคำนวณอะไรให้จริง ๆ
            // (หน้าเดิมมีแค่ฟอร์มลอย ๆ ลูกค้าไม่รู้ว่าจ่ายแล้วจะได้อะไร)
            'preview'   => $scorer->scoreRange($today, $today->copy()->addDays(13)),
            'ruekList'  => ThaiAstro::RUEK,
            // ตารางลงเลขยามของวันนี้ โชว์ฟรีก่อนจ่าย — พิสูจน์ว่าเลขมาจากการคำนวณจริง
            'todayYam'  => ThaiAstro::yamAtthakan($nowYam['date']),
            'nowYam'    => $nowYam,
        ]);
    }

    public function find(Request $request, AuspiciousAdvisor $advisor, AuspiciousScorer $scorer)
    {
        $data = $request->validate([
            'occasion'      => 'required|string|max:128',
            'occasion_type' => 'nullable|string|max:32',
            'from_date'     => 'nullable|date',
            'to_date'       => 'nullable|date|after_or_equal:from_date',
        ]);

        if (! $request->user()) {
            // เก็บสิ่งที่กรอกไว้ให้ด้วย ไม่งั้นล็อกอินเสร็จต้องพิมพ์ใหม่ทั้งหมด
            return redirect()->route('login')
                ->withInput()
                ->with('status', 'กรุณาเข้าสู่ระบบเพื่อใช้บริการหาฤกษ์ยาม');
        }

        // ผู้ใช้เลือกหมวดเองได้ ถ้าไม่เลือกก็เดาจากข้อความที่พิมพ์
        $occasionKey = AuspiciousOccasions::has($data['occasion_type'] ?? null)
            ? $data['occasion_type']
            : AuspiciousOccasions::detect($data['occasion']);

        [$from, $to] = $this->range($data);

        // คำนวณก่อนหักเงินเสมอ — ถ้าไม่มีวันผ่านเกณฑ์ ต้องไม่คิดเงิน
        $candidates = $scorer->candidateDays($from, $to, $occasionKey);
        if (empty($candidates)) {
            return redirect()->route('auspicious.index')
                ->withInput()
                ->with('status', 'ช่วงวันที่เลือกไม่มีวันไหนผ่านเกณฑ์ฤกษ์สำหรับงานนี้เลย กรุณาขยายช่วงวันให้กว้างขึ้น — ยังไม่มีการหักเครดิต');
        }

        if ($this->guardCharge($request, 'reading') === false) {
            return redirect()->route('auspicious.index')->with('status', 'รายการก่อนหน้ากำลังประมวลผล กรุณารอสักครู่');
        }

        $cost = Pricing::for('auspicious');
        $tx = null;
        if ($cost > 0) {
            try {
                $tx = $this->wallet->debit($request->user(), $cost, 'หาฤกษ์ยาม: '.$data['occasion'], [
                    'reference_type' => 'reading',
                ]);
            } catch (InsufficientFundsException $e) {
                return redirect()->route('wallet.index')->with('status', $e->getMessage().' — กรุณาเติมเงิน');
            }
        }

        try {
            $advice = $advisor->advise($request->user(), $occasionKey, $data['occasion'], $candidates);

            $reading = Reading::create([
                'user_id'       => $request->user()->id,
                'session_token' => Str::uuid()->toString(),
                'type'          => 'auspicious',
                'question'      => $data['occasion'],
                'payload'       => self::payload($occasionKey, $data['occasion'], $from, $to, $candidates, $cost),
                'result'        => $advice['text'],
                'ai_provider'   => $advice['ai_provider'],
                'ai_model'      => $advice['ai_model'],
            ]);

            if ($tx) {
                $tx->update(['reference_id' => $reading->id]);
            }
        } catch (\Throwable $e) {
            Log::error('Auspicious reading failed after debit — refunding', [
                'user_id' => $request->user()->id, 'tx_id' => $tx?->id, 'err' => $e->getMessage(),
            ]);
            if ($tx) {
                try {
                    $this->wallet->refund($tx, 'ระบบขัดข้องระหว่างหาฤกษ์ยาม');
                } catch (\Throwable $refundErr) {
                    Log::critical('Refund FAILED — manual intervention needed', ['tx_id' => $tx->id, 'err' => $refundErr->getMessage()]);
                }
            }

            return redirect()->route('auspicious.index')
                ->with('status', 'ระบบขัดข้องชั่วคราว — เครดิตถูกคืนแล้ว กรุณาลองใหม่');
        }

        // ดูผลผ่านเส้นทางเดียวกับประวัติ เพื่อให้หน้าที่เห็นตอนซื้อกับตอนย้อนดูเป็นหน้าเดียวกัน
        return redirect()->route('reading.show', $reading);
    }

    /* ============================================================ */

    /** @return array{0:Carbon,1:Carbon} */
    private function range(array $data): array
    {
        $today = Carbon::today(ThaiAstro::TZ);

        $from = isset($data['from_date']) ? Carbon::parse($data['from_date'], ThaiAstro::TZ)->startOfDay() : $today->copy();
        // หาฤกษ์ย้อนหลังไม่มีประโยชน์ และทำให้ผู้ใช้เผลอเสียเงินกับวันที่ผ่านไปแล้ว
        if ($from->lt($today)) {
            $from = $today->copy();
        }

        $to = isset($data['to_date'])
            ? Carbon::parse($data['to_date'], ThaiAstro::TZ)->startOfDay()
            : $from->copy()->addDays(59);

        if ($to->lt($from)) {
            $to = $from->copy()->addDays(59);
        }
        $max = $from->copy()->addDays(self::MAX_RANGE_DAYS - 1);
        if ($to->gt($max)) {
            $to = $max;
        }

        return [$from, $to];
    }

    /**
     * payload ที่เก็บลง readings — ต้องครบพอที่จะ render หน้าผลย้อนหลังได้ทั้งหน้า
     * โดยไม่ต้องคำนวณดวงจันทร์ใหม่ (ผลต้องเหมือนเดิมเป๊ะทุกครั้งที่เปิดดู)
     *
     * @param  array<int, array<string,mixed>>  $candidates
     */
    public static function payload(string $occasionKey, string $occasionText, Carbon $from, Carbon $to, array $candidates, float $cost): array
    {
        return [
            'occasion'       => $occasionText,
            'occasion_type'  => $occasionKey,
            'occasion_label' => AuspiciousOccasions::get($occasionKey)['label'],
            'occasion_icon'  => AuspiciousOccasions::get($occasionKey)['icon'],
            'occasion_note'  => AuspiciousOccasions::get($occasionKey)['note'],
            'from_date'      => $from->toDateString(),
            'to_date'        => $to->toDateString(),
            'cost'           => $cost,
            // v2 = มียามอัฐกาลแล้ว · ผลเก่า (v1) ไม่มีคีย์ yam — หน้าแสดงผลต้องทนได้
            'engine'         => 'thai-astro-v2',
            'candidates'     => array_map(fn ($c) => [
                'date'       => $c['date']->toDateString(),
                'label'      => $c['label'],
                'score'      => $c['score'],
                'score_pct'  => $c['score_pct'],
                'grade'      => $c['grade'],
                'weekday'    => $c['weekday'],
                'nakshatra'  => $c['nakshatra'],
                'ruek'       => $c['ruek'],
                'tithi'      => $c['tithi'],
                'yam'        => $c['yam'],
                'ruek_from'  => $c['ruek_from']->format('H:i'),
                'ruek_to'    => $c['ruek_to']->format('H:i'),
                'best_from'  => $c['best_from']?->format('H:i'),
                'best_to'    => $c['best_to']?->format('H:i'),
                'reasons'    => $c['reasons'],
                'warnings'   => $c['warnings'],
            ], $candidates),
        ];
    }
}
