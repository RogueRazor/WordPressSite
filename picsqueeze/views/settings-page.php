<?php
/**
 * Settings page view. Variables in scope: $settings, $account, $connected.
 *
 * @package PicSqueeze
 */

namespace PicSqueeze;

defined( 'ABSPATH' ) || exit;

$account_ok = $connected && ! is_wp_error( $account );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'PicSqueeze', 'picsqueeze' ); ?></h1>

    <?php settings_errors( 'picsqueeze' ); ?>

    <div class="card" style="max-width:800px">
        <h2><?php esc_html_e( 'Account', 'picsqueeze' ); ?></h2>
        <?php if ( $account_ok ) : ?>
            <p>
                <?php
                printf(
                    esc_html__( 'Connected as %1$s (%2$s plan).', 'picsqueeze' ),
                    esc_html( $account->email ?? '' ),
                    esc_html( $account->account_type ?? 'free' )
                );
                ?>
            </p>
            <?php if ( isset( $account->quota, $account->consumed_current_month_quota ) ) : ?>
                <p>
                    <?php
                    printf(
                        esc_html__( 'Monthly quota: %1$s used of %2$s MB.', 'picsqueeze' ),
                        esc_html( number_format_i18n( (int) $account->consumed_current_month_quota ) ),
                        esc_html( number_format_i18n( (int) $account->quota ) )
                    );
                    ?>
                </p>
            <?php endif; ?>
        <?php else : ?>
            <p><?php esc_html_e( 'Not connected. Paste your API key below to enable optimization.', 'picsqueeze' ); ?></p>
        <?php endif; ?>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field( 'picsqueeze_settings', 'picsqueeze_nonce' ); ?>
        <input type="hidden" name="picsqueeze_save" value="1" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="picsqueeze_api_key"><?php esc_html_e( 'API key', 'picsqueeze' ); ?></label></th>
                <td>
                    <input type="text" class="regular-text" id="picsqueeze_api_key" name="picsqueeze_api_key" value="" autocomplete="off" />
                    <?php if ( $settings['api_key'] ) : ?>
                        <p class="description"><?php esc_html_e( 'A key is saved. Enter a new one to replace it; leave blank to keep it.', 'picsqueeze' ); ?></p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'picsqueeze' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Compression level', 'picsqueeze' ); ?></th>
                <td>
                    <?php foreach ( array( 'normal' => __( 'Normal', 'picsqueeze' ), 'aggressive' => __( 'Aggressive', 'picsqueeze' ), 'ultra' => __( 'Ultra', 'picsqueeze' ) ) as $value => $label ) : ?>
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
                        <?php esc_html_e( 'Create WebP/AVIF versions', 'picsqueeze' ); ?>
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
