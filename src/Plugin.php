<?php
/**
 * Plugin bootstrap and service wiring.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate;

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Smtp_Provider;
use SWMailgate\Providers\Mailgun_Provider;
use SWMailgate\Security\Secret_Store;
use SWMailgate\Logging\Logger;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Logging\Privacy;
use SWMailgate\Admin\Log_Page;
use SWMailgate\Mail\Redirector;
use SWMailgate\Routing\Circuit_Breaker;
use SWMailgate\Routing\Failure_Alert;
use SWMailgate\Routing\Retry_Queue;

final class Plugin {

	private static ?Plugin $instance = null;

	private Dispatcher $dispatcher;
	private Redirector $redirector;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );

		$secrets          = new Secret_Store();
		$connections      = new Connection_Repository( $secrets );
		$providers        = new Provider_Registry( array( new Smtp_Provider(), new Mailgun_Provider() ) );
		$breaker          = new Circuit_Breaker();
		$queue            = new Retry_Queue( $secrets );
		$this->dispatcher = new Dispatcher( $connections, $providers, $breaker, $queue );
		$this->redirector = new Redirector();
		$logs             = new Log_Repository();
		( new Logger( $logs ) )->register();
		( new Privacy( $logs, $queue ) )->register();
		add_action( 'init', array( Logger::class, 'schedule' ) );
		add_action( 'sw_mailgate_attempt', array( $breaker, 'record' ) );
		$queue->register( $this->dispatcher );
		( new Failure_Alert( $this->dispatcher ) )->register();

		// Late, so the catcher wins over plugins that also replace PHPMailer during plugins_loaded.
		add_action( 'plugins_loaded', array( $this, 'install_mailer' ), PHP_INT_MAX );
		add_filter( 'wp_mail', array( $this->dispatcher, 'begin_mail' ), 1 );
		add_filter( 'wp_mail_from', array( $this->dispatcher, 'filter_from' ), PHP_INT_MAX );

		if ( is_admin() ) {
			( new Admin_Page( $connections, $providers, $secrets, $this->dispatcher, $this->redirector, $breaker ) )->register();
			( new Log_Page( $logs, $connections, $queue, $this->dispatcher ) )->register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'sw-mailgate', false, dirname( plugin_basename( SW_MAILGATE_FILE ) ) . '/languages' );
	}

	/**
	 * Puts the Mail_Catcher into the global that wp_mail() reuses. wp_mail() only creates its own
	 * instance when the global is not a PHPMailer object.
	 */
	public function install_mailer(): void {
		global $phpmailer;

		if ( $phpmailer instanceof Mail_Catcher ) {
			return;
		}

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';

		$catcher = new Mail_Catcher( true );
		$catcher->set_dispatcher( $this->dispatcher );
		$catcher->set_redirector( $this->redirector );
		$catcher::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};

		$phpmailer = $catcher; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Replacing the mailer is the purpose of this plugin.
	}
}
