# Tests

Run the checkout and uninstall smoke tests after `composer install`:

```sh
php tests/bootstrap.php
php tests/uninstall.php . delete
php tests/uninstall.php . preserve
php tests/uninstall.php . default
composer cs
```

The release workflow runs the same entry points against the packaged plugin,
including the prefixed autoloader.

`integration.php` boots real WordPress and uses a **disposable MariaDB/MySQL
database**. It creates WordPress tables with the `swmg_test_` prefix and deletes
the plugin's test data. Never point it at a production installation or database.
Download an unconfigured WordPress copy, enable PHP `mysqli` and `sodium`, then:

```sh
WP_ROOT=/tmp/wordpress WP_DB_HOST=127.0.0.1:3306 \
  WP_DB_NAME=mailgate_test WP_DB_USER=root WP_DB_PASSWORD='' \
  php tests/integration.php enabled
```

Run `integration.php` separately with `unset`, `disabled`, `string`, and `integer`
to verify logging stays off without an explicit boolean `true` constant. These
scenarios test that stored options and submitted settings cannot enable logging,
no bodies are captured, and no log rows are written. Separate processes isolate
immutable PHP constants. The `enabled` scenario exercises the full log feature set.

The transport is a deterministic test provider; it never sends external mail.
The suite covers schema upgrades, grouped failover outcomes, opt-in content,
recipient masking, Bcc/Reply-To privacy lookup, export/erasure, retention, resend,
query filtering, spreadsheet formula escaping, native admin rendering, database
failures during delivery and uninstall policies. CI runs it on WordPress 6.8 and
current WordPress with PHP 8.2/8.4.

Set `SW_MAILGATE_TEST_PLUGIN` to test a staged release directory instead of the
source checkout.
