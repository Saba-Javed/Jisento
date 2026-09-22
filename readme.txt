=== Jisento Migration ===
Contributors: jisento
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full WordPress site migration and backup using .jisento packages, chunked jobs, and short-lived migration keys.

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
* wp-content/jisento (packages, logs, job files) and wp-content/mu-plugins/jisento-live-url.php/.json
* Any copy of the Jisento Migration plugin, under any folder name
* Database views (they are listed in the export log; recreate them on the destination)

= Packages from version 1.2.11 and older =

Those versions replaced every "%" in the database with a placeholder such as
{4f0c...}. The import detects this and stops before changing anything. Export the source again
with this version (recommended), or enable "Repair % characters" on the import screen: it
replaces exactly the repeated placeholder tokens with "%".

= Recovery =

If a finished import leaves home or siteurl pointing at the old domain, fix them over SSH:

    wp option update home 'https://your-site.example'
    wp option update siteurl 'https://your-site.example'

A failed import is never restarted automatically. Press Retry on the job to re-validate the
package and continue; before the table swap it restarts from package validation.

== Installation ==

1. Upload the plugin folder to wp-content/plugins/
2. Activate Jisento Migration
3. Open Jisento in the WordPress admin menu

== Changelog ==

= 1.3.0 =
* Fix: "%" characters were corrupted by the export (package format 2.0).
* Fix: preserve mode could drop live tables; all restores now use work tables.
* Fix: the live-URL mu-plugin no longer hard-codes a server path and is never exported.
* Only one export or import can run on a site at a time.
* Package contents are verified before any destructive step; files are restored atomically.
* Removed jisento-recover.php.

= 1.0.0 =
* Initial release
