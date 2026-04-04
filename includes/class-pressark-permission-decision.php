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

	public const ALLOW = 'allow';
	public const ASK   = 'ask';
	public const DENY  = 'deny';

	public const APPROVAL_NONE        = 'none';
	public const APPROVAL_PREVIEW     = 'preview';
	public const APPROVAL_CONFIRM     = 'confirm';
	public const APPROVAL_HUMAN       = 'human_confirmation';
	public const APPROVAL_UNAVAILABLE = 'unavailable';

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
}
