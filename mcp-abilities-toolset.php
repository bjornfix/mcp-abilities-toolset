<?php
/**
 * Plugin Name: MCP Abilities - Toolset
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-toolset
 * Description: Toolset abilities for MCP. Manage custom post types, fields, and relationships created with Toolset.
 * Version: 1.0.3
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Toolset
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Abilities API is available.
 */
function mcp_toolset_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Toolset</strong> requires the <a href="https://github.com/WordPress/abilities-api">Abilities API</a> plugin to be installed and activated.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Get all registered post types.
 */
function mcp_toolset_get_post_types(): array {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	$toolset_types = array();

	foreach ( $types as $type ) {
		if ( 'attachment' === $type->name ) {
			continue;
		}
		$toolset_types[] = array(
			'name'  => $type->name,
			'label' => $type->labels->singular_name,
		);
	}

	return $toolset_types;
}

/**
 * Get custom fields for a post type.
 */
function mcp_toolset_parse_group_list( $value ): array {
	if ( is_array( $value ) ) {
		return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
	}
	if ( ! is_string( $value ) || $value === '' ) {
		return array();
	}
	$parts = array_map( 'trim', explode( ',', $value ) );
	return array_values( array_filter( array_map( 'sanitize_key', $parts ) ) );
}

function mcp_toolset_get_field_definitions(): array {
	$fields = get_option( 'wpcf-fields', array() );
	return is_array( $fields ) ? $fields : array();
}

function mcp_toolset_sanitize_meta_compare( $compare ): string {
	$allowed = array(
		'=',
		'!=',
		'>',
		'>=',
		'<',
		'<=',
		'LIKE',
		'NOT LIKE',
		'IN',
		'NOT IN',
		'BETWEEN',
		'NOT BETWEEN',
		'EXISTS',
		'NOT EXISTS',
		'REGEXP',
		'NOT REGEXP',
		'RLIKE',
	);
	$compare = strtoupper( trim( (string) $compare ) );
	return in_array( $compare, $allowed, true ) ? $compare : '=';
}

function mcp_toolset_save_field_definition( array $input ): array {
	$slug = sanitize_key( $input['slug'] ?? '' );
	if ( empty( $slug ) ) {
		return array(
			'success' => false,
			'message' => 'Field slug is required.',
		);
	}

	$fields = mcp_toolset_get_field_definitions();

		$field = array(
			'id'        => $slug,
			'name'      => $input['name'] ?? ucfirst( $slug ),
			'slug'      => $slug,
			'type'      => $input['type'] ?? 'textfield',
		'data'      => array(
			'title'       => $input['name'] ?? ucfirst( $slug ),
			'description' => $input['description'] ?? '',
		),
			'meta_key'  => $input['meta_key'] ?? 'wpcf-' . $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_type' => 'postmeta',
		);

	$options = $input['options'] ?? array();
	if ( ! empty( $options ) && in_array( $field['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
		$field['data']['options'] = array();
		$default = $input['default'] ?? '';
		foreach ( $options as $key => $title ) {
			$value = (string) $key;
			$field['data']['options'][ $key ] = array(
				'title' => $title,
				'value' => $value,
			);
		}
		if ( ! empty( $default ) && isset( $field['data']['options'][ $default ] ) ) {
			$field['data']['default'] = (string) $default;
		}
	}

	if ( in_array( $field['type'], array( 'textfield', 'textarea', 'numeric' ), true ) && ! empty( $input['default'] ) ) {
		$field['data']['default_value'] = $input['default'];
	}

	$fields[ $slug ] = $field;
	update_option( 'wpcf-fields', $fields );

	delete_transient( 'wpcf_fields' );

	return array(
		'success' => true,
		'field'   => $field,
	);
}

function mcp_toolset_get_post_type_fields( string $post_type ): array {
	static $cache = array();
	$post_type = sanitize_key( $post_type );
	if ( isset( $cache[ $post_type ] ) ) {
		return $cache[ $post_type ];
	}

	$fields = array();
	$definitions = mcp_toolset_get_field_definitions();

	$page = 1;
	do {
		$groups = get_posts(
			array(
				'post_type'      => 'wp-types-group',
				'posts_per_page' => 200,
				'paged'          => $page,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'no_found_rows'  => true,
			)
		);

		foreach ( $groups as $group ) {
			$post_types = mcp_toolset_parse_group_list( get_post_meta( $group->ID, '_wp_types_group_post_types', true ) );
			if ( ! in_array( $post_type, $post_types, true ) ) {
				continue;
			}
			$field_slugs = mcp_toolset_parse_group_list( get_post_meta( $group->ID, '_wp_types_group_fields', true ) );
			foreach ( $field_slugs as $slug ) {
				$def = $definitions[ $slug ] ?? array();
				$label = $def['name'] ?? $slug;
				if ( empty( $def['name'] ) && isset( $def['data'] ) && is_array( $def['data'] ) && ! empty( $def['data']['title'] ) ) {
					$label = $def['data']['title'];
				}
				$fields[] = array(
					'name'          => $slug,
					'label'         => $label,
					'type'          => $def['type'] ?? 'text',
					'is_repeatable' => $def['repeatable'] ?? false,
				);
			}
		}
		$page++;
	} while ( ! empty( $groups ) );

	$cache[ $post_type ] = $fields;
	return $fields;
}

/**
 * Get taxonomies for a post type.
 */
function mcp_toolset_get_post_type_taxonomies( string $post_type ): array {
	$taxonomies = get_object_taxonomies( $post_type, 'objects' );
	$toolset_taxes = array();

	foreach ( $taxonomies as $tax ) {
		$toolset_taxes[] = array(
			'name'  => $tax->name,
			'label' => $tax->labels->singular_name,
		);
	}

	return $toolset_taxes;
}

/**
 * Convert meta key to Toolset format (wpcf_* → wpcf-*).
 * Toolset expects hyphens in meta keys, not underscores.
 * AI callers naturally use underscores; this converts them automatically.
 *
 * @param string $key Meta key.
 * @return string Converted meta key.
 */
function mcp_toolset_convert_meta_key( string $key ): string {
	if ( str_starts_with( $key, 'wpcf_' ) ) {
		return 'wpcf-' . substr( $key, 5 );
	}
	return $key;
}

/**
 * Get taxonomy UI config for custom admin meta boxes.
 */
function mcp_toolset_get_taxonomy_ui_config(): array {
	$config = get_option( 'mcp_toolset_taxonomy_ui', array() );
	return is_array( $config ) ? $config : array();
}

/**
 * Persist taxonomy UI config.
 */
function mcp_toolset_set_taxonomy_ui_config( string $taxonomy, string $post_type, string $ui ): void {
	$config = mcp_toolset_get_taxonomy_ui_config();
	if ( empty( $config[ $post_type ] ) || ! is_array( $config[ $post_type ] ) ) {
		$config[ $post_type ] = array();
	}

	if ( 'checkboxes' === $ui ) {
		unset( $config[ $post_type ][ $taxonomy ] );
		if ( empty( $config[ $post_type ] ) ) {
			unset( $config[ $post_type ] );
		}
	} else {
		$config[ $post_type ][ $taxonomy ] = array(
			'ui' => $ui,
		);
	}

	update_option( 'mcp_toolset_taxonomy_ui', $config );
}

/**
 * Register custom taxonomy UI meta boxes.
 */
function mcp_toolset_register_taxonomy_ui_meta_boxes( $post_type, $post ): void {
	$config = mcp_toolset_get_taxonomy_ui_config();
	$post_type = is_string( $post_type ) ? $post_type : '';

	if ( empty( $post_type ) || empty( $config[ $post_type ] ) || ! is_array( $config[ $post_type ] ) ) {
		return;
	}

	foreach ( $config[ $post_type ] as $taxonomy => $settings ) {
		$ui = $settings['ui'] ?? '';
		if ( ! taxonomy_exists( $taxonomy ) || ! in_array( $ui, array( 'radios', 'dropdown' ), true ) ) {
			continue;
		}

		remove_meta_box( $taxonomy . 'div', $post_type, 'side' );
		remove_meta_box( 'tagsdiv-' . $taxonomy, $post_type, 'side' );

		$tax_obj = get_taxonomy( $taxonomy );
		$label   = $tax_obj && ! empty( $tax_obj->labels->singular_name ) ? $tax_obj->labels->singular_name : $taxonomy;

		add_meta_box(
			'mcp_toolset_tax_ui_' . $taxonomy,
			$label,
			'mcp_toolset_render_taxonomy_ui_metabox',
			$post_type,
			'side',
			'default',
			array(
				'taxonomy' => $taxonomy,
				'ui'       => $ui,
			)
		);
	}
}

/**
 * Render taxonomy UI meta box.
 */
function mcp_toolset_render_taxonomy_ui_metabox( $post, array $metabox ): void {
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$taxonomy = $metabox['args']['taxonomy'] ?? '';
	$ui       = $metabox['args']['ui'] ?? '';

	if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
		return;
	}

	if ( empty( $ui ) ) {
		$config = mcp_toolset_get_taxonomy_ui_config();
		$ui = $config[ $post->post_type ][ $taxonomy ]['ui'] ?? 'checkboxes';
	}

	$nonce_name = 'mcp_toolset_tax_ui_nonce_' . $taxonomy;
	$nonce_action = 'mcp_toolset_tax_ui_' . $taxonomy;
	wp_nonce_field( $nonce_action, $nonce_name );

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);

	$selected = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
	$selected_id = ! empty( $selected ) ? (int) $selected[0] : 0;

	$field_name = 'mcp_toolset_tax_ui[' . esc_attr( $taxonomy ) . ']';

	if ( 'dropdown' === $ui ) {
		wp_dropdown_categories(
			array(
				'taxonomy'         => $taxonomy,
				'name'             => $field_name,
				'selected'         => $selected_id,
				'show_option_none' => '— None —',
				'option_none_value'=> 0,
				'hide_empty'       => false,
			)
		);
		return;
	}

	echo '<div class="mcp-toolset-tax-ui mcp-toolset-tax-ui-radios">';
	if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			printf(
				'<label style="display:block;margin:6px 0;"><input type="radio" name="%1$s" value="%2$d" %3$s /> %4$s</label>',
				esc_attr( $field_name ),
				(int) $term->term_id,
				checked( $selected_id, (int) $term->term_id, false ),
				esc_html( $term->name )
			);
		}
	} else {
		echo '<em>No terms available.</em>';
	}
	echo '</div>';
}

/**
 * Filter taxonomy args to attach custom UI meta box when configured.
 */
function mcp_toolset_filter_taxonomy_args( array $args, string $taxonomy ): array {
	$config = mcp_toolset_get_taxonomy_ui_config();
	foreach ( $config as $post_type => $taxes ) {
		if ( isset( $taxes[ $taxonomy ] ) && in_array( $taxes[ $taxonomy ]['ui'] ?? '', array( 'radios', 'dropdown' ), true ) ) {
			$args['show_ui'] = true;
			$args['meta_box_cb'] = 'mcp_toolset_render_taxonomy_ui_metabox';
			break;
		}
	}
	return $args;
}

/**
 * Update already-registered taxonomy objects when config exists.
 */
function mcp_toolset_registered_taxonomy( string $taxonomy, $object_type, $args ): void {
	$config = mcp_toolset_get_taxonomy_ui_config();
	foreach ( $config as $post_type => $taxes ) {
		if ( isset( $taxes[ $taxonomy ] ) && in_array( $taxes[ $taxonomy ]['ui'] ?? '', array( 'radios', 'dropdown' ), true ) ) {
			$tax_obj = get_taxonomy( $taxonomy );
			if ( $tax_obj ) {
				$tax_obj->show_ui = true;
				$tax_obj->meta_box_cb = 'mcp_toolset_render_taxonomy_ui_metabox';
			}
			break;
		}
	}
}

/**
 * Save taxonomy UI meta box values.
 */
function mcp_toolset_save_taxonomy_ui_meta( int $post_id, $post ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! $post instanceof WP_Post ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$config = mcp_toolset_get_taxonomy_ui_config();
	if ( empty( $config[ $post->post_type ] ) || ! is_array( $config[ $post->post_type ] ) ) {
		return;
	}

	foreach ( $config[ $post->post_type ] as $taxonomy => $settings ) {
		$ui = $settings['ui'] ?? '';
		if ( ! in_array( $ui, array( 'radios', 'dropdown' ), true ) ) {
			continue;
		}

		$nonce_name = 'mcp_toolset_tax_ui_nonce_' . $taxonomy;
		$nonce_action = 'mcp_toolset_tax_ui_' . $taxonomy;
		if ( empty( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
			continue;
		}

		$raw = isset( $_POST['mcp_toolset_tax_ui'] ) ? wp_unslash( $_POST['mcp_toolset_tax_ui'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$incoming = $raw[ $taxonomy ] ?? 0;
		if ( is_array( $incoming ) ) {
			$incoming = 0;
		}
		$incoming = sanitize_text_field( (string) $incoming );
		$term_id = is_numeric( $incoming ) ? (int) $incoming : 0;

		if ( $term_id > 0 ) {
			wp_set_post_terms( $post_id, array( $term_id ), $taxonomy, false );
		} else {
			wp_set_post_terms( $post_id, array(), $taxonomy, false );
		}
	}
}

if ( is_admin() ) {
	add_action( 'add_meta_boxes', 'mcp_toolset_register_taxonomy_ui_meta_boxes', 20, 2 );
	add_action( 'save_post', 'mcp_toolset_save_taxonomy_ui_meta', 10, 2 );
	add_filter( 'register_taxonomy_args', 'mcp_toolset_filter_taxonomy_args', 10, 2 );
	add_action( 'registered_taxonomy', 'mcp_toolset_registered_taxonomy', 10, 3 );
}

/**
 * Create a post with custom fields.
 */
function mcp_toolset_create_post( string $post_type, string $title, string $content, array $meta = array(), array $terms = array() ): array {
	$post_type = sanitize_key( $post_type );
	if ( empty( $post_type ) || ! post_type_exists( $post_type ) ) {
		return array(
			'success' => false,
			'message' => 'Invalid post type.',
		);
	}

	$post_data = array(
		'post_title'   => sanitize_text_field( $title ),
		'post_content' => wp_kses_post( $content ),
		'post_status'  => 'draft',
		'post_type'    => $post_type,
	);

	$post_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $post_id ) ) {
		return array(
			'success' => false,
			'message' => 'Failed to create post: ' . $post_id->get_error_message(),
		);
	}

	if ( ! empty( $meta ) ) {
		foreach ( $meta as $key => $value ) {
			$converted_key = sanitize_key( mcp_toolset_convert_meta_key( (string) $key ) );
			update_post_meta( $post_id, $converted_key, $value );
		}
	}

	if ( ! empty( $terms ) ) {
		foreach ( $terms as $taxonomy => $term_names ) {
			$taxonomy = sanitize_key( $taxonomy );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$term_ids = array();
			foreach ( (array) $term_names as $term_name ) {
				$term_name = sanitize_text_field( (string) $term_name );
				$term = get_term_by( 'name', $term_name, $taxonomy );
				if ( $term ) {
					$term_ids[] = $term->term_id;
				} else {
					$new_term = wp_insert_term( $term_name, $taxonomy );
					if ( ! is_wp_error( $new_term ) ) {
						$term_ids[] = $new_term['term_id'];
					}
				}
			}
			if ( ! empty( $term_ids ) ) {
				wp_set_post_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}
	}

	return array(
		'success'   => true,
		'post_id'   => $post_id,
		'edit_link' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'message'   => 'Post created successfully.',
	);
}

/**
 * Get post with custom fields.
 */
function mcp_toolset_get_post( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return array(
			'success' => false,
			'message' => 'Post not found.',
		);
	}

	$meta = get_post_meta( $post_id );
	$meta_array = array();
	foreach ( $meta as $key => $values ) {
		$meta_array[ $key ] = count( $values ) === 1 ? $values[0] : $values;
	}

	$taxonomies = get_object_taxonomies( $post->post_type );
	$terms_array = array();
	foreach ( $taxonomies as $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) ) {
			$terms_array[ $taxonomy ] = $terms;
		}
	}

	return array(
		'success'      => true,
		'id'           => $post->ID,
		'title'        => $post->post_title,
		'content'      => $post->post_content,
		'status'       => $post->post_status,
		'slug'         => $post->post_name,
		'link'         => get_permalink( $post_id ),
		'edit_link'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'meta'         => $meta_array,
		'terms'        => $terms_array,
		'post_type'    => $post->post_type,
		'message'      => 'Post retrieved successfully.',
	);
}

/**
 * List posts with optional filtering.
 */
function mcp_toolset_list_posts( string $post_type, array $filters = array() ): array {
	$post_type = sanitize_key( $post_type );
	$args = array(
		'post_type'      => $post_type,
		'posts_per_page' => $filters['limit'] ?? 50,
		'offset'         => $filters['offset'] ?? 0,
		'post_status'    => $filters['status'] ?? 'any',
	);

	if ( ! empty( $filters['meta_key'] ) && isset( $filters['meta_value'] ) ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => sanitize_key( $filters['meta_key'] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'value'   => $filters['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'compare' => mcp_toolset_sanitize_meta_compare( $filters['meta_compare'] ?? '=' ),
			),
		);
	}

	if ( ! empty( $filters['taxonomy'] ) && ! empty( $filters['term'] ) ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => sanitize_key( $filters['taxonomy'] ),
				'field'    => 'name',
				'terms'    => (array) $filters['term'],
			),
		);
	}

	if ( ! empty( $filters['search'] ) ) {
		$args['s'] = $filters['search'];
	}

	$query = new WP_Query( $args );

	$posts = array();
	while ( $query->have_posts() ) {
		$query->the_post();
		$post_id = get_the_ID();
		$posts[] = array(
			'id'        => $post_id,
			'title'     => get_the_title(),
			'status'    => get_post_status(),
			'slug'      => get_post_field( 'post_name' ),
			'link'      => get_permalink(),
			'edit_link' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'date'      => get_the_date( 'c' ),
		);
	}
	wp_reset_postdata();

	return array(
		'success' => true,
		'posts'   => $posts,
		'count'   => count( $posts ),
		'total'   => $query->found_posts,
		'message' => 'Retrieved ' . count( $posts ) . ' posts.',
	);
}

/**
 * Update post with custom fields.
 */
function mcp_toolset_update_post( int $post_id, array $data = array() ): array {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return array(
			'success' => false,
			'message' => 'Post not found.',
		);
	}

	$update_data = array( 'ID' => $post_id );

	if ( isset( $data['title'] ) ) {
		$update_data['post_title'] = sanitize_text_field( $data['title'] );
	}
	if ( isset( $data['content'] ) ) {
		$update_data['post_content'] = wp_kses_post( $data['content'] );
	}
	if ( isset( $data['status'] ) ) {
		$status = sanitize_key( $data['status'] );
		$valid_statuses = array_keys( get_post_stati() );
		if ( in_array( $status, $valid_statuses, true ) ) {
			$update_data['post_status'] = $status;
		}
	}

	if ( count( $update_data ) > 1 ) {
		$result = wp_update_post( $update_data, true );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => 'Failed to update post: ' . $result->get_error_message(),
			);
		}
	}

	if ( ! empty( $data['meta'] ) ) {
		foreach ( $data['meta'] as $key => $value ) {
			$converted_key = sanitize_key( mcp_toolset_convert_meta_key( (string) $key ) );
			update_post_meta( $post_id, $converted_key, $value );
		}
	}

	if ( ! empty( $data['terms'] ) ) {
		foreach ( $data['terms'] as $taxonomy => $term_names ) {
			$taxonomy = sanitize_key( $taxonomy );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$term_ids = array();
			foreach ( (array) $term_names as $term_name ) {
				$term_name = sanitize_text_field( (string) $term_name );
				$term = get_term_by( 'name', $term_name, $taxonomy );
				if ( $term ) {
					$term_ids[] = $term->term_id;
				} else {
					$new_term = wp_insert_term( $term_name, $taxonomy );
					if ( ! is_wp_error( $new_term ) ) {
						$term_ids[] = $new_term['term_id'];
					}
				}
			}
			if ( ! empty( $term_ids ) ) {
				wp_set_post_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}
	}

	return array(
		'success'   => true,
		'post_id'   => $post_id,
		'edit_link' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'message'   => 'Post updated successfully.',
	);
}

/**
 * Delete a post.
 */
function mcp_toolset_delete_post( int $post_id, bool $force = false ): array {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return array(
			'success' => false,
			'message' => 'Post not found.',
		);
	}

	$result = wp_delete_post( $post_id, $force );

	if ( ! $result ) {
		return array(
			'success' => false,
			'message' => 'Failed to delete post.',
		);
	}

	return array(
		'success' => true,
		'message' => $force ? 'Post permanently deleted.' : 'Post moved to trash.',
	);
}

/**
 * Get markers used to detect Toolset usage in post content.
 */
function mcp_toolset_get_usage_content_markers(): array {
	return array(
		'toolset_string'             => 'toolset',
		'toolset_block'              => 'wp:toolset',
		'toolset_blocks_namespace'   => 'toolset-blocks',
		'views_shortcode'            => '[wpv-',
		'types_shortcode'            => '[types ',
		'views_block_or_reference'   => 'wpv-view',
		'wpcf_field_reference'       => 'wpcf-',
		'toolset_dynamic_source_ref' => 'toolset_dynamic',
	);
}

/**
 * Sanitize audit post statuses.
 */
function mcp_toolset_sanitize_audit_statuses( $statuses ): array {
	$allowed = array( 'publish', 'draft', 'private', 'pending', 'future' );
	$statuses = is_array( $statuses ) ? $statuses : array();
	$statuses = array_values( array_intersect( array_map( 'sanitize_key', $statuses ), $allowed ) );
	return ! empty( $statuses ) ? $statuses : array( 'publish' );
}

/**
 * Sanitize audit post types.
 */
function mcp_toolset_sanitize_audit_post_types( $post_types ): array {
	if ( ! is_array( $post_types ) ) {
		return array();
	}

	$clean = array();
	foreach ( $post_types as $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( post_type_exists( $post_type ) ) {
			$clean[] = $post_type;
		}
	}

	return array_values( array_unique( $clean ) );
}

/**
 * Format a post row for Toolset usage reports.
 */
function mcp_toolset_format_usage_post( int $post_id, string $post_type, string $status, array $extra = array() ): array {
	return array_merge(
		array(
			'id'     => $post_id,
			'title'  => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES ),
			'type'   => $post_type,
			'status' => $status,
			'url'    => get_permalink( $post_id ),
		),
		$extra
	);
}

/**
 * Find posts/pages with Toolset markers in post_content.
 */
function mcp_toolset_find_content_usage( array $post_types, array $statuses, int $limit ): array {
	global $wpdb;

	$markers = mcp_toolset_get_usage_content_markers();
	$where_parts = array();
	$args = array();

	foreach ( $markers as $marker ) {
		$where_parts[] = 'LOWER(post_content) LIKE LOWER(%s)';
		$args[] = '%' . $wpdb->esc_like( $marker ) . '%';
	}

	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$sql = "SELECT ID, post_type, post_status, post_content FROM {$wpdb->posts} WHERE post_status IN ($status_placeholders) AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment') AND (" . implode( ' OR ', $where_parts ) . ')';
	$query_args = array_merge( $statuses, $args );

	if ( ! empty( $post_types ) ) {
		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql .= " AND post_type IN ($post_type_placeholders)";
		$query_args = array_merge( $query_args, $post_types );
	}

	$sql .= ' ORDER BY post_type ASC, post_title ASC LIMIT %d';
	$query_args[] = $limit;

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
	$matches = array();

	foreach ( $rows as $row ) {
		$lower_content = strtolower( (string) $row['post_content'] );
		$found_markers = array();

		foreach ( $markers as $label => $marker ) {
			if ( false !== strpos( $lower_content, strtolower( $marker ) ) ) {
				$found_markers[] = $label;
			}
		}

		$matches[] = mcp_toolset_format_usage_post(
			(int) $row['ID'],
			(string) $row['post_type'],
			(string) $row['post_status'],
			array( 'markers' => $found_markers )
		);
	}

	return $matches;
}

/**
 * Find posts/pages with Toolset-related post meta.
 */
function mcp_toolset_find_meta_usage( array $post_types, array $statuses, int $limit ): array {
	global $wpdb;

	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$query_args = $statuses;
	$sql = "SELECT p.ID, p.post_type, p.post_status, pm.meta_key, LEFT(CAST(pm.meta_value AS CHAR), 180) AS sample
		FROM {$wpdb->postmeta} pm
		JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE p.post_status IN ($status_placeholders)
			AND p.post_type NOT IN ('revision', 'nav_menu_item', 'attachment')
			AND (
				pm.meta_key = %s
				OR pm.meta_key LIKE %s
				OR pm.meta_key LIKE %s
				OR pm.meta_key LIKE %s
				OR pm.meta_key LIKE %s
				OR CAST(pm.meta_value AS CHAR) LIKE %s
				OR CAST(pm.meta_value AS CHAR) LIKE %s
				OR CAST(pm.meta_value AS CHAR) LIKE %s
			)";

	$query_args = array_merge(
		$query_args,
		array(
			'_views_template',
			$wpdb->esc_like( 'wpcf-' ) . '%',
			$wpdb->esc_like( '_wpcf' ) . '%',
			$wpdb->esc_like( '_wpv' ) . '%',
			$wpdb->esc_like( '_toolset' ) . '%',
			'%' . $wpdb->esc_like( 'toolset' ) . '%',
			'%' . $wpdb->esc_like( 'wpv-' ) . '%',
			'%' . $wpdb->esc_like( 'wpcf-' ) . '%',
		)
	);

	if ( ! empty( $post_types ) ) {
		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql .= " AND p.post_type IN ($post_type_placeholders)";
		$query_args = array_merge( $query_args, $post_types );
	}

	$sql .= ' ORDER BY p.post_type ASC, p.post_title ASC, pm.meta_key ASC LIMIT %d';
	$query_args[] = $limit;

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
	$matches = array();

	foreach ( $rows as $row ) {
		$post_id = (int) $row['ID'];
		if ( ! isset( $matches[ $post_id ] ) ) {
			$matches[ $post_id ] = mcp_toolset_format_usage_post(
				$post_id,
				(string) $row['post_type'],
				(string) $row['post_status'],
				array( 'meta' => array() )
			);
		}

		$matches[ $post_id ]['meta'][] = array(
			'key'    => (string) $row['meta_key'],
			'sample' => (string) $row['sample'],
		);
	}

	return array_values( $matches );
}

/**
 * List Toolset-owned configuration objects.
 */
function mcp_toolset_list_configuration_objects(): array {
	$toolset_post_types = array( 'view', 'view-template', 'cred-form', 'cred-user-form', 'cred_rel_form', 'wp-types-group', 'wp-types-user-group', 'wp-types-term-group' );
	$objects = array();

	foreach ( $toolset_post_types as $post_type ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $posts as $post ) {
			$objects[] = mcp_toolset_format_usage_post( (int) $post->ID, $post->post_type, $post->post_status );
		}
	}

	return $objects;
}

/**
 * Audit Toolset usage across content, meta, and Toolset configuration objects.
 */
function mcp_toolset_audit_usage( array $input = array() ): array {
	$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 500;
	$limit = max( 1, min( 2000, $limit ) );
	$statuses = mcp_toolset_sanitize_audit_statuses( $input['statuses'] ?? array( 'publish' ) );
	$post_types = mcp_toolset_sanitize_audit_post_types( $input['post_types'] ?? array() );
	$include_meta = array_key_exists( 'include_meta', $input ) ? (bool) $input['include_meta'] : true;

	$content_matches = mcp_toolset_find_content_usage( $post_types, $statuses, $limit );
	$meta_matches = $include_meta ? mcp_toolset_find_meta_usage( $post_types, $statuses, $limit ) : array();
	$toolset_objects = mcp_toolset_list_configuration_objects();

	return array(
		'success'         => true,
		'active_plugins'  => array_values(
			array_filter(
				(array) get_option( 'active_plugins', array() ),
				static function ( $plugin ): bool {
					return false !== strpos( $plugin, 'toolset' ) || false !== strpos( $plugin, 'types/' );
				}
			)
		),
		'content_matches' => $content_matches,
		'meta_matches'    => $meta_matches,
		'toolset_objects' => $toolset_objects,
		'custom_types'    => is_array( get_option( 'wpcf-custom-types' ) ) ? array_keys( get_option( 'wpcf-custom-types' ) ) : array(),
		'custom_taxonomies' => is_array( get_option( 'wpcf-custom-taxonomies' ) ) ? array_keys( get_option( 'wpcf-custom-taxonomies' ) ) : array(),
		'message'         => sprintf(
			'Found %d content match(es), %d meta match(es), and %d Toolset configuration object(s).',
			count( $content_matches ),
			count( $meta_matches ),
			count( $toolset_objects )
		),
	);
}

/**
 * Clean stale Toolset runtime data after replacing frontend Toolset usage.
 */
function mcp_toolset_cleanup_stale_data( array $input = array() ): array {
	global $wpdb;

	$dry_run = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
	$delete_meta = ! empty( $input['delete_stale_meta'] );
	$delete_objects = ! empty( $input['delete_toolset_objects'] );
	$clean_content = ! empty( $input['clean_toolset_ds_version'] );
	$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 5000;
	$limit = max( 1, min( 20000, $limit ) );

	$meta_keys = array( '_views_template', '_wpv_contains_gutenberg_views', '_wpv_is_gutenberg_view', '_wpv_layout_settings', '_wpv_settings', '_wpv_view_data', '_wpv_used_in_posts', '_wpv_description', '_toolset_edit_last' );
	$toolset_post_types = array( 'view', 'view-template', 'cred-form', 'cred-user-form', 'cred_rel_form', 'wp-types-group', 'wp-types-user-group', 'wp-types-term-group' );
	$result = array(
		'success'          => true,
		'dry_run'          => $dry_run,
		'meta_deleted'     => 0,
		'objects_deleted'  => 0,
		'content_cleaned'  => 0,
		'meta_candidates'  => 0,
		'object_candidates' => array(),
		'content_candidates' => array(),
		'message'          => '',
	);

	if ( $delete_meta ) {
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)";
		$result['meta_candidates'] = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $dry_run && $result['meta_candidates'] > 0 ) {
			$delete_sql = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) LIMIT %d";
			$query_args = array_merge( $meta_keys, array( $limit ) );
			$wpdb->query( $wpdb->prepare( $delete_sql, $query_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$result['meta_deleted'] = (int) $wpdb->rows_affected;
		}
	}

	if ( $clean_content ) {
		$content_posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => min( 2000, $limit ),
				's'              => 'toolsetDSVersion',
				'no_found_rows'  => true,
			)
		);

		foreach ( $content_posts as $post ) {
			if ( false === strpos( $post->post_content, 'toolsetDSVersion' ) ) {
				continue;
			}

			$result['content_candidates'][] = array(
				'id'     => (int) $post->ID,
				'title'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
				'type'   => $post->post_type,
				'status' => $post->post_status,
			);

			$cleaned_content = preg_replace( '/,"dynamicAttributes":\{"toolsetDSVersion":"[0-9]+"\}/', '', $post->post_content );
			$cleaned_content = preg_replace( '/"dynamicAttributes":\{"toolsetDSVersion":"[0-9]+"\},/', '', $cleaned_content );
			$cleaned_content = preg_replace( '/\{"dynamicAttributes":\{"toolsetDSVersion":"[0-9]+"\}\}/', '{}', $cleaned_content );

			if ( $cleaned_content !== $post->post_content && ! $dry_run ) {
				wp_update_post(
					array(
						'ID'           => (int) $post->ID,
						'post_content' => $cleaned_content,
					)
				);
				$result['content_cleaned']++;
			}
		}
	}

	if ( $delete_objects ) {
		foreach ( $toolset_post_types as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => min( 500, $limit ),
					'no_found_rows'  => true,
				)
			);

			foreach ( $posts as $post ) {
				$result['object_candidates'][] = array(
					'id'     => (int) $post->ID,
					'title'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
					'type'   => $post->post_type,
					'status' => $post->post_status,
				);

				if ( ! $dry_run && wp_delete_post( (int) $post->ID, true ) ) {
					$result['objects_deleted']++;
				}
			}
		}
	}

	$result['message'] = $dry_run ? 'Dry run completed. No data was changed.' : 'Cleanup completed.';

	return $result;
}

/**
 * Register Toolset abilities.
 */
function mcp_register_toolset_abilities(): void {
	if ( ! mcp_toolset_check_dependencies() ) {
		return;
	}

	// LIST POST TYPES
wp_register_ability(
		'toolset/list-post-types',
		array(
			'label'               => 'List Toolset Post Types',
			'description'         => 'Get all public post types.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'          => 'object',
				'minProperties' => 0,
				'properties'    => array(
					'noop' => array(
						'type'        => 'boolean',
						'description' => 'No-op flag to satisfy object input requirements.',
					),
				),
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'post_types' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$post_types = mcp_toolset_get_post_types();
				return array(
					'success'    => true,
					'post_types' => $post_types,
					'message'    => 'Retrieved ' . count( $post_types ) . ' post types.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// GET POST TYPE FIELDS
wp_register_ability(
		'toolset/get-post-type-fields',
		array(
			'label'               => 'Get Post Type Custom Fields',
			'description'         => 'Get custom fields for a post type.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_type' ),
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'fields'  => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_type'] ) ) {
					return array( 'success' => false, 'message' => 'post_type is required.' );
				}

				$fields = mcp_toolset_get_post_type_fields( $input['post_type'] );
				return array(
					'success' => true,
					'fields'  => $fields,
					'message' => 'Retrieved ' . count( $fields ) . ' fields.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// GET POST TYPE TAXONOMIES
wp_register_ability(
		'toolset/get-post-type-taxonomies',
		array(
			'label'               => 'Get Post Type Taxonomies',
			'description'         => 'Get taxonomies for a post type.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_type' ),
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'taxonomies' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_type'] ) ) {
					return array( 'success' => false, 'message' => 'post_type is required.' );
				}

				$taxonomies = mcp_toolset_get_post_type_taxonomies( $input['post_type'] );
				return array(
					'success'    => true,
					'taxonomies' => $taxonomies,
					'message'    => 'Retrieved ' . count( $taxonomies ) . ' taxonomies.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// CREATE POST
wp_register_ability(
		'toolset/create-post',
		array(
			'label'               => 'Create Post with Custom Fields',
			'description'         => 'Create a new post with custom fields and taxonomy terms.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_type', 'title' ),
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type to create.',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Post title.',
					),
					'content'   => array(
						'type'        => 'string',
						'description' => 'Post content (optional).',
					),
					'meta'      => array(
						'type'        => 'object',
						'description' => 'Custom field key-value pairs.',
					),
					'terms'     => array(
						'type'        => 'object',
						'description' => 'Taxonomy terms: {"taxonomy_name": ["term1", "term2"]}.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'post_id'   => array( 'type' => 'integer' ),
					'edit_link' => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_type'] ) || empty( $input['title'] ) ) {
					return array(
						'success' => false,
						'message' => 'post_type and title are required.',
					);
				}

				$content = $input['content'] ?? '';
				$meta    = $input['meta'] ?? array();
				$terms   = $input['terms'] ?? array();

				return mcp_toolset_create_post(
					$input['post_type'],
					$input['title'],
					$content,
					$meta,
					$terms
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// GET POST
wp_register_ability(
		'toolset/get-post',
		array(
			'label'               => 'Get Post with Custom Fields',
			'description'         => 'Get a post by ID including all custom fields.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Post ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'title'        => array( 'type' => 'string' ),
					'content'      => array( 'type' => 'string' ),
					'status'       => array( 'type' => 'string' ),
					'slug'         => array( 'type' => 'string' ),
					'link'         => array( 'type' => 'string' ),
					'edit_link'    => array( 'type' => 'string' ),
					'meta'         => array( 'type' => 'object' ),
					'terms'        => array( 'type' => 'object' ),
					'post_type'    => array( 'type' => 'string' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => 'post_id is required.' );
				}

				return mcp_toolset_get_post( (int) $input['post_id'] );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// LIST POSTS
wp_register_ability(
		'toolset/list-posts',
		array(
			'label'               => 'List Posts with Filters',
			'description'         => 'List posts of a post type with filtering.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_type' ),
				'properties'           => array(
					'post_type'  => array(
						'type'        => 'string',
						'description' => 'Post type to list.',
					),
					'limit'      => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => 'Number of posts.',
					),
					'offset'     => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Pagination offset.',
					),
					'status'     => array(
						'type'        => 'string',
						'default'     => 'any',
						'description' => 'Post status.',
					),
					'meta_key'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'type'        => 'string',
						'description' => 'Filter by meta key.',
					),
					'meta_value' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'type'        => 'string',
						'description' => 'Filter by meta value.',
					),
					'meta_compare' => array(
						'type'        => 'string',
						'description' => 'Meta compare operator (e.g. =, !=, LIKE).',
					),
					'taxonomy'   => array(
						'type'        => 'string',
						'description' => 'Filter by taxonomy.',
					),
					'term'       => array(
						'type'        => 'string',
						'description' => 'Filter by term.',
					),
					'search'     => array(
						'type'        => 'string',
						'description' => 'Search query.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'posts'   => array( 'type' => 'array' ),
					'count'   => array( 'type' => 'integer' ),
					'total'   => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_type'] ) ) {
					return array( 'success' => false, 'message' => 'post_type is required.' );
				}

				return mcp_toolset_list_posts( $input['post_type'], $input );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// UPDATE POST
wp_register_ability(
		'toolset/update-post',
		array(
			'label'               => 'Update Post with Custom Fields',
			'description'         => 'Update an existing post.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Post ID to update.',
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'New title.',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'New content.',
					),
					'status'  => array(
						'type'        => 'string',
						'description' => 'New status.',
					),
					'meta'    => array(
						'type'        => 'object',
						'description' => 'Custom fields.',
					),
					'terms'   => array(
						'type'        => 'object',
						'description' => 'Taxonomy terms.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'post_id'   => array( 'type' => 'integer' ),
					'edit_link' => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => 'post_id is required.' );
				}

				return mcp_toolset_update_post( (int) $input['post_id'], $input );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// DELETE POST
wp_register_ability(
		'toolset/delete-post',
		array(
			'label'               => 'Delete Post',
			'description'         => 'Delete (move to trash) or permanently remove a post.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Post ID to delete.',
					),
					'force'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Permanently delete.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => 'post_id is required.' );
				}

				$force = $input['force'] ?? false;
				return mcp_toolset_delete_post( (int) $input['post_id'], $force );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'delete_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);
	// GET USER FIELDS
wp_register_ability(
		'toolset/get-user-fields',
		array(
			'label'               => 'Get User Custom Fields',
			'description'         => 'Get custom fields defined for users.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'          => 'object',
				'minProperties' => 0,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'fields'  => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$fields = array();

				if ( function_exists( 'wpcf_get_custom_fields' ) ) {
					$toolset_fields = wpcf_get_custom_fields();
					foreach ( $toolset_fields as $field ) {
						if ( in_array( 'user', (array) $field['types'] ?? array(), true ) ) {
							$fields[] = array(
								'name'          => $field['slug'],
								'label'         => $field['name'] ?? $field['slug'],
								'type'          => $field['type'] ?? 'text',
								'is_repeatable' => $field['repeatable'] ?? false,
							);
						}
					}
				}

				return array(
					'success' => true,
					'fields'  => $fields,
					'message' => 'Retrieved ' . count( $fields ) . ' user fields.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// GET USER
wp_register_ability(
		'toolset/get-user',
		array(
			'label'               => 'Get User with Custom Fields',
			'description'         => 'Get a user by ID including custom fields.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id' ),
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => 'User ID.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'integer' ),
					'username'     => array( 'type' => 'string' ),
					'email'        => array( 'type' => 'string' ),
					'display_name' => array( 'type' => 'string' ),
					'meta'         => array( 'type' => 'object' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['user_id'] ) ) {
					return array( 'success' => false, 'message' => 'user_id is required.' );
				}

				$user = get_userdata( (int) $input['user_id'] );

				if ( ! $user ) {
					return array( 'success' => false, 'message' => 'User not found.' );
				}

				$meta = get_user_meta( $input['user_id'] );
				$meta_array = array();
				foreach ( $meta as $key => $values ) {
					if ( strpos( $key, 'wp_' ) === 0 ) {
						continue;
					}
					$meta_array[ $key ] = count( $values ) === 1 ? $values[0] : $values;
				}

				return array(
					'success'      => true,
					'id'           => $user->ID,
					'username'     => $user->user_login,
					'email'        => $user->user_email,
					'display_name' => $user->display_name,
					'meta'         => $meta_array,
					'message'      => 'User retrieved successfully.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// LIST USERS
wp_register_ability(
		'toolset/list-users',
		array(
			'label'               => 'List Users',
			'description'         => 'List users with optional filtering.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'role'   => array(
						'type'        => 'string',
						'description' => 'Filter by role.',
					),
					'limit'  => array(
						'type'        => 'integer',
						'default'     => 50,
						'description' => 'Number of users.',
					),
					'offset' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Pagination offset.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'users'   => array( 'type' => 'array' ),
					'count'   => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$args = array(
					'number' => $input['limit'] ?? 50,
					'offset' => $input['offset'] ?? 0,
				);

				if ( ! empty( $input['role'] ) ) {
					$args['role'] = $input['role'];
				}

				$users = get_users( $args );

				$result = array();
				foreach ( $users as $user ) {
					$result[] = array(
						'id'           => $user->ID,
						'username'     => $user->user_login,
						'email'        => $user->user_email,
						'display_name' => $user->display_name,
						'roles'        => $user->roles,
					);
				}

				return array(
					'success' => true,
					'users'   => $result,
					'count'   => count( $result ),
					'message' => 'Retrieved ' . count( $result ) . ' users.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// LIST ROLES
wp_register_ability(
		'toolset/list-roles',
		array(
			'label'               => 'List User Roles',
			'description'         => 'List all user roles and their capabilities.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'          => 'object',
				'minProperties' => 0,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'roles'   => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				global $wp_roles;

				$roles = array();
				if ( ! empty( $wp_roles->roles ) ) {
					foreach ( $wp_roles->roles as $name => $role ) {
						$roles[] = array(
							'name'         => $name,
							'label'        => $role['name'],
							'capabilities' => array_keys( $role['capabilities'] ),
						);
					}
				}

				return array(
					'success' => true,
					'roles'   => $roles,
					'message' => 'Retrieved ' . count( $roles ) . ' roles.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// GET ROLE CAPABILITIES
wp_register_ability(
		'toolset/get-role-capabilities',
		array(
			'label'               => 'Get Role Capabilities',
			'description'         => 'Get capabilities for a specific role.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'role' ),
				'properties'           => array(
					'role' => array(
						'type'        => 'string',
						'description' => 'Role name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'role'         => array( 'type' => 'string' ),
					'label'        => array( 'type' => 'string' ),
					'capabilities' => array( 'type' => 'array' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['role'] ) ) {
					return array( 'success' => false, 'message' => 'role is required.' );
				}

				$role_obj = get_role( $input['role'] );

				if ( ! $role_obj ) {
					return array( 'success' => false, 'message' => 'Role not found.' );
				}

				return array(
					'success'      => true,
					'role'         => $input['role'],
					'label'        => $role_obj->name,
					'capabilities' => array_keys( $role_obj->capabilities ),
					'message'      => 'Retrieved capabilities for ' . $input['role'] . ' role.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// LIST POST RELATIONSHIPS
wp_register_ability(
		'toolset/list-post-relationships',
		array(
			'label'               => 'List Post Relationships',
			'description'         => 'List posts related to a given post.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'      => array(
						'type'        => 'integer',
						'description' => 'Post ID to get relationships for.',
					),
					'relationship' => array(
						'type'        => 'string',
						'description' => 'Relationship name (optional).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'relationships' => array( 'type' => 'array' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => 'post_id is required.' );
				}

				$post = get_post( (int) $input['post_id'] );
				if ( ! $post ) {
					return array( 'success' => false, 'message' => 'Post not found.' );
				}

				$relationships = array();

				// Check for Toolset relationships.
				if ( function_exists( 'types_get_child_posts_for_parent' ) ) {
					$child_posts = types_get_child_posts_for_parent( $post->ID );
					if ( ! empty( $child_posts ) ) {
						$relationships['children'] = array();
						foreach ( $child_posts as $child ) {
							$relationships['children'][] = array(
								'id'    => $child->ID,
								'title' => $child->post_title,
								'link'  => get_permalink( $child->ID ),
							);
						}
					}
				}

				// Check for parent relationship.
				$parent_id = get_post_meta( $post->ID, '_wpcf_parent', true );
				if ( ! empty( $parent_id ) ) {
					$parent = get_post( $parent_id );
					if ( $parent ) {
						$relationships['parent'] = array(
							'id'    => $parent->ID,
							'title' => $parent->post_title,
							'link'  => get_permalink( $parent->ID ),
						);
					}
				}

				return array(
					'success'      => true,
					'relationships' => $relationships,
					'message'      => 'Retrieved relationships for post ' . $post->ID,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// LIST TOOLSET FORMS
wp_register_ability(
		'toolset/list-forms',
		array(
			'label'               => 'List Toolset Forms',
			'description'         => 'List all forms created with Toolset CRED.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Filter by post type.',
					),
					'noop'     => array(
						'type'        => 'boolean',
						'description' => 'No-op flag to satisfy object input requirements.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type' => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'forms'   => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				$forms = array();

				// Check if CRED is active.
				if ( function_exists( 'cred_get_forms' ) ) {
					$all_forms = cred_get_forms();
					foreach ( $all_forms as $form ) {
						$form_settings = get_post_meta( $form->ID, '_cred_form_settings', true );
						if ( ! empty( $input['post_type'] ) ) {
							if ( empty( $form_settings['post']['post_type'] ) ||
							     $form_settings['post']['post_type'] !== $input['post_type'] ) {
								continue;
							}
						}
						$forms[] = array(
							'id'        => $form->ID,
							'name'      => $form->post_title,
							'post_type' => $form_settings['post']['post_type'] ?? '',
							'edit_link' => admin_url( 'post.php?post=' . $form->ID . '&action=edit' ),
						);
					}
				}

				return array(
					'success' => true,
					'forms'   => $forms,
					'message' => 'Retrieved ' . count( $forms ) . ' forms.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// LIST TAXONOMIES
wp_register_ability(
	'toolset/list-taxonomies',
	array(
		'label'               => 'List All Taxonomies',
		'description'         => 'Get all registered taxonomies including custom ones.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'noop' => array(
					'type'        => 'boolean',
					'description' => 'No-op flag to satisfy object input requirements.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'     => array( 'type' => 'boolean' ),
				'taxonomies'  => array( 'type' => 'array' ),
				'message'     => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
			$result     = array();
			foreach ( $taxonomies as $tax ) {
				$result[] = array(
					'name'       => $tax->name,
					'label'      => $tax->labels->singular_name,
					'post_types' => $tax->object_type,
					'hierarchical' => $tax->hierarchical,
				);
			}
			return array(
				'success'    => true,
				'taxonomies' => $result,
				'message'    => 'Retrieved ' . count( $result ) . ' taxonomies.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// LIST TERMS
wp_register_ability(
	'toolset/list-terms',
	array(
		'label'               => 'List Taxonomy Terms',
		'description'         => 'Get terms from a taxonomy.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'taxonomy' ),
			'properties'           => array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy name.',
				),
				'hide_empty' => array(
					'type'        => 'boolean',
					'description' => 'Hide empty terms.',
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'terms'   => array( 'type' => 'array' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			if ( empty( $input['taxonomy'] ) ) {
				return array( 'success' => false, 'message' => 'taxonomy is required.' );
			}
			$hide_empty = $input['hide_empty'] ?? false;
			$terms      = get_terms(
				array(
					'taxonomy'   => $input['taxonomy'],
					'hide_empty' => $hide_empty,
				)
			);
			if ( is_wp_error( $terms ) ) {
				return array( 'success' => false, 'message' => $terms->get_error_message() );
			}
			$result = array();
			foreach ( $terms as $term ) {
				$result[] = array(
					'id'    => $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => $term->count,
				);
			}
			return array(
				'success' => true,
				'terms'   => $result,
				'message' => 'Retrieved ' . count( $result ) . ' terms.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// CREATE TERM
wp_register_ability(
	'toolset/create-term',
	array(
		'label'               => 'Create Taxonomy Term',
		'description'         => 'Create a new term in a taxonomy.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'taxonomy', 'name' ),
			'properties'           => array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy name.',
				),
				'name'     => array(
					'type'        => 'string',
					'description' => 'Term name.',
				),
				'slug'     => array(
					'type'        => 'string',
					'description' => 'Term slug (optional).',
				),
				'parent'   => array(
					'type'        => 'integer',
					'description' => 'Parent term ID (for hierarchical taxonomies).',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'term_id'   => array( 'type' => 'integer' ),
				'term_taxonomy_id' => array( 'type' => 'integer' ),
				'message'   => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			if ( empty( $input['taxonomy'] ) || empty( $input['name'] ) ) {
				return array( 'success' => false, 'message' => 'taxonomy and name are required.' );
			}
			$args = array();
			if ( ! empty( $input['slug'] ) ) {
				$args['slug'] = $input['slug'];
			}
			if ( ! empty( $input['parent'] ) ) {
				$args['parent'] = (int) $input['parent'];
			}
			$term = wp_insert_term( $input['name'], $input['taxonomy'], $args );
			if ( is_wp_error( $term ) ) {
				return array( 'success' => false, 'message' => $term->get_error_message() );
			}
			return array(
				'success'        => true,
				'term_id'        => $term['term_id'],
				'term_taxonomy_id' => $term['term_taxonomy_id'],
				'message'        => 'Term created successfully.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_categories' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// GET TAXONOMY UI CONFIG
wp_register_ability(
	'toolset/get-taxonomy-ui',
	array(
		'label'               => 'Get Taxonomy UI Configuration',
		'description'         => 'Get taxonomy UI configuration for custom meta boxes.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Optional post type to filter.',
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Optional taxonomy to filter.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'config'  => array( 'type' => 'object' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$config = mcp_toolset_get_taxonomy_ui_config();
			$post_type = ! empty( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '';
			$taxonomy  = ! empty( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

			if ( $post_type && isset( $config[ $post_type ] ) ) {
				$config = array( $post_type => $config[ $post_type ] );
			}

			if ( $taxonomy && $post_type && isset( $config[ $post_type ][ $taxonomy ] ) ) {
				$config = array( $post_type => array( $taxonomy => $config[ $post_type ][ $taxonomy ] ) );
			} elseif ( $taxonomy && ! $post_type ) {
				$filtered = array();
				foreach ( $config as $pt => $taxes ) {
					if ( isset( $taxes[ $taxonomy ] ) ) {
						$filtered[ $pt ] = array( $taxonomy => $taxes[ $taxonomy ] );
					}
				}
				$config = $filtered;
			}

			return array(
				'success' => true,
				'config'  => $config,
				'message' => 'Retrieved taxonomy UI configuration.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// SET TAXONOMY UI CONFIG
wp_register_ability(
	'toolset/set-taxonomy-ui',
	array(
		'label'               => 'Set Taxonomy UI Configuration',
		'description'         => 'Configure taxonomy UI to use radios, dropdown, or default checkboxes for a post type.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'taxonomy', 'post_type', 'ui' ),
			'properties'           => array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy name.',
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type name.',
				),
				'ui'       => array(
					'type'        => 'string',
					'enum'        => array( 'checkboxes', 'radios', 'dropdown' ),
					'description' => 'UI style to use for taxonomy selection.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'config'  => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$taxonomy  = sanitize_key( $input['taxonomy'] ?? '' );
			$post_type = sanitize_key( $input['post_type'] ?? '' );
			$ui        = $input['ui'] ?? '';

			if ( empty( $taxonomy ) || empty( $post_type ) || empty( $ui ) ) {
				return array( 'success' => false, 'message' => 'taxonomy, post_type, and ui are required.' );
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return array( 'success' => false, 'message' => "Taxonomy '{$taxonomy}' does not exist." );
			}

			if ( ! post_type_exists( $post_type ) ) {
				return array( 'success' => false, 'message' => "Post type '{$post_type}' does not exist." );
			}

			if ( ! in_array( $ui, array( 'checkboxes', 'radios', 'dropdown' ), true ) ) {
				return array( 'success' => false, 'message' => 'ui must be one of: checkboxes, radios, dropdown.' );
			}

			mcp_toolset_set_taxonomy_ui_config( $taxonomy, $post_type, $ui );

			return array(
				'success' => true,
				'message' => "Taxonomy UI for '{$taxonomy}' on '{$post_type}' set to '{$ui}'.",
				'config'  => mcp_toolset_get_taxonomy_ui_config(),
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// LIST VIEWS
wp_register_ability(
	'toolset/list-views',
	array(
		'label'               => 'List Toolset Views',
		'description'         => 'List all Views created with Toolset Views.',
		'category'            => 'site',
	'input_schema'        => array(
		'type'                 => 'object',
		'properties'           => array(
			'noop' => array(
				'type'        => 'boolean',
				'description' => 'No-op flag to satisfy object input requirements.',
			),
		),
		'additionalProperties' => false,
	),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'views'   => array( 'type' => 'array' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$views = array();
			// Check if Views is active.
			if ( function_exists( 'wpv_get_views' ) ) {
				$all_views = wpv_get_views();
				foreach ( $all_views as $view_id => $view_name ) {
					$view_settings = get_post_meta( $view_id, '_wpv_settings', true );
					$views[]       = array(
						'id'          => $view_id,
						'name'        => $view_name,
						'post_type'   => $view_settings['post_type'] ?? '',
						'view_mode'   => $view_settings['view_mode'] ?? '',
						'edit_link'   => admin_url( 'post.php?post=' . $view_id . '&action=edit' ),
					);
				}
			}
			return array(
				'success' => true,
				'views'   => $views,
				'message' => 'Retrieved ' . count( $views ) . ' views.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// GET POST RELATIONSHIPS
wp_register_ability(
	'toolset/get-post-relationships',
	array(
		'label'               => 'Get Post Relationships',
		'description'         => 'Get all relationships for a specific post.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'Post ID.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'relationships' => array( 'type' => 'array' ),
				'message'       => array( 'type' => 'string' ),
			),
		),
			'execute_callback'    => function ( array $input = array() ): array {
				if ( empty( $input['post_id'] ) ) {
					return array( 'success' => false, 'message' => 'post_id is required.' );
				}
				$post_id = (int) $input['post_id'];
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return array( 'success' => false, 'message' => 'You do not have permission to inspect relationships for this post.' );
				}
				$relationships = array();
				// Check if Types relationships are active.
				if ( function_exists( 'types_get_relationships' ) ) {
					$post_relationships = get_post_meta( $post_id, '_wpcf_belongs', true );
				if ( ! empty( $post_relationships ) && is_array( $post_relationships ) ) {
					foreach ( $post_relationships as $relationship => $parent_id ) {
						$relationships[] = array(
							'relationship' => $relationship,
							'parent_id'    => (int) $parent_id,
							'parent_title' => get_the_title( $parent_id ),
						);
					}
				}
			}
			// Get children if Toolset relationships API available.
			if ( function_exists( 'wpv_get_related_posts' ) ) {
				// This would require specific relationship name.
			}
			return array(
				'success'       => true,
				'relationships' => $relationships,
				'message'       => 'Retrieved ' . count( $relationships ) . ' relationships.',
			);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// GET USERS BY ROLE
wp_register_ability(
	'toolset/get-users-by-role',
	array(
		'label'               => 'Get Users By Role',
		'description'         => 'Get users filtered by role.',
		'category'            => 'users',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'role' ),
			'properties'           => array(
				'role'    => array(
					'type'        => 'string',
					'description' => 'Role name.',
				),
				'number'  => array(
					'type'        => 'integer',
					'description' => 'Number of users to return.',
					'default'     => 100,
				),
				'offset'  => array(
					'type'        => 'integer',
					'description' => 'Offset for pagination.',
					'default'     => 0,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'users'   => array( 'type' => 'array' ),
				'total'   => array( 'type' => 'integer' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			if ( empty( $input['role'] ) ) {
				return array( 'success' => false, 'message' => 'role is required.' );
			}
			$args  = array(
				'role'   => $input['role'],
				'number' => $input['number'] ?? 100,
				'offset' => $input['offset'] ?? 0,
			);
			$query = new WP_User_Query( $args );
			$users = array();
			foreach ( $query->get_results() as $user ) {
				$users[] = array(
					'id'       => $user->ID,
					'username' => $user->user_login,
					'email'    => $user->user_email,
					'name'     => $user->display_name,
				);
			}
			return array(
				'success' => true,
				'users'   => $users,
				'total'   => $query->get_total(),
				'message' => 'Retrieved ' . count( $users ) . ' users.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'list_users' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// QUERY POSTS WITH TOOLSET FILTERS
wp_register_ability(
	'toolset/query-posts',
	array(
		'label'               => 'Query Posts with Toolset',
		'description'         => 'Query posts using Toolset filters and post relationships.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'    => array(
					'type'        => 'string',
					'description' => 'Post type to query.',
				),
				'post_status'  => array(
					'type'        => 'string',
					'description' => 'Post status.',
					'default'     => 'publish',
				),
				'posts_per_page' => array(
					'type'        => 'integer',
					'description' => 'Number of posts.',
					'default'     => 20,
				),
				'offset'       => array(
					'type'        => 'integer',
					'description' => 'Offset for pagination.',
					'default'     => 0,
				),
				'orderby'      => array(
					'type'        => 'string',
					'description' => 'Order by field.',
					'default'     => 'date',
				),
				'order'        => array(
					'type'        => 'string',
					'description' => 'Order direction.',
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'DESC',
				),
					'meta_key'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'type'        => 'string',
						'description' => 'Filter by meta key.',
					),
					'meta_value'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'description' => 'Filter by meta value.',
					),
					'meta_compare' => array(
						'type'        => 'string',
						'description' => 'Meta compare operator (e.g. =, !=, LIKE).',
					),
					'tax_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						'description' => 'Taxonomy filters.',
					),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'posts'   => array( 'type' => 'array' ),
				'total'   => array( 'type' => 'integer' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$args = array(
				'post_type'      => $input['post_type'] ?? 'post',
				'post_status'    => $input['post_status'] ?? 'publish',
				'posts_per_page' => $input['posts_per_page'] ?? 20,
				'offset'         => $input['offset'] ?? 0,
				'orderby'        => $input['orderby'] ?? 'date',
				'order'          => $input['order'] ?? 'DESC',
			);
			if ( ! empty( $input['meta_key'] ) ) {
				$args['meta_key'] = sanitize_key( $input['meta_key'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				if ( isset( $input['meta_value'] ) ) {
					$args['meta_value'] = $input['meta_value']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				}
				if ( ! empty( $input['meta_compare'] ) ) {
					$args['meta_compare'] = mcp_toolset_sanitize_meta_compare( $input['meta_compare'] );
				}
			}
			if ( ! empty( $input['tax_query'] ) ) {
				$args['tax_query'] = $input['tax_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
			$query = new WP_Query( $args );
			$posts = array();
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$post    = array(
					'id'          => $post_id,
					'title'       => get_the_title(),
					'slug'        => get_post_field( 'post_name' ),
					'status'      => get_post_status(),
					'permalink'   => get_permalink(),
					'date'        => get_the_date( 'c' ),
					'modified'    => get_the_modified_date( 'c' ),
					'author'      => get_the_author_meta( 'display_name' ),
				);
				// Add custom fields.
				$post['custom_fields'] = mcp_toolset_get_post_type_fields( get_post_type( $post_id ) );
				$posts[]               = $post;
			}
			wp_reset_postdata();
			return array(
				'success' => true,
				'posts'   => $posts,
				'total'   => $query->found_posts,
				'message' => 'Retrieved ' . count( $posts ) . ' posts.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

// =============================================================================
// TOOLSET MANAGEMENT ABILITIES - Create/Manage Custom Types & Taxonomies
// =============================================================================

	// CREATE CUSTOM POST TYPE
wp_register_ability(
	'toolset/create-post-type',
	array(
		'label'               => 'Create Custom Post Type',
		'description'         => 'Create a new custom post type using Toolset.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'name', 'singular_name' ),
			'properties'           => array(
				'name'          => array(
					'type'        => 'string',
					'description' => 'Post type slug (e.g., "issue").',
				),
				'singular_name' => array(
					'type'        => 'string',
					'description' => 'Singular label (e.g., "Issue").',
				),
				'plural_name'   => array(
					'type'        => 'string',
					'description' => 'Plural label (defaults to singular + "s").',
				),
				'description'   => array(
					'type'        => 'string',
					'description' => 'Description of the post type.',
				),
				'public'        => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Whether post type is public.',
				),
				'show_ui'       => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Show in admin UI.',
				),
				'hierarchical'  => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Hierarchical like pages (vs posts).',
				),
				'supports'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'default'     => array( 'title', 'editor', 'custom-fields' ),
					'description' => 'Supported features.',
				),
				'has_archive'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Has archive page.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'message'   => array( 'type' => 'string' ),
				'post_type' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$name          = sanitize_key( $input['name'] );
			$singular_name = sanitize_text_field( $input['singular_name'] );
			$plural_name   = sanitize_text_field( $input['plural_name'] ?? $singular_name . 's' );
			$description   = sanitize_text_field( $input['description'] ?? '' );

			// Get existing types
			$types = get_option( 'wpcf-custom-types', array() );

			if ( isset( $types[ $name ] ) ) {
				return array(
					'success'   => false,
					'message'   => "Post type '{$name}' already exists.",
					'post_type' => $name,
				);
			}

			// Build labels
			$labels = array(
				'name'                  => $plural_name,
				'singular_name'         => $singular_name,
				'menu_name'             => $plural_name,
				'name_admin_bar'        => $singular_name,
				'archives'              => $singular_name . ' Archives',
				'attributes'            => $singular_name . ' Attributes',
				'parent_item_colon'     => 'Parent ' . $singular_name . ':',
				'all_items'             => 'All ' . $plural_name,
				'add_new_item'          => 'Add New ' . $singular_name,
				'add_new'               => 'Add New',
				'new_item'              => 'New ' . $singular_name,
				'edit_item'             => 'Edit ' . $singular_name,
				'view_item'             => 'View ' . $singular_name,
				'search_items'          => 'Search ' . $plural_name,
				'not_found'             => 'No ' . strtolower( $plural_name ) . ' found.',
				'not_found_in_trash'    => 'No ' . strtolower( $plural_name ) . ' found in Trash.',
				'featured_image'        => 'Featured Image',
				'set_featured_image'    => 'Set featured image',
				'remove_featured_image' => 'Remove featured image',
				'use_featured_image'    => 'Use as featured image',
			);

			$supports = $input['supports'] ?? array( 'title', 'editor', 'custom-fields' );

			$args = array(
				'label'               => $plural_name,
				'labels'              => $labels,
				'description'         => $description,
				'public'              => $input['public'] ?? true,
				'publicly_queryable'  => $input['public'] ?? true,
				'show_ui'             => $input['show_ui'] ?? true,
				'show_in_menu'        => true,
				'query_var'           => $name,
				'rewrite'             => array( 'slug' => $name ),
				'capability_type'     => 'post',
				'has_archive'         => $input['has_archive'] ?? false,
				'hierarchical'        => $input['hierarchical'] ?? false,
				'supports'            => $supports,
				'taxonomies'          => array(),
				'show_in_nav_menus'   => true,
				'show_in_rest'        => true,
				'rest_base'           => $name,
			);

			// Store in Toolset format
			$types[ $name ] = array(
				'name'        => $name,
				'label'       => $plural_name,
				'description' => $description,
				'public'      => $input['public'] ?? true,
				'publicly_queryable' => $input['public'] ?? true,
				'show_ui'     => $input['show_ui'] ?? true,
				'show_in_menu' => true,
				'hierarchical' => $input['hierarchical'] ?? false,
				'supports'    => $supports,
				'taxonomies'  => array(),
				'rest_base'   => $name,
				'labels'      => $labels,
				'_builtin'    => false,
			);

			update_option( 'wpcf-custom-types', $types );

			// Register immediately
			register_post_type( $name, $args );

			return array(
				'success'   => true,
				'message'   => "Post type '{$name}' created successfully.",
				'post_type' => $name,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// CREATE CUSTOM TAXONOMY
wp_register_ability(
	'toolset/create-taxonomy',
	array(
		'label'               => 'Create Custom Taxonomy',
		'description'         => 'Create a new custom taxonomy using Toolset.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'name', 'singular_name', 'object_type' ),
			'properties'           => array(
				'name'          => array(
					'type'        => 'string',
					'description' => 'Taxonomy slug (e.g., "issue_status").',
				),
				'singular_name' => array(
					'type'        => 'string',
					'description' => 'Singular label (e.g., "Status").',
				),
				'plural_name'   => array(
					'type'        => 'string',
					'description' => 'Plural label (defaults to singular + "s").',
				),
				'object_type'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Post types to attach to (e.g., ["issue"]).',
				),
				'description'   => array(
					'type'        => 'string',
					'description' => 'Description of the taxonomy.',
				),
				'hierarchical'  => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Hierarchical like categories (vs tags).',
				),
				'public'        => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Whether taxonomy is public.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'message'   => array( 'type' => 'string' ),
				'taxonomy'  => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$name          = sanitize_key( $input['name'] );
			$singular_name = sanitize_text_field( $input['singular_name'] );
			$plural_name   = sanitize_text_field( $input['plural_name'] ?? $singular_name . 's' );
			$object_type   = is_array( $input['object_type'] )
				? array_map( 'sanitize_key', $input['object_type'] )
				: array( sanitize_key( $input['object_type'] ) );
			$description   = sanitize_text_field( $input['description'] ?? '' );

			// Get existing taxonomies
			$taxes = get_option( 'wpcf-custom-taxonomies', array() );

			if ( isset( $taxes[ $name ] ) ) {
				return array(
					'success'  => false,
					'message'  => "Taxonomy '{$name}' already exists.",
					'taxonomy' => $name,
				);
			}

			// Build labels
			$labels = array(
				'name'                       => $plural_name,
				'singular_name'              => $singular_name,
				'menu_name'                  => $plural_name,
				'all_items'                  => 'All ' . $plural_name,
				'edit_item'                  => 'Edit ' . $singular_name,
				'view_item'                  => 'View ' . $singular_name,
				'update_item'                => 'Update ' . $singular_name,
				'add_new_item'               => 'Add New ' . $singular_name,
				'new_item_name'              => 'New ' . $singular_name . ' Name',
				'parent_item'                => 'Parent ' . $singular_name,
				'parent_item_colon'          => 'Parent ' . $singular_name . ':',
				'search_items'               => 'Search ' . $plural_name,
				'popular_items'              => 'Popular ' . $plural_name,
				'separate_items_with_commas' => 'Separate ' . strtolower( $plural_name ) . ' with commas',
				'add_or_remove_items'        => 'Add or remove ' . strtolower( $plural_name ),
				'choose_from_most_used'      => 'Choose from most used ' . strtolower( $plural_name ),
				'not_found'                  => 'No ' . strtolower( $plural_name ) . ' found.',
			);

			$args = array(
				'labels'            => $labels,
				'description'       => $description,
				'public'            => $input['public'] ?? true,
				'publicly_queryable'=> $input['public'] ?? true,
				'hierarchical'      => $input['hierarchical'] ?? false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_nav_menus' => true,
				'show_tagcloud'     => true,
				'show_in_quick_edit'=> true,
				'show_admin_column' => true,
				'meta_box_cb'       => $input['hierarchical'] ?? false ? 'post_categories_meta_box' : 'post_tags_meta_box',
				'rewrite'           => array( 'slug' => $name ),
				'query_var'         => $name,
				'show_in_rest'      => true,
				'rest_base'         => $name,
			);

			// Store in Toolset format
			$taxes[ $name ] = array(
				'name'          => $name,
				'label'         => $plural_name,
				'object_type'   => $object_type,
				'description'   => $description,
				'hierarchical'  => $input['hierarchical'] ?? false,
				'public'        => $input['public'] ?? true,
				'publicly_queryable' => $input['public'] ?? true,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'show_in_nav_menus' => true,
				'show_tagcloud' => true,
				'show_in_quick_edit' => true,
				'show_admin_column' => true,
				'rewrite'       => array( 'slug' => $name ),
				'query_var'     => $name,
				'rest_base'     => $name,
				'labels'        => $labels,
				'_builtin'      => false,
			);

			update_option( 'wpcf-custom-taxonomies', $taxes );

			// Register immediately
			register_taxonomy( $name, $object_type, $args );

			return array(
				'success'  => true,
				'message'  => "Taxonomy '{$name}' created successfully.",
				'taxonomy' => $name,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// DELETE CUSTOM POST TYPE
wp_register_ability(
	'toolset/delete-post-type',
	array(
		'label'               => 'Delete Custom Post Type',
		'description'         => 'Delete a custom post type created with Toolset.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'name' ),
			'properties'           => array(
				'name'   => array(
					'type'        => 'string',
					'description' => 'Post type slug to delete.',
				),
				'force'  => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Force delete posts (bypass trash).',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'message'   => array( 'type' => 'string' ),
				'post_type' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$name = sanitize_key( $input['name'] );
			$force = $input['force'] ?? false;

			// Get existing types
			$types = get_option( 'wpcf-custom-types', array() );

			if ( ! isset( $types[ $name ] ) ) {
				return array(
					'success'   => false,
					'message'   => "Post type '{$name}' does not exist.",
					'post_type' => $name,
				);
			}

			// Delete all posts of this type
			$posts = get_posts( array(
				'post_type'   => $name,
				'numberposts' => -1,
				'fields'      => 'ids',
			) );

			foreach ( $posts as $post_id ) {
				if ( $force ) {
					wp_delete_post( $post_id, true );
				} else {
					wp_trash_post( $post_id );
				}
			}

			// Remove from Toolset options
			unset( $types[ $name ] );
			update_option( 'wpcf-custom-types', $types );

			return array(
				'success'   => true,
				'message'   => "Post type '{$name}' deleted. " . count( $posts ) . ' posts processed.',
				'post_type' => $name,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// DELETE CUSTOM TAXONOMY
wp_register_ability(
	'toolset/delete-taxonomy',
	array(
		'label'               => 'Delete Custom Taxonomy',
		'description'         => 'Delete a custom taxonomy created with Toolset.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'name' ),
			'properties'           => array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Taxonomy slug to delete.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'  => array( 'type' => 'boolean' ),
				'message'  => array( 'type' => 'string' ),
				'taxonomy' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$name = sanitize_key( $input['name'] );

			// Get existing taxonomies
			$taxes = get_option( 'wpcf-custom-taxonomies', array() );

			if ( ! isset( $taxes[ $name ] ) ) {
				return array(
					'success'  => false,
					'message'  => "Taxonomy '{$name}' does not exist.",
					'taxonomy' => $name,
				);
			}

			// Delete all terms
			$terms = get_terms( array(
				'taxonomy'   => $name,
				'hide_empty' => false,
				'fields'     => 'ids',
			) );

			foreach ( $terms as $term_id ) {
				if ( ! is_wp_error( $term_id ) ) {
					wp_delete_term( $term_id, $name );
				}
			}

			// Remove from Toolset options
			unset( $taxes[ $name ] );
			update_option( 'wpcf-custom-taxonomies', $taxes );

			return array(
				'success'  => true,
				'message'  => "Taxonomy '{$name}' deleted. " . count( $terms ) . ' terms processed.',
				'taxonomy' => $name,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// =============================================================================
	// TOOLSET FIELD GROUPS - Create and manage custom field groups
	// =============================================================================

	// LIST FIELD GROUPS
wp_register_ability(
	'toolset/list-field-groups',
	array(
		'label'               => 'List Field Groups',
		'description'         => 'List Toolset field groups and their attached fields.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Optional post type filter.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'groups'  => array( 'type' => 'array' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$filter = sanitize_key( $input['post_type'] ?? '' );
				$items = array();
				$page = 1;
				do {
					$groups = get_posts(
						array(
							'post_type'      => 'wp-types-group',
							'posts_per_page' => 200,
							'paged'          => $page,
							'post_status'    => array( 'publish', 'draft', 'private' ),
							'no_found_rows'  => true,
						)
					);

					foreach ( $groups as $group ) {
						$post_types = mcp_toolset_parse_group_list( get_post_meta( $group->ID, '_wp_types_group_post_types', true ) );
						if ( $filter && ! in_array( $filter, $post_types, true ) ) {
							continue;
						}
						$items[] = array(
							'id'         => $group->ID,
							'slug'       => $group->post_name,
							'title'      => $group->post_title,
							'post_types' => $post_types,
							'fields'     => mcp_toolset_parse_group_list( get_post_meta( $group->ID, '_wp_types_group_fields', true ) ),
							'taxonomies' => mcp_toolset_parse_group_list( get_post_meta( $group->ID, '_wp_types_group_taxonomies', true ) ),
							'edit_link'  => admin_url( 'post.php?post=' . $group->ID . '&action=edit' ),
						);
					}
					$page++;
				} while ( ! empty( $groups ) );

			return array(
				'success' => true,
				'groups'  => $items,
				'message' => 'Retrieved ' . count( $items ) . ' field groups.',
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// UPDATE FIELD GROUP
wp_register_ability(
	'toolset/update-field-group',
	array(
		'label'               => 'Update Field Group',
		'description'         => 'Update a Toolset field group fields/post types/taxonomies.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'group_id'   => array(
					'type'        => 'integer',
					'description' => 'Field group post ID.',
				),
				'group_slug' => array(
					'type'        => 'string',
					'description' => 'Field group slug.',
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'New group title.',
				),
				'post_types' => array(
					'type'        => 'array',
					'description' => 'Replace attached post types.',
					'items'       => array( 'type' => 'string' ),
				),
				'fields'     => array(
					'type'        => 'array',
					'description' => 'Replace attached field slugs.',
					'items'       => array( 'type' => 'string' ),
				),
				'taxonomies' => array(
					'type'        => 'array',
					'description' => 'Replace attached taxonomies.',
					'items'       => array( 'type' => 'string' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'group'   => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$group_id = isset( $input['group_id'] ) ? (int) $input['group_id'] : 0;
			if ( ! $group_id && ! empty( $input['group_slug'] ) ) {
				$existing = get_page_by_path( sanitize_key( $input['group_slug'] ), OBJECT, 'wp-types-group' );
				if ( $existing ) {
					$group_id = (int) $existing->ID;
				}
			}

			if ( ! $group_id ) {
				return array(
					'success' => false,
					'message' => 'group_id or group_slug is required.',
				);
			}

			$group = get_post( $group_id );
			if ( ! $group || 'wp-types-group' !== $group->post_type ) {
				return array(
					'success' => false,
					'message' => 'Field group not found.',
				);
			}

			if ( isset( $input['title'] ) ) {
				wp_update_post(
					array(
						'ID'         => $group_id,
						'post_title' => sanitize_text_field( $input['title'] ),
					)
				);
			}

			if ( isset( $input['post_types'] ) ) {
				$post_types = array_values( array_filter( array_map( 'sanitize_key', $input['post_types'] ) ) );
				update_post_meta( $group_id, '_wp_types_group_post_types', implode( ',', $post_types ) );
			}

			if ( isset( $input['fields'] ) ) {
				$fields = array_values( array_filter( array_map( 'sanitize_key', $input['fields'] ) ) );
				update_post_meta( $group_id, '_wp_types_group_fields', implode( ',', $fields ) );
			}

			if ( array_key_exists( 'taxonomies', $input ) ) {
				$taxonomies = array_values( array_filter( array_map( 'sanitize_key', $input['taxonomies'] ?? array() ) ) );
				update_post_meta( $group_id, '_wp_types_group_taxonomies', implode( ',', $taxonomies ) );
			}

			$updated = get_post( $group_id );

			return array(
				'success' => true,
				'message' => 'Field group updated.',
				'group'   => array(
					'id'         => $group_id,
					'slug'       => $updated->post_name,
					'title'      => $updated->post_title,
					'post_types' => mcp_toolset_parse_group_list( get_post_meta( $group_id, '_wp_types_group_post_types', true ) ),
					'fields'     => mcp_toolset_parse_group_list( get_post_meta( $group_id, '_wp_types_group_fields', true ) ),
					'taxonomies' => mcp_toolset_parse_group_list( get_post_meta( $group_id, '_wp_types_group_taxonomies', true ) ),
				),
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// CREATE CUSTOM FIELD GROUP
wp_register_ability(
	'toolset/create-field-group',
	array(
		'label'               => 'Create Custom Field Group',
		'description'         => 'Create a Toolset custom field group with fields.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'name', 'title' ),
			'properties'           => array(
				'name'       => array(
					'type'        => 'string',
					'description' => 'Field group slug (e.g., "issue-fields").',
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'Field group title (e.g., "Issue Fields").',
				),
				'post_types' => array(
					'type'        => 'array',
					'description' => 'Post types to attach to.',
					'items'       => array( 'type' => 'string' ),
				),
				'taxonomies' => array(
					'type'        => 'array',
					'description' => 'Taxonomies to attach to.',
					'items'       => array( 'type' => 'string' ),
				),
				'fields'     => array(
					'type'        => 'array',
					'description' => 'Custom fields to create.',
					'items'       => array(
						'type' => 'object',
						'properties' => array(
							'name'    => array( 'type' => 'string' ),
							'slug'    => array( 'type' => 'string' ),
							'type'    => array( 'type' => 'string', 'enum' => array( 'textfield', 'textarea', 'checkbox', 'select', 'radio', 'numeric', 'email', 'url', 'date' ) ),
							'options' => array( 'type' => 'object' ),
						),
					),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'group'   => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$name      = sanitize_key( $input['name'] );
			$title     = sanitize_text_field( $input['title'] );
			$post_type = array_values( array_filter( array_map( 'sanitize_key', $input['post_types'] ?? array() ) ) );
			$taxonomies = array_values( array_filter( array_map( 'sanitize_key', $input['taxonomies'] ?? array() ) ) );
			$fields    = $input['fields'] ?? array();

			$existing = get_page_by_path( $name, OBJECT, 'wp-types-group' );
			if ( $existing ) {
				return array(
					'success' => false,
					'message' => 'Field group "' . $name . '" already exists.',
				);
			}

			$field_defs  = array();
			$field_slugs = array();
			foreach ( $fields as $field ) {
				$slug = sanitize_key( $field['slug'] ?? $field['name'] ?? '' );
				if ( empty( $slug ) ) {
					continue;
				}
				$field['slug'] = $slug;
				if ( empty( $field['name'] ) ) {
					$field['name'] = ucfirst( $slug );
				}
				$result = mcp_toolset_save_field_definition( $field );
				if ( empty( $result['success'] ) ) {
					return $result;
				}
				$field_defs[ $slug ] = $result['field'];
				$field_slugs[]       = $slug;
			}

			$group_id = wp_insert_post(
				array(
					'post_type'   => 'wp-types-group',
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_name'   => $name,
				),
				true
			);

			if ( is_wp_error( $group_id ) ) {
				return array(
					'success' => false,
					'message' => $group_id->get_error_message(),
				);
			}

			update_post_meta( $group_id, '_wp_types_group_post_types', implode( ',', $post_type ) );
			update_post_meta( $group_id, '_wp_types_group_fields', implode( ',', $field_slugs ) );
			if ( ! empty( $taxonomies ) ) {
				update_post_meta( $group_id, '_wp_types_group_taxonomies', implode( ',', $taxonomies ) );
			}

			return array(
				'success' => true,
				'message' => "Field group '{$title}' created with " . count( $field_defs ) . ' fields.',
				'group'   => array(
					'id'         => $group_id,
					'name'       => $name,
					'title'      => $title,
					'post_types' => $post_type,
					'fields'     => array_keys( $field_defs ),
					'taxonomies' => $taxonomies,
				),
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// =============================================================================
	// TOOLSET RELATIONSHIPS - Create and manage post relationships
	// =============================================================================

	// CREATE POST RELATIONSHIP
wp_register_ability(
	'toolset/create-relationship',
	array(
		'label'               => 'Create Post Relationship',
		'description'         => 'Create a Toolset relationship between post types.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'name', 'from', 'to' ),
			'properties'           => array(
				'name'        => array(
					'type'        => 'string',
					'description' => 'Relationship name/slug (e.g., "issue-article").',
				),
				'from'        => array(
					'type'        => 'string',
					'description' => 'From post type (e.g., "issue").',
				),
				'to'          => array(
					'type'        => 'string',
					'description' => 'To post type (e.g., "post").',
				),
				'cardinality' => array(
					'type'        => 'string',
					'enum'        => array( 'one-to-many', 'many-to-one', 'one-to-one', 'many-to-many' ),
					'default'     => 'many-to-one',
					'description' => 'Relationship cardinality.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'relationship' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$name       = sanitize_key( $input['name'] );
			$from       = sanitize_key( $input['from'] );
			$to         = sanitize_key( $input['to'] );
			$cardinality = $input['cardinality'] ?? 'many-to-one';

			// Toolset relationships structure
			$relationship = array(
				'id'           => $name,
				'name'         => $name,
				'from'         => $from,
				'to'           => $to,
				'cardinality'  => $cardinality,
				'_builtin'     => false,
			);

			// Save to Toolset relationships option
			$existing = get_option( 'wpcf-custom-post-relationships', array() );
			$existing[ $name ] = $relationship;
			update_option( 'wpcf-custom-post-relationships', $existing );

			return array(
				'success' => true,
				'message' => "Relationship '{$from} → {$to}' created.",
				'relationship' => $relationship,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// ADD POST RELATIONSHIP
wp_register_ability(
	'toolset/add-post-relationship',
	array(
		'label'               => 'Add Post Relationship',
		'description'         => 'Link two posts in a Toolset relationship.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'relationship', 'from_id', 'to_id' ),
			'properties'           => array(
				'relationship' => array(
					'type'        => 'string',
					'description' => 'Relationship name (e.g., "issue-article").',
				),
				'from_id'      => array(
					'type'        => 'integer',
					'description' => 'From post ID.',
				),
				'to_id'        => array(
					'type'        => 'integer',
					'description' => 'To post ID.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$relationship = sanitize_key( $input['relationship'] );
			$from_id      = (int) $input['from_id'];
			$to_id        = (int) $input['to_id'];

			// Store relationship using Toolset's meta approach
			$meta_key = "_wpcf_relationship_{$relationship}";
			$existing = get_post_meta( $from_id, $meta_key, true );
			$existing = is_array( $existing ) ? $existing : array();

			if ( ! in_array( $to_id, $existing, true ) ) {
				$existing[] = $to_id;
				update_post_meta( $from_id, $meta_key, $existing );
			}

			return array(
				'success' => true,
				'message' => "Post {$from_id} linked to {$to_id} via '{$relationship}'.",
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// GET POSTS BY RELATIONSHIP
wp_register_ability(
	'toolset/get-posts-by-relationship',
	array(
		'label'               => 'Get Posts by Relationship',
		'description'         => 'Get posts related via a Toolset relationship.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'relationship', 'post_id' ),
			'properties'           => array(
				'relationship' => array(
					'type'        => 'string',
					'description' => 'Relationship name (e.g., "issue-article").',
				),
				'post_id'      => array(
					'type'        => 'integer',
					'description' => 'The post to find relationships for.',
				),
				'direction'    => array(
					'type'        => 'string',
					'enum'        => array( 'from', 'to', 'both' ),
					'default'     => 'both',
					'description' => 'Get posts this post relates TO, FROM, or both.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'related' => array( 'type' => 'array' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$relationship = sanitize_key( $input['relationship'] );
			$post_id      = (int) $input['post_id'];
			$direction    = $input['direction'] ?? 'both';

			$meta_key = "_wpcf_relationship_{$relationship}";
			$related  = array();

			// Get posts this post relates TO
			if ( in_array( $direction, array( 'to', 'both' ), true ) ) {
				$to_posts = get_post_meta( $post_id, $meta_key, true );
				$to_posts = is_array( $to_posts ) ? $to_posts : array();
				foreach ( $to_posts as $id ) {
					$related[] = array(
						'id'       => $id,
						'title'    => get_the_title( $id ),
						'direction' => 'to',
					);
				}
			}

			// Note: For 'from' direction, we'd need inverse lookups
			// This is a simplified implementation

			return array(
				'success' => true,
				'message' => 'Found ' . count( $related ) . ' related posts.',
				'related' => $related,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// =============================================================================
	// TOOLSET ACCESS - Manage user permissions
	// =============================================================================

	// CHECK USER ACCESS
wp_register_ability(
	'toolset/check-access',
	array(
		'label'               => 'Check User Access',
		'description'         => 'Check if current user can perform an action based on Toolset Access.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'capability' ),
			'properties'           => array(
				'capability' => array(
					'type'        => 'string',
					'description' => 'Capability to check (e.g., "edit_posts", "delete_issues").',
				),
				'post_id'    => array(
					'type'        => 'integer',
					'description' => 'Optional post ID for post-specific checks.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'      => array( 'type' => 'boolean' ),
				'has_access'   => array( 'type' => 'boolean' ),
				'message'      => array( 'type' => 'string' ),
				'user'         => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$capability = $input['capability'];
			$post_id    = $input['post_id'] ?? null;

			$user = wp_get_current_user();
			$has_access = false;

			// Check basic capability
			if ( $post_id ) {
				$has_access = current_user_can( $capability, $post_id );
			} else {
				$has_access = current_user_can( $capability );
			}

			return array(
				'success'    => true,
				'has_access' => $has_access,
				'message'    => $has_access ? 'Access granted' : 'Access denied',
				'user'       => array(
					'id'         => $user->ID,
					'login'      => $user->user_login,
					'email'      => $user->user_email,
					'roles'      => $user->roles,
				),
			);
		},
		'permission_callback' => function (): bool {
			return is_user_logged_in();
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// GET USER CAPABILITIES
wp_register_ability(
	'toolset/get-user-capabilities',
	array(
		'label'               => 'Get User Capabilities',
		'description'         => 'Get all capabilities for a specific user.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'          => 'object',
			'properties'    => array(
				'user_id' => array(
					'type'        => 'integer',
					'description' => 'User ID (optional, defaults to current user).',
				),
			),
			'minProperties' => 0,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'      => array( 'type' => 'boolean' ),
				'message'      => array( 'type' => 'string' ),
				'user'         => array( 'type' => 'object' ),
				'capabilities' => array( 'type' => 'array' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$user_id = $input['user_id'] ?? get_current_user_id();
			$user    = get_userdata( $user_id );

			if ( ! $user ) {
				return array(
					'success' => false,
					'message' => 'User not found.',
				);
			}

			return array(
				'success' => true,
				'message' => 'Retrieved capabilities.',
				'user'    => array(
					'id'     => $user->ID,
					'login'  => $user->user_login,
					'email'  => $user->user_email,
					'roles'  => $user->roles,
				),
				'capabilities' => array_keys( $user->allcaps ),
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'list_users' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// UPDATE FIELD DEFINITION
wp_register_ability(
	'toolset/update-field',
	array(
		'label'               => 'Update Field Definition',
		'description'         => 'Update or create a Toolset field definition. Useful for changing field types, options, etc.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'          => 'object',
			'required'      => array( 'slug' ),
			'properties'    => array(
				'slug'        => array(
					'type'        => 'string',
					'description' => 'Field slug (e.g., "severity", "status").',
				),
				'name'        => array(
					'type'        => 'string',
					'description' => 'Field display name (optional, defaults to slug).',
				),
				'type'        => array(
					'type'        => 'string',
					'description' => 'Field type: textfield, textarea, select, radio, checkbox, numeric.',
				),
					'meta_key'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'type'        => 'string',
						'description' => 'Meta key (optional, auto-generated as wpcf-{slug}).',
					),
				'options'     => array(
					'type'        => 'object',
					'description' => 'For select/radio/checkbox: array of options (key => title).',
				),
				'default'     => array(
					'type'        => 'string',
					'description' => 'Default value or default option key.',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Field description.',
				),
			),
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success'  => array( 'type' => 'boolean' ),
				'message'  => array( 'type' => 'string' ),
				'field'    => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$result = mcp_toolset_save_field_definition( $input );
			if ( empty( $result['success'] ) ) {
				return $result;
			}

			return array(
				'success' => true,
				'message' => 'Field "' . $result['field']['slug'] . '" updated.',
				'field'   => $result['field'],
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// GET FIELD DEFINITION
wp_register_ability(
	'toolset/get-field',
	array(
		'label'               => 'Get Field Definition',
		'description'         => 'Retrieve a Toolset field definition by slug.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'slug' ),
			'properties'           => array(
				'slug' => array(
					'type'        => 'string',
					'description' => 'Field slug.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'field'   => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => function ( array $input = array() ): array {
			$slug = sanitize_key( $input['slug'] ?? '' );
			if ( empty( $slug ) ) {
				return array(
					'success' => false,
					'message' => 'Field slug is required.',
				);
			}

			$fields = mcp_toolset_get_field_definitions();
			if ( ! isset( $fields[ $slug ] ) ) {
				return array(
					'success' => false,
					'message' => 'Field not found.',
				);
			}

			return array(
				'success' => true,
				'message' => 'Field retrieved.',
				'field'   => $fields[ $slug ],
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
		),
	)
);

	// AUDIT TOOLSET USAGE
	wp_register_ability(
		'toolset/audit-usage',
		array(
			'label'               => 'Audit Toolset Usage',
			'description'         => 'Read-only audit of posts, pages, and Toolset configuration objects that appear to use Toolset blocks, shortcodes, fields, Views, or templates.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_types'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional post types to scan. Empty scans all non-attachment content post types.',
					),
					'statuses'     => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
						),
						'description' => 'Post statuses to scan. Defaults to publish.',
					),
					'include_meta' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Whether to scan post meta for Toolset markers.',
					),
					'limit'        => array(
						'type'        => 'integer',
						'default'     => 500,
						'minimum'     => 1,
						'maximum'     => 2000,
						'description' => 'Maximum rows to scan per content/meta query.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'           => array( 'type' => 'boolean' ),
					'active_plugins'    => array( 'type' => 'array' ),
					'content_matches'   => array( 'type' => 'array' ),
					'meta_matches'      => array( 'type' => 'array' ),
					'toolset_objects'   => array( 'type' => 'array' ),
					'custom_types'      => array( 'type' => 'array' ),
					'custom_taxonomies' => array( 'type' => 'array' ),
					'message'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				return mcp_toolset_audit_usage( $input );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	// CLEAN STALE TOOLSET DATA
	wp_register_ability(
		'toolset/cleanup-stale-data',
		array(
			'label'               => 'Clean Stale Toolset Data',
			'description'         => 'Destructive cleanup for stale Toolset metadata, Toolset View/configuration posts, and toolsetDSVersion block attributes after frontend usage has been replaced.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'dry_run'                  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Preview cleanup targets without changing data.',
					),
					'delete_stale_meta'        => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Delete known stale Toolset postmeta keys such as _wpv_* and _views_template.',
					),
					'delete_toolset_objects'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Permanently delete old Toolset View/template/form/field-group posts.',
					),
					'clean_toolset_ds_version' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Remove stale toolsetDSVersion block attributes from post content.',
					),
					'limit'                    => array(
						'type'        => 'integer',
						'default'     => 5000,
						'minimum'     => 1,
						'maximum'     => 20000,
						'description' => 'Maximum cleanup rows/posts per operation.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'dry_run'            => array( 'type' => 'boolean' ),
					'meta_deleted'       => array( 'type' => 'integer' ),
					'objects_deleted'    => array( 'type' => 'integer' ),
					'content_cleaned'    => array( 'type' => 'integer' ),
					'meta_candidates'    => array( 'type' => 'integer' ),
					'object_candidates'  => array( 'type' => 'array' ),
					'content_candidates' => array( 'type' => 'array' ),
					'message'            => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				return mcp_toolset_cleanup_stale_data( $input );
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);
}


// Register the abilities with WordPress
add_action( 'wp_abilities_api_init', 'mcp_register_toolset_abilities' );
