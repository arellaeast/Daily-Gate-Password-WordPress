<?php
/**
 * Plugin Name: Daily Gate Password
 * Description: Sets a random daily password on a chosen page (content gate) and exposes REST endpoints to rotate/fetch it. Built for n8n-driven daily rotation.
 * Version: 1.0.0
 * Author: J
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // No direct access.
}

class Daily_Gate_Password {

    const OPTION_PASSWORD   = 'dgp_current_password';
    const OPTION_PAGE_ID    = 'dgp_target_page_id';
    const OPTION_SET_DATE   = 'dgp_password_set_date'; // Y-m-d, site timezone.
    const OPTION_LENGTH     = 'dgp_default_length';
    const OPTION_COOKIE_DAYS = 'dgp_cookie_days'; // 0 = expire end of today.

    const SETTINGS_GROUP = 'dgp_settings_group';
    const SETTINGS_SLUG  = 'daily-gate-password';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_filter( 'post_password_expires', [ $this, 'filter_cookie_expiry' ] );
        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    /**
     * Controls how long the wp-postpass cookie lasts, based on the admin setting.
     * Default (cookie days = 0) expires it at the end of "today" (site timezone),
     * so yesterday's password stops working once the day rolls over. Set it higher
     * if you want a grace period instead of a hard daily cutoff.
     */
    public function filter_cookie_expiry( $expires ) {
        $days = (int) get_option( self::OPTION_COOKIE_DAYS, 0 );

        if ( $days <= 0 ) {
            return strtotime( 'today 23:59:59', current_time( 'timestamp' ) );
        }

        return strtotime( "+{$days} days", current_time( 'timestamp' ) );
    }

    public function register_routes() {
        register_rest_route( 'daily-gate/v1', '/set-password', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_set_password' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'page_id' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ],
                'length' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( 'daily-gate/v1', '/get-password', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_get_password' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );
    }

    /**
     * Requires an authenticated request (WP Application Passwords) from a user
     * who can edit pages. This runs after WP core's application password auth
     * has already resolved the current user.
     */
    public function check_auth() {
        return current_user_can( 'edit_pages' );
    }

    /**
     * Generate and set a new random password on the target page.
     *
     * Body params (all optional):
     *   page_id - override which page gets gated (falls back to saved/default)
     *   length  - password length, default 12
     */
    public function handle_set_password( WP_REST_Request $request ) {
        $page_id = $request->get_param( 'page_id' );
        if ( ! $page_id ) {
            $page_id = (int) get_option( self::OPTION_PAGE_ID );
        }

        if ( ! $page_id || 'page' !== get_post_type( $page_id ) ) {
            return new WP_Error(
                'dgp_invalid_page',
                'No valid page_id provided or saved. Pass page_id once, or set it via update_option in wp-admin/console.',
                [ 'status' => 400 ]
            );
        }

        $default_length = (int) get_option( self::OPTION_LENGTH, 12 );
        $length   = $request->get_param( 'length' ) ?: $default_length;
        $length   = max( 8, min( 64, $length ) ); // sane bounds
        $password = $this->generate_password( $length );

        $result = wp_update_post( [
            'ID'            => $page_id,
            'post_password' => $password,
        ], true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        update_option( self::OPTION_PASSWORD, $password );
        update_option( self::OPTION_PAGE_ID, $page_id );
        update_option( self::OPTION_SET_DATE, current_time( 'Y-m-d' ) );

        return [
            'success'  => true,
            'page_id'  => $page_id,
            'password' => $password,
            'set_date' => current_time( 'Y-m-d' ),
        ];
    }

    /**
     * Return today's password. If it was never set today (e.g. cron didn't run yet),
     * flags that in the response rather than silently returning a stale password.
     */
    public function handle_get_password( WP_REST_Request $request ) {
        $password = get_option( self::OPTION_PASSWORD );
        $set_date = get_option( self::OPTION_SET_DATE );
        $page_id  = (int) get_option( self::OPTION_PAGE_ID );
        $today    = current_time( 'Y-m-d' );

        if ( ! $password ) {
            return new WP_Error(
                'dgp_no_password_set',
                'No password has ever been set. Call set-password first.',
                [ 'status' => 404 ]
            );
        }

        return [
            'password'    => $password,
            'page_id'     => $page_id,
            'set_date'    => $set_date,
            'is_current'  => ( $set_date === $today ),
        ];
    }

    /**
     * Random, URL/email-safe password. Avoids ambiguous characters (0/O, 1/l/I)
     * so it reads cleanly in a newsletter.
     */
    private function generate_password( $length = 12 ) {
        $charset = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        $max = strlen( $charset ) - 1;
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= $charset[ random_int( 0, $max ) ];
        }
        return $password;
    }

    /* ---------------------------------------------------------------------
     * Settings page
     * ------------------------------------------------------------------- */

    public function register_settings_page() {
        add_options_page(
            'Daily Gate Password',
            'Daily Gate Password',
            'manage_options',
            self::SETTINGS_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( self::SETTINGS_GROUP, self::OPTION_PAGE_ID, [
            'sanitize_callback' => 'absint',
        ] );
        register_setting( self::SETTINGS_GROUP, self::OPTION_LENGTH, [
            'sanitize_callback' => function( $value ) {
                return max( 8, min( 64, absint( $value ) ) );
            },
            'default' => 12,
        ] );
        register_setting( self::SETTINGS_GROUP, self::OPTION_COOKIE_DAYS, [
            'sanitize_callback' => 'absint',
            'default' => 0,
        ] );

        add_settings_section(
            'dgp_main_section',
            'Gate Settings',
            function() {
                echo '<p>These control which page gets gated and how the password behaves. The password itself is still rotated by your n8n workflow calling the REST endpoints below — this page does not rotate it for you.</p>';
            },
            self::SETTINGS_SLUG
        );

        add_settings_field(
            'dgp_page_id',
            'Gated page',
            [ $this, 'render_page_id_field' ],
            self::SETTINGS_SLUG,
            'dgp_main_section'
        );

        add_settings_field(
            'dgp_length',
            'Default password length',
            [ $this, 'render_length_field' ],
            self::SETTINGS_SLUG,
            'dgp_main_section'
        );

        add_settings_field(
            'dgp_cookie_days',
            'Access cookie duration',
            [ $this, 'render_cookie_days_field' ],
            self::SETTINGS_SLUG,
            'dgp_main_section'
        );
    }

    public function render_page_id_field() {
        $current = (int) get_option( self::OPTION_PAGE_ID );

        $pages = get_pages( [ 'sort_column' => 'post_title' ] );
        echo '<select name="' . esc_attr( self::OPTION_PAGE_ID ) . '">';
        echo '<option value="0">— Select a page —</option>';
        foreach ( $pages as $page ) {
            printf(
                '<option value="%d" %s>%s (ID %d)</option>',
                $page->ID,
                selected( $current, $page->ID, false ),
                esc_html( $page->post_title ),
                $page->ID
            );
        }
        echo '</select>';
        echo '<p class="description">The page that gets the daily password applied. This is the fallback used when n8n doesn\'t pass a <code>page_id</code> explicitly.</p>';
    }

    public function render_length_field() {
        $current = (int) get_option( self::OPTION_LENGTH, 12 );
        printf(
            '<input type="number" min="8" max="64" name="%s" value="%d" class="small-text" /> characters',
            esc_attr( self::OPTION_LENGTH ),
            $current
        );
        echo '<p class="description">Used when n8n\'s request doesn\'t specify a <code>length</code>. Range 8–64.</p>';
    }

    public function render_cookie_days_field() {
        $current = (int) get_option( self::OPTION_COOKIE_DAYS, 0 );
        printf(
            '<input type="number" min="0" max="30" name="%s" value="%d" class="small-text" /> days',
            esc_attr( self::OPTION_COOKIE_DAYS ),
            $current
        );
        echo '<p class="description">How long a visitor stays "let in" after entering the password, before WordPress asks again. Set to <strong>0</strong> to expire at midnight (site time) the same day it was entered — matches a strict daily rotation. Set higher (e.g. 1–2) if you want a short grace period so people aren\'t locked out right at midnight.</p>';
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $password = get_option( self::OPTION_PASSWORD );
        $set_date = get_option( self::OPTION_SET_DATE );
        $page_id  = (int) get_option( self::OPTION_PAGE_ID );
        $today    = current_time( 'Y-m-d' );
        $is_current = ( $set_date && $set_date === $today );
        ?>
        <div class="wrap">
            <h1>Daily Gate Password</h1>

            <h2>Current status</h2>
            <table class="widefat" style="max-width: 600px; margin-bottom: 2em;">
                <tbody>
                    <tr>
                        <td><strong>Gated page</strong></td>
                        <td><?php echo $page_id ? esc_html( get_the_title( $page_id ) ) . ' (ID ' . $page_id . ')' : '— not set —'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Current password</strong></td>
                        <td><code><?php echo $password ? esc_html( $password ) : '— none set yet —'; ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Last rotated</strong></td>
                        <td>
                            <?php echo $set_date ? esc_html( $set_date ) : '— never —'; ?>
                            <?php if ( $set_date ) : ?>
                                <?php if ( $is_current ) : ?>
                                    <span style="color: #46b450;">(today — current)</span>
                                <?php else : ?>
                                    <span style="color: #dc3232;">(stale — n8n hasn't rotated it today)</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="description">This page is read-only status plus configuration. Rotation itself happens via your n8n workflow calling <code>POST /wp-json/daily-gate/v1/set-password</code> on a daily schedule.</p>

            <form action="options.php" method="post">
                <?php
                settings_fields( self::SETTINGS_GROUP );
                do_settings_sections( self::SETTINGS_SLUG );
                submit_button( 'Save Settings' );
                ?>
            </form>
        </div>
        <?php
    }
}

new Daily_Gate_Password();
