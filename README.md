# WP Block Template Sync

A WordPress plugin that syncs changes to theme templates in the Site Editor with the files in the active theme.

## Description

When you edit a block theme template or template part in the WordPress **Site Editor** (Appearance → Editor) and save it, WordPress stores the updated markup in the database. This plugin listens for those REST API save events and writes the new block markup back to the corresponding HTML file in your theme directory — keeping your theme's source files in sync with Site Editor customisations.

It also automatically syncs **global styles** (edited via the Site Editor's Styles panel) into the active theme's `theme.json` and `style.css` files.

**Note:** Due to the headaches of dealing with filesystem permissions and potential security issues, this plugin is __intended for local development only__.

### How it works

| Site Editor save | Plugin action |
|---|---|
| Template (`wp_template`) | Writes to `{theme}/templates/{slug}.html` |
| Template part (`wp_template_part`) | Writes to `{theme}/parts/{slug}.html` |
| Global styles option update | Writes to `{theme}/theme.json` and `{theme}/style.css` |
| Theme activation | Writes global styles to `{theme}/theme.json` and `{theme}/style.css` |

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

## Global Styles Sync

### How it works

Whenever the `wp_global_styles` database option is updated (i.e. when you save global styles in the Site Editor), the plugin:

1. Parses the stored JSON and extracts `settings` and `styles` keys.
2. **Backs up** the existing `theme.json` and `style.css` into `{theme}/.global-styles-backups/` with timestamped filenames.
3. Writes a new `theme.json` (version 2) containing the `settings` and `styles` from the database.
4. Generates a computed CSS stylesheet (using `wp_get_global_stylesheet()` when available) and writes it to `style.css`.

The same sync runs automatically when a theme is activated (`after_switch_theme`).

### WP-CLI command

You can trigger the sync manually or from a CI pipeline:

```bash
wp block-template-sync sync-global-styles
```

To use a different option name:

```bash
wp block-template-sync sync-global-styles --option=my_custom_global_styles
```

### Disabling the automatic sync

The automatic sync is **enabled by default**. To disable it (e.g. on staging or production), add this to a must-use plugin or your theme's `functions.php`:

```php
add_filter( 'wbts_auto_sync_global_styles_enabled', '__return_false' );
```

### Overriding the option name

By default the plugin watches the `wp_global_styles` option. To override:

```php
add_filter( 'wbts_global_styles_option_name', function() {
    return 'my_custom_global_styles';
} );
```

### Backups

Before overwriting, the plugin copies the existing `theme.json` and `style.css` to:

```
{theme}/.global-styles-backups/theme.json.YYYYMMDDHHmmss.bak
{theme}/.global-styles-backups/style.css.YYYYMMDDHHmmss.bak
```

Add `.global-styles-backups/` to your `.gitignore` if you do not want backups committed.

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
- Global styles are only written when the theme directory exists and is writable.
- Existing files are backed up before being overwritten.

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

