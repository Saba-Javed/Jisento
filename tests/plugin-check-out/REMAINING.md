# Plugin Check remaining findings (D6)

## Environment
- WordPress latest + Plugin Check (sqlite local install on D:\jisento-pc)
- PHP 8.2.4 (host). PHP 7.4 / 8.3 Docker WordPress runs were blocked by a hung Docker engine on this machine after disk exhaustion; the same plugin tree was checked under PHP 8.2 with latest WordPress.

## ERRORs remaining after fixes
1. `hidden_files` on `.distignore` — required by WordPress.org deploy tooling; excluded from the release zip by itself. Not shipped to sites.

## WARNINGs kept (reasonable)
See agent summary: DirectDatabaseQuery / NoCaching / UnescapedDBParameter for migration SQL; set_time_limit for long jobs; view template locals; intentional core hooks; load_plugin_textdomain bootstrap.

Full CSV: tests/plugin-check-out/after-fix.csv
