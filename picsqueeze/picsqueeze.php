<?php
/**
 * Plugin Name: PicSqueeze
 * Description: Reduce image file sizes automatically on upload with fully local, free, open-source compression — no external service required.
 * Version: 2.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: RogueRazor
 * Licence: GPLv2
 * Text Domain: picsqueeze
 *
 * @package PicSqueeze
 */

defined( 'ABSPATH' ) || exit;

define( 'PICSQUEEZE_VERSION', '2.0.0' );
define( 'PICSQUEEZE_FILE', __FILE__ );
define( 'PICSQUEEZE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PICSQUEEZE_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '>=' ) && version_compare( get_bloginfo( 'version' ), '5.8', '>=' ) ) {
    require_once PICSQUEEZE_PATH . 'includes/class-settings.php';
    require_once PICSQUEEZE_PATH . 'includes/class-compressor.php';
    require_once PICSQUEEZE_PATH . 'includes/class-media-data.php';
    require_once PICSQUEEZE_PATH . 'includes/class-optimizer.php';
    require_once PICSQUEEZE_PATH . 'includes/class-auto-optimizer.php';
    require_once PICSQUEEZE_PATH . 'includes/class-bulk.php';
    require_once PICSQUEEZE_PATH . 'includes/class-nextgen.php';
    require_once PICSQUEEZE_PATH . 'includes/class-admin.php';
    require_once PICSQUEEZE_PATH . 'includes/class-plugin.php';

    /**
     * Main plugin instance.
     *
     * @return \PicSqueeze\Plugin
     */
    function picsqueeze() {
        return \PicSqueeze\Plugin::instance();
    }

    picsqueeze();
}
