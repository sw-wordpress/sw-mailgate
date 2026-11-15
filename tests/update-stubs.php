<?php
/** Minimal update-policy APIs for standalone uninstall tests; real WP tests cover cleanup. */
function get_site_option(string $name, mixed $default = false): mixed { return $default; }
function update_site_option(string $name, mixed $value): bool { return true; }
function delete_site_option(string $name): bool { return true; }
function get_site_transient(string $name): mixed { return false; }
function remove_filter(string $name, mixed $callback): bool { return true; }
function apply_filters(string $hook, mixed $value): mixed { return $value; }
