<?php

/**
 * Return OAuth2 token, or falseShort description
 *
 * @param  string $consumer_key     Consumer key
 * @param  string $consumer_secret  Consumer secret
 * @return mixed                    false on error, OAuth token string on success
 */
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
    
    do_action( 'pre_ozh_ta_get_token' );
    /**
     * This action for plugins to be able to alter HTTP request option prior to polling the API
     * For instance, hook on this action to add:
     * add_filter( 'https_ssl_verify', '__return_false' );
     */
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

/**
 * Is it debug mode ? Return bool
 *
 * @return bool  true if we're in debug mode, false otherwise
 */
function ozh_ta_is_debug() {
    static $is_debug = null;
    
    if( $is_debug === null ) {
        $is_debug = file_exists( dirname( dirname(__FILE__) ).'/debug.log' );
    }
    
    return $is_debug;
}

/**
 * Log debug message in flat file if applicable
 *
 * @param mixed $in  String, array or message to log if we're in debug mode
 */
function ozh_ta_debug( $in ) {
    static $log_debug = null;
    
    if( $log_debug === null )   
        $log_debug = ozh_ta_is_debug();
        
    if( $log_debug === false )
        return;
		
	if( is_array( $in ) or is_object( $in ) )
		$in = print_r( $in, true );
	
	$ts = date('Y-m-d H:i:s');
	
	error_log( "$ts: $in\n", 3, dirname( dirname(__FILE__) ).'/debug.log' );
}

/**
 * Is the plugin configured? Return bool
 *
 * @return bool  true if configured, false otherwise
 */
function ozh_ta_is_configured() {
    global $ozh_ta;
    return ( isset( $ozh_ta['access_token'] ) && $ozh_ta['access_token'] && isset( $ozh_ta['screen_name'] ) && $ozh_ta['screen_name'] );
}

/**
 * Delay before next update
 *
 * @param  bool $human_time  true to get human readable interval in hours/min/sec, false to get a number of seconds
 * @param  bool $long        if $human_time is true, displays "seconds/minutes/hours" if true, "s/m/h" if false
 * @return mixed             string or integer
 */
function ozh_ta_next_update_in( $human_time = true, $long = true ) {
	global $ozh_ta;
	
	$next = wp_next_scheduled( 'ozh_ta_cron_import' );
	$freq = $ozh_ta['refresh_interval'];
	$now = time();
	if( $next < $now && $next )
		$next = $now + $freq - 1;
	
	if( $human_time )
		return ozh_ta_seconds_to_words( $next - $now, $long );
	else 
		return ($next - $now );
}

/**
 * Transform 132456 seconds into x hours y minutes z seconds
 *
 * @param  integer $seconds  Interval in seconds to convert
 * @param  bool    $long     displays "seconds/minutes/hours" if true, "s/m/h" if false
 * @return string             Human readable interval
 */
function ozh_ta_seconds_to_words( $seconds, $long = true ) {
    $ret = "";
    
    $str_hour = $long ? " hour"   : "h";
    $str_min  = $long ? " minute" : "m";
    $str_sec  = $long ? " second" : "s";

    $hours = intval( intval($seconds) / 3600 );
    if( $hours > 0 ) {
        $ret .= $hours.$str_hour;
		$ret .= ( $hours > 1 && $long ? 's ' : ' ' );
    }

    $minutes = intval( $seconds / 60 ) % 60;
    if( $minutes > 0 ) {
        $ret .= $minutes.$str_min;
		$ret .= ( $minutes > 1 && $long ? 's ' : ' ' );
    }
  
    $seconds = $seconds % 60;
    if( $seconds > 0 ) {
		$ret .= $seconds.$str_sec;
		$ret .= ( $seconds > 1 && $long ? 's ' : ' ' );
	}

    return trim( $ret );
}

/**
 * Schedule next twitter archiving
 *
 * @param int $delay  Number of second before next archiving
 */
function ozh_ta_schedule_next( $delay = 30 ) {
	wp_clear_scheduled_hook( 'ozh_ta_cron_import' );
	ozh_ta_debug( "Schedule cleared" );
	if( $delay ) {
		wp_schedule_single_event( time()+$delay, 'ozh_ta_cron_import' );
		ozh_ta_debug( "Schedule next in $delay" );
	}
}

/**
 * Convert hashtags: replace #blah with applicable HTML
 *
 * @param  string $text     Tweet text ("I am very nice #bleh")
 * @param  string $hashtag  Hashtag text ("bleh")
 * @return string           Formatted HTML
 */
function ozh_ta_convert_hashtags( $text, $hashtag ) {
    global $ozh_ta;
    
    switch( $ozh_ta['link_hashtags'] ) {
    
        case 'twitter':
        $replace = sprintf( '<span class="hashtag hashtag_twitter">#<a href="%s">%s</a></span>',
                            'https://twitter.com/search?q=%23' . $hashtag, $hashtag );
        break;    
    
        case 'local':
        $replace = sprintf( '<span class="hashtag hashtag_local">#<a href="%s">%s</a>',
                            ozh_ta_get_tag_link( $hashtag ), $hashtag );
        break;    
    
        case 'no':
        $replace = sprintf( '<span class="hashtag hashtag_no">%s</span>',
                            '#' . $hashtag );
        break;    
    
    }
    
    $text = preg_replace( '/\#' . $hashtag . '/', $replace, $text, 1 );
    
    return $text;
}

/**
 * Convert links
 *
 * @param  string $text          Tweet text ("Wow very cool http://t.co/123AbC")
 * @param  string $expanded_url  Expanded t.co link
 * @param  string $display_url   Link for display
 * @param  string $tco_url       Original t.co URL
 * @return string                Formatted HTML
 */
function ozh_ta_convert_links( $text, $expanded_url, $display_url, $tco_url ) {
    global $ozh_ta;
    
    // If the expanded URL is on twitter.com, return the tweet with a leading newline for autoembedding
    if( parse_url( $expanded_url, PHP_URL_HOST ) == 'twitter.com' ) {
        $replace = "\n\n" . $expanded_url . "\n";
    
    // Other URLs
    } else {
    
        if( $ozh_ta['un_tco'] == 'yes' ) {
            $replace = sprintf( '<a href="%s" title="%s" class="link link_untco">%s</a>',
                                $expanded_url, $expanded_url, $display_url );
        } else {
            $replace = sprintf( '<a href="%s" class="link link_tco">%s</a>',
                                $tco_url, $tco_url );
        }
        
    }
    
    $text = preg_replace( '!' . $tco_url . '!', $replace, $text, 1 ); // using ! instead of / because URLs contain / already ;P

    return $text;
}

/**
 * Manually expand a t.co URL
 *
 * @param  string $url  URL to expand ("http://t.co/blah123")
 * @return string       Expanded URL ("http://some-long-url.com")
 */
function ozh_ta_expand_tco_url( $url ) {
    if( strpos( $url, 'http://t.co/' ) !== 0 )
        return $url;

    $head = wp_remote_head( $url );
    return isset( $head['headers']['location'] ) ? $head['headers']['location'] : $url;
}

/**
 * Convert @mentions to link or to span
 *
 * @param  string $text         Tweet text ("Wow very cool http://t.co/123AbC")
 * @param  string $screen_name  Screen name ("@ozh")
 * @param  string $name         Real name ("Ozh RICHARD")
 * @return string               Formatted HTML
 */
function ozh_ta_convert_mentions( $text, $screen_name, $name ) {
    global $ozh_ta;
    
    if( $ozh_ta['link_usernames'] == 'yes' ) {
        $replace = sprintf( '<span class="username username_linked">@<a href="https://twitter.com/%s" title="%s">%s</a></span>',
                            $screen_name, esc_attr( $name ), $screen_name );
    } else {
        $replace = sprintf( '<span title="%s" class="username username_unlinked">@%s</span>',
                            esc_attr( $name ), $screen_name );
    }
    
    $text = preg_replace( '/\@' . $screen_name . '/', $replace, $text, 1 );
    
    return $text;
}

/**
 * Trim long strings
 *
 * @param  string $text  Text to trim if longer than threshold
 * @param  int $len      Threshold
 * @return string        Trimmed text
 */
function ozh_ta_trim_long_string( $text, $len = 30 ) {
    if( strlen( $text ) > $len )
        $text = substr( $text, 0, $len ) . '...';
    return $text;
}

/**
 * Get link for a given tag.
 *
 * (Note: the tag may or may not actually exist)
 *
 * @param  string $tag  Tag
 * @return string       Tag URL
 */
// 
function ozh_ta_get_tag_link( $tag ) {
	global $wp_rewrite;
	$link = $wp_rewrite->get_tag_permastruct();
    	
	$tag = sanitize_title_with_dashes( remove_accents( $tag ) );
	
	if( empty( $link ) ) {
		// site.com/?tag=bleh
		$link = trailingslashit( home_url() ) . '?tag=' . $tag;
	} else {
		// site.com/tag/bleh/
		$link = str_replace( '%post_tag%', $tag, $link );
		$link = home_url( user_trailingslashit( $link, 'category' ) );
	}
	return apply_filters( 'ozh_ta_get_tag_link', $link );
}

