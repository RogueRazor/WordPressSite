<?php
/**
 * HTTP client for the cloud optimization service.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class Api_Client
 */
class Api_Client {

    /**
     * Cache key for user/account data.
     */
    const USER_TRANSIENT = 'picsqueeze_account';

    /**
     * API base URL.
     *
     * @var string
     */
    private $base_url;

    /**
     * Constructor.
     *
     * @param string $base_url API base URL.
     */
    public function __construct( $base_url = PICSQUEEZE_API_BASE ) {
        $this->base_url = untrailingslashit( $base_url ) . '/';
    }

    /**
     * Authorization header for requests.
     *
     * @return string[]
     */
    private function auth_header() {
        return array( 'Authorization' => 'token ' . Settings::get( 'api_key' ) );
    }

    /**
     * Low-level request against the service.
     *
     * @param string $path Endpoint path.
     * @param array  $args wp_remote args.
     * @return array|WP_Error
     */
    private function request( $path, array $args = array() ) {
        if ( ! Settings::has_api_key() ) {
            return new WP_Error( 'picsqueeze_no_key', __( 'No API key configured.', 'picsqueeze' ) );
        }

        $args = array_merge(
            array(
                'timeout' => 30,
                'headers' => array_merge( $this->auth_header(), array( 'Accept' => 'application/json' ) ),
            ),
            $args
        );

        $response = wp_remote_request( $this->base_url . ltrim( $path, '/' ), $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( $code < 200 || $code >= 300 ) {
            $message = isset( $body->message ) ? (string) $body->message : __( 'The optimization service returned an error.', 'picsqueeze' );
            return new WP_Error( 'picsqueeze_http_' . $code, $message, array( 'status' => $code ) );
        }

        return $body;
    }

    /**
     * Fetch account details (plan, quota).
     *
     * @return object|WP_Error
     */
    public function get_account() {
        $cached = get_transient( self::USER_TRANSIENT );
        if ( false !== $cached ) {
            return $cached;
        }

        $account = $this->request( 'users/me/', array( 'timeout' => 10 ) );

        if ( is_wp_error( $account ) ) {
            return $account;
        }

        $account = $this->normalize_account( $account );
        set_transient( self::USER_TRANSIENT, $account, 5 * MINUTE_IN_SECONDS );
        return $account;
    }

    /**
     * Ensure expected account fields exist.
     *
     * @param object $account Raw account object.
     * @return object
     */
    private function normalize_account( $account ) {
        $defaults = array(
            'account_type'                 => 'free',
            'quota'                        => 0,
            'extra_quota'                  => 0,
            'extra_quota_consumed'         => 0,
            'consumed_current_month_quota' => 0,
        );
        foreach ( $defaults as $field => $value ) {
            if ( ! isset( $account->$field ) ) {
                $account->$field = $value;
            }
        }
        return $account;
    }

    /**
     * Validate an API key (before saving it).
     *
     * @param string $key Key to check.
     * @return true|WP_Error
     */
    public function check_key( $key ) {
        $temporarily = Settings::get( 'api_key' );
        Settings::set( 'api_key', $key );
        delete_transient( self::USER_TRANSIENT );

        $account = $this->get_account();
        Settings::set( 'api_key', $temporarily );
        delete_transient( self::USER_TRANSIENT );

        if ( is_wp_error( $account ) ) {
            return $account;
        }
        return true;
    }

    /**
     * Optimize an image by uploading it.
     *
     * @param string $file_path Absolute path to the image.
     * @param string $level normal|aggressive|ultra.
     * @param bool   $to_nextgen Also request a WebP/AVIF variant.
     * @param string $nextgen_format webp|avif.
     * @return object|WP_Error Service response.
     */
    public function optimize_image( $file_path, $level = 'aggressive', $to_nextgen = false, $nextgen_format = 'webp' ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'picsqueeze_no_file', __( 'The image file could not be found.', 'picsqueeze' ) );
        }

        if ( filesize( $file_path ) > PICSQUEEZE_MAX_FILE_SIZE ) {
            return new WP_Error( 'picsqueeze_too_large', __( 'The image exceeds the maximum size the service accepts.', 'picsqueeze' ) );
        }

        $fields = array(
            'image'   => new CURLFile( $file_path ), // phpcs:ignore
            'context' => 'wp',
        );

        if ( in_array( $level, array( 'normal', 'aggressive', 'ultra' ), true ) ) {
            $fields['optimization_level'] = $level;
        }

        if ( $to_nextgen ) {
            $fields['extra'] = wp_json_encode(
                array( 'convert-to' => array( $nextgen_format ) )
            );
        }

        $response = $this->request(
            'upload/',
            array(
                'method'  => 'POST',
                'timeout' => 60,
                'headers' => $this->auth_header(),
                'body'    => $fields,
            )
        );

        return $response;
    }

    /**
     * Download the optimized file to a local path.
     *
     * @param string $url Remote file URL.
     * @param string $destination Absolute local path.
     * @return true|WP_Error
     */
    public function download_result( $url, $destination ) {
        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 60,
                'headers' => array(),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $content = wp_remote_retrieve_body( $response );
        if ( '' === $content ) {
            return new WP_Error( 'picsqueeze_empty', __( 'The optimized file download returned no data.', 'picsqueeze' ) );
        }

        if ( false === file_put_contents( $destination, $content ) ) { // phpcs:ignore
            return new WP_Error( 'picsqueeze_write', __( 'The optimized file could not be saved.', 'picsqueeze' ) );
        }

        return true;
    }

    /**
     * Invalidate cached account data.
     */
    public static function clear_cache() {
        delete_transient( self::USER_TRANSIENT );
    }
}
