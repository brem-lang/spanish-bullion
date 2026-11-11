# Brasil Bullion – static version

Plain HTML/CSS/JS copy of the brasil-bullion `/cadastro` landing page, for hosting that runs
PHP (Apache, cPanel, …) instead of the Node app. No build step.

| File | What it is |
| --- | --- |
| `index.html` | The page. The signup form and bonus offer are `<template>`s at the bottom. |
| `index.css` | Styles (generated from the app's Tailwind build; additions at the end). |
| `index.js` | Form, validation, captcha, Meta Pixel, live prices, bonus tab. **Settings at the top.** |
| `api/submit-lead.php` | Receives the form, checks it, verifies the captcha, sends the lead to the CRM. |
| `api/prices.php` | Live prices for the ticker (Yahoo Finance, cached 60 s). |
| `assets/hero-trader.jpg` | Hero photo. |
| `.env.example` | Server settings: copy to `.env`. |
| `.htaccess` | Blocks web access to `.env` (Apache). |

## Setup

1. Upload the folder. The server needs PHP 8.1+ with the `curl` extension.
2. Copy `.env.example` to `.env` and fill in `BASE_ENDPOINT`, `BASE_API_KEY` and `TURNSTILE_SECRET_KEY`.
   Behind Cloudflare, also set `TRUST_CLOUDFLARE=1`.
3. In `index.js`, set `CONFIG.TURNSTILE_SITE_KEY` (and `CONFIG.WHATSAPP_NUMBER` for the bonus button).
4. On nginx, block `.env` yourself (`location ~ /\. { deny all; }`): `.htaccess` only works on Apache.

Without the captcha keys, every lead is rejected on the live site. On `localhost`, Cloudflare's
always-pass test keys are used automatically.

## How it behaves

- **Form:** step 1 is name, e-mail and WhatsApp (+55) plus "30 anos ou mais"; step 2 is the 3 questions, LGPD
  consent and the captcha. The browser and `api/submit-lead.php` apply the same checks.
- **Bots:** no valid captcha → rejected. Honeypot filled, submitted in under 3 s, or a visitor
  outside Brazil (Cloudflare only) → sent to the CRM flagged `is_bot=true` with the reason.
- **After signup:** a new tab opens `index.html?bonus=1` (the 100% bonus offer with the WhatsApp
  button) and this tab goes to the account (autologin). If the browser blocks the tab, this tab
  stays and shows the bonus and an "Acessar minha conta" button instead.
- **Meta Pixel:** PageView on load, Lead after the CRM accepts. Never on `localhost`.
- **Logs:** each lead writes one `[submit-lead]` line to the PHP error log (no personal data).

## Testing locally

```sh
php -S localhost:8000
```

Then open http://localhost:8000. Leads only go through with a real `BASE_ENDPOINT` and
`BASE_API_KEY` in `.env`.
"# spanish-bullion" 
