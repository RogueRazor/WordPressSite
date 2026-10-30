<?php
/**
 * Settings store: a single option holding all plugin configuration.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

    const OPTION = 'picsqueeze_settings';

    /**
     * Cached settings.
     *
     * @var array|null
     */
    private static $cache = null;

    /**
     * Default configuration values.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            'api_key'          => '',
            'compression'      => 'aggressive',
            'auto_optimize'    => true,
            'auto_convert'     => true,
            'convert_format'   => 'webp',
            'display_nextgen'  => true,
            'keep_originals'   => true,
            'resize_enabled'   => false,
            'resize_max_width' => 1920,
            'admin_bar'        => true,
        );
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array
     */
    public static function all() {
        if ( null === self::$cache ) {
            $saved        = get_option( self::OPTION, array() );
            $saved        = is_array( $saved ) ? $saved : array();
            self::$cache  = array_merge( self::defaults(), $saved );
        }
        return self::$cache;
    }

    /**
     * Get one setting.
     *
     * @param string $key Setting key.
     * @param mixed  $fallback Value if missing.
     * @return mixed
     */
    public static function get( $key, $fallback = null ) {
        $all = self::all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
    }

    /**
     * Set one or many settings.
     *
     * @param string|array $keyOrValues Key or key=>value map.
     * @param mixed        $value Value when $keyOrValues is a string.
     * @return bool
     */
    public static function set( $keyOrValues, $value = null ) {
        $values = is_array( $keyOrValues ) ? $keyOrValues : array( $keyOrValues => $value );
        $all    = array_merge( self::all(), $values );
        self::$cache = $all;
        return update_option( self::OPTION, $all, false );
    }

    /**
     * Reset everything to defaults.
     *
     * @return bool
     */
    public static function reset() {
        self::$cache = null;
        return update_option( self::OPTION, self::defaults(), false );
    }

    /**
     * Is a valid API key present?
     *
     * @return bool
     */
    public static function has_api_key() {
        $key = (string) self::get( 'api_key' );
        return '' !== $key;
    }

    /**
     * Capability required to manage the plugin.
     *
     * @return string
     */
    public static function capability() {
        return 'manage_options';
    }
}
