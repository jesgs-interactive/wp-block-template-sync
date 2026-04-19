# WP Block Template Sync

A WordPress plugin that syncs changes to theme templates in the Site Editor with the files in the active theme.

## Description

When you edit a block theme template or template part in the WordPress **Site Editor** (Appearance → Editor) and save it, WordPress stores the updated markup in the database. This plugin listens for those REST API save events and writes the new block markup back to the corresponding HTML file in your theme directory — keeping your theme's source files in sync with Site Editor customisations.

### How it works

| Site Editor save | Plugin action |
|---|---|
| Template (`wp_template`) | Writes to `{theme}/templates/{slug}.html` |
| Template part (`wp_template_part`) | Writes to `{theme}/parts/{slug}.html` |

The plugin only syncs templates that belong to the **currently active (child) theme** and requires the saving user to have the `edit_theme_options` capability (Administrator by default).

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
- Only templates belonging to the currently active theme are written.
- Files are written via the [WordPress Filesystem API](https://developer.wordpress.org/apis/filesystem/).

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
