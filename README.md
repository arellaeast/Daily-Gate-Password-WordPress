# Daily Gate Password

A WordPress plugin that rotates a random password on a chosen page (a content gate) and exposes simple REST endpoints to set/fetch that password. Built to pair with an automated daily rotation (e.g. an n8n workflow) but works completely standalone too.

---

## What it does

- Picks one WordPress **page** to act as your gate.
- Generates a random password and applies it using WordPress's native page-password feature (the same mechanism as the "Password Protected" visibility option in the block editor).
- Stores the current password, the page ID, and the date it was last set.
- Controls how long a visitor's "unlocked" cookie lasts before WordPress asks for the password again (default: expires at midnight, site time — a hard daily cutoff).
- Adds a **Settings → Daily Gate Password** screen to configure all of the above without touching code.

It does **not** generate a new password on its own schedule. Something has to call the rotation endpoint (or use the manual button — see below) once a day. That "something" is usually n8n, but doesn't have to be.

---

## Installation

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, then **Activate**.
2. Go to **Settings → Daily Gate Password**.
3. Under **Gated page**, pick the page you want protected.
4. Adjust **Default password length** and **Access cookie duration** if you want something other than the defaults (12 characters, expires at midnight).
5. Save.

At this point the plugin is configured but no password has been set yet — the gated page won't have a password until the rotation endpoint is called for the first time (or you use the manual option below).

---

## Using it WITHOUT n8n

If you don't want to run n8n at all, you have two options: a manual click in wp-admin, or a server-side cron job. Both produce the same result — they just skip the network round-trip n8n would otherwise make.

### Option A — Manual rotation from wp-admin

The **Settings → Daily Gate Password** screen shows the current password, which page it's applied to, and whether it was rotated today.

If you're setting the password by hand each morning and copy-pasting it into your newsletter tool directly, just open that settings page each day, copy the current password, and paste it in — no automation required at all. (If you'd like a one-click "rotate now" button added to that screen instead of relying on the REST endpoint, that's a small addition — just ask.)

### Option B — Server-side cron (fully automated, no n8n)

If you have shell access to the server (you do, via CasaOS) and want automatic daily rotation without any external workflow tool, use a system cron job to call the REST endpoint locally with `curl`.

Example cron entry (runs daily at 6:00 AM), hitting the REST endpoint from the server itself:

```bash
0 6 * * * curl -s -u "USERNAME:APP_PASSWORD" -X POST https://your-site.com/wp-json/daily-gate/v1/set-password >> /var/log/daily-gate.log 2>&1
```

Replace `USERNAME:APP_PASSWORD` with the WordPress username and Application Password (see the n8n section below for how to generate one — the same credential works here too).

To then pull the password into something else (e.g. a script that builds your newsletter without n8n), read it straight from the database via WP-CLI:

```bash
wp option get dgp_current_password --path=/path/to/wordpress
```

This gives you the current password as plain text, which you can pipe into whatever script assembles your newsletter content.

---

## Using it WITH n8n

This is the setup the plugin was originally built around: n8n handles the daily schedule and the newsletter assembly, the plugin handles password generation and storage.

### 1. Create a WordPress Application Password

Application Passwords are a built-in WordPress feature (no extra plugin needed) that let external tools authenticate as a real user without using that user's actual login password.

1. In wp-admin, go to **Users → Profile** (for the account n8n should authenticate as — usually an editor or admin).
2. Scroll to **Application Passwords**.
3. Enter a name like `n8n-daily-gate` and click **Add New Application Password**.
4. Copy the generated password immediately — it's only shown once.

### 2. Create the n8n credential

In n8n: **Credentials → New → HTTP Basic Auth**.

- **User**: the WordPress username from step 1
- **Password**: the Application Password string (keep the spaces — n8n handles them fine)

Name it something identifiable, e.g. `WP - Daily Gate App Password`.

### 3. The two REST endpoints

| Endpoint | Method | Purpose |
|---|---|---|
| `/wp-json/daily-gate/v1/set-password` | `POST` | Generates a new password, applies it to the gated page, stores it. Returns the new password in the response. |
| `/wp-json/daily-gate/v1/get-password` | `GET` | Returns today's password without changing anything. Use this from your newsletter-building workflow. |

Both require the Basic Auth credential from step 2. Both only work for a user who can edit pages.

**Example response from `get-password`:**

```json
{
  "password": "k7mPq9vXrT2w",
  "page_id": 123,
  "set_date": "2026-06-19",
  "is_current": true
}
```

`is_current` is `true` only if the password was actually rotated today — handy as a quick sanity check before you send a newsletter out with it.

### 4. Wire up the workflow

- **Rotation branch**: Schedule Trigger (daily, e.g. 6 AM) → HTTP Request (`POST set-password`, Basic Auth credential attached).
- **Newsletter branch**: wherever your newsletter content gets assembled → HTTP Request (`GET get-password`, same credential) → drop `{{ $json.password }}` into your email template, ideally wrapped in bold (`<b>...</b>`) so it stands out.

If you're integrating into an existing multi-branch n8n workflow (e.g. one that already merges news, events, and ad content before building the final HTML), add the password fetch as one more branch into that merge step, then read it back out in whatever Code node builds the final HTML.

---

## Settings reference

| Setting | What it controls |
|---|---|
| **Gated page** | Which WordPress page gets the password applied. Used whenever a rotation request doesn't explicitly specify a `page_id`. |
| **Default password length** | Length (8–64 characters) used when a rotation request doesn't specify a `length`. |
| **Access cookie duration** | How long a visitor stays "unlocked" after entering the password correctly. `0` = expires at midnight site time (strict daily rotation). Higher values (e.g. 1–2 days) give a grace period instead of a hard cutoff. |

---

## Troubleshooting

**"Stale" status on the settings page** — the password hasn't been rotated today. Check that your cron job or n8n Schedule Trigger actually ran; this plugin won't rotate anything on its own.

**401 Unauthorized from the REST endpoints** — usually means the Application Password credential is wrong, or a reverse proxy in front of WordPress is stripping the `Authorization` header before it reaches PHP. Check proxy/server config if Basic Auth headers are being dropped.

**Password still works after midnight** — check the **Access cookie duration** setting. If it's set above `0`, visitors who entered yesterday's password are intentionally still let in for the grace period you configured.

**No password ever appears** — the gate doesn't auto-generate one on plugin activation. Trigger the `set-password` endpoint or cron job once manually to generate the first one.
