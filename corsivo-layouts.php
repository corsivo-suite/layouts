<?php
/**
 * Plugin Name:       Corsivo Layouts
 * Description:       Layout di partenza per l'editor a blocchi: applica strutture di blocchi predefinite a post e pagine.
 * Version:           1.2.2
 * Update URI:        https://www.corsivo.dev/plugins/corsivo-layouts
 * Text Domain:       corsivo-layouts
 * Domain Path:       /languages
 * Author:            Salvatore Corsi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP:      8.1
 */

namespace Corsivo\Layouts;

defined( 'ABSPATH' ) || exit;

const VERSION                 = '1.2.1';
const SCRIPT_HANDLE           = 'corsivo-layouts-editor';
const MAX_TEMPLATES           = 100;
const MAX_TEMPLATE_CANDIDATES = 500;
const MAX_BLOCKS_PER_TEMPLATE = 250;
const MAX_BLOCK_DEPTH         = 20;
const MAX_TEMPLATE_BYTES      = 262144;
const MAX_CONFIGURATION_BYTES = 2097152;

function template_collections( $context = array() ) {
	$collections = apply_filters( 'corsivo_layouts_templates', array(), $context );

	return is_array( $collections ) ? $collections : array();
}

function reject_template( $collection, $index, $reason, $detail = null ) {
	$diagnostic = array(
		'collection' => $collection,
		'index'      => (string) $index,
		'reason'     => $reason,
	);
	$logged_reason = $reason;

	if ( is_string( $detail ) && '' !== $detail ) {
		$diagnostic['detail'] = $detail;
		$logged_reason        = $reason . ' (' . $detail . ')';
	}

	do_action( 'corsivo_layouts_template_rejected', $diagnostic );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		_doing_it_wrong(
			'corsivo_layouts_templates',
			sprintf(
				__( 'Layout rifiutato nella raccolta "%1$s" alla posizione %2$s: %3$s.', 'corsivo-layouts' ),
				$collection,
				(string) $index,
				$logged_reason
			),
			VERSION
		);
	}

	return null;
}

function normalize_block_templates( $blocks, &$block_count, $depth = 0, &$block_error = null ) {
	if ( ! is_array( $blocks ) ) {
		$block_error = 'invalid_block_structure';
		return null;
	}

	if ( $depth > MAX_BLOCK_DEPTH && ! empty( $blocks ) ) {
		$block_error = 'max_block_depth_exceeded';
		return null;
	}

	$normalized_blocks = array();

	foreach ( $blocks as $block ) {
		if (
			! is_array( $block ) ||
			! array_key_exists( 0, $block ) ||
			! is_string( $block[0] ) ||
			! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block[0] )
		) {
			$block_error = 'invalid_block_structure';
			return null;
		}

		$attributes   = $block[1] ?? array();
		$inner_blocks = $block[2] ?? array();

		if ( ! is_array( $attributes ) || ! is_array( $inner_blocks ) ) {
			$block_error = 'invalid_block_structure';
			return null;
		}

		++$block_count;

		if ( $block_count > MAX_BLOCKS_PER_TEMPLATE ) {
			$block_error = 'max_blocks_exceeded';
			return null;
		}

		if ( false === wp_json_encode( $attributes ) ) {
			$block_error = 'invalid_block_attributes';
			return null;
		}

		$normalized_inner_blocks = normalize_block_templates( $inner_blocks, $block_count, $depth + 1, $block_error );

		if ( null === $normalized_inner_blocks ) {
			return null;
		}

		$normalized_blocks[] = array( $block[0], $attributes, $normalized_inner_blocks );
	}

	return $normalized_blocks;
}

function block_template_names( $blocks ) {
	$names = array();

	foreach ( $blocks as $block ) {
		$names[] = $block[0];
		$names   = array_merge( $names, block_template_names( $block[2] ) );
	}

	return $names;
}

function block_templates_are_allowed( $blocks, $allowed_block_types ) {
	if ( true === $allowed_block_types ) {
		return true;
	}

	if ( false === $allowed_block_types || ! is_array( $allowed_block_types ) ) {
		return false;
	}

	return array() === array_diff( block_template_names( $blocks ), $allowed_block_types );
}

function normalized_url_port( $scheme, $port ) {
	if ( null !== $port ) {
		return (int) $port;
	}

	return match ( $scheme ) {
		'http'  => 80,
		'https' => 443,
		default => null,
	};
}

function normalize_preview_image( $image, $template_id, $post_type ) {
	if ( ! is_string( $image ) ) {
		return '';
	}

	$url = esc_url_raw( trim( $image ), array( 'http', 'https' ) );

	if ( '' === $url ) {
		return '';
	}

	$url_parts = wp_parse_url( $url );

	if ( false === $url_parts ) {
		return '';
	}

	$preview_host   = strtolower( (string) ( $url_parts['host'] ?? '' ) );
	$preview_scheme = strtolower( (string) ( $url_parts['scheme'] ?? '' ) );

	if ( '' !== $preview_scheme && '' === $preview_host ) {
		return '';
	}

	if ( '' === $preview_host ) {
		return $url;
	}

	$preview_port = $url_parts['port'] ?? null;

	foreach ( array( home_url(), site_url(), content_url() ) as $local_url ) {
		$local_host   = strtolower( (string) wp_parse_url( $local_url, PHP_URL_HOST ) );
		$local_scheme = strtolower( (string) wp_parse_url( $local_url, PHP_URL_SCHEME ) );
		$local_port   = wp_parse_url( $local_url, PHP_URL_PORT );
		$resolved_preview_scheme = '' === $preview_scheme ? $local_scheme : $preview_scheme;

		if (
			$preview_host === $local_host &&
			$resolved_preview_scheme === $local_scheme &&
			normalized_url_port( $resolved_preview_scheme, $preview_port ) === normalized_url_port( $local_scheme, $local_port )
		) {
			return $url;
		}
	}

	$allow_remote = apply_filters(
		'corsivo_layouts_allow_remote_preview',
		false,
		$url,
		$template_id,
		$post_type
	);

	return $allow_remote ? $url : '';
}

function value_has_disallowed_remote_url( $value, $template_id, $post_type ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $nested_value ) {
			if ( value_has_disallowed_remote_url( $nested_value, $template_id, $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	if ( ! is_string( $value ) ) {
		return false;
	}

	if ( preg_match( '/^(?:https?:|\/\/)/i', $value ) ) {
		return '' === normalize_preview_image( $value, $template_id, $post_type );
	}

	if ( ! preg_match_all( '/(?:https?:[^\s"\'<>]+|\/\/[^\s"\'<>]+)/i', $value, $matches ) ) {
		return false;
	}

	foreach ( $matches[0] as $url ) {
		if ( '' === normalize_preview_image( $url, $template_id, $post_type ) ) {
			return true;
		}
	}

	return false;
}

function block_templates_have_remote_resources( $blocks, $template_id, $post_type, $resource_attributes = null ) {
	if ( null === $resource_attributes ) {
		$default_resource_attributes = array(
			'core/audio'      => array( 'src' ),
			'core/cover'      => array( 'url' ),
			'core/embed'      => array( 'url' ),
			'core/file'       => array( 'href' ),
			'core/html'       => array( 'content' ),
			'core/image'      => array( 'url' ),
			'core/media-text' => array( 'mediaUrl' ),
			'core/video'      => array( 'poster', 'src' ),
		);
		$resource_attributes = apply_filters(
			'corsivo_layouts_preview_resource_attributes',
			$default_resource_attributes,
			$template_id,
			$post_type
		);

		if ( ! is_array( $resource_attributes ) ) {
			$resource_attributes = $default_resource_attributes;
		}

		foreach ( $default_resource_attributes as $block_name => $default_attribute_names ) {
			$filtered_attribute_names = $resource_attributes[ $block_name ] ?? array();

			if ( ! is_array( $filtered_attribute_names ) ) {
				$filtered_attribute_names = array();
			}

			$resource_attributes[ $block_name ] = array_values(
				array_unique(
					array_merge(
						$default_attribute_names,
						array_filter( $filtered_attribute_names, 'is_string' )
					)
				)
			);
		}
	}

	foreach ( $blocks as $block ) {
		$style            = (array) ( $block[1]['style'] ?? array() );
		$background       = (array) ( $style['background'] ?? array() );
		$background_image = $background['backgroundImage'] ?? null;

		if ( is_array( $background_image ) || is_object( $background_image ) ) {
			$background_image = ( (array) $background_image )['url'] ?? null;
		}

		if ( value_has_disallowed_remote_url( $background_image, $template_id, $post_type ) ) {
			return true;
		}

		$attribute_names = $resource_attributes[ $block[0] ] ?? array();

		if ( ! is_array( $attribute_names ) ) {
			$attribute_names = array();
		}

		foreach ( $attribute_names as $attribute_name ) {
			if (
				is_string( $attribute_name ) &&
				isset( $block[1][ $attribute_name ] ) &&
				value_has_disallowed_remote_url( $block[1][ $attribute_name ], $template_id, $post_type )
			) {
				return true;
			}
		}

		if ( block_templates_have_remote_resources( $block[2], $template_id, $post_type, $resource_attributes ) ) {
			return true;
		}
	}

	return false;
}

function normalize_template( $template, $collection, $index, $post_type, $page_templates, $allowed_block_types ) {
	if ( ! is_array( $template ) ) {
		return reject_template( $collection, $index, 'invalid_template' );
	}

	$name = isset( $template['name'] ) && is_string( $template['name'] )
		? sanitize_text_field( $template['name'] )
		: '';

	if ( '' === $name ) {
		return reject_template( $collection, $index, 'missing_name' );
	}

	$block_count = 0;
	$block_error = null;
	$blocks      = normalize_block_templates( $template['blocks'] ?? null, $block_count, 0, $block_error );

	if ( null === $blocks ) {
		return reject_template( $collection, $index, 'invalid_blocks', $block_error );
	}

	if ( ! block_templates_are_allowed( $blocks, $allowed_block_types ) ) {
		return reject_template( $collection, $index, 'disallowed_block' );
	}

	$page_template_exists = array_key_exists( 'page_template', $template );
	$page_template        = '';

	if ( $page_template_exists ) {
		if ( ! is_string( $template['page_template'] ) ) {
			return reject_template( $collection, $index, 'invalid_page_template' );
		}

		$page_template = trim( $template['page_template'] );

		if ( '' !== $page_template && ! array_key_exists( $page_template, $page_templates ) ) {
			return reject_template( $collection, $index, 'invalid_page_template' );
		}
	}

	$fingerprint = wp_json_encode( array( $name, $blocks, $page_template_exists ? $page_template : null ) );

	if ( false === $fingerprint || strlen( $fingerprint ) > MAX_TEMPLATE_BYTES ) {
		return reject_template( $collection, $index, 'template_too_large' );
	}

	$template_id = isset( $template['id'] ) && is_string( $template['id'] )
		? sanitize_key( $template['id'] )
		: '';

	if ( '' === $template_id ) {
		$template_id = 'layout-' . substr( hash( 'sha256', (string) $fingerprint ), 0, 16 );
	}

	$normalized = array(
		'id'     => $template_id,
		'name'   => $name,
		'blocks' => $blocks,
	);

	if ( isset( $template['description'] ) && is_string( $template['description'] ) ) {
		$description = sanitize_text_field( $template['description'] );

		if ( '' !== $description ) {
			$normalized['description'] = $description;
		}
	}

	if ( isset( $template['image'] ) ) {
		$image = normalize_preview_image( $template['image'], $template_id, $post_type );

		if ( '' !== $image ) {
			$normalized['image'] = $image;
		}
	}

	if ( $page_template_exists ) {
		$normalized['page_template'] = $page_template;
	}

	if ( block_templates_have_remote_resources( $blocks, $template_id, $post_type ) ) {
		$normalized['preview_disabled'] = true;
	}

	return $normalized;
}

function normalize_collection( $collection, $collection_name, $post_type, $page_templates, $allowed_block_types ) {
	$normalized = array(
		'has_open_on_new_post' => false,
		'open_on_new_post'     => false,
		'inherit_all'          => true,
		'templates'            => array(),
	);

	if ( ! is_array( $collection ) ) {
		reject_template( $collection_name, 'collection', 'invalid_collection' );
		return $normalized;
	}

	$normalized['has_open_on_new_post'] = array_key_exists( 'open_on_new_post', $collection );
	$normalized['open_on_new_post']     = ! empty( $collection['open_on_new_post'] );
	$normalized['inherit_all']          = ! array_key_exists( 'inherit_all', $collection )
		|| ! empty( $collection['inherit_all'] );

	if ( ! array_key_exists( 'templates', $collection ) ) {
		return $normalized;
	}

	if ( ! is_array( $collection['templates'] ) ) {
		reject_template( $collection_name, 'templates', 'invalid_collection' );
		return $normalized;
	}

	$template_ids = array();
	$candidates   = 0;

	foreach ( $collection['templates'] as $index => $template ) {
		++$candidates;

		if ( $candidates > MAX_TEMPLATE_CANDIDATES || count( $normalized['templates'] ) >= MAX_TEMPLATES ) {
			reject_template( $collection_name, $index, 'truncated_collection' );
			break;
		}

		$normalized_template = normalize_template(
			$template,
			$collection_name,
			$index,
			$post_type,
			$page_templates,
			$allowed_block_types
		);

		if ( null === $normalized_template ) {
			continue;
		}

		if ( isset( $template_ids[ $normalized_template['id'] ] ) ) {
			reject_template( $collection_name, $index, 'duplicate_template_id' );
			continue;
		}

		$template_ids[ $normalized_template['id'] ] = true;
		$normalized['templates'][]                  = $normalized_template;
	}

	return $normalized;
}

function editor_configuration( $post_type, $post = null ) {
	$context = array(
		'post_type' => $post_type,
		'post_id'   => $post instanceof \WP_Post ? $post->ID : 0,
	);

	$collections = template_collections( $context );

	if ( ! isset( $collections[ $post_type ] ) && ! isset( $collections['all'] ) ) {
		return array(
			'openOnNewPost' => false,
			'templates'     => array(),
		);
	}

	$page_templates = (array) wp_get_theme()->get_page_templates( $post, $post_type );
	$editor_context = new \WP_Block_Editor_Context( array( 'post' => $post ) );
	$allowed_blocks = get_allowed_block_types( $editor_context );
	$specific       = normalize_collection(
		$collections[ $post_type ] ?? array(),
		$post_type,
		$post_type,
		$page_templates,
		$allowed_blocks
	);
	$use_shared       = $specific['inherit_all'];
	$templates        = $specific['templates'];
	$template_ids     = array_fill_keys( array_column( $templates, 'id' ), true );
	$open_on_new_post = $specific['has_open_on_new_post']
		? $specific['open_on_new_post']
		: false;

	if ( $use_shared ) {
		$shared = normalize_collection(
			$collections['all'] ?? array(),
			'all',
			$post_type,
			$page_templates,
			$allowed_blocks
		);

		foreach ( $shared['templates'] as $template ) {
			if ( isset( $template_ids[ $template['id'] ] ) ) {
				continue;
			}

			$templates[]                    = $template;
			$template_ids[ $template['id'] ] = true;
		}

		if ( ! $specific['has_open_on_new_post'] ) {
			$open_on_new_post = $shared['open_on_new_post'];
		}
	}

	return array(
		'openOnNewPost' => $open_on_new_post,
		'templates'     => array_slice( $templates, 0, MAX_TEMPLATES ),
	);
}

function user_can_edit_screen( $post_type, $post ) {
	if ( $post instanceof \WP_Post ) {
		return $post_type === $post->post_type && current_user_can( 'edit_post', $post->ID );
	}

	$post_type_object = get_post_type_object( $post_type );

	if ( ! $post_type_object ) {
		return false;
	}

	$create_capability = $post_type_object->cap->create_posts ?? $post_type_object->cap->edit_posts;

	return current_user_can( $create_capability );
}

function current_editor_post() {
	if ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
		return $GLOBALS['post'];
	}

	if ( ! isset( $_GET['post'] ) || ! is_scalar( $_GET['post'] ) ) {
		return null;
	}

	return get_post( absint( wp_unslash( $_GET['post'] ) ) );
}

function report_editor_asset_issue( $reason ) {
	$diagnostic = array( 'reason' => $reason );

	do_action( 'corsivo_layouts_editor_asset_issue', $diagnostic );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		_doing_it_wrong(
			'corsivo_layouts_editor_assets',
			sprintf(
				__( 'Asset editor non disponibile: %s.', 'corsivo-layouts' ),
				$reason
			),
			VERSION
		);
	}

	return null;
}

function load_editor_asset_metadata( $asset_file ) {
	if ( ! is_readable( $asset_file ) ) {
		return report_editor_asset_issue( 'missing_asset_metadata' );
	}

	try {
		$asset = include $asset_file;
	} catch ( \Throwable ) {
		return report_editor_asset_issue( 'invalid_asset_metadata' );
	}

	if (
		! is_array( $asset ) ||
		! array_key_exists( 'dependencies', $asset ) ||
		! is_array( $asset['dependencies'] ) ||
		! array_key_exists( 'version', $asset ) ||
		! is_scalar( $asset['version'] )
	) {
		return report_editor_asset_issue( 'invalid_asset_metadata' );
	}

	foreach ( $asset['dependencies'] as $dependency ) {
		if ( ! is_string( $dependency ) || '' === $dependency ) {
			return report_editor_asset_issue( 'invalid_asset_metadata' );
		}
	}

	return array(
		'dependencies' => array_values( $asset['dependencies'] ),
		'version'      => (string) $asset['version'],
	);
}

function editor_asset_metadata() {
	return load_editor_asset_metadata( __DIR__ . '/editor/build/index.asset.php' );
}

function enqueue_editor_assets() {
	$screen = get_current_screen();

	if ( ! $screen || 'post' !== $screen->base || empty( $screen->post_type ) ) {
		return;
	}

	$post_type = sanitize_key( $screen->post_type );
	$post      = current_editor_post();

	if ( ! user_can_edit_screen( $post_type, $post ) ) {
		return;
	}

	$script_file = __DIR__ . '/editor/build/index.js';

	if ( ! is_readable( $script_file ) ) {
		report_editor_asset_issue( 'missing_editor_script' );
		return;
	}

	$asset = editor_asset_metadata();

	if ( ! $asset ) {
		return;
	}

	$configuration = editor_configuration( $post_type, $post );

	if ( empty( $configuration['templates'] ) ) {
		return;
	}

	$configuration_json = wp_json_encode(
		$configuration,
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
	);

	if ( false === $configuration_json ) {
		reject_template( 'configuration', 'payload', 'invalid_configuration' );
		return;
	}

	if ( strlen( $configuration_json ) > MAX_CONFIGURATION_BYTES ) {
		reject_template( 'configuration', 'payload', 'configuration_too_large' );
		return;
	}

	wp_enqueue_script(
		SCRIPT_HANDLE,
		plugins_url( 'editor/build/index.js', __FILE__ ),
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_set_script_translations( SCRIPT_HANDLE, 'corsivo-layouts', __DIR__ . '/languages' );

	if ( is_readable( __DIR__ . '/editor/build/index.css' ) ) {
		wp_enqueue_style(
			SCRIPT_HANDLE,
			plugins_url( 'editor/build/index.css', __FILE__ ),
			array(),
			$asset['version']
		);
		wp_style_add_data( SCRIPT_HANDLE, 'rtl', 'replace' );
	}

	wp_add_inline_script(
		SCRIPT_HANDLE,
		'window.corsivoLayoutsConfig = Object.freeze(' . $configuration_json . ');',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets' );
