<?php

defined( 'ABSPATH' ) || exit;

require_once WP_PLUGIN_DIR . '/corsivo-layouts/corsivo-layouts.php';

function corsivo_layouts_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'example/hero' ) ) {
	register_block_type( 'example/hero' );
}

$fixture_post_id = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'draft',
		'post_title'  => 'Corsivo Layouts smoke test',
	),
	true
);

if ( is_wp_error( $fixture_post_id ) ) {
	throw new RuntimeException( $fixture_post_id->get_error_message() );
}

$fixture_deleted = false;
$fixture_post    = get_post( $fixture_post_id );

register_shutdown_function(
	static function () use ( $fixture_post_id, &$fixture_deleted ) {
		if ( ! $fixture_deleted ) {
			wp_delete_post( $fixture_post_id, true );
		}
	}
);

$rejections = array();
$diagnostics_callback = static function ( $diagnostic ) use ( &$rejections ) {
	$rejections[] = $diagnostic;
};
$canonical_callback = static function ( $collections, $context ) {
	corsivo_layouts_assert( 'post' === $context['post_type'], 'Missing post type context.' );

	$collections['post'] = array(
		'open_on_new_post' => true,
		'templates'        => array(
			array(
				'id'     => 'blank',
				'name'   => 'Blank',
				'blocks' => array(),
			),
			array(
				'id'     => 'remote-block',
				'name'   => 'Remote block',
				'blocks' => array(
					array( 'core/image', array( 'url' => 'https://tracker.example/image.jpg' ), array() ),
				),
			),
			array(
				'id'     => 'remote-block-with-image',
				'name'   => 'Remote block with image',
				'image'  => home_url( '/missing-preview.jpg' ),
				'blocks' => array(
					array( 'core/image', array( 'url' => 'https:tracker.example/image.jpg' ), array() ),
				),
			),
			array(
				'id'     => 'valid',
				'name'   => 'Valid',
				'image'  => 'https://tracker.example/preview.jpg',
				'blocks' => array( array( 'core/paragraph', array( 'content' => 'Safe' ), array() ) ),
			),
			array(
				'id'     => 'custom-remote-block',
				'name'   => 'Custom remote block',
				'blocks' => array(
					array( 'example/hero', array( 'backgroundImage' => 'https://tracker.example/hero.jpg' ), array() ),
				),
			),
			array(
				'id'     => 'valid',
				'name'   => 'Duplicate',
				'blocks' => array( array( 'core/heading', array(), array() ) ),
			),
			array(
				'name'   => 'Broken',
				'blocks' => 'invalid',
			),
			array(
				'name'          => 'Invalid page template',
				'page_template' => 'missing.php',
				'blocks'        => array( array( 'core/paragraph', array(), array() ) ),
			),
		),
	);
	$collections['all']  = array(
		'templates' => array(
			array(
				'id'     => 'shared',
				'name'   => 'Shared',
				'blocks' => array( array( 'core/image', array(), array() ) ),
			),
			array(
				'id'     => 'valid',
				'name'   => 'Shared duplicate',
				'blocks' => array( array( 'core/paragraph', array(), array() ) ),
			),
		),
	);

	return $collections;
};
$resource_attributes_callback = static function ( $resource_attributes ) {
	$resource_attributes['example/hero'] = array( 'backgroundImage' );

	return $resource_attributes;
};

add_action( 'corsivo_layouts_template_rejected', $diagnostics_callback );
add_filter( 'corsivo_layouts_templates', $canonical_callback, 10, 2 );
add_filter( 'corsivo_layouts_preview_resource_attributes', $resource_attributes_callback );

$configuration = \Corsivo\Layouts\editor_configuration( 'post', $fixture_post );
$templates_by_id = array_column( $configuration['templates'], null, 'id' );

corsivo_layouts_assert( true === $configuration['openOnNewPost'], 'Auto-open was not preserved.' );
corsivo_layouts_assert( 6 === count( $configuration['templates'] ), 'Unexpected canonical template count.' );
corsivo_layouts_assert( 'Valid' === $templates_by_id['valid']['name'], 'Specific template precedence failed.' );
corsivo_layouts_assert( isset( $templates_by_id['blank'] ), 'Blank template compatibility failed.' );
corsivo_layouts_assert( ! isset( $templates_by_id['valid']['image'] ), 'Remote preview was not removed.' );
corsivo_layouts_assert(
	! empty( $templates_by_id['remote-block']['preview_disabled'] ),
	'Remote block preview was not disabled.'
);
corsivo_layouts_assert(
	! empty( $templates_by_id['remote-block-with-image']['preview_disabled'] ),
	'Remote block preview fallback was not disabled.'
);
corsivo_layouts_assert(
	home_url( '/missing-preview.jpg' ) === $templates_by_id['remote-block-with-image']['image'],
	'Same-origin preview image was removed.'
);
corsivo_layouts_assert(
	! empty( $templates_by_id['custom-remote-block']['preview_disabled'] ),
	'Custom remote block preview was not disabled.'
);
corsivo_layouts_assert( 3 === count( $rejections ), 'Invalid templates were not diagnosed.' );

$invalid_resource_attributes_callback = static fn( $resource_attributes ) => null;
add_filter( 'corsivo_layouts_preview_resource_attributes', $invalid_resource_attributes_callback, 20 );

corsivo_layouts_assert(
	\Corsivo\Layouts\block_templates_have_remote_resources(
		array( array( 'core/image', array( 'url' => 'https://tracker.example/fallback.jpg' ), array() ) ),
		'fallback',
		'post'
	),
	'Invalid resource attribute map disabled core protection.'
);

remove_filter( 'corsivo_layouts_preview_resource_attributes', $invalid_resource_attributes_callback, 20 );

$corrupted_resource_attributes_callback = static function ( $resource_attributes ) {
	$resource_attributes['core/image'] = 'url';

	return $resource_attributes;
};
add_filter( 'corsivo_layouts_preview_resource_attributes', $corrupted_resource_attributes_callback, 20 );

corsivo_layouts_assert(
	\Corsivo\Layouts\block_templates_have_remote_resources(
		array( array( 'core/image', array( 'url' => 'https://tracker.example/corrupted.jpg' ), array() ) ),
		'corrupted',
		'post'
	),
	'Corrupted core resource attributes disabled core protection.'
);

remove_filter( 'corsivo_layouts_preview_resource_attributes', $corrupted_resource_attributes_callback, 20 );
corsivo_layouts_assert(
	'' === \Corsivo\Layouts\normalize_preview_image(
		'https:///tracker.example/image.jpg',
		'invalid',
		'post'
	),
	'Triple-slash preview bypassed remote policy.'
);
corsivo_layouts_assert(
	'' === \Corsivo\Layouts\normalize_preview_image(
		'https:/tracker.example/image.jpg',
		'invalid',
		'post'
	),
	'Single-slash preview bypassed remote policy.'
);

$allowed_blocks_callback = static fn() => array( 'core/paragraph' );
add_filter( 'allowed_block_types_all', $allowed_blocks_callback );

$restricted_configuration = \Corsivo\Layouts\editor_configuration( 'post', $fixture_post );

corsivo_layouts_assert( 2 === count( $restricted_configuration['templates'] ), 'Disallowed blocks were not removed.' );

remove_filter( 'allowed_block_types_all', $allowed_blocks_callback );
remove_filter( 'corsivo_layouts_templates', $canonical_callback, 10 );
remove_filter( 'corsivo_layouts_preview_resource_attributes', $resource_attributes_callback );

$maximum_depth_blocks = array();

for ( $depth = \Corsivo\Layouts\MAX_BLOCK_DEPTH; $depth >= 0; --$depth ) {
	$maximum_depth_blocks = array( array( 'core/group', array(), $maximum_depth_blocks ) );
}

$block_count = 0;
$normalized_maximum_depth = \Corsivo\Layouts\normalize_block_templates(
	$maximum_depth_blocks,
	$block_count
);

corsivo_layouts_assert( null !== $normalized_maximum_depth, 'Maximum block depth was rejected.' );

$rejections = array();
$too_deep_blocks = array( array( 'core/group', array(), $maximum_depth_blocks ) );

\Corsivo\Layouts\normalize_template(
	array(
		'name'   => 'Invalid structure',
		'blocks' => 'invalid',
	),
	'diagnostics',
	0,
	'post',
	array(),
	true
);

corsivo_layouts_assert(
	'invalid_blocks' === $rejections[ array_key_last( $rejections ) ]['reason'] &&
	'invalid_block_structure' === $rejections[ array_key_last( $rejections ) ]['detail'],
	'Invalid block structure was not diagnosed.'
);

\Corsivo\Layouts\normalize_template(
	array(
		'name'   => 'Too deep',
		'blocks' => $too_deep_blocks,
	),
	'diagnostics',
	1,
	'post',
	array(),
	true
);

corsivo_layouts_assert(
	'invalid_blocks' === $rejections[ array_key_last( $rejections ) ]['reason'] &&
	'max_block_depth_exceeded' === $rejections[ array_key_last( $rejections ) ]['detail'],
	'Excessive block depth was not diagnosed.'
);

\Corsivo\Layouts\normalize_template(
	array(
		'name'   => 'Too many blocks',
		'blocks' => array_fill(
			0,
			\Corsivo\Layouts\MAX_BLOCKS_PER_TEMPLATE + 1,
			array( 'core/paragraph', array(), array() )
		),
	),
	'diagnostics',
	2,
	'post',
	array(),
	true
);

corsivo_layouts_assert(
	'invalid_blocks' === $rejections[ array_key_last( $rejections ) ]['reason'] &&
	'max_blocks_exceeded' === $rejections[ array_key_last( $rejections ) ]['detail'],
	'Excessive block count was not diagnosed.'
);

\Corsivo\Layouts\normalize_template(
	array(
		'name'   => 'Invalid attributes',
		'blocks' => array( array( 'core/paragraph', array( 'value' => NAN ), array() ) ),
	),
	'diagnostics',
	3,
	'post',
	array(),
	true
);

corsivo_layouts_assert(
	'invalid_blocks' === $rejections[ array_key_last( $rejections ) ]['reason'] &&
	'invalid_block_attributes' === $rejections[ array_key_last( $rejections ) ]['detail'],
	'Invalid block attributes were not diagnosed.'
);

$asset_issues = array();
$asset_issue_callback = static function ( $diagnostic ) use ( &$asset_issues ) {
	$asset_issues[] = $diagnostic;
};

add_action( 'corsivo_layouts_editor_asset_issue', $asset_issue_callback );

$missing_asset_file = wp_tempnam( 'corsivo-layouts-missing-asset.php' );
corsivo_layouts_assert( is_string( $missing_asset_file ), 'Missing asset fixture was not created.' );
wp_delete_file( $missing_asset_file );
\Corsivo\Layouts\load_editor_asset_metadata( $missing_asset_file );

$invalid_asset_file = wp_tempnam( 'corsivo-layouts-invalid-asset.php' );
corsivo_layouts_assert( is_string( $invalid_asset_file ), 'Invalid asset fixture was not created.' );
corsivo_layouts_assert(
	false !== file_put_contents(
		$invalid_asset_file,
		"<?php return array( 'dependencies' => 'invalid', 'version' => 'test' );"
	),
	'Invalid asset fixture was not written.'
);
\Corsivo\Layouts\load_editor_asset_metadata( $invalid_asset_file );
wp_delete_file( $invalid_asset_file );

$syntax_error_asset_file = wp_tempnam( 'corsivo-layouts-syntax-error-asset.php' );
corsivo_layouts_assert( is_string( $syntax_error_asset_file ), 'Syntax error asset fixture was not created.' );
corsivo_layouts_assert(
	false !== file_put_contents( $syntax_error_asset_file, '<?php return array(' ),
	'Syntax error asset fixture was not written.'
);
\Corsivo\Layouts\load_editor_asset_metadata( $syntax_error_asset_file );
wp_delete_file( $syntax_error_asset_file );

corsivo_layouts_assert(
	array( 'missing_asset_metadata', 'invalid_asset_metadata', 'invalid_asset_metadata' ) === array_column( $asset_issues, 'reason' ),
	'Editor asset issues were not diagnosed.'
);
corsivo_layouts_assert(
	is_array( \Corsivo\Layouts\editor_asset_metadata() ),
	'Valid editor asset metadata was rejected.'
);

$previous_screen = get_current_screen();
$editor_capabilities_callback = static function ( $all_capabilities, $required_capabilities ) {
	foreach ( $required_capabilities as $required_capability ) {
		$all_capabilities[ $required_capability ] = true;
	}

	return $all_capabilities;
};
$editor_templates_callback = static function ( $collections ) {
	$collections['post'] = array(
		'templates' => array(
			array(
				'id'     => 'asset-smoke',
				'name'   => 'Asset smoke',
				'blocks' => array(),
			),
		),
	);

	return $collections;
};

set_current_screen( 'post' );
add_filter( 'user_has_cap', $editor_capabilities_callback, 10, 2 );
add_filter( 'corsivo_layouts_templates', $editor_templates_callback );

\Corsivo\Layouts\enqueue_editor_assets();

$registered_editor_style = wp_styles()->registered[ \Corsivo\Layouts\SCRIPT_HANDLE ] ?? null;

corsivo_layouts_assert(
	wp_script_is( \Corsivo\Layouts\SCRIPT_HANDLE, 'enqueued' ),
	'Editor script was not enqueued.'
);
corsivo_layouts_assert(
	wp_style_is( \Corsivo\Layouts\SCRIPT_HANDLE, 'enqueued' ),
	'Editor style was not enqueued.'
);
corsivo_layouts_assert(
	is_object( $registered_editor_style ) &&
	'replace' === ( $registered_editor_style->extra['rtl'] ?? null ),
	'Editor style did not register the RTL replacement.'
);

remove_filter( 'user_has_cap', $editor_capabilities_callback, 10 );
remove_filter( 'corsivo_layouts_templates', $editor_templates_callback );
wp_dequeue_script( \Corsivo\Layouts\SCRIPT_HANDLE );
wp_deregister_script( \Corsivo\Layouts\SCRIPT_HANDLE );
wp_dequeue_style( \Corsivo\Layouts\SCRIPT_HANDLE );
wp_deregister_style( \Corsivo\Layouts\SCRIPT_HANDLE );
set_current_screen( $previous_screen instanceof WP_Screen ? $previous_screen->id : '' );

remove_action( 'corsivo_layouts_editor_asset_issue', $asset_issue_callback );

$rejections = array();

$isolated_callback = static function ( $collections ) {
	$collections['post'] = array(
		'inherit_all' => false,
		'templates'   => array(
			array(
				'id'            => 'isolated',
				'name'          => 'Isolated',
				'page_template' => '',
				'blocks'        => array( array( 'core/paragraph', array(), array() ) ),
			),
		),
	);

	return $collections;
};
$shared_callback = static function ( $collections ) {
	$collections['all'] = array(
		'open_on_new_post' => true,
		'templates'        => array(
			array(
				'name'   => 'Invalid shared',
				'blocks' => 'invalid',
			),
		),
	);

	return $collections;
};

add_filter( 'corsivo_layouts_templates', $isolated_callback );
add_filter( 'corsivo_layouts_templates', $shared_callback, 20 );

$isolated_configuration = \Corsivo\Layouts\editor_configuration( 'post', $fixture_post );

corsivo_layouts_assert( false === $isolated_configuration['openOnNewPost'], 'Shared auto-open ignored inherit_all.' );
corsivo_layouts_assert(
	1 === count( $isolated_configuration['templates'] ),
	'inherit_all did not exclude shared layouts.'
);
corsivo_layouts_assert(
	'' === $isolated_configuration['templates'][0]['page_template'],
	'Default page template was lost.'
);
corsivo_layouts_assert( array() === $rejections, 'Excluded shared layouts were normalized.' );

remove_filter( 'corsivo_layouts_templates', $isolated_callback );
remove_filter( 'corsivo_layouts_templates', $shared_callback, 20 );

$inherited_open_callback = static function ( $collections ) {
	$collections['post'] = array(
		'templates' => array(
			array(
				'id'     => 'inherited-open',
				'name'   => 'Inherited open',
				'blocks' => array(),
			),
		),
	);
	$collections['all']  = array(
		'open_on_new_post' => true,
		'templates'        => array(),
	);

	return $collections;
};

add_filter( 'corsivo_layouts_templates', $inherited_open_callback );

$inherited_open_configuration = \Corsivo\Layouts\editor_configuration( 'post', $fixture_post );

corsivo_layouts_assert(
	true === $inherited_open_configuration['openOnNewPost'],
	'Shared auto-open fallback was not inherited.'
);

remove_filter( 'corsivo_layouts_templates', $inherited_open_callback );

$fixture_deleted = (bool) wp_delete_post( $fixture_post_id, true );

echo "Corsivo Layouts runtime smoke test passed.\n";
