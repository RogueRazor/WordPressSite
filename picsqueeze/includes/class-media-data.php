<?php
/**
 * Per-image optimization record stored in attachment meta.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

/**
 * Class Media_Data
 */
class Media_Data {

    const META_KEY = '_picsqueeze_data';

    /**
     * Attachment ID.
     *
     * @var int
     */
    private $id;

    /**
     * Constructor.
     *
     * @param int $id Attachment ID.
     */
    public function __construct( $id ) {
        $this->id = (int) $id;
    }

    /**
     * Read the stored record.
     *
     * @return array
     */
    public function read() {
        $meta = get_post_meta( $this->id, self::META_KEY, true );
        return is_array( $meta ) ? $meta : array();
    }

    /**
     * Persist the record.
     *
     * @param array $data New record.
     * @return bool
     */
    public function write( array $data ) {
        return update_post_meta( $this->id, self::META_KEY, $data );
    }

    /**
     * Record a successful optimization of one size.
     *
     * @param string $size Size key.
     * @param string $level Compression level.
     * @param string $status success|already_optimized.
     * @param int    $original_size Bytes before.
     * @param int    $optimized_size Bytes after.
     * @param string $nextgen next-gen format or empty string.
     */
    public function record_success( $size, $level, $status, $original_size, $optimized_size, $nextgen = '' ) {
        $data = $this->read();
        if ( ! isset( $data['sizes'] ) || ! is_array( $data['sizes'] ) ) {
            $data['sizes'] = array();
        }

        $data['sizes'][ $size ] = array(
            'level'       => $level,
            'status'      => $status,
            'original'    => (int) $original_size,
            'optimized'   => (int) $optimized_size,
            'time'        => time(),
            'nextgen'     => $nextgen,
        );

        $this->write( $data );
    }

    /**
     * Record a failed optimization attempt.
     *
     * @param string $size Size key.
     * @param string $error Error message.
     */
    public function record_error( $size, $error ) {
        $data = $this->read();
        if ( ! isset( $data['sizes'] ) || ! is_array( $data['sizes'] ) ) {
            $data['sizes'] = array();
        }

        $data['sizes'][ $size ] = array(
            'status'  => 'error',
            'error'   => (string) $error,
            'time'    => time(),
        );

        $this->write( $data );
    }

    /**
     * Has this size been optimized already?
     *
     * @param string $size Size key.
     * @return bool
     */
    public function is_optimized( $size = '_full' ) {
        $data = $this->read();
        return ! empty( $data['sizes'][ $size ]['status'] ) && 'success' === $data['sizes'][ $size ]['status'];
    }

    /**
     * Total original bytes across recorded sizes.
     *
     * @return int
     */
    public function original_bytes() {
        $total = 0;
        $data  = $this->read();
        if ( isset( $data['sizes'] ) ) {
            foreach ( $data['sizes'] as $entry ) {
                $total += isset( $entry['original'] ) ? (int) $entry['original'] : 0;
            }
        }
        return $total;
    }

    /**
     * Total optimized bytes across recorded sizes.
     *
     * @return int
     */
    public function optimized_bytes() {
        $total = 0;
        $data  = $this->read();
        if ( isset( $data['sizes'] ) ) {
            foreach ( $data['sizes'] as $entry ) {
                $total += isset( $entry['optimized'] ) ? (int) $entry['optimized'] : 0;
            }
        }
        return $total;
    }

    /**
     * Drop all stored data (used on restore or reset).
     */
    public function clear() {
        delete_post_meta( $this->id, self::META_KEY );
    }
}
