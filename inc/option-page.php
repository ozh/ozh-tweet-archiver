<?php

// Add the nagging message on all admin page except the plugin's one
function ozh_ta_notice_config() {
	global $plugin_page;

    // on the plugin page
	if( $plugin_page == 'ozh_ta' ) {
        global $ozh_ta;
        if(    isset( $ozh_ta['cons_key'] )
            && isset( $ozh_ta['cons_secret'] )
            && ( !isset( $ozh_ta['access_token'] ) || !$ozh_ta['access_token'] )
        ) {
            $message = 'Could not authenticate to Twitter. Check your consumer key and secret, then try again';
        } else {
            return;
        }

    // on all other admin pages
    } else {
        $url = menu_page_url( 'ozh_ta', false );
        $message = 'Please configure <strong>Ozh\' Tweet Archiver</strong> <a href="'.$url.'">settings</a> now';
    }

	echo "<div class='error'><p>$message</p></div>";
}

// Draw the "automatic import" menu
function ozh_ta_do_page_scheduled() {
	global $ozh_ta;
	
	if( isset( $_GET['action'] ) && $_GET['action'] == 'cancel_auto' ) {
		check_admin_referer( 'ozh_ta-cancel_auto' );
		ozh_ta_schedule_next( 0 );
		return;
	}
	
	?>
	<fieldset class="ozh_ta_fs">
	<h3>Automatic archiving</h3>
	
	<?php
	$next = wp_next_scheduled( 'ozh_ta_cron_import' );
	$freq = $ozh_ta['refresh_interval'];
	$now = time();
	if( $next < $now )
		$next = $now + $freq - 1;
		
	echo '<p>Automatic archiving is scheduled every <strong>'.ozh_ta_seconds_to_words( $freq ) .'</strong></p>';
	echo '<p>Next update in: <strong>'. ozh_ta_next_update_in() .'</strong></p>';
	$url = wp_nonce_url( admin_url( 'options-general.php?page=ozh_ta&action=cancel_auto' ), 'ozh_ta-cancel_auto' );
	echo '<p><a href="'.$url.'" class="button">Disable</a> (you can restart this anytime with a manual archiving)</p>';
	
	echo '</fieldset>';

}

// Are we manually archiving stuff?
function ozh_ta_is_manually_archiving() {
	return ( isset( $_GET['action'] ) && $_GET['action'] == 'import_all' );
}

// Draw the "manual import" menu
function ozh_ta_do_page_manual() {
	?>
	<fieldset class="ozh_ta_fs">
	<h3>Manual archiving</h3>
	<?php if( !ozh_ta_is_manually_archiving() ) { ?>
		<p>Manually archive your tweets as posts into WordPress. Once this is done, the import will be set to <strong>automatic</strong>, as per the refresh interval defined.</p>
		<p>If this is the first time you do it and you have lots of tweets to import, this will be longish. Don't close this page till it says "all done"! If the script timeouts or goes moo in the middle of an import, just refresh the page</p>
		<?php 
		$url = wp_nonce_url( admin_url( 'options-general.php?page=ozh_ta&action=import_all&time='.time() ), 'ozh_ta-import_all' );
		?>
		<p><a href="<?php echo $url; ?>" class="button">Manually archive now</a></p>

	<?php } else {
		
		check_admin_referer( 'ozh_ta-import_all' );
		
		// Import the goodness
		ozh_ta_require( 'import.php' );
        ozh_ta_schedule_next( 0 ); // clear any scheduling : it'll be rescheduled after all tweets have been imported
		ozh_ta_get_tweets( true );
		
	}
	echo '</fieldset>';

}

// Draw plugin page
function ozh_ta_do_page() {
	global $ozh_ta;
	
    ?>
    <div class="wrap">
    <?php screen_icon(); ?>
    <h2>Ozh' Tweet Archiver</h2>
	<style>
	fieldset.ozh_ta_fs {
		border:1px solid #ddd;
		padding:2px 10px;
		padding-bottom:10px;
		margin-bottom:10px;
	}
	
	</style>
	<?php
	if( ozh_ta_is_debug() ) {
		echo "<pre style='border:1px solid;padding:5px 15px;'>";
		var_dump( $ozh_ta );
		echo "</pre>";
	}
	
	if( wp_next_scheduled( 'ozh_ta_cron_import' ) && !ozh_ta_is_manually_archiving() ) {
		ozh_ta_do_page_scheduled();
	}
	
	if( ozh_ta_is_configured() ) {
		ozh_ta_do_page_manual();
	}

	// Option page when not doing operation
	if( !isset( $_GET['action'] ) or $_GET['action'] != 'import_all' ) { ?>
	    <form action="options.php" method="post">
		<?php settings_fields( 'ozh_ta' ); ?>
		<?php do_settings_sections('ozh_ta'); ?>
		<script type="text/javascript">
		(function($){
			$('.toggler').change(function(){
				var id = $(this).attr('id');
				var val = $(this).val();
				$('.toggle_'+id).hide();
				$('#toggle_'+id+'_'+val).show();
				pulse('#toggle_'+id+'_'+val );
			});
			
			var maxw = 0;
			$('select').each(function(i,e){
				var w = jQuery(this).css('width').replace('px', '');
				maxw = Math.max( maxw, w );
			}).css('width', maxw+'px');
			
			$('#link_hashtags').change(function(){
				if( $(this).val() == 'local' ) {
					pulse( $('#add_hash_as_tags').val('yes').change() );
				}
			});
			
			$('#add_hash_as_tags').change(function(){
				if( $(this).val() == 'no' && $('#link_hashtags').val() == 'local' ) {
					pulse( $('#link_hashtags').val('twitter').change() );
				}
			});
			
			function pulse( el ){
			var bg = $(el).css('backgroundColor');
			$(el).animate({backgroundColor: '#ff4'}, 500, function(){
				$(el).animate({backgroundColor: bg}, 500, function(){
					$(el).css('backgroundColor', bg);
				});
			});
			}
		
		})(jQuery);
		</script>
        <?php
        submit_button();
        submit_button( 'Reset all settings', 'delete', 'delete_btn' );
        ?>
        <script>
        (function($){
        $('#delete_btn').click( function() {
            if (!confirm('Really reset everything? No undo!')) {
                return false;
            }
        });
        })(jQuery);
        </script>
	    </form>
	<?php } ?>

	</div>
    <?php
}


function ozh_ta_reload( $url, $delay = 30 ) {
	$url = str_replace( '&amp;', '&', $url );
	ozh_ta_debug( "Reloading $url in $delay" );
	?>
	<p>Your browser will refresh this page in <strong id='countdown'><?php echo "$delay"; ?></strong> seconds. If the page does not refresh, click here: <a class="button" href="<?php echo $url; ?>">Next please</a></p>
	<script type='text/javascript'>
	var seconds = <?php echo $delay ?>;
	var millisec=0;
	var ts;

	function ozh_ta_display(){ 
		if ( millisec <= 0 ){ 
			millisec = 9;
			seconds -=1;
		}
		if ( seconds <= -1 ) {
			millisec = 0;
			seconds += 1;
		} else {
			millisec -= 1;
		}
		jQuery('#countdown').text( seconds+"."+millisec );
		ts = setTimeout("ozh_ta_display()",100);
		if( seconds == 0 && millisec == 1 ) {
			clearTimeout( ts );
			ozh_ta_nextpage();
		}
	}
	ozh_ta_display();
	
	function ozh_ta_nextpage() {
		window.location="<?php echo $url; ?>";
	}
	</script><?php
}
