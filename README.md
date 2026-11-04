# OpenDesk CRM — Self-Hosted PHP Edition

A complete CRM for support operations (built for ISP-Data Centers style workflows, adaptable to any
industry): tickets with a per-customer daily reference format, SLA tracking, vendor
escalation, RFO/RCA, reports, master-data import/export, and email-to-ticket.

**One server, three ways to open it — web browser, Android app, Windows software —
same data, same options.** This package is the server edition; the Android and Windows
clients connect to this same installation.

## Requirements

- PHP **8.5.12** (latest patched PHP; the code runs on PHP 8.1+ but 8.5.x is the target)
  with extensions: `pdo_mysql`, `mbstring`, `imap` (for mail fetching), `fileinfo`
- Apache or Nginx
- MySQL 8 / MariaDB 10.6+

## Quick install

1. Upload the `opendesk-crm` folder to your server.
2. Make `config/`, `storage/uploads/` and `storage/backups/` writable by the web server.
3. Create an empty MySQL database and a database user.
4. Open `https://your-server/install/` in a browser and run the installer
   (it checks the PHP version, creates EMPTY tables, and writes `config/config.php`).
5. Open the application — the first screen is a one-time **Create Owner Account** setup.
   There is no default password anywhere. The Owner then creates staff accounts
   (Admin at most — an Admin can never create, edit, reset or deactivate the Owner).

The package ships **100% empty**: no test data, no customers, no tickets, no accounts.

## Cron entries (adjust paths)

```
*/5 * * * * php /var/www/opendesk-crm/cron/fetch_mail.php
30 8 * * *  php /var/www/opendesk-crm/cron/daily_report.php
15 2 * * *  php /var/www/opendesk-crm/cron/backup.php
```

## Email

Settings → Email Integration offers three presets: **Microsoft 365**, **Generic IMAP**
(any server) and **Zimbra** (suggested IMAP 993 SSL, SMTP 465 SSL, with 587 STARTTLS
as the noted alternative — mail is picked up directly from your Zimbra server).
**Mail ports are fully editable:** the IMAP and SMTP port fields accept any value
1-65535, each with its own Security selector (SSL/TLS, STARTTLS, None). Presets only
pre-fill the common values (IMAP 993 SSL / 143 plain; SMTP 465 SSL / 587 STARTTLS /
25 plain) — no port is ever locked, and the fetch/send code uses the saved port +
security combination exactly as entered. **Test Connection** in Settings reports
against the values currently entered in the form. The fetcher
is read-only, deduplicates by Message-ID, appends replies carrying a ticket reference
to that ticket, and routes unmatched senders to **Needs Review**.

## Ticket reference format

`<Customer Company Name>-<DDMMYYYY>-<sequence>` — the sequence is that customer's own
count for the day (their 1st ticket today = 1, 2nd = 2; another customer's first
ticket is also 1; it resets daily).

## Network access

See **INSTALL-NETWORK-GUIDE.md** for the full step-by-step server and network
configuration (fixed LAN IP, public static IP port-forwarding, SSL, access modes),
also available as **INSTALL-NETWORK-GUIDE.pdf**.

## Repository layout

- `/` — the PHP server application (this is the deployable server edition):
  `app/` core code, `pages/` screens, `public/` web root (assets, manifest, service
  worker), `install/` first-run installer + schema, `cron/` mail fetch, daily report
  and backup jobs, `config/` configuration (the real `config.php` is created by the
  installer and is git-ignored), `storage/` runtime uploads/backups (git-ignored).
- `clients/` — the Android and Windows client sources (see below).
- `INSTALL-NETWORK-GUIDE.md` / `.pdf` — step-by-step server + network configuration.
- `LICENSE` — license terms, including the footer-credit clause.

## Clients

The clients in `clients/` are thin wrappers around this server — they store only
the server address locally, so the Android app, the Windows software and the web
browser all show the same live data from the one server installation.

- `clients/android/` — Android WebView client sources (no-Gradle build; see
  `clients/BUILD.md`). Signing keystores are never committed (see `.gitignore`).
- `clients/windows/` — Electron client sources with the NSIS installer script
  (`installer.nsi`). Build outputs (`dist/`, installers, `.exe`) are not committed.

## Notes

- Import accepts CSV (in Excel: File → Save As → CSV). For direct .xlsx parsing on your
  server, the open-source PhpSpreadsheet library can be added; credential-named columns
  (password / username / secret / token) are always excluded and never imported.
- Uploads: images & ZIP up to 10 MB, any other file type up to 15 MB, always optional,
  enforced on the server.
- The footer credit shown on every page is part of the license — see `LICENSE`.
