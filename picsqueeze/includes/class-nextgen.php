<?php
/**
 * Next-gen display: serve WebP/AVIF to browsers that support them.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

/**
 * Class Nextgen
 */
class Nextgen {

    /**
     * Register hooks.
     */
    public function register() {
        if ( Settings::get( 'display_nextgen' ) ) {
            add_filter( 'the_content', array( $this, 'replace_content_images' ), 999 );
            add_filter( 'wp_get_attachment_image_attributes', array( $this, 'attachment_image_attributes' ), 10, 3 );
        }
    }

    /**
     * Next-gen path for a local file.
     *
     * @param string $url Original image URL.
     * @return string URL with extension swapped, or '' if the file does not exist.
     */
    private function url_to_nextgen( $url ) {
        $format = (string) Settings::get( 'convert_format', 'webp' );
        $path   = null;

        $upload = wp_get_upload_dir();
        if ( 0 === strpos( $url, $upload['baseurl'] ) ) {
            $rel  = substr( $url, strlen( $upload['baseurl'] ) );
            $path = $upload['basedir'] . $rel;
        }

        if ( ! $path || ! file_exists( $path ) ) {
            return '';
        }

        $nextgen = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $path );
        if ( $nextgen === $path || ! file_exists( $nextgen ) ) {
            return '';
        }

        return preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $url );
    }

    /**
     * Add next-gen <source> via the srcset filter approach on content images.
     *
     * @param string $content Post content.
     * @return string
     */
    public function replace_content_images( $content ) {
        if ( ! is_main_query() || ! is_singular() ) {
            return $content;
        }

        if ( ! preg_match_all( '/<img\s[^>]*src=["\']([^"\']+\.(jpe?g|png|gif))["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
            return $content;
        }

        foreach ( $matches as $match ) {
            $nextgen = $this->url_to_nextgen( $match[1] );
            if ( ! $nextgen ) {
                continue;
            }

            $source = '<source type="image/' . esc_attr( Settings::get( 'convert_format', 'webp' ) ) . '" src="' . esc_url( $nextgen ) . '" />';
            $tag    = $match[0];

            if ( false !== strpos( $tag, '<picture' ) ) {
                continue;
            }

            $picture = '<picture>' . $source . str_replace( '<img ', '<img ', $tag ) . '</picture>';
            $content = str_replace( $tag, $picture, $content );
        }

        return $content;
    }

    /**
     * Fallback for images printed via wp_get_attachment_image outside content.
     *
     * @param array   $attr Image attributes.
     * @param WP_Post $post Attachment post.
     * @param int     $size Requested size.
     * @return array
     */
    public function attachment_image_attributes( $attr, $post, $size = 'thumbnail' ) {
        if ( isset( $attr['data-picsqueeze-nextgen'] ) ) {
            return $attr;
        }

        $src = wp_get_attachment_image_url( $post->ID, $size );
        if ( ! $src ) {
            return $attr;
        }

        $nextgen = $this->url_to_nextgen( $src );
        if ( $nextgen ) {
            $attr['data-picsqueeze-nextgen'] = $nextgen;
        }

        return $attr;
    }
}
