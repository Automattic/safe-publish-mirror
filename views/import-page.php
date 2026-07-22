<?php

use Automattic\SafePublishMirror\Import_Admin;

defined( 'ABSPATH' ) || die();

$spm = Import_Admin::view_model();
?>
<div class="wrap">
	<h1>
		<?php
		printf(
			/* translators: %s: connected source site URL */
			esc_html__( 'Posts from %s', 'safe-publish-mirror' ),
			esc_html( $spm['source_url'] )
		);
		?>
	</h1>

	<?php if ( null !== $spm['notice'] ) : ?>
		<div class="notice notice-<?php echo 'success' === $spm['notice']['type'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo wp_kses_post( $spm['notice']['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $spm['error'] ) : ?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: error message from the source */
					esc_html__( 'Could not load posts from the source: %s', 'safe-publish-mirror' ),
					esc_html( $spm['error'] )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<form method="get" style="margin: 1em 0;">
		<input type="hidden" name="page" value="<?php echo esc_attr( Import_Admin::MENU_SLUG ); ?>" />
		<label for="spm-post-type"><?php esc_html_e( 'Type', 'safe-publish-mirror' ); ?></label>
		<select id="spm-post-type" name="post_type">
			<?php
			foreach ( [
				'post' => __( 'Posts', 'safe-publish-mirror' ),
				'page' => __( 'Pages', 'safe-publish-mirror' ),
			] as $spm_type => $spm_label ) :
				?>
				<option value="<?php echo esc_attr( $spm_type ); ?>" <?php selected( $spm['post_type'], $spm_type ); ?>>
										<?php echo esc_html( $spm_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filter', 'safe-publish-mirror' ), 'secondary', '', false ); ?>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Title', 'safe-publish-mirror' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Published date', 'safe-publish-mirror' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Local state', 'safe-publish-mirror' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Local status', 'safe-publish-mirror' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Source status', 'safe-publish-mirror' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'safe-publish-mirror' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( [] === $spm['rows'] ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No posts found on the source.', 'safe-publish-mirror' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $spm['rows'] as $spm_row ) : ?>
				<tr>
					<td>
						<?php if ( '' !== $spm_row['edit_link'] ) : ?>
							<a href="<?php echo esc_url( $spm_row['edit_link'] ); ?>"><strong><?php echo esc_html( '' === $spm_row['title'] ? __( '(no title)', 'safe-publish-mirror' ) : $spm_row['title'] ); ?></strong></a>
						<?php else : ?>
							<strong><?php echo esc_html( '' === $spm_row['title'] ? __( '(no title)', 'safe-publish-mirror' ) : $spm_row['title'] ); ?></strong>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $spm_row['date'] ); ?></td>
					<td><?php echo esc_html( $spm_row['local_state'] ); ?></td>
					<td><?php echo esc_html( '' === $spm_row['local_status'] ? '—' : $spm_row['local_status'] ); ?></td>
					<td><?php echo esc_html( '' === $spm_row['source_status'] ? '—' : $spm_row['source_status'] ); ?></td>
					<td>
						<?php if ( 0 !== $spm_row['local_post_id'] ) : ?>
							<span class="spm-already-imported"><?php esc_html_e( 'Already imported', 'safe-publish-mirror' ); ?></span>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<input type="hidden" name="action" value="<?php echo esc_attr( Import_Admin::IMPORT_ACTION ); ?>" />
								<input type="hidden" name="source_post_id" value="<?php echo esc_attr( (string) $spm_row['source_post_id'] ); ?>" />
								<input type="hidden" name="post_type" value="<?php echo esc_attr( $spm_row['post_type'] ); ?>" />
								<?php wp_nonce_field( Import_Admin::NONCE ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Import', 'safe-publish-mirror' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	$spm_base = add_query_arg(
		[
			'page'      => Import_Admin::MENU_SLUG,
			'post_type' => $spm['post_type'],
		],
		admin_url( 'admin.php' )
	);
	?>
	<p class="tablenav" style="margin-top: 1em;">
		<?php if ( $spm['page'] > 1 ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $spm['page'] - 1, $spm_base ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'safe-publish-mirror' ); ?></a>
		<?php endif; ?>
		<?php if ( $spm['has_more'] ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $spm['page'] + 1, $spm_base ) ); ?>"><?php esc_html_e( 'Next', 'safe-publish-mirror' ); ?> &rarr;</a>
		<?php endif; ?>
	</p>
</div>
