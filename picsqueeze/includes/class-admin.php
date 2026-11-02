<?php
/**
 * Admin page, settings form and AJAX endpoints.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class Admin
 */
class Admin {

    /**
     * Nonce action shared by all AJAX endpoints.
     */
    const NONCE_ACTION = 'picsqueeze_admin';

    /**
     * Page hook suffix.
     *
     * @var string
     */
    private $hook = '';

    /**
     * Optimizer.
     *
     * @var Optimizer
     */
    private $optimizer;

    /**
     * Bulk.
     *
     * @var Bulk
     */
    private $bulk;

    /**
     * Constructor.
     *
     * @param Optimizer|null $optimizer Optional optimizer.
     * @param Bulk|null      $bulk Optional bulk runner.
     */
    public function __construct( Optimizer $optimizer = null, Bulk $bulk = null ) {
        $this->optimizer = $optimizer ? $optimizer : new Optimizer();
        $this->bulk      = $bulk ? $bulk : new Bulk( $this->optimizer );
    }

    /**
     * Register hooks.
     */
    public function register() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'save_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'wp_ajax_picsqueeze_bulk_start', array( $this, 'ajax_bulk_start' ) );
        add_action( 'wp_ajax_picsqueeze_bulk_step', array( $this, 'ajax_bulk_step' ) );
        add_action( 'wp_ajax_picsqueeze_optimize_one', array( $this, 'ajax_optimize_one' ) );
        add_action( 'wp_ajax_picsqueeze_restore_one', array( $this, 'ajax_restore_one' ) );
        add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );
    }

    /**
     * Settings page under Settings.
     */
    public function add_menu() {
        $this->hook = add_options_page(
            __( 'PicSqueeze', 'picsqueeze' ),
            __( 'PicSqueeze', 'picsqueeze' ),
            Settings::capability(),
            'picsqueeze',
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue admin JS only on our page.
     *
     * @param string $hook Current admin hook.
     */
    public function enqueue( $hook ) {
        if ( $hook !== $this->hook ) {
            return;
        }

        wp_enqueue_script(
            'picsqueeze-admin',
            PICSQUEEZE_URL . 'assets/admin.js',
            array(),
            PICSQUEEZE_VERSION,
            true
        );

        wp_localize_script(
            'picsqueeze-admin',
            'picsqueezeAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
                'i18n'     => array(
                    'running'  => __( 'Optimizing…', 'picsqueeze' ),
                    'done'     => __( 'Bulk optimization finished.', 'picsqueeze' ),
                    'failed'   => __( 'Bulk run stopped due to an error.', 'picsqueeze' ),
                    'restored' => __( 'Image restored.', 'picsqueeze' ),
                    'error'    => __( 'Request failed.', 'picsqueeze' ),
                ),
            )
        );
    }

    /**
     * Save the settings form (classic, non-REST settings API for simplicity).
     */
    public function save_settings() {
        if ( ! isset( $_POST['picsqueeze_nonce'], $_POST['picsqueeze_save'] ) ) {
            return;
        }

        if ( ! current_user_can( Settings::capability() ) ) {
            return;
        }

        check_admin_referer( 'picsqueeze_settings', 'picsqueeze_nonce' );

        $compression = isset( $_POST['picsqueeze_compression'] ) ? sanitize_key( wp_unslash( $_POST['picsqueeze_compression'] ) ) : 'aggressive';
        if ( ! in_array( $compression, array( 'normal', 'aggressive', 'ultra' ), true ) ) {
            $compression = 'aggressive';
        }

        $format = isset( $_POST['picsqueeze_convert_format'] ) ? sanitize_key( wp_unslash( $_POST['picsqueeze_convert_format'] ) ) : 'webp';
        if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
            $format = 'webp';
        }

        $new_key = isset( $_POST['picsqueeze_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['picsqueeze_api_key'] ) ) : '';

        Settings::set(
            array(
                'compression'     => $compression,
                'auto_optimize'   => ! empty( $_POST['picsqueeze_auto_optimize'] ),
                'auto_convert'    => ! empty( $_POST['picsqueeze_auto_convert'] ),
                'convert_format'  => $format,
                'display_nextgen' => ! empty( $_POST['picsqueeze_display_nextgen'] ),
                'keep_originals'   => ! empty( $_POST['picsqueeze_keep_originals'] ),
                'resize_enabled'   => ! empty( $_POST['picsqueeze_resize_enabled'] ),
                'resize_max_width' => max( 0, isset( $_POST['picsqueeze_resize_max_width'] ) ? (int) $_POST['picsqueeze_resize_max_width'] : 0 ),
            )
        );

        if ( $new_key && $new_key !== (string) Settings::get( 'api_key' ) ) {
            $check = ( new Api_Client() )->check_key( $new_key );
            if ( is_wp_error( $check ) ) {
                add_settings_error( 'picsqueeze', 'api_key', __( 'The API key could not be validated: ', 'picsqueeze' ) . $check->get_error_message() );
            } else {
                Settings::set( 'api_key', $new_key );
                add_settings_error( 'picsqueeze', 'api_key', __( 'API key saved.', 'picsqueeze' ), 'success' );
            }
        }

        add_settings_error( 'picsqueeze', 'saved', __( 'Settings saved.', 'picsqueeze' ), 'success' );
    }

    /**
     * Verify AJAX nonce + capability.
     *
     * @return void Dies on failure.
     */
    private function verify_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( Settings::capability() ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'picsqueeze' ) ), 403 );
        }
    }

    /**
     * Start a bulk run.
     */
    public function ajax_bulk_start() {
        $this->verify_ajax();
        Api_Client::clear_cache();
        $count = $this->bulk->build_queue();
        wp_send_json_success(
            array(
                'queued' => $count,
                'empty'  => 0 === $count,
            )
        );
    }

    /**
     * Process one bulk batch.
     */
    public function ajax_bulk_step() {
        $this->verify_ajax();
        $result = $this->bulk->process_batch();
        wp_send_json_success( $result );
    }

    /**
     * Optimize a single attachment.
     */
    public function ajax_optimize_one() {
        $this->verify_ajax();
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $result = $this->optimizer->optimize_attachment( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => __( 'Image optimized.', 'picsqueeze' ) ) );
    }

    /**
     * Restore a single attachment.
     */
    public function ajax_restore_one() {
        $this->verify_ajax();
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $result = $this->optimizer->restore_attachment( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => __( 'Image restored.', 'picsqueeze' ) ) );
    }

    /**
     * Add an optimization column to the media list.
     *
     * @param array $columns Columns.
     * @return array
     */
    public function media_column( $columns ) {
        $columns['picsqueeze'] = __( 'PicSqueeze', 'picsqueeze' );
        return $columns;
    }

    /**
     * Render the media column content.
     *
     * @param string $column Column key.
     * @param int     $id Attachment ID.
     */
    public function media_column_content( $column, $id ) {
        if ( 'picsqueeze' !== $column ) {
            return;
        }

        $data = new Media_Data( $id );
        $record = $data->read();

        if ( empty( $record['sizes'] ) ) {
            echo '<button class="button button-small picsqueeze-action" data-task="optimize" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Optimize', 'picsqueeze' ) . '</button>';
            return;
        }

        $original = $data->original_bytes();
        $optimized = $data->optimized_bytes();

        if ( $original > 0 && $optimized < $original ) {
            $percent = (int) round( ( $original - $optimized ) / $original * 100 );
            echo '<strong>' . esc_html( sprintf( __( '−%d%% smaller', 'picsqueeze' ), $percent ) ) . '</strong><br />';
        }

        echo '<button class="button button-small picsqueeze-action" data-task="restore" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Restore', 'picsqueeze' ) . '</button>';
    }

    /**
     * Render the settings page.
     */
    public function render_page() {
        if ( ! current_user_can( Settings::capability() ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'picsqueeze' ) );
        }

        $settings  = Settings::all();
        $account   = Settings::has_api_key() ? ( new Api_Client() )->get_account() : null;
        $connected = is_wp_error( $account ) ? false : ( is_object( $account ) && empty( $account->id ) ? false : true );

        require PICSQUEEZE_PATH . 'views/settings-page.php';
    }
}
