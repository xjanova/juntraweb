# แม่หมอจันทรา · juntraweb

Fortune-telling website built on Laravel 11 with the Mae Mor Chantra theme. Sister project to [Thaiprompt-Affiliate](https://github.com/xjanova/Thaiprompt-Affiliate); deployed to the same DirectAdmin server.

**Production:** https://xn--82c4af5bzdj.online (`จันทราพยากรณ์.online`)
**Server path:** `/home/admin/domains/xn--82c4af5bzdj.online/public_html`

## Features

- **Tarot** — 78-card Rider-Waite deck, 3-card spread + Celtic Cross with 3D flip animation, AI-generated interpretations
- **Daily horoscope** — 12 western zodiacs, daily-fresh content (admin-managed or AI-generated), lucky number/color/card
- **Thai zodiac (ปีนักษัตร)** — 12 nakshatras with traits
- **Numerology** — Pythagorean Life Path / Expression / Birth Day with Thai narratives
- **Palmistry** — image upload, Gemini Vision analysis
- **Auspicious dates (ฤกษ์ยาม)** — heuristic scorer + AI advice
- **AI Chat** — single-flight conversational oracle (Gemini), per-session history
- **Membership** — Laravel Breeze auth, reading history per user
- **Admin** — Filament v3 panel for content/users/settings

## Stack

- Laravel 11 + PHP 8.3
- Tailwind CSS 3 + Alpine.js + Vite
- Filament 3 (admin)
- Laravel Breeze (auth, blade)
- Laravel Sanctum (API auth, future use)
- MySQL (production) / SQLite (local)

## Local development

```bash
git clone https://github.com/xjanova/juntraweb.git
cd juntraweb
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run dev
# In another terminal:
php artisan serve
```

Default admin (after seeding): `admin@xn--82c4af5bzdj.online` (password is derived from `APP_KEY`; see `database/seeders/AdminUserSeeder.php`).

## Configuring AI

Settings → AI:
- `ai_provider` — `gemini` (default)
- `ai_model` — `gemini-2.0-flash-exp`
- `ai_api_key` — your Google AI Studio API key (encrypted at rest)

If `ai_api_key` is empty, every AI surface falls back to a deterministic heuristic so the site remains functional.

## Deployment

Mirrors the Thaiprompt-Affiliate pattern:

1. **GitHub Actions** (`.github/workflows/deploy.yml`) — triggers on push to `main` or manual dispatch
2. **appleboy/ssh-action** — SSH into DirectAdmin server using organization secrets `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`
3. **Server-side `deploy.sh`** — git pull → composer → npm → migrate → seed → cache regenerate → health check

Required GitHub repo variables:
- `DEPLOY_PATH` = `/home/admin/domains/xn--82c4af5bzdj.online/public_html`
- `APP_URL` = `https://xn--82c4af5bzdj.online`

Required GitHub secrets (inherited from organization, no per-repo setup needed):
- `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`, optionally `SSH_PORT`

## License

Private — © 2026 xjanova
