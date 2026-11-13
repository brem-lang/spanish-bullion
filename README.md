# Spanish Bullion – static version

Spanish-language landing page for visitors in Spain, adapted from the brasil-bullion `/descubra` and `/cadastro` pages, for hosting that runs
PHP (Apache, cPanel, …) instead of the Node app. No build step.

| File | What it is |
| --- | --- |
| `index.html` | Pre-landing article (`/descubra`), the page visitors land on. Its buttons show a 2 s loader, then open `cadastro.html` with the same query string (utm_*, click_id). |
| `cadastro.html` | Signup page (`/cadastro`). The signup form is a `<template>` at the bottom. |
| `index.css` | Styles (generated from the app's Tailwind build; additions at the end). |
| `index.js` | Form, validation, captcha, Meta Pixel, live prices. **Settings at the top.** |
| `form-timing.js` | Records how the form was filled, for the leads log. |
| `api/submit-lead.php` | Receives the form, checks it, verifies the captcha, sends the lead to the CRM. |
| `api/lib/lead-log.php` | Copies accepted leads (and the timing report) to the leads log. |
| `tests/` | Tests (see below). Not served. |
| `api/prices.php` | Live prices for the ticker (Yahoo Finance, cached 60 s). |
| `assets/hero-trader.jpg` | Hero photo. |
| `.env.example` | Server settings: copy to `.env`. |
| `.htaccess` | Blocks web access to `.env` (Apache). |

## Setup

1. Upload the folder. The server needs PHP 8.1+ with the `curl` extension.
2. Copy `.env.example` to `.env` and fill in `BASE_ENDPOINT`, `BASE_API_KEY` and `TURNSTILE_SECRET_KEY`.
   Behind Cloudflare, also set `TRUST_CLOUDFLARE=1`.
3. In `index.js`, set `CONFIG.TURNSTILE_SITE_KEY`.
4. On nginx, block `.env` yourself (`location ~ /\. { deny all; }`): `.htaccess` only works on Apache.
5. Clean URLs `/descubra` and `/cadastro` work on Apache through `.htaccess` (needs `mod_rewrite`). On nginx add
   `location = /descubra { try_files /index.html =404; }` and `location = /cadastro { try_files /cadastro.html =404; }`.
   Ads can point at `/`, `/descubra` or `/index.html`: all show the same page.

Without the captcha keys, every lead is rejected on the live site. On `localhost`, Cloudflare's
always-pass test keys are used automatically.

## How it behaves

- **Form:** step 1 is nombre, apellidos, e-mail and a Spanish mobile (+34, 9 digits starting with 6 or 71–74) plus "30 años o más"; step 2 is the 3 questions, RGPD
  consent and the captcha. The browser and `api/submit-lead.php` apply the same checks.
- **Bots:** no valid captcha → rejected. Honeypot filled, submitted in under 3 s, or a visitor
  outside Spain (Cloudflare only) → sent to the CRM flagged `is_bot=true` with the reason.
- **After signup:** the form shows a success message in the same tab, then (if the CRM returns an
  autologin link) this tab goes to the account after 1.5 s. No new tab is opened.
- **Meta Pixel:** PageView on load, Lead after the CRM accepts. Never on `localhost`.
- **Logs:** each lead writes one `[submit-lead]` line to the PHP error log (no personal data).
- **Leads log:** each lead the CRM accepted is also posted to `LEADS_LOG_ENDPOINT` (with
  `LEADS_LOG_API_KEY` from `.env`; nothing is sent without it), after the visitor already has
  the response. It holds the exact CRM payload plus a readable `timing` report of how the form
  was filled (`form-timing.js` records it in the browser; `api/lib/lead-log.php` writes the
  report). Timing never goes to the CRM, and bad timing data never rejects a lead.

## Testing locally

```sh
php -S localhost:8000 router.php
```

Then open http://localhost:8000/descubra. `router.php` copies the `.htaccess` clean URLs
(`/descubra`, `/cadastro`), because PHP's built-in server and Node dev servers like Live Server
ignore `.htaccess`. Leads only go through with a real `BASE_ENDPOINT` and
`BASE_API_KEY` in `.env`.

## Tests

```sh
php tests/run.php                        # server (needs php-cgi next to php; never calls the CRM)
node --test tests/form-timing.test.mjs   # browser timer
```

`SHOW_REPORT=1 php tests/run.php` prints a sample timing report.
