<?php
/**
 * Importer Service
 *
 * @package aie-importer
 */

namespace AIEImporter\Services;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Refactored importer logic that can run from UI or CLI callers.
 */
class ImporterService {

    /**
     * Column map for the official Excel file.
     *
     * @var array<string, string>
     */
    private $column_map = [
        'obra_code'      => 'A',
        'track_title'    => 'B',
        'artist_name'    => 'C',
        'bmat'           => 'E',
        'performer_name' => 'G',
        'cedula'         => 'H',
        'performer_role' => 'I',
        'performer_instr'=> 'K',
        'album_title'    => 'L',
        'id_album'       => 'M',
        'track_isrc'     => 'N',
        'year'           => 'Q',
        'duration'       => 'R',
    ];

    /**
     * Collects non-fatal warnings.
     *
     * @var array<int, string>
     */
    private $warnings = [];

    /**
     * Main entry point: import from XLSX file and return summary.
     *
     * @param string $file_path Absolute path to the XLSX file.
     * @return array<string, mixed>
     */
    public function import( string $file_path ): array {
        $this->warnings = [];

        if ( ! file_exists( $file_path ) ) {
            throw new InvalidArgumentException( sprintf( 'File not found: %s', $file_path ) );
        }

        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( 'xlsx' !== $extension ) {
            throw new InvalidArgumentException( 'Only .xlsx files are supported.' );
        }

        if ( ! class_exists( IOFactory::class ) ) {
            $autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
            if ( file_exists( $autoload ) ) {
                require_once $autoload;
            }
        }

        $rows   = $this->parse_excel_rows( $file_path );
        $groups = $this->group_rows_by_album_id( $rows );

        $results = [
            'albums_created'     => 0,
            'singles_created'    => 0,
            'songs_created'      => 0,
            'performers_created' => 0,
            'warnings'           => [],
        ];

        foreach ( $groups as $album_id => $group_rows ) {
            $is_album = '' !== $group_rows[0]['id_album'];
            $post_id  = $is_album
                ? $this->build_fonograma( $group_rows )
                : $this->build_sencillo( $group_rows );

            if ( ! $post_id ) {
                $this->warnings[] = sprintf(
                    'Could not create post for group "%s".',
                    $group_rows[0]['id_album'] ? $group_rows[0]['id_album'] : 'single'
                );
                continue;
            }

            if ( $is_album ) {
                $results['albums_created']++;
            } else {
                $results['singles_created']++;
            }

            $counts = $this->build_song_repeater( $group_rows, $post_id );
            $results['songs_created']      += $counts['songs'];
            $results['performers_created'] += $counts['performers'];
        }

        $results['warnings'] = $this->warnings;

        return $results;
    }

    /**
     * Parse XLSX rows into associative arrays keyed by column meaning.
     *
     * @param string $file_path Path to the XLSX.
     * @return array<int, array<string, string>>
     */
    public function parse_excel_rows( string $file_path ): array {
        $spreadsheet = IOFactory::load( $file_path );
        $rows_raw    = $spreadsheet->getActiveSheet()->toArray( null, true, true, true );

        $rows = [];
        foreach ( $rows_raw as $index => $row ) {
            if ( 1 === $index ) {
                // Skip header row.
                continue;
            }

            $rows[] = [
                'track_title'    => $this->cell( $row, 'track_title' ),
                'artist_name'    => $this->cell( $row, 'artist_name' ),
                'bmat'           => $this->cell( $row, 'bmat' ),
                'performer_name' => $this->cell( $row, 'performer_name' ),
                'cedula'         => $this->cell( $row, 'cedula' ),
                'performer_role' => $this->cell( $row, 'performer_role' ),
                'performer_instr'=> $this->cell( $row, 'performer_instr' ),
                'album_title'    => $this->cell( $row, 'album_title' ),
                'id_album'       => $this->cell( $row, 'id_album' ),
                'track_isrc'     => $this->cell( $row, 'track_isrc' ),
                'year'           => $this->cell( $row, 'year' ),
                'duration'       => $this->cell( $row, 'duration' ),
                'obra_code'      => $this->cell( $row, 'obra_code' ),
            ];
        }

        return $rows;
    }

    /**
     * Group rows by ID_ALBUM, creating single-row groups for empty IDs.
     *
     * @param array<int, array<string, string>> $rows Parsed rows.
     * @return array<string, array<int, array<string, string>>>
     */
    public function group_rows_by_album_id( array $rows ): array {
        $groups = [];
        foreach ( $rows as $idx => $row ) {
            $album_id = $row['id_album'];
            if ( '' === $album_id ) {
                $single_key = $row['obra_code'] ?: (string) $idx;
                $album_id   = 'single-' . $single_key;
            }

            if ( ! isset( $groups[ $album_id ] ) ) {
                $groups[ $album_id ] = [];
            }

            $groups[ $album_id ][] = $row;
        }

        return $groups;
    }

    /**
     * Create a fonograma post and set top-level fields.
     *
     * @param array<int, array<string, string>> $rows Group rows.
     * @return int Post ID or 0 on failure.
     */
    public function build_fonograma( array $rows ): int {
        $first      = $rows[0];
        $post_title = $first['album_title'] ?: $first['track_title'];

        $post_id = \wp_insert_post(
            [
                'post_type'   => 'fonograma',
                'post_title'  => $post_title,
                'post_status' => 'publish',
                'post_author' => $this->find_user_by_cedula( $first['cedula'] ) ?: 0,
            ],
            true
        );

        if ( \is_wp_error( $post_id ) || ! $post_id ) {
            $this->warnings[] = sprintf( 'Could not insert fonograma for album "%s".', $post_title );
            return 0;
        }

        $this->update_field( 'ano_de_publicacion', $first['year'], $post_id );
        $this->update_field( 'nombre_del_artista_solista_o_agrupacion', $first['artist_name'], $post_id );
        $this->update_field( 'numero_album_o_single', $first['id_album'], $post_id );

        return (int) $post_id;
    }

    /**
     * Create a sencillo post and set top-level fields.
     *
     * @param array<int, array<string, string>> $rows Group rows.
     * @return int Post ID or 0 on failure.
     */
    public function build_sencillo( array $rows ): int {
        $first      = $rows[0];
        $post_title = $first['track_title'];

        $post_id = \wp_insert_post(
            [
                'post_type'   => 'sencillo',
                'post_title'  => $post_title,
                'post_status' => 'publish',
                'post_author' => $this->find_user_by_cedula( $first['cedula'] ) ?: 0,
            ],
            true
        );

        if ( \is_wp_error( $post_id ) || ! $post_id ) {
            $this->warnings[] = sprintf( 'Could not insert sencillo for track "%s".', $post_title );
            return 0;
        }

        $this->update_field( 'ano_de_publicacion', $first['year'], $post_id );
        $this->update_field( 'nombre_del_artista_solista_o_agrupacion', $first['artist_name'], $post_id );
        $this->update_field( 'numero_album_o_single', $first['id_album'], $post_id );

        return (int) $post_id;
    }

    /**
     * Build the song repeater and nested performer repeater, then update ACF.
     *
     * @param array<int, array<string, string>> $rows Rows for this post.
     * @param int                                $post_id Post ID.
     * @return array<string, int> Counts.
     */
    public function build_song_repeater( array $rows, int $post_id ): array {
        $songs_by_key     = [];
        $performers_total = 0;

        foreach ( $rows as $index => $row ) {
            $song_key = $row['track_title'] . '|' . $row['obra_code'];

            if ( ! isset( $songs_by_key[ $song_key ] ) ) {
                $songs_by_key[ $song_key ] = [
                    'titulo_de_la_cancion'      => $row['track_title'],
                    'isrc'                      => $row['track_isrc'],
                    'bmat'                      => $row['bmat'],
                    'duracion_en_segundos'      => $row['duration'],
                    'codigo_de_obra'            => $row['obra_code'],
                    'interpretes_y_ejecutantes' => [],
                ];
            }

            $performers = $this->build_artist_nested_repeater( $row, $index + 1 );

            $songs_by_key[ $song_key ]['interpretes_y_ejecutantes'] = array_merge(
                $songs_by_key[ $song_key ]['interpretes_y_ejecutantes'],
                $performers
            );

            $performers_total += count( $performers );
        }

        $repeater_rows = array_values( $songs_by_key );
        $this->update_field( 'nombre_de_cada_tema_del_album_o_sencillo', $repeater_rows, $post_id );

        return [
            'songs'      => count( $repeater_rows ),
            'performers' => $performers_total,
        ];
    }

    /**
     * Build nested performer rows for a song.
     *
     * @param array<string, string> $row Current song row.
     * @param int                   $row_number One-based row number for warnings.
     * @return array<int, array<string, string>>
     */
    public function build_artist_nested_repeater( array $row, int $row_number ): array {
        $user_id = $this->find_user_by_cedula( $row['cedula'] );
        if ( ! $user_id ) {
            $this->warnings[] = sprintf(
                'No user found for cédula "%s" (row %d). Performer will be saved without user ID.',
                $row['cedula'],
                $row_number
            );
        }

        return [
            [
                'nombre_completo' => $row['performer_name'] ?: '',
                'id_de_usuario'   => $user_id ? (string) $user_id : '',
                'instrumento'     => $row['performer_instr'],
                'role'            => $row['performer_role'],
            ],
        ];
    }

    /**
     * Find user by cédula meta.
     *
     * @param string $cedula Cedula value.
     * @return int|null User ID or null.
     */
    public function find_user_by_cedula( string $cedula ): ?int {
        $cedula = trim( $cedula );
        if ( '' === $cedula ) {
            return null;
        }

        $users = \get_users(
            [
                'meta_key'   => 'numero_de_identificacion',
                'meta_value' => $cedula,
                'number'     => 1,
                'fields'     => 'ids',
            ]
        );

        return ! empty( $users ) ? (int) $users[0] : null;
    }

    /**
     * Helper to read and trim a cell by logical name.
     *
     * @param array<string, string> $row Spreadsheet row with lettered keys.
     * @param string                $key Logical key from column map.
     * @return string
     */
    private function cell( array $row, string $key ): string {
        $column = $this->column_map[ $key ] ?? '';
        $value  = $column && isset( $row[ $column ] ) ? $row[ $column ] : '';

        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }


    /**
     * Update an ACF field if available, otherwise use post meta.
     *
     * @param string $field_key Field key or name.
     * @param mixed  $value     Value to save.
     * @param int    $post_id   Post ID.
     * @return void
     */
    private function update_field( string $field_key, $value, int $post_id ): void {
        if ( \function_exists( 'update_field' ) ) {
            \update_field( $field_key, $value, $post_id );
            return;
        }

        \update_post_meta( $post_id, $field_key, $value );
    }
}
