<?php

namespace App\Support\Telegram;

use App\Models\Setting;
use App\Support\Alerts\Alert;
use App\Support\Alerts\AlertCard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The admin Telegram bot: sends alert cards and edits them once someone has acted on them.
 *
 * Every alert goes out as a photo — the drawn [AlertCard], an HTML caption with the same facts in
 * words, and a link button to the admin page. Low-priority alerts (INFO) arrive silently. If the
 * card can't be drawn, or Telegram refuses the photo, it goes again as text: the alert matters more
 * than the picture. Telegram instead of LINE because LINE OA messages are metered; Telegram is free.
 *
 * THE TOKEN IS IN EVERY URL ("/bot<token>/sendPhoto"). Guzzle's connection errors quote the full
 * URL, and error strings from here reach laravel.log and the admin page's history — so everything
 * that leaves this class goes through [self::redact] first.
 *
 * Settings: `telegram_alerts_enabled` ('1'/'0'), `telegram_bot_token` (encrypted), `telegram_chat_id`
 * (a user id, a -100… group id, or @channel), `telegram_bot_username`, `telegram_topics` (JSON
 * category => forum thread id), `telegram_protect_content` ('1'/'0').
 *
 * Ported from xmanstudio (itself from NetWix) without the webhook/command half — this site only
 * talks to the owner, it doesn't take orders from the chat.
 */
final class TelegramBot
{
    private const API = 'https://api.telegram.org';

    /** Telegram's limits are 1024 (caption) and 4096 (message) — stay clear, emoji count double. */
    public const CAPTION_MAX = 1000;

    public const TEXT_MAX = 3800;

    public static function enabled(): bool
    {
        return Setting::get('telegram_alerts_enabled', '0') === '1' && self::configured();
    }

    public static function configured(): bool
    {
        return self::token() !== '' && self::chat() !== '';
    }

    public static function token(): string
    {
        return trim((string) Setting::get('telegram_bot_token', ''));
    }

    /** The chat alerts go to. */
    public static function chat(): string
    {
        return trim((string) Setting::get('telegram_chat_id', ''));
    }

    public static function username(): string
    {
        return trim((string) Setting::get('telegram_bot_username', ''));
    }

    /** Forum thread for a category in a topics-enabled group, or null for the main chat. */
    public static function topic(string $category): ?int
    {
        $topics = json_decode((string) Setting::get('telegram_topics', ''), true);
        $id = is_array($topics) ? ($topics[$category] ?? null) : null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    // ---------------------------------------------------------------------- alerts

    /**
     * Send one alert as a card. Never throws: an alerting failure must not take down whatever was
     * reporting it.
     *
     * @return array{error:?string,message_id:?int,chat:string}
     */
    public static function sendAlert(Alert $alert, ?string $chat = null, ?int $thread = null, ?int $replyTo = null): array
    {
        $chat ??= self::chat();
        try {
            $sent = self::sendAlertOnce($alert, $chat, $thread, $replyTo, retryMigrated: true);
            if ($sent['error'] === null && $alert->photo !== null) {
                self::sendPhotoFile($sent['chat'], $alert->photo, $thread, $sent['message_id'], self::protects($alert));
            }

            return $sent;
        } catch (Throwable $e) {
            $reason = self::redact($e->getMessage());
            Log::warning('telegram: send threw', ['error' => $reason]);

            return ['error' => mb_substr($reason, 0, 200), 'message_id' => null, 'chat' => $chat];
        }
    }

    private static function sendAlertOnce(Alert $alert, string $chat, ?int $thread, ?int $replyTo, bool $retryMigrated): array
    {
        $keyboard = self::keyboard($alert);
        $png = AlertCard::png($alert);
        $common = [
            'chat_id' => $chat,
            'message_thread_id' => $thread,
            'parse_mode' => 'HTML',
            'disable_notification' => $alert->level === Alert::INFO ? 'true' : 'false',
            'protect_content' => self::protects($alert) ? 'true' : null,
            'reply_parameters' => $replyTo ? json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true]) : null,
            'reply_markup' => $keyboard !== null ? json_encode($keyboard) : null,
        ];

        if ($png !== null) {
            $res = self::call('sendPhoto', $common + [
                'caption' => self::caption($alert, $keyboard === null, self::CAPTION_MAX),
            ], $png);

            if ($res['ok']) {
                return ['error' => null, 'message_id' => (int) ($res['result']['message_id'] ?? 0) ?: null, 'chat' => $chat];
            }
            if ($retryMigrated && ($to = self::followMigration($res, $chat)) !== null) {
                return self::sendAlertOnce($alert, $to, $thread, $replyTo, retryMigrated: false);
            }
            if (self::isFatal($res)) {
                return ['error' => self::explain($res), 'message_id' => null, 'chat' => $chat];
            }
            // Something about the photo itself was refused — say it in words instead.
            Log::info('telegram: photo refused, falling back to text', ['desc' => $res['desc']]);
        }

        $text = $common + [
            'text' => self::caption($alert, true, self::TEXT_MAX),
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ];
        $res = self::call('sendMessage', $text);

        if (! $res['ok'] && $retryMigrated && ($to = self::followMigration($res, $chat)) !== null) {
            return self::sendAlertOnce($alert, $to, $thread, $replyTo, retryMigrated: false);
        }
        // Last resort: if Telegram could not parse our HTML, the words still matter — send them bare.
        if (! $res['ok'] && str_contains(strtolower($res['desc']), 'entities')) {
            $res = self::call('sendMessage', ['text' => mb_substr($alert->toText(), 0, self::TEXT_MAX), 'parse_mode' => null] + $text);
        }

        return $res['ok']
            ? ['error' => null, 'message_id' => (int) ($res['result']['message_id'] ?? 0) ?: null, 'chat' => $chat]
            : ['error' => self::explain($res), 'message_id' => null, 'chat' => $chat];
    }

    /**
     * Replace a card we sent earlier with a fresh one — the slip was approved on the website, a
     * teammate already rejected it — so the chat shows the current state and nobody acts on it twice.
     * Falls back to editing only the caption when the message was sent as text.
     */
    public static function editAlert(string $chat, int $messageId, Alert $alert): ?string
    {
        try {
            $keyboard = self::keyboard($alert);
            $markup = json_encode($keyboard ?? ['inline_keyboard' => []]);
            $png = AlertCard::png($alert);
            if ($png !== null) {
                $res = self::call('editMessageMedia', [
                    'chat_id' => $chat,
                    'message_id' => $messageId,
                    'media' => json_encode([
                        'type' => 'photo',
                        'media' => 'attach://card',
                        'caption' => self::caption($alert, $keyboard === null, self::CAPTION_MAX),
                        'parse_mode' => 'HTML',
                    ]),
                    'reply_markup' => $markup,
                ], $png, attachAs: 'card');
                if ($res['ok'] || str_contains($res['desc'], 'not modified')) {
                    return null;
                }
            }
            foreach (['editMessageCaption' => 'caption', 'editMessageText' => 'text'] as $method => $field) {
                $res = self::call($method, [
                    'chat_id' => $chat,
                    'message_id' => $messageId,
                    $field => self::caption($alert, $keyboard === null, $field === 'caption' ? self::CAPTION_MAX : self::TEXT_MAX),
                    'parse_mode' => 'HTML',
                    'reply_markup' => $markup,
                ]);
                if ($res['ok'] || str_contains($res['desc'], 'not modified')) {
                    return null;
                }
            }

            return self::explain($res);
        } catch (Throwable $e) {
            return mb_substr(self::redact($e->getMessage()), 0, 200);
        }
    }

    /** An image from disk (a payment slip) as a reply to the card about it. Best-effort. */
    private static function sendPhotoFile(string $chat, string $path, ?int $thread, ?int $replyTo, bool $protect): void
    {
        try {
            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                return;
            }
            $res = self::call('sendPhoto', [
                'chat_id' => $chat,
                'message_thread_id' => $thread,
                'caption' => 'สลิปที่ลูกค้าแนบมา',
                'disable_notification' => 'true',
                'protect_content' => $protect ? 'true' : null,
                'reply_parameters' => $replyTo ? json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true]) : null,
            ], $bytes, attachAs: 'photo', filename: 'slip-' . substr(sha1($path), 0, 8) . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg'));
            if (! $res['ok']) {
                Log::info('telegram: slip photo refused', ['desc' => $res['desc']]);
            }
        } catch (Throwable $e) {
            Log::info('telegram: slip photo failed', ['error' => self::redact($e->getMessage())]);
        }
    }

    // ---------------------------------------------------------------------- setup helpers

    /**
     * Ask Telegram who a token belongs to (getMe) — used when the admin saves a token, so a typo is
     * caught on the spot instead of at the first real alert.
     *
     * @return array{ok:bool,reachable:bool,username:?string,name:?string,error:?string}
     */
    public static function identify(string $token): array
    {
        try {
            $res = self::call('getMe', [], null, $token);
        } catch (Throwable $e) {
            return ['ok' => false, 'reachable' => false, 'username' => null, 'name' => null,
                'error' => 'ต่อ Telegram ไม่ได้ในตอนนี้ (' . mb_substr(self::redact($e->getMessage(), $token), 0, 120) . ')'];
        }

        return $res['ok']
            ? ['ok' => true, 'reachable' => true, 'username' => $res['result']['username'] ?? null, 'name' => $res['result']['first_name'] ?? null, 'error' => null]
            : ['ok' => false, 'reachable' => true, 'username' => null, 'name' => null, 'error' => self::explain($res)];
    }

    /**
     * The chats that have talked to the bot recently — so the owner never has to dig a numeric chat
     * id out of Telegram by hand: press Start with the bot, then pick it from a list.
     *
     * @return array{chats:array<int,array{id:string,type:string,title:string,is_forum:bool,user_id:?string}>,error:?string}
     */
    public static function discoverChats(): array
    {
        if (self::token() === '') {
            return ['chats' => [], 'error' => 'ยังไม่ได้ใส่ Bot Token'];
        }

        try {
            $res = self::call('getUpdates', ['limit' => 100, 'timeout' => 0]);
        } catch (Throwable $e) {
            return ['chats' => [], 'error' => 'ต่อ Telegram ไม่ได้ในตอนนี้ (' . mb_substr(self::redact($e->getMessage()), 0, 120) . ')'];
        }
        if (! $res['ok']) {
            return ['chats' => [], 'error' => $res['status'] === 409
                ? 'บอทนี้ตั้ง webhook ไว้กับระบบอื่นอยู่ — ใส่ Chat ID เองในช่องด้านล่าง'
                : self::explain($res)];
        }

        $chats = [];
        foreach ((array) $res['result'] as $update) {
            foreach (['message', 'edited_message', 'channel_post', 'my_chat_member', 'chat_member'] as $kind) {
                $chat = $update[$kind]['chat'] ?? null;
                if (is_array($chat) && isset($chat['id'])) {
                    $chats = self::chatEntry($chats, $chat, $update[$kind]['from'] ?? null);
                }
            }
        }

        if ($chats === []) {
            return ['chats' => [], 'error' => 'ยังไม่พบแชทที่คุยกับบอท — เปิดแชทกับบอทแล้วกด Start (หรือเพิ่มบอทเข้ากลุ่ม) แล้วกดค้นหาอีกครั้ง'];
        }

        return ['chats' => array_reverse(array_values($chats)), 'error' => null];
    }

    private static function chatEntry(array $chats, array $chat, ?array $from): array
    {
        $name = trim((string) ($chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))));
        $id = (string) $chat['id'];
        unset($chats[$id]);     // re-insert so the newest activity ends up last
        $chats[$id] = [
            'id' => $id,
            'type' => (string) ($chat['type'] ?? ''),
            'title' => $name !== '' ? $name : '@' . ($chat['username'] ?? $id),
            'is_forum' => (bool) ($chat['is_forum'] ?? false),
            'user_id' => isset($from['id']) ? (string) $from['id'] : null,
        ];

        return $chats;
    }

    // ---------------------------------------------------------------------- transport

    /**
     * One Bot API call. Null params are dropped; everything else is sent as a string field, which
     * is what a multipart request needs and what Telegram accepts either way.
     *
     * @return array{ok:bool,status:int,desc:string,result:mixed,params:array}
     */
    private static function call(string $method, array $params, ?string $photo = null, ?string $token = null, string $attachAs = 'photo', string $filename = 'juntra-alert.png'): array
    {
        $params = array_map('strval', array_filter($params, fn ($v) => $v !== null));
        $request = Http::connectTimeout(5)->timeout($photo !== null ? 25 : 12);
        if ($photo !== null) {
            $request = $request->attach($attachAs, $photo, $filename);
        } else {
            $request = $request->asForm();
        }

        $resp = $request->post(self::API . '/bot' . ($token ?? self::token()) . '/' . $method, $params);
        $json = $resp->json();
        $ok = $resp->successful() && is_array($json) && ($json['ok'] ?? false) === true;

        return [
            'ok' => $ok,
            'status' => $resp->status(),
            'desc' => $ok ? '' : (string) (is_array($json) ? ($json['description'] ?? '') : mb_substr($resp->body(), 0, 200)),
            'result' => $ok ? ($json['result'] ?? []) : [],
            'params' => is_array($json) ? (array) ($json['parameters'] ?? []) : [],
        ];
    }

    /**
     * A group that gets upgraded to a supergroup changes its chat id, and Telegram answers the old
     * one with the new id attached. Follow it once and remember it, instead of going silent.
     */
    private static function followMigration(array $res, string $chat): ?string
    {
        $to = $res['params']['migrate_to_chat_id'] ?? null;
        if (! is_numeric($to)) {
            return null;
        }
        if ($chat === self::chat()) {
            Setting::put('telegram_chat_id', (string) $to, 'telegram');
            Log::info('telegram: chat migrated to a supergroup, chat id updated', ['to' => (string) $to]);
        }

        return (string) $to;
    }

    /** Failures a text retry would only repeat: the token, the chat, or rate limiting. */
    private static function isFatal(array $res): bool
    {
        $d = strtolower($res['desc']);

        return in_array($res['status'], [401, 403, 404, 429], true)
            || str_contains($d, 'chat not found') || str_contains($d, 'upgraded') || str_contains($d, 'thread not found');
    }

    /** A short Thai reason for the admin page, never the raw API text alone. */
    public static function explain(array $res): string
    {
        $d = strtolower($res['desc']);

        return match (true) {
            in_array($res['status'], [401, 404], true) => 'Token ไม่ถูกต้อง หรือบอทถูกลบไปแล้ว — ขอ Token ใหม่จาก @BotFather',
            str_contains($d, 'chat not found') => 'ไม่พบแชทปลายทาง — ต้องกด Start ในแชทกับบอทก่อน (หรือเพิ่มบอทเข้ากลุ่ม) แล้วตรวจ Chat ID อีกครั้ง',
            str_contains($d, 'thread not found') => 'ไม่พบห้องย่อย (Topic) ที่ตั้งไว้ — อาจถูกลบไปแล้ว',
            str_contains($d, 'blocked by the user') => 'บอทถูกบล็อกอยู่ — เปิดแชทกับบอทแล้วกด Restart',
            str_contains($d, 'not a member'), str_contains($d, 'kicked'), str_contains($d, 'not enough rights') => 'บอทไม่ได้อยู่ในกลุ่ม/ช่องนี้ หรือไม่มีสิทธิ์ส่งข้อความ — เพิ่มบอทเข้าไป (ช่องต้องตั้งบอทเป็นแอดมิน)',
            str_contains($d, 'upgraded') => 'กลุ่มถูกอัปเกรดเป็น supergroup และ Chat ID เปลี่ยนแล้ว — กดค้นหา Chat ID ใหม่',
            $res['status'] === 429 => 'Telegram ให้รอสักครู่ (ส่งถี่เกินไป)',
            default => mb_substr(self::redact('HTTP ' . $res['status'] . ' ' . $res['desc']), 0, 200),
        };
    }

    /** Strip the bot token out of anything that is about to be logged, stored or shown. */
    public static function redact(string $text, ?string $token = null): string
    {
        foreach (array_filter([$token, self::token()]) as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return preg_replace('~bot\d{5,}:[A-Za-z0-9_-]{20,}~', 'bot***', $text) ?? $text;
    }

    // ---------------------------------------------------------------------- message body

    /** Customer data stays inside the chat: no forwarding or saving, when the admin asked for that. */
    private static function protects(Alert $a): bool
    {
        return in_array($a->category, ['money', 'members'], true)
            && Setting::get('telegram_protect_content', '0') === '1';
    }

    /** HTML caption: headline, the facts as a list, then the explanation — trimmed to fit $max. */
    public static function caption(Alert $a, bool $withUrl, int $max): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $head = $a->emoji() . ' <b>' . $e($a->title) . '</b>';
        $headPlain = $a->emoji() . ' ' . $a->title;

        $facts = [];
        $factsPlain = '';
        foreach ($a->facts as $label => $value) {
            $facts[] = '• ' . $e((string) $label) . ': <b>' . $e((string) $value) . '</b>';
            $factsPlain .= '• ' . $label . ': ' . $value . "\n";
        }

        $url = $withUrl && $a->url ? $a->url : '';

        // Budget the body on VISIBLE characters — that is what Telegram counts, after tags.
        $room = $max - mb_strlen($headPlain . $factsPlain . $url) - 8;
        $body = trim($a->body);
        if (mb_strlen($body) > $room) {
            $body = rtrim(mb_substr($body, 0, max(0, $room - 1))) . '…';
        }
        if ($body !== '') {
            // A long report collapses; a sentence or two stays open.
            $body = substr_count($body, "\n") >= 3
                ? '<blockquote expandable>' . $e($body) . '</blockquote>'
                : $e($body);
        }

        return implode("\n\n", array_filter([
            $head,
            implode("\n", $facts),
            $body,
            $url !== '' ? $e($url) : '',
        ], fn ($part) => $part !== ''));
    }

    /**
     * The buttons: the alert's own link rows, then a link to the admin page — a URL button only for
     * an address Telegram will accept (not localhost or a bare IP). This site's domain is Thai, so the
     * host is sent as punycode: Telegram rejects a button URL with raw Unicode in it.
     */
    private static function keyboard(Alert $a): ?array
    {
        $rows = [];
        foreach ($a->buttons as $row) {
            $out = [];
            foreach ((array) $row as $b) {
                $url = isset($b['url']) ? self::asciiUrl((string) $b['url']) : null;
                if ($url !== null && self::publicUrl($url)) {
                    $out[] = ['text' => (string) $b['text'], 'url' => $url];
                }
            }
            if ($out !== []) {
                $rows[] = $out;
            }
        }
        $url = $a->url ? self::asciiUrl($a->url) : null;
        if ($url !== null && self::publicUrl($url)) {
            $rows[] = [['text' => $a->urlLabel, 'url' => $url]];
        }

        return $rows !== [] ? ['inline_keyboard' => $rows] : null;
    }

    private static function asciiUrl(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || ! preg_match('/[^\x20-\x7e]/', $host) || ! function_exists('idn_to_ascii')) {
            return $url;
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? str_replace($host, $ascii, $url) : $url;
    }

    private static function publicUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! preg_match('/\.(test|local|localhost)$/', $host);
    }
}
