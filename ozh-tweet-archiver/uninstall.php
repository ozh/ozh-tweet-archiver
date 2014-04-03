<?php
// Make sure that we are uninstalling
if ( !defined('WP_UNINSTALL_PLUGIN') ) {
    exit();
}

// Leave no trail in DB
delete_option('ozh_ta');

// Unregister any cron job
wp_clear_scheduled_hook( 'ozh_ta_cron_import' );
