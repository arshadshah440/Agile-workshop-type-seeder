/**
 * Workshop Type Seeder — Admin JS  v1.1
 *
 * Calls POST /wp-json/wts/v1/seed-workshop-types via the Fetch API.
 * Authentication uses the WP REST nonce supplied via wp_localize_script().
 *
 * Per-term flow (handled server-side, reported here):
 *   1. Upload featured_image  → /wp/v2/media  → attachment ID
 *   2. Upload workshop_badge  → /wp/v2/media  → attachment ID
 *   3. Create taxonomy term   → /wp/v2/workshop-type
 *   4. Set all ACF fields
 */
( function () {
    'use strict';

    /* -----------------------------------------------------------------------
     * DOM refs
     * --------------------------------------------------------------------- */
    const btn    = document.getElementById( 'wts-seed-button' );
    const status = document.getElementById( 'wts-status' );

    if ( ! btn || ! status ) return;

    /* -----------------------------------------------------------------------
     * Button click handler
     * --------------------------------------------------------------------- */
    btn.addEventListener( 'click', async function handleClick() {

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
                body: JSON.stringify( {} ),
            } );

            const data = await response.json();

            if ( ! response.ok ) {
                const msg = data.message || ( 'HTTP ' + response.status );
                status.innerHTML = notice( 'error', esc( wtsData.i18n.errPfx ) + ' ' + esc( msg ) );
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
                'All terms and images were created successfully.' );
        } else {
            html += notice( 'warning',
                '<strong>Partial success.</strong> ' +
                'Some terms could not be created — see the Errors section below.' );
        }

        if ( ! data.acf_active ) {
            html += notice( 'warning',
                '<strong>ACF not active:</strong> Terms and images were created but ' +
                'ACF fields were not populated. Activate Advanced Custom Fields and re-seed.' );
        }

        // Created terms table
        if ( data.created && data.created.length ) {
            html += '<h3 style="margin-top:20px">Created Terms (' + data.created.length + ')</h3>';
            html += '<table class="wp-list-table widefat fixed striped" style="border-collapse:collapse">';
            html += '<thead><tr>' +
                    '<th style="width:50px">ID</th>' +
                    '<th>Name</th>' +
                    '<th style="width:150px">Slug</th>' +
                    '<th style="width:220px">Images</th>' +
                    '<th>ACF Fields</th>' +
                    '</tr></thead><tbody>';

            data.created.forEach( function ( term ) {
                html += '<tr>' +
                    '<td>' + esc( String( term.id ) ) + '</td>' +
                    '<td><a href="' + esc( term.link ) + '" target="_blank" rel="noopener">' + esc( term.name ) + '</a></td>' +
                    '<td><code>' + esc( term.slug ) + '</code></td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildImageCell( term.images ) + '</td>' +
                    '<td style="font-size:12px;line-height:1.8">' + buildAcfCell( term.acf_fields, term.acf_active ) + '</td>' +
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
     * Image upload status cell
     * --------------------------------------------------------------------- */
    function buildImageCell( images ) {
        if ( ! images || ! Object.keys( images ).length ) {
            return '<em style="color:#999">No images</em>';
        }

        const labels = { featured_image: 'Featured', workshop_badge: 'Badge' };

        return Object.entries( images ).map( function ( [ key, info ] ) {
            const label = labels[ key ] || key;
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
            const colour = info.updated ? '#2a9d2a' : '#b32d2e';
            const val    = info.value !== '' && info.value !== null && info.value !== undefined
                ? ' <span style="color:#555">(' + esc( truncate( String( info.value ), 35 ) ) + ')</span>'
                : '';

            return '<span style="color:' + colour + '">' + icon + ' <strong>' + esc( label ) + '</strong>' + val + '</span>';
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
        btn.disabled     = loading;
        btn.style.cursor = loading ? 'wait' : '';
    }

}() );
