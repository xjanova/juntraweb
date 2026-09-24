<?php

namespace App\Services\Account;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Reading;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ลบบัญชีตามสิทธิ์ PDPA — ใช้ร่วมกันทั้งหน้าเว็บ (ProfileController) และแอพ (POST /api/v1/account/delete)
 *
 * Google Play (User Data policy) และ PDPA ต้องการ "ลบจริง" ไม่ใช่แค่ปิดบัญชี: ข้อมูลที่ระบุตัวตนได้
 * และเนื้อหาที่ลูกค้าส่งมา (คำถาม คำทำนาย บทสนทนา รูปถ่าย วันเกิด) ต้องหายไป
 * ที่เก็บต่อได้คือบัญชีการเงินตามกฎหมาย (ยอด วันที่ ประเภทบริการ) ซึ่งไม่ผูกกับข้อมูลที่ระบุตัวตนแล้ว
 *
 * ของเดิม (ก่อน 2026-09-24) ลบแค่ชื่อ/อีเมล/ช่องทางเชื่อมต่อ — เบอร์โทร วันเกิด คำถามที่พิมพ์ บทสนทนา
 * กับแม่หมอ และรูปลายมือยังอยู่ครบ และเบอร์ที่ค้างอยู่ (unique) ทำให้สมัครใหม่ด้วยเบอร์เดิมไม่ได้
 *
 * เก็บไว้ (แจ้งในนโยบายความเป็นส่วนตัว):
 *   - แถวธุรกรรมวอลเลต (ยอด/วันที่/ประเภท) — หลักฐานทางบัญชี แต่ลบรูปสลิปและชื่อผู้โอนทิ้ง
 *   - แถวคำทำนายแบบไม่มีเนื้อหา (ประเภท/ราคา/วันที่) — บิลที่ผังแม่หมอใช้คิดค่าแนะนำ
 *   - การซื้อผ่าน Google Play (เลขออเดอร์/token) — ต้องใช้ตามการขอคืนเงินกับ Google
 *   - รหัสสมาชิกแม่หมอ (maemor_member_code) — ผูกกับค่าแนะนำในระบบ Thaiprompt ที่จ่ายไปแล้ว
 */
class AccountDeletion
{
    public function delete(User $user): void
    {
        $files = ['public' => [], 'local' => []];

        DB::transaction(function () use ($user, &$files) {
            // โทเคนแอพทุกตัว — bearer ที่ค้างในเครื่องไหนก็ใช้ต่อไม่ได้
            $user->tokens()->delete();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            // โปรไฟล์ดวง (วันเกิด เวลาเกิด สถานที่เกิด เบอร์ LINE รูปโปรไฟล์)
            if ($profile = $user->profile()->first()) {
                if ($profile->avatar_path) {
                    $files['public'][] = $profile->avatar_path;
                }
                $profile->delete();
            }

            // คำทำนาย: เหลือแค่ประเภท/ราคา/วันที่ — คำถาม คำทำนาย วันเกิด และรูปลายมือหายหมด
            Reading::where('user_id', $user->id)->orderBy('id')->chunkById(200, function ($rows) use (&$files) {
                foreach ($rows as $reading) {
                    $payload = (array) ($reading->payload ?? []);
                    if (! empty($payload['image_path'])) {
                        $files['public'][] = (string) $payload['image_path'];
                    }
                    $reading->tarotCards()->delete();
                    $reading->forceFill([
                        'question'      => null,
                        'result'        => null,
                        'shared_public' => false,
                        'payload'       => array_filter([
                            'cost'         => $payload['cost'] ?? null,
                            'wallet_tx_id' => $payload['wallet_tx_id'] ?? null,
                            'source'       => $payload['source'] ?? null,
                            'refunded_at'  => $payload['refunded_at'] ?? null,
                            'account_deleted' => true,
                        ], fn ($v) => $v !== null),
                    ])->save();
                }
            });

            // บทสนทนากับแม่หมอทั้งหมด — ลบข้อความเองก่อน ไม่พึ่ง FK cascade (sqlite บางตั้งค่าปิด FK ไว้)
            $conversationIds = ChatConversation::where('user_id', $user->id)->pluck('id');
            if ($conversationIds->isNotEmpty()) {
                ChatMessage::whereIn('chat_conversation_id', $conversationIds)->delete();
                ChatConversation::whereIn('id', $conversationIds)->delete();
            }

            // ธุรกรรม: ยกเลิกรายการเติมเงินที่ค้าง ลบรูปสลิปและชื่อผู้โอน เก็บยอด/วันที่ไว้เป็นหลักฐานบัญชี
            WalletTransaction::where('user_id', $user->id)->orderBy('id')->chunkById(200, function ($rows) use (&$files) {
                foreach ($rows as $tx) {
                    if ($tx->slip_path) {
                        $files['local'][] = $tx->slip_path;
                    }
                    $meta = (array) ($tx->meta ?? []);
                    foreach (['sender_name', 'sender_account', 'receiver_name', 'slip', 'slip_data', 'sender'] as $k) {
                        unset($meta[$k]);
                    }
                    $tx->forceFill([
                        'slip_path' => null,
                        'status'    => $tx->status === 'pending' ? 'cancelled' : $tx->status,
                        'meta'      => $meta ?: null,
                    ])->save();
                }
            });

            // ข้อมูลบัญชี — ไม่มีอะไรที่ระบุตัวตนได้เหลืออยู่ (อีเมลแทนที่ด้วยค่าที่ไม่ซ้ำ ไม่ชนดัชนี unique)
            $user->forceFill([
                'name'                        => 'ผู้ใช้ที่ลบบัญชี',
                'email'                       => 'deleted+' . $user->id . '@deleted.local',
                'phone'                       => null,
                'email_verified_at'           => null,
                'password'                    => Hash::make(Str::random(64)),
                'remember_token'              => null,
                'thaiprompt_user_id'          => null,
                'thaiprompt_token'            => null,
                'thaiprompt_refresh_token'    => null,
                'thaiprompt_token_expires_at' => null,
                'thaiprompt_synced_at'        => null,
                'facebook_user_id'            => null,
                'line_user_id'                => null,
                'pending_referral_code'       => null,
            ])->save();

            $user->delete(); // soft delete — แถวธุรกรรมที่ผูกด้วย FK cascade จึงยังอยู่
        });

        // ลบไฟล์หลัง commit เท่านั้น (transaction ล้ม = ไฟล์ต้องยังอยู่)
        foreach ($files as $disk => $paths) {
            foreach (array_unique($paths) as $path) {
                try {
                    if ($path !== '' && Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Account deletion: file cleanup failed', ['disk' => $disk, 'path' => $path, 'err' => $e->getMessage()]);
                }
            }
        }
    }
}
