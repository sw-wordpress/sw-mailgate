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

M3 redirection scenarios use the same isolated database and WordPress bootstrap:

```sh
php tests/integration.php enabled redirection ui
php tests/integration.php unset redirection ui
php tests/integration.php enabled redirection constant
php tests/integration.php enabled redirection empty
php tests/integration.php enabled redirection invalid
php tests/integration.php enabled redirection invalid-type
php tests/integration.php enabled redirection production
```

Use the same `WP_ROOT` / `WP_DB_*` environment variables as above. These scenarios
cover mixed To/Cc/Bcc allowlists, exact domain matching, Bcc header confidentiality,
deferred recipients, failover, native SMTP without configured connections, scoped
test-form bypass and cleanup, forced/disabled/invalid configuration, staging notices,
redirected log status and privacy lookup, masking, resend, settings validation, and
uninstall. SMTP is an in-memory test double; these tests do not connect to a server.

M4 Mailgun scenarios:

```sh
php tests/integration.php enabled mailgun api
php tests/integration.php unset mailgun api
php tests/integration.php enabled mailgun constant
```

The same `WP_ROOT` / `WP_DB_*` variables apply. WordPress's `pre_http_request`
intercepts every Mailgun request, so no API credentials or live account are needed
and no external mail is sent. Tests inspect the multipart wire payload, full
To/Cc/Bcc envelope and Bcc-only messages, MIME headers, HTML/plain alternatives and
binary attachments, regional hosts, read-only domain tests, encrypted keys and
wp-config overrides, TLS/timeouts/redirect settings, HTTP and transport failure
classification, ambiguous successful responses, safe diagnostics, redirection,
admin fields/test actions, logging opt-in and uninstall cleanup.
