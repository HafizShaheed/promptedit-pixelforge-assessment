# Deploying the Pixelforge assessment app

Recommended platform: **Railway** — it is the only suitable host that needs
**no credit card at all**: new accounts get a 30-day Trial with a one-time
$5 usage grant, and afterwards a permanent Free plan ($1/month of usage).
The app runs as **one Docker service** whose container runs the web server
*and* the queue worker together (supervisor), with a small persistent
volume for the SQLite database and the downloaded result images. One
service matters on free tiers: a Railway volume can attach to only one
service, and the web process and the worker must share the SQLite file and
the stored images.

> Timing note: the Railway Trial runs for 30 days from *account signup*,
> not from first deploy. Sign up when you are ready to deploy and submit.

## 0. Before you start

You need:

- This repository pushed to GitHub (Railway deploys from it).
- Your **Pixelforge API key** (dashboard → API Keys — the key named
  **"PromptEdit Assessment"**). You will paste it into Railway's env
  settings; never commit it anywhere.
- A locally generated Laravel app key. On any machine with the repo:
  `php artisan key:generate --show` prints one (`base64:...`) without
  touching any file. Copy it for step 3.

## 1. Create the Railway project

1. Go to <https://railway.com> and sign in with **GitHub**. Connecting
   GitHub also verifies the account — an unverified ("Limited") Trial has
   restricted outbound network access, which would break calls to the
   Pixelforge API. If prompted, complete verification at
   <https://railway.com/verify>.
2. **New Project → Deploy from GitHub repo** → pick
   `promptedit-pixelforge-assessment`.
3. Railway detects the root `Dockerfile` automatically and starts a first
   build. It will fail or start with placeholder config — that's fine;
   finish the steps below, then redeploy.

## 2. Attach the persistent volume

In the service: **Settings → Volumes → New Volume**

- Mount path: `/data`
- Size: 0.5 GB (the free allowance; plenty for SQLite + assessment images)

The entrypoint stores the SQLite database and the result images on this
volume, so user accounts, generations and prices survive redeploys.

## 3. Environment variables

Service → **Variables → Raw Editor**, paste (filling in the two secrets and
the app key):

```ini
APP_NAME="Pixelforge Image Generator"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:PASTE_YOUR_GENERATED_KEY
APP_URL=https://REPLACE-AFTER-STEP-4.up.railway.app

DB_CONNECTION=sqlite
DB_DATABASE=/data/database.sqlite

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=local
LOG_CHANNEL=stderr
LOG_LEVEL=warning

PIXELFORGE_API_KEY=pf_live_PASTE_FROM_PIXELFORGE_DASHBOARD
PIXELFORGE_WEBHOOK_SECRET=PASTE_AFTER_STEP_6
PIXELFORGE_API_URL=https://api.pixelforge.promptedit.dev/v1
```

Notes:

- `QUEUE_CONNECTION=database` is mandatory — every Pixelforge call runs in
  a queued job (`SubmitGenerationJob` / `PollGenerationJob`).
- The container entrypoint runs `php artisan migrate --force` on every
  boot, creates the SQLite file if missing, links storage, and caches
  config/routes/views. No manual shell steps are needed.

## 4. Public URL

Service → **Settings → Networking → Generate Domain**. Railway assigns
`https://<something>.up.railway.app`. Then:

1. Put that exact URL into `APP_URL` (step 3) — the app passes
   `APP_URL + /webhooks/pixelforge` to Pixelforge with each generation.
2. Redeploy (Deployments → ⋯ → Redeploy) so the entrypoint re-runs with
   the final variables.

## 5. Check the worker is running

Service → **Deploy Logs** should show supervisor starting three programs:
`php-fpm`, `nginx`, and `queue-worker` (`php artisan queue:work ...`).
The worker must be running for anything to happen after a user submits a
prompt — submissions, polling and retries all live there.

Smoke test:

1. Open the public URL, register a test account, log in.
2. **Balance** page shows the live Pixelforge balance ($25.00 starting
   credit) — this proves outbound API access works.
3. **Generate**: submit a short prompt. It should move queued →
   processing → completed in **History** (auto-refreshes) within ~2
   minutes, with the final recorded price next to it.

## 6. Register the webhook and set its secret

Webhooks make completions land even if nobody is watching the History
page, and the secret is required for signature verification:

1. Pixelforge dashboard → **API Keys** → the **"PromptEdit Assessment"**
   key → register the webhook URL:
   `https://<your-railway-domain>/webhooks/pixelforge`
   (events: `generation.completed`, `generation.failed`).
   Via the API instead:
   `POST https://api.pixelforge.promptedit.dev/v1/account/webhooks`
   with `{"url": "https://<your-railway-domain>/webhooks/pixelforge", "events": ["generation.completed", "generation.failed"]}`.
2. Copy the webhook secret shown **once** at registration and set it as
   `PIXELFORGE_WEBHOOK_SECRET` in Railway (replacing the placeholder from
   step 3). The service restarts automatically; the entrypoint re-runs
   migrations harmlessly.
3. Generate one more image and confirm it completes — webhook deliveries
   are deduplicated and out-of-order-safe, and the polling job remains as
   a fallback, so either path records the same final price.

## 7. Finish the submission paperwork

1. In `README.md`, replace the two placeholders in *Submission details*:
   - `DEPLOYED_URL` → your `https://<...>.up.railway.app` URL
   - `PIXELFORGE_ACCOUNT_EMAIL` → `shaheedkhan336@gmail.com`
2. Commit and push; Railway redeploys automatically (no content change,
   it just keeps the repo and the deployment in sync).
3. Submit the application form with: this GitHub repo URL, the deployed
   URL, and the Pixelforge account email (`shaheedkhan336@gmail.com`).

## After the Trial ends (day 31+)

The account drops to the Free plan: $1/month of usage, and services sleep
after 5–10 minutes *without outbound traffic*. Two practical effects:

- The queue worker's polling counts as outbound traffic, so while a
  generation is running the service stays awake and finishes it.
- When idle, the service sleeps; the next request (a visitor, or a
  Pixelforge webhook) wakes it. Expect a slow first page load after idle
  periods, and note that $1 of monthly usage may run out before month-end
  if the app is used heavily — services then pause until the next cycle.

If the assessment is still under review at that point and uptime matters,
the same `Dockerfile` deploys unchanged to Northflank's free Sandbox
(always-on, 2 services) or an Oracle Cloud Always Free VM — both require a
card on file for identity verification, which is why they are fallbacks
rather than the primary recommendation.

## Alternative: two services instead of one

On a platform with a shared managed database, split the roles:

- **web** service: default image command (`entrypoint.sh web` strips the
  worker out of supervisor).
- **worker** service: same image, start command
  `/usr/local/bin/entrypoint.sh worker`.
- Point both at the same Postgres/MySQL via `DB_*` variables and drop the
  `/data` volume/SQLite settings. Note that result images are stored on
  the worker's filesystem in this layout, so prefer the combined
  single-service deployment unless the platform offers shared storage.

Heroku-style platforms can use the `Procfile` instead of Docker:
`web:` runs the app server, `worker:` runs `php artisan queue:work`, and
`release:` runs migrations. The front-end assets (`npm run build`) must be
built during the platform's build phase.
