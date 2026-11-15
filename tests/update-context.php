<?php
/** Constant-isolated context and missing-extension guards; no WordPress, DB or HTTP. */
declare(strict_types=1);
$mode = $argv[1] ?? '';
define('ABSPATH', '/swmg-guard-test/');
if ('cli' === $mode) { define('WP_CLI', true); }
if ('rest' === $mode) { define('REST_REQUEST', true); }
class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
}
function __(string $text, string $domain = ''): string { return $text; }
function is_admin(): bool { return true; }
function wp_doing_cron(): bool { return false; }
function is_multisite(): bool { return false; }
function current_user_can(string $cap): bool { return true; }
function wp_is_file_mod_allowed(string $context): bool { return true; }
require dirname(__DIR__) . '/vendor/autoload.php';
if ('support' === $mode) {
    if (function_exists('sodium_crypto_sign_verify_detached') || class_exists('ZipArchive')) { throw new RuntimeException('Run with php -n and sodium verification disabled.'); }
    $manifest = \SWMailgate\Update\Manifest::verify('{}', '{}', array(), array());
    $package = \SWMailgate\Update\Package::verify(__FILE__, array('bytes' => filesize(__FILE__), 'sha256' => hash_file('sha256', __FILE__)));
    if (!$manifest instanceof WP_Error || !$package instanceof WP_Error) { throw new RuntimeException('Missing support must fail closed.'); }
} else {
    $result = \SWMailgate\Update\Service::check();
    if (!$result instanceof WP_Error || 'updates_forbidden' !== $result->code) { throw new RuntimeException('Unsupported context passed the guard.'); }
}
echo "PASS: update guard $mode (no HTTP or DB APIs available).\n";
