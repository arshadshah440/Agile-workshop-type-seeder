/**
 * Workshop Type Seeder — Admin JS  v1.4
 *
 * User selects a JSON file → client validates → sends terms array to
 * POST /wp-json/wts/v1/seed-workshop-types via the Fetch API.
 *
 * Validation rules (applied client-side; mirrored server-side):
 *   Primary term  — required: name, slug
 *   Translations  — required: name  (only checked when wtsData.wpmlActive is true)
 *   All other fields are optional and generate warnings, not errors.
 *
 * The plugin works identically on non-WPML sites: the "translations" key is
 * simply ignored when WPML is inactive.
 */
( function () {
    'use strict';

    /* -----------------------------------------------------------------------
     * DOM refs
     * --------------------------------------------------------------------- */
    const fileInput    = document.getElementById( 'wts-json-file' );
    const btn          = document.getElementById( 'wts-seed-button' );
    const validationEl = document.getElementById( 'wts-file-validation' );
    const status       = document.getElementById( 'wts-status' );

    if ( ! fileInput || ! btn || ! validationEl || ! status ) return;

    const wpmlActive = !! wtsData.wpmlActive;

    /** Holds the validated terms array after a successful file parse. */
    let parsedTerms = null;

    /* -----------------------------------------------------------------------
     * File input → parse → validate
     * --------------------------------------------------------------------- */
    fileInput.addEventListener( 'change', function () {
        parsedTerms            = null;
        btn.disabled           = true;
        status.innerHTML       = '';
        validationEl.innerHTML = '';

        const file = this.files[ 0 ];
        if ( ! file ) return;

        const reader = new FileReader();

        reader.onload = function ( e ) {
            let data;

            // 1. Parse JSON
            try {
                data = JSON.parse( e.target.result );
            } catch ( err ) {
                validationEl.innerHTML = notice( 'error',
                    '<strong>' + esc( wtsData.i18n.invalidJson ) + '</strong> ' + esc( err.message ) );
                return;
            }

            // 2. Must be a non-empty array
            if ( ! Array.isArray( data ) ) {
                validationEl.innerHTML = notice( 'error', esc( wtsData.i18n.notArray ) );
                return;
            }
            if ( data.length === 0 ) {
                validationEl.innerHTML = notice( 'error', esc( wtsData.i18n.noTerms ) );
                return;
            }

            // 3. Validate each term
            const errors   = [];
            const warnings = [];

            data.forEach( function ( term, i ) {
                const label = term.name
                    ? esc( term.name )
                    : '<em>Term #' + ( i + 1 ) + '</em>';

                // --- Required fields (block submit) ---
                const missingReq = [];
                if ( ! term.name ) missingReq.push( 'name' );
                if ( ! term.slug ) missingReq.push( 'slug' );
                if ( missingReq.length ) {
                    errors.push(
                        label + ': missing required field(s): <strong>' +
                        esc( missingReq.join( ', ' ) ) + '</strong>'
                    );
                }

                // --- WPML: translation names required ---
                if ( wpmlActive && term.translations && typeof term.translations === 'object' ) {
                    Object.keys( term.translations ).forEach( function ( langCode ) {
                        if ( ! term.translations[ langCode ].name ) {
                            errors.push(
                                label + ': translation <strong>"' + esc( langCode ) + '"</strong> — ' +
                                esc( wtsData.i18n.wpmlTransNoName )
                            );
                        }
                    } );
                }

                // --- Optional fields (warn only) ---
                const missingOpt = [];
                if ( ! term.description )                                missingOpt.push( 'description' );
                if ( ! term.images || ! term.images.featured_image )    missingOpt.push( 'images.featured_image' );
                if ( ! term.images || ! term.images.workshop_badge )    missingOpt.push( 'images.workshop_badge' );
                if ( ! term.acf    || ! term.acf.workshop_description ) missingOpt.push( 'acf.workshop_description' );
                if ( ! term.acf    || ! term.acf.workshop_tagline )     missingOpt.push( 'acf.workshop_tagline' );
                if ( ! term.acf    || ! term.acf.abbreviation )         missingOpt.push( 'acf.abbreviation' );
                if ( missingOpt.length ) {
                    warnings.push( label + ': ' + esc( missingOpt.join( ', ' ) ) );
                }

                // --- WPML: warn about optional fields missing in translations ---
                if ( wpmlActive && term.translations && typeof term.translations === 'object' ) {
                    Object.entries( term.translations ).forEach( function ( [ langCode, trans ] ) {
                        const missingTransOpt = [];
                        if ( ! trans.description )                               missingTransOpt.push( 'description' );
                        if ( ! trans.images || ! trans.images.featured_image )  missingTransOpt.push( 'images.featured_image' );
                        if ( ! trans.images || ! trans.images.workshop_badge )  missingTransOpt.push( 'images.workshop_badge' );
                        if ( ! trans.acf    || ! trans.acf.workshop_description ) missingTransOpt.push( 'acf.workshop_description' );
                        if ( ! trans.acf    || ! trans.acf.workshop_tagline )    missingTransOpt.push( 'acf.workshop_tagline' );
                        if ( ! trans.acf    || ! trans.acf.abbreviation )        missingTransOpt.push( 'acf.abbreviation' );
                        if ( missingTransOpt.length ) {
                            warnings.push(
                                label + ' [' + esc( langCode ) + ']: ' +
                                esc( missingTransOpt.join( ', ' ) )
                            );
                        }
                    } );
                }
            } );

            // Errors block submit
            if ( errors.length ) {
                validationEl.innerHTML = notice( 'error',
                    '<strong>' + esc( wtsData.i18n.validFailed ) + '</strong>' +
                    '<ul style="margin:6px 0 0 18px">' +
                    errors.map( function ( e ) { return '<li>' + e + '</li>'; } ).join( '' ) +
                    '</ul>'
                );
                return;
            }

            // Count translations across all terms
            let totalTranslations = 0;
            if ( wpmlActive ) {
                data.forEach( function ( term ) {
                    if ( term.translations && typeof term.translations === 'object' ) {
                        totalTranslations += Object.keys( term.translations ).length;
                    }
                } );
            }

            // All required fields present — enable submit
            parsedTerms  = data;
            btn.disabled = false;

            let summaryLine = '<strong>' + esc( String( data.length ) ) + ' ' +
                              esc( wtsData.i18n.readyToImport ) + '</strong>';
            if ( wpmlActive && totalTranslations > 0 ) {
                summaryLine += ' <span style="color:#555">(' + totalTranslations +
                               ' translation' + ( totalTranslations !== 1 ? 's' : '' ) + ')</span>';
            }
            let html = notice( 'success', summaryLine );

            if ( warnings.length ) {
                html += notice( 'warning',
                    '<strong>' + esc( wtsData.i18n.missingOptional ) + '</strong>' +
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
    btn.addEventListener( 'click', async function handleClick() {

        if ( ! parsedTerms ) return;
        if ( ! window.confirm( wtsData.i18n.confirm ) ) return;

        setLoading( true );
        status.innerHTML = notice( 'info', '<em>' + esc( wtsData.i18n.seeding ) + '</em>' );

        try {
            const response = await fetch( wtsData.restBase + 'seed-workshop-types', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   wtsData.nonce,
                },
                body: JSON.stringify( { terms: parsedTerms } ),
            } );

            const data = await response.json();

            if ( ! response.ok ) {
                if ( data.data && data.data.errors ) {
                    const errList = data.data.errors.map( function ( e ) {
                        return '<li><strong>' + esc( e.term ) + '</strong>: ' + esc( e.message ) + '</li>';
                    } ).join( '' );
                    status.innerHTML = notice( 'error',
                        '<strong>' + esc( wtsData.i18n.errPfx ) + ' ' + esc( data.message ) + '</strong>' +
                        '<ul style="margin:6px 0 0 18px">' + errList + '</ul>'
                    );
                } else {
                    const msg = data.message || ( 'HTTP ' + response.status );
                    status.innerHTML = notice( 'error', esc( wtsData.i18n.errPfx ) + ' ' + esc( msg ) );
                }
                return;
            }

            status.innerHTML = renderResults( data );

        } catch ( err ) {
            status.innerHTML = notice( 'error', esc( wtsData.i18n.errPfx ) + ' ' + esc( err.message ) );
        } finally {
            setLoading( false );
        }
    } );

    /* -----------------------------------------------------------------------
     * Render the full results
     * --------------------------------------------------------------------- */
    function renderResults( data ) {
        let html = '';

        // Top-level outcome banner
        if ( data.success && ( ! data.errors || data.errors.length === 0 ) ) {
            html += notice( 'success',
                '<strong>' + esc( wtsData.i18n.done ) + '</strong> ' +
                'All terms and images were created successfully.'
            );
        } else {
            html += notice( 'warning',
                '<strong>Partial success.</strong> ' +
                'Some terms could not be created — see the Errors section below.'
            );
        }

        if ( ! data.acf_active ) {
            html += notice( 'warning',
                '<strong>ACF not active:</strong> Terms and images were created but ' +
                'ACF fields were not populated.'
            );
        }

        // Created terms table
        if ( data.created && data.created.length ) {
            const showWpml = !! data.wpml_active;

            html += '<h3 style="margin-top:20px">Created Terms (' + data.created.length + ')</h3>';
            html += '<table class="wp-list-table widefat fixed striped" style="border-collapse:collapse">';
            html += '<thead><tr>' +
                    '<th style="width:45px">ID</th>' +
                    '<th>Name</th>' +
                    '<th style="width:120px">Slug</th>' +
                    '<th style="width:175px">Images</th>' +
                    '<th style="width:175px">Import Log</th>' +
                    '<th>ACF Fields</th>' +
                    ( showWpml ? '<th style="width:200px">Translations</th>' : '' ) +
                    '</tr></thead><tbody>';

            data.created.forEach( function ( term ) {
                html += '<tr>' +
                    '<td>' + esc( String( term.id ) ) + '</td>' +
                    '<td><a href="' + esc( term.link ) + '" target="_blank" rel="noopener">' +
                        esc( term.name ) + '</a></td>' +
                    '<td><code>' + esc( term.slug ) + '</code></td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildImageCell( term.images ) + '</td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildMissingCell( term.missing_fields ) + '</td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildAcfCell( term.acf_fields, term.acf_active ) + '</td>' +
                    ( showWpml
                        ? '<td style="font-size:12px;line-height:1.8">' + buildTranslationsCell( term.translations, term.trid ) + '</td>'
                        : '' ) +
                    '</tr>';
            } );

            html += '</tbody></table>';
        }

        // Errors table
        if ( data.errors && data.errors.length ) {
            html += '<h3 style="margin-top:20px;color:#b32d2e">Errors (' + data.errors.length + ')</h3>';
            html += '<table class="wp-list-table widefat fixed striped">';
            html += '<thead><tr><th>Term</th><th>Error</th></tr></thead><tbody>';
            data.errors.forEach( function ( err ) {
                html += '<tr>' +
                    '<td><strong>' + esc( err.term ) + '</strong></td>' +
                    '<td>' + esc( err.message ) + '</td>' +
                    '</tr>';
            } );
            html += '</tbody></table>';
        }

        return html;
    }

    /* -----------------------------------------------------------------------
     * WPML translations cell
     * --------------------------------------------------------------------- */
    function buildTranslationsCell( translations, trid ) {
        if ( ! translations || ! Object.keys( translations ).length ) {
            return '<em style="color:#999">None in JSON</em>';
        }

        const tridLabel = trid
            ? ' <span style="color:#888;font-size:11px">(trid: ' + trid + ')</span>'
            : '';

        return Object.entries( translations ).map( function ( [ lang, t ] ) {
            if ( t.error ) {
                return '<span style="color:#b32d2e">&#10007; <strong>' + esc( lang ) + '</strong>: ' +
                       esc( t.error ) + '</span>';
            }
            const idPart = t.id
                ? ' <span style="color:#555">(ID: ' + t.id + ')</span>'
                : '';
            return '<span style="color:#2a9d2a">&#10003; <strong>' + esc( lang.toUpperCase() ) + '</strong>' +
                   ' ' + esc( t.name ) + idPart + '</span>';
        } ).join( '<br>' ) + tridLabel;
    }

    /* -----------------------------------------------------------------------
     * Import log cell — missing optional fields
     * --------------------------------------------------------------------- */
    function buildMissingCell( missingFields ) {
        if ( ! missingFields || missingFields.length === 0 ) {
            return '<span style="color:#2a9d2a">&#10003; All optional fields present</span>';
        }
        return '<span style="color:#b07d00">&#9888; Missing optional:<br>' +
            missingFields.map( function ( f ) {
                return '<span style="color:#555;font-style:italic">' + esc( f ) + '</span>';
            } ).join( '<br>' ) + '</span>';
    }

    /* -----------------------------------------------------------------------
     * Image upload status cell
     * --------------------------------------------------------------------- */
    function buildImageCell( images ) {
        if ( ! images || ! Object.keys( images ).length ) {
            return '<em style="color:#999">No images</em>';
        }

        const labels = { featured_image: 'Featured', workshop_badge: 'Badge' };

        return Object.entries( images ).map( function ( [ key, info ] ) {
            const label = labels[ key ] || key;
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
        } ).join( '<br>' );
    }

    /* -----------------------------------------------------------------------
     * ACF field status cell
     * --------------------------------------------------------------------- */
    const FIELD_LABELS = {
        field_695645eac1f1c: 'featured_image',
        field_69564622c1f1d: 'workshop_badge',
        field_695798931d1f9: 'workshop_description',
        field_69596558ec5b6: 'workshop_tagline',
        field_6959662681c4c: 'certification_body',
        field_695966d7807ed: 'abbreviation',
    };

    function buildAcfCell( acfFields, acfActive ) {
        if ( ! acfActive ) {
            return '<em style="color:#996">ACF inactive</em>';
        }
        if ( ! acfFields || ! Object.keys( acfFields ).length ) {
            return '<em>No fields recorded</em>';
        }

        return Object.entries( acfFields ).map( function ( [ key, info ] ) {
            const label  = FIELD_LABELS[ key ] || key;
            const icon   = info.updated ? '&#10003;' : '&#10007;';
            const colour = info.updated ? '#2a9d2a'  : '#b32d2e';
            const val    = info.value !== '' && info.value !== null && info.value !== undefined
                ? ' <span style="color:#555">(' + esc( truncate( String( info.value ), 35 ) ) + ')</span>'
                : '';
            return '<span style="color:' + colour + '">' + icon +
                   ' <strong>' + esc( label ) + '</strong>' + val + '</span>';
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
