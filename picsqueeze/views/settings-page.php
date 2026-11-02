<?php
/**
 * Settings page view. Variables in scope: $settings.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

$webp_supported = Compressor::supports_format( 'webp' );
$avif_supported = Compressor::supports_format( 'avif' );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'PicSqueeze', 'picsqueeze' ); ?></h1>

    <?php settings_errors( 'picsqueeze' ); ?>

    <div class="card" style="max-width:800px">
        <h2><?php esc_html_e( 'Local compression', 'picsqueeze' ); ?></h2>
        <p><?php esc_html_e( 'Images are compressed on your own server. No account, no API key, no quota — completely free.', 'picsqueeze' ); ?></p>
        <p>
            <?php
            printf(
                /* translators: %s: WebP or AVIF. */
                esc_html__( 'WebP support: %s', 'picsqueeze' ),
                $webp_supported ? '<strong style="color:green">' . esc_html__( 'available', 'picsqueeze' ) . '</strong>' : '<strong style="color:#b32d2e">' . esc_html__( 'not available on this server', 'picsqueeze' ) . '</strong>'
            );
            ?>
            <br />
            <?php
            printf(
                /* translators: %s: WebP or AVIF. */
                esc_html__( 'AVIF support: %s', 'picsqueeze' ),
                $avif_supported ? '<strong style="color:green">' . esc_html__( 'available', 'picsqueeze' ) . '</strong>' : '<strong style="color:#b32d2e">' . esc_html__( 'not available on this server', 'picsqueeze' ) . '</strong>'
            );
            ?>
        </p>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field( 'picsqueeze_settings', 'picsqueeze_nonce' ); ?>
        <input type="hidden" name="picsqueeze_save" value="1" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Compression level', 'picsqueeze' ); ?></th>
                <td>
                    <?php
                    $levels = array(
                        'normal'     => __( 'Normal (high quality, modest savings)', 'picsqueeze' ),
                        'aggressive' => __( 'Aggressive (good balance)', 'picsqueeze' ),
                        'ultra'      => __( 'Ultra (smallest files, visible quality loss)', 'picsqueeze' ),
                    );
                    foreach ( $levels as $value => $label ) :
                        ?>
                        <label style="display:block;margin-bottom:4px">
                            <input type="radio" name="picsqueeze_compression" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['compression'], $value ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Automation', 'picsqueeze' ); ?></th>
                <td>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="picsqueeze_auto_optimize" value="1" <?php checked( $settings['auto_optimize'] ); ?> />
                        <?php esc_html_e( 'Optimize images automatically on upload', 'picsqueeze' ); ?>
                    </label>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="picsqueeze_auto_convert" value="1" <?php checked( $settings['auto_convert'] ); ?> />
                        <?php esc_html_e( 'Create next-gen versions', 'picsqueeze' ); ?>
                    </label>
                    <label style="display:block;margin-bottom:4px">
                        <select name="picsqueeze_convert_format">
                            <option value="webp" <?php selected( $settings['convert_format'], 'webp' ); ?>>WebP</option>
                            <option value="avif" <?php selected( $settings['convert_format'], 'avif' ); ?>>AVIF</option>
                        </select>
                        <?php esc_html_e( 'Next-gen format', 'picsqueeze' ); ?>
                    </label>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="picsqueeze_display_nextgen" value="1" <?php checked( $settings['display_nextgen'] ); ?> />
                        <?php esc_html_e( 'Serve next-gen versions to supporting browsers', 'picsqueeze' ); ?>
                    </label>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="picsqueeze_keep_originals" value="1" <?php checked( $settings['keep_originals'] ); ?> />
                        <?php esc_html_e( 'Keep a backup of the original images', 'picsqueeze' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Resize large images', 'picsqueeze' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="picsqueeze_resize_enabled" value="1" <?php checked( $settings['resize_enabled'] ); ?> />
                        <?php esc_html_e( 'Scale down images wider than', 'picsqueeze' ); ?>
                    </label>
                    <input type="number" min="0" step="10" name="picsqueeze_resize_max_width" value="<?php echo esc_attr( $settings['resize_max_width'] ); ?>" class="small-text" />
                    <?php esc_html_e( 'pixels', 'picsqueeze' ); ?>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save settings', 'picsqueeze' ) ); ?>
    </form>

    <hr />

    <div class="card" style="max-width:800px">
        <h2><?php esc_html_e( 'Bulk optimization', 'picsqueeze' ); ?></h2>
        <p><?php esc_html_e( 'Optimize every un-optimized image in your media library.', 'picsqueeze' ); ?></p>
        <p>
            <button type="button" class="button button-primary" id="picsqueeze-bulk-start"><?php esc_html_e( 'Optimize all images', 'picsqueeze' ); ?></button>
        </p>
        <div id="picsqueeze-bulk-progress" style="display:none;max-width:600px">
            <div class="progress-bar" style="background:#dcdcde;border-radius:4px;overflow:hidden;height:18px">
                <div id="picsqueeze-bulk-bar" style="background:#2271b1;height:18px;width:0%"></div>
            </div>
            <p id="picsqueeze-bulk-status"></p>
        </div>
    </div>
</div>
