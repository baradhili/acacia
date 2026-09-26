# AGENTS.md

Guidance for AI coding agents working in this repository. Read this and
[`docs/modules.md`](docs/modules.md) before touching module code.

## What this is

**Acacia** — a Laravel 13 / PHP 8.3 ERP for Australian professional-services
firms: cash-basis accounting over an Eloquent IFRS double-entry ledger, time
tracking, invoicing, Wise bank reconciliation, BAS-ready GST. Blade +
Tailwind + Alpine frontend; Spatie roles (`admin`, `accountant`, `staff`);
core app under `app/` plus feature modules under `Modules/`
(nwidart/laravel-modules v13): Payroll, Reconciliation, Shares, Crm, Resumes.

## Commands

```bash
php artisan test                                  # full suite (~100s, 900+ tests)
php artisan test Modules/Payroll/Tests/PayrollTest.php   # one class
php artisan test --filter=test_name               # one test
vendor/bin/pint <paths>                           # style — run before committing
php artisan module:list                           # module status
php artisan module:migrate Resumes                # run one module's migrations
```

- The husky **pre-commit** hook runs Pint via lint-staged on staged PHP
  files — Pint your changes or the commit is rejected. Never bypass with
  `--no-verify`; fix what the hook asks for.
- The husky **commit-msg** hook runs commitlint (`@commitlint/config-conventional`):
  `type(scope): subject`, lower-case type from the conventional enum, no
  trailing period. Validate before committing:
  `printf '%s' "your message" | npx commitlint`.

## Repo map

- `app/` — core: `Http/Controllers`, `Models`, `Services`
  (`IfrsPosting` resolves the IFRS entity), `Support/Nav` (navigation
  registry, fed by `App\Nav\CoreNav` from `AppServiceProvider`), `Widgets`.
- `Modules/<Name>/` — mini-packages: `module.json`, own `composer.json`
  (PSR-4 `Modules\<Name>\` merged by the wikimedia composer-merge-plugin),
  provider, `routes/web.php`, views, migrations, config, `Tests/`.
- `routes/web.php` — core routes in `auth` + role groups.
- `tests/Feature` and `Modules/*/Tests` — the latter runs in phpunit's
  "Modules" suite; module tests extend `Tests\TestCase` like feature tests.
- `docs/modules.md` — the canonical module authoring doc.

## Hard rules (the expensive lessons)

1. **Module routes must declare the `web` group explicitly**
   (`Route::middleware(['web', 'auth', ...])`). Provider-loaded routes
   inherit no group — without `SubstituteBindings` controllers receive
   *empty models*, and sessions/CSRF drop.
2. **Resource route parameters must match the controller's variable.**
   `Route::resource('payroll-employees', ...)` generates `{payroll_employee}`;
   a controller typing `Employee $employee` then gets an *empty model* with
   no error (edit renders the create form, update inserts duplicates).
   Use `->parameters(['payroll-employees' => 'employee'])`.
3. **HTML forms cannot PUT.** A `method="POST"` form aimed at an update
   route needs `@method('PUT')` — and only on the edit variant, or the
   create POST gets spoofed into a 405. Regression-test updates with a
   spoofed `$this->post(url, ['_method' => 'PUT', ...])`, not `->put()`.
4. **New module wiring:** `module.json` + module `composer.json` + an entry
   in `modules_statuses.json`, then `composer dump-autoload` (the
   merge-plugin picks up the new PSR-4 root). The provider's existence must
   precede enabling the module in `modules_statuses.json`.
5. **Modules never edit shell views.** Navigation is registered from the
   provider's `boot()` via `App\Support\Nav` (`addSidebar`, `addTopbar`,
   `addTopbarChild('DropdownLabel', $item)` — e.g. Resumes slots into the
   Employees dropdown Payroll owns). Cross-module view edits degrade via
   `\Route::has(...)` guards; cross-module class deps via `class_exists`.
6. **Tests:** extend `Tests\TestCase` (RefreshDatabase, sqlite under
   `/tmp`). Create roles **before** seeding `IFRSSeeder` (it assigns
   `admin`). `IFRSSeeder` also calls `Auth::login($admin)` — call
   `Auth::logout()` before any guest-behaviour assertion. Resolve the
   entity with `IfrsPosting::resolveEntity()`; factories live in
   `database/factories`.
7. **Module migrations run in isolated processes** (`ModuleManager::runArtisan`)
   — in-process `Artisan::call` shares the caller's DB transaction, which
   sqlite refuses.
8. **Files:** the polymorphic `Document` attachment system stores on the
   **public** disk (that is what the backup runbook archives). Anything
   sensitive or regenerable belongs in the DB instead (the Resumes module
   stores `parsed_data` only and regenerates every export).
9. **PDF export (Resumes)** compiles LaTeX with LuaLaTeX via Symfony
   Process — needs `lualatex` on the host, gated by `config('resumes.latex.*')`;
   tests skip when absent. DOCX uses PHPWord (pure PHP).

## Docs to keep updated

- `docs/modules.md` when modules are added or their registration patterns
  change.
- `README.md` features/stack when user-visible capabilities land.
- `docs/runbooks/backup-restore.md` when new data lands outside the DB or
  the public disk.
