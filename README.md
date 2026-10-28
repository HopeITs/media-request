# Media Requests (standalone)

Requests to the Media team (design 001, photography 002, videography 003, HC_PACK files 000), as a small
Laravel 12 + Livewire 3 app. It is the same module that was written for the HR app, running on its own.

## See it on this computer

Double-click `start.bat`. It opens http://127.0.0.1:8000.
On the sign-in page, choose one of the sample accounts (local preview only):

| Account | Who |
|---|---|
| admin@example.test | administrator (also manages people) |
| rami@example.test | requester |
| lina@example.test | program manager of DISC and MP |
| maya@example.test | media manager |
| fadi@example.test | Media team: designer |
| sami@example.test | Media team: videographer |
| nour@example.test, omar@example.test | more requesters (added by the demo data) |

All sample passwords are `password`. To start again from the sample data, with about thirty requests at every stage:

```
.tools\php\php.exe artisan migrate:fresh --seed
.tools\php\php.exe artisan db:seed --class=DemoSeeder
```

Tests: `.tools\php\php.exe artisan test` (64 tests, including one that opens every link for every kind of person).

`.tools` holds a portable PHP 8.3 and Composer for this preview only. A server has its own PHP.

## Put it on a server

Any PHP 8.2+ host with Composer.

1. Copy the folder without `.tools`, `vendor` and `database/database.sqlite`.
2. `composer install --no-dev`, then copy `.env.example` to `.env`, run `php artisan key:generate`,
   set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, and the database (MySQL or SQLite).
3. `php artisan migrate --seed`. The seed adds the programs, the governorates and sample people.
   On a server it does not add sample requests. Change the sample passwords, or remove those people,
   on the People page.
4. The proofreader uses Google Gemini: set `GEMINI_API_KEY` in `.env` (aistudio.google.com/apikey).
   Without it, only punctuation, spacing and numerals are fixed. Its instructions are in `resources/prompts/proofread.md`.
5. Working days, the time each request type takes and the team's capacity are in `config/media-requests.php` (`work`).

## Move it into the HR app

The module files are the same in both apps, so a merge is mostly a copy:

- **Same in both** (copy as is): `app/Enums/MediaRequestStatus.php`, `app/Models/MediaRequest*.php`,
  `app/Support/MediaRequests/` (except `MediaTeam.php`), `app/Policies/MediaRequestPolicy.php`,
  `app/Services/MediaRequest*.php`, `app/Notifications/MediaRequestUpdated.php`, `app/Livewire/MediaRequests/`,
  `resources/views/livewire/media-requests/`, `resources/views/components/media/`, `config/media-requests.php`,
  `lang/*/media.php` (to `resources/lang`; the block marked "Standalone app only" can stay or go),
  the media migration and `tests/Feature/MediaRequestWorkflowTest.php`.
- **Different on purpose**:
  - `app/Support/MediaRequests/MediaTeam.php`: here it reads `employees.media_role` and `program_managers`.
    The HR version reads contracts (department CT, job-level grades). Keep the HR version.
  - Standalone only, not carried over: the people tables migration, `Employee`/`Program`/`City`/`User`
    models, the login, people and notifications pages, `config/access-control.php`, the layout.
- **Data**: requests can be exported with their history (the `media_requests`, `media_request_comments`,
  `activity_log` tables), with employee ids mapped to HR employee ids.

The HR copy of the module is in `D:\HR-app-Code` (see `docs/media-requests-handoff.md` there).
