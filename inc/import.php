<?php
// Functions related to fetching & importing tweets

// Poll Twitter API and get tweets
function ozh_ta_get_tweets( $echo = false ) {
	global $ozh_ta;
	
	ozh_ta_schedule_next( 0 ); // clear scheduling

	if( !$ozh_ta['screen_name'] ) {
		ozh_ta_debug( 'No screen name defined' );
		return false;
	}
	
	
	$api = add_query_arg( array(
		'count' => OZH_TA_BATCH,
		'screen_name' => urlencode( $ozh_ta['screen_name'] ),
		'page' => $ozh_ta['api_page'],
		'since_id' => $ozh_ta['last_tweet_id_inserted']
	), OZH_TA_API );
	
	ozh_ta_debug( "Polling $api" );
	
	$response = wp_remote_get( $api, array( 'timeout' => 10 ) );
	$tweets = wp_remote_retrieve_body( $response );
	// Fix integers in the JSON response to have them handled as strings and not integers
	$tweets = preg_replace( '/"\s*:\s*([\d]+)\s*([,}{])/', '": "$1"$2', $tweets );
	$ratelimit = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
	$ratelimit_r = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
	
	ozh_ta_debug( "rate: $ratelimit_r" );
	
	// Fail Whale FTW
	if ( !$tweets or strpos( $tweets, 'Please wait a moment and try again. For more information, check out <a href="http://status.twitter.com">Twitter Status &raquo;</a></p>' ) ) {
	
		ozh_ta_debug( 'Twitter fail, retry in '.OZH_TA_NEXT_FAIL );

		// Context: from option page
		if( $echo ) {
			$url = wp_nonce_url( admin_url( 'options-general.php?page=ozh_ta&action=import_all&time='.time() ), 'ozh_ta-import_all' );
			ozh_ta_reload( $url, OZH_TA_NEXT_FAIL );
			wp_die( '<p>Twitter is over capacity. This page will refresh in '.OZH_TA_NEXT_FAIL.' seconds. Please wait this delay to avoid a ban.</p>' );
		
		// Context: from cron
		} else {
			// schedule same operation in 60 seconds
			ozh_ta_schedule_next( OZH_TA_NEXT_FAIL );
			return false;
		}
	}
	
	// No Fail Whale, let's import
	$tweets = array_reverse( (array)json_decode( $tweets ) );
	
	// Tweets found, let's archive
	if ( $tweets ) {
		$results = ozh_ta_insert_tweets( $tweets, true );
		// array( "inserted", "last_tweet_id_inserted", (array)$user );
		
		// Record highest temp last_tweet_id_inserted, increment api_page and update user info
		$ozh_ta['_last_tweet_id_inserted'] = max( $results['last_tweet_id_inserted'], $ozh_ta['_last_tweet_id_inserted'] );
		$ozh_ta['twitter_stats'] = array_merge( $ozh_ta['twitter_stats'], $results['user'] );
		$ozh_ta['api_page']++;
		update_option( 'ozh_ta', $ozh_ta );

		ozh_ta_debug( "Twitter OK, imported {$results['inserted']}, next in ".OZH_TA_NEXT_SUCCESS );

		// Context: option page
		if( $echo ) {
			echo "<p>Tweets inserted:<strong>{$results[ 'inserted' ]}</strong></p>";
			$url = wp_nonce_url( admin_url( 'options-general.php?page=ozh_ta&action=import_all&time='.time() ), 'ozh_ta-import_all' );
			ozh_ta_reload( $url, OZH_TA_NEXT_SUCCESS );
		
		// Context: from cron
		} else {
			// schedule next operation in 30 seconds
			ozh_ta_schedule_next( OZH_TA_NEXT_SUCCESS );
		}
				
	// No tweets found
	} else {
	
		global $wpdb;
	
		// Schedule next operation
		ozh_ta_schedule_next( $ozh_ta['refresh_interval'] );
		ozh_ta_debug( "Twitter finished, imported {$result['inserted']}, next in {$ozh_ta['refresh_interval']}" );
		
		// Update real last_tweet_id_inserted, stats, & reset API paging
		$ozh_ta['last_tweet_id_inserted'] = max( $ozh_ta['last_tweet_id_inserted'], $ozh_ta['_last_tweet_id_inserted'] );
		unset( $ozh_ta['_last_tweet_id_inserted'] );
		$ozh_ta['api_page'] = 1;
		$ozh_ta['twitter_stats']['link_count'] = $wpdb->get_var( "SELECT COUNT(ID) FROM `$wpdb->posts` WHERE `post_content` LIKE '%http://%'" );
		$ozh_ta['twitter_stats']['replies'] = $wpdb->get_row( "SELECT COUNT( DISTINCT `meta_value`) as unique_names, COUNT( `meta_value`) as total FROM `$wpdb->postmeta` WHERE `meta_key` = 'ozh_ta_reply_to_name'", ARRAY_A );
		$ozh_ta['twitter_stats']['total_archived'] = $wpdb->get_var( "SELECT COUNT(`meta_key`) FROM `$wpdb->postmeta` WHERE `meta_key` = 'ozh_ta_id'" );
		update_option( 'ozh_ta', $ozh_ta );
		
		// Context: option page
		if( $echo ) {
			echo '<p>Finished importing tweets! Automatic archiving now scheduled.</p>';
			echo '<p><a href="'.menu_page_url( 'ozh_ta', false ).'" class="button">Return to plugin config</a></p>';
		}
		
	}
	
	return true;
}

// Insert tweets as posts
function ozh_ta_insert_tweets( $tweets, $display = false ) {
	$inserted = 0;
	$user = array();
	
	global $ozh_ta;
		
	foreach ( (array)$tweets as $tweet ) {
		
		// Current tweet
		$tid    = (string)$tweet->id;
		$text   = $tweet->text;
		$date   = date( 'Y-m-d H:i:s', strtotime( $tweet->created_at ) );
		$source = $tweet->source;
		$reply_to_name  = $tweet->in_reply_to_screen_name;
		$reply_to_tweet = (string)$tweet->in_reply_to_status_id;
		
		// Info about Twitter user
		if( !$user ) {
			$user = array(
				'tweet_counts' => $tweet->user->statuses_count,
				'followers' => $tweet->user->followers_count,
				'following' => $tweet->user->friends_count,
				'listed_count' => $tweet->user->listed_count,
				'profile_image_url' => $tweet->user->profile_image_url,
				'tweeting_since' => date( 'Y-m-d H:i:s', strtotime( $tweet->user->created_at ) ),
			);
		}
		
		// Check for duplicate posts before inserting
		global $wpdb;
		$sql = "SELECT post_id
		        FROM `$wpdb->postmeta`
				WHERE `meta_key` = 'ozh_ta_id' AND `meta_value` = '$tid ' LIMIT 0,1"; // Yeah, trusting api.twitter.com so we don't sanitize the SQL query, yeeeha
		if( !$wpdb->get_var( $sql ) ) {

			// Insert tweet as new post
			$post = array(
				'post_title'   => $text,
				'post_content' => $text,
				'post_date'    => $date,
				'post_category'=> array( $ozh_ta['post_category'] ),
				'post_status'  => 'publish',
				'post_author'  => $ozh_ta['post_author'],
			);
			// Plugins: hack here
			$post = apply_filters( 'ozh_ta_insert_tweets_post', $post ); 
			
			$post_id = wp_insert_post( $post );

			// Insert post meta data
			add_post_meta( $post_id, 'ozh_ta_id', $tid, true );
			if( $source )
				add_post_meta( $post_id, 'ozh_ta_source', $source, true );
			if( $reply_to_name )
				add_post_meta( $post_id, 'ozh_ta_reply_to_name', $reply_to_name, true );
			if( $reply_to_tweet )
				add_post_meta( $post_id, 'ozh_ta_reply_to_tweet', $reply_to_tweet, true );
		
			$last_tweet_id_inserted = $tid;
			ozh_ta_debug( "Inserted $post_id (tweet id: $tid, tweet: ". substr($text, 0, 45) ."...)" );
			$inserted++;
			
		} else {
			// This tweet has already been imported ?!
			ozh_ta_debug( "Skipping tweet $tid, already imported?!" );
		}
	}

	return array(
		'inserted' => $inserted,
		'last_tweet_id_inserted' => $tid,
		'user' => $user,
	);
}


// Schedule next twitter archiving
function ozh_ta_schedule_next( $delay = 30 ) {
	wp_clear_scheduled_hook( 'ozh_ta_cron_import' );
	ozh_ta_debug( "Schedule cleared" );
	if( $delay ) {
		wp_schedule_single_event( time()+$delay, 'ozh_ta_cron_import' );
		ozh_ta_debug( "Schedule next in $delay" );
	}
}


