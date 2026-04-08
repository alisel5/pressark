<?php
/**
 * PressArk Permission Decision — canonical permission contract.
 *
 * Normalizes allow/ask/deny outcomes across entitlements, policy, automation,
 * and tool visibility into one replay-safe shape that can be serialized for
 * run persistence and operator debugging.
 *
 * @package PressArk
 * @since   5.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Permission_Decision {

	public const CONTRACT = 'permission_decision';
	public const VERSION  = 1;
	public const RECEIPT_CONTRACT = 'approval_receipt';

	public const ALLOW = 'allow';
	public const ASK   = 'ask';
	public const DENY  = 'deny';

	public const APPROVAL_NONE        = 'none';
	public const APPROVAL_PREVIEW     = 'preview';
	public const APPROVAL_CONFIRM     = 'confirm';
	public const APPROVAL_HUMAN       = 'human_confirmation';
	public const APPROVAL_UNAVAILABLE = 'unavailable';

	public const OUTCOME_APPROVED  = 'approved';
	public const OUTCOME_DECLINED  = 'declined';
	public const OUTCOME_DISCARDED = 'discarded';
	public const OUTCOME_ABORTED   = 'aborted';
	public const OUTCOME_CANCELLED = 'cancelled';
	public const OUTCOME_EXPIRED   = 'expired';

	/**
	 * Build a normalized permission decision array.
	 *
	 * @param array $decision Partial decision payload.
	 * @return array
	 */
	public static function normalize( array $decision ): array {
		$verdict = (string) ( $decision['verdict'] ?? $decision['behavior'] ?? self::DENY );
		if ( ! in_array( $verdict, array( self::ALLOW, self::ASK, self::DENY ), true ) ) {
			$verdict = self::DENY;
		}

		$reasons = array();
		foreach ( (array) ( $decision['reasons'] ?? array() ) as $reason ) {
			if ( is_scalar( $reason ) ) {
				$text = trim( (string) $reason );
				if ( '' !== $text ) {
					$reasons[] = $text;
				}
			}
		}

		$provenance = (array) ( $decision['provenance'] ?? array() );
		$source     = (string) ( $decision['source'] ?? $provenance['source'] ?? '' );
		$authority  = (string) ( $provenance['authority'] ?? '' );
		$kind       = (string) ( $provenance['kind'] ?? '' );

		$approval = (array) ( $decision['approval'] ?? array() );
		$approval = array(
			'required'  => ! empty( $approval['required'] ),
			'mode'      => (string) ( $approval['mode'] ?? self::APPROVAL_NONE ),
			'available' => array_key_exists( 'available', $approval ) ? (bool) $approval['available'] : true,
		);

		$entitlement = (array) ( $decision['entitlement'] ?? array() );
		$entitlement = array(
			'checked'    => ! empty( $entitlement['checked'] ),
			'allowed'    => array_key_exists( 'allowed', $entitlement ) ? (bool) $entitlement['allowed'] : null,
			'basis'      => (string) ( $entitlement['basis'] ?? '' ),
			'tier'       => (string) ( $entitlement['tier'] ?? '' ),
			'group'      => (string) ( $entitlement['group'] ?? '' ),
			'capability' => (string) ( $entitlement['capability'] ?? '' ),
			'remaining'  => isset( $entitlement['remaining'] ) ? (int) $entitlement['remaining'] : null,
			'limit'      => isset( $entitlement['limit'] ) ? (int) $entitlement['limit'] : null,
			'used'       => isset( $entitlement['used'] ) ? (int) $entitlement['used'] : null,
		);

		$visibility = (array) ( $decision['visibility'] ?? array() );
		$reason_codes = array();
		foreach ( (array) ( $visibility['reason_codes'] ?? array() ) as $code ) {
			if ( is_scalar( $code ) ) {
				$text = sanitize_key( (string) $code );
				if ( '' !== $text ) {
					$reason_codes[] = $text;
				}
			}
		}
		$visibility = array(
			'visible_to_model' => array_key_exists( 'visible_to_model', $visibility ) ? (bool) $visibility['visible_to_model'] : self::ALLOW === $verdict,
			'reason_codes'     => array_values( array_unique( $reason_codes ) ),
		);

		return array(
			'contract'    => self::CONTRACT,
			'version'     => self::VERSION,
			'operation'   => (string) ( $decision['operation'] ?? '' ),
			'context'     => (string) ( $decision['context'] ?? '' ),
			'verdict'     => $verdict,
			'behavior'    => $verdict, // Legacy adapter.
			'reasons'     => array_values( array_unique( $reasons ) ),
			'source'      => $source,
			'provenance'  => array(
				'authority' => '' !== $authority ? $authority : $source,
				'source'    => '' !== $source ? $source : $authority,
				'kind'      => $kind,
			),
			'approval'    => $approval,
			'entitlement' => $entitlement,
			'visibility'  => $visibility,
			'debug'       => is_array( $decision['debug'] ?? null ) ? $decision['debug'] : array(),
			'meta'        => is_array( $decision['meta'] ?? null ) ? $decision['meta'] : array(),
		);
	}

	/**
	 * Build a decision with the given verdict and provenance.
	 *
	 * @param string $verdict  allow|ask|deny.
	 * @param string $reason   Human-readable reason.
	 * @param string $source   Decision source identifier.
	 * @param array  $overrides Additional fields.
	 * @return array
	 */
	public static function create( string $verdict, string $reason, string $source, array $overrides = array() ): array {
		$decision = array_merge(
			array(
				'verdict'    => $verdict,
				'behavior'   => $verdict,
				'reasons'    => '' !== $reason ? array( $reason ) : array(),
				'source'     => $source,
				'provenance' => array(
					'authority' => $source,
					'source'    => $source,
					'kind'      => '',
				),
			),
			$overrides
		);

		return self::normalize( $decision );
	}

	/**
	 * Attach or replace the approval shape on a decision.
	 *
	 * @param array  $decision  Decision array.
	 * @param bool   $required  Whether human approval is required.
	 * @param string $mode      Approval mode.
	 * @param bool   $available Whether that approval path exists in-context.
	 * @return array
	 */
	public static function with_approval( array $decision, bool $required, string $mode, bool $available = true ): array {
		$decision['approval'] = array(
			'required'  => $required,
			'mode'      => $mode,
			'available' => $available,
		);

		return self::normalize( $decision );
	}

	/**
	 * Attach entitlement information to a decision.
	 *
	 * @param array  $decision  Decision array.
	 * @param array  $facet     Entitlement facet.
	 * @return array
	 */
	public static function with_entitlement( array $decision, array $facet ): array {
		$decision['entitlement'] = array_merge(
			(array) ( $decision['entitlement'] ?? array() ),
			$facet
		);

		return self::normalize( $decision );
	}

	/**
	 * Attach visibility information to a decision.
	 *
	 * @param array    $decision     Decision array.
	 * @param bool     $visible      Visible to the model.
	 * @param string[] $reason_codes Optional visibility reason codes.
	 * @return array
	 */
	public static function with_visibility( array $decision, bool $visible, array $reason_codes = array() ): array {
		$decision['visibility'] = array(
			'visible_to_model' => $visible,
			'reason_codes'     => array_values( array_unique( array_filter( array_map( 'sanitize_key', $reason_codes ) ) ) ),
		);

		return self::normalize( $decision );
	}

	/**
	 * Check if a decision allows execution.
	 */
	public static function is_allowed( array $decision ): bool {
		$normalized = self::normalize( $decision );
		return self::ALLOW === $normalized['verdict'];
	}

	/**
	 * Check if a decision requires approval.
	 */
	public static function is_ask( array $decision ): bool {
		$normalized = self::normalize( $decision );
		return self::ASK === $normalized['verdict'];
	}

	/**
	 * Check if a decision denies execution.
	 */
	public static function is_denied( array $decision ): bool {
		$normalized = self::normalize( $decision );
		return self::DENY === $normalized['verdict'];
	}

	/**
	 * Check if a decision is visible to the model.
	 */
	public static function is_visible_to_model( array $decision ): bool {
		$normalized = self::normalize( $decision );
		return ! empty( $normalized['visibility']['visible_to_model'] );
	}

	/**
	 * Build a normalized approval outcome payload.
	 *
	 * @param string $status    Outcome status.
	 * @param array  $overrides Optional extra fields.
	 * @return array
	 */
	public static function approval_outcome( string $status, array $overrides = array() ): array {
		return self::normalize_approval_outcome(
			array_merge(
				array(
					'status'      => $status,
					'recorded_at' => gmdate( 'c' ),
				),
				$overrides
			)
		);
	}

	/**
	 * Build a compact server acknowledgement receipt for an approval outcome.
	 *
	 * @param array $outcome Raw approval outcome payload.
	 * @param array $context Optional settlement context.
	 * @return array
	 */
	public static function approval_receipt( array $outcome, array $context = array() ): array {
		$normalized = self::normalize_approval_outcome( $outcome );
		if ( empty( $normalized ) ) {
			return array();
		}

		$receipt = array(
			'contract'       => self::RECEIPT_CONTRACT,
			'version'        => self::VERSION,
			'status'         => $normalized['status'],
			'action'         => sanitize_key( (string) ( $normalized['action'] ?? '' ) ),
			'scope'          => sanitize_key( (string) ( $normalized['scope'] ?? '' ) ),
			'source'         => sanitize_key( (string) ( $normalized['source'] ?? '' ) ),
			'actor'          => sanitize_key( (string) ( $normalized['actor'] ?? '' ) ),
			'reason_code'    => sanitize_key( (string) ( $normalized['reason_code'] ?? '' ) ),
			'message'        => sanitize_text_field( (string) ( $context['message'] ?? $normalized['message'] ?? '' ) ),
			'recorded_at'    => sanitize_text_field( (string) ( $normalized['recorded_at'] ?? '' ) ),
			'acknowledged'   => true,
			'settled'        => true,
			'acknowledged_at'=> sanitize_text_field( (string) ( $context['acknowledged_at'] ?? $normalized['recorded_at'] ?? gmdate( 'c' ) ) ),
			'run_id'         => sanitize_text_field( (string) ( $context['run_id'] ?? '' ) ),
			'correlation_id' => sanitize_text_field( (string) ( $context['correlation_id'] ?? '' ) ),
			'run_status'     => sanitize_key( (string) ( $context['run_status'] ?? '' ) ),
			'execution_ok'   => array_key_exists( 'execution_ok', $context ) ? (bool) $context['execution_ok'] : null,
			'meta'           => is_array( $normalized['meta'] ?? null ) ? $normalized['meta'] : array(),
		);

		return array_filter(
			$receipt,
			static function ( $value, $key ) {
				if ( in_array( $key, array( 'acknowledged', 'settled' ), true ) ) {
					return true;
				}

				if ( 'execution_ok' === $key ) {
					return null !== $value;
				}

				if ( 'meta' === $key ) {
					return ! empty( $value );
				}

				return '' !== (string) $value;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Normalize an approval outcome payload used by settlement and checkpoint flows.
	 *
	 * @param array $outcome Raw outcome payload.
	 * @return array
	 */
	public static function normalize_approval_outcome( array $outcome ): array {
		$status = sanitize_key( (string) ( $outcome['status'] ?? $outcome['outcome'] ?? '' ) );
		if ( ! in_array( $status, self::approval_outcome_statuses(), true ) ) {
			return array();
		}

		$clean = array(
			'status'      => $status,
			'action'      => sanitize_key( (string) ( $outcome['action'] ?? '' ) ),
			'scope'       => sanitize_key( (string) ( $outcome['scope'] ?? '' ) ),
			'source'      => sanitize_key( (string) ( $outcome['source'] ?? '' ) ),
			'actor'       => sanitize_key( (string) ( $outcome['actor'] ?? '' ) ),
			'reason_code' => sanitize_key( (string) ( $outcome['reason_code'] ?? '' ) ),
			'message'     => sanitize_text_field( (string) ( $outcome['message'] ?? '' ) ),
			'recorded_at' => sanitize_text_field( (string) ( $outcome['recorded_at'] ?? $outcome['at'] ?? '' ) ),
			'meta'        => is_array( $outcome['meta'] ?? null ) ? $outcome['meta'] : array(),
		);

		return array_filter(
			$clean,
			static function ( $value, $key ) {
				if ( 'meta' === $key ) {
					return ! empty( $value );
				}

				return '' !== (string) $value;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Allowed approval outcome statuses.
	 *
	 * @return string[]
	 */
	public static function approval_outcome_statuses(): array {
		return array(
			self::OUTCOME_APPROVED,
			self::OUTCOME_DECLINED,
			self::OUTCOME_DISCARDED,
			self::OUTCOME_ABORTED,
			self::OUTCOME_CANCELLED,
			self::OUTCOME_EXPIRED,
		);
	}
}
