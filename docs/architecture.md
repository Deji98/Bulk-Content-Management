# Bulk Content Management Architecture

This plugin uses a maintainable WordPress plugin layout built around a small bootstrap, focused source folders, admin templates, and dedicated assets.

## Current Structure

- `bulk-content-management.php`: canonical WordPress plugin entry file. Keeps the plugin header and loads the bootstrap.
- `plugin.php`: bootstrap file for constants, includes, hooks, textdomain loading, and service registration.
- `src/Admin/Assets.php`: admin asset loading.
- `src/Core/functions.php`: core admin workflows, generation handlers, batching, and import/export callbacks.
- `src/ImportExport/slug-importer.php`: same-name/many-slugs importer.
- `templates/admin/`: admin page templates.
- `assets/css/` and `assets/js/`: admin UI assets.
- `languages/`: translation files.

## Next Extraction Targets

- Move menu registration into `src/Admin/Menu.php`.
- Move generator logic into `src/Generators/TermsGenerator.php` and `src/Generators/PostsGenerator.php`.
- Move import/export logic into `src/ImportExport/`.
- Move each admin page callback into a small controller class and render templates from `templates/admin/`.
- Add a WordPress.org-ready `readme.txt`, uninstall cleanup, text domain loading, and PHPCS configuration.
