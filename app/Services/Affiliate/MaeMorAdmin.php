<?php

namespace App\Services\Affiliate;

use App\Models\User;
use App\Services\Thaiprompt\JuntraServerClient;

/**
 * 🌙 หลังบ้านจันทราสั่งงานผังแม่หมอผ่าน API — ข้อมูลเก็บและคำนวณที่ Thaiprompt ทั้งหมด
 *
 * เจ้าของสั่ง (2026-09-21): เว็บใครเว็บมัน — หลังบ้านจันทราจัดการเฉพาะค่าแนะนำจากบิลจันทรา
 *   (อนุมัติ/ปฏิเสธ/ปรับยอด/จ่ายเงิน/สร้างมือ/ดูผัง/ย้ายสายของลูกค้าจันทรา)
 *   ค่าแนะนำจากบิลบอทจัดการที่หลังบ้านแม่หมอ · อัตรา % ตั้งที่ Thaiprompt ที่เดียว (ที่นี่ดูได้อย่างเดียว)
 *   กติกาเรื่องเงินอยู่ฝั่ง Thaiprompt ที่เดียว — ที่นี่ห้ามคำนวณหรือตัดสินอะไรเอง
 *
 * ทุกคำสั่งที่เปลี่ยนข้อมูลแนบ actor (แอดมินคนไหนของจันทรากด) ไปให้ Thaiprompt บันทึก
 *
 * คืนค่าแบบเดียวทุกเมธอด: ['ok' => bool, 'data' => ?array, 'message' => ?string]
 */
class MaeMorAdmin
{
    public function __construct(private JuntraServerClient $server) {}

    public function overview(array $filters = []): array
    {
        return $this->call('GET', '/admin/overview', array_filter($filters));
    }

    public function commissions(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return $this->call('GET', '/admin/commissions', array_filter($filters + [
            'page' => $page,
            'per_page' => $perPage,
        ], fn ($v) => $v !== null && $v !== '' && $v !== 'all'));
    }

    public function approve(User $actor, array $ids): array
    {
        return $this->call('POST', '/admin/commissions/approve', ['ids' => array_values($ids), 'actor' => $this->actor($actor)]);
    }

    public function pay(User $actor, array $ids): array
    {
        return $this->call('POST', '/admin/commissions/pay', ['ids' => array_values($ids), 'actor' => $this->actor($actor)]);
    }

    public function reject(User $actor, int $id, ?string $reason): array
    {
        return $this->call('POST', "/admin/commissions/{$id}/reject", ['reason' => $reason, 'actor' => $this->actor($actor)]);
    }

    public function adjust(User $actor, int $id, float $amount, ?string $reason): array
    {
        return $this->call('POST', "/admin/commissions/{$id}/adjust", [
            'amount' => number_format($amount, 2, '.', ''),
            'reason' => $reason,
            'actor' => $this->actor($actor),
        ]);
    }

    /**
     * บิลจันทราเท่านั้น — bill_id = เลขรายการตัดเครดิต (JW-…) ลูกค้าเจ้าของบิล Thaiprompt หาให้เอง
     *
     * @param  array{bill_id: int, user_id: int, level: int, amount: float, notes?: ?string}  $data
     */
    public function createManual(User $actor, array $data): array
    {
        return $this->call('POST', '/admin/commissions/manual', $data + ['actor' => $this->actor($actor)]);
    }

    /** ดูได้อย่างเดียว — อัตราตั้งที่หน้าคอมแม่หมอของ Thaiprompt */
    public function settings(): array
    {
        return $this->call('GET', '/admin/settings');
    }

    public function users(string $q = '', int $page = 1): array
    {
        return $this->call('GET', '/admin/users', array_filter(['q' => $q, 'page' => $page, 'per_page' => 50]));
    }

    public function userStats(int $thaipromptUserId): array
    {
        return $this->call('GET', "/admin/users/{$thaipromptUserId}/stats");
    }

    public function userTree(int $thaipromptUserId, int $depth = 5): array
    {
        return $this->call('GET', "/admin/users/{$thaipromptUserId}/tree", ['depth' => $depth]);
    }

    public function searchMembers(string $q, ?int $excludeMemberId = null): array
    {
        return $this->call('GET', '/admin/members/search', array_filter(['q' => $q, 'exclude' => $excludeMemberId]));
    }

    public function moveMember(User $actor, int $memberId, int $newSponsorMemberId, ?string $notes): array
    {
        return $this->call('POST', "/admin/members/{$memberId}/move", [
            'new_sponsor_member_id' => $newSponsorMemberId,
            'notes' => $notes,
            'actor' => $this->actor($actor),
        ]);
    }

    /* ============================================================ */

    private function actor(User $actor): array
    {
        return array_filter([
            'juntra_user_id' => $actor->id,
            'name' => mb_substr((string) ($actor->name ?: $actor->email), 0, 180),
            'thaiprompt_user_id' => $actor->thaiprompt_user_id ?: null,
        ], fn ($v) => $v !== null);
    }

    private function call(string $method, string $path, array $payload = []): array
    {
        $res = $this->server->affiliate($method, $path, $payload, timeout: 20);

        if ($res['status'] === 'ok') {
            return ['ok' => true, 'data' => $res['data'], 'message' => null];
        }

        $message = match ($res['status']) {
            'rejected' => $res['message'] ?: $this->firstValidationError($res['data']) ?: 'ผังแม่หมอไม่รับคำสั่งนี้',
            'unsupported' => 'ยังเชื่อมผังแม่หมอไม่ได้ — ตรวจการตั้งค่า Thaiprompt (client) หรือรอ Thaiprompt อัปเดต',
            default => 'ผังแม่หมอไม่ตอบ ลองใหม่อีกครั้ง',
        };

        return ['ok' => false, 'data' => $res['data'], 'message' => $message];
    }

    private function firstValidationError(?array $json): ?string
    {
        $errors = $json['errors'] ?? null;

        return is_array($errors) ? (string) (collect($errors)->flatten()->first() ?? '') : null;
    }
}
