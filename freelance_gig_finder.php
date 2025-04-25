<?php
/*
Plugin Name: Freelance Gig Finder (Remotive JSON + Alerts + Offset Pagination + Unsubscribe)
Plugin URI:  https://gigfinder.co/
Description: Search Remotive’s JSON API with offset-based pagination, styled table, email alerts (HTML) including job table, subscription confirmation, and unsubscribe links.
Version:     1.2.8
Author:      Mark Z Marketing
Author URI:  https://www.markzmarketing.com
License:     GPLv2+
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remotive_Enhanced_Gig_Finder {
    const TRANSIENT_PREFIX   = 'remotive_jobs_';
    const USER_META_SEARCHES = 'remotive_saved_searches';
    const CRON_HOOK          = 'remotive_send_alerts';

    public function __construct() {
        // Mail configuration
        add_filter( 'wp_mail_from',      [ $this, 'set_mail_from' ] );
        add_filter( 'wp_mail_from_name', [ $this, 'set_mail_from_name' ] );

        // Schedule cron on activation
        register_activation_hook(   __FILE__, [ $this, 'schedule_cron' ] );
        register_deactivation_hook( __FILE__, [ $this, 'clear_cron' ] );
        add_action( self::CRON_HOOK,    [ $this, 'send_email_alerts' ] );

        // Admin settings
        add_action( 'admin_menu',    [ $this, 'add_settings_page' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );

        // Frontend
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_shortcode( 'job_search',       [ $this, 'render_search_form' ] );
        add_shortcode( 'job_list',         [ $this, 'render_job_list' ] );
    }

    public function set_mail_from( $email ) {
        return 'info@gigfinder.co';
    }

    public function set_mail_from_name( $name ) {
        return 'Mark Z Marketing';
    }

    public function schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event(
                time(),
                get_option( 'remotivegf_email_interval', 'twicedaily' ),
                self::CRON_HOOK
            );
        }
    }

    public function clear_cron() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    // Admin settings page
    public function add_settings_page() {
        add_options_page(
            'Gig Finder Settings',
            'Gig Finder',
            'manage_options',
            'remotivegf-settings',
            [ $this, 'settings_page_html' ]
        );
    }

    public function register_settings() {
        register_setting( 'remotivegf_options', 'remotivegf_default_limit', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 10,
        ] );
        register_setting( 'remotivegf_options', 'remotivegf_email_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'twicedaily',
        ] );
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Gig Finder Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'remotivegf_options' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="remotivegf_default_limit">Jobs per page</label></th>
                        <td>
                            <input name="remotivegf_default_limit" id="remotivegf_default_limit" type="number" min="1" max="50"
                                   value="<?php echo esc_attr( get_option('remotivegf_default_limit') ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="remotivegf_email_interval">Email frequency</label></th>
                        <td>
                            <select name="remotivegf_email_interval" id="remotivegf_email_interval">
                                <option value="daily" <?php selected( get_option('remotivegf_email_interval'),'daily' ); ?>>Daily</option>
                                <option value="twicedaily" <?php selected( get_option('remotivegf_email_interval'),'twicedaily' ); ?>>Twice Daily</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'remotivegf-style', plugin_dir_url( __FILE__ ) . 'assets/style.css' );
    }

    public function render_search_form() {
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . wp_login_url( get_permalink() ) . '">log in</a> to search gigs or subscribe.</p>';
        }

        $kw    = sanitize_text_field( $_GET['remotivegf_keyword'] ?? '' );
        $page  = max( 1, intval( $_GET['remotivegf_page'] ?? 1 ) );
        $sub   = isset( $_GET['remotivegf_subscribe'] );
        $unsub = isset( $_GET['remotivegf_unsubscribe'] );

        // Unsubscribe
        if ( $unsub && $kw ) {
            $uid   = get_current_user_id();
            $saves = get_user_meta( $uid, self::USER_META_SEARCHES, true ) ?: [];
            $saves = array_diff( $saves, [ $kw ] );
            update_user_meta( $uid, self::USER_META_SEARCHES, $saves );
            echo '<div class="notice success">Unsubscribed from &ldquo;' . esc_html($kw) . '&rdquo;.</div>';
        }

        // Subscribe
        if ( $sub && $kw && ! $unsub ) {
            $uid   = get_current_user_id();
            $saves = get_user_meta( $uid, self::USER_META_SEARCHES, true ) ?: [];
            if ( ! in_array( $kw, $saves, true ) ) {
                $saves[] = $kw;
                update_user_meta( $uid, self::USER_META_SEARCHES, $saves );
                $this->send_confirmation_email( $kw );
                echo '<div class="notice success">Subscribed to &ldquo;' . esc_html($kw) . '&rdquo;. Check your email.</div>';
            }
        }

        ob_start(); ?>
        <form method="get" class="remotivegf-search">
            <input type="text" name="remotivegf_keyword" placeholder="Keyword" value="<?php echo esc_attr($kw); ?>" />
            <label><input type="checkbox" name="remotivegf_subscribe"<?php checked($sub); ?> /> Email me updates</label>
            <button type="submit">Search</button>
        </form>
        <?php
        if ( $kw ) {
            echo do_shortcode( '[job_list keyword="' . esc_attr($kw) . '" page="' . intval($page) . '"]' );
        }
        return ob_get_clean();
    }

    private function send_confirmation_email( $kw ) {
        $user  = wp_get_current_user();
        $limit = absint( get_option('remotivegf_default_limit',10) );
        $jobs  = $this->fetch_jobs_with_offset( $kw, $limit, 0 );
        $count = count( $jobs );

        $body  = '<p>Hi ' . esc_html($user->display_name) . ',</p>';
        $body .= '<p>You have subscribed to updates for <strong>' . esc_html($kw) . '</strong>. You will receive alerts twice daily.</p>';
        $body .= '<table style="width:100%;border-collapse:collapse;"><thead><tr>' .
                 '<th>Title</th><th>Company</th><th>Posted</th><th>Apply</th>' .
                 '</tr></thead><tbody>';
        foreach ( $jobs as $j ) {
            $body .= '<tr>' .
                     '<td>' . esc_html($j['title']) . '</td>' .
                     '<td>' . esc_html($j['company']) . '</td>' .
                     '<td>' . esc_html($j['date']) . '</td>' .
                     '<td><a href="' . esc_url($j['url']) . '" target="_blank">Apply</a></td>' .
                     '</tr>';
        }
        $body .= '</tbody></table>';

        if ( $count >= $limit ) {
            $more_url = esc_url( add_query_arg([
                'remotivegf_keyword' => rawurlencode($kw),
                'remotivegf_page'    => 2
            ], get_permalink()) );
            $body    .= '<p style="text-align:center;"><a href="' . $more_url . '" style="color:#0008ee;font-weight:bold;text-decoration:underline;">Show More</a></p>';
        } else {
            $body    .= '<p style="text-align:center;"><em>Only ' . $count . ' result(s); no more pages.</em></p>';
        }

        $unsubscribe_url = esc_url( add_query_arg([
            'remotivegf_keyword'     => rawurlencode($kw),
            'remotivegf_unsubscribe' => 1
        ], get_permalink()) );
        $body           .= '<p style="text-align:center;"><em>If you no longer want these emails please:</em></p>';
        $body           .= '<p style="text-align:center;"><a href="' . $unsubscribe_url . '" style="color:#0008ee;font-weight:bold;text-decoration:underline;">UNSUBSCRIBE by Clicking Here</a></p>';
        $body           .= '<p>Thanks,<br>Mark Z Marketing</p>';

        wp_mail( $user->user_email, "Subscribed to '{$kw}' updates", $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    public function render_job_list( $atts ) {
        $atts   = shortcode_atts([ 'keyword'=>'','page'=>1 ], $atts, 'job_list');
        $kw     = sanitize_text_field( $atts['keyword'] );
        $page   = max(1,intval($atts['page']));
        $limit  = absint(get_option('remotivegf_default_limit',10));
        $offset = ($page-1) * $limit;
        $jobs   = $this->fetch_jobs_with_offset($kw,$limit,$offset);

        if ( empty($jobs) ) {
            return '<p>No gigs found.</p>';
        }

        ob_start(); ?>
        <table class="remotivegf-table">
            <thead><tr><th>Title</th><th>Company</th><th>Posted</th><th>Apply</th></tr></thead>
            <tbody>
        <?php foreach($jobs as $j): ?>
            <tr>
                <td><?php echo esc_html($j['title']); ?></td>
                <td><?php echo esc_html($j['company']); ?></td>
                <td><?php echo esc_html($j['date']); ?></td>
                <td><a href="<?php echo esc_url($j['url']); ?>" target="_blank">Apply</a></td>
            </tr>
        <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( count($jobs) === $limit ):
            $next = $page + 1;
            $url  = esc_url(add_query_arg([ 'remotivegf_keyword' => rawurlencode($kw), 'remotivegf_page' => $next ], get_permalink()));
            echo '<p><a class="remotivegf-more" href="'.$url.'">Show More</a></p>';
        endif;
        return ob_get_clean();
    }

    private function fetch_jobs_with_offset($keyword,$limit,$offset) {
        $url  = add_query_arg([ 'search'=>rawurlencode($keyword), 'limit'=>$limit, 'offset'=>$offset ], 'https://remotive.com/api/remote-jobs');
        $resp = wp_remote_get($url, ['timeout'=>15]);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ( empty($data['jobs']) || ! is_array($data['jobs']) ) {
            return [];
        }
        $out = [];
        foreach ($data['jobs'] as $job) {
            $out[] = [
                'title'   => $job['title'] ?? '',
                'company' => $job['company_name'] ?? '',
                'date'    => isset($job['publication_date']) ? date('Y-m-d',strtotime($job['publication_date'])) : '',
                'url'     => $job['url'] ?? '',
            ];
        }
        return $out;
    }

    public function send_email_alerts() {
        $users = get_users(['meta_key'=>self::USER_META_SEARCHES]);
        foreach($users as $u) {
            $searches = get_user_meta($u->ID,self::USER_META_SEARCHES,true);
            if(empty($searches)) continue;
            foreach($searches as $kw) {
                $limit = absint(get_option('remotivegf_default_limit',10));
                $jobs  = $this->fetch_jobs_with_offset($kw,$limit,0);
                $meta  = 'remotive_last_'.md5($kw);
                $old   = get_user_meta($u->ID,$meta,true)?:[];
                $new   = array_filter($jobs, function($j) use ($old) { return ! in_array($j['url'], $old, true); });
                if(empty($new)) continue;
                // Build email
                $body = '<p>New jobs for <strong>'.esc_html($kw).'</strong>:</p>';
                $body .= '<table style="width:100%;border-collapse:collapse;"><thead><tr><th>Title</th><th>Company</th><th>Posted</th><th>Apply</th></tr></thead><tbody>';
                $cnt = 0;
                foreach($new as $j) {
                    if($cnt++ >= $limit) break;
                    $body .= '<tr><td>'.esc_html($j['title']).'</td><td>'.esc_html($j['company']).'</td><td>'.esc_html($j['date']).'</td><td><a href="'.esc_url($j['url']).'">Apply</a></td></tr>';
                }
                $body .= '</tbody></table>';
                if(count($new) >= $limit) {
                    $more = esc_url(add_query_arg(['remotivegf_keyword'=>rawurlencode($kw),'remotivegf_page'=>2],get_permalink()));
                    $body .= '<p style="text-align:center;"><a href="'.$more.'" style="color:#0008ee;font-weight:bold;text-decoration:underline;">Show More</a></p>';
                } else {
                    $body .= '<p style="text-align:center;"><em>Only '.count($new).' new result(s); no more pages.</em></p>';
                }
                $unsub = esc_url(add_query_arg(['remotivegf_keyword'=>rawurlencode($kw),'remotivegf_unsubscribe'=>1],get_permalink()));
                $body .= '<p style="text-align:center;"><em>If you no longer want these emails please:</em></p>';
                $body .= '<p style="text-align:center;"><a href="'.$unsub.'" style="color:#0008ee;font-weight:bold;text-decoration:underline;">UNSUBSCRIBE by Clicking Here</a></p>';
                wp_mail($u->user_email,"New job updates for '{$kw}'",$body,['Content-Type: text/html; charset=UTF-8']);
                update_user_meta($u->ID,$meta,array_column($jobs,'url'));
            }
        }
    }
}

new Remotive_Enhanced_Gig_Finder();

// Redirect subscribers after loginunction redirect_subscriber_on_login( $redirect_to, $request, $user ) {
    if ( isset($user->roles) && is_array($user->roles) && in_array('subscriber',$user->roles,true) ) {
        return home_url('/free-lance-gig-finder/');
    }
    return $redirect_to;
}
add_filter('login_redirect','redirect_subscriber_on_login',10,3);
