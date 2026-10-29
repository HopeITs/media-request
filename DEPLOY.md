# Putting Media Requests on a server (on its own, not inside the HR app)

A Laravel 12 + Livewire 3 app. No build step (no Node/npm) and no background worker: a web server with PHP,
one database, and **one cron line** (for the deadline reminders) is enough.

## 1. What the server needs

- **PHP 8.2 or newer** (8.3 tested), with the extensions: `mbstring`, `xml`/`dom`, `zip`, `fileinfo`,
  `curl`, `openssl`, `pdo` with the driver for the database (`pdo_mysql` or `pdo_sqlite`), `intl` (recommended).
- **Composer 2**.
- **A database**: MySQL 8 / MariaDB 10.6+ (recommended for a server), or SQLite (simplest, one file).
- **A web server** (Nginx or Apache) whose document root is the app's **`public/`** folder, never the app folder itself.
- **HTTPS** (a certificate, e.g. Let's Encrypt).
- **Outgoing HTTPS** to `generativelanguage.googleapis.com`, only if the proofreader's AI part is wanted.

## 2. First install

```bash
git clone git@github.com:HopeITs/media_request.git media-requests
cd media-requests
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` (the values that matter):

```ini
APP_NAME="Media Requests"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://media.example.org        # the real address, with https
APP_LOCALE=en                            # en or ar: the language people see first

DB_CONNECTION=mysql                      # or sqlite (then remove the DB_* lines below)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=media_requests
DB_USERNAME=media_requests
DB_PASSWORD=change-me

SESSION_SECURE_COOKIE=true               # cookies only over https
LOG_LEVEL=warning

# Proofreader (optional). Empty = only punctuation, spacing and numerals are fixed.
GEMINI_API_KEY=
GEMINI_MODEL=gemini-flash-lite-latest,gemini-flash-latest
```

Then:

```bash
php artisan migrate --force              # creates the tables
php artisan db:seed --force              # programs and governorates only (no sample people in production)
php artisan media:admin --email=fares.badawi@hcsyria.org --name="Fares Badawi" --media-manager
                                         # the main administrator, also the media manager;
                                         # asks for his Arabic name and a password, never shows it
php artisan media:team storage/app/private/media-team.csv --dry-run   # check the team list first
php artisan media:team storage/app/private/media-team.csv             # then load it
php artisan optimize                     # caches config, routes and views
```

The Media team list (`media-team.csv`) holds people's emails, so it is **not in git**. Fares sends it to IT
privately; put it in `storage/app/private/`. One row per person:

```
email,name_en,name_ar,city,media_role,position_title,work_hours
someone@hcsyria.org,Some One,,HMS,member,"Photographer / Videographer (on need) / Local print",10:00-14:00
```

- `city`: governorate code (ALP, HMS, DMS, LAT, …). Shoots go to the team of the place.
- `media_role`: `manager` for the media manager and his vice, else `member`.
- `position_title`: skills separated by `/`; `(on need)` marks a second skill.
- `work_hours`: empty for full time.

New people get a password, printed once at the end; pass each one on privately. Running it again
updates people (same email) and keeps their passwords.

**The old Google sheet (once, after `media:team`).** The requests from the old "MEDIA Request (Responses)"
sheet come in as history: dates, who asked, program, place, what, who did it, how it ended. Export the sheet
as CSV and put it, with the name map `sheet-map.json` that Fares sends (it joins names written several ways),
in `storage/app/private/`:

```bash
php artisan media:import-sheet "storage/app/private/MEDIA Request (Responses).csv" --dry-run
php artisan media:import-sheet "storage/app/private/MEDIA Request (Responses).csv"
```

Done requests come in as delivered (the sheet had no delivery dates, so they are left out of on-time
statistics), rejected as rejected, open ones from the last 45 days as open, older open ones not at all.
Publishing rows are kept aside in `storage/app/private/sheet-publishing.csv`. Running it again adds nothing twice.
Do it before people start using the app, so the request numbers stay in date order.

Once the file is in place, the list also works by itself: anyone on it whom Fares adds on the People page
(even with quick add as "Team"), or who signs in while not yet on the team, takes their place on the team
right away (role, specialties, city, hours). `media:admin` for Fares reads his row too. Changes made later
on the People page are kept; to apply a changed list to everyone, run `php artisan media:team` again.

Folders the web server user (e.g. `www-data`) must be able to write:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

**Fares Badawi** (fares.badawi@hcsyria.org) is the main administrator and the media manager. He signs in,
then adds people on the **People** page. To try the app quickly, **Add a person to try the app** takes an
email, a name and one role (Media team with a specialty, Team who asks for work, or Program manager with
its programs) and makes a password shown once, to pass on.

For real accounts, the full form on the same page sets each person's role, whether they
are on the Media team (member or manager), their position title (the specialty is read from it:
"Communication team member / Photographer", "… / Videographer", "… / Designer"), and which
programs they manage.

## 3. The cron line (deadline reminders)

Every working morning at 08:30 (Damascus) the app reminds whoever holds a request one working day before its
needed-by date and on the day, and every day after it is late (also the assignee and the media manager).
Laravel's scheduler does this; it needs one line in the web server user's crontab (`crontab -u www-data -e`):

```cron
* * * * * cd /var/www/media-requests && php artisan schedule:run >> /dev/null 2>&1
```

To try it by hand: `php artisan media:remind` (safe to run again: each request is reminded at most once a day).

**Email.** When a request is delivered, the requester and their program manager get an email with the details
and a button that opens it (files are only shared in the app). Set the organisation's mail server in `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com        # or the server IT uses
MAIL_PORT=587
MAIL_USERNAME=media-requests@hcsyria.org
MAIL_PASSWORD=...                   # set by IT, never in git
MAIL_FROM_ADDRESS=media-requests@hcsyria.org
MAIL_FROM_NAME="Hope Center Media"
```

Without it (`MAIL_MAILER=log`) the emails only go to the log; delivering still works.

## 4. Web server

**Nginx** (the important lines):

```nginx
server {
    server_name media.example.org;
    root /var/www/media-requests/public;
    index index.php;

    client_max_body_size 60M;            # uploads are up to 50 MB per file

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

PHP (`php.ini`): `upload_max_filesize = 50M`, `post_max_size = 60M`.

**Apache**: point the site's `DocumentRoot` at `public/` and allow `.htaccess` (`AllowOverride All`);
`public/.htaccess` is included.

## 5. Updating to a new version

```bash
cd /var/www/media-requests
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan up
```

## 6. Backups

Two things hold all the data:

- **the database** (requests, history, comments, notifications, people, home screens, proofreading history);
- **`storage/app/private/`**: the files people attach to requests.

Back up both, every day. `.env` holds the app key: keep a copy somewhere safe, because without it
sessions and anything encrypted cannot be read.

## 7. HC_PACK search

The HC_PACK search reads a list of the pack's files from `resources/data/hc-pack.json` (shipped in the
repository). When the pack changes, rebuild the list on a machine that has the pack folder and commit it:

```bash
php artisan media:pack-index "D:\000_HC_PACK_012025"
```

The files themselves are not on the server: the team sends them, the app records who asked and when.

## 8. Settings people usually change (`config/media-requests.php`)

- `work`: working days and hours, the days each request type takes, the team's capacity.
- `specialties`: which request types belong to which specialty, and the words in position titles.
- `generator.url`: the HC Generator address (or `HC_GENERATOR_URL` in `.env`).
- `proofread.daily_limit`: full AI checks per person per day.
- Proofreader instructions: `resources/prompts/proofread.md`.

After changing a config file on the server, run `php artisan optimize` again.

## 9. Good to know

- Notifications are inside the app (the bell); no email is sent, so no mail server is needed.
- The sample accounts and the "sample accounts" list on the sign-in page exist only on a local copy
  (`APP_ENV=local`), never on a server.
- `.tools/` (portable PHP for the Windows preview) and `.env` are not in the repository.
- Tests: `php artisan test` (needs the dev dependencies: `composer install` without `--no-dev`).
