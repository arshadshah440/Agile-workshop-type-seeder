<?php
/**
 * Plugin Name:  Workshop Type Seeder
 * Description:  Adds a dashboard menu page where you upload a JSON file of Workshop Type terms. Each term is validated (name + slug required) then seeded — images are sideloaded into the media library and ACF custom fields are populated. Missing optional fields are reported per-term in the import log.
 * Version:      1.5.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:       Arshad Shah
 * Author URI:   https://arshadwebstudio.com
 * License:      GPL-2.0+
 *
 * Taxonomy targeted : workshop-type  (registered by The Events Calendar or equivalent)
 * ACF field group   : group_695645e713735  (attached to workshop-type terms)
 *
 * REST flow (per term)
 * --------------------
 * Browser JS  →  POST /wp-json/wts/v1/seed-workshop-types  { terms: [...] }
 *                  └─ For each term in the uploaded JSON:
 *                       1. If image_def has a valid "id" → reuse existing attachment
 *                          Else: download_url( image_url ) → temp file
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

    // Image ACF field keys — workshop type
    private const FIELD_FEATURED_IMAGE = 'field_695645eac1f1c'; // return_format: id
    private const FIELD_WORKSHOP_BADGE = 'field_69564622c1f1d'; // return_format: array
    private const FIELD_CERT_BODY      = 'field_6959662681c4c'; // relationship

    // ACF field keys — certification body CPT
    private const CB_FIELD_LOGO           = 'field_693a765d93403'; // image
    private const CB_FIELD_LOGO_WITH_TEXT = 'field_6927892bdbe66'; // image
    private const CB_FIELD_LOGO_INITIALS  = 'field_69278948dbe67'; // image
    private const CB_FIELD_WATERMARK      = 'field_6927896edbe68'; // image
    private const CB_FIELD_GALLERY        = 'field_693a768c373ff'; // gallery

    private const CB_ACF_SCALAR_FIELDS = [
        'field_69278863dbe63' => 'certification_body_aj_id',       // number
        'field_692788acdbe64' => 'certification_body_abbreviation', // text
        'field_69278982dbe69' => 'certification_body_slogan',       // text
        'field_692788f4dbe65' => 'certification_body_website_url',  // url
        'field_693a776cfff6c' => 'certification_body_description',  // textarea
    ];

    // Maps JSON key → field constant for the four logo image fields
    private const CB_LOGO_IMAGE_FIELDS = [
        'certification_body_logo'           => self::CB_FIELD_LOGO,
        'certification_body_logo_with_text' => self::CB_FIELD_LOGO_WITH_TEXT,
        'certification_body_logo_initials'  => self::CB_FIELD_LOGO_INITIALS,
        'certification_body_watermark'      => self::CB_FIELD_WATERMARK,
    ];

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

        add_submenu_page(
            'workshop-type-seeder',
            __( 'Certification Body Seeder', 'wts' ),
            __( 'Cert Body Seeder',          'wts' ),
            'manage_options',
            'cert-body-seeder',
            [ $this, 'render_cert_admin_page' ]
        );
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wts' ) );
        }

        $taxonomy_exists = taxonomy_exists( 'workshop-type' );
        $acf_active      = function_exists( 'update_field' );
        $wpml_active     = $this->is_wpml_active();
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

            <?php if ( ! $acf_active ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e( 'ACF not active:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'Advanced Custom Fields is not active. Terms and images will still be created, but ACF fields will NOT be populated.', 'wts' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( $wpml_active ) : ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php esc_html_e( 'WPML detected:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'Primary terms will be created in the default language. Add a "translations" object to any term in your JSON to seed additional language versions at the same time.', 'wts' ); ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-info" style="display:none" id="wts-wpml-hint">
                    <p>
                        <strong><?php esc_html_e( 'Multilingual support:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'Install and activate WPML to seed translations directly from your JSON file.', 'wts' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border-left:4px solid #72aee6;padding:12px 18px;margin:20px 0;max-width:760px;">
                <p><?php esc_html_e( 'Upload a JSON file containing workshop type terms. Each term must include name and slug. Optional fields (description, images, ACF data) will be imported if present — any missing fields are noted per-term in the import log.', 'wts' ); ?></p>
                <?php if ( $wpml_active ) : ?>
                    <p style="margin:4px 0 0;font-size:12px;color:#666;"><?php esc_html_e( 'WPML: add a "translations" key to any term with language-keyed objects (e.g. "fr", "de") to create linked translations. Each translation requires at minimum a "name" field.', 'wts' ); ?></p>
                <?php endif; ?>
                <p style="margin:4px 0 0;font-size:12px;color:#666;"><?php esc_html_e( 'Expected format: a JSON array of term objects. Each entry needs at minimum a "name" and "slug" key.', 'wts' ); ?></p>
            </div>

            <div style="background:#fff;padding:18px 20px;border:1px solid #ccd0d4;border-radius:3px;max-width:520px;margin-bottom:20px;">
                <label for="wts-json-file" style="display:block;font-weight:600;margin-bottom:8px;">
                    <?php esc_html_e( 'Select terms JSON file', 'wts' ); ?>
                </label>
                <input
                    type="file"
                    id="wts-json-file"
                    accept=".json,application/json"
                    style="display:block;margin-bottom:12px;"
                    <?php disabled( ! $taxonomy_exists ); ?>
                />
                <div id="wts-file-validation" style="margin-bottom:12px;"></div>
                <button
                    id="wts-seed-button"
                    class="button button-primary button-hero"
                    disabled="disabled"
                >
                    <?php esc_html_e( 'Import & Seed Terms', 'wts' ); ?>
                </button>
            </div>

            <div id="wts-status" style="margin-top:24px;max-width:800px;"></div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Admin assets
    // -----------------------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

        // ---- Certification Body Seeder page --------------------------------
        if ( 'cert-body-seeder' === $current_page ) {
            wp_enqueue_script(
                'wts-admin-cert',
                plugin_dir_url( __FILE__ ) . 'assets/js/admin-cert.js',
                [],
                '1.0.0',
                true
            );
            wp_localize_script( 'wts-admin-cert', 'wtsCertData', [
                'restBase'   => esc_url_raw( rest_url( 'wts/v1/' ) ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'wpmlActive' => $this->is_wpml_active(),
                'i18n'       => [
                    'confirm'             => __( 'This will upload images and create Certification Body posts. Existing posts with the same slug will cause an error. Continue?', 'wts' ),
                    'seeding'             => __( 'Uploading images and seeding certification bodies — this may take a moment…', 'wts' ),
                    'done'                => __( 'Seeding complete!', 'wts' ),
                    'errPfx'              => __( 'Error:', 'wts' ),
                    'invalidJson'         => __( 'Could not parse JSON:', 'wts' ),
                    'notArray'            => __( 'JSON must be a top-level array of objects.', 'wts' ),
                    'noItems'             => __( 'The JSON file contains no entries.', 'wts' ),
                    'validFailed'         => __( 'Validation failed — fix the errors below before importing:', 'wts' ),
                    'missingOptional'     => __( 'Optional fields missing (will be skipped):', 'wts' ),
                    'readyToImport'       => __( 'certification body entries ready to import.', 'wts' ),
                    'wpmlTransNoTitle'    => __( 'translation is missing required field: title', 'wts' ),
                    'wpmlMissingOptional' => __( 'Translations with missing optional fields:', 'wts' ),
                ],
            ] );
            return;
        }

        // ---- Workshop Type Seeder page -------------------------------------
        if ( 'toplevel_page_workshop-type-seeder' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'wts-admin',
            plugin_dir_url( __FILE__ ) . 'assets/js/admin.js',
            [],
            '1.4.0',
            true
        );

        wp_localize_script( 'wts-admin', 'wtsData', [
            'restBase'   => esc_url_raw( rest_url( 'wts/v1/' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'wpmlActive' => $this->is_wpml_active(),
            'i18n'       => [
                'confirm'              => __( 'This will upload images to your Media Library and create Workshop Type terms. Existing terms with the same slug will cause an error. Continue?', 'wts' ),
                'seeding'              => __( 'Uploading images and seeding terms — this may take a moment…', 'wts' ),
                'done'                 => __( 'Seeding complete!', 'wts' ),
                'errPfx'               => __( 'Error:', 'wts' ),
                'invalidJson'          => __( 'Could not parse JSON:', 'wts' ),
                'notArray'             => __( 'JSON must be a top-level array of term objects.', 'wts' ),
                'noTerms'              => __( 'The JSON file contains no terms.', 'wts' ),
                'validFailed'          => __( 'Validation failed — fix the errors below before importing:', 'wts' ),
                'missingOptional'      => __( 'Optional fields missing (will be skipped):', 'wts' ),
                'readyToImport'        => __( 'term(s) ready to import.', 'wts' ),
                'wpmlTransNoName'      => __( 'translation is missing required field: name', 'wts' ),
                'wpmlMissingOptional'  => __( 'Translations with missing optional fields:', 'wts' ),
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

        register_rest_route( 'wts/v1', '/seed-certification-bodies', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_cert_seed' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( 'wts/v1', '/cert-status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_cert_status' ],
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
     * Accepts a JSON body with a "terms" array. For each term:
     *   1. Validates name + slug (and translation names when WPML is active)
     *   2. Uploads featured_image / workshop_badge → POST /wp/v2/media → attachment ID
     *   3. Creates taxonomy term → POST /wp/v2/workshop-type → term ID
     *   4. Sets all ACF fields via update_field()
     *   5. (WPML) Registers primary term language and creates linked translation terms
     *   6. Reports any missing optional fields in the per-term log
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

        $terms_data = $request->get_param( 'terms' );

        if ( empty( $terms_data ) || ! is_array( $terms_data ) ) {
            return new WP_Error(
                'wts_no_data',
                __( 'No term data provided. Send a "terms" array in the request body.', 'wts' ),
                [ 'status' => 400 ]
            );
        }

        // WPML context — resolved once so translation validation and processing
        // are both skipped entirely when WPML is not installed.
        $wpml_active  = $this->is_wpml_active();
        $default_lang = $wpml_active ? $this->get_default_language() : '';

        // -----------------------------------------------------------------------
        // Server-side validation: name + slug required for every primary term.
        // When WPML is active, each translation entry must also have a name.
        // -----------------------------------------------------------------------
        $validation_errors = [];
        foreach ( $terms_data as $i => $term ) {
            $term_label = ! empty( $term['name'] )
                ? sanitize_text_field( $term['name'] )
                : sprintf( __( 'Term #%d', 'wts' ), $i + 1 );

            $missing = [];
            if ( empty( $term['name'] ) ) $missing[] = 'name';
            if ( empty( $term['slug'] ) ) $missing[] = 'slug';

            if ( ! empty( $missing ) ) {
                $validation_errors[] = [
                    'index'   => $i,
                    'term'    => $term_label,
                    'message' => sprintf(
                        __( 'Missing required field(s): %s', 'wts' ),
                        implode( ', ', $missing )
                    ),
                ];
            }

            if ( $wpml_active && ! empty( $term['translations'] ) && is_array( $term['translations'] ) ) {
                foreach ( $term['translations'] as $lang_code => $translation ) {
                    if ( empty( $translation['name'] ) ) {
                        $validation_errors[] = [
                            'index'   => $i,
                            'term'    => $term_label,
                            'message' => sprintf(
                                __( 'Translation "%s" is missing the required "name" field.', 'wts' ),
                                sanitize_key( $lang_code )
                            ),
                        ];
                    }
                }
            }
        }

        if ( ! empty( $validation_errors ) ) {
            return new WP_Error(
                'wts_validation_failed',
                __( 'One or more terms failed validation.', 'wts' ),
                [ 'status' => 400, 'errors' => $validation_errors ]
            );
        }

        // -----------------------------------------------------------------------
        // Process each term
        // -----------------------------------------------------------------------
        $acf_active    = function_exists( 'update_field' );
        $cert_body_ids = $this->resolve_certification_body_ids();
        $created       = [];
        $errors        = [];

        // Ensure primary terms are created in the default language
        if ( $wpml_active ) {
            do_action( 'wpml_switch_language', $default_lang );
        }

        foreach ( $terms_data as $term_data ) {
            $name = sanitize_text_field( $term_data['name'] ?? '' );

            // Detect missing optional fields for the import log
            $missing_fields = [];
            if ( empty( $term_data['description'] ) )                 $missing_fields[] = 'description';
            if ( empty( $term_data['images']['featured_image'] ) )    $missing_fields[] = 'images.featured_image';
            if ( empty( $term_data['images']['workshop_badge'] ) )    $missing_fields[] = 'images.workshop_badge';
            if ( empty( $term_data['acf']['workshop_description'] ) ) $missing_fields[] = 'acf.workshop_description';
            if ( empty( $term_data['acf']['workshop_tagline'] ) )     $missing_fields[] = 'acf.workshop_tagline';
            if ( empty( $term_data['acf']['abbreviation'] ) )         $missing_fields[] = 'acf.abbreviation';

            // ------------------------------------------------------------------
            // Step 1 — Upload images via POST /wp/v2/media
            // ------------------------------------------------------------------
            $featured_image_id = 0;
            $workshop_badge_id = 0;
            $image_log         = [];

            if ( ! empty( $term_data['images']['featured_image'] ) ) {
                $img_def  = $term_data['images']['featured_image'];
                $existing = $this->maybe_use_existing_image( $img_def );
                if ( $existing ) {
                    $featured_image_id = $existing;
                    $image_log['featured_image'] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
                } else {
                    $result = $this->upload_image_via_media_api( $img_def );
                    if ( is_wp_error( $result ) ) {
                        $image_log['featured_image'] = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                    } else {
                        $featured_image_id = $result;
                        $image_log['featured_image'] = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                    }
                }
            }

            if ( ! empty( $term_data['images']['workshop_badge'] ) ) {
                $img_def  = $term_data['images']['workshop_badge'];
                $existing = $this->maybe_use_existing_image( $img_def );
                if ( $existing ) {
                    $workshop_badge_id = $existing;
                    $image_log['workshop_badge'] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
                } else {
                    $result = $this->upload_image_via_media_api( $img_def );
                    if ( is_wp_error( $result ) ) {
                        $image_log['workshop_badge'] = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                    } else {
                        $workshop_badge_id = $result;
                        $image_log['workshop_badge'] = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                    }
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

            $term_id      = (int)    $term_result['id'];
            $term_link    = (string) $term_result['link'];
            $acf_selector = 'workshop-type_' . $term_id;

            // ------------------------------------------------------------------
            // Step 3 — Populate ACF fields
            // ------------------------------------------------------------------
            $acf_log = [];

            if ( $acf_active ) {

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

                $acf_log[ self::FIELD_CERT_BODY ] = $this->set_acf_field(
                    self::FIELD_CERT_BODY,
                    $cert_body_ids,
                    $acf_selector
                );

                foreach ( self::ACF_TEXT_FIELDS as $field_key => $json_key ) {
                    $value = $term_data['acf'][ $json_key ] ?? '';
                    $acf_log[ $field_key ] = $this->set_acf_field( $field_key, $value, $acf_selector );
                }
            }

            // ------------------------------------------------------------------
            // Step 4 — WPML: register primary term language + process translations
            // ------------------------------------------------------------------
            $trid             = 0;
            $translations_log = [];

            if ( $wpml_active ) {
                $trid = $this->set_wpml_term_language( $term_id, $default_lang, null, $default_lang );

                if ( ! empty( $term_data['translations'] ) && is_array( $term_data['translations'] ) ) {
                    foreach ( $term_data['translations'] as $lang_code => $trans_data ) {
                        if ( empty( $trans_data['name'] ) ) continue;
                        $translations_log[ sanitize_key( $lang_code ) ] = $this->process_term_translation(
                            $trans_data,
                            sanitize_key( $lang_code ),
                            $trid,
                            $default_lang,
                            $acf_active,
                            $cert_body_ids,
                            $featured_image_id,
                            $workshop_badge_id
                        );
                    }
                }
            }

            $created[] = [
                'id'             => $term_id,
                'name'           => $term_data['name'],
                'slug'           => $term_data['slug'],
                'link'           => $term_link,
                'images'         => $image_log,
                'missing_fields' => $missing_fields,
                'acf_active'     => $acf_active,
                'acf_fields'     => $acf_log,
                'wpml_active'    => $wpml_active,
                'trid'           => $trid ?: null,
                'translations'   => $translations_log,
            ];
        }

        return rest_ensure_response( [
            'success'     => empty( $errors ),
            'acf_active'  => $acf_active,
            'wpml_active' => $wpml_active,
            'created'     => $created,
            'errors'      => $errors,
        ] );
    }

    // -----------------------------------------------------------------------
    // REST callback: status
    // -----------------------------------------------------------------------

    public function handle_status( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( [
            'taxonomy_exists'    => taxonomy_exists( 'workshop-type' ),
            'acf_active'         => function_exists( 'update_field' ),
            'wpml_active'        => $this->is_wpml_active(),
            'existing_term_count' => wp_count_terms( [
                'taxonomy'   => 'workshop-type',
                'hide_empty' => false,
            ] ),
        ] );
    }

    // -----------------------------------------------------------------------
    // Image resolution — reuse existing attachment or upload a new one
    // -----------------------------------------------------------------------

    /**
     * If the image definition includes a valid WordPress attachment ID,
     * returns it so the download + upload step can be skipped entirely.
     *
     * Callers should check for a non-zero return value and, if found,
     * mark the image log entry as reused rather than uploaded.
     *
     * @param array $image_def  Image definition from the JSON (may contain an "id" key).
     * @return int  Existing attachment ID if valid, 0 otherwise.
     */
    private function maybe_use_existing_image( array $image_def ): int {
        $id = isset( $image_def['id'] ) ? (int) $image_def['id'] : 0;
        if ( $id <= 0 ) {
            return 0;
        }
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return 0;
        }
        return $id;
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
    // WPML helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true when the WPML plugin is active.
     * ICL_SITEPRESS_VERSION is defined by WPML on plugins_loaded.
     */
    private function is_wpml_active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' );
    }

    /**
     * Returns the WPML default language code (e.g. "en").
     */
    private function get_default_language(): string {
        return (string) apply_filters( 'wpml_default_language', '' );
    }

    /**
     * Registers a workshop-type term with WPML by firing
     * wpml_set_element_language_details with the term's term_taxonomy_id.
     *
     * Pass $trid = null to create a new translation group (primary term).
     * Pass an existing $trid to link a translated term into that group.
     * Returns the trid so callers can chain translations off the primary term.
     *
     * @param int      $term_id     WordPress term ID
     * @param string   $lang_code   WPML language code, e.g. "fr"
     * @param int|null $trid        Existing translation group ID, or null for primary
     * @param string   $source_lang Default language code (source for translations)
     * @return int  The trid (translation group ID)
     */
    private function set_wpml_term_language( int $term_id, string $lang_code, ?int $trid, string $source_lang ): int {
        $term_obj = get_term( $term_id, 'workshop-type' );

        if ( is_wp_error( $term_obj ) || ! $term_obj ) {
            return 0;
        }

        $ttid = (int) $term_obj->term_taxonomy_id;

        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $ttid,
            'element_type'         => 'tax_workshop-type',
            'trid'                 => $trid ?: false,
            'language_code'        => $lang_code,
            'source_language_code' => $trid ? $source_lang : null,
        ] );

        // After registering a new primary term, retrieve the trid WPML assigned
        if ( ! $trid ) {
            $trid = (int) apply_filters( 'wpml_element_trid', null, $ttid, 'tax_workshop-type' );
        }

        return $trid;
    }

    /**
     * Creates one translated term and links it to the primary term's translation
     * group via WPML. Switches language context before creation and restores it
     * to $default_lang afterwards.
     *
     * Images and ACF text fields are optional — only populated when present in
     * $trans_data, so minimal translation entries (name only) are fully supported.
     *
     * @param array  $trans_data    Single translation object from the JSON "translations" key
     * @param string $lang_code     Language code, e.g. "fr"
     * @param int    $trid          Translation group ID obtained from the primary term
     * @param string $default_lang  Default language code to restore after processing
     * @param bool   $acf_active    Whether ACF's update_field() is available
     * @param int[]  $cert_body_ids Certification body post IDs for the relationship field
     * @return array  Per-translation log entry included in the REST response
     */
    private function process_term_translation(
        array  $trans_data,
        string $lang_code,
        int    $trid,
        string $default_lang,
        bool   $acf_active,
        array  $cert_body_ids,
        int    $fallback_featured_image_id = 0,
        int    $fallback_workshop_badge_id = 0
    ): array {
        do_action( 'wpml_switch_language', $lang_code );

        $log = [
            'lang'       => $lang_code,
            'name'       => sanitize_text_field( $trans_data['name'] ),
            'slug'       => '',
            'id'         => 0,
            'link'       => '',
            'images'     => [],
            'acf_fields' => [],
            'error'      => null,
        ];

        // Optional image uploads for this translation
        $featured_image_id = 0;
        $workshop_badge_id = 0;

        if ( ! empty( $trans_data['images']['featured_image'] ) ) {
            $img_def  = $trans_data['images']['featured_image'];
            $existing = $this->maybe_use_existing_image( $img_def );
            if ( $existing ) {
                $featured_image_id = $existing;
                $log['images']['featured_image'] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
            } else {
                $result = $this->upload_image_via_media_api( $img_def );
                if ( is_wp_error( $result ) ) {
                    $log['images']['featured_image'] = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                } else {
                    $featured_image_id = $result;
                    $log['images']['featured_image'] = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                }
            }
        } elseif ( $fallback_featured_image_id ) {
            // No image in translation JSON — reuse the primary term's attachment
            $featured_image_id = $fallback_featured_image_id;
            $log['images']['featured_image'] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $fallback_featured_image_id ];
        }

        if ( ! empty( $trans_data['images']['workshop_badge'] ) ) {
            $img_def  = $trans_data['images']['workshop_badge'];
            $existing = $this->maybe_use_existing_image( $img_def );
            if ( $existing ) {
                $workshop_badge_id = $existing;
                $log['images']['workshop_badge'] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
            } else {
                $result = $this->upload_image_via_media_api( $img_def );
                if ( is_wp_error( $result ) ) {
                    $log['images']['workshop_badge'] = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                } else {
                    $workshop_badge_id = $result;
                    $log['images']['workshop_badge'] = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                }
            }
        } elseif ( $fallback_workshop_badge_id ) {
            // No badge in translation JSON — reuse the primary term's attachment
            $workshop_badge_id = $fallback_workshop_badge_id;
            $log['images']['workshop_badge'] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $fallback_workshop_badge_id ];
        }

        // Auto-generate slug if not provided (appends lang code to avoid conflicts)
        $slug = ! empty( $trans_data['slug'] )
            ? $trans_data['slug']
            : sanitize_title( $trans_data['name'] ) . '-' . $lang_code;

        $term_result = $this->create_term_via_rest_api(
            $trans_data['name'],
            $slug,
            $trans_data['description'] ?? ''
        );

        if ( is_wp_error( $term_result ) ) {
            $log['error'] = $term_result->get_error_message();
            do_action( 'wpml_switch_language', $default_lang );
            return $log;
        }

        $trans_term_id = (int) $term_result['id'];
        $log['id']     = $trans_term_id;
        $log['slug']   = $slug;
        $log['link']   = (string) $term_result['link'];

        // Link to the primary term's translation group
        $this->set_wpml_term_language( $trans_term_id, $lang_code, $trid, $default_lang );

        // Populate only the ACF fields that are present in this translation entry
        if ( $acf_active ) {
            $acf_selector = 'workshop-type_' . $trans_term_id;

            if ( $featured_image_id ) {
                $log['acf_fields'][ self::FIELD_FEATURED_IMAGE ] = $this->set_acf_field(
                    self::FIELD_FEATURED_IMAGE, $featured_image_id, $acf_selector
                );
            }
            if ( $workshop_badge_id ) {
                $log['acf_fields'][ self::FIELD_WORKSHOP_BADGE ] = $this->set_acf_field(
                    self::FIELD_WORKSHOP_BADGE, $workshop_badge_id, $acf_selector
                );
            }
            $log['acf_fields'][ self::FIELD_CERT_BODY ] = $this->set_acf_field(
                self::FIELD_CERT_BODY, $cert_body_ids, $acf_selector
            );
            foreach ( self::ACF_TEXT_FIELDS as $field_key => $json_key ) {
                if ( isset( $trans_data['acf'][ $json_key ] ) ) {
                    $log['acf_fields'][ $field_key ] = $this->set_acf_field(
                        $field_key, $trans_data['acf'][ $json_key ], $acf_selector
                    );
                }
            }
        }

        do_action( 'wpml_switch_language', $default_lang );

        return $log;
    }

    // -----------------------------------------------------------------------
    // Certification Body — admin page
    // -----------------------------------------------------------------------

    public function render_cert_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wts' ) );
        }

        $cpt_exists  = post_type_exists( 'certifications-body' );
        $acf_active  = function_exists( 'update_field' );
        $wpml_active = $this->is_wpml_active();
        ?>
        <div class="wrap" id="wts-cert-admin-page">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Certification Body Seeder', 'wts' ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( ! $cpt_exists ) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e( 'Post type missing:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'The "certifications-body" post type is not registered. Please activate the plugin or theme that provides it before seeding.', 'wts' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! $acf_active ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e( 'ACF not active:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'Advanced Custom Fields is not active. Posts will still be created, but ACF fields will NOT be populated.', 'wts' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( $wpml_active ) : ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php esc_html_e( 'WPML detected:', 'wts' ); ?></strong>
                        <?php esc_html_e( 'Primary posts will be created in the default language. Add a "translations" object to any entry in your JSON to seed additional language versions at the same time.', 'wts' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border-left:4px solid #72aee6;padding:12px 18px;margin:20px 0;max-width:760px;">
                <p><?php esc_html_e( 'Upload a JSON file containing certification body entries. Each entry must include title and slug. Optional fields (excerpt, featured image, logos, gallery, ACF data) will be imported if present — any missing fields are noted per-entry in the import log.', 'wts' ); ?></p>
                <?php if ( $wpml_active ) : ?>
                    <p style="margin:4px 0 0;font-size:12px;color:#666;"><?php esc_html_e( 'WPML: add a "translations" key to any entry with language-keyed objects (e.g. "fr", "de") to create linked translations. Each translation requires at minimum a "title" field.', 'wts' ); ?></p>
                <?php endif; ?>
                <p style="margin:4px 0 0;font-size:12px;color:#666;"><?php esc_html_e( 'Expected format: a JSON array of certification body objects. Each entry needs at minimum a "title" and "slug" key.', 'wts' ); ?></p>
            </div>

            <div style="background:#fff;padding:18px 20px;border:1px solid #ccd0d4;border-radius:3px;max-width:520px;margin-bottom:20px;">
                <label for="wts-cert-json-file" style="display:block;font-weight:600;margin-bottom:8px;">
                    <?php esc_html_e( 'Select certification bodies JSON file', 'wts' ); ?>
                </label>
                <input
                    type="file"
                    id="wts-cert-json-file"
                    accept=".json,application/json"
                    style="display:block;margin-bottom:12px;"
                    <?php disabled( ! $cpt_exists ); ?>
                />
                <div id="wts-cert-file-validation" style="margin-bottom:12px;"></div>
                <button
                    id="wts-cert-seed-button"
                    class="button button-primary button-hero"
                    disabled="disabled"
                >
                    <?php esc_html_e( 'Import & Seed Certification Bodies', 'wts' ); ?>
                </button>
            </div>

            <div id="wts-cert-status" style="margin-top:24px;max-width:1000px;"></div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Certification Body — REST: seed
    // -----------------------------------------------------------------------

    /**
     * POST /wp-json/wts/v1/seed-certification-bodies
     *
     * Accepts a JSON body with a "posts" array. For each entry:
     *   1. Validates title + slug
     *   2. Uploads / reuses featured image
     *   3. Uploads / reuses logo image fields (logo, logo_with_text, logo_initials, watermark)
     *   4. Uploads / reuses gallery images
     *   5. Creates certifications-body post via POST /wp/v2/certifications-body
     *   6. Sets ACF fields via update_field()
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_cert_seed( WP_REST_Request $request ) {

        if ( ! post_type_exists( 'certifications-body' ) ) {
            return new WP_Error(
                'wts_cpt_missing',
                __( 'The "certifications-body" post type is not registered.', 'wts' ),
                [ 'status' => 400 ]
            );
        }

        $posts_data = $request->get_param( 'posts' );

        if ( empty( $posts_data ) || ! is_array( $posts_data ) ) {
            return new WP_Error(
                'wts_no_data',
                __( 'No data provided. Send a "posts" array in the request body.', 'wts' ),
                [ 'status' => 400 ]
            );
        }

        // WPML context — resolved once
        $wpml_active  = $this->is_wpml_active();
        $default_lang = $wpml_active ? $this->get_default_language() : '';

        // Server-side validation: title + slug required for every primary post.
        // When WPML is active each translation entry must also have a title.
        $validation_errors = [];
        foreach ( $posts_data as $i => $post ) {
            $post_label = ! empty( $post['title'] )
                ? sanitize_text_field( $post['title'] )
                : sprintf( __( 'Entry #%d', 'wts' ), $i + 1 );

            $missing = [];
            if ( empty( $post['title'] ) ) $missing[] = 'title';
            if ( empty( $post['slug'] ) )  $missing[] = 'slug';

            if ( ! empty( $missing ) ) {
                $validation_errors[] = [
                    'index'   => $i,
                    'post'    => $post_label,
                    'message' => sprintf(
                        __( 'Missing required field(s): %s', 'wts' ),
                        implode( ', ', $missing )
                    ),
                ];
            }

            if ( $wpml_active && ! empty( $post['translations'] ) && is_array( $post['translations'] ) ) {
                foreach ( $post['translations'] as $lang_code => $translation ) {
                    if ( empty( $translation['title'] ) ) {
                        $validation_errors[] = [
                            'index'   => $i,
                            'post'    => $post_label,
                            'message' => sprintf(
                                __( 'Translation "%s" is missing the required "title" field.', 'wts' ),
                                sanitize_key( $lang_code )
                            ),
                        ];
                    }
                }
            }
        }

        if ( ! empty( $validation_errors ) ) {
            return new WP_Error(
                'wts_validation_failed',
                __( 'One or more entries failed validation.', 'wts' ),
                [ 'status' => 400, 'errors' => $validation_errors ]
            );
        }

        $acf_active = function_exists( 'update_field' );
        $created    = [];
        $errors     = [];

        // Ensure primary posts are created in the default language
        if ( $wpml_active ) {
            do_action( 'wpml_switch_language', $default_lang );
        }

        foreach ( $posts_data as $post_data ) {
            $title = sanitize_text_field( $post_data['title'] ?? '' );

            // Detect missing optional fields for the import log
            $missing_fields = [];
            if ( empty( $post_data['excerpt'] ) )       $missing_fields[] = 'excerpt';
            if ( empty( $post_data['featured_image'] ) ) $missing_fields[] = 'featured_image';
            foreach ( array_keys( self::CB_LOGO_IMAGE_FIELDS ) as $json_key ) {
                if ( empty( $post_data['acf'][ $json_key ] ) ) $missing_fields[] = 'acf.' . $json_key;
            }
            if ( empty( $post_data['acf']['certification_body_gallery'] ) ) $missing_fields[] = 'acf.certification_body_gallery';
            foreach ( array_values( self::CB_ACF_SCALAR_FIELDS ) as $json_key ) {
                $val = $post_data['acf'][ $json_key ] ?? null;
                if ( $val === null || $val === '' ) $missing_fields[] = 'acf.' . $json_key;
            }

            // ------------------------------------------------------------------
            // Step 1 — Featured image
            // ------------------------------------------------------------------
            $featured_image_id  = 0;
            $featured_image_log = null;

            if ( ! empty( $post_data['featured_image'] ) ) {
                $img_def  = $post_data['featured_image'];
                $existing = $this->maybe_use_existing_image( $img_def );
                if ( $existing ) {
                    $featured_image_id  = $existing;
                    $featured_image_log = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
                } else {
                    $result = $this->upload_image_via_media_api( $img_def );
                    if ( is_wp_error( $result ) ) {
                        $featured_image_log = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                    } else {
                        $featured_image_id  = $result;
                        $featured_image_log = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                    }
                }
            }

            // ------------------------------------------------------------------
            // Step 2 — Logo image fields
            // ------------------------------------------------------------------
            $logos_log = [];
            $logo_ids  = []; // field_key => attachment_id

            foreach ( self::CB_LOGO_IMAGE_FIELDS as $json_key => $field_key ) {
                if ( empty( $post_data['acf'][ $json_key ] ) ) continue;

                $img_def  = $post_data['acf'][ $json_key ];
                $existing = $this->maybe_use_existing_image( $img_def );
                if ( $existing ) {
                    $logo_ids[ $field_key ]  = $existing;
                    $logos_log[ $json_key ]  = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
                } else {
                    $result = $this->upload_image_via_media_api( $img_def );
                    if ( is_wp_error( $result ) ) {
                        $logos_log[ $json_key ] = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                    } else {
                        $logo_ids[ $field_key ] = $result;
                        $logos_log[ $json_key ] = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                    }
                }
            }

            // ------------------------------------------------------------------
            // Step 3 — Gallery images
            // ------------------------------------------------------------------
            $gallery_ids = [];
            $gallery_log = [];

            if ( ! empty( $post_data['acf']['certification_body_gallery'] ) && is_array( $post_data['acf']['certification_body_gallery'] ) ) {
                foreach ( $post_data['acf']['certification_body_gallery'] as $idx => $img_def ) {
                    if ( ! is_array( $img_def ) ) continue;

                    $existing = $this->maybe_use_existing_image( $img_def );
                    if ( $existing ) {
                        $gallery_ids[]  = $existing;
                        $gallery_log[]  = [ 'index' => $idx, 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
                    } else {
                        $result = $this->upload_image_via_media_api( $img_def );
                        if ( is_wp_error( $result ) ) {
                            $gallery_log[] = [ 'index' => $idx, 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                        } else {
                            $gallery_ids[] = $result;
                            $gallery_log[] = [ 'index' => $idx, 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                        }
                    }
                }
            }

            // ------------------------------------------------------------------
            // Step 4 — Create post via REST API
            // ------------------------------------------------------------------
            $post_result = $this->create_cert_post_via_rest_api(
                $post_data['title']   ?? '',
                $post_data['slug']    ?? '',
                $post_data['excerpt'] ?? '',
                $featured_image_id
            );

            if ( is_wp_error( $post_result ) ) {
                $errors[] = [
                    'post'    => $title,
                    'message' => $post_result->get_error_message(),
                ];
                continue;
            }

            $post_id   = (int)    $post_result['id'];
            $post_link = (string) $post_result['link'];

            // ------------------------------------------------------------------
            // Step 5 — Populate ACF fields
            // ------------------------------------------------------------------
            $acf_log = [];

            if ( $acf_active ) {
                // Logo image fields
                foreach ( $logo_ids as $field_key => $attachment_id ) {
                    $acf_log[ $field_key ] = $this->set_acf_field( $field_key, $attachment_id, $post_id );
                }

                // Gallery
                if ( ! empty( $gallery_ids ) ) {
                    $acf_log[ self::CB_FIELD_GALLERY ] = $this->set_acf_field( self::CB_FIELD_GALLERY, $gallery_ids, $post_id );
                }

                // Scalar fields (number, text, url, textarea)
                foreach ( self::CB_ACF_SCALAR_FIELDS as $field_key => $json_key ) {
                    $value = $post_data['acf'][ $json_key ] ?? null;
                    if ( $value !== null && $value !== '' ) {
                        $acf_log[ $field_key ] = $this->set_acf_field( $field_key, $value, $post_id );
                    }
                }
            }

            // ------------------------------------------------------------------
            // Step 6 — WPML: register primary post language + process translations
            // ------------------------------------------------------------------
            $trid             = 0;
            $translations_log = [];

            if ( $wpml_active ) {
                $trid = $this->set_wpml_post_language( $post_id, $default_lang, null, $default_lang );

                if ( ! empty( $post_data['translations'] ) && is_array( $post_data['translations'] ) ) {
                    foreach ( $post_data['translations'] as $lang_code => $trans_data ) {
                        if ( empty( $trans_data['title'] ) ) continue;
                        $translations_log[ sanitize_key( $lang_code ) ] = $this->process_cert_post_translation(
                            $trans_data,
                            sanitize_key( $lang_code ),
                            $trid,
                            $default_lang,
                            $acf_active,
                            $featured_image_id,
                            $logo_ids,
                            $gallery_ids
                        );
                    }
                }
            }

            $created[] = [
                'id'             => $post_id,
                'title'          => $post_data['title'],
                'slug'           => $post_data['slug'],
                'link'           => $post_link,
                'featured_image' => $featured_image_log,
                'logos'          => $logos_log,
                'gallery'        => $gallery_log,
                'missing_fields' => $missing_fields,
                'acf_active'     => $acf_active,
                'acf_fields'     => $acf_log,
                'wpml_active'    => $wpml_active,
                'trid'           => $trid ?: null,
                'translations'   => $translations_log,
            ];
        }

        return rest_ensure_response( [
            'success'     => empty( $errors ),
            'acf_active'  => $acf_active,
            'wpml_active' => $wpml_active,
            'created'     => $created,
            'errors'      => $errors,
        ] );
    }

    // -----------------------------------------------------------------------
    // Certification Body — REST: status
    // -----------------------------------------------------------------------

    public function handle_cert_status( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( [
            'cpt_exists'     => post_type_exists( 'certifications-body' ),
            'acf_active'     => function_exists( 'update_field' ),
            'existing_count' => (int) wp_count_posts( 'certifications-body' )->publish,
        ] );
    }

    // -----------------------------------------------------------------------
    // Certification Body — create post via REST API
    // -----------------------------------------------------------------------

    /**
     * Creates a single certifications-body post via an internal REST request.
     * Sets title, slug, excerpt, status, and optionally the featured image.
     *
     * @param string $title
     * @param string $slug
     * @param string $excerpt
     * @param int    $featured_image_id  Attachment ID, or 0 to skip.
     * @return array{id:int,link:string}|WP_Error
     */
    private function create_cert_post_via_rest_api( string $title, string $slug, string $excerpt, int $featured_image_id = 0 ) {
        $request = new WP_REST_Request( 'POST', '/wp/v2/certifications-body' );
        $request->set_param( 'title',   $title );
        $request->set_param( 'slug',    $slug );
        $request->set_param( 'excerpt', $excerpt );
        $request->set_param( 'status',  'publish' );

        if ( $featured_image_id ) {
            $request->set_param( 'featured_media', $featured_image_id );
        }

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
                __( 'No post ID returned from /wp/v2/certifications-body.', 'wts' ),
                [ 'status' => 500 ]
            );
        }

        return [
            'id'   => (int)    $data['id'],
            'link' => (string) ( $data['link'] ?? '' ),
        ];
    }

    // -----------------------------------------------------------------------
    // Certification Body — WPML helpers
    // -----------------------------------------------------------------------

    /**
     * Registers a certifications-body post with WPML.
     * element_id for CPT posts is the post ID directly (not term_taxonomy_id).
     *
     * Pass $trid = null to create a new translation group (primary post).
     * Pass an existing $trid to link a translated post into that group.
     * Returns the trid so callers can chain translations off the primary post.
     *
     * @param int      $post_id     WordPress post ID
     * @param string   $lang_code   WPML language code, e.g. "fr"
     * @param int|null $trid        Existing translation group ID, or null for primary
     * @param string   $source_lang Default language code (source for translations)
     * @return int  The trid (translation group ID)
     */
    private function set_wpml_post_language( int $post_id, string $lang_code, ?int $trid, string $source_lang ): int {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $post_id,
            'element_type'         => 'post_certifications-body',
            'trid'                 => $trid ?: false,
            'language_code'        => $lang_code,
            'source_language_code' => $trid ? $source_lang : null,
        ] );

        if ( ! $trid ) {
            $trid = (int) apply_filters( 'wpml_element_trid', null, $post_id, 'post_certifications-body' );
        }

        return $trid;
    }

    /**
     * Creates one translated certifications-body post and links it to the
     * primary post's WPML translation group.
     *
     * Switches language context before creation and restores it afterwards.
     * Falls back to primary post images when a translation provides none.
     *
     * @param array  $trans_data               Translation entry from JSON "translations" key
     * @param string $lang_code                Language code, e.g. "fr"
     * @param int    $trid                     Translation group ID from the primary post
     * @param string $default_lang             Default language code to restore after processing
     * @param bool   $acf_active               Whether ACF's update_field() is available
     * @param int    $fallback_featured_image_id Primary post's featured image attachment ID
     * @param int[]  $fallback_logo_ids         Primary post's logo attachment IDs (field_key => id)
     * @param int[]  $fallback_gallery_ids      Primary post's gallery attachment IDs
     * @return array  Per-translation log entry included in the REST response
     */
    private function process_cert_post_translation(
        array  $trans_data,
        string $lang_code,
        int    $trid,
        string $default_lang,
        bool   $acf_active,
        int    $fallback_featured_image_id = 0,
        array  $fallback_logo_ids          = [],
        array  $fallback_gallery_ids       = []
    ): array {
        do_action( 'wpml_switch_language', $lang_code );

        $log = [
            'lang'           => $lang_code,
            'title'          => sanitize_text_field( $trans_data['title'] ),
            'slug'           => '',
            'id'             => 0,
            'link'           => '',
            'featured_image' => null,
            'logos'          => [],
            'gallery'        => [],
            'acf_fields'     => [],
            'error'          => null,
        ];

        // -- Featured image ---------------------------------------------------
        $featured_image_id  = 0;
        $featured_image_log = null;

        if ( ! empty( $trans_data['featured_image'] ) ) {
            $img_def  = $trans_data['featured_image'];
            $existing = $this->maybe_use_existing_image( $img_def );
            if ( $existing ) {
                $featured_image_id  = $existing;
                $featured_image_log = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
            } else {
                $result = $this->upload_image_via_media_api( $img_def );
                if ( is_wp_error( $result ) ) {
                    $featured_image_log = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                } else {
                    $featured_image_id  = $result;
                    $featured_image_log = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                }
            }
        } elseif ( $fallback_featured_image_id ) {
            $featured_image_id  = $fallback_featured_image_id;
            $featured_image_log = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $fallback_featured_image_id ];
        }

        $log['featured_image'] = $featured_image_log;

        // -- Logo image fields ------------------------------------------------
        $logos_log = [];
        $logo_ids  = []; // field_key => attachment_id

        foreach ( self::CB_LOGO_IMAGE_FIELDS as $json_key => $field_key ) {
            if ( ! empty( $trans_data['acf'][ $json_key ] ) ) {
                $img_def  = $trans_data['acf'][ $json_key ];
                $existing = $this->maybe_use_existing_image( $img_def );
                if ( $existing ) {
                    $logo_ids[ $field_key ] = $existing;
                    $logos_log[ $json_key ] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
                } else {
                    $result = $this->upload_image_via_media_api( $img_def );
                    if ( is_wp_error( $result ) ) {
                        $logos_log[ $json_key ] = [ 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                    } else {
                        $logo_ids[ $field_key ] = $result;
                        $logos_log[ $json_key ] = [ 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                    }
                }
            } elseif ( isset( $fallback_logo_ids[ $field_key ] ) && $fallback_logo_ids[ $field_key ] ) {
                $logo_ids[ $field_key ] = $fallback_logo_ids[ $field_key ];
                $logos_log[ $json_key ] = [ 'uploaded' => false, 'reused' => true, 'attachment_id' => $fallback_logo_ids[ $field_key ] ];
            }
        }

        $log['logos'] = $logos_log;

        // -- Gallery images ---------------------------------------------------
        $gallery_ids = [];
        $gallery_log = [];

        if ( ! empty( $trans_data['acf']['certification_body_gallery'] ) && is_array( $trans_data['acf']['certification_body_gallery'] ) ) {
            foreach ( $trans_data['acf']['certification_body_gallery'] as $idx => $img_def ) {
                if ( ! is_array( $img_def ) ) continue;
                $existing = $this->maybe_use_existing_image( $img_def );
                if ( $existing ) {
                    $gallery_ids[] = $existing;
                    $gallery_log[] = [ 'index' => $idx, 'uploaded' => false, 'reused' => true, 'attachment_id' => $existing ];
                } else {
                    $result = $this->upload_image_via_media_api( $img_def );
                    if ( is_wp_error( $result ) ) {
                        $gallery_log[] = [ 'index' => $idx, 'uploaded' => false, 'reused' => false, 'error' => $result->get_error_message() ];
                    } else {
                        $gallery_ids[] = $result;
                        $gallery_log[] = [ 'index' => $idx, 'uploaded' => true, 'reused' => false, 'attachment_id' => $result ];
                    }
                }
            }
        } elseif ( ! empty( $fallback_gallery_ids ) ) {
            foreach ( $fallback_gallery_ids as $idx => $att_id ) {
                $gallery_ids[] = $att_id;
                $gallery_log[] = [ 'index' => $idx, 'uploaded' => false, 'reused' => true, 'attachment_id' => $att_id ];
            }
        }

        $log['gallery'] = $gallery_log;

        // -- Auto-generate slug if not provided ------------------------------
        $slug = ! empty( $trans_data['slug'] )
            ? $trans_data['slug']
            : sanitize_title( $trans_data['title'] ) . '-' . $lang_code;

        // -- Create the translated post --------------------------------------
        $post_result = $this->create_cert_post_via_rest_api(
            $trans_data['title'],
            $slug,
            $trans_data['excerpt'] ?? '',
            $featured_image_id
        );

        if ( is_wp_error( $post_result ) ) {
            $log['error'] = $post_result->get_error_message();
            do_action( 'wpml_switch_language', $default_lang );
            return $log;
        }

        $trans_post_id = (int) $post_result['id'];
        $log['id']     = $trans_post_id;
        $log['slug']   = $slug;
        $log['link']   = (string) $post_result['link'];

        // Link to the primary post's WPML translation group
        $this->set_wpml_post_language( $trans_post_id, $lang_code, $trid, $default_lang );

        // Populate ACF fields present in this translation entry
        if ( $acf_active ) {
            foreach ( $logo_ids as $field_key => $attachment_id ) {
                $log['acf_fields'][ $field_key ] = $this->set_acf_field( $field_key, $attachment_id, $trans_post_id );
            }

            if ( ! empty( $gallery_ids ) ) {
                $log['acf_fields'][ self::CB_FIELD_GALLERY ] = $this->set_acf_field( self::CB_FIELD_GALLERY, $gallery_ids, $trans_post_id );
            }

            foreach ( self::CB_ACF_SCALAR_FIELDS as $field_key => $json_key ) {
                $value = $trans_data['acf'][ $json_key ] ?? null;
                if ( $value !== null && $value !== '' ) {
                    $log['acf_fields'][ $field_key ] = $this->set_acf_field( $field_key, $value, $trans_post_id );
                }
            }
        }

        do_action( 'wpml_switch_language', $default_lang );

        return $log;
    }

    // -----------------------------------------------------------------------

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
