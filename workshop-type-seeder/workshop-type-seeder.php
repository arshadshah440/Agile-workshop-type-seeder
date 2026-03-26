<?php
/**
 * Plugin Name:  Workshop Type Seeder
 * Description:  Adds a dashboard menu page with a button that seeds 5 Workshop Type taxonomy terms — including all default and ACF custom fields — via the WordPress REST API. Term data (including image URLs) is loaded from data/terms.json; images are sideloaded into the media library via the /wp/v2/media REST endpoint before being attached to each term.
 * Version:      1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:       Arshad Shah
 * Author URI:   https://arshadwebstudio.com
 * License:      GPL-2.0+
 *
 * Taxonomy targeted : workshop-type  (registered by The Events Calendar or equivalent)
 * ACF field group   : group_695645e713735  (attached to workshop-type terms)
 * Data file         : data/terms.json
 *
 * REST flow (per term)
 * --------------------
 * Browser JS  →  POST /wp-json/wts/v1/seed-workshop-types
 *                  └─ For each term in terms.json:
 *                       1. download_url( image_url ) → temp file
 *                          POST /wp/v2/media  (via rest_do_request)  → attachment ID
 *                       2. POST /wp/v2/workshop-type (via rest_do_request) → term ID
 *                       3. update_field( acf_key, value, 'workshop-type_{term_id}' )
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', static function () {
    WTS_Workshop_Type_Seeder::instance();
} );

// ---------------------------------------------------------------------------
// Main class
// ---------------------------------------------------------------------------

final class WTS_Workshop_Type_Seeder {

    /** Absolute path to the JSON data file. */
    private const DATA_FILE = __DIR__ . '/data/terms.json';

    /**
     * ACF field key → JSON property name mapping.
     * Image fields are handled separately (uploaded first, then the attachment
     * ID is passed to update_field).
     */
    private const ACF_TEXT_FIELDS = [
        'field_695798931d1f9' => 'workshop_description',  // wysiwyg
        'field_69596558ec5b6' => 'workshop_tagline',      // text
        'field_695966d7807ed' => 'abbreviation',          // text
    ];

    // Image ACF field keys
    private const FIELD_FEATURED_IMAGE = 'field_695645eac1f1c'; // return_format: id
    private const FIELD_WORKSHOP_BADGE = 'field_69564622c1f1d'; // return_format: array
    private const FIELD_CERT_BODY      = 'field_6959662681c4c'; // relationship

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_admin_menu'  ] );
        add_action( 'rest_api_init',         [ $this, 'register_rest_routes' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // -----------------------------------------------------------------------
    // Admin menu
    // -----------------------------------------------------------------------

    public function register_admin_menu(): void {
        add_menu_page(
            __( 'Workshop Type Seeder', 'wts' ),
            __( 'WS Type Seeder',       'wts' ),
            'manage_options',
            'workshop-type-seeder',
            [ $this, 'render_admin_page' ],
            'dashicons-tickets-alt',
            25
        );
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wts' ) );
        }

        $taxonomy_exists = taxonomy_exists( 'workshop-type' );
        $acf_active      = function_exists( 'update_field' );
        $data_file_ok    = file_exists( self::DATA_FILE );
        $term_count      = count( $this->load_terms_json() );
        ?>
        <div class="wrap" id="wts-admin-page">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Workshop Type Seeder', 'wts' ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( ! $taxonomy_exists ) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e( 'Taxonomy missing:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'The "workshop-type" taxonomy is not registered. Please activate the plugin that provides it (e.g. The Events Calendar) before seeding.', 'wts' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! $data_file_ok ) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e( 'Data file missing:', 'wts' ); ?></strong>
                        <?php echo esc_html( sprintf( __( 'Could not find %s.', 'wts' ), self::DATA_FILE ) ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! $acf_active ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e( 'ACF not active:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'Advanced Custom Fields is not active. Terms and images will still be created, but ACF fields (description, tagline, certification body, abbreviation) will NOT be populated.', 'wts' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border-left:4px solid #72aee6;padding:12px 18px;margin:20px 0;max-width:760px;">
                <p>
                    <?php
                    echo esc_html( sprintf(
                        __( 'Reads %d terms from %s. For each term:', 'wts' ),
                        $term_count,
                        'data/terms.json'
                    ) );
                    ?>
                </p>
                <ol style="margin:6px 0 6px 18px;padding:0;">
                    <li><?php esc_html_e( 'Downloads featured_image and workshop_badge from their URLs and uploads them to the Media Library via POST /wp/v2/media.', 'wts' ); ?></li>
                    <li><?php esc_html_e( 'Creates the taxonomy term via POST /wp/v2/workshop-type.', 'wts' ); ?></li>
                    <li><?php esc_html_e( 'Populates all ACF fields (using the uploaded attachment IDs for image fields).', 'wts' ); ?></li>
                </ol>
                <p style="margin:0;font-size:12px;color:#666;">
                    <?php esc_html_e( 'Data file: ', 'wts' ); ?>
                    <code><?php echo esc_html( self::DATA_FILE ); ?></code>
                </p>
            </div>

            <button
                id="wts-seed-button"
                class="button button-primary button-hero"
                <?php disabled( ! $taxonomy_exists || ! $data_file_ok ); ?>
            >
                <?php echo esc_html( sprintf( __( 'Seed %d Workshop Type Terms', 'wts' ), $term_count ) ); ?>
            </button>

            <div id="wts-status" style="margin-top:24px;max-width:800px;"></div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Admin assets
    // -----------------------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        if ( 'toplevel_page_workshop-type-seeder' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'wts-admin',
            plugin_dir_url( __FILE__ ) . 'assets/js/admin.js',
            [],
            '1.1.0',
            true
        );

        wp_localize_script( 'wts-admin', 'wtsData', [
            'restBase' => esc_url_raw( rest_url( 'wts/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'i18n'     => [
                'confirm' => __( 'This will upload images to your Media Library and create Workshop Type terms. Existing terms with the same slug will cause an error. Continue?', 'wts' ),
                'seeding' => __( 'Uploading images and seeding terms via REST API — this may take a moment…', 'wts' ),
                'done'    => __( 'Seeding complete!', 'wts' ),
                'errPfx'  => __( 'Error:', 'wts' ),
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // REST routes
    // -----------------------------------------------------------------------

    public function register_rest_routes(): void {
        register_rest_route( 'wts/v1', '/seed-workshop-types', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_seed' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( 'wts/v1', '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_status' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );
    }

    // -----------------------------------------------------------------------
    // REST callback: seed
    // -----------------------------------------------------------------------

    /**
     * POST /wp-json/wts/v1/seed-workshop-types
     *
     * For each entry in data/terms.json:
     *   1. Uploads featured_image URL  → POST /wp/v2/media  → attachment ID
     *   2. Uploads workshop_badge URL  → POST /wp/v2/media  → attachment ID
     *   3. Creates taxonomy term       → POST /wp/v2/workshop-type → term ID
     *   4. Sets all ACF fields via update_field()
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_seed( WP_REST_Request $request ) {

        if ( ! taxonomy_exists( 'workshop-type' ) ) {
            return new WP_Error(
                'wts_taxonomy_missing',
                __( 'The "workshop-type" taxonomy is not registered.', 'wts' ),
                [ 'status' => 400 ]
            );
        }

        $terms_data = $this->load_terms_json();

        if ( empty( $terms_data ) ) {
            return new WP_Error(
                'wts_no_data',
                __( 'No term data found in data/terms.json.', 'wts' ),
                [ 'status' => 500 ]
            );
        }

        $acf_active    = function_exists( 'update_field' );
        $cert_body_ids = $this->resolve_certification_body_ids();
        $created       = [];
        $errors        = [];

        foreach ( $terms_data as $term_data ) {
            $name = $term_data['name'] ?? 'Unknown';

            // ------------------------------------------------------------------
            // Step 1 — Upload images via POST /wp/v2/media
            // ------------------------------------------------------------------
            $featured_image_id = 0;
            $workshop_badge_id = 0;
            $image_log         = [];

            if ( ! empty( $term_data['images']['featured_image'] ) ) {
                $result = $this->upload_image_via_media_api( $term_data['images']['featured_image'] );
                if ( is_wp_error( $result ) ) {
                    $image_log['featured_image'] = [ 'uploaded' => false, 'error' => $result->get_error_message() ];
                } else {
                    $featured_image_id = $result;
                    $image_log['featured_image'] = [ 'uploaded' => true, 'attachment_id' => $result ];
                }
            }

            if ( ! empty( $term_data['images']['workshop_badge'] ) ) {
                $result = $this->upload_image_via_media_api( $term_data['images']['workshop_badge'] );
                if ( is_wp_error( $result ) ) {
                    $image_log['workshop_badge'] = [ 'uploaded' => false, 'error' => $result->get_error_message() ];
                } else {
                    $workshop_badge_id = $result;
                    $image_log['workshop_badge'] = [ 'uploaded' => true, 'attachment_id' => $result ];
                }
            }

            // ------------------------------------------------------------------
            // Step 2 — Create the taxonomy term via POST /wp/v2/workshop-type
            // ------------------------------------------------------------------
            $term_result = $this->create_term_via_rest_api(
                $term_data['name']        ?? '',
                $term_data['slug']        ?? '',
                $term_data['description'] ?? ''
            );

            if ( is_wp_error( $term_result ) ) {
                $errors[] = [
                    'term'    => $name,
                    'message' => $term_result->get_error_message(),
                ];
                continue;
            }

            $term_id   = (int)    $term_result['id'];
            $term_link = (string) $term_result['link'];
            $acf_selector = 'workshop-type_' . $term_id;

            // ------------------------------------------------------------------
            // Step 3 — Populate ACF fields
            // ------------------------------------------------------------------
            $acf_log = [];

            if ( $acf_active ) {

                // Image fields — pass the attachment ID directly
                $acf_log[ self::FIELD_FEATURED_IMAGE ] = $this->set_acf_field(
                    self::FIELD_FEATURED_IMAGE,
                    $featured_image_id,
                    $acf_selector
                );

                $acf_log[ self::FIELD_WORKSHOP_BADGE ] = $this->set_acf_field(
                    self::FIELD_WORKSHOP_BADGE,
                    $workshop_badge_id,
                    $acf_selector
                );

                // Relationship field — certification body post IDs
                $acf_log[ self::FIELD_CERT_BODY ] = $this->set_acf_field(
                    self::FIELD_CERT_BODY,
                    $cert_body_ids,
                    $acf_selector
                );

                // Text / wysiwyg fields — read directly from JSON acf block
                foreach ( self::ACF_TEXT_FIELDS as $field_key => $json_key ) {
                    $value = $term_data['acf'][ $json_key ] ?? '';
                    $acf_log[ $field_key ] = $this->set_acf_field( $field_key, $value, $acf_selector );
                }
            }

            $created[] = [
                'id'         => $term_id,
                'name'       => $term_data['name'],
                'slug'       => $term_data['slug'],
                'link'       => $term_link,
                'images'     => $image_log,
                'acf_active' => $acf_active,
                'acf_fields' => $acf_log,
            ];
        }

        return rest_ensure_response( [
            'success'    => empty( $errors ),
            'acf_active' => $acf_active,
            'created'    => $created,
            'errors'     => $errors,
        ] );
    }

    // -----------------------------------------------------------------------
    // REST callback: status
    // -----------------------------------------------------------------------

    public function handle_status( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( [
            'taxonomy_exists'    => taxonomy_exists( 'workshop-type' ),
            'acf_active'         => function_exists( 'update_field' ),
            'data_file_exists'   => file_exists( self::DATA_FILE ),
            'term_count_in_json' => count( $this->load_terms_json() ),
            'existing_term_count' => wp_count_terms( [
                'taxonomy'   => 'workshop-type',
                'hide_empty' => false,
            ] ),
        ] );
    }

    // -----------------------------------------------------------------------
    // Image upload via /wp/v2/media REST endpoint
    // -----------------------------------------------------------------------

    /**
     * Downloads an image from a remote URL and uploads it to the WordPress
     * Media Library by dispatching an internal REST request to POST /wp/v2/media.
     *
     * Flow:
     *   download_url()  →  temp file on disk
     *   detect MIME     →  wp_get_image_mime() / finfo
     *   rest_do_request( POST /wp/v2/media, set_file_params() )
     *   unlink temp file
     *
     * @param array{url:string,filename:string,title:string,alt:string} $image_def
     * @return int|WP_Error  Attachment ID on success.
     */
    private function upload_image_via_media_api( array $image_def ) {

        $url      = $image_def['url']      ?? '';
        $filename = $image_def['filename'] ?? '';
        $title    = $image_def['title']    ?? '';
        $alt      = $image_def['alt']      ?? '';

        if ( empty( $url ) ) {
            return new WP_Error( 'wts_no_url', __( 'Image URL is empty.', 'wts' ) );
        }

        // Ensure media sideload functions are available (normally only in admin context)
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_get_image_mime' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // 1. Download the remote image to a WordPress temp file
        $tmp_path = download_url( $url );

        if ( is_wp_error( $tmp_path ) ) {
            return new WP_Error(
                'wts_download_failed',
                sprintf( __( 'Failed to download image from %s: %s', 'wts' ), $url, $tmp_path->get_error_message() )
            );
        }

        // 2. Detect MIME type from the downloaded file content
        $mime = wp_get_image_mime( $tmp_path );

        if ( ! $mime ) {
            // Fallback: use PHP's finfo if available
            if ( function_exists( 'finfo_open' ) ) {
                $finfo = finfo_open( FILEINFO_MIME_TYPE );
                $mime  = finfo_file( $finfo, $tmp_path );
                finfo_close( $finfo );
            }
            if ( ! $mime ) {
                $mime = 'image/jpeg'; // safe default for photographic URLs
            }
        }

        // 3. Derive a proper filename (with extension) from the MIME type
        if ( empty( $filename ) || ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
            $ext_map  = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
            $ext      = $ext_map[ $mime ] ?? 'jpg';
            $base     = $filename ? pathinfo( $filename, PATHINFO_FILENAME ) : sanitize_title( $title ?: 'image' );
            $filename = $base . '.' . $ext;
        }

        // 4. Read the downloaded file into memory, then delete the temp file.
        //    We pass the raw bytes as the request body so the REST endpoint routes
        //    through upload_from_data() → wp_handle_sideload(), which does NOT call
        //    is_uploaded_file(). Using set_file_params() + upload_from_file() would
        //    fail that check because download_url() files are not real PHP uploads.
        $file_content = file_get_contents( $tmp_path );
        @unlink( $tmp_path );

        if ( $file_content === false || $file_content === '' ) {
            return new WP_Error( 'wts_read_failed', __( 'Could not read the downloaded image file.', 'wts' ) );
        }

        // 5. Dispatch POST /wp/v2/media via rest_do_request() using raw body.
        //    Content-Disposition (with filename) + Content-Type are required by
        //    WP_REST_Attachments_Controller::upload_from_data().
        $safe_filename = sanitize_file_name( $filename );
        $media_request = new WP_REST_Request( 'POST', '/wp/v2/media' );
        $media_request->set_body( $file_content );
        $media_request->set_header( 'Content-Type',        $mime );
        $media_request->set_header( 'Content-Disposition', 'attachment; filename="' . $safe_filename . '"' );
        $media_request->set_param( 'title',    $title );
        $media_request->set_param( 'alt_text', $alt   );

        $media_response = rest_do_request( $media_request );

        if ( $media_response->is_error() ) {
            $err = $media_response->as_error();
            return new WP_Error(
                'wts_media_upload_failed',
                sprintf(
                    __( 'Media upload failed for "%s": %s', 'wts' ),
                    $filename,
                    $err->get_error_message()
                )
            );
        }

        $media_data = $media_response->get_data();

        if ( empty( $media_data['id'] ) ) {
            return new WP_Error(
                'wts_media_no_id',
                __( 'Media upload succeeded but no attachment ID was returned.', 'wts' )
            );
        }

        // 7. Set alt text via post meta (REST param alt_text may not always persist)
        if ( $alt ) {
            update_post_meta( (int) $media_data['id'], '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        }

        return (int) $media_data['id'];
    }

    // -----------------------------------------------------------------------
    // Term creation via /wp/v2/workshop-type REST endpoint
    // -----------------------------------------------------------------------

    /**
     * Creates a single workshop-type term by dispatching an internal REST request
     * to POST /wp/v2/workshop-type (the native WP taxonomy REST endpoint).
     *
     * @param string $name
     * @param string $slug
     * @param string $description
     * @return array{id:int,link:string}|WP_Error
     */
    private function create_term_via_rest_api( string $name, string $slug, string $description ) {

        $request = new WP_REST_Request( 'POST', '/wp/v2/workshop-type' );
        $request->set_param( 'name',        $name        );
        $request->set_param( 'slug',        $slug        );
        $request->set_param( 'description', $description );

        $response = rest_do_request( $request );

        if ( $response->is_error() ) {
            $error = $response->as_error();
            return new WP_Error(
                $error->get_error_code(),
                $error->get_error_message(),
                [ 'status' => $response->get_status() ]
            );
        }

        $data = $response->get_data();

        if ( empty( $data['id'] ) ) {
            return new WP_Error(
                'wts_unexpected_response',
                __( 'No term ID returned from /wp/v2/workshop-type.', 'wts' ),
                [ 'status' => 500 ]
            );
        }

        return [
            'id'   => (int)    $data['id'],
            'link' => (string) ( $data['link'] ?? '' ),
        ];
    }

    // -----------------------------------------------------------------------
    // ACF field helper
    // -----------------------------------------------------------------------

    /**
     * Calls update_field() and returns a small log array for the response payload.
     *
     * @param string $field_key  ACF field key (field_xxxxxxxx)
     * @param mixed  $value
     * @param string $selector   ACF post/term selector, e.g. "workshop-type_42"
     * @return array{updated:bool,value:mixed}
     */
    private function set_acf_field( string $field_key, $value, string $selector ): array {
        $updated = update_field( $field_key, $value, $selector );
        return [
            'updated' => (bool) $updated,
            'value'   => is_array( $value ) ? implode( ', ', $value ) : $value,
        ];
    }

    // -----------------------------------------------------------------------
    // Data helpers
    // -----------------------------------------------------------------------

    /**
     * Loads and decodes data/terms.json.
     * Returns an empty array on any failure so callers do not need to null-check.
     *
     * @return array<int, array>
     */
    private function load_terms_json(): array {
        if ( ! file_exists( self::DATA_FILE ) ) {
            return [];
        }

        $raw = file_get_contents( self::DATA_FILE );
        if ( $raw === false ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Returns the IDs of published certifications-body posts for use in the
     * workshop_certification_body relationship field.
     * Returns an empty array when no posts of that type exist yet.
     *
     * @return int[]
     */
    private function resolve_certification_body_ids(): array {
        $posts = get_posts( [
            'post_type'      => 'certifications-body',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );

        return array_map( 'intval', $posts );
    }
}
