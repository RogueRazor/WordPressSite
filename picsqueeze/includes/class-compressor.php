<?php
/**
 * Local image compressor using WordPress' built-in image engines (GD or Imagick).
 *
 * Re-saving an image through an image editor strips metadata (EXIF, comments)
 * and applies the configured quality level, which shrinks the file without
 * needing any external service.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class Compressor
 */
class Compressor {

    /**
     * Image types we can process.
     *
     * @return string[]
     */
    public static function supported_mime_types() {
        return array( 'image/jpeg', 'image/png', 'image/gif' );
    }

    /**
     * Can the installed engine produce this output format?
     *
     * @param string $format webp|avif.
     * @return bool
     */
    public static function supports_format( $format ) {
        if ( 'webp' === $format ) {
            return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
        }
        if ( 'avif' === $format ) {
            return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
        }
        return false;
    }

    /**
     * Compress a file in place using the current quality setting.
     *
     * @param string $path Absolute file path.
     * @param string $level normal|aggressive|ultra.
     * @return array{original:int,optimized:int}|WP_Error
     */
    public function compress( $path, $level = 'aggressive' ) {
        $original_size = filesize( $path );
        if ( 0 === $original_size ) {
            return new WP_Error( 'picsqueeze_empty_file', __( 'The image file is empty.', 'picsqueeze' ) );
        }

        if ( ! $this->is_supported_file( $path ) ) {
            return new WP_Error( 'picsqueeze_unsupported', __( 'This image format cannot be compressed locally.', 'picsqueeze' ) );
        }

        $quality = Settings::quality_for( $level );

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return $editor;
        }

        $editor->set_quality( $quality );

        // PNGs are lossless; quality changes palette/depth, so use a lighter touch.
        if ( $this->is_png( $path ) ) {
            $editor->set_quality( min( 90, $quality + 12 ) );
        }

        $temp = $this->temp_path( $path );
        $saved = $editor->save( $temp );

        if ( is_wp_error( $saved ) ) {
            $this->cleanup( $temp );
            return $saved;
        }

        $temp = ! empty( $saved['path'] ) ? $saved['path'] : $temp;

        // Only replace when the re-encoded file is genuinely smaller.
        clearstatcache( true, $temp );
        if ( ! file_exists( $temp ) || filesize( $temp ) >= $original_size ) {
            $this->cleanup( $temp );
            return array(
                'original'  => $original_size,
                'optimized' => $original_size,
                'status'    => 'already_optimized',
            );
        }

        if ( ! rename( $temp, $path ) ) {
            $this->cleanup( $temp );
            return new WP_Error( 'picsqueeze_replace', __( 'The compressed image could not replace the original.', 'picsqueeze' ) );
        }

        clearstatcache( true, $path );
        $this->apply_permissions( $path );

        return array(
            'original'  => $original_size,
            'optimized' => filesize( $path ),
            'status'    => 'success',
        );
    }

    /**
     * Create a next-gen copy of a file (WebP or AVIF).
     *
     * @param string $path Source file path.
     * @param string $format webp|avif.
     * @param string $level Compression level.
     * @return string|WP_Error New file path, or error.
     */
    public function convert( $path, $format, $level = 'aggressive' ) {
        if ( ! $this->is_supported_file( $path ) ) {
            return new WP_Error( 'picsqueeze_unsupported', __( 'This image format cannot be converted.', 'picsqueeze' ) );
        }

        if ( ! self::supports_format( $format ) ) {
            return new WP_Error(
                'picsqueeze_format_unsupported',
                sprintf(
                    /* translators: %s is a file format such as WebP. */
                    __( 'Your server cannot generate %s images; consider asking your host to enable the Imagick or GD extensions.', 'picsqueeze' ),
                    strtoupper( $format )
                )
            );
        }

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return $editor;
        }

        $editor->set_quality( Settings::quality_for( $level ) );

        $target = $this->nextgen_path( $path, $format );
        $saved  = $editor->save( $target, 'image/' . $format );

        if ( is_wp_error( $saved ) ) {
            $this->cleanup( $target );
            return $saved;
        }

        $this->apply_permissions( $target );
        return ! empty( $saved['path'] ) ? $saved['path'] : $target;
    }

    /**
     * Is this file one we can work with?
     *
     * @param string $path File path.
     * @return bool
     */
    private function is_supported_file( $path ) {
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ), true );
    }

    /**
     * Is the file a PNG?
     *
     * @param string $path File path.
     * @return bool
     */
    private function is_png( $path ) {
        return 'png' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
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
     * Temp path used during re-encoding (keeps a valid extension for the editor).
     *
     * @param string $path File path.
     * @return string
     */
    private function temp_path( $path ) {
        $info = pathinfo( $path );
        return $info['dirname'] . '/' . $info['filename'] . '-picsqueeze-tmp.' . $info['extension'];
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
     * Apply the same permissions as the parent directory's files.
     *
     * @param string $path File path.
     */
    private function apply_permissions( $path ) {
        $stat  = stat( dirname( $path ) );
        $perms = $stat ? $stat['mode'] & 0000666 : 0644;
        chmod( $path, $perms ); // phpcs:ignore
    }
}
