<?php
/**
 * PressArk planning-policy decisions.
 *
 * @package PressArk
 * @since   5.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Planning_Policy {

	public const MODE_NONE      = 'none';
	public const MODE_SOFT_PLAN = 'soft_plan';
	public const MODE_HARD_PLAN = 'hard_plan';

	/**
	 * Decide whether a request should skip planning, show a soft plan, or stop
	 * for an approval-gated hard plan.
	 *
	 * @param string $message          User request.
	 * @param array  $permission_probe Best-effort permission/tool probe.
	 * @param int    $async_score      Async score from PressArk_Task_Queue.
	 * @param array  $context          Optional scoring context.
	 * @return array<string,mixed>
	 */
	public function decide( string $message, array $permission_probe, int $async_score, array $context = array() ): array {
		$message         = wp_check_invalid_utf8( $message );
		$trimmed         = trim( $message );
		$explicit_plan   = ! empty( $context['explicit_plan'] );
		$suppress_plan   = ! empty( $context['suppress_plan'] ) || ! empty( $context['plan_execute'] );
		$continuation_mode = sanitize_key( (string) ( $context['continuation_mode'] ?? '' ) );
		$legacy_signal   = ! empty( $context['legacy_plan_signal'] );
		$tool_candidates = $this->sanitize_tool_candidates( $context['predicted_tools'] ?? array() );
		$groups          = $this->predict_groups( $trimmed, $permission_probe, $tool_candidates, $context );
		$group_count     = count( $groups );
		$intent          = sanitize_key( (string) ( $permission_probe['intent'] ?? '' ) );
		$ui_action       = sanitize_key( (string) ( $permission_probe['ui_action'] ?? '' ) );
		$writes_likely   = 'write' === $intent || $this->message_likely_requests_write( $trimmed );
		$commerce_critical_write = $this->is_commerce_critical_write( $trimmed, $groups, $permission_probe, $writes_likely );

		$predicted_write_count = $this->predict_write_count( $trimmed, $writes_likely, $permission_probe );
		$destructive_score     = $this->destructive_score( $trimmed, $permission_probe );
		$breadth_score         = $this->breadth_score( $trimmed, $group_count, $predicted_write_count, $async_score );
		$uncertainty_score     = $this->uncertainty_score( $trimmed, $permission_probe, $context, $writes_likely );
		$needs_discovery       = $this->needs_discovery( $trimmed, $group_count, $async_score, $writes_likely, $uncertainty_score );
		$risk_score            = $this->risk_score( $permission_probe, $groups, $writes_likely, $destructive_score, $breadth_score, $uncertainty_score, $async_score, $commerce_critical_write );
		$complexity_score      = $this->complexity_score( $trimmed, $writes_likely, $group_count, $predicted_write_count, $async_score, $breadth_score, $uncertainty_score, $needs_discovery );
		$reads_first           = $needs_discovery || $writes_likely || $group_count > 1 || $async_score > 0;

		$reason_codes = array();
		if ( $explicit_plan ) {
			$reason_codes[] = 'explicit_plan_directive';
		}
		if ( $legacy_signal ) {
			$reason_codes[] = 'legacy_plan_signal';
		}
		if ( $writes_likely ) {
			$reason_codes[] = 'predicted_write';
		}
		if ( $commerce_critical_write ) {
			$reason_codes[] = 'commerce_critical_write';
		}
		if ( $group_count > 1 ) {
			$reason_codes[] = 'multi_domain';
		}
		if ( $predicted_write_count > 1 ) {
			$reason_codes[] = 'multi_entity_write';
		}
		if ( $destructive_score >= 5 ) {
			$reason_codes[] = 'destructive_operation';
		}
		if ( $breadth_score >= 6 ) {
			$reason_codes[] = 'broad_scope';
		}
		if ( $uncertainty_score >= 5 ) {
			$reason_codes[] = 'ambiguous_target';
		}
		if ( $needs_discovery ) {
			$reason_codes[] = 'discovery_required';
		}
		if ( $async_score >= $this->async_hard_threshold() && $writes_likely ) {
			$reason_codes[] = 'async_heavy_write';
		}

		if ( $explicit_plan ) {
			return $this->build_decision(
				self::MODE_HARD_PLAN,
				true,
				true,
				$reason_codes,
				$complexity_score,
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				$predicted_write_count,
				$group_count
			);
		}

		if ( 'plan' === $continuation_mode ) {
			return $this->build_decision(
				self::MODE_HARD_PLAN,
				true,
				true,
				array_values( array_unique( array_merge( $reason_codes, array( 'continuation_plan_resume' ) ) ) ),
				$complexity_score,
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				$predicted_write_count,
				$group_count
			);
		}

		if ( 'execute' === $continuation_mode ) {
			return $this->build_decision(
				self::MODE_NONE,
				false,
				false,
				array_values( array_unique( array_merge( $reason_codes, array( 'continuation_execute_resume' ) ) ) ),
				$complexity_score,
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				$predicted_write_count,
				$group_count
			);
		}

		if ( $suppress_plan ) {
			return $this->build_decision(
				self::MODE_NONE,
				false,
				false,
				array_values( array_unique( array_merge( $reason_codes, array( 'approved_plan_execution' ) ) ) ),
				$complexity_score,
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				$predicted_write_count,
				$group_count
			);
		}

		if ( $this->is_resolved_single_target_followup_write( $trimmed, $writes_likely, $context, $commerce_critical_write, $destructive_score, $predicted_write_count ) ) {
			// v5.8.15 (2026-05-14): resolved same-target follow-up writes use preview flow, not read-only hard Plan Mode.
			return $this->build_decision(
				self::MODE_NONE,
				false,
				false,
				array_values( array_unique( array_merge( $reason_codes, array( 'resolved_single_target_write' ) ) ) ),
				min( $complexity_score, 3 ),
				min( $risk_score, 3 ),
				min( $breadth_score, 2 ),
				min( $uncertainty_score, 1 ),
				$destructive_score,
				1,
				1
			);
		}

		$small_protected_write = $writes_likely
			&& $predicted_write_count <= 1
			&& $group_count <= 1
			&& $destructive_score <= 2
			&& $uncertainty_score <= 2
			&& $breadth_score <= 2
			&& in_array( $ui_action, array( 'preview', 'confirm' ), true )
			&& ! $commerce_critical_write;

		if ( ! $writes_likely && $complexity_score <= 2 && $risk_score <= 2 && $breadth_score <= 1 && $uncertainty_score <= 2 ) {
			return $this->build_decision(
				self::MODE_NONE,
				false,
				$reads_first,
				array_values( array_unique( array_merge( $reason_codes, array( 'low_risk_read' ) ) ) ),
				$complexity_score,
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				0,
				$group_count
			);
		}

		if ( $small_protected_write ) {
			return $this->build_decision(
				self::MODE_NONE,
				false,
				false,
				array_values( array_unique( array_merge( $reason_codes, array( 'small_preview_protected_write' ) ) ) ),
				max( 1, $complexity_score ),
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				$predicted_write_count,
				$group_count
			);
		}

		$hard_plan = $legacy_signal
			|| $predicted_write_count >= 3
			|| $breadth_score >= 6
			|| $destructive_score >= 5
			|| ( $async_score >= $this->async_hard_threshold() && $writes_likely )
			|| ( $needs_discovery && $writes_likely && $risk_score >= 5 );

		if ( defined( 'PRESSARK_DEBUG_ROUTE' ) && PRESSARK_DEBUG_ROUTE && self::route_debug_env_ok() ) {
			$log_path = defined( 'PRESSARK_DEBUG_ROUTE_LOG' ) ? (string) PRESSARK_DEBUG_ROUTE_LOG : '/tmp/pressark-route.log';
			@file_put_contents(
				$log_path,
				sprintf(
					"[%s] POLICY hp=%d legacy=%d writes=%d breadth=%d destructive=%d uncertainty=%d async=%d writes_likely=%d discovery=%d risk=%d groups=%d complexity=%d\n",
					gmdate( 'H:i:s' ),
					$hard_plan ? 1 : 0,
					$legacy_signal ? 1 : 0,
					$predicted_write_count,
					$breadth_score,
					$destructive_score,
					$uncertainty_score,
					$async_score,
					$writes_likely ? 1 : 0,
					$needs_discovery ? 1 : 0,
					$risk_score,
					$group_count,
					$complexity_score
				),
				FILE_APPEND
			);
		}

		if ( $hard_plan ) {
			return $this->build_decision(
				self::MODE_HARD_PLAN,
				true,
				true,
				$reason_codes,
				$complexity_score,
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				$predicted_write_count,
				$group_count
			);
		}

		$soft_plan = $complexity_score >= 4
			|| $writes_likely
			|| $group_count > 1
			|| $needs_discovery
			|| $async_score >= max( 1, (int) floor( $this->async_hard_threshold() / 2 ) );

		if ( $soft_plan ) {
			return $this->build_decision(
				self::MODE_SOFT_PLAN,
				false,
				true,
				array_values( array_unique( array_merge( $reason_codes, array( 'contained_multi_step_work' ) ) ) ),
				$complexity_score,
				$risk_score,
				$breadth_score,
				$uncertainty_score,
				$destructive_score,
				max( 1, $predicted_write_count ),
				max( 1, $group_count )
			);
		}

		return $this->build_decision(
			self::MODE_NONE,
			false,
			$reads_first,
			array_values( array_unique( array_merge( $reason_codes, array( 'direct_execution_ok' ) ) ) ),
			$complexity_score,
			$risk_score,
			$breadth_score,
			$uncertainty_score,
			$destructive_score,
			$predicted_write_count,
			$group_count
		);
	}

	/**
	 * Build a normalized decision payload.
	 *
	 * @return array<string,mixed>
	 */
	private function build_decision(
		string $mode,
		bool $approval_required,
		bool $reads_first,
		array $reason_codes,
		int $complexity_score,
		int $risk_score,
		int $breadth_score,
		int $uncertainty_score,
		int $destructive_score,
		int $predicted_write_count,
		int $predicted_domain_count
	): array {
		$mode = in_array( $mode, array( self::MODE_NONE, self::MODE_SOFT_PLAN, self::MODE_HARD_PLAN ), true )
			? $mode
			: self::MODE_NONE;

		return array(
			'mode'                   => $mode,
			'approval_required'      => $approval_required,
			'reads_first'            => $reads_first,
			'reason_codes'           => array_values( array_slice( array_unique( array_filter( array_map( 'sanitize_key', $reason_codes ) ) ), 0, 12 ) ),
			'complexity_score'       => max( 0, min( 10, $complexity_score ) ),
			'risk_score'             => max( 0, min( 10, $risk_score ) ),
			'breadth_score'          => max( 0, min( 10, $breadth_score ) ),
			'uncertainty_score'      => max( 0, min( 10, $uncertainty_score ) ),
			'destructive_score'      => max( 0, min( 10, $destructive_score ) ),
			'predicted_write_count'  => max( 0, $predicted_write_count ),
			'predicted_domain_count' => max( 0, $predicted_domain_count ),
		);
	}

	/**
	 * Predict likely operating domains from the request and candidate tools.
	 *
	 * @return string[]
	 */
	private function predict_groups( string $message, array $permission_probe, array $tool_candidates, array $context = array() ): array {
		$groups = array();
		$probe_group = $this->normalize_group_label( (string) ( $permission_probe['group'] ?? '' ) );
		if ( '' !== $probe_group ) {
			$groups[] = $probe_group;
		}

		foreach ( $tool_candidates as $candidate ) {
			$group = $this->normalize_group_label( (string) ( $candidate['group'] ?? '' ) );
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}

		$keyword_groups = array(
			'content'      => '/\b(?:post|page|content|copy|headline|article|blog|draft|title|cta)\b/i',
			'seo'          => '/\b(?:seo|meta|schema|slug|rank|yoast|search console|keyword)\b/i',
			'woocommerce'  => '/\b(?:woocommerce|product|products|order|orders|cart|checkout|inventory|sku|price)\b/i',
			'elementor'    => '/\b(?:elementor|widget|section|template|landing page)\b/i',
			'system'       => '/\b(?:setting|settings|plugin|plugins|theme|themes|site|users?|roles?|capabilities|maintenance|cache)\b/i',
			'media'        => '/\b(?:image|images|media|gallery|alt text|thumbnail|video)\b/i',
		);

		foreach ( $keyword_groups as $group => $pattern ) {
			if ( 1 === preg_match( $pattern, $message ) ) {
				$groups[] = $group;
			}
		}

		if ( ! empty( $context['screen'] ) && false !== strpos( (string) $context['screen'], 'woocommerce' ) ) {
			$groups[] = 'woocommerce';
		}
		if ( ! empty( $context['post_id'] ) ) {
			$groups[] = 'content';
		}

		return array_values( array_slice( array_unique( array_filter( $groups ) ), 0, 6 ) );
	}

	/**
	 * Score request breadth.
	 */
	private function breadth_score( string $message, int $group_count, int $predicted_write_count, int $async_score ): int {
		$score = 0;
		if ( 1 === preg_match( '/\b(?:all|every|each|bulk|batch|site-?wide|across|entire|global)\b/i', $message ) ) {
			$score += 5;
		}
		if ( 1 === preg_match( '/\b(?:multiple|several|many|dozens?|hundreds?)\b/i', $message ) ) {
			$score += 3;
		}
		if ( $predicted_write_count >= 3 ) {
			$score += 3;
		}
		if ( $predicted_write_count >= 10 ) {
			$score += 2;
		}
		if ( $group_count >= 2 ) {
			$score += 2;
		}
		if ( $group_count >= 3 ) {
			$score += 1;
		}
		if ( $async_score >= $this->async_hard_threshold() ) {
			$score += 2;
		}

		return min( 10, $score );
	}

	/**
	 * Score ambiguity and targeting uncertainty.
	 */
	private function uncertainty_score( string $message, array $permission_probe, array $context, bool $writes_likely ): int {
		$score   = 0;
		$post_id = absint( $context['post_id'] ?? 0 );

		if ( 0 === $post_id && 1 === preg_match( '/\b(?:this|that|it|them|those|these)\b/i', $message ) ) {
			$score += 2;
		}
		if ( 1 === preg_match( '/\b(?:find|choose|pick|decide|figure out|research|compare|best)\b/i', $message ) ) {
			$score += 3;
		}
		if ( $writes_likely && 1 === preg_match( '/\b(?:maybe|probably|some|a few|whatever|whichever)\b/i', $message ) ) {
			$score += 2;
		}
		if ( $writes_likely && empty( $permission_probe ) ) {
			$score += 1;
		}
		if ( $writes_likely && 1 === preg_match( '/\b(?:which|what)\s+(?:one|post|page|product|item|settings?)\b/i', $message ) ) {
			$score += 3;
		}

		return min( 10, $score );
	}

	/**
	 * Score destructive or hard-to-undo intent.
	 */
	private function destructive_score( string $message, array $permission_probe ): int {
		$score = 0;
		if ( 1 === preg_match( '/\b(?:delete|remove|trash|purge|wipe|reset|overwrite|deactivate|disable|uninstall|revoke)\b/i', $message ) ) {
			$score += 7;
		}
		// v5.8.12 (2026-05-14): treat "clear" as destructive only with destructive objects, not CTA clarity.
		if ( 1 === preg_match( '/\bclear(?:\s+(?:all|every|cache|caches|transients?|settings?|options?|logs?|orders?|products?|posts?|pages?|content|cart|stock|inventory|sale|sales|coupons?))\b/i', $message ) ) {
			$score += 7;
		}
		if ( 1 === preg_match( '/\b(?:replace all|bulk replace|mass update|change every)\b/i', $message ) ) {
			$score += 2;
		}
		if ( 'settings' === $this->normalize_group_label( (string) ( $permission_probe['group'] ?? '' ) ) ) {
			$score += 1;
		}

		return min( 10, $score );
	}

	/**
	 * Score overall risk.
	 *
	 * @param string[] $groups Detected domain groups.
	 */
	private function risk_score(
		array $permission_probe,
		array $groups,
		bool $writes_likely,
		int $destructive_score,
		int $breadth_score,
		int $uncertainty_score,
		int $async_score,
		bool $commerce_critical_write = false
	): int {
		$score = (int) round( $destructive_score * 0.6 );
		$score += (int) round( $breadth_score * 0.4 );
		$score += (int) round( $uncertainty_score * 0.4 );

		if ( $writes_likely ) {
			$score += 2;
		}
		if ( count( $groups ) >= 2 ) {
			$score += 2;
		}
		if ( in_array( 'system', $groups, true ) ) {
			$score += 2;
		}
		if ( $async_score >= $this->async_hard_threshold() ) {
			$score += 1;
		}
		if ( $commerce_critical_write ) {
			$score += 2;
		}
		if ( 'ask' === sanitize_key( (string) ( $permission_probe['behavior'] ?? '' ) ) && ! empty( $permission_probe['tool_name'] ) ) {
			$score += 1;
		}

		return min( 10, $score );
	}

	/**
	 * Score total execution complexity.
	 */
	private function complexity_score(
		string $message,
		bool $writes_likely,
		int $group_count,
		int $predicted_write_count,
		int $async_score,
		int $breadth_score,
		int $uncertainty_score,
		bool $needs_discovery
	): int {
		$score = 1;

		if ( $writes_likely ) {
			$score += 2;
		}
		if ( $group_count >= 2 ) {
			$score += 2;
		}
		if ( $predicted_write_count >= 2 ) {
			$score += 2;
		}
		if ( $async_score >= 1 ) {
			$score += 1;
		}
		if ( $async_score >= $this->async_hard_threshold() ) {
			$score += 2;
		}
		if ( mb_strlen( $message ) > 140 ) {
			$score += 1;
		}
		if ( 1 === preg_match( '/\b(?:then|after|before|and also|along with|while)\b/i', $message ) ) {
			$score += 1;
		}
		if ( $needs_discovery ) {
			$score += 1;
		}
		$score += (int) floor( $breadth_score / 4 );
		$score += (int) floor( $uncertainty_score / 4 );

		return min( 10, $score );
	}

	/**
	 * Whether the request likely needs discovery before safe execution.
	 */
	private function needs_discovery( string $message, int $group_count, int $async_score, bool $writes_likely, int $uncertainty_score ): bool {
		if ( $uncertainty_score >= 4 ) {
			return true;
		}
		if ( $group_count >= 2 && $writes_likely ) {
			return true;
		}
		if ( $async_score >= $this->async_hard_threshold() && $writes_likely ) {
			return true;
		}

		return 1 === preg_match( '/\b(?:audit|inspect|analyze|review|research|discover|scan|inventory|compare)\b/i', $message );
	}

	/**
	 * Same-chat "publish it" / "fix 3" commands are already target-resolved by
	 * the request compiler. Treat them as bounded writes and let preview/confirm
	 * approval carry the safety work instead of sending them through hard Plan Mode.
	 */
	private function is_resolved_single_target_followup_write(
		string $message,
		bool $writes_likely,
		array $context,
		bool $commerce_critical_write,
		int $destructive_score,
		int $predicted_write_count
	): bool {
		if (
			! $writes_likely
			|| absint( $context['post_id'] ?? 0 ) <= 0
			|| $commerce_critical_write
			|| $destructive_score > 2
			|| $predicted_write_count > 1
		) {
			return false;
		}

		$normalized = strtolower( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) ) );
		if ( '' === $normalized || mb_strlen( $normalized ) > 160 ) {
			return false;
		}

		$word_count = str_word_count( preg_replace( '/[^\p{L}\p{N}\s%+\-]/u', ' ', $normalized ) );
		if ( $word_count > 14 ) {
			return false;
		}

		$numbered_action = $this->message_looks_like_numbered_action_followup( $normalized );
		if (
			! $numbered_action
			&& 1 !== preg_match( '/\b(?:it|this|that|the\s+(?:draft|page|post|product|item|content|landing\s+page))\b/i', $normalized )
		) {
			return false;
		}

		if ( $numbered_action ) {
			return true;
		}

		return 1 === preg_match(
			'/\b(?:publish|unpublish|draft|private|schedule|make\s+(?:it|this|that|the\s+\w+)\s+live|put\s+(?:it|this|that|the\s+\w+)\s+live|take\s+(?:it|this|that|the\s+\w+)\s+live|move\s+(?:it|this|that|the\s+\w+)\s+to\s+draft|set\s+(?:it|this|that|the\s+\w+)\s+to\s+(?:publish|published|draft|private))\b/i',
			$normalized
		);
	}

	private function message_looks_like_numbered_action_followup( string $message ): bool {
		return 1 === preg_match(
			'/^\s*(?:please\s+)?(?:fix|apply|do|handle|use|run|update|change|make)\s+(?:the\s+)?(?:(?:issue|item|fix)\s+)?(?:#\s*)?(?:\d{1,2}|one|two|three|four|five|first|second|third|fourth|fifth)(?:\s+(?:one|item|issue|fix|result))?\s*$/i',
			$message
		) || 1 === preg_match(
			'/^\s*(?:please\s+)?(?:the\s+)?(?:first|second|third|fourth|fifth)\s+(?:one|item|issue|fix|result)\s*$/i',
			$message
		);
	}

	/**
	 * Detect financially sensitive WooCommerce writes that should not bypass planning.
	 */
	private function is_commerce_critical_write( string $message, array $groups, array $permission_probe, bool $writes_likely ): bool {
		if ( ! $writes_likely ) {
			return false;
		}

		$probe_group = $this->normalize_group_label( (string) ( $permission_probe['group'] ?? '' ) );
		$is_woo      = in_array( 'woocommerce', $groups, true ) || 'woocommerce' === $probe_group;
		if ( ! $is_woo ) {
			return false;
		}

		return 1 === preg_match( '/\b(?:price|pricing|sale|discount|coupon|refund|tax|shipping|gateway|payment|checkout|subscription|billing)\b/i', $message );
	}

	/**
	 * Predict likely write count.
	 */
	private function predict_write_count( string $message, bool $writes_likely, array $permission_probe ): int {
		if ( ! $writes_likely ) {
			return 0;
		}

		if ( preg_match( '/\b(\d+)\s+(?:posts?|pages?|products?|orders?|items?|records?|articles?)\b/i', $message, $matches ) ) {
			return max( 1, min( 50, absint( $matches[1] ) ) );
		}

		if ( preg_match( '/\b(one|two|three|four|five|six|seven|eight|nine|ten)\s+(?:posts?|pages?|products?|orders?|items?|records?|articles?)\b/i', $message, $matches ) ) {
			$map = array(
				'one'   => 1,
				'two'   => 2,
				'three' => 3,
				'four'  => 4,
				'five'  => 5,
				'six'   => 6,
				'seven' => 7,
				'eight' => 8,
				'nine'  => 9,
				'ten'   => 10,
			);
			return (int) ( $map[ strtolower( $matches[1] ) ] ?? 1 );
		}

		if ( 1 === preg_match( '/\b(?:all|every|each|bulk|batch|site-?wide|multiple|many|several)\b/i', $message ) ) {
			return 3;
		}

		return ! empty( $permission_probe['tool_name'] ) ? 1 : 1;
	}

	/**
	 * Basic write-intent heuristic used alongside permission probing.
	 */
	private function message_likely_requests_write( string $message ): bool {
		if ( $this->message_looks_like_numbered_action_followup( strtolower( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) ) ) ) ) {
			return true;
		}

		return 1 === preg_match(
			'/^\s*(?:please\s+)?(?:update|change|edit|modify|rewrite|replace|delete|remove|create|add|set|publish|increase|decrease|raise|lower|append|prepend|rename|move|fix|make)\b/i',
			$message
		) || (
			1 === preg_match( '/\b(?:product|products|catalog|catalogue|store|shop|woo|woocommerce)\b/i', $message )
			&& 1 === preg_match( '/\b(?:price|pricing|sale|discount|markdown|markup|off|regular price|sale price)\b/i', $message )
		);
	}

	/**
	 * Normalize predicted tool rows.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function sanitize_tool_candidates( $rows ): array {
		$clean = array();
		foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, 6 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = array(
				'name'  => sanitize_key( (string) ( $row['name'] ?? '' ) ),
				'group' => $this->normalize_group_label( (string) ( $row['group'] ?? '' ) ),
			);
		}

		return $clean;
	}

	/**
	 * Normalize internal group names into broader planning domains.
	 */
	private function normalize_group_label( string $group ): string {
		$group = sanitize_key( $group );
		if ( '' === $group ) {
			return '';
		}

		return match ( $group ) {
			'core'       => 'content',
			'health'     => 'system',
			'discovery'  => '',
			default      => $group,
		};
	}

	/**
	 * Async pressure threshold used for hard-plan escalation.
	 */
	private function async_hard_threshold(): int {
		return class_exists( 'PressArk_Task_Queue' )
			? (int) PressArk_Task_Queue::ASYNC_THRESHOLD
			: 3;
	}

	/**
	 * Belt-and-suspenders check for route debug logging: only emit when the
	 * PRESSARK_DEBUG_ROUTE constant *and* a dev environment marker are set.
	 * Keeps prod quiet even if someone flips the constant by accident.
	 */
	public static function route_debug_env_ok(): bool {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}
		$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		if ( '' === $host ) {
			return false;
		}
		return 'localhost' === $host
			|| 0 === strpos( $host, '127.0.0.1' )
			|| str_ends_with( $host, '.local' )
			|| str_ends_with( $host, '.test' )
			|| preg_match( '/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $host ) === 1;
	}
}
