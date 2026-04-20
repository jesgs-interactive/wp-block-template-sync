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

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- A **block theme** (FSE-compatible, e.g. Twenty Twenty-Three)
- The web server must have write permission to the theme directory

## Installation

1. Download or clone this repository into `wp-content/plugins/wp-block-template-sync`.
2. Activate the plugin via **Plugins → Installed Plugins** in WordPress admin.
3. Edit a theme template in **Appearance → Editor**, make a change, and click **Save** — the corresponding file in your theme will be updated automatically.

## Development

### Install dependencies

```bash
composer install
```

### Run tests

```bash
composer test
```

## Security

- Only users with the `edit_theme_options` capability can trigger a sync.
- Template slugs are validated to prevent path-traversal attacks.
- Only templates and styles belonging to the currently active theme are written.
- Files are written via the [WordPress Filesystem API](https://developer.wordpress.org/apis/filesystem/).
- Automatic backups of `theme.json` and `style.css` are kept in `{theme}/.global-styles-backups/` before every overwrite.

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
