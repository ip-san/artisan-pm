# Artisan PM

[日本語](README.md) | English

Artisan PM is a feature-parity reimplementation of [Redmine](https://www.redmine.org/) — the open-source project management tool — built on Laravel 13, Livewire 4 (via [Volt](https://livewire.laravel.com/docs/volt) single-file components), and PostgreSQL. The goal is to reproduce Redmine's actual behavior (down to specific business rules, not just the visible feature list) rather than to build a Redmine-inspired app from scratch.

Progress against Redmine's feature set is tracked in [`docs/parity-checklist.md`](docs/parity-checklist.md), which cross-references this codebase against a reference Redmine checkout, module by module, with notes on intentional deviations and scope decisions.

## What's implemented

Issue tracking (trackers, statuses, workflows, custom fields, relations, watchers), Gantt charts and calendars, wikis (with version history, redirects, a project-configurable start page, and macros), forums, news, time tracking, multiple SCM repositories (Git/SVN) with browsing/diff/annotate, saved queries, project hierarchies, role-based permissions, LDAP authentication, two-factor authentication, email notifications (including `@mention`), reactions (a single 👍 toggle on issues/comments/news/forum posts), a REST API, PDF export (issues/wiki/Gantt, with CJK font support), optional guest access to public projects (`login_required`), and a scheduled sweep that unwatches things a user has lost access to — see the checklist for the authoritative, per-feature status.

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.3 or later (developed on 8.5; `composer.lock` is resolved for 8.3), Laravel 13 |
| UI | Livewire 4 + Volt (single-file components), Tailwind CSS |
| Auth | Laravel Fortify (password, 2FA/TOTP), LDAP via `directorytree/ldaprecord-laravel` |
| Database | PostgreSQL (development); MySQL 8.0+ / MariaDB 10.3+ and SQLite are also supported |
| Search | Laravel Scout (database driver) |
| Attachments | `spatie/laravel-medialibrary` |
| Nested sets (project tree only — issues use a plain `parent_id` adjacency list, see `docs/design/domain-model.md`) | `kalnoy/nestedset` |
| PDF export | `barryvdh/laravel-dompdf` (bundled IPAGothic font for CJK text — see `resources/fonts/`) |
| Testing | Pest 4 |
| Static analysis | Larastan (PHPStan) |
| Local environment | Laravel Sail (Docker) |

## Getting started

This project runs entirely inside [Laravel Sail](https://laravel.com/docs/sail)'s Docker containers — every command below is prefixed with `vendor/bin/sail`.

```bash
# 1. Install PHP dependencies. `vendor/bin/sail` doesn't exist until
#    `vendor/` is populated, so on a fresh clone this first install has to
#    run through a throwaway Composer container — see Laravel's own
#    "Installing Sail Into Existing Applications" docs if the exact image
#    tag below is out of date for your PHP version:
#    https://laravel.com/docs/sail#installing-sail-into-existing-applications
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# 2. Copy the environment file — .env.example already points at Sail's
#    Postgres service (DB_HOST=pgsql, DB_PASSWORD=password), matching
#    compose.yaml's own POSTGRES_PASSWORD fallback, so no manual editing
#    is needed here for the default local setup.
cp .env.example .env

# 3. Start the stack (app, PostgreSQL, Redis, Mailpit)
vendor/bin/sail up -d

# 4. Generate the app key, run migrations, and seed base data —
#    DatabaseSeeder creates the built-in Anonymous/Non-member roles Redmine
#    itself ships with, a demo project, and an admin user you can log in
#    with immediately: admin@example.com / password
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed

# 5. Install JS dependencies and build frontend assets
vendor/bin/sail npm install
vendor/bin/sail npm run build
```

> **Note**: Tailwind CSS only emits the classes present in the templates at build time. Re-run `vendor/bin/sail npm run build` (or keep `vendor/bin/sail npm run dev` running) after changing views; a stale build leaves newly used classes unstyled.

Open the app with `vendor/bin/sail open`, or visit `http://localhost`. Outgoing mail during local development is caught by [Mailpit](https://github.com/axllent/mailpit) at `http://localhost:8025`.

### Running tests

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail bin phpstan analyse --no-progress
vendor/bin/sail bin pint --format agent
```

### Page audit (browser tests)

`tests/Browser/PageAuditTest.php` opens every HTML page in Chromium as an administrator and checks it with axe (critical/serious) plus layout checks for overflowing elements, clipped text and content hidden behind a sideways scrollbar. New pages are picked up automatically. Existing findings are recorded in `tests/Browser/baselines/page-audit.json`; only new findings fail.

Chromium has to be set up in the container once (and again after `sail build` rebuilds the image):

```bash
docker compose exec -u root laravel.test npx playwright install-deps chromium
vendor/bin/sail npx playwright install chromium
```

```bash
vendor/bin/sail artisan test --compact tests/Browser/PageAuditTest.php
# after fixing pages, refresh the baseline
vendor/bin/sail exec -e UPDATE_PAGE_AUDIT_BASELINE=1 laravel.test php artisan test --compact tests/Browser/PageAuditTest.php
```

Pages whose route parameters the audit cannot fill (repository contents, attachments, …) are listed under `unmapped` in the baseline. A new page landing there fails the test until you add a fixture in `PageAuditTest.php` or record it in the baseline.

### Repository storage

SCM repositories the app browses/syncs must live under the directory configured by `SCM_REPOSITORIES_ROOT` (see `config/scm.php`) — the app shells out to `git`/`svn` binaries against paths under that root; it does not manage repository creation itself.

## Deploying to shared hosting

The app runs on rental hosting such as Xserver, さくらのレンタルサーバ or ConoHa WING (PHP-FPM, MySQL 8.0+ / MariaDB 10.3+, cron, no resident processes). The settings each step refers to are explained in [`.env.example`](.env.example).

1. **Build locally.** Hosts rarely have Node.js, and `public/build` is not committed: run `npm ci && npm run build`, and `composer install --no-dev --optimize-autoloader` with PHP 8.3 or later (the lock file is resolved for 8.3, so it installs on any 8.3+ host) (add `--ignore-platform-req=ext-ldap` if the host has no ldap extension and you don't use LDAP).
2. **Upload** the whole app, including `vendor/` and `public/build/`, to a directory **outside** the web root, e.g. `~/artisan-pm` (not into `public_html`).
3. **Point the web root at `public/`.** The web root must be the app's `public/` directory, never the app directory itself (that would serve `.env`). Hosts fix the document root to `public_html` (or `~/<domain>/public_html`), so either
   - replace it with a symlink (preferred): `mv public_html public_html.orig && ln -s ~/artisan-pm/public public_html`, or
   - if symlinks aren't allowed, put the app inside `public_html` and add a `public_html/.htaccess` that hands every request to `public/` (the rewrite also means `.env` and the rest of the app are never served directly):
     ```apache
     <IfModule mod_rewrite.c>
         RewriteEngine On
         RewriteRule ^(.*)$ public/$1 [L]
     </IfModule>
     ```
4. **Configure `.env`** (copy `.env.example`): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, the database (`DB_CONNECTION=mysql` or `mariadb`, `DB_COLLATION` — choose it before migrating), `QUEUE_CONNECTION=database`, `TRUSTED_PROXIES=*` when the host terminates HTTPS in front of PHP, the mail settings, and `SCHEDULE_TIMEZONE=Asia/Tokyo` if the daily jobs should run at Japanese midnight.
5. **Initialise** over SSH (use the host's full path to PHP 8.3 or later, e.g. `/usr/bin/php8.3`):
   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan passport:keys          # keys for the REST API's OAuth tokens
   php artisan db:seed --force        # default roles/trackers/statuses, a demo project and admin@example.com / password
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   Sign in as `admin@example.com` right away, change its e-mail and password, and delete the demo project if you don't want it. Run `php artisan config:cache` again after every `.env` change.
6. **Permissions:** `storage/` and `bootstrap/cache/` must be writable by PHP (`chmod -R u+rwX storage bootstrap/cache`; hosts run PHP as your own user, so 755/644 is enough). Attachments, logs, the dompdf font cache and Git/SVN repositories (`SCM_REPOSITORIES_ROOT`) live under `storage/`.
7. **Cron:** one entry, every minute if the control panel allows it (otherwise `*/5`, starting at :00 — see `.env.example` on why):
   ```
   * * * * * cd /home/you/artisan-pm && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
   ```
   It runs the scheduled jobs (incoming mail, repository autofetch, housekeeping) and, with `QUEUE_CONNECTION=database`, also works off the queued notification mail, webhooks and CSV imports — no second entry or resident worker is needed.
8. **Check** 管理 → 情報 (admin information): it lists the PHP extensions, writable directories and the git/svn commands the app found.

## Architecture

The layered overview, full domain model, authorization model, request lifecycle, issue workflow, and notification/job pipeline are documented in detail — with Mermaid diagrams — in [`docs/design/`](docs/design/README.md). Start there for a whole-system view; this README stays focused on getting the app running and the handful of design decisions below.

## Notable design decisions

A few patterns recur across the codebase and are worth knowing before making changes:

- **Single point of truth for model invariants.** Behavior Redmine enforces at the model layer (e.g. "a project's first repository is automatically its default", "a repository's identifier, once set, can never change") is implemented via Eloquent model hooks (`saving`/`created`/`updated`) rather than scattered across every write path that touches the attribute.
- **Authorization is centralized.** `App\Support\Authorization\AuthorizationService` mirrors Redmine's per-project, per-role permission resolution (including the built-in `Anonymous`/`Non-member` roles); Policies call into it rather than re-implementing role logic.
- **Notification recipients merge tiers, then filter once.** When a feature (issue mail, wiki mail, `@mention`) needs to notify several independent pools of users (project members by role, watchers, explicitly mentioned users), the pools are unioned and deduplicated first, then a single visibility filter is applied — avoiding both double-filtering and double-sending.
- **Multi-repository routing uses named route pairs, not optional segments.** Laravel route parameters are only genuinely optional as the last URL segment; a repository identifier needed mid-path, so each repository action registers both an identifier-less route and a `.repo`-suffixed sibling pointing at the same component.
- **Redmine parity over Redmine literalism.** Where Redmine's implementation is Ruby/ActiveRecord-specific (e.g. certain validation quirks) and reproducing it exactly would add little value, the deviation is documented in `docs/parity-checklist.md` rather than silently diverging.
- **A route name's URL can outlive its original meaning.** The wiki module's `wiki.index` route kept its name and URL (`/projects/{project}/wiki`) when its behavior changed from "show the page list" to "redirect to the wiki's start page" (matching Redmine's own URL scheme) — the page list moved to a new `wiki.pages` route instead. A Volt component can redirect from `mount()` on the initial page load just like from an action, which is what makes this kind of behavior swap possible without a plain Controller.
- **Verify against Redmine's source before adding a feature, not just its checklist description.** Several items the checklist once listed as "not yet built" turned out, on rereading Redmine's actual code, to be features Redmine itself doesn't have (a subtask reorder UI, Gantt drag-and-drop rescheduling, `@mention` on news/forum posts) — building them would have been *scope creep*, not parity. `docs/parity-checklist.md` documents each retraction alongside the original (wrong) claim, so the reasoning survives for the next person who reads that entry.

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

Artisan PM is an unofficial, independent reimplementation. It is not affiliated with or endorsed by the Redmine project and contains no Redmine source code. The "Redmine" name belongs to the Redmine project.
