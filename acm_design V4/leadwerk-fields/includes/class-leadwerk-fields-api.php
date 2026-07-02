<?php
/**
 * Leadwerk Fields API – drop-in replacement for the ACF functions used by
 * leadwerk_theme and leadwerk_importer.
 *
 * Data storage:
 *  - Post / page fields   → update_post_meta / get_post_meta  (key = field name)
 *  - 'option' fields      → update_option / get_option         (key = 'leadwerk_opt_' . field name)
 *  - Flexible Content     → stored as JSON array in a single meta key
 *  - Repeater / Group     → stored as JSON array in a single meta key
 *  - Image / Video / File  → stored as attachment ID (int); File may keep a legacy URL string from imports
 *  - Gallery              → stored as JSON array of attachment IDs
 *
 * @package Leadwerk_Fields
 */

class Leadwerk_Fields_API {

	/** @var array Runtime options cache. */
	private static $options_cache = array();

	/** @var bool Whether we already initialised. */
	private static $initialised = false;

	/** @var array|null Current repeater/flex row for have_rows() / the_row(). */
	private static $row_stack = array();

	/**
	 * Register global helper functions only when ACF is NOT active.
	 */
	public static function init() {
		if ( self::$initialised ) {
			return;
		}
		self::$initialised = true;

		// If ACF (free or Pro) is already active, do nothing – it provides its own API.
		if ( function_exists( 'get_field' ) ) {
			return;
		}

		// Register our lightweight equivalents.
		require_once __DIR__ . '/leadwerk-fields-functions.php';
	}

	/* ------------------------------------------------------------------
	 * Core read / write
	 * ----------------------------------------------------------------*/

	/**
	 * Get a field value.
	 *
	 * @param string     $name    Field name.
	 * @param int|string $post_id Post ID or 'option'.
	 * @return mixed
	 */
	public static function get_field( $name, $post_id = null ) {
		if ( $post_id === 'option' || $post_id === 'options' ) {
			return self::get_option_field( $name );
		}
		if ( $post_id === null ) {
			$post_id = get_the_ID();
		}
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return null;
		}
		$raw = get_post_meta( $post_id, $name, true );
		return self::maybe_json_decode( $raw );
	}

	/**
	 * Update a field value.
	 *
	 * @param string     $name    Field name.
	 * @param mixed      $value   Value to store.
	 * @param int|string $post_id Post ID or 'option'.
	 */
	public static function update_field( $name, $value, $post_id = null ) {
		if ( $post_id === 'option' || $post_id === 'options' ) {
			self::update_option_field( $name, $value );
			return;
		}
		if ( $post_id === null ) {
			$post_id = get_the_ID();
		}
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		$stored = self::maybe_json_encode( $value );
		$stored = is_string( $stored ) ? wp_slash( $stored ) : $stored;
		update_post_meta( $post_id, $name, $stored );
	}

	/* ------------------------------------------------------------------
	 * Options helpers
	 * ----------------------------------------------------------------*/

	private static function option_key( $name ) {
		return 'leadwerk_opt_' . $name;
	}

	private static function get_option_field( $name ) {
		$key = self::option_key( $name );
		if ( isset( self::$options_cache[ $key ] ) ) {
			return self::$options_cache[ $key ];
		}
		$raw = get_option( $key, null );
		$val = self::maybe_json_decode( $raw );
		self::$options_cache[ $key ] = $val;
		return $val;
	}

	private static function update_option_field( $name, $value ) {
		$key = self::option_key( $name );
		$stored = self::maybe_json_encode( $value );
		$stored = is_string( $stored ) ? wp_slash( $stored ) : $stored;
		update_option( $key, $stored, false );
		self::$options_cache[ $key ] = $value;
	}

	/* ------------------------------------------------------------------
	 * JSON encode / decode for complex data
	 * ----------------------------------------------------------------*/

	private static function maybe_json_encode( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return $value;
	}

	private static function maybe_json_decode( $raw ) {
		if ( ! is_string( $raw ) ) {
			return $raw;
		}
		if ( $raw === '' ) {
			return '';
		}
		// Fast check: starts with { or [
		$first = $raw[0] ?? '';
		if ( $first === '{' || $first === '[' ) {
			$decoded = json_decode( $raw, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $decoded;
			}
		}
		// Return integers where appropriate (attachment IDs etc.)
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		return $raw;
	}

	/* ------------------------------------------------------------------
	 * have_rows / the_row / get_sub_field (minimal Repeater / Flex support)
	 * ----------------------------------------------------------------*/

	/**
	 * Iterate over a repeater / flexible content field.
	 *
	 * Usage:
	 *   while ( have_rows('home_sections', $id) ) { the_row(); $layout = get_row_layout(); }
	 *
	 * @param string     $name    Field name.
	 * @param int|string $post_id Post ID or 'option'.
	 * @return bool
	 */
	public static function have_rows( $name, $post_id = null ) {
		$top = end( self::$row_stack );
		// If we already started iterating this field, advance pointer.
		if ( $top && $top['field'] === $name && $top['post_id'] === $post_id ) {
			$top['pointer']++;
			if ( $top['pointer'] < count( $top['rows'] ) ) {
				self::$row_stack[ key( self::$row_stack ) ] = $top;
				return true;
			}
			// Exhausted – pop stack.
			array_pop( self::$row_stack );
			return false;
		}
		// First call – fetch data and push.
		$rows = self::get_field( $name, $post_id );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return false;
		}
		// Ensure sequential array.
		$rows = array_values( $rows );
		self::$row_stack[] = array(
			'field'   => $name,
			'post_id' => $post_id,
			'rows'    => $rows,
			'pointer' => 0,
		);
		return true;
	}

	public static function the_row() {
		// No-op; pointer advanced in have_rows.
	}

	public static function get_row_layout() {
		$top = end( self::$row_stack );
		if ( ! $top ) {
			return '';
		}
		$row = $top['rows'][ $top['pointer'] ] ?? array();
		return isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : '';
	}

	public static function get_sub_field( $name ) {
		$top = end( self::$row_stack );
		if ( ! $top ) {
			return null;
		}
		$row = $top['rows'][ $top['pointer'] ] ?? array();
		return isset( $row[ $name ] ) ? $row[ $name ] : null;
	}

	/* ------------------------------------------------------------------
	 * ACF stub functions
	 * ----------------------------------------------------------------*/

	/**
	 * Stub: acf_add_options_page – we don't need a UI, but the theme calls it.
	 */
	public static function acf_add_options_page( $args = array() ) {
		// No-op. Options are stored via update_field('x', $val, 'option').
	}

	/**
	 * Stub: acf_register_block_type – thin wrapper around register_block_type.
	 */
	public static function acf_register_block_type( $args = array() ) {
		$name = isset( $args['name'] ) ? 'acf/' . $args['name'] : '';
		if ( ! $name ) {
			return;
		}
		$block_args = array();
		if ( isset( $args['render_callback'] ) ) {
			$block_args['render_callback'] = $args['render_callback'];
		}
		if ( isset( $args['title'] ) ) {
			$block_args['title'] = $args['title'];
		}
		if ( isset( $args['description'] ) ) {
			$block_args['description'] = $args['description'];
		}
		register_block_type( $name, $block_args );
	}
}
