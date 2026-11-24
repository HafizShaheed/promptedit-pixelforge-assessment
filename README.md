# Pixelforge Image Generator — PromptEdit Assessment

A Laravel 12 + Inertia v2 + Vue 3 + Tailwind v4 application that lets signed-in
users generate images through the **Pixelforge API**, records exactly what each
generation cost, and charges the user the Pixelforge cost **plus a 25% markup**.

- **Pricing** lives in a standalone, unit-tested service (`app/Services/PricingService.php`).
  The price shown before submitting is an estimate (900 assumed output tokens);
  the recorded price is recomputed on completion from the API-reported token
  counts. Failed generations cost **$0**.
- **All Pixelforge calls run in queued jobs** (`SubmitGenerationJob`,
  `PollGenerationJob`) — never inline in a web request. Transient failures
  (429/5xx/timeouts) retry with exponential backoff (max 5 attempts);
  permanent errors (400/401/402/404/422) fail fast with a clear message.
- **Webhooks** (`POST /webhooks/pixelforge`) are HMAC-SHA256 verified,
  deduplicated by event id, and protected against out-of-order delivery.
- Completed images are **downloaded and stored locally** because Pixelforge
  result URLs are signed and expire after 24 hours.

## Submission details

- **Deployed URL:** `DEPLOYED_URL` _(to be filled in at submission)_
- **Pixelforge account email:** `PIXELFORGE_ACCOUNT_EMAIL` _(to be filled in at submission)_
- **Webhook URL:** `https://<your-domain>/webhooks/pixelforge`

## Requirements

- PHP 8.2+ with SQLite (or MySQL/Postgres), Composer
- Node 20+ and npm
- A Pixelforge account with an API key (dashboard → API Keys → create a key
  named **"PromptEdit Assessment"**) and a registered webhook (the webhook
  secret is shown once at registration)

## Setup

```bash
git clone <this-repo> pixelforge && cd pixelforge

composer install

cp .env.example .env
php artisan key:generate

# Configure the database (SQLite by default):
touch database/database.sqlite

# Add your Pixelforge credentials to .env:
#   PIXELFORGE_API_KEY=pf_live_...
#   PIXELFORGE_WEBHOOK_SECRET=whsec_...
#   PIXELFORGE_API_URL=https://api.pixelforge.promptedit.dev/v1

php artisan migrate

npm install
npm run build

php artisan storage:link   # so locally stored result images are web-accessible
```

`.env.example` already contains the `PIXELFORGE_*` keys (empty values) and
`QUEUE_CONNECTION=database`.

## Running the app

Both processes are required — the web server **and** a queue worker:

```bash
php artisan serve
php artisan queue:work   # REQUIRED: submissions, polling and retries run here
```

Then register an account, open **Generate**, and submit a prompt. Watch it move
through **History** (auto-refreshes while running) and check the live account
state on **Balance**.

For webhooks to arrive, `APP_URL` must be a public **https** URL; the app
passes `https://<your-domain>/webhooks/pixelforge` to Pixelforge when a
generation is submitted (and the same URL should be registered in the
Pixelforge dashboard to obtain the webhook secret).

## Pricing rules (from the Pixelforge docs)

| Size        | Input / 1k tokens | Output / 1k tokens |
| ----------- | ----------------- | ------------------ |
| 1024x1024   | $0.040            | $0.160             |
| 1024x1536   | $0.050            | $0.200             |
| 1536x1024   | $0.050            | $0.200             |

- Prompt tokens = `ceil(prompt characters / 4)`; each reference image adds a
  flat 1,600 input tokens.
- Estimate assumes 900 output tokens. Actual price uses the token counts the
  API reports. User price = cost × 1.25.
- Worked example (docs): 40-char prompt, 1024x1024, no references →
  cost $0.1444, user estimate $0.1805.

## Tests

```bash
php artisan test
```

Covers: pricing maths (incl. the docs worked example, reference-image tokens,
per-size rates, actual-cost from reported tokens), the generation lifecycle
(submit → poll → completed + recorded price + locally stored image; failed →
$0; permanent vs retryable submit errors), and the webhook handler (valid
completed/failed payloads in the docs' shape, duplicate delivery, out-of-order
delivery in both directions, bad signature → 401, stale timestamp → 401).
All API traffic in tests is faked (`Http::fake`) — no real key is needed.

## Project structure (app-specific parts)

```
app/
  Http/Controllers/
    GenerationController.php   Generate form, submission, History
    BalanceController.php      Live balance page
    WebhookController.php      Signature-verified webhook endpoint
  Jobs/
    SubmitGenerationJob.php    POST to Pixelforge with idempotency + retries
    PollGenerationJob.php      Status polling until a terminal state
  Services/
    PricingService.php         Pure pricing maths (unit-testable)
    PixelforgeClient.php       Bearer-auth HTTP wrapper (30s timeout)
    GenerationService.php      Shared complete/fail transitions + image storage
  Models/
    Generation.php             One image generation and its recorded cost
    WebhookEvent.php           Webhook dedupe log
resources/js/Pages/
  Generations/Create.vue       Form with live price estimate + warnings
  Generations/Index.vue        History with auto-refresh while running
  Balance/Index.vue            Live balance with low-balance warning
```
