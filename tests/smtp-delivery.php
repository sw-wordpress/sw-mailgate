<?php
/**
 * Issue #6: actual PHPMailer SMTP protocol with deterministic I/O, no network.
 * Included by integration.php enabled issues smtp.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Providers\Provider;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Providers\Smtp_Provider;
use SWMailgate\Providers\Tracked_SMTP;

class SW_Mailgate_Scripted_SMTP extends Tracked_SMTP {
	public array $commands = array();
	public array $accepted_addresses = array();
	public array $delivered_addresses = array();
	private bool $open = false;
	private string $reply = '';
	private int $recipient_index = 0;
	public function __construct( public array $scenario ) {}
	public function connect( $host, $port = null, $timeout = 30, $options = array() ) {
		if ( ! empty( $this->scenario['connect_failure'] ) ) {
			$this->setError( 'Connection failed' );
			return false;
		}
		$this->open = true;
		return true;
	}
	public function connected() { return $this->open; }
	public function close() { $this->open = false; parent::close(); }
	protected function get_lines() { return $this->reply; }
	public function client_send( $data, $command = '' ) {
		$this->commands[] = $command;
		$code = 250;
		if ( 'RCPT TO' === $command ) {
			$code = $this->scenario['recipients'][ $this->recipient_index++ ] ?? 250;
			if ( 250 === $code || 251 === $code ) {
				preg_match( '/<([^>]+)>/', $data, $address );
				$this->accepted_addresses[] = $address[1];
			}
		} elseif ( 'DATA' === $command && "DATA\r\n" === $data ) {
			$code = $this->scenario['data_start'] ?? 354;
		} elseif ( 'DATA END' === $command ) {
			$code = $this->scenario['data_end'] ?? 250;
			if ( 250 === $code || 0 === $code ) {
				// For code 0 the fixture accepted the message but lost the response.
				$this->delivered_addresses = $this->accepted_addresses;
			}
		} elseif ( 'QUIT' === $command ) {
			if ( ! empty( $this->scenario['quit_failure'] ) ) { throw new RuntimeException( 'cleanup callback fixture failure' ); }
			$code = 221;
		}
		$this->reply = $code ? $code . ' fixture response' . "\r\n" : '';
		if ( 0 === $code ) { $this->open = false; }
		return strlen( $data );
	}
}

$smtp_provider = new Smtp_Provider();
$fixture_provider = new class( $smtp_provider ) implements Provider {
	public array $scenario = array();
	public array $instances = array();
	public function __construct( private Smtp_Provider $delegate ) {}
	public function id(): string { return 'scripted_smtp'; }
	public function label(): string { return 'Scripted SMTP'; }
	public function fields(): array { return $this->delegate->fields(); }
	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {
		$this->delegate->prepare( $mailer, $connection );
		$instance = new SW_Mailgate_Scripted_SMTP( $this->scenario );
		$this->instances[] = $instance;
		$mailer->setSMTPInstance( $instance );
	}
	public function send( Envelope $envelope, Connection $connection ): Send_Result { return $this->delegate->send( $envelope, $connection ); }
	public function test_connection( Connection $connection ): Send_Result { return $this->delegate->test_connection( $connection ); }
};
add_filter( 'sw_mailgate_providers', static fn( array $providers ): array => array_merge( $providers, array( $fixture_provider ) ), 20 );
delete_option( Connection_Repository::OPTION );
$connections->save( array( 'id' => 'primary', 'provider' => 'scripted_smtp', 'from_email' => 'sender@example.test', 'settings' => array( 'host' => 'smtp.fixture.test', 'port' => '25', 'encryption' => 'none', 'auth' => '0' ) ) );
$connections->save( array( 'id' => 'second', 'provider' => 'test', 'from_email' => 'fallback@example.test' ) );
$cases = array(
	'mixed permanent' => array( 'recipients' => array( 250, 550 ), 'sent' => false, 'error' => Send_Result::PARTIAL, 'delivered' => 1 ),
	'mixed temporary' => array( 'recipients' => array( 250, 450 ), 'sent' => false, 'error' => Send_Result::PARTIAL, 'delivered' => 1 ),
	'all permanent' => array( 'recipients' => array( 550, 550 ), 'sent' => false, 'error' => Send_Result::PERMANENT, 'delivered' => 0 ),
	'mixed rejection classes' => array( 'recipients' => array( 450, 550 ), 'sent' => false, 'error' => Send_Result::PERMANENT, 'delivered' => 0 ),
	'all temporary' => array( 'recipients' => array( 450, 450 ), 'sent' => true, 'error' => Send_Result::TRANSIENT, 'delivered' => 0, 'fallback' => true ),
	'connection failure' => array( 'connect_failure' => true, 'sent' => true, 'error' => Send_Result::TRANSIENT, 'delivered' => 0, 'fallback' => true ),
	'DATA rejected temporarily' => array( 'data_end' => 450, 'sent' => true, 'error' => Send_Result::TRANSIENT, 'delivered' => 0, 'fallback' => true ),
	'DATA rejected permanently' => array( 'data_end' => 550, 'sent' => false, 'error' => Send_Result::PERMANENT, 'delivered' => 0 ),
	'lost DATA response' => array( 'data_end' => 0, 'sent' => false, 'error' => Send_Result::UNCERTAIN, 'delivered' => 2 ),
	'accepted' => array( 'sent' => true, 'error' => '', 'delivered' => 2 ),
	'callback failure after acceptance' => array( 'callback_failure' => true, 'sent' => true, 'error' => '', 'delivered' => 2 ),
	'QUIT failure after acceptance' => array( 'quit_failure' => true, 'sent' => true, 'error' => '', 'delivered' => 2 ),
	'QUIT failure after partial acceptance' => array( 'quit_failure' => true, 'recipients' => array( 250, 550 ), 'sent' => false, 'error' => Send_Result::PARTIAL, 'delivered' => 1 ),
);
$capture = array();
add_action( 'sw_mailgate_attempt', static function ( $attempt ) use ( &$capture ): void { $capture[] = $attempt; } );
foreach ( array( false, true ) as $keepalive ) {
	foreach ( $cases as $label => $scenario ) {
		$fixture_provider->scenario = $scenario;
		$configure = static function ( $mailer ) use ( $keepalive, $scenario ): void {
			$mailer->SMTPKeepAlive = $keepalive;
			$mailer->action_function = ! empty( $scenario['callback_failure'] ) ? static function (): void { throw new RuntimeException( 'callback fixture failure' ); } : null;
		};
		add_action( 'phpmailer_init', $configure );
		$capture = array();
		$before = count( $provider->messages );
		$sent = wp_mail( array( 'accepted@example.test', 'other@example.test' ), 'SMTP case ' . $label, 'Body fixture' );
		remove_action( 'phpmailer_init', $configure );
		$instance = end( $fixture_provider->instances );
		sw_mailgate_check( $scenario['sent'] === $sent && $scenario['error'] === $capture[0]->result->error_class(), $label . ' preserves the structured result (' . ( $keepalive ? 'RSET' : 'QUIT' ) . ').' );
		sw_mailgate_check( count( $provider->messages ) === $before + ( empty( $scenario['fallback'] ) ? 0 : 1 ) && count( $instance->delivered_addresses ) === $scenario['delivered'], $label . ' never replays a delivered or uncertain message.' );
		$status = in_array( $scenario['error'], array( Send_Result::PARTIAL, Send_Result::UNCERTAIN ), true ) ? $scenario['error'] : ( '' === $scenario['error'] ? 'sent' : 'failed' );
		$row = $logs->search( array( 'connection_id' => 'primary' ), 1 )[0];
		sw_mailgate_check( $status === $row['status'], $label . ' is represented accurately in the mail log.' );
		if ( in_array( $label, array( 'mixed permanent', 'all permanent', 'all temporary', 'accepted' ), true ) ) {
			sw_mailgate_check( in_array( $keepalive ? 'RSET' : 'QUIT', $instance->commands, true ), 'Actual PHPMailer cleanup path was exercised.' );
		}
	}
}
$totals = $logs->totals( 7 );
sw_mailgate_check( 6 === $totals['partial'] && 2 === $totals['uncertain'], 'Dashboard totals keep partial and uncertain delivery separate from sent/failed.' );

// postSend() can return false instead of throwing when PHPMailer exceptions are disabled.
$mailer = new Mail_Catcher( false );
$smtp_provider->prepare( $mailer, $connections->find( 'primary' ) );
$mailer->setSMTPInstance( new SW_Mailgate_Scripted_SMTP( array( 'recipients' => array( 550 ) ) ) );
$mailer->setFrom( 'sender@example.test' );
$mailer->addAddress( 'other@example.test' );
$mailer->Subject = 'No exception fixture';
$mailer->Body = 'Body';
$mailer->preSend();
sw_mailgate_check( Send_Result::PERMANENT === $smtp_provider->send( new Envelope( $mailer ), $connections->find( 'primary' ) )->error_class(), 'A false postSend result is never reported as success.' );
update_option( Installer::DELETE_DATA_OPTION, true );
Installer::uninstall();
echo 'PASS: SMTP delivery regressions completed.' . PHP_EOL;
