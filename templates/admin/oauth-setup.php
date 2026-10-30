<?php
/**
 * Provider setup guide, progressively enhanced into an accessible native dialog.
 *
 * @package SWMailgate
 */

defined( 'ABSPATH' ) || exit;
?>
<details class="swmg-oauth-help" data-close-label="<?php esc_attr_e( 'Close', 'sw-mailgate' ); ?>">
	<summary><?php esc_html_e( 'How do I create the OAuth client credentials?', 'sw-mailgate' ); ?></summary>
	<div class="swmg-oauth-instructions">
		<h2 id="swmg-oauth-help-title"><?php echo esc_html( sprintf( /* translators: %s: provider */ __( 'Set up %s', 'sw-mailgate' ), $provider->label() ) ); ?></h2>
		<p><?php esc_html_e( 'Create your own application in the provider account. Use an HTTPS WordPress installation and an administrator who can manage Mailgate. Client secrets and tokens are encrypted; the editor never displays stored secret values.', 'sw-mailgate' ); ?></p>
		<h3><?php esc_html_e( 'Redirect URI for this WordPress installation', 'sw-mailgate' ); ?></h3>
		<p><code class="swmg-oauth-uri"><?php echo esc_html( \SWMailgate\OAuth\OAuth_Client::redirect_uri() ); ?></code></p>
		<p><?php esc_html_e( 'Copy this complete URL exactly, including https, host, subdirectory and query string. Each staging, production or multisite site needs its own registered URI. After a site move, update it at the provider and reconnect the mailbox.', 'sw-mailgate' ); ?></p>

		<?php if ( \SWMailgate\OAuth\OAuth_Client::smtp( $provider->id() ) ) : ?>
			<?php require __DIR__ . '/oauth-smtp-setup.php'; ?>
		<?php elseif ( 'google' === $provider->id() ) : ?>
			<h3><?php esc_html_e( '1. Create a project and enable Gmail', 'sw-mailgate' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Open Google Cloud Console, choose a project or create a dedicated project for this WordPress site.', 'sw-mailgate' ); ?> <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Cloud Console', 'sw-mailgate' ); ?></a></li>
				<li><?php esc_html_e( 'Open APIs & Services → Library, find Gmail API and enable it in that project.', 'sw-mailgate' ); ?></li>
			</ol>
			<h3><?php esc_html_e( '2. Configure consent and the audience', 'sw-mailgate' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Open Google Auth Platform → Branding. Set the application name, support email and developer contact; complete the initial setup.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Under Audience, use Internal only for an eligible Google Workspace organization and accounts inside it. Otherwise choose External. While testing an external app, add the sending Google account as a test user.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Under Data Access, add only the Gmail send scope shown below. Mailgate does not request inbox-reading or mailbox-management access.', 'sw-mailgate' ); ?><br><code>https://www.googleapis.com/auth/gmail.send</code></li>
			</ol>
			<p><strong><?php esc_html_e( 'gmail.send is a sensitive scope, not a restricted scope.', 'sw-mailgate' ); ?></strong> <?php esc_html_e( 'An internal Workspace app normally avoids public verification, but organization access policies still apply. An external production app may need Google verification. External apps left in Testing generally receive refresh tokens that expire after seven days for this scope; do not treat Testing as an unattended production setup.', 'sw-mailgate' ); ?></p>
			<h3><?php esc_html_e( '3. Create a Web application client', 'sw-mailgate' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Open Google Auth Platform → Clients → Create client. Select Web application and give it a recognizable name.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Add the exact Redirect URI above under Authorized redirect URIs. JavaScript origins are not required by this server-side integration.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Create the client and copy its Client ID and Client secret into the corresponding Mailgate fields. Keep the secret securely; do not use an API key, a service-account JSON file or your mailbox password.', 'sw-mailgate' ); ?></li>
			</ol>
			<h3><?php esc_html_e( '4. Connect and verify', 'sw-mailgate' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Save this connection, then choose Connect mailbox. Sign in to the Google account that should send mail and approve sending permission.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Set the From address to that mailbox or an alias already permitted by Gmail. Run the connection test, then send a test mail.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'If access is revoked or a testing token expires, use Reconnect mailbox. If redirect_uri_mismatch appears, compare the registered URI character for character.', 'sw-mailgate' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'Gmail accepts the prepared MIME including Bcc. Messages over 5 MB use the media upload endpoint; messages over the 35 MB API limit are rejected locally.', 'sw-mailgate' ); ?></p>
			<p><a href="https://developers.google.com/workspace/guides/configure-oauth-consent" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google consent setup', 'sw-mailgate' ); ?></a> · <a href="https://developers.google.com/workspace/gmail/api/auth/scopes" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Gmail scope reference', 'sw-mailgate' ); ?></a> · <a href="https://developers.google.com/identity/protocols/oauth2/web-server" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Web OAuth guide', 'sw-mailgate' ); ?></a></p>
		<?php else : ?>
			<h3><?php esc_html_e( '1. Register a Microsoft Entra application', 'sw-mailgate' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Open Microsoft Entra admin center → Identity → Applications → App registrations → New registration.', 'sw-mailgate' ); ?> <a href="https://entra.microsoft.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft Entra admin center', 'sw-mailgate' ); ?></a></li>
				<li><?php esc_html_e( 'Choose a name and Accounts in this organizational directory only (single tenant). This integration uses the global Microsoft cloud and a work or school tenant.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'For Delegated mode, register the Redirect URI above with platform Web. Do not select Single-page application, enable implicit grants or allow public-client flows. Application mode does not require a redirect URI.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'From Overview, copy Application (client) ID into Client ID and Directory (tenant) ID into the tenant field. Use the tenant UUID, not common, organizations or a tenant URL.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Open Certificates & secrets → Client secrets → New client secret. Choose a suitable expiry and copy the secret Value immediately into Mailgate. The Secret ID is not the secret. Plan to rotate it before expiry.', 'sw-mailgate' ); ?></li>
			</ol>
			<h3><?php esc_html_e( '2A. Delegated mode: send through a signed-in mailbox', 'sw-mailgate' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'In API permissions → Add a permission → Microsoft Graph → Delegated permissions, add Mail.Send. Mailgate also requests offline_access to renew the session. It does not require User.Read, Mail.Read or Mail.ReadWrite.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Grant administrator consent if your tenant requires it. Select Delegated in Mailgate and save the client settings.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Choose Connect mailbox and sign in as the sending mailbox user. Sending uses /me/sendMail; the application-mode mailbox field is ignored. Shared-mailbox delegation and Mail.Send.Shared are not part of this mode.', 'sw-mailgate' ); ?></li>
			</ol>
			<h3><?php esc_html_e( '2B. Application mode: unattended mail for a scoped mailbox', 'sw-mailgate' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Choose Application in Mailgate and enter the exact mailbox address to send through. Tokens use the client-credentials flow; there is no mailbox sign-in or refresh token.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Have your Exchange administrator grant the Application Mail.Send role through Exchange Online RBAC for Applications, limited to the intended mailbox scope. Register the enterprise service principal in Exchange, create the resource scope and assign the scoped role; test the authorization before use.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'Important: a tenant-wide Microsoft Graph Application Mail.Send grant in Entra is additive to Exchange RBAC. Remove an unscoped grant if relying on RBAC mailbox restrictions; merely adding a scoped RBAC role does not restrict that broader grant.', 'sw-mailgate' ); ?></li>
				<li><?php esc_html_e( 'If using the older Application Access Policy approach instead, Graph Application Mail.Send needs administrator consent and an Exchange policy restricting the app to the intended mail-enabled security group. Test an allowed and a disallowed mailbox. Changing the mailbox field here is not a provider-side access restriction.', 'sw-mailgate' ); ?></li>
			</ol>
			<p><a href="https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Exchange RBAC setup, PowerShell commands and authorization tests', 'sw-mailgate' ); ?></a></p>
			<h3><?php esc_html_e( '3. Save and send a test mail', 'sw-mailgate' ); ?></h3>
			<p><?php esc_html_e( 'Use a From address permitted for the selected mailbox. Save, test token acquisition, then send a test mail to check actual mailbox and Send As permissions. A 202 response means Graph accepted the request, not that the recipient has received it. This integration rejects encoded MIME requests above 4 MB; large attachment upload sessions are not implemented.', 'sw-mailgate' ); ?></p>
			<p><?php esc_html_e( 'For invalid_client, check the secret Value and expiry. For consent or access errors, check the permission type, tenant, mailbox scope and Send As rules. After rotating a secret, save it and reconnect delegated access.', 'sw-mailgate' ); ?></p>
			<p><a href="https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Register an app', 'sw-mailgate' ); ?></a> · <a href="https://learn.microsoft.com/en-us/graph/api/user-sendmail" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Graph sendMail', 'sw-mailgate' ); ?></a></p>
		<?php endif; ?>
		<h3><?php esc_html_e( 'Disconnecting and keeping access working', 'sw-mailgate' ); ?></h3>
		<p><?php esc_html_e( 'Mailgate renews tokens automatically while the provider grant remains valid. Removing local OAuth credentials clears the local token cache and cancels pending authorizations; it does not revoke the provider grant. Revoke that separately in the provider account. In Application mode, client credentials can obtain another token until the app secret or provider permission is revoked.', 'sw-mailgate' ); ?></p>
	</div>
</details>
