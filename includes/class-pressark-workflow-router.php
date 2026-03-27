<?php
/**
 * PressArk Workflow Router — Routes messages to workflows or falls back to agent.
 *
 * v3.2.0: Confidence-scored routing with negative patterns.
 * Each workflow defines positive patterns (with weights) and negative patterns
 * (that suppress false positives). A workflow must exceed the confidence threshold
 * to be selected. Context signals (screen, conversation) boost confidence.
 *
 * @package PressArk
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Workflow_Router {

	/**
	 * Minimum confidence score to select a workflow (0-100).
	 * Below this → fall through to agent.
	 */
	public const CONFIDENCE_THRESHOLD = 40;
	public const AMBIGUITY_MARGIN     = 10;

	/**
	 * Routing rules: workflow class => { patterns, negatives, context_boosts }.
	 *
	 * patterns:       [regex => weight] — positive signals (summed).
	 * negatives:      [regex, ...] — if ANY matches, workflow is rejected.
	 * context_boosts: [condition_key => weight] — bonus when context matches.
	 */
	private const RULES = array(
		'PressArk_Workflow_Content_Create' => array(
			'patterns' => array(
				// "create/write/draft a post/page/article..."
				'/\b(?:create|write|draft|generate|build|make)\b.*\b(?:post|page|article|blog\s+post|landing\s+page|home\s+page|homepage|about\s+page|contact\s+page|sales\s+page)\b/i' => 55,
				// "write me a blog post", "draft me a landing page"
				'/\b(?:write|draft|create)\s+(?:me\s+)?(?:a|an|new|the)?\s*(?:blog\s+post|post|page|article|landing\s+page)\b/i' => 50,
			),
			'negatives' => array(
				// Questions / analysis should not create content.
				'/\b(?:how many|list all|show me|count|analyze|audit|report|export|status|overview)\b/i',
				// Bulk creation belongs in agent/async land.
				'/\b(?:all posts|all pages|every|bulk|batch)\b/i',
				// Existing-content edits belong to the edit workflow.
				'/\b(?:edit|update|rewrite|improve|fix|modify|replace)\b.*\b(?:post|page|article|content|copy|title|excerpt|heading)\b/i',
				// SEO metadata generation is not content creation.
				'/\b(?:meta\s+title|meta\s+description|focus\s+keyword|search\s+snippet)\b/i',
			),
			'context_boosts' => array(),
		),
		'PressArk_Workflow_Content_Edit' => array(
			'patterns' => array(
				// "edit/update/improve/rewrite the content/post/page ..."
				'/\b(?:edit|update|improve|rewrite|fix|change|modify|revise)\b.*\b(?:content|post|page|article|text|copy|title|excerpt|heading)\b/i' => 40,
				// "make the content/post more ..."
				'/\bmake\b.*\b(?:content|post|page|article)\b.*\bmore\b/i' => 45,
				// Specific content editing commands.
				'/\b(?:shorten|lengthen|simplify|formalize|translate|tone)\b.*\b(?:content|post|page|article|text)\b/i' => 50,
				// Common copy traps: hero, headline, CTA, copy refreshes.
				'/\b(?:replace|refresh|polish|tighten)\b.*\b(?:headline|hero|copy|cta)\b/i' => 45,
			),
			'negatives' => array(
				// Questions or analytics — not edits.
				'/\b(?:how many|list all|show me|count|analyze|audit|report|export)\b/i',
				// Bulk operations — too broad for workflow.
				'/\b(?:all posts|all pages|every|bulk|batch|find and replace)\b/i',
				// Creation — different from editing.
				'/\b(?:create|write|draft|generate)\s+(?:a|new|the)\b/i',
			),
			'context_boosts' => array(
				'on_post_editor' => 20,
				'references_this' => 15,
			),
		),
		'PressArk_Workflow_SEO_Fix' => array(
			'patterns' => array(
				// "fix/improve/optimize SEO ..."
				'/\b(?:fix|improve|optimize|update)\b.*\bseo\b/i' => 50,
				// "SEO for [page/post/site]"
				'/\bseo\b.*\bfor\b/i' => 40,
				// "meta title/description"
				'/\b(?:meta\s+title|meta\s+description|meta\s+tag)/i' => 50,
				// "improve search rankings"
				'/\b(?:improve|boost|fix)\b.*\b(?:search\s+rank|rankings?|serp)\b/i' => 40,
				// Title tags and snippets.
				'/\b(?:title\s+tag|search\s+snippet|snippet)\b/i' => 35,
			),
			'negatives' => array(
				// Analysis-only requests — use agent's read tools.
				'/\b(?:analyze|audit|check|scan|report|show)\b.*\bseo\b/i',
				// Bulk SEO — too broad for workflow.
				'/\b(?:all pages|all posts|every|bulk|site-wide)\b.*\bseo\b/i',
				// Creation requests that merely include SEO belong in content creation.
				'/\b(?:create|write|draft|generate|build|make)\b.*\b(?:post|page|article|blog\s+post|content)\b/i',
			),
			'context_boosts' => array(
				'on_post_editor' => 15,
			),
		),
		'PressArk_Workflow_Woo_Ops' => array(
			'patterns' => array(
				// "update/edit product ..."
				'/\b(?:update|edit|change|modify)\b.*\bproduct\b/i' => 45,
				// "update order ..."
				'/\b(?:update|change|mark|set)\b.*\border\b/i' => 45,
				// "create/manage coupon ..."
				'/\b(?:create|manage|add|edit)\b.*\bcoupon\b/i' => 50,
				// "set price/stock ..."
				'/\b(?:set|change|update)\b.*\b(?:price|stock|inventory)\b/i' => 45,
				// Sale/discount adjustments on products.
				'/\b(?:sale|discount)\b.*\b(?:price|product)\b/i' => 40,
			),
			'negatives' => array(
				// Queries and reports — not operations.
				'/\b(?:list|show|how many|report|analyze|revenue|sales summary|top sellers)\b/i',
				// Bulk product edits — too broad for single-target workflow.
				'/\b(?:all products|every product|bulk)\b/i',
			),
			'context_boosts' => array(),
			'requires' => 'WooCommerce',
		),
	);

	/**
	 * Route a message to a workflow or return null for agent fallback.
	 *
	 * v3.2.0: Confidence-scored routing. Returns the highest-confidence
	 * workflow above threshold, or null. Uses conversation history for
	 * domain continuity detection.
	 *
	 * @param string                 $message      User's message.
	 * @param array                  $conversation Conversation history.
	 * @param PressArk_AI_Connector  $ai           AI connector.
	 * @param PressArk_Action_Engine $engine       Action engine.
	 * @param string                 $tier         User's tier.
	 * @param string                 $screen       Current admin screen slug.
	 * @param int                    $post_id      Current post ID.
	 * @return ?PressArk_Workflow_Runner Workflow instance, or null for agent fallback.
	 */
	public function route(
		string                 $message,
		array                  $conversation,
		PressArk_AI_Connector  $ai,
		PressArk_Action_Engine $engine,
		string                 $tier,
		string                 $screen = '',
		int                    $post_id = 0
	): ?PressArk_Workflow_Runner {
		$decision = $this->route_decision( $message, $conversation, $ai, $engine, $tier, $screen, $post_id );
		return $decision['workflow'];
	}

	/**
	 * Return the full workflow routing decision and ambiguity metadata.
	 *
	 * @param string                 $message      User message.
	 * @param array                  $conversation Conversation history.
	 * @param PressArk_AI_Connector  $ai           AI connector.
	 * @param PressArk_Action_Engine $engine       Action engine.
	 * @param string                 $tier         User tier.
	 * @param string                 $screen       Admin screen slug.
	 * @param int                    $post_id      Current post ID.
	 * @return array
	 */
	public function route_decision(
		string                 $message,
		array                  $conversation,
		PressArk_AI_Connector  $ai,
		PressArk_Action_Engine $engine,
		string                 $tier,
		string                 $screen = '',
		int                    $post_id = 0
	): array {
		// v3.6.0: Multi-intent detection — messages with multiple distinct
		// actions connected by sequencing words ("then", "after that", "and
		// also") should go to the agent, not a single-purpose workflow.
		// Workflows are designed for one focused task; the agent handles
		// multi-step orchestration.
		// Continuations should resume through the agent path. The agent knows how
		// to classify from the original request, enforce execution guards, and
		// skip duplicate non-idempotent writes. Letting workflows claim
		// continuation markers can replay create flows instead of finishing the
		// remaining steps.
		if ( $this->is_continuation_message( $message ) ) {
			return array(
				'workflow'       => null,
				'class'          => '',
				'score'          => 0,
				'second_score'   => 0,
				'scores'         => array(),
				'ambiguous'      => false,
				'multi_intent'   => false,
				'needs_premium'  => false,
				'reason'         => 'continuation',
			);
		}

		if ( $this->is_multi_intent( $message ) ) {
			return array(
				'workflow'       => null,
				'class'          => '',
				'score'          => 0,
				'second_score'   => 0,
				'scores'         => array(),
				'ambiguous'      => false,
				'multi_intent'   => true,
				'needs_premium'  => false,
				'reason'         => 'multi_intent',
			);
		}

		$best_class = null;
		$best_score = 0;
		$second_score = 0;
		$scores = array();
		$conv_domain = $this->detect_conversation_domain( $conversation );

		foreach ( self::RULES as $class => $rule ) {
			// Check plugin requirements.
			if ( ! empty( $rule['requires'] ) && ! class_exists( $rule['requires'] ) ) {
				$scores[ $class ] = 0;
				continue;
			}

			$score = $this->score_workflow( $message, $rule, $screen, $post_id, $conv_domain, $class );
			$scores[ $class ] = $score;

			if ( $score > $best_score ) {
				$second_score = $best_score;
				$best_score = $score;
				$best_class = $class;
			} elseif ( $score > $second_score ) {
				$second_score = $score;
			}
		}

		$ambiguous = $best_score >= self::CONFIDENCE_THRESHOLD
			&& $second_score >= self::CONFIDENCE_THRESHOLD
			&& abs( $best_score - $second_score ) <= self::AMBIGUITY_MARGIN;

		$workflow = null;
		if ( $best_class && $best_score >= self::CONFIDENCE_THRESHOLD && ! $ambiguous ) {
			$workflow = new $best_class( $ai, $engine, $tier );
		}

		arsort( $scores );

		return array(
			'workflow'      => $workflow,
			'class'         => $workflow ? $best_class : '',
			'score'         => $best_score,
			'second_score'  => $second_score,
			'scores'        => $scores,
			'ambiguous'     => $ambiguous,
			'multi_intent'  => false,
			'needs_premium' => $ambiguous,
			'reason'        => $workflow
				? 'workflow_match'
				: ( $ambiguous ? 'workflow_ambiguity' : 'below_threshold' ),
		);
	}

	// ── Multi-Intent Detection (v3.6.0) ──────────────────────────────

	/**
	 * Sequential connectors that indicate chained tasks.
	 * "then", "after that", "once done", "and then", "afterwards",
	 * "next", "finally", "also", "plus".
	 */
	private const SEQUENCE_CONNECTORS = '/\b(?:then|after\s+that|once\s+(?:done|that\'s\s+done|finished)|and\s+then|afterwards?|next\s+(?:i\s+want|optimize|fix|update)|finally|and\s+(?:also|additionally))\b/i';

	/**
	 * Distinct action verb groups — each represents a different task domain.
	 * A message matching 2+ groups from different domains is multi-intent.
	 */
	private const INTENT_PATTERNS = array(
		'create'   => '/\b(?:create|write|draft|generate|build|make)\s+(?:(?:a|an|new|the|me)\s+){0,2}(?:blog\s+post|post|page|article|blog|content|product)\b/i',
		'edit'     => '/\b(?:edit|update|change|modify|rewrite|shorten|lengthen)\s+(?:the|this|my)?\s*(?:post|page|content|article|product|title|description)\b/i',
		'seo'      => '/\b(?:fix|improve|optimize|update)\b.*\bseo\b|\bmeta\s+(?:titles?|descriptions?)\b|\bsearch\s+rank/i',
		'security' => '/\b(?:fix|scan|check|improve)\b.*\bsecurity\b|\bsecurity\s+(?:scan|audit|fix)\b/i',
		'analyze'  => '/\b(?:analyze|audit|scan|check|report)\b.*\b(?:seo|security|speed|health|performance)\b/i',
		'woo'      => '/\b(?:update|edit|create|change)\b.*\b(?:product|order|coupon|shipping|inventory)\b/i',
	);

	/**
	 * Detect whether a message contains multiple distinct task intents.
	 *
	 * Returns true when the message has:
	 *   1. A sequencing connector ("then", "after that", "and also", etc.)
	 *      AND 2+ different intent domains (e.g. create + seo), OR
	 *   2. 3+ different intent domains even without an explicit connector
	 *      (handles comma-separated or implied sequences).
	 *
	 * Single-domain multi-step requests ("fix SEO then update meta") are
	 * NOT flagged — those belong in a single workflow.
	 */
	private function is_multi_intent( string $message ): bool {
		$matched_domains = array();

		foreach ( self::INTENT_PATTERNS as $domain => $pattern ) {
			if ( preg_match( $pattern, $message ) ) {
				$matched_domains[] = $domain;
			}
		}

		// Single domain or no match — not multi-intent.
		if ( count( $matched_domains ) < 2 ) {
			return false;
		}

		// 2 domains + a sequencing connector → multi-intent.
		if ( preg_match( self::SEQUENCE_CONNECTORS, $message ) ) {
			return true;
		}

		// 3+ domains even without explicit connector → multi-intent.
		return count( $matched_domains ) >= 3;
	}

	private function is_continuation_message( string $message ): bool {
		return 1 === preg_match( '/^\[(?:Confirmed|Continue)\]/', trim( $message ) );
	}

	/**
	 * Score a message against a workflow's routing rules.
	 *
	 * @param string $message      User message.
	 * @param array  $rule         Routing rule from RULES.
	 * @param string $screen       Current admin screen.
	 * @param int    $post_id      Current post ID.
	 * @param string $conv_domain  Domain detected from conversation history.
	 * @param string $class        Workflow class name (for conversation matching).
	 * @return int Confidence score (0 = no match, higher = more confident).
	 */
	private function score_workflow(
		string $message,
		array  $rule,
		string $screen,
		int    $post_id,
		string $conv_domain = '',
		string $class = ''
	): int {
		// Check negative patterns first — any match rejects entirely.
		foreach ( $rule['negatives'] ?? array() as $negative ) {
			if ( preg_match( $negative, $message ) ) {
				return 0;
			}
		}

		// Sum positive pattern weights.
		$score = 0;
		foreach ( $rule['patterns'] as $pattern => $weight ) {
			if ( preg_match( $pattern, $message ) ) {
				$score += $weight;
			}
		}

		if ( 0 === $score ) {
			return 0;
		}

		// Apply context boosts.
		$boosts = $rule['context_boosts'] ?? array();

		if ( ! empty( $boosts['on_post_editor'] ) && $this->is_post_editor( $screen ) && $post_id > 0 ) {
			$score += $boosts['on_post_editor'];
		}

		if ( ! empty( $boosts['references_this'] ) && $this->references_current_content( $message ) ) {
			$score += $boosts['references_this'];
		}

		// v3.2.0: Conversation continuity boost — if previous turns were about
		// the same domain, the user is likely continuing that workflow.
		if ( $conv_domain && $class && $conv_domain === $class ) {
			$score += 15;
		}

		return $score;
	}

	/**
	 * Detect which workflow domain the conversation has been in recently.
	 * Scans the last 3 user messages for workflow pattern matches.
	 *
	 * Returns the workflow class name with the strongest recent signal,
	 * or empty string if no clear domain.
	 *
	 * @since 3.2.0
	 */
	private function detect_conversation_domain( array $conversation ): string {
		if ( empty( $conversation ) ) {
			return '';
		}

		// Extract last 3 user messages.
		$recent_user_msgs = array();
		for ( $i = count( $conversation ) - 1; $i >= 0 && count( $recent_user_msgs ) < 3; $i-- ) {
			if ( ( $conversation[ $i ]['role'] ?? '' ) === 'user' ) {
				$recent_user_msgs[] = $conversation[ $i ]['content'] ?? '';
			}
		}

		if ( empty( $recent_user_msgs ) ) {
			return '';
		}

		// Score each message against each workflow's positive patterns only.
		$domain_scores = array();
		foreach ( self::RULES as $class => $rule ) {
			$domain_scores[ $class ] = 0;
			foreach ( $recent_user_msgs as $msg ) {
				foreach ( $rule['patterns'] as $pattern => $weight ) {
					if ( preg_match( $pattern, $msg ) ) {
						$domain_scores[ $class ] += $weight;
						break; // One match per message per workflow is enough.
					}
				}
			}
		}

		// Return the class with the highest score, or empty if all zero.
		arsort( $domain_scores );
		$top_class = array_key_first( $domain_scores );
		return $domain_scores[ $top_class ] > 0 ? $top_class : '';
	}

	/**
	 * Check if the current screen is a post editor.
	 */
	private function is_post_editor( string $screen ): bool {
		return in_array( $screen, array( 'post', 'post.php', 'post-new.php', 'edit-post' ), true )
			|| str_contains( $screen, 'edit' );
	}

	/**
	 * Check if the message references the currently viewed content.
	 */
	private function references_current_content( string $message ): bool {
		return (bool) preg_match( '/\b(?:this|the|current|that)\s+(?:post|page|article|content)\b/i', $message );
	}

	/**
	 * Expose confidence scores for testing and observability.
	 *
	 * @since 3.2.0
	 */
	public function score_message(
		string $message,
		string $screen = '',
		int    $post_id = 0,
		array  $conversation = array()
	): array {
		$scores = array();
		$conv_domain = $this->detect_conversation_domain( $conversation );

		foreach ( self::RULES as $class => $rule ) {
			if ( ! empty( $rule['requires'] ) && ! class_exists( $rule['requires'] ) ) {
				$scores[ $class ] = 0;
				continue;
			}
			$scores[ $class ] = $this->score_workflow( $message, $rule, $screen, $post_id, $conv_domain, $class );
		}

		return $scores;
	}
}
