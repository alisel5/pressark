<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages conversation history with token-aware compression.
 *
 * v3.7.0: Checkpoint-first compression for chats with real structured state.
 *         Synthetic goal-only checkpoints proved too lossy for multistep work,
 *         so chats without a real checkpoint still use token-budgeted history.
 *         Recent tail defaults to 4/8 messages, with a slightly larger tail
 *         when the checkpoint is structurally sparse.
 */
class PressArk_History_Manager {

	/**
	 * Token budgets for conversation history.
	 */
	private const NORMAL_TOKEN_BUDGET = 3000;
	private const DEEP_TOKEN_BUDGET   = 8000;

	/**
	 * Max messages to consider (before token budgeting).
	 */
	private const NORMAL_MAX_MESSAGES = 20;
	private const DEEP_MAX_MESSAGES   = 40;

	/**
	 * Recent tail sizes for checkpoint-based compression (v3.7.0).
	 * Reduced from 6/10 — checkpoint carries operational state,
	 * recent messages carry only conversational tone.
	 */
	private const NORMAL_TAIL        = 4;
	private const DEEP_TAIL          = 8;
	private const SPARSE_NORMAL_TAIL = 6;
	private const SPARSE_DEEP_TAIL   = 10;

	/**
	 * Prepare conversation history within a token budget.
	 *
	 * v3.7.0: Checkpoint-first default only when a non-empty checkpoint exists.
	 * Conversations without durable state stay on token-budgeted history rather
	 * than fabricating a tiny checkpoint that drops too much context.
	 *
	 * Strategy A (turn 0-1, no checkpoint):
	 * 1. Take the most recent messages (up to max count).
	 * 2. Estimate tokens for each message.
	 * 3. Keep as many recent messages as fit within the budget.
	 * 4. If a single message is very long, truncate it.
	 *
	 * Strategy B (checkpoint-first default):
	 * 1. Prepend checkpoint context header as first message.
	 * 2. Keep last 4 messages (normal) or 8 (deep) for coherence.
	 * 3. No backward token scan — checkpoint captures the operational state.
	 *
	 * @param array                    $conversation Full conversation array from frontend.
	 * @param bool                     $deep_mode    Whether deep mode is active.
	 * @param PressArk_Checkpoint|null $checkpoint   Optional checkpoint from frontend round-trip.
	 * @return array Compressed conversation suitable for the API.
	 */
	public static function prepare( array $conversation, bool $deep_mode = false, ?PressArk_Checkpoint $checkpoint = null ): array {
		if ( empty( $conversation ) ) {
			return array();
		}

		// Strategy B: use provided checkpoint.
		if ( $checkpoint && ! $checkpoint->is_empty() ) {
			return self::prepare_with_checkpoint( $conversation, $checkpoint, $deep_mode );
		}

		// Strategy A: token-budgeted backward scan when no durable checkpoint exists.
		$max_messages  = $deep_mode ? self::DEEP_MAX_MESSAGES : self::NORMAL_MAX_MESSAGES;
		$token_budget  = $deep_mode ? self::DEEP_TOKEN_BUDGET : self::NORMAL_TOKEN_BUDGET;

		// Take the most recent messages first.
		$candidates = array_slice( $conversation, -$max_messages );

		// Filter to valid roles only.
		$valid = array();
		foreach ( $candidates as $msg ) {
			$role = isset( $msg['role'] ) ? sanitize_text_field( $msg['role'] ) : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) ) {
				$content = $msg['content'] ?? '';
				$valid[] = array(
					'role'    => $role,
					'content' => $content,
					'tokens'  => self::estimate_tokens( $content ),
				);
			}
		}

		if ( empty( $valid ) ) {
			return array();
		}

		// Work backwards from most recent, accumulating tokens.
		$result       = array();
		$tokens_used  = 0;
		$max_single   = (int) ( $token_budget * 0.4 ); // No single message gets more than 40% of budget.

		for ( $i = count( $valid ) - 1; $i >= 0; $i-- ) {
			$msg_tokens = $valid[ $i ]['tokens'];

			// Truncate overly long individual messages.
			if ( $msg_tokens > $max_single ) {
				$valid[ $i ]['content'] = self::truncate_to_tokens( $valid[ $i ]['content'], $max_single );
				$msg_tokens = $max_single;
			}

			// Check if it fits in budget.
			if ( $tokens_used + $msg_tokens > $token_budget ) {
				// If we haven't added any messages yet, force-add the most recent one (truncated).
				if ( empty( $result ) ) {
					$valid[ $i ]['content'] = self::truncate_to_tokens(
						$valid[ $i ]['content'],
						$token_budget
					);
					$result[] = array(
						'role'    => $valid[ $i ]['role'],
						'content' => $valid[ $i ]['content'],
					);
				}
				break;
			}

			$tokens_used += $msg_tokens;
			$result[] = array(
				'role'    => $valid[ $i ]['role'],
				'content' => $valid[ $i ]['content'],
			);
		}

		// Reverse to chronological order.
		return array_reverse( $result );
	}

	/**
	 * Estimate token count for a string.
	 * Uses a simple heuristic: ~4 characters per token for English text.
	 *
	 * @param string $text The text to estimate.
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( string $text ): int {
		if ( empty( $text ) ) {
			return 0;
		}
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	/**
	 * Truncate text to approximately a target token count.
	 *
	 * @param string $text       The text to truncate.
	 * @param int    $max_tokens Maximum tokens allowed.
	 * @return string Truncated text.
	 */
	private static function truncate_to_tokens( string $text, int $max_tokens ): string {
		$max_chars = $max_tokens * 4;
		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}
		return mb_substr( $text, 0, $max_chars ) . "\n... [truncated]";
	}

	/**
	 * Get the total estimated tokens for a prepared history array.
	 *
	 * @param array $history Prepared history from prepare().
	 * @return int Total estimated tokens.
	 */
	public static function count_tokens( array $history ): int {
		$total = 0;
		foreach ( $history as $msg ) {
			$total += self::estimate_tokens( $msg['content'] ?? '' );
			$total += 4; // Overhead for role/message structure.
		}
		return $total;
	}

	/**
	 * Checkpoint-based history compression.
	 * Keeps checkpoint header + the most recent messages, using a slightly
	 * larger tail when the checkpoint is structurally sparse.
	 *
	 * v3.3.0: Token-aware — elides checkpoint sections that are already
	 * represented in recent messages, and truncates outcomes to last 5.
	 * v3.7.0: Reduced tail from 6/10 to 4/8.
	 *
	 * @since 2.4.0
	 * @since 3.3.0 Token-aware elision of redundant checkpoint data.
	 * @since 3.7.0 Reduced recent tail for checkpoint-first default.
	 *
	 * @param array               $conversation Full conversation array.
	 * @param PressArk_Checkpoint $checkpoint   Non-empty checkpoint.
	 * @param bool                $deep_mode    Whether deep mode is active.
	 * @return array Compressed conversation.
	 */
	private static function prepare_with_checkpoint( array $conversation, PressArk_Checkpoint $checkpoint, bool $deep_mode ): array {
		$keep_count = self::checkpoint_supports_tiny_tail( $checkpoint )
			? ( $deep_mode ? self::DEEP_TAIL : self::NORMAL_TAIL )
			: ( $deep_mode ? self::SPARSE_DEEP_TAIL : self::SPARSE_NORMAL_TAIL );

		// Filter to valid roles.
		$valid = array();
		foreach ( $conversation as $msg ) {
			$role = isset( $msg['role'] ) ? sanitize_text_field( $msg['role'] ) : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) ) {
				$valid[] = array(
					'role'    => $role,
					'content' => $msg['content'] ?? '',
				);
			}
		}

		// Take the most recent messages.
		$recent = array_slice( $valid, -$keep_count );

		// v3.3.0: Only prepend checkpoint if it adds information beyond
		// what's in the recent messages. Estimate header tokens and skip
		// if the header would exceed 25% of the budget for its tier.
		$header = $checkpoint->to_context_header();
		if ( $header ) {
			$header_tokens  = self::estimate_tokens( $header );
			$budget         = $deep_mode ? self::DEEP_TOKEN_BUDGET : self::NORMAL_TOKEN_BUDGET;
			$max_header     = (int) ( $budget * 0.25 );

			if ( $header_tokens > $max_header ) {
				// Header is too large — truncate it to fit.
				$header = self::truncate_to_tokens( $header, $max_header );
			}

			array_unshift( $recent, array(
				'role'    => 'user',
				'content' => $header,
			) );
		}

		return $recent;
	}

	/**
	 * Tiny tails are safe only when the checkpoint carries real structural state.
	 */
	private static function checkpoint_supports_tiny_tail( PressArk_Checkpoint $checkpoint ): bool {
		$data = $checkpoint->to_array();

		if ( ! empty( $data['entities'] )
			|| ! empty( $data['facts'] )
			|| ! empty( $data['pending'] )
			|| ! empty( $data['outcomes'] )
			|| ! empty( $data['retrieval'] )
			|| ! empty( $data['selected_target'] )
			|| ! empty( $data['approvals'] )
			|| ! empty( $data['blockers'] )
			|| ! empty( $data['context_capsule'] )
			|| ! empty( $data['loaded_tool_groups'] )
			|| ! empty( $data['bundle_ids'] ) ) {
			return true;
		}

		$execution = is_array( $data['execution'] ?? null ) ? $data['execution'] : array();
		return ! empty( $execution['tasks'] )
			|| ! empty( $execution['receipts'] )
			|| ! empty( $execution['current_target']['post_id'] );
	}
}
