<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Media, Comments, Taxonomies, Custom Fields & Forms action handlers.
 *
 * Handles: list_media, get_media, update_media, delete_media,
 *          bulk_delete_media, regenerate_thumbnails, list_comments,
 *          moderate_comments, reply_comment, list_taxonomies,
 *          manage_taxonomy, assign_terms, get_custom_fields,
 *          update_custom_field, list_forms.
 *
 * @since 2.7.0
 */

class PressArk_Handler_Media extends PressArk_Handler_Base {

	// ── Part C: Media Library ────────────────────────────────────────

	/**
	 * List media attachments, optionally filtered by post, type, or search.
	 */
	public function list_media( array $params ): array {
		// If post_id is provided, use get_attached_media for accurate results.
		if ( ! empty( $params['post_id'] ) ) {
			$post_id   = (int) $params['post_id'];
			$mime_type = sanitize_text_field( $params['type'] ?? $params['mime_type'] ?? '' );
			$attached  = get_attached_media( $mime_type ?: '', $post_id );
			$list      = array();

			$reg_sizes = wp_get_registered_image_subsizes();

			foreach ( $attached as $item ) {
				$meta    = wp_get_attachment_metadata( $item->ID );
				$gen     = $meta['sizes'] ?? array();
				$missing = count( array_diff_key( $reg_sizes, $gen ) );

				$list[] = array(
					'id'            => $item->ID,
					'title'         => $item->post_title,
					'url'           => wp_get_attachment_url( $item->ID ),
					'type'          => $item->post_mime_type,
					'alt'           => get_post_meta( $item->ID, '_wp_attachment_image_alt', true ),
					/* translators: %d: number of missing thumbnail sizes */
					'missing_sizes' => $missing > 0 ? sprintf( __( '%d sizes need regeneration', 'pressark' ), $missing ) : null,
				);
			}

			return array(
				'success'    => true,
				'post_id'    => $post_id,
				'post_title' => get_the_title( $post_id ),
				'count'      => count( $list ),
				'data'       => $list,
			);
		}

		// Standard query path.
		$count     = min( absint( $params['count'] ?? 20 ), 50 );
		$offset    = absint( $params['offset'] ?? 0 );
		$mime_type = sanitize_text_field( $params['mime_type'] ?? '' );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $count,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $mime_type ) ) {
			// Allow shorthand: "image" => "image/", "video" => "video/", etc.
			if ( ! str_contains( $mime_type, '/' ) ) {
				$mime_type .= '/';
			}
			$args['post_mime_type'] = $mime_type;
		}

		if ( ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}

		$attachments = get_posts( $args );
		$list        = array();
		$reg_sizes   = wp_get_registered_image_subsizes();

		foreach ( $attachments as $att ) {
			$meta    = wp_get_attachment_metadata( $att->ID );
			$gen     = $meta['sizes'] ?? array();
			$missing = count( array_diff_key( $reg_sizes, $gen ) );

			$list[] = array(
				'id'            => $att->ID,
				'title'         => $att->post_title,
				'filename'      => basename( get_attached_file( $att->ID ) ?: '' ),
				'mime_type'     => $att->post_mime_type,
				'url'           => wp_get_attachment_url( $att->ID ),
				'alt'           => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
				'width'         => $meta['width'] ?? null,
				'height'        => $meta['height'] ?? null,
				'date'          => $att->post_date,
				/* translators: %d: number of missing thumbnail sizes */
				'missing_sizes' => $missing > 0 ? sprintf( __( '%d sizes need regeneration', 'pressark' ), $missing ) : null,
			);
		}

		$total_attachments = (int) wp_count_posts( 'attachment' )->inherit;

		return array(
			'success'     => true,
			'message'     => sprintf(
				/* translators: %d: number of attachments found */
				__( 'Found %d attachment(s).', 'pressark' ),
				count( $list )
			),
			'data'        => $list,
			'_pagination' => array(
				'total'    => $total_attachments,
				'offset'   => $offset,
				'limit'    => $count,
				'has_more' => ( $offset + $count ) < $total_attachments,
			),
		);
	}

	/**
	 * Get full details of a single media attachment.
	 */
	public function get_media( array $params ): array {
		$id = absint( $params['attachment_id'] ?? $params['media_id'] ?? $params['id'] ?? 0 );
		if ( ! $id ) {
			return array( 'success' => false, 'message' => __( 'Attachment ID is required.', 'pressark' ) );
		}

		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return array( 'success' => false, 'message' => __( 'Attachment not found.', 'pressark' ) );
		}

		$meta = wp_get_attachment_metadata( $id );
		$url  = wp_get_attachment_url( $id );
		$orig_url = function_exists( 'wp_get_original_image_url' ) ? wp_get_original_image_url( $id ) : null;

		// Type detection.
		$type = wp_attachment_is( 'image', $id ) ? 'image'
			: ( wp_attachment_is( 'video', $id ) ? 'video'
			: ( wp_attachment_is( 'audio', $id ) ? 'audio' : 'file' ) );

		// Generated sizes vs registered sizes — detect missing thumbnails.
		$registered_sizes = wp_get_registered_image_subsizes();
		$generated_sizes  = $meta['sizes'] ?? array();
		$missing_sizes    = array_keys( array_diff_key( $registered_sizes, $generated_sizes ) );

		// EXIF — already stored in $meta, just expose useful fields.
		$exif = array();
		if ( ! empty( $meta['image_meta'] ) ) {
			$raw_exif    = $meta['image_meta'];
			$exif_fields = array(
				'camera', 'aperture', 'focal_length', 'iso',
				'shutter_speed', 'caption', 'credit', 'copyright',
				'title', 'created_timestamp', 'keywords',
			);
			foreach ( $exif_fields as $field ) {
				if ( ! empty( $raw_exif[ $field ] ) ) {
					$exif[ $field ] = $raw_exif[ $field ];
				}
			}
			if ( ! empty( $exif['created_timestamp'] ) ) {
				$exif['created_timestamp'] = gmdate( 'Y-m-d H:i:s', $exif['created_timestamp'] );
			}
		}

		$data = array(
			'id'            => $id,
			'title'         => $att->post_title,
			'filename'      => basename( get_attached_file( $id ) ?: '' ),
			'url'           => $url,
			'original_url'  => ( $orig_url && $orig_url !== $url ) ? $orig_url : null,
			'was_scaled'    => ( $orig_url && $orig_url !== $url ),
			'type'          => $type,
			'mime_type'     => $att->post_mime_type,
			'alt'           => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'       => $att->post_excerpt,
			'description'   => $att->post_content,
			'width'         => $meta['width'] ?? null,
			'height'        => $meta['height'] ?? null,
			'file_size'     => ! empty( $meta['filesize'] ) ? size_format( $meta['filesize'] ) : null,
			'sizes'         => array_keys( $generated_sizes ),
			'missing_sizes' => $missing_sizes,
			'exif'          => $exif ?: null,
			'uploaded'      => get_the_date( 'Y-m-d', $att ),
			'attached_to'   => $att->post_parent
				? array( 'id' => $att->post_parent, 'title' => get_the_title( $att->post_parent ) )
				: null,
		);

		// Which posts use this as featured image.
		global $wpdb;
		$used_as_featured = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 10",
				$id
			)
		);
		$data['featured_for'] = array_map( 'absint', $used_as_featured );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: attachment title */
				__( 'Details for "%s".', 'pressark' ),
				$att->post_title
			),
			'data'    => $data,
		);
	}

	/**
	 * Update media attachment metadata.
	 */
	public function update_media( array $params ): array {
		$id = absint( $params['attachment_id'] ?? 0 );
		if ( ! $id ) {
			return array( 'success' => false, 'message' => __( 'Attachment ID is required.', 'pressark' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to edit this attachment.', 'pressark' ) );
		}

		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return array( 'success' => false, 'message' => __( 'Attachment not found.', 'pressark' ) );
		}

		$changes = $params['changes'] ?? array();
		$updated = array();

		$old_values = array(
			'title'       => $att->post_title,
			'caption'     => $att->post_excerpt,
			'description' => $att->post_content,
			'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);

		$update_args = array( 'ID' => $id );

		if ( isset( $changes['title'] ) ) {
			$update_args['post_title'] = sanitize_text_field( $changes['title'] );
			$updated[] = 'title';
		}
		if ( isset( $changes['caption'] ) ) {
			$update_args['post_excerpt'] = sanitize_textarea_field( $changes['caption'] );
			$updated[] = 'caption';
		}
		if ( isset( $changes['description'] ) ) {
			$update_args['post_content'] = sanitize_textarea_field( $changes['description'] );
			$updated[] = 'description';
		}

		if ( count( $update_args ) > 1 ) {
			wp_update_post( $update_args );
		}

		if ( isset( $changes['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $changes['alt'] ) );
			$updated[] = 'alt text';
		}

		// Set as featured image for a post.
		if ( ! empty( $changes['set_featured_for'] ) ) {
			$target_post_id = absint( $changes['set_featured_for'] );
			if ( $target_post_id && current_user_can( 'edit_post', $target_post_id ) ) {
				set_post_thumbnail( $target_post_id, $id );
				$updated[] = sprintf(
					/* translators: %d: target post ID */
					__( 'featured image for post #%d', 'pressark' ),
					$target_post_id
				);
			}
		}

		if ( empty( $updated ) ) {
			return array( 'success' => false, 'message' => __( 'No changes specified.', 'pressark' ) );
		}

		$log_id = $this->logger->log(
			'update_media',
			$id,
			'attachment',
			wp_json_encode( $old_values ),
			wp_json_encode( $changes )
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: attachment title, 2: comma-separated list of updated fields */
				__( 'Updated attachment "%1$s": %2$s.', 'pressark' ),
				$att->post_title,
				implode( ', ', $updated )
			),
			'log_id'  => $log_id,
		);
	}

	/**
	 * Permanently delete a media attachment.
	 */
	public function delete_media( array $params ): array {
		$id = absint( $params['attachment_id'] ?? 0 );
		if ( ! $id ) {
			return array( 'success' => false, 'message' => __( 'Attachment ID is required.', 'pressark' ) );
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to delete this attachment.', 'pressark' ) );
		}

		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return array( 'success' => false, 'message' => __( 'Attachment not found.', 'pressark' ) );
		}

		$title = $att->post_title;
		$this->logger->log( 'delete_media', $id, 'attachment', wp_json_encode( array( 'title' => $title, 'url' => wp_get_attachment_url( $id ) ) ), null );

		$result = wp_delete_attachment( $id, true );
		if ( ! $result ) {
			return array( 'success' => false, 'message' => __( 'Failed to delete attachment.', 'pressark' ) );
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: attachment title */
				__( 'Deleted attachment "%s".', 'pressark' ),
				$title
			),
		);
	}

	/**
	 * Permanently delete multiple media attachments at once.
	 */
	public function bulk_delete_media( array $params ): array {
		$ids = array_map( 'absint', (array) ( $params['attachment_ids'] ?? array() ) );

		if ( empty( $ids ) ) {
			return array( 'success' => false, 'message' => __( 'Attachment IDs array is required.', 'pressark' ) );
		}

		// Cap to prevent timeouts.
		$ids = array_slice( $ids, 0, 50 );

		$deleted = 0;
		$errors  = array();

		foreach ( $ids as $id ) {
			if ( ! $id ) {
				continue;
			}

			if ( ! current_user_can( 'delete_post', $id ) ) {
				$errors[] = sprintf(
					/* translators: %d: attachment ID */
					__( '#%d: no permission', 'pressark' ),
					$id
				);
				continue;
			}

			$att = get_post( $id );
			if ( ! $att || 'attachment' !== $att->post_type ) {
				$errors[] = sprintf(
					/* translators: %d: attachment ID */
					__( '#%d: not found', 'pressark' ),
					$id
				);
				continue;
			}

			$this->logger->log(
				'delete_media',
				$id,
				'attachment',
				wp_json_encode( array( 'title' => $att->post_title, 'url' => wp_get_attachment_url( $id ) ) ),
				null
			);

			$result = wp_delete_attachment( $id, true );
			if ( $result ) {
				$deleted++;
			} else {
				$errors[] = sprintf( __( '"%s": delete failed', 'pressark' ), $att->post_title );
			}
		}

		$message = sprintf(
			/* translators: 1: deleted count 2: total count */
			__( 'Deleted %1$d of %2$d attachments.', 'pressark' ),
			$deleted,
			count( $ids )
		);
		if ( ! empty( $errors ) ) {
			/* translators: %s: semicolon-separated error list */
			$message .= ' ' . sprintf( __( 'Errors: %s', 'pressark' ), implode( '; ', $errors ) );
		}

		return array( 'success' => $deleted > 0, 'message' => $message );
	}

	/**
	 * Regenerate all thumbnail sizes for one or multiple images.
	 */
	public function regenerate_thumbnails( array $params ): array {
		$ids = array();

		if ( ! empty( $params['media_id'] ) ) {
			$ids = array( (int) $params['media_id'] );
		} elseif ( ! empty( $params['media_ids'] ) ) {
			$ids = array_map( 'intval', (array) $params['media_ids'] );
		} elseif ( ! empty( $params['post_id'] ) ) {
			$attached = get_attached_media( 'image', (int) $params['post_id'] );
			$ids      = wp_list_pluck( $attached, 'ID' );
		}

		if ( empty( $ids ) ) {
			return array( 'success' => false, 'message' => __( 'Provide media_id, media_ids, or post_id.', 'pressark' ) );
		}

		// Cap to prevent timeouts.
		$ids     = array_slice( $ids, 0, 20 );
		$results = array();

		foreach ( $ids as $attachment_id ) {
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$results[] = array(
					'id'      => $attachment_id,
					'success' => false,
					'error'   => __( 'No permission for this attachment.', 'pressark' ),
				);
				continue;
			}

			$file = get_attached_file( $attachment_id );

			if ( ! $file || ! file_exists( $file ) ) {
				$results[] = array(
					'id'      => $attachment_id,
					'success' => false,
					'error'   => __( 'Original file not found on disk.', 'pressark' ),
				);
				continue;
			}

			$meta = wp_generate_attachment_metadata( $attachment_id, $file );

			if ( empty( $meta ) || empty( $meta['file'] ) ) {
				$results[] = array(
					'id'      => $attachment_id,
					'success' => false,
					'error'   => __( 'Failed to generate metadata for this attachment.', 'pressark' ),
				);
				continue;
			}

			wp_update_attachment_metadata( $attachment_id, $meta );

			$results[] = array(
				'id'      => $attachment_id,
				'title'   => get_the_title( $attachment_id ),
				'success' => true,
				'sizes'   => sprintf(
					/* translators: %d: number of generated image sizes */
					__( '%d sizes generated', 'pressark' ),
					count( $meta['sizes'] ?? array() )
				),
			);
		}

		$success_count = count( array_filter( $results, fn( $r ) => $r['success'] ) );

		return array(
			'success' => $success_count > 0,
			'message' => sprintf(
				/* translators: 1: success count 2: total count */
				__( 'Regenerated thumbnails for %1$d of %2$d image(s).', 'pressark' ),
				$success_count,
				count( $ids )
			),
			'results' => $results,
		);
	}

	// ── Part D: Comments ──────────────────────────────────────────────

	/**
	 * List comments.
	 */
	public function list_comments( array $params ): array {
		$count   = min( absint( $params['count'] ?? 20 ), 50 );
		$status  = sanitize_text_field( $params['status'] ?? 'all' );
		$post_id = absint( $params['post_id'] ?? 0 );

		$args = array(
			'number' => $count,
			'status' => $status,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);

		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}

		if ( ! empty( $params['search'] ) ) {
			$args['search'] = sanitize_text_field( $params['search'] );
		}

		if ( empty( $params['include_pingbacks'] ) ) {
			$args['type__not_in'] = array( 'pingback', 'trackback' );
		}

		if ( ! empty( $params['author_email'] ) ) {
			$args['author_email'] = sanitize_email( $params['author_email'] );
		}

		$comments = get_comments( $args );
		$list = array();

		foreach ( $comments as $c ) {
			$list[] = array(
				'id'      => (int) $c->comment_ID,
				'post_id' => (int) $c->comment_post_ID,
				'post'    => get_the_title( $c->comment_post_ID ),
				'author'  => $c->comment_author,
				'email'   => $c->comment_author_email,
				'content' => mb_substr( wp_strip_all_tags( $c->comment_content ), 0, 200 ),
				'status'  => wp_get_comment_status( $c ),
				'date'    => $c->comment_date,
				'parent'  => (int) $c->comment_parent,
			);
		}

		$count_post_id = (int) ( $params['post_id'] ?? 0 );
		$counts        = wp_count_comments( $count_post_id );

		return array(
			'success' => true,
			'data'    => $list,
			'counts'  => array(
				'approved' => (int) $counts->approved,
				'pending'  => (int) $counts->moderated,
				'spam'     => (int) $counts->spam,
				'trash'    => (int) $counts->trash,
				'total'    => (int) $counts->total_comments,
			),
			'message' => sprintf(
				/* translators: 1: shown count 2: approved 3: pending 4: spam */
				__( 'Showing %1$d comment(s). Site totals: %2$d approved, %3$d pending, %4$d spam.', 'pressark' ),
				count( $list ),
				$counts->approved,
				$counts->moderated,
				$counts->spam
			),
		);
	}

	/**
	 * Moderate comments (approve, unapprove, spam, trash).
	 */
	public function moderate_comments( array $params ): array {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to moderate comments.', 'pressark' ) );
		}

		$comment_ids = $params['comment_ids'] ?? array();
		// v5.0.6: AI models sometimes pass IDs as comma-separated string instead of array.
		if ( is_string( $comment_ids ) ) {
			$comment_ids = array_filter( array_map( 'absint', explode( ',', $comment_ids ) ) );
		}
		$comment_ids = (array) $comment_ids;
		$action      = sanitize_text_field( $params['action'] ?? '' );

		if ( empty( $comment_ids ) ) {
			return array( 'success' => false, 'message' => __( 'Comment IDs are required.', 'pressark' ) );
		}

		$valid_actions = array( 'approve', 'hold', 'spam', 'unspam', 'trash', 'untrash', 'unapprove' );

		if ( ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: requested comment moderation action */
					__( 'Invalid action: %s. Use approve, unapprove/hold, spam, unspam, trash, or untrash.', 'pressark' ),
					$action
				),
			);
		}

		// Normalize legacy alias.
		if ( 'unapprove' === $action ) {
			$action = 'hold';
		}

		$success_count = 0;
		$failed        = array();

		foreach ( $comment_ids as $cid ) {
			$cid = absint( $cid );
			if ( ! $cid ) continue;

			$result = match ( $action ) {
				'spam'    => wp_spam_comment( $cid ),
				'unspam'  => wp_unspam_comment( $cid ),
				'trash'   => wp_trash_comment( $cid ),
				'untrash' => wp_untrash_comment( $cid ),
				'approve' => wp_set_comment_status( $cid, 'approve' ),
				'hold'    => wp_set_comment_status( $cid, 'hold' ),
				default   => false,
			};

			if ( $result ) {
				$success_count++;
			} else {
				$failed[] = $cid;
			}
		}

		$this->logger->log( 'moderate_comments', null, 'comment', wp_json_encode( $comment_ids ), wp_json_encode( array( 'action' => $action ) ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: action 2: count */
				__( '%1$s %2$d comment(s).', 'pressark' ),
				ucfirst( $action ) . ( 'd' === substr( $action, -1 ) ? '' : 'd' ),
				$success_count
			),
		);
	}

	/**
	 * Reply to a comment.
	 */
	public function reply_comment( array $params ): array {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to reply to comments.', 'pressark' ) );
		}

		$parent_id = absint( $params['comment_id'] ?? 0 );
		$content   = sanitize_textarea_field( $params['content'] ?? '' );

		if ( ! $parent_id ) {
			return array( 'success' => false, 'message' => __( 'Parent comment ID is required.', 'pressark' ) );
		}
		if ( empty( $content ) ) {
			return array( 'success' => false, 'message' => __( 'Reply content is required.', 'pressark' ) );
		}

		$parent = get_comment( $parent_id );
		if ( ! $parent ) {
			return array( 'success' => false, 'message' => __( 'Parent comment not found.', 'pressark' ) );
		}

		$user = wp_get_current_user();
		$new_comment_id = wp_insert_comment( array(
			'comment_post_ID'  => $parent->comment_post_ID,
			'comment_content'  => $content,
			'comment_parent'   => $parent_id,
			'comment_author'   => $user->display_name,
			'comment_author_email' => $user->user_email,
			'user_id'          => $user->ID,
			'comment_approved' => 1,
		) );

		if ( ! $new_comment_id ) {
			return array( 'success' => false, 'message' => __( 'Failed to post reply.', 'pressark' ) );
		}

		$this->logger->log( 'reply_comment', $new_comment_id, 'comment', null, wp_json_encode( array( 'parent' => $parent_id, 'content' => $content ) ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: parent comment ID, 2: new reply comment ID */
				__( 'Replied to comment #%1$d (new comment #%2$d).', 'pressark' ),
				$parent_id,
				$new_comment_id
			),
		);
	}

	// ── Part E: Taxonomies ────────────────────────────────────────────

	/**
	 * List taxonomies and their terms.
	 */
	public function list_taxonomies( array $params ): array {
		$taxonomy   = sanitize_text_field( $params['taxonomy'] ?? '' );
		$hide_empty = ! empty( $params['hide_empty'] );

		if ( ! empty( $taxonomy ) ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: taxonomy slug */
						__( 'Taxonomy "%s" does not exist.', 'pressark' ),
						$taxonomy
					),
				);
			}

			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => $hide_empty,
				'number'     => 100,
			) );

			if ( is_wp_error( $terms ) ) {
				return array( 'success' => false, 'message' => $terms->get_error_message() );
			}

			$term_list = array();
			foreach ( $terms as $t ) {
				$term_list[] = array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'slug'   => $t->slug,
					'count'  => $t->count,
					'parent' => $t->parent,
				);
			}

			$tax_obj = get_taxonomy( $taxonomy );
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: taxonomy label, 2: number of terms */
					__( 'Taxonomy "%1$s" has %2$d term(s).', 'pressark' ),
					$tax_obj->labels->name,
					count( $term_list )
				),
				'data'    => array(
					'taxonomy'     => $taxonomy,
					'label'        => $tax_obj->labels->name,
					'hierarchical' => $tax_obj->hierarchical,
					'terms'        => $term_list,
				),
			);
		}

		// List all public taxonomies.
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$list = array();

		foreach ( $taxonomies as $tax ) {
			$term_count = wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );
			$list[] = array(
				'name'         => $tax->name,
				'label'        => $tax->labels->name,
				'hierarchical' => $tax->hierarchical,
				'term_count'   => is_wp_error( $term_count ) ? 0 : (int) $term_count,
				'post_types'   => $tax->object_type,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of public taxonomies found */
				__( 'Found %d public taxonomy/taxonomies.', 'pressark' ),
				count( $list )
			),
			'data'    => $list,
		);
	}

	/**
	 * Manage taxonomy terms (create, edit, delete).
	 */
	public function manage_taxonomy( array $params ): array {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to manage taxonomy terms.', 'pressark' ) );
		}

		$operation = sanitize_text_field( $params['operation'] ?? '' );
		$taxonomy  = sanitize_text_field( $params['taxonomy'] ?? '' );

		if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return array( 'success' => false, 'message' => __( 'Valid taxonomy slug is required.', 'pressark' ) );
		}

		switch ( $operation ) {
			case 'create':
				$name = sanitize_text_field( $params['name'] ?? '' );
				if ( empty( $name ) ) {
					return array( 'success' => false, 'message' => __( 'Term name is required.', 'pressark' ) );
				}
				$term_args = array();
				if ( ! empty( $params['slug'] ) ) {
					$term_args['slug'] = sanitize_title( $params['slug'] );
				}
				if ( ! empty( $params['description'] ) ) {
					$term_args['description'] = sanitize_textarea_field( $params['description'] );
				}
				if ( ! empty( $params['parent'] ) ) {
					$term_args['parent'] = absint( $params['parent'] );
				}
				$result = wp_insert_term( $name, $taxonomy, $term_args );
				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}
				$this->logger->log( 'manage_taxonomy', $result['term_id'], $taxonomy, null, wp_json_encode( array( 'name' => $name, 'operation' => 'create' ) ) );
				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: term name, 2: taxonomy slug, 3: term ID */
						__( 'Created term "%1$s" in %2$s (ID: %3$d).', 'pressark' ),
						$name,
						$taxonomy,
						$result['term_id']
					),
				);

			case 'edit':
				$term_id = absint( $params['term_id'] ?? 0 );
				if ( ! $term_id ) {
					return array( 'success' => false, 'message' => __( 'Term ID is required for editing.', 'pressark' ) );
				}
				$update_args = array();
				if ( ! empty( $params['name'] ) ) {
					$update_args['name'] = sanitize_text_field( $params['name'] );
				}
				if ( ! empty( $params['slug'] ) ) {
					$update_args['slug'] = sanitize_title( $params['slug'] );
				}
				if ( isset( $params['description'] ) ) {
					$update_args['description'] = sanitize_textarea_field( $params['description'] );
				}
				if ( isset( $params['parent'] ) ) {
					$update_args['parent'] = absint( $params['parent'] );
				}
				if ( empty( $update_args ) ) {
					return array( 'success' => false, 'message' => __( 'No changes specified.', 'pressark' ) );
				}
				$old_term = get_term( $term_id, $taxonomy );
				$result = wp_update_term( $term_id, $taxonomy, $update_args );
				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}
				$this->logger->log( 'manage_taxonomy', $term_id, $taxonomy, wp_json_encode( array( 'name' => $old_term->name, 'slug' => $old_term->slug ) ), wp_json_encode( $update_args ) );
				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: term ID, 2: taxonomy slug */
						__( 'Updated term #%1$d in %2$s.', 'pressark' ),
						$term_id,
						$taxonomy
					),
				);

			case 'delete':
				$term_id = absint( $params['term_id'] ?? 0 );
				if ( ! $term_id ) {
					return array( 'success' => false, 'message' => __( 'Term ID is required for deletion.', 'pressark' ) );
				}
				$term = get_term( $term_id, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					return array( 'success' => false, 'message' => __( 'Term not found.', 'pressark' ) );
				}
				$this->logger->log( 'manage_taxonomy', $term_id, $taxonomy, wp_json_encode( array( 'name' => $term->name, 'slug' => $term->slug ) ), null );
				$result = wp_delete_term( $term_id, $taxonomy );
				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}
				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: term name, 2: taxonomy slug */
						__( 'Deleted term "%1$s" from %2$s.', 'pressark' ),
						$term->name,
						$taxonomy
					),
				);

			default:
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: requested taxonomy operation */
						__( 'Unknown taxonomy operation: %s', 'pressark' ),
						$operation
					),
				);
		}
	}

	/**
	 * Assign terms to a post.
	 */
	public function assign_terms( array $params ): array {
		$post_id  = absint( $params['post_id'] ?? 0 );
		$taxonomy = sanitize_text_field( $params['taxonomy'] ?? '' );
		$terms    = $params['terms'] ?? array();
		$append   = ! empty( $params['append'] );

		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Post ID is required.', 'pressark' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to edit this post.', 'pressark' ) );
		}
		if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return array( 'success' => false, 'message' => __( 'Valid taxonomy slug is required.', 'pressark' ) );
		}
		if ( empty( $terms ) || ! is_array( $terms ) ) {
			return array( 'success' => false, 'message' => __( 'Terms array is required.', 'pressark' ) );
		}

		// Resolve term names to IDs, creating if needed for non-hierarchical taxonomies.
		$term_ids = $this->resolve_term_ids( $terms, $taxonomy );

		// Save old terms for undo.
		$old_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

		$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append );
		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'message' => $result->get_error_message() );
		}

		$this->logger->log(
			'assign_terms',
			$post_id,
			$taxonomy,
			wp_json_encode( array( 'term_ids' => is_wp_error( $old_terms ) ? array() : $old_terms ) ),
			wp_json_encode( array( 'term_ids' => $term_ids, 'append' => $append ) )
		);

		$post = get_post( $post_id );
		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: number of assigned terms, 2: post title, 3: taxonomy slug */
				__( 'Assigned %1$d term(s) to "%2$s" in %3$s.', 'pressark' ),
				count( $term_ids ),
				$post ? $post->post_title : '#' . $post_id,
				$taxonomy
			),
		);
	}

	/**
	 * Resolve an array of term names/IDs to term IDs.
	 * For non-hierarchical taxonomies (tags), creates terms that don't exist.
	 *
	 * @param array  $terms    Array of term names (strings) or IDs (ints).
	 * @param string $taxonomy Taxonomy slug.
	 * @return array Array of term IDs.
	 */
	private function resolve_term_ids( array $terms, string $taxonomy ): array {
		$tax_obj  = get_taxonomy( $taxonomy );
		$term_ids = array();

		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$term_ids[] = absint( $term );
				continue;
			}

			$name = sanitize_text_field( $term );
			$existing = get_term_by( 'name', $name, $taxonomy );

			if ( $existing ) {
				$term_ids[] = $existing->term_id;
			} elseif ( ! $tax_obj->hierarchical ) {
				// Create non-hierarchical terms (like tags) on the fly.
				$new = wp_insert_term( $name, $taxonomy );
				if ( ! is_wp_error( $new ) ) {
					$term_ids[] = $new['term_id'];
				}
			} else {
				// For hierarchical (categories), try slug match.
				$by_slug = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
				if ( $by_slug ) {
					$term_ids[] = $by_slug->term_id;
				}
			}
		}

		return $term_ids;
	}

	// ── Custom Fields ─────────────────────────────────────────────────

	/**
	 * Get custom fields for a post (ACF-aware with raw postmeta fallback).
	 */
	public function get_custom_fields( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => __( 'Post not found.', 'pressark' ) );
		}

		// Collect registered meta keys for this post type.
		$registered      = get_registered_meta_keys( 'post', $post->post_type );
		$registered_info = array();
		foreach ( $registered as $key => $schema ) {
			$registered_info[ $key ] = array(
				'type'        => $schema['type'] ?? 'string',
				'description' => $schema['description'] ?? '',
				'default'     => $schema['default'] ?? null,
				'single'      => $schema['single'] ?? true,
			);
		}

		// ACF path — uses field objects for rich type/label data.
		if ( function_exists( 'get_field_objects' ) ) {
			$field_objects = get_field_objects( $post_id );

			if ( ! empty( $field_objects ) ) {
				$fields     = array();
				$seen_keys  = array();
				foreach ( $field_objects as $key => $field ) {
					$seen_keys[ $key ] = true;
					$entry = array(
						'key'          => $key,
						'label'        => $field['label']        ?? $key,
						'type'         => $field['type']         ?? 'text',
						'value'        => $field['value']        ?? null,
						'instructions' => $field['instructions'] ?? '',
						'required'     => $field['required']     ?? false,
						'source'       => 'acf',
					);
					if ( isset( $registered_info[ $key ] ) ) {
						$entry['registered'] = true;
					}
					$fields[] = $entry;
				}

				// Append registered keys not already covered by ACF.
				foreach ( $registered_info as $key => $schema ) {
					if ( isset( $seen_keys[ $key ] ) || str_starts_with( $key, '_' ) ) {
						continue;
					}
					$fields[] = array(
						'key'         => $key,
						'label'       => $schema['description'] ?: ucwords( str_replace( array( '_', '-' ), ' ', $key ) ),
						'type'        => $schema['type'],
						'value'       => get_post_meta( $post_id, $key, $schema['single'] ),
						'source'      => 'registered',
						'registered'  => true,
					);
				}

				return array(
					'post_id'   => $post_id,
					'source'    => 'acf',
					'count'     => count( $fields ),
					'fields'    => $fields,
					'edit_hint' => __( 'Use update_custom_field with the key name to update any field.', 'pressark' ),
				);
			}
		}

		// Fallback — raw postmeta, filter out WordPress internals.
		$all_meta    = get_post_meta( $post_id );
		$public_meta = array();
		$seen_keys   = array();

		$skip_prefixes = array( '_edit_', '_wp_', '_encloseme', '_pingme', '_yoast',
		                        'rank_math', '_elementor', '_thumbnail_id' );

		foreach ( $all_meta as $key => $values ) {
			// Skip WordPress internal keys (start with _) unless whitelisted.
			if ( str_starts_with( $key, '_' ) ) {
				$whitelisted = array( '_pressark_meta_title', '_pressark_meta_description',
				                      '_pressark_og_title', '_pressark_og_description' );
				if ( ! in_array( $key, $whitelisted, true ) ) {
					continue;
				}
			}

			// Skip known internal patterns.
			$skip = false;
			foreach ( $skip_prefixes as $prefix ) {
				if ( str_contains( $key, $prefix ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$seen_keys[ $key ] = true;
			$value = maybe_unserialize( $values[0] ?? '' );
			$entry = array(
				'key'   => $key,
				'label' => ucwords( str_replace( array( '_', '-' ), ' ', $key ) ),
				'type'  => is_array( $value ) ? 'array' : ( is_numeric( $value ) ? 'number' : 'text' ),
				'value' => is_array( $value ) ? wp_json_encode( $value ) : $value,
			);
			if ( isset( $registered_info[ $key ] ) ) {
				$entry['type']       = $registered_info[ $key ]['type'];
				$entry['registered'] = true;
			}
			$public_meta[] = $entry;
		}

		// Append registered keys that have no stored value yet.
		foreach ( $registered_info as $key => $schema ) {
			if ( isset( $seen_keys[ $key ] ) || str_starts_with( $key, '_' ) ) {
				continue;
			}
			$public_meta[] = array(
				'key'        => $key,
				'label'      => $schema['description'] ?: ucwords( str_replace( array( '_', '-' ), ' ', $key ) ),
				'type'       => $schema['type'],
				'value'      => $schema['default'],
				'registered' => true,
				'empty'      => true,
			);
		}

		return array(
			'post_id'   => $post_id,
			'source'    => 'raw_postmeta',
			'count'     => count( $public_meta ),
			'fields'    => $public_meta,
			'edit_hint' => __( 'Use update_custom_field with the key name to update any field.', 'pressark' ),
			'note'      => __( 'ACF not detected. Showing public post meta and registered meta fields.', 'pressark' ),
		);
	}

	/**
	 * Update a custom field value.
	 * Uses ACF API when available, falls back to update_post_meta.
	 */
	public function update_custom_field( array $args ): array {
		$post_id = (int)   ( $args['post_id'] ?? 0 );
		$key     = (string)( $args['key']     ?? '' );
		$value   =           $args['value']   ?? '';

		if ( ! $post_id || ! $key ) {
			return array( 'error' => __( 'post_id and key are required.', 'pressark' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => __( 'Post not found.', 'pressark' ) );
		}

		// Validate against registered meta schema when available.
		$post_type = $post->post_type;
		if ( registered_meta_key_exists( 'post', $key, $post_type ) ) {
			$registered = get_registered_meta_keys( 'post', $post_type );
			$schema     = $registered[ $key ] ?? array();
			$expected   = $schema['type'] ?? 'string';

			// Basic type coercion/validation.
			switch ( $expected ) {
				case 'integer':
					if ( ! is_numeric( $value ) ) {
						return array(
							'error' => sprintf(
								/* translators: 1: custom field key, 2: received PHP type */
								__( "Field '%1$s' expects an integer, got: %2$s", 'pressark' ),
								$key,
								gettype( $value )
							),
						);
					}
					$value = (int) $value;
					break;
				case 'number':
					if ( ! is_numeric( $value ) ) {
						return array(
							'error' => sprintf(
								/* translators: 1: custom field key, 2: received PHP type */
								__( "Field '%1$s' expects a number, got: %2$s", 'pressark' ),
								$key,
								gettype( $value )
							),
						);
					}
					$value = (float) $value;
					break;
				case 'boolean':
					$value = (bool) $value;
					break;
				case 'array':
				case 'object':
					if ( is_string( $value ) ) {
						$decoded = json_decode( $value, true );
						if ( json_last_error() === JSON_ERROR_NONE ) {
							$value = $decoded;
						}
					}
					break;
			}
		}

		$old_value = get_post_meta( $post_id, $key, true );

		// Use ACF update_field when available (handles field groups, validation).
		if ( function_exists( 'update_field' ) ) {
			$result = update_field( $key, $value, $post_id );
		} else {
			$result = update_post_meta( $post_id, $key, $value );
		}

		if ( $result === false ) {
			return array(
				'error' => sprintf(
					/* translators: %s: custom field key */
					__( "Failed to update field '%s'.", 'pressark' ),
					$key
				),
			);
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
			'key'     => $key,
			'before'  => is_array( $old_value ) ? wp_json_encode( $old_value ) : $old_value,
			'after'   => is_array( $value )     ? wp_json_encode( $value )     : $value,
		);
	}

	// ── Forms Detection ───────────────────────────────────────────────

	/**
	 * List all forms on the site.
	 * Detects Contact Form 7, WPForms, Gravity Forms, Fluent Forms.
	 * Returns form list + email configuration + submission stats where available.
	 */
	public function list_forms( array $args ): array {
		$forms    = array();
		$detected = array();

		// ─── Contact Form 7 ──────────────────────────────────────────────
		if ( class_exists( 'WPCF7' ) || post_type_exists( 'wpcf7_contact_form' ) ) {
			$detected[] = 'Contact Form 7';

			$cf7_posts = get_posts( array(
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
			) );

			foreach ( $cf7_posts as $form_post ) {
				$form_entry = array(
					'id'        => $form_post->ID,
					'title'     => $form_post->post_title,
					'plugin'    => 'Contact Form 7',
					'shortcode' => '[contact-form-7 id="' . $form_post->ID . '"]',
				);

				// Get mail settings.
				$mail = get_post_meta( $form_post->ID, '_mail', true );
				if ( is_array( $mail ) ) {
					$form_entry['email_to']      = $mail['recipient'] ?? '';
					$form_entry['email_subject'] = $mail['subject']   ?? '';
					$form_entry['email_from']    = $mail['sender']    ?? '';
				}

				// Check if form is used in any post.
				global $wpdb;
				$usage = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_content LIKE %s AND post_status = 'publish'",
					'%[contact-form-7 id="' . $form_post->ID . '"%'
				) );
				$form_entry['used_in_pages'] = (int) $usage;

				$forms[] = $form_entry;
			}
		}

		// ─── WPForms ─────────────────────────────────────────────────────
		if ( class_exists( 'WPForms' ) || function_exists( 'wpforms' ) || post_type_exists( 'wpforms' ) ) {
			$detected[] = 'WPForms';

			$wpf_posts = get_posts( array(
				'post_type'      => 'wpforms',
				'posts_per_page' => 50,
				'post_status'    => 'any',
			) );

			foreach ( $wpf_posts as $form_post ) {
				$form_data  = json_decode( $form_post->post_content, true );
				$settings   = $form_data['settings'] ?? array();
				$form_entry = array(
					'id'          => $form_post->ID,
					'title'       => $form_post->post_title,
					'plugin'      => 'WPForms',
					'shortcode'   => '[wpforms id="' . $form_post->ID . '"]',
					'email_to'    => $settings['notification_email'] ?? '',
					'field_count' => count( $form_data['fields'] ?? array() ),
				);
				$forms[] = $form_entry;
			}
		}

		// ─── Gravity Forms ───────────────────────────────────────────────
		if ( class_exists( 'GFForms' ) && method_exists( 'GFAPI', 'get_forms' ) ) {
			$detected[] = 'Gravity Forms';
			$gf_forms   = \GFAPI::get_forms();

			foreach ( $gf_forms as $gf ) {
				$notification = reset( $gf['notifications'] ?? array() );
				$forms[] = array(
					'id'            => $gf['id'],
					'title'         => $gf['title'],
					'plugin'        => 'Gravity Forms',
					'shortcode'     => '[gravityforms id="' . $gf['id'] . '"]',
					'email_to'      => $notification['to']      ?? '',
					'email_subject' => $notification['subject'] ?? '',
					'field_count'   => count( $gf['fields'] ?? array() ),
					'active'        => (bool) ( $gf['is_active'] ?? true ),
				);
			}
		}

		// ─── Fluent Forms ────────────────────────────────────────────────
		if ( defined( 'FLUENTFORM' ) || class_exists( '\FluentForm\App\Models\Form' ) ) {
			$detected[] = 'Fluent Forms';
			global $wpdb;
			$ff_table = $wpdb->prefix . 'fluentform_forms';
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $ff_table ) ) ) {
				$ff_forms = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, title, status FROM {$ff_table} LIMIT %d", 50
				) );
				foreach ( $ff_forms as $ff ) {
					$forms[] = array(
						'id'        => $ff->id,
						'title'     => $ff->title,
						'plugin'    => 'Fluent Forms',
						'shortcode' => '[fluentform id="' . $ff->id . '"]',
						'active'    => $ff->status === 'published',
					);
				}
			}
		}

		if ( empty( $detected ) ) {
			return array(
				'forms_detected' => false,
				'message'        => __( 'No form plugin detected. Common plugins: Contact Form 7, WPForms, Gravity Forms.', 'pressark' ),
			);
		}

		return array(
			'plugins_detected' => $detected,
			'form_count'       => count( $forms ),
			'forms'            => $forms,
			'smtp_configured'  => $this->check_smtp_configured(),
			'hint'             => __( 'If forms are not sending emails, check smtp_configured. Most issues = no SMTP plugin.', 'pressark' ),
		);
	}

	/**
	 * Quick SMTP check — used by list_forms to give immediate email diagnosis.
	 */
	private function check_smtp_configured(): bool {
		$smtp_plugins = array(
			'wp-mail-smtp/wp_mail_smtp.php',
			'post-smtp/postman-smtp.php',
			'easy-wp-smtp/easy-wp-smtp.php',
			'fluent-smtp/fluent-smtp.php',
		);
		$active = get_option( 'active_plugins', array() );
		foreach ( $smtp_plugins as $plugin ) {
			if ( in_array( $plugin, $active, true ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Preview Methods ─────────────────────────────────────────────────

	/**
	 * Preview for update_media.
	 */
	public function preview_update_media( array $params, array $action ): array {
		$att_id  = absint( $params['attachment_id'] ?? 0 );
		$att     = get_post( $att_id );
		$changes = $params['changes'] ?? ( $action['changes'] ?? array() );

		$preview = array(
			'post_title' => $att ? $att->post_title : __( 'Unknown', 'pressark' ),
			'post_id'    => $att_id,
			'changes'    => array(),
		);

		foreach ( $changes as $key => $value ) {
			if ( 'set_featured_for' === $key ) {
				$target = get_post( absint( $value ) );
				$preview['changes'][] = array(
					'field'  => __( 'Set as featured image', 'pressark' ),
					'before' => '—',
					/* translators: 1: post title, 2: post ID */
					'after'  => $target ? sprintf( __( '"%1$s" (#%2$d)', 'pressark' ), $target->post_title, $value ) : '#' . $value,
				);
			} else {
				$current = '';
				if ( 'alt' === $key ) {
					$current = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
				} elseif ( $att ) {
					$field_map = array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' );
					$prop      = $field_map[ $key ] ?? '';
					$current   = $prop ? $att->$prop : '';
				}
				$preview['changes'][] = array(
					'field'  => ucfirst( str_replace( '_', ' ', $key ) ),
					'before' => $current ?: __( '(empty)', 'pressark' ),
					'after'  => $value,
				);
			}
		}

		return $preview;
	}

	/**
	 * Preview for delete_media.
	 */
	public function preview_delete_media( array $params, array $action ): array {
		$att_id = absint( $params['attachment_id'] ?? 0 );
		$att    = get_post( $att_id );
		return array(
			'changes' => array(
				array(
					'field'  => __( 'Delete Attachment', 'pressark' ),
					/* translators: 1: attachment title, 2: MIME type */
					'before' => $att ? sprintf( __( '"%1$s" (%2$s)', 'pressark' ), $att->post_title, $att->post_mime_type ) : '#' . $att_id,
					'after'  => __( 'Permanently deleted', 'pressark' ),
				),
			),
		);
	}

	/**
	 * Preview for bulk_delete_media.
	 */
	public function preview_bulk_delete_media( array $params, array $action ): array {
		$bulk_att_ids = $params['attachment_ids'] ?? ( $action['attachment_ids'] ?? array() );

		$att_titles = array();
		foreach ( array_slice( $bulk_att_ids, 0, 10 ) as $aid ) {
			$att = get_post( intval( $aid ) );
			if ( $att ) {
				$att_titles[] = "\"{$att->post_title}\"";
			}
		}
		$att_title_str = implode( ', ', $att_titles );
		if ( count( $bulk_att_ids ) > 10 ) {
			/* translators: %d: number of additional items */
			$att_title_str .= ' ' . sprintf( __( '+%d more', 'pressark' ), count( $bulk_att_ids ) - 10 );
		}

		return array(
			'post_title' => sprintf(
				/* translators: %d: number of media files in the bulk delete */
				__( 'Bulk Delete Media — %d files', 'pressark' ),
				count( $bulk_att_ids )
			),
			'post_id'    => 0,
			'changes'    => array(
				array(
					'field'  => __( 'Files to Delete', 'pressark' ),
					'before' => $att_title_str,
					'after'  => sprintf(
						/* translators: %d: number of files that will be permanently deleted */
						__( '%d files will be permanently deleted', 'pressark' ),
						count( $bulk_att_ids )
					),
				),
			),
		);
	}

	/**
	 * Preview for moderate_comments.
	 */
	public function preview_moderate_comments( array $params, array $action ): array {
		$cids       = $params['comment_ids'] ?? array();
		if ( is_string( $cids ) ) {
			$cids = array_filter( array_map( 'absint', explode( ',', $cids ) ) );
		}
		$cids       = (array) $cids;
		$mod_action = $params['action'] ?? '';
		return array(
			'changes' => array(
				array(
					/* translators: %s: moderation action (e.g. Approve, Spam) */
					'field'  => sprintf( __( '%s Comments', 'pressark' ), ucfirst( $mod_action ) ),
					'before' => sprintf(
						/* translators: %d: number of comments */
						__( '%d comment(s)', 'pressark' ),
						count( $cids )
					),
					'after'  => ucfirst( $mod_action ),
				),
			),
		);
	}

	/**
	 * Preview for reply_comment.
	 */
	public function preview_reply_comment( array $params, array $action ): array {
		$parent = get_comment( absint( $params['comment_id'] ?? 0 ) );
		return array(
			'changes' => array(
				array(
					'field'  => __( 'Reply to Comment', 'pressark' ),
					'before' => $parent
						? sprintf(
							/* translators: 1: comment author, 2: comment excerpt */
							__( 'By %1$s: "%2$s"', 'pressark' ),
							$parent->comment_author,
							mb_substr( wp_strip_all_tags( $parent->comment_content ), 0, 80 )
						)
						: sprintf(
							/* translators: %d: comment ID */
							__( 'Comment #%d', 'pressark' ),
							$params['comment_id'] ?? 0
						),
					'after'  => mb_substr( $params['content'] ?? '', 0, 150 ),
				),
			),
		);
	}

	/**
	 * Preview for manage_taxonomy.
	 */
	public function preview_manage_taxonomy( array $params, array $action ): array {
		$tax_op    = $params['operation'] ?? '';
		$tax_name  = $params['taxonomy'] ?? '';
		$term_name = $params['name'] ?? '';

		if ( 'delete' === $tax_op && ! empty( $params['term_id'] ) ) {
			$term_obj  = get_term( absint( $params['term_id'] ), $tax_name );
			$term_name = ( $term_obj && ! is_wp_error( $term_obj ) ) ? $term_obj->name : '#' . $params['term_id'];
		}

		return array(
			'changes' => array(
				array(
					/* translators: %s: operation (e.g. Create, Edit, Delete) */
					'field'  => sprintf( __( '%s Term', 'pressark' ), ucfirst( $tax_op ) ),
					'before' => 'delete' === $tax_op ? $term_name : '—',
					/* translators: 1: term name, 2: taxonomy name */
					'after'  => 'delete' === $tax_op ? __( 'Deleted', 'pressark' ) : sprintf( __( '%1$s in %2$s', 'pressark' ), $term_name ?: __( 'Unknown', 'pressark' ), $tax_name ),
				),
			),
		);
	}

	/**
	 * Preview for assign_terms.
	 */
	public function preview_assign_terms( array $params, array $action ): array {
		$post_id     = absint( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$assign_post = get_post( $post_id );
		$terms       = $params['terms'] ?? array();
		return array(
			'changes' => array(
				array(
					'field'  => __( 'Assign Terms', 'pressark' ),
					'before' => $assign_post ? $assign_post->post_title : '#' . $post_id,
					'after'  => implode( ', ', array_map( 'strval', $terms ) ) . ' → ' . ( $params['taxonomy'] ?? '' ),
				),
			),
		);
	}
}
