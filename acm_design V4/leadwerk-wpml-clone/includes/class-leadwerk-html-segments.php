<?php
/**
 * Extract and apply translation segments for HTML fragments.
 *
 * @package Leadwerk_WPML_Clone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leadwerk_HTML_Segments {

	/**
	 * Tags to skip entirely.
	 *
	 * @var string[]
	 */
	protected static $skip_tags = array( 'script', 'style', 'svg', 'path', 'circle', 'source' );

	/**
	 * Classes to skip.
	 *
	 * @var string[]
	 */
	protected static $skip_classes = array(
		'badge-dot',
		'blurb-icon',
		'contact-icon',
		'header-lang-icon',
		'kontakt-info-icon',
		'step-icon',
		'timeline-item__icon',
		'timeline-item__number',
		'timeline-card__toggle-arrow',
		'service-icon',
		'fs-nav-icon',
		'testimonial-initials',
	);

	/**
	 * Extract translatable segments from an HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return array<string,array<string,mixed>>
	 */
	public static function extract( $html ) {
		$root = self::load_fragment( $html );
		if ( ! $root ) {
			return array();
		}

		$segments = array();
		self::walk_element( $root, 'root[1]', $segments, false, array() );

		return $segments;
	}

	/**
	 * Apply translations to an HTML fragment.
	 *
	 * @param string               $html         HTML fragment.
	 * @param array<string,string> $translations Segment translations by key.
	 * @return string
	 */
	public static function apply( $html, $translations ) {
		$root = self::load_fragment( $html );
		if ( ! $root ) {
			return $html;
		}

		self::walk_element( $root, 'root[1]', $translations, true, array() );

		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $root->ownerDocument->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Walk the DOM and either extract or mutate text/attributes.
	 *
	 * @param DOMNode              $node         Current DOM node.
	 * @param string               $path         Current node path.
	 * @param array<string,mixed> &$collector    Collector or translation map.
	 * @param bool                 $apply        Whether translations should be applied.
	 * @param array<string,int>    $skip_context Skip context cache.
	 * @return void
	 */
	protected static function walk_element( $node, $path, &$collector, $apply, $skip_context ) {
		if ( ! $node instanceof DOMNode ) {
			return;
		}

		if ( XML_TEXT_NODE === $node->nodeType ) {
			self::handle_text_node( $node, $path, $collector, $apply, $skip_context );
			return;
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return;
		}

		$tag_name = strtolower( $node->nodeName );
		if ( in_array( $tag_name, self::$skip_tags, true ) ) {
			return;
		}

		$skip_context = self::merge_skip_context( $node, $skip_context );

		foreach ( array( 'alt', 'aria-label', 'title', 'placeholder', 'data-title', 'data-body' ) as $attr_name ) {
			if ( ! $node->hasAttribute( $attr_name ) ) {
				continue;
			}

			$value = (string) $node->getAttribute( $attr_name );
			if ( ! self::should_translate_attribute( $value, $skip_context ) ) {
				continue;
			}

			$key = $path . '/@' . $attr_name;

			if ( $apply ) {
				if ( isset( $collector[ $key ] ) && '' !== trim( (string) $collector[ $key ] ) ) {
					$node->setAttribute( $attr_name, (string) $collector[ $key ] );
				}
			} else {
				$collector[ $key ] = array(
					'key'         => $key,
					'type'        => 'attribute',
					'attribute'   => $attr_name,
					'source'      => $value,
					'source_hash' => md5( $value ),
				);
			}
		}

		$element_counters = array();
		$text_counter     = 0;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				++$text_counter;
				self::walk_element( $child, $path . '/text[' . $text_counter . ']', $collector, $apply, $skip_context );
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$child_tag = strtolower( $child->nodeName );
			if ( ! isset( $element_counters[ $child_tag ] ) ) {
				$element_counters[ $child_tag ] = 0;
			}
			++$element_counters[ $child_tag ];

			self::walk_element( $child, $path . '/' . $child_tag . '[' . $element_counters[ $child_tag ] . ']', $collector, $apply, $skip_context );
		}
	}

	/**
	 * Handle a text node.
	 *
	 * @param DOMNode              $node         Text node.
	 * @param string               $path         Segment path.
	 * @param array<string,mixed> &$collector    Collector or translation map.
	 * @param bool                 $apply        Whether to apply translations.
	 * @param array<string,int>    $skip_context Skip context state.
	 * @return void
	 */
	protected static function handle_text_node( $node, $path, &$collector, $apply, $skip_context ) {
		$source = (string) $node->nodeValue;
		if ( ! self::should_translate_text( $source, $skip_context ) ) {
			return;
		}

		if ( $apply ) {
			if ( isset( $collector[ $path ] ) && '' !== trim( (string) $collector[ $path ] ) ) {
				$node->nodeValue = self::preserve_whitespace( $source, (string) $collector[ $path ] );
			}
			return;
		}

		$collector[ $path ] = array(
			'key'         => $path,
			'type'        => 'text',
			'source'      => trim( $source ),
			'source_hash' => md5( trim( $source ) ),
		);
	}

	/**
	 * Merge skip context from an ancestor.
	 *
	 * @param DOMElement        $node         DOM element.
	 * @param array<string,int> $skip_context Existing context.
	 * @return array<string,int>
	 */
	protected static function merge_skip_context( $node, $skip_context ) {
		if ( ! $node instanceof DOMElement ) {
			return $skip_context;
		}

		if ( 'true' === $node->getAttribute( 'aria-hidden' ) ) {
			$skip_context['aria_hidden'] = 1;
		}

		$class_attr = (string) $node->getAttribute( 'class' );
		if ( '' !== $class_attr ) {
			$classes = preg_split( '/\s+/', trim( $class_attr ) );
			if ( is_array( $classes ) ) {
				$skip_classes_filtered = apply_filters( 'leadwerk_html_segments_skip_classes', self::$skip_classes );
				foreach ( $classes as $class_name ) {
					if ( in_array( $class_name, $skip_classes_filtered, true ) ) {
						$skip_context['class_skip'] = 1;
						break;
					}
				}
			}
		}

		return $skip_context;
	}

	/**
	 * Determine whether a text node should be translated.
	 *
	 * @param string            $text         Raw text.
	 * @param array<string,int> $skip_context Skip context.
	 * @return bool
	 */
	protected static function should_translate_text( $text, $skip_context ) {
		if ( ! empty( $skip_context['aria_hidden'] ) || ! empty( $skip_context['class_skip'] ) ) {
			return false;
		}

		$stripped = trim( $text );
		if ( '' === $stripped ) {
			return false;
		}

		if ( preg_match( '/^[\d\s%+.,:;|()\/\-\x{2013}\x{2014}&]+$/u', $stripped ) ) {
			return false;
		}

		if ( strlen( $stripped ) <= 2 && strtoupper( $stripped ) === $stripped ) {
			return false;
		}

		if ( false !== strpos( $stripped, '@' ) || preg_match( '#^(https?:)?//#i', $stripped ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine whether an attribute should be translated.
	 *
	 * @param string            $value        Attribute value.
	 * @param array<string,int> $skip_context Skip context.
	 * @return bool
	 */
	protected static function should_translate_attribute( $value, $skip_context ) {
		if ( ! empty( $skip_context['aria_hidden'] ) || ! empty( $skip_context['class_skip'] ) ) {
			return false;
		}

		if ( '' === trim( $value ) ) {
			return false;
		}

		if ( in_array( trim( $value ), array( 'DE', 'EN' ), true ) ) {
			return false;
		}

		return (bool) preg_match( '/[[:alpha:]\x{00C0}-\x{024F}]/u', $value );
	}

	/**
	 * Preserve the leading and trailing whitespace of a text node.
	 *
	 * @param string $original    Original value.
	 * @param string $translation Replacement value.
	 * @return string
	 */
	protected static function preserve_whitespace( $original, $translation ) {
		if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/us', $original, $matches ) ) {
			return $translation;
		}

		return $matches[1] . $translation . $matches[3];
	}

	/**
	 * Load a fragment into DOM.
	 *
	 * @param string $html HTML fragment.
	 * @return DOMElement|null
	 */
	protected static function load_fragment( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return null;
		}

		$dom  = new DOMDocument( '1.0', 'UTF-8' );
		$html = '<div id="leadwerk-html-root">' . $html . '</div>';

		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$root = $dom->getElementById( 'leadwerk-html-root' );
		return $root instanceof DOMElement ? $root : null;
	}
}
