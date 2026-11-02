<?php
/**
 * Single-image optimizer: handles backup, optimize, restore, next-gen conversion.
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
     * Api client.
     *
     * @var Api_Client
     */
    private $api;

    /**
     * Constructor.
     *
     * @param Api_Client|null $api Optional injected client.
     */
    public function __construct( Api_Client $api = null ) {
        $this->api = $api ? $api : new Api_Client();
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

        $level     = isset( $args['level'] ) ? $args['level'] : Settings::get( 'compression' );
        $nextgen   = isset( $args['nextgen'] ) ? $args['nextgen'] : ( Settings::get( 'auto_convert' ) ? Settings::get( 'convert_format' ) : '' );

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
     * Optimize one physical file.
     *
     * @param string $path Absolute file path.
     * @param string $level Compression level.
     * @param string $nextgen Next-gen format ('' to skip).
     * @return array|WP_Error
     */
    public function optimize_file( $path, $level = 'aggressive', $nextgen = '' ) {
        $original_size = filesize( $path );
        if ( 0 === $original_size ) {
            return new WP_Error( 'picsqueeze_empty_file', __( 'The image file is empty.', 'picsqueeze' ) );
        }

        $backup_ok = Settings::get( 'keep_originals' ) ? $this->backup( $path ) : true;
        if ( ! $backup_ok ) {
            return new WP_Error( 'picsqueeze_backup', __( 'Could not back up the original image, so it was not optimized.', 'picsqueeze' ) );
        }

        $response = $this->api->optimize_image( $path, $level, '' !== $nextgen, (string) $nextgen );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response->image ) ) {
            return new WP_Error( 'picsqueeze_bad_response', __( 'The optimization service returned an invalid response.', 'picsqueeze' ) );
        }

        $temp = $this->temp_path( $path );
        $saved = $this->api->download_result( $response->image, $temp );

        if ( is_wp_error( $saved ) ) {
            $this->cleanup( $temp );
            return $saved;
        }

        // Only replace if actually smaller.
        if ( filesize( $temp ) >= $original_size ) {
            $this->cleanup( $temp );
            return array(
                'level'     => $level,
                'status'    => 'already_optimized',
                'original'  => $original_size,
                'optimized' => $original_size,
                'nextgen'   => '',
            );
        }

        $renamed = rename( $temp, $path );
        if ( ! $renamed ) {
            $this->cleanup( $temp );
            return new WP_Error( 'picsqueeze_replace', __( 'The optimized image could not replace the original.', 'picsqueeze' ) );
        }

        $this->apply_permissions( $path );

        if ( '' !== $nextgen && ! empty( $response->sources->{$nextgen} ) ) {
            $this->create_nextgen_file( $path, $response->sources->{$nextgen}, $nextgen );
        }

        return array(
            'level'     => $level,
            'status'    => 'success',
            'original'  => $original_size,
            'optimized' => filesize( $path ),
            'nextgen'   => $nextgen,
        );
    }

    /**
     * Save a next-gen variant alongside the optimized file.
     *
     * @param string $original_path Path of the optimized file.
     * @param string $url Download URL of the next-gen file.
     * @param string $format webp|avif.
     * @return bool
     */
    private function create_nextgen_file( $original_path, $url, $format ) {
        $target = $this->nextgen_path( $original_path, $format );
        $saved  = $this->api->download_result( $url, $target );
        if ( is_wp_error( $saved ) ) {
            return false;
        }
        $this->apply_permissions( $target );
        return true;
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

        $webp = $this->nextgen_path( $path, 'webp' );
        $avif = $this->nextgen_path( $path, 'avif' );
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
     * Next-gen sibling path for a file.
     *
     * @param string $path File path.
     * @param string $format webp|avif.
     * @return string
     */
    public function nextgen_path( $path, $format ) {
        return preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $path );
    }

    /**
     * Temporary path for downloads.
     *
     * @param string $path Original path.
     * @return string
     */
    private function temp_path( $path ) {
        return $path . '.picsqueeze-tmp';
    }

    /**
     * Delete a temp file if it exists.
     *
     * @param string $path Temp path.
     */
    private function cleanup( $path ) {
        if ( file_exists( $path ) ) {
            unlink( $path ); // phpcs:ignore
        }
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
