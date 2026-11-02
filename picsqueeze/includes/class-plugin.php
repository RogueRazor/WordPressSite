<?php
/**
 * Plugin bootstrap: registers hooks and wires components together.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Optimizer.
     *
     * @var Optimizer
     */
    private $optimizer;

    /**
     * Bulk runner.
     *
     * @var Bulk
     */
    private $bulk;

    /**
     * Private constructor: wire up all behaviour.
     */
    private function __construct() {
        $this->optimizer = new Optimizer();
        $this->bulk       = new Bulk( $this->optimizer );

        add_action( 'plugins_loaded', array( $this, 'load_components' ) );
        register_activation_hook( PICSQUEEZE_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( PICSQUEEZE_FILE, array( $this, 'deactivate' ) );
    }

    /**
     * Instance accessor.
     *
     * @return Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register components once translations are available.
     */
    public function load_components() {
        if ( is_admin() ) {
            ( new Admin() )->register();
        }

        ( new Auto_Optimizer( $this->optimizer ) )->register();
        ( new Nextgen() )->register();
    }

    /**
     * Activation: nothing stored yet beyond defaults.
     */
    public function activate() {
        if ( false === get_option( Settings::OPTION ) ) {
            Settings::reset();
        }
    }

    /**
     * Deactivation: clear bulk state and caches, keep settings.
     */
    public function deactivate() {
        delete_option( Bulk::QUEUE_OPTION );
        delete_option( Bulk::POINTER_OPTION );
        delete_option( Bulk::RUNNING_FLAG );
        Api_Client::clear_cache();
    }

    /**
     * Optimizer accessor.
     *
     * @return Optimizer
     */
    public function optimizer() {
        return $this->optimizer;
    }

    /**
     * Bulk accessor.
     *
     * @return Bulk
     */
    public function bulk() {
        return $this->bulk;
    }
}
