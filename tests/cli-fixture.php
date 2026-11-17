<?php
/** CLI boundary fixture; real repositories, dispatcher and WordPress run in integration.php. */
namespace {
	class WP_CLI {
		public static array $commands = array();
		public static function error( string $message ): never { throw new \RuntimeException( $message ); }
		public static function success( string $message ): void { echo $message; }
		public static function add_command( string $name, object $command ): void { self::$commands[$name] = $command; }
	}
}
namespace WP_CLI\Utils {
	function format_items( string $format, array $items, array $fields ): void {
		echo json_encode( array( 'format' => $format, 'items' => $items, 'fields' => $fields ), JSON_THROW_ON_ERROR );
	}
}
