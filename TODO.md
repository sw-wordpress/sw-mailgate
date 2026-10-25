# SW Mailgate – TODO & Milestones

The full design (decisions, data model, review notes) is in the approved plan. This file tracks what is done and what comes next.

Legend: `[x]` done · `[ ]` open · **(M)** milestone release

---

## Done – M1 "Core + SMTP" (0.1.0)

- [x] Plugin skeleton: bootstrap, PHP check, Composer/PSR-4, Mozart prefixing, phpcs (WPCS), uninstall handler
- [x] `Installer`: DB version option, `manage_sw_mailgate` capability for administrators
- [x] `Mail_Catcher` replaces the global PHPMailer, so all `wp_mail_*` filters and `phpmailer_init` keep working
- [x] `Dispatcher`: failover chain, error classes (`transient` / `auth` / `permanent`), no failover on permanent errors
- [x] Sender per connection (force From, original sender as Reply-To)
- [x] `Provider` interface, `Provider_Registry` with `sw_mailgate_providers` filter, declarative `Field`s
- [x] SMTP provider: STARTTLS/SSL, connection test, SMTP transcript without credentials or message content
- [x] `Secret_Store`: libsodium encryption, `SW_MAILGATE_KEY`, key-change detection with admin notice
- [x] wp-config overrides `SW_MAILGATE_<CONNECTION>_<FIELD>`; locked fields in the UI
- [x] Admin: connection list (order, enable/disable, delete), connection editor, test mail page with per-attempt results
- [x] `sw_mailgate_attempt` action after every attempt
- [x] Smoke test against Mailpit (failover, Bcc, Reply-To, secrets, constants) – lives in the scratchpad, see "Tests" below

---

## Next steps (short term)

- [ ] Move the smoke test into `tests/` as PHPUnit integration tests (`tests/bootstrap.php` exists)
- [ ] Detect other mailer plugins (e.g. WP Mail SMTP) that also replace `$phpmailer` and show an admin notice
- [ ] German translation (`languages/sw-mailgate-de_DE.po/.mo`); load `de_DE.mo` explicitly for `de_DE_formal` (WP 7.1 just-in-time loading does not fall back)
- [x] Settings page: "delete data on uninstall" (`sw_mailgate_delete_data`)
- [x] Verify the Mozart loader path in release builds (`vendor-prefixed/autoload.php` + `addPsr4`)

---

## M2 – Log & analytics (0.2.0)

- [x] Table `{prefix}sw_mailgate_log` via `dbDelta` (bump `Installer::DB_VERSION`)
  - status, connection, provider, attempt group/number, from, to, original_to, subject, error class/message, duration, provider message ID, caller
- [x] `Logger` listens to `sw_mailgate_attempt`; must never break sending (try/catch)
- [x] Logging off by default; enable only with `SW_MAILGATE_LOG_ENABLED` set to boolean `true` in wp-config.php. Admin settings control body storage (default **off**), retention and address masking.
- [x] Daily retention cron
- [x] Log page: `WP_List_Table` with filters (status, connection, date range), search, detail view with all attempts, CSV export
- [x] Resend (only when the body is stored; unavailable for attachments, which are not retained)
- [x] Privacy exporter/eraser (`wp_privacy_personal_data_exporters` / `_erasers`)
- [x] Dashboard widget: sent / failed (later: bounce rate) for 7 and 30 days
- [x] Record the caller (plugin/theme from the backtrace)

M2 notes: logging cannot be enabled through the admin UI or stored options. Masking
suppresses body storage and only affects new entries. Privacy lookup
uses keyed address hashes (including Bcc and Reply-To); export omits content and other
recipients. Dashboard totals count the final attempt per message using retained logs.
Integration tests and supported-version CI are documented in `tests/README.md`.

## M3 – Redirection (0.3.0)

- [x] `Redirector`: replace To/Cc/Bcc, add `X-Original-To`, prefix the subject with `[Redirected]`
- [x] Settings plus the `SW_MAILGATE_REDIRECT_TO` constant (overrides the UI, e.g. for staging)
- [x] Domain/address allowlist that is not redirected
- [x] Notice when `wp_get_environment_type() !== 'production'` and redirection is off
- [x] Test mail option "ignore redirection"
- [x] Log status `redirected` with the original recipients

M3 notes: exact allowlist matches preserve each recipient's To/Cc/Bcc role; other
recipients are replaced even with native WordPress transport. Original Bcc addresses
are never exposed in `X-Original-To`. Invalid targets block delivery. Redirection
never enables logging; the M2 wp-config opt-in remains required. See `README.md`.

## M4 – Mailgun (0.4.0)

- [ ] `Mailgun_Provider`: `POST /v3/{domain}/messages.mime`, EU/US region, API key as secret
- [ ] Classify errors with `Send_Result::class_for_http_status()`; store the provider message ID
- [ ] Connection test: `GET /v3/domains/{domain}`
- [ ] Shared lightweight HTTP helper (`wp_remote_*`, timeouts, response debug without secrets)

## M5 – Failover hardening (0.5.0)

- [ ] Circuit breaker: skip a connection for X minutes after N failures (transient)
- [ ] Option "no failover on timeout" (prevents duplicates when the provider already accepted the mail)
- [ ] Admin notice and optional alert mail (via another connection) when a connection keeps failing
- [ ] Optional retry queue `{prefix}sw_mailgate_queue` with the encrypted MIME message; delete after success or expiry (default **off**)

## M6 – OAuth providers: Google & Microsoft 365 (0.6.0)

- [ ] `OAuth_Client`: authorization code flow with `state` check, refresh tokens in `Secret_Store`, refresh lock (transient)
- [ ] `Google_Provider`: Gmail API `users.messages.send` (`raw`, Bcc in MIME), upload endpoint for messages over 5 MB
- [ ] UI help: `gmail.send` is a restricted scope (internal Workspace app vs. Google verification)
- [ ] `Office365_Provider`: Graph `sendMail` with MIME, modes *Application* (client credentials) and *Delegated*
- [ ] UI help: Exchange Application Access Policy / RBAC for Applications; size limit around 4 MB
- [ ] Optional: SMTP with XOAUTH2 (PHPMailer `OAuthTokenProvider`)

## M7 – Webhook tracking (0.7.0)

- [ ] `Webhook_Capable` interface (`register_webhook()`, `verify_request()`, `parse_events()`)
- [ ] REST route `sw-mailgate/v1/webhook/{connection}`
- [ ] Mailgun: HMAC-SHA256 signature, ±5 min timestamp window, replay protection, `hash_equals`
- [ ] Table `{prefix}sw_mailgate_events`; update log status (delivered / bounced / complained) via provider message ID
- [ ] UI: copy webhook URL, "create webhooks at Mailgun" via API; hidden for providers without webhooks
- [ ] Bounce rate in the dashboard widget and statistics

## M8 – DNS checks (0.8.0)

- [ ] `Dns_Checker` per sender domain:
  - SPF: present and unique, provider include, max. 10 lookups, warning on `+all` / `?all`
  - DKIM: selector per provider or manual, TXT or CNAME
  - DMARC: present, policy, `rua`
  - Alignment of the From domain with the DKIM domain / Return-Path (important for fallback senders)
  - optional: MX, PTR of the SMTP host
- [ ] `dns_requirements()` per provider; Mailgun reads the expected records from its API
- [ ] DNS-over-HTTPS fallback (opt-in, it sends domains to a third party)
- [ ] DNS page with traffic-light status, expected record and recommendation; result cache (transient)
- [ ] Weekly cron, Site Health test, notice when results get worse

## M9 – 1.0 release

- [ ] Dashboard widget, Site Health tests (connection, OAuth token, DNS)
- [ ] WP-CLI: `wp mailgate test|log|dns|connections`
- [ ] Complete translations, readme, user documentation (wp-config constants, provider setup)
- [ ] PHPUnit unit + integration tests, phpstan, CI (`workflows/main.yml`)
- [ ] Release ZIP with `vendor-prefixed`, without dev files

---

## Ideas after 1.0

- [ ] More providers: Amazon SES, Postmark, Brevo, SendGrid, Resend
- [ ] Sender rules per calling plugin/theme
- [ ] Rate limiting / throttling (e.g. Gmail limits), protection against mass mailing
- [ ] Rules: block or redirect by pattern (subject, recipient)
- [ ] Mail catcher mode: log only, no sending, with HTML preview
- [ ] Multisite: network-wide configuration
- [ ] Graph upload sessions for large attachments
- [ ] Vue 3 + Quasar admin UI like swbackupit (current UI uses PHP templates, as decided for V1)

---

## Tests

- Local Mailpit: SMTP `127.0.0.1:32768`, API/UI `http://localhost:8025`. 
- Smoke test without DB writes: options are injected with `pre_option_*` filters (currently in the Claude scratchpad, to be moved to `tests/`).
