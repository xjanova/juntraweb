# Mobile social login

The app uses the existing Thaiprompt OAuth client and provider credentials. No Facebook/Google secrets or access tokens belong in Flutter or browser URLs. This change is independent of PR #68.

## Deployment dependencies

1. Deploy the Thaiprompt `juntra-social-v1` bridge first. It must expose the capability endpoint and bind each authorization code to fresh provider authentication, provider, nonce and user. Its token endpoint must reject missing/mismatched social parameters and replay.
2. Deploy this PR and run `php artisan migrate --force` before enabling traffic to the new endpoints. `APP_URL` must be the canonical HTTPS Juntra origin, with no credentials, query, fragment or subdirectory. Unicode hosts are normalized to ASCII IDNA/punycode using `idn_to_ascii` (PHP `intl` or Composer's IDNA polyfill); without IDNA support configure the ASCII host explicitly. Invalid/unconvertible origins hide providers and fail start with HTTP 503, without creating tickets. Staging origins and nondefault HTTPS ports are supported; request Host headers never choose the returned origin. The existing scheduled task runner removes expired tickets hourly.
3. Ship the Flutter client with the `juntra://auth/complete` handler. This URI wakes the app; the app polls with its private verifier to authenticate.

Until the upstream capability endpoint returns the exact flow version, the provider list is empty. On 2026-11-12, live Thaiprompt login HTML exposed Facebook and LINE, with Google absent. LINE is outside bridge v1. Google appears here only after it is configured and enabled upstream. No production settings, merge or deployment are performed by this PR.

## Public API

All endpoints are unauthenticated. Responses containing tickets or tokens have `Cache-Control: no-store`.

`GET /api/v1/auth/social/providers`

```json
{"data":{"providers":[{"id":"facebook","name":"Facebook"}]}}
```

`POST /api/v1/auth/social/start`

```json
{"provider":"facebook","code_challenge":"<base64url SHA256 of random verifier, no padding>","device":"mobile","referral_code":"OPTIONAL_SPONSOR"}
```

Returns `{data:{id,url,expires_in:300,poll_interval:2}}`. The URL is an HTTPS path on the configured Juntra origin. The random ID is stored only as SHA-256; the verifier remains on the app. TTL is fixed at creation and never extended.

The app opens the URL externally. It claims one browser session, clears existing Juntra login/remember state, and redirects through Thaiprompt's selected fresh-provider flow. A second session cannot replace the first. Callback state, browser binding, upstream provider/nonce proof and stable upstream user ID must all match. Concurrent callbacks use a conditional database claim. Staff/deleted accounts and conflicting existing identity links are refused. The success page contains no account details or tokens.

`POST /api/v1/auth/social/poll`

```json
{"id":"<start ID>","code_verifier":"<43–128 unreserved ASCII characters>"}
```

Responses:

- `{data:{status:"pending"}}` during provider authentication.
- `{data:{status:"authorized",token:"<new Sanctum token>",user:{...}}}` exactly once. User fields match `/api/v1/auth/login` and `/auth/me`.
- `{data:{status:"failed"}}`, `{data:{status:"cancelled"}}`, or `{data:{status:"expired"}}`. Restart with a new verifier/ticket.
- HTTP 404 and a generic message for unknown ID, wrong verifier or consumed ticket. Wrong verifiers never consume tickets or expose status/user data.
- HTTP 422 for malformed inputs, unavailable/unknown provider, or invalid referral syntax. HTTP 429 for rate limits.

Poll every two seconds, including after browser resume. Never authenticate from the deep link, which carries no parameters. A lost successful polling response requires a new sign-in: consumed tokens are intentionally not replayed or persisted in plaintext.

Referral codes are stored on the ticket, applied to the verified user only when no existing referral/member binding exists, and processed by the existing Mae Mor affiliate service. Browser referral cookies cannot override app attribution.

## Upstream contract

- `GET /api/oauth/social/providers`: `{data:{flow:"juntra-social-v1",providers:[{id,name}]}}`, configured+enabled Facebook/Google only. Cached for 30 seconds; failures fail closed.
- `GET /oauth/social/authorize`: existing OAuth parameters plus `provider` and random `social_nonce`; existing Juntra callback URI.
- `POST /oauth/token`: normal authorization-code exchange plus `social_provider` and `social_nonce`. Successful JSON must echo both after verifying their binding to the authorization code. Juntra compares both in constant time before fetching `/api/user`.

## Verification

`tests/Feature/MobileSocialAuthTest.php` covers PKCE mismatch, pending/expiry, replay, conditional-consume interleaving, provider availability/selection, callback proof/state, old/changed browser accounts, browser binding, staff/deleted users, identity conflicts, reserved-email squatting, referral attribution and rate limits. Existing Thaiprompt SSO and mobile auth suites cover shared callback/profile regressions.

External provider sign-in and multi-process MySQL locking require staging verification after both PRs deploy. Tests use SQLite and mocked upstream responses; the consume interleaving test checks the compare-and-set guard but does not simulate independent database workers. CluadeX Chrome was unavailable during live inspection; production login HTML was read via HTTP only.
