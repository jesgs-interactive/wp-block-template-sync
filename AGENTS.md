# AGENTS.md

## Scope
- This is a local-development WordPress plugin: it syncs Site Editor state back into the active block theme’s files. Start at `wp-block-template-sync.php`.
- Bootstrap is hook-driven: `plugins_loaded` instantiates `TemplateSync`, `GlobalStylesSync`, and `UpdateChecker`; `includes/cli-global-styles.php` conditionally registers a WP-CLI command.

## Big picture
- `includes/class-template-sync.php` handles `wp_template` and `wp_template_part` saves from REST hooks and writes HTML into `{theme}/templates/*.html` or `{theme}/parts/*.html`.
- `includes/class-global-styles-sync.php` is the main subsystem. Its constructor wires save hooks, legacy option fallback, theme-switch sync, admin settings/UI, and AJAX endpoints.
- Global styles flow is asymmetric by design:
  - DB/Editor -> theme files: `GlobalStylesSync::sync_to_theme()` deep-merges only `settings` and `styles` into `theme.json`, preserves other keys/order, backs up files into `{theme}/.global-styles-backups/`, and optionally rewrites `style.css`.
  - theme files -> DB: admin page + `admin/js/wbts-sync.js` preview/commit `theme.json` changes into `wp_global_styles` via `wp_ajax_wbts_sync_preview`, `wp_ajax_wbts_sync_commit`, and `admin_post_wbts_sync_theme_to_db`.
- `includes/class-update-checker.php` is a small GitHub Releases integration, not a package dependency: it reads `WP_BLOCK_TEMPLATE_SYNC_GITHUB_REPO`, caches latest release JSON in a transient for 5 minutes, and populates plugin update hooks.

## Files to read first
- `wp-block-template-sync.php` — plugin constants, bootstrap order, version placeholder.
- `includes/class-template-sync.php` — capability/theme ownership guards and file-writing pattern.
- `includes/class-global-styles-sync.php` — nearly all business logic lives here.
- `admin/js/wbts-sync.js` — admin diff/commit UX and AJAX contract.
- `tests/Unit/*.php` — best place to confirm intended behavior and edge cases.

## Repo-specific conventions
- Treat `GlobalStylesSync` as side-effectful on construction: hooks are registered in `__construct()`, unlike `TemplateSync`, which requires explicit `init()`.
- Keep template writes constrained to the active theme. `TemplateSync::sync_post()` rejects mismatched `wp_theme` taxonomy terms and rejects traversal slugs like `..`, `/`, or `\\`.
- Preserve `theme.json` structure. `sync_to_theme()` intentionally updates only `settings` and `styles`; do not replace the full decoded array.
- Preserve clean diffs. `deep_merge()` keeps associative key order, while sequential arrays like palettes/presets are replaced wholesale.
- When touching `style.css`, preserve the theme header comment. `extract_style_header()` exists because WordPress requires that header for theme recognition.
- JSON from `wp_global_styles` may be malformed or polluted; `safe_json_decode()` and the normalization warnings are part of the expected recovery path, not dead code.
- Settings are filter-first and option-backed. Examples: `wbts_auto_sync_global_styles_enabled`, `wbts_prune_generated_css`, `wbts_write_style_css`, and `wbts_global_styles_option_name`.

## Workflows
- Install deps with `composer install`.
- Unit tests are configured via `phpunit.xml` and `composer test`, but in this repo they must be run from the WordPress dev environment rather than a generic shell session.
- Packaging uses Phing (`vendor/bin/phing`) with targets from `build.xml`; `bin/build.sh` injects the `{{VERSION}}` placeholder from git tag + commit hash. That script is POSIX-shell oriented, so treat release packaging as a dev-environment task too.

## Testing/debugging patterns
- Unit tests use Brain Monkey + Mockery, with lightweight WordPress stubs in `tests/bootstrap.php`; they do not boot full WordPress.
- When changing hooks, AJAX actions, or capability checks, update tests that assert exact hook names and `current_user_can()` / nonce behavior.
- For global styles bugs, inspect both directions: the DB normalization path (`normalize_global_styles_for_theme_json()`) and the reverse sync UI (`ajax_preview()` / `ajax_commit()`).

## Integration points
- WordPress hooks: `rest_after_insert_wp_template`, `rest_after_insert_wp_template_part`, `save_post_wp_global_styles`, `update_option_*`, `after_switch_theme`, admin and AJAX hooks.
- WordPress Filesystem API is the canonical write path for theme files; direct reads exist, but writes go through `write_file()`.
- Admin JS depends on localized `wbtsSync.ajax_url` and `wbtsSync.nonce`; keep that contract aligned with `enqueue_admin_assets()`.
- WP-CLI command: `wp block-template-sync sync-global-styles` calls `GlobalStylesSync::sync_to_theme()` directly for the current theme.

