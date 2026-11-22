# Getting Started

## Before you install

You need WordPress **6.8 or later**, PHP **8.2 or later** with the **sodium** extension,
and MySQL or MariaDB. Single-site and multisite installations are supported.
Google and Microsoft OAuth connections require an HTTPS WordPress site.

Have your mail provider's credentials and a permitted sender address ready.
Disable other SMTP/mailer plugins to avoid conflicting transports.

## 1. Install SW Mailgate

1. Download the **installable plugin ZIP** from the
   [GitHub releases](https://github.com/sw-wordpress/sw-mailgate/releases), not GitHub's automatically generated source-code archive.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP, install it, and activate **SW Mailgate**.
4. Open **Mailgate → Connections**.

::: tip Source checkouts
A source archive does not contain the generated Composer autoloader. Use the
release package for ordinary installations; development setup is in the
[repository README](https://github.com/sw-wordpress/sw-mailgate#development).
:::

## 2. Add your first connection

For a conventional mail account, select **SMTP** under **Add connection**:

1. Give it a recognizable **Name**, for example `Primary SMTP`.
2. Set **From address** to an address your provider allows, and optionally set **From name**.
3. Enter your provider's **SMTP host**, **Port**, **Encryption**, **Username** and **Password**.
   Port `587` normally uses **STARTTLS**; port `465` uses **SSL/TLS (implicit)**.
   Use your provider's documented combination and keep **Verify TLS certificate** enabled.
4. Leave **Use this connection** checked and click **Add connection**.

For other transports, choose Mailgun, Google / Gmail, Microsoft 365 or a dedicated
SMTP / XOAUTH2 provider. See [provider configuration](./configuration#providers).

::: warning Protect staging before testing
On a staging site, first set **Mailgate → Settings → Redirect mail to** to an inbox
you control. This also affects test mail unless you explicitly bypass redirection.
See [staging redirection](./configuration#staging-redirection).
:::

## 3. Check the connection and send a test

1. On the saved connection, click **Test connection (no mail is sent)**.
2. Click **Send test mail**, enter an inbox you control, select the connection and send.
3. Check the inbox and spam folder. Inspect the received sender and authentication results.

A successful connection test confirms connectivity/authentication, **not delivery**
or sender permissions. Provider acceptance does not guarantee inbox placement.
Leave **Ignore redirection for this test mail** unchecked unless you deliberately
want to contact the entered recipient directly, even when redirection is forced.

## 4. Add a fallback (optional)

Add another connection and use the **↑/↓** controls to set the order. Regular mail
starts with the first enabled connection and uses later ones for eligible failures.
With no enabled connections, WordPress uses its native transport.

Partial or uncertain delivery is not automatically resent through another SMTP
connection: some recipients may already have received it. Check provider records
before resending. More options: [failover and retries](./configuration#failover-and-retries).

## 5. Check sender DNS

Open **Mailgate → DNS checks** and review SPF, DKIM and DMARC. Configure the actual
DKIM selectors and sender domains used by your provider, then **Save and check again**.
DNS checks are guidance; verify the headers of a received test message as well.

## If something does not work

- **Test succeeds, mail fails:** check the From address and the provider's Send As permissions.
- **Credentials cannot be decrypted:** the encryption key or WordPress salts may have changed;
  re-enter credentials. Read [credential storage](./configuration#credential-storage).
- **Mail is blocked on staging:** correct an invalid redirect target rather than disabling protection.
- **Log is empty:** logging is off until explicitly enabled in `wp-config.php`.
- **Mailgun probe fails with a sending-only key:** the probe also needs permission to read domain details.

Continue with [Configuration](./configuration) for logging, redirection and operational settings.
