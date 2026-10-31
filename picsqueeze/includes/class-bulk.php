<?php
/**
 * Bulk optimization: background batches driven by AJAX + option queue.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class Bulk
 */
class Bulk {

    const QUEUE_OPTION    = 'picsqueeze_bulk_queue';
    const POINTER_OPTION  = 'picsqueeze_bulk_pointer';
    const RUNNING_FLAG    = 'picsqueeze_bulk_running';
    const BATCH_SIZE      = 4;

    /**
     * Optimizer.
     *
     * @var Optimizer
     */
    private $optimizer;

    /**
     * Constructor.
     *
     * @param Optimizer|null $optimizer Optional injected optimizer.
     */
    public function __construct( Optimizer $optimizer = null ) {
        $this->optimizer = $optimizer ? $optimizer : new Optimizer();
    }

    /**
     * Build the queue of un-optimized attachment IDs.
     *
     * @return int Number queued.
     */
    public function build_queue() {
        $ids = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        $pending = array();
        foreach ( $ids as $id ) {
            $data = new Media_Data( $id );
            if ( ! $data->is_optimized( '_full' ) ) {
                $pending[] = (int) $id;
            }
        }

        update_option( self::QUEUE_OPTION, $pending, false );
        update_option( self::POINTER_OPTION, 0, false );
        update_option( self::RUNNING_FLAG, count( $pending ) > 0 ? 1 : 0, false );

        return count( $pending );
    }

    /**
     * Process one batch from the queue.
     *
     * @return array Status summary for the JS runner.
     */
    public function process_batch() {
        $queue   = get_option( self::QUEUE_OPTION, array() );
        $pointer = (int) get_option( self::POINTER_OPTION, 0 );

        if ( ! is_array( $queue ) || 0 === count( $queue ) || $pointer >= count( $queue ) ) {
            return $this->finish();
        }

        $batch  = array_slice( $queue, $pointer, self::BATCH_SIZE );
        $done   = 0;
        $failed = 0;

        foreach ( $batch as $id ) {
            $result = $this->optimizer->optimize_attachment( $id );
            if ( is_wp_error( $result ) ) {
                $failed++;
            } else {
                $done++;
            }
        }

        update_option( self::POINTER_OPTION, $pointer + count( $batch ), false );

        $total = count( $queue );

        return array(
            'finished'    => false,
            'total'       => $total,
            'processed'   => min( $pointer + count( $batch ), $total ),
            'succeeded'   => $done,
            'failed'      => $failed,
            'percent'     => $total > 0 ? (int) round( ( $pointer + count( $batch ) ) / $total * 100 ) : 100,
        );
    }

    /**
     * Finish the run and clear state.
     *
     * @return array
     */
    private function finish() {
        update_option( self::RUNNING_FLAG, 0, false );
        return array(
            'finished'  => true,
            'percent'   => 100,
            'total'     => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
        );
    }

    /**
     * Is a bulk run currently queued/running?
     *
     * @return bool
     */
    public static function is_running() {
        return (int) get_option( self::RUNNING_FLAG, 0 ) === 1;
    }
}
