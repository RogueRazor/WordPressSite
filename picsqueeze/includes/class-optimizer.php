<?php
/**
 * Single-image optimizer: local compression, backup, restore, next-gen conversion.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class Optimizer
 */
class Optimizer {

    /**
     * Local compressor.
     *
     * @var Compressor
     */
    private $compressor;

    /**
     * Constructor.
     *
     * @param Compressor|null $compressor Optional injected compressor.
     */
    public function __construct( Compressor $compressor = null ) {
        $this->compressor = $compressor ? $compressor : new Compressor();
    }

    /**
     * Optimize a single attachment (all its sizes).
     *
     * @param int   $id Attachment ID.
     * @param array $args { optional overrides }.
     * @return true|WP_Error
     */
    public function optimize_attachment( $id, array $args = array() ) {
        $id = (int) $id;

        if ( ! wp_attachment_is_image( $id ) ) {
            return new WP_Error( 'picsqueeze_not_image', __( 'This attachment is not a supported image.', 'picsqueeze' ) );
        }

        $level   = isset( $args['level'] ) ? $args['level'] : Settings::get( 'compression' );
        $nextgen = isset( $args['nextgen'] ) ? $args['nextgen'] : ( Settings::get( 'auto_convert' ) ? Settings::get( 'convert_format' ) : '' );

        $data  = new Media_Data( $id );
        $sizes = $this->attachment_sizes( $id );
        $any   = false;

        foreach ( $sizes as $size => $path ) {
            if ( ! file_exists( $path ) ) {
                continue;
            }
            if ( $data->is_optimized( $size ) ) {
                continue;
            }

            $result = $this->optimize_file( $path, $level, $nextgen );
            if ( is_wp_error( $result ) ) {
                $data->record_error( $size, $result->get_error_message() );
                continue;
            }

            $data->record_success( $size, $result['level'], $result['status'], $result['original'], $result['optimized'], $result['nextgen'] );
            $any = true;
        }

        if ( ! $any ) {
            return new WP_Error( 'picsqueeze_nothing_done', __( 'No image size could be optimized.', 'picsqueeze' ) );
        }

        return true;
    }

    /**
     * Optimize one physical file locally.
     *
     * @param string $path Absolute file path.
     * @param string $level Compression level.
     * @param string $nextgen Next-gen format ('' to skip).
     * @return array|WP_Error
     */
    public function optimize_file( $path, $level = 'aggressive', $nextgen = '' ) {
        $backup_ok = Settings::get( 'keep_originals' ) ? $this->backup( $path ) : true;
        if ( ! $backup_ok ) {
            return new WP_Error( 'picsqueeze_backup', __( 'Could not back up the original image, so it was not optimized.', 'picsqueeze' ) );
        }

        $result = $this->compressor->compress( $path, $level );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( 'success' !== $result['status'] ) {
            // Nothing to do: file was already smaller than our output.
            return array(
                'level'     => $level,
                'status'    => 'already_optimized',
                'original'  => $result['original'],
                'optimized' => $result['original'],
                'nextgen'   => '',
            );
        }

        $converted = '';
        if ( '' !== $nextgen ) {
            $converted = $this->generate_nextgen( $path, $nextgen, $level );
        }

        return array(
            'level'     => $level,
            'status'    => 'success',
            'original'  => $result['original'],
            'optimized' => $result['optimized'],
            'nextgen'   => $converted,
        );
    }

    /**
     * Generate a next-gen variant of a file, if possible.
     *
     * @param string $path Source path.
     * @param string $format webp|avif.
     * @param string $level Compression level.
     * @return string Format name on success ('' on failure).
     */
    private function generate_nextgen( $path, $format, $level ) {
        $result = $this->compressor->convert( $path, $format, $level );
        if ( is_wp_error( $result ) ) {
            return '';
        }
        return $format;
    }

    /**
     * Restore an attachment to its original files.
     *
     * @param int $id Attachment ID.
     * @return true|WP_Error
     */
    public function restore_attachment( $id ) {
        $id = (int) $id;
        $sizes = $this->attachment_sizes( $id );
        $restored = false;

        foreach ( $sizes as $size => $path ) {
            if ( $this->restore_file( $path ) ) {
                $restored = true;
            }
        }

        $data = new Media_Data( $id );
        $data->clear();

        if ( ! $restored ) {
            return new WP_Error( 'picsqueeze_no_backup', __( 'No backup copies were found to restore.', 'picsqueeze' ) );
        }

        return true;
    }

    /**
     * Restore a single file from its backup.
     *
     * @param string $path File path.
     * @return bool
     */
    public function restore_file( $path ) {
        $backup = $this->backup_path( $path );
        if ( ! file_exists( $backup ) ) {
            return false;
        }
        if ( ! rename( $backup, $path ) ) {
            return false;
        }
        $this->apply_permissions( $path );

        $webp = $this->compressor->nextgen_path( $path, 'webp' );
        $avif = $this->compressor->nextgen_path( $path, 'avif' );
        if ( file_exists( $webp ) ) { unlink( $webp ); }
        if ( file_exists( $avif ) ) { unlink( $avif ); }

        return true;
    }

    /**
     * Copy the current file to a backup location.
     *
     * @param string $path File path.
     * @return bool
     */
    private function backup( $path ) {
        $backup = $this->backup_path( $path );
        if ( file_exists( $backup ) ) {
            return true;
        }

        $dir = dirname( $backup );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        $ok = copy( $path, $backup );
        if ( $ok ) {
            $this->apply_permissions( $backup );
        }
        return $ok;
    }

    /**
     * Backup path for a file.
     *
     * @param string $path File path.
     * @return string
     */
    public function backup_path( $path ) {
        return dirname( $path ) . '/picsqueeze-backup-' . basename( $path );
    }

    /**
     * Apply standard permissions to a written file.
     *
     * @param string $path File path.
     */
    private function apply_permissions( $path ) {
        $stat  = stat( dirname( $path ) );
        $perms = $stat ? $stat['mode'] & 0000666 : 0644;
        chmod( $path, $perms ); // phpcs:ignore
    }

    /**
     * All size paths of an attachment, including the full image.
     *
     * @param int $id Attachment ID.
     * @return array size => path.
     */
    public function attachment_sizes( $id ) {
        $id  = (int) $id;
        $src = get_attached_file( $id );
        if ( ! $src || ! file_exists( $src ) ) {
            return array();
        }

        $sizes = array( '_full' => $src );

        $meta = wp_get_attachment_metadata( $id );
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $key => $info ) {
                if ( ! empty( $info['file'] ) ) {
                    $path = dirname( $src ) . '/' . $info['file'];
                    if ( file_exists( $path ) && $path !== $src ) {
                        $sizes[ $key ] = $path;
                    }
                }
            }
        }

        return $sizes;
    }
}
