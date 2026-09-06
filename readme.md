# Online Agenda Platform (日程预约平台)

A self-hosted scheduling site that lets people **book free time on your calendar**, see your weekly **availability**, and lets you (the admin) manage everything from a private panel. Written in **vanilla PHP 8** (no framework, no Composer), served by **Nginx + PHP-FPM**, backed by **MySQL**, with calendar sources via **Microsoft Graph (Outlook)**, **private iCal URL** (e.g. Google Calendar), or **uploaded `.ics` files**. E-mails are sent through **SMTP**.

The whole UI is available in **简体中文 (中)** and **English (EN)** with a one-click language switch.

---

## 1. Features

### Visitor side
- **日程预约 / Book** — choose a day, a duration (**15 min – 6 hours**), and a free start time. Required fields: name, e-mail, phone, topic/theme. Choose where the event happens:
  - Zoom Meeting
  - Tencent Meeting
  - Phone call (ask for a phone number)
  - Meet in person (ask for a location)
  - Optional: other attendees (e-mails), appendix (dress code, things to bring, etc.)
- **日历查看 / My calendar** — a weekly view of your occupied time. Event titles & locations stay hidden until the visitor enters the **invitation code** (default `4310`).
- On submission the visitor gets a “request received” e-mail (with a cancel link), and you get a “new booking request” e-mail with **Approve / Decline** buttons.

### Admin side (`/admin/`)
Single administrator account. Sections:
- **Dashboard** — pending requests, upcoming confirmed, today's events, quick approve/decline.
- **Booking requests** — approve / decline (with reason) / cancel / delete, view full details, download `.ics`.
- **All bookings** — searchable archive.
- **General settings** — site title, default language, time zone, site URL, **working hours per weekday**, slot step, min/max duration, buffer after events, max booking advance, duration presets.
- **Appearance** — brand colour of the whole interface (with preview).
- **Security** — invitation code, administrator username/password.
- **Calendars** — link Microsoft Outlook (Graph), upload `.ics`, add a private iCal URL; choose which Outlook sub-calendars to show; set the write-back calendar; sync now; sync interval.
- **E-mail** — SMTP settings + send a test e-mail.

### Approval flow (booking lifecycle)
1. Visitor submits → a `pending` booking is created, the time is blocked on the public site.
2. You approve/decline (via the e-mail button or the admin panel).
3. **Approve** → the event is created in your selected **Outlook calendar** (with the visitor & attendees) and confirmation e-mails (with an `.ics` attachment) go to the visitor and attendees.
4. **Decline** → decline e-mails go to the visitor (and attendees if it was previously confirmed).
5. **Cancel** (you or the visitor via the link) → the time is freed; cancellation e-mails are sent; the Outlook event (if any) is deleted.

> For calendars linked by **.ics file or URL** there is no write-back, so approved bookings are confirmed by e-mail only (the time is still blocked on the site).

---

## 2. Mechanism overview

### How availability is computed
- Working hours are configured per weekday in your **site time zone** (all storage is UTC).
- Busy periods come from two sources, merged:
  1. **Cached external events** — synced from your linked calendars into the `events_cache` table.
  2. **Bookings** — every `pending`/`approved` booking blocks its time.
- A slot (step = configurable, default 15 min) is offered only if the whole `[start, start+duration]` (plus the configured **buffer** after each event) does not overlap any busy period.

### Calendar sync
`scripts/sync.php` is run by **cron** at your configured interval (default every 10 minutes). For each enabled calendar it:
- **upload** → parses the stored `.ics` file.
- **url** → fetches the private iCal URL and parses it.
- **graph** → uses the stored Microsoft token to pull `calendarView` of each selected sub-calendar.
- replaces that calendar's rows in `events_cache` (window: **−60 days … +180 days**), and prunes old rows.

The public pages and the availability engine read only the local cache — they never call Microsoft live, so the site stays fast and keeps working even if Graph is temporarily unavailable.

### Microsoft Graph (Outlook)
Uses the **OAuth2 authorization-code flow with PKCE** and a stored refresh token, so you sign in **once with your own Microsoft account** (delegated access to your own calendars). Approved bookings are written back to your chosen sub-calendar via `POST /me/calendars/{id}/events`.

To avoid double counting, events this platform creates get a subject like `[My Agenda #12] Topic`. When they come back through sync they are recognised (by the `#12` marker) and excluded from the “busy” calculation (the booking row already blocks the time).

---

## 3. Requirements

- **Debian** / Ubuntu (or similar) Linux server
- **Nginx** + **PHP-FPM 8.0+** with extensions: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`, `session`, `filter`, `fileinfo`
- **MySQL 5.7+** or **MariaDB 10.3+**
- A domain pointed at the server (needed for TLS and Microsoft sign-in)

---

## 4. Directory layout

```
Agendas_code/
├── public/                  # Nginx document root (the only web-visible folder)
│   ├── index.php            # landing: 日程预约 | 日历查看 | 后台管理
│   ├── book.php             # booking flow (+ cancel link handler)
│   ├── calendar.php         # weekly view with invite-code gate
│   ├── respond.php          # approve/decline buttons from e-mail
│   ├── ajax.php             # JSON endpoints (slots, reveal, sync, test mail, …)
│   ├── lang.php             # language switcher
│   ├── install/             # one-time web installer
│   ├── admin/               # admin login + all settings pages
│   └── assets/              # CSS / JS
├── app/                     # PHP core (NOT web-visible)
│   ├── config.php           # generated by the installer (DB + APP_SECRET)
│   ├── bootstrap.php, db.php, util.php, csrf.php, auth.php, i18n.php
│   ├── mailer.php           # self-contained SMTP client (no dependencies)
│   ├── ics.php              # RFC5545 parser + recurrence expansion
│   ├── graph.php            # Microsoft Graph OAuth + API client
│   ├── availability.php     # working hours, slots, busy periods
│   ├── booking_ops.php      # booking lifecycle + e-mails + write-back
│   ├── mail_templates.php   # e-mail HTML + .ics generation
│   └── sync_lib.php         # calendar sync engine
├── i18n/                    # en.php / zh.php dictionaries
├── scripts/sync.php         # cron job
├── deploy/                  # nginx.conf.example, crontab.example, schema.sql
├── storage/                 # uploads/, cache/, logs/, tmp/  (writable)
└── readme.md
```

---

## 5. Deployment on Debian

> **Using 宝塔面板 / BTPanel instead?** Follow the dedicated guide: [`deploy/btpanel.md`](deploy/btpanel.md) (Simplified Chinese + English). It covers creating the site, setting the run directory to `/public`, the open_basedir requirement, database creation, cron, and upgrading an already-running install.

Run the following as `root` (or with `sudo`). `agenda.example.com` is your domain.

### 5.1 Install the base stack

```bash
apt update
apt install -y nginx php-fpm php-mysql php-curl php-mbstring php-xml php-cli \
               mysql-server certbot python3-certbot-nginx curl unzip
php -v            # should be PHP 8.0+
```

### 5.2 Upload the project

```bash
# On your computer, copy the project to the server, then:
mkdir -p /var/www/agenda
# upload the contents of this folder into /var/www/agenda
chown -R www-data:www-data /var/www/agenda
chmod -R 775 /var/www/agenda/storage
```

The web root is **`/var/www/agenda/public`**. `app/`, `i18n/`, `scripts/`, `deploy/`, `storage/` live above it and are never served.

### 5.3 Nginx

Edit `deploy/nginx.conf.example`, change `server_name` and the PHP-FPM socket to match your PHP version, then:

```bash
cp deploy/nginx.conf.example /etc/nginx/sites-available/agenda.conf
ln -s /etc/nginx/sites-available/agenda.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

Enable HTTPS:

```bash
certbot --nginx -d agenda.example.com
```

### 5.4 Database

```bash
mysql -u root -p
CREATE DATABASE agenda CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'agenda'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON agenda.* TO 'agenda'@'localhost';
FLUSH PRIVILEGES;
```

(The installer can also create the database automatically if the user has the privilege.)

### 5.5 Run the installer

Open **`https://agenda.example.com/install/`** in a browser. Enter the database credentials, your administrator username/password/e-mail, the site title, base URL and time zone. It writes `app/config.php`, creates the tables and default settings, then locks itself. Default invitation code: **`4310`** (change it in Admin → Security).

After install, log in at `/admin/` and configure (in this order):
1. **E-mail** — SMTP settings, click **Send** to test.
2. **Calendars** — link Outlook (see §6) and/or add `.ics`/URL sources, then **Sync now**.
3. **General settings** — working hours, durations, buffer, time zone.
4. **Appearance / Security** — theme colour, invitation code, credentials.

### 5.6 Cron (automatic sync)

```bash
crontab -e
# add:
*/10 * * * * /usr/bin/php /var/www/agenda/scripts/sync.php >> /var/www/agenda/storage/logs/sync.log 2>&1
```

Match `10` to the “Automatic sync interval” in Admin → Calendars. The cron user (`www-data` recommended) must be able to read `/var/www/agenda` and write `storage/`.

---

## 6. Linking Microsoft Outlook (Microsoft Graph)

1. Go to <https://portal.azure.com> → **Azure Active Directory** → **App registrations** → **New registration**.
   - Name: e.g. `Agenda Platform`
   - Supported account types: **Personal Microsoft accounts and work/school accounts** (or single-tenant if you prefer).
   - Redirect URI: **Web** → `https://agenda.example.com/admin/graph_callback.php`
   - Save → note the **Application (client) ID** and **Directory (tenant) ID**.
2. In the app → **Certificates & secrets** → **New client secret** → copy the **value** (shown once).
3. Under **API permissions** → **Add a permission** → **Microsoft Graph** → **Delegated permissions** → add:
   - `Calendars.ReadWrite`
   - `User.Read`
   - (optional) `offline_access` is implied when we request it.
   No admin consent is needed for delegated permissions on the user's own calendar.
4. In **Admin → Calendars → Connect Microsoft Outlook**, enter the Tenant ID, Client ID and Client Secret, then click **Sign in with Microsoft**. Sign in with your Outlook account and consent.
5. After connecting, a picker appears: tick the **sub-calendars** to show on the site, and choose the **write-back calendar** where approved bookings are created.

> Because you sign in with your own account (delegated flow), **no admin consent** is required and no separate Azure user is needed.

### Using a personal Outlook / Google calendar without Graph
- **Google Calendar**: open the calendar's settings → **Settings and sharing** → scroll to “Secret address in iCal format” → copy the URL → add it in **Admin → Calendars → Add iCal URL**.
- **Any calendar that exports `.ics`**: download the file and use **Add / Upload .ics file**.

---

## 7. SMTP e-mail

Configure in **Admin → E-mail**. Common providers:

| Provider | Host | Port | Encryption | Username | Password |
|---|---|---|---|---|---|
| Gmail | `smtp.gmail.com` | 587 | STARTTLS | full gmail address | **App password** (2FA required) |
| QQ 邮箱 | `smtp.qq.com` | 465 | SSL | qq number | **授权码** (SMTP authorization code) |
| 163 邮箱 | `smtp.163.com` | 465 | SSL | 163 address | **客户端授权密码** |
| Outlook.com | `smtp-mail.outlook.com` | 587 | STARTTLS | full outlook address | app/account password |

Always use an **app password / SMTP authorization code**, never your main account password. The password is stored AES-encrypted using the secret in `app/config.php`.

---

## 8. Security notes

- One administrator account, bcrypt-hashed. Session-based login.
- CSRF token on every form/POST.
- Invitation code (`4310` default) gates event titles in the calendar view; the admin_token in e-mailed approve/decline links is the secret that authorises them.
- `app/`, `storage/`, `i18n/`, `scripts/`, `deploy/` are blocked by Nginx.
- Keep `app/config.php` and `storage/cache/graph_token.json` out of version control and readable only by `www-data` (the token file is written with `0600`).
- Use HTTPS (certbot) — required for the Microsoft sign-in redirect and to protect e-mail links.

---

## 9. Upgrading an installed site

Each release ships a self-contained `upgrade_<version>.php` at the project root. It applies the changes to a server that is **already deployed and running** (no re-upload of the whole project needed). It is standalone — it does **not** require `app/bootstrap.php`, so it works even when the running site is broken.

**To upgrade to 1.1.0:**

1. Upload `upgrade_1.1.0.php` to the project root (next to `public/` and `app/`).
2. Run it either way:
   - Browser: `https://agenda.example.com/upgrade_1.1.0.php`
   - CLI: `php /var/www/agenda/upgrade_1.1.0.php`
3. It reads `settings.app_version` (missing = 1.0.0), **backs up** the 13 admin PHP files to `storage/tmp/upgrade_backup_<timestamp>/`, applies the path fixes, writes `app_version = 1.1.0`, and prints a per-file report. If it is already ≥ 1.1.0 it reports “already up to date” and exits.
4. **Delete `upgrade_1.1.0.php` from the server afterwards** — it deliberately has no access token.

### What 1.1.0 fixes
- Admin panel fatal error `Failed opening required .../public/admin/../app/bootstrap.php`: the admin pages required bootstrap with a path one level too shallow. All 13 `public/admin/*.php` now use `../../app/bootstrap.php`.
- Admin AJAX endpoints (`test mail`, `sync now`, Graph calendar picker) pointed at `ajax.php` instead of `../ajax.php`, so they 404'd from `/admin/`.

---

## 10. Troubleshooting

- **Installer says requirements missing** → install the PHP extensions: `apt install php-curl php-mbstring php-mysql php-xml`.
- **“Database connection failed”** → check `app/config.php` credentials and that MySQL is running (`systemctl status mysql`).
- **E-mails not sent** → Admin → E-mail → Send test. Check the port/encryption and that the provider requires an app password. Look in `storage/logs/`.
- **Calendar shows no events** → Admin → Calendars → Sync now, and check the “Last sync / error” column. For URL sources confirm the link is the **secret iCal address** (must be `https://`).
- **Approve doesn't create an Outlook event** → confirm you are connected to Microsoft and that a write-back calendar is selected. The error (if any) is shown on the booking details row.
- **Times look wrong** → the site time zone is set in Admin → General settings; all stored times are UTC.
- **Re-install** → delete `storage/installed.lock`, run `/install/` again (this resets the database).
- **Admin panel 500 / `Failed opening required ...app/bootstrap.php`** → you are running a pre-1.1.0 copy of `public/admin/`. Run `upgrade_1.1.0.php` (see §9) or replace `public/admin/*.php` with the current versions.
- **BTPanel admin 500 / open_basedir restriction** → set the run directory to `/public` and keep the open_basedir range at the site root (not `/public`) — see `deploy/btpanel.md` §3–§4.

---

## 11. License / notes

Built with **vanilla PHP** — the SMTP client and the iCalendar parser are self-contained, so the server needs **no internet at runtime** except for Microsoft Graph / calendar URLs / SMTP. No third-party SDKs are bundled.
