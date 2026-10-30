<?php
/**
 * SMTP-specific OAuth registration and troubleshooting, inside the setup dialog.
 *
 * @package SWMailgate
 */

defined( 'ABSPATH' ) || exit;
?>
<p><?php esc_html_e( 'SMTP OAuth uses delegated mailbox authorization. Save the client credentials and mailbox login address, then choose Connect mailbox. Sign in as that mailbox. API tokens and SMTP tokens have different permissions; create a separate Mailgate connection and authorize it independently.', 'sw-mailgate' ); ?></p>
<p><strong><?php esc_html_e( 'Mailgate fixes the SMTP endpoint to port 587 with mandatory STARTTLS and certificate verification. Host, port, encryption and password overrides do not change this transport.', 'sw-mailgate' ); ?></strong></p>
<?php if ( 'google_smtp' === $provider->id() ) : ?>
	<h3><?php esc_html_e( '1. Create the Google project and consent configuration', 'sw-mailgate' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Open Google Cloud Console and select or create a project for this site. Open Google Auth Platform → Branding and enter the application name, support email and developer contact.', 'sw-mailgate' ); ?> <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Cloud Console', 'sw-mailgate' ); ?></a></li>
		<li><?php esc_html_e( 'Under Audience choose Internal for eligible Google Workspace organization accounts, or External otherwise. In external Testing mode, add the sending mailbox as a test user.', 'sw-mailgate' ); ?></li>
		<li><?php esc_html_e( 'Under Data Access add the scope below; enter it manually if necessary. SMTP requires full mail access. The Gmail API gmail.send scope does not authorize SMTP.', 'sw-mailgate' ); ?><br><code>https://mail.google.com/</code></li>
	</ol>
	<p><strong><?php esc_html_e( 'This is a restricted scope with access to read, send, change and permanently delete mail.', 'sw-mailgate' ); ?></strong> <?php esc_html_e( 'Mailgate uses it only for SMTP submission, but the grant itself is broader. Prefer the Google / Gmail API provider if send-only permission meets your needs. External production apps may require restricted-scope verification and a security assessment, depending on how restricted data is handled. Internal Workspace apps remain subject to organization access policies. External Testing refresh tokens generally expire after seven days.', 'sw-mailgate' ); ?></p>
	<h3><?php esc_html_e( '2. Create a Web application OAuth client', 'sw-mailgate' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Open Google Auth Platform → Clients → Create client → Web application. Register the exact Redirect URI above under Authorized redirect URIs. JavaScript origins are not required.', 'sw-mailgate' ); ?></li>
		<li><?php esc_html_e( 'Copy Client ID and Client secret into Mailgate. Do not use a mailbox password, app password, API key or service-account file.', 'sw-mailgate' ); ?></li>
		<li><?php esc_html_e( 'Enter the primary Gmail or Workspace mailbox as Mailbox login address. Save, connect that account and approve the full mail scope. Set From to the mailbox or a permitted sending alias.', 'sw-mailgate' ); ?></li>
	</ol>
	<p><code>smtp.gmail.com:587</code> — <?php esc_html_e( 'STARTTLS, XOAUTH2. Workspace administrators may need to allow the OAuth app. Gmail SMTP message-size and sending limits apply; the Gmail API media-upload path is not used.', 'sw-mailgate' ); ?></p>
	<p><a href="https://developers.google.com/workspace/gmail/imap/xoauth2-protocol" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google SMTP XOAUTH2 and required scope', 'sw-mailgate' ); ?></a> · <a href="https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Restricted-scope verification', 'sw-mailgate' ); ?></a></p>
<?php else : ?>
	<h3><?php esc_html_e( '1. Register a Microsoft Entra Web application', 'sw-mailgate' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Open Microsoft Entra admin center → Identity → Applications → App registrations → New registration. Select Accounts in this organizational directory only. This provider supports Microsoft 365 work or school mailboxes in the global cloud.', 'sw-mailgate' ); ?> <a href="https://entra.microsoft.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft Entra admin center', 'sw-mailgate' ); ?></a></li>
		<li><?php esc_html_e( 'Register the exact Redirect URI above using platform Web. Do not enable implicit grants, SPA or public-client flows. Copy Application (client) ID and Directory (tenant) ID from Overview into Mailgate; the tenant must be its UUID.', 'sw-mailgate' ); ?></li>
		<li><?php esc_html_e( 'Under Certificates & secrets create a client secret. Copy the secret Value immediately, not the Secret ID. Save it securely and plan rotation before expiry.', 'sw-mailgate' ); ?></li>
	</ol>
	<h3><?php esc_html_e( '2. Grant the Exchange Online SMTP permission', 'sw-mailgate' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Under API permissions → Add a permission, select Office 365 Exchange Online → Delegated permissions → SMTP.Send. If it is not listed, search under APIs my organization uses for Office 365 Exchange Online (resource ID 00000002-0000-0ff1-ce00-000000000000). Graph Mail.Send does not authorize SMTP.', 'sw-mailgate' ); ?></li>
		<li><?php esc_html_e( 'Mailgate requests the SMTP scope below plus offline_access for refresh tokens. Grant administrator consent if required by tenant policy. SMTP.SendAsApp application permissions are not supported by this connection type.', 'sw-mailgate' ); ?><br><code>https://outlook.office.com/SMTP.Send offline_access</code></li>
		<li><?php esc_html_e( 'Enter the primary mailbox address as Mailbox login address. Save the connection and choose Connect mailbox. Sign in as that mailbox and approve the delegated permission. Shared-mailbox delegation is not supported by this connection type.', 'sw-mailgate' ); ?></li>
	</ol>
	<h3><?php esc_html_e( '3. Check whether authenticated SMTP is permitted', 'sw-mailgate' ); ?></h3>
	<p><?php esc_html_e( 'An Exchange administrator must verify the organization and mailbox SMTP AUTH settings. In Microsoft 365 admin center: Users → Active users → select the mailbox → Mail → Manage email apps → Authenticated SMTP. With Exchange Online PowerShell, inspect SmtpClientAuthenticationDisabled on both Get-TransportConfig and Get-CASMailbox -Identity mailbox@example.com; a null mailbox value inherits the organization setting. If policy permits, enable SMTP AUTH only for the intended mailbox, for example with Set-CASMailbox -Identity mailbox@example.com -SmtpClientAuthenticationDisabled $false.', 'sw-mailgate' ); ?></p>
	<p><?php esc_html_e( 'Security Defaults or organization policy may block SMTP AUTH. Prefer the Microsoft 365 Graph provider when SMTP is prohibited; do not weaken tenant security merely to use this transport. XOAUTH2 does not require enabling Basic authentication or an app password.', 'sw-mailgate' ); ?></p>
	<p><code>smtp.office365.com:587</code> — <?php esc_html_e( 'STARTTLS, XOAUTH2. Use a permitted From address; another sender needs Exchange Send As authorization. Exchange SMTP limits apply, rather than the Graph 4 MB request limit.', 'sw-mailgate' ); ?></p>
	<p><a href="https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft SMTP OAuth setup', 'sw-mailgate' ); ?></a> · <a href="https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'SMTP AUTH settings', 'sw-mailgate' ); ?></a></p>
<?php endif; ?>
<h3><?php esc_html_e( 'Test and troubleshoot', 'sw-mailgate' ); ?></h3>
<p><?php esc_html_e( 'Test connection opens a verified TLS SMTP session and authenticates with XOAUTH2 without submitting mail. Then send a test mail to verify From/Send As permissions and delivery. A 535 error can mean missing SMTP consent, a different signed-in mailbox, revoked access or disabled SMTP AUTH. After changing the mailbox login or client credentials, save and reconnect. Expired access tokens are renewed automatically; rejected SMTP submissions are not automatically replayed on this connection.', 'sw-mailgate' ); ?></p>
