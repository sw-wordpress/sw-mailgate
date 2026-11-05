<?php
/** Deterministic native DNS adapter fixture. @package SWMailgate */
namespace SWMailgate\Dns;
function dns_get_record( string $name, int $type ): array|false {
	$GLOBALS['swmg_native_queries'][] = array( $name, $type );
	return $GLOBALS['swmg_native_records'][ $name ][ $type ] ?? false;
}
