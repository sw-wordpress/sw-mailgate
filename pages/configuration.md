# Configuration

Manage transports under **Mailgate → Connections**. General options live under
**Mailgate → Settings**. Changes to `wp-config.php` belong **before** the line that
loads `wp-settings.php`; keep secrets out of version control.

## Connections and sender rules

Each connection has its own provider, credentials and sender. **Use this connection**
includes it in the normal failover chain. Use **↑/↓** on the connection list to order it.

- **From address:** use a verified domain or mailbox your provider permits.
- **Always use this sender:** replaces the original sender; otherwise only WordPress's default sender is replaced.
- **Use the original sender as Reply-To when it is replaced:** lets replies go to the original sender.

Stored passwords and API keys are never displayed again. Leave the secret field
empty to keep its current value; use **Delete stored value** to remove it.

## Providers

### SMTP

Enter host, port, encryption and credentials from your provider. Keep certificate
verification enabled. When connecting to an IP or alias, **Certificate host name**
can specify the name on the server certificate without disabling verification.

### Mailgun

Enter the sending domain, US/EU region, API key and an allowed From address.
The connection test needs domain-read permission; a sending-only key can still
send while the probe fails.

For delivery/bounce tracking, also save the **HTTP webhook signing key** (not the
sending API key). Copy the connection's HTTPS webhook URL into Mailgun or use
**Create webhooks at Mailgun** with a key permitted to manage domain webhooks.
Tracking requires logging to be enabled below. Events are not a complete
per-recipient delivery report.

### Google / Gmail and Microsoft 365

Use the setup guide inside the selected connection editor. It supplies the exact
HTTPS redirect URI for your site and explains app registration and consent.
Save the connection and complete authorization with the intended mailbox.

- **Gmail API:** send-only authorization; prefer this over Google's broader SMTP scope.
- **Microsoft Graph:** delegated mailbox or application mailbox. Restrict application permissions
  in Exchange; the local mailbox setting alone does not restrict tenant-wide access.
- **Google SMTP / XOAUTH2:** requires Google's full-mail scope, which also permits reading,
  modifying and deleting mail, although Mailgate only uses SMTP.
- **Microsoft 365 SMTP / XOAUTH2:** requires delegated `SMTP.Send` and a mailbox permitted
  to use SMTP AUTH. Graph `Mail.Send` is not sufficient. Use Graph if tenant policy prohibits SMTP AUTH.

The dedicated OAuth SMTP providers use STARTTLS on port 587 with fixed provider
endpoints and certificate verification. Removing local tokens does not revoke
consent at Google or Microsoft. Additional
[provider details](https://github.com/sw-wordpress/sw-mailgate/blob/master/docs/providers.md)
and a [German OAuth walkthrough](https://github.com/sw-wordpress/sw-mailgate/blob/master/docs/oauth-einrichtung.md)
are available in the repository.

## Staging redirection

Set **Redirect mail to** to an inbox you control. Leave it empty to disable
redirection, or force and lock the target in `wp-config.php`:

```php
define( 'SW_MAILGATE_REDIRECT_TO', 'staging-inbox@example.com' );
```

An empty constant disables redirection even if an admin target is saved. An invalid
target blocks sending rather than leaking mail to real recipients.

**Do not redirect** accepts one exact email address or domain per line. A domain
matches its mailboxes, not subdomains; wildcards are unsupported. Allowlisted
recipients still receive real mail. Non-allowlisted To/Cc/Bcc recipients are redirected,
even with no enabled Mailgate connection.

Redirected subjects start with `[Redirected]`. Original visible To/Cc addresses
appear in `X-Original-To`; Bcc addresses are not exposed in that header.
The test-mail form can explicitly bypass redirection for one test, even a forced target.

## Delivery logging and privacy

Logging is **off by default** and cannot be enabled from the admin UI. Opt in with
an actual boolean in `wp-config.php`:

```php
define( 'SW_MAILGATE_LOG_ENABLED', true );
```

Strings such as `'true'` and integers do not enable it. Under **Mailgate → Settings**,
choose retention, address masking and whether to store message bodies. Body storage
is off by default and remains off while masking is enabled or logging is disabled.

Treat logs as personal data. Use appropriate retention and avoid body storage unless
you need it. Turning logging off stops new entries but preserves existing ones;
daily cleanup and WordPress personal-data export/erasure remain available.

## Credential storage

Passwords, API keys and tokens are encrypted using sodium. By default the key is
derived from WordPress salts. For a dedicated key, define `SW_MAILGATE_KEY` with a
cryptographically random value of **at least 32 characters** before saving credentials.
Keep the key in your protected deployment configuration and backups, not in Git.

::: warning Key changes
Changing the dedicated key, switching from the salt-derived key, or changing the
WordPress salts when no dedicated key is set makes existing encrypted data unreadable.
Plan the change and re-enter credentials; queued mail also depends on this key.
:::

### Override a connection field

The saved connection editor displays its constant prefix. Use that exact ID, not
an assumed ID based on the current name. For a connection whose ID is `primary`:

```php
// Example only: supply the actual secret through your protected configuration.
define( 'SW_MAILGATE_PRIMARY_PASSWORD', 'replace-with-your-smtp-password' );
```

The general form is `SW_MAILGATE_<CONNECTION ID>_<FIELD KEY>` in uppercase, with
other characters replaced by underscores. Overrides lock the field in the editor.
Secret fields stay blank. Saved values are preserved and apply again when the
constant is removed.

## Failover and retries

Configure **Failover** under **Mailgate → Settings**:

- **Circuit breaker:** by default, three consecutive temporary/login errors pause
  a connection for ten minutes. A threshold of `0` disables pausing. **Reset and use again**
  clears a pause. If all connections are paused, Mailgate still tries the whole chain.
- **API timeouts after submission:** the provider might already have accepted the mail.
  Automatic failover can cause duplicates. Disable **Fail over when an API provider does
  not answer after the message was sent** to report uncertain delivery instead.
- **Alerts:** optionally notify an address about paused/recovered connections and abandoned queued mail.
- **Retry queue:** opt-in storage of mail after all connections fail with temporary/login errors.
  Queued mail is encrypted and retried by WP-Cron. Default expiry is 24 hours (maximum 72).
  Attachments are limited to 5 MB in total. Permanent, partial and uncertain outcomes are not queued.

Queue acceptance reports success to the sending plugin but does **not** mean delivery.
Use **Retry now** or **Discard all** in settings to manage waiting messages.
For the full retry schedule and delivery semantics, see
[delivery details](https://github.com/sw-wordpress/sw-mailgate/blob/master/docs/delivery.md).

On low-traffic sites, or when `DISABLE_WP_CRON` is set, schedule WordPress cron on
the server. For example, adapt this crontab entry to your installation:

```text
*/5 * * * * wp cron event run --due-now --path=/path/to/wordpress
```

## DNS checks and Site Health

Under **Mailgate → DNS checks**, set active DKIM selectors, actual DKIM `d=` domains
and Return-Path domains for each sender, including fallbacks. Results are cached
for an hour; **Save and check again** refreshes them. Weekly monitoring and
**Tools → Site Health** help identify problems.

Local DNS is used by default. The optional Cloudflare DNS-over-HTTPS fallback
sends queried domains to that service when local resolution fails. DNS diagnostics
do not replace checking authentication results in received mail.

## Deactivation and uninstall

Deactivation stops background tasks but keeps data; WordPress sends mail natively.
Deletion also keeps data unless **Delete connections, credentials, settings and logs
when uninstalling** is checked. Opting in removes connections, credentials, settings,
logs and queued mail. Each site in multisite uses its own preference.
Revoke OAuth consent separately at Google or Microsoft if it is no longer needed.
