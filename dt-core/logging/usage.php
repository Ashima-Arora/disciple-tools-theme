<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly

class Disciple_Tools_Usage {

    /**
     * Versioning of the usage data
     * @version 1 - ? columns
     *
     * @var int
     */
    public $version = 4;

    public function send_usage() {
        $disabled = apply_filters( 'dt_disable_usage_report', false );
        if ( ! $disabled ) {
            $url = 'https://disciple.tools/wp-json/dt-usage/v1/telemetry';
            $args = [
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'body' => $this->telemetry(),
            ];


            wp_remote_post( $url, $args );
        }
    }

    public function telemetry() {

        global $wp_version, $wp_db_version;

        $system_usage = $this->system_usage();
        $activity = $this->activity();
        $regions = $this->regions();
        $users = new WP_User_Query( [ 'count_total' => true ] );

        $site_url = get_site_url( null, '', 'https' );

        //active plugins
        $network_active_plugins = get_site_option( 'active_sitewide_plugins', [] );
        $active_plugins_options = get_option( 'active_plugins', [] );
        foreach ( $network_active_plugins as $plugin => $time ){
            $active_plugins_options[] = $plugin;
        }
        $active_plugins = array_map( function ( $folder_slash_plugin ){
            return explode( '/', $folder_slash_plugin )[1];
        }, $active_plugins_options );

        //geocoding
        $using_mapbox = (bool) DT_Mapbox_API::get_key();
        $using_google_geocode = (bool) Disciple_Tools_Google_Geocode_API::get_key();

        $data = [
            'validator' => hash( 'sha256', time() ),
            'site_id' => hash( 'sha256', $site_url ),
            'usage_version' => $this->version,
            'payload' => [

                // BASIC STATS
                'site_id' => hash( 'sha256', $site_url ),
                'usage_version' => $this->version,
                'php_version' => phpversion(),
                'wp_version' => $wp_version,
                'wp_db_version' => $wp_db_version,
                'site_url' => $site_url,
                'theme_version' => disciple_tools()->version,

                // SYSTEM USAGE
                'active_contacts' => (string) $system_usage['active_contacts'] ?: '0',
                'total_contacts' => (string) $system_usage['total_contacts'] ?: '0',
                'active_groups' => (string) $system_usage['active_groups'] ?: '0',
                'total_groups' => (string) $system_usage['total_groups'] ?: '0',
                'active_churches' => (string) $system_usage['active_churches'] ?: '0',
                'total_churches' => (string) $system_usage['total_churches'] ?: '0',
                'active_users' => (string) $activity['active_users'] ?: '0',
                'total_users' => (string) $users->get_total() ?: '0',
                'has_demo_data' => !empty( $system_usage['has_demo_data'] ),

                'regions' => $regions ?: '0',
                'timestamp' => gmdate( 'Y-m-d' ),

                //DT Usage
                'active_plugins' => $active_plugins,
                'using_mapbox' => $using_mapbox,
                'using_google_geocode' => $using_google_geocode
            ],
        ];

        return $data;
    }

    private function system_usage() {
        global $wpdb;
        $results = $wpdb->get_row("
            SELECT

            (
            SELECT COUNT(*)
            FROM $wpdb->posts as p
            JOIN $wpdb->postmeta ON p.ID=$wpdb->postmeta.post_id AND meta_key = 'overall_status' AND meta_value = 'active'
            WHERE post_type = 'contacts'
            AND post_status = 'publish'
            ) as active_contacts,

            (
            SELECT COUNT(*)
            FROM $wpdb->posts
            WHERE post_type = 'contacts'
            AND post_status = 'publish'
            AND ID NOT IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'duplicate_of')
            ) as total_contacts,

            (
            SELECT COUNT(*)
            FROM $wpdb->posts
            JOIN $wpdb->postmeta ON $wpdb->posts.ID=$wpdb->postmeta.post_id AND meta_key = 'group_status' AND meta_value = 'active'
            WHERE post_type = 'groups'
            AND post_status = 'publish'
            ) as active_groups,

            (
            SELECT COUNT(*)
            FROM $wpdb->posts
            WHERE post_type = 'groups'
            AND post_status = 'publish'
            ) as total_groups,

            (
            SELECT COUNT(*)
            FROM $wpdb->posts
            JOIN $wpdb->postmeta as s ON $wpdb->posts.ID=s.post_id AND s.meta_key = 'group_status' AND s.meta_value = 'active'
            JOIN $wpdb->postmeta as c ON $wpdb->posts.ID=c.post_id AND c.meta_key = 'group_type' AND c.meta_value = 'church'
            WHERE post_type = 'groups'
            AND post_status = 'publish'
            ) as active_churches,

            (
            SELECT COUNT(*)
            FROM $wpdb->posts
            JOIN $wpdb->postmeta as c ON $wpdb->posts.ID=c.post_id AND c.meta_key = 'group_type' AND c.meta_value = 'church'
            WHERE post_type = 'groups'
            AND post_status = 'publish'
            ) as total_churches,

            (
            SELECT COUNT(*)
            FROM $wpdb->postmeta pm
            WHERE pm.meta_key = '_sample'
            ) as has_demo_data;

            ", ARRAY_A );

        return $results;
    }

    public function activity() {
        global $wpdb;
        $results = $wpdb->get_row("
        SELECT (
            SELECT COUNT(DISTINCT user_id)
            FROM $wpdb->dt_activity_log
            WHERE action = 'logged_in'
            AND user_id != 0
            AND from_unixtime(`hist_time`) BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE()
            AND user_id
        ) as active_users;
        ", ARRAY_A );

        return $results;
    }

    public function regions() {
        $data = '';
        $map_level = get_option( 'dt_mapping_module_starting_map_level' );
        if ( $map_level ) {

            if ( ! empty( $map_level['children'] ) ) {
                $data .= implode( ',', $map_level['children'] );
                if ( $map_level['parent'] !== 'world' ){
                    $data .= $map_level['parent'];
                }
            }
            else {
                $data .= $map_level['parent'];
            }
        }

        return $data;
    }

}

class Disciple_Tools_Usage_Scheduler {

    public function __construct() {
        if ( ! wp_next_scheduled( 'usage' ) ) {
            wp_schedule_event( strtotime( 'today 1am' ), 'weekly', 'usage' );
        }
        add_action( 'usage', [ $this, 'action' ] );
    }

    public static function action(){
        $usage = new Disciple_Tools_Usage();
        $usage->send_usage();
    }
}
new Disciple_Tools_Usage_Scheduler();
