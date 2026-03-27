<?php
/**
 * Reservation lifecycle for ICU-based billing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Reservation {

	private PressArk_Cost_Ledger $ledger;
	private PressArk_Token_Bank $token_bank;

	public function __construct() {
		$this->ledger     = new PressArk_Cost_Ledger();
		$this->token_bank = new PressArk_Token_Bank();
	}

	public function estimate_tokens( string $message, array $conversation, string $tier = 'free' ): int {
		$estimate = $this->estimate_usage( $message, $conversation, $tier );
		return $estimate['input_tokens'] + $estimate['output_tokens'];
	}

	public function estimate_icus( string $message, array $conversation, string $tier = 'free', string $model = '' ): int {
		$estimate   = $this->estimate_usage( $message, $conversation, $tier );
		$model      = $model ?: PressArk_Model_Policy::resolve( $tier );
		$multiplier = PressArk_Model_Policy::get_model_multiplier( $model );

		return (int) ceil(
			( $estimate['input_tokens'] * (int) ( $multiplier['input'] ?? 10 ) )
			+ ( $estimate['output_tokens'] * (int) ( $multiplier['output'] ?? 30 ) )
		);
	}

	public function reserve(
		int $user_id,
		int $estimated_icus,
		string $route,
		string $tier,
		string $model = '',
		int $estimated_tokens = 0
	): array {
		$is_byok        = PressArk_Entitlements::is_byok();
		$reservation_id = wp_generate_uuid4();
		$model          = $model ?: PressArk_Model_Policy::resolve( $tier );

		$insert_id = $this->ledger->insert( array(
			'user_id'          => $user_id,
			'reservation_id'   => $reservation_id,
			'status'           => 'reserved',
			'estimated_tokens' => $estimated_tokens > 0 ? $estimated_tokens : $estimated_icus,
			'estimated_icus'   => $estimated_icus,
			'route'            => $route,
			'model'            => $model,
			'model_class'      => PressArk_Model_Policy::get_model_class( $model ),
			'is_byok'          => (int) $is_byok,
		) );

		if ( ! $insert_id ) {
			return array(
				'reservation_id' => '',
				'ok'             => false,
				'error'          => 'Failed to create reservation record.',
			);
		}

		if ( $is_byok ) {
			return array(
				'reservation_id' => $reservation_id,
				'ok'             => true,
			);
		}

		$bank_result = $this->token_bank->reserve( $estimated_icus, $reservation_id, $tier, $model );

		if ( empty( $bank_result['ok'] ) ) {
			$this->ledger->fail( $reservation_id, 'Credit budget exhausted' );
			return array(
				'reservation_id' => $reservation_id,
				'ok'             => false,
				'error'          => 'token_limit_reached',
			);
		}

		return array(
			'reservation_id' => $reservation_id,
			'ok'             => true,
		);
	}

	public function settle( string $reservation_id, array $actual_usage, string $tier ): array {
		$is_byok = PressArk_Entitlements::is_byok();

		$model = (string) ( $actual_usage['model'] ?? '' );
		$resolved = $this->token_bank->resolve_icus( array(
			'model'              => $model,
			'input_tokens'       => (int) ( $actual_usage['input_tokens'] ?? 0 ),
			'output_tokens'      => (int) ( $actual_usage['output_tokens'] ?? 0 ),
			'cache_read_tokens'  => (int) ( $actual_usage['cache_read_tokens'] ?? 0 ),
			'cache_write_tokens' => (int) ( $actual_usage['cache_write_tokens'] ?? 0 ),
		) );

		$settle_payload = array_merge(
			$actual_usage,
			array(
				'settled_icus'           => (int) ( $resolved['icu_total'] ?? 0 ),
				'actual_icus'            => (int) ( $resolved['icu_total'] ?? 0 ),
				'actual_tokens'          => (int) ( $actual_usage['settled_tokens'] ?? 0 ),
				'model_class'            => (string) ( $resolved['model_class'] ?? '' ),
				'model_multiplier_input' => (int) ( $resolved['multiplier']['input'] ?? 0 ),
				'model_multiplier_output'=> (int) ( $resolved['multiplier']['output'] ?? 0 ),
			)
		);

		// v5.0.1: Settle on remote bank FIRST, then local ledger.
		// If the process crashes after local settle but before bank settle,
		// the ledger shows 'settled' but the bank still holds the reservation.
		// Reconciliation cannot fix this because the ledger entry is terminal.
		// By settling the bank first: if bank fails, ledger stays 'reserved'
		// and the 5-minute reconciliation cron expires it correctly.
		$bank_status = $this->token_bank->get_status();

		if ( ! $is_byok && (int) ( $settle_payload['actual_icus'] ?? 0 ) > 0 ) {
			$bank_status = $this->token_bank->settle( $reservation_id, $settle_payload, $tier );
		}

		$this->ledger->settle( $reservation_id, $settle_payload );

		return $bank_status;
	}

	public function fail( string $reservation_id, string $reason = '' ): void {
		if ( empty( $reservation_id ) ) {
			return;
		}

		if ( ! PressArk_Entitlements::is_byok() ) {
			$this->token_bank->release( $reservation_id );
		}

		$this->ledger->fail( $reservation_id, $reason );
	}

	public function reconcile(): int {
		return $this->ledger->expire_stale( 5 );
	}

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'schedule_reconciliation' ) );
		add_action( 'pressark_reconcile_reservations', array( self::class, 'handle_reconcile' ) );
	}

	public static function schedule_reconciliation(): void {
		if ( ! wp_next_scheduled( 'pressark_reconcile_reservations' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'pressark_reconcile_reservations' );
		}
	}

	public static function handle_reconcile(): void {
		( new self() )->reconcile();
	}

	private function estimate_usage( string $message, array $conversation, string $tier ): array {
		$lightweight_chat = PressArk_Agent::is_lightweight_chat_request( $message, $conversation );
		$input_chars      = strlen( $message );
		$history          = array_slice( $conversation, $lightweight_chat ? -4 : -10 );

		foreach ( $history as $turn ) {
			$input_chars += strlen( $turn['content'] ?? '' );
		}

		// v5.0.1: Reduced from 3500 to 2700 to match actual system prompt size
		// after v3.6.0 prompt hardening (~2600 tokens cached). The old value
		// over-reserved by ~900 tokens per request, causing premature budget
		// exhaustion near tier limits.
		$system_overhead = $lightweight_chat ? 800 : 2700;
		$input_tokens    = (int) ceil( $input_chars / 4 ) + $system_overhead;
		$output_tokens   = $lightweight_chat
			? min( 1200, PressArk_Entitlements::output_buffer( $tier ) )
			: PressArk_Entitlements::output_buffer( $tier );

		return array(
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens,
		);
	}
}
