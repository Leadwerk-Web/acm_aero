<?php
/**
 * Front-end routing, locale management, SEO/hreflang, and URL filters
 * for the Leadwerk multilingual layer.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_Translation_Router {

	/**
	 * Locale map for supported languages.
	 *
	 * @var array<string,string>
	 */
	protected static $locale_map = array(
		'de' => 'de_DE',
		'en' => 'en_US',
		'fr' => 'fr_FR',
		'es' => 'es_ES',
		'it' => 'it_IT',
		'tr' => 'tr_TR',
		'nl' => 'nl_NL',
		'pt' => 'pt_PT',
		'pl' => 'pl_PL',
		'ru' => 'ru_RU',
	);

	/**
	 * Register router hooks.
	 *
	 * @return void
	 */
	public static function init() {
		/* --- URL routing --- */
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 1 );
		add_filter( 'page_link', array( __CLASS__, 'filter_page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'filter_post_type_link' ), 10, 2 );
		add_filter( 'attachment_link', array( __CLASS__, 'filter_attachment_link' ), 10, 2 );
		add_filter( 'term_link', array( __CLASS__, 'filter_term_link' ), 10, 3 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_internal_slug_redirects' ), 10, 2 );

		/* --- Locale & language attributes --- */
		add_filter( 'locale', array( __CLASS__, 'filter_locale' ) );
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_plugin_locale' ), 10, 2 );
		add_filter( 'language_attributes', array( __CLASS__, 'filter_language_attributes' ), 10, 2 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );

		/* --- SEO: hreflang --- */
		add_action( 'wp_head', array( __CLASS__, 'render_hreflang_tags' ), 1 );

		/* --- Front-end language switcher in admin bar --- */
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_frontend_admin_bar_switcher' ), 91 );

		/* --- Front-end language switcher (footer) --- */
		add_action( 'wp_footer', array( __CLASS__, 'render_frontend_language_switcher' ), 50 );
		add_action( 'wp_head', array( __CLASS__, 'render_switcher_styles' ), 99 );

		/* --- Menu localisation --- */
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_nav_menu_objects' ), 10, 2 );
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'filter_nav_menu_args' ), 10, 1 );
	}

	/* =========================================================================
	 * URL ROUTING
	 * ====================================================================== */

	/**
	 * Resolve /en/... style public URLs to their internal WordPress objects.
	 *
	 * @param WP $wp Main WP object.
	 * @return void
	 */
	public static function parse_request( $wp ) {
		if ( is_admin() || ! $wp instanceof WP ) {
			return;
		}

		$path     = self::get_request_path();
		$resolved = Leadwerk_Translation_API::resolve_public_request( $path );

		if ( empty( $resolved['language_code'] ) ) {
			Leadwerk_Translation_API::set_current_request_language( Leadwerk_Translation_API::get_default_language() );
			return;
		}

		Leadwerk_Translation_API::set_current_request_language( $resolved['language_code'] );
		self::reload_frontend_plugin_textdomains( $resolved['language_code'] );

		if ( empty( $resolved['element_type'] ) || empty( $resolved['element_id'] ) ) {
			return;
		}

		if ( 0 !== strpos( (string) $resolved['element_type'], 'post_' ) ) {
			return;
		}

		$post = get_post( (int) $resolved['element_id'] );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		/* Clear 404 flags set by WP core during rewrite matching */
		$wp->query_vars = array();
		unset( $wp->query_vars['error'] );

		if ( 'page' === $post->post_type ) {
			$wp->query_vars['page_id']   = (int) $post->ID;
			$wp->query_vars['pagename']  = $post->post_name; // Give WP a pagename so it handles it natively
			return;
		}

		if ( 'attachment' === $post->post_type ) {
			$wp->query_vars['attachment_id'] = (int) $post->ID;
			return;
		}

		$wp->query_vars['p']         = (int) $post->ID;
		$wp->query_vars['post_type'] = $post->post_type;
		$wp->query_vars['name']      = $post->post_name;
	}

	/**
	 * Filter page permalinks to their public multilingual URLs.
	 *
	 * @param string  $link    Original URL.
	 * @param integer $post_id Post ID.
	 * @return string
	 */
	public static function filter_page_link( $link, $post_id ) {
		return Leadwerk_Translation_API::get_public_post_url( $post_id, $link );
	}

	/**
	 * Filter generic post type links to their public multilingual URLs.
	 *
	 * @param string  $link Original URL.
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function filter_post_type_link( $link, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $link;
		}

		return Leadwerk_Translation_API::get_public_post_url( $post->ID, $link );
	}

	/**
	 * Filter attachment links to their public multilingual URLs.
	 *
	 * @param string  $link    Original URL.
	 * @param integer $post_id Attachment ID.
	 * @return string
	 */
	public static function filter_attachment_link( $link, $post_id ) {
		return Leadwerk_Translation_API::get_public_post_url( $post_id, $link );
	}

	/**
	 * Filter term links when a translated term exists.
	 *
	 * @param string       $url      Original URL.
	 * @param WP_Term      $term     Term object.
	 * @param string|array $taxonomy Taxonomy slug or array of taxonomy data.
	 * @return string
	 */
	public static function filter_term_link( $url, $term, $taxonomy ) {
		if ( ! $term instanceof WP_Term ) {
			return $url;
		}

		return Leadwerk_Translation_API::get_public_term_url( $term->term_id, $term->taxonomy, $url );
	}

	/**
	 * Prevent WordPress from redirecting public /en/... URLs back to internal slugs.
	 * Only suppress redirect when the resolved record has a published post.
	 *
	 * @param string|false $redirect_url Redirect target.
	 * @param string       $requested    Requested URL.
	 * @return string|false
	 */
	public static function prevent_internal_slug_redirects( $redirect_url, $requested ) {
		if ( is_admin() || empty( $requested ) ) {
			return $redirect_url;
		}

		$path     = trim( (string) wp_parse_url( $requested, PHP_URL_PATH ), '/' );
		$resolved = Leadwerk_Translation_API::resolve_public_request( $path );

		if ( ! empty( $resolved['element_id'] ) && ! empty( $resolved['element_type'] ) ) {
			$post = get_post( (int) $resolved['element_id'] );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				return false;
			}
		}

		/* Language root (e.g. /en/) without a specific element */
		if ( ! empty( $resolved['language_code'] ) && $resolved['language_code'] !== Leadwerk_Translation_API::get_default_language() ) {
			return false;
		}

		return $redirect_url;
	}

	/* =========================================================================
	 * LOCALE & LANGUAGE ATTRIBUTES
	 * ====================================================================== */

	/**
	 * Switch the WordPress locale based on current front-end request language.
	 *
	 * @param string $locale Current locale.
	 * @return string
	 */
	public static function filter_locale( $locale ) {
		if ( is_admin() ) {
			return $locale;
		}

		$lang = Leadwerk_Translation_API::get_current_request_language();

		return self::get_locale_for_language( $lang ) ?: $locale;
	}

	/**
	 * Filter plugin locales for multilingual front-end requests.
	 *
	 * @param string $locale Current locale.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function filter_plugin_locale( $locale, $domain ) {
		if ( is_admin() ) {
			return $locale;
		}

		if ( 'complianz-gdpr' !== (string) $domain ) {
			return $locale;
		}

		$lang = Leadwerk_Translation_API::get_current_request_language();

		return self::get_locale_for_language( $lang ) ?: $locale;
	}

	/**
	 * Reload plugin textdomains after request language has been resolved.
	 *
	 * @param string $lang Request language code.
	 * @return void
	 */
	protected static function reload_frontend_plugin_textdomains( $lang ) {
		static $reloaded = array();

		if ( is_admin() ) {
			return;
		}

		$locale = self::get_locale_for_language( $lang );
		if ( ! $locale || Leadwerk_Translation_API::get_default_language() === $lang ) {
			return;
		}

		$key = 'complianz-gdpr|' . $locale;
		if ( isset( $reloaded[ $key ] ) ) {
			return;
		}

		if ( function_exists( 'switch_to_locale' ) && determine_locale() !== $locale ) {
			switch_to_locale( $locale );
		}

		if ( function_exists( 'unload_textdomain' ) ) {
			unload_textdomain( 'complianz-gdpr' );
		}

		if ( function_exists( 'load_plugin_textdomain' ) ) {
			load_plugin_textdomain( 'complianz-gdpr', false, 'complianz-gdpr/languages' );
		}

		$reloaded[ $key ] = true;
	}

	/**
	 * Filter the <html> lang attribute for front-end requests.
	 *
	 * @param string $output Existing output.
	 * @param string $doctype Document type.
	 * @return string
	 */
	public static function filter_language_attributes( $output, $doctype = 'html' ) {
		if ( is_admin() ) {
			return $output;
		}

		$lang   = Leadwerk_Translation_API::get_current_request_language();
		$locale = self::get_locale_for_language( $lang );

		if ( ! $locale ) {
			return $output;
		}

		$bcp47 = str_replace( '_', '-', $locale );

		if ( 'xhtml' === $doctype ) {
			return 'xml:lang="' . esc_attr( $bcp47 ) . '" lang="' . esc_attr( $bcp47 ) . '"';
		}

		return 'lang="' . esc_attr( $bcp47 ) . '"';
	}

	/**
	 * Add language-specific CSS classes to the body tag.
	 *
	 * @param string[] $classes Existing body classes.
	 * @return string[]
	 */
	public static function filter_body_class( $classes ) {
		if ( is_admin() ) {
			return $classes;
		}

		$lang      = Leadwerk_Translation_API::get_current_request_language();
		$classes[] = 'lang-' . sanitize_html_class( $lang );

		if ( $lang !== Leadwerk_Translation_API::get_default_language() ) {
			$classes[] = 'lang-translated';
		}

		return $classes;
	}

	/* =========================================================================
	 * SEO: HREFLANG TAGS
	 * ====================================================================== */

	/**
	 * Render <link rel="alternate" hreflang="..."> tags in <head>.
	 *
	 * @return void
	 */
	public static function render_hreflang_tags() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$source_id    = Leadwerk_Translation_API::get_source_post_id( $post->ID );
		$source_id    = $source_id ?: $post->ID;
		$translations = Leadwerk_Translation_API::get_translations( $source_id );

		if ( empty( $translations ) ) {
			return;
		}

		$default_lang = Leadwerk_Translation_API::get_default_language();
		$links        = array();

		foreach ( $translations as $lang => $translation_id ) {
			$url = Leadwerk_Translation_API::get_public_post_url( $translation_id );
			if ( ! $url ) {
				continue;
			}

			$translation_post = get_post( $translation_id );
			if ( ! $translation_post instanceof WP_Post || 'publish' !== $translation_post->post_status ) {
				continue;
			}

			$locale = self::get_locale_for_language( $lang );
			$bcp47  = $locale ? str_replace( '_', '-', $locale ) : $lang;

			$links[] = '<link rel="alternate" hreflang="' . esc_attr( $bcp47 ) . '" href="' . esc_url( $url ) . '" />';

			if ( $default_lang === $lang ) {
				$links[] = '<link rel="alternate" hreflang="x-default" href="' . esc_url( $url ) . '" />';
			}
		}

		if ( ! empty( $links ) ) {
			echo "\n" . implode( "\n", $links ) . "\n";
		}
	}

	/* =========================================================================
	 * FRONT-END ADMIN BAR SWITCHER
	 * ====================================================================== */

	/**
	 * Add language switcher to admin bar on front-end pages.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 * @return void
	 */
	public static function register_frontend_admin_bar_switcher( $admin_bar ) {
		if ( is_admin() || ! $admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		$current = Leadwerk_Translation_API::get_current_request_language();
		$title   = '🌐 ' . strtoupper( $current );

		$admin_bar->add_node(
			array(
				'id'    => 'frontend-language',
				'title' => esc_html( $title ),
				'href'  => false,
			)
		);

		$alternate_urls = self::get_current_page_alternate_urls();

		foreach ( Leadwerk_Translation_API::get_active_languages() as $code => $config ) {
			$url = isset( $alternate_urls[ $code ] ) ? $alternate_urls[ $code ] : '';
			if ( ! $url ) {
				$url = $code === Leadwerk_Translation_API::get_default_language()
					? home_url( '/' )
					: home_url( '/' . $code . '/' );
			}

			$admin_bar->add_node(
				array(
					'id'     => 'frontend-language-' . $code,
					'parent' => 'frontend-language',
					'title'  => esc_html( $config['label'] ),
					'href'   => esc_url( $url ),
				)
			);
		}
	}

	/* =========================================================================
	 * FRONT-END LANGUAGE SWITCHER (FLOATING)
	 * ====================================================================== */

	/**
	 * Render a floating language switcher on the front-end.
	 *
	 * @return void
	 */
	public static function render_frontend_language_switcher() {
		if ( is_admin() ) {
			return;
		}

		/**
		 * Filter whether to render the floating language switcher.
		 *
		 * @param bool $render Whether to render. Default true.
		 */
		if ( ! apply_filters( 'leadwerk_render_floating_switcher', true ) ) {
			return;
		}

		$current   = Leadwerk_Translation_API::get_current_request_language();
		$languages = Leadwerk_Translation_API::get_active_languages();
		$alternates = self::get_current_page_alternate_urls();

		echo '<div class="lang-switcher" role="navigation" aria-label="' . esc_attr__( 'Language switcher', 'leadwerk-wpml-clone' ) . '">';
		echo '<div class="lang-switcher__current">' . esc_html( strtoupper( $current ) ) . '</div>';
		echo '<div class="lang-switcher__dropdown">';

		foreach ( $languages as $code => $config ) {
			if ( $code === $current ) {
				continue;
			}

			$url = isset( $alternates[ $code ] ) ? $alternates[ $code ] : '';
			if ( ! $url ) {
				$url = $code === Leadwerk_Translation_API::get_default_language()
					? home_url( '/' )
					: home_url( '/' . $code . '/' );
			}

			$active_class = $code === $current ? ' lang-switcher__link--active' : '';
			echo '<a class="lang-switcher__link' . esc_attr( $active_class ) . '" href="' . esc_url( $url ) . '" hreflang="' . esc_attr( $code ) . '">';
			echo esc_html( $config['label'] );
			echo '</a>';
		}

		echo '</div></div>';
	}

	/**
	 * Render switcher CSS in <head>.
	 *
	 * @return void
	 */
	public static function render_switcher_styles() {
		if ( is_admin() ) {
			return;
		}
		?>
		<style id="lang-switcher-css">
		.lang-switcher{position:fixed;bottom:24px;right:24px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,sans-serif}
		.lang-switcher__current{display:flex;align-items:center;justify-content:center;width:48px;height:48px;background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;border-radius:50%;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.35);transition:transform .2s,box-shadow .2s}
		.lang-switcher__current:hover{transform:scale(1.08);box-shadow:0 6px 20px rgba(37,99,235,.45)}
		.lang-switcher__dropdown{position:absolute;bottom:56px;right:0;min-width:140px;background:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.15);opacity:0;visibility:hidden;transform:translateY(8px);transition:opacity .2s,transform .2s,visibility .2s;overflow:hidden}
		.lang-switcher:hover .lang-switcher__dropdown,.lang-switcher:focus-within .lang-switcher__dropdown{opacity:1;visibility:visible;transform:translateY(0)}
		.lang-switcher__link{display:block;padding:10px 16px;color:#1e293b;text-decoration:none;font-size:14px;font-weight:500;transition:background .15s,color .15s}
		.lang-switcher__link:hover{background:#f0f5ff;color:#2563eb}
		.lang-switcher__link--active{color:#2563eb;font-weight:700}
		@media(max-width:480px){.lang-switcher{bottom:16px;right:16px}.lang-switcher__current{width:40px;height:40px;font-size:11px}}
		</style>
		<?php
	}

	/* =========================================================================
	 * MENU LOCALISATION
	 * ====================================================================== */

	/**
	 * Swap menu to language-specific version if available.
	 *
	 * @param array<string,mixed> $args Menu arguments.
	 * @return array<string,mixed>
	 */
	public static function filter_nav_menu_args( $args ) {
		if ( is_admin() ) {
			return $args;
		}

		$lang = Leadwerk_Translation_API::get_current_request_language();
		if ( $lang === Leadwerk_Translation_API::get_default_language() ) {
			return $args;
		}

		$menu = $args['menu'] ?? '';
		if ( ! $menu ) {
			/* Try theme_location → resolve the term */
			$location = $args['theme_location'] ?? '';
			if ( $location ) {
				$locations = get_nav_menu_locations();
				$menu      = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;
			}
		}

		if ( ! $menu ) {
			return $args;
		}

		$menu_obj = wp_get_nav_menu_object( $menu );
		if ( ! $menu_obj ) {
			return $args;
		}

		/* Find translated menu via registry */
		$source_record = Leadwerk_Translation_API::get_registry_record(
			Leadwerk_Translation_API::get_term_element_type( 'nav_menu' ),
			(int) $menu_obj->term_id
		);

		if ( empty( $source_record['trid'] ) ) {
			return $args;
		}

		global $wpdb;
		$target_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT element_id FROM " . Leadwerk_Translation_API::get_elements_table_name() . " WHERE trid = %s AND element_type = %s AND language_code = %s LIMIT 1",
				(string) $source_record['trid'],
				Leadwerk_Translation_API::get_term_element_type( 'nav_menu' ),
				$lang
			)
		);

		if ( $target_id ) {
			$args['menu'] = $target_id;
		}

		return $args;
	}

	/**
	 * Rewrite menu item URLs to use public multilingual URLs.
	 *
	 * @param array<int,WP_Post> $items Menu items.
	 * @param stdClass           $args  Menu args.
	 * @return array<int,WP_Post>
	 */
	public static function filter_nav_menu_objects( $items, $args ) {
		if ( is_admin() || ! is_array( $items ) ) {
			return $items;
		}

		$lang = Leadwerk_Translation_API::get_current_request_language();

		foreach ( $items as &$item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}

			$object_id = (int) $item->object_id;
			if ( ! $object_id || ! in_array( $item->type, array( 'post_type', 'post_type_archive' ), true ) ) {
				continue;
			}

			$public_url = Leadwerk_Translation_API::get_public_post_url( $object_id );
			if ( $public_url ) {
				$item->url = $public_url;
			}
		}

		return $items;
	}

	/* =========================================================================
	 * HELPERS
	 * ====================================================================== */

	/**
	 * Get the WP locale string for a language code.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_locale_for_language( $lang ) {
		$lang = sanitize_key( (string) $lang );
		return isset( self::$locale_map[ $lang ] ) ? self::$locale_map[ $lang ] : '';
	}

	/**
	 * Build alternate URLs for the current page.
	 *
	 * @return array<string,string>
	 */
	public static function get_current_page_alternate_urls() {
		$alternates = array();

		if ( ! is_singular() ) {
			$default_lang = Leadwerk_Translation_API::get_default_language();
			foreach ( Leadwerk_Translation_API::get_active_languages() as $code => $config ) {
				$alternates[ $code ] = $code === $default_lang
					? home_url( '/' )
					: home_url( '/' . $code . '/' );
			}
			return $alternates;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return $alternates;
		}

		$source_id    = Leadwerk_Translation_API::get_source_post_id( $post->ID );
		$source_id    = $source_id ?: $post->ID;
		$translations = Leadwerk_Translation_API::get_translations( $source_id );

		foreach ( $translations as $lang => $translation_id ) {
			$translation_post = get_post( $translation_id );
			if ( ! $translation_post instanceof WP_Post ) {
				continue;
			}

			$include = is_post_publicly_viewable( $translation_post );
			if ( ! $include && is_user_logged_in() && current_user_can( 'read_post', $translation_post->ID ) ) {
				$include = true;
			}
			if ( ! $include ) {
				continue;
			}

			$url = Leadwerk_Translation_API::get_public_post_url( $translation_id );
			if ( $url ) {
				$alternates[ $lang ] = $url;
			}
		}

		return $alternates;
	}

	/**
	 * Return the current request path relative to the site home path.
	 *
	 * @return string
	 */
	protected static function get_request_path() {
		$request_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$current_path = trim( (string) wp_parse_url( self::current_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $request_path && 0 === strpos( $current_path, $request_path ) ) {
			$current_path = trim( substr( $current_path, strlen( $request_path ) ), '/' );
		}

		return urldecode( $current_path );
	}

	/**
	 * Build the current absolute request URL.
	 *
	 * @return string
	 */
	protected static function current_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		return $scheme . $host . $uri;
	}
}
