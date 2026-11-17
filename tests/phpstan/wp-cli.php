<?php
/** WP-CLI is provided by the CLI host, never bundled with the plugin. */
namespace {
	class WP_CLI {
		public static function error( string $message ): never { throw new \RuntimeException( $message ); }
		public static function success( string $message ): void {}
		public static function add_command( string $name, object $command ): void {}
	}
}
namespace WP_CLI\Utils {
	/** @param array<array<string, mixed>> $items
	 * @param list<string> $fields */
	function format_items( string $format, array $items, array $fields ): void {}
}
