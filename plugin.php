<?php
/*
Plugin Name: Oxygen Repeater Fix (Duplicated IDs)
Author: Fadlul Alim
Author URI: https://motekar.com
Version: 1.3.1
Description: This plugin fix duplicated IDs generated by Oxygen Repeater element.
*/

class Oxygen_Repeater_Fix
{
	private $cache_path = '/cache/oxygen-fix';

	function __construct() {
		if ( ! file_exists( WP_CONTENT_DIR . $this->cache_path ) ) {
			wp_mkdir_p( WP_CONTENT_DIR . $this->cache_path );
		}

		add_action( 'wp_head', [$this, 'start_buffer'], 1 );
		add_action( 'wp_footer', [$this, 'start_buffer'], 1 );

		add_action( 'plugins_loaded', [$this, 'watch_uri'] );

		add_filter( 'do_shortcode_tag', [$this, 'rewrite_html'], 10, 2 );

		add_action( 'save_post', [$this, 'reset_cache'] );
		add_action( 'wp_ajax_oxygen_vsb_cache_generated', [$this, 'reset_cache'], 9 );
	}

	function start_buffer() {
		ob_start( [$this, 'rewrite_css_uri'] );
	}

	function rewrite_css_uri( $buffer ) {
		$buffer = preg_replace_callback(
			'#\/uploads\/oxygen\/css\/(.*?\.css)#',
			function( $matches ) {
				$css_path = $matches[0];
				$css_path_new = $this->cache_path . '/' . $matches[1];

				if ( ! file_exists( WP_CONTENT_DIR . $css_path_new ) ) {
					file_put_contents(
						WP_CONTENT_DIR . $css_path_new,
						$this->fix_css( file_get_contents( WP_CONTENT_DIR . $css_path ) )
					);
				}

				return $css_path_new;
			},
			$buffer
		);

		return $buffer;
	}

	function watch_uri() {
		if (
			isset( $_REQUEST['xlink'] ) &&
			stripslashes( $_REQUEST['xlink'] ) == 'css'
		) {
			ob_start( [$this, 'fix_css'] );
		}
	}

	function fix_css( $buffer ) {
		$repeater_ids = get_option( 'oxy_repeater_ids' );
		foreach( $repeater_ids as $id => $parent ) {
			$buffer = str_replace( '#' . $id, "#{$parent} .{$id}", $buffer );
		}

		return $buffer;
	}

	function rewrite_html( $output, $tag ) {
		if ( ! preg_match( '/oxy_dynamic_list/', $tag ) ) return $output;

		$output = preg_replace_callback( '/id="(.*?)".*?>/m', function( $matches ) {

			if ( preg_match( '/_dynamic/', $matches[1] ) ) {
				Oxygen_Repeater_Fix_IDs::set_parent( $matches[1] );
				return $matches[0];
			}

			Oxygen_Repeater_Fix_IDs::save_id( $matches[1] );

			$out = str_replace( 'id="' . $matches[1] . '"', '', $matches[0] );
			$out = str_replace( 'class="', 'class="' . $matches[1] . ' ', $out );

			return $out;
		}, $output );

		$existing_ids = get_option( 'oxy_repeater_ids' );
		$new_ids = array_merge( $existing_ids, Oxygen_Repeater_Fix_IDs::get_ids() );
		if ( $existing_ids != $new_ids ) {
			update_option( 'oxy_repeater_ids', $new_ids );
		}

		return $output;
	}

	function reset_cache() {
		update_option( 'oxy_repeater_ids', [] );

		foreach( glob( WP_CONTENT_DIR . $this->cache_path . '/*' ) as $cache_file ) {
			unlink( $cache_file );
		}
	}
}

new Oxygen_Repeater_Fix;

class Oxygen_Repeater_Fix_IDs {
	private static $parent = '';
	private static $ids = [];

	public static function set_parent( $parent ) {
		self::$parent = $parent;
	}

	public static function save_id( $id ) {
		self::$ids[$id] = self::$parent;
	}

	public static function get_ids() {
		return self::$ids;
	}
}
