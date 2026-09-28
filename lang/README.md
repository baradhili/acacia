# Locales

`en` is the base locale: every translation key must exist there — the
framework's own files (auth, pagination, passwords, validation) plus
any app keys land in `lang/en/`. `en_AU` holds only the keys that
*differ* in Australian English (spelling, formats); per-key fallback
means anything missing here resolves from `en`, so never copy whole
files into an override — a stale override is the bug, an absent one is
fine.

Adding a locale: create `lang/<locale>/` (underscore form — `en_AU`,
not `en-AU`) with only the differing keys, set `APP_LOCALE`, and keep
`APP_FALLBACK_LOCALE=en`.

Conventions for app strings live in [`AGENTS.md`](../AGENTS.md) — new
or edited user-facing strings go through the translator
(`__('...')` / `trans_choice`); legacy hard-coded strings are not
bulk-converted, only converted as their screens are touched.
