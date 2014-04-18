<?php
// Functions related to settings

// Init settings & plugin option page
function ozh_ta_init_settings() {
	register_setting(
		'ozh_ta',
		'ozh_ta',
		'ozh_ta_validate_options'
	);
	// Add two sections
	add_settings_section(
		'ozh_ta_section_twitter', 		// section ID
		'Twitter Settings',				// H3 text
		'ozh_ta_section_twitter_text',	// callback function for text
		'ozh_ta'						// plugin page
	);
	add_settings_section(
		'ozh_ta_section_plugin',
		'Plugin Settings',		
		'ozh_ta_section_plugin_text',
		'ozh_ta'
	);
	// Twitter section: screen name field
	add_settings_field(
		'ozh_ta_setting_screen_name',  // setting ID
		'Twitter user name',           // text on the left
		'ozh_ta_setting_screen_name',  // callback function for field
		'ozh_ta',                      // plugin page
		'ozh_ta_section_twitter'       // section name
	);
	// Twitter section: consumer key
	add_settings_field(
		'ozh_ta_setting_cons_key',  // setting ID
		'Consumer Key',             // text on the left
		'ozh_ta_setting_cons_key',  // callback function for field
		'ozh_ta',                   // plugin page
		'ozh_ta_section_twitter'    // section name
	);
	// Twitter section: consumer secret
	add_settings_field(
		'ozh_ta_setting_cons_secret', // setting ID
		'Consumer Secret',            // text on the left
		'ozh_ta_setting_cons_secret', // callback function for field
		'ozh_ta',                     // plugin page
		'ozh_ta_section_twitter'      // section name
	);
	// Plugin section: lots of fields
	$fields = array(
		'refresh_interval' => 'Refresh interval',
		'post_category'    => 'Post category',
		'post_format'      => 'Post format',
		'post_author'      => 'Post author',
		'link_usernames'   => 'Link @usernames',
		'link_hashtags'    => 'Link #hashtags',
		'add_hash_as_tags' => 'Add hashtags as post tags',
		'un_tco'           => 'Expand <code>t.co</code> links',
		'embed_images'     => 'Embed <code>pic.twitter.com</code> images',
	);
	foreach( $fields as $field => $text ) {
		add_settings_field(
			"ozh_ta_setting_$field",
			$text,
			"ozh_ta_setting_$field",
			'ozh_ta',
			'ozh_ta_section_plugin'
		);
	}
}


// Plugin settings section header
function ozh_ta_section_plugin_text() {
	echo '<p>Configure how the plugin will archive the tweets (default settings will work just fine)</p>';
}

// Twitter settings section header
function ozh_ta_section_twitter_text() {
	echo '<p>Create a new application on <a href="https://apps.twitter.com/app/new">apps.twitter.com</a>, fill in the details, save, then click on the <strong>Test OAuth</strong> button to get your consumer key and consumer secret.</p>';
}

// Wrapper for all fields
function ozh_ta_setting( $setting ) {
	// get setting value
	global $ozh_ta;
	$value = $ozh_ta[ $setting ];
	
	// echo the field
	switch( $setting ) {
	case 'screen_name':
	case 'cons_key':
	case 'cons_secret':
		$value = esc_attr( $value );
		echo "<input id='$setting' name='ozh_ta[$setting]' type='text' value='$value' />";
		break;
		$value = esc_attr( $value );
		echo "<input id='$setting' name='ozh_ta[$setting]' type='text' value='$value' />";
		break;
		
	case 'refresh_interval':
		$options = array(
			'300'   => 'every 5 minutes',
			'900'   => 'every 15 minutes',
			'3600'  => 'hourly',
			'43200' => 'twice daily',
			'86400' => 'daily'
		);
		$value = absint( $value );
		echo "<select id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $options as $option => $desc ){
			echo "<option value='$option' ".selected( $option, $value, false ).">$desc</option>\n";
		}
		echo "</select>\n";
		echo "<br/>How often you want WordPress to check for new tweets and archive them.<br/>Ideally, select a frequency corresponding to 10 or 15 tweets.";
		break;
		
	case 'post_category':
		$value = absint( $value );
		wp_dropdown_categories( array(
			'hide_empty' => 0,
			'name' => "ozh_ta[$setting]",
			'orderby' => 'name',
			'selected' => $value,
			'hierarchical' => true
			)
		);
		echo "<br/>Posts will be filed into this category.";
		break;
		
	case 'post_format':
		$options = get_post_format_strings();
		$value = ( array_key_exists( $value, $options ) ? $value : 'standard' );
		echo "<select class='toggler' id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $options as $option => $desc ){
			echo "<option value='$option' ".selected( $option, $value, false ).">$desc</option>\n";
		}
		echo "</select>\n";
		echo "<br/>Posts will be assigned this post format.";
		break;
		
	case 'post_author':
		global $wpdb;
		$value = absint( $value );
		$logins = $wpdb->get_results( "SELECT ID as 'option', user_login as 'desc' FROM $wpdb->users ORDER BY user_login ASC" );
		echo "<select id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $logins as $login ){
			echo "<option value='$login->option' ".selected( $login->option, $value, false ).">$login->desc</option>\n";
		}
		echo "</select>\n";
		echo "<br/>Tweets will be assigned to this author.";
		break;
		
	case 'link_usernames':
		$options = array(
			'no' => 'No',
			'yes' => 'Yes',
		);
		$value = ( $value == 'yes' ? 'yes' : 'no' );
		echo "<select class='toggler' id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $options as $option => $desc ){
			echo "<option value='$option' ".selected( $option, $value, false ).">$desc</option>\n";
		}
		echo "</select>\n";

		echo "<br/>Example: \"<span id='helper_link_usernames' class='tweet_sample'>Thank you
		<span id='toggle_link_usernames_no' class='toggle_link_usernames' style='display:". ($value == 'no' ? 'inline' : 'none') ."'>@ozh</span>
		<span id='toggle_link_usernames_yes' class='toggle_link_usernames' style='display:". ($value == 'yes' ? 'inline' : 'none') ."'>@<a href='https://twitter.com/ozh'>ozh</a></span>
		for the #WordPress plugins!</span>\"";

		break;
		
	case 'un_tco':
		$options = array(
			'no'  => 'No',
			'yes' => 'Yes',
		);
        $value = ( $value == 'yes' ? 'yes' : 'no' );
		echo "<select class='toggler' id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $options as $option => $desc ){
			echo "<option value='$option' ".selected( $option, $value, false ).">$desc</option>\n";
		}
		echo "</select>\n";
		
		echo "<br/>Example: \"<span id='helper_un_tco' class='tweet_sample fade-303060 fade'>Wow super site 
		<span id='toggle_un_tco_no' class='toggle_un_tco' style='display:". ($value == 'no' ? 'inline' : 'none') ."'><a href='http://t.co/JeIYgZHB6o'>t.co/JeIYgZHB6o</a></span>
		<span id='toggle_un_tco_yes' class='toggle_un_tco' style='display:". ($value == 'yes' ? 'inline' : 'none') ."'><a href='http://ozh.org/'>ozh.org</a></span>
		! Check it out!</span>\"";
		
		break;
		
	case 'embed_images':
		$options = array(
			'no'  => 'No',
			'yes' => 'Yes',
		);
        $value = ( $value == 'yes' ? 'yes' : 'no' );
		echo "<select class='toggler' id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $options as $option => $desc ){
			echo "<option value='$option' ".selected( $option, $value, false ).">$desc</option>\n";
		}
		echo "</select>\n";
		
		echo "<br/><span id='helper_embed_images' class='tweet_sample fade-303060 fade'>
		<span id='toggle_embed_images_no' class='toggle_embed_images' style='display:". ($value == 'no' ? 'inline' : 'none') ."'>Just display a link to the image</span>
		<span id='toggle_embed_images_yes' class='toggle_embed_images' style='display:". ($value == 'yes' ? 'inline' : 'none') ."'>Display a link and an &lt;img> after the text</span>
		</span>";
		
		break;
		
	case 'link_hashtags':
		$options = array(
			'no' => 'No',
			'twitter' => 'Yes, pointing to Twitter search',
			'local' => 'Yes, pointing to blog tag links here'
		);
		switch( $value ) {
			case 'local':
			case 'twitter':
			case 'no':
				$value = $value;
				break;
			default:
				$value = 'no';
		}
		echo "<select class='toggler' id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $options as $option => $desc ){
			echo "<option value='$option' ".selected( $option, $value, false ).">$desc</option>\n";
		}
		echo "</select>\n";
		
		echo "<br/>Example: \"<span id='helper_link_hashtags' class='tweet_sample fade-303060 fade'>Thank you @ozh for the
		<span id='toggle_link_hashtags_no' class='toggle_link_hashtags' style='display:". ($value == 'no' ? 'inline' : 'none') ."'>#WordPress</span>
		<span id='toggle_link_hashtags_twitter' class='toggle_link_hashtags' style='display:". ($value == 'twitter' ? 'inline' : 'none') ."'><a href='http://search.twitter.com/search?q=%23WordPress'>#WordPress</a></span>
		<span id='toggle_link_hashtags_local' class='toggle_link_hashtags' style='display:". ($value == 'local' ? 'inline' : 'none') ."'><a href='".ozh_ta_get_tag_link('wordpress')."'>#WordPress</a></span>
		plugins!</span>\"";
		
		break;
		
	case 'add_hash_as_tags':
		$options = array(
			'no' => 'No',
			'yes' => 'Yes',
		);
		$value = ( $value == 'yes' ? 'yes' : 'no' );
		echo "<select id='$setting' name='ozh_ta[$setting]'>\n";
		foreach( $options as $option => $desc ){
			echo "<option value='$option' ".selected( $option, $value, false ).">$desc</option>\n";
		}
		echo "</select>\n";
		echo "<br/>If selected, tags in WordPress will be created with each #hashtags.";
		break;
	}
}

// Field: screen_name
function ozh_ta_setting_screen_name() {
	ozh_ta_setting( 'screen_name' );
}

// Field: cons_key
function ozh_ta_setting_cons_key() {
	ozh_ta_setting( 'cons_key' );
}

// Field: cons_secret
function ozh_ta_setting_cons_secret() {
	ozh_ta_setting( 'cons_secret' );
}

// Field: refresh_interval
function ozh_ta_setting_refresh_interval() {
	ozh_ta_setting( 'refresh_interval' );
}

// Field: post_category
function ozh_ta_setting_post_category() {
	ozh_ta_setting( 'post_category' );
}

// Field: post_format
function ozh_ta_setting_post_format() {
	ozh_ta_setting( 'post_format' );
}

// Field: post_author
function ozh_ta_setting_post_author() {
	ozh_ta_setting( 'post_author' );
}

// Field: link_usernames
function ozh_ta_setting_link_usernames() {
	ozh_ta_setting( 'link_usernames' );
}

// Field: link_hashtags
function ozh_ta_setting_link_hashtags() {
	ozh_ta_setting( 'link_hashtags' );
}

// Field: un_tco
function ozh_ta_setting_un_tco() {
	ozh_ta_setting( 'un_tco' );
}

// Field: un_tco
function ozh_ta_setting_embed_images() {
	ozh_ta_setting( 'embed_images' );
}

// Field: add_hash_as_tags
function ozh_ta_setting_add_hash_as_tags() {
	ozh_ta_setting( 'add_hash_as_tags' );
}

// Display and fill the form field
function ozh_ta_setting_input() {
	// get option 'text_string' value from the database
	$options = get_option( 'ozh_ta_options' );
	$text_string = $options['text_string'];
	// echo the field
	echo "<input id='text_string' name='ozh_ta_options[text_string]' type='text' value='$text_string' />";
}

// Validate user input
function ozh_ta_validate_options( $input ) {
	global $ozh_ta;
	
	// Screw validation. Just don't be an idiot.
    
    // Reset if applicable
    if( isset( $_POST['delete_btn'] ) ) {
        $input = array();
        ozh_ta_schedule_next( 0 );
    } else {
        // don't lose stuff that are not "settings" submitted in the plugin page
        $input = array_merge( $ozh_ta, $input );
        
        // Consumer key & secret defined? Get an OAuth token
        if( isset( $input['cons_key'] ) && isset( $input['cons_secret'] ) && !ozh_ta_is_manually_archiving() ) {
            $input['access_token'] = ozh_ta_get_token( $input['cons_key'], $input['cons_secret'] );
        }
    }

	return $input;
}
