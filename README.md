# SW Mailgate

A WordPress mail transport plugin with connection failover and optional delivery logs.

## Enable logging

Logging is **off by default**. To allow it, add this to `wp-config.php` before the
line that loads `wp-settings.php`:

```php
define( 'SW_MAILGATE_LOG_ENABLED', true );
```

Only the boolean `true` enables logging. An absent constant, `false`, strings, and
integers keep logging disabled. Database options and admin form submissions cannot
enable it. Remove the definition or set it to `false` to stop recording new entries.

The Mailgate settings page shows the current status and controls retention,
address masking, and optional message body storage. Body storage is off by default;
it also stays off whenever logging is disabled or address masking is enabled.

Disabling logging preserves existing entries. Daily retention cleanup and the
WordPress personal data export/erasure tools remain available.

See [tests/README.md](tests/README.md) for validation instructions.
