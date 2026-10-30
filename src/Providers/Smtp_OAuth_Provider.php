<?php
/**
 * Delegated Google / Microsoft SMTP with the existing token store and delivery tracking.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Mail\Mailer_Factory;
use SWMailgate\OAuth\SMTP_Token_Provider;

class Smtp_OAuth_Provider extends OAuth_Provider {

	public function __construct( private readonly bool $google = true ) {
		parent::__construct();
	}

	public function id(): string {
		return $this->google ? 'google_smtp' : 'office365_smtp';
	}

	public function label(): string {
		return $this->google ? __( 'Google SMTP / XOAUTH2', 'sw-mailgate' ) : __( 'Microsoft 365 SMTP / XOAUTH2', 'sw-mailgate' );
	}

	public function fields(): array {
		$fields = array( new Field( 'username', __( 'Mailbox login address', 'sw-mailgate' ), Field::EMAIL, required: true, description: __( 'Sign in as this mailbox when connecting. Use its primary address, not a sender alias. SMTP OAuth supports delegated access to the signed-in mailbox.', 'sw-mailgate' ) ) );
		if ( ! $this->google ) {
			$fields[] = new Field( 'tenant_id', __( 'Directory (tenant) ID', 'sw-mailgate' ), required: true, description: __( 'Single-tenant UUID for Microsoft 365 in the global cloud.', 'sw-mailgate' ) );
		}
		return array_merge( $fields, $this->client_fields() );
	}

	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {
		( new Smtp_Provider() )->prepare( $mailer, $connection );
		// Bearer credentials must only reach the selected provider over verified TLS.
		$mailer->Host                                    = $this->google ? 'smtp.gmail.com' : 'smtp.office365.com';
		$mailer->Port                                    = 587;
		$mailer->SMTPSecure                              = 'tls';
		$mailer->SMTPAutoTLS                             = true;
		$mailer->SMTPAuth                                = true;
		$mailer->AuthType                                = 'XOAUTH2';
		$mailer->Username                                = $connection->get( 'username' );
		$mailer->Password                                = '';
		$mailer->Timeout                                 = min( 60, $mailer->Timeout );
		$mailer->SMTPOptions['ssl']['verify_peer']       = true;
		$mailer->SMTPOptions['ssl']['verify_peer_name']  = true;
		$mailer->SMTPOptions['ssl']['allow_self_signed'] = false;
		unset( $mailer->SMTPOptions['ssl']['peer_name'] );
		$mailer->setSMTPInstance( $this->smtp_instance() );
	}

	protected function smtp_instance(): OAuth_SMTP {
		return new OAuth_SMTP();
	}

	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		return $this->with_token( $envelope->mailer(), $connection, $envelope );
	}

	public function test_connection( Connection $connection ): Send_Result {
		$mailer = Mailer_Factory::create();
		$this->prepare( $mailer, $connection );
		return $this->with_token( $mailer, $connection );
	}

	private function with_token( Mail_Catcher $mailer, Connection $connection, ?Envelope $envelope = null ): Send_Result {
		$token = $this->oauth->access_token( $connection );
		if ( $token instanceof Send_Result ) {
			return $token;
		}
		Mailer_Factory::load_oauth();
		$adapter = new SMTP_Token_Provider( $connection->get( 'username' ), $token );
		$mailer->setOAuth( $adapter );
		$smtp       = new Smtp_Provider();
		$result     = null === $envelope ? $smtp->test_prepared_connection( $mailer, $connection ) : $smtp->send( $envelope, $connection );
		$instance   = $mailer->getSMTPInstance();
		$auth_error = $instance instanceof OAuth_SMTP ? $instance->authentication_error() : null;
		if ( ! $result->is_success() && null !== $auth_error ) {
			$result = $result->reclassify( $auth_error >= 400 && $auth_error < 500 ? Send_Result::TRANSIENT : Send_Result::AUTH, __( 'SMTP XOAUTH2 authentication failed. Check the connected mailbox, SMTP permission and provider SMTP AUTH policy.', 'sw-mailgate' ) );
		}
		// Also scrub a server that echoes a credential in an error or reply.
		$secrets = array_filter( array( $token, $adapter->getOauth64(), $connection->get( 'client_secret' ), $connection->get( 'password' ) ) );
		$clean   = static fn( string $value ): string => str_replace( $secrets, '[credentials omitted]', $value );
		$debug   = array_map( $clean, $result->debug() );
		$safe    = $result->is_success() ? Send_Result::success( $clean( $result->provider_message_id() ), $debug ) : Send_Result::failure( $result->error_class(), $clean( $result->message() ), $debug );
		return $safe->with_duration( $result->duration() );
	}
}
