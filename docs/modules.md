# Modules

The app is modularised with [nwidart/laravel-modules](https://nwidart.github.io/laravel-modules/)
(v13): each feature module is a mini-Laravel package under `Modules/<Name>/`,
discovered by its `module.json` and booted through its service provider.
Akaunting's architecture is the reference — their `modules/` directory is a
per-deployment drop-zone and the core exposes the registration contracts apps
hook into.

## Shipped modules

| Module | Owns | Notes |
|---|---|---|
| `Payroll` | Pay runs, employees, PAYG withholding + super (NAT 1004), PSI assessment | config exposed as `config('payroll.*')` |
| `Reconciliation` | Bank statement import, matching + learning, counterparty rules | bank tables live in the core squashed schema |
| `Shares` | Shareholding ledger, franking account, dividend runs | company identity (CompanyShareholder/ShareClass) stays core |
| `Crm` | Leads through the sales funnel, activity history, client conversion, monthly sales targets | first module authored in place (its migrations ship in the module) |
| `Resumes` | Employee resumes on the modified JSON Resume schema: upload against a payroll payee, keyword tailoring, PDF (LuaLaTeX) / DOCX (PHPWord) / JSON / LaTeX exports | depends on Payroll (employees FK); schema vendored in the module; view/export open to all signed-in staff, upload/delete limited to admins and the payee's linked user (`employees.user_id`) |

All five carry `"core": true` in their manifest — they ship with the app and
cannot be uninstalled from the GUI (only disabled). `modules_statuses.json`
is committed so fresh clones boot with them enabled; disabling from the GUI
rewrites it locally, which is per-deployment state.

## Module anatomy

```
Modules/Payroll/
├── module.json          # name, alias, version, providers, core flag
├── composer.json        # PSR-4 Modules\Payroll\ → "" (merged by the
│                        #   wikimedia composer-merge-plugin — root
│                        #   composer.json extra.merge-plugin includes
│                        #   Modules/*/composer.json)
├── Providers/PayrollServiceProvider.php
├── routes/web.php
├── Http/Controllers/  Models/  Services/  Mail/  Console/
├── resources/views/   # feature-named dirs (payroll/, psi/)
├── database/migrations/
├── config/config.php  # merges as config('payroll.*')
└── Tests/             # runs in the phpunit "Modules" suite
```

## The registration contracts

Modules never edit shell views. From the service provider's `boot()`:

- **Nav** — `App\Support\Nav`: `addSidebar(items)`, `addTopbar(items)` and
  `addTopbarChild('DropdownLabel', $item)` to slot one item into another
  module's dropdown (the child's active-route patterns join the
  dropdown's) — e.g. PSI Assessment under Setup, or Resumes under the
  Employees section Payroll owns. Items carry `type`
  (link/heading/divider/dropdown), `position`, `roles`, `active` route
  patterns, `icon` (inner SVG) and optional `add` shortcut routes. A
  dropdown with no `roles` of its own can mix gated and open children
  (Employees: the admin/accountant payee master data plus the
  staff-visible Resumes item) — children filter individually and an
  emptied dropdown drops out.
- **Dashboard widgets** — `App\Support\Widgets::add(class, span)`; the
  dashboard grid renders the registry (ids = class basenames, which the
  drag-order preferences persist).

## Hard-won rules for new modules

1. **Module routes must declare the `web` group explicitly**
   (`Route::middleware(['web', 'auth', ...])`). Provider-loaded routes
   inherit no group — without `SubstituteBindings` controllers receive
   *empty models*, and sessions/CSRF drop.
2. **Watch same-namespace references when moving code.** A class that
   referenced `PeriodLockService` unqualified inside `App\Services` needs
   `use App\Services\PeriodLockService;` once it lives in a module
   namespace.
3. **Module migrations run in isolated processes** (see
   `ModuleManager::runArtisan`) — an in-process `Artisan::call` shares the
   caller's DB transaction, which sqlite refuses.
4. **Factories stay in `database/factories`**; a moved model pins them via
   `newFactory()` because the convention resolves module models to a
   non-existent module factory namespace.
5. **Core → module seams degrade via `class_exists`** (the BAS W1/W2 labels
   on Payroll; the franking hook, tax-report balances and opening-share
   backfill on Shares). Keep this pattern for new soft dependencies.
6. **Resource route parameters must match the controller's variable.**
   `Route::resource('payroll-employees', ...)` generates `{payroll_employee}`;
   a controller typing `Employee $employee` silently receives an *empty*
   model — no 404, just wrong behaviour (edit renders the create form,
   update inserts duplicates). Declare
   `->parameters(['payroll-employees' => 'employee'])`.
7. **A `method="POST"` form aimed at a PUT route needs `@method('PUT')`**
   — on the edit variant only, or the create POST gets spoofed into a
   405. Cover it in tests with a spoofed
   `$this->post($url, ['_method' => 'PUT', ...])`, which exercises the
   real browser path.

## Installing and managing modules

The admin **Modules** screen (user menu → Admin → Modules) lists installed
modules with enable/disable, update for git-installed ones, and uninstall
(rolls back the module's migrations — dropping its tables — and deletes the
directory; refused for `core: true`).

**Install from GitHub**: paste an `https://github.com/…` repository URL. The
repo is cloned to a temp dir, its `module.json` validated (name, version,
providers; the module root or one `Modules/<Name>/` level deep), then moved
into `Modules/`, autoloaded, migrated and enabled — a failed install leaves
nothing behind. Local paths under the project are accepted in non-production
environments (used by the hermetic `ModulesAdminTest` fixture).

CLI equivalents: `module:list`, `module:enable/disable <Name>`,
`module:migrate`, `module:migrate-rollback`.

## Splitting a module into its own repository

History is preserved with a subtree split:

```bash
git subtree split -P Modules/Payroll -b split/erp-payroll
git push git@github.com:you/erp-payroll.git split/erp-payroll:main
```

Then uninstall the shipped copy and install from the new repository URL on
the Modules screen — the module becomes a git checkout (Update button
appears, `module:update` fast-forwards it).
