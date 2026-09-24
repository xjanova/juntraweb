<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ContentReport;
use App\Models\Reading;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /v1/reports — ลูกค้ารายงานคำทำนาย/ข้อความแชทที่ AI สร้าง โดยไม่ต้องออกจากแอพ
 *
 * Google Play (AI-Generated Content policy) บังคับให้แอพ AI ที่ผู้ใช้คุยด้วยมีทางรายงานเนื้อหา
 * ในแอพ ส่งถึงแอดมินทาง Telegram และเข้าคิว "รายงานเนื้อหา AI" ในหลังบ้าน
 * รายงานได้เฉพาะของของตัวเอง (คำทำนาย/ห้องแชทของตัวเอง) — กันใช้เป็นช่องสแปมแอดมิน
 */
class ContentReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(ContentReport::SUBJECTS))],
            'subject_id'   => 'required|integer|min:1',
            'reason'       => ['required', Rule::in(array_keys(ContentReport::REASONS))],
            'note'         => 'nullable|string|max:1000',
        ]);
        $user = $request->user();

        $snapshot = $this->snapshotFor($data['subject_type'], (int) $data['subject_id'], $user->id);
        if ($snapshot === null) {
            return response()->json([
                'message'     => 'ไม่พบเนื้อหาที่ต้องการรายงาน',
                'reason_code' => 'subject_not_found',
            ], 404);
        }

        // กดรายงานซ้ำเรื่องเดิมที่ยังเปิดอยู่ = รายการเดิม (ไม่ส่งแจ้งเตือนซ้ำ)
        $existing = ContentReport::where('user_id', $user->id)
            ->where('subject_type', $data['subject_type'])
            ->where('subject_id', $data['subject_id'])
            ->where('status', 'open')
            ->first();
        if ($existing) {
            return response()->json(['data' => $this->payload($existing)]);
        }

        $report = ContentReport::create([
            'user_id'      => $user->id,
            'subject_type' => $data['subject_type'],
            'subject_id'   => $data['subject_id'],
            'reason'       => $data['reason'],
            'note'         => $data['note'] ?? null,
            'snapshot'     => mb_substr($snapshot, 0, 5000),
            'status'       => 'open',
        ]);

        try {
            AdminAlerts::send(new Alert(
                key: 'content-report:' . $report->id,
                level: Alert::WARNING,
                title: 'ลูกค้ารายงานเนื้อหาที่แม่หมอตอบ',
                body: (ContentReport::REASONS[$report->reason] ?? $report->reason)
                    . ($report->note ? "\nลูกค้าเขียนว่า: " . mb_substr($report->note, 0, 300) : '')
                    . "\n\n" . mb_substr($snapshot, 0, 400),
                facts: [
                    'ประเภท' => ContentReport::SUBJECTS[$report->subject_type] ?? $report->subject_type,
                    'รายการ' => '#' . $report->subject_id,
                    'ลูกค้า' => '#' . $user->id,
                ],
                url: url('/admin/content-reports'),
                urlLabel: 'เปิดรายงาน',
                category: 'readings',
            ), 5);
        } catch (\Throwable) {
            // แจ้งเตือนพลาดไม่เป็นไร — รายงานถูกบันทึกแล้ว แอดมินเห็นในหลังบ้าน
        }

        return response()->json(['data' => $this->payload($report)], 201);
    }

    /** เนื้อหาที่ AI สร้างของรายการนั้น — null ถ้าไม่ใช่ของผู้ใช้คนนี้ หรือไม่ใช่ข้อความของ AI */
    private function snapshotFor(string $type, int $id, int $userId): ?string
    {
        if ($type === 'reading') {
            $reading = Reading::where('user_id', $userId)->find($id);

            return $reading ? (string) ($reading->result ?? '') : null;
        }

        $message = ChatMessage::with('conversation')->find($id);
        if (! $message || $message->role === 'user' || $message->conversation?->user_id !== $userId) {
            return null;
        }

        return (string) $message->content;
    }

    private function payload(ContentReport $report): array
    {
        return [
            'id'      => $report->id,
            'status'  => $report->status,
            'message' => 'ขอบคุณที่แจ้งค่ะ ทีมงานจะตรวจสอบเนื้อหานี้โดยเร็ว',
        ];
    }
}
