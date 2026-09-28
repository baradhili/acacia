# en_AU — Australian English overrides

Deliberately empty. This directory exists so Australian differences
are a drop-away override, never a fork: add a file only for a group
that actually differs (e.g. `validation.php` with just the custom
attribute names), and inside it only the keys whose wording changes.
Missing keys and files fall back to `lang/en/` per key, so a partial
override is complete by construction.

The directory takes effect when `APP_LOCALE=en_AU` (underscore form);
with the default `APP_LOCALE=en` it simply sits ready.
