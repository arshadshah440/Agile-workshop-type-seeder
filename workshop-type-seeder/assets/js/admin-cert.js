/**
 * Workshop Type Seeder — Certification Body Admin JS  v1.1
 *
 * User selects a JSON file → client validates (title + slug required, WPML
 * translations require title) → sends posts array to
 * POST /wp-json/wts/v1/seed-certification-bodies.
 *
 * Optional fields: excerpt, featured_image, all acf.* fields, gallery (array).
 * Missing optional fields are warned pre-submit and logged post-import.
 */
( function () {
    'use strict';

    /* -----------------------------------------------------------------------
     * DOM refs
     * --------------------------------------------------------------------- */
    const fileInput    = document.getElementById( 'wts-cert-json-file' );
    const btn          = document.getElementById( 'wts-cert-seed-button' );
    const validationEl = document.getElementById( 'wts-cert-file-validation' );
    const status       = document.getElementById( 'wts-cert-status' );

    if ( ! fileInput || ! btn || ! validationEl || ! status ) return;

    const LOGO_FIELDS = [
        'certification_body_logo',
        'certification_body_logo_with_text',
        'certification_body_logo_initials',
        'certification_body_watermark',
    ];

    const SCALAR_FIELDS = [
        'certification_body_aj_id',
        'certification_body_abbreviation',
        'certification_body_slogan',
        'certification_body_website_url',
        'certification_body_description',
    ];

    const WPML_ACTIVE = !! ( wtsCertData.wpmlActive );

    /** Holds the validated posts array after a successful file parse. */
    let parsedPosts = null;

    /* -----------------------------------------------------------------------
     * File input → parse → validate
     * --------------------------------------------------------------------- */
    fileInput.addEventListener( 'change', function () {
        parsedPosts            = null;
        btn.disabled           = true;
        status.innerHTML       = '';
        validationEl.innerHTML = '';

        const file = this.files[ 0 ];
        if ( ! file ) return;

        const reader = new FileReader();

        reader.onload = function ( e ) {
            let data;

            try {
                data = JSON.parse( e.target.result );
            } catch ( err ) {
                validationEl.innerHTML = notice( 'error',
                    '<strong>' + esc( wtsCertData.i18n.invalidJson ) + '</strong> ' + esc( err.message ) );
                return;
            }

            if ( ! Array.isArray( data ) ) {
                validationEl.innerHTML = notice( 'error', esc( wtsCertData.i18n.notArray ) );
                return;
            }
            if ( data.length === 0 ) {
                validationEl.innerHTML = notice( 'error', esc( wtsCertData.i18n.noItems ) );
                return;
            }

            const errors   = [];
            const warnings = [];

            data.forEach( function ( post, i ) {
                const label = post.title
                    ? esc( post.title )
                    : '<em>Entry #' + ( i + 1 ) + '</em>';

                // --- Required fields (block submit) ---
                const missingReq = [];
                if ( ! post.title ) missingReq.push( 'title' );
                if ( ! post.slug )  missingReq.push( 'slug' );
                if ( missingReq.length ) {
                    errors.push(
                        label + ': missing required field(s): <strong>' +
                        esc( missingReq.join( ', ' ) ) + '</strong>'
                    );
                }

                // --- WPML: translations must have title ---
                if ( WPML_ACTIVE && post.translations && typeof post.translations === 'object' ) {
                    Object.keys( post.translations ).forEach( function ( langCode ) {
                        const trans = post.translations[ langCode ];
                        if ( ! trans || ! trans.title ) {
                            errors.push(
                                label + ' [' + esc( langCode ) + ']: ' +
                                esc( wtsCertData.i18n.wpmlTransNoTitle )
                            );
                        }
                    } );
                }

                // --- Optional fields (warn only) ---
                const missingOpt = [];
                if ( ! post.excerpt )        missingOpt.push( 'excerpt' );
                if ( ! post.featured_image ) missingOpt.push( 'featured_image' );

                const acf = post.acf || {};
                LOGO_FIELDS.forEach( function ( f ) {
                    if ( ! acf[ f ] ) missingOpt.push( 'acf.' + f );
                } );
                SCALAR_FIELDS.forEach( function ( f ) {
                    const v = acf[ f ];
                    if ( v === undefined || v === null || v === '' ) {
                        missingOpt.push( 'acf.' + f );
                    }
                } );
                if ( ! acf.certification_body_gallery || ! acf.certification_body_gallery.length ) {
                    missingOpt.push( 'acf.certification_body_gallery' );
                }

                if ( missingOpt.length ) {
                    warnings.push( label + ': ' + esc( missingOpt.join( ', ' ) ) );
                }

                // --- WPML: warn about missing optional fields in translations ---
                if ( WPML_ACTIVE && post.translations && typeof post.translations === 'object' ) {
                    const transWarnings = [];
                    Object.keys( post.translations ).forEach( function ( langCode ) {
                        const trans = post.translations[ langCode ];
                        if ( ! trans || ! trans.title ) return; // already an error
                        const missingTransOpt = [];
                        if ( ! trans.excerpt )        missingTransOpt.push( 'excerpt' );
                        if ( ! trans.featured_image ) missingTransOpt.push( 'featured_image' );
                        const tacf = trans.acf || {};
                        LOGO_FIELDS.forEach( function ( f ) {
                            if ( ! tacf[ f ] ) missingTransOpt.push( 'acf.' + f );
                        } );
                        if ( ! tacf.certification_body_gallery || ! tacf.certification_body_gallery.length ) {
                            missingTransOpt.push( 'acf.certification_body_gallery' );
                        }
                        if ( missingTransOpt.length ) {
                            transWarnings.push( '[' + esc( langCode ) + ']: ' + esc( missingTransOpt.join( ', ' ) ) );
                        }
                    } );
                    if ( transWarnings.length ) {
                        warnings.push(
                            label + ' — ' + esc( wtsCertData.i18n.wpmlMissingOptional ) +
                            '<ul style="margin:4px 0 0 18px">' +
                            transWarnings.map( function ( w ) { return '<li>' + w + '</li>'; } ).join( '' ) +
                            '</ul>'
                        );
                    }
                }
            } );

            if ( errors.length ) {
                validationEl.innerHTML = notice( 'error',
                    '<strong>' + esc( wtsCertData.i18n.validFailed ) + '</strong>' +
                    '<ul style="margin:6px 0 0 18px">' +
                    errors.map( function ( e ) { return '<li>' + e + '</li>'; } ).join( '' ) +
                    '</ul>'
                );
                return;
            }

            parsedPosts  = data;
            btn.disabled = false;

            // Count translations
            let transCount = 0;
            if ( WPML_ACTIVE ) {
                data.forEach( function ( post ) {
                    if ( post.translations && typeof post.translations === 'object' ) {
                        transCount += Object.keys( post.translations ).length;
                    }
                } );
            }

            let html = notice( 'success',
                '<strong>' + esc( String( data.length ) ) + ' ' +
                esc( wtsCertData.i18n.readyToImport ) + '</strong>' +
                ( transCount ? ' <span style="color:#666">(' + transCount + ' translation(s))</span>' : '' )
            );

            if ( warnings.length ) {
                html += notice( 'warning',
                    '<strong>' + esc( wtsCertData.i18n.missingOptional ) + '</strong>' +
                    '<ul style="margin:6px 0 0 18px">' +
                    warnings.map( function ( w ) { return '<li>' + w + '</li>'; } ).join( '' ) +
                    '</ul>'
                );
            }

            validationEl.innerHTML = html;
        };

        reader.readAsText( file );
    } );

    /* -----------------------------------------------------------------------
     * Submit button → seed
     * --------------------------------------------------------------------- */
    btn.addEventListener( 'click', async function () {

        if ( ! parsedPosts ) return;
        if ( ! window.confirm( wtsCertData.i18n.confirm ) ) return;

        setLoading( true );
        status.innerHTML = notice( 'info', '<em>' + esc( wtsCertData.i18n.seeding ) + '</em>' );

        try {
            const response = await fetch( wtsCertData.restBase + 'seed-certification-bodies', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   wtsCertData.nonce,
                },
                body: JSON.stringify( { posts: parsedPosts } ),
            } );

            const data = await response.json();

            if ( ! response.ok ) {
                if ( data.data && data.data.errors ) {
                    const errList = data.data.errors.map( function ( e ) {
                        return '<li><strong>' + esc( e.post ) + '</strong>: ' + esc( e.message ) + '</li>';
                    } ).join( '' );
                    status.innerHTML = notice( 'error',
                        '<strong>' + esc( wtsCertData.i18n.errPfx ) + ' ' + esc( data.message ) + '</strong>' +
                        '<ul style="margin:6px 0 0 18px">' + errList + '</ul>'
                    );
                } else {
                    const msg = data.message || ( 'HTTP ' + response.status );
                    status.innerHTML = notice( 'error', esc( wtsCertData.i18n.errPfx ) + ' ' + esc( msg ) );
                }
                return;
            }

            status.innerHTML = renderResults( data );

        } catch ( err ) {
            status.innerHTML = notice( 'error', esc( wtsCertData.i18n.errPfx ) + ' ' + esc( err.message ) );
        } finally {
            setLoading( false );
        }
    } );

    /* -----------------------------------------------------------------------
     * Render the full results
     * --------------------------------------------------------------------- */
    function renderResults( data ) {
        let html = '';

        if ( data.success && ( ! data.errors || ! data.errors.length ) ) {
            html += notice( 'success',
                '<strong>' + esc( wtsCertData.i18n.done ) + '</strong> ' +
                'All certification bodies were created successfully.'
            );
        } else {
            html += notice( 'warning',
                '<strong>Partial success.</strong> ' +
                'Some entries could not be created — see the Errors section below.'
            );
        }

        if ( ! data.acf_active ) {
            html += notice( 'warning',
                '<strong>ACF not active:</strong> Posts were created but ACF fields were not populated.'
            );
        }

        if ( data.created && data.created.length ) {
            const showWpml = !! data.wpml_active;

            html += '<h3 style="margin-top:20px">Created Certification Bodies (' + data.created.length + ')</h3>';
            html += '<table class="wp-list-table widefat fixed striped" style="border-collapse:collapse">';
            html += '<thead><tr>' +
                '<th style="width:45px">ID</th>' +
                '<th>Title</th>' +
                '<th style="width:130px">Slug</th>' +
                '<th style="width:140px">Featured Image</th>' +
                '<th style="width:160px">Logos</th>' +
                '<th style="width:110px">Gallery</th>' +
                '<th style="width:155px">Import Log</th>' +
                '<th>ACF Fields</th>' +
                ( showWpml ? '<th style="width:200px">Translations</th>' : '' ) +
                '</tr></thead><tbody>';

            data.created.forEach( function ( post ) {
                html += '<tr>' +
                    '<td>' + esc( String( post.id ) ) + '</td>' +
                    '<td><a href="' + esc( post.link ) + '" target="_blank" rel="noopener">' + esc( post.title ) + '</a></td>' +
                    '<td><code>' + esc( post.slug ) + '</code></td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildSingleImageCell( post.featured_image, 'Featured' ) + '</td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildLogosCell( post.logos ) + '</td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildGalleryCell( post.gallery ) + '</td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildMissingCell( post.missing_fields ) + '</td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildAcfCell( post.acf_fields, post.acf_active ) + '</td>' +
                    ( showWpml ? '<td style="font-size:12px;line-height:1.8">' + buildTranslationsCell( post.translations ) + '</td>' : '' ) +
                    '</tr>';
            } );

            html += '</tbody></table>';
        }

        if ( data.errors && data.errors.length ) {
            html += '<h3 style="margin-top:20px;color:#b32d2e">Errors (' + data.errors.length + ')</h3>';
            html += '<table class="wp-list-table widefat fixed striped">';
            html += '<thead><tr><th>Entry</th><th>Error</th></tr></thead><tbody>';
            data.errors.forEach( function ( err ) {
                html += '<tr>' +
                    '<td><strong>' + esc( err.post ) + '</strong></td>' +
                    '<td>' + esc( err.message ) + '</td>' +
                    '</tr>';
            } );
            html += '</tbody></table>';
        }

        return html;
    }

    /* -----------------------------------------------------------------------
     * Featured image cell (single image)
     * --------------------------------------------------------------------- */
    function buildSingleImageCell( info, label ) {
        if ( ! info ) return '<em style="color:#999">None</em>';
        if ( info.reused ) {
            return '<span style="color:#0073aa">&#8617; ' + esc( label ) +
                   ' <span style="color:#555">(ID: ' + info.attachment_id + ', reused)</span></span>';
        }
        if ( info.uploaded ) {
            return '<span style="color:#2a9d2a">&#10003; ' + esc( label ) +
                   ' <span style="color:#555">(ID: ' + info.attachment_id + ')</span></span>';
        }
        return '<span style="color:#b32d2e">&#10007; ' + esc( label ) +
               ' <span style="color:#555">(' + esc( info.error || 'failed' ) + ')</span></span>';
    }

    /* -----------------------------------------------------------------------
     * Logos cell (logo, logo_with_text, logo_initials, watermark)
     * --------------------------------------------------------------------- */
    const LOGO_LABELS = {
        certification_body_logo:           'Logo',
        certification_body_logo_with_text: 'Logo+Text',
        certification_body_logo_initials:  'Initials',
        certification_body_watermark:      'Watermark',
    };

    function buildLogosCell( logos ) {
        if ( ! logos || ! Object.keys( logos ).length ) {
            return '<em style="color:#999">No logos</em>';
        }
        return Object.entries( logos ).map( function ( [ key, info ] ) {
            const label = LOGO_LABELS[ key ] || key;
            if ( info.reused ) {
                return '<span style="color:#0073aa">&#8617; ' + esc( label ) +
                       ' <span style="color:#555">(ID: ' + info.attachment_id + ')</span></span>';
            }
            if ( info.uploaded ) {
                return '<span style="color:#2a9d2a">&#10003; ' + esc( label ) +
                       ' <span style="color:#555">(ID: ' + info.attachment_id + ')</span></span>';
            }
            return '<span style="color:#b32d2e">&#10007; ' + esc( label ) +
                   ' <span style="color:#555">(' + esc( info.error || 'failed' ) + ')</span></span>';
        } ).join( '<br>' );
    }

    /* -----------------------------------------------------------------------
     * Gallery cell — compact summary
     * --------------------------------------------------------------------- */
    function buildGalleryCell( gallery ) {
        if ( ! gallery || ! gallery.length ) {
            return '<em style="color:#999">No gallery</em>';
        }
        let uploaded = 0, reused = 0, failed = 0;
        gallery.forEach( function ( item ) {
            if ( item.reused )        reused++;
            else if ( item.uploaded ) uploaded++;
            else                      failed++;
        } );
        const parts = [];
        if ( uploaded ) parts.push( '<span style="color:#2a9d2a">&#10003; ' + uploaded + ' uploaded</span>' );
        if ( reused )   parts.push( '<span style="color:#0073aa">&#8617; ' + reused + ' reused</span>' );
        if ( failed )   parts.push( '<span style="color:#b32d2e">&#10007; ' + failed + ' failed</span>' );
        return parts.join( '<br>' );
    }

    /* -----------------------------------------------------------------------
     * Import log cell — missing optional fields
     * --------------------------------------------------------------------- */
    function buildMissingCell( missingFields ) {
        if ( ! missingFields || ! missingFields.length ) {
            return '<span style="color:#2a9d2a">&#10003; All optional fields present</span>';
        }
        return '<span style="color:#b07d00">&#9888; Missing optional:<br>' +
            missingFields.map( function ( f ) {
                return '<span style="color:#555;font-style:italic">' + esc( f ) + '</span>';
            } ).join( '<br>' ) + '</span>';
    }

    /* -----------------------------------------------------------------------
     * ACF field status cell
     * --------------------------------------------------------------------- */
    const FIELD_LABELS_CERT = {
        field_69278863dbe63: 'aj_id',
        field_692788acdbe64: 'abbreviation',
        field_693a765d93403: 'logo',
        field_6927892bdbe66: 'logo_with_text',
        field_69278948dbe67: 'logo_initials',
        field_6927896edbe68: 'watermark',
        field_69278982dbe69: 'slogan',
        field_692788f4dbe65: 'website_url',
        field_693a768c373ff: 'gallery',
        field_693a776cfff6c: 'description',
    };

    function buildAcfCell( acfFields, acfActive ) {
        if ( ! acfActive ) return '<em style="color:#996">ACF inactive</em>';
        if ( ! acfFields || ! Object.keys( acfFields ).length ) return '<em>No fields recorded</em>';
        return Object.entries( acfFields ).map( function ( [ key, info ] ) {
            const label  = FIELD_LABELS_CERT[ key ] || key;
            const icon   = info.updated ? '&#10003;' : '&#10007;';
            const colour = info.updated ? '#2a9d2a'  : '#b32d2e';
            const val    = info.value !== '' && info.value !== null && info.value !== undefined
                ? ' <span style="color:#555">(' + esc( truncate( String( info.value ), 30 ) ) + ')</span>'
                : '';
            return '<span style="color:' + colour + '">' + icon +
                   ' <strong>' + esc( label ) + '</strong>' + val + '</span>';
        } ).join( '<br>' );
    }

    /* -----------------------------------------------------------------------
     * Translations cell — one row per language
     * --------------------------------------------------------------------- */
    function buildTranslationsCell( translations ) {
        if ( ! translations || ! Object.keys( translations ).length ) {
            return '<em style="color:#999">None</em>';
        }
        return Object.entries( translations ).map( function ( [ lang, t ] ) {
            if ( t.error ) {
                return '<span style="color:#b32d2e"><strong>[' + esc( lang ) + ']</strong> &#10007; ' +
                       esc( t.error ) + '</span>';
            }
            const imgParts = [];
            if ( t.featured_image ) {
                imgParts.push( buildSingleImageCell( t.featured_image, 'FI' ) );
            }
            if ( t.logos && Object.keys( t.logos ).length ) {
                let lu = 0, lr = 0, lf = 0;
                Object.values( t.logos ).forEach( function ( info ) {
                    if ( info.reused )        lr++;
                    else if ( info.uploaded ) lu++;
                    else                      lf++;
                } );
                const lParts = [];
                if ( lu ) lParts.push( '<span style="color:#2a9d2a">' + lu + '&#10003;</span>' );
                if ( lr ) lParts.push( '<span style="color:#0073aa">' + lr + '&#8617;</span>' );
                if ( lf ) lParts.push( '<span style="color:#b32d2e">' + lf + '&#10007;</span>' );
                imgParts.push( 'Logos: ' + lParts.join( ' ' ) );
            }
            const postLink = t.link
                ? '<a href="' + esc( t.link ) + '" target="_blank" rel="noopener">' + esc( t.title ) + '</a>'
                : esc( t.title );
            return '<strong>[' + esc( lang ) + ']</strong> ' + postLink +
                   ( imgParts.length ? '<br><span style="font-size:11px">' + imgParts.join( ' · ' ) + '</span>' : '' );
        } ).join( '<br>' );
    }

    /* -----------------------------------------------------------------------
     * Utilities
     * --------------------------------------------------------------------- */
    function notice( type, content ) {
        return '<div class="notice notice-' + type + ' inline" style="padding:10px 14px;margin:8px 0">' +
               '<p>' + content + '</p></div>';
    }

    function esc( str ) {
        const div = document.createElement( 'div' );
        div.textContent = String( str );
        return div.innerHTML;
    }

    function truncate( str, max ) {
        return str.length > max ? str.slice( 0, max ) + '…' : str;
    }

    function setLoading( loading ) {
        btn.disabled       = loading;
        btn.style.cursor   = loading ? 'wait' : '';
        fileInput.disabled = loading;
    }

}() );
