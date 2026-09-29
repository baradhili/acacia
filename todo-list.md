# Todo list

Done items are archived in [CHANGELOG.md](CHANGELOG.md) — cleared from
here once their changelog entry lands. Ordered by priority.

- [ ] WAIT! - Lets think about client portal need for future - Remove the "client" role concept from the app — audit RoleSeeder's roles list, any `client` role checks/assignments and portal-client user handling; user accounts should be staff-side only (admin/accountant/staff). (Sep 2026, requested while making all users show as payroll payees — the payroll UserObserver currently skips client-role users defensively, so this unpays that workaround.)

- [ ] Bring the remaining resource_mgr concepts into acacia — services (Sep 2026) and the skills library (Sep 2026, Modules/Skills) are across; the allocations/resourcing concepts are what's left. Review https://github.com/baradhili/resource_mgr for those.

- [ ] Ability to have multiple un-related company entities with separate everything on same system - do we do this by user associations or by landing domain? Justify why it cannot be one user to one or more entities?

- [ ] Modules per company (builds on the multi-entity item above).

- [ ] cucumber tests via behat — plan reviewed and refreshed 2026-09-28: .zcode/plans/behat-migration-plan.md (scope renewal with maintainer is the first step; suite has grown to 55 feature files + the Modules suite since the original approval).

- [ ] Tailwind 3 → 4 migration — Dependabot's tailwindcss 4 PR (#53, Sep 2026) was closed: v4 drops the PostCSS plugin and `@tailwind` directives the build still uses. The migration is CSS-first: wire `@tailwindcss/vite` (already in package.json) into vite.config.js, replace `@tailwind base/components/utilities` with `@import 'tailwindcss'`, port tailwind.config.js (Figtree font stack, the dashboard safelist the widget-manager emits at runtime) to `@theme`/`@source`, re-check `@tailwindcss/forms`, then visual QA — v4 preflight and default-border changes are not covered by the test suite.

- [ ] Backfill class docblocks on the ~83 pre-convention core classes (app/Models, app/Http/Controllers, widgets, console commands, remaining services — found by the Sep 2026 docblock audit). Write them as code is touched per AGENTS.md, or as one dedicated documentation pass; state invariants, not narrations.

- [ ] Freeform dashboard resize: drag handles/heights beyond the column-span cycling the layout management ships (Sep 2026) — gridstack is already in package.json if this lands.

- [ ] property rental management module - the company is a landlord

- [ ] CRM follow-ups from the Sep 2026 module: email/calendar integrations, per-owner targets.

- [ ] Proposal management — flesh the Proposals module (Sep 2026 extraction of estimates) into a full proposal builder. Reference products: [ProposalForge](https://github.com/ICodingStack/ProposalForge) (smart builder, pricing packages, live preview, PDF export) and [propsly](https://github.com/Old-G/propsly) (block editor, e-signature, tracking). Queued pieces, roughly in build order:
    - [ ] Structured proposal documents on top of the estimate lines: cover/intro, scope, deliverables and timeline sections; content variables (`{{client.name}}`, `{{estimate.total}}`, …) resolved at render time (propsly's variable model).
    - [ ] Good/Better/Best pricing packages: present the existing optional lines as tiered packages the client chooses between, with a recommended tier (ProposalForge's Basic/Standard/Premium); the chosen package drives the invoice conversion.
    - [ ] Live split preview while editing: a client-facing preview pane updating in real time beside the form (ProposalForge's Live Split Preview; Alpine on the existing create/edit forms).
    - [ ] Branded PDF export: one-click, print-ready, logo + accent colour (ProposalForge's 2×-DPI html2canvas/jsPDF approach vs the Resumes LuaLaTeX precedent).
    - [ ] Client acceptance portal: tokenised share link where the client reads the proposal, toggles optional lines with totals recalculating, and accepts with a typed/drawn e-signature that locks the document (propsly); the signature feeds the existing accepted state.
    - [ ] Engagement tracking on sent proposals: open/view notifications and per-section read analytics (propsly's tracking and engagement scores).

- [ ] Larascan deploy-time residue — the scan is baseline-free as of Sep 2026 (every code finding fixed; the FK mass-assignment sweep made ownership explicit across 29 models). What's left only resolves at deploy: php.ini posture (`allow_url_fopen=Off`, `expose_php=Off`), and the localhost env infos (APP_URL, session-secure) clear with the production .env. The `verification.notice` signed-route finding is a known scanner false positive — revisit if larascan ever fixes its route heuristic.

- [ ] https://docs.markwhen.com/ might be good to integrate into projects section - but only once we have a client module and a real project module

- [ ] active security testing harness - salvatorecervone/laravel-pentest if they upgrade to 13
