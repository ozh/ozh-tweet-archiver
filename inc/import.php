<?php
// Functions related to fetching & importing tweets

/**
 * Poll Twitter API and get tweets
 *
 * @param  bool $echo  True to output results and redirect page (ie called from option page)
 * @return bool        false if error while polling Twitter, true otherwise
 */
function ozh_ta_get_tweets( $echo = false ) {
	global $ozh_ta;
	
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
    
	$tweets      = wp_remote_retrieve_body( $response );
	$ratelimit   = wp_remote_retrieve_header( $response, 'x-rate-limit-limit' );
	$ratelimit_r = wp_remote_retrieve_header( $response, 'x-rate-limit-remaining' );
    $status      = wp_remote_retrieve_response_code( $response );
	ozh_ta_debug( "API status: $status" );
	ozh_ta_debug( "API rate: $ratelimit_r/$ratelimit" );
    
    /**
     * Something to check when Twitter update their API :
     *
     * Currently, when you try to retrieve more tweets than available (either you already have fetched 3200, or you
     * have fetched them all), the API returns no particular error: status 200, just an empty body.
     * In the future, check if they change this and return a particular message or status code
     */
	
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
    
        if( ozh_ta_is_debug() ) {
            $overall = new ozh_ta_query_count(); 
        }

        $results = ozh_ta_insert_tweets( $tweets, true );
		// array( inserted, skipped, tagged, num_tags, (array)$user );
        
		// Increment api_page and update user info
		$ozh_ta['twitter_stats'] = array_merge( $ozh_ta['twitter_stats'], $results['user'] );
		$ozh_ta['api_page']++;
		update_option( 'ozh_ta', $ozh_ta );

		ozh_ta_debug( "Twitter OK, imported {$results['inserted']}, skipped {$results['skipped']}, tagged {$results['tagged']} with {$results['num_tags']} tags, next in ".OZH_TA_NEXT_SUCCESS );
        if( ozh_ta_is_debug() ) {
            ozh_ta_debug( 'Total queries: ' . $overall->stop() );            
        }

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
        
		// Update last_tweet_id_inserted, stats, & reset API paging
		$ozh_ta['api_page'] = 1;
		$ozh_ta['last_tweet_id_inserted']          = $wpdb->get_var( "SELECT `meta_value` FROM `$wpdb->postmeta` WHERE `meta_key` = 'ozh_ta_id' ORDER BY ABS(`meta_value`) DESC LIMIT 1" ); // order by ABS() because they are strings in the DB
		$ozh_ta['twitter_stats']['link_count']     = $wpdb->get_var( "SELECT COUNT(ID) FROM `$wpdb->posts` WHERE `post_type` = 'post' AND `post_status` = 'publish' AND `post_content` LIKE '%class=\"link%'" );
		$ozh_ta['twitter_stats']['replies']        = $wpdb->get_row( "SELECT COUNT( DISTINCT `meta_value`) as unique_names, COUNT( `meta_value`) as total FROM `$wpdb->postmeta` WHERE `meta_key` = 'ozh_ta_reply_to_name'", ARRAY_A );
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

/**
 * Linkify @usernames, #hashtags and t.co links, then return text 
 *
 * @param  object $tweet  a Tweet object (json_decoded result of https://dev.twitter.com/docs/platform-objects/tweets)
 * @return string         formatted tweet
 */
function ozh_ta_linkify_tweet( $tweet ) {
    global $ozh_ta;
    
    $text = $tweet->text;
    
	// Linkify twitter names if applicable
    if( isset( $tweet->entities->user_mentions ) && $mentions = $tweet->entities->user_mentions ) {
        foreach( $mentions as $mention ) {
            $screen_name = $mention->screen_name;
            $name        = $mention->name;
            
            $text = ozh_ta_convert_mentions( $text, $screen_name, $name );
        }
    }
    
	// un-t.co links if applicable
    if( isset( $tweet->entities->urls ) && $urls = $tweet->entities->urls ) {
        foreach( $urls as $url ) {
            $expanded_url = $url->expanded_url;
            $display_url  = $url->display_url;
            $tco_url      = $url->url;
            
            $text = ozh_ta_convert_links( $text, $expanded_url, $display_url, $tco_url );
        }
    }
    
	// hashtag links if applicable
    if( isset( $tweet->entities->hashtags ) && $hashes = $tweet->entities->hashtags ) {
        foreach( $hashes as $hash ) {
            $hash_text = $hash->text;
            
            $text = ozh_ta_convert_hashtags( $text, $hash_text );
        }
    }
    
	// embed images if applicable. This operation shall be the last one
    if( isset( $tweet->entities->media ) && $medias = $tweet->entities->media ) {
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

/**
 * Insert tweets as posts
 *
 * @param array $tweets   Array of tweet objects
 * @return array          Array of stats about the insertion
 */
function ozh_ta_insert_tweets( $tweets ) {

    // Flag as importing : this will cut some queries in the process, regarding (ping|track)backs
    if( !defined( 'WP_IMPORTING' ) )
        define( 'WP_IMPORTING', true );

	global $ozh_ta;
	$inserted = $skipped = $tagged = $num_tags = 0;
	$user = array();
    
    if( ozh_ta_is_debug() ) {
        $num_sql_batch = new ozh_ta_query_count(); 
    }
		
	foreach ( (array)$tweets as $tweet ) {
		
        if( ozh_ta_is_debug() ) {
            $num_sql_post = new ozh_ta_query_count(); 
        }

        // Current tweet
		$tid            = (string)$tweet->id_str;
		$text           = ozh_ta_linkify_tweet( $tweet );
		$date           = date( 'Y-m-d H:i:s', strtotime( $tweet->created_at ) + 3600 * get_option('gmt_offset') );
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
                'guid'          => home_url() . '/?tid=' . $tid,  // forcing a GUID will save one query when inserting
			);
			// Post format
			if ( 'standard' != $ozh_ta['post_format'] ) {
				$post['tax_input'] = array( 'post_format' => array( 'post-format-' . $ozh_ta['post_format'] ) );
			}

			// Plugins: hack here
			$post = apply_filters( 'ozh_ta_insert_tweets_post', $post ); 
			
			$post_id = wp_insert_post( $post );

			// Insert post meta data
			add_post_meta( $post_id, 'ozh_ta_id', $tid );
			if( $source )
				add_post_meta( $post_id, 'ozh_ta_source', $source );
			if( $reply_to_name )
				add_post_meta( $post_id, 'ozh_ta_reply_to_name', $reply_to_name );
			if( $reply_to_tweet )
				add_post_meta( $post_id, 'ozh_ta_reply_to_tweet', $reply_to_tweet );

            ozh_ta_debug( " Inserted #$post_id (tweet id: $tid, tweet: ". ozh_ta_trim_long_string( $text, 100 ) . ')' );
		
            if( ozh_ta_is_debug() ) {
                ozh_ta_debug( '  Import query cost: ' . $num_sql_post->stop() );
            }

            // Tag post if applicable
            if( $has_hashtags && $ozh_ta['add_hash_as_tags'] == 'yes' ) {
                $hashtags  = ozh_ta_get_hashtags( $tweet );
                $num_tags += count( $hashtags );
                $hashtags  = implode( ', ', $hashtags );
                ozh_ta_debug( "  Tagging post $post_id with " . $hashtags );
                $tagged++;
                if( ozh_ta_is_debug() ) {
                    $num_sql_tag = new ozh_ta_query_count();
                }
                wp_set_post_tags( $post_id, $hashtags );
                if( ozh_ta_is_debug() ) {
                    ozh_ta_debug( '   Tagging query cost: ' . $num_sql_tag->stop() );
                }
                
            }
            
			$inserted++;
			
		} else {
			// This tweet has already been imported ?!
			ozh_ta_debug( " Skipping tweet $tid, already imported?!" );
            $skipped++;
		}
	}

    if( ozh_ta_is_debug() ) {
        ozh_ta_debug( 'Batch import query cost: ' . $num_sql_batch->stop() );
    }

    return array(
		'inserted'               => $inserted,
        'skipped'                => $skipped,
        'tagged'                 => $tagged,
        'num_tags'               => $num_tags,
		'user'                   => $user,
	);
}

/**
 * Return list of hashtags for a given tweet
 *
 * @param  object $tweet  a Tweet object (json_decoded result of https://dev.twitter.com/docs/platform-objects/tweets)
 * @return array          Array of hashtags
 */
function ozh_ta_get_hashtags( $tweet ) {
    $list = array();
    foreach( $tweet->entities->hashtags as $tag ) {
        $list[] = $tag->text;
    }
    return $list;
}


/**
 * Count MySQL queries between two events
 *
 * Usage:
 * $context = new ozh_ta_query_count();
 * // ... do some stuff in that context
 * var_dump( $context->stop() ); // int 4
 */
class ozh_ta_query_count {
    
    private $start;
    private $stop;
    
    public function __construct() {
        $this->start();
    }
    
    private function start() {
        global $wpdb;
        $this->start = $wpdb->num_queries;
    }
    
    public function stop() {
        global $wpdb;
        $this->stop = $wpdb->num_queries;
        return ($this->stop - $this->start );
    }

}

/**
 * Fetch a single tweet
 *
 * This function is not used in the plugin, it's here to be used when debugging or for custom use
 *
 * @param  string $id   Tweet ID ('454752497002115072' in 'https://twitter.com/ozh/statuses/454752497002115072')
 * @return bool|object  false if not found, or tweet object (see https://dev.twitter.com/docs/platform-objects/tweets)
 */
function ozh_ta_get_single_tweet( $id ) {
    global $ozh_ta;
    
    if( !ozh_ta_is_configured() ) {
		ozh_ta_debug( 'Config incomplete, cannot import tweets' );
        return false;
    }

    $api = 'https://api.twitter.com/1.1/statuses/show.json?id=' . $id;
	$headers = array(
		'Authorization' => 'Bearer ' . $ozh_ta['access_token'],
	);

    ozh_ta_debug( "Polling $api" );
    
	$response = wp_remote_get( $api, array(
		'headers' => $headers,
		'timeout' => 10
	) );
    
	$tweet = json_decode( wp_remote_retrieve_body( $response ) );
    
    if( isset( $tweet->errors ) ) {
        ozh_ta_debug( "Error with tweet #$id : " . $tweet->errors[0]->message );
        return false;
    }
    
    return $tweet;
}

/**
 * Import a single tweet as a post
 *
 * This function is not used in the plugin, it's here to be used when debugging or for custom use
 *
 * @param  string $id   Tweet ID ('454752497002115072' in 'https://twitter.com/ozh/statuses/454752497002115072')
 * @return bool|array   false if not found, or array of stats about the insertion
 */
function ozh_ta_import_single_tweet( $id ) {
    if( $tweet = ozh_ta_get_single_tweet( $id ) ) {
        return( ozh_ta_insert_tweets( array( $tweet ) ) );
    }
    
    return false;
}
