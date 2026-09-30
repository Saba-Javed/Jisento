=== Jisento Migration ===
Contributors: jisento
Tags: migration, backup, clone, transfer, move site
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate or back up a full WordPress site with .jisento packages, chunked jobs, and short-lived migration keys.

== Description ==

Jisento Migration exports and imports complete WordPress sites:

* .jisento packages (ZIP) with a manifest and a SHA-256 for every database segment
* Database and wp-content file migration
* Serialized-data-safe URL replacement
* Replace or preserve destination modes
* Server-to-server transfer via short-lived migration keys
* Resumable chunked jobs for shared hosting

Single sites only. Import and export are refused on WordPress multisite.

= Package integrity =

The "JISENTO" entry in a package is a format marker. It identifies the package layout
(JISENTO-PACKAGE-v2 for version 2 packages) and proves nothing about who created the package.
Integrity is checked with the manifest: the file count and total bytes, and the SHA-256 of every
database segment. All of it is verified before any table is created. Authenticity (signed
packages) is not provided; only import packages you created yourself.

= Destination modes =

Every database restore goes into work tables (the table name plus "__js"). The live tables are
only replaced at the end, in one RENAME TABLE, after every segment has been restored and the
row counts have been checked against the manifest. If the import fails or is cancelled before
that point, the live site is unchanged and the work tables are dropped.

* Replace: every table in the package replaces the table of the same name on this site. Tables
  that exist only on this site are left in place. Plugins, themes and uploads from the package
  are restored.
* Preserve: an existing table is never changed, unless you list it under "Existing tables to
  replace". Tables from the package that do not exist here are added. Plugin, theme and upload
  files follow the strategies selected on the import screen.

After a Replace import that includes the users table, log in with the SOURCE site's username and
password.

= What is never exported or restored =

* wp-config.php, .htaccess, .user.ini, php.ini, web.config, .env
* The drop-ins object-cache.php, advanced-cache.php, db.php, db-error.php and maintenance.php in wp-content
* wp-content/jisento and wp-content/uploads/jisento-* (packages, logs, job files)
  and wp-content/mu-plugins/jisento-live-url.php/.json (temporary import guard)
* Any copy of the Jisento Migration plugin, under any folder name
* Database views (they are listed in the export log; recreate them on the destination)

== Installation ==

1. Upload the `jisento-migration` folder to `/wp-content/plugins/`.
2. Activate **Jisento Migration** through the Plugins screen.
3. Open **Jisento** in the WordPress admin menu to export, import, or migrate.

== Frequently Asked Questions ==

= What is Replace mode vs Preserve mode? =

**Replace** overwrites matching tables and restores plugins, themes, and uploads from the package.
**Preserve** keeps existing destination tables unless you explicitly list them to replace, and uses
the plugin/theme/upload strategies you choose on the import screen. After Replace that includes
users, log in with the source site credentials.

= What are migration keys? =

A migration key is a short-lived secret generated on the source site. Paste it on the destination
to transfer the package server-to-server over HTTPS. Keys expire and can be single-use. The package
is sent only to the destination site you connect—not to Jisento or any third party.

= Why does Jisento create a must-use plugin during import? =

While a database import is running, Jisento writes a temporary must-use plugin
(`wp-content/mu-plugins/jisento-live-url.php`). It pins this site's URL, active theme, and active
plugins so the source database cannot take over the destination while tables are being swapped.
The file exists only while that import job is running and is removed when the job completes, fails,
or is cancelled, and also when the plugin is deactivated or uninstalled. It is safe to delete
manually if no import is in progress.

= How do I resume an import over WP-CLI if the theme fatals? =

If an import dies after the database swap because the imported theme is incomplete on disk:

`wp jisento resume --job=<id> --skip-themes`

Then restore or fix the theme files and switch themes under Appearance, or resume again without
`--skip-themes` once the theme directory is complete.

= Should I clear the hosting cache after migration? =

Yes. After a successful import, purge any page, object, or CDN cache provided by your host or
caching plugin so visitors see the migrated site. Jisento also attempts common plugin cache flushes,
but host-level caches often need a manual clear in the hosting panel.

= Packages from version 1.2.11 and older =

Those versions replaced every "%" in the database with a placeholder. The import detects this and
stops before changing anything. Export the source again with this version (recommended), or enable
"Repair % characters" on the import screen.

= Recovery if home or siteurl still point at the old domain =

Fix them over SSH:

`wp option update home 'https://your-site.example'`

`wp option update siteurl 'https://your-site.example'`

== Screenshots ==

1. Migration screen — start a full export or open import and key workflows.
2. Import wizard — choose a package, pick Replace or Preserve mode, then review.
3. Backups — list, download, and manage saved .jisento packages.
4. Migration progress — resumable chunked jobs with stage timings.
5. Settings — retention, chunk size, and migration-key defaults.

== Changelog ==

= 1.0.0 =
* Initial public release on WordPress.org.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.

== Privacy ==

Jisento Migration does not track you and does not send data to Jisento or other third parties.

When you use a migration key, the site package is transferred only from the source site to the
destination site you connect. HTTPS is required for remote transfer. Packages and logs stay on
your servers under the plugin storage folder unless you download or delete them.
