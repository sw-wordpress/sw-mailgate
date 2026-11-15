<?php
/** Validate uploaded artifacts with the runtime verifier before publishing a draft. CLI build tool only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', '/swmg-release-validation/');
define('DAY_IN_SECONDS', 86400);
class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
}
function __(string $text, string $domain = ''): string { return $text; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/src/Update/Manifest.php';
require dirname(__DIR__) . '/src/Update/Package.php';
try {
    $release = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($release) || true !== ($release['draft'] ?? null)) { throw new RuntimeException('Expected an unpublished draft.'); }
    // Verify the exact future published state while the release remains a draft.
    $release['draft'] = false;
    $directory = $argv[2];
    $keys = require ($argv[3] ?? dirname(__DIR__) . '/src/Update/Config/trusted-keys.php');
    $manifest = \SWMailgate\Update\Manifest::verify(file_get_contents($directory . '/sw-plugin-release.json'), file_get_contents($directory . '/sw-plugin-release.json.sig'), $keys, $release);
    if (is_wp_error($manifest)) { throw new RuntimeException($manifest->message); }
    foreach ($manifest['packages'] as $package) {
        $result = \SWMailgate\Update\Package::verify($directory . '/' . $package['filename'], $package);
        if (is_wp_error($result)) { throw new RuntimeException($result->message); }
    }
    echo "Verified uploaded manifest, signature and ZIP with runtime verifiers.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Release validation failed: ' . $error->getMessage() . "\n");
    exit(1);
}
