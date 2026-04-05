<?php
/**
 * Normalized evidence receipts and confidence ladder.
 *
 * @package PressArk
 * @since   5.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Evidence_Receipt {

	private const VERSION = 1;

	/**
	 * Confidence ladder for local verification evidence.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function confidence_ladder(): array {
		return array(
			'none' => array(
				'rank'        => 0,
				'label'       => 'No evidence',
				'description' => 'No reliable automated verification evidence was recorded for this write.',
			),
			'indirect' => array(
				'rank'        => 1,
				'label'       => 'Indirect evidence',
				'description' => 'Signals exist, but they came from degraded, derived, cached, or otherwise secondary evidence.',
			),
			'partial' => array(
				'rank'        => 2,
				'label'       => 'Partial evidence',
				'description' => 'The write was checked, but only existence or limited fields were confirmed.',
			),
			'strong' => array(
				'rank'        => 3,
				'label'       => 'Strong evidence',
				'description' => 'A fresh read-back confirmed the requested resource or fields directly.',
			),
		);
	}

	/**
	 * Normalize one stored receipt.
	 *
	 * @param array<string,mixed> $raw Raw receipt payload.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		$statuses = self::status_labels();
		$ladder   = self::confidence_ladder();

		$status = sanitize_key( (string) ( $raw['status'] ?? 'not_checked' ) );
		if ( ! isset( $statuses[ $status ] ) ) {
			$status = 'not_checked';
		}

		$confidence = sanitize_key( (string) ( $raw['confidence'] ?? 'none' ) );
		if ( ! isset( $ladder[ $confidence ] ) ) {
			$confidence = 'none';
		}

		$entry = $ladder[ $confidence ];
		$signals = array();
		foreach ( (array) ( $raw['signals'] ?? array() ) as $signal ) {
			if ( ! is_array( $signal ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $signal['label'] ?? '' ) );
			$value = sanitize_text_field( (string) ( $signal['value'] ?? '' ) );
			if ( '' === $label || '' === $value ) {
				continue;
			}

			$signals[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		$mismatches = array();
		foreach ( (array) ( $raw['mismatches'] ?? array() ) as $mismatch ) {
			if ( ! is_array( $mismatch ) ) {
				continue;
			}

			$field = sanitize_key( (string) ( $mismatch['field'] ?? '' ) );
			if ( '' === $field ) {
				continue;
			}

			$mismatches[] = array(
				'field'    => $field,
				'expected' => sanitize_text_field( (string) ( $mismatch['expected'] ?? '' ) ),
				'actual'   => sanitize_text_field( (string) ( $mismatch['actual'] ?? '' ) ),
			);
		}

		$summary = sanitize_text_field( (string) ( $raw['summary'] ?? '' ) );
		$evidence = sanitize_text_field( (string) ( $raw['evidence'] ?? '' ) );
		if ( '' === $summary ) {
			$summary = self::default_summary( $status, $confidence, $evidence );
		}

		return array(
			'version'           => self::VERSION,
			'kind'              => 'evidence_receipt',
			'phase'             => sanitize_key( (string) ( $raw['phase'] ?? 'verify' ) ) ?: 'verify',
			'source'            => sanitize_key( (string) ( $raw['source'] ?? 'plugin_local' ) ) ?: 'plugin_local',
			'tool'              => sanitize_key( (string) ( $raw['tool'] ?? '' ) ),
			'status'            => $status,
			'status_label'      => $statuses[ $status ],
			'confidence'        => $confidence,
			'confidence_rank'   => (int) $entry['rank'],
			'confidence_label'  => sanitize_text_field( (string) ( $raw['confidence_label'] ?? $entry['label'] ) ),
			'confidence_reason' => sanitize_text_field( (string) ( $raw['confidence_reason'] ?? $entry['description'] ) ),
			'summary'           => $summary,
			'evidence'          => $evidence,
			'strategy'          => sanitize_key( (string) ( $raw['strategy'] ?? '' ) ),
			'read_tool'         => sanitize_key( (string) ( $raw['read_tool'] ?? '' ) ),
			'checked_at'        => sanitize_text_field( (string) ( $raw['checked_at'] ?? '' ) ),
			'signals'           => $signals,
			'mismatches'        => $mismatches,
		);
	}

	/**
	 * Build a default unchecked receipt for write operations.
	 *
	 * @param string $tool_name Write tool name.
	 * @param string $summary   Write summary.
	 * @return array<string,mixed>
	 */
	public static function for_unchecked_write( string $tool_name, string $summary = '' ): array {
		return self::sanitize(
			array(
				'tool'              => $tool_name,
				'status'            => 'not_checked',
				'confidence'        => 'none',
				'confidence_reason' => 'No automated verification receipt was recorded for this write.',
				'summary'           => $summary ?: 'Write recorded. No automated verification receipt yet.',
			)
		);
	}

	/**
	 * Upgrade a legacy verification payload into a normalized evidence receipt.
	 *
	 * @param string $tool_name Write tool name.
	 * @param array  $legacy    Legacy verification payload.
	 * @param string $summary   Receipt summary.
	 * @return array<string,mixed>
	 */
	public static function from_legacy_verification( string $tool_name, array $legacy, string $summary = '' ): array {
		$status = sanitize_key( (string) ( $legacy['status'] ?? '' ) );
		if ( 'verified' !== $status ) {
			$status = 'uncertain';
		}

		return self::sanitize(
			array(
				'tool'              => $tool_name,
				'status'            => $status,
				'confidence'        => 'verified' === $status ? 'partial' : 'none',
				'confidence_reason' => 'verified' === $status
					? 'Legacy verification evidence was recorded before the confidence ladder existed.'
					: 'Legacy verification remained uncertain and should be reviewed manually.',
				'summary'           => $summary ?: ( 'verified' === $status
					? 'Legacy verification receipt preserved.'
					: 'Legacy verification remained uncertain.' ),
				'evidence'          => sanitize_text_field( (string) ( $legacy['evidence'] ?? '' ) ),
				'checked_at'        => sanitize_text_field( (string) ( $legacy['checked_at'] ?? '' ) ),
			)
		);
	}

	/**
	 * Build a normalized receipt from an automated verification attempt.
	 *
	 * @param string $tool_name       Write tool name.
	 * @param array  $readback_result Read-back result payload.
	 * @param bool   $passed          Whether verification passed.
	 * @param string $evidence        Compact evidence string.
	 * @param array  $meta            Optional verification metadata.
	 * @return array<string,mixed>
	 */
	public static function from_verification(
		string $tool_name,
		array $readback_result,
		bool $passed,
		string $evidence = '',
		array $meta = array()
	): array {
		$policy       = is_array( $meta['policy'] ?? null ) ? $meta['policy'] : array();
		$readback     = is_array( $meta['readback'] ?? null ) ? $meta['readback'] : array();
		$read_meta    = is_array( $readback_result['read_meta'] ?? null ) ? $readback_result['read_meta'] : array();
		$mismatches   = is_array( $meta['mismatches'] ?? null ) ? $meta['mismatches'] : array();
		$strategy     = sanitize_key( (string) ( $policy['strategy'] ?? '' ) );
		$read_tool    = sanitize_key( (string) ( $readback['tool'] ?? $policy['read_tool'] ?? '' ) );
		$check_fields = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					(array) ( $policy['check_fields'] ?? array() )
				)
			)
		);
		$readback_failed = ! empty( $meta['readback_failed'] );

		$confidence       = self::confidence_from_context( $passed, $strategy, $read_meta, $mismatches, $readback_failed );
		$confidence_reason = self::confidence_reason(
			$confidence,
			$passed,
			$strategy,
			$read_meta,
			$check_fields,
			$readback_failed
		);

		$signals = array();
		if ( '' !== $strategy ) {
			$signals[] = array(
				'label' => 'Strategy',
				'value' => str_replace( '_', ' ', $strategy ),
			);
		}
		if ( '' !== $read_tool ) {
			$signals[] = array(
				'label' => 'Read tool',
				'value' => $read_tool,
			);
		}
		if ( ! empty( $check_fields ) ) {
			$signals[] = array(
				'label' => 'Fields checked',
				'value' => implode( ', ', $check_fields ),
			);
		}
		foreach ( array(
			'freshness'    => 'Freshness',
			'completeness' => 'Completeness',
			'trust_class'  => 'Trust class',
		) as $key => $label ) {
			$value = sanitize_key( (string) ( $read_meta[ $key ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}

			$signals[] = array(
				'label' => $label,
				'value' => str_replace( '_', ' ', $value ),
			);
		}

		$status = $passed ? 'verified' : 'uncertain';
		$summary = $passed
			? self::default_verified_summary( $strategy, $confidence )
			: ( $readback_failed
				? 'Automated read-back failed. The write needs manual review.'
				: 'Read-back did not confirm the requested state.' );

		return self::sanitize(
			array(
				'tool'              => $tool_name,
				'status'            => $status,
				'confidence'        => $confidence,
				'confidence_reason' => $confidence_reason,
				'summary'           => $summary,
				'evidence'          => $evidence,
				'strategy'          => $strategy,
				'read_tool'         => $read_tool,
				'checked_at'        => gmdate( 'c' ),
				'signals'           => $signals,
				'mismatches'        => $mismatches,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function status_labels(): array {
		return array(
			'not_checked' => 'Not checked',
			'verified'    => 'Verified',
			'uncertain'   => 'Uncertain',
		);
	}

	/**
	 * @param array<string,mixed> $read_meta
	 * @param array<int,mixed>    $mismatches
	 */
	private static function confidence_from_context(
		bool $passed,
		string $strategy,
		array $read_meta,
		array $mismatches,
		bool $readback_failed
	): string {
		if ( $readback_failed || ! $passed || ! empty( $mismatches ) ) {
			return 'none';
		}

		switch ( $strategy ) {
			case 'field_check':
			case 'read_back':
				$confidence = 'strong';
				break;
			case 'existence_check':
				$confidence = 'partial';
				break;
			default:
				$confidence = 'indirect';
				break;
		}

		$freshness    = sanitize_key( (string) ( $read_meta['freshness'] ?? '' ) );
		$completeness = sanitize_key( (string) ( $read_meta['completeness'] ?? '' ) );
		$trust_class  = sanitize_key( (string) ( $read_meta['trust_class'] ?? '' ) );

		if ( in_array( $freshness, array( 'cached', 'stale' ), true )
			|| 'preview' === $completeness
			|| 'derived_summary' === $trust_class ) {
			$confidence = self::downgrade_confidence( $confidence );
		}

		return $confidence;
	}

	/**
	 * @param array<string,mixed> $read_meta
	 * @param array<int,string>   $check_fields
	 */
	private static function confidence_reason(
		string $confidence,
		bool $passed,
		string $strategy,
		array $read_meta,
		array $check_fields,
		bool $readback_failed
	): string {
		if ( $readback_failed ) {
			return 'Automated read-back failed, so PressArk cannot claim verified evidence for this write.';
		}

		if ( ! $passed ) {
			return 'The read-back did not confirm the requested state, so this write remains uncertain.';
		}

		if ( 'strong' === $confidence ) {
			return ! empty( $check_fields )
				? 'A fresh read-back matched the requested fields directly.'
				: 'A fresh read-back returned the updated resource directly.';
		}

		if ( 'partial' === $confidence ) {
			return 'existence_check' === $strategy
				? 'The resource was read back successfully, but only existence-level evidence was checked.'
				: 'The write was verified with limited evidence, so confidence remains partial.';
		}

		if ( 'indirect' === $confidence ) {
			$freshness = sanitize_key( (string) ( $read_meta['freshness'] ?? '' ) );
			$completeness = sanitize_key( (string) ( $read_meta['completeness'] ?? '' ) );
			$trust_class = sanitize_key( (string) ( $read_meta['trust_class'] ?? '' ) );

			if ( in_array( $freshness, array( 'cached', 'stale' ), true ) ) {
				return 'The evidence came from a cached or stale read-back, so it is treated as indirect.';
			}
			if ( 'preview' === $completeness ) {
				return 'The evidence only covered a preview of the resource, so confidence is indirect.';
			}
			if ( 'derived_summary' === $trust_class ) {
				return 'The evidence came from a derived summary rather than a direct read-back.';
			}
		}

		return self::confidence_ladder()[ $confidence ]['description'];
	}

	private static function downgrade_confidence( string $confidence ): string {
		switch ( $confidence ) {
			case 'strong':
				return 'partial';
			case 'partial':
				return 'indirect';
			default:
				return 'none';
		}
	}

	private static function default_summary( string $status, string $confidence, string $evidence ): string {
		if ( 'verified' === $status ) {
			return self::default_verified_summary( '', $confidence );
		}

		if ( 'uncertain' === $status ) {
			return '' !== $evidence
				? 'Verification remained uncertain after read-back.'
				: 'Verification is uncertain and needs manual review.';
		}

		return 'Write recorded. No automated verification evidence was stored.';
	}

	private static function default_verified_summary( string $strategy, string $confidence ): string {
		if ( 'strong' === $confidence ) {
			return 'Verification recorded a strong read-back match.';
		}

		if ( 'partial' === $confidence && 'existence_check' === $strategy ) {
			return 'Verification confirmed the resource exists.';
		}

		if ( 'partial' === $confidence ) {
			return 'Verification recorded a partial read-back match.';
		}

		return 'Verification recorded indirect supporting evidence.';
	}
}
