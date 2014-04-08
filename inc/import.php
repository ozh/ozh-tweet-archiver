<?php
// Functions related to fetching & importing tweets

// Poll Twitter API and get tweets
function ozh_ta_get_tweets( $echo = false ) {
	global $ozh_ta;
	
	ozh_ta_schedule_next( 0 ); // clear scheduling
    
    if( !ozh_ta_is_configured() ) {
		ozh_ta_debug( 'Config incomplete, cannot import tweets' );
        return false;
    }

	$api = add_query_arg( array(
		'count'       => OZH_TA_BATCH,
		'page'        => $ozh_ta['api_page'],
		'screen_name' => urlencode( $ozh_ta['screen_name'] ),
		'since_id'    => $ozh_ta['last_tweet_id_inserted']
	), OZH_TA_API );
	
	ozh_ta_debug( "Polling $api" );
	
	$headers = array(
		'Authorization' => 'Bearer ' . $ozh_ta['access_token'],
	);

	ozh_ta_debug( "Headers: " . json_encode( $headers ) );
	
	$response = wp_remote_get( $api, array(
		'headers' => $headers,
		'timeout' => 10
	) );
    
	$tweets = wp_remote_retrieve_body( $response );
	$ratelimit = wp_remote_retrieve_header( $response, 'x-rate-limit-limit' );
	$ratelimit_r = wp_remote_retrieve_header( $response, 'x-rate-limit-remaining' );
    $status = wp_remote_retrieve_response_code( $response );
    
	ozh_ta_debug( "status: $status" );
	ozh_ta_debug( "rate: $ratelimit_r/$ratelimit" );
	
	// Fail Whale or other error
	if ( !$tweets or $status != 200 ) {
        
        // 401 : Unauthorized
        if( $status == 401 ) {
            ozh_ta_debug( 'Could not fetch tweets: unauthorized access.' );
            if( $echo ) {
                wp_die( '<p>Twitter returned an "Unauthorized" error. Check your consumer key and secret!</p>' );
            } else {
                // TODO: what to do in such a case? Better not to silently die and do nothing.
                // Email blog admin ?
                return false;
            }
        }
        
        // 419 : Rate limit exceeded
        // 5xx : Fail whale
		ozh_ta_debug( 'Twitter fail, retry in '.OZH_TA_NEXT_FAIL );

		// Context: from option page
		if( $echo ) {
			$url = wp_nonce_url( admin_url( 'options-general.php?page=ozh_ta&action=import_all&time='.time() ), 'ozh_ta-import_all' );
			ozh_ta_reload( $url, OZH_TA_NEXT_FAIL );
			wp_die( '<p>Twitter is over capacity. This page will refresh in '.OZH_TA_NEXT_FAIL.' seconds. Please wait this delay to avoid a ban.</p>' );
		
		// Context: from cron
		} else {
			// schedule same operation later
			ozh_ta_schedule_next( OZH_TA_NEXT_FAIL );
			return false;
		}
	}
	
	// No Fail Whale, let's import

	// Legacy note:
    // We used to have to fix integers in the JSON response to have them handled as strings and not integers,
    // to avoid having tweet ID 438400650846928897 interpreted as 4.34343e15
	// $tweets = preg_replace( '/"\s*:\s*([\d]+)\s*([,}{])/', '": "$1"$2', $tweets );
    // This isn't needed anymore since, smartly, Twitter's API returns both an id and an id_str. Nice, Twitter :)

	$tweets = array_reverse( (array)json_decode( $tweets ) );
    
	// Tweets found, let's archive
	if ( $tweets ) {

        $results = ozh_ta_insert_tweets( $tweets, true );
		// array( inserted, skipped, last_tweet_id_inserted, (array)$user );
        
		// Record highest temp last_tweet_id_inserted, increment api_page and update user info
		$ozh_ta['_last_tweet_id_inserted'] = max( $results['last_tweet_id_inserted'], $ozh_ta['_last_tweet_id_inserted'] );
		$ozh_ta['twitter_stats'] = array_merge( $ozh_ta['twitter_stats'], $results['user'] );
		$ozh_ta['api_page']++;
		update_option( 'ozh_ta', $ozh_ta );

		ozh_ta_debug( "Twitter OK, imported {$results['inserted']}, skipped {$results['skipped']}, next in ".OZH_TA_NEXT_SUCCESS );

		// Context: option page
		if( $echo ) {
			echo "<p>Tweets inserted: <strong>{$results[ 'inserted' ]}</strong></p>";
			$url = wp_nonce_url( admin_url( 'options-general.php?page=ozh_ta&action=import_all&time='.time() ), 'ozh_ta-import_all' );
			ozh_ta_reload( $url, OZH_TA_NEXT_SUCCESS );
		
		// Context: from cron
		} else {
			// schedule next operation soon
			ozh_ta_schedule_next( OZH_TA_NEXT_SUCCESS );
		}
				
	// No tweets found
	} else {
	
		global $wpdb;
	
		// Schedule next operation
		ozh_ta_schedule_next( $ozh_ta['refresh_interval'] );
		ozh_ta_debug( "Twitter finished, next in {$ozh_ta['refresh_interval']}" );
		
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

// Linkify @usernames, #hashtags and t.co links, then return text 
function ozh_ta_linkify_tweet( $tweet ) {
    global $ozh_ta;
    
    $text = $tweet->text;
    
	// Linkify twitter names if applicable
    if( $mentions = $tweet->entities->user_mentions ) {
        foreach( $mentions as $mention ) {
            $screen_name = $mention->screen_name;
            $name        = $mention->name;
            
            // Convert to link or to span
            if( $ozh_ta['link_usernames'] == 'yes' ) {
                $replace = sprintf( '<span class="username username_linked">@<a href="https://twitter.com/%s" title="%s">%s</a></span>',
                                    $screen_name, esc_attr( $name ), $screen_name );
            } else {
                $replace = sprintf( '<span title="%s" class="username username_unlinked">@%s</a>',
                                    esc_attr( $name ), $screen_name );
            }
            
            $text = preg_replace( '/\@' . $screen_name . '/', $replace, $text, 1 );
        }
    }
    
	// un-t.co links if applicable
    if( $urls = $tweet->entities->urls ) {
        foreach( $urls as $url ) {
            $expanded_url = $url->expanded_url;
            $display_url  = $url->display_url;
            $tco_url      = $url->url;
            
            // Convert links
            if( $ozh_ta['un_tco'] == 'yes' ) {
                $replace = sprintf( '<a href="%s" title="%s" class="link link_untco">%s</a>',
                                    $expanded_url, $expanded_url, $display_url );
            } else {
                $replace = sprintf( '<a href="%s" class="link link_tco">%s</a>',
                                    $tco_url, $tco_url );
            }
            
            $text = preg_replace( '!' . $tco_url . '!', $replace, $text, 1 ); // using ! instead of / because URLs contain / already ;P
        }
    }
    
	// hashtag links if applicable
    if( $hashes = $tweet->entities->hashtags ) {
        foreach( $hashes as $hash ) {
            $hash_text         = $hash->text;
            
            // Convert hashtags
            switch( $ozh_ta['link_hashtags'] ) {
            
                case 'twitter':
                $replace = sprintf( '<span class="hashtag hashtag_twitter">#<a href="%s">%s</a></span>',
                                    'https://twitter.com/search?q=%23' . $hash_text, $hash_text );
                break;    
            
                case 'local':
                $replace = sprintf( '<span class="hashtag hashtag_local">#<a href="%s">%s</a>',
                                    ozh_ta_get_tag_link( $hash_text ), $hash_text );
                break;    
            
                case 'no':
                $replace = sprintf( '<span class="hashtag hashtag_no">%s</span>',
                                    '#' . $hash_text );
                break;    
            
            }
            
            $text = preg_replace( '/\#' . $hash_text . '/', $replace, $text, 1 );
        }
    }
    
	// embed images if applicable. This operation shall be the last one
    if( $medias = $tweet->entities->media ) {
        foreach( $medias as $media ) {
            $media_url    = $media->media_url_https;
            $display_url  = $media->display_url;
            $expanded_url = $media->expanded_url;
            $tco_url      = $media->url;
            
            // Convert links
            if( $ozh_ta['un_tco'] == 'yes' ) {
                $replace = sprintf( '<a href="%s" title="%s" class="link link_untco link_untco_image">%s</a>',
                                $expanded_url, $expanded_url, $display_url );
            } else {
                $replace = sprintf( '<a href="%s" class="link link_tco link_tco_image">%s</a>',
                                    $tco_url, $tco_url );
            }

            // Add image
            if( $ozh_ta['embed_images'] == 'yes' ) {
                $insert  = sprintf( '<span class="embed_image embed_image_yes"><a href="%s"><img src="%s" /></a></span>',
                                    $expanded_url, $media_url );
            } else {
                $insert  = '';
            }
            
            $text  = preg_replace( '!' . $tco_url . '!', $replace, $text, 1 );
            $text .= $insert;
        }
    }
    
    
    return $text;
}

// Insert tweets as posts
function ozh_ta_insert_tweets( $tweets, $display = false ) {
	$inserted = $skipped = 0;
	$user = array();
	
	global $ozh_ta;
		
	foreach ( (array)$tweets as $tweet ) {
		
		// Current tweet
		$tid            = (string)$tweet->id_str;
		$text           = ozh_ta_linkify_tweet( $tweet );
		$date           = date( 'Y-m-d H:i:s', strtotime( $tweet->created_at ) );
		$source         = $tweet->source;
        $has_hashtags   = count( $tweet->entities->hashtags ) > 0;
		$reply_to_name  = $tweet->in_reply_to_screen_name;
		$reply_to_tweet = (string)$tweet->in_reply_to_status_id_str;
        
		// Info about Twitter user
		if( !$user ) {
			$user = array(
				'tweet_counts'      => $tweet->user->statuses_count,
				'followers'         => $tweet->user->followers_count,
				'following'         => $tweet->user->friends_count,
				'listed_count'      => $tweet->user->listed_count,
				'profile_image_url' => $tweet->user->profile_image_url,
				'tweeting_since'    => date( 'Y-m-d H:i:s', strtotime( $tweet->user->created_at ) ),
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
				'post_title'    => strip_tags( $text ),
				'post_content'  => $text,
				'post_date'     => $date,
				'post_category' => array( $ozh_ta['post_category'] ),
				'post_status'   => 'publish',
				'post_author'   => $ozh_ta['post_author'],
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
			if( $has_hashtags )
				add_post_meta( $post_id, 'ozh_ta_has_hashtags', ozh_ta_get_hashtags( $tweet ), true );
		
			$last_tweet_id_inserted = $tid;
			ozh_ta_debug( "Inserted $post_id (tweet id: $tid, tweet: ". substr($text, 0, 100) ."...)" );
			$inserted++;
			
		} else {
			// This tweet has already been imported ?!
			ozh_ta_debug( "Skipping tweet $tid, already imported?!" );
            $skipped++;
		}
	}

	return array(
		'inserted'               => $inserted,
        'skipped'                => $skipped,
		'last_tweet_id_inserted' => $tid,
		'user'                   => $user,
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

// Return list of hashtags for a given tweet
function ozh_ta_get_hashtags( $tweet ) {
    $list = array();
    foreach( $tweet->entities->hashtags as $tag ) {
        $list[] = $tag->text;
    }
    return $list;
}
