<?php
/**
 * Content Provenance experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Content_Provenance\Signing\BYOK_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Connected_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C2PA Content Provenance experiment.
 *
 * Embeds cryptographic proof of origin into published content using the
 * C2PA 2.3 text authentication specification (Section A.7). Proof survives
 * copy-paste, scraping, and syndication. Zero editorial workflow change.
 *
 * @since x.x.x
 */
class Content_Provenance extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'content-provenance';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{label: string, description: string, category: string}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Provenance', 'ai' ),
			'description' => __( 'Embeds cryptographic proof of origin into published content using the C2PA 2.3 text authentication specification. Proof survives copy-paste, scraping, and syndication.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * Registers all WordPress hooks for this experiment.
	 *
	 * Sets up signing hooks, REST routes, well-known endpoint, and editor assets.
	 * Also hooks into the experiment-enabled toggle so the local keypair is
	 * generated on first activation.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// Sign on first publication.
		add_action( 'publish_post', array( $this, 'sign_on_publish' ), 20, 2 );

		// Re-sign when content is updated.
		add_action( 'post_updated', array( $this, 'sign_on_update' ), 20, 3 );

		// Register c2pa/sign and c2pa/verify abilities.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Block editor sidebar panel.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );

		// REST endpoints for verification and status.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Rewrite rule for /.well-known/c2pa.
		add_action( 'init', array( $this, 'add_well_known_rewrite' ) );

		// Serve the well-known discovery document.
		add_action( 'template_redirect', array( $this, 'handle_well_known_request' ) );

		// Keypair generation on toggle.
		add_action(
			'update_option_wpai_feature_content-provenance_enabled',
			array( $this, 'on_toggle' ),
			10,
			2
		);
	}

	/**
	 * Registers experiment-specific settings with the WordPress Settings API.
	 *
	 * All options are namespaced via get_field_option_name() and grouped under
	 * the 'ai_experiments' settings group used by the experiments settings page.
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'signing_tier' ),
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'local',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'connected_service_url' ),
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => Connected_Signer::DEFAULT_SERVICE_URL,
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'connected_service_api_key' ),
			array(
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'byok_key_path' ),
			array(
				'sanitize_callback' => array( $this, 'sanitize_file_path' ),
				'default'           => '',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'byok_certificate' ),
			array(
				'sanitize_callback' => array( $this, 'sanitize_file_path' ),
				'default'           => '',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'auto_sign' ),
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'show_badge' ),
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'badge_position' ),
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'below',
			)
		);
	}

	/**
	 * Renders the experiment settings fields inside the experiment card.
	 *
	 * Outputs signing-tier selection, conditional service configuration inputs,
	 * badge display controls, and a short explanation of trust tiers per PRD §4.1.
	 *
	 * @since x.x.x
	 */
	public function render_settings_fields(): void {
		$signing_tier_raw          = $this->get_signing_option( 'signing_tier' );
		$signing_tier              = $signing_tier_raw ? (string) $signing_tier_raw : 'local';
		$connected_service_url_raw = $this->get_signing_option( 'connected_service_url' );
		$connected_service_url     = $connected_service_url_raw ? (string) $connected_service_url_raw : Connected_Signer::DEFAULT_SERVICE_URL;
		$connected_service_api_key = self::decrypt_value( (string) $this->get_signing_option( 'connected_service_api_key' ) );
		$byok_key_path             = (string) $this->get_signing_option( 'byok_key_path' );
		$byok_certificate          = (string) $this->get_signing_option( 'byok_certificate' );
		$auto_sign                 = (bool) $this->get_signing_option( 'auto_sign' );
		$show_badge                = (bool) $this->get_signing_option( 'show_badge' );
		$badge_position_raw        = (string) $this->get_signing_option( 'badge_position' );
		$badge_position            = $badge_position_raw ? $badge_position_raw : 'below';

		$tier_name_signing       = $this->get_field_option_name( 'signing_tier' );
		$tier_name_service_url   = $this->get_field_option_name( 'connected_service_url' );
		$tier_name_api_key       = $this->get_field_option_name( 'connected_service_api_key' );
		$tier_name_byok_key_path = $this->get_field_option_name( 'byok_key_path' );
		$tier_name_byok_cert     = $this->get_field_option_name( 'byok_certificate' );
		$tier_name_auto_sign     = $this->get_field_option_name( 'auto_sign' );
		$tier_name_show_badge    = $this->get_field_option_name( 'show_badge' );
		$tier_name_badge_pos     = $this->get_field_option_name( 'badge_position' );
		?>
		<fieldset class="ai-experiment-content-provenance-settings">
			<legend class="screen-reader-text">
				<?php esc_html_e( 'Content Provenance Settings', 'ai' ); ?>
			</legend>

			<details>
				<summary><?php esc_html_e( 'Signing Tier', 'ai' ); ?></summary>
				<p class="description">
					<?php
					esc_html_e(
						'Choose how content is signed. Local signing requires no external services and uses a key stored in the database. Connected signing delegates to an external key-management service for a fuller trust chain. Bring-Your-Own-Key (BYOK) lets you supply your own CA-backed private key.',
						'ai'
					);
					?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_signing ); ?>">
								<?php esc_html_e( 'Signing Tier', 'ai' ); ?>
							</label>
						</th>
						<td>
							<select
								id="<?php echo esc_attr( $tier_name_signing ); ?>"
								name="<?php echo esc_attr( $tier_name_signing ); ?>"
							>
								<option value="local" <?php selected( $signing_tier, 'local' ); ?>>
									<?php esc_html_e( 'Local — self-signed, zero config', 'ai' ); ?>
								</option>
								<option value="connected" <?php selected( $signing_tier, 'connected' ); ?>>
									<?php esc_html_e( 'Connected — CA-verified via Encypher', 'ai' ); ?>
								</option>
								<option value="byok" <?php selected( $signing_tier, 'byok' ); ?>>
									<?php esc_html_e( 'BYOK — your own CA-issued certificate', 'ai' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</table>
			</details>

			<details <?php echo 'connected' === $signing_tier ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Connected Service Configuration', 'ai' ); ?></summary>
				<p class="description">
					<?php esc_html_e( 'Connected signing uses a CA-verified certificate from the Encypher signing service. Manifests are trusted by standard C2PA verifiers like Content Credentials.', 'ai' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_service_url ); ?>">
								<?php esc_html_e( 'Service URL', 'ai' ); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								id="<?php echo esc_attr( $tier_name_service_url ); ?>"
								name="<?php echo esc_attr( $tier_name_service_url ); ?>"
								value="<?php echo esc_attr( $connected_service_url ); ?>"
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Pre-configured for Encypher. Change only if using a custom signing service.', 'ai' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_api_key ); ?>">
								<?php esc_html_e( 'API Key', 'ai' ); ?>
							</label>
						</th>
						<td>
							<?php
							$masked_key = '';
							if ( '' !== $connected_service_api_key ) {
								$masked_key = str_repeat( '*', max( 0, strlen( $connected_service_api_key ) - 4 ) ) . substr( $connected_service_api_key, -4 );
							}
							?>
							<input
								type="password"
								id="<?php echo esc_attr( $tier_name_api_key ); ?>"
								name="<?php echo esc_attr( $tier_name_api_key ); ?>"
								value="<?php echo esc_attr( $masked_key ); ?>"
								class="regular-text"
								autocomplete="new-password"
								placeholder="<?php esc_attr_e( 'Enter API key', 'ai' ); ?>"
							/>
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to the Encypher signup page. */
									esc_html__( 'Get your free API key at %s', 'ai' ),
									'<a href="https://encypher.com/signup" target="_blank" rel="noopener noreferrer">encypher.com/signup</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</details>

			<details <?php echo 'byok' === $signing_tier ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'BYOK Certificate Configuration', 'ai' ); ?></summary>
				<p class="description">
					<?php esc_html_e( 'Supply your own CA-issued EC P-256 private key and X.509 certificate for the highest trust level. The certificate should be issued by a C2PA trust list CA (SSL.com, DigiCert, etc.).', 'ai' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_byok_key_path ); ?>">
								<?php esc_html_e( 'Private Key Path', 'ai' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="<?php echo esc_attr( $tier_name_byok_key_path ); ?>"
								name="<?php echo esc_attr( $tier_name_byok_key_path ); ?>"
								value="<?php echo esc_attr( $byok_key_path ); ?>"
								class="large-text"
								placeholder="/etc/ssl/private/c2pa-signing-key.pem"
							/>
							<p class="description">
								<?php esc_html_e( 'Filesystem path to PEM-encoded EC P-256 private key. Must be readable by the web server.', 'ai' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_byok_cert ); ?>">
								<?php esc_html_e( 'Certificate Path', 'ai' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="<?php echo esc_attr( $tier_name_byok_cert ); ?>"
								name="<?php echo esc_attr( $tier_name_byok_cert ); ?>"
								value="<?php echo esc_attr( $byok_certificate ); ?>"
								class="large-text"
								placeholder="/etc/ssl/certs/c2pa-signing-cert.pem"
							/>
							<p class="description">
								<?php esc_html_e( 'Filesystem path to PEM-encoded X.509 certificate (or chain).', 'ai' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</details>

			<details>
				<summary><?php esc_html_e( 'Publishing Options', 'ai' ); ?></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Auto-sign', 'ai' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( $tier_name_auto_sign ); ?>"
									value="1"
									<?php checked( $auto_sign ); ?>
								/>
								<?php esc_html_e( 'Automatically sign content on publish and update', 'ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Provenance Badge', 'ai' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( $tier_name_show_badge ); ?>"
									value="1"
									<?php checked( $show_badge ); ?>
								/>
								<?php esc_html_e( 'Show provenance badge on signed content', 'ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_badge_pos ); ?>">
								<?php esc_html_e( 'Badge Position', 'ai' ); ?>
							</label>
						</th>
						<td>
							<select
								id="<?php echo esc_attr( $tier_name_badge_pos ); ?>"
								name="<?php echo esc_attr( $tier_name_badge_pos ); ?>"
							>
								<option value="below" <?php selected( $badge_position, 'below' ); ?>>
									<?php esc_html_e( 'Below content', 'ai' ); ?>
								</option>
								<option value="above" <?php selected( $badge_position, 'above' ); ?>>
									<?php esc_html_e( 'Above content', 'ai' ); ?>
								</option>
								<option value="inline" <?php selected( $badge_position, 'inline' ); ?>>
									<?php esc_html_e( 'Inline (end of content)', 'ai' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</table>
			</details>
		</fieldset>
		<?php
	}

	/**
	 * Signs a post on first publication if auto-sign is enabled.
	 *
	 * Hooked to 'publish_post' at priority 20 so it runs after standard WP
	 * publish routines. Skips revisions and auto-drafts.
	 *
	 * @since x.x.x
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 */
	public function sign_on_publish( int $post_id, \WP_Post $post ): void {
		if ( ! $this->get_signing_option( 'auto_sign' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->sign_post( $post_id, $post, 'c2pa.created' );
	}

	/**
	 * Re-signs a post when its content changes after initial publication.
	 *
	 * Hooked to 'post_updated' at priority 20. Skips non-published posts and
	 * updates that do not change the post content, to avoid churning signatures.
	 *
	 * @since x.x.x
	 *
	 * @param int      $post_id     The post ID.
	 * @param \WP_Post $post_after  The post object after the update.
	 * @param \WP_Post $post_before The post object before the update.
	 */
	public function sign_on_update( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( ! $this->get_signing_option( 'auto_sign' ) ) {
			return;
		}

		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		if ( $post_after->post_content === $post_before->post_content ) {
			return;
		}

		$this->sign_post( $post_id, $post_after, 'c2pa.edited', $post_before );
	}

	/**
	 * Builds, signs, embeds, and persists a C2PA manifest for a post.
	 *
	 * Strips HTML to obtain plain text, builds the C2PA claims structure,
	 * signs it via the configured signing tier, embeds the manifest using
	 * Unicode variation selectors, and stores the result back to the post.
	 * Failures are logged and stored in post meta — publication is never blocked.
	 *
	 * @since x.x.x
	 *
	 * @param int           $post_id  Post ID.
	 * @param \WP_Post      $post     Post object to sign.
	 * @param string        $action   C2PA action string: 'c2pa.created' or 'c2pa.edited'.
	 * @param \WP_Post|null $previous Optional previous post object for ingredient chain.
	 * @return bool True on success, false on failure.
	 */
	public function sign_post( int $post_id, \WP_Post $post, string $action, ?\WP_Post $previous = null ): bool {
		$plain_text = wp_strip_all_tags( $post->post_content );

		if ( empty( $plain_text ) ) {
			return false;
		}

		$previous_manifest = null;
		if ( 'c2pa.edited' === $action ) {
			$raw_manifest      = get_post_meta( $post_id, '_c2pa_manifest', true );
			$previous_manifest = $raw_manifest ? (string) $raw_manifest : null;
		}

		$signer = $this->get_signer();

		$raw_permalink = get_permalink( $post_id );
		$metadata      = array(
			'title'   => $post->post_title,
			'url'     => $raw_permalink ? (string) $raw_permalink : '',
			'author'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'post_id' => $post_id,
		);

		$result = C2PA_Manifest_Builder::build(
			$plain_text,
			$action,
			$previous_manifest,
			$metadata,
			$signer
		);

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_c2pa_status', 'error' );
			return false;
		}

		$new_content = Unicode_Embedder::embed( $post->post_content, $result['manifest'] );

		// Temporarily remove own hooks to avoid recursive triggering.
		remove_action( 'publish_post', array( $this, 'sign_on_publish' ), 20 );
		remove_action( 'post_updated', array( $this, 'sign_on_update' ), 20 );

		$update_result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		// Restore hooks.
		add_action( 'publish_post', array( $this, 'sign_on_publish' ), 20, 2 );
		add_action( 'post_updated', array( $this, 'sign_on_update' ), 20, 3 );

		if ( is_wp_error( $update_result ) ) {
			update_post_meta( $post_id, '_c2pa_status', 'error' );
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary JUMBF data must be base64-encoded for safe storage in WordPress post meta.
		update_post_meta( $post_id, '_c2pa_manifest', base64_encode( $result['manifest'] ) );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_signed_at', gmdate( 'c' ) );
		update_post_meta( $post_id, '_c2pa_signer_tier', $signer->get_tier() );

		return true;
	}

	/**
	 * Registers the c2pa/sign and c2pa/verify abilities.
	 *
	 * Hooked to 'wp_abilities_api_init'.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'c2pa/sign',
			array(
				'label'         => __( 'C2PA: Sign Content', 'ai' ),
				'description'   => __( 'Embed C2PA provenance into text content.', 'ai' ),
				'ability_class' => \WordPress\AI\Abilities\Content_Provenance\C2PA_Sign::class,
			)
		);

		wp_register_ability(
			'c2pa/verify',
			array(
				'label'         => __( 'C2PA: Verify Provenance', 'ai' ),
				'description'   => __( 'Verify C2PA provenance in text content.', 'ai' ),
				'ability_class' => \WordPress\AI\Abilities\Content_Provenance\C2PA_Verify::class,
			)
		);
	}

	/**
	 * Registers REST API endpoints for verification and signing status.
	 *
	 * Hooked to 'rest_api_init'. The /verify route is publicly accessible so
	 * third-party tools can verify provenance without authentication. The
	 * /status route requires edit_post capability for the requested post.
	 *
	 * @since x.x.x
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'c2pa-provenance/v1',
			'/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_verify_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'text' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			)
		);

		register_rest_route(
			'c2pa-provenance/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status_callback' ),
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
				},
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST callback: verify C2PA provenance in submitted text.
	 *
	 * Extracts and validates the embedded manifest, returning a structured
	 * response that includes verification status, the parsed manifest, and
	 * any error detail.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function rest_verify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$text   = (string) $request->get_param( 'text' );
		$result = C2PA_Manifest_Builder::extract_and_verify( $text );

		$manifest    = $result['manifest'];
		$signed_at   = null;
		$signer_tier = null;

		if ( is_array( $manifest ) ) {
			$signed_at   = $manifest['signed_at'] ?? null;
			$signer_tier = $manifest['signer'] ?? null;
		}

		return new \WP_REST_Response(
			array(
				'verified'    => $result['verified'],
				'status'      => $result['status'],
				'manifest'    => $manifest,
				'signed_at'   => $signed_at,
				'signer_tier' => $signer_tier,
			),
			200
		);
	}

	/**
	 * REST callback: return the signing status for a specific post.
	 *
	 * Reads post meta written by sign_post() and returns a summarised status
	 * payload for use in the block editor sidebar panel.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function rest_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );

		$raw_status = get_post_meta( $post_id, '_c2pa_status', true );
		$status     = $raw_status ? (string) $raw_status : 'unsigned';
		$raw_signed = get_post_meta( $post_id, '_c2pa_signed_at', true );
		$signed_at  = $raw_signed ? (string) $raw_signed : null;
		$raw_tier   = get_post_meta( $post_id, '_c2pa_signer_tier', true );
		$tier       = $raw_tier ? (string) $raw_tier : null;
		$raw_mfst   = get_post_meta( $post_id, '_c2pa_manifest', true );
		$manifest   = $raw_mfst ? (string) $raw_mfst : null;

		// Provide a summary rather than the full binary manifest.
		$manifest_preview = null;
		if ( $manifest ) {
			$manifest_preview = array(
				'format' => 'jumbf',
				'size'   => strlen( $manifest ),
			);
		}

		return new \WP_REST_Response(
			array(
				'status'           => $status,
				'signed_at'        => $signed_at,
				'signer_tier'      => $tier,
				'manifest_preview' => $manifest_preview,
			),
			200
		);
	}

	/**
	 * Enqueues block editor assets for the provenance sidebar panel.
	 *
	 * Only loads on the post edit and new-post screens. Passes runtime
	 * configuration to the JS bundle via wp_localize_script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$hook_suffix = $screen->base;

		if ( 'post' !== $hook_suffix && 'post-new' !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'content_provenance', 'experiments/content-provenance' );
		Asset_Loader::localize_script(
			'content_provenance',
			'ContentProvenanceData',
			array(
				'enabled'     => $this->is_enabled(),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'restUrl'     => rest_url( 'c2pa-provenance/v1' ),
				'signerTier'  => ( $this->get_signing_option( 'signing_tier' ) ? (string) $this->get_signing_option( 'signing_tier' ) : 'local' ),
				'settingsUrl' => admin_url( 'admin.php?page=ai' ),
			)
		);
	}

	/**
	 * Registers the /.well-known/c2pa rewrite rule.
	 *
	 * Delegates to Well_Known_Handler for rewrite registration.
	 *
	 * @since x.x.x Delegates to Well_Known_Handler.
	 */
	public function add_well_known_rewrite(): void {
		Well_Known_Handler::add_rewrite_rule();
	}

	/**
	 * Serves the /.well-known/c2pa discovery document when requested.
	 *
	 * Delegates to Well_Known_Handler for spec-compliant C2PA discovery.
	 *
	 * @since x.x.x Delegates to Well_Known_Handler with spec-compliant field names.
	 */
	public function handle_well_known_request(): void {
		Well_Known_Handler::maybe_handle();
	}

	/**
	 * Handles the experiment enable/disable toggle.
	 *
	 * Generates the local keypair on first activation so it is available
	 * immediately when the first post is published.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $new_value The new option value.
	 */
	public function on_toggle( $old_value, $new_value ): void {
		if ( '1' !== $new_value ) {
			return;
		}
		$this->ensure_local_keypair();
	}

	/**
	 * Generates and persists the local keypair if one does not already exist.
	 *
	 * Stores the keypair as a site option so it persists across requests.
	 * Uses EC P-256 with a self-signed X.509 certificate for C2PA compliance.
	 * Called once on experiment activation.
	 *
	 * @since x.x.x Switched from RSA-2048 to EC P-256.
	 */
	public function ensure_local_keypair(): void {
		$existing = get_option( '_c2pa_local_keypair' );

		if ( is_array( $existing ) && ! empty( $existing['private_key'] ) && ! empty( $existing['certificate_pem'] ) ) {
			return;
		}

		$keypair = $this->generate_keypair();

		if ( is_wp_error( $keypair ) ) {
			return;
		}

		update_option( '_c2pa_local_keypair', $keypair, false );
	}

	/**
	 * Returns the configured signer for external callers (e.g. the c2pa/sign Ability).
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface
	 */
	public function get_public_signer(): Signing_Interface {
		return $this->get_signer();
	}

	/**
	 * Returns the Signing_Interface implementation for the configured tier.
	 *
	 * Reads the signing_tier option and instantiates the appropriate backend.
	 * Defaults to Local_Signer when no tier is set.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface
	 */
	private function get_signer(): Signing_Interface {
		$raw_tier = $this->get_signing_option( 'signing_tier' );
		$tier     = $raw_tier ? (string) $raw_tier : 'local';

		if ( 'connected' === $tier ) {
			$encrypted_key = (string) $this->get_signing_option( 'connected_service_api_key' );
			return new Connected_Signer(
				(string) $this->get_signing_option( 'connected_service_url' ),
				self::decrypt_value( $encrypted_key )
			);
		}

		if ( 'byok' === $tier ) {
			return new BYOK_Signer(
				(string) $this->get_signing_option( 'byok_key_path' ),
				(string) $this->get_signing_option( 'byok_certificate' )
			);
		}

		return new Local_Signer( $this->get_local_keypair() );
	}

	/**
	 * Sanitizes the API key setting, preserving the existing value when the
	 * submitted value is the masked placeholder.
	 *
	 * @since x.x.x
	 *
	 * @param string $value Submitted API key value.
	 * @return string Sanitized API key.
	 */
	public function sanitize_api_key( string $value ): string {
		$value = sanitize_text_field( $value );

		// If the submitted value is all asterisks followed by up to 4 chars,
		// the user did not change the key — keep the stored value.
		if ( preg_match( '/^\*+.{0,4}$/', $value ) ) {
			$stored = get_option( $this->get_field_option_name( 'connected_service_api_key' ), '' );
			return is_string( $stored ) ? $stored : '';
		}

		if ( '' === $value ) {
			return '';
		}

		return self::encrypt_value( $value );
	}

	/**
	 * Sanitizes a BYOK file path, rejecting traversal attempts.
	 *
	 * @since x.x.x
	 *
	 * @param string $value Submitted file path.
	 * @return string Sanitized path, or empty string on failure.
	 */
	public function sanitize_file_path( string $value ): string {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return '';
		}

		// Reject paths with traversal sequences before resolving.
		if ( false !== strpos( $value, '..' ) ) {
			add_settings_error(
				$this->get_field_option_name( 'byok_key_path' ),
				'c2pa_path_traversal',
				esc_html__( 'File path must not contain directory traversal sequences.', 'ai' )
			);
			return '';
		}

		return $value;
	}

	/**
	 * Returns the value of an experiment setting option.
	 *
	 * Wraps get_option() with the namespaced option name produced by
	 * get_field_option_name() to reduce boilerplate at call sites.
	 *
	 * @since x.x.x
	 *
	 * @param string $name Base option name (e.g. 'signing_tier').
	 * @return mixed Option value, or false if not set.
	 */
	/**
	 * Encrypts a value for at-rest storage using AES-256-CBC with the site auth key.
	 *
	 * @since x.x.x
	 *
	 * @param string $value Plaintext value.
	 * @return string Base64-encoded ciphertext with IV prefix, or original value on failure.
	 */
	private static function encrypt_value( string $value ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( 16 );

		if ( false === $iv ) {
			return $value;
		}

		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encrypted binary data must be base64-encoded for safe storage.
		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypts a value encrypted by encrypt_value().
	 *
	 * Returns the original string if the value is not encrypted (no 'enc:' prefix).
	 *
	 * @since x.x.x
	 *
	 * @param string $value Stored value (encrypted or plaintext).
	 * @return string Decrypted plaintext.
	 */
	private static function decrypt_value( string $value ): string {
		if ( 0 !== strpos( $value, 'enc:' ) ) {
			return $value;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding encrypted data stored by encrypt_value().
		$raw = base64_decode( substr( $value, 4 ), true );

		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$key       = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv        = substr( $raw, 0, 16 );
		$encrypted = substr( $raw, 16 );

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Returns the value of an experiment setting option.
	 *
	 * Wraps get_option() with the namespaced option name produced by
	 * get_field_option_name() to reduce boilerplate at call sites.
	 *
	 * @since x.x.x
	 *
	 * @param string $name Base option name (e.g. 'signing_tier').
	 * @return mixed Option value, or false if not set.
	 */
	private function get_signing_option( string $name ) {
		return get_option( $this->get_field_option_name( $name ) );
	}

	/**
	 * Retrieves or generates the local EC P-256 keypair.
	 *
	 * Reads the persisted keypair from the '_c2pa_local_keypair' site option.
	 * If none exists or the stored keypair uses the legacy RSA format (missing
	 * certificate_pem), generates a new EC P-256 keypair and persists it.
	 *
	 * @since x.x.x Returns EC P-256 keypair with certificate instead of RSA.
	 *
	 * @return array{private_key: string, certificate_pem: string}
	 */
	private function get_local_keypair(): array {
		$stored = get_option( '_c2pa_local_keypair' );

		if ( is_array( $stored ) && ! empty( $stored['private_key'] ) && ! empty( $stored['certificate_pem'] ) ) {
			/** @var array{private_key: string, certificate_pem: string} $stored */
			return $stored;
		}

		$keypair = $this->generate_keypair();

		if ( is_wp_error( $keypair ) ) {
			// Return a placeholder — signing will fail gracefully downstream.
			return array(
				'private_key'     => '',
				'certificate_pem' => '',
			);
		}

		update_option( '_c2pa_local_keypair', $keypair, false );

		return $keypair;
	}

	/**
	 * Generates a fresh EC P-256 keypair with self-signed X.509 certificate.
	 *
	 * @since x.x.x Switched from RSA-2048 to EC P-256 with X.509 certificate for C2PA compliance.
	 *
	 * @return array{private_key: string, certificate_pem: string}|\WP_Error Keypair array or WP_Error on failure.
	 */
	private function generate_keypair() {
		return Local_Signer::generate_keypair();
	}
}
