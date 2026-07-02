<?php
/**
 * Generic structured section renderer for ACM pages.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * First usable value for a prioritized list of keys.
 *
 * @param array<string,mixed> $data    Source row.
 * @param array<int,string>   $keys    Keys in order.
 * @param mixed               $default Default when none found (int 0 for image ids).
 * @return mixed
 */
function leadwerk_theme_pick_first_value( $data, $keys, $default = '' ) {
    if ( ! is_array( $data ) ) {
        return $default;
    }
    $want_int = is_int( $default );
    foreach ( $keys as $key ) {
        if ( ! array_key_exists( $key, $data ) ) {
            continue;
        }
        $v = $data[ $key ];
        if ( $want_int ) {
            return (int) $v;
        }
        if ( is_string( $v ) && '' !== trim( wp_strip_all_tags( $v ) ) ) {
            return $v;
        }
        if ( ! is_string( $v ) && is_scalar( $v ) && '' !== trim( (string) $v ) ) {
            return (string) $v;
        }
    }
    return $default;
}

/**
 * Render one structured ACM group.
 *
 * @param array<string,mixed> $group   Group schema.
 * @param mixed               $value   Stored field value.
 * @param int                 $post_id Post ID.
 * @return string
 */
function leadwerk_theme_render_structured_page_group( $group, $value, $post_id = 0 ) {
    $resolved = leadwerk_theme_resolve_structured_group_value( $group, $value, $post_id );
    $value    = $resolved['value'];

    if ( ! empty( $resolved['override_html'] ) ) {
        return (string) $resolved['override_html'];
    }

    if ( empty( $group['layouts'] ) ) {
        $headline = isset( $value['headline'] ) ? (string) $value['headline'] : '';
        $content  = isset( $value['content'] ) ? (string) $value['content'] : '';

        if ( '' === trim( wp_strip_all_tags( $headline . $content ) ) ) {
            return leadwerk_theme_render_missing_content_notice( $group, $post_id );
        }

        return sprintf(
            '<section class="content-section content-section--white legal-content"><div class="container--narrow"><h1 class="legal-title anim">%1$s</h1><div class="legal-body anim" style="animation-delay:100ms">%2$s</div></div></section>',
            esc_html( $headline ),
            wp_kses_post( $content )
        );
    }

    $sections = is_array( $value ) ? array_values( $value ) : array();
    if ( empty( $sections ) ) {
        return leadwerk_theme_render_missing_content_notice( $group, $post_id );
    }

    $output = '';
    $index  = 0;
    foreach ( (array) $group['layouts'] as $layout_key => $layout_schema ) {
        $section = isset( $sections[ $index ] ) && is_array( $sections[ $index ] ) ? $sections[ $index ] : array( 'acf_fc_layout' => $layout_key );
        $output .= leadwerk_theme_render_structured_section( $section, $layout_schema, $layout_key, $index, $post_id );
        ++$index;
    }

    if ( '' === trim( wp_strip_all_tags( $output ) ) ) {
        return leadwerk_theme_render_missing_content_notice( $group, $post_id );
    }

    return $output;
}

/**
 * Resolve the effective structured value for one page.
 *
 * @param array<string,mixed> $group   Group schema.
 * @param mixed               $value   Current field value.
 * @param int                 $post_id Post ID.
 * @return array<string,mixed>
 */
function leadwerk_theme_resolve_structured_group_value( $group, $value, $post_id = 0 ) {
    if ( leadwerk_theme_group_has_visible_content( $group, $value ) ) {
        return array(
            'value'                   => $value,
            'used_last_good_fallback' => false,
            'override_html'           => '',
        );
    }

    $field_name = (string) ( $group['field_name'] ?? '' );
    if ( '' !== $field_name ) {
        $snapshot = get_post_meta( $post_id, '_leadwerk_last_good_' . sanitize_key( $field_name ), true );
        if ( is_array( $snapshot ) && array_key_exists( 'value', $snapshot ) && leadwerk_theme_group_has_visible_content( $group, $snapshot['value'] ) ) {
            return array(
                'value'                   => $snapshot['value'],
                'used_last_good_fallback' => true,
                'override_html'           => '',
            );
        }
    }

    if ( empty( $group['layouts'] ) ) {
        $post_content = (string) get_post_field( 'post_content', $post_id );
        $post_obj     = get_post( $post_id );
        $has_acm_page = false;
        if ( $post_obj instanceof WP_Post ) {
            $has_acm_page = has_block( 'acf/leadwerk-acm-page', $post_obj );
        }
        if ( '' !== trim( $post_content ) && ! $has_acm_page ) {
            return array(
                'value'                   => $value,
                'used_last_good_fallback' => false,
                'override_html'           => $post_content,
            );
        }
    }

    return array(
        'value'                   => $value,
        'used_last_good_fallback' => false,
        'override_html'           => '',
    );
}

/**
 * Whether one flexible-content row has visible data (text, media id, nested repeaters).
 *
 * Contact sections always render the form; treat them as non-empty for group fallback logic.
 *
 * @param mixed $section Section field values (typically array).
 * @return bool
 */
function leadwerk_theme_structured_section_has_visible_content( $section ) {
    if ( ! is_array( $section ) ) {
        return false;
    }

    if ( isset( $section['acf_fc_layout'] ) && 'contact_main' === (string) $section['acf_fc_layout'] ) {
        return true;
    }

    $contact_keys = array( 'privacy_page', 'privacy_label', 'info_cards' );
    foreach ( $contact_keys as $contact_key ) {
        if ( array_key_exists( $contact_key, $section ) ) {
            return true;
        }
    }

    foreach ( $section as $key => $value ) {
        if ( ! is_string( $key ) ) {
            continue;
        }
        if ( 'acf_fc_layout' === $key ) {
            continue;
        }
        if ( '' !== $key && 0 === strpos( $key, '_' ) ) {
            continue;
        }

        if ( is_array( $value ) ) {
            if ( leadwerk_theme_structured_section_has_visible_content( $value ) ) {
                return true;
            }
            continue;
        }

        if ( is_int( $value ) ) {
            if ( 0 !== $value ) {
                return true;
            }
            continue;
        }

        if ( is_string( $value ) && is_numeric( $value ) && '' !== $value ) {
            if ( 0 !== (int) $value ) {
                return true;
            }
            continue;
        }

        if ( is_string( $value ) && '' !== trim( wp_strip_all_tags( $value ) ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a group contains any visible content.
 *
 * @param array<string,mixed> $group Group schema.
 * @param mixed               $value Group value.
 * @return bool
 */
function leadwerk_theme_group_has_visible_content( $group, $value ) {
    if ( empty( $group['layouts'] ) ) {
        $headline = is_array( $value ) ? (string) ( $value['headline'] ?? '' ) : '';
        $content  = is_array( $value ) ? (string) ( $value['content'] ?? '' ) : '';
        return '' !== trim( wp_strip_all_tags( $headline . $content ) );
    }

    $sections = is_array( $value ) ? array_values( $value ) : array();
    if ( empty( $sections ) ) {
        return false;
    }

    foreach ( $sections as $section ) {
        if ( is_array( $section ) && leadwerk_theme_structured_section_has_visible_content( $section ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Render one structured section.
 *
 * @param array<string,mixed> $section       Section values.
 * @param array<string,mixed> $layout_schema Layout schema.
 * @param string              $layout_key    Layout key.
 * @param int                 $index         Section index.
 * @param int                 $post_id       Post ID.
 * @return string
 */
function leadwerk_theme_render_structured_section( $section, $layout_schema, $layout_key, $index, $post_id ) {
    $template = (string) ( $layout_schema['template'] ?? $layout_key );

    switch ( $template ) {
        case 'hero_slider':
            return leadwerk_theme_render_hero_slider_section( $section, $layout_schema, $layout_key, $index );
        case 'contact_main':
            return leadwerk_theme_render_contact_main_section( $section, $layout_schema, $layout_key, $index );
        default:
            return leadwerk_theme_render_generic_structured_section( $section, $layout_schema, $layout_key, $index, $post_id );
    }
}

/**
 * Render the home hero slider section as cards.
 *
 * @param array<string,mixed> $section       Section values.
 * @param array<string,mixed> $layout_schema Layout schema.
 * @param string              $layout_key    Layout key.
 * @param int                 $index         Section index.
 * @return string
 */
function leadwerk_theme_render_hero_slider_section( $section, $layout_schema, $layout_key, $index ) {
    $slides   = isset( $section['slides'] ) && is_array( $section['slides'] ) ? array_values( $section['slides'] ) : array();
    $services = isset( $section['services'] ) && is_array( $section['services'] ) ? array_values( $section['services'] ) : array();

    ob_start();
    ?>
    <section class="leadwerk-structured-section leadwerk-structured-section--hero-slider leadwerk-layout--<?php echo esc_attr( sanitize_html_class( $layout_key ) ); ?>" data-layout="<?php echo esc_attr( $layout_key ); ?>" data-index="<?php echo esc_attr( (string) $index ); ?>">
        <div class="leadwerk-structured-container">
            <?php if ( ! empty( $slides ) ) : ?>
                <div class="leadwerk-hero-slider-grid">
                    <?php foreach ( $slides as $slide ) : ?>
                        <div class="leadwerk-hero-slide-card">
                            <div class="leadwerk-hero-slide-card__media">
                                <?php echo leadwerk_theme_render_structured_image( (int) ( $slide['background'] ?? 0 ), (string) ( $slide['background_alt'] ?? '' ), 'leadwerk-hero-slide-card__image' ); ?>
                            </div>
                            <div class="leadwerk-hero-slide-card__content">
                                <?php echo leadwerk_theme_render_main_title( (string) ( $slide['title'] ?? '' ), 'leadwerk-hero-slide-card__title' ); ?>
                                <?php echo leadwerk_theme_render_html_block( (string) ( $slide['subtitle'] ?? '' ), 'leadwerk-hero-slide-card__subtitle leadwerk-structured-copy' ); ?>
                                <?php echo leadwerk_theme_render_button_markup( array(
                                    'label'    => (string) ( $slide['cta_label'] ?? '' ),
                                    'page_key' => (string) ( $slide['cta_page_key'] ?? '' ),
                                    'url'      => (string) ( $slide['cta_url'] ?? '' ),
                                ), 'leadwerk-structured-button' ); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $services ) ) : ?>
                <div class="leadwerk-structured-grid leadwerk-structured-grid--cards leadwerk-structured-grid--compact">
                    <?php foreach ( $services as $service ) : ?>
                        <article class="leadwerk-structured-card leadwerk-structured-card--service">
                            <?php echo leadwerk_theme_render_structured_image( (int) ( $service['icon'] ?? 0 ), (string) ( $service['icon_alt'] ?? '' ), 'leadwerk-structured-card__icon' ); ?>
                            <?php echo leadwerk_theme_render_main_title( (string) ( $service['title'] ?? '' ), 'leadwerk-structured-card__title leadwerk-structured-card__title--small', 'h3' ); ?>
                            <?php echo leadwerk_theme_render_html_block( (string) ( $service['description'] ?? '' ), 'leadwerk-structured-copy leadwerk-structured-copy--small' ); ?>
                            <?php echo leadwerk_theme_render_button_markup( array(
                                'label'    => (string) ( wp_strip_all_tags( $service['title'] ?? '' ) ),
                                'page_key' => (string) ( $service['page_key'] ?? '' ),
                                'url'      => (string) ( $service['url'] ?? '' ),
                            ), 'leadwerk-structured-link leadwerk-structured-link--inline', true ); ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php

    return ob_get_clean();
}

/**
 * Render the contact main section.
 *
 * @param array<string,mixed> $section       Section values.
 * @param array<string,mixed> $layout_schema Layout schema.
 * @param string              $layout_key    Layout key.
 * @param int                 $index         Section index.
 * @return string
 */
function leadwerk_theme_render_contact_main_section( $section, $layout_schema, $layout_key, $index ) {
    $info_cards = isset( $section['info_cards'] ) && is_array( $section['info_cards'] ) ? array_values( $section['info_cards'] ) : array();
    $privacy    = (string) ( $section['privacy_label'] ?? '' );
    $privacy_key = (string) ( $section['privacy_page'] ?? '' );
    $privacy_url = '' !== $privacy_key ? leadwerk_theme_get_page_url( $privacy_key, leadwerk_theme_get_current_lang(), '#' ) : '#';
    $privacy_link_label = leadwerk_theme_get_string( 'contact_privacy_link_label', 'Datenschutz' );

    ob_start();
    ?>
    <section class="leadwerk-structured-section leadwerk-structured-section--contact leadwerk-layout--<?php echo esc_attr( sanitize_html_class( $layout_key ) ); ?>" data-layout="<?php echo esc_attr( $layout_key ); ?>" data-index="<?php echo esc_attr( (string) $index ); ?>">
        <div class="leadwerk-structured-container">
            <div class="leadwerk-structured-shell leadwerk-structured-shell--two-column">
                <div class="leadwerk-structured-shell__content">
                    <?php echo leadwerk_theme_render_main_title( (string) ( $section['title'] ?? '' ) ); ?>
                    <?php echo leadwerk_theme_render_html_block( (string) ( $section['intro'] ?? '' ), 'leadwerk-structured-copy' ); ?>
                    <?php if ( ! empty( $info_cards ) ) : ?>
                        <div class="leadwerk-structured-grid leadwerk-structured-grid--cards">
                            <?php foreach ( $info_cards as $card ) : ?>
                                <article class="leadwerk-structured-card leadwerk-structured-card--contact">
                                    <?php echo leadwerk_theme_render_main_title( (string) ( $card['title'] ?? '' ), 'leadwerk-structured-card__title leadwerk-structured-card__title--small', 'h3' ); ?>
                                    <div class="leadwerk-structured-copy">
                                        <?php
                                        $value = (string) ( $card['value'] ?? '' );
                                        $href  = (string) ( $card['href'] ?? '' );
                                        if ( '' !== trim( $href ) ) {
                                            echo '<a href="' . esc_url( $href ) . '">' . wp_kses_post( $value ) . '</a>';
                                        } else {
                                            echo wp_kses_post( $value );
                                        }
                                        ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="leadwerk-structured-shell__aside">
                    <div class="leadwerk-structured-card leadwerk-structured-card--form">
                        <?php echo leadwerk_theme_get_contact_form_markup(); ?>
                        <?php if ( '' !== trim( $privacy ) ) : ?>
                            <div class="leadwerk-structured-copy leadwerk-structured-copy--small leadwerk-contact-privacy">
                                <?php
                                    echo wp_kses_post( $privacy );
                                    if ( '#' !== $privacy_url ) {
                                        echo ' <a class="leadwerk-structured-link leadwerk-structured-link--inline" href="' . esc_url( $privacy_url ) . '">' . esc_html( $privacy_link_label ) . '</a>';
                                    }
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php

    return ob_get_clean();
}

/**
 * Render a generic structured section.
 *
 * @param array<string,mixed> $section       Section values.
 * @param array<string,mixed> $layout_schema Layout schema.
 * @param string              $layout_key    Layout key.
 * @param int                 $index         Section index.
 * @param int                 $post_id       Post ID.
 * @return string
 */
function leadwerk_theme_render_generic_structured_section( $section, $layout_schema, $layout_key, $index, $post_id ) {
    $title       = leadwerk_theme_pick_first_value( $section, array( 'title', 'goals_title', 'challenge_title', 'explainer_title', 'how_title', 'steps_title' ) );
    $intro_keys  = array( 'subtitle', 'intro', 'body', 'lead', 'summary', 'outro', 'note', 'goals_body', 'challenge_body', 'explainer_body', 'explainer_sub', 'how_body', 'left_body' );
    $image_id    = (int) leadwerk_theme_pick_first_value( $section, array( 'image', 'background_image' ), 0 );
    $image_alt   = (string) leadwerk_theme_pick_first_value( $section, array( 'image_alt', 'background_alt' ), '' );
    $has_content = leadwerk_theme_structured_section_has_visible_content( $section );

    if ( ! $has_content ) {
        return current_user_can( 'edit_post', $post_id )
            ? '<section class="leadwerk-structured-section"><div class="leadwerk-structured-container"><div class="leadwerk-structured-empty">Section "' . esc_html( $layout_schema['label'] ?? $layout_key ) . '" has no visible content yet.</div></div></section>'
            : '';
    }

    ob_start();
    ?>
    <section class="leadwerk-structured-section leadwerk-layout--<?php echo esc_attr( sanitize_html_class( $layout_key ) ); ?>" data-layout="<?php echo esc_attr( $layout_key ); ?>" data-index="<?php echo esc_attr( (string) $index ); ?>">
        <div class="leadwerk-structured-container">
            <div class="leadwerk-structured-shell<?php echo $image_id ? ' leadwerk-structured-shell--two-column' : ''; ?>">
                <div class="leadwerk-structured-shell__content">
                    <?php echo leadwerk_theme_render_main_title( $title ); ?>
                    <?php foreach ( $intro_keys as $intro_key ) : ?>
                        <?php
                        if ( ! isset( $section[ $intro_key ] ) || '' === trim( (string) $section[ $intro_key ] ) ) {
                            continue;
                        }
                        echo leadwerk_theme_render_html_block( (string) $section[ $intro_key ], 'leadwerk-structured-copy leadwerk-structured-copy--' . sanitize_html_class( $intro_key ) );
                        ?>
                    <?php endforeach; ?>

                    <?php if ( ! empty( $section['right_title'] ) ) : ?>
                        <h3 class="leadwerk-structured-subtitle"><?php echo esc_html( (string) $section['right_title'] ); ?></h3>
                    <?php endif; ?>

                    <?php echo leadwerk_theme_render_structured_repeaters( $section, $layout_schema ); ?>
                    <?php echo leadwerk_theme_render_structured_buttons( $section ); ?>
                </div>

                <?php if ( $image_id ) : ?>
                    <div class="leadwerk-structured-shell__aside">
                        <?php echo leadwerk_theme_render_structured_image( $image_id, $image_alt, 'leadwerk-structured-image leadwerk-structured-image--aside' ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php

    return ob_get_clean();
}

/**
 * Render all repeaters for one section.
 *
 * @param array<string,mixed> $section       Section values.
 * @param array<string,mixed> $layout_schema Layout schema.
 * @return string
 */
function leadwerk_theme_render_structured_repeaters( $section, $layout_schema ) {
    $output = '';

    foreach ( (array) ( $layout_schema['fields'] ?? array() ) as $field_key => $definition ) {
        if ( 'repeater' !== ( $definition['type'] ?? '' ) ) {
            continue;
        }

        $items = isset( $section[ $field_key ] ) && is_array( $section[ $field_key ] ) ? array_values( $section[ $field_key ] ) : array();
        if ( empty( $items ) ) {
            continue;
        }

        $output .= '<div class="leadwerk-structured-repeater leadwerk-structured-repeater--' . esc_attr( sanitize_html_class( $field_key ) ) . '">';
        $output .= '<div class="leadwerk-structured-grid leadwerk-structured-grid--cards">';
        foreach ( $items as $item ) {
            $output .= leadwerk_theme_render_structured_card( is_array( $item ) ? $item : array(), (string) $field_key );
        }
        $output .= '</div></div>';
    }

    return $output;
}

/**
 * Render one generic repeater card.
 *
 * @param array<string,mixed> $item      Repeater item.
 * @param string              $field_key Parent field key.
 * @return string
 */
function leadwerk_theme_render_structured_card( $item, $field_key ) {
    if ( ! empty( $item['is_empty'] ) ) {
        return '<div class="leadwerk-structured-card leadwerk-structured-card--empty" aria-hidden="true"></div>';
    }

    $title      = leadwerk_theme_pick_first_value( $item, array( 'title', 'card_title', 'question', 'label' ) );
    $image_id   = (int) leadwerk_theme_pick_first_value( $item, array( 'image', 'icon' ), 0 );
    $image_alt  = (string) leadwerk_theme_pick_first_value( $item, array( 'image_alt', 'icon_alt' ), '' );
    $value_text = leadwerk_theme_pick_first_value( $item, array( 'value', 'number', 'icon_text' ) );
    $copy_keys  = array( 'intro', 'body', 'content', 'text', 'quote', 'description', 'answer', 'result', 'role' );
    $anchor_id  = isset( $item['anchor_id'] ) ? sanitize_title( (string) $item['anchor_id'] ) : '';

    ob_start();
    ?>
    <article class="leadwerk-structured-card"<?php echo '' !== $anchor_id ? ' id="' . esc_attr( $anchor_id ) . '"' : ''; ?>>
        <?php if ( $image_id ) : ?>
            <div class="leadwerk-structured-card__media">
                <?php echo leadwerk_theme_render_structured_image( $image_id, $image_alt, 'leadwerk-structured-card__image' ); ?>
            </div>
        <?php endif; ?>
        <div class="leadwerk-structured-card__body">
            <?php
            $title_str = is_string( $title ) ? $title : ( is_scalar( $title ) ? (string) $title : '' );
            if ( '' !== trim( wp_strip_all_tags( $title_str ) ) ) {
                echo leadwerk_theme_render_main_title( $title_str, 'leadwerk-structured-card__title leadwerk-structured-card__title--small', 'h3' );
            }
            $vt = is_string( $value_text ) ? $value_text : ( is_scalar( $value_text ) ? (string) $value_text : '' );
            if ( '' !== trim( wp_strip_all_tags( $vt ) ) ) {
                echo leadwerk_theme_render_html_block( $vt, 'leadwerk-structured-copy leadwerk-structured-card__value' );
            }
            foreach ( $copy_keys as $ck ) {
                if ( empty( $item[ $ck ] ) || ! is_string( $item[ $ck ] ) ) {
                    continue;
                }
                if ( '' === trim( wp_strip_all_tags( $item[ $ck ] ) ) ) {
                    continue;
                }
                echo leadwerk_theme_render_html_block( (string) $item[ $ck ], 'leadwerk-structured-copy leadwerk-structured-copy--' . sanitize_html_class( $ck ) );
            }
            ?>
        </div>
    </article>
    <?php
    return ob_get_clean();
}
