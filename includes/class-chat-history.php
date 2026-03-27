<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database-backed chat history: CRUD for conversations.
 * Table: {prefix}pressark_chats
 */
class PressArk_Chat_History {

	/**
	 * Get the table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressark_chats';
	}

	/**
	 * List conversations for the current user.
	 *
	 * @param int $limit Max results.
	 * @return array
	 */
	public function list_chats( int $limit = 30 ): array {
		global $wpdb;

		$table   = $this->table();
		$user_id = get_current_user_id();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get a single conversation with messages.
	 *
	 * @param int $chat_id Chat ID.
	 * @return array|null
	 */
	public function get_chat( int $chat_id ): ?array {
		global $wpdb;

		$table   = $this->table();
		$user_id = get_current_user_id();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
				$chat_id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['messages'] = json_decode( $row['messages'] ?: '[]', true );
		return $row;
	}

	/**
	 * Create a new conversation.
	 *
	 * @param string $title    Chat title.
	 * @param array  $messages Messages array.
	 * @return int|false Inserted ID or false.
	 */
	public function create_chat( string $title, array $messages = array() ): int|false {
		global $wpdb;

		$messages = self::sanitize_messages( $messages );

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'user_id'    => get_current_user_id(),
				'title'      => sanitize_text_field( $title ),
				'messages'   => wp_json_encode( $messages ),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing conversation (title and/or messages).
	 *
	 * @param int        $chat_id  Chat ID.
	 * @param array|null $messages Updated messages (null = don't change).
	 * @param string|null $title   Updated title (null = don't change).
	 * @return bool
	 */
	public function update_chat( int $chat_id, ?array $messages = null, ?string $title = null ): bool {
		global $wpdb;

		$table   = $this->table();
		$user_id = get_current_user_id();

		// Verify ownership.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
				$chat_id,
				$user_id
			)
		);

		if ( ! $exists ) {
			return false;
		}

		$data    = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( null !== $messages ) {
			$data['messages'] = wp_json_encode( self::sanitize_messages( $messages ) );
			$formats[]        = '%s';
		}

		if ( null !== $title ) {
			$data['title'] = sanitize_text_field( $title );
			$formats[]     = '%s';
		}

		return (bool) $wpdb->update(
			$table,
			$data,
			array( 'id' => $chat_id, 'user_id' => $user_id ),
			$formats,
			array( '%d', '%d' )
		);
	}

	/**
	 * Delete a conversation.
	 *
	 * @param int $chat_id Chat ID.
	 * @return bool
	 */
	public function delete_chat( int $chat_id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete(
			$this->table(),
			array( 'id' => $chat_id, 'user_id' => get_current_user_id() ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Sanitize a messages array: enforce role/content discipline, strip HTML.
	 *
	 * Every message must be { role: user|assistant, content: string }.
	 * Content is plain text — HTML is stripped via sanitize_text_field()
	 * so stored payloads cannot become executable when loaded into the
	 * chat panel's markdown renderer.
	 *
	 * @param array $messages Raw messages array.
	 * @return array Sanitized messages.
	 */
	public static function sanitize_messages( array $messages ): array {
		$clean = array();

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}

			$role = sanitize_text_field( $msg['role'] ?? '' );
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}

			$content = $msg['content'] ?? '';
			if ( ! is_string( $content ) ) {
				continue;
			}

			$clean[] = array(
				'role'    => $role,
				'content' => sanitize_text_field( $content ),
			);
		}

		return $clean;
	}

	/**
	 * Generate a title from the first user message.
	 *
	 * @param string $message First user message.
	 * @return string
	 */
	public static function generate_title( string $message ): string {
		$title = wp_strip_all_tags( $message );
		$title = preg_replace( '/\s+/', ' ', $title );
		$title = trim( $title );

		if ( mb_strlen( $title ) > 60 ) {
			$title = mb_substr( $title, 0, 57 ) . '...';
		}

		return $title ?: __( 'New Chat', 'pressark' );
	}
}
