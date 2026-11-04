# OpenDesk CRM — Installation & Network Configuration Guide

Step-by-step server-end configuration so the CRM runs inside your network and is
reachable from outside through your **public static IP** — so authorized people
(for example senior management) can log in from anywhere in the world and view
remarks, while access stays under your control.

**One server, three ways to open it — web browser, Android app, Windows software —
same data, same options.**

## Step 1 — Prepare the server

- Install **PHP 8.5.12** (latest patched PHP) with `pdo_mysql`, `mbstring`, `imap`, `fileinfo`.
- Install **Apache or Nginx** and **MySQL 8 / MariaDB 10.6+**.
- Create an empty database and a database user for the CRM.

## Step 2 — Upload files, set permissions

- Upload the `opendesk-crm` folder (e.g. to `/var/www/opendesk-crm`).
- Make `config/`, `storage/uploads/` and `storage/backups/` writable by the web server user.
- Apache: the included `.htaccess` files already protect `config/` and `storage/`.
  Nginx: apply the rules in `nginx-notes.conf`.

## Step 3 — Run the installer, create the Owner account

- Open `http://<server>/install/` in a browser on the local network.
- Enter the database details. The installer checks the PHP version, creates the
  **empty** schema, and writes `config/config.php`. No account and no data is created.
- Open the application and complete the one-time **Create Owner Account** setup.
  The Owner is a single account with full rights; it can never be created again
  from inside the software, and there is no default password.

## Step 4 — Fixed LAN IP, test inside the office

- Give the server a **fixed LAN IP** (static assignment or DHCP reservation),
  e.g. `192.168.1.10`, so the inside address never changes.
- Set it in **Settings → Network Configuration → LAN Address**.
- Test from office PCs: `http://192.168.1.10/` should open the CRM login.

## Step 5 — Port-forward from the Public Static IP (router/firewall)

- On your router/firewall, forward **port 443** from your **public static IP** to the
  server's LAN IP (port 443). Forward **port 80** as well, for the HTTP→HTTPS
  redirect and certificate renewal only.
- Do not forward the database port (3306) — the database is never exposed.

## Step 6 — Domain (optional)

- If you have a domain, create a DNS **A record** (e.g. `crm.your-company.com`)
  pointing to your public static IP. Then the CRM opens by name instead of by IP.

## Step 7 — SSL certificate — force HTTPS (mandatory for outside access)

- Install an SSL certificate (e.g. a free Let's Encrypt certificate) for the domain/IP.
- Force HTTPS in the web server so all traffic, including passwords, is encrypted.
  Outside access without HTTPS must not be used.

## Step 8 — Public URL + Access Mode in the CRM

In **Settings → Network Configuration** (Owner only):

- Set **Public Static IP** and/or **Public Domain**.
- **External Access:** On.
- **Access Mode:**
  - **Open with Login** — the login page opens from anywhere in the world; only
    account holders get in. Best when management travels (their IP keeps changing).
  - **Restricted by IP** — only the public IPs you list can even open the login page.
    Best for fixed locations. Safety rule: the Owner is never locked out from the
    LAN address.

## Step 9 — Cron entries

Add on the server (adjust the path):

```
*/5 * * * * php /var/www/opendesk-crm/cron/fetch_mail.php
30 8 * * *  php /var/www/opendesk-crm/cron/daily_report.php
15 2 * * *  php /var/www/opendesk-crm/cron/backup.php
```

Then configure **Settings → Email Integration** (Microsoft 365, Generic IMAP, or
**Zimbra** — enter your Zimbra server address; suggested IMAP 993 SSL, SMTP 465 SSL, or 587 STARTTLS if your Zimbra uses it). Mail ports there are fully editable (any value 1-65535) with a separate Security selector (SSL/TLS, STARTTLS, None) per direction — presets only suggest common values — and the Test Connection button checks the exact values entered.

## Step 10 — Staff accounts + outside verification

- The Owner creates staff accounts (**Admin at most**; then Team Lead, Team Member,
  Viewer). Each person receives a temporary password and must set their own at
  first login — nobody else's password is ever visible or stored in plain text.
- Verify: from an outside connection (e.g. mobile data), open the public address,
  log in as senior management, and confirm tickets and remarks are visible.
