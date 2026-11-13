# Mobile API parity — November 2026

Branch: `feat/app-parity-api`. Target: draft PR to `main`; no merge or deployment is included.
The app contract remains in the app repository at `docs/API_PARITY_2026-11.md`.

## Callback authentication rollout

`POST /api/server/chat-slips/ping` now requires a dedicated server bearer:

- Receiver: `services.thaiprompt.chat_slip_ping_token` / `THAIPROMPT_CHAT_SLIP_PING_TOKEN`.
- Thaiprompt relay: `services.juntra.chat_slip_ping_token` / `JUNTRA_CHAT_SLIP_PING_TOKEN`.
- Use the same randomly generated secret of at least 32 characters on both servers, shared only for this callback. No real secret is committed here. Do not reuse OAuth secrets, SMS device keys, or customer bearer tokens.
- Missing, short, or mismatched configuration fails closed: HTTP 401 with `{"message":"Unauthenticated.","reason_code":"unauthenticated"}` before throttling, validation, intake, upstream calls, or wallet changes.
- Valid callbacks retain the existing HTTP 200 result or HTTP 202 `outcome: later`; validation remains 422. The authenticated bucket remains 30/minute.
- The companion relay change must send `Authorization: Bearer ...`; without a configured secret it skips HTTP and does not increment attempts. Coordinate both configuration changes and rebuild cached configuration during an approved deployment.
- A missed or rejected ping does not lose a slip: `wallet:sweep-chat-slips` still checks parked slips every minute. This receiver change leaves that authenticated server-to-server path intact.

## C — readings

- Tarot creation accepts `voice_premium: true`. `TarotPurchase` is shared with the web purchase path: offerability, total balance precheck, atomic separate card/voice debits, and `voicepass:` idempotency. AI or persistence failure refunds both. Existing `mode: sync` stays available; async uses the web tarot finisher.
- Detail adds `persona`, `room {key,name,short,image_url}`, `reader_name`, `premium {status,price}|null`, `review {rating,message,allow_public}|null`, `share {public,url}`, and `qa_minutes`. Existing fields retain their meanings. `cost` remains the tarot base price; premium price is in `premium.price`. In a 402 `insufficient_funds`, `cost` is the total needed (card + premium when `voice_premium` is set; unchanged for 0.6.0, which never sends it).
- Share: `POST /api/v1/history/readings/{id}/share {public: bool}` → `{data:{public,url}}`. Turning sharing off returns `url:null`.
- Review: `POST /api/v1/history/readings/{id}/review {rating,message?,allow_public?}` → `{data:{review:{rating,message,allow_public}}}`. Both use the web's extracted `ReadingEngagement` rules: owner, finished tarot, and approval reset after every edit.
- Narration: `POST /api/v1/history/readings/{id}/voice/next` retains the web's **top-level** `{status,done,total,refunded,wait?,busy?}` and adds `voice:null|{status,duration,chapters,url}`. Chapter metadata comes from the existing builder. The addition supplies a usable audio URL without changing web status keys.
- Narration URLs always point to configured `APP_URL` plus `/api/v1/history/readings/{id}/voice.mp3`; raw stored URLs are replaced in API detail too. Download with the Juntra bearer. The binary route serves only that reading's local file, checks owner and active paid premium, and returns `audio/mpeg`. Refunded premium, another owner's reading, query-string URL/ID substitution, and public sharing do not grant audio access. `voice/next` on a refunded premium still answers 200 like the web (`refunded: true`, `voice: null`) so a polling app learns why narration stopped; a reading without premium is 403. Configure production `APP_URL` with its canonical HTTPS origin.
- Chat `reply_voice.url` remains the existing **public** Thaiprompt/R2 URL. Fetch that file without the Juntra bearer; never forward a bearer to another host.
- Deep accepts `mode: async`: HTTP 202 `{data:{id,status:"pending",reading:null,questions,birth_date,cost,balance,created_at}}`. The shared `InterpretDeepReading` / `DeepReadingFinisher` path handles completion and refund. Maximum questions remains **3**, shared with web and job timeout configuration.
- Replaying a Deep key returns its original reading and current status (202 while processing, 201 when done). Failed/refunded keys return 503 `reading_failed`; an orphan successful debit returns 409 `in_flight`, never a free second reading.
- Status remains `{data:{id,status:"pending"|"working"|"failed"|"done"}}`, `Cache-Control: no-store`, with the existing reading privacy gate.

## D — free card

- `GET /api/v1/tarot/free` requires bearer and returns `{data:{available,reason,message,max_chars}}`; `message` is the web gate's Thai reason (null when available).
- Reasons: `claimed|disabled|daily_cap|null`, plus **`no_token` and `in_flight`**, which are real web gates. `max_chars` is the requested **reading output** length (200–900), not the question limit.
- `POST /api/v1/tarot/free` accepts nullable `question` up to **500** characters and requires `Idempotency-Key` (1–64 characters). The shared `FreeTarotReading` service performs server drawing, user/global quota locks, reservation, failure cleanup and restoration of the free entitlement. The existing web still sends no question.
- A supplied question is persisted and forwarded to Thaiprompt's `/tarot/free`; absent/blank question leaves the legacy upstream request unchanged. **Question-aware interpretation requires [Thaiprompt PR #2703](https://github.com/xjanova/Thaiprompt-Affiliate/pull/2703)** (validator and prompt support); an older upstream produces its existing general life reading. The same companion PR authenticates the callback caller. Coordinate the companion release before presenting question-specific free readings to users.
- Success HTTP 201: `{data:{reading:<same detail shape as history>,conversation_id}}`; repeat/previous claim HTTP 200 returns the same reading and room. No second AI call, claim, or wallet debit. Reusing a successful key after a batch change still returns that original reading.
- `FreeReadingRoom::open` is shared with the web. Conversation `reading_id` stays null; `free_reading_id` points at the free card, preserving the free-ask clock and package menu.
- Errors are HTTP 409 for eligibility/busy gates, HTTP 503 for AI/deck failure, and HTTP 422 `validation_failed` for input errors. Failed AI/persistence retains the quota accounting row while restoring the customer's entitlement.

## B / E / F / G and operational prerequisites

- B package/config/wallet fields, E check-in/collection/testimonials, F profile, and G FCM endpoints remain on this branch and have focused regression coverage. All 15 feature flags now describe implemented endpoints.
- API validation (`/api/v1/*`) adds `reason_code: validation_failed`; `message` stays the first field message (Thai via `lang/th`), as the v1 endpoints that build their own 422 already do. Push validation follows the same contract.
- Play responses exclude transfer/QR/bank data through the shared channel rules. Push treats unknown/missing channel as Play, and direct-only bill notifications stay off Play.
- Apply the branch's profile/push-device migrations during an approved rollout. FCM stays disabled without `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_JSON`. Use the documented FCM service account configuration; no credentials are included in the PR.
- Existing limits outside this patch: SiteHelp may mention PromptPay to Play users; `profiles.user_id` lacks a unique index; existing web-push subscriptions survive account deletion. These need separate follow-up work.
- Verification uses PHP lint/autoload/routes and focused PHPUnit suites only. Frontend assets were not changed in this finishing patch, so npm build and unrelated full-suite tests are out of scope. External AI, FCM delivery, production callback credentials and deployment are not exercised by local fakes.
