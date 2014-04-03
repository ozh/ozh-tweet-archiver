<?php
/* Template tags:
 * Instead of using ozh_ta_echo_something() functions, use the identically named actions:
 * do_action( 'ozh_ta_echo_something' )
 * This way, when you deactivate the plugin, your theme won't break
 */

// Template tag: "From Tweetdeck"
function ozh_ta_source( $echo = true ) {
	$what = ozh_ta_get_meta( 'source' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_source', 'ozh_ta_source', 10, 0 );

// Template tag: tweet id (eg the 13456464123 part of the http://twitter.com/ozh/statuses/13456464123 )
function ozh_ta_id( $echo = true ) {
	$what = ozh_ta_get_meta( 'id' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_id', 'ozh_ta_id', 10, 0 );

// Template tag: tweet link (http://twitter.com/ozh/statuses/13456464123)
function ozh_ta_tweet_link( $echo = true ) {
	global $ozh_ta;
	$link = 'http://twitter.com/'.$ozh_ta['screen_name'].'/statuses/'.ozh_ta_get_meta( 'id' );
	if( $echo )
		echo $link;
	return $link;
}
add_action( 'ozh_ta_tweet_link', 'ozh_ta_tweet_link', 10, 0 );

// Template tag: the reply-to name
function ozh_ta_reply_to_name( $echo = true ) {
	$what = ozh_ta_get_meta( 'reply_to_name' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_reply_to_name', 'ozh_ta_reply_to_name', 10, 0 );

// Template tag: the reply-to tweet id
function ozh_ta_reply_to_tweet( $echo = true ) {
	$what = ozh_ta_get_meta( 'reply_to_tweet' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_reply_to_tweet', 'ozh_ta_reply_to_tweet', 10, 0 );

// Template tag: "in reply to Ozh"
function ozh_ta_in_reply_to_tweet( $text = 'in reply to %name%', $echo = true ) {
	$tweet = ozh_ta_reply_to_tweet( false );
	$name = ozh_ta_reply_to_name( false );
	if( $tweet && $name ) {
		$nofollow = apply_filters( 'ozh_ta_reply_to_nofollow', 'rel="nofollow"' );
		$text = str_replace( '%name%', $name, $text );
		$link = sprintf( '<a href="http://twitter.com/%s/statuses/%s" %s class="ozh_ta_in_reply">%s</a>',
			$name, $tweet, $nofollow, $text );
		$link = apply_filters( 'ozh_ta_in_reply_to_tweet_link', $link );
		if( $echo )
			echo $link;
		return $link;
	}
}
add_action( 'ozh_ta_in_reply_to_tweet', 'ozh_ta_in_reply_to_tweet', 10, 0 );

// Template tag: total number of tweets ON TWITTER (may not be all archived)
function ozh_ta_total_tweets( $echo = true ) {
	$what = ozh_ta_get_total( 'tweet_counts' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_tweets', 'ozh_ta_total_tweets', 10, 0 );

// Template tag: total number of followers
function ozh_ta_total_followers( $echo = true ) {
	$what = ozh_ta_get_total( 'followers' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_followers', 'ozh_ta_total_followers', 10, 0 );

// Template tag: total number of following
function ozh_ta_total_following( $echo = true ) {
	$what = ozh_ta_get_total( 'following' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_following', 'ozh_ta_total_following', 10, 0 );

// Template tag: total number of times listed
function ozh_ta_total_listed( $echo = true ) {
	$what = ozh_ta_get_total( 'listed_count' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_listed', 'ozh_ta_total_listed', 10, 0 );

// Template tag: tweeting since date
function ozh_ta_tweeting_since( $echo = true, $format = 'Y-m-d H:i:s' ) {
	$what = ozh_ta_get_total( 'tweeting_since' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_tweeting_since', 'ozh_ta_tweeting_since', 10, 0 );
	
// Template tag: Twitter avatar url
function ozh_ta_twitter_avatar( $echo = true ) {
	$what = ozh_ta_get_total( 'profile_image_url' );
	ozh_ta_debug( $what );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_twitter_avatar', 'ozh_ta_twitter_avatar', 10, 0 );

// Template tag: total number of tweets containing links (in tweets archived)
function ozh_ta_total_links( $echo = true ) {
	$what = ozh_ta_get_total( 'link_count' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_links', 'ozh_ta_total_links', 10, 0 );

// Template tag: ratio of tweets containing links (in tweets archived)
function ozh_ta_link_ratio( $echo = true ) {
	$links = ozh_ta_get_total( 'link_count' );
	$total = ozh_ta_get_total( 'total_archived' );
	$what = (string)( intval( 1000 * $links / $total ) / 10 ).'%';
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_link_ratio', 'ozh_ta_link_ratio', 10, 0 );

// Template tag: ratio of tweets that are replies (in tweets archived)
function ozh_ta_reply_ratio( $echo = true ) {
	$replies = ozh_ta_get_total( 'replies' );
	$total = ozh_ta_get_total( 'total_archived' );
	$what = (string)( intval( 1000 * $replies['total'] / $total ) / 10 ).'%';
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_reply_ratio', 'ozh_ta_reply_ratio', 10, 0 );

// Template tag: total number of tweets that are replies
function ozh_ta_total_replies( $echo = true ) {
	$what = ozh_ta_get_total( 'replies' );
	$what = $what['total'];
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_replies', 'ozh_ta_total_replies', 10, 0 );

// Template tag: total number of people to which a reply has been sent
function ozh_ta_total_replies_uniques( $echo = true ) {
	$what = ozh_ta_get_total( 'replies' );
	$what = $what['unique_names'];
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_replies_uniques', 'ozh_ta_total_replies_uniques' );

// Template tag: total number of tweets archived
function ozh_ta_total_archived( $echo = true ) {
	$what = ozh_ta_get_total( 'total_archived' );
	if( $echo )
		echo $what;
	return $what;
}
add_action( 'ozh_ta_total_archived', 'ozh_ta_total_archived', 10, 0 );

/** Helper functions **/

// Get total number of something
function ozh_ta_get_total( $what ) {
	global $ozh_ta;
	return $ozh_ta['twitter_stats'][$what];
}

// Get meta tag
function ozh_ta_get_meta( $what = 'source' ) {
	global $id;
	return get_post_meta( $id, 'ozh_ta_'.$what, true );
	echo "<pre>";var_dump( debug_backtrace() );echo "</pre>";
	return 'bleh';
}

// Slight hacked monthly archives
function ozh_ta_get_month_archives($args = '') {
	global $wpdb, $wp_locale;

	$defaults = array(
		'limit' => '',
		'before' => '',
		'after' => '',
		'show_post_count' => true,
		'echo' => 1
	);

	$r = wp_parse_args( $args, $defaults );
	extract( $r, EXTR_SKIP );

	if ( '' != $limit ) {
		$limit = absint($limit);
		$limit = ' LIMIT '.$limit;
	}

	// this is what will separate dates on weekly archive links
	$archive_week_separator = '&#8211;';

	// over-ride general date format ? 0 = no: use the date format set in Options, 1 = yes: over-ride
	$archive_date_format_over_ride = 0;

	// options for daily archive (only if you over-ride the general date format)
	$archive_day_date_format = 'Y/m/d';

	// options for weekly archive (only if you over-ride the general date format)
	$archive_week_start_date_format = 'Y/m/d';
	$archive_week_end_date_format	= 'Y/m/d';

	if ( !$archive_date_format_over_ride ) {
		$archive_day_date_format = get_option('date_format');
		$archive_week_start_date_format = get_option('date_format');
		$archive_week_end_date_format = get_option('date_format');
	}

	//filters
	$where = "WHERE post_type = 'post' AND post_status = 'publish'";

	$output = '';

	$query = "SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts FROM $wpdb->posts $where GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY post_date DESC $limit";
	$key = md5($query);
	$cache = wp_cache_get( 'wp_get_archives' , 'general');
	if ( !isset( $cache[ $key ] ) ) {
		$arcresults = $wpdb->get_results($query);
		$cache[ $key ] = $arcresults;
		wp_cache_set( 'wp_get_archives', $cache, 'general' );
	} else {
		$arcresults = $cache[ $key ];
	}
	if ( $arcresults ) {
		$total = ozh_ta_total_tweets( false );
		$afterafter = $after;

		// Get max post
		$max = 0;
		foreach ( (array) $arcresults as $arcresult ) {
			$max = max( $max, $arcresult->posts );
		}

		foreach ( (array) $arcresults as $arcresult ) {
			$url = get_month_link( $arcresult->year, $arcresult->month );
			$text = sprintf(__('%1$s %2$d'), $wp_locale->get_month($arcresult->month), $arcresult->year);
			if ( $show_post_count )
				$after = '<span class="count">'.$arcresult->posts.'</span>' . $afterafter;
			$size = ( $arcresult->posts && $max ) ? intval( 100 * $arcresult->posts / $max ) : 0 ;
			$output .= ozh_ta_get_archives_link($url, $text, $before, $after, $size );
		}
	}
	
	$output = apply_filters( 'ozh_ta_get_month_archives', $output );

	if ( $echo )
		echo $output;
	else
		return $output;
}

function ozh_ta_get_archives_link( $url, $text, $before = '', $after = '', $count = 0 ) {
	$text = wptexturize($text);
	$title_text = esc_attr($text);
	$url = esc_url($url);
	
	// ugly hack bleh
	$_url = str_replace( array( 'http://', 'https://', $_SERVER['SERVER_NAME'] ), '', $url );
	$current = ( $_SERVER['REQUEST_URI'] == $_url ) ? 'current' : '';
	
	$bg = get_template_directory_uri().'/img/bg-nav.png';
	$count =( 250 - $count );

	$link_html = "\t<li class='$current count_$count' style='background-position: -{$count}px 0px'>$before<a href='$url' title='$title_text'>$text $after</a></li>\n";

	$link_html = apply_filters( 'ozh_ta_get_archives_link', $link_html );

	return $link_html;
}