<?php
/**
 * Logger Service
 *
 * @package aie-importer
 */

namespace AIEImporter\Services;

/**
 * Logs importer runs to files under uploads/aieimporter/logs/.
 */
class LoggerService {

    /**
     * Log an import summary to a timestamped file.
     *
     * @param array  $summary     Summary array from ImporterService.
     * @param string $source_file Path to the uploaded XLSX file.
     * @return void
     */
    public static function log_import( array $summary, string $source_file ): void {
        $start_time = microtime( true );

        $uploads = \wp_upload_dir();
        if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
            return;
        }

        $base_dir  = trailingslashit( $uploads['basedir'] ) . 'aieimporter/';
        $logs_dir  = $base_dir . 'logs/';

        // Ensure directories exist.
        if ( ! is_dir( $logs_dir ) ) {
            \wp_mkdir_p( $logs_dir );
            @chmod( $logs_dir, 0755 );
        }

        if ( ! is_writable( $logs_dir ) ) {
            return;
        }

        $timestamp   = current_time( 'Y-m-d H:i:s' );
        $filename_ts = current_time( 'Y-m-d_H-i-s' );
        $filename    = sprintf( 'aieimporter-%s.log', $filename_ts );
        $filepath    = $logs_dir . $filename;

        $warnings = isset( $summary['warnings'] ) && is_array( $summary['warnings'] ) ? $summary['warnings'] : [];

        $lines   = [];
        $lines[] = 'Timestamp: ' . $timestamp;
        $lines[] = 'Source file: ' . $source_file;
        $lines[] = sprintf(
            'Counts => albums: %d, singles: %d, songs: %d, performers: %d',
            intval( $summary['albums_created'] ?? 0 ),
            intval( $summary['singles_created'] ?? 0 ),
            intval( $summary['songs_created'] ?? 0 ),
            intval( $summary['performers_created'] ?? 0 )
        );

        if ( ! empty( $warnings ) ) {
            $lines[] = 'Warnings:';
            foreach ( $warnings as $warning ) {
                $lines[] = '- ' . $warning;
            }
        } else {
            $lines[] = 'Warnings: none';
        }

        $duration = microtime( true ) - $start_time;
        $lines[]  = 'Duration: ' . number_format( $duration, 4 ) . ' seconds';

        $contents = implode( PHP_EOL, $lines ) . PHP_EOL;

        @file_put_contents( $filepath, $contents );
    }
}
