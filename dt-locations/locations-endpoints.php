<?php

/**
 * Disciple_Tools_Locations_Endpoints
 *
 * @class   Disciple_Tools_Locations_Endpoints
 * @version 0.1.0
 * @since   0.1.0
 * @package Disciple_Tools
 *
 */

if ( !defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly

/**
 * Class Disciple_Tools_Locations_Endpoints
 */
class Disciple_Tools_Locations_Endpoints
{

    private $version = 1;
    private $context = "dt";
    private $namespace;

    /**
     * Disciple_Tools_Locations_Endpoints The single instance of Disciple_Tools_Locations_Endpoints.
     *
     * @var    object
     * @access private
     * @since  0.1.0
     */
    private static $_instance = null;

    /**
     * Main Disciple_Tools_Locations_Endpoints Instance
     * Ensures only one instance of Disciple_Tools_Locations_Endpoints is loaded or can be loaded.
     *
     * @since  0.1.0
     * @static
     * @return Disciple_Tools_Locations_Endpoints instance
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    } // End instance()

    /**
     * Constructor function.
     *
     * @access public
     * @since  0.1.0
     */
    public function __construct() {
        $this->namespace = $this->context . "/v" . intval( $this->version );
        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
    } // End __construct()

    /**
     * Registers all of the routes associated with locations
     */
    public function add_api_routes() {
        $base = '/locations';

        // Holds all routes for locations
        $routes = [

            $base.'/validate_address' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'validate_address' ],
            ],

            $base.'/auto_build_location' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'auto_build_location' ],
            ],
            $base.'/auto_build_simple_location' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'auto_build_simple_location' ],
            ],
            $base.'/auto_build_levels_from_post' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'auto_build_levels_from_post' ],
            ],

        ];

        // Register each route
        foreach ($routes as $route => $args) {
            register_rest_route( $this->namespace, $route, $args );
        }
    }


    /**
     * Get tract from submitted address
     *
     * @param WP_REST_Request $request
     * @access public
     * @since 0.1
     * @return string|WP_Error The contact on success
     */
    public function validate_address( WP_REST_Request $request ){
        if ( ! current_user_can( 'manage_dt' ) ) {
            return new WP_Error( __METHOD__, 'Insufficient permissions', [] );
        }
        $params = $request->get_json_params();
        if ( isset( $params['address'] ) ){

            $result = Disciple_Tools_Google_Geocode_API::query_google_api( $params['address'] );

            if ( $result['status'] == 'OK' ){
                return $result;
            } else {
                return new WP_Error( "status_error", 'Zero Results', array( 'status' => 400 ) );
            }
        } else {
            return new WP_Error( "param_error", "Please provide a valid address", array( 'status' => 400 ) );
        }
    }

    public function auto_build_location( WP_REST_Request $request ){
        $params = $request->get_json_params();

        if ( isset( $params['data'] ) && isset( $params['type'] ) ){

            if ( !current_user_can( 'publish_locations' ) ) {
                return new WP_Error( __FUNCTION__, "You may not publish a location", [ 'status' => 403 ] );
            }

            $components = $params['components'] ?? [];

            $result = Disciple_Tools_Locations::auto_build_location( $params['data'], $params['type'], $components );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            if ( $result['status'] == 'OK' ){
                return $result;
            } else {
                return new WP_Error( "status_error", 'Zero Results', array( 'status' => 400 ) );
            }
        } else {
            return new WP_Error( "param_error", "Please provide a valid address", array( 'status' => 400 ) );
        }
    }

    public function auto_build_simple_location( WP_REST_Request $request ){
        $params = $request->get_json_params();

        if ( isset( $params['title'] ) ){

            if ( !current_user_can( 'publish_locations' ) ) {
                return new WP_Error( __FUNCTION__, "You may not publish a location", [ 'status' => 403 ] );
            }

            $args = [
                'post_title' => sanitize_text_field( wp_unslash( $params['title'] ) ),
                'post_type' => 'locations',
                'post_status' => 'publish',
            ];
            return wp_insert_post( $args, true );

        } else {
            return new WP_Error( "param_error", "Please provide a valid address", array( 'status' => 400 ) );
        }
    }

    public function auto_build_levels_from_post( WP_REST_Request $request ){
        $params = $request->get_json_params();

        if ( isset( $params['post_id'] ) ){

            if ( !current_user_can( 'publish_locations' ) ) {
                return new WP_Error( __FUNCTION__, "You may not publish a location", [ 'status' => 403 ] );
            }

            $result = Disciple_Tools_Locations::auto_build_location( $params['post_id'], 'post_id' );

            if ( 'OK' == $result['status'] ?? '' ) {
                $posts_created = $result['posts_created'] ?? [];
                $formatted_array = [];

                foreach ( $posts_created as $single_post ) {
                    $item = get_post_meta( $single_post, 'base_name', true );
                    $formatted_array[] = [
                        'id' => md5( $item ),
                        'link' => '<a href="'. esc_url( admin_url() ).'post.php?post='. esc_attr( $single_post ).'&action=edit">'. esc_html( $item ) .'</a>',
                    ];
                }

                return $formatted_array;
            } else {
                return new WP_Error( "processing_error", "Please provide a valid address", array( 'status' => 400 ) );
            }
        } else {
            return new WP_Error( "param_error", "Please provide a valid address", array( 'status' => 400 ) );
        }
    }
}
