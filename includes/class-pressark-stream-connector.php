<?php
/**
 * Streaming variant of AI provider calls.
 *
 * Reuses PressArk_AI_Connector for all non-streaming logic (model resolution,
 * system prompt building, message formatting, response extraction). Streaming
 * transport is handled via the WordPress HTTP API, attaching a cURL write
 * callback through http_api_curl when the cURL transport is active so SSE
 * tokens can be emitted progressively.
 *
 * Returns the same array shape as send_message_raw() for agent loop compatibility.
 *
 * @since 4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Stream_Connector {

	private PressArk_AI_Connector $ai;
	private PressArk_SSE_Emitter $emitter;
	private $cancel_check;

	public function __construct( PressArk_AI_Connector $ai, PressArk_SSE_Emitter $emitter, ?callable $cancel_check = null ) {
		$this->ai           = $ai;
		$this->emitter      = $emitter;
		$this->cancel_check = $cancel_check;
	}

	/**
	 * Stream an AI call, emitting token events in real-time.
	 *
	 * Returns the same array shape as PressArk_AI_Connector::send_message_raw()
	 * so the agent loop can process the result identically.
	 *
	 * @param array  $messages      Pre-built messages array.
	 * @param array  $tools         OpenAI function schemas.
	 * @param string $system_prompt Full system prompt (dynamic part).
	 * @param bool   $deep_mode     Whether deep mode is active.
	 * @return array{raw:array,provider:string,model:string,cache_metrics:array,request_made:bool}
	 */
	public function send_streaming(
		array $messages,
		array $tools = array(),
		string $system_prompt = '',
		bool $deep_mode = false
	): array {
		$do_work = function () use ( $messages, $tools, $system_prompt, $deep_mode ) {
			$provider = $this->ai->get_provider();

			if ( empty( $this->ai->get_api_key() ) && ! PressArk_AI_Connector::is_proxy_mode() ) {
				return array(
					'raw'           => array( 'error' => __( 'API key not configured.', 'pressark' ) ),
					'provider'      => $provider,
					'model'         => $this->ai->get_model(),
					'cache_metrics' => array( 'cache_read' => 0, 'cache_write' => 0 ),
					'request_made'  => false,
				);
			}

			if ( PressArk_AI_Connector::is_proxy_mode() ) {
				$result = $this->stream_via_bank( $messages, $tools, $system_prompt );
			} elseif ( 'anthropic' === $provider ) {
				$result = $this->stream_anthropic( $messages, $tools, $system_prompt );
			} else {
				$result = $this->stream_openai( $messages, $tools, $system_prompt );
			}

			return array(
				'raw'           => $result,
				'cancelled'     => ! empty( $result['__pressark_cancelled'] ),
				'provider'      => $provider,
				'model'         => $this->ai->get_model(),
				'cache_metrics' => $this->extract_cache_metrics( $result, $provider ),
				'request_made'  => true,
			);
		};

		return $this->ai->with_byok_context( $do_work ) ?? array(
			'raw'           => array( 'error' => __( 'BYOK enabled but no API key configured.', 'pressark' ) ),
			'provider'      => $this->ai->get_provider(),
			'model'         => $this->ai->get_model(),
			'cache_metrics' => array( 'cache_read' => 0, 'cache_write' => 0 ),
			'request_made'  => false,
		);
	}

	/**
	 * Has the server-side run been cancelled?
	 */
	private function cancellation_requested(): bool {
		return is_callable( $this->cancel_check )
			? (bool) call_user_func( $this->cancel_check )
			: false;
	}

	/**
	 * Stream from an OpenAI-compatible endpoint (OpenRouter, OpenAI, DeepSeek, Gemini).
	 *
	 * @return array Accumulated raw response in the same shape as a non-streaming call.
	 */
	private function stream_openai( array $messages, array $tools, string $system_prompt ): array {
		$request = $this->ai->prepare_streaming_request( $messages, $tools, $system_prompt );

		$request['body']['stream']         = true;
		$request['body']['stream_options'] = array( 'include_usage' => true );

		$accumulated = array(
			'content'       => '',
			'tool_calls'    => array(),
			'finish_reason' => null,
			'usage'         => array(),
			'model'         => $request['body']['model'] ?? '',
		);

		$line_buffer   = '';
		$consume_chunk = function ( string $data ) use ( &$accumulated, &$line_buffer ): void {
			$this->consume_sse_chunk(
				$data,
				$line_buffer,
				function ( string $line ) use ( &$accumulated ): void {
					if ( ! str_starts_with( $line, 'data: ' ) ) {
						return;
					}

					$payload = substr( $line, 6 );
					if ( '[DONE]' === $payload ) {
						return;
					}

					$this->parse_openai_chunk( $payload, $accumulated );
				}
			);
		};

		$transport = $this->perform_streaming_http_request(
			$request['endpoint'],
			(array) $request['headers'],
			(string) wp_json_encode( $request['body'] ),
			$consume_chunk
		);

		$this->flush_sse_buffer(
			$line_buffer,
			function ( string $line ) use ( &$accumulated ): void {
				if ( ! str_starts_with( $line, 'data: ' ) ) {
					return;
				}

				$payload = substr( $line, 6 );
				if ( '[DONE]' === $payload ) {
					return;
				}

				$this->parse_openai_chunk( $payload, $accumulated );
			}
		);

		if ( $transport['stream_aborted'] ) {
			return array(
				'__pressark_cancelled' => true,
				'usage'                => $accumulated['usage'],
				'model'                => $accumulated['model'],
			);
		}

		$response    = $transport['response'];
		$has_content = ! empty( $accumulated['content'] ) || ! empty( $accumulated['tool_calls'] );

		if ( is_wp_error( $response ) ) {
			$http_error = $response->get_error_message();
			if ( $has_content ) {
				$http_error = '';
			} elseif ( str_contains( $http_error, 'timed out' ) || str_contains( $http_error, 'Operation timed out' ) ) {
				return array( 'error' => 'The AI provider took too long to respond. Try again or simplify your request.' );
			} elseif ( '' !== $http_error ) {
				return array( 'error' => 'Connection error: ' . $http_error );
			}
		}

		$http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code && empty( $accumulated['content'] ) && empty( $accumulated['tool_calls'] ) ) {
			return array( 'error' => sprintf( 'API error (%d): Streaming request failed.', $http_code ) );
		}

		if ( ! $has_content ) {
			PressArk_Error_Tracker::warning(
				'StreamConnector',
				'OpenAI-compatible stream returned no assistant content',
				array(
					'http_code'     => $http_code,
					'finish_reason' => (string) ( $accumulated['finish_reason'] ?? '' ),
				)
			);

			return array(
				'error' => __( 'The AI response ended before producing text or tool calls. No changes were made. Please retry.', 'pressark' ),
			);
		}

		$message = array(
			'role'    => 'assistant',
			'content' => $accumulated['content'] ?: null,
		);

		if ( ! empty( $accumulated['tool_calls'] ) ) {
			$message['tool_calls'] = array_values( $accumulated['tool_calls'] );
		}

		return array(
			'id'      => 'stream_' . wp_generate_uuid4(),
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => $message,
					'finish_reason' => $accumulated['finish_reason'] ?? 'stop',
				),
			),
			'usage'   => $accumulated['usage'],
			'model'   => $accumulated['model'],
		);
	}

	/**
	 * Stream from the Anthropic Messages API.
	 *
	 * @return array Accumulated raw response in the same shape as a non-streaming call.
	 */
	private function stream_anthropic( array $messages, array $tools, string $system_prompt ): array {
		$request = $this->ai->prepare_streaming_request( $messages, $tools, $system_prompt );

		$request['body']['stream'] = true;

		$accumulated = array(
			'content'     => array(),
			'stop_reason' => null,
			'usage'       => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
			'model'       => $request['body']['model'] ?? '',
		);

		$line_buffer   = '';
		$event_type    = '';
		$consume_chunk = function ( string $data ) use ( &$accumulated, &$line_buffer, &$event_type ): void {
			$this->consume_sse_chunk(
				$data,
				$line_buffer,
				function ( string $line ) use ( &$accumulated, &$event_type ): void {
					if ( str_starts_with( $line, 'event: ' ) ) {
						$event_type = substr( $line, 7 );
						return;
					}

					if ( ! str_starts_with( $line, 'data: ' ) ) {
						return;
					}

					$payload = substr( $line, 6 );
					$this->parse_anthropic_chunk( $event_type, $payload, $accumulated );
					$event_type = '';
				}
			);
		};

		$transport = $this->perform_streaming_http_request(
			$request['endpoint'],
			(array) $request['headers'],
			(string) wp_json_encode( $request['body'] ),
			$consume_chunk
		);

		$this->flush_sse_buffer(
			$line_buffer,
			function ( string $line ) use ( &$accumulated, &$event_type ): void {
				if ( str_starts_with( $line, 'event: ' ) ) {
					$event_type = substr( $line, 7 );
					return;
				}

				if ( ! str_starts_with( $line, 'data: ' ) ) {
					return;
				}

				$payload = substr( $line, 6 );
				$this->parse_anthropic_chunk( $event_type, $payload, $accumulated );
				$event_type = '';
			}
		);

		if ( $transport['stream_aborted'] ) {
			return array(
				'__pressark_cancelled' => true,
				'usage'                => $accumulated['usage'],
				'model'                => $accumulated['model'],
			);
		}

		$response    = $transport['response'];
		$has_content = ! empty( $accumulated['content'] );

		if ( is_wp_error( $response ) ) {
			$http_error = $response->get_error_message();
			if ( $has_content ) {
				$http_error = '';
			} elseif ( str_contains( $http_error, 'timed out' ) || str_contains( $http_error, 'Operation timed out' ) ) {
				return array( 'error' => 'The AI provider took too long to respond. Try again or simplify your request.' );
			} elseif ( '' !== $http_error ) {
				return array( 'error' => 'Connection error: ' . $http_error );
			}
		}

		$http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code && empty( $accumulated['content'] ) ) {
			return array( 'error' => sprintf( 'API error (%d): Streaming request failed.', $http_code ) );
		}

		return array(
			'id'          => 'stream_' . wp_generate_uuid4(),
			'type'        => 'message',
			'role'        => 'assistant',
			'content'     => $accumulated['content'],
			'model'       => $accumulated['model'],
			'stop_reason' => $accumulated['stop_reason'] ?? 'end_turn',
			'usage'       => $accumulated['usage'],
		);
	}

	/**
	 * Stream an AI call through the bank proxy.
	 *
	 * The bank holds the real API key, reserves credits, forwards to the
	 * provider, and streams SSE events back. The SSE format is identical
	 * to a direct OpenRouter call, so parse_openai_chunk() handles it.
	 *
	 * @since 5.0.0
	 * @return array Accumulated raw response in the same shape as a non-streaming call.
	 */
	private function stream_via_bank( array $messages, array $tools, string $system_prompt ): array {
		$provider = $this->ai->get_provider();

		$request = $this->ai->prepare_streaming_request( $messages, $tools, $system_prompt );

		$request['body']['stream']         = true;
		$request['body']['stream_options'] = array( 'include_usage' => true );

		$bank_url = defined( 'PRESSARK_TOKEN_BANK_URL' )
			? PRESSARK_TOKEN_BANK_URL
			: get_option( 'pressark_token_bank_url', 'https://tokens.pressark.com' );

		$bank = new PressArk_Token_Bank();
		$bank->ensure_handshaked();
		$site_token = (string) get_option( 'pressark_site_token', '' );

		if ( '' === $site_token ) {
			return array( 'error' => 'PressArk is still setting up your account. This usually takes a few seconds. Please try again.' );
		}

		$tier  = method_exists( $this->ai, 'get_tier' ) ? $this->ai->get_tier() : 'free';
		$model = $this->ai->get_model();

		$proxy_body = (string) wp_json_encode(
			array(
				'site_domain'       => PressArk_Token_Bank::current_site_identity(),
				'installation_uuid' => PressArk_Token_Bank::ensure_installation_uuid(),
				'user_id'           => get_current_user_id(),
				'tier'              => $tier,
				'model'             => $model,
				'provider'          => $provider,
				'stream'            => true,
				'estimated_icus'    => 5000,
				'icu_budget'        => PressArk_Entitlements::icu_budget( $tier ),
				'request_body'      => $request['body'],
			)
		);

		$accumulated = array(
			'content'       => '',
			'tool_calls'    => array(),
			'finish_reason' => null,
			'usage'         => array(),
			'model'         => $model,
		);

		$line_buffer   = '';
		$error_buffer  = '';
		$consume_chunk = function ( string $data ) use ( &$accumulated, &$line_buffer, &$error_buffer ): void {
			if ( strlen( $error_buffer ) < 16384 ) {
				$remaining     = 16384 - strlen( $error_buffer );
				$error_buffer .= substr( $data, 0, $remaining );
			}

			$this->consume_sse_chunk(
				$data,
				$line_buffer,
				function ( string $line ) use ( &$accumulated ): void {
					if ( ! str_starts_with( $line, 'data: ' ) ) {
						return;
					}

					$payload = substr( $line, 6 );
					if ( '[DONE]' === $payload ) {
						return;
					}

					$this->parse_openai_chunk( $payload, $accumulated );
				}
			);
		};

		$transport = $this->perform_streaming_http_request(
			trailingslashit( $bank_url ) . 'v1/chat',
			array(
				'Content-Type: application/json',
				'x-pressark-token: ' . $site_token,
			),
			$proxy_body,
			$consume_chunk
		);

		$this->flush_sse_buffer(
			$line_buffer,
			function ( string $line ) use ( &$accumulated ): void {
				if ( ! str_starts_with( $line, 'data: ' ) ) {
					return;
				}

				$payload = substr( $line, 6 );
				if ( '[DONE]' === $payload ) {
					return;
				}

				$this->parse_openai_chunk( $payload, $accumulated );
			}
		);

		if ( $transport['stream_aborted'] ) {
			return array(
				'__pressark_cancelled' => true,
				'usage'                => $accumulated['usage'],
				'model'                => $accumulated['model'],
			);
		}

		$response    = $transport['response'];
		$has_content = ! empty( $accumulated['content'] ) || ! empty( $accumulated['tool_calls'] );

		if ( is_wp_error( $response ) ) {
			$http_error = $response->get_error_message();
			if ( $has_content ) {
				$http_error = '';
			} elseif ( str_contains( $http_error, 'timed out' ) || str_contains( $http_error, 'Operation timed out' ) ) {
				return array( 'error' => 'The AI provider took too long to respond. Try again or simplify your request.' );
			} elseif ( '' !== $http_error ) {
				return array( 'error' => 'Bank proxy connection error: ' . $http_error );
			}
		}

		$http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $http_code && empty( $accumulated['content'] ) && empty( $accumulated['tool_calls'] ) ) {
			$error_body = trim( $error_buffer );
			if ( '' === $error_body ) {
				$error_body = is_wp_error( $response ) ? trim( $line_buffer ) : trim( (string) wp_remote_retrieve_body( $response ) );
			}

			$decoded_error = json_decode( $error_body, true );
			if ( is_array( $decoded_error ) ) {
				$normalized = PressArk_AI_Connector::normalize_bank_proxy_error( $decoded_error, $http_code );

				PressArk_Error_Tracker::warning(
					'StreamConnector',
					'Bank proxy stream request failed',
					array(
						'http_code'  => $http_code,
						'error_code' => $decoded_error['code'] ?? $decoded_error['error']['code'] ?? '',
						'error'      => $normalized['error'] ?? '',
					)
				);

				return $normalized;
			}

			PressArk_Error_Tracker::warning(
				'StreamConnector',
				'Bank proxy stream request failed',
				array(
					'http_code' => $http_code,
					'raw_body'  => mb_substr( $error_body, 0, 500 ),
				)
			);

			return array( 'error' => sprintf( 'Bank proxy error (HTTP %d): Streaming request failed.', $http_code ) );
		}

		if ( ! $has_content ) {
			$error_body = trim( $error_buffer );
			if ( '' === $error_body ) {
				$error_body = is_wp_error( $response ) ? trim( $line_buffer ) : trim( (string) wp_remote_retrieve_body( $response ) );
			}

			$decoded_error = json_decode( $error_body, true );
			if ( is_array( $decoded_error )
				&& ( ! empty( $decoded_error['error'] ) || ! empty( $decoded_error['message'] ) || ! empty( $decoded_error['code'] ) )
			) {
				$error_type = sanitize_key( (string) ( $decoded_error['error']['type'] ?? $decoded_error['type'] ?? '' ) );
				$normalized = 'invalid_json_reply' === $error_type
					? array(
						'error' => __( 'The AI response was interrupted before it produced valid JSON. No changes were made. Please retry.', 'pressark' ),
					)
					: PressArk_AI_Connector::normalize_bank_proxy_error( $decoded_error, $http_code );

				// v5.8.14 (2026-05-14): plain JSON proxy errors in streaming mode must fail, not settle as blank success.
				PressArk_Error_Tracker::warning(
					'StreamConnector',
					'Bank proxy stream returned an error payload without SSE content',
					array(
						'http_code'  => $http_code,
						'error_code' => $decoded_error['code'] ?? $decoded_error['error']['code'] ?? '',
						'error_type' => $error_type,
						'error'      => $normalized['error'] ?? '',
					)
				);

				return $normalized;
			}

			PressArk_Error_Tracker::warning(
				'StreamConnector',
				'Bank proxy stream returned no assistant content',
				array(
					'http_code'     => $http_code,
					'finish_reason' => (string) ( $accumulated['finish_reason'] ?? '' ),
					'raw_body'      => mb_substr( $error_body, 0, 500 ),
				)
			);

			return array(
				'error' => __( 'The AI response ended before producing text or tool calls. No changes were made. Please retry.', 'pressark' ),
			);
		}

		$message = array(
			'role'    => 'assistant',
			'content' => $accumulated['content'] ?: null,
		);

		if ( ! empty( $accumulated['tool_calls'] ) ) {
			$message['tool_calls'] = array_values( $accumulated['tool_calls'] );
		}

		return array(
			'id'      => 'stream_' . wp_generate_uuid4(),
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => $message,
					'finish_reason' => $accumulated['finish_reason'] ?? 'stop',
				),
			),
			'usage'   => $accumulated['usage'],
			'model'   => $accumulated['model'],
		);
	}

	/**
	 * Execute a streaming HTTP request via the WordPress HTTP API.
	 *
	 * When the cURL transport is active, attach a write callback through
	 * http_api_curl so SSE chunks can be consumed progressively. If another
	 * transport is selected, fall back to parsing the buffered response body.
	 *
	 * @param string   $endpoint      Request endpoint.
	 * @param array    $headers       Header lines or key/value pairs.
	 * @param string   $body          JSON request body.
	 * @param callable $consume_chunk Callback that processes raw response chunks.
	 * @return array{response:array|\WP_Error,used_curl_transport:bool,stream_aborted:bool}
	 */
	private function perform_streaming_http_request( string $endpoint, array $headers, string $body, callable $consume_chunk ): array {
		$used_curl_transport = false;
		$stream_aborted      = false;

		$configure_curl = function ( $handle, array $parsed_args, string $url = '' ) use ( $endpoint, $consume_chunk, &$used_curl_transport, &$stream_aborted ): void {
			unset( $parsed_args );

			if ( $endpoint !== $url ) {
				return;
			}

			$used_curl_transport = true;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- The WordPress HTTP API only exposes progressive SSE callbacks through http_api_curl.
			curl_setopt(
				$handle,
				CURLOPT_WRITEFUNCTION,
				function ( $curl_handle, $data ) use ( $consume_chunk, &$stream_aborted ) {
					unset( $curl_handle );

					$data = (string) $data;

					if ( connection_aborted() || ! $this->emitter->is_connected() || $this->cancellation_requested() ) {
						$stream_aborted = true;
						return 0;
					}

					$consume_chunk( $data );
					return strlen( $data );
				}
			);
		};

		add_action( 'http_api_curl', $configure_curl, 10, 3 );

		// Simulator observers (Claude Code sub-agents) respond on human-turn
		// latency, not API latency. Bump the HTTP read timeout to match the
		// agent-loop wall-clock budget in that mode; otherwise keep the normal
		// 120s cap that matches OpenRouter's streaming window.
		$stream_timeout = PressArk_AI_Connector::simulator_active()
			? PressArk_AI_Connector::SIMULATOR_TIMEOUT_SECONDS
			: 120;

		try {
			$response = wp_remote_post(
				$endpoint,
				array(
					'headers'     => $this->build_wp_remote_headers( $headers ),
					'body'        => $body,
					'timeout'     => $stream_timeout,
					'httpversion' => '1.1',
					'redirection' => 0,
					'blocking'    => true,
				)
			);
		} finally {
			remove_action( 'http_api_curl', $configure_curl, 10 );
		}

		if ( ! is_wp_error( $response ) && ! $used_curl_transport ) {
			$fallback_body = (string) wp_remote_retrieve_body( $response );
			if ( '' !== $fallback_body ) {
				$consume_chunk( $fallback_body );
			}
		}

		return array(
			'response'            => $response,
			'used_curl_transport' => $used_curl_transport,
			'stream_aborted'      => $stream_aborted,
		);
	}

	/**
	 * Normalize headers for wp_remote_post().
	 *
	 * @param array $headers Header lines or key/value pairs.
	 * @return array<string,string>
	 */
	private function build_wp_remote_headers( array $headers ): array {
		$normalized = array();

		foreach ( $headers as $key => $value ) {
			if ( is_string( $key ) && '' !== trim( $key ) ) {
				$normalized[ trim( $key ) ] = trim( (string) $value );
				continue;
			}

			if ( ! is_string( $value ) || false === strpos( $value, ':' ) ) {
				continue;
			}

			$parts       = explode( ':', $value, 2 );
			$header_name = trim( (string) ( $parts[0] ?? '' ) );
			$header_body = trim( (string) ( $parts[1] ?? '' ) );

			if ( '' === $header_name ) {
				continue;
			}

			$normalized[ $header_name ] = $header_body;
		}

		return $normalized;
	}

	/**
	 * Consume raw SSE bytes, emitting complete logical lines.
	 *
	 * @param string   $data         Raw response chunk.
	 * @param string   $line_buffer  Trailing partial line buffer.
	 * @param callable $line_handler Callback for complete lines.
	 */
	private function consume_sse_chunk( string $data, string &$line_buffer, callable $line_handler ): void {
		$line_buffer .= $data;
		$lines        = explode( "\n", $line_buffer );
		$line_buffer  = (string) array_pop( $lines );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$line_handler( $line );
		}
	}

	/**
	 * Flush any buffered SSE line after the transport completes.
	 *
	 * @param string   $line_buffer  Trailing partial line buffer.
	 * @param callable $line_handler Callback for complete lines.
	 */
	private function flush_sse_buffer( string &$line_buffer, callable $line_handler ): void {
		$line = trim( $line_buffer );
		if ( '' !== $line ) {
			$line_handler( $line );
		}

		$line_buffer = '';
	}

	/**
	 * Parse an OpenAI SSE chunk and emit tokens.
	 *
	 * OpenAI streaming format:
	 *   data: {"choices":[{"delta":{"content":"Hello"},"finish_reason":null}]}
	 *   data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"name":"read_content","arguments":"{\"post_id\":"}}]}}]}
	 */
	private function parse_openai_chunk( string $payload, array &$accumulated ): void {
		$chunk = json_decode( $payload, true );
		if ( ! is_array( $chunk ) ) {
			return;
		}

		if ( isset( $chunk['usage'] ) ) {
			$accumulated['usage'] = $chunk['usage'];
		}

		$delta  = $chunk['choices'][0]['delta'] ?? array();
		$finish = $chunk['choices'][0]['finish_reason'] ?? null;

		if ( null !== $finish ) {
			$accumulated['finish_reason'] = $finish;
		}

		if ( isset( $delta['content'] ) && '' !== $delta['content'] ) {
			$accumulated['content'] .= $delta['content'];
			$this->emitter->emit( 'token', array( 'text' => $delta['content'] ) );
		}

		if ( ! empty( $delta['tool_calls'] ) ) {
			foreach ( $delta['tool_calls'] as $tc_delta ) {
				$idx = $tc_delta['index'] ?? 0;

				if ( ! isset( $accumulated['tool_calls'][ $idx ] ) ) {
					$accumulated['tool_calls'][ $idx ] = array(
						'id'       => $tc_delta['id'] ?? '',
						'type'     => 'function',
						'function' => array(
							'name'      => $tc_delta['function']['name'] ?? '',
							'arguments' => '',
						),
					);
				}

				if ( ! empty( $tc_delta['function']['name'] ) ) {
					$accumulated['tool_calls'][ $idx ]['function']['name'] = $tc_delta['function']['name'];
				}
				if ( isset( $tc_delta['function']['arguments'] ) ) {
					$accumulated['tool_calls'][ $idx ]['function']['arguments'] .= $tc_delta['function']['arguments'];
				}
				if ( ! empty( $tc_delta['id'] ) ) {
					$accumulated['tool_calls'][ $idx ]['id'] = $tc_delta['id'];
				}
			}
		}
	}

	/**
	 * Parse an Anthropic SSE event and emit tokens.
	 *
	 * Anthropic streaming format:
	 *   event: content_block_start -> new text or tool_use block
	 *   event: content_block_delta -> incremental text or tool input
	 *   event: content_block_stop  -> block finished
	 *   event: message_delta       -> stop_reason, usage
	 *   event: message_start       -> initial usage
	 */
	private function parse_anthropic_chunk( string $event_type, string $payload, array &$accumulated ): void {
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		switch ( $event_type ) {
			case 'message_start':
				if ( isset( $data['message']['usage'] ) ) {
					$accumulated['usage']['input_tokens'] = (int) ( $data['message']['usage']['input_tokens'] ?? 0 );
				}
				if ( isset( $data['message']['model'] ) ) {
					$accumulated['model'] = $data['message']['model'];
				}
				break;

			case 'content_block_start':
				$block = $data['content_block'] ?? array();
				$index = $data['index'] ?? count( $accumulated['content'] );

				if ( 'text' === ( $block['type'] ?? '' ) ) {
					$accumulated['content'][ $index ] = array(
						'type' => 'text',
						'text' => $block['text'] ?? '',
					);
				} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
					$accumulated['content'][ $index ] = array(
						'type'        => 'tool_use',
						'id'          => $block['id'] ?? '',
						'name'        => $block['name'] ?? '',
						'input'       => array(),
						'_input_json' => '',
					);
				}
				break;

			case 'content_block_delta':
				$index = $data['index'] ?? 0;
				$delta = $data['delta'] ?? array();

				if ( 'text_delta' === ( $delta['type'] ?? '' ) ) {
					$text = $delta['text'] ?? '';
					if ( '' !== $text && isset( $accumulated['content'][ $index ] ) ) {
						$accumulated['content'][ $index ]['text'] .= $text;
						$this->emitter->emit( 'token', array( 'text' => $text ) );
					}
				} elseif ( 'input_json_delta' === ( $delta['type'] ?? '' ) ) {
					$json_part = $delta['partial_json'] ?? '';
					if ( '' !== $json_part && isset( $accumulated['content'][ $index ] ) ) {
						$accumulated['content'][ $index ]['_input_json'] .= $json_part;
					}
				}
				break;

			case 'content_block_stop':
				$index = $data['index'] ?? 0;
				if (
					isset( $accumulated['content'][ $index ] )
					&& 'tool_use' === $accumulated['content'][ $index ]['type']
					&& ! empty( $accumulated['content'][ $index ]['_input_json'] )
				) {
					$parsed = json_decode( $accumulated['content'][ $index ]['_input_json'], true );
					if ( is_array( $parsed ) ) {
						$accumulated['content'][ $index ]['input'] = $parsed;
					}
					unset( $accumulated['content'][ $index ]['_input_json'] );
				}

				if ( isset( $accumulated['content'][ $index ]['_input_json'] ) ) {
					unset( $accumulated['content'][ $index ]['_input_json'] );
				}
				break;

			case 'message_delta':
				$delta = $data['delta'] ?? array();
				if ( isset( $delta['stop_reason'] ) ) {
					$accumulated['stop_reason'] = $delta['stop_reason'];
				}
				if ( isset( $data['usage']['output_tokens'] ) ) {
					$accumulated['usage']['output_tokens'] = (int) $data['usage']['output_tokens'];
				}
				break;
		}
	}

	/**
	 * Extract cache metrics from a raw streamed response.
	 */
	private function extract_cache_metrics( array $raw, string $provider ): array {
		$usage = $raw['usage'] ?? array();

		if ( 'anthropic' === $provider ) {
			return array(
				'cache_read'  => (int) ( $usage['cache_read_input_tokens'] ?? $usage['cache_read'] ?? 0 ),
				'cache_write' => (int) ( $usage['cache_creation_input_tokens'] ?? $usage['cache_write'] ?? 0 ),
			);
		}

		$details = $usage['prompt_tokens_details'] ?? array();

		return array(
			'cache_read'  => (int) ( $details['cached_tokens'] ?? 0 ),
			'cache_write' => 0,
		);
	}
}
