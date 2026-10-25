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

## Redirect mail on staging

Set a target under **Mailgate → Settings**, or force it in `wp-config.php`:

```php
define( 'SW_MAILGATE_REDIRECT_TO', 'staging-inbox@example.com' );
```

The constant overrides and locks the admin target. An empty string explicitly
disables redirection, including a saved admin target. Invalid targets block sending
instead of falling back to real recipients. A notice warns administrators when
redirection is off on a non-production WordPress environment.

All non-allowlisted To/Cc/Bcc recipients are replaced by the target, including when
WordPress uses its native transport without enabled Mailgate connections. Exact
email addresses or domains can be allowlisted in settings; those recipients retain
their original To/Cc/Bcc roles. Domain rules do not include subdomains or wildcards.

Redirected messages have a `[Redirected]` subject prefix and an `X-Original-To`
header containing the original visible To/Cc addresses. Bcc addresses never appear
in this header; Bcc-only messages use `undisclosed-recipients:;` instead. Reply-To
is preserved.

The authenticated test-mail form can explicitly **ignore redirection** for one
test, including a forced target. Ordinary mail immediately resumes redirection.
Logging still requires `SW_MAILGATE_LOG_ENABLED` to be boolean `true`; redirection
does not enable it. When enabled, logs retain original recipients subject to
masking, record successful redirected deliveries as `redirected`, and include them
in sent totals. Failed attempts remain `failed`.

Resend applies the current redirection rules to the stored delivery recipients;
it never restores addresses from the original-recipient log field.

See [tests/README.md](tests/README.md) for validation instructions.
