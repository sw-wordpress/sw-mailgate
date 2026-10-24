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
use SWMailgate\Security\Secret_Store;

final class Plugin {

	private static ?Plugin $instance = null;

	private Dispatcher $dispatcher;

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
		$providers        = new Provider_Registry( array( new Smtp_Provider() ) );
		$this->dispatcher = new Dispatcher( $connections, $providers );

		// Late, so the catcher wins over plugins that also replace PHPMailer during plugins_loaded.
		add_action( 'plugins_loaded', array( $this, 'install_mailer' ), PHP_INT_MAX );
		add_filter( 'wp_mail', array( $this->dispatcher, 'begin_mail' ), 1 );
		add_filter( 'wp_mail_from', array( $this->dispatcher, 'filter_from' ), PHP_INT_MAX );

		if ( is_admin() ) {
			( new Admin_Page( $connections, $providers, $secrets, $this->dispatcher ) )->register();
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
		$catcher::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};

		$phpmailer = $catcher; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Replacing the mailer is the purpose of this plugin.
	}
}
