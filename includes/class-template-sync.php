<?php
/**
 * Template Sync class.
 *
 * Listens for REST API saves of wp_template and wp_template_part post types
 * (triggered by the WordPress Site Editor) and writes the updated block markup
 * back to the corresponding HTML file in the active theme directory.
 *
 * @package WpBlockTemplateSync
 */

namespace WpBlockTemplateSync;

/**
 * Handles syncing of Site Editor template and template-part saves to theme files.
 */
class TemplateSync {

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_after_insert_wp_template', array( $this, 'sync_template' ), 10, 3 );
		add_action( 'rest_after_insert_wp_template_part', array( $this, 'sync_template_part' ), 10, 3 );
	}

	/**
	 * Sync a wp_template post to a file inside the theme's templates/ directory.
	 *
	 * @param \WP_Post         $post     The saved template post object.
	 * @param \WP_REST_Request $request  The REST request that triggered the save.
	 * @param bool             $creating True when inserting, false when updating.
	 * @return void
	 */
	public function sync_template( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
		$this->sync_post( $post, 'templates' );
	}

	/**
	 * Sync a wp_template_part post to a file inside the theme's parts/ directory.
	 *
	 * @param \WP_Post         $post     The saved template part post object.
	 * @param \WP_REST_Request $request  The REST request that triggered the save.
	 * @param bool             $creating True when inserting, false when updating.
	 * @return void
	 */
	public function sync_template_part( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
		$this->sync_post( $post, 'parts' );
	}

	/**
	 * Write the post content to the appropriate theme file.
	 *
	 * Only writes when:
	 * - The current user has the `edit_theme_options` capability.
	 * - The template/part belongs to the currently active (child) theme.
	 *
	 * @param \WP_Post $post The saved post object.
	 * @param string   $type Sub-directory inside the theme: 'templates' or 'parts'.
	 * @return bool True on success, false otherwise.
	 */
	public function sync_post( \WP_Post $post, string $type ): bool {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}

		$theme_slug = $this->get_theme_slug( $post->ID );

		if ( empty( $theme_slug ) ) {
			return false;
		}

		// Only sync templates that belong to the currently active theme.
		if ( $theme_slug !== get_stylesheet() ) {
			return false;
		}

		$template_slug = $post->post_name;

		// Reject slugs that could escape the theme directory.
		if ( ! $this->is_valid_slug( $template_slug ) ) {
			return false;
		}

		$theme_dir = get_stylesheet_directory();
		$file_path = $theme_dir . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . $template_slug . '.html';

		// Ensure the destination directory exists.
		$dir = dirname( $file_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $this->write_file( $file_path, $post->post_content );
	}

	/**
	 * Retrieve the theme slug associated with a template post via the wp_theme taxonomy.
	 *
	 * @param int $post_id The post ID.
	 * @return string Theme slug, or an empty string if not found.
	 */
	protected function get_theme_slug( int $post_id ): string {
		$terms = get_the_terms( $post_id, 'wp_theme' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return (string) $terms[0]->name;
	}

	/**
	 * Validate that a template slug does not contain path-traversal sequences.
	 *
	 * @param string $slug The template slug to validate.
	 * @return bool True when the slug is safe, false otherwise.
	 */
	protected function is_valid_slug( string $slug ): bool {
		if ( '' === $slug ) {
			return false;
		}

		// Disallow any directory traversal attempts or absolute paths.
		if ( false !== strpos( $slug, '..' ) || false !== strpos( $slug, '/' ) || false !== strpos( $slug, '\\' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Write content to a file using the WordPress Filesystem API.
	 *
	 * @param string $file_path Absolute path to the destination file.
	 * @param string $content   Content to write.
	 * @return bool True on success, false on failure.
	 */
	protected function write_file( string $file_path, string $content ): bool {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return (bool) $wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE );
	}
}
