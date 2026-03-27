<?php
/**
 * PressArk Automation Recurrence — Calendar-accurate next-occurrence computation.
 *
 * No "monthly = 30 days" or "yearly = 365 days" shortcuts.
 * Uses PHP DateTime for proper calendar arithmetic.
 *
 * Cadence types:
 *   once       — no recurrence
 *   hourly     — every N hours (cadence_value = N)
 *   daily      — every day
 *   weekly     — every 7 days
 *   monthly    — same day-of-month (or last day if month is shorter)
 *   yearly     — same day-of-year
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Automation_Recurrence {

	/**
	 * Valid cadence types.
	 */
	public const CADENCE_TYPES = array( 'once', 'hourly', 'daily', 'weekly', 'monthly', 'yearly' );

	/**
	 * Compute the next occurrence after a reference time.
	 *
	 * @param string          $cadence_type  One of CADENCE_TYPES.
	 * @param int             $cadence_value Hours for 'hourly', otherwise unused.
	 * @param string          $timezone      IANA timezone (e.g. 'America/New_York').
	 * @param string          $first_run_at  Original first run (UTC, Y-m-d H:i:s).
	 * @param string|null     $last_run_at   Last run time (UTC, Y-m-d H:i:s), null = never run.
	 * @return string|null    Next run time in UTC (Y-m-d H:i:s), null for 'once' after first run.
	 */
	public static function compute_next(
		string  $cadence_type,
		int     $cadence_value,
		string  $timezone,
		string  $first_run_at,
		?string $last_run_at = null
	): ?string {
		if ( 'once' === $cadence_type ) {
			// 'once' only fires if it hasn't run yet.
			return null;
		}

		try {
			$tz  = new \DateTimeZone( $timezone ?: 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}

		$utc = new \DateTimeZone( 'UTC' );
		$now = new \DateTimeImmutable( 'now', $utc );

		// Reference point: the first_run_at in the user's timezone.
		$first = new \DateTimeImmutable( $first_run_at, $utc );
		$first_local = $first->setTimezone( $tz );

		// Start computing from the last run or first run.
		$base = $last_run_at
			? new \DateTimeImmutable( $last_run_at, $utc )
			: $first;

		$base_local = $base->setTimezone( $tz );

		// Compute next occurrence using calendar arithmetic in user's timezone.
		$next_local = self::advance( $cadence_type, $cadence_value, $base_local, $first_local );

		if ( ! $next_local ) {
			return null;
		}

		// If computed next is in the past (site was down), skip to the next future occurrence.
		// This implements the "run once, don't flood backlog" missed-run policy.
		$safety = 0;
		while ( $next_local->setTimezone( $utc ) <= $now && $safety < 1000 ) {
			$next_local = self::advance( $cadence_type, $cadence_value, $next_local, $first_local );
			if ( ! $next_local ) {
				return null;
			}
			$safety++;
		}

		return $next_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Advance a local datetime by one cadence period.
	 *
	 * @param string              $cadence_type  Cadence type.
	 * @param int                 $cadence_value Hours for 'hourly'.
	 * @param \DateTimeImmutable  $from          Current local time.
	 * @param \DateTimeImmutable  $anchor        Original first-run local time (for monthly/yearly day anchoring).
	 * @return \DateTimeImmutable|null
	 */
	private static function advance(
		string              $cadence_type,
		int                 $cadence_value,
		\DateTimeImmutable  $from,
		\DateTimeImmutable  $anchor
	): ?\DateTimeImmutable {
		switch ( $cadence_type ) {
			case 'hourly':
				$hours = max( 1, $cadence_value );
				return $from->modify( "+{$hours} hours" );

			case 'daily':
				return $from->modify( '+1 day' );

			case 'weekly':
				return $from->modify( '+7 days' );

			case 'monthly':
				// Calendar-accurate: go to same day-of-month in next month.
				// If the anchor was on the 31st but next month only has 28 days,
				// use the last day of that month.
				$anchor_day = (int) $anchor->format( 'j' );
				$next_month = $from->modify( 'first day of next month' );
				$days_in_next = (int) $next_month->format( 't' );
				$target_day  = min( $anchor_day, $days_in_next );

				return $next_month->setDate(
					(int) $next_month->format( 'Y' ),
					(int) $next_month->format( 'n' ),
					$target_day
				)->setTime(
					(int) $anchor->format( 'G' ),
					(int) $anchor->format( 'i' ),
					(int) $anchor->format( 's' )
				);

			case 'yearly':
				$anchor_month = (int) $anchor->format( 'n' );
				$anchor_day   = (int) $anchor->format( 'j' );
				$next_year    = (int) $from->format( 'Y' ) + 1;

				// Handle Feb 29 → Feb 28 in non-leap years.
				// Use DateTime instead of cal_days_in_month() for portability
				// (calendar extension not available in all PHP runtimes).
				$probe          = new \DateTimeImmutable( sprintf( '%04d-%02d-01', $next_year, $anchor_month ), $from->getTimezone() );
				$days_in_month  = (int) $probe->format( 't' );
				$target_day     = min( $anchor_day, $days_in_month );

				return $from->setDate( $next_year, $anchor_month, $target_day )
					->setTime(
						(int) $anchor->format( 'G' ),
						(int) $anchor->format( 'i' ),
						(int) $anchor->format( 's' )
					);

			default:
				return null;
		}
	}

	/**
	 * Validate a cadence type.
	 */
	public static function is_valid_cadence( string $type ): bool {
		return in_array( $type, self::CADENCE_TYPES, true );
	}

	/**
	 * Approximate interval in seconds for a cadence.
	 * Used for min_automation_interval enforcement.
	 *
	 * @return int Seconds between runs, or PHP_INT_MAX for 'once'.
	 */
	public static function cadence_seconds( string $cadence_type, int $cadence_value = 0 ): int {
		return match ( $cadence_type ) {
			'once'    => PHP_INT_MAX,
			'hourly'  => max( 1, $cadence_value ) * 3600,
			'daily'   => 86400,
			'weekly'  => 604800,
			'monthly' => 2592000,  // 30 days
			'yearly'  => 31536000, // 365 days
			default   => 0,
		};
	}

	/**
	 * Human-readable cadence label.
	 */
	public static function label( string $cadence_type, int $cadence_value = 0 ): string {
		switch ( $cadence_type ) {
			case 'once':    return 'Once';
			case 'hourly':  return sprintf( 'Every %d hour(s)', max( 1, $cadence_value ) );
			case 'daily':   return 'Daily';
			case 'weekly':  return 'Weekly';
			case 'monthly': return 'Monthly';
			case 'yearly':  return 'Yearly';
			default:        return 'Unknown';
		}
	}
}
