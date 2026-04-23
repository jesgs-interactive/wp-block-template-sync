# WP Block Template Sync

A WordPress plugin that syncs changes to theme templates in the Site Editor with the files in the active theme.

## Description

When you edit a block theme template, template part, or global styles in the WordPress **Site Editor** (Appearance → Editor) and save them, WordPress stores the changes in the database. This plugin listens for those save events and writes the changes back to the corresponding files in your theme directory — keeping your theme's source files in sync with Site Editor customisations.

**Note:** Due to the headaches of dealing with filesystem permissions and potential security issues, this plugin is __intended for local development only__.

### How it works

| Site Editor save | Plugin action |
|---|---|
| Template (`wp_template`) | Writes to `{theme}/templates/{slug}.html` |
| Template part (`wp_template_part`) | Writes to `{theme}/parts/{slug}.html` |
| Global Styles (`wp_global_styles`) | Deep-merges into `{theme}/theme.json`; regenerates `{theme}/style.css` |

The plugin only syncs templates and styles that belong to the **currently active (child) theme** and requires the saving user to have the `edit_theme_options` capability (Administrator by default).

### Global Styles sync

When you save changes in the **Styles** panel of the Site Editor the plugin:

1. Reads the existing `theme.json` from disk so that non-style keys (`templateParts`, `customTemplates`, `patterns`, etc.) are preserved.
2. Normalises the database representation — WordPress groups palette, gradients, duotone, font sizes and font families by origin (`theme`, `default`, `custom`); the plugin extracts the `theme` origin so the shape matches what `theme.json` expects.
3. Deep-merges the normalised `settings` and `styles` values on top of the existing file. Associative objects are merged key-by-key (preserving the original key order for clean diffs); sequential arrays such as `palette` entries are replaced wholesale.
4. Backs up the previous `theme.json` and `style.css` into `{theme}/.global-styles-backups/` before writing.
5. Writes the merged `theme.json` and a regenerated `style.css` via `wp_get_global_stylesheet()`.

The sync also fires on the `after_switch_theme` hook so the files reflect the correct styles immediately after a theme is activated.

### Options & Filters

- `wbts_prune_generated_css` (bool) — When enabled the plugin will prune the generated `style.css` so it only contains preset variables and helper classes that are present in the merged `theme.json`. This is OFF by default to preserve editor and plugin compatibility. Enable with:

You can enable pruning either by the filter above or via the plugin admin UI at Appearance → Template Sync. The admin page exposes checkboxes for the plugin settings:

- "Auto-sync theme.json" — when enabled the plugin automatically syncs the Site Editor's global styles into `theme.json` on save/theme-switch (default: off).
- "Prune generated CSS" — when enabled the plugin removes generated preset CSS that is not present in the merged `theme.json` so the stylesheet only contains CSS for presets the theme actually defines (default: off).
- "Write generated style.css" — when enabled the plugin will write the regenerated stylesheet into the active theme's `style.css` (header is preserved). This is OFF by default; when disabled the plugin will still update `theme.json` but will not touch `style.css`.

The admin page also contains a **Manual sync** panel for pushing changes made directly to `theme.json` back into the WordPress database without triggering a save from the Site Editor:

1. Click **"Preview changes"** — the plugin computes an LCS-based diff between `theme.json` on disk and the global styles stored in the database. Deleted lines are highlighted in red, added lines in green.
2. If the preview looks correct, click **"Sync theme.json → Database"** to copy the `settings` and `styles` from `theme.json` into the active theme's `wp_global_styles` post.

A non-JavaScript fallback form performs the sync directly (without a preview step) for environments where JS is unavailable.

Programmatic enabling (example):

```php
add_filter( 'wbts_prune_generated_css', '__return_true' );
```


- `wbts_write_style_css_enabled` (admin option, bool) — Controls the default behavior exposed to the `wbts_write_style_css` filter. Default: OFF. When enabled the plugin will write the generated `style.css` into the active theme when a sync runs. You can toggle this from the admin UI or programmatically via the `wbts_write_style_css` filter.

Programmatic override example (force-disable writing `style.css` regardless of the admin option):

```php
add_filter( 'wbts_write_style_css', '__return_false' );
```

- The plugin also avoids touching `style.css` when the newly generated content is identical to the existing file (avoids unnecessary backups and timestamp changes).

- `wbts_auto_sync_global_styles_enabled` (bool) — Controls whether the plugin automatically syncs global styles on each Site Editor save and on `after_switch_theme`. The default is read from the "Auto-sync theme.json" admin option (default: off). Override programmatically:

```php
add_filter( 'wbts_auto_sync_global_styles_enabled', '__return_true' );
```

- `wbts_global_styles_option_name` (string, default: `wp_global_styles`) — The WordPress option name the plugin watches as a fallback hook (`update_option_{name}`). Change only when your site stores global styles under a non-standard option key:

```php
add_filter( 'wbts_global_styles_option_name', function() {
    return 'my_custom_global_styles_option';
} );
```

## Requirements

- WordPress 6.0 or later
- PHP 8.0 or later
- A **block theme** (FSE-compatible, e.g. Twenty Twenty-Three)
- The web server must have write permission to the theme directory

## Installation

1. Download or clone this repository into `wp-content/plugins/wp-block-template-sync`.
2. Activate the plugin via **Plugins → Installed Plugins** in WordPress admin.
3. Edit a theme template in **Appearance → Editor**, make a change, and click **Save** — the corresponding file in your theme will be updated automatically.

## WP-CLI

The plugin registers a WP-CLI command for triggering a global styles sync without visiting the admin UI — useful for scripted deployments.

### `wp block-template-sync sync-global-styles`

Reads the `wp_global_styles` post for the active theme and writes the merged result into `theme.json` (and optionally `style.css`). Existing files are backed up into `{theme}/.global-styles-backups/` before being overwritten. Logs each backed-up and written file, then prints a success message. Exits with an error if no `wp_global_styles` post is found.

```bash
wp block-template-sync sync-global-styles
```

## Development

### Install dependencies

```bash
composer install
```

### Run tests

```bash
composer test
```

### Testing with Pest

This project now supports running tests with Pest while preserving the existing PHPUnit + Brain Monkey + Mockery setup.

- To run tests with Pest locally:

```bash
composer pest
```

- The Pest bootstrap (`tests/Pest.php`) reuses `tests/bootstrap.php` so Brain Monkey and the lightweight WordPress stubs remain available.

Pest is an ergonomic test runner; PHPUnit-style TestCase classes will continue to work while you incrementally convert tests to Pest's function-style syntax.

## Automatic Updates

The plugin includes a built-in GitHub Releases update checker that integrates with the standard WordPress plugin update system — no manual downloading required.

- On each WordPress update check the plugin queries the GitHub Releases API for the [`jesgs-interactive/wp-block-template-sync`](https://github.com/jesgs-interactive/wp-block-template-sync) repository (response cached for 6 hours via transient).
- If the latest release tag is newer than the installed version, WordPress shows an update notice in **Plugins → Installed Plugins**, identical to a plugin from the official directory.
- Clicking the plugin name in the update list opens a details modal populated from the GitHub release description.
- Updates are applied via the standard WordPress one-click update mechanism (downloads the release ZIP from GitHub).

No API token is required; the checker works with public repositories only.

## Security

- Only users with the `edit_theme_options` capability can trigger a sync.
- Template slugs are validated to prevent path-traversal attacks.
- Only templates and styles belonging to the currently active theme are written.
- Files are written via the [WordPress Filesystem API](https://developer.wordpress.org/apis/filesystem/).
- Automatic backups of `theme.json` and `style.css` are kept in `{theme}/.global-styles-backups/` before every overwrite.

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
