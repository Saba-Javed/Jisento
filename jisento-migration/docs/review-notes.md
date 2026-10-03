# Release review notes — Jisento Migration 1.0.0

Scope: what a reviewer needs in order to accept `dist/jisento-migration.zip` for the 1.0.0
release. This file is development documentation only; `docs/` is excluded from the release
archive by `.distignore`, so nothing here ships to users.

## What was validated

| Step | Command / tool | Result |
| --- | --- | --- |
| Release archive built | `bin/build-release.ps1` (from `git ls-files` minus `.distignore`) | `dist/jisento-migration.zip`, 68 files, 221,863 bytes |
| Archive contents | `unzip -l dist/jisento-migration.zip` | Single top-level folder `jisento-migration/`; no `docs/`, `tests/`, `bin/`, `dist/`, `.wordpress-org/`, `.distignore`, `*.ps1` |
| Installed into WordPress | WordPress 7.1.2, PHP 8.3.33, `Plugin_Upgrader::install()` + `activate_plugin()` | Install OK, header parsed, activation OK, 6 plugin tables created |
| Plugin Check static ruleset | `phpcs --standard=phpcs-rulesets/plugin-review.xml` (Plugin Check 2.1.0 ruleset; WPCS 3.4.1, PHPCS 3.13.6, PluginCheck sniffs) | **0 errors**, 38 warnings on PHP 7.4 **and** PHP 8.3 |
| PHP lint | `find . -name '*.php'` piped to `php -l` on PHP 7.4.33 | 0 syntax errors in 93 files (62 in the release archive) |
| PHP test suite | `php tests/*-test.php` | 466 passed, 13 failed — all 13 are runner-environment defects, see below |

Errors target is met: the ruleset reports no errors at either PHP version.

## Remaining warnings, grouped

All 38 warnings are identical on PHP 7.4 and PHP 8.3. Every one is a static-analysis false
positive or an accepted trade-off, and each group is listed with the reason it stays.

### 1. `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` — 10

`includes/Core/Lease.php:51,52,54,55,56`; `includes/Core/Logger.php:43,52,125`;
`includes/Security/Admin_Guard.php:116,120`

**Why it stays:** the interpolated pieces are table identifiers, an `(int)`-cast constant, a
literal SQL fragment, or generated `%d` placeholders — none can be passed as a `prepare()`
value, and every piece of user data still goes through a real placeholder.

Detail: `{$table}` / `{$jobs}` are `$wpdb->prefix . 'jisento_*'`; `{$ttl}` is `(int) self::TTL`;
`{$take}` is a hard-coded string with no variable inside; `$holders` is
`implode( ',', array_fill( 0, count( $ids ), '%d' ) )` over ids already passed through
`array_map( 'intval', ... )`.

### 2. `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` — 2

`includes/Security/Admin_Guard.php:116,120`

**Why it stays:** the same two queries as group 1 — the sniff cannot see the `%d` placeholders
because they are assembled into `$holders` before the query string is built.

### 3. `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` — 1

`includes/Jobs/Job_Store.php:122`

**Why it stays:** `$args` is built in the loop directly above (one entry per non-`NULL` `SET`
column) and then gets `$job_id` and `$version` appended, so it holds exactly one value per
placeholder; the sniff counts the array itself as a single replacement.

### 4. `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` — 9
### 5. `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` — 9

`includes/Security/Job_Continuation.php:120,124,129,130,211,245,247,248,269`

**Why they stay:** every value is compared against a fixed pattern and never echoed, stored,
or used to build SQL, output, or a path.

Detail: `REQUEST_METHOD` is upper-cased and compared to `'POST'`; `REQUEST_URI`,
`QUERY_STRING` and `$_GET['rest_route']` are matched against
`#/jisento/v1/jobs/([A-Za-z0-9_]{8,64})#`, which is itself the sanitiser; `HTTP_HOST` is
stripped of a trailing port and compared case-insensitively to the `home` option host;
`HTTPS` and `HTTP_X_FORWARDED_PROTO` are lower-cased and compared to `'on'`/`'1'`/`'https'`;
`HTTP_X_WP_NONCE` is only tested for presence and reported as `present`/`absent`.
`wp_unslash()` is deliberately not applied: these are server-provided values, not slashed
form input, and unslashing a URI before regex matching would change what matches.

### 6. `WordPress.Security.NonceVerification.Recommended` — 4

`admin/views/layout-start.php:13` (×2); `includes/Security/Job_Continuation.php:129` (×2)

**Why they stay:** both are read-only. `layout-start.php` reads `$_GET['page']` only to render
a `data-page` attribute, and it is passed through `sanitize_key( wp_unslash( ... ) )` inside
`esc_attr()`. `Job_Continuation` reads `$_GET['rest_route']` only to decide whether the
current request is the job-step route. Neither site mutates state; every mutating route
verifies its own nonce and capability.

### 7. `Squiz.PHP.DiscouragedFunctions.Discouraged` — 3

`includes/Admin/Admin.php:305`; `includes/Api/Rest_Controller.php:486`;
`includes/Cli/Commands.php:237`

**Why they stay:** migration steps legitimately run past `max_execution_time`. Each call is
wrapped in `if ( function_exists( 'set_time_limit' ) )` and silenced, so hosts that disable it
(in safe-mode-style or FPM hardening setups) are unaffected, and `Step_Budget` still bounds
each step independently.

## Test suite

`tests/*-test.php` run under plain `php` with the WordPress stubs in `tests/bootstrap.php`.
Of the 26 files, 21 print `0 failed`, `tests/sql-scanner-test.php` and
`tests/url-replace-test.php` print their own all-passed lines, and `tests/roundtrip-test.php`
skips. The 13 failures sit in the remaining two files and are defects in the PHP runtime used
for this validation, not in the plugin:

* `tests/streaming-zip-test.php` — 3 failures, all `unzip -t … without errors`. Running the
  identical commands by hand returns exit code 0 and
  `No errors detected in compressed data of …` for all three archives (spanning, forced
  ZIP64, and a real exporter package). The runtime reports exit code 1 for *every* `exec()`
  call — including `printf` and `ls` — so the test's `0 === $rc` can never hold.
* `tests/live-url-test.php` — 10 failures in the mu-plugin guard group. The runtime's
  `PHP_BINARY` is a generated wrapper that does not forward `"$@"`, so each child `php`
  process prints its usage text instead of running the guard. Re-running the same ten cases
  as real child processes gives 10 passed, 0 failed.

`tests/roundtrip-test.php` reports `SKIP no database server`, which is the behaviour the
bootstrap documents for a host with no reachable MariaDB/MySQL.

## Release package rules

* `docs/` is listed in `.distignore` and is absent from the archive.
* Generated reports, PHPCS output and Docker output are written outside the repository and
  are never staged; `dist/` itself is also in `.distignore`, so a rebuild cannot pull a
  previous archive into the next one.
* The archive is rebuilt from `git ls-files`, so an untracked scratch file cannot leak into
  a release even if it sits in the plugin folder.
