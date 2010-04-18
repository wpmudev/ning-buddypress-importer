<?php
/*
Plugin Name: Ning To BuddyPress User Importer
Version: 1.0
Plugin URI: http://premium.wpmudev.org/project/ning-buddypress-importer
Description: Allows you to do a full import of a Ning network's users, their custom profile fields, and avatars to BuddyPress. Full support for very large member lists via optional FTP upload and batch processing.
Author: Aaron Edwards (Incsub)
Author URI: http://uglyrobot.com

Copyright 2009-2010 Incsub (http://incsub.com)

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

//------------------------------------------------------------------------//

//---Config---------------------------------------------------------------//

//------------------------------------------------------------------------//



//------------------------------------------------------------------------//

//---Hook-----------------------------------------------------------------//

//------------------------------------------------------------------------//

register_activation_hook( __FILE__, 'nbi_install' );
add_action( 'init', 'nbi_localization' );
add_action( 'admin_menu', 'nbi_plug_pages' );

//------------------------------------------------------------------------//

//---Functions------------------------------------------------------------//

//------------------------------------------------------------------------//

function nbi_install() {
  //create default email text
  $subject = "FULLNAME: Here is Your New Login Information For SITENAME";
  $message = "Hello FULLNAME,

Due to recent changes in the policies of Ning, we are migrating our network to a new exciting software platform that will allow us to expand and provide amazing new features. We have gone ahead and created a new login and profile for you based on the old one. Here are your new login details:

Username: USERNAME
Password: PASSWORD

Please login (LOGINURL) and update your password to something more memorable.

Thank you!
The SITENAME Team";

  add_option('nbi_email_subject', $subject, '', 'no');
  add_option('nbi_email_text', $message, '', 'no');
}

function nbi_localization() {
  // Load up the localization file if we're using WordPress in a different language
	// Place it in this plugin's "languages" folder and name it "nbi-[value in wp-config].mo"
	load_plugin_textdomain( 'nbi', FALSE, '/ning-importer/languages' );
}

function nbi_plug_pages() {
  //only show to site admins if on wpmu
  if (is_multisite() && !is_site_admin())
    return;

	add_submenu_page('bp-general-settings', __('Ning Importer', 'nbi'), __('Ning Importer', 'nbi'), 'edit_users', 'ning-importer', 'nbi_page_output');
}

function nbi_get_profile_fields($all = false) {
  $file_path = nbi_file_path();

  $fh = @fopen($file_path, 'r');
  if ($fh) {

    $fields = fgetcsv($fh, 5120); // 5KB

    fclose($fh);
    
    if ($all) {
      return $fields;
    } else {
      //filter out standard fields
      foreach ($fields as $field) {
        if ( $field != 'Name' && $field != 'Email' && $field != 'Profile Address' && $field != 'Date Joined' )
          $profile_fields[] = $field;
      }
      return $profile_fields;
    }
    
	} else {
    return false;
  }
}

function nbi_get_csv_array() {
  $file_path = nbi_file_path();
  $titles = nbi_get_profile_fields(true);
  
  $fh = @fopen($file_path, 'r');
  if ($fh) {
    while (!feof($fh)) {
      //parse csv line
      $temp_fields = fgetcsv($fh, 5120); // 5KB
      
      if (is_array($temp_fields)) {
        //switch keys out for titles
        $new_fields = array();
        foreach ($temp_fields as $key => $value) {
          $new_fields[$titles[$key]] = $value;
        }
      }
      
      $fields[] = $new_fields;
    }


    fclose($fh);
    
    //remove header row
    array_shift($fields);
    
    return $fields;
	} else {
    return false;
  }
}

function nbi_file_path($dir = false) {
  $target_path = wp_upload_dir();
  if ($dir)
    return trailingslashit($target_path['basedir']);
  else
    return trailingslashit($target_path['basedir']) . 'ning-export.csv';
}

function nbi_process_import_queue() {
  @set_time_limit(60);
  $users = nbi_get_csv_array();
  
  $total = count($users);
  $count = get_option('nbi_count');
  $count = (!$count) ? 0 : $count;
  
  $imported_count = get_option('nbi_imported_count');
  $imported_count = (!$imported_count) ? 0 : $imported_count;
  
  $errors = get_option('nbi_errors');
  
  //only do 5 users at a time
  $users = array_slice($users, $count, 5);

  if (count($users)) {
    echo '<ul style="margin-left: 40px;">';
    //loop through queue
    foreach ($users as $user) {

      //get username from email prefix
      $temp_email = explode('@', $user['Email']);
      $username = sanitize_user($temp_email[0], true);

      //make username unique by apending a number
      $i = 0;
      while (username_exists($username)) {
        $i++;
        $username = $username . $i;
      }

      //check email
      if (email_exists($user['Email'])) {
        $errors[] = __('Skipped - Email address already in use: ', 'nbi') . $user['Email'];
        //fatal error, so skip to next user
        $count++;
        update_option('nbi_count', $count);
        continue;
      }

      //seperate first/last name
      $temp_name = explode(' ', $user['Name']);
      $first_name = $temp_name[0];
      $last_name = ($temp_name[2]) ? $temp_name[2] : $temp_name[1];

      //registered date
      $joined_date = date('Y-m-d H:i:s', strtotime($user['Date Joined']));

      $password = wp_generate_password();

      ///*
  		// create user
  		$user_id = wp_insert_user(array(
        						"user_login"       => $username,
        						"display_name"     => $user['Name'],
        						"nickname"         => $user['Name'],
        						"first_name"       => $first_name,
        						"last_name"        => $last_name,
        						"user_pass"        => $password,
        						"user_email"       => $user['Email'],
        						"user_registered"  => $joined_date,
        						"role"             => 'subscriber'
                    ));
      //*/
      
  		if (!$user_id) {
  			$errors[] = __('Could not create user: ', 'nbi') . $user['Name'] . ' (' . $username . ')';
  		} else {

  			//now insert buddypress profile fields if BP active
  			if (function_exists('bp_is_active')) {
  		    nbi_import_buddypress_fields($user_id, $user);
  		    
  		    //scrape avatar
          nbi_fetch_ning_avatar( $user_id, $user['Profile Address'] );
        }

  			//notify user
  			nbi_new_user_notification($user_id, $password);
        
        //display status on page
        echo '<li style="font-size: 14px; display: block; height: 25px; line-height: 25px;">';
        echo bp_core_fetch_avatar( array('item_id' => $user_id, 'width' => 25, 'height' => 25) ) . ' ';
        echo sprintf(__('Successfully Imported: %s - %s (%s)', 'nbi'), $user['Name'], $username, $user['Email']) . '</li>';

        //save the successful count
        $imported_count++;
        update_option('nbi_imported_count', $imported_count);
  		}

      //save location (skipped or not)
      $count++;
      update_option('nbi_count', $count);
    }
    //update error array
    update_option('nbi_errors', $errors);
    
    echo "</ul>";
    echo '<h3>' . sprintf(__('Processed %d of %d members so far. Please be patient as this process may take a while.', 'nbi'), $count, $total) . '</h3>';

    ?>
    <br />
    <p><?php _e( 'If your browser doesn&#8217;t start loading the next page automatically, click this link:' ); ?> <a class="button" href="admin.php?page=ning-importer&action=process"><?php _e("Next Members", 'nbi'); ?></a></p>
  	<script type='text/javascript'>
  	<!--
  	function nextpage() {
  		//window.location.reload();
      window.location='admin.php?page=ning-importer&action=process';
  	}
  	setTimeout( "nextpage()", 250 );
  	//-->
  	</script><?php
  	
  } else {
    //complete message
    echo '<h3>' . sprintf(__('Congratulations! %d of %d members were imported successfully.', 'nbi'), $imported_count, $total) . '</h3>';
    ?>
    <p><?php _e( 'They should be recieving your notification email with their username and temporary random password.', 'nbi'); ?></p>
    <p><a class="button" href="admin.php?page=bp-profile-setup"><?php _e("Adjust Profile Fields", 'nbi'); ?></a></p>
    
    <?php
    //print error list
    if (is_array($errors) && count($errors)) {
      echo '<p>' . sprintf(__('%d errors were encountered during the import:', 'nbi'), count($errors)) . '</p>';
      echo "<ul>";
      foreach ($errors as $error)
        echo '<li>'.$error.'</li>';
      echo "</ul>";
    }
    
    //delete import file and options
    if (@unlink(nbi_file_path())) {
      //delete options
      delete_option('nbi_count');
      delete_option('nbi_imported_count');
      delete_option('nbi_errors');
    }

  }
}

function nbi_create_buddypress_fields() {
  $fields = nbi_get_profile_fields();

  foreach ($fields as $field) {
    //skip if existing
    if (xprofile_get_field_id_from_name($field))
			continue;

		$args = array(
			'field_group_id' => 1,
			'name' => $field,
			'type' => 'textarea',
			'is_required' => false
		);
    xprofile_insert_field( $args );
  }


}

function nbi_import_buddypress_fields($user_id, $user) {
  $fields = nbi_get_profile_fields();

  //loop through custom fields and insert
  foreach ($fields as $field) {
    if ($user[$field])
      xprofile_set_field_data( $field, $user_id, $user[$field]);
  }
}

//filters http timeout
function nbi_filter_http_timeout( $val ) {
	return 30;
}

//filters useragent
function nbi_filter_http_useragent( $user_agent ) {
	return 'Ning To BuddyPress Importer: http://premium.wpmudev.org';
}

//scans the user's profile page and scrapes their avatar, adding it to BP
function nbi_fetch_ning_avatar( $user_id, $profile_url ) {

  add_filter( 'http_request_timeout', 'nbi_filter_http_timeout' );
  add_filter( 'http_headers_useragent', 'nbi_filter_http_useragent' );
  
  //get the avatar url from the profile
  $profile_page = wp_remote_retrieve_body(wp_remote_get($profile_url));
  if (!$profile_page)
    return false;

  $sub_html = trim(substr($profile_page, strpos($profile_page, 'class="module_user_thumbnail"', 2000) + 30, 200));
  $img_url = substr($sub_html, strpos($sub_html, 'src="http://api.ning.com/files/') + 5);
  $img_url = substr($img_url, 0, strpos($img_url, '?'));
  if (!$img_url)
    return false;
    
  $url = $img_url.'?width='.BP_AVATAR_FULL_WIDTH.'&height='.BP_AVATAR_FULL_HEIGHT.'&crop=1%3A1';
  
	// extract the file name and extension from the url
	$file_name = basename($img_url);

	// get placeholder file in the upload dir with a unique sanitized filename
	$upload = wp_upload_dir();
	
	$upload['path'] = $upload['basedir'];

	$filename = wp_unique_filename( $upload['path'], $file_name );

	$new_file = $upload['path'] . "/$filename";
	if ( ! wp_mkdir_p( dirname( $new_file ) ) ) {
		$message = sprintf( __( 'Unable to create directory %s. Is its parent directory writable by the server?' ), dirname( $new_file ) );
		return array( 'error' => $message );
	}

	$ifp = @ fopen( $new_file, 'wb' );
	if ( ! $ifp )
		return array( 'error' => sprintf( __( 'Could not write file %s' ), $new_file ) );

	@fwrite( $ifp, $bits );
	fclose( $ifp );
	// Set correct file permissions
	$stat = @ stat( dirname( $new_file ) );
	$perms = $stat['mode'] & 0007777;
	$perms = $perms & 0000666;
	@ chmod( $new_file, $perms );

	// fetch the remote url and write it to the placeholder file
	$headers = wp_get_http($url, $new_file);

	//Request failed
	if ( ! $headers ) {
		@unlink($new_file);
		return new WP_Error( 'import_file_error', __('Remote server did not respond') );
	}

	// make sure the fetch was successful
	if ( $headers['response'] != '200' ) {
		@unlink($new_file);
		return new WP_Error( 'import_file_error', sprintf(__('Remote file returned error response %1$d %2$s'), $headers['response'], get_status_header_desc($headers['response']) ) );
	} else if ( isset($headers['content-length']) && filesize($new_file) != $headers['content-length'] ) {
		@unlink($new_file);
		return new WP_Error( 'import_file_error', __('Remote file is incorrect size') );
	}
	
  //if file extension is bin rename it based on image headers
	if ( preg_match( '!\.(bin)$!i', $filename ) ) {
    if ( strpos($headers['content-type'], 'image/jpeg') !== false ) {
      $renamed = str_replace('.bin', '.jpg', $new_file);
      @rename($new_file, $renamed);
      $new_file = $renamed;
    } else if ( strpos($headers['content-type'], 'image/png') !== false ) {
      $renamed = str_replace('.bin', '.png', $new_file);
      @rename($new_file, $renamed);
      $new_file = $renamed;
    } else if ( strpos($headers['content-type'], 'image/gif') !== false ) {
      $renamed = str_replace('.bin', '.gif', $new_file);
      @rename($new_file, $renamed);
      $new_file = $renamed;
    } else {
      @unlink($new_file);
      return new WP_Error( 'import_file_error', __('Invalid file type') );
    }
	} else { //not bin, check if valid
    $wp_filetype = wp_check_filetype( $filename );
  	if ( !$wp_filetype['ext'] ) {
      @unlink($new_file);
      return new WP_Error( 'import_file_error', __('Invalid file type') );
    }
  }
  
  //save to buddypress
  $avatar_folder_dir = apply_filters( 'bp_core_avatar_folder_dir', BP_AVATAR_UPLOAD_PATH . '/avatars/' . $user_id, $user_id, 'user', 'avatars' );

  require_once( ABSPATH . '/wp-admin/includes/image.php' );
	require_once( ABSPATH . '/wp-admin/includes/file.php' );

	/* Delete the existing avatar files for the object */
	bp_core_delete_existing_avatar( array( 'object' => 'user', 'avatar_path' => $avatar_folder_dir ) );

	/* Set the full and thumb filenames */
	$full_filename = wp_hash( $original_file . time() ) . '-bpfull.jpg';
	$thumb_filename = wp_hash( $original_file . time() ) . '-bpthumb.jpg';

  //make sure directory exists
  wp_mkdir_p( $avatar_folder_dir );

	/* Crop the image */
	$full_cropped = wp_crop_image( $new_file, 0, 0, BP_AVATAR_FULL_WIDTH, BP_AVATAR_FULL_HEIGHT, BP_AVATAR_FULL_WIDTH, BP_AVATAR_FULL_HEIGHT, false, $avatar_folder_dir . '/' . $full_filename );
	$thumb_cropped = wp_crop_image( $new_file, 0, 0, BP_AVATAR_FULL_WIDTH, BP_AVATAR_FULL_HEIGHT, BP_AVATAR_THUMB_WIDTH, BP_AVATAR_THUMB_HEIGHT, false, $avatar_folder_dir . '/' . $thumb_filename );

	/* Remove the original */
	@unlink( $new_file );

	return true;

}


//------------------------------------------------------------------------//

//---Output Functions-----------------------------------------------------//

//------------------------------------------------------------------------//

function nbi_new_user_notification($user_id, $plaintext_pass) {
	$user = new WP_User($user_id);

	$user_login = stripslashes($user->user_login);
	$user_email = stripslashes($user->user_email);

	// The blogname option is escaped with esc_html on the way into the database in sanitize_option
	// we want to reverse this for the plain text arena of emails.
	$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

  //filter subject
  $subject = stripslashes(get_option('nbi_email_subject'));
  $subject = str_replace('FULLNAME', $user->display_name, $subject);
	$subject = str_replace('USERNAME', $user_login, $subject);
	$subject = str_replace('EMAIL', $user_email, $subject);
	$subject = str_replace('SITENAME', $blogname, $subject);

  //filter message
  $message = stripslashes(get_option('nbi_email_text'));
  $message = str_replace('FULLNAME', $user->display_name, $message);
	$message = str_replace('USERNAME', $user_login, $message);
	$message = str_replace('PASSWORD', $plaintext_pass, $message);
	$message = str_replace('EMAIL', $user_email, $message);
	$message = str_replace('LOGINURL', wp_login_url(), $message);
	$message = str_replace('SITENAME', $blogname, $message);

	wp_mail($user_email, $subject, $message);

}

//------------------------------------------------------------------------//

//---Page Output Functions------------------------------------------------//

//------------------------------------------------------------------------//

function nbi_page_output() {
	global $wpdb;

  //only show to site admins if on wpmu
  if (is_multisite() && !is_site_admin())
    return;

	if(!current_user_can('edit_users')) {
		echo "<p>Nice Try...</p>";  //If accessed properly, this message doesn't appear.
		return;
	}

	echo '<div class="wrap">';
	
	switch( $_GET[ 'action' ] ) {
		//---------------------------------------------------//
		default:

			$file_path = nbi_file_path();

			?>
			<h2><?php _e('Ning Importer', 'nbi') ?></h2>
			<?php

      //delete file
      if (isset($_GET['del']) && file_exists($file_path)) {
        @unlink($file_path);
        echo '<div class="updated fade"><p>'.__('Import file successfully deleted.', 'nbi').'</p></div>';
      }

			//if uploaded file
			if (isset($_FILES['csv_file']['name'])) {
			
        //make sure directory exists
        wp_mkdir_p( nbi_file_path(true) );
        
        //check extension
        if ( preg_match( '!\.(csv)$!i', strtolower($_FILES['csv_file']['name']) ) ) {
        
          //attempt to move uploaded file
          if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $file_path)) {
            @unlink($_FILES['csv_file']['tmp_name']);
            echo '<div class="error"><p>'.__('There was a problem uploading your file. Please check permissions or use FTP.', 'nbi').'</p></div>';
          } else {
            //success, clear options
            delete_option('nbi_count');
            delete_option('nbi_imported_count');
            delete_option('nbi_errors');
          }

        } else {
          echo '<div class="error"><p>'.__('Invalid file format. Please upload your export file ending in ".csv".', 'nbi').'</p></div>';
        }
        
      }

      //if file has been uploaded
      if (file_exists($file_path)) {
        $fields = nbi_get_profile_fields();
        $total = count(nbi_get_csv_array());
        ?>
  			<form action="admin.php?page=ning-importer&action=process" method="post">
  			<h3><?php echo sprintf(__('A Ning import file was detected with %d members to process.', 'nbi'), $total); ?></h3>
  			<p><a class="button" href="admin.php?page=ning-importer&del=1"><?php _e("Delete Import File", 'nbi'); ?></a></p>

        <?php
        //custom fields
        if (is_array($fields) && count($fields)) {
          ?>
    			<h3><?php _e('Detected Custom Profile Fields', 'nbi') ?></h3>
    			<p><?php _e('The following custom profile fields will be created if not existing and imported to BuddyPress. You should modify the field types and settings after import.', 'nbi') ?></p>
    			<?php
          echo '<ul style="margin-left: 40px">';
          foreach ($fields as $field)
            echo '<li>'.$field.'</li>';
          echo "</ul>";
        }
        ?>
        
  			<h3><?php _e('Customize Your Email Notification:', 'nbi') ?></h3>
  			<p>
          <label for="email_subject"><?php _e('Subject:', 'nbi') ?></label><br />
          <input name="email_subject" value="<?php echo esc_attr(stripslashes(get_option('nbi_email_subject'))) ?>" size="100" /><br />
          <small><?php _e('No HTML allowed. The following codes will be replaced with their appropriate values: FULLNAME, USERNAME, EMAIL, SITENAME', 'nbi') ?></small>
        </p>
  			<p>
          <label for="email_text"><?php _e('Message:', 'nbi') ?></label><br />
          <textarea name="email_text" cols="100" rows="10"><?php echo esc_attr(stripslashes(get_option('nbi_email_text'))) ?></textarea><br />
          <small><?php _e('No HTML allowed. The following codes will be replaced with their appropriate values: FULLNAME, USERNAME, PASSWORD, EMAIL, LOGINURL, SITENAME', 'nbi') ?></small>
  			</p>
  			<p><?php _e('Please be patient while members are being imported. The page will attempt to import 5 members at a time then refresh to import the next batch. Don\'t worry if you have duplicate members in your csv as it will skip existing BuddyPress users if their email address exists. Ready?', 'nbi') ?></p>
  			<p class="submit">
  			  <input name="Submit" value="<?php _e('Import Members &raquo;', 'nbi') ?>" type="submit">
  			</p>
  			</form>
        <p>
        <?php _e('<strong>Please Note:</strong><br /><br />Spam filters, especially strict ones for institutional email addresses, can sometimes block this username and login information from reaching your members. You should consider also sending out a bulk email via another method to your network notifying them of the migration and who to contact if they don\'t recieve this notification.', 'nbi') ?>
        </p>
        <?php
        
      } else { //file does not exist, show upload form
      
  		  ?>
  			<form action="admin.php?page=ning-importer" method="post" enctype="multipart/form-data">
  			<p><?php echo sprintf(__('Please <a href="http://help.ning.com/cgi-bin/ning.cfg/php/enduser/std_adp.php?p_faqid=3023" target="_blank">export your Ning network members to a CSV file</a> and upload that file here. If your CSV is too large, you can ftp it to the "%s" directory and rename it to "ning-export.csv".', 'nbi'), nbi_file_path(true)); ?></p>
  			<p>
  			  <input name="csv_file" id="csv_file" size="20" type="file" /><br />
  			  <small><?php echo __('Maximum file size: ', 'nbi') . ini_get('upload_max_filesize'); ?></small>
  			</p>
  			<p class="submit">
  			  <input name="Submit" value="<?php _e('Upload &raquo;', 'nbi') ?>" type="submit">
  			</p>
  			</form>
        <?php
        
      } //end file exists
		break;
		
		//---------------------------------------------------//
		case "process":
      //save email text
      if (isset($_POST['email_subject'])) {
        update_option('nbi_email_subject', strip_tags($_POST['email_subject']));
        update_option('nbi_email_text', strip_tags($_POST['email_text']));
      }

      ?>
			<h2><?php _e('Importing Members...', 'nbi') ?></h2>
			<br />
			<?php
			//if file has been uploaded
      if (file_exists(nbi_file_path())) {
        nbi_create_buddypress_fields();
        nbi_process_import_queue();
      }
		break;

	}
	
	echo '</div>';
}



//------------------------------------------------------------------------//

//---Support Functions----------------------------------------------------//

//------------------------------------------------------------------------//



?>