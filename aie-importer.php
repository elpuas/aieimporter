<?php
/**
 * Plugin Name: AIE Costa Rica Importer (Literal Keys)
 * Description: WP-CLI command to import Albums (fonograma) and Singles (sencillo) from XLSX,
 *              mapping ACF fields by literal field_key strings, nested repeaters, and assigning the author.
 * Version:     1.0.4
 * Author:      ElPuas DigitalCrafts
 * Text Domain: aie-importer
 */

require_once __DIR__ . '/src/Services/ImporterService.php';
require_once __DIR__ . '/src/Services/LoggerService.php';

// Load PhpSpreadsheet only when running via WP-CLI to avoid unnecessary overhead in wp-admin.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/vendor/autoload.php';

    WP_CLI::add_command( 'aie import', function( $args ) {
        list( $file ) = $args;

        // 1) Validate file path
        if ( ! file_exists( $file ) ) {
            WP_CLI::error( "File not found: {$file}" );
        }

        // 2) Load spreadsheet into rows array
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $file );
        $rows        = $spreadsheet->getActiveSheet()->toArray( null, true, true, true );

        // 3) Define static column map (adjust letters if your sheet differs)
        $col = [
            'idAlbum'             => 'L',
            'title'               => 'K',
            'tipo'                => 'I',
            'label'               => 'C',
            'year'                => 'P',
            'artistName'          => 'N',
            'trackTitle'          => 'B',
            'trackNumber'         => 'A',
            'trackISRC'           => 'M',
            'trackDuration'       => 'R',
            'bmat'                => 'E', // ID_BMAT (column E per sheet map)
            'cedula'              => 'G',
            'performerName'       => 'F',
            'performerInstrument' => 'J',
            'performerRole'       => 'H',
        ];

        // 4) Group rows by ID_ALBUM
        $groups = [];
        foreach ( $rows as $i => $r ) {
            if ( $i === 1 ) continue; // skip header
            $aid = trim( $r[ $col['idAlbum'] ] );
            if ( ! $aid ) {
                WP_CLI::warning( "Row {$i}: empty ID_ALBUM" );
                continue;
            }
            $groups[ $aid ][] = $r;
        }

        // 5) Process each group
        foreach ( $groups as $aid => $items ) {
            $first = reset( $items );

            // a) Determine CPT based on ALBUM column
            $album_empty_in_group = true;
            foreach ( $items as $r ) {
                $album_val = trim( (string) $r[ $col['title'] ] );
                if ( '' !== $album_val ) {
                    $album_empty_in_group = false;
                    break;
                }
            }

            $post_type = $album_empty_in_group ? 'sencillo' : 'fonograma';

            // b) Find main user by cédula
            $ced = trim( $first[ $col['cedula'] ] );
            $found = get_users([
                'meta_key'   => 'numero_de_identificacion',
                'meta_value' => $ced,
                'number'     => 1,
            ]);
            $main_user = ! empty( $found ) ? $found[0] : null;

            // c) Create post with author if found
            $post_title = trim( $first[ $col['title'] ] );
            if ( '' === $post_title ) {
                foreach ( $items as $row ) {
                    $candidate = trim( (string) $row[ $col['trackTitle'] ] );
                    if ( '' !== $candidate ) {
                        $post_title = $candidate;
                        break;
                    }
                }
            }

            $post_args = [
                'post_type'   => $post_type,
                'post_title'  => $post_title,
                'post_status' => 'publish',
            ];
            if ( $main_user ) {
                $post_args['post_author'] = $main_user->ID;
            }
            $post_id = wp_insert_post( $post_args );
            if ( is_wp_error( $post_id ) ) {
                WP_CLI::warning( "ID_ALBUM {$aid}: failed to create post" );
                continue;
            }

            // d) Update simple ACF fields by literal field_keys
            // Fonograma keys
            $keys_fono = [
                'label'   => 'field_64f512aa8521e',
                'year'    => 'field_64f52628b2990',
                'artist'  => 'field_64f512828521d',
                'pubtype' => 'field_64f5263cb2991',
            ];
            // Sencillo keys
            $keys_senc = [
                'label'   => 'field_65fb86103e5db',
                'year'    => 'field_65fb86103e5df',
                'artist'  => 'field_65fb86103e5d8',
                'pubtype' => 'field_65fb86103e5e3',
            ];
            $keys = ( 'fonograma' === $post_type ) ? $keys_fono : $keys_senc;
            update_field( $keys['label'],   trim( $first[ $col['label'] ] ),   $post_id );
            update_field( $keys['year'],    trim( $first[ $col['year'] ] ),    $post_id );
            update_field( $keys['artist'],  trim( $first[ $col['artistName'] ] ), $post_id );
            update_field( $keys['pubtype'], trim( $first[ $col['tipo'] ] ),     $post_id );

            // e) Build nested repeater rows
            $rows_data = [];
            foreach ( $items as $idx => $r ) {
                // find performer user
                $c = trim( $r[ $col['cedula'] ] );
                $u = get_users([
                    'meta_key'   => 'numero_de_identificacion',
                    'meta_value' => $c,
                    'number'     => 1,
                ]);
                if ( empty( $u ) ) {
                    WP_CLI::warning( "ID_ALBUM {$aid}, row {$idx}: cedula {$c} not found" );
                    continue;
                }
                $user = $u[0];

                // track sub-fields
                if ( 'fonograma' === $post_type ) {
                    $track = [
                        'field_65f31861a7ebf' => trim( $r[ $col['trackTitle'] ] ),    // titulo_de_la_cancion
                        'field_65f31871a7ec0' => trim( $r[ $col['trackISRC'] ] ),     // isrc
                        'field_65f3189ca7ec1' => trim( $r[ $col['trackNumber'] ] ),   // numero_de_tema
                        'field_65f318b5a7ec2' => trim( $r[ $col['trackDuration'] ] ), // duracion_del_tema
                        'field_652bf4845b32a' => trim( $r[ $col['bmat'] ] ),          // BMAT
                    ];
                    $interp_key = 'field_65f318d3a7ec3';
                    $track[ $interp_key ] = [[
                        'field_65f318f0a7ec4' => $user->display_name,               // nombre_completo
                        'field_65f31903a7ec5' => $user->ID,                         // id_de_usuario
                        'field_65f3191da7ec6' => trim( $r[ $col['performerInstrument'] ] ), // instrumento
                        'field_65f31927a7ec7' => trim( $r[ $col['performerRole'] ] ),       // role
                    ]];
                    $repeater_key = 'field_64f5266bb2994';
                } else {
                    $track = [
                        'field_65fb8610410b9' => trim( $r[ $col['trackTitle'] ] ),    // titulo_de_la_cancion
                        'field_65fb8610410bd' => trim( $r[ $col['trackISRC'] ] ),     // isrc
                        'field_65fb8610410c0' => trim( $r[ $col['trackNumber'] ] ),   // numero_de_tema
                        'field_65fb8610410c2' => trim( $r[ $col['trackDuration'] ] ), // duracion_del_tema
                        'field_652bf4845b32a' => trim( $r[ $col['bmat'] ] ),          // BMAT
                    ];
                    $interp_key = 'field_65fb8610410c5';
                    $track[ $interp_key ] = [[
                        'field_65fb861042707' => $user->display_name,
                        'field_65fb86104270a' => $user->ID,
                        'field_65fb86104270d' => trim( $r[ $col['performerInstrument'] ] ),
                        'field_65fb861042711' => trim( $r[ $col['performerRole'] ] ),
                    ]];
                    $repeater_key = 'field_65fb86103e5ed';
                }

                $rows_data[] = $track;
            }

            // f) Save repeater
            update_field( $repeater_key, $rows_data, $post_id );

            // Success message
            WP_CLI::success( ucfirst($post_type)." \"" . $post_title . "\" imported as post #{$post_id}" );
        }
    } );
}

// -------------------------------------------------------------------------
// Admin UI: simple upload form that posts to a custom handler.
// -------------------------------------------------------------------------

const AIEIMPORTER_MENU_SLUG   = 'aieimporter-import';
const AIEIMPORTER_NONCE_FIELD = 'aieimporter_import_nonce';
const AIEIMPORTER_NONCE_ACTION = 'aieimporter_import_action';
const AIEIMPORTER_TRANSIENT_KEY = 'aieimporter_last_import_summary';

add_action( 'admin_menu', 'aieimporter_register_menu_page' );
/**
 * Register the AIE Importer admin page.
 */
function aieimporter_register_menu_page() {
    add_menu_page(
        __( 'AIE Importer', 'aie-importer' ),
        __( 'AIE Importer', 'aie-importer' ),
        'manage_options',
        AIEIMPORTER_MENU_SLUG,
        'aieimporter_render_admin_page',
        'dashicons-upload'
    );
}

add_action( 'admin_notices', 'aieimporter_render_notices' );
/**
 * Render success/error notices for the importer page.
 */
function aieimporter_render_notices() {
    if ( ! isset( $_GET['page'] ) || AIEIMPORTER_MENU_SLUG !== $_GET['page'] ) {
        return;
    }

    $type    = isset( $_GET['aieimporter_status'] ) ? sanitize_key( $_GET['aieimporter_status'] ) : '';
    $message = isset( $_GET['aieimporter_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aieimporter_message'] ) ) : '';
    $import_completed = isset( $_GET['import_completed'] ) ? (int) $_GET['import_completed'] : 0;

    if ( $type && $message ) {
        $class = 'notice notice-' . ( 'success' === $type ? 'success' : 'error' );
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr( $class ),
            esc_html( $message )
        );
    }

    if ( $import_completed ) {
        $summary = get_transient( AIEIMPORTER_TRANSIENT_KEY );
        if ( $summary && is_array( $summary ) ) {
            delete_transient( AIEIMPORTER_TRANSIENT_KEY );
            $warnings = isset( $summary['warnings'] ) && is_array( $summary['warnings'] ) ? $summary['warnings'] : [];
            $post_status_used = isset( $summary['post_status_used'] ) ? $summary['post_status_used'] : '';
            $counts_message = sprintf(
                /* translators: 1: albums, 2: singles, 3: songs, 4: performers */
                __( 'Import completed. Albums: %1$s, Singles: %2$s, Songs: %3$s, Performers: %4$s.', 'aie-importer' ),
                intval( $summary['albums_created'] ?? 0 ),
                intval( $summary['singles_created'] ?? 0 ),
                intval( $summary['songs_created'] ?? 0 ),
                intval( $summary['performers_created'] ?? 0 )
            );
            echo '<div class="notice notice-success"><p>' . esc_html( $counts_message ) . '</p>';
            if ( $post_status_used ) {
                echo '<p>' . esc_html( sprintf( __( 'Estado aplicado: %s', 'aie-importer' ), $post_status_used ) ) . '</p>';
            }
            echo '</div>';
            if ( ! empty( $warnings ) ) {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Warnings:', 'aie-importer' ) . '</p><ul>';
                foreach ( $warnings as $warning ) {
                    echo '<li>' . esc_html( $warning ) . '</li>';
                }
                echo '</ul></div>';
            }
        }
    }
}

/**
 * Render the uploader UI.
 */
function aieimporter_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'aie-importer' ) );
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'AIE Importer', 'aie-importer' ); ?></h1>
        <p><?php esc_html_e( 'Sube el archivo Excel oficial y haz clic en “Start Import”. No se requieren configuraciones adicionales.', 'aie-importer' ); ?></p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:1; width:50%;">
            <?php wp_nonce_field( AIEIMPORTER_NONCE_ACTION, AIEIMPORTER_NONCE_FIELD ); ?>
            <input type="hidden" name="action" value="aieimporter_import" />
            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row">
                        <label for="aieimporter_file"><?php esc_html_e( 'Excel file (.xlsx)', 'aie-importer' ); ?></label>
                    </th>
                    <td>
                        <input type="file" id="aieimporter_file" name="aieimporter_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                        <p class="description"><?php esc_html_e( 'Solo se acepta el formato oficial .xlsx.', 'aie-importer' ); ?></p>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row">
                        <label for="aieimporter_post_status"><?php esc_html_e( 'Estado de publicación', 'aie-importer' ); ?></label>
                    </th>
                    <td>
                        <select id="aieimporter_post_status" name="aieimporter_post_status">
                            <option value="publish" selected><?php esc_html_e( 'publish', 'aie-importer' ); ?></option>
                            <option value="draft"><?php esc_html_e( 'draft', 'aie-importer' ); ?></option>
                        </select>
                    </td>
                </tr>
                </tbody>
            </table>
            <?php submit_button( __( 'Start Import', 'aie-importer' ) ); ?>

            <div style="background:#fff3cd; border:1px solid #ffeeba; padding:10px; margin-top:20px;">
                <p style="font-size: 12px;"><strong>Importante sobre archivos grandes:</strong></p>
                <p style="font-size: 12px;">Los archivos Excel se procesan completos en memoria. Como referencia práctica:</p>
                <ul style="margin-left:18px; font-size: 12px;">
                    <li>• Hasta 1,000 filas: importación normal y estable</li>
                    <li>• Entre 1,000 y 5,000 filas: puede tardar más o requerir mayor memoria</li>
                    <li>• Más de 5,000 filas: el servidor podría no completar la importación</li>
                    <li>• Más de 10,000 filas: altamente probable que falle sin aumentar el memory_limit</li>
                </ul>
            </div>
        </form>
    </div>
    <?php
}

add_action( 'admin_post_aieimporter_import', 'aieimporter_handle_import' );
/**
 * Handle the upload, run the importer, and redirect with summary.
 */
function aieimporter_handle_import() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to perform this action.', 'aie-importer' ) );
    }

    check_admin_referer( AIEIMPORTER_NONCE_ACTION, AIEIMPORTER_NONCE_FIELD );

    if ( ! isset( $_FILES['aieimporter_file'] ) || ! is_array( $_FILES['aieimporter_file'] ) ) {
        aieimporter_redirect_with_notice( 'error', __( 'No se recibió ningún archivo. Por favor, selecciona un .xlsx válido.', 'aie-importer' ) );
    }

    $post_status = isset( $_POST['aieimporter_post_status'] ) ? sanitize_key( wp_unslash( $_POST['aieimporter_post_status'] ) ) : '';
    if ( ! in_array( $post_status, [ 'publish', 'draft' ], true ) ) {
        $post_status = 'publish';
    }

    $file = $_FILES['aieimporter_file'];

    if ( ! empty( $file['error'] ) ) {
        aieimporter_redirect_with_notice( 'error', __( 'Ocurrió un error al subir el archivo. Intenta de nuevo.', 'aie-importer' ) );
    }

    $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( 'xlsx' !== $extension ) {
        aieimporter_redirect_with_notice( 'error', __( 'Solo se permiten archivos .xlsx. Por favor, sube el archivo oficial.', 'aie-importer' ) );
    }

    $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ] );
    if ( ! $filetype['ext'] || 'xlsx' !== $filetype['ext'] ) {
        aieimporter_redirect_with_notice( 'error', __( 'El archivo no es un .xlsx válido.', 'aie-importer' ) );
    }

    $uploads = wp_upload_dir();
    $target_dir = trailingslashit( $uploads['basedir'] ) . 'aieimporter/';
    wp_mkdir_p( $target_dir );

    $filename   = wp_unique_filename( $target_dir, basename( $file['name'] ) );
    $targetpath = $target_dir . $filename;

    if ( ! @move_uploaded_file( $file['tmp_name'], $targetpath ) ) {
        aieimporter_redirect_with_notice( 'error', __( 'No se pudo mover el archivo subido. Inténtalo de nuevo.', 'aie-importer' ) );
    }

    try {
        $service = new \AIEImporter\Services\ImporterService();
        $summary = $service->import( $targetpath, $post_status );
        \AIEImporter\Services\LoggerService::log_import( $summary, $targetpath );
        set_transient( AIEIMPORTER_TRANSIENT_KEY, $summary, MINUTE_IN_SECONDS );
        $url = add_query_arg(
            [
                'page'             => AIEIMPORTER_MENU_SLUG,
                'import_completed' => 1,
            ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    } catch ( \Throwable $e ) {
        aieimporter_redirect_with_notice( 'error', $e->getMessage() );
    }
}

/**
 * Redirect back to the importer page with a notice.
 *
 * @param string $status  Status slug: success|error.
 * @param string $message Notice message.
 */
function aieimporter_redirect_with_notice( $status, $message ) {
    $url = add_query_arg(
        [
            'page'                 => AIEIMPORTER_MENU_SLUG,
            'aieimporter_status'   => $status,
            'aieimporter_message'  => rawurlencode( wp_strip_all_tags( $message ) ),
        ],
        admin_url( 'admin.php' )
    );

    wp_safe_redirect( $url );
    exit;
}
