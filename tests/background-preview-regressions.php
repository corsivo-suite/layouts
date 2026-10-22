<?php

if ( 'cli' !== PHP_SAPI || defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'Run this test in an isolated PHP CLI process.' );
}

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$wordpress_directory = getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__, 4 );
$plugin_file         = getenv( 'CORSIVO_LAYOUTS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/corsivo-layouts.php';

define( 'ABSPATH', rtrim( $wordpress_directory, '/\\' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_URL', 'https://assets.layouts.test/wp-content' );

$_SERVER['HTTPS'] = 'on';

foreach ( array( 'compat-utf8.php', 'utf8.php' ) as $core_file ) {
	if ( is_readable( ABSPATH . WPINC . '/' . $core_file ) ) {
		require_once ABSPATH . WPINC . '/' . $core_file;
	}
}

foreach ( array( 'compat.php', 'plugin.php', 'load.php', 'functions.php', 'formatting.php', 'kses.php', 'http.php', 'link-template.php' ) as $core_file ) {
	require_once ABSPATH . WPINC . '/' . $core_file;
}

add_filter(
	'pre_option',
	static function ( $previous, $option ) {
		if ( false !== $previous ) {
			return $previous;
		}

		$options = array(
			'home'         => 'https://layouts.test',
			'siteurl'      => 'https://admin.layouts.test/wp',
			'blog_charset' => 'UTF-8',
		);

		if ( ! array_key_exists( $option, $options ) ) {
			throw new RuntimeException( 'Unexpected option access: ' . $option );
		}

		return $options[ $option ];
	},
	10,
	2
);

require $plugin_file;

function corsivo_layouts_background_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function corsivo_layouts_background_template( $blocks, $template_id = 'background-regression' ) {
	return \Corsivo\Layouts\normalize_template(
		array(
			'id'     => $template_id,
			'name'   => 'Background regression',
			'blocks' => $blocks,
		),
		'post',
		0,
		'post',
		array(),
		true
	);
}

function corsivo_layouts_background_blocks( $background_image, $block_name = 'core/group' ) {
	return array(
		array(
			$block_name,
			array(
				'style' => array(
					'background' => array( 'backgroundImage' => $background_image ),
				),
			),
			array(),
		),
	);
}

$remote_url = 'https://tracker.example/background.jpg';
$blocked_backgrounds = array(
	'group URL object'       => array( 'url' => $remote_url, 'source' => 'file', 'id' => 42 ),
	'plain URL'              => $remote_url,
	'protocol-relative URL'  => array( 'url' => '//tracker.example/background.jpg' ),
	'CSS URL'                => 'url("' . $remote_url . '")',
	'multiple CSS images'    => 'url("/local.jpg"), url("' . $remote_url . '")',
	'non-default port'       => array( 'url' => 'https://layouts.test:8443/background.jpg' ),
	'different scheme'       => array( 'url' => 'http://layouts.test/background.jpg' ),
	'malformed scheme'       => array( 'url' => 'https:tracker.example/background.jpg' ),
	'malformed single slash' => array( 'url' => 'https:/tracker.example/background.jpg' ),
	'malformed triple slash' => array( 'url' => 'https:///tracker.example/background.jpg' ),
	'backslash authority'    => array( 'url' => 'https://tracker.example\\@layouts.test/background.jpg' ),
	'backslash relative'     => array( 'url' => '\\\\tracker.example/background.jpg' ),
	'CSS escaped URL'        => 'url("\\2f \\2f tracker.example/background.jpg")',
	'HTML entity URL'        => array( 'url' => '&#47;&#47;tracker.example/background.jpg' ),
	'HTML entity backslash'  => array( 'url' => '&#92;&#92;tracker.example/background.jpg' ),
	'JSON object URL'        => (object) array( 'url' => $remote_url ),
);

foreach ( $blocked_backgrounds as $case => $background_image ) {
	$blocks   = corsivo_layouts_background_blocks( $background_image );
	$template = corsivo_layouts_background_template( $blocks );

	corsivo_layouts_background_assert( true === ( $template['preview_disabled'] ?? false ), $case . ': remote background preview stayed enabled.' );
	corsivo_layouts_background_assert( $blocks === $template['blocks'], $case . ': original block attributes changed.' );
}

$allowed_backgrounds = array(
	'home origin'               => array( 'url' => home_url( '/background.jpg' ) ),
	'site origin'               => array( 'url' => site_url( '/background.jpg' ) ),
	'content origin'            => array( 'url' => content_url( '/background.jpg' ) ),
	'explicit default port'     => array( 'url' => 'https://layouts.test:443/background.jpg' ),
	'protocol-relative local'   => array( 'url' => '//layouts.test/background.jpg' ),
	'root-relative URL'         => array( 'url' => '/background.jpg' ),
	'relative URL'              => array( 'url' => 'images/background.jpg' ),
	'local CSS URL'             => 'url("https://layouts.test/background.jpg")',
	'local HTML entity URL'     => array( 'url' => 'https:&#47;&#47;layouts.test/background.jpg' ),
	'relative CSS URL'          => 'url("/background.jpg")',
	'empty URL'                 => array( 'url' => '' ),
	'URL absent'                => array( 'id' => 42 ),
	'non-resource URL metadata' => array( 'url' => '/background.jpg', 'alt' => $remote_url, 'source' => $remote_url ),
);

foreach ( $allowed_backgrounds as $case => $background_image ) {
	$blocks   = corsivo_layouts_background_blocks( $background_image );
	$template = corsivo_layouts_background_template( $blocks );

	corsivo_layouts_background_assert( ! isset( $template['preview_disabled'] ), $case . ': local background preview was disabled.' );
	corsivo_layouts_background_assert( $blocks === $template['blocks'], $case . ': original block attributes changed.' );
}

foreach ( array( 'core/columns', 'example/hero' ) as $block_name ) {
	$template = corsivo_layouts_background_template( corsivo_layouts_background_blocks( array( 'url' => $remote_url ), $block_name ) );
	corsivo_layouts_background_assert( true === ( $template['preview_disabled'] ?? false ), $block_name . ': shared background support was ignored.' );
}

$nested_blocks = array(
	array( 'core/group', array(), array( array( 'core/columns', array(), corsivo_layouts_background_blocks( array( 'url' => $remote_url ) ) ) ) ),
);
$nested_template = corsivo_layouts_background_template( $nested_blocks );

corsivo_layouts_background_assert( true === ( $nested_template['preview_disabled'] ?? false ), 'Nested remote background preview stayed enabled.' );
corsivo_layouts_background_assert( $nested_blocks === $nested_template['blocks'], 'Nested block structure changed.' );

$object_attributes = array(
	array(
		'core/group',
		array( 'style' => (object) array( 'background' => (object) array( 'backgroundImage' => (object) array( 'url' => $remote_url ) ) ) ),
		array(),
	),
);
$object_template = corsivo_layouts_background_template( $object_attributes );

corsivo_layouts_background_assert( true === ( $object_template['preview_disabled'] ?? false ), 'JSON object style attributes bypassed background protection.' );
corsivo_layouts_background_assert( $object_attributes === $object_template['blocks'], 'JSON object style attributes changed.' );

$empty_attributes = array(
	array(),
	array( 'style' => null ),
	array( 'style' => false ),
	array( 'style' => 42 ),
	array( 'style' => 'background' ),
	array( 'style' => array() ),
	array( 'style' => array( 'background' => null ) ),
	array( 'style' => array( 'background' => true ) ),
	array( 'style' => array( 'background' => 42 ) ),
	array( 'style' => array( 'background' => 'image' ) ),
	array( 'style' => array( 'background' => array( 'backgroundImage' => false ) ) ),
	array( 'style' => array( 'background' => array( 'backgroundImage' => 42 ) ) ),
	array( 'style' => array( 'color' => array( 'text' => $remote_url ) ) ),
	array( 'content' => '<a href="' . $remote_url . '">External link</a>' ),
	array( 'metadata' => array( 'source' => $remote_url ) ),
);

foreach ( $empty_attributes as $index => $attributes ) {
	$template = corsivo_layouts_background_template( array( array( 'core/paragraph', $attributes, array() ) ) );
	corsivo_layouts_background_assert( ! isset( $template['preview_disabled'] ), 'Non-resource attributes disabled preview at case ' . $index . '.' );
}

$invalid_map_callback = static fn() => null;
add_filter( 'corsivo_layouts_preview_resource_attributes', $invalid_map_callback );

$invalid_map_template = corsivo_layouts_background_template( corsivo_layouts_background_blocks( array( 'url' => $remote_url ) ) );
corsivo_layouts_background_assert( true === ( $invalid_map_template['preview_disabled'] ?? false ), 'Invalid resource map disabled background protection.' );

remove_filter( 'corsivo_layouts_preview_resource_attributes', $invalid_map_callback );

$corrupted_map_callback = static function ( $resource_attributes ) {
	$resource_attributes['core/group'] = 'style';
	return $resource_attributes;
};
add_filter( 'corsivo_layouts_preview_resource_attributes', $corrupted_map_callback );

$corrupted_map_template = corsivo_layouts_background_template( corsivo_layouts_background_blocks( array( 'url' => $remote_url ) ) );
corsivo_layouts_background_assert( true === ( $corrupted_map_template['preview_disabled'] ?? false ), 'Corrupted block resource map disabled background protection.' );

remove_filter( 'corsivo_layouts_preview_resource_attributes', $corrupted_map_callback );

$opt_in_calls = array();
$opt_in_callback = static function ( $allowed, $url, $template_id, $post_type ) use ( &$opt_in_calls, $remote_url ) {
	$opt_in_calls[] = array( $url, $template_id, $post_type );
	return $remote_url === $url && 'opt-in-background' === $template_id && 'post' === $post_type;
};
add_filter( 'corsivo_layouts_allow_remote_preview', $opt_in_callback, 10, 4 );

$opt_in_blocks = corsivo_layouts_background_blocks( array( 'url' => $remote_url ) );
$opt_in_template = corsivo_layouts_background_template( $opt_in_blocks, 'opt-in-background' );

corsivo_layouts_background_assert( ! isset( $opt_in_template['preview_disabled'] ), 'Explicit remote preview opt-in was ignored.' );
corsivo_layouts_background_assert( $opt_in_blocks === $opt_in_template['blocks'], 'Opt-in changed original blocks.' );
corsivo_layouts_background_assert( array( array( $remote_url, 'opt-in-background', 'post' ) ) === $opt_in_calls, 'Remote preview opt-in received unexpected URL or context.' );

remove_filter( 'corsivo_layouts_allow_remote_preview', $opt_in_callback );

foreach ( array( 'https://tracker.example\\@layouts.test/preview.jpg', '\\\\tracker.example/preview.jpg' ) as $image ) {
	corsivo_layouts_background_assert(
		'' === \Corsivo\Layouts\normalize_preview_image( $image, 'ambiguous-image', 'post' ),
		'Ambiguous image URL passed the same-origin check.'
	);
}

$html_blocks = array(
	array( 'core/html', array( 'content' => '<img src="&#47;&#47;tracker.example/image.jpg">' ), array() ),
);
$html_template = corsivo_layouts_background_template( $html_blocks );

corsivo_layouts_background_assert( true === ( $html_template['preview_disabled'] ?? false ), 'HTML entities bypassed remote resource protection.' );
corsivo_layouts_background_assert( $html_blocks === $html_template['blocks'], 'Resource checks changed the original HTML.' );

restore_error_handler();

echo "Corsivo Layouts background preview regressions passed.\n";
