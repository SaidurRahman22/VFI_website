# Developer Requirements — things VFI must provide

This is the list of things **only you (VFI)** can supply — accounts, keys, a domain,
and data feeds — that the code cannot create by itself. Each item says:

- **Why** it's needed and what breaks without it
- **How to get it** (step by step)
- **What to deliver** to the developer (exact values)
- **How to deliver it safely**

> **How to deliver secrets safely (read this first).**
> Do **not** paste API secrets or passwords into the chat or commit them to GitHub —
> anything typed in chat is stored, and anything in Git is public in your repo.
> Two safe options for every secret below:
> 1. **You set it yourself on the server** (recommended). Each item gives the exact
>    commands. You SSH in and edit one file; the secret never leaves your server.
> 2. **You send it, then rotate.** If you'd rather the developer set it, share the
>    value through a password manager / one-time-secret link (e.g. onetimesecret.com),
>    and rotate/regenerate the key afterwards.
>
> **The one file that holds all server settings** is `/var/www/vfi/backend/.env`.
> To edit it on the server (`ssh vfi@103.14.23.151`, then your server password —
> which you should rotate now, see **Priority 0**):
> ```bash
> sudo nano /var/www/vfi/backend/.env        # edit the file
> # …make your changes, save with Ctrl-O Enter, exit with Ctrl-X…
> sudo -u www-data php /var/www/vfi/backend/artisan config:clear   # apply changes
> ```

---

## PRIORITY 0 — Security actions (do these first) 🔴 URGENT

A security review in **August 2026** fixed some issues on the server and flagged
others only you can close:

1. **Rotate the server SSH password now.** The server password was previously
   written in this file — which the web server was serving **publicly** at
   `http://103.14.23.151/Developer_requier.md` — and it also lives in the Git
   history. The public exposure has been **closed** (see below), but because the
   value was public and remains in history, **change it**: SSH in, run `passwd`,
   choose a strong password, and keep it only in a password manager. Rotate it
   anywhere it was reused.
2. **Replace the temporary admin password.** The super-admin uses a weak
   placeholder (`VFI@123`). Pick a strong password and set it (see Priority 5).
3. **Enable admin 2FA on the live server.** Set `ADMIN_REQUIRE_TOTP=true` in
   `.env` (it is `false` only for local development), then run `config:clear`, so
   admin sign-in requires Google Authenticator.

**Already fixed for you (no action needed):** the web server was serving the
application's PHP **source code**, `composer.json`, and these `.md` documents as
plaintext to anyone. That has been locked down — only the public site and the
`/api` endpoints are reachable now; source, config, and docs return “403 Forbidden”.

---

## PRIORITY 1 — Email delivery (this is why OTP codes don't arrive) 🔴 BLOCKING

**Why:** every OTP code (student sign-up, partner sign-up, email verification) and
every password-reset link is **sent by email**. Right now the app is set to the
`log` mail driver, which *writes the email to a log file instead of sending it* —
so nothing reaches your inbox. This is intentional until you provide a mail sender.
Nothing else about sign-up/sign-in is broken; only the delivery step is missing.

You have **two options**. Option A is the easiest and works today.

### Option A — Use your existing email account's SMTP (recommended, fastest)

If VFI already has an email address (e.g. `dhaka@vfi-edu.com`, a Google Workspace
account, or any hosting mailbox), you can send through it.

**A1. If you use Google Workspace / Gmail:**
1. Sign in to the Google account that will send the mail.
2. Turn on 2-Step Verification: <https://myaccount.google.com/security>.
3. Create an **App Password**: <https://myaccount.google.com/apppasswords> →
   name it "VFI Portal" → Google shows a 16-character password. Copy it.
4. Deliver these values (see the safe-delivery note above):
   - `MAIL_HOST=smtp.gmail.com`
   - `MAIL_PORT=587`
   - `MAIL_USERNAME=<the gmail address>`
   - `MAIL_PASSWORD=<the 16-char app password>`
   - `MAIL_FROM_ADDRESS=<the gmail address>`

**A2. If you use your own domain email (cPanel / hosting mailbox, e.g. vfi-edu.com):**
1. In your hosting / cPanel → **Email Accounts** → pick the mailbox → **Connect
   Devices** / "Mail Client Configuration". Note the **outgoing (SMTP) server**,
   **port** (usually 465 SSL or 587 TLS), the **username** (the full email
   address) and its **password**.
2. Deliver these values:
   - `MAIL_HOST=<smtp server, e.g. mail.vfi-edu.com>`
   - `MAIL_PORT=<465 or 587>`
   - `MAIL_USERNAME=<full email address>`
   - `MAIL_PASSWORD=<mailbox password>`
   - `MAIL_FROM_ADDRESS=<full email address>`

**To apply (you or the developer):** edit `.env` (see the box above) so it reads:
```
MAIL_MAILER=smtp
MAIL_HOST=<from above>
MAIL_PORT=<from above>
MAIL_USERNAME=<from above>
MAIL_PASSWORD=<from above>
MAIL_ENCRYPTION=tls          # use "ssl" if the port is 465
MAIL_FROM_ADDRESS=<from above>
MAIL_FROM_NAME="VFI Foreign Consultancy"
```
then run the `config:clear` command from the box.

> ⚠️ Gmail/Workspace sending has a daily cap (~500–2,000 emails/day) — fine for
> launch and testing, but for high volume move to Option B.

### Option B — Postmark (best deliverability at scale, needs a domain)

Postmark is a transactional-email service the codebase already supports.

1. Create an account: <https://postmarkapp.com> (free trial, then paid per volume).
2. **Add and verify a sending domain** (you need a domain — see Priority 2). In
   Postmark → **Sending** → **Domains** → add e.g. `mail.vfi-edu.com`. Postmark
   shows **DKIM** and **Return-Path (CNAME)** records.
3. Add those DNS records at your domain registrar / DNS host. Wait for Postmark to
   show them **Verified** (green).
4. Postmark → **Servers** → your server → **API Tokens** → copy the **Server API
   Token**.
5. Deliver: `POSTMARK_TOKEN=<the server token>` and the verified
   `MAIL_FROM_ADDRESS=<e.g. no-reply@vfi-edu.com>`.

**To apply:** in `.env` set `MAIL_MAILER=postmark`, `POSTMARK_TOKEN=<token>`,
`MAIL_FROM_ADDRESS=<verified address>`, then `config:clear`.

### ⚠️ TEMPORARY: the demo code `123456` is currently switched on

So the portals can be demonstrated before email works, the server is set to
**also accept `123456`** as the verification code on any signup or email
verification. The real, randomly generated code still works too; nothing else is
relaxed (codes still expire after 10 minutes, still allow only 5 attempts, and
still work only once).

**This must be turned off before real students or partners sign up.** While it is
on, anyone who guesses `123456` can verify an account they do not own. Every use
is recorded in the audit trail, so the period it was enabled is reviewable
afterwards.

**To turn it off** (do this the same day email starts working):
```bash
sudo nano /var/www/vfi/backend/.env     # delete the line AUTH_DEMO_OTP=123456
sudo -u www-data php /var/www/vfi/backend/artisan config:cache
```

### ⚠️ TEMPORARY: development settings that must be reverted

While the site is being built (not yet live to real students) the server is set
up for fast iteration rather than maximum security:

| Setting | Now | Must be before launch |
|---|---|---|
| `AUTH_DEMO_OTP` | `123456` | **empty** |
| `ADMIN_REQUIRE_TOTP` | `false` (password only) | **`true`** |
| `SESSION_LIFETIME` | `43200` (30 days) | `120` (2 hours) |

The last two exist because signing in, fetching an authenticator code, and then
being signed out again on every cache clear made development painful. Admin
sign-in still requires the password — only the second factor is off.

**Revert all three the day real students or partners are onboarded:**
```bash
sudo nano /var/www/vfi/backend/.env     # set the "before launch" values above
sudo -u www-data php /var/www/vfi/backend/artisan config:cache
```

### How we confirm email works
Once set, the developer registers a test account and confirms the OTP arrives in a
real inbox. You'll be told "email is live".

**Deliver for Priority 1:** the SMTP block (Option A) **or** the Postmark token +
verified from-address (Option B).

---

## PRIORITY 2 — A domain name + HTTPS 🟠 STRONGLY RECOMMENDED

**Why:** the site currently runs on a bare IP over **plain HTTP**
(`http://103.14.23.151`). A real domain with HTTPS is needed for: trustworthy
links in emails, secure sign-in cookies, best email deliverability (SPF/DKIM/DMARC
live on a domain), and simply looking professional. It also fixes `APP_URL`
(see Priority 5).

**How to get it:**
1. **Buy a domain** (if you don't have one) from any registrar — Namecheap,
   GoDaddy, Cloudflare, or a local Bangladeshi registrar. e.g. `vfi-fc.com`.
2. **Point it at the server.** In the domain's DNS settings, create an **A record**:
   - Host/Name: `@` (and `www`) → Value: `103.14.23.151`
   - If you'll use Postmark, also add its DKIM/CNAME records here.
3. Tell the developer the **exact domain** once DNS is set. The developer will then
   install a free **Let's Encrypt HTTPS certificate** on the server and switch the
   site to `https://<yourdomain>` (no cost, ~15 minutes).

**Deliver for Priority 2:** the domain name (e.g. `vfi-fc.com`), and confirmation
you've pointed its A record to `103.14.23.151`.

---

## PRIORITY 3 — Cloudflare Turnstile keys (bot protection) 🟡 RECOMMENDED

**Why:** the public sign-up / password-reset / OTP-resend forms are protected by
rate-limiting today, but a CAPTCHA-style check (Cloudflare Turnstile — free,
invisible) blocks automated abuse. It is currently **off** until you add keys.

**How to get it (free):**
1. Sign in to Cloudflare: <https://dash.cloudflare.com> (create a free account).
2. Left menu → **Turnstile** → **Add widget**.
3. Name it "VFI Portal", add your domain (or `103.14.23.151` for now), widget mode
   **Managed**. Create.
4. Copy the **Site Key** and the **Secret Key**.

**Deliver for Priority 3:**
- `TURNSTILE_SITE_KEY=<site key>` (this one is public — safe to share)
- `TURNSTILE_SECRET_KEY=<secret key>` (keep private)

**To apply:** in `.env` set `TURNSTILE_ENABLED=true`, `TURNSTILE_SECRET_KEY=<secret>`,
then `config:clear`. (The site key is added to the front-end forms by the developer.)

---

## PRIORITY 4 — Program catalogue data feeds (Phase 8 / Program Search) 🟡 PARTIALLY BLOCKED

**Why:** the partner Program Search needs a catalogue of universities and their
programs with intakes. **Free, real program data exists only for the US and
Germany** (already being ingested). For your core destinations — **UK, Canada,
Australia, Ireland, New Zealand** — there is **no free program-level feed**; the
search is filled with clearly-labelled placeholder ("seed") data until you supply a
real source. The engine, filters and UI all work now and will switch to real data
with no code change.

To get **real** programs for those countries, choose one:

**Option A — License a commercial aggregator (covers everything, paid):**
- **Studyportals** (<https://www.studyportals.com/b2b/>) — global program feed.
- **ApplyBoard / partner feeds** (<https://www.applyboard.com/partners>).
- **QS / Keystone** program datasets.
- Contact their sales/partnerships team, ask for a **program catalogue data feed
  (CSV or API)** for your destination countries, and deliver the developer the
  **API endpoint + key** or the **CSV files**.

**Option B — Get feeds directly from partner universities (free but manual):**
- Ask each VFI partner university for their **course/programme list** as a
  spreadsheet (CSV/Excel): program title, level, study area, tuition fee,
  intakes/start dates, entry requirements (IELTS etc.), application deadline.
- Deliver the spreadsheets; the developer maps them into the ingest pipeline.

**Option C — (US only, to expand US coverage) a free data.gov API key:**
- Get a free key at <https://api.data.gov/signup/> (instant, email only).
- Deliver `CATALOGUE_SCORECARD_KEY=<key>` to raise the US ingest limit.

**Deliver for Priority 4:** either a licensed feed (endpoint+key or CSVs), or the
per-university spreadsheets, per destination country. Until then, UK/CA/AU/IE/NZ
search results are seeded placeholders (clearly flagged).

### About real-time feeds, webhooks, and scraping (answering your question)

- **Webhooks:** universities do **not** publish webhooks for their programme
  catalogues — there is no push feed to subscribe to. "Real-time" program data
  realistically comes only from (a) the open government/agency APIs we already
  use (US College Scorecard, Germany's DAAD), or (b) a licensed commercial
  aggregator's API (Option A above), which we can poll on a schedule.
- **Custom scraper:** we *can* build one, but the "100% legitimate source"
  requirement is the deciding factor. Scraping a university's website is only
  safe where **their Terms of Service and robots.txt allow it**, or where VFI has
  **written permission**. Many prohibit automated collection, and scraped page
  layouts change often (constant breakage/maintenance). So a scraper is viable
  only for specific sites that permit it — not as a blanket solution.
- **Recommended path:** launch with the real US+DE feeds plus the clearly-flagged
  seed placeholders, and in parallel ask your partner universities for a
  programme list (spreadsheet or feed — Option B). **Tell us which universities
  have given written permission** to pull from their site, and we'll wire a
  compliant importer for exactly those, always taking the latest published data.

### Countries with NO real data yet (so you know the gaps)

Only **US and Germany** have real programs today. In the search filters:
- **Seed placeholder** (clearly flagged, swaps out with a feed): UK, Canada,
  Australia, Ireland, New Zealand.
- **No data at all** (selectable, but returns nothing until a feed is supplied):
  Netherlands, France, Italy, Sweden, Finland, Malaysia, Singapore, UAE.

### How often the catalogue refreshes

The catalogue is rebuilt by running an import command; today that is **manual**.
Decide how often you want it refreshed (e.g. nightly) and we'll schedule it to run
automatically.

---

## PRIORITY 5 — Small settings the developer needs from you 🟢 QUICK

- **`APP_URL`** — currently empty on the server, which makes links in emails
  point to the bare IP. Set it to your domain (Priority 2) once you have one:
  `APP_URL=https://<yourdomain>`. Until then it can be `http://103.14.23.151`.
- **Super-admin password** — the admin login (`superadmin@vfi-fc.com`) uses a
  temporary password. Decide the **real admin email + a strong password** you want,
  deliver them, and the developer sets them (or you rotate after first login). You
  should also enrol the admin's **TOTP** (Google Authenticator) on first sign-in.

**Deliver for Priority 5:** your chosen `APP_URL` and admin email/password.

---

## PRIORITY 6 — Optional, for scale/hardening (not blocking) ⚪ LATER

These are deferred by choice and only matter as volume grows:

- **Private object storage (Cloudflare R2 or AWS S3)** — student documents are
  stored on the server's local disk today (works fine, but a bucket is more durable
  and scalable). If you get one, deliver: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
  `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT` (for R2). The developer switches
  the `documents` disk to it — no code change.
- **ClamAV virus scanner** — uploaded documents are checked by a built-in scanner
  that catches the standard test virus; a full ClamAV engine (a background service)
  catches real malware. If you want it, the developer installs it on the server and
  sets `DOCUMENTS_SCANNER=clamav`. No credentials needed from you.
- **Payments (Phase 9)** — when the wallet/application-fee feature is built, a
  payment provider (e.g. **Flywire** for international education payments) account
  will be needed. Not required yet.

---

## PRIORITY 7 — Making updates reach returning visitors 🟠 ONE SERVER CHANGE NEEDED

**Why:** nginx serves `css/` and `js/` with a **7-day** public cache and the filenames
never change, so a returning visitor can run **last week's JavaScript against this week's
HTML**. That is what broke the partner Applications page — the browser's cached HTML was
missing elements the newer script expected, so the "no applications" notice sat on top of
real rows and **View** opened nothing.

**Developer rule (already in place).** Every page now references its assets with a
content stamp, e.g. `css/style.css?v=beb900c3`. The stamp is a hash of the file's own
bytes, so a changed asset gets a URL no cache can answer. **Whenever anything under
`css/` or `js/` changes, run this before committing:**

```bash
node tools/stamp-assets.mjs      # re-stamps the HTML; safe to run twice
```

Skip it and the deploy is invisible to returning visitors for up to a week.

### 🔧 TODO for whoever has server access — make nginx revalidate HTML

The stamps only work if the browser fetches fresh HTML to read them from. Today HTML is
served with **no** `Cache-Control` header at all, so browsers cache it by guesswork. Add
this block to the live site's nginx config (the repo's own copy is
`deploy/nginx/production.conf`, which already has the matching `css|js` block):

```nginx
# HTML carries the ?v= asset stamps, so it must be revalidated every time.
# "no-cache" does not mean "do not store": the browser keeps its copy but has to
# check with the server first, which answers 304 Not Modified when nothing changed.
location ~* \.html$ {
    try_files $uri @backendpublic;
    add_header Cache-Control "no-cache" always;

    # nginx stops inheriting the server-level add_header lines as soon as a block
    # defines one of its own, so the security headers must be repeated here.
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}

# The web root IS the repo root, so the new build-tooling folder needs the same
# deny the other non-web folders already have (/backend/, /deploy/, /docs/, /test/).
location ^~ /tools/ { deny all; }
```

Apply with `sudo nginx -t && sudo systemctl reload nginx`, then confirm with
`curl -sI http://103.14.23.151/index.html | grep -i cache-control` — it should say
`no-cache`. (The bare `/` URL is served as `index.html` and picks up the same rule.)

---

## Quick summary — what to send, in order

| # | Item | Blocks | What to deliver |
|---|------|--------|-----------------|
| 0 | **Rotate SSH password, set strong admin password, enable admin 2FA, turn OFF the `123456` demo code once email works** | security 🔒 | do it on the server; nothing to send |
| 1 | **Email (SMTP or Postmark)** | OTP/reset emails ❌ | SMTP host/port/user/pass **or** Postmark token + from-address |
| 2 | **Domain + point DNS to the IP** | HTTPS, links, deliverability | the domain name (A record → `103.14.23.151`) |
| 3 | **Turnstile keys** | bot protection | site key + secret key |
| 4 | **Catalogue feed(s)** | real UK/CA/AU/etc. programs | licensed feed or university spreadsheets |
| 5 | **APP_URL + admin login** | correct email links / admin | domain URL + admin email & password |
| 6 | R2/S3, ClamAV, payments | scale/later | keys if/when you choose |

**Start with #1** — send the email settings and OTP codes will arrive immediately.

---

## Images — compressing everything under `assets/`

```bash
node tools/optimise-images.mjs            # rewrite assets/ in place
node tools/optimise-images.mjs --dry-run  # show the table, write nothing
```

No npm packages and no external binaries — the VPS has no `cwebp`, `jpegoptim`, `pngquant` or
ImageMagick, so the PNG and JPEG codecs are built on `node:zlib`. It runs the same on Windows
and on the server. Add `--webp` and it will emit `.webp` siblings **only if** a real `cwebp`
happens to be on `PATH`; nothing in the HTML points at them, so wiring up `<picture>` is a
separate, opt-in job.

**It is safe to run as often as you like.** PNG work is strictly lossless, so a second pass
regenerates byte-identical output and reports "already minimal". JPEG work only ever coarsens
the quantisation tables *towards* a fixed target quality (`--jpeg-quality`, default 80) and
never past it, so a file already at the target is left alone rather than degraded a second
time. That guarantee comes from the tables themselves, not from the
`assets/.image-optimised.json` skip list — deleting that file costs you time, not quality.

Every file is decoded again before it is written: PNGs must come back pixel-for-pixel identical,
and JPEGs must come back with every DCT coefficient matching what was intended. A file that
fails is left untouched and reported, and nothing is ever replaced by a *larger* file.

Filenames and pixel dimensions never change, so no page needs editing. Note that `assets/` is
served with the same 7-day cache as everything else and image URLs are not fingerprinted the way
`css/` and `js/` are by `tools/stamp-assets.mjs` — a returning browser may hold the old bytes for
up to a week. That is fine for a recompression (the picture is the same) but worth remembering if
you ever swap an image for a *different* one.

### 🔴 Never point this tool at `backend/storage`

It refuses any `--dir` outside `assets/`, and that guard is deliberate.
`backend/storage/app/private` holds student passports, transcripts and other uploaded
documents. Every row in `document_files` carries a **sha256 used for dedupe and for an
append-only access log**, so re-encoding those bytes would invalidate the hash, break the audit
trail, and degrade the legibility of documents that get submitted to visa officers. Student
documents are never compressed, resized, or stripped of metadata — not by this tool, not by
anything else.
