<?php
/**
 * Joined run trust surface builder.
 *
 * @package PressArk
 * @since   5.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Run_Trust_Surface {

	/**
	 * Build one normalized trust surface for a run.
	 *
	 * @param array<string,mixed>            $run          Run row.
	 * @param array<int,array<string,mixed>> $joined_trace Joined plugin and bank trace events.
	 * @param array<string,mixed>            $execution    Optional execution ledger override.
	 * @return array<string,mixed>
	 */
	public static function build( array $run, array $joined_trace = array(), array $execution = array() ): array {
		$execution = ! empty( $execution ) ? PressArk_Execution_Ledger::sanitize( $execution ) : self::extract_execution_from_run( $run );
		$evidence  = self::build_evidence_panel( $execution );
		$billing   = self::build_billing_panel( $run );
		$fallback  = self::build_fallback_panel( $joined_trace, $billing );

		return array(
			'version'                 => 1,
			'phase'                   => self::build_phase_panel( $run ),
			'evidence'                => $evidence,
			'fallback'                => $fallback,
			'billing'                 => $billing,
			'authority_boundary_note' => 'Evidence quality describes what PressArk verified locally. Billing authority describes who settles reservations, credits, and spend.',
		);
	}

	/**
	 * Pull the most recent execution ledger snapshot from a run record.
	 *
	 * @param array<string,mixed> $run Run row.
	 * @return array<string,mixed>
	 */
	public static function extract_execution_from_run( array $run ): array {
		$candidates = array(
			$run['result']['checkpoint']['execution'] ?? null,
			$run['result']['continuation']['execution'] ?? null,
			$run['result']['execution'] ?? null,
			$run['workflow_state']['execution'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_array( $candidate ) && ! PressArk_Execution_Ledger::is_empty( $candidate ) ) {
				return PressArk_Execution_Ledger::sanitize( $candidate );
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $run Run row.
	 * @return array<string,mixed>
	 */
	private static function build_phase_panel( array $run ): array {
		$workflow_state = is_array( $run['workflow_state'] ?? null ) ? $run['workflow_state'] : array();
		$checkpoint     = is_array( $run['result']['checkpoint'] ?? null ) ? $run['result']['checkpoint'] : array();
		$stage          = sanitize_key( (string) ( $checkpoint['workflow_stage'] ?? $workflow_state['workflow_stage'] ?? '' ) );
		$status         = sanitize_key( (string) ( $run['status'] ?? '' ) );
		$route          = sanitize_key( (string) ( $run['route'] ?? '' ) );

		$stage_labels = array(
			'preview' => 'Preview',
			'approval' => 'Approval',
			'verify' => 'Verify',
			'settled' => 'Settled',
		);

		return array(
			'stage'        => $stage,
			'stage_label'  => $stage_labels[ $stage ] ?? ( $stage ? ucwords( str_replace( '_', ' ', $stage ) ) : 'Run' ),
			'run_status'   => $status,
			'run_label'    => $status ? ucwords( str_replace( '_', ' ', $status ) ) : 'Unknown',
			'route'        => $route,
			'route_label'  => $route ? ucwords( str_replace( '_', ' ', $route ) ) : 'Unknown',
			'description'  => self::phase_description( $stage, $status, $route ),
		);
	}

	/**
	 * @param array<string,mixed> $execution Execution ledger.
	 * @return array<string,mixed>
	 */
	private static function build_evidence_panel( array $execution ): array {
		$rows = PressArk_Execution_Ledger::evidence_receipts( $execution );
		$status_counts = array(
			'verified'    => 0,
			'uncertain'   => 0,
			'not_checked' => 0,
		);
		$confidence_counts = array(
			'strong'   => 0,
			'partial'  => 0,
			'indirect' => 0,
			'none'     => 0,
		);
		$overall_confidence = ! empty( $rows ) ? 'strong' : 'none';

		foreach ( $rows as $row ) {
			$receipt = is_array( $row['evidence_receipt'] ?? null ) ? $row['evidence_receipt'] : array();
			$status  = sanitize_key( (string) ( $receipt['status'] ?? 'not_checked' ) );
			$confidence = sanitize_key( (string) ( $receipt['confidence'] ?? 'none' ) );

			if ( isset( $status_counts[ $status ] ) ) {
				$status_counts[ $status ]++;
			}
			if ( isset( $confidence_counts[ $confidence ] ) ) {
				$confidence_counts[ $confidence ]++;
			}

			$overall_confidence = self::min_confidence( $overall_confidence, $confidence );
		}

		if ( empty( $rows ) ) {
			$headline = 'No write evidence';
			$summary  = 'This run has no persisted write receipts with verification evidence yet.';
		} elseif ( $status_counts['uncertain'] > 0 ) {
			$headline = 'Evidence needs review';
			$summary  = sprintf(
				'%d verified, %d uncertain, %d not checked.',
				$status_counts['verified'],
				$status_counts['uncertain'],
				$status_counts['not_checked']
			);
		} elseif ( $status_counts['not_checked'] > 0 ) {
			$headline = 'Mixed evidence';
			$summary  = sprintf(
				'%d verified receipt(s) and %d write(s) without automated verification.',
				$status_counts['verified'],
				$status_counts['not_checked']
			);
		} else {
			$headline = 'Verified write evidence';
			$summary  = sprintf(
				'%d verified receipt(s), weakest confidence: %s.',
				$status_counts['verified'],
				self::confidence_label( $overall_confidence )
			);
		}

		return array(
			'headline'           => $headline,
			'summary'            => $summary,
			'overall_confidence' => $overall_confidence,
			'overall_label'      => self::confidence_label( $overall_confidence ),
			'status_counts'      => $status_counts,
			'confidence_counts'  => $confidence_counts,
			'receipts'           => $rows,
			'verified_example'   => self::first_matching_receipt( $rows, 'verified' ),
		);
	}

	/**
	 * @param array<string,mixed> $run Run row.
	 * @return array<string,mixed>
	 */
	private static function build_billing_panel( array $run ): array {
		$result_budget = is_array( $run['result']['budget'] ?? null ) ? $run['result']['budget'] : array();
		$billing_state = is_array( $result_budget['billing_state'] ?? null ) ? $result_budget['billing_state'] : array();
		$settlement    = is_array( $result_budget['settlement_delta'] ?? null ) ? $result_budget['settlement_delta'] : array();

		return array(
			'authority_mode'        => sanitize_key( (string) ( $billing_state['authority_mode'] ?? '' ) ),
			'authority_label'       => sanitize_text_field( (string) ( $billing_state['authority_label'] ?? 'Not recorded' ) ),
			'authority_notice'      => sanitize_text_field( (string) ( $billing_state['authority_notice'] ?? '' ) ),
			'service_state'         => sanitize_key( (string) ( $billing_state['service_state'] ?? '' ) ),
			'service_label'         => sanitize_text_field( (string) ( $billing_state['service_label'] ?? 'Unknown' ) ),
			'service_notice'        => sanitize_text_field( (string) ( $billing_state['service_notice'] ?? '' ) ),
			'spend_source'          => sanitize_key( (string) ( $billing_state['spend_source'] ?? '' ) ),
			'spend_label'           => sanitize_text_field( (string) ( $billing_state['spend_label'] ?? 'Unknown' ) ),
			'estimate_mode'         => sanitize_key( (string) ( $billing_state['estimate_mode'] ?? '' ) ),
			'estimate_notice'       => sanitize_text_field( (string) ( $billing_state['estimate_notice'] ?? '' ) ),
			'estimate_authority'    => sanitize_text_field( (string) ( $settlement['estimate_authority'] ?? '' ) ),
			'settlement_authority'  => sanitize_text_field( (string) ( $settlement['settlement_authority'] ?? '' ) ),
			'estimated_icus'        => (int) ( $settlement['estimated_icus'] ?? 0 ),
			'settled_icus'          => (int) ( $settlement['settled_icus'] ?? 0 ),
			'delta_icus'            => (int) ( $settlement['delta_icus'] ?? 0 ),
			'estimated_raw_tokens'  => (int) ( $settlement['estimated_raw_tokens'] ?? 0 ),
			'actual_raw_tokens'     => (int) ( $settlement['actual_raw_tokens'] ?? 0 ),
			'settlement_summary'    => sanitize_text_field( (string) ( $settlement['summary'] ?? '' ) ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $joined_trace Joined events.
	 * @param array<string,mixed>            $billing      Billing panel.
	 * @return array<string,mixed>
	 */
	private static function build_fallback_panel( array $joined_trace, array $billing ): array {
		$catalog = class_exists( 'PressArk_Activity_Trace' )
			? PressArk_Activity_Trace::reason_catalog()
			: array();
		$events = array();
		$has_fallback = false;
		$has_degraded = false;

		foreach ( $joined_trace as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$reason = sanitize_key( (string) ( $event['reason'] ?? '' ) );
			if ( '' === $reason || ! self::is_fallback_reason( $reason ) ) {
				continue;
			}

			if ( 0 === strpos( $reason, 'fallback_' ) ) {
				$has_fallback = true;
			}
			if ( 0 === strpos( $reason, 'degraded_' ) || in_array( $reason, array( 'worker_deferred', 'worker_slot_contention', 'provider_error', 'usage_missing_stream' ), true ) ) {
				$has_degraded = true;
			}

			$events[] = array(
				'when'         => sanitize_text_field( (string) ( $event['occurred_at'] ?? $event['created_at'] ?? '' ) ),
				'source'       => sanitize_key( (string) ( $event['source'] ?? '' ) ),
				'reason'       => $reason,
				'reason_label' => sanitize_text_field( (string) ( $catalog[ $reason ] ?? str_replace( '_', ' ', $reason ) ) ),
				'status'       => sanitize_key( (string) ( $event['status'] ?? '' ) ),
				'summary'      => sanitize_text_field( (string) ( $event['summary'] ?? '' ) ),
			);
		}

		if ( 'degraded' === ( $billing['service_state'] ?? '' ) || 'offline_assisted' === ( $billing['service_state'] ?? '' ) ) {
			$has_degraded = true;
		}

		if ( $has_fallback && $has_degraded ) {
			$headline = 'Fallback and degraded path recorded';
		} elseif ( $has_fallback ) {
			$headline = 'Fallback path recorded';
		} elseif ( $has_degraded ) {
			$headline = 'Degraded path recorded';
		} else {
			$headline = 'No fallback or degraded path';
		}

		$summary = ! empty( $events )
			? sprintf( '%d trace event(s) explain fallback or degraded behavior.', count( $events ) )
			: ( $has_degraded
				? 'Billing state is degraded even though no matching fallback trace event was found in this view.'
				: 'No fallback or degraded events were recorded for this run.' );

		return array(
			'headline' => $headline,
			'summary'  => $summary,
			'events'   => array_slice( $events, 0, 12 ),
			'example'  => ! empty( $events ) ? $events[0] : array(),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows with evidence receipts.
	 * @return array<string,mixed>
	 */
	private static function first_matching_receipt( array $rows, string $status ): array {
		foreach ( $rows as $row ) {
			$receipt = is_array( $row['evidence_receipt'] ?? null ) ? $row['evidence_receipt'] : array();
			if ( $status === ( $receipt['status'] ?? '' ) ) {
				return $row;
			}
		}

		return array();
	}

	private static function is_fallback_reason( string $reason ): bool {
		if ( 0 === strpos( $reason, 'fallback_' ) || 0 === strpos( $reason, 'degraded_' ) ) {
			return true;
		}

		return in_array(
			$reason,
			array(
				'retry_async_failure',
				'worker_deferred',
				'worker_slot_contention',
				'provider_error',
				'usage_missing_stream',
				'reserve_blocked_budget',
				'discover_budget_reached',
				'discover_repeated_misfire',
			),
			true
		);
	}

	private static function phase_description( string $stage, string $status, string $route ): string {
		$parts = array();
		if ( '' !== $stage ) {
			$parts[] = 'Stage: ' . str_replace( '_', ' ', $stage );
		}
		if ( '' !== $status ) {
			$parts[] = 'Run status: ' . str_replace( '_', ' ', $status );
		}
		if ( '' !== $route ) {
			$parts[] = 'Route: ' . str_replace( '_', ' ', $route );
		}

		return ! empty( $parts )
			? implode( '. ', $parts ) . '.'
			: 'Run phase data is not available.';
	}

	private static function confidence_label( string $confidence ): string {
		$ladder = PressArk_Evidence_Receipt::confidence_ladder();
		return $ladder[ $confidence ]['label'] ?? $ladder['none']['label'];
	}

	private static function min_confidence( string $left, string $right ): string {
		$ladder = PressArk_Evidence_Receipt::confidence_ladder();
		$left_rank = $ladder[ $left ]['rank'] ?? 0;
		$right_rank = $ladder[ $right ]['rank'] ?? 0;

		return $left_rank <= $right_rank ? $left : $right;
	}
}
