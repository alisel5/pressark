<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs all PressArk actions and enables undo functionality.
 */
class PressArk_Action_Logger {

	/**
	 * Log an action to the pressark_log table.
	 *
	 * @param string      $action_type Action type identifier.
	 * @param int|null    $target_id   Target post/object ID.
	 * @param string|null $target_type Target type (post, page, etc.).
	 * @param string|null $old_value   Serialized old value.
	 * @param string|null $new_value   Serialized new value.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function log( string $action_type, ?int $target_id, ?string $target_type, ?string $old_value, ?string $new_value ): int|false {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_log';

		$inserted = $wpdb->insert(
			$table,
			array(
				'action_type' => $action_type,
				'target_id'   => $target_id,
				'target_type' => $target_type,
				'old_value'   => $old_value,
				'new_value'   => $new_value,
				'user_id'     => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
				'undone'      => 0,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Undo a logged action by restoring the old value.
	 *
	 * @param int $log_id The log entry ID to undo.
	 * @return array{success: bool, message: string}
	 */
	public function undo( int $log_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_log';

		$entry = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $log_id, get_current_user_id() )
		);

		if ( ! $entry ) {
			return array( 'success' => false, 'message' => __( 'Log entry not found.', 'pressark' ) );
		}

		if ( $entry->undone ) {
			return array( 'success' => false, 'message' => __( 'This action has already been undone.', 'pressark' ) );
		}

		// Re-check that the user still has the capability required for this undo.
		$required_cap = PressArk_Capabilities::cap_for_undo( $entry );
		$cap_args     = $entry->target_id ? array( $required_cap, (int) $entry->target_id ) : array( $required_cap );
		if ( ! current_user_can( ...$cap_args ) ) {
			return array( 'success' => false, 'message' => __( 'You no longer have permission to undo this action.', 'pressark' ) );
		}

		if ( null === $entry->old_value ) {
			// For create_post actions, we can trash the post.
			if ( 'create_post' === $entry->action_type && $entry->target_id ) {
				wp_trash_post( $entry->target_id );
				$wpdb->update( $table, array( 'undone' => 1 ), array( 'id' => $log_id ), array( '%d' ), array( '%d' ) );
				return array( 'success' => true, 'message' => __( 'Created post moved to trash.', 'pressark' ) );
			}
			return array( 'success' => false, 'message' => __( 'Cannot undo: no previous value stored.', 'pressark' ) );
		}

		$old_data = json_decode( $entry->old_value, true );

		switch ( $entry->action_type ) {
			case 'edit_content':
			case 'find_and_replace':
			case 'bulk_edit':
				// Prefer revision-based restore if a revision_id was stored.
				if ( ! empty( $old_data['revision_id'] ) ) {
					$restored = wp_restore_post_revision( (int) $old_data['revision_id'] );
					if ( is_wp_error( $restored ) ) {
						return array( 'success' => false, 'message' => $restored->get_error_message() );
					}
				} else {
					// Fallback: field-by-field restore for legacy log entries.
					$update_args = array( 'ID' => $entry->target_id );
					if ( isset( $old_data['title'] ) ) {
						$update_args['post_title'] = $old_data['title'];
					}
					if ( isset( $old_data['content'] ) ) {
						$update_args['post_content'] = $old_data['content'];
					}
					if ( isset( $old_data['excerpt'] ) ) {
						$update_args['post_excerpt'] = $old_data['excerpt'];
					}
					$result = wp_update_post( wp_slash( $update_args ), true );
					if ( is_wp_error( $result ) ) {
						return array( 'success' => false, 'message' => $result->get_error_message() );
					}
				}
				break;

			case 'update_meta':
				if ( isset( $old_data['key'] ) ) {
					update_post_meta( $entry->target_id, $old_data['key'], $old_data['value'] ?? '' );
				}
				break;

			case 'edit_product':
				if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $entry->target_id );
					if ( $product ) {
						if ( isset( $old_data['name'] ) ) {
							$product->set_name( $old_data['name'] );
						}
						if ( isset( $old_data['description'] ) ) {
							$product->set_description( $old_data['description'] );
						}
						if ( isset( $old_data['short_description'] ) ) {
							$product->set_short_description( $old_data['short_description'] );
						}
						if ( isset( $old_data['regular_price'] ) ) {
							$product->set_regular_price( $old_data['regular_price'] );
						}
						if ( isset( $old_data['sale_price'] ) ) {
							$product->set_sale_price( $old_data['sale_price'] );
						}
						$product->save();
					}
				}
				break;

			case 'update_site_settings':
				// old_data is an associative array of option_name => old_value.
				foreach ( $old_data as $key => $value ) {
					update_option( $key, $value );
				}
				if ( isset( $old_data['permalink_structure'] ) ) {
					flush_rewrite_rules();
				}
				break;

			case 'update_media':
				if ( $entry->target_id ) {
					$update_args = array( 'ID' => $entry->target_id );
					if ( isset( $old_data['title'] ) ) {
						$update_args['post_title'] = $old_data['title'];
					}
					if ( isset( $old_data['caption'] ) ) {
						$update_args['post_excerpt'] = $old_data['caption'];
					}
					if ( isset( $old_data['description'] ) ) {
						$update_args['post_content'] = $old_data['description'];
					}
					if ( count( $update_args ) > 1 ) {
						wp_update_post( $update_args );
					}
					if ( isset( $old_data['alt'] ) ) {
						update_post_meta( $entry->target_id, '_wp_attachment_image_alt', $old_data['alt'] );
					}
				}
				break;

			case 'assign_terms':
				// old_data has term_ids array.
				if ( $entry->target_id && $entry->target_type && ! empty( $old_data['term_ids'] ) ) {
					wp_set_object_terms( $entry->target_id, $old_data['term_ids'], $entry->target_type );
				}
				break;

			case 'elementor_edit_widget':
				if ( ! empty( $old_data['_elementor_data'] ) ) {
					update_post_meta( $entry->target_id, '_elementor_data', $old_data['_elementor_data'] );
					if ( class_exists( '\Elementor\Plugin' ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
					}
				} else {
					return array( 'success' => false, 'message' => __( 'No previous Elementor data found.', 'pressark' ) );
				}
				break;

			case 'elementor_create_from_template':
				wp_trash_post( $entry->target_id );
				break;

			case 'update_theme_setting':
				if ( isset( $old_data['key'] ) ) {
					set_theme_mod( $old_data['key'], $old_data['value'] ?? '' );
				}
				break;

			case 'switch_theme':
				if ( ! empty( $old_data['stylesheet'] ) ) {
					switch_theme( $old_data['stylesheet'] );
				}
				break;

			default:
				return array( 'success' => false, 'message' => __( 'Undo not supported for this action type.', 'pressark' ) );
		}

		$wpdb->update( $table, array( 'undone' => 1 ), array( 'id' => $log_id ), array( '%d' ), array( '%d' ) );

		return array( 'success' => true, 'message' => __( 'Action undone successfully.', 'pressark' ) );
	}

	/**
	 * Log a post edit action using WordPress revisions.
	 * WordPress automatically creates a revision on wp_update_post —
	 * we just store the revision ID, not the full content.
	 */
	public function log_post_edit( int $post_id, string $action_type, array $context = [] ): int|false {
		global $wpdb;

		// Get the revision WordPress just created (most recent for this post)
		$revision = $wpdb->get_row( $wpdb->prepare("
			SELECT ID FROM {$wpdb->posts}
			WHERE post_parent = %d
			  AND post_type   = 'revision'
			ORDER BY post_modified DESC
			LIMIT 1
		", $post_id ) );

		$revision_id = $revision->ID ?? null;

		$table = $wpdb->prefix . 'pressark_log';

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'     => get_current_user_id(),
				'action_type' => $action_type,
				'target_id'   => $post_id,
				'target_type' => 'post',
				'old_value'   => wp_json_encode( array( 'revision_id' => $revision_id ) ),
				'new_value'   => wp_json_encode( $context ),
				'created_at'  => current_time( 'mysql' ),
				'undone'      => 0,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Undo a post edit by restoring the WordPress revision.
	 */
	public function undo_post_edit( int $log_id ): array {
		$result = $this->undo( $log_id );

		if ( ! empty( $result['success'] ) ) {
			return $result;
		}

		return array(
			'success' => false,
			'error'   => $result['message'] ?? __( 'Unable to undo this action.', 'pressark' ),
		);
	}

	/**
	 * Get recent actions for the current user.
	 *
	 * @param int $limit Number of entries to return.
	 * @return array
	 */
	public function get_recent( int $limit = 20 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, action_type, target_id, target_type, created_at, undone FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
				get_current_user_id(),
				$limit
			),
			ARRAY_A
		) ?: array();
	}
}
