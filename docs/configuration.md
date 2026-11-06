# Configuration details

Overview and constant list: [README](../README.md#configuration).

## Logging

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

## wp-config overrides in the editor

Every connection field can be set with a constant named
`SW_MAILGATE_<CONNECTION ID>_<FIELD KEY>` (upper case, other characters become `_`),
e.g. `SW_MAILGATE_PRIMARY_PASSWORD`. The connection editor shows the prefix.

Disabled non-secret provider fields display the effective wp-config value used by
the transport, including checkbox interpretation. Passwords and API keys stay blank.
Saving other fields preserves the underlying values and encrypted credentials,
so removing a constant restores the saved configuration.

## Multisite uninstall

Uninstall evaluates every site's own deletion preference in bounded batches.
Sites opting into deletion lose their Mailgate options, encrypted credentials,
capabilities, log table and retry queue; sites opting out retain their data. Scheduled Mailgate
tasks are cleared on every site. The caller's site context is restored, including
when a site hook throws an exception during cleanup.
