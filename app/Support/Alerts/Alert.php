<?php

namespace App\Support\Alerts;

/**
 * One admin alert, described once and rendered per surface: a drawn card plus an HTML caption for
 * Telegram, a row in the history table for the admin page, plain text wherever pictures can't go.
 *
 * It is structured rather than a pre-formatted string because a card needs to know which part is
 * the headline, which numbers deserve a tile and what to chart — and a string that already has all
 * of that flattened into lines cannot be un-flattened reliably.
 *
 * Ported from NetWix (same shape), plus two things xmanstudio needs: `columns` for a per-day chart
 * (sales over a week) and `buttons` — actions the admin can take from the chat itself.
 */
final class Alert
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    /** Worth a record, not a buzz — Telegram delivers it silently. */
    public const INFO = 'info';

    public const OK = 'ok';

    /** Good news that should buzz: a new order, money in, a customer writing to us. */
    public const MONEY = 'money';

    public const LEVELS = [self::CRITICAL, self::WARNING, self::INFO, self::OK, self::MONEY];

    /**
     * @param  string  $key  throttle identity ("order:ORD-123") — the same key is silent for a while
     * @param  array<string,string|int>  $facts  label => value, drawn as tiles (first 4)
     * @param  array<string,int|float>  $bars  label => amount, drawn as horizontal bars (first 6)
     * @param  array<string,bool>  $chips  label => healthy?, drawn as status pills
     * @param  string  $category  one of AdminAlerts::CATEGORIES — decides whether it is sent at all
     * @param  array<string,int|float>  $columns  label => amount in order, drawn as a column chart (first 14)
     * @param  array<int,array<int,array{text:string,url?:string,data?:string}>>  $buttons  rows of chat buttons:
     *                                                                                      `url` opens a page, `data` is a bot action
     * @param  string|null  $photo  absolute path of an image sent right after the card, as a reply to it
     *                              (a payment slip, so it can be checked without opening the website)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $level,
        public readonly string $title,
        public readonly string $body = '',
        public readonly array $facts = [],
        public readonly array $bars = [],
        public readonly array $chips = [],
        public readonly ?string $url = null,
        public readonly string $urlLabel = 'เปิดในหน้าแอดมิน',
        public readonly string $category = 'system',
        public readonly string $barsLabel = '',
        public readonly array $columns = [],
        public readonly string $columnsLabel = '',
        public readonly string $chipsLabel = 'สถานะระบบ',
        public readonly array $buttons = [],
        public readonly ?string $photo = null,
    ) {}

    /** The same alert under another throttle key — one card design, several distinct events. */
    public function withKey(string $key): self
    {
        $args = get_object_vars($this);
        $args['key'] = $key;

        return new self(...$args);
    }

    public function emoji(): string
    {
        return match ($this->level) {
            self::CRITICAL => '🚨',
            self::WARNING => '⚠️',
            self::INFO => '🛡️',
            self::MONEY => '💰',
            default => '✅',
        };
    }

    /** Short Thai word for the level — the badge on the card. */
    public function levelLabel(): string
    {
        return match ($this->level) {
            self::CRITICAL => 'ด่วน',
            self::WARNING => 'ควรตรวจสอบ',
            self::INFO => 'แจ้งให้ทราบ',
            self::MONEY => 'เรื่องใหม่',
            default => 'เรียบร้อย',
        };
    }

    /** "label: value" lines for the facts — what a text-only channel shows instead of tiles. */
    public function factLines(): array
    {
        $lines = [];
        foreach ($this->facts as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        return $lines;
    }

    /** Plain text — the fallback message, and what the history table keeps. */
    public function toText(): string
    {
        $parts = [$this->emoji() . ' ' . $this->title];
        if ($this->facts !== []) {
            $parts[] = implode("\n", $this->factLines());
        }
        if (trim($this->body) !== '') {
            $parts[] = trim($this->body);
        }
        if ($this->url) {
            $parts[] = $this->url;
        }

        return implode("\n\n", $parts);
    }
}
