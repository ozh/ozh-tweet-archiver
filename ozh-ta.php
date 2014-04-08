<?php
/*
Plugin Name: Ozh' Tweet Archiver
Plugin URI: http://planetozh.com/blog/my-projects/ozh-tweet-archiver-backup-twitter-with-wordpress/
Description: Archive your tweets and import them as posts. Can convert #hashtags to WordPress tags.
Version: 1.1
Author: Ozh
Author URI: http://ozh.org/
*/

/* History
   1.0     initial release
   1.0.1   fix notice when no tweet found
   1.1     change to Twitter API v1.1
*/

/*
 TODO: 
 
 FIX: backslashes are stripped
 https://twitter.com/ozh/statuses/435501668822966273
 
 FIX: screenshot no longer accurate
 
 CHANGE: turn debug off before shipping to WP
 
*/


// Constants that should work for everyone
define( 'OZH_TA_API', 'https://api.twitter.com/1.1/statuses/user_timeline.json' ); // Twitter API url (1.1 version)
define( 'OZH_TA_BATCH', 50 );	     // How many tweets to import at most. Take it easy on shared hosting.
define( 'OZH_TA_DEBUG', true );      // Log debug messages
define( 'OZH_TA_NEXT_SUCCESS', 10 ); // How long to wait between sucessfull batches
define( 'OZH_TA_NEXT_FAIL', 120 );   // How long to wait after a Fail Whale

global $ozh_ta;

// Action used for cron
add_action( 'ozh_ta_cron_import', 'ozh_ta_cron_import' );

// Other plugin hooks
add_action( 'init',        'ozh_ta_init' );
add_action( 'admin_init',  'ozh_ta_load_admin' );
add_action( 'admin_menu',  'ozh_ta_add_page');
add_filter( 'the_content', 'ozh_ta_add_tags' );

// Import tweets from cron job
function ozh_ta_cron_import() {
	ozh_ta_require( 'import.php' );
	ozh_ta_get_tweets();
}

// Init plugin
function ozh_ta_init() {
	global $ozh_ta;
	
	if( !$ozh_ta = get_option( 'ozh_ta' ) )
		$ozh_ta = ozh_ta_defaults();

	ozh_ta_require( 'template_tags.php' );
	
	ozh_ta_debug( 'Plugin init' );
}

// Require files as needed
function ozh_ta_require( $file ) {
	require_once( dirname(__FILE__).'/inc/'.$file );
}

// Admin init
function ozh_ta_load_admin() {
	global $ozh_ta;
	ozh_ta_require( 'settings.php' );
	ozh_ta_require( 'option-page.php' );
	ozh_ta_init_settings();
	if( !ozh_ta_is_configured() ) {
		add_action( 'admin_notices', 'ozh_ta_notice_config' );
    }
	add_filter( 'plugin_row_meta', 'ozh_ta_plugin_row_meta', 10, 2 );
	add_filter( 'plugin_action_links_'.plugin_basename( __FILE__ ), 'ozh_ta_plugin_actions' );
}

// Is the plugin configured? Return bool
function ozh_ta_is_configured() {
    global $ozh_ta;
    return ( isset( $ozh_ta['access_token'] ) && $ozh_ta['access_token'] && isset( $ozh_ta['screen_name'] ) && $ozh_ta['screen_name'] );
}

// Plugin action links
function ozh_ta_plugin_actions( $actions ) {
	global $ozh_ta;
	$class = '';
	$text = 'Configure';
	if( !ozh_ta_is_configured() ) {
		$class = 'delete';
		$text .= ' now!';
	}
	$actions['ozh_ta '.$class] = '<a class="'.$class.'" href="'.menu_page_url( 'ozh_ta', false ).'">'.$text.'</a>';
	return $actions;
}

// Add link to plugin meta row
function ozh_ta_plugin_row_meta( $plugin_meta, $plugin_file ) {
	if( $plugin_file == plugin_basename( __FILE__ ) ) {
		$plugin_meta[] = '<a href="'.menu_page_url( 'ozh_ta', false ).'"><strong>Configure</strong></a>';
	}
	return $plugin_meta;
}

// Add plugin menu
function ozh_ta_add_page() {
	ozh_ta_debug( 'add_page' );
	$page = add_options_page( 'Ozh\' Tweet Archiver', 'Tweet Archiver', 'manage_options', 'ozh_ta', 'ozh_ta_do_page' );
}

// Add post tags if applicable
// This is hooked into filter 'the_content'
function ozh_ta_add_tags( $text ) {
	global $ozh_ta, $id;
    
    // is there any #hashtag here ?
    if( $ozh_ta['add_hash_as_tags'] == 'yes' && $hashtags = get_post_meta( $id, 'ozh_ta_has_hashtags', true ) ) {
        ozh_ta_debug( "Tagging post $id with ".implode( ', ', $hashtags ) );
        wp_set_post_tags( $id, implode( ', ', $hashtags ) );
        delete_post_meta( $id, 'ozh_ta_has_hashtags' );
    }
	
	return apply_filters( 'ozh_ta_post_linkify', $text );
}

// Get link for a given tag.
// (Note: the tag may or may not actually exist)
function ozh_ta_get_tag_link( $tag ) {
	global $wp_rewrite;
	$link = $wp_rewrite->get_tag_permastruct();
	
	$tag = sanitize_title_with_dashes( $tag );

	if( empty( $link ) ) {
		// site.com/?tag=bleh
		$link = trailingslashit( home_url() ) . '?tag=' . $tag;
	} else {
		// site.com/tag/bleh/
		$link = str_replace( '%tag%', $tag, $link );
		$link = home_url( user_trailingslashit( $link, 'category' ) );
	}
	return apply_filters( 'ozh_ta_get_tag_link', $link );
}

// Default plugin options
function ozh_ta_defaults() {
	global $wpdb;
	
	ozh_ta_debug( 'Loading defaults' );
	
	return array(
		// plugin:
		'refresh_interval'       => 60*60, // 60 minutes
		'post_category'          => get_option('default_category'), // integer
		'post_author'            => $wpdb->get_var("SELECT ID FROM $wpdb->users ORDER BY ID LIMIT 1"), // first user id found
		'link_hashtags'          => 'local', // can be no/local/twitter
		'add_hash_as_tags'       => 'yes', // can be yes/no
		'link_usernames'         => 'yes', // can be yes/no
		'embed_images'           => 'yes', // can be yes/no
		'un_tco'                 => 'yes', // can be yes/no
		'last_tweet_id_inserted' => 1, // ID of last inserted tweet
		'api_page'               => 1, // current page being polled on the API
		
		// twitter user:
		'screen_name'   => '',
		'cons_key'      => '',
		'cons_secret'   => '',
		'access_token'  => '',
		'twitter_stats' => array (),
	);
}

// Delay before next update
function ozh_ta_next_update_in( $human_time = true ) {
	global $ozh_ta;
	
	$next = wp_next_scheduled( 'ozh_ta_cron_import' );
	$freq = $ozh_ta['refresh_interval'];
	$now = time();
	if( $next < $now )
		$next = $now + $freq - 1;
	
	if( $human_time )
		return ozh_ta_seconds_to_words( $next - $now );
	else 
		return ($next - $now );
}


// Transform 132456 seconds into x hours y minutes z seconds
function ozh_ta_seconds_to_words( $seconds ) {
    $ret = "";

    $hours = intval( intval($seconds) / 3600 );
    if( $hours > 0 ) {
        $ret .= "$hours hour";
		$ret .= ( $hours > 1 ? 's ' : ' ' );
    }

    $minutes = intval( $seconds / 60 ) % 60;
    if( $minutes > 0 ) {
        $ret .= "$minutes minute";
		$ret .= ( $minutes > 1 ? 's ' : ' ' );
    }
  
    $seconds = $seconds % 60;
    if( $seconds > 0 ) {
		$ret .= "$seconds second";
		$ret .= ( $seconds > 1 ? 's ' : ' ' );
	}

    return trim( $ret );
}

// Log debug message in flat file
function ozh_ta_debug( $in ) {
	if( !defined( 'OZH_TA_DEBUG' ) || !OZH_TA_DEBUG )
		return;
		
	if( is_array( $in ) or is_object( $in ) )
		$in = print_r( $in, true );
	
	$ts = date('r');
	
	error_log( "$ts: $in\n", 3, dirname(__FILE__).'/debug.log' );
}

// Return OAuth2 token, or false
function ozh_ta_get_token( $consumer_key, $consumer_secret ) {
    $bearer_token_credential = $consumer_key . ':' . $consumer_secret;
    $credentials = base64_encode( $bearer_token_credential );

    $args = array(
        'method'      => 'POST',
        'httpversion' => '1.1',
        'blocking'    => true,
        'body'        => array( 'grant_type' => 'client_credentials' ),
        'headers'     => array( 
            'Authorization' => 'Basic ' . $credentials,
            'Content-Type'  => 'application/x-www-form-urlencoded;charset=UTF-8',
        ),
    );

    add_filter( 'https_ssl_verify', '__return_false' );
    $response = wp_remote_post( 'https://api.twitter.com/oauth2/token', $args );

    $keys = json_decode($response['body']);
    
    if( $keys && isset( $keys->access_token ) ) {
        $return = $keys->access_token;
    } else {
        $return = false;
    }
    
    ozh_ta_debug( 'Getting token: ' . $return );

    return $return;
}
