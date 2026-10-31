<?php
/**
 * Automatic optimization on upload, via wp_generate_attachment_metadata.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Optimizer
 */
class Auto_Optimizer {

    /**
     * Optimizer instance.
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
     * Hook into upload flow.
     */
    public function register() {
        add_action( 'wp_generate_attachment_metadata', array( $this, 'handle_upload' ), 10, 2 );
    }

    /**
     * Optimize a freshly uploaded attachment.
     *
     * @param array $metadata Attachment metadata.
     * @param int   $id Attachment ID.
     * @return array Untouched metadata.
     */
    public function handle_upload( $metadata, $id ) {
        if ( ! Settings::get( 'auto_optimize' ) ) {
            return $metadata;
        }

        if ( ! Settings::has_api_key() ) {
            return $metadata;
        }

        $this->optimizer->optimize_attachment( $id );

        return $metadata;
    }
}
