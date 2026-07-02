<?php
/**
 * Admin and orchestration layer for the Leadwerk multilingual system.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_WPML_Clone {

	const REPAIR_NOTICE_TRANSIENT = 'leadwerk_translation_registry_repair_notice';
	const STYLE_REPAIR_OPTION     = 'leadwerk_translation_style_repair_version';
	const STYLE_REPAIR_VERSION    = '2026-03-27-1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'init', array( __CLASS__, 'maybe_repair_finora_body_classes' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar_switcher' ), 90 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_translation_metabox' ) );
		add_action( 'save_post', array( __CLASS__, 'maybe_sync_source_post' ), 20, 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_attachment_image_attributes' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_admin_list_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_list_filter' ) );
		add_action( 'init', array( __CLASS__, 'register_translatable_list_table_hooks' ), 20 );
	}

	/**
	 * Register list columns, custom column render, and row actions for translatable post types.
	 * Runs on init priority 20 so theme-registered CPTs (e.g. acm_news) exist.
	 *
	 * @return void
	 */
	public static function register_translatable_list_table_hooks() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		foreach ( Leadwerk_Translation_API::get_settings()['translatable_post_types'] as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || ! Leadwerk_Translation_API::is_translatable_post_type( $post_type ) ) {
				continue;
			}

			$pto = get_post_type_object( $post_type );
			if ( ! $pto || ! $pto->show_ui ) {
				continue;
			}

			if ( 'page' === $post_type ) {
				add_filter( 'manage_pages_columns', array( __CLASS__, 'add_list_columns' ) );
				add_action( 'manage_pages_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );
				add_filter( 'page_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
				continue;
			}

			add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'add_list_columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_list_column' ), 10, 2 );
			add_filter( "{$post_type}_row_actions", array( __CLASS__, 'add_row_action' ), 10, 2 );
		}
	}

	/**
	 * Handle admin actions and state changes.
	 *
	 * @return void
	 */
	public static function handle_admin_actions() {
		if ( isset( $_GET['lw_admin_lang'] ) ) {
			Leadwerk_Translation_API::set_admin_language( sanitize_key( wp_unslash( $_GET['lw_admin_lang'] ) ) );

			$redirect = remove_query_arg( 'lw_admin_lang' );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( isset( $_GET['leadwerk_repair_registry'] ) && current_user_can( 'manage_options' ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ?? '' ), 'leadwerk_repair_registry' ) ) {
			$summary = array(
				'registry' => Leadwerk_Translation_API::repair_all_translation_links( 'en' ),
				'styles'   => self::repair_finora_body_class_meta( true ),
			);
			set_transient( self::REPAIR_NOTICE_TRANSIENT, $summary, MINUTE_IN_SECONDS * 5 );
			wp_safe_redirect( admin_url( 'admin.php?page=leadwerk-translation' ) );
			exit;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		if ( isset( $_GET['leadwerk_create_translation'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ?? '' ), 'leadwerk_create_translation' ) ) {
			$source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
			$lang      = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : 'en';
			$target_id = self::create_translation_post( $source_id, $lang );
			$url       = add_query_arg(
				array(
					'page'      => 'leadwerk-translation',
					'source_id' => $source_id,
					'lang'      => $lang,
				),
				admin_url( 'admin.php' )
			);

			if ( $target_id ) {
				wp_safe_redirect( $url );
				exit;
			}
		}

		if ( isset( $_GET['leadwerk_copy_menu'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ?? '' ), 'leadwerk_copy_menu' ) ) {
			$source_menu_id = isset( $_GET['source_menu_id'] ) ? absint( $_GET['source_menu_id'] ) : 0;
			self::copy_menu_translation( $source_menu_id, 'en' );

			wp_safe_redirect( admin_url( 'admin.php?page=leadwerk-translation-menus' ) );
			exit;
		}
	}

	/**
	 * Register plugin admin menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Leadwerk Translation', 'leadwerk-wpml-clone' ),
			__( 'Leadwerk Translation', 'leadwerk-wpml-clone' ),
			'edit_posts',
			'leadwerk-translation',
			array( __CLASS__, 'render_dashboard_or_editor' ),
			'dashicons-translation',
			81
		);

		add_submenu_page(
			'leadwerk-translation',
			__( 'String Translation', 'leadwerk-wpml-clone' ),
			__( 'String Translation', 'leadwerk-wpml-clone' ),
			'manage_options',
			'leadwerk-translation-strings',
			array( __CLASS__, 'render_strings_page' )
		);

		add_submenu_page(
			'leadwerk-translation',
			__( 'Menus', 'leadwerk-wpml-clone' ),
			__( 'Menus', 'leadwerk-wpml-clone' ),
			'manage_options',
			'leadwerk-translation-menus',
			array( __CLASS__, 'render_menus_page' )
		);

		add_submenu_page(
			'leadwerk-translation',
			__( 'Settings', 'leadwerk-wpml-clone' ),
			__( 'Settings', 'leadwerk-wpml-clone' ),
			'manage_options',
			'leadwerk-translation-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		add_submenu_page(
			'leadwerk-translation',
			__( 'Registry cleanup', 'leadwerk-wpml-clone' ),
			__( 'Registry cleanup', 'leadwerk-wpml-clone' ),
			'manage_options',
			'leadwerk-translation-registry-cleanup',
			array( __CLASS__, 'render_registry_cleanup_page' )
		);
	}

	/**
	 * Add an admin-bar language switcher.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 * @return void
	 */
	public static function register_admin_bar_switcher( $admin_bar ) {
		if ( ! is_admin() || ! $admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		$current = Leadwerk_Translation_API::get_admin_language();
		$title   = 'Lang: ' . strtoupper( $current );

		$admin_bar->add_node(
			array(
				'id'    => 'leadwerk-admin-language',
				'title' => esc_html( $title ),
				'href'  => false,
			)
		);

		foreach ( Leadwerk_Translation_API::get_active_languages() as $code => $config ) {
			$admin_bar->add_node(
				array(
					'id'     => 'leadwerk-admin-language-' . $code,
					'parent' => 'leadwerk-admin-language',
					'title'  => esc_html( $config['label'] ),
					'href'   => esc_url( add_query_arg( 'lw_admin_lang', $code ) ),
				)
			);
		}
	}

	/**
	 * Dashboard or translation editor entry point.
	 *
	 * @return void
	 */
	public static function render_dashboard_or_editor() {
		$source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
		if ( $source_id ) {
			self::render_editor_page();
			return;
		}

		self::render_dashboard_page();
	}

	/**
	 * Render dashboard.
	 *
	 * @return void
	 */
	protected static function render_dashboard_page() {
		foreach ( Leadwerk_Translation_API::get_settings()['translatable_post_types'] as $post_type ) {
			self::ensure_registry_for_post_type( $post_type );
		}

		self::sync_dashboard_string_packages();

		$source_ids     = self::get_dashboard_source_ids();
		$string_queue = Leadwerk_Translation_API::get_string_translation_queue( 'en' );
		$repair_notice  = get_transient( self::REPAIR_NOTICE_TRANSIENT );
		if ( false !== $repair_notice ) {
			delete_transient( self::REPAIR_NOTICE_TRANSIENT );
		}
		$repair_url = current_user_can( 'manage_options' )
			? wp_nonce_url(
				add_query_arg(
					array(
						'page'                    => 'leadwerk-translation',
						'leadwerk_repair_registry'=> '1',
					),
					admin_url( 'admin.php' )
				),
				'leadwerk_repair_registry'
			)
			: '';

		echo '<div class="wrap"><h1>Leadwerk Translation</h1>';
		if ( is_array( $repair_notice ) ) {
			echo '<div class="notice notice-success"><p>';
			$registry_notice = is_array( $repair_notice['registry'] ?? null ) ? $repair_notice['registry'] : $repair_notice;
			$style_notice    = is_array( $repair_notice['styles'] ?? null ) ? $repair_notice['styles'] : array();
			echo 'Translation repair complete. ';
			echo 'Registry: scanned ' . esc_html( (string) ( $registry_notice['scanned'] ?? 0 ) ) . ', ';
			echo 'resolved ' . esc_html( (string) ( $registry_notice['resolved'] ?? 0 ) ) . ', ';
			echo 'repaired ' . esc_html( (string) ( $registry_notice['repaired'] ?? 0 ) ) . ', ';
			echo 'duplicates ' . esc_html( (string) ( $registry_notice['duplicates_detected'] ?? 0 ) ) . ', ';
			echo 'missing ' . esc_html( (string) ( $registry_notice['missing'] ?? 0 ) ) . '.';
			if ( ! empty( $style_notice ) ) {
				echo ' Styles: scanned ' . esc_html( (string) ( $style_notice['scanned'] ?? 0 ) ) . ', ';
				echo 'repaired ' . esc_html( (string) ( $style_notice['repaired'] ?? 0 ) ) . ', ';
				echo 'matched ' . esc_html( (string) ( $style_notice['matched'] ?? 0 ) ) . ', ';
				echo 'missing shells ' . esc_html( (string) ( $style_notice['missing_shell'] ?? 0 ) ) . '.';
			}
			echo '</p></div>';
		}
		if ( '' !== $repair_url ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( $repair_url ) . '">Run Translation Repair</a></p>';
		}
		echo '<h2 style="margin-top:24px;">Page Translations</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Source</th><th>English</th><th>Progress</th><th>Translation</th><th>Publication</th><th></th></tr></thead><tbody>';

		foreach ( $source_ids as $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source instanceof WP_Post ) {
				continue;
			}

			$translation_info = Leadwerk_Translation_API::repair_translation_links_for_source( $source->ID, 'en' );
			$target_id        = (int) ( $translation_info['target_id'] ?? 0 );
			$status           = $target_id ? (string) get_post_meta( $target_id, Leadwerk_Translation_API::META_STATUS, true ) : 'not_translated';
			$target           = $target_id ? get_post( $target_id ) : null;
			$post_state = $target instanceof WP_Post ? (string) $target->post_status : 'draft';
			$last_sync = $target_id ? get_post_meta( $target_id, Leadwerk_Translation_API::META_SEED_SNAPSHOT, true ) : array();
			$last_sync = is_array( $last_sync ) ? $last_sync : array();
			$completeness = $target_id ? Leadwerk_Translation_API::get_translation_completeness( $target_id ) : 0;
			$url       = add_query_arg(
				array(
					'page'      => 'leadwerk-translation',
					'source_id' => $source->ID,
					'lang'      => 'en',
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td>' . esc_html( $source->post_type ) . '</td>';
			echo '<td>' . esc_html( $source->post_title ) . '</td>';
			echo '<td>' . esc_html( $target ? $target->post_title : 'Missing' );
			if ( ! empty( $translation_info['repaired'] ) ) {
				echo '<div style="margin-top:6px;color:#667085;font-size:12px;">Registry repaired from existing EN page.</div>';
			}
			if ( ! empty( $translation_info['duplicates_detected'] ) ) {
				echo '<div style="margin-top:6px;color:#92400e;font-size:12px;">Duplicate EN candidates detected; best match selected.</div>';
			}
			echo '</td>';
			echo '<td><div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;background:#e5e7eb;border-radius:999px;height:8px;min-width:60px;"><div style="width:' . esc_attr( $completeness ) . '%;height:100%;background:' . ( $completeness >= 100 ? '#22c55e' : ( $completeness > 0 ? '#3b82f6' : '#ef4444' ) ) . ';border-radius:999px;transition:width .3s;"></div></div><span style="font-size:12px;font-weight:600;color:#667085;">' . esc_html( $completeness . '%' ) . '</span></div></td>';
			echo '<td><span class="leadwerk-status-badge leadwerk-status-badge--' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
			if ( ! empty( $last_sync['updated_at_gmt'] ) ) {
				echo '<div style="margin-top:6px;color:#667085;font-size:12px;">' . esc_html( gmdate( 'Y-m-d H:i', strtotime( (string) $last_sync['updated_at_gmt'] ) ) . ' UTC' ) . '</div>';
			}
			echo '</td>';
			echo '<td><span class="leadwerk-state-badge leadwerk-state-badge--' . esc_attr( sanitize_key( $post_state ) ) . '">' . esc_html( self::post_state_label( $post_state ) ) . '</span></td>';
			echo '<td><a class="button button-secondary" href="' . esc_url( $url ) . '">Open Editor</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2 style="margin-top:28px;">String Updates</h2>';
		if ( empty( $string_queue ) ) {
			echo '<p>No string translations are waiting for review.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Package</th><th>Key</th><th>Source</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $string_queue as $row ) {
				$source_record = Leadwerk_Translation_API::get_string_record(
					(string) ( $row['package'] ?? '' ),
					(string) ( $row['string_name'] ?? '' ),
					Leadwerk_Translation_API::get_default_language(),
					(string) ( $row['object_type'] ?? '' ),
					(int) ( $row['object_id'] ?? 0 )
				);
				$strings_url   = admin_url( 'admin.php?page=leadwerk-translation-strings' );

				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['package'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['string_name'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $source_record['value'] ?? '' ) ) . '</td>';
				echo '<td><span class="leadwerk-status-badge leadwerk-status-badge--' . esc_attr( (string) ( $row['status'] ?? 'not_translated' ) ) . '">' . esc_html( self::status_label( (string) ( $row['status'] ?? 'not_translated' ) ) ) . '</span></td>';
				echo '<td><a class="button button-secondary" href="' . esc_url( $strings_url ) . '">Open Strings</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
		self::render_inline_styles();
	}

	/**
	 * Render translation editor.
	 *
	 * @return void
	 */
	protected static function render_editor_page() {
		$source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
		$lang      = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : 'en';

		if ( ! $source_id ) {
			self::render_dashboard_page();
			return;
		}

		$translation_info = Leadwerk_Translation_API::repair_translation_links_for_source( $source_id, $lang );
		$translated_id    = (int) ( $translation_info['target_id'] ?? 0 );
		if ( ! $translated_id ) {
			$translated_id = self::create_translation_post( $source_id, $lang );
			$translation_info = Leadwerk_Translation_API::repair_translation_links_for_source( $source_id, $lang, $translated_id );
		}

		if ( ! $translated_id ) {
			echo '<div class="wrap"><h1>Leadwerk Translation</h1><div class="notice notice-error"><p>No translation target found.</p></div></div>';
			return;
		}

		$notice_type = '';
		$notice_text = '';

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['leadwerk_translation_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['leadwerk_translation_nonce'] ), 'leadwerk_save_translation' ) ) {
			$action          = sanitize_key( (string) ( wp_unslash( $_POST['leadwerk_editor_action'] ?? 'save_draft' ) ) );
			$current_post    = get_post( $translated_id );
			$current_state   = $current_post instanceof WP_Post ? (string) $current_post->post_status : 'draft';
			$identity_result = self::save_editor_page_settings( $translated_id, $_POST );
			$bundle          = Leadwerk_Translation_Sync::save_translations( $source_id, $translated_id, $_POST['leadwerk_segments'] ?? array() );
			$bundle_state    = Leadwerk_Translation_Sync::get_bundle_status( $bundle );
			Leadwerk_Shared_Translation_Packages::save_editor_submissions( $_POST['leadwerk_shared_segments'] ?? array(), $source_id, $lang );
			$shared_sections = Leadwerk_Shared_Translation_Packages::get_editor_sections( $source_id, $lang );
			$shared_state    = Leadwerk_Shared_Translation_Packages::get_sections_status( $shared_sections );
			$combined_state  = self::merge_translation_statuses( $bundle_state, $shared_state );

			if ( 'save_publish' === $action ) {
				if ( ! empty( $identity_result['publish_error'] ) ) {
					if ( 'publish' !== $current_state ) {
						wp_update_post(
							array(
								'ID'          => $translated_id,
								'post_status' => 'draft',
							)
						);
					}
					$notice_type = 'error';
					$notice_text = (string) $identity_result['publish_error'];
				} elseif ( Leadwerk_Translation_Sync::can_publish_bundle( $bundle ) && 'complete' === $shared_state ) {
					wp_update_post(
						array(
							'ID'          => $translated_id,
							'post_status' => 'publish',
						)
					);
					$notice_type = 'success';
					$notice_text = 'publish' === $current_state ? 'Published translation updated.' : 'Translation published.';
				} else {
					if ( 'publish' !== $current_state ) {
						wp_update_post(
							array(
								'ID'          => $translated_id,
								'post_status' => 'draft',
							)
						);
					}
					$notice_type = 'error';
					$notice_text = 'Complete every required segment before publishing this translation. Current status: ' . self::status_label( $combined_state ) . '.';
				}
			} elseif ( 'save_translation' === $action ) {
				$notice_type = ! empty( $identity_result['draft_warning'] ) ? 'warning' : 'success';
				$notice_text = ! empty( $identity_result['draft_warning'] ) ? (string) $identity_result['draft_warning'] : 'Translation updated.';
			} else {
				if ( 'publish' !== $current_state ) {
					wp_update_post(
						array(
							'ID'          => $translated_id,
							'post_status' => 'draft',
						)
					);
				}
				$notice_type = ! empty( $identity_result['draft_warning'] ) ? 'warning' : 'success';
				$notice_text = ! empty( $identity_result['draft_warning'] ) ? (string) $identity_result['draft_warning'] : 'Draft saved.';
			}
		}

		echo '<div class="wrap">';
		if ( '' !== $notice_text ) {
			echo '<div class="notice notice-' . esc_attr( $notice_type ) . '"><p>' . esc_html( $notice_text ) . '</p></div>';
		}
		if ( ! empty( $translation_info['repaired'] ) ) {
			echo '<div class="notice notice-info"><p>Recovered translation link from an existing EN page.</p></div>';
		}
		if ( ! empty( $translation_info['duplicates_detected'] ) ) {
			echo '<div class="notice notice-warning"><p>Duplicate EN translation candidates were found. The best existing EN page was selected; other duplicates were left untouched.</p></div>';
		}

		$sections = Leadwerk_Translation_Sync::get_editor_sections( $source_id, $translated_id );
		$shared_sections = Leadwerk_Shared_Translation_Packages::get_editor_sections( $source_id, $lang );
		if ( ! empty( $shared_sections ) ) {
			$sections = array_merge( $sections, $shared_sections );
		}
		$source   = get_post( $source_id );
		$target   = get_post( $translated_id );
		$target_translation_status = (string) get_post_meta( $translated_id, Leadwerk_Translation_API::META_STATUS, true );
		$target_post_status        = $target instanceof WP_Post ? (string) $target->post_status : 'draft';
		$page_settings             = self::get_editor_page_settings( $source_id, $translated_id );

		echo '<h1>Leadwerk Translation Editor</h1>';
		echo '<p><strong>Source:</strong> ' . esc_html( $source ? $source->post_title : '' ) . ' &rarr; <strong>English:</strong> ' . esc_html( $target ? $target->post_title : '' ) . '</p>';
		echo '<p><span class="leadwerk-status-badge leadwerk-status-badge--' . esc_attr( $target_translation_status ?: 'not_translated' ) . '">' . esc_html( self::status_label( $target_translation_status ?: 'not_translated' ) ) . '</span> <span class="leadwerk-state-badge leadwerk-state-badge--' . esc_attr( sanitize_key( $target_post_status ) ) . '">' . esc_html( self::post_state_label( $target_post_status ) ) . '</span></p>';
		echo '<form method="post" class="leadwerk-translation-form">';
		wp_nonce_field( 'leadwerk_save_translation', 'leadwerk_translation_nonce' );
		echo '<div class="leadwerk-translation-toolbar">';
		echo '<div class="leadwerk-toolbar-group leadwerk-toolbar-group--start"><label class="leadwerk-toolbar-toggle"><input type="checkbox" class="leadwerk-hide-completed-toggle" data-target="segments"> Hide completed segments</label></div>';
		echo '<div class="leadwerk-toolbar-group"><button type="button" class="button leadwerk-translation-copy-all">Copy all DE segments to EN</button><button type="button" class="button leadwerk-icon-button leadwerk-translation-clear-all" aria-label="Clear all EN segments" title="Clear all EN segments"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button><button type="button" class="button leadwerk-remove-amp-button" data-target="segments">Remove all amp;</button><span class="leadwerk-amp-status" data-target="segments" aria-live="polite"></span><button type="button" class="button button-secondary leadwerk-bulk-deepl-button" data-target="segments">Bulk DeepL Translate</button><span class="leadwerk-bulk-status" data-target="segments" aria-live="polite"></span><button type="button" class="button button-link-delete leadwerk-bulk-stop" data-target="segments" hidden>Stop</button></div>';
		self::render_editor_submit_actions( $target_post_status, 'leadwerk-submit-actions' );
		echo '</div>';
		self::render_editor_page_settings_card( $page_settings );

		foreach ( $sections as $index => $section ) {
			echo '<div class="leadwerk-translation-card"><div class="leadwerk-translation-card__head">';
			echo '<h2>' . esc_html( ( $index + 1 ) . '. ' . $section['label'] ) . '</h2>';
			echo '<div class="leadwerk-translation-card__tools">';
			echo '<span class="leadwerk-translation-card__count">' . esc_html( count( $section['segments'] ) . ' segments' ) . '</span>';
			echo '<button type="button" class="button button-small leadwerk-translation-copy-section">Copy DE section to EN</button>';
			echo '<button type="button" class="button button-small leadwerk-icon-button leadwerk-translation-clear-section" aria-label="Clear EN section" title="Clear EN section"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
			echo '</div>';
			echo '</div><div class="leadwerk-translation-card__body">';

			foreach ( $section['segments'] as $segment ) {
				$is_richtext   = 'richtext' === ( $segment['type'] ?? 'text' );
				$source_markup = $is_richtext
					? wp_kses_post( (string) $segment['source'] )
					: nl2br( esc_html( (string) $segment['source'] ) );
				$target_rows   = $is_richtext ? 8 : 3;

				echo '<div class="leadwerk-translation-segment">';
				echo '<div class="leadwerk-translation-segment__source"><strong>' . esc_html( $segment['label'] ) . '</strong><div class="leadwerk-translation-segment__lang">DE</div><div>' . $source_markup . '</div><textarea class="leadwerk-translation-source-raw" hidden readonly>' . esc_textarea( (string) $segment['source'] ) . '</textarea></div>';
				echo '<div class="leadwerk-translation-segment__target"><strong>' . esc_html( $segment['label'] ) . '</strong><div class="leadwerk-translation-segment__lang">EN</div>';
				echo '<div class="leadwerk-translation-segment__target-actions"><button type="button" class="button button-small leadwerk-translation-copy-one" title="Copy DE to EN">&darr; Copy DE</button><button type="button" class="button button-small leadwerk-icon-button leadwerk-translation-clear-one" aria-label="Clear EN segment" title="Clear EN segment"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></div>';
				$input_name = ! empty( $segment['input_name'] ) ? (string) $segment['input_name'] : 'leadwerk_segments[' . esc_attr( $segment['id'] ) . ']';
				echo '<textarea class="large-text leadwerk-translation-target" rows="' . esc_attr( (string) $target_rows ) . '" name="' . esc_attr( $input_name ) . '">' . esc_textarea( $segment['translation'] ) . '</textarea>';
				echo '<span class="leadwerk-status-badge leadwerk-status-badge--' . esc_attr( $segment['status'] ) . '">' . esc_html( self::status_label( $segment['status'] ) ) . '</span></div>';
				echo '</div>';
			}

			echo '</div></div>';
		}

		self::render_editor_submit_actions( $target_post_status, 'submit' );
		echo '</form></div>';
		self::render_inline_styles();
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( isset( $_POST['leadwerk_translation_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['leadwerk_translation_settings_nonce'] ), 'leadwerk_save_translation_settings' ) ) {
			$post_types = array_values( array_map( 'sanitize_key', (array) ( $_POST['leadwerk_post_types'] ?? array() ) ) );
			$post_types = array_values(
				array_filter(
					$post_types,
					static function ( $pt ) {
						return 'attachment' !== $pt;
					}
				)
			);
			Leadwerk_Translation_API::update_settings(
				array(
					'translatable_post_types' => $post_types,
					'translatable_taxonomies' => array_values( array_map( 'sanitize_key', (array) ( $_POST['leadwerk_taxonomies'] ?? array() ) ) ),
				)
			);

			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}

		$settings   = Leadwerk_Translation_API::get_settings();
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );

		echo '<div class="wrap"><h1>Leadwerk Translation Settings</h1><form method="post">';
		wp_nonce_field( 'leadwerk_save_translation_settings', 'leadwerk_translation_settings_nonce' );
		echo '<h2>Translatable Post Types</h2>';

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}
			echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="leadwerk_post_types[]" value="' . esc_attr( $post_type->name ) . '"' . checked( in_array( $post_type->name, $settings['translatable_post_types'], true ), true, false ) . '> ' . esc_html( $post_type->label ) . '</label>';
		}

		echo '<h2 style="margin-top:24px;">Translatable Taxonomies</h2>';
		foreach ( $taxonomies as $taxonomy ) {
			echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="leadwerk_taxonomies[]" value="' . esc_attr( $taxonomy->name ) . '"' . checked( in_array( $taxonomy->name, $settings['translatable_taxonomies'], true ), true, false ) . '> ' . esc_html( $taxonomy->label ) . '</label>';
		}

		submit_button( __( 'Save Settings', 'leadwerk-wpml-clone' ) );
		echo '</form></div>';
	}

	/**
	 * List and delete registry rows for posts that no longer exist.
	 *
	 * @return void
	 */
	public static function render_registry_cleanup_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'leadwerk-wpml-clone' ) );
		}

		if ( isset( $_POST['leadwerk_registry_cleanup_nonce'] )
			&& wp_verify_nonce( wp_unslash( $_POST['leadwerk_registry_cleanup_nonce'] ), 'leadwerk_registry_cleanup' )
			&& isset( $_POST['leadwerk_registry_cleanup_action'] )
			&& 'delete_selected' === sanitize_key( wp_unslash( $_POST['leadwerk_registry_cleanup_action'] ) ) ) {
			$ids = isset( $_POST['leadwerk_registry_row_ids'] ) ? (array) wp_unslash( $_POST['leadwerk_registry_row_ids'] ) : array();
			$res = Leadwerk_Translation_API::delete_orphan_registry_rows_by_ids( $ids );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: deleted count, 2: skipped count */
						__( 'Removed %1$d registry row(s); skipped %2$d (not found, not orphan, or wrong type).', 'leadwerk-wpml-clone' ),
						(int) ( $res['deleted'] ?? 0 ),
						(int) ( $res['skipped'] ?? 0 )
					)
				)
			);
		}

		$orphans = Leadwerk_Translation_API::get_orphan_post_registry_rows();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Registry cleanup (orphan posts)', 'leadwerk-wpml-clone' ) . '</h1>';
		echo '<p>' . esc_html__( 'These rows point to post IDs that no longer exist in WordPress (deleted posts). Trashed posts still exist and will not appear here. Term translations are not listed.', 'leadwerk-wpml-clone' ) . '</p>';

		if ( empty( $orphans ) ) {
			echo '<p><strong>' . esc_html__( 'No orphan registry rows found.', 'leadwerk-wpml-clone' ) . '</strong></p>';
			echo '</div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'leadwerk_registry_cleanup', 'leadwerk_registry_cleanup_nonce' );
		echo '<input type="hidden" name="leadwerk_registry_cleanup_action" value="delete_selected" />';

		echo '<p><button type="submit" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Delete the selected registry rows? This cannot be undone.', 'leadwerk-wpml-clone' ) ) . '\');">' . esc_html__( 'Delete selected', 'leadwerk-wpml-clone' ) . '</button>';
		echo ' <button type="button" class="button" id="leadwerk-registry-cleanup-select-all">' . esc_html__( 'Select all', 'leadwerk-wpml-clone' ) . '</button>';
		echo ' <button type="button" class="button" id="leadwerk-registry-cleanup-select-none">' . esc_html__( 'Select none', 'leadwerk-wpml-clone' ) . '</button></p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:40px;"><span class="screen-reader-text">' . esc_html__( 'Select', 'leadwerk-wpml-clone' ) . '</span></th>';
		echo '<th>' . esc_html__( 'Row ID', 'leadwerk-wpml-clone' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'leadwerk-wpml-clone' ) . '</th>';
		echo '<th>' . esc_html__( 'Post ID', 'leadwerk-wpml-clone' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'leadwerk-wpml-clone' ) . '</th>';
		echo '<th>' . esc_html__( 'Public slug', 'leadwerk-wpml-clone' ) . '</th>';
		echo '<th>' . esc_html__( 'TRID', 'leadwerk-wpml-clone' ) . '</th>';
		echo '<th>' . esc_html__( 'Source post ID', 'leadwerk-wpml-clone' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $orphans as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$db_id = (int) ( $row['id'] ?? 0 );
			if ( $db_id < 1 ) {
				continue;
			}
			echo '<tr>';
			echo '<td><input class="leadwerk-registry-cleanup-cb" type="checkbox" name="leadwerk_registry_row_ids[]" value="' . esc_attr( (string) $db_id ) . '" /></td>';
			echo '<td>' . esc_html( (string) $db_id ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['element_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $row['element_id'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['language_code'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['public_slug'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $row['trid'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) (int) ( $row['source_element_id'] ?? 0 ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary" onclick="return confirm(\'' . esc_js( __( 'Delete the selected registry rows? This cannot be undone.', 'leadwerk-wpml-clone' ) ) . '\');">' . esc_html__( 'Delete selected', 'leadwerk-wpml-clone' ) . '</button></p>';
		echo '</form>';

		echo '<script>';
		echo '(function(){';
		echo 'var cbs=document.querySelectorAll(".leadwerk-registry-cleanup-cb");';
		echo 'var allBtn=document.getElementById("leadwerk-registry-cleanup-select-all");';
		echo 'var noneBtn=document.getElementById("leadwerk-registry-cleanup-select-none");';
		echo 'if(allBtn){allBtn.addEventListener("click",function(){for(var i=0;i<cbs.length;i++){cbs[i].checked=true;}});}';
		echo 'if(noneBtn){noneBtn.addEventListener("click",function(){for(var i=0;i<cbs.length;i++){cbs[i].checked=false;}});}';
		echo '})();';
		echo '</script>';

		echo '</div>';
	}

	/**
	 * Render string translation page for theme string packages.
	 *
	 * @return void
	 */
	public static function render_strings_page() {
		$source_strings = self::get_theme_source_strings();
		$target_records = Leadwerk_Translation_API::sync_string_package( 'theme_strings', $source_strings, 'en' );

		if ( isset( $_POST['leadwerk_string_translation_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['leadwerk_string_translation_nonce'] ), 'leadwerk_save_string_translation' ) ) {
			$submitted = array_map( 'wp_unslash', (array) ( $_POST['leadwerk_theme_strings'] ?? array() ) );
			foreach ( $source_strings as $key => $source_value ) {
				$translation = array_key_exists( $key, $submitted ) ? trim( (string) $submitted[ $key ] ) : (string) ( $target_records[ $key ]['value'] ?? '' );
				Leadwerk_Translation_API::upsert_string(
					'theme_strings',
					$key,
					'en',
					$translation,
					array(
						'source_hash' => md5( (string) $source_value ),
						'status'      => '' === $translation ? 'not_translated' : 'complete',
					)
				);
			}

			$target_records = Leadwerk_Translation_API::sync_string_package( 'theme_strings', $source_strings, 'en' );
			echo '<div class="notice notice-success"><p>String translations saved.</p></div>';
		}

		echo '<div class="wrap"><h1>String Translation</h1><form method="post" class="leadwerk-string-translation-form">';
		wp_nonce_field( 'leadwerk_save_string_translation', 'leadwerk_string_translation_nonce' );
		echo '<div class="leadwerk-translation-toolbar">';
		echo '<div class="leadwerk-toolbar-group leadwerk-toolbar-group--start"><label class="leadwerk-toolbar-toggle"><input type="checkbox" class="leadwerk-hide-completed-toggle" data-target="strings"> Hide completed segments</label></div>';
		echo '<div class="leadwerk-toolbar-group"><button type="button" class="button leadwerk-remove-amp-button" data-target="strings">Remove all amp;</button><span class="leadwerk-amp-status" data-target="strings" aria-live="polite"></span><button type="button" class="button button-secondary leadwerk-bulk-deepl-button" data-target="strings">Bulk DeepL Translate</button><span class="leadwerk-bulk-status" data-target="strings" aria-live="polite"></span><button type="button" class="button button-link-delete leadwerk-bulk-stop" data-target="strings" hidden>Stop</button></div>';
		self::render_string_submit_actions( 'leadwerk-submit-actions' );
		echo '</div>';
		echo '<table class="widefat striped leadwerk-string-table"><thead><tr><th>Key</th><th>Source</th><th>English</th><th>Status</th></tr></thead><tbody>';
		foreach ( $source_strings as $key => $value ) {
			$target_record = $target_records[ $key ] ?? array();
			$status        = (string) ( $target_record['status'] ?? 'not_translated' );

			echo '<tr class="leadwerk-string-row">';
			echo '<td><strong>' . esc_html( $key ) . '</strong></td>';
			echo '<td style="max-width:320px;" class="leadwerk-string-source"><div>' . esc_html( $value ) . '</div><textarea class="leadwerk-string-source-raw" hidden readonly>' . esc_textarea( (string) $value ) . '</textarea></td>';
			echo '<td><div class="leadwerk-string-actions"><button type="button" class="button button-small leadwerk-string-copy-one" title="Copy DE to EN">&darr; Copy DE</button><button type="button" class="button button-small leadwerk-icon-button leadwerk-string-clear-one" aria-label="Clear EN string" title="Clear EN string"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></div><textarea class="large-text leadwerk-string-target" rows="3" name="leadwerk_theme_strings[' . esc_attr( $key ) . ']">' . esc_textarea( (string) ( $target_record['value'] ?? '' ) ) . '</textarea></td>';
			echo '<td><span class="leadwerk-status-badge leadwerk-status-badge--' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		self::render_string_submit_actions( 'submit' );
		echo '</form></div>';
		self::render_inline_styles();
	}

	/**
	 * Render editor submit buttons.
	 *
	 * @param string $target_post_status Target WordPress post status.
	 * @param string $wrapper_class      Wrapper class.
	 * @return void
	 */
	protected static function render_editor_submit_actions( $target_post_status, $wrapper_class = 'submit' ) {
		$classes = trim( $wrapper_class . ' leadwerk-submit-actions' );
		echo '<div class="' . esc_attr( $classes ) . '">';
		if ( 'publish' === $target_post_status ) {
			echo '<button type="submit" name="leadwerk_editor_action" value="save_translation" class="button button-secondary">Save Translation</button> ';
			echo '<button type="submit" name="leadwerk_editor_action" value="save_publish" class="button button-primary">Update Published Translation</button>';
		} else {
			echo '<button type="submit" name="leadwerk_editor_action" value="save_draft" class="button button-secondary">Save Draft</button> ';
			echo '<button type="submit" name="leadwerk_editor_action" value="save_publish" class="button button-primary">Save + Publish Translation</button>';
		}
		echo '</div>';
	}

	/**
	 * Render string translation submit button.
	 *
	 * @param string $wrapper_class Wrapper class.
	 * @return void
	 */
	protected static function render_string_submit_actions( $wrapper_class = 'submit' ) {
		$classes = trim( $wrapper_class . ' leadwerk-submit-actions' );
		echo '<div class="' . esc_attr( $classes ) . '">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save Strings', 'leadwerk-wpml-clone' ) . '</button>';
		echo '</div>';
	}

	/**
	 * Build editor page settings payload.
	 *
	 * @param int $source_id     Source post ID.
	 * @param int $translated_id Target post ID.
	 * @return array<string,mixed>
	 */
	protected static function get_editor_page_settings( $source_id, $translated_id ) {
		$source = get_post( (int) $source_id );
		$target = get_post( (int) $translated_id );

		$source_slug = self::format_public_slug_for_display( Leadwerk_Translation_API::get_post_public_slug( $source_id ), Leadwerk_Translation_API::is_post_language_root( $source_id ), 'de' );
		$target_slug = self::format_public_slug_for_display( Leadwerk_Translation_API::get_post_public_slug( $translated_id ), Leadwerk_Translation_API::is_post_language_root( $translated_id ), Leadwerk_Translation_API::get_post_language( $translated_id ) );

		$is_acm_news = ( $source instanceof WP_Post && 'acm_news' === $source->post_type )
			|| ( $target instanceof WP_Post && 'acm_news' === $target->post_type );
		if ( $is_acm_news ) {
			$source_slug = self::strip_leading_news_url_segment( $source_slug );
			$target_slug = self::strip_leading_news_url_segment( $target_slug );
		}

		return array(
			'source_title'   => $source instanceof WP_Post ? (string) $source->post_title : '',
			'target_title'   => $target instanceof WP_Post ? (string) $target->post_title : '',
			'source_slug'    => $source_slug,
			'target_slug'    => $target_slug,
			'target_is_home' => Leadwerk_Translation_API::is_post_language_root( $translated_id ),
			'is_acm_news'    => $is_acm_news,
		);
	}

	/**
	 * Render the page settings card above translation segments.
	 *
	 * @param array<string,mixed> $settings Settings payload.
	 * @return void
	 */
	protected static function render_editor_page_settings_card( $settings ) {
		echo '<div class="leadwerk-translation-card leadwerk-page-settings-card">';
		echo '<div class="leadwerk-translation-card__head"><h2>Page Settings</h2></div>';
		echo '<div class="leadwerk-translation-card__body leadwerk-page-settings-grid">';

		echo '<label class="leadwerk-page-settings-field"><span class="leadwerk-page-settings-label">Source Title</span><textarea class="large-text leadwerk-page-settings-textarea leadwerk-page-settings-source-title" rows="2" readonly>' . esc_textarea( (string) ( $settings['source_title'] ?? '' ) ) . '</textarea></label>';
		echo '<label class="leadwerk-page-settings-field"><span class="leadwerk-page-settings-label">English Title</span><textarea class="large-text leadwerk-page-settings-textarea leadwerk-page-settings-target-title" rows="2" name="leadwerk_page_title" placeholder="English title">' . esc_textarea( (string) ( $settings['target_title'] ?? '' ) ) . '</textarea></label>';
		echo '<label class="leadwerk-page-settings-field"><span class="leadwerk-page-settings-label">Source Slug</span><textarea class="large-text leadwerk-page-settings-textarea leadwerk-page-settings-source-slug" rows="2" readonly>' . esc_textarea( (string) ( $settings['source_slug'] ?? '' ) ) . '</textarea></label>';
		$slug_placeholder = ! empty( $settings['is_acm_news'] ) ? 'my-article-slug' : '/en/page-slug';
		echo '<label class="leadwerk-page-settings-field"><span class="leadwerk-page-settings-label">English Slug</span><textarea class="large-text leadwerk-page-settings-textarea leadwerk-page-settings-target-slug" rows="2" name="leadwerk_page_public_slug" placeholder="' . esc_attr( $slug_placeholder ) . '"' . disabled( ! empty( $settings['target_is_home'] ), true, false ) . '>' . esc_textarea( (string) ( $settings['target_slug'] ?? '' ) ) . '</textarea></label>';

		if ( ! empty( $settings['target_is_home'] ) ) {
			echo '<p class="leadwerk-page-settings-note">Home/root translations keep their language root URL and cannot use a custom public slug.</p>';
		}

		if ( ! empty( $settings['is_acm_news'] ) ) {
			echo '<p class="leadwerk-page-settings-note">' . esc_html__( 'News articles: Source and English slug fields show only the article slug (no "news/" prefix). That prefix is added automatically in the public URL.', 'leadwerk-wpml-clone' ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * Save title and public slug from the translation editor.
	 *
	 * @param int                 $translated_id Target post ID.
	 * @param array<string,mixed> $submitted     Raw form submission.
	 * @return array<string,string|bool>
	 */
	protected static function save_editor_page_settings( $translated_id, $submitted ) {
		$translated_id = (int) $translated_id;
		$current_post  = get_post( $translated_id );
		$current_title = $current_post instanceof WP_Post ? (string) $current_post->post_title : '';
		$is_home       = Leadwerk_Translation_API::is_post_language_root( $translated_id );
		$title_input = sanitize_text_field( (string) wp_unslash( $submitted['leadwerk_page_title'] ?? $current_title ) );

		$raw_slug = trim( (string) wp_unslash( $submitted['leadwerk_page_public_slug'] ?? '' ), '/' );
		if ( $current_post instanceof WP_Post && 'acm_news' === $current_post->post_type && '' !== $raw_slug ) {
			$raw_slug = self::strip_leading_news_url_segment( $raw_slug );
		}
		$slug_input = sanitize_title( $raw_slug );

		$result = array(
			'publish_error' => '',
			'draft_warning' => '',
		);

		if ( '' === trim( $title_input ) ) {
			$title_input = $current_title;
			if ( 'save_publish' === sanitize_key( (string) ( wp_unslash( $submitted['leadwerk_editor_action'] ?? '' ) ) ) ) {
				$result['publish_error'] = 'English title is required before publishing this translation.';
				return $result;
			}

			$result['draft_warning'] = 'Translation saved. English title was empty, so the existing title was kept.';
		}

		$identity_args = array(
			'post_title' => $title_input,
		);

		if ( ! $is_home && '' !== $slug_input ) {
			$identity_args['public_slug'] = $slug_input;
		}

		Leadwerk_Translation_API::update_post_identity( $translated_id, $identity_args );
		return $result;
	}

	/**
	 * Format a public slug for readonly/editor display.
	 *
	 * @param string $public_slug Public slug.
	 * @param bool   $is_home     Whether the post is a language root.
	 * @param string $lang        Language code.
	 * @return string
	 */
	protected static function format_public_slug_for_display( $public_slug, $is_home = false, $lang = 'de' ) {
		if ( $is_home ) {
			return 'de' === sanitize_key( (string) $lang ) ? '/' : '/' . sanitize_key( (string) $lang ) . '/';
		}

		return trim( (string) $public_slug, '/' );
	}

	/**
	 * Remove leading "news/" segments from a registry-style path for editor display/input.
	 *
	 * @param string $public_slug Path or slug string.
	 * @return string
	 */
	protected static function strip_leading_news_url_segment( $public_slug ) {
		$s = trim( rawurldecode( (string) $public_slug ), '/' );
		while ( preg_match( '#^news/#i', $s ) ) {
			$s = trim( (string) preg_replace( '#^news/#i', '', $s ), '/' );
		}

		return $s;
	}

	/**
	 * Strip CPT rewrite prefix "news/" when the post is acm_news (delegates to strip_leading_news_url_segment).
	 *
	 * @param string $public_slug Path segment(s).
	 * @param int    $post_id     Post ID (used to detect acm_news).
	 * @return string
	 */
	protected static function strip_acm_news_public_path_prefix_for_editor( $public_slug, $post_id ) {
		if ( ! $post_id || 'acm_news' !== get_post_type( (int) $post_id ) ) {
			return $public_slug;
		}

		return self::strip_leading_news_url_segment( $public_slug );
	}

	/**
	 * Render menu translation helper page.
	 *
	 * @return void
	 */
	public static function render_menus_page() {
		$menus = wp_get_nav_menus();

		echo '<div class="wrap"><h1>Leadwerk Translation Menus</h1><table class="widefat striped"><thead><tr><th>Source Menu</th><th>English Menu</th><th></th></tr></thead><tbody>';
		foreach ( $menus as $menu ) {
			$source_record = Leadwerk_Translation_API::get_registry_record( Leadwerk_Translation_API::get_term_element_type( 'nav_menu' ), (int) $menu->term_id );
			if ( empty( $source_record ) ) {
				$source_record = Leadwerk_Translation_API::upsert_element(
					Leadwerk_Translation_API::get_term_element_type( 'nav_menu' ),
					(int) $menu->term_id,
					array(
						'language_code' => 'de',
						'public_slug'   => $menu->slug,
						'internal_slug' => $menu->slug,
						'status'        => 'complete',
					)
				);
			}

			$target_name = 'Missing';
			$target_id   = 0;

			if ( ! empty( $source_record['trid'] ) ) {
				global $wpdb;
				$target_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT element_id FROM " . Leadwerk_Translation_API::get_elements_table_name() . " WHERE trid = %s AND element_type = %s AND language_code = 'en' LIMIT 1",
						(string) $source_record['trid'],
						Leadwerk_Translation_API::get_term_element_type( 'nav_menu' )
					)
				);
			}

			if ( $target_id ) {
				$target_menu = wp_get_nav_menu_object( $target_id );
				$target_name = $target_menu ? $target_menu->name : 'Missing';
			}

			$copy_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'                => 'leadwerk-translation-menus',
						'leadwerk_copy_menu'  => '1',
						'source_menu_id'      => (int) $menu->term_id,
					),
					admin_url( 'admin.php' )
				),
				'leadwerk_copy_menu'
			);

			echo '<tr><td>' . esc_html( $menu->name ) . '</td><td>' . esc_html( $target_name ) . '</td><td><a class="button button-secondary" href="' . esc_url( $copy_url ) . '">Copy to EN</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Add a row action on page lists.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @param WP_Post              $post    Current post.
	 * @return array<string,string>
	 */
	public static function add_row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || ! Leadwerk_Translation_API::is_translatable_post_type( $post->post_type ) ) {
			return $actions;
		}

		$source_id = Leadwerk_Translation_API::get_source_post_id( $post->ID );
		$url       = add_query_arg(
			array(
				'page'      => 'leadwerk-translation',
				'source_id' => $source_id ?: $post->ID,
				'lang'      => 'en',
			),
				admin_url( 'admin.php' )
			);

		$actions['leadwerk_translate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Translate', 'leadwerk-wpml-clone' ) . '</a>';
		return $actions;
	}

	/**
	 * Register the translation info meta box.
	 *
	 * @return void
	 */
	public static function register_translation_metabox() {
		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $post_type ) {
			if ( Leadwerk_Translation_API::is_translatable_post_type( $post_type ) ) {
				add_meta_box( 'leadwerk_translation_box', 'Leadwerk Translation', array( __CLASS__, 'render_translation_metabox' ), $post_type, 'side', 'high' );
			}
		}
	}

	/**
	 * Render the translation info meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_translation_metabox( $post ) {
		$lang      = Leadwerk_Translation_API::get_post_language( $post->ID );
		$source_id = Leadwerk_Translation_API::get_source_post_id( $post->ID );
		$target_id = Leadwerk_Translation_API::get_translation( $source_id ?: $post->ID, 'en' );
		$status    = (string) get_post_meta( $post->ID, Leadwerk_Translation_API::META_STATUS, true );
		$post_state = (string) $post->post_status;

		echo '<p><strong>Language:</strong> ' . esc_html( strtoupper( $lang ) ) . '</p>';
		echo '<p><strong>Status:</strong> ' . esc_html( self::status_label( $status ?: 'complete' ) ) . '</p>';
		echo '<p><strong>Publication:</strong> ' . esc_html( self::post_state_label( $post_state ) ) . '</p>';

		if ( $source_id && $source_id !== $post->ID ) {
			echo '<p><a href="' . esc_url( get_edit_post_link( $source_id ) ) . '">Open source</a></p>';
		}

		if ( $target_id ) {
			$editor_url = add_query_arg(
				array(
					'page'      => 'leadwerk-translation',
					'source_id' => $source_id ?: $post->ID,
					'lang'      => 'en',
				),
				admin_url( 'admin.php' )
			);
			echo '<p><a class="button button-secondary" href="' . esc_url( $editor_url ) . '">Open translation editor</a></p>';
			return;
		}

		if ( Leadwerk_Translation_API::get_default_language() === $lang ) {
			$create_url = wp_nonce_url(
				add_query_arg(
					array(
						'leadwerk_create_translation' => '1',
						'source_id'                   => $post->ID,
						'lang'                        => 'en',
					),
					admin_url( 'admin.php' )
				),
				'leadwerk_create_translation'
			);
			echo '<p><a class="button button-primary" href="' . esc_url( $create_url ) . '">Create English translation</a></p>';
		}
	}

	/**
	 * Add language/status columns.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function add_list_columns( $columns ) {
		$columns['leadwerk_lang']        = 'Lang';
		$columns['leadwerk_translation'] = 'Translation';
		return $columns;
	}

	/**
	 * Render language/status columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_list_column( $column, $post_id ) {
		if ( 'leadwerk_lang' === $column ) {
			echo esc_html( strtoupper( Leadwerk_Translation_API::get_post_language( $post_id ) ) );
			return;
		}

		if ( 'leadwerk_translation' === $column ) {
			$post      = get_post( $post_id );
			$lang      = Leadwerk_Translation_API::get_post_language( $post_id );
			$source_id = Leadwerk_Translation_API::get_source_post_id( $post_id );
			$source_id = $source_id ?: (int) $post_id;
			$target_id = Leadwerk_Translation_API::get_translation( $source_id, 'en' );
			$status    = $target_id ? (string) get_post_meta( $target_id, Leadwerk_Translation_API::META_STATUS, true ) : 'not_translated';
			$target    = $target_id ? get_post( $target_id ) : null;
			$post_state = $target instanceof WP_Post ? (string) $target->post_status : 'draft';

			if ( Leadwerk_Translation_API::get_default_language() !== $lang ) {
				$editor_url = add_query_arg(
					array(
						'page'      => 'leadwerk-translation',
						'source_id' => $source_id,
						'lang'      => $lang,
					),
					admin_url( 'admin.php' )
				);
				echo '<a href="' . esc_url( $editor_url ) . '" title="' . esc_attr__( 'Edit translation', 'leadwerk-wpml-clone' ) . '"><span class="dashicons dashicons-edit" style="vertical-align:text-bottom;"></span></a> ';
				echo '<span class="leadwerk-status-badge leadwerk-status-badge--' . esc_attr( $status ?: 'complete' ) . '">' . esc_html( strtoupper( $lang ) ) . '</span>';
				echo ' <span class="leadwerk-state-badge leadwerk-state-badge--' . esc_attr( sanitize_key( (string) $post->post_status ) ) . '">' . esc_html( self::post_state_label( (string) $post->post_status ) ) . '</span>';
				return;
			}

			if ( $target_id ) {
				$editor_url = add_query_arg(
					array(
						'page'      => 'leadwerk-translation',
						'source_id' => $source_id,
						'lang'      => 'en',
					),
					admin_url( 'admin.php' )
				);
				echo '<a href="' . esc_url( $editor_url ) . '" title="' . esc_attr__( 'Edit English translation', 'leadwerk-wpml-clone' ) . '"><span class="dashicons dashicons-edit" style="vertical-align:text-bottom;"></span></a> ';
				echo '<span class="leadwerk-status-badge leadwerk-status-badge--' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span> ';
				echo '<span class="leadwerk-state-badge leadwerk-state-badge--' . esc_attr( sanitize_key( $post_state ) ) . '">' . esc_html( self::post_state_label( $post_state ) ) . '</span>';
				return;
			}

			if ( $post instanceof WP_Post ) {
				$create_url = wp_nonce_url(
					add_query_arg(
						array(
							'leadwerk_create_translation' => '1',
							'source_id'                   => $source_id,
							'lang'                        => 'en',
						),
						admin_url( 'admin.php' )
					),
					'leadwerk_create_translation'
				);
				echo '<a href="' . esc_url( $create_url ) . '" title="' . esc_attr__( 'Create English translation', 'leadwerk-wpml-clone' ) . '"><span class="dashicons dashicons-plus-alt" style="vertical-align:text-bottom;"></span></a> ';
			}

			echo '<span class="leadwerk-status-badge leadwerk-status-badge--not_translated">' . esc_html__( 'Not translated', 'leadwerk-wpml-clone' ) . '</span>';
		}
	}

	/**
	 * Render language filter on list screens.
	 *
	 * @return void
	 */
	public static function render_list_filter() {
		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->post_type ) || ! Leadwerk_Translation_API::is_translatable_post_type( $screen->post_type ) ) {
			return;
		}

		$current = Leadwerk_Translation_API::get_admin_language();
		echo '<select name="lw_admin_lang">';
		echo '<option value="all"' . selected( $current, 'all', false ) . '>' . esc_html__( 'All Languages', 'leadwerk-wpml-clone' ) . '</option>';
		foreach ( Leadwerk_Translation_API::get_active_languages() as $code => $config ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $config['label'] ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Filter admin list queries by the selected language.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	public static function filter_admin_list_query( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		if ( ! $post_type || ! Leadwerk_Translation_API::is_translatable_post_type( $post_type ) ) {
			return;
		}

		global $wpdb;
		self::ensure_registry_for_post_type( $post_type );

		$lang = isset( $_GET['lw_admin_lang'] ) ? sanitize_key( wp_unslash( $_GET['lw_admin_lang'] ) ) : Leadwerk_Translation_API::get_admin_language();

		/* "all" shows every post regardless of language */
		if ( 'all' === $lang ) {
			return;
		}

		$query->set( 'leadwerk_lang_filter', $lang );
		$query->set( 'leadwerk_element_type', Leadwerk_Translation_API::get_post_element_type( $post_type ) );
		
		add_filter( 'posts_join', array( __CLASS__, 'filter_admin_list_join' ), 10, 2 );
	}

	/**
	 * Join the elements table for admin list filtering.
	 *
	 * @param string   $join  Join string.
	 * @param WP_Query $query Query object.
	 * @return string
	 */
	public static function filter_admin_list_join( $join, $query ) {
		$lang         = $query->get( 'leadwerk_lang_filter' );
		$element_type = $query->get( 'leadwerk_element_type' );

		if ( ! $lang || ! $element_type ) {
			return $join;
		}

		global $wpdb;
		$table = Leadwerk_Translation_API::get_elements_table_name();
		
		$join .= " INNER JOIN {$table} AS lw_el ON {$wpdb->posts}.ID = lw_el.element_id AND lw_el.element_type = '" . esc_sql( $element_type ) . "' AND lw_el.language_code = '" . esc_sql( $lang ) . "'";

		return $join;
	}

	/**
	 * Sync translation statuses when a source post changes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public static function maybe_sync_source_post( $post_id, $post, $update ) {
		if ( ! $update || ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || ! Leadwerk_Translation_API::is_translatable_post_type( $post->post_type ) ) {
			return;
		}

		if ( Leadwerk_Translation_API::get_default_language() !== Leadwerk_Translation_API::get_post_language( $post_id ) ) {
			return;
		}

		Leadwerk_Translation_API::ensure_post_record( $post_id );
		Leadwerk_Translation_Sync::sync_all_from_source( $post_id );
	}

	/**
	 * Localize attachment alt/title attributes on the front-end.
	 *
	 * @param array<string,string> $attr       Attributes.
	 * @param WP_Post              $attachment Attachment post.
	 * @return array<string,string>
	 */
	public static function filter_attachment_image_attributes( $attr, $attachment ) {
		if ( is_admin() || ! $attachment instanceof WP_Post ) {
			return $attr;
		}

		$attr['alt'] = Leadwerk_Translation_API::get_localized_attachment_meta( $attachment->ID, '_wp_attachment_image_alt', null, $attr['alt'] ?? '' );
		return $attr;
	}

	/**
	 * Create a translation target post if it does not exist yet.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Target language.
	 * @return int
	 */
	protected static function create_translation_post( $source_id, $lang ) {
		$source = get_post( $source_id );
		if ( ! $source instanceof WP_Post ) {
			return 0;
		}

		$source_record = Leadwerk_Translation_API::ensure_post_record( $source_id );

		$existing = Leadwerk_Translation_API::get_translation( $source_id, $lang );
		if ( $existing ) {
			return $existing;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $source->post_type,
				'post_status'  => 'draft',
				'post_title'   => $source->post_title,
				'post_content' => $source->post_content,
				'post_excerpt' => $source->post_excerpt,
				'post_name'    => ! empty( $source_record['is_home'] ) ? 'home-' . $lang : sanitize_title( $source->post_name . '-' . $lang ),
			)
		);

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, 'leadwerk_source_key', get_post_meta( $source_id, 'leadwerk_source_key', true ) );
		update_post_meta( $post_id, 'leadwerk_source_file', get_post_meta( $source_id, 'leadwerk_source_file', true ) );

		$body_class = self::resolve_expected_body_class_for_post( $source_id );
		if ( '' !== $body_class ) {
			update_post_meta( $post_id, 'leadwerk_body_class', $body_class );
		}

		Leadwerk_Translation_API::link_posts(
			$source_id,
			$post_id,
			$lang,
			! empty( $source_record['is_home'] ),
			array(
				'source_key'        => get_post_meta( $source_id, 'leadwerk_source_key', true ),
				'source_public_slug'=> (string) ( $source_record['public_slug'] ?? '' ),
				'source_is_home'    => ! empty( $source_record['is_home'] ),
				'public_slug'       => ! empty( $source_record['is_home'] ) ? '' : ( trim( (string) ( $source_record['public_slug'] ?? '' ), '/' ) ?: $source->post_name ),
				'status'            => 'not_translated',
				'is_home'           => ! empty( $source_record['is_home'] ),
			)
		);
		Leadwerk_Translation_Sync::sync_from_source( $source_id, $post_id, array() );
		if ( 'acm_news' === $source->post_type ) {
			Leadwerk_Translation_Sync::sync_acm_news_shared_from_source( $source_id, $post_id, true );
		}

		return (int) $post_id;
	}

	/**
	 * Run one idempotent body-class repair pass once per version.
	 *
	 * @return void
	 */
	public static function maybe_repair_finora_body_classes() {
		if ( self::STYLE_REPAIR_VERSION === (string) get_option( self::STYLE_REPAIR_OPTION, '' ) ) {
			return;
		}

		$summary = self::repair_finora_body_class_meta();
		if ( empty( $summary['missing_shell'] ) ) {
			update_option( self::STYLE_REPAIR_OPTION, self::STYLE_REPAIR_VERSION );
		}
	}

	/**
	 * Backfill canonical body classes for all importer-managed FINORA pages.
	 *
	 * @param bool $force Whether to bypass the stored repair version.
	 * @return array<string,int>
	 */
	protected static function repair_finora_body_class_meta( $force = false ) {
		$summary = array(
			'scanned'      => 0,
			'repaired'     => 0,
			'matched'      => 0,
			'missing_shell'=> 0,
		);

		if ( ! $force && self::STYLE_REPAIR_VERSION === (string) get_option( self::STYLE_REPAIR_OPTION, '' ) ) {
			return $summary;
		}

		$post_ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => 'leadwerk_source_key',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( (array) $post_ids as $post_id ) {
			$expected = self::resolve_expected_body_class_for_post( (int) $post_id );
			if ( '' === $expected ) {
				++$summary['missing_shell'];
				continue;
			}

			++$summary['scanned'];

			$current = self::normalize_body_class_string( (string) get_post_meta( (int) $post_id, 'leadwerk_body_class', true ) );
			if ( $current === $expected ) {
				++$summary['matched'];
				continue;
			}

			update_post_meta( (int) $post_id, 'leadwerk_body_class', $expected );
			++$summary['repaired'];
		}

		if ( $force || 0 === $summary['missing_shell'] ) {
			update_option( self::STYLE_REPAIR_OPTION, self::STYLE_REPAIR_VERSION );
		}

		return $summary;
	}

	/**
	 * Resolve the expected page body class from source meta or canonical shells.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected static function resolve_expected_body_class_for_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}

		$source_key = sanitize_key( (string) get_post_meta( $post_id, 'leadwerk_source_key', true ) );
		$source_id  = class_exists( 'Leadwerk_Translation_API' ) ? (int) Leadwerk_Translation_API::get_source_post_id( $post_id ) : 0;
		if ( '' === $source_key && $source_id > 0 ) {
			$source_key = sanitize_key( (string) get_post_meta( $source_id, 'leadwerk_source_key', true ) );
		}

		if ( $source_id > 0 && $source_id !== $post_id ) {
			$source_body_class = self::normalize_body_class_string( (string) get_post_meta( $source_id, 'leadwerk_body_class', true ) );
			if ( '' !== $source_body_class ) {
				return $source_body_class;
			}
		}

		if ( '' !== $source_key && function_exists( 'leadwerk_theme_get_source_template_body_class' ) ) {
			$shell_body_class = self::normalize_body_class_string( (string) leadwerk_theme_get_source_template_body_class( $source_key ) );
			if ( '' !== $shell_body_class ) {
				return $shell_body_class;
			}
		}

		return self::normalize_body_class_string( (string) get_post_meta( $post_id, 'leadwerk_body_class', true ) );
	}

	/**
	 * Normalize one body class string.
	 *
	 * @param string $body_class Body class string.
	 * @return string
	 */
	protected static function normalize_body_class_string( $body_class ) {
		$classes = preg_split( '/\s+/', trim( (string) $body_class ) );
		$classes = is_array( $classes ) ? $classes : array();
		$classes = array_map( 'sanitize_html_class', $classes );
		$classes = array_values( array_unique( array_filter( $classes ) ) );

		return implode( ' ', $classes );
	}

	/**
	 * Copy one DE menu into an EN menu translation.
	 *
	 * @param int    $source_menu_id Source menu term ID.
	 * @param string $lang           Target language.
	 * @return int
	 */
	protected static function copy_menu_translation( $source_menu_id, $lang ) {
		$source_menu = wp_get_nav_menu_object( $source_menu_id );
		if ( ! $source_menu ) {
			return 0;
		}

		$source_record = Leadwerk_Translation_API::upsert_element(
			Leadwerk_Translation_API::get_term_element_type( 'nav_menu' ),
			(int) $source_menu_id,
			array(
				'language_code' => 'de',
				'public_slug'   => $source_menu->slug,
				'internal_slug' => $source_menu->slug,
				'status'        => 'complete',
			)
		);

		global $wpdb;
		$existing = ! empty( $source_record['trid'] )
			? (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT element_id FROM " . Leadwerk_Translation_API::get_elements_table_name() . " WHERE trid = %s AND element_type = %s AND language_code = %s LIMIT 1",
					(string) $source_record['trid'],
					Leadwerk_Translation_API::get_term_element_type( 'nav_menu' ),
					$lang
				)
			)
			: 0;

		if ( $existing ) {
			return $existing;
		}

		$target_menu_id = wp_create_nav_menu( $source_menu->name . ' ' . strtoupper( $lang ) );
		if ( ! $target_menu_id || is_wp_error( $target_menu_id ) ) {
			return 0;
		}

		/* Map old item IDs to new item IDs to preserve parent/child hierarchy */
		$parent_map = array();

		foreach ( (array) wp_get_nav_menu_items( $source_menu_id ) as $item ) {
			$object_id = (int) $item->object_id;
			$item_url  = $item->url;

			if ( $object_id && in_array( $item->type, array( 'post_type', 'post_type_archive' ), true ) ) {
				$translated_object_id = Leadwerk_Translation_API::get_translation( $object_id, $lang );
				if ( $translated_object_id ) {
					$object_id = $translated_object_id;
					$item_url  = get_permalink( $translated_object_id );
				}
			}

			$parent_id = 0;
			if ( ! empty( $item->menu_item_parent ) && isset( $parent_map[ (int) $item->menu_item_parent ] ) ) {
				$parent_id = (int) $parent_map[ (int) $item->menu_item_parent ];
			}

			$new_item_id = wp_update_nav_menu_item(
				$target_menu_id,
				0,
				array(
					'menu-item-title'     => $item->title,
					'menu-item-url'       => $item_url,
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => $parent_id,
					'menu-item-type'      => $item->type,
					'menu-item-object'    => $item->object,
					'menu-item-object-id' => $object_id,
					'menu-item-position'  => $item->menu_order,
				)
			);

			if ( ! is_wp_error( $new_item_id ) ) {
				$parent_map[ (int) $item->ID ] = (int) $new_item_id;
			}
		}

		Leadwerk_Translation_API::upsert_element(
			Leadwerk_Translation_API::get_term_element_type( 'nav_menu' ),
			(int) $target_menu_id,
			array(
				'trid'                 => $source_record['trid'] ?? Leadwerk_Translation_API::generate_trid(),
				'language_code'        => $lang,
				'source_language_code' => 'de',
				'source_element_id'    => (int) $source_menu_id,
				'public_slug'          => sanitize_title( $source_menu->slug . '-' . $lang ),
				'internal_slug'        => sanitize_title( $source_menu->slug . '-' . $lang ),
				'status'               => 'complete',
			)
		);

		return (int) $target_menu_id;
	}

	/**
	 * Collect all source-language posts for the dashboard.
	 *
	 * @return int[]
	 */
	protected static function get_dashboard_source_ids() {
		global $wpdb;

		foreach ( Leadwerk_Translation_API::get_settings()['translatable_post_types'] as $post_type ) {
			self::ensure_registry_for_post_type( $post_type );
		}

		$element_types = array_map( array( 'Leadwerk_Translation_API', 'get_post_element_type' ), Leadwerk_Translation_API::get_settings()['translatable_post_types'] );
		if ( empty( $element_types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $element_types ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT element_id FROM " . Leadwerk_Translation_API::get_elements_table_name() . " WHERE language_code = %s AND source_language_code = '' AND element_type IN ({$placeholders}) ORDER BY element_type, element_id",
			array_merge( array( Leadwerk_Translation_API::get_default_language() ), $element_types )
		);

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Convert a status key into a human label.
	 *
	 * @param string $status Internal status.
	 * @return string
	 */
	protected static function status_label( $status ) {
		switch ( sanitize_key( (string) $status ) ) {
			case 'blocked':
				return 'Blocked';
			case 'needs_update':
			case 'outdated':
				return 'Needs update';
			case 'in_progress':
				return 'In progress';
			case 'not_translated':
			case 'needs_translation':
				return 'Not translated';
			case 'complete':
			default:
				return 'Complete';
		}
	}

	/**
	 * Convert a WordPress post status into an admin label.
	 *
	 * @param string $post_status WordPress post status.
	 * @return string
	 */
	protected static function post_state_label( $post_status ) {
		switch ( sanitize_key( (string) $post_status ) ) {
			case 'publish':
				return 'Published';
			case 'draft':
				return 'Draft';
			case 'pending':
				return 'Pending';
			case 'private':
				return 'Private';
			case 'future':
				return 'Scheduled';
			default:
				return ucfirst( (string) $post_status );
		}
	}

	/**
	 * Shared inline admin CSS.
	 *
	 * @return void
	 */
	protected static function render_inline_styles() {
		echo <<<'INLINE'
<style>
.leadwerk-translation-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:18px 0}
.leadwerk-toolbar-group{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.leadwerk-toolbar-group--start{margin-right:auto}
.leadwerk-toolbar-toggle{display:inline-flex;align-items:center;gap:8px;font-weight:600;color:#1f2937}
.leadwerk-toolbar-toggle input{margin:0}
.leadwerk-amp-status,.leadwerk-bulk-status{font-size:12px;color:#667085;min-height:18px}
.leadwerk-bulk-stop[hidden]{display:none !important}
.leadwerk-submit-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.leadwerk-page-settings-grid{grid-template-columns:repeat(2,minmax(260px,1fr));align-items:start}
.leadwerk-page-settings-field{display:grid;gap:8px}
.leadwerk-page-settings-textarea{min-height:74px;resize:vertical}
.leadwerk-page-settings-label{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#667085}
.leadwerk-page-settings-note{grid-column:1 / -1;margin:0;color:#667085}
.leadwerk-translation-card{margin:18px 0;background:#fff;border:1px solid #d0d7de;border-radius:10px;overflow:hidden}
.leadwerk-translation-card__head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #e5e7eb;background:#f6f8fa}
.leadwerk-translation-card__head h2{margin:0;font-size:17px}
.leadwerk-translation-card__tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.leadwerk-translation-card__count{color:#667085}
.leadwerk-translation-card__body{padding:18px;display:grid;gap:14px}
.leadwerk-translation-segment{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
.leadwerk-translation-segment[hidden],.leadwerk-translation-card[hidden],.leadwerk-string-row[hidden]{display:none !important}
.leadwerk-translation-segment__source,.leadwerk-translation-segment__target{display:grid;gap:8px}
.leadwerk-translation-segment__target-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.leadwerk-icon-button{display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding-inline:10px}
.leadwerk-icon-button .dashicons{font-size:16px;width:16px;height:16px}
.leadwerk-string-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-bottom:8px}
.leadwerk-translation-segment__lang{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#667085}
.leadwerk-status-badge{display:inline-flex;align-items:center;width:max-content;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:600}
.leadwerk-status-badge--complete{background:#dcfce7;color:#166534}
.leadwerk-status-badge--blocked{background:#fde68a;color:#7c2d12}
.leadwerk-status-badge--outdated,.leadwerk-status-badge--needs_update{background:#fef3c7;color:#92400e}
.leadwerk-status-badge--needs_translation,.leadwerk-status-badge--not_translated{background:#fee2e2;color:#991b1b}
.leadwerk-status-badge--in_progress{background:#dbeafe;color:#1d4ed8}
.leadwerk-state-badge{display:inline-flex;align-items:center;width:max-content;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:600}
.leadwerk-state-badge--publish{background:#dcfce7;color:#166534}
.leadwerk-state-badge--draft{background:#e5e7eb;color:#374151}
.leadwerk-state-badge--pending,.leadwerk-state-badge--future{background:#dbeafe;color:#1d4ed8}
.leadwerk-state-badge--private{background:#f3e8ff;color:#6b21a8}
@media (max-width:960px){.leadwerk-translation-segment,.leadwerk-page-settings-grid{grid-template-columns:1fr}}
</style>
<script>
(function(){
	var DEEPL_ICON_SELECTOR = '[data-qa="input-translation-icon"], .dl-icon-circle.dl-icon[data-qa="input-translation-icon"], .dl-input-placeholder [data-tooltip*="Ctrl+Shift+Y"], .dl-input-placeholder [data-tooltip*="Translate"], .dl-input-placeholder [data-tooltip*="bersetzen"], .dl-input-placeholder [data-tooltip*="Übersetzen"]';
	var DEEPL_ICON_TRIGGER_SELECTOR = '[data-qa="input-translation-icon"], .dl-icon-circle.dl-icon[data-qa="input-translation-icon"]';
	var DEEPL_ICON_TOOLTIP_SELECTOR = '[data-tooltip*="Ctrl+Shift+Y"], [data-tooltip*="Translate"], [data-tooltip*="bersetzen"], [data-tooltip*="Übersetzen"]';
	var DEEPL_OVERLAY_SELECTOR = '.dl-input-positioner, .dl-input-placeholder, .dl-input-translation-container';
	var DEEPL_LOADING_SELECTOR = '.dl-loading';
	var DEEPL_LENGTH_LIMIT = 1500;
	var DEEPL_ICON_WAIT_MS = 2000;
	var DEEPL_TRANSLATION_WAIT_MS = 8000;
	var DEEPL_SETTLE_WAIT_MS = 800;
	var DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER = 3;
	var DEEPL_HUMAN_PRE_ACTION_MIN_MS = 280;
	var DEEPL_HUMAN_PRE_ACTION_MAX_MS = 620;
	var DEEPL_HUMAN_RETRY_MIN_MS = 380;
	var DEEPL_HUMAN_RETRY_MAX_MS = 780;
	var DEEPL_HUMAN_BETWEEN_ITEMS_MIN_MS = 900;
	var DEEPL_HUMAN_BETWEEN_ITEMS_MAX_MS = 1700;
	var DEEPL_SAFE_BATCH_SIZE = 2;
	var DEEPL_SAFE_BATCH_COOLDOWN_MIN_MS = 12000;
	var DEEPL_SAFE_BATCH_COOLDOWN_MAX_MS = 24000;
	var DEEPL_SAFE_BATCH_FAILURE_COOLDOWN_MIN_MS = 25000;
	var DEEPL_SAFE_BATCH_FAILURE_COOLDOWN_MAX_MS = 45000;
	var DEEPL_SAFE_BATCH_RESUME_KEY = 'leadwerkBulkDeepLResumeV5';
	var DEEPL_SAFE_BATCH_RESUME_MAX_AGE_MS = 1800000;
	var bulkState = {
		active: false,
		stopRequested: false,
		target: '',
		form: null,
		runId: 0,
		reloadPending: false,
		finalOverrideMessage: ''
	};
	var bulkAutomationState = window.leadwerkBulkDeepLState && 'object' === typeof window.leadwerkBulkDeepLState
		? window.leadwerkBulkDeepLState
		: {};
	var bulkAutomationFieldCounter = parseInt(bulkAutomationState.fieldCounter || 0, 10) || 0;
	window.leadwerkBulkDeepLState = bulkAutomationState;

	function clonePlainObject(value){
		if (!value || 'object' !== typeof value) return null;
		try {
			return JSON.parse(JSON.stringify(value));
		} catch (error) {
			return null;
		}
	}

	function createEmptyBulkCounts(){
		return {
			processed: 0,
			translated: 0,
			skipped_filled: 0,
			skipped_too_long: 0,
			skipped_no_icon: 0,
			click_failed: 0,
			hotkey_used: 0,
			hotkey_failed: 0,
			timed_out: 0,
			failed: 0
		};
	}

	function normalizeBulkCounts(counts){
		var normalized = createEmptyBulkCounts();
		var input = counts && 'object' === typeof counts ? counts : {};
		Object.keys(normalized).forEach(function(key){
			normalized[key] = parseInt(input[key] || 0, 10) || 0;
		});
		return normalized;
	}

	function initializeBulkAutomationState(){
		bulkAutomationState.version = 2;
		bulkAutomationState.mode = 'js_only';
		bulkAutomationState.activeRun = !!bulkAutomationState.activeRun;
		bulkAutomationState.target = bulkAutomationState.target || '';
		bulkAutomationState.currentItem = clonePlainObject(bulkAutomationState.currentItem);
		bulkAutomationState.lastResult = clonePlainObject(bulkAutomationState.lastResult);
		bulkAutomationState.lastSummary = clonePlainObject(bulkAutomationState.lastSummary);
		bulkAutomationState.requestCounter = parseInt(bulkAutomationState.requestCounter || 0, 10) || 0;
		bulkAutomationState.fieldCounter = bulkAutomationFieldCounter;
		bulkAutomationState.getSnapshot = function(){
			return {
				version: bulkAutomationState.version,
				mode: bulkAutomationState.mode,
				activeRun: !!bulkAutomationState.activeRun,
				target: bulkAutomationState.target || '',
				currentItem: clonePlainObject(bulkAutomationState.currentItem),
				lastResult: clonePlainObject(bulkAutomationState.lastResult),
				lastSummary: clonePlainObject(bulkAutomationState.lastSummary)
			};
		};
	}

	initializeBulkAutomationState();

	function getBulkResumePageKey(){
		return window.location.pathname + window.location.search;
	}

	function readBulkResumeState(){
		try {
			var raw = window.sessionStorage ? window.sessionStorage.getItem(DEEPL_SAFE_BATCH_RESUME_KEY) : '';
			if (!raw) {
				return null;
			}
			var parsed = JSON.parse(raw);
			if (!parsed || 'object' !== typeof parsed) {
				return null;
			}
			if (parsed.pageKey !== getBulkResumePageKey()) {
				return null;
			}
			if ((Date.now() - (parseInt(parsed.createdAt || 0, 10) || 0)) > DEEPL_SAFE_BATCH_RESUME_MAX_AGE_MS) {
				return null;
			}
			parsed.savedCount = Math.max(0, parseInt(parsed.savedCount || 0, 10) || 0);
			return parsed;
		} catch (error) {
			return null;
		}
	}

	function persistBulkResumeState(state){
		try {
			if (!window.sessionStorage) {
				return false;
			}
			window.sessionStorage.setItem(DEEPL_SAFE_BATCH_RESUME_KEY, JSON.stringify(state));
			return true;
		} catch (error) {
			return false;
		}
	}

	function clearBulkResumeState(){
		try {
			if (window.sessionStorage) {
				window.sessionStorage.removeItem(DEEPL_SAFE_BATCH_RESUME_KEY);
			}
		} catch (error) {}
	}

	function getBulkFormByTarget(target){
		if ('strings' === target) {
			return document.querySelector('.leadwerk-string-translation-form');
		}
		if ('segments' === target) {
			return document.querySelector('.leadwerk-translation-form');
		}
		return null;
	}

	function buildBulkResumeState(target, savedCount){
		return {
			version: 5,
			pageKey: getBulkResumePageKey(),
			target: target || '',
			savedCount: Math.max(0, parseInt(savedCount || 0, 10) || 0),
			resetDueToFailure: arguments.length > 2 ? !!arguments[2] : false,
			createdAt: Date.now()
		};
	}

	function getAutoSaveSubmitterData(form){
		if (!form) {
			return null;
		}
		var submitter = null;
		if (form.classList && form.classList.contains('leadwerk-translation-form')) {
			submitter = form.querySelector('button[type="submit"][name="leadwerk_editor_action"][value="save_translation"]')
				|| form.querySelector('button[type="submit"][name="leadwerk_editor_action"][value="save_draft"]')
				|| form.querySelector('button[type="submit"][name="leadwerk_editor_action"]');
		}
		submitter = submitter || form.querySelector('button[type="submit"]');
		if (!submitter) {
			return null;
		}
		return {
			element: submitter,
			name: submitter.name,
			value: submitter.value || ''
		};
	}

	function countBulkRemainingItems(form, target){
		var items = target === 'strings' ? buildStringBatchItems(form) : buildTranslationBatchItems(form);
		return items.reduce(function(total, item){
			if (!item || !item.targetField || !item.sourceField || !item.targetField.matches('textarea')) {
				return total;
			}
			return isFilledField(item.targetField) ? total : total + 1;
		}, 0);
	}

	function submitBulkForm(form){
		var submitterData = getAutoSaveSubmitterData(form);
		var tempSubmitterInput = null;
		if (submitterData && submitterData.element && 'function' === typeof form.requestSubmit) {
			form.requestSubmit(submitterData.element);
			return true;
		}
		if (submitterData && submitterData.name) {
			tempSubmitterInput = document.createElement('input');
			tempSubmitterInput.type = 'hidden';
			tempSubmitterInput.name = submitterData.name;
			tempSubmitterInput.value = submitterData.value || '';
			form.appendChild(tempSubmitterInput);
		}
		if ('function' === typeof form.submit) {
			form.submit();
			return true;
		}
		if (tempSubmitterInput && tempSubmitterInput.parentNode) {
			tempSubmitterInput.parentNode.removeChild(tempSubmitterInput);
		}
		throw new Error('save_unavailable');
	}

	function collectSegments(scope){
		return Array.prototype.slice.call(scope.querySelectorAll('.leadwerk-translation-segment'));
	}

	function hasOverwriteRisk(segments){
		return segments.some(function(segment){
			var sourceField = segment.querySelector('.leadwerk-translation-source-raw');
			var targetField = segment.querySelector('.leadwerk-translation-target');
			if (!sourceField || !targetField) return false;
			var sv = sourceField.value || '';
			var tv = targetField.value || '';
			return tv.trim() !== '' && tv !== sv;
		});
	}

	function hasFilledTargets(segments){
		return segments.some(function(segment){
			var targetField = segment.querySelector('.leadwerk-translation-target');
			return !!targetField && (targetField.value || '').trim() !== '';
		});
	}

	function updateBadge(segment, value){
		var badge = segment.querySelector('.leadwerk-status-badge');
		if (!badge) return;
		badge.classList.remove('leadwerk-status-badge--complete','leadwerk-status-badge--needs_translation','leadwerk-status-badge--not_translated','leadwerk-status-badge--in_progress','leadwerk-status-badge--outdated','leadwerk-status-badge--needs_update');
		if ((value || '').trim() === '') {
			badge.classList.add('leadwerk-status-badge--needs_translation');
			badge.textContent = 'Needs Translation';
			return;
		}
		badge.classList.add('leadwerk-status-badge--complete');
		badge.textContent = 'Complete';
	}

	function updateStringBadge(row, value){
		var badge = row.querySelector('.leadwerk-status-badge');
		if (!badge) return;
		badge.classList.remove('leadwerk-status-badge--complete','leadwerk-status-badge--needs_translation','leadwerk-status-badge--not_translated','leadwerk-status-badge--in_progress','leadwerk-status-badge--outdated','leadwerk-status-badge--needs_update');
		if ((value || '').trim() === '') {
			badge.classList.add('leadwerk-status-badge--not_translated');
			badge.textContent = 'Not translated';
			return;
		}
		badge.classList.add('leadwerk-status-badge--complete');
		badge.textContent = 'Complete';
	}

	function isCompletedBadge(badge){
		return !!badge && badge.classList.contains('leadwerk-status-badge--complete');
	}

	function applySegmentFilter(form){
		if (!form) return;
		var toggle = form.querySelector('.leadwerk-hide-completed-toggle[data-target="segments"]');
		var hideCompleted = !!toggle && !!toggle.checked;
		Array.prototype.slice.call(form.querySelectorAll('.leadwerk-translation-card')).forEach(function(card){
			var visibleCount = 0;
			collectSegments(card).forEach(function(segment){
				var hideSegment = hideCompleted && isCompletedBadge(segment.querySelector('.leadwerk-status-badge'));
				segment.hidden = hideSegment;
				if (!hideSegment) {
					visibleCount += 1;
				}
			});
			card.hidden = hideCompleted && visibleCount === 0;
		});
	}

	function applyStringFilter(form){
		if (!form) return;
		var toggle = form.querySelector('.leadwerk-hide-completed-toggle[data-target="strings"]');
		var hideCompleted = !!toggle && !!toggle.checked;
		Array.prototype.slice.call(form.querySelectorAll('.leadwerk-string-row')).forEach(function(row){
			row.hidden = hideCompleted && isCompletedBadge(row.querySelector('.leadwerk-status-badge'));
		});
	}

	function copySegments(segments){
		segments.forEach(function(segment){
			var sourceField = segment.querySelector('.leadwerk-translation-source-raw');
			var targetField = segment.querySelector('.leadwerk-translation-target');
			if (!sourceField || !targetField) return;
			targetField.value = sourceField.value || '';
			updateBadge(segment, targetField.value);
		});
	}

	function clearSegments(segments){
		segments.forEach(function(segment){
			var targetField = segment.querySelector('.leadwerk-translation-target');
			if (!targetField) return;
			targetField.value = '';
			updateBadge(segment, targetField.value);
		});
	}

	function copyStringRow(row){
		var sourceField = row.querySelector('.leadwerk-string-source-raw');
		var targetField = row.querySelector('.leadwerk-string-target');
		if (!sourceField || !targetField) return;
		targetField.value = sourceField.value || '';
		updateStringBadge(row, targetField.value);
	}

	function clearStringRow(row){
		var targetField = row.querySelector('.leadwerk-string-target');
		if (!targetField) return;
		targetField.value = '';
		updateStringBadge(row, targetField.value);
	}

	function getAmpStatus(form, target){
		if (!form) return null;
		return form.querySelector('.leadwerk-amp-status[data-target="' + target + '"]');
	}

	function setAmpStatus(form, target, message){
		var status = getAmpStatus(form, target);
		if (!status) return;
		status.textContent = message || '';
	}

	function getAmpCleanupFields(form, target){
		if (!form) return [];
		if ('strings' === target) {
			return Array.prototype.slice.call(form.querySelectorAll('.leadwerk-string-target'));
		}
		return Array.prototype.slice.call(form.querySelectorAll('.leadwerk-translation-target, .leadwerk-page-settings-target-title, .leadwerk-page-settings-target-slug')).filter(function(field){
			return !!field && !field.readOnly && !field.disabled;
		});
	}

	function replaceAmpArtifacts(value){
		var raw = value || '';
		var matches = raw.match(/amp;/g);
		return {
			value: raw.replace(/amp;/g, ''),
			count: matches ? matches.length : 0
		};
	}

	function cleanAmpArtifacts(form, target){
		var fields = getAmpCleanupFields(form, target);
		var changedFields = 0;
		var removedCount = 0;
		var firstChangedField = null;

		fields.forEach(function(field){
			var result = replaceAmpArtifacts(field.value || '');
			if (result.count < 1) {
				return;
			}
			field.value = result.value;
			removedCount += result.count;
			changedFields += 1;
			if (!firstChangedField) {
				firstChangedField = field;
			}
			triggerFieldUpdate(field);
		});

		if (firstChangedField && typeof firstChangedField.focus === 'function') {
			firstChangedField.focus();
		}

		if (removedCount < 1) {
			setAmpStatus(form, target, 'No amp; found in editable text boxes.');
			return;
		}

		setAmpStatus(
			form,
			target,
			'Removed ' + removedCount + ' amp; occurrence' + (removedCount === 1 ? '' : 's') + ' in ' + changedFields + ' text box' + (changedFields === 1 ? '' : 'es') + '.'
		);
		refreshFilters(form);
	}

	function refreshFilters(scope){
		if (!scope) return;
		var translationForm = scope.classList && scope.classList.contains('leadwerk-translation-form') ? scope : scope.closest ? scope.closest('.leadwerk-translation-form') : null;
		var stringForm = scope.classList && scope.classList.contains('leadwerk-string-translation-form') ? scope : scope.closest ? scope.closest('.leadwerk-string-translation-form') : null;
		if (translationForm) {
			applySegmentFilter(translationForm);
		}
		if (stringForm) {
			applyStringFilter(stringForm);
		}
	}

	function triggerFieldUpdate(field){
		if (!field) return;
		field.dispatchEvent(new Event('input', { bubbles: true }));
		field.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function isFilledField(field){
		return !!field && (field.value || '').trim() !== '';
	}

	function normalizeValue(value){
		return (value || '').replace(/\s+/g, ' ').trim();
	}

	function ensureBulkAutomationFieldId(field){
		if (!field) return '';
		var currentId = field.getAttribute('data-leadwerk-deepl-id');
		if (currentId) {
			return currentId;
		}
		bulkAutomationFieldCounter += 1;
		currentId = 'leadwerk-deepl-' + bulkAutomationFieldCounter;
		field.setAttribute('data-leadwerk-deepl-id', currentId);
		bulkAutomationState.fieldCounter = bulkAutomationFieldCounter;
		return currentId;
	}

	function setBulkAutomationCurrentItem(item, baseline){
		bulkAutomationState.activeRun = true;
		bulkAutomationState.target = bulkState.target || '';
		bulkAutomationState.currentItem = {
			runId: bulkState.runId,
			label: item && item.label ? item.label : '',
			type: item && item.type ? item.type : '',
			targetId: ensureBulkAutomationFieldId(item ? item.targetField : null),
			baselineNormalized: normalizeValue(baseline),
			sourceLength: item && item.sourceField ? ((item.sourceField.value || '').length) : 0,
			startedAt: Date.now()
		};
		bulkAutomationState.lastResult = null;
	}

	function setBulkAutomationResult(status, detail){
		var currentItem = bulkAutomationState.currentItem || {};
		bulkAutomationState.lastResult = {
			runId: currentItem.runId || bulkState.runId,
			status: status || 'failed',
			detail: detail || '',
			reportedAt: Date.now()
		};
	}

	function clearAutomationHotkeyItem(finalStatus){
		if (bulkAutomationState.currentItem && finalStatus) {
			bulkAutomationState.currentItem.finalStatus = finalStatus;
		}
		bulkAutomationState.currentItem = null;
	}

	function finalizeAutomationRun(summary){
		bulkAutomationState.activeRun = false;
		bulkAutomationState.target = '';
		bulkAutomationState.currentItem = null;
		bulkAutomationState.lastSummary = clonePlainObject(summary);
	}

	function cleanupDeepLInteractionState(){
		var activeElement = document.activeElement;
		if (activeElement && typeof activeElement.selectionStart === 'number' && typeof activeElement.selectionEnd === 'number') {
			var valueLength = (activeElement.value || '').length;
			try {
				activeElement.setSelectionRange(valueLength, valueLength, 'forward');
			} catch (error) {}
		}
		if (activeElement && typeof activeElement.blur === 'function') {
			try {
				activeElement.blur();
			} catch (error) {}
		}
		bulkAutomationState.currentItem = null;
		bulkAutomationState.lastResult = null;
	}

	function pushUniqueElement(list, element){
		if (!element || !(element instanceof Element) || list.indexOf(element) !== -1) {
			return;
		}
		list.push(element);
	}

	function collectSearchRoots(){
		var roots = [];
		var queue = [document];
		var seen = [];
		while (queue.length) {
			var root = queue.shift();
			if (!root || seen.indexOf(root) !== -1) {
				continue;
			}
			seen.push(root);
			roots.push(root);
			if (!root.querySelectorAll) {
				continue;
			}
			Array.prototype.slice.call(root.querySelectorAll('*')).forEach(function(node){
				if (node && node.shadowRoot && seen.indexOf(node.shadowRoot) === -1) {
					queue.push(node.shadowRoot);
				}
			});
		}
		return roots;
	}

	function collectDeepLNodes(selector){
		var matches = [];
		collectSearchRoots().forEach(function(root){
			if (!root || !root.querySelectorAll) {
				return;
			}
			try {
				Array.prototype.slice.call(root.querySelectorAll(selector)).forEach(function(node){
					pushUniqueElement(matches, node);
				});
			} catch (error) {}
		});
		return matches;
	}

	function getBulkControls(form, target){
		if (!form) return null;
		return {
			button: form.querySelector('.leadwerk-bulk-deepl-button[data-target="' + target + '"]'),
			status: form.querySelector('.leadwerk-bulk-status[data-target="' + target + '"]'),
			stop: form.querySelector('.leadwerk-bulk-stop[data-target="' + target + '"]')
		};
	}

	function setBulkControls(form, target, active, message){
		var controls = getBulkControls(form, target);
		if (!controls) return;
		if (controls.button) {
			controls.button.disabled = !!active;
		}
		if (controls.stop) {
			controls.stop.hidden = !active;
			controls.stop.disabled = !active;
		}
		if (controls.status) {
			controls.status.textContent = message || '';
		}
	}

	function sleep(ms){
		return new Promise(function(resolve){
			window.setTimeout(resolve, ms);
		});
	}

	function randomBetween(min, max){
		min = parseInt(min, 10) || 0;
		max = parseInt(max, 10) || min;
		if (max < min) {
			var swap = min;
			min = max;
			max = swap;
		}
		return min + Math.floor(Math.random() * ((max - min) + 1));
	}

	function getHumanLengthBonusMs(sourceLength){
		var length = parseInt(sourceLength || 0, 10) || 0;
		if (length < 280) return 0;
		if (length < 700) return randomBetween(120, 320);
		if (length < 1100) return randomBetween(280, 620);
		return randomBetween(520, 980);
	}

	function getHumanFailureBackoffMs(result){
		var status = result && result.status ? result.status : '';
		if (!status || 'translated' === status || 'too_long' === status) {
			return 0;
		}
		if ('timed_out' === status) {
			return randomBetween(2600, 4200);
		}
		if ('hotkey_failed' === status || 'failed' === status) {
			return randomBetween(1800, 3200);
		}
		if ('no_icon' === status) {
			return randomBetween(1000, 1800);
		}
		return 0;
	}

	async function humanPause(minMs, maxMs, extraMs, scrollSnapshot){
		var duration = randomBetween(minMs, maxMs) + Math.max(0, parseInt(extraMs || 0, 10) || 0);
		stabilizeScrollPosition(scrollSnapshot);
		await sleep(duration);
		stabilizeScrollPosition(scrollSnapshot);
		return duration;
	}

	function captureScrollPosition(){
		var scrollingElement = document.scrollingElement || document.documentElement || document.body;
		return {
			x: scrollingElement ? scrollingElement.scrollLeft : (window.pageXOffset || window.scrollX || 0),
			y: scrollingElement ? scrollingElement.scrollTop : (window.pageYOffset || window.scrollY || 0)
		};
	}

	function restoreScrollPosition(snapshot){
		if (!snapshot) return;
		var scrollingElement = document.scrollingElement || document.documentElement || document.body;
		if (scrollingElement) {
			scrollingElement.scrollLeft = snapshot.x;
			scrollingElement.scrollTop = snapshot.y;
		}
		if (typeof window.scrollTo === 'function') {
			window.scrollTo(snapshot.x, snapshot.y);
		}
	}

	function stabilizeScrollPosition(snapshot){
		if (!snapshot) return;
		restoreScrollPosition(snapshot);
		window.setTimeout(function(){ restoreScrollPosition(snapshot); }, 40);
		window.setTimeout(function(){ restoreScrollPosition(snapshot); }, 160);
		window.setTimeout(function(){ restoreScrollPosition(snapshot); }, 320);
	}

	function isVisibleElement(element){
		if (!element || !(element instanceof Element)) return false;
		if (element.hidden) return false;
		if (element.getAttribute('aria-hidden') === 'true') return false;
		var style = window.getComputedStyle(element);
		if (!style || style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity || '1') === 0) return false;
		return element.getClientRects().length > 0;
	}

	function getDistanceBetween(rectA, rectB){
		var ax = rectA.left + (rectA.width / 2);
		var ay = rectA.top + (rectA.height / 2);
		var bx = rectB.left + (rectB.width / 2);
		var by = rectB.top + (rectB.height / 2);
		return Math.abs(ax - bx) + Math.abs(ay - by);
	}

	function rectsVerticallyOverlap(rectA, rectB){
		return !!rectA && !!rectB && rectA.bottom >= rectB.top && rectB.bottom >= rectA.top;
	}

	function getDeepLOverlayScore(fieldRect, overlayRect){
		var desiredX = fieldRect.left + (fieldRect.width * 0.8);
		var overlayCenterX = overlayRect.left + (overlayRect.width / 2);
		var score = Math.abs((fieldRect.top + (fieldRect.height / 2)) - (overlayRect.top + (overlayRect.height / 2)));
		score += Math.abs(desiredX - overlayCenterX);
		if (!rectsVerticallyOverlap(fieldRect, overlayRect)) {
			score += 2500;
		}
		if (overlayRect.left < (fieldRect.left + (fieldRect.width * 0.45))) {
			score += 500;
		}
		return score;
	}

	function getTooltipText(node){
		if (!node || !(node instanceof Element)) return '';
		if (node.getAttribute && node.getAttribute('data-tooltip')) {
			return node.getAttribute('data-tooltip') || '';
		}
		var tooltipOwner = node.closest ? node.closest('[data-tooltip]') : null;
		return tooltipOwner ? (tooltipOwner.getAttribute('data-tooltip') || '') : '';
	}

	function isTranslateTooltipText(text){
		return /ctrl\s*\+\s*shift\s*\+\s*y|translate|ubersetzen|[\u00dc\u00fc]bersetzen/i.test(text || '');
	}

	function normalizeDeepLOverlayNode(node){
		if (!node || !(node instanceof Element)) return null;
		if (node.matches && node.matches('.dl-input-positioner')) {
			return node;
		}
		if (node.closest) {
			var closestPositioner = node.closest('.dl-input-positioner');
			if (closestPositioner) {
				return closestPositioner;
			}
			var closestContainer = node.closest('.dl-input-translation-container');
			if (closestContainer) {
				return closestContainer.querySelector('.dl-input-positioner') || closestContainer;
			}
			var closestPlaceholder = node.closest('.dl-input-placeholder');
			if (closestPlaceholder) {
				return closestPlaceholder.closest('.dl-input-positioner') || closestPlaceholder;
			}
		}
		return null;
	}

	function normalizeDeepLIconNode(node){
		if (!node || !(node instanceof Element)) return null;
		if (node.matches && node.matches(DEEPL_ICON_TRIGGER_SELECTOR)) {
			return node;
		}
		var directMatch = node.closest ? node.closest(DEEPL_ICON_TRIGGER_SELECTOR) : null;
		if (directMatch) {
			return directMatch;
		}
		var tooltipOwner = node.matches && node.matches(DEEPL_ICON_TOOLTIP_SELECTOR)
			? node
			: (node.closest ? node.closest(DEEPL_ICON_TOOLTIP_SELECTOR) : null);
		if (!tooltipOwner || !isTranslateTooltipText(getTooltipText(tooltipOwner))) {
			return null;
		}
		if (tooltipOwner.closest) {
			var tooltipIcon = tooltipOwner.closest(DEEPL_ICON_TRIGGER_SELECTOR);
			if (tooltipIcon) {
				return tooltipIcon;
			}
			var fallbackIcon = tooltipOwner.closest('.dl-icon-circle.dl-icon');
			if (fallbackIcon) {
				return fallbackIcon;
			}
		}
		return tooltipOwner.parentElement && tooltipOwner.parentElement.matches('.dl-icon-circle.dl-icon')
			? tooltipOwner.parentElement
			: null;
	}

	function collectOverlayIcons(overlay){
		var icons = [];
		if (!overlay || !overlay.querySelectorAll) {
			return icons;
		}
		Array.prototype.slice.call(overlay.querySelectorAll(DEEPL_ICON_SELECTOR + ', ' + DEEPL_ICON_TOOLTIP_SELECTOR)).forEach(function(node){
			var icon = normalizeDeepLIconNode(node);
			if (!icon || !isVisibleElement(icon)) {
				return;
			}
			pushUniqueElement(icons, icon);
		});
		return icons;
	}

	function findDeepLTargets(field){
		if (!field) return [];
		var fieldRect = field.getBoundingClientRect();
		var matches = [];
		var seenOverlays = [];
		var seenIcons = [];

		collectDeepLNodes(DEEPL_OVERLAY_SELECTOR).forEach(function(node){
			var overlay = normalizeDeepLOverlayNode(node) || node;
			if (!overlay || !isVisibleElement(overlay) || seenOverlays.indexOf(overlay) !== -1) {
				return;
			}
			seenOverlays.push(overlay);
			var overlayRect = overlay.getBoundingClientRect();
			collectOverlayIcons(overlay).forEach(function(icon){
				if (seenIcons.indexOf(icon) !== -1) {
					return;
				}
				seenIcons.push(icon);
				matches.push({
					overlay: overlay,
					icon: icon,
					score: getDeepLOverlayScore(fieldRect, overlayRect)
				});
			});
		});

		collectDeepLNodes(DEEPL_ICON_SELECTOR + ', ' + DEEPL_ICON_TOOLTIP_SELECTOR).forEach(function(node){
			var icon = normalizeDeepLIconNode(node);
			if (!icon || !isVisibleElement(icon) || seenIcons.indexOf(icon) !== -1) {
				return;
			}
			var overlay = normalizeDeepLOverlayNode(icon);
			var targetRect = overlay ? overlay.getBoundingClientRect() : icon.getBoundingClientRect();
			seenIcons.push(icon);
			matches.push({
				overlay: overlay,
				icon: icon,
				score: overlay
					? getDeepLOverlayScore(fieldRect, targetRect)
					: (getDistanceBetween(fieldRect, targetRect) + 1200)
			});
		});

		matches.sort(function(a, b){
			return a.score - b.score;
		});
		if (!matches.length) {
			return matches;
		}
		var bestScore = matches[0].score;
		return matches.filter(function(match, index){
			return 0 === index || match.score <= (bestScore + 900);
		}).slice(0, 2);
	}

	function findDeepLTarget(field){
		var matches = findDeepLTargets(field);
		return matches.length ? matches[0] : null;
	}

	async function waitForDeepLTarget(field, timeoutMs){
		var startedAt = Date.now();
		while ((Date.now() - startedAt) < timeoutMs) {
			var target = findDeepLTarget(field);
			if (target && target.icon) {
				return target;
			}
			await sleep(150);
		}
		return null;
	}

	function getFieldClickPoint(field){
		var rect = field.getBoundingClientRect();
		return {
			clientX: Math.max(rect.left + 24, rect.right - 28),
			clientY: Math.max(rect.top + 18, rect.bottom - 18)
		};
	}

	function isFieldComfortablyVisible(field){
		if (!field) return false;
		var rect = field.getBoundingClientRect();
		var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
		return rect.top >= 100 && rect.bottom <= Math.max(140, viewportHeight - 80);
	}

	function scrollFieldIntoViewForDeepL(field){
		if (!field) return;
		if (isFieldComfortablyVisible(field)) {
			return;
		}
		field.scrollIntoView({ block: 'center', inline: 'nearest' });
	}

	function getElementCenterPoint(element){
		var rect = element.getBoundingClientRect();
		return {
			clientX: rect.left + (rect.width / 2),
			clientY: rect.top + (rect.height / 2)
		};
	}

	function getDeepLActivityWaitMs(sourceLength){
		var length = parseInt(sourceLength || 0, 10) || 0;
		if (length < 280) return DEEPL_ICON_WAIT_MS;
		if (length < 700) return DEEPL_ICON_WAIT_MS + 1200;
		if (length < 1100) return DEEPL_ICON_WAIT_MS + 2400;
		return DEEPL_ICON_WAIT_MS + 3600;
	}

	function getDeepLTranslationWaitMs(sourceLength){
		var length = parseInt(sourceLength || 0, 10) || 0;
		if (length < 280) return DEEPL_TRANSLATION_WAIT_MS;
		if (length < 700) return DEEPL_TRANSLATION_WAIT_MS + 3000;
		if (length < 1100) return DEEPL_TRANSLATION_WAIT_MS + 5000;
		return DEEPL_TRANSLATION_WAIT_MS + 7000;
	}

	async function humanizeBeforeDeepLAction(field, sourceLength, scrollSnapshot){
		if (field) {
			scrollFieldIntoViewForDeepL(field);
		}
		await humanPause(
			DEEPL_HUMAN_PRE_ACTION_MIN_MS * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
			DEEPL_HUMAN_PRE_ACTION_MAX_MS * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
			Math.max(120, getHumanLengthBonusMs(sourceLength)),
			scrollSnapshot
		);
	}

	async function humanizeBetweenBulkItems(item, result, index, total){
		if (!item || index >= (total - 1)) {
			return;
		}
		var sourceLength = item && item.sourceField ? ((item.sourceField.value || '').length) : 0;
		var scrollSnapshot = item && item.targetField ? captureScrollPosition() : null;
		var extraMs = getHumanLengthBonusMs(sourceLength) + getHumanFailureBackoffMs(result);
		await humanPause(
			DEEPL_HUMAN_BETWEEN_ITEMS_MIN_MS,
			DEEPL_HUMAN_BETWEEN_ITEMS_MAX_MS,
			extraMs,
			scrollSnapshot
		);
	}

	function chunkBulkItems(items, chunkSize){
		var normalizedChunkSize = Math.max(1, parseInt(chunkSize || 0, 10) || 1);
		var chunks = [];
		for (var index = 0; index < items.length; index += normalizedChunkSize) {
			chunks.push(items.slice(index, index + normalizedChunkSize));
		}
		return chunks;
	}

	function shouldUseFailureTierCooldown(result){
		var status = result && result.status ? result.status : '';
		return 'timed_out' === status || 'hotkey_failed' === status || 'no_icon' === status || 'failed' === status;
	}

	function formatBulkProgressPrefix(batchInfo){
		if (!batchInfo) {
			return '';
		}
		return 'Batch ' + batchInfo.batchIndex + '/' + batchInfo.batchTotal + ' | item ' + batchInfo.batchItemIndex + '/' + batchInfo.batchItemTotal;
	}

	async function humanizeBetweenBatches(form, target, counts, batchInfo, useFailureTier, phaseLabel){
		var scrollSnapshot = captureScrollPosition();
		var minMs = useFailureTier ? DEEPL_SAFE_BATCH_FAILURE_COOLDOWN_MIN_MS : DEEPL_SAFE_BATCH_COOLDOWN_MIN_MS;
		var maxMs = useFailureTier ? DEEPL_SAFE_BATCH_FAILURE_COOLDOWN_MAX_MS : DEEPL_SAFE_BATCH_COOLDOWN_MAX_MS;
		var duration = randomBetween(minMs, maxMs);
		var remainingMs = duration;
		var prefix = formatBulkProgressPrefix(batchInfo);
		var countdownLabel = phaseLabel || 'cooling down';
		while (remainingMs > 0) {
			if (bulkState.stopRequested) {
				return true;
			}
			var remainingSeconds = Math.max(1, Math.ceil(remainingMs / 1000));
			var parts = [
				prefix,
				countdownLabel + ' ' + remainingSeconds + 's',
				'translated ' + counts.translated,
				'skipped filled ' + counts.skipped_filled,
				'too long ' + counts.skipped_too_long,
				'no icon ' + counts.skipped_no_icon,
				'click failed ' + counts.click_failed,
				'hotkey used ' + counts.hotkey_used,
				'hotkey failed ' + counts.hotkey_failed,
				'timed out ' + counts.timed_out
			].filter(Boolean);
			setBulkControls(form, target, true, parts.join(' | '));
			var stepMs = Math.min(400, remainingMs);
			stabilizeScrollPosition(scrollSnapshot);
			await sleep(stepMs);
			remainingMs -= stepMs;
		}
		stabilizeScrollPosition(scrollSnapshot);
		return !!bulkState.stopRequested;
	}

	async function saveAndReloadAfterBatch(form, target, counts, batchInfo, useFailureTier){
		var prefix = formatBulkProgressPrefix(batchInfo);
		cleanupDeepLInteractionState();
		if (!persistBulkResumeState(buildBulkResumeState(target, counts.processed, useFailureTier))) {
			throw new Error('resume_state_failed');
		}
		var phaseLabel = useFailureTier ? 'resetting widget in' : 'saving page in';
		var interrupted = await humanizeBetweenBatches(form, target, counts, batchInfo, useFailureTier, phaseLabel);
		if (interrupted) {
			clearBulkResumeState();
			return false;
		}
		bulkState.reloadPending = true;
		setBulkControls(form, target, true, [prefix, useFailureTier ? 'resetting widget now...' : 'saving page now...'].filter(Boolean).join(' | '));
		submitBulkForm(form);
		return true;
	}

	function showPendingBulkResumeMessage(){
		clearBulkResumeState();
	}

	function dispatchPointerSequence(target, point){
		if (!target) return;
		var base = {
			bubbles: true,
			cancelable: true,
			view: window,
			clientX: point.clientX,
			clientY: point.clientY,
			button: 0,
			buttons: 1
		};

		try {
			target.dispatchEvent(new PointerEvent('pointerover', base));
			target.dispatchEvent(new PointerEvent('pointerenter', base));
			target.dispatchEvent(new PointerEvent('pointermove', base));
			target.dispatchEvent(new PointerEvent('pointerdown', base));
			target.dispatchEvent(new PointerEvent('pointerup', base));
		} catch (error) {}

		target.dispatchEvent(new MouseEvent('mouseover', base));
		target.dispatchEvent(new MouseEvent('mouseenter', base));
		target.dispatchEvent(new MouseEvent('mousemove', base));
		target.dispatchEvent(new MouseEvent('mousedown', base));
		target.dispatchEvent(new MouseEvent('mouseup', base));
		target.dispatchEvent(new MouseEvent('click', base));
	}

	function moveCaretToEnd(field){
		if (!field) return;
		var valueLength = (field.value || '').length;
		try {
			field.focus({ preventScroll: true });
		} catch (error) {
			field.focus();
		}
		if (typeof field.setSelectionRange === 'function') {
			field.setSelectionRange(valueLength, valueLength, 'forward');
		}
		field.scrollTop = field.scrollHeight;
		field.scrollLeft = field.scrollWidth;
	}

	function selectAllFieldText(field){
		if (!field) return;
		var valueLength = (field.value || '').length;
		try {
			field.focus({ preventScroll: true });
		} catch (error) {
			field.focus();
		}
		if (typeof field.select === 'function') {
			field.select();
		}
		if (typeof field.setSelectionRange === 'function') {
			field.setSelectionRange(0, valueLength, 'forward');
		}
	}

	function hasFullFieldSelection(field){
		if (!field) return false;
		var valueLength = (field.value || '').length;
		if (typeof field.selectionStart !== 'number' || typeof field.selectionEnd !== 'number') {
			return false;
		}
		return document.activeElement === field && field.selectionStart === 0 && field.selectionEnd === valueLength;
	}

	async function primeFieldForDeepLTrigger(field, sourceLength, scrollSnapshot){
		if (!field) return false;
		scrollFieldIntoViewForDeepL(field);
		moveCaretToEnd(field);
		dispatchPointerSequence(field, getFieldClickPoint(field));
		stabilizeScrollPosition(scrollSnapshot);
		await humanPause(
			120 * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
			240 * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
			Math.min(220 * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER, getHumanLengthBonusMs(sourceLength) * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER),
			scrollSnapshot
		);
		selectAllFieldText(field);
		stabilizeScrollPosition(scrollSnapshot);
		await humanPause(
			220 * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
			420 * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
			Math.min(320 * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER, getHumanLengthBonusMs(sourceLength) * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER),
			scrollSnapshot
		);
		return hasFullFieldSelection(field);
	}

	function isFieldReadyForDeepL(field){
		if (!field) return false;
		var valueLength = (field.value || '').length;
		var activeMatches = document.activeElement === field;
		if (typeof field.selectionStart === 'number' && typeof field.selectionEnd === 'number') {
			return activeMatches && field.selectionStart === valueLength && field.selectionEnd === valueLength;
		}
		return activeMatches;
	}

	async function activateTargetFieldForDeepL(field){
		if (!field) return;
		for (var attempt = 0; attempt < 2; attempt += 1) {
			scrollFieldIntoViewForDeepL(field);
			moveCaretToEnd(field);
			dispatchPointerSequence(field, getFieldClickPoint(field));
			await sleep(180);
			moveCaretToEnd(field);
			dispatchPointerSequence(field, getFieldClickPoint(field));
			await sleep(180);
			if (isFieldReadyForDeepL(field)) {
				return true;
			}
		}
		return isFieldReadyForDeepL(field);
	}

	function hasTranslatedValue(field, baselineNormalized){
		var currentValue = field ? (field.value || '') : '';
		return normalizeValue(currentValue) !== baselineNormalized && normalizeValue(currentValue) !== '';
	}

	function isDeepLLoadingVisible(overlay){
		if (!overlay || !overlay.querySelector) return false;
		var loading = overlay.querySelector(DEEPL_LOADING_SELECTOR);
		return isVisibleElement(loading);
	}

	async function waitForTranslationActivity(field, baseline, overlay, timeoutMs){
		var baselineNormalized = normalizeValue(baseline);
		var startedAt = Date.now();
		while ((Date.now() - startedAt) < timeoutMs) {
			if (hasTranslatedValue(field, baselineNormalized)) {
				await sleep(DEEPL_SETTLE_WAIT_MS);
				return 'value_changed';
			}
			if (isDeepLLoadingVisible(overlay)) {
				return 'loading';
			}
			await sleep(150);
		}
		return '';
	}

	async function waitForTranslatedValue(field, baseline, timeoutMs){
		var baselineNormalized = normalizeValue(baseline);
		var startedAt = Date.now();
		while ((Date.now() - startedAt) < timeoutMs) {
			if (hasTranslatedValue(field, baselineNormalized)) {
				await sleep(DEEPL_SETTLE_WAIT_MS);
				return true;
			}
			await sleep(250);
		}
		return false;
	}

	function getElementClickPoints(element){
		if (!element) return [];
		var rect = element.getBoundingClientRect();
		if (!rect.width || !rect.height) {
			return [];
		}
		var centerX = rect.left + (rect.width / 2);
		var centerY = rect.top + (rect.height / 2);
		var offsetX = Math.max(3, Math.min(9, rect.width / 4));
		var offsetY = Math.max(3, Math.min(9, rect.height / 4));
		var points = [];
		[
			{ clientX: centerX, clientY: centerY },
			{ clientX: centerX - offsetX, clientY: centerY },
			{ clientX: centerX + offsetX, clientY: centerY },
			{ clientX: centerX, clientY: centerY - offsetY },
			{ clientX: centerX, clientY: centerY + offsetY }
		].forEach(function(point){
			if (point.clientX < 0 || point.clientY < 0) {
				return;
			}
			if (points.some(function(existing){
				return Math.abs(existing.clientX - point.clientX) < 1 && Math.abs(existing.clientY - point.clientY) < 1;
			})) {
				return;
			}
			points.push(point);
		});
		return points;
	}

	function getElementsFromPointSafe(point){
		if (!point) return [];
		if (document.elementsFromPoint) {
			return document.elementsFromPoint(point.clientX, point.clientY) || [];
		}
		var topmost = document.elementFromPoint(point.clientX, point.clientY);
		return topmost ? [topmost] : [];
	}

	function collectDeepLClickCandidates(icon){
		var candidates = [];
		if (!icon) {
			return candidates;
		}
		[
			normalizeDeepLIconNode(icon),
			icon,
			icon.firstElementChild,
			icon.querySelector ? icon.querySelector(DEEPL_ICON_TOOLTIP_SELECTOR) : null,
			icon.querySelector ? icon.querySelector('span') : null,
			icon.parentElement
		].forEach(function(node){
			if (!node) {
				return;
			}
			pushUniqueElement(candidates, normalizeDeepLIconNode(node) || node);
			if (node.closest) {
				var tooltipOwner = node.closest(DEEPL_ICON_TOOLTIP_SELECTOR);
				if (tooltipOwner && isTranslateTooltipText(getTooltipText(tooltipOwner))) {
					pushUniqueElement(candidates, tooltipOwner);
					pushUniqueElement(candidates, tooltipOwner.parentElement);
				}
			}
		});

		getElementClickPoints(icon).forEach(function(point){
			getElementsFromPointSafe(point).forEach(function(node){
				if (!node || !(node instanceof Element)) {
					return;
				}
				pushUniqueElement(candidates, normalizeDeepLIconNode(node) || node);
				if (node.closest) {
					var tooltipOwner = node.closest(DEEPL_ICON_TOOLTIP_SELECTOR);
					if (tooltipOwner && isTranslateTooltipText(getTooltipText(tooltipOwner))) {
						pushUniqueElement(candidates, tooltipOwner);
						pushUniqueElement(candidates, normalizeDeepLIconNode(tooltipOwner) || tooltipOwner.parentElement);
					}
				}
			});
		});

		return candidates.filter(function(candidate){
			return !!candidate && candidate instanceof Element && isVisibleElement(candidate);
		});
	}

	function performNativeClick(icon, scrollSnapshot){
		var target = normalizeDeepLIconNode(icon) || icon;
		if (!target || !isVisibleElement(target)) {
			return false;
		}
		var point = getElementCenterPoint(target);
		try {
			if (typeof target.focus === 'function') {
				target.focus({ preventScroll: true });
			}
		} catch (error) {
			try {
				target.focus();
			} catch (focusError) {}
		}
		try {
			if (typeof target.click === 'function') {
				target.click();
				stabilizeScrollPosition(scrollSnapshot);
				return true;
			}
		} catch (error) {}
		dispatchPointerSequence(target, point);
		stabilizeScrollPosition(scrollSnapshot);
		return true;
	}

	function buildKeyboardEvent(type, key, code, keyCode, modifiers){
		var init = {
			key: key,
			code: code,
			keyCode: keyCode,
			which: keyCode,
			bubbles: true,
			cancelable: true,
			ctrlKey: !!(modifiers && modifiers.ctrlKey),
			shiftKey: !!(modifiers && modifiers.shiftKey)
		};
		try {
			return new KeyboardEvent(type, init);
		} catch (error) {
			var event = document.createEvent('KeyboardEvent');
			event.initEvent(type, true, true);
			return event;
		}
	}

	function dispatchDeepLHotkey(field){
		return !!field && false;
	}

	function mergeDeepLTargets(primaryTarget, fallbackTargets){
		var merged = [];
		var seenIcons = [];
		[primaryTarget].concat(fallbackTargets || []).forEach(function(target){
			if (!target || !target.icon || seenIcons.indexOf(target.icon) !== -1) {
				return;
			}
			seenIcons.push(target.icon);
			merged.push(target);
		});
		return merged;
	}

	async function attemptDeepLClick(field, deepLTarget, baseline, activityWaitMs, scrollSnapshot, sourceLength){
		var candidates = mergeDeepLTargets(deepLTarget, findDeepLTargets(field)).slice(0, 2);
		for (var attempt = 0; attempt < candidates.length; attempt += 1) {
			var currentTarget = candidates[attempt];
			await primeFieldForDeepLTrigger(field, sourceLength, scrollSnapshot);
			await humanPause(
				DEEPL_HUMAN_PRE_ACTION_MIN_MS * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
				DEEPL_HUMAN_PRE_ACTION_MAX_MS * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER,
				Math.max(120 * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER, getHumanLengthBonusMs(sourceLength) * DEEPL_PRE_TRIGGER_WAIT_MULTIPLIER),
				scrollSnapshot
			);
			if (!performNativeClick(currentTarget.icon, scrollSnapshot)) {
				continue;
			}
			var activity = await waitForTranslationActivity(field, baseline, currentTarget.overlay, Math.max(1000, activityWaitMs));
			if (activity) {
				stabilizeScrollPosition(scrollSnapshot);
				return {
					started: true,
					activity: activity,
					overlay: currentTarget.overlay,
					hadCandidate: true
				};
			}
			await humanPause(
				DEEPL_HUMAN_RETRY_MIN_MS,
				DEEPL_HUMAN_RETRY_MAX_MS,
				getHumanLengthBonusMs(sourceLength),
				scrollSnapshot
			);
			stabilizeScrollPosition(scrollSnapshot);
		}
		return {
			started: false,
			overlay: deepLTarget && deepLTarget.overlay ? deepLTarget.overlay : null,
			hadCandidate: candidates.length > 0
		};
	}

	async function attemptDeepLHotkey(field, deepLTarget, baseline, activityWaitMs, scrollSnapshot, sourceLength){
		return {
			started: false,
			activity: '',
			overlay: deepLTarget && deepLTarget.overlay ? deepLTarget.overlay : null
		};
	}

	function buildTranslationBatchItems(form){
		var items = [];
		if (!form) return items;

		var sourceTitle = form.querySelector('.leadwerk-page-settings-source-title');
		var targetTitle = form.querySelector('.leadwerk-page-settings-target-title');
		if (sourceTitle && targetTitle) {
			items.push({
				type: 'page_title',
				label: 'English Title',
				sourceField: sourceTitle,
				targetField: targetTitle
			});
		}

		collectSegments(form).forEach(function(segment){
			var sourceField = segment.querySelector('.leadwerk-translation-source-raw');
			var targetField = segment.querySelector('.leadwerk-translation-target');
			if (!sourceField || !targetField) return;
			var labelNode = segment.querySelector('.leadwerk-translation-segment__target strong');
			items.push({
				type: 'segment',
				label: labelNode ? (labelNode.textContent || '').trim() : 'Segment',
				segment: segment,
				sourceField: sourceField,
				targetField: targetField
			});
		});

		return items;
	}

	function buildStringBatchItems(form){
		var items = [];
		if (!form) return items;
		Array.prototype.slice.call(form.querySelectorAll('.leadwerk-string-row')).forEach(function(row){
			var sourceField = row.querySelector('.leadwerk-string-source-raw');
			var targetField = row.querySelector('.leadwerk-string-target');
			if (!sourceField || !targetField) return;
			var keyNode = row.querySelector('td strong');
			items.push({
				type: 'string',
				label: keyNode ? (keyNode.textContent || '').trim() : 'String',
				row: row,
				sourceField: sourceField,
				targetField: targetField
			});
		});
		return items;
	}

	function applyBulkCopy(item){
		if (!item || !item.sourceField || !item.targetField) return;
		item.targetField.value = item.sourceField.value || '';
		triggerFieldUpdate(item.targetField);
		if (item.segment) {
			updateBadge(item.segment, item.targetField.value);
		}
		if (item.row) {
			updateStringBadge(item.row, item.targetField.value);
		}
		refreshFilters(item.targetField);
	}

	function summarizeBulkCounts(counts){
		return 'Done. Processed ' + counts.processed +
			' | translated ' + counts.translated +
			' | skipped filled ' + counts.skipped_filled +
			' | too long ' + counts.skipped_too_long +
			' | no icon ' + counts.skipped_no_icon +
			' | click failed ' + counts.click_failed +
			' | hotkey used ' + counts.hotkey_used +
			' | hotkey failed ' + counts.hotkey_failed +
			' | timed out ' + counts.timed_out +
			' | failed ' + counts.failed + '.';
	}

	function updateBulkProgress(form, target, counts, currentIndex, total, currentLabel, batchInfo){
		var parts = [
			'Processing ' + currentIndex + '/' + total,
			'translated ' + counts.translated,
			'skipped filled ' + counts.skipped_filled,
			'too long ' + counts.skipped_too_long,
			'no icon ' + counts.skipped_no_icon,
			'click failed ' + counts.click_failed,
			'hotkey used ' + counts.hotkey_used,
			'hotkey failed ' + counts.hotkey_failed,
			'timed out ' + counts.timed_out
		];
		var batchPrefix = formatBulkProgressPrefix(batchInfo);
		if (batchPrefix) {
			parts.unshift(batchPrefix);
		}
		if (currentLabel) {
			parts.unshift(currentLabel);
		}
		setBulkControls(form, target, true, parts.join(' | '));
	}

	async function runDeepLForItem(item){
		var sourceValue = item.sourceField ? (item.sourceField.value || '') : '';
		var sourceLength = (sourceValue || '').length;
		var copiedValue = item.targetField ? (item.targetField.value || '') : '';
		var activityWaitMs = getDeepLActivityWaitMs(sourceLength);
		var translationWaitMs = getDeepLTranslationWaitMs(sourceLength);
		var scrollSnapshot = null;
		if (!item.targetField) {
			setBulkAutomationResult('failed', 'missing_target_field');
			return {
				status: 'failed',
				clickFailed: false,
				hotkeyUsed: false,
				hotkeyFailed: false
			};
		}

		if ((sourceValue || '').length >= DEEPL_LENGTH_LIMIT) {
			setBulkAutomationCurrentItem(item, copiedValue);
			setBulkAutomationResult('too_long', 'length_limit');
			clearAutomationHotkeyItem('too_long');
			return {
				status: 'too_long',
				clickFailed: false,
				hotkeyUsed: false,
				hotkeyFailed: false
			};
		}

		setBulkAutomationCurrentItem(item, copiedValue);
		await activateTargetFieldForDeepL(item.targetField);
		scrollSnapshot = captureScrollPosition();
		await humanizeBeforeDeepLAction(item.targetField, sourceLength, scrollSnapshot);

		var deepLTarget = await waitForDeepLTarget(item.targetField, activityWaitMs);
		if (!deepLTarget) {
			await activateTargetFieldForDeepL(item.targetField);
			scrollSnapshot = captureScrollPosition();
			await humanizeBeforeDeepLAction(item.targetField, sourceLength, scrollSnapshot);
			deepLTarget = await waitForDeepLTarget(item.targetField, Math.max(1200, Math.floor(activityWaitMs / 2)));
		}

		var clickAttempt = await attemptDeepLClick(item.targetField, deepLTarget, copiedValue, activityWaitMs, scrollSnapshot, sourceLength);
		if (clickAttempt.started) {
			var translatedAfterClick = 'value_changed' === clickAttempt.activity
				? true
				: await waitForTranslatedValue(item.targetField, copiedValue, translationWaitMs);
			triggerFieldUpdate(item.targetField);
			refreshFilters(item.targetField);
			stabilizeScrollPosition(scrollSnapshot);
			setBulkAutomationResult(translatedAfterClick ? 'translated' : 'timed_out', 'native_click');
			clearAutomationHotkeyItem(translatedAfterClick ? 'translated' : 'timed_out');
			return {
				status: translatedAfterClick ? 'translated' : 'timed_out',
				clickFailed: false,
				hotkeyUsed: false,
				hotkeyFailed: false
			};
		}

		triggerFieldUpdate(item.targetField);
		refreshFilters(item.targetField);
		stabilizeScrollPosition(scrollSnapshot);
		var finalStatus = clickAttempt.hadCandidate ? 'failed' : 'no_icon';
		setBulkAutomationResult(finalStatus, clickAttempt.hadCandidate ? 'icon_found_but_click_failed' : 'no_translate_icon_found');
		clearAutomationHotkeyItem(finalStatus);
		return {
			status: finalStatus,
			clickFailed: !!clickAttempt.hadCandidate,
			hotkeyUsed: false,
			hotkeyFailed: false
		};
	}

	async function startBulkDeepLRun(form, target){
		if (!form || bulkState.active) {
			return;
		}

		var allItems = target === 'strings' ? buildStringBatchItems(form) : buildTranslationBatchItems(form);
		var items = allItems.filter(function(item){
			return !!item && !!item.targetField && !!item.sourceField && item.targetField.matches('textarea') && !isFilledField(item.targetField);
		});
		var counts = createEmptyBulkCounts();
		if (!items.length) {
			setBulkControls(form, target, false, 'No empty eligible textareas found.');
			return;
		}

		bulkState.active = true;
		bulkState.stopRequested = false;
		bulkState.target = target;
		bulkState.form = form;
		bulkState.reloadPending = false;
		bulkState.finalOverrideMessage = '';
		bulkState.runId += 1;
		bulkAutomationState.activeRun = true;
		bulkAutomationState.target = target;
		bulkAutomationState.currentItem = null;
		bulkAutomationState.lastResult = null;
		bulkAutomationState.lastSummary = null;
		cleanupDeepLInteractionState();
		clearBulkResumeState();

		setBulkControls(form, target, true, 'Preparing bulk DeepL translation...');

		try {
			for (var itemIndex = 0; itemIndex < items.length; itemIndex += 1) {
				if (bulkState.stopRequested) {
					break;
				}

				var item = items[itemIndex];
				updateBulkProgress(form, target, counts, itemIndex + 1, items.length, item.label || 'Translating');

				if (!item.targetField || !item.sourceField || !item.targetField.matches('textarea')) {
					counts.failed += 1;
					counts.processed += 1;
					continue;
				}

				try {
					applyBulkCopy(item);
					var result = await runDeepLForItem(item);
				} catch (error) {
					var result = {
						status: 'failed',
						clickFailed: false,
						hotkeyUsed: false,
						hotkeyFailed: false
					};
				}

				if (result.clickFailed) {
					counts.click_failed += 1;
				}
				if (result.hotkeyUsed) {
					counts.hotkey_used += 1;
				}
				if (result.hotkeyFailed) {
					counts.hotkey_failed += 1;
				}

				if ('translated' === result.status) {
					counts.translated += 1;
				} else if ('too_long' === result.status) {
					counts.skipped_too_long += 1;
				} else if ('no_icon' === result.status) {
					counts.skipped_no_icon += 1;
				} else if ('timed_out' === result.status) {
					counts.timed_out += 1;
				} else {
					counts.failed += 1;
				}

				counts.processed += 1;
				await humanizeBetweenBulkItems(item, result, itemIndex, items.length);
			}
		} finally {
			if (!bulkState.reloadPending) {
				var baseSummary = summarizeBulkCounts(counts);
				var finalMessage = bulkState.finalOverrideMessage
					? 'Stopped. ' + bulkState.finalOverrideMessage + ' ' + baseSummary
					: (bulkState.stopRequested ? 'Stopped. ' + baseSummary : baseSummary);
				setBulkControls(form, target, false, finalMessage);
				finalizeAutomationRun({
					runId: bulkState.runId,
					target: target,
					stopped: !!bulkState.stopRequested,
					message: finalMessage,
					counts: counts
				});
			}
			bulkState.active = false;
			bulkState.stopRequested = false;
			bulkState.reloadPending = false;
			bulkState.finalOverrideMessage = '';
			bulkState.target = '';
			bulkState.form = null;
			cleanupDeepLInteractionState();
		}
	}

	document.addEventListener('click', function(event){
		var ampButton = event.target.closest('.leadwerk-remove-amp-button');
		var bulkButton = event.target.closest('.leadwerk-bulk-deepl-button');
		var bulkStopButton = event.target.closest('.leadwerk-bulk-stop');
		var allButton = event.target.closest('.leadwerk-translation-copy-all');
		var allClearButton = event.target.closest('.leadwerk-translation-clear-all');
		var sectionButton = event.target.closest('.leadwerk-translation-copy-section');
		var sectionClearButton = event.target.closest('.leadwerk-translation-clear-section');
		var oneButton = event.target.closest('.leadwerk-translation-copy-one');
		var oneClearButton = event.target.closest('.leadwerk-translation-clear-one');
		var stringCopyButton = event.target.closest('.leadwerk-string-copy-one');
		var stringClearButton = event.target.closest('.leadwerk-string-clear-one');

		if (ampButton) {
			var ampTarget = ampButton.getAttribute('data-target') || 'segments';
			var ampForm = ampButton.closest('.leadwerk-translation-form, .leadwerk-string-translation-form');
			cleanAmpArtifacts(ampForm, ampTarget);
			return;
		}

		if (bulkButton) {
			var bulkTarget = bulkButton.getAttribute('data-target') || 'segments';
			var bulkForm = bulkButton.closest('.leadwerk-translation-form, .leadwerk-string-translation-form');
			startBulkDeepLRun(bulkForm, bulkTarget);
			return;
		}

		if (bulkStopButton) {
			if (bulkState.active) {
				bulkState.stopRequested = true;
				setBulkControls(bulkState.form, bulkState.target, true, 'Stopping after current item...');
			}
			return;
		}

		if (allButton) {
			var form = allButton.closest('.leadwerk-translation-form');
			if (!form) return;
			var allSegments = collectSegments(form);
			if (hasOverwriteRisk(allSegments) && !window.confirm('This will overwrite the current EN draft fields on this page with the DE source text. Continue?')) return;
			copySegments(allSegments);
			refreshFilters(form);
			return;
		}

		if (allClearButton) {
			var clearForm = allClearButton.closest('.leadwerk-translation-form');
			if (!clearForm) return;
			var clearAllSegments = collectSegments(clearForm);
			if (hasFilledTargets(clearAllSegments) && !window.confirm('Clear every EN segment on this page?')) return;
			clearSegments(clearAllSegments);
			refreshFilters(clearForm);
			return;
		}

		if (sectionButton) {
			var card = sectionButton.closest('.leadwerk-translation-card');
			if (!card) return;
			var sectionSegments = collectSegments(card);
			if (hasOverwriteRisk(sectionSegments) && !window.confirm('This will overwrite the current EN draft fields in this section with the DE source text. Continue?')) return;
			copySegments(sectionSegments);
			refreshFilters(card);
			return;
		}

		if (sectionClearButton) {
			var clearCard = sectionClearButton.closest('.leadwerk-translation-card');
			if (!clearCard) return;
			var clearSectionSegments = collectSegments(clearCard);
			if (hasFilledTargets(clearSectionSegments) && !window.confirm('Clear every EN segment in this section?')) return;
			clearSegments(clearSectionSegments);
			refreshFilters(clearCard);
			return;
		}

		if (oneButton) {
			var segment = oneButton.closest('.leadwerk-translation-segment');
			if (!segment) return;
			var sourceField = segment.querySelector('.leadwerk-translation-source-raw');
			var targetField = segment.querySelector('.leadwerk-translation-target');
			if (!sourceField || !targetField) return;
			var tv = targetField.value || '';
			if (tv.trim() !== '' && tv !== (sourceField.value || '') && !window.confirm('Overwrite the current EN text for this segment with the DE source?')) return;
			targetField.value = sourceField.value || '';
			updateBadge(segment, targetField.value);
			refreshFilters(segment);
			return;
		}

		if (oneClearButton) {
			var clearSegment = oneClearButton.closest('.leadwerk-translation-segment');
			if (!clearSegment) return;
			var clearTargetField = clearSegment.querySelector('.leadwerk-translation-target');
			if (!clearTargetField) return;
			if ((clearTargetField.value || '').trim() !== '' && !window.confirm('Clear the EN text for this segment?')) return;
			clearTargetField.value = '';
			updateBadge(clearSegment, clearTargetField.value);
			refreshFilters(clearSegment);
			return;
		}

		if (stringCopyButton) {
			var copyRow = stringCopyButton.closest('tr');
			if (!copyRow) return;
			var stringTargetField = copyRow.querySelector('.leadwerk-string-target');
			var stringSourceField = copyRow.querySelector('.leadwerk-string-source-raw');
			if (!stringTargetField || !stringSourceField) return;
			if ((stringTargetField.value || '').trim() !== '' && stringTargetField.value !== (stringSourceField.value || '') && !window.confirm('Overwrite the current EN string with the DE source text?')) return;
			copyStringRow(copyRow);
			refreshFilters(copyRow);
			return;
		}

		if (stringClearButton) {
			var clearRow = stringClearButton.closest('tr');
			if (!clearRow) return;
			var clearStringField = clearRow.querySelector('.leadwerk-string-target');
			if (!clearStringField) return;
			if ((clearStringField.value || '').trim() !== '' && !window.confirm('Clear this EN string?')) return;
			clearStringRow(clearRow);
			refreshFilters(clearRow);
		}
	});

	document.addEventListener('input', function(event){
		if (event.target.matches('.leadwerk-translation-target')) {
			var segment = event.target.closest('.leadwerk-translation-segment');
			if (!segment) return;
			updateBadge(segment, event.target.value || '');
			refreshFilters(segment);
			return;
		}

		if (event.target.matches('.leadwerk-string-target')) {
			var row = event.target.closest('.leadwerk-string-row');
			if (!row) return;
			updateStringBadge(row, event.target.value || '');
			refreshFilters(row);
		}
	});

	document.addEventListener('change', function(event){
		if (event.target.matches('.leadwerk-hide-completed-toggle')) {
			refreshFilters(event.target);
		}
	});

	document.querySelectorAll('.leadwerk-translation-form').forEach(function(form){
		applySegmentFilter(form);
	});
	document.querySelectorAll('.leadwerk-string-translation-form').forEach(function(form){
		applyStringFilter(form);
	});
	showPendingBulkResumeMessage();
})();
</script>
INLINE;
	}

	/**
	 * Sync string packages that should appear in the translation dashboard.
	 *
	 * @return void
	 */
	protected static function sync_dashboard_string_packages() {
		$theme_source_strings = self::get_theme_source_strings();
		if ( ! empty( $theme_source_strings ) ) {
			Leadwerk_Translation_API::sync_string_package( 'theme_strings', $theme_source_strings, 'en' );
		}
		Leadwerk_Shared_Translation_Packages::sync_dashboard_packages( 'en' );
	}

	/**
	 * Combine page and shared translation states.
	 *
	 * @param string $primary   Page bundle state.
	 * @param string $secondary Shared bundle state.
	 * @return string
	 */
	protected static function merge_translation_statuses( $primary, $secondary ) {
		$states = array( sanitize_key( (string) $primary ), sanitize_key( (string) $secondary ) );
		if ( in_array( 'not_translated', $states, true ) || in_array( 'needs_translation', $states, true ) ) {
			return 'not_translated';
		}
		if ( in_array( 'needs_update', $states, true ) || in_array( 'outdated', $states, true ) ) {
			return 'needs_update';
		}
		if ( in_array( 'in_progress', $states, true ) || in_array( 'blocked', $states, true ) ) {
			return 'in_progress';
		}
		return 'complete';
	}

	/**
	 * Resolve the active DE theme string package.
	 *
	 * @return array<string,string>
	 */
	protected static function get_theme_source_strings() {
		if ( function_exists( 'leadwerk_theme_get_theme_strings' ) ) {
			$strings = leadwerk_theme_get_theme_strings( 'de' );
			return is_array( $strings ) ? $strings : array();
		}

		$strings = Leadwerk_Translation_API::get_package_strings( 'theme_strings', 'de', array() );
		if ( ! empty( $strings ) ) {
			return $strings;
		}

		$raw = Leadwerk_Translation_API::get_localized_option( 'theme_strings', 'de', '' );
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Ensure registry rows exist for all posts of one translatable post type.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	protected static function ensure_registry_for_post_type( $post_type ) {
		if ( ! Leadwerk_Translation_API::is_translatable_post_type( $post_type ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $post_id ) {
			Leadwerk_Translation_API::ensure_post_record( (int) $post_id );
		}
	}
}
