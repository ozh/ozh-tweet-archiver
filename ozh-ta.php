<?php
/*
Plugin Name: Ozh' Tweet Archiver
Plugin URI: http://planetozh.com/blog/my-projects/ozh-tweet-archiver-backup-twitter-with-wordpress/
Description: Archive your tweets and import them as posts
Version: 2.0.4
Author: Ozh
Author URI: http://ozh.org/
*/

/**
 * History
 * 1.0     initial release
 *
 * 1.0.1   fix notice when no tweet found
 *
 * 2.0     change to Twitter API v1.1, with help from @EHER
 *         allow embedding of images
 *         allow unwrapping t.co links
 *         lots of tweaks
 *
 * 2.0.1   post formats, thanks to @chipbennett
 *         cronjob should be smarters
 *         tweaks here and there
 *
 * 2.0.2   post formats -- fixed -- thanks to @hatsumatsu
 *         time offset, thanks to @GoldDave
 *         new template tag: ozh_ta_is_retweet_or_not()
 *
 * 2.0.3   fix hashtags with umlauts (and probably other funky chars) -- thanks to pep
 *
 * 2.0.4   put retweeted tweets on their own line to allow for auto embed -- see issue 12
 *
 */

/*
 FIXME Known bug:
 The plugin will eat backslashes. A simple fix would be to replace \ with &#92; prior to inserting, but I haven't checked
 all potential unwanted side effects yet.
*/

// Constants that should work for everyone
define( 'OZH_TA_API', 'https://api.twitter.com/1.1/statuses/user_timeline.json' ); // Twitter API url (1.1 version)
define( 'OZH_TA_BATCH', 15 );	    // How many tweets to import at most. Take it easy on shared hosting.
define( 'OZH_TA_NEXT_SUCCESS', 5 ); // How long to wait between sucessfull batches (in seconds)
define( 'OZH_TA_NEXT_FAIL', 120 );  // How long to wait after a Fail Whale (in seconds)

global $ozh_ta;

// Action used for cron
add_action( 'ozh_ta_cron_import', 'ozh_ta_cron_import' );

// Other plugin hooks
add_action( 'init',        'ozh_ta_init' );
add_action( 'admin_init',  'ozh_ta_load_admin' );
add_action( 'admin_menu',  'ozh_ta_add_page');
add_filter( 'the_content', 'ozh_ta_convert_old_posts' );

// Import tweets from cron job
function ozh_ta_cron_import() {
	ozh_ta_require( 'import.php' );
    ozh_ta_debug( 'Starting cron job' );
	ozh_ta_get_tweets();
}

// Init plugin
function ozh_ta_init() {
	global $ozh_ta;
	
	ozh_ta_require( 'utils.php' );
	ozh_ta_require( 'template_tags.php' );
    
    $ozh_ta = get_option( 'ozh_ta' );
    if( $ozh_ta == false ) {
        $ozh_ta = ozh_ta_defaults();
    } else {
        $ozh_ta = array_merge( ozh_ta_defaults(), $ozh_ta );
    }
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
	$page = add_options_page( 'Ozh\' Tweet Archiver', 'Tweet Archiver', 'manage_options', 'ozh_ta', 'ozh_ta_do_page' );
}

// Default plugin options
function ozh_ta_defaults() {
	global $wpdb;
	
	return array(
		// plugin:
		'refresh_interval'       => 60*60, // 60 minutes
		'post_category'          => get_option('default_category'), // integer
		'post_format'            => 'standard', // can be any of the values returned by get_post_format_slugs()
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

// Require files as needed
function ozh_ta_require( $file ) {
	require_once( dirname(__FILE__).'/inc/'.$file );
}

// Convert links, @mentions and #hashtags from older posts
function ozh_ta_convert_old_posts( $text ) {

    // Has this post already been converted? Assuming a span means already formatted
    if( strpos( $text, '<span class="' ) !== false )
        return $text;

    global $ozh_ta;
    
    // Get unformatted title: this will be the unmodified original tweet -- pure text, no HTML
    global $post;
    $title = $post->post_title;
    $ID    = $post->ID;
    
    // Keep track of whether the post has been formatted
    $updated = false;

    // Tweet has links that have not been converted
    if( ( strpos( $title, 'http://' ) !== false OR strpos( $title, 'https://' ) !== false ) && strpos( $text, 'class="link' ) === false ) {
        preg_match_all( '!https?://\S*!', $title, $matches );
        foreach( $matches[0] as $url ) {
            
            // t.co URL ?
            if( $ozh_ta['un_tco'] == 'yes' && strpos( $url, 'http://t.co/' ) === 0 ) {
                $expanded_url = ozh_ta_expand_tco_url( $url );
                $tco_url      = $url;
            } else {
                $expanded_url = $tco_url = $url;
            }
            $display_url  = ozh_ta_trim_long_string( preg_replace( '/https?:\/\//', '', $expanded_url ) );
            
            $text = ozh_ta_convert_links( $text, $expanded_url, $display_url, $tco_url );
        }
        $updated = true;
    }
    
    // Tweet has @mentions that have not been converted
    if( strpos( $title, '@' ) !== false && strpos( $text, 'class="username' ) === false ) {
        preg_match_all( '/\B@(\w+)/', $title, $matches ); // good news, this won't match joe@site.com
        if( isset( $matches[1] ) ) {
            foreach( $matches[1] as $mention ) {
                $text = ozh_ta_convert_mentions( $text, $mention, $mention );
            }
        }
        $updated = true;
    }
    
    // Tweet has #hashtags that have not been converted
    if( strpos( $title, '#' ) !== false && strpos( $text, 'class="hashtag' ) === false ) {
        preg_match_all( '/\B#(\w*[a-zA-Z-]+\w*)/', $text, $matches );
        if( isset( $matches[1] ) ) {
            foreach( $matches[1] as $tag ) {
                $text = ozh_ta_convert_hashtags( $text, $tag );
            }
            
            if( $ozh_ta['add_hash_as_tags'] == 'yes' ) {
                wp_set_post_tags( $ID, implode( ', ', $matches[1] ) );
            }
        }
        $updated = true;
    }
    
    // Did we alter the post? Update it, then
    if( $updated ) {
        $post->post_content = $text;
        wp_update_post( $post );
    }
    
    return $text;
}


