# CertiTrack

**A privacy-preserving, browser-based monitoring system for online certification examinations.**

A Chrome extension records a small, predefined set of browser signals (tab switches, new tabs, exam-tab closure, navigation outside approved domains, window focus loss, connection loss) **only while a student explicitly runs a monitoring session**. A Laravel backend stores them in MySQL, and instructors review timelines in a web dashboard.

> ⚠️ **CertiTrack records technical activity. It does not detect, prevent, or prove cheating.** Events can have innocent explanations, users can disable the extension, and browser-generated records are not tamper-proof.

## Project status

Verified on Ubuntu 22.04 with Chrome by the maintainer (results reported by the maintainer; output logs are not stored in the repo):

| Part | Status |
|---|---|
| Chrome extension logic tests (`tests/extension`) | ✅ 31/31 (fake Chrome API + API stub) |
| Laravel backend tests (`php artisan test`) | ✅ Passing |
| End-to-end API smoke test against Laravel + MySQL (`tests/integration/e2e-smoke.mjs`) | ✅ Passing |
| Extension loaded in real Chrome and used against the backend | ✅ Working |
| Formal capture-rate measurement (95% target) | ⬜ Not measured |
| `composer.lock` | ⬜ Not committed yet. Run `composer install` and commit it |

If you hit a problem on another OS or PHP version, check `backend/storage/logs/laravel.log` and open an issue with the output.

## What's inside

```
backend/                Laravel app (API, Sanctum auth, RBAC, Blade dashboards)
certitrack-extension/   Chrome Manifest V3 extension
database/               create-database.sql
scripts/                setup-backend.sh / .ps1
tests/                  extension tests + end-to-end API smoke test + Postman collection
documentation/          detailed technical README (API, schema, limitations)
```

**Roles:** Student (pairs extension, starts/ends sessions, sees own records) · Instructor (reviews assigned students' sessions) · Administrator (users, platforms, domains, rules, audit log).

---

## Quick start (Ubuntu 22.04)

### 1. Install PHP 8.3, Composer, MySQL, Node, Chrome

Jammy ships PHP 8.1 and Node 12, which are too old, so this adds newer versions.

```bash
sudo apt update
sudo apt install -y software-properties-common curl wget unzip git ca-certificates

# PHP 8.3
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-sqlite3 php8.3-bcmath php8.3-intl

# Composer 2
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer

# MySQL 8
sudo apt install -y mysql-server && sudo systemctl enable --now mysql

# Node 20 (only used by the test scripts)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs

# Google Chrome
wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb -O /tmp/chrome.deb
sudo apt install -y /tmp/chrome.deb
```

Check: `php -v` (8.3.x), `composer --version` (2.x), `node -v` (v20.x).

### 2. Get the code

```bash
git clone <repo-url> CertiTrack     # or unzip CertiTrack-Complete-Project.zip
cd CertiTrack
```

### 3. Create the database

Choose a password with letters and digits only:

```bash
DBPASS='ChooseAPassword123'
sed -i "s/change_me_db_password/$DBPASS/" database/create-database.sql
sudo mysql < database/create-database.sql
mysql -u certitrack -p"$DBPASS" -e "SHOW DATABASES;"      # should list "certitrack"
```

### 4. Bootstrap the backend

```bash
bash scripts/setup-backend.sh
```

This downloads the Laravel 12 skeleton for framework boilerplate (without overwriting CertiTrack's files), runs `composer install`, creates `backend/.env`, and generates the app key. It takes a few minutes and needs internet access.

```bash
cd backend
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DBPASS/" .env
```

### 5. Migrate, seed, and run the backend tests

```bash
php artisan migrate --seed
php artisan test
```

### 6. Start the servers

Use two terminals, both in `backend/`:

```bash
php artisan serve --host=127.0.0.1 --port=8000     # terminal 1
php artisan schedule:work                          # terminal 2: heartbeat timeouts and retention purge
```

Open <http://127.0.0.1:8000>. You should see the login page.

### 7. Development accounts

These are seeded by `php artisan migrate --seed`. **Change or delete them before any real deployment.**

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@certitrack.test` | `Password123!` |
| Instructor 1 (supervises students 1 and 2) | `instructor1@certitrack.test` | `Password123!` |
| Instructor 2 (supervises student 3) | `instructor2@certitrack.test` | `Password123!` |
| Students | `student1@certitrack.test` … `student3@certitrack.test` | `Password123!` |

### 8. Verify the backend

With the server running, in a third terminal from the repository root:

```bash
node tests/integration/e2e-smoke.mjs      # every line should say PASS
node tests/extension/run-tests.mjs        # extension logic tests, 31/31
```

The smoke test walks the full flow: login, pairing, session start, event ingest, duplicate rejection, cross-student access checks, instructor read, review, and session end. The extension tests run against a fake Chrome API and a stub server, not against Laravel.

### 9. Load the extension in Chrome

1. Open `chrome://extensions`.
2. Turn on **Developer mode** (top right).
3. Click **Load unpacked** and select the `certitrack-extension/` folder.
4. Click the puzzle icon and pin **CertiTrack Monitor**.

If something misbehaves, click **service worker** on the extension's card to open its console.

---

## Try it end to end

### Pair the extension
1. Log in at <http://127.0.0.1:8000/login> as `student1@certitrack.test`.
2. Click **Generate pairing code**.
3. Open the extension popup, enter the code, and click **Pair**.

### Start monitoring
1. In a new tab, open <http://localhost:8000/demo-target>. This neutral demo page is not an exam system.
2. Open the popup. **Local Test Page (demo)** should be preselected and the tab detected as matching.
3. Tick the consent box and click **Start monitoring**. A red **ON** badge appears.

### Generate events

| Action | Expected event |
|---|---|
| Switch to another tab and back | Tab switched (away / return) |
| Ctrl+T | New tab opened |
| Click another app, then back to Chrome | Window focus lost / gained |
| Click the "example.com" link on the demo page | Navigation outside approved domain (host only) |
| Press Back | Navigation returned |
| Close the demo tab | Exam tab closed |

Click **End monitoring** in the popup. The student dashboard (`/student`) shows the session and its timeline.

### Review as an instructor
Log out, then log in as `instructor1@certitrack.test`. Open the session, flag an event, save a review, and click **Generate report**. As `student2`, opening that session URL should give a 404.

### Resilience checks
1. **Offline queue:** stop `php artisan serve` and switch tabs a few times. The popup shows Offline with events waiting. Restart the server and the events sync within about 30 seconds.
2. **Worker suspension:** in `chrome://serviceworker-internals`, stop the CertiTrack worker, then keep switching tabs. Events are still recorded.
3. **Interruption:** disable the extension for about 3 minutes mid-session. The instructor view shows the session Interrupted (heartbeat timeout). Re-enable it and it returns to Active with a "monitoring resumed" event.

---

## Add a new certification platform (no code changes)

Log in as the admin and go to **Platforms → Add platform**, then add its domains. Include sign-in (SSO) hosts too, or normal login redirects are recorded as "navigation outside approved domains". Extensions pick up the change from `/api/platforms/config`.

The seeded Cisco and Fortinet hostnames are **examples**. Verify the real hosts your exams use.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Dashboard shows 401 or 419 | `SANCTUM_STATEFUL_DOMAINS` in `backend/.env` must match the host:port in your address bar, then `php artisan config:clear` |
| `Class … not found` | `composer dump-autoload` |
| `could not find driver` | Install `php8.3-mysql` (or `php8.3-sqlite3` for tests) |
| Extension: "Cannot reach server" | Is `php artisan serve` running? Re-save the server address in the popup and accept the permission prompt |
| "Open the … page in this window first" | The active tab must be on one of the platform's approved domains |
| No heartbeat-timeout events | Run `php artisan schedule:work`, or open the instructor session list, which also triggers a check |
| Extension says signed out | Pair again. Unsent events are kept and sent afterwards |

## Privacy

**Collected during a session only:** tab switches, new tabs, exam-tab closure, navigation outside approved domains (host name only), window focus changes, connection status and heartbeats, with timestamps.

**Never collected:** screen or camera, microphone, keystrokes, clipboard, passwords, exam answers, page content, or other browsing history.

Use of this software should follow your institution's policies and applicable law, including the Philippine Data Privacy Act of 2012 (RA 10173). Obtain consent, publish a privacy notice, and consult your Data Protection Officer.

## Known limitations

- Chrome desktop on a single machine only. Other browsers, apps, and devices are invisible.
- Users can disable or uninstall the extension, and client records can be forged by someone holding a paired token.
- Window-focus events can produce false positives (OS dialogs, notifications, multi-window setups).
- Not yet implemented: email alerts, password reset, SSO login, and a UI for editing severity mappings.

See [`documentation/TECHNICAL-README.md`](documentation/TECHNICAL-README.md) for the full architecture, API reference with examples, database schema, test coverage, and complete limitations.

## License

MIT. See [`LICENSE`](LICENSE). Bootstrap and Chart.js are MIT-licensed and vendored in `backend/public/vendor/`.
