<?php
/*
Plugin Name: Ozh' Tweet Archiver
Plugin URI: http://planetozh.com/blog/my-projects/ozh-tweet-archiver-backup-twitter-with-wordpress/
Description: Archive your tweets and import them as posts. Can convert #hashtags to WordPress tags.
Version: 1.0.1
Author: Ozh
Author URI: http://ozh.org/
*/

/* History
   1.0     initial release
   1.0.1   fix notice when no tweet found
*/

// Constants that should work for everyone
define( 'OZH_TA_API', 'http://api.twitter.com/1/statuses/user_timeline.json' ); // Twitter API url (no auth needed)
define( 'OZH_TA_BATCH', 100 );	// How many tweets to import at most. 200 is the max allowed on Twitter. Take it easy on shared hosting.
define( 'OZH_TA_DEBUG', false ); // Log debug messages
define( 'OZH_TA_NEXT_SUCCESS', 10 ); // How long to wait between sucessfull batches
define( 'OZH_TA_NEXT_FAIL', 90 ); // How long to wait after a Fail Whale

global $ozh_ta;

// Action used for cron
add_action( 'ozh_ta_cron_import', 'ozh_ta_cron_import' );

// Other plugin hooks
add_action( 'init',        'ozh_ta_init' );
add_action( 'admin_init',  'ozh_ta_load_admin' );
add_action( 'admin_menu',  'ozh_ta_add_page');
add_filter( 'the_content', 'ozh_ta_linkify' );

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
	if( !$ozh_ta['screen_name'] )
		add_action( 'admin_notices', 'ozh_ta_notice_config' );
	add_filter( 'plugin_row_meta', 'ozh_ta_plugin_row_meta', 10, 2 );
	add_filter( 'plugin_action_links_'.plugin_basename( __FILE__ ), 'ozh_ta_plugin_actions' );
}

// Plugin action links
function ozh_ta_plugin_actions( $actions ) {
	global $ozh_ta;
	$class = '';
	$text = 'Configure';
	if( !isset( $ozh_ta['screen_name'] ) or empty( $ozh_ta['screen_name'] ) ) {
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

// Add links to URLs, @usernames and #hashtags.
// This is to be hooked into filter 'the_content'
function ozh_ta_linkify( $text ) {
	global $ozh_ta;
	
	// Linkify twitter names if applicable
	if( $ozh_ta['link_usernames'] == 'yes' ) {
		$nofollow = apply_filters( 'ozh_ta_username_nofollow', 'rel="nofollow"' );
		$text = preg_replace(
			'/(\W)@(\w+)/',
			'\\1@<a href="http://twitter.com/\\2" '.$nofollow.'>\\2</a>',
			$text
		);
	}
	
	if( $ozh_ta['link_hashtags'] != 'no' ) {
		// find hashtags
		preg_match_all( '/\B#(\w*[a-zA-Z-]+\w*)/', $text, $matches );
		$hashtags = $matches[0]; // #bleh
		$tags = $matches[1];     //  bleh
		unset( $matches );
		
		if( $hashtags ) {
		
			// Check if post has been already tagged, if not, tag it
			global $id;
			if( !get_post_meta( $id, 'ozh_ta_tagged', true ) ){
				ozh_ta_debug( "Tagging post $id with ".implode( ', ', $hashtags ) );
				wp_set_post_tags( $id, implode( ', ', $tags ) );
				add_post_meta( $id, 'ozh_ta_tagged', '1', true );
			}
			
			$nofollow = apply_filters( 'ozh_ta_hashtag_nofollow', 'rel="nofollow"' );
			// Replace the array $tag with an array of links
			array_walk( $tags, 'ozh_ta_linkify_'.$ozh_ta['link_hashtags'], $nofollow );
			// Linkify hashtags
			$text = str_replace( $hashtags, $tags, $text );	
		}
	}
	
	// Linkify other links. Note: nofollowed by WP and there's no filters on this
	$text = make_clickable( $text );

	return apply_filters( 'ozh_ta_post_linkify', $text );
}

// Create a Twitter search link (array_walk() callback)
function ozh_ta_linkify_twitter( &$tag, $key, $nofollow ) {
	$tag = '<a href="http://search.twitter.com/search?q=%23'.$tag.'" '.$nofollow.'>#'.$tag.'</a>';
}

// Create a local tag link (array_walk() callback)
function ozh_ta_linkify_local( &$tag, $key, $nofollow ) {
	$tag = '<a href="'.ozh_ta_get_tag_link( $tag ).'">#'.$tag.'</a>';
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
		'refresh_interval' => 5*60, // 5 minutes
		'post_category' => get_option('default_category'), // integer
		'post_author' => $wpdb->get_var("SELECT ID FROM $wpdb->users ORDER BY ID LIMIT 1"), // first user id found
		'link_hashtags' => 'twitter', // can be no/local/twitter
		'add_hash_as_tags' => 'yes', // can be yes/no
		'link_usernames' => 'yes', // can be yes/no
		'last_tweet_id_inserted' => 1, // ID of last inserted tweet
		'api_page' => 1, // current page being polled on the API
		
		// twitter user:
		'screen_name' => '',
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
