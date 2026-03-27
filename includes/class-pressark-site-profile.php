<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-generates a comprehensive site profile by crawling all content.
 * The profile captures business identity, content DNA, people, structure,
 * technical details, and WooCommerce data — injected into every AI prompt.
 */
class PressArk_Site_Profile {

	const OPTION_KEY          = 'pressark_site_profile';
	const LAST_GENERATED_KEY  = 'pressark_site_profile_generated';

	/**
	 * Generate the full site profile by crawling all content.
	 */
	public function generate(): array {
		$profile = array(
			'generated_at' => current_time( 'mysql' ),
			'identity'     => $this->analyze_identity(),
			'content_dna'  => $this->analyze_content_dna(),
			'people'       => $this->extract_people(),
			'structure'    => $this->analyze_structure(),
			'technical'    => $this->analyze_technical(),
		);

		// Design system: extract brand colors and content width from theme.json.
		if ( wp_theme_has_theme_json() ) {
			$profile['design_system'] = $this->extract_design_system();
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$profile['woocommerce'] = $this->analyze_woocommerce();
		}

		$profile['ai_summary'] = $this->generate_ai_summary( $profile );

		update_option( self::OPTION_KEY, $profile, false );
		update_option( self::LAST_GENERATED_KEY, current_time( 'mysql' ), false );

		return $profile;
	}

	/**
	 * Get the stored profile.
	 */
	public function get(): ?array {
		$profile = get_option( self::OPTION_KEY, null );
		return is_array( $profile ) ? $profile : null;
	}

	/**
	 * Get just the AI summary for injection into prompts.
	 */
	public function get_ai_summary(): string {
		$profile = $this->get();
		return $profile['ai_summary'] ?? '';
	}

	/**
	 * Check if profile needs regeneration (older than 7 days or never generated).
	 */
	public function needs_refresh(): bool {
		$last = get_option( self::LAST_GENERATED_KEY, null );
		if ( ! $last ) {
			return true;
		}
		return ( strtotime( $last ) + ( 7 * DAY_IN_SECONDS ) ) < time();
	}

	// === ANALYZERS ===

	private function analyze_identity(): array {
		$identity = array(
			'name'        => get_bloginfo( 'name' ),
			'tagline'     => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'language'    => get_locale(),
			'admin_email' => get_option( 'admin_email' ),
		);

		$all_content = $this->get_all_content_text();
		$identity['detected_industry'] = $this->detect_industry( $all_content );
		$identity['key_services']      = $this->extract_key_topics( $all_content, 10 );
		$identity['brand_terms']       = $this->extract_frequent_terms( $all_content, 15 );

		return $identity;
	}

	private function analyze_content_dna(): array {
		$pages = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 150,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		if ( empty( $pages ) ) {
			return array( 'tone' => 'unknown', 'avg_length' => 0, 'total_pages' => 0 );
		}

		$total_words     = 0;
		$total_pages     = count( $pages );
		$all_text        = '';
		$heading_styles  = array();
		$has_lists       = 0;
		$person_counts   = array( 'we' => 0, 'you' => 0, 'they' => 0, 'i' => 0 );
		$cta_texts       = array();
		$images_per_page = array();

		foreach ( $pages as $page ) {
			$content = $page->post_content;
			$text    = wp_strip_all_tags( $content );
			$words   = str_word_count( $text );
			$total_words += $words;
			$all_text    .= ' ' . $text;

			$lower = strtolower( $text );
			$person_counts['we']   += substr_count( $lower, ' we ' ) + substr_count( $lower, "we're" ) + substr_count( $lower, 'our ' );
			$person_counts['you']  += substr_count( $lower, ' you ' ) + substr_count( $lower, 'your ' );
			$person_counts['they'] += substr_count( $lower, ' they ' ) + substr_count( $lower, 'their ' );
			$person_counts['i']    += substr_count( $lower, ' i ' ) + substr_count( $lower, "i'm" ) + substr_count( $lower, ' my ' );

			if ( preg_match( '/<[uo]l/i', $content ) ) {
				$has_lists++;
			}

			preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/i', $content, $headings );
			foreach ( $headings[1] ?? array() as $h ) {
				$h = wp_strip_all_tags( $h );
				if ( strlen( $h ) > 3 ) {
					$heading_styles[] = $h;
				}
			}

			$img_count         = substr_count( $content, '<img' );
			$images_per_page[] = $img_count;

			preg_match_all( '/(?:wp-block-button__link|btn|button)[^>]*>(.*?)</i', $content, $buttons );
			foreach ( $buttons[1] ?? array() as $btn ) {
				$btn = trim( wp_strip_all_tags( $btn ) );
				if ( strlen( $btn ) > 2 && strlen( $btn ) < 50 ) {
					$cta_texts[] = $btn;
				}
			}
		}

		$avg_length = $total_pages > 0 ? round( $total_words / $total_pages ) : 0;
		$avg_images = ! empty( $images_per_page ) ? round( array_sum( $images_per_page ) / count( $images_per_page ), 1 ) : 0;

		arsort( $person_counts );
		$dominant_voice_key = array_key_first( $person_counts );
		$voice_map          = array(
			'we'   => 'first person plural (we/our)',
			'you'  => 'second person (you/your)',
			'i'    => 'first person singular (I/my)',
			'they' => 'third person (they/their)',
		);
		$dominant_voice = $voice_map[ $dominant_voice_key ] ?? 'mixed';

		$tone = $this->detect_tone( $all_text );

		$heading_style = 'descriptive';
		if ( ! empty( $heading_styles ) ) {
			$questions = count( array_filter( $heading_styles, function ( $h ) {
				return str_contains( $h, '?' );
			} ) );
			$actions = count( array_filter( $heading_styles, function ( $h ) {
				return preg_match( '/^(get|start|discover|learn|find|try|join|build|create|how to)/i', $h );
			} ) );
			if ( $questions > count( $heading_styles ) * 0.3 ) {
				$heading_style = 'question-based';
			} elseif ( $actions > count( $heading_styles ) * 0.3 ) {
				$heading_style = 'action-oriented';
			}
		}

		$cta_patterns = array_unique( $cta_texts );
		$cta_patterns = array_slice( $cta_patterns, 0, 10 );

		return array(
			'total_pages'          => $total_pages,
			'total_words'          => $total_words,
			'avg_length'           => $avg_length,
			'tone'                 => $tone,
			'dominant_voice'       => $dominant_voice,
			'person_counts'        => $person_counts,
			'heading_style'        => $heading_style,
			'sample_headings'      => array_slice( $heading_styles, 0, 10 ),
			'uses_lists'           => $has_lists > ( $total_pages * 0.3 ),
			'list_usage_percent'   => $total_pages > 0 ? round( ( $has_lists / $total_pages ) * 100 ) : 0,
			'avg_images_per_page'  => $avg_images,
			'cta_patterns'         => $cta_patterns,
		);
	}

	/**
	 * Extract people/team info — counts only, no raw PII sent to AI.
	 */
	private function extract_people(): array {
		$page_ids = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 150,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		$all_text = '';
		foreach ( $page_ids as $page_id ) {
			$all_text .= ' ' . wp_strip_all_tags( get_post_field( 'post_content', $page_id ) );
		}

		preg_match_all( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $all_text, $emails );
		preg_match_all( '/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $all_text, $phones );

		$admin_count = count( get_users( array( 'role__in' => array( 'administrator', 'editor', 'author' ), 'fields' => 'ID' ) ) );

		return array(
			'team_member_count' => $admin_count,
			'has_contact_info'  => ! empty( $emails[0] ) || ! empty( $phones[0] ),
			'email_count'       => count( array_unique( $emails[0] ?? array() ) ),
			'phone_count'       => count( array_unique( $phones[0] ?? array() ) ),
		);
	}

	/**
	 * Scrub PII from any string before sending to AI.
	 */
	private function scrub_pii( string $text ): string {
		// Redact emails.
		$text = preg_replace( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[email]', $text );
		// Redact phone numbers.
		$text = preg_replace( '/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', '[phone]', $text );
		// Redact US addresses.
		$text = preg_replace( '/\d{1,5}\s[\w\s]{5,40},?\s[\w\s]{2,20},?\s[A-Z]{2}\s\d{5}/', '[address]', $text );
		return $text;
	}

	private function analyze_structure(): array {
		$structure = array();

		$pages     = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
		$top_level = 0;
		$children  = 0;
		foreach ( $pages as $page ) {
			if ( 0 === $page->post_parent ) {
				$top_level++;
			} else {
				$children++;
			}
		}
		$structure['page_hierarchy'] = array(
			'top_level_pages' => $top_level,
			'child_pages'     => $children,
			'total'           => count( $pages ),
		);

		$structure['has_blog']      = wp_count_posts( 'post' )->publish > 0;
		$structure['has_shop']      = class_exists( 'WooCommerce' ) && wp_count_posts( 'product' )->publish > 0;
		$structure['has_portfolio'] = post_type_exists( 'portfolio' ) || post_type_exists( 'project' );

		$taxonomies  = get_taxonomies( array( 'public' => true ), 'objects' );
		$active_taxes = array();
		foreach ( $taxonomies as $tax ) {
			$count = wp_count_terms( $tax->name );
			if ( is_wp_error( $count ) ) {
				$count = 0;
			}
			if ( $count > 0 ) {
				$active_taxes[] = array( 'name' => $tax->label, 'slug' => $tax->name, 'count' => $count );
			}
		}
		$structure['active_taxonomies'] = $active_taxes;

		$menus              = wp_get_nav_menus();
		$structure['menus'] = count( $menus );

		$structure['homepage_type'] = 'page' === get_option( 'show_on_front' ) ? 'static page' : 'blog listing';

		return $structure;
	}

	private function analyze_technical(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$technical = array(
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => phpversion(),
			'theme'          => wp_get_theme()->get( 'Name' ),
			'theme_version'  => wp_get_theme()->get( 'Version' ),
			'active_plugins' => array(),
			'editor_type'    => 'block',
		);

		$active = get_option( 'active_plugins', array() );
		foreach ( $active as $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( file_exists( $plugin_path ) ) {
				$plugin_data = get_plugin_data( $plugin_path, false, false );
				if ( ! empty( $plugin_data['Name'] ) ) {
					$technical['active_plugins'][] = $plugin_data['Name'];
				}
			}
		}

		if ( in_array( 'classic-editor/classic-editor.php', $active, true ) ) {
			$technical['editor_type'] = 'classic';
		}

		$plugin_names                      = implode( ' ', $technical['active_plugins'] );
		$technical['has_seo_plugin']        = (bool) preg_match( '/yoast|rank math|all in one seo/i', $plugin_names );
		$technical['has_security_plugin']   = (bool) preg_match( '/wordfence|sucuri|ithemes security|solid security/i', $plugin_names );
		$technical['has_cache_plugin']      = (bool) preg_match( '/wp super cache|w3 total|litespeed|wp rocket|autoptimize/i', $plugin_names );
		$technical['has_form_plugin']       = (bool) preg_match( '/contact form 7|wpforms|gravity|formidable|ninja forms/i', $plugin_names );

		return $technical;
	}

	private function analyze_woocommerce(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$products    = wc_get_products( array( 'limit' => 150, 'status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC' ) );
		$prices      = array();
		$desc_lengths = array();
		$categories  = array();

		foreach ( $products as $product ) {
			$price = (float) $product->get_regular_price();
			if ( $price > 0 ) {
				$prices[] = $price;
			}

			$desc           = wp_strip_all_tags( $product->get_description() );
			$desc_lengths[] = str_word_count( $desc );

			$cats       = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $cats ) ) {
				$categories = array_merge( $categories, $cats );
			}
		}

		$categories = array_unique( $categories );

		return array(
			'total_products'         => count( $products ),
			'price_range'            => ! empty( $prices ) ? array(
				'min' => min( $prices ),
				'max' => max( $prices ),
				'avg' => round( array_sum( $prices ) / count( $prices ), 2 ),
			) : null,
			'avg_description_length' => ! empty( $desc_lengths ) ? round( array_sum( $desc_lengths ) / count( $desc_lengths ) ) : 0,
			'product_categories'     => array_slice( $categories, 0, 20 ),
			'currency'               => get_woocommerce_currency(),
			'currency_symbol'        => get_woocommerce_currency_symbol(),
			'store_country'          => WC()->countries->get_base_country(),
		);
	}

	// === HELPER METHODS ===

	private function get_all_content_text(): string {
		$page_ids = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 150,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		$text = '';
		foreach ( $page_ids as $page_id ) {
			$text .= ' ' . wp_strip_all_tags( get_post_field( 'post_content', $page_id ) );
			$text .= ' ' . get_the_title( $page_id );
		}
		return $text;
	}

	private function detect_industry( string $text ): string {
		$text       = strtolower( $text );
		$industries = array(
			'fitness'      => array( 'gym', 'workout', 'fitness', 'exercise', 'training', 'muscle', 'weight loss', 'personal trainer' ),
			'restaurant'   => array( 'menu', 'restaurant', 'dining', 'cuisine', 'chef', 'reservation', 'food', 'dish' ),
			'ecommerce'    => array( 'shop', 'buy', 'cart', 'checkout', 'shipping', 'product', 'order', 'price' ),
			'technology'   => array( 'software', 'developer', 'app', 'code', 'tech', 'saas', 'platform', 'api' ),
			'healthcare'   => array( 'patient', 'doctor', 'medical', 'health', 'clinic', 'treatment', 'hospital', 'therapy' ),
			'education'    => array( 'course', 'student', 'learn', 'training', 'education', 'lesson', 'school', 'teacher' ),
			'real_estate'  => array( 'property', 'real estate', 'listing', 'apartment', 'house', 'rent', 'mortgage', 'realtor' ),
			'legal'        => array( 'attorney', 'lawyer', 'legal', 'law firm', 'litigation', 'counsel', 'case' ),
			'finance'      => array( 'investment', 'finance', 'accounting', 'tax', 'bank', 'insurance', 'financial' ),
			'marketing'    => array( 'marketing', 'seo', 'social media', 'brand', 'advertising', 'campaign', 'content' ),
			'photography'  => array( 'photo', 'photography', 'portrait', 'wedding', 'shoot', 'gallery', 'studio' ),
			'construction' => array( 'construction', 'building', 'contractor', 'renovation', 'remodel', 'plumbing', 'electrical' ),
			'beauty'       => array( 'salon', 'spa', 'beauty', 'hair', 'skincare', 'nails', 'massage', 'facial' ),
			'automotive'   => array( 'car', 'auto', 'vehicle', 'repair', 'mechanic', 'dealership', 'tire' ),
			'nonprofit'    => array( 'donate', 'nonprofit', 'charity', 'volunteer', 'mission', 'cause', 'community' ),
		);

		$scores = array();
		foreach ( $industries as $industry => $keywords ) {
			$score = 0;
			foreach ( $keywords as $kw ) {
				$score += substr_count( $text, $kw );
			}
			$scores[ $industry ] = $score;
		}

		arsort( $scores );
		$top       = array_slice( $scores, 0, 3, true );
		$first_key = array_key_first( $top );
		if ( $top[ $first_key ] < 3 ) {
			return 'general/unknown';
		}

		return $first_key;
	}

	private function detect_tone( string $text ): string {
		$text       = strtolower( $text );
		$word_count = str_word_count( $text );
		if ( 0 === $word_count ) {
			return 'unknown';
		}

		$indicators = array(
			'formal'      => array( 'therefore', 'furthermore', 'consequently', 'hereby', 'whereas', 'pursuant', 'accordingly', 'henceforth' ),
			'casual'      => array( 'hey', 'awesome', 'cool', 'gonna', 'wanna', 'stuff', 'things', 'pretty much', 'super', 'amazing', 'love' ),
			'technical'   => array( 'implementation', 'architecture', 'algorithm', 'framework', 'infrastructure', 'optimization', 'integration', 'configuration' ),
			'friendly'    => array( 'welcome', 'happy to', 'we love', 'thank you', 'appreciate', 'glad', 'excited', 'feel free', 'reach out' ),
			'professional' => array( 'solutions', 'expertise', 'dedicated', 'committed', 'excellence', 'quality', 'services', 'experience', 'trusted' ),
			'persuasive'  => array( 'discover', 'transform', 'exclusive', 'limited', 'guarantee', 'proven', 'results', 'unlock', 'boost', 'skyrocket' ),
		);

		$scores = array();
		foreach ( $indicators as $tone => $words ) {
			$score = 0;
			foreach ( $words as $word ) {
				$score += substr_count( $text, $word );
			}
			$scores[ $tone ] = $score;
		}

		arsort( $scores );
		$top  = array_slice( $scores, 0, 2, true );
		$keys = array_keys( $top );

		if ( $top[ $keys[0] ] < 2 ) {
			return 'neutral';
		}

		if ( count( $keys ) > 1 && $top[ $keys[1] ] > $top[ $keys[0] ] * 0.5 ) {
			return $keys[0] . ' with ' . $keys[1] . ' elements';
		}

		return $keys[0];
	}

	private function extract_key_topics( string $text, int $limit = 10 ): array {
		$text  = strtolower( $text );
		$text  = preg_replace( '/[^a-z\s]/', '', $text );
		$words = explode( ' ', $text );
		$words = array_filter( $words, function ( $w ) {
			return strlen( $w ) > 3;
		} );

		$bigrams     = array();
		$word_values = array_values( $words );
		for ( $i = 0; $i < count( $word_values ) - 1; $i++ ) {
			$bigram = $word_values[ $i ] . ' ' . $word_values[ $i + 1 ];
			$skip   = array( 'this that', 'more info', 'read more', 'click here', 'learn more', 'find more', 'with the', 'from the', 'about the' );
			if ( ! in_array( $bigram, $skip, true ) ) {
				$bigrams[ $bigram ] = ( $bigrams[ $bigram ] ?? 0 ) + 1;
			}
		}

		arsort( $bigrams );
		$bigrams = array_filter( $bigrams, function ( $count ) {
			return $count >= 2;
		} );

		return array_slice( array_keys( $bigrams ), 0, $limit );
	}

	private function extract_frequent_terms( string $text, int $limit = 15 ): array {
		$text  = strtolower( $text );
		$text  = preg_replace( '/[^a-z\s]/', '', $text );
		$words = array_count_values( str_word_count( $text, 1 ) );

		$stop_words = array( 'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was', 'one', 'our', 'out', 'with', 'they', 'been', 'have', 'from', 'this', 'that', 'will', 'your', 'what', 'when', 'make', 'like', 'just', 'know', 'take', 'come', 'could', 'than', 'look', 'only', 'also', 'back', 'after', 'use', 'how', 'more', 'about', 'which', 'their', 'these', 'some', 'them', 'into', 'other', 'then', 'there', 'would', 'each', 'where', 'does', 'most', 'over', 'such', 'being', 'through', 'much', 'before', 'between' );

		foreach ( $stop_words as $sw ) {
			unset( $words[ $sw ] );
		}

		$words = array_filter( $words, function ( $count, $word ) {
			return strlen( $word ) > 4 && $count >= 2;
		}, ARRAY_FILTER_USE_BOTH );

		arsort( $words );
		return array_slice( array_keys( $words ), 0, $limit );
	}

	/**
	 * Generate a natural language summary for the AI system prompt.
	 */
	private function generate_ai_summary( array $profile ): string {
		$summary = "SITE PROFILE (auto-generated from content analysis):\n\n";

		// Identity.
		$id = $profile['identity'];
		$summary .= "Business: \"{$id['name']}\"";
		if ( ! empty( $id['tagline'] ) ) {
			$summary .= " — \"{$id['tagline']}\"";
		}
		$summary .= "\n";
		$summary .= "Industry: {$id['detected_industry']}\n";
		if ( ! empty( $id['key_services'] ) ) {
			$summary .= 'Key topics/services: ' . implode( ', ', array_slice( $id['key_services'], 0, 8 ) ) . "\n";
		}
		if ( ! empty( $id['brand_terms'] ) ) {
			$summary .= 'Frequently used brand terms: ' . implode( ', ', array_slice( $id['brand_terms'], 0, 10 ) ) . "\n";
		}

		// Content DNA.
		$dna = $profile['content_dna'];
		$summary .= "\nContent Style:\n";
		$summary .= "- Tone: {$dna['tone']}\n";
		$summary .= "- Voice: {$dna['dominant_voice']}\n";
		$summary .= "- Average page length: {$dna['avg_length']} words\n";
		$summary .= "- Heading style: {$dna['heading_style']}\n";
		$summary .= '- Uses lists: ' . ( $dna['uses_lists'] ? "yes ({$dna['list_usage_percent']}% of pages)" : 'rarely' ) . "\n";
		if ( ! empty( $dna['cta_patterns'] ) ) {
			$summary .= '- Common CTAs: ' . implode( ', ', array_slice( $dna['cta_patterns'], 0, 5 ) ) . "\n";
		}
		if ( ! empty( $dna['sample_headings'] ) ) {
			$summary .= '- Example headings from site: ' . implode( ' | ', array_slice( $dna['sample_headings'], 0, 5 ) ) . "\n";
		}

		// People (counts only — no PII sent to AI).
		$people = $profile['people'];
		if ( ! empty( $people['team_member_count'] ) ) {
			$summary .= "\nTeam: {$people['team_member_count']} admin/editor/author users\n";
		}
		if ( ! empty( $people['has_contact_info'] ) ) {
			$summary .= "Site has contact info published ({$people['email_count']} emails, {$people['phone_count']} phone numbers)\n";
		}

		// Structure.
		$struct = $profile['structure'];
		$summary .= "\nSite Structure:\n";
		$summary .= "- {$struct['page_hierarchy']['total']} pages ({$struct['page_hierarchy']['top_level_pages']} top-level, {$struct['page_hierarchy']['child_pages']} children)\n";
		$summary .= "- Homepage type: {$struct['homepage_type']}\n";
		$summary .= '- Has blog: ' . ( $struct['has_blog'] ? 'yes' : 'no' ) . "\n";
		$summary .= '- Has shop: ' . ( $struct['has_shop'] ? 'yes' : 'no' ) . "\n";

		// WooCommerce.
		if ( ! empty( $profile['woocommerce'] ) ) {
			$wc = $profile['woocommerce'];
			$summary .= "\nStore Profile:\n";
			$summary .= "- {$wc['total_products']} products\n";
			if ( $wc['price_range'] ) {
				$summary .= "- Price range: {$wc['currency_symbol']}{$wc['price_range']['min']} - {$wc['currency_symbol']}{$wc['price_range']['max']} (avg: {$wc['currency_symbol']}{$wc['price_range']['avg']})\n";
			}
			$summary .= '- Product categories: ' . implode( ', ', array_slice( $wc['product_categories'], 0, 10 ) ) . "\n";
			$summary .= "- Avg product description: {$wc['avg_description_length']} words\n";
		}

		// Instructions to AI.
		$summary .= "\nIMPORTANT: When generating ANY content for this site:\n";
		$summary .= "- Match the {$dna['tone']} tone and {$dna['dominant_voice']} voice\n";
		$summary .= "- Aim for ~{$dna['avg_length']} words per page (matching existing content length)\n";
		$summary .= "- Use {$dna['heading_style']} heading style\n";
		$summary .= "- Reference the business as \"{$id['name']}\" (never make up a different name)\n";
		if ( ! empty( $id['key_services'] ) ) {
			$summary .= '- Core topics to be aware of: ' . implode( ', ', array_slice( $id['key_services'], 0, 5 ) ) . "\n";
		}

		return $summary;
	}

	/**
	 * Extract brand-relevant design tokens from theme.json.
	 * Lightweight subset: brand colors + content width only.
	 */
	private function extract_design_system(): array {
		$settings = wp_get_global_settings();

		$palette = $settings['color']['palette']['theme']
			?? $settings['color']['palette']['default']
			?? array();

		$colors = array_map( fn( $c ) => array(
			'slug'  => $c['slug'],
			'name'  => $c['name'],
			'color' => $c['color'],
		), array_slice( $palette, 0, 12 ) );

		$content_width = $settings['layout']['contentSize'] ?? null;
		$wide_width    = $settings['layout']['wideSize'] ?? null;

		return array(
			'brand_colors'  => $colors,
			'content_width' => $content_width,
			'wide_width'    => $wide_width,
		);
	}

	// ── Hook Registration ─────────────────────────────────────────────

	/**
	 * Register site-profile WordPress hooks.
	 *
	 * @since 4.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'pressark_generate_profile', array( self::class, 'handle_generate' ) );
		add_action( 'init', array( self::class, 'schedule_refresh' ) );
		add_action( 'pressark_refresh_profile', array( self::class, 'handle_refresh' ) );
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_generate(): void {
		( new self() )->generate();
	}

	/**
	 * @since 4.2.0
	 */
	public static function schedule_refresh(): void {
		if ( ! wp_next_scheduled( 'pressark_refresh_profile' ) ) {
			wp_schedule_event( time(), 'weekly', 'pressark_refresh_profile' );
		}
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_refresh(): void {
		( new self() )->generate();
	}
}
