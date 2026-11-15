<?php
/** A second independent SW provider, derived locally for coexistence tests; never shipped. */
function swmg_test_other_provider(): string {
    $directory = sys_get_temp_dir() . '/swmg-other-provider-' . bin2hex(random_bytes(8));
    mkdir($directory . '/Config', 0700, true);
    foreach (glob(dirname(__DIR__, 2) . '/src/Update/*.php') as $file) {
        $code = str_replace(
            array('SWMailgate\\Update', 'SW_MAILGATE_', 'swmg_', 'swmg-', 'swmgVerifiedUpdates', 'sw-mailgate'),
            array('SWMailgateTest\\OtherUpdate', 'SWMG_OTHER_', 'swmg_other_', 'swmg-other-', 'swmgOtherVerifiedUpdates', 'other-sw-plugin'),
            file_get_contents($file)
        );
        file_put_contents($directory . '/' . basename($file), $code);
    }
    $source = require dirname(__DIR__, 2) . '/src/Update/Config/source.php';
    $source['repository'] = 'sw-wordpress/other-sw-plugin';
    $source['repository_id'] = 777;
    $source['main_slug'] = 'other-sw-plugin';
    $source['allowed_slugs'] = array('other-sw-plugin');
    $source['state_key'] = 'swmg_other_verified_github_updates_v1';
    $source['lock_key'] = 'swmg_other_verified_github_updates_lock_v1';
    foreach (array('source' => $source, 'descriptors' => array('other-sw-plugin/other-sw-plugin.php' => array('slug' => 'other-sw-plugin', 'version' => '1.0.0')), 'trusted-keys' => array()) as $name => $value) {
        file_put_contents($directory . '/Config/' . $name . '.php', '<?php return ' . var_export($value, true) . ';');
    }
    foreach (array('Manifest', 'Package', 'Store', 'Transport', 'Service', 'Provider') as $class) {
        require $directory . '/' . $class . '.php';
    }
    return $directory;
}
