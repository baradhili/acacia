# Todo list

Done items are archived in [CHANGELOG.md](CHANGELOG.md) — cleared from
here once their changelog entry lands. Ordered by priority.

- [ ] WAIT! - Lets think about client portal need for future - Remove the "client" role concept from the app — audit RoleSeeder's roles list, any `client` role checks/assignments and portal-client user handling; user accounts should be staff-side only (admin/accountant/staff). (Sep 2026, requested while making all users show as payroll payees — the payroll UserObserver currently skips client-role users defensively, so this unpays that workaround.)

- [ ] Bring the remaining resource_mgr concepts into acacia — services (Sep 2026) and the skills library (Sep 2026, Modules/Skills) are across; the allocations/resourcing concepts are what's left. Review https://github.com/baradhili/resource_mgr for those.

- [ ] Ability to have multiple un-related company entities with separate everything against same user - aka same user can be admin of more than one org.

- [ ] Modules per company (builds on the multi-entity item above).

- [ ] cucumber tests via behat — plan reviewed and refreshed 2026-09-28: .zcode/plans/behat-migration-plan.md (scope renewal with maintainer is the first step; suite has grown to 55 feature files + the Modules suite since the original approval).

- [ ] Backfill class docblocks on the ~83 pre-convention core classes (app/Models, app/Http/Controllers, widgets, console commands, remaining services — found by the Sep 2026 docblock audit). Write them as code is touched per AGENTS.md, or as one dedicated documentation pass; state invariants, not narrations.

- [ ] Freeform dashboard resize: drag handles/heights beyond the column-span cycling the layout management ships (Sep 2026) — gridstack is already in package.json if this lands.

- [ ] property rental management module - the company is a landlord

- [ ] CRM follow-ups from the Sep 2026 module: email/calendar integrations, per-owner targets.

- [ ] Static security testing: look at `laravel-security/pentest-scanner` or `baspa/larascan`

- [ ] Finish the modularisation arc: Create "Proposals"  module and extract Estimates into it. Add todo to flesh out proposal management.[GitHub - ICodingStack/ProposalForge: ProposalForge — Elegant &amp; Intelligent Proposal Generator Create beautiful, professional proposals and quotes in minutes with smart builder, pricing packages, real-time preview, and one-click PDF export. 100% free • Open source • Works offline · GitHub](https://github.com/ICodingStack/ProposalForge) or  [GitHub - Old-G/propsly · GitHub](https://github.com/Old-G/propsly)

- [ ] https://docs.markwhen.com/ might be good to integrate into projects section - but only once we have a client module and a real project module

- [ ] active security testing harness - salvatorecervone/laravel-pentest if they upgrade to 13
