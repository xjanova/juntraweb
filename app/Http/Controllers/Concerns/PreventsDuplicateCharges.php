<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Idempotency for paid actions. A double-tap, a browser "resubmit", or a
 * flaky-network retry can fire the same paid POST twice within the throttle
 * window and debit twice. We block that with a short cache lock keyed by a
 * per-submit idempotency token:
 *
 *   - Web forms embed a hidden `_idem` UUID (one per rendered form), so
 *     resubmitting the SAME form reuses the token and is blocked, while a
 *     freshly loaded form gets a new token and is allowed.
 *   - The mobile app sends an `Idempotency-Key` header, generated per action.
 *
 * When no token is present we fall through (no idempotency) so older clients
 * keep working exactly as before.
 */
trait PreventsDuplicateCharges
{
    /**
     * @return Lock|null|false  null = no token (proceed), Lock = acquired,
     *                          false = an identical action is already in flight.
     */
    protected function guardCharge(Request $request, string $scope, int $ttl = 90): Lock|null|false
    {
        $token = $this->idempotencyToken($request);
        if ($token === null) {
            return null;
        }
        $lock = Cache::lock("charge:{$scope}:" . sha1($token), $ttl);
        return $lock->get() ? $lock : false;
    }

    /** ล็อกของคำขอปัจจุบัน — เก็บไว้เพื่อปล่อยคืนเมื่อรายการ "ไม่สำเร็จ" */
    private Lock|null $heldChargeLock = null;

    /**
     * เหมือน {@see guardCharge()} แต่จำ Lock ไว้ให้ {@see releaseChargeLock()}
     *
     * @return bool  false = มีรายการเดียวกันกำลังทำอยู่ (ผู้เรียกควรตอบ 409)
     */
    protected function guardChargeAuto(Request $request, string $scope, int $ttl = 90): bool
    {
        $lock = $this->guardCharge($request, $scope, $ttl);
        if ($lock === false) {
            return false;
        }

        $this->heldChargeLock = $lock;

        return true;
    }

    /**
     * ปล่อยล็อกเมื่อรายการ **ไม่สำเร็จ** (ยังไม่ตัดเงิน หรือคืนเงินไปแล้ว)
     *
     * ห้ามเรียกตอนสำเร็จ — ล็อกต้องอยู่ครบ TTL เพื่อกันคำขอเดิมที่ dio retry
     * ส่งซ้ำมาแล้วถูกคิดเงินรอบสอง (นั่นคือหน้าที่หลักของมัน)
     *
     * ที่ต้องมี: ของเดิมทิ้ง Lock ไปเฉย ๆ ล็อกจึงค้าง 90 วินาทีเสมอ ผู้ใช้ที่เจอ
     * error แล้วกดลองใหม่ทันทีได้ "รายการก่อนหน้ากำลังประมวลผล" ซ้ำ ๆ ทั้งที่
     * ไม่มีอะไรค้างจริงและเงินถูกคืนไปแล้ว — อ่านแล้วเหมือนเงินยังติดอยู่ในระบบ
     */
    protected function releaseChargeLock(): void
    {
        try {
            $this->heldChargeLock?->release();
        } catch (\Throwable) {
            // ปล่อยไม่ได้ก็ไม่เป็นไร — TTL จะเก็บกวาดให้เอง
        }
        $this->heldChargeLock = null;
    }

    protected function idempotencyToken(Request $request): ?string
    {
        $token = $request->header('Idempotency-Key') ?: $request->input('_idem');
        $token = is_string($token) ? trim($token) : '';
        return $token !== '' ? substr($token, 0, 64) : null;
    }
}
