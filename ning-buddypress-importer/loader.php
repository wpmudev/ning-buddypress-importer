<?php
/*
Plugin Name: Ning To BuddyPress User Importer
Version: 1.1
Plugin URI: http://premium.wpmudev.org/project/ning-to-buddypress-user-importer/
Description: Allows you to do a full import of a Ning network's users, their custom profile fields, and avatars to BuddyPress. Full support for very large member lists via optional FTP upload and batch processing.
Author: Aaron Edwards (Incsub)
Author URI: http://uglyrobot.com
Text Domain: nbi
WDP ID: 129

Copyright 2009-2013 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/* Only load code that needs BuddyPress to run once BP is loaded and initialized. */
function nbi_init() {
  require( dirname( __FILE__ ) . '/includes/ning-buddypress-importer.php' );
}
add_action( 'bp_include', 'nbi_init' );

include_once( dirname( __FILE__ ) . '/includes/dash-notice/wpmudev-dash-notification.php' );
?>