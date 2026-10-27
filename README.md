# SW Mailgate

Development: see [tests/README.md](tests/README.md) for the regression suite,
local Clover coverage and SonarQube Cloud CI setup.

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

## Send through Mailgun

Add a **Mailgun** connection under **Mailgate → Connections**. Enter the Mailgun
sending domain (for example `mg.example.com`), select its US or EU region, and
provide an API key. Set the connection's From address to a sender permitted for
that domain. API keys are encrypted in the existing secret store and are never
printed back into the editor.

The connection test reads domain details and checks that the domain is active;
it sends no email. The key needs permission to read domain details for this test.
A sending-only key may permit submission while rejecting that read-only test.

As with SMTP, each field supports an override using the connection ID shown in
the editor. For a connection whose ID is `primary`, for example:

```php
define( 'SW_MAILGATE_PRIMARY_DOMAIN', 'mg.example.com' );
define( 'SW_MAILGATE_PRIMARY_REGION', 'eu' );
define( 'SW_MAILGATE_PRIMARY_API_KEY', 'your-mailgun-api-key' );
define( 'SW_MAILGATE_PRIMARY_TIMEOUT', 15 );
```

Mailgun receives the prepared MIME message, preserving HTML, plain alternatives,
attachments, sender rules and Reply-To. The explicit API envelope includes To,
Cc and Bcc; Bcc is omitted from the MIME headers. Redirection is applied before
the API request. Logging still requires the separate wp-config opt-in above.

Requests use verified HTTPS, fixed regional Mailgun hosts, a 5–60 second timeout
and no HTTP redirects. Diagnostics include only method, host, HTTP status and
duration; they omit authentication headers, message content and raw response/error
text. Successful submissions retain Mailgun's message ID. Authentication failures,
rate limits, server errors and transport errors follow the existing failover
classification. A successful HTTP response without a valid message ID stops
automatic failover because the message may already have been accepted.

The implementation follows Mailgun's current
[MIME submission API](https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/messages/post-v3--domain-name--messages-mime)
(`POST /v3/{domain}/messages.mime`) and
[domain-details API](https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/domains/get-v4-domains--name-)
(`GET /v4/domains/{domain}`). The latter supersedes the v3 path in the original
milestone plan.

## SMTP delivery outcomes and retries

SMTP delivery facts are recorded before PHPMailer runs QUIT or RSET. If the server
accepts DATA for some recipients but rejects others, `wp_mail()` returns false and
the log marks the message as **partial delivery**. If the final DATA response is
lost, the result is **delivery uncertain**. Neither outcome automatically submits
the whole message through another connection. Check the SMTP server's delivery
records before manually retrying, since some recipients may already have received it.

All-recipient permanent rejections stop failover. Temporary recipient rejections
and known failures before submission remain eligible for the next connection.
Cleanup or callback errors after confirmed acceptance do not cause a second send.
Log filters and the dashboard distinguish partial/uncertain results from sent/failed;
the existing wp-config requirement for logging still applies.

## Failover hardening

Configure these options under **Mailgate → Settings → Failover**.

**Pausing failing connections (circuit breaker).** After 3 consecutive temporary or
login errors (configurable; `0` disables it), a connection is skipped for 10 minutes,
so mails go straight to the next connection instead of waiting for the same timeout.
After the pause the next mail tries it again; one more failure pauses it again. Rejected,
partial and uncertain deliveries do not count. If every connection is paused, the whole
chain is tried anyway, so the breaker never blocks mail. A test mail to a single
connection always uses it. A success, a successful connection test, saving the
connection or **Reset and use again** in the connection list closes the breaker.
While a connection keeps failing, administrators see a notice on every admin page.

**Timeouts.** When an API provider (Mailgun) does not answer after the request was
sent, it may have accepted the message. By default the next connection is tried,
which can deliver the message twice. Uncheck **Fail over when an API provider does
not answer** to report such mails as **delivery uncertain** instead. DNS, connect and
TLS errors always fail over. SMTP already stops when a submitted message is not
confirmed (see below).

**Alert mail.** Optionally notify an address when a connection is paused, when it
works again, and when a queued message is given up. Alerts are sent at the end of
the request through the other connections.

**Retry queue (off by default).** When every connection failed with temporary or
login errors, the message is stored encrypted (with the `SW_MAILGATE_KEY` /
salt-derived key of the credential store) in `{prefix}sw_mailgate_queue` and
`wp_mail()` reports success. WordPress cron retries it after 5, 15, 30 and
60 minutes and then every 2–4 hours until it is delivered or expires (24 hours by
default, at most 72). Each retry runs through the current chain, so every connection
applies its own sender. Attachments up to 5 MB in total are kept; larger messages are
not queued and fail as before. Test mails and permanent, partial or uncertain results
are never queued. The log marks the attempt as **queued** and continues the same
attempt group with every retry. The settings page lists waiting messages (subject
and recipient count only) with **Retry now** and **Discard all**; privacy erasure
removes queued messages of the address.

Settings that other plugins apply in `phpmailer_init`, such as DKIM signing keys,
belong to the original request and are not applied again on retries. Because WordPress
cron needs site traffic, use a real system cron for timely retries on quiet sites.

## wp-config overrides in the editor

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

See [tests/README.md](tests/README.md) for validation instructions.
