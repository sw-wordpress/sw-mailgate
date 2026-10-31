# Delivery, failover and retries

Overview: [README](../README.md). Provider specifics: [providers.md](providers.md).

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
the existing wp-config requirement for logging still applies
([Logging](configuration.md#logging)).

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
not answer after the message was sent** to report such mails as **delivery uncertain** instead. DNS, connect and
TLS errors always fail over. SMTP already stops when a submitted message is not
confirmed (see [SMTP delivery outcomes](#smtp-delivery-outcomes-and-retries)).

**Alert mail.** Optionally notify an address when a connection is paused, when it
works again, and when a queued message is given up. Alerts are sent at the end of
the request through the other connections.

## Retry queue

Off by default. When every connection failed with temporary or
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
