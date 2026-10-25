<?php
/**
 * Admin integration: connections list and editor, test mail page and their form handlers.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Admin;

use SWMailgate\Capabilities;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Mail\Attempt;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Redirector;
use SWMailgate\Providers\Field;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Security\Secret_Store;

final class Admin_Page {

	public const SLUG      = 'sw-mailgate';
	public const TEST_SLUG = 'sw-mailgate-test';
	private const HANDLE   = 'sw-mailgate-admin';

	/** Results of the last test are kept per user for the redirect after the form post. */
	private const RESULT_TRANSIENT = 'sw_mailgate_result_';

	/** @var string[] */
	private array $hooks = array();

	public function __construct(
		private readonly Connection_Repository $connections,
		private readonly Provider_Registry $providers,
		private readonly Secret_Store $secrets,
		private readonly Dispatcher $dispatcher,
		private readonly Redirector $redirector
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'key_notice' ) );
		add_action( 'admin_post_sw_mailgate_save_connection', array( $this, 'handle_save_connection' ) );
		add_action( 'admin_post_sw_mailgate_connection_action', array( $this, 'handle_connection_action' ) );
		add_action( 'admin_post_sw_mailgate_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_sw_mailgate_send_test', array( $this, 'handle_send_test' ) );
	}

	public function add_menu(): void {
		$this->hooks[] = (string) add_menu_page(
			__( 'Mailgate', 'sw-mailgate' ),
			__( 'Mailgate', 'sw-mailgate' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render_connections' ),
			'dashicons-email-alt',
			81
		);
		$this->hooks[] = (string) add_submenu_page(
			self::SLUG,
			__( 'Connections', 'sw-mailgate' ),
			__( 'Connections', 'sw-mailgate' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render_connections' )
		);
		$this->hooks[] = (string) add_submenu_page(
			self::SLUG,
			__( 'Send test mail', 'sw-mailgate' ),
			__( 'Test mail', 'sw-mailgate' ),
			Capabilities::MANAGE,
			self::TEST_SLUG,
			array( $this, 'render_test' )
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, SW_MAILGATE_URL . 'assets/admin.css', array(), SW_MAILGATE_VERSION );
	}

	public function key_notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! $this->secrets->has_key_mismatch() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'SW Mailgate: stored credentials cannot be decrypted because the encryption key changed (SW_MAILGATE_KEY or the WordPress salts). Please enter the passwords and API keys of your connections again.', 'sw-mailgate' )
		);
	}

	public function render_connections(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$view        = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$id          = isset( $_GET['connection'] ) ? sanitize_key( wp_unslash( $_GET['connection'] ) ) : '';
		$provider_id = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		// phpcs:enable

		$data = array(
			'notice'    => $this->current_notice(),
			'results'   => $this->take_results(),
			'providers' => $this->providers->all(),
		);

		if ( 'edit' === $view ) {
			$connection = '' === $id ? null : $this->connections->find( $id );
			$provider   = $this->providers->get( null === $connection ? $provider_id : $connection->provider() );
			if ( null !== $provider ) {
				$data['connection'] = $connection;
				$data['provider']   = $provider;
				$this->include_template( 'connection-edit', $data );
				return;
			}
		}

		$data['connections']  = $this->connections->all();
		$data['dedicated']    = Secret_Store::uses_dedicated_key();
		$data['chain_active'] = array() !== $this->connections->chain();
		$this->include_template( 'connections', $data );
	}

	public function render_test(): void {
		$this->require_capability();

		$this->include_template(
			'test',
			array(
				'notice'      => $this->current_notice(),
				'results'     => $this->take_results(),
				'connections' => $this->connections->all(),
				'recipient'   => wp_get_current_user()->user_email,
			)
		);
	}

	public function handle_save_connection(): void {
		check_admin_referer( 'sw_mailgate_save_connection' );
		$this->require_capability();

		$existing_id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		$existing    = '' === $existing_id ? null : $this->connections->find( $existing_id );
		$provider_id = null !== $existing
			? $existing->provider()
			: ( isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '' );
		$provider    = $this->providers->get( $provider_id );
		if ( null === $provider ) {
			$this->redirect( self::SLUG, 'unknown_provider' );
		}

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$label = '' === $label ? $provider->label() : $label;
		$id    = null !== $existing ? $existing->id() : $this->connections->unique_id( $label );

		$from_email = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
		if ( null !== $existing && $existing->is_locked( 'from_email' ) ) {
			$from_email = (string) $existing->to_array()['from_email'];
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized by its Field below.
		$posted_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized by its Field below.
		$posted_secrets = isset( $_POST['secrets'] ) && is_array( $_POST['secrets'] ) ? wp_unslash( $_POST['secrets'] ) : array();
		$clear_secrets  = array();
		if ( isset( $_POST['clear_secrets'] ) && is_array( $_POST['clear_secrets'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the keys are used, each passed through sanitize_key().
			$clear_secrets = array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['clear_secrets'] ) ) );
		}

		$settings = array();
		$secrets  = array();
		foreach ( $provider->fields() as $field ) {
			// Locked inputs are disabled and not posted; keep the stored value for when the constant is removed.
			if ( null !== $existing && $existing->is_locked( $field->key ) ) {
				if ( ! $field->secret && array_key_exists( $field->key, $existing->settings() ) ) {
					$settings[ $field->key ] = (string) $existing->settings()[ $field->key ];
				}
				continue;
			}
			if ( $field->secret ) {
				$secrets[ $field->key ] = $field->sanitize( $posted_secrets[ $field->key ] ?? '' );
				continue;
			}
			$settings[ $field->key ] = Field::CHECKBOX === $field->type
				? ( isset( $posted_settings[ $field->key ] ) ? '1' : '0' )
				: $field->sanitize( $posted_settings[ $field->key ] ?? $field->default );
		}

		$this->connections->save(
			array(
				'id'                => $id,
				'label'             => $label,
				'provider'          => $provider->id(),
				'enabled'           => isset( $_POST['enabled'] ),
				'from_email'        => $from_email,
				'from_name'         => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
				'force_from'        => isset( $_POST['force_from'] ),
				'reply_to_original' => isset( $_POST['reply_to_original'] ),
				'settings'          => $settings,
			),
			$secrets
		);
		foreach ( $clear_secrets as $key ) {
			if ( '' === ( $secrets[ $key ] ?? '' ) ) {
				$this->connections->delete_secret( $id, $key );
			}
		}

		$this->redirect(
			self::SLUG,
			'saved',
			array(
				'view'       => 'edit',
				'connection' => $id,
			)
		);
	}

	public function handle_connection_action(): void {
		$id     = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		$action = isset( $_POST['connection_action'] ) ? sanitize_key( wp_unslash( $_POST['connection_action'] ) ) : '';
		check_admin_referer( 'sw_mailgate_connection_' . $id );
		$this->require_capability();

		if ( null === $this->connections->find( $id ) ) {
			$this->redirect( self::SLUG, 'not_found' );
		}

		switch ( $action ) {
			case 'up':
			case 'down':
				$this->connections->move( $id, 'up' === $action ? -1 : 1 );
				$this->redirect( self::SLUG, 'moved' );
				break;
			case 'enable':
			case 'disable':
				$this->connections->set_enabled( $id, 'enable' === $action );
				$this->redirect( self::SLUG, 'enable' === $action ? 'enabled' : 'disabled' );
				break;
			case 'delete':
				$this->connections->delete( $id );
				$this->redirect( self::SLUG, 'deleted' );
				break;
		}
		$this->redirect( self::SLUG, 'not_found' );
	}

	public function handle_test_connection(): void {
		$id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		check_admin_referer( 'sw_mailgate_connection_' . $id );
		$this->require_capability();

		$connection = $this->connections->find( $id );
		$provider   = null === $connection ? null : $this->providers->get( $connection->provider() );
		if ( null === $connection || null === $provider ) {
			$this->redirect( self::SLUG, 'not_found' );
		}

		$start  = microtime( true );
		$result = $provider->test_connection( $connection )->with_duration( microtime( true ) - $start );
		$this->store_results(
			__( 'Connection test', 'sw-mailgate' ),
			array( $this->result_row( $connection, $result ) )
		);

		$this->redirect(
			self::SLUG,
			'',
			array(
				'view'       => 'edit',
				'connection' => $id,
			)
		);
	}

	public function handle_send_test(): void {
		check_admin_referer( 'sw_mailgate_send_test' );
		$this->require_capability();

		$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		$only      = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		if ( ! is_email( $recipient ) ) {
			$this->redirect( self::TEST_SLUG, 'invalid_recipient' );
		}
		if ( '' !== $only && null === $this->connections->find( $only ) ) {
			$this->redirect( self::TEST_SLUG, 'not_found' );
		}

		$error   = null;
		$capture = static function ( \WP_Error $wp_error ) use ( &$error ): void {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );
		$this->dispatcher->restrict_to( '' === $only ? null : $only );

		$send = fn(): bool => wp_mail(
			$recipient,
			/* translators: %s: site name */
			sprintf( __( '[%s] SW Mailgate test mail', 'sw-mailgate' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$this->test_body(),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		try {
			$sent = isset( $_POST['ignore_redirection'] ) ? $this->redirector->without_redirection( $send ) : $send();
		} finally {
			$this->dispatcher->restrict_to( null );
			remove_action( 'wp_mail_failed', $capture );
		}

		$rows = array_map(
			fn( Attempt $attempt ): array => $this->result_row( $attempt->connection, $attempt->result, $attempt->from ),
			$this->dispatcher->last_attempts()
		);
		if ( array() === $rows ) {
			// No active connection: WordPress sent through PHP mail().
			$rows[] = array(
				'connection' => __( 'PHP mail() (WordPress default)', 'sw-mailgate' ),
				'success'    => $sent,
				'class'      => '',
				'message'    => (string) $error,
				'duration'   => '',
				'from'       => '',
				'debug'      => array(),
			);
		}
		$this->store_results( __( 'Test mail', 'sw-mailgate' ), $rows );

		$this->redirect( self::TEST_SLUG, $sent ? 'test_sent' : 'test_failed' );
	}

	private function test_body(): string {
		return sprintf(
			'<p>%1$s</p><p>%2$s<br>%3$s</p>',
			esc_html__( 'This is a test mail sent by SW Mailgate. If you can read it, the connection works.', 'sw-mailgate' ),
			/* translators: %s: site URL */
			esc_html( sprintf( __( 'Site: %s', 'sw-mailgate' ), home_url() ) ),
			/* translators: %s: date and time */
			esc_html( sprintf( __( 'Time: %s', 'sw-mailgate' ), wp_date( 'Y-m-d H:i:s' ) ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function result_row( Connection $connection, Send_Result $result, string $from = '' ): array {
		return array(
			'connection' => $connection->label(),
			'success'    => $result->is_success(),
			'class'      => $result->error_class(),
			'message'    => $result->message(),
			'duration'   => number_format_i18n( $result->duration(), 2 ) . ' s',
			'from'       => $from,
			'debug'      => $result->debug(),
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function store_results( string $title, array $rows ): void {
		set_transient(
			self::RESULT_TRANSIENT . get_current_user_id(),
			array(
				'title' => $title,
				'rows'  => $rows,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * @return array{title: string, rows: list<array<string, mixed>>}|null
	 */
	private function take_results(): ?array {
		$key     = self::RESULT_TRANSIENT . get_current_user_id();
		$results = get_transient( $key );
		if ( false === $results ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $results ) ? $results : null;
	}

	/**
	 * Notice texts are an allowlist, so the query argument is never echoed.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function current_notice(): ?array {
		$messages = array(
			'saved'             => array( 'success', __( 'Connection saved.', 'sw-mailgate' ) ),
			'deleted'           => array( 'success', __( 'Connection deleted.', 'sw-mailgate' ) ),
			'moved'             => array( 'success', __( 'Failover order changed.', 'sw-mailgate' ) ),
			'enabled'           => array( 'success', __( 'Connection enabled.', 'sw-mailgate' ) ),
			'disabled'          => array( 'success', __( 'Connection disabled.', 'sw-mailgate' ) ),
			'not_found'         => array( 'error', __( 'The connection does not exist.', 'sw-mailgate' ) ),
			'unknown_provider'  => array( 'error', __( 'Unknown provider.', 'sw-mailgate' ) ),
			'invalid_recipient' => array( 'error', __( 'Please enter a valid recipient address.', 'sw-mailgate' ) ),
			'test_sent'         => array( 'success', __( 'The test mail was sent.', 'sw-mailgate' ) ),
			'test_failed'       => array( 'error', __( 'The test mail could not be sent.', 'sw-mailgate' ) ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a message from the allowlist above.
		$code = isset( $_GET['swmg_notice'] ) ? sanitize_key( wp_unslash( $_GET['swmg_notice'] ) ) : '';
		if ( ! isset( $messages[ $code ] ) ) {
			return null;
		}
		return array(
			'type'    => $messages[ $code ][0],
			'message' => $messages[ $code ][1],
		);
	}

	private function require_capability(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sw-mailgate' ), 403 );
		}
	}

	/**
	 * @param array<string, string> $args
	 */
	private function redirect( string $page, string $notice, array $args = array() ): never {
		$args['page'] = $page;
		if ( '' !== $notice ) {
			$args['swmg_notice'] = $notice;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function include_template( string $name, array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is read by the template.
		include SW_MAILGATE_DIR . 'templates/admin/' . $name . '.php';
	}

	public static function url( array $args = array(), string $page = self::SLUG ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}
}
