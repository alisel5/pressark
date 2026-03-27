<?php
/**
 * Frontend SEO meta tag output.
 *
 * Outputs meta description, OG tags, and filters the document title
 * when no dedicated SEO plugin is active (Yoast, RankMath, AIOSEO, SEOPress, The SEO Framework).
 *
 * @since 4.3.0 Extracted from pressark.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Frontend_SEO {

	public static function register_hooks(): void {
		add_action( 'wp_head', array( self::class, 'output_meta_tags' ), 1 );
		add_filter( 'pre_get_document_title', array( self::class, 'filter_document_title' ) );
	}

	/**
	 * Output PressArk SEO meta tags on the frontend.
	 * Only outputs if no supported SEO plugin is active (to avoid duplicates).
	 */
	public static function output_meta_tags(): void {
		if ( ! is_singular() ) {
			return;
		}

		if ( self::has_seo_plugin() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$meta_desc = get_post_meta( $post_id, '_pressark_meta_description', true );
		$og_title  = get_post_meta( $post_id, '_pressark_og_title', true );
		$og_desc   = get_post_meta( $post_id, '_pressark_og_description', true );
		$og_image  = get_post_meta( $post_id, '_pressark_og_image', true );

		if ( $meta_desc ) {
			echo '<meta name="description" content="' . esc_attr( $meta_desc ) . '">' . "\n";
		}
		if ( $og_title ) {
			echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
		}
		if ( $og_desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
		}
		if ( $og_image ) {
			echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
		}
	}

	/**
	 * Filter document title if PressArk meta title is set.
	 * Only filters if no supported SEO plugin is active.
	 */
	public static function filter_document_title( string $title ): string {
		if ( ! is_singular() ) {
			return $title;
		}

		if ( self::has_seo_plugin() ) {
			return $title;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $title;
		}

		$meta_title = get_post_meta( $post_id, '_pressark_meta_title', true );
		return $meta_title ? $meta_title : $title;
	}

	/**
	 * Check if a supported SEO plugin is active.
	 *
	 * Delegates to PressArk_Handler_Base::detect_seo_plugin() when available,
	 * otherwise checks class/constant markers directly.
	 */
	private static function has_seo_plugin(): bool {
		if ( class_exists( 'PressArk_SEO_Resolver' ) ) {
			return PressArk_SEO_Resolver::has_plugin();
		}

		// Inline fallback in case resolver hasn't loaded yet.
		return defined( 'WPSEO_VERSION' )
			|| class_exists( 'RankMath' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}
}
