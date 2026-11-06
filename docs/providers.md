# Providers

Overview: [README](../README.md#integrations). Step-by-step OAuth setup (German):
[oauth-einrichtung.md](oauth-einrichtung.md). Failover and retries: [delivery.md](delivery.md).

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
the editor ([wp-config overrides](configuration.md#wp-config-overrides-in-the-editor)).
For a connection whose ID is `primary`, for example:

```php
define( 'SW_MAILGATE_PRIMARY_DOMAIN', 'mg.example.com' );
define( 'SW_MAILGATE_PRIMARY_REGION', 'eu' );
define( 'SW_MAILGATE_PRIMARY_API_KEY', 'your-mailgun-api-key' );
define( 'SW_MAILGATE_PRIMARY_TIMEOUT', 15 );
```

Mailgun receives the prepared MIME message, preserving HTML, plain alternatives,
attachments, sender rules and Reply-To. The explicit API envelope includes To,
Cc and Bcc; Bcc is omitted from the MIME headers. Redirection is applied before
the API request. Logging still requires the separate wp-config opt-in
([Logging](configuration.md#logging)).

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

## Google / Gmail and Microsoft 365

Each provider editor includes a detailed setup guide, enhanced into a
keyboard-accessible modal, with the exact HTTPS redirect URI of that WordPress
site. It explains client registration, minimum scopes, consent, expiry and
mailbox scoping. See the [German setup guide](oauth-einrichtung.md).

Gmail uses send-only offline authorization. Microsoft 365 supports delegated
authorization for the signed-in mailbox and client credentials for a configured
application mailbox in one global-cloud tenant. Scope application permissions
in Exchange; the local mailbox setting does not restrict a tenant-wide grant.

Tokens use the encrypted Secret Store, one-time session-bound state, PKCE and
atomic expiring refresh claims. Changing credentials, tenant, mode or mailbox
invalidates the cache. Removing local tokens invalidates pending authorizations,
but does not revoke provider consent.
Reconnects and token refreshes share a connection claim. A successful reconnect
advances the credential generation so an older refresh cannot restore a previous
mailbox. If a refresh is busy, retry connecting after it finishes.

Connection tests acquire tokens without requesting inbox access or sending mail.
Use a test mail to verify mailbox and Send As permissions. MIME preserves Bcc,
Reply-To and attachments. Gmail uses the media upload endpoint above 5 MB, with a
35 MB limit. Graph limits the entire base64 request to 4 MB and returns 202 without
a remote message ID; acceptance does not confirm delivery. Logging remains
wp-config opt-in. Graph large attachment uploads are not included.

## SMTP with XOAUTH2

Choose **Google SMTP / XOAUTH2** (`google_smtp`) or **Microsoft 365 SMTP / XOAUTH2**
(`office365_smtp`) for delegated SMTP submission. Enter the primary mailbox login
address, OAuth client ID/secret and, for Microsoft, tenant UUID. Save and connect
the same mailbox. Each connection has its own encrypted grant. Changing the login
invalidates its tokens and pending authorization; existing API grants remain valid.

SMTP endpoints are fixed to `smtp.gmail.com:587` and `smtp.office365.com:587` with
mandatory STARTTLS and certificate/host verification, even if old settings or
wp-config constants specify another host or weaker TLS. No password or LOGIN/PLAIN
fallback is used. The connection test performs TLS and XOAUTH2 authentication
without submitting mail; send a test mail to verify sender/Send As permissions.
Expired tokens refresh automatically. Provider SMTP size/rate limits apply.
The existing SMTP partial/uncertain-delivery safeguards also apply
([delivery.md](delivery.md#smtp-delivery-outcomes-and-retries)).

Google SMTP requires the **restricted full-mail scope** `https://mail.google.com/`,
which permits reading, changing and deleting mail as well as sending. Mailgate
uses only SMTP, but prefer the Gmail API provider for send-only authorization.
Microsoft SMTP requires delegated Exchange Online **SMTP.Send**, requested as
`https://outlook.office.com/SMTP.Send offline_access`, and an SMTP-AUTH-enabled
mailbox. Graph Mail.Send does not authorize SMTP. Follow tenant policy; use Graph
when SMTP AUTH is prohibited. Shared-mailbox delegation and SMTP.SendAsApp are
not implemented. The setup dialog and [German guide](oauth-einrichtung.md)
explain registration, consent, verification and SMTP AUTH settings.

WordPress versions that omit PHPMailer's OAuthTokenProvider interface use the
unmodified compatibility copy in `compat/PHPMailer`, distributed under its
included LGPL-2.1 license. No additional OAuth SDK or PHPMailer runtime is loaded.
