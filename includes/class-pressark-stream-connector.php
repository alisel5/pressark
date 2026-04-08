<?php
/**
 * Streaming variant of AI provider calls using raw cURL with CURLOPT_WRITEFUNCTION.
 *
 * Reuses PressArk_AI_Connector for all non-streaming logic (model resolution,
 * system prompt building, message formatting, response extraction). Only replaces
 * the HTTP transport with a streaming cURL call.
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
	private PressArk_SSE_Emitter  $emitter;
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
	 * @return array { raw: array, provider: string, model: string, cache_metrics: array, request_made: bool }
	 */
	public function send_streaming(
		array  $messages,
		array  $tools        = array(),
		string $system_prompt = '',
		bool   $deep_mode    = false
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
		$request['body']['stream_options']  = array( 'include_usage' => true );

		// Accumulated state — built incrementally from SSE chunks.
		$accumulated = array(
			'content'      => '',
			'tool_calls'   => array(),
			'finish_reason' => null,
			'usage'        => array(),
			'model'        => $request['body']['model'] ?? '',
		);

		$line_buffer = '';
		$stream_aborted = false;

		// Raw cURL required for CURLOPT_WRITEFUNCTION (SSE streaming).
		// WordPress's WP_Http API does not support streaming write callbacks.
		$ch = curl_init( $request['endpoint'] );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => $request['headers'],
			CURLOPT_POSTFIELDS     => wp_json_encode( $request['body'] ),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$accumulated, &$line_buffer, &$stream_aborted ) {
				if ( connection_aborted() || ! $this->emitter->is_connected() || $this->cancellation_requested() ) {
					$stream_aborted = true;
					return 0; // Abort cURL.
				}

				$line_buffer .= $data;
				$lines = explode( "\n", $line_buffer );
				$line_buffer = array_pop( $lines ); // Keep incomplete line.

				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					if ( str_starts_with( $line, 'data: ' ) ) {
						$payload = substr( $line, 6 );
						if ( '[DONE]' === $payload ) {
							continue;
						}
						$this->parse_openai_chunk( $payload, $accumulated );
					}
				}

				return strlen( $data );
			},
		) );

		$this->apply_wp_proxy( $ch );

		curl_exec( $ch );
		$curl_error = curl_error( $ch );
		$http_code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $stream_aborted ) {
			return array(
				'__pressark_cancelled' => true,
				'usage'                => $accumulated['usage'],
				'model'                => $accumulated['model'],
			);
		}

		if ( ! empty( $curl_error ) ) {
			if ( str_contains( $curl_error, 'timed out' ) || str_contains( $curl_error, 'Operation timed out' ) ) {
				return array( 'error' => 'The AI provider took too long to respond. Try again or simplify your request.' );
			}
			return array( 'error' => 'Connection error: ' . $curl_error );
		}

		if ( $http_code !== 200 && empty( $accumulated['content'] ) && empty( $accumulated['tool_calls'] ) ) {
			return array( 'error' => sprintf( 'API error (%d): Streaming request failed.', $http_code ) );
		}

		// Reconstruct into the standard OpenAI response shape.
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

		// Accumulated state.
		$accumulated = array(
			'content'     => array(),
			'stop_reason' => null,
			'usage'       => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
			'model'       => $request['body']['model'] ?? '',
		);

		$line_buffer  = '';
		$event_type   = '';
		$stream_aborted = false;

		// Raw cURL required for CURLOPT_WRITEFUNCTION (SSE streaming).
		// WordPress's WP_Http API does not support streaming write callbacks.
		$ch = curl_init( $request['endpoint'] );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => $request['headers'],
			CURLOPT_POSTFIELDS     => wp_json_encode( $request['body'] ),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$accumulated, &$line_buffer, &$event_type, &$stream_aborted ) {
				if ( connection_aborted() || ! $this->emitter->is_connected() || $this->cancellation_requested() ) {
					$stream_aborted = true;
					return 0;
				}

				$line_buffer .= $data;
				$lines = explode( "\n", $line_buffer );
				$line_buffer = array_pop( $lines );

				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					if ( str_starts_with( $line, 'event: ' ) ) {
						$event_type = substr( $line, 7 );
						continue;
					}
					if ( str_starts_with( $line, 'data: ' ) ) {
						$payload = substr( $line, 6 );
						$this->parse_anthropic_chunk( $event_type, $payload, $accumulated );
						$event_type = '';
					}
				}

				return strlen( $data );
			},
		) );

		$this->apply_wp_proxy( $ch );

		curl_exec( $ch );
		$curl_error = curl_error( $ch );
		$http_code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $stream_aborted ) {
			return array(
				'__pressark_cancelled' => true,
				'usage'                => $accumulated['usage'],
				'model'                => $accumulated['model'],
			);
		}

		if ( ! empty( $curl_error ) ) {
			if ( str_contains( $curl_error, 'timed out' ) || str_contains( $curl_error, 'Operation timed out' ) ) {
				return array( 'error' => 'The AI provider took too long to respond. Try again or simplify your request.' );
			}
			return array( 'error' => 'Connection error: ' . $curl_error );
		}

		if ( $http_code !== 200 && empty( $accumulated['content'] ) ) {
			return array( 'error' => sprintf( 'API error (%d): Streaming request failed.', $http_code ) );
		}

		// Reconstruct into the standard Anthropic response shape.
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
	 * to a direct OpenRouter call — parse_openai_chunk() handles it.
	 *
	 * @since 5.0.0
	 * @return array Accumulated raw response in the same shape as a non-streaming call.
	 */
	private function stream_via_bank( array $messages, array $tools, string $system_prompt ): array {
		$provider = $this->ai->get_provider();

		// Build the provider-format request body using the same fresh runtime
		// contract as the direct transport path.
		$request = $this->ai->prepare_streaming_request( $messages, $tools, $system_prompt );

		$request['body']['stream']        = true;
		$request['body']['stream_options'] = array( 'include_usage' => true );

		$bank_url    = defined( 'PRESSARK_TOKEN_BANK_URL' )
			? PRESSARK_TOKEN_BANK_URL
			: get_option( 'pressark_token_bank_url', 'https://tokens.pressark.com' );

		// Ensure we have a site_token before streaming.
		// IMPORTANT: Read site_token AFTER handshake — on fresh installs the token
		// is empty until handshake completes, so reading before would send an empty
		// header causing a 401 "Bank proxy error (HTTP 401)" from the bank.
		$bank = new PressArk_Token_Bank();
		$bank->ensure_handshaked();
		$site_token  = (string) get_option( 'pressark_site_token', '' );

		// v5.2.0: Return error if no token available (prevents 401 from bank).
		if ( '' === $site_token ) {
			return array( 'error' => 'PressArk is still setting up your account. This usually takes a few seconds — please try again.' );
		}

		$tier  = method_exists( $this->ai, 'get_tier' ) ? $this->ai->get_tier() : 'free';
		$model = $this->ai->get_model();

		$proxy_body = wp_json_encode( array(
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
		) );

		// Accumulated state — same shape as stream_openai().
		$accumulated = array(
			'content'       => '',
			'tool_calls'    => array(),
			'finish_reason' => null,
			'usage'         => array(),
			'model'         => $model,
		);

		$line_buffer    = '';
		$error_buffer   = '';
		$stream_aborted = false;

		$ch = curl_init( trailingslashit( $bank_url ) . 'v1/chat' );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'x-pressark-token: ' . $site_token,
			),
			CURLOPT_POSTFIELDS     => $proxy_body,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$accumulated, &$line_buffer, &$error_buffer, &$stream_aborted ) {
				if ( connection_aborted() || ! $this->emitter->is_connected() || $this->cancellation_requested() ) {
					$stream_aborted = true;
					return 0;
				}

				if ( strlen( $error_buffer ) < 16384 ) {
					$remaining     = 16384 - strlen( $error_buffer );
					$error_buffer .= substr( $data, 0, $remaining );
				}

				$line_buffer .= $data;
				$lines = explode( "\n", $line_buffer );
				$line_buffer = array_pop( $lines );

				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					if ( str_starts_with( $line, 'data: ' ) ) {
						$payload = substr( $line, 6 );
						if ( '[DONE]' === $payload ) {
							continue;
						}
						$this->parse_openai_chunk( $payload, $accumulated );
					}
				}

				return strlen( $data );
			},
		) );

		$this->apply_wp_proxy( $ch );

		curl_exec( $ch );
		$curl_error = curl_error( $ch );
		$http_code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $stream_aborted ) {
			return array(
				'__pressark_cancelled' => true,
				'usage'                => $accumulated['usage'],
				'model'                => $accumulated['model'],
			);
		}

		if ( ! empty( $curl_error ) ) {
			// OpenSSL 3.x reports "unexpected eof" when the server closes the
			// connection after streaming completes. If we already accumulated
			// content, the stream succeeded — ignore the benign SSL EOF.
			$has_content = ! empty( $accumulated['content'] ) || ! empty( $accumulated['tool_calls'] );
			if ( $has_content ) {
				// Stream completed despite the SSL EOF — proceed normally.
			} elseif ( str_contains( $curl_error, 'timed out' ) || str_contains( $curl_error, 'Operation timed out' ) ) {
				return array( 'error' => 'The AI provider took too long to respond. Try again or simplify your request.' );
			} else {
				return array( 'error' => 'Bank proxy connection error: ' . $curl_error );
			}
		}

		// Non-200 with no streamed content = bank returned a JSON error.
		if ( 200 !== $http_code && empty( $accumulated['content'] ) && empty( $accumulated['tool_calls'] ) ) {
			$error_body = trim( $error_buffer );
			if ( '' === $error_body ) {
				$error_body = trim( $line_buffer );
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

		// Reconstruct into the standard OpenAI response shape.
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

		// Usage info (sent with stream_options.include_usage).
		if ( isset( $chunk['usage'] ) ) {
			$accumulated['usage'] = $chunk['usage'];
		}

		$delta = $chunk['choices'][0]['delta'] ?? array();
		$finish = $chunk['choices'][0]['finish_reason'] ?? null;

		if ( null !== $finish ) {
			$accumulated['finish_reason'] = $finish;
		}

		// Text content delta.
		if ( isset( $delta['content'] ) && '' !== $delta['content'] ) {
			$accumulated['content'] .= $delta['content'];
			$this->emitter->emit( 'token', array( 'text' => $delta['content'] ) );
		}

		// Tool call deltas — accumulate incrementally.
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
	 *   event: content_block_start → new text or tool_use block
	 *   event: content_block_delta → incremental text or tool input
	 *   event: content_block_stop  → block finished
	 *   event: message_delta       → stop_reason, usage
	 *   event: message_start       → initial usage
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
						'type'  => 'tool_use',
						'id'    => $block['id'] ?? '',
						'name'  => $block['name'] ?? '',
						'input' => array(),
						'_input_json' => '', // Accumulate raw JSON string.
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
				// Finalize tool_use input from accumulated JSON.
				if ( isset( $accumulated['content'][ $index ] )
					&& 'tool_use' === $accumulated['content'][ $index ]['type']
					&& ! empty( $accumulated['content'][ $index ]['_input_json'] )
				) {
					$parsed = json_decode( $accumulated['content'][ $index ]['_input_json'], true );
					if ( is_array( $parsed ) ) {
						$accumulated['content'][ $index ]['input'] = $parsed;
					}
					unset( $accumulated['content'][ $index ]['_input_json'] );
				}
				// Clean up _input_json for text blocks.
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
	 * Apply WordPress proxy configuration to a cURL handle.
	 *
	 * Raw cURL bypasses WP_Http, so WP_PROXY_* constants must be
	 * forwarded manually — matches wp-includes/Requests/Transport/cURL.php.
	 */
	private function apply_wp_proxy( $ch ): void {
		if ( defined( 'WP_PROXY_HOST' ) && WP_PROXY_HOST ) {
			$proxy = WP_PROXY_HOST;
			if ( defined( 'WP_PROXY_PORT' ) && WP_PROXY_PORT ) {
				$proxy .= ':' . WP_PROXY_PORT;
			}
			curl_setopt( $ch, CURLOPT_PROXY, $proxy );

			if ( defined( 'WP_PROXY_USERNAME' ) && WP_PROXY_USERNAME ) {
				$proxy_auth = WP_PROXY_USERNAME;
				if ( defined( 'WP_PROXY_PASSWORD' ) && WP_PROXY_PASSWORD ) {
					$proxy_auth .= ':' . WP_PROXY_PASSWORD;
				}
				curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy_auth );
			}
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

		// OpenAI / OpenRouter.
		$details = $usage['prompt_tokens_details'] ?? array();

		return array(
			'cache_read'  => (int) ( $details['cached_tokens'] ?? 0 ),
			'cache_write' => 0,
		);
	}
}
