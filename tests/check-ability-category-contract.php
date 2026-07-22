<?php
/** Toolset ability-category ownership contract. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$toolset_test_abilities = array();

/**
 * Capture public ability definitions without booting WordPress.
 *
 * @param string              $name Ability name.
 * @param array<string,mixed> $args Ability definition.
 * @return null
 */
function wp_register_ability( string $name, array $args ) {
	global $toolset_test_abilities;
	$toolset_test_abilities[ $name ] = $args;
	return null;
}

/** Stub WordPress hook registration for the isolated contract. */
function add_action(): void {
}

/** Keep admin-only hooks outside the isolated registration contract. */
function is_admin(): bool {
	return false;
}

require dirname( __DIR__ ) . '/mcp-abilities-toolset.php';

mcp_register_toolset_abilities();

$user_abilities = array(
	'toolset/get-user-fields',
	'toolset/get-user',
	'toolset/list-users',
	'toolset/list-roles',
	'toolset/get-role-capabilities',
	'toolset/get-users-by-role',
	'toolset/get-user-capabilities',
);

foreach ( $user_abilities as $ability_name ) {
	if ( ! isset( $toolset_test_abilities[ $ability_name ] ) ) {
		throw new RuntimeException( 'Missing Toolset user ability: ' . $ability_name );
	}
	if ( 'user' !== $toolset_test_abilities[ $ability_name ]['category'] ) {
		throw new RuntimeException( $ability_name . ' must use the WordPress core user category.' );
	}
}

foreach ( $toolset_test_abilities as $ability_name => $definition ) {
	if ( ! in_array( $definition['category'], array( 'site', 'user' ), true ) ) {
		throw new RuntimeException( $ability_name . ' references unowned category ' . $definition['category'] . '.' );
	}
}

fwrite( STDOUT, "Toolset ability-category contract passed.\n" );
