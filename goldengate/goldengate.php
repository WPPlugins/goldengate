<?php

/*
Plugin Name: GoldenGate
Plugin URI: http://code.google.com/p/goldengate/
Description: A bridge between Picasa Web Albums and Wordpress
Version: 1.5
Author: Jeff Fisher
Author URI: http://code.google.com/p/goldengate/wiki/AboutAuthor
*/

/*  Copyright 2007 Google Inc.

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

define('GG_PLUGIN_DIR', 'goldengate');
define('GG_DEFAULT_PAGE_SIZE', '25');


if (!class_exists('Zend_Loader')) {
  add_action('admin_notices', 'goldengate_need_zend');
}
else {
  Zend_Loader::loadClass('Zend_Gdata_Photos');
  Zend_Loader::loadClass('Zend_Gdata_Photos_UserQuery');
  Zend_Loader::loadClass('Zend_Gdata_Photos_PhotoQuery');
  Zend_Loader::loadClass('Zend_Gdata_App_MediaFileSource');
  Zend_Loader::loadClass('Zend_Gdata_AuthSub');

  add_action('admin_menu', 'goldengate_add_pages');
  add_action('admin_notices', 'goldengate_check_authsub');
  
  //WP 2.5+
  add_filter('media_upload_tabs', 'goldengate_add_goldengate_media_tab');
  add_action('media_upload_goldengate_browse', 'goldengate_handle_browse_hook');
  add_action('media_upload_goldengate_upload', 'goldengate_show_upload_form_hook');
  
  //TODO: implement these actions and point forms at admin-post.php
  //add_action('admin_post_goldengate_upload', 'goldengate_handle_upload');
  //add_action('admin_post_goldengate_browse', 'goldengate_handle_browse_action')
}

/**
 * Prints an error message in case the Zend GData plugin isn't installed.
 */
function goldengate_need_zend() {
  echo <<<END_OF_HTML
  <div class='error'>
  <p><strong>
  GoldenGate Plugin: The Zend Gdata Framework needs to be
  installed and activated.
  </strong></p>
  </div>
END_OF_HTML;
}

/**
 * Print a message if the AuthSub token is not valid.
 * Also handle responses from Google Accounts servers.
 */
function goldengate_check_authsub() {
  
  //current user is a subscriber and doesn't care
  if(! current_user_can('edit_posts')) {
    return;
  }
  
  if(goldengate_grab_get('token') != null) {
    try {
      $useSslProxy = goldengate_get_option('goldengate_use_ssl_proxy', 0);
      if($useSslProxy) {
        $sslProxyHost = goldengate_get_option('goldengate_ssl_proxy_host', '');
        $sslProxyPort = goldengate_get_option('goldengate_ssl_proxy_port', '');
        $client = new Zend_Http_Client('https://www.example.com/', array(
               'adapter'      => 'Zend_Http_Client_Adapter_Proxy',
               'proxy_host'   => $sslProxyHost,
               'proxy_port'   => intval($sslProxyPort)
        ));
        $token = Zend_Gdata_AuthSub::getAuthSubSessionToken(goldengate_grab_get('token'), $client);
      }
      else {
        $token = Zend_Gdata_AuthSub::getAuthSubSessionToken(goldengate_grab_get('token'));
      }
      goldengate_set_authsub_token($token);
      echo "<div class='updated'><p><strong>GoldenGate Plugin: Updated AuthSub Token. You can now use Google Photos integration!</strong></p></div>";
    }
    catch(Zend_Gdata_App_AuthException $e) {
        
       $retryLink = goldengate_get_authsub_link();
      goldengate_print_error("Authentication Error - Error exchanging token. <a href='$retryLink'>Retry?</a>");
    }
    catch(Zend_Gdata_App_HttpException $e) {
      goldengate_print_error("Problems making connection. Check proxy?");
    }

  }
  
  if(!goldengate_valid_authsub()) {
    $loginLink = goldengate_get_authsub_link();
    goldengate_print_error("Authentication Error - AuthSub token invalid or not set. <a href='$loginLink'>Login to Google Photos</a>");
  }
}

/**
 * Checks if the current AuthSub token is valid.
 * @return boolean true if the token is valid.
 */
function goldengate_valid_authsub() {
    
    $token = goldengate_get_authsub_token();
    
    if($token) {
      try {
        $useSslProxy = goldengate_get_option('goldengate_use_ssl_proxy', 0);
        if($useSslProxy) {
          $sslProxyHost = goldengate_get_option('goldengate_ssl_proxy_host', '');
          $sslProxyPort = goldengate_get_option('goldengate_ssl_proxy_port', '');
          $client = new Zend_Http_Client('https://www.example.com/', array(
                 'adapter'      => 'Zend_Http_Client_Adapter_Proxy',
                 'proxy_host'   => $sslProxyHost,
                 'proxy_port'   => intval($sslProxyPort)
          ));
          $resp = Zend_Gdata_AuthSub::getAuthSubTokenInfo($token, $client);
        }
        else {
          $resp = Zend_Gdata_AuthSub::getAuthSubTokenInfo($token);
        }
      
        if(preg_match('/^Target=(.+)\nSecure=(.+)\nScope=(.+)/', $resp)) {
            return true;
        }
      }
      catch(Zend_Gdata_App_HttpException $e) {
        goldengate_print_error("Problems checking token. Check proxy?");
      }
    }
    
    return false;
    
    
}

/**
 * Handle the upload POST. This means that a user submitted the upload form
 * on the upload tab. The goal is to validate their input and send the
 * photo over to Google's servers.
 */
function goldengate_handle_upload() {


    if(goldengate_grab_post('action') == 'upload') {
        check_admin_referer('goldengate_upload_form');
     
        $albumId = goldengate_grab_post('post_album');
        $caption = goldengate_grab_post('post_caption');
        
        
        if($albumId == null) {
            goldengate_set_error("No album specified.");
            return;
        }
        
        if(!isset($_FILES['photo_upload'])) {
            goldengate_set_error("No photo uploaded!");
            return;
        }
        
        if($_FILES['photo_upload']['error']) {
            goldengate_set_error("Problem uploading photo.");
            return;
        }
        
        $service = goldengate_handle_login();
        
        $fd = $service->newMediaFileSource($_FILES['photo_upload']['tmp_name']);
        $fd->setContentType($_FILES['photo_upload']['type']);
        
        $entry = new Zend_Gdata_Photos_PhotoEntry();
        $entry->setMediaSource($fd);
        $entry->title = $service->newTitle($_FILES['photo_upload']['name']);
        $entry->summary = $service->newSummary($caption);
        
        $query = new Zend_Gdata_Photos_AlbumQuery();
        $query->setAlbumId($albumId);
        
        try {
            $resultEntry = $service->insertPhotoEntry($entry, $query->getQueryUrl());
        }
        catch(Zend_Gdata_App_Exception $e) {
            goldengate_set_error("Problem sending photo to Picasa.");
            return;
        }
        
        if(!$resultEntry) {
            //error on upload to picasa
            goldengate_set_error("Photo not sent correctly to Picasa.");
            return;
        }
        
        $args = array('albumId' => $albumId, 'tab' => 'goldengate_browse');
        wp_redirect(add_query_arg($args));
    }
}

/**
 * Handle actions from the album browsing menu
 */
function goldengate_handle_browse_action() {

    $link = get_bloginfo('wpurl') . '/wp-content/plugins/' . GG_PLUGIN_DIR . '/js/gg.js';
    wp_enqueue_script('gg_js', $link, array('jquery'), '0.7');
    
    //remove these arguments from REQUEST_URI to make sure the tab links
    //do not get altered.
    $_SERVER['REQUEST_URI'] = remove_query_arg(array('albumId','photoId','gAction','startIndex'));

    $action = goldengate_grab_post('gAction');
    if($action == 'send_to_editor') {
      $title = goldengate_grab_post('img_title');
      $img_src = goldengate_grab_post('img_src');
      $thumb_src = goldengate_grab_post('img_thumb_src');
      $page_src = goldengate_grab_post('page_src');
      $album_src = goldengate_grab_post('album_src');
      
      
      $link = goldengate_grab_post('link');
      $display = goldengate_grab_post('display');
      $html = '';
      
      if($link != 'none') {
        
        if($link == 'file') {
          $url = $img_src;
        }
        else if($link == 'album') {
          $url = $album_src;
        }
        else {
          $url = $page_src;
        }
        
        $html .= "<a href='$url'>";
      }
      
      if($display != 'title') {
        $html .= "<img src='" . ($display == 'thumb' ? $thumb_src : $img_src)
          . "' alt='$title' />";
      }
      else {
        $html .= $title;
      }
      
      if($link != 'none') {
        $html .= "</a>";
      }
      
      return media_send_to_editor($html);
    }
    else if($action == 'update' || $action == 'delete') {
        check_admin_referer('goldengate_edit_form');
        
        $caption = goldengate_grab_post('post_caption');
        $albumId = goldengate_grab_get('albumId');
        $photoId = goldengate_grab_get('photoId');
        
        if($albumId == null) {
            goldengate_set_error("AlbumId is undefined.");
            return;
        }
        
        if($photoId == null) {
            goldengate_set_error("PhotoId is undefined.");
            return;
        }
        
        $service = goldengate_handle_login();

        $query = new Zend_Gdata_Photos_PhotoQuery();
        $query->setPhotoId($photoId);
        $query->setAlbumId($albumId);
        $query->setType('entry');
        $url = $query->getQueryUrl();
        
        try {
            $photoEntry = $service->getPhotoEntry($url);
        }
        catch(Zend_Gdata_App_Exception $e) {
            goldengate_set_error("Problem retrieving data from Google Photos.");
            return;
        }
        
        if($action == 'delete') { 
            try {
                $photoEntry->delete();
            }
            catch(Zend_Gdata_App_Exception $e) {
                goldengate_set_error("Problem deleting photo from Google Photos.");
                return;
            }
        }
        else {
            $photoEntry->summary = $service->newSummary($caption);
            try {
                $photoEntry->save();
            }
            catch(Zend_Gdata_App_Exception $e) {
                goldengate_set_error("Problem updating photo on Google Photos.");
                return;
            }
        }
        
        $args = array('albumId' => $albumId, 'tab' => 'goldengate_browse');
        wp_redirect(add_query_arg($args));
        
    }
}

/**
 * Adds custom tabs to the add media window (v2.5)
 */
function goldengate_add_goldengate_media_tab($tabs) {
  
  $type = goldengate_grab_get('type');

  if($type != 'image' and $type != null) {
    return $tabs;
  }
  
  if(ini_get('file_uploads')) {
    $newTabs = array(
    'goldengate_upload' => 'Upload to Google',
    'goldengate_browse' => 'Your Photos on Google', 
    );
  }
  else {
    $newTabs = array(
    'goldengate_browse' => 'Your Photos on Google', 
    );
  }
  return array_merge($tabs, $newTabs);
}

function goldengate_show_upload_form_hook() {
  wp_admin_css('media');
  goldengate_handle_upload();

  return wp_iframe('goldengate_show_upload_form');
}

function goldengate_handle_browse_hook() {
  wp_admin_css('media');
  goldengate_custom_css();
  goldengate_handle_browse_action();
  
  return wp_iframe('goldengate_handle_browse');
}

/**
 * Sets up the service object to control a connection to Google Photos
 * with the appropriate authentication using a stored AuthSub session token.
 */
function goldengate_handle_login() {
    
    $token = goldengate_get_authsub_token();
    
    $client = Zend_Gdata_AuthSub::getHttpClient($token);
    $service = new Zend_Gdata_Photos($client);
    
    return $service;
    
}


/**
 * Displays the album or photo desired by the user.
 */
function goldengate_handle_browse() {
  
    media_upload_header();
    
    $service = goldengate_handle_login();
    $albumId = goldengate_grab_get('albumId');
    $photoId = goldengate_grab_get('photoId');
    
    
    try {
        if($photoId && $albumId != null) {
            goldengate_show_photo_details($service, $photoId, $albumId);
        }
        else {
            goldengate_show_album($service, $albumId);
        }
    }
    catch(Zend_Gdata_App_Exception $e) {
        echo '<div style="padding-top:10px">';
        goldengate_print_error('Problems retrieving data from Google Photos.');
        echo '</div>';
    }
    
}


/**
 * Displays an upload form for Google Photos.
 */
function goldengate_show_upload_form() {
  
    media_upload_header();
    
    $service = goldengate_handle_login();
    
    $albums = goldengate_get_album_list($service);
    
    
    if(is_wp_error($albums)) {
        $error = $albums->get_error_message();
        echo "<div style='padding-top:10px'>Problem retrieving album list.</div>";
        return;
    }
    
    if(empty($albums)) {
        echo '<div style="padding-top:10px">No albums available.</div>';
        return;
    }
    
    $actionUrl = attribute_escape($_SERVER['REQUEST_URI']);
    
    $albumId = goldengate_get_album_choice($albums);
    
    $selectBox = goldengate_make_select($albums, $albumId, 'post_album');
    
    //capture the output from functions that print directly to the browser
    //so we can format their information better
    ob_start();
    wp_nonce_field('goldengate_upload_form');  
    $nonceField = ob_get_contents();
    ob_end_clean();
    
    ob_start();
    goldengate_print_error();
    $error = ob_get_contents();
    ob_end_clean();
    
    echo <<<END_OF_HTML

<form enctype="multipart/form-data" id="upload-file" class="media-upload-form type-form validate" method="post" action="$actionUrl">
$error
<table><col /><col class="widefat" />
    <tr>
    <th scope="row"><label for="upload">File</label></th>
    <td><input type="file" id="upload" name="photo_upload" /></td>
    </tr>
    <tr>
    <th scope="row"><label for="post_album">Album</label></th>
    <td>$selectBox</td>
    </tr>
    <tr>
    <th scope="row"><label for="post_caption">Caption</label></th>
    <td><input type="text" id="post_caption" name="post_caption" value="" /></td>
    </tr>
    <tr id="buttons" class="submit">
        <td colspan='2'>
        <input type="hidden" name="action" value="upload" />
        $nonceField
        <div class="submit">
        <input type="submit" value="Upload &raquo;" />
        </div>
        </td>
    </tr>
</table>
</form>
END_OF_HTML;
}

/**
 * Detail view of a photo. Handles both inserting photo into blog post
 * and editing/removing the photo.
 * @param Zend_Gdata_Photos $service An authenticated service object.
 * @param string $photoId The ID of the photo we are retrieving.
 * @param string $albumId The ID of the album the photo is in.
 */
function goldengate_show_photo_details($service, $photoId, $albumId) {
    
    $query = new Zend_Gdata_Photos_PhotoQuery();
    $query->setPhotoId($photoId);
    $query->setAlbumId($albumId);
    $query->setParam('thumbsize','200');
    $query->setParam('imgmax','800u');
    $query->setType('entry');
  
    $photo = $service->getPhotoEntry($query);
    $mediaGroup = $photo->getMediaGroup();
    
    $query2 = new Zend_Gdata_Photos_AlbumQuery();
    $query2->setAlbumId($albumId);
    $query2->setType('entry');
    $album = $service->getAlbumEntry($query2);
    
    if($mediaGroup == null) {
        wp_set_error("Retrieved photo without media data?!");
        $img = "";
        $full_img = "";
        $content = "";
    }
    else {
        $thumbnails = $mediaGroup->getThumbnail();
        $img = $thumbnails[0]->getUrl();
        $content = $mediaGroup->getContent();
        $full_img = $content[0]->getUrl();
        //the image name
        $title = $mediaGroup->getTitle();
        //the photo caption
        $caption = $mediaGroup->getDescription();
    }
    
    if($caption != '') {
        $title = $caption;
    }
    $altLink = $photo->getAlternateLink()->href;
    $link = attribute_escape(add_query_arg('albumId', $albumId));
    $args = array('albumId' => $albumId, 'photoId' => $photoId);
    $insertLink = attribute_escape(add_query_arg($args));
    $args['gAction'] = 'edit';
    $editLink = attribute_escape(add_query_arg($args));
    $actionUrl = $insertLink;
    $albumLink = $album->getAlternateLink()->href;
    
    ob_start();
    wp_nonce_field('goldengate_edit_form');  
    $nonceField = ob_get_contents();
    ob_end_clean();
    
    ob_start();
    goldengate_print_error();
    $error = ob_get_contents();
    ob_end_clean();
    
    if(goldengate_grab_get('gAction') == 'edit') {
        $formLine = "<form method='post' name='editoptions' id='editoptions' action='$actionUrl'>";
        $editInsertLink = "<a href='$insertLink'>Insert</a>";
        $optionTable =<<<END_OF_HTML
        <table>
        <col/><col class="widefat"/>
        <tbody>
        <tr><th scope="row"><label for="url">URL</label></th>
        <td><input style="width: 100%;" id="url" class="readonly" value="$full_img" readonly="readonly" type="text"/></td></tr>
        <tr><th scope="row"><label for="pageurl">Page URL</label></th>
        <td><input style="width: 100%;" id="pageurl" class="readonly" value="$altLink" readonly="readonly" type="text"/></td></tr>
        <tr><th scope="row"><label for="post_caption">Caption</label></th>
        <td><input style="width: 100%;" id="post_caption" name="post_caption" value="$caption" type="text"/></td></tr>
        <tr><td colspan="2">
        <p class="submit">
        <input name="delete" class="delete" value="Delete File" onclick="GG.deleteFile();" type="button"/>
        <input name="gAction" id="gAction" value="update" type="hidden"/>
        $nonceField
        <input value="Save &raquo;" type="submit"/>
        </p>
        </td></tr></tbody>
        </table>
END_OF_HTML;
    }
    else {
        $editInsertLink = "<a href='$editLink'>Edit</a>";
        $formLine = "<form method='post' name='uploadoptions' id='uploadoptions' class='alignleft' action='$actionUrl'>";
        $optionTable =<<<END_OF_HTML
        <table><tbody><tr>
        <th style="padding-bottom: 0.5em;">Show:</th>
        <td style="padding-bottom: 0.5em;">
        <label for="display-full">
        <input name="display" id="display-full" value="full" type="radio" checked="checked"/> Full size (max 800px)</label>
        <br/><label for="display-thumb">
        <input name="display" id="display-thumb" value="thumb" type="radio" /> Thumbnail</label>
        <br/><label for="display-title">
        <input name="display" id="display-title" value="title" type="radio"/> Title</label>
        </td></tr>
        <tr><th>Link to:</th>
        <td><label for="link-file"><input name="link" id="link-file" value="file"  type="radio"/> File</label>
        <br/><label for="link-page"><input name="link" id="link-page" value="page" checked="checked" type="radio"/> Page</label>
        <br/><label for="link-album"><input name="link" id="link-album" value="album" type="radio"/> Album</label>
        <br/><label for="link-none"><input name="link" id="link-none" value="none" type="radio"/> None</label></td></tr>
        <tr><td colspan="2">
        <p class="submit">
        <input value="Send to editor &raquo;" type="submit"/>
        <input name="gAction" id="gAction" value="send_to_editor" type="hidden"/>
        </p></td></tr>
        </tbody>
        </table>
END_OF_HTML;
    }
    

    echo <<<END_OF_HTML
    
<div id="gg_photo_details">
    $error
    <a href='$link' class='gg_link'>&laquo; Back to Album</a>
    <div id="gg_photo_title">
    <h2>$title</h2> &#8212; $editInsertLink
    </div>
    
    <div id="gg_photo_preview" class="alignleft">
    <img src='$img' alt='$title'/>
    </div>
    
    $formLine
    <input name="img_src" value="$full_img" type="hidden"/>
    <input name="img_thumb_src" value="$img" type="hidden"/>
    <input name="img_title" value="$title" type="hidden"/>
    <input name="page_src" value="$altLink" type="hidden"/>
    <input name="album_src" value="$albumLink" type="hidden"/>

    $optionTable
    
    </form>
</div>
    
END_OF_HTML;
}

/**
 * Displays all of the photos in an album.
 * @param Zend_Gdata_Photos $service An authenticated service object.
 * @param string $albumId The album ID of the album to display.
 */
function goldengate_show_album($service, $albumId = null) {
    
    
    $albums = goldengate_get_album_list($service);
    
    if(is_wp_error($albums)) {
        $error = $albums->get_error_message();
        echo "<div style='padding-top:10px'>Problem retrieving album list.</div>";
        return;
    }
    
    if(empty($albums)) {
        echo '<div style="padding-top:10px">No albums available.</div>';
        return;
    }
    
    
    if($albumId == null) {
        $albumId = goldengate_get_album_choice($albums);
    }
    else {
        update_option('goldengate_last_album', $albumId);
    }
    
    $startIndex = goldengate_grab_get('startIndex');
    
  
    $query = new Zend_Gdata_Photos_AlbumQuery();
    $query->setParam('kind','photo');
    $query->setAlbumId($albumId);
    $query->setParam('thumbsize','144');
    $query->setMaxResults(goldengate_get_option('goldengate_page_size', GG_DEFAULT_PAGE_SIZE));
    if($startIndex) {
      $query->setStartIndex($startIndex);
    }

    
    $photoFeed = $service->getAlbumFeed($query);
    $albumId = $photoFeed->getGphotoId()->text;
    $albumUrl = $photoFeed->getAlternateLink()->href;
    $nextLink = $photoFeed->getNextLink()->href;
    $prevLink = $photoFeed->getPreviousLink()->href;
    
    

    $url = attribute_escape(add_query_arg('albumId', $albumId));
    
    if($nextLink) {
      preg_match('/start-index=(\d+)/', $nextLink, $matches);
      $qargs = array('albumId' => $albumId, 'startIndex' => $matches[1]);
      $nextLink = attribute_escape(add_query_arg($qargs));
    }
    
    if($prevLink) {
      preg_match('/start-index=(\d+)/', $prevLink, $matches);
      $qargs = array('albumId' => $albumId, 'startIndex' => $matches[1]);
      $prevLink = attribute_escape(add_query_arg($qargs));
    }
    
    $selectBox = goldengate_make_select($albums, $albumId, '', 'albumId');
    echo '<div id="gg_album_view">';
    echo '<div id="gg_album_chooser">';
    echo 'Album Browser: ' . $selectBox;
    echo '</div>';
    echo '<ul id="gg_album_photo_list">';
    
    foreach($photoFeed as $entry) {
        if($entry instanceof Zend_Gdata_Photos_PhotoEntry) {
            $thumbnails = $entry->getMediaGroup()->getThumbnail();
            $img = $thumbnails[0]->getUrl();
            $title = $entry->getTitle();
            $photoId = $entry->getGphotoId()->text;
            $qargs = array('albumId' => $albumId, 'photoId' => $photoId);
            $link = attribute_escape(add_query_arg($qargs));
            
            echo "<li class='gg_photo_thumb'>";
            echo "<a href='$link' class='file-link image' style='width: 146px; height: 144px;'>&nbsp;";
            echo "<img src='$img' alt='$title' /></a></li>";
        }
    }
   
    echo '</ul>';
    echo '</div>';
    echo "<div class='gg_link'><table width='100%' style='padding-right:10px;'><tr>";
    if($prevLink) {
      echo "<td><a href='$prevLink'>&laquo; Previous</a></td>";
    }
    if($nextLink) {
      echo "<td align='right'><a href='$nextLink'>Next &raquo;</a></td>";
    }
    echo "</tr></table></div>";
    echo "<div class='gg_link'><a href='$albumUrl' target='_blank'>Browse this album on Picasa</a>.</div>";
}

function goldengate_custom_css() {
  
  $link = get_bloginfo('wpurl') . '/wp-content/plugins/' . GG_PLUGIN_DIR . '/css/gg.css';
  wp_enqueue_style('goldengate_browse', $link);
}

/**
 * Constructs a select box, such as to render a list of albums.
 * @param array $options An array with key-value pairs for the select list
 * options.
 * @param mixed $selected The default option to be selected, if any.
 * @param mixed $id The XHTML id of the select box.
 * @param mixed $queryArg If the value is a url, then this is the query
 * parameter the passed in key should be used with.
 * 
 * @return string HTML representing the constructed select box.
 */
function goldengate_make_select($options, $selected = null, $id = '', $queryArg = null) {
    
    if($queryArg != null) {
        $onChangeHtml = ' onchange="window.location = this.options[this.selectedIndex].value"';
    }
    else {
        $onChangeHtml = '';
    }
    
    if($id) {
        $id = " name='$id' id='$id'";
    }
    
    $html = "<select{$id}{$onChangeHtml}>";
    
    foreach($options as $key => $value) {
        if($key == $selected) {
            $isSelected = " selected='selected'";
        }
        else {
            $isSelected = '';
        }
        
        if($queryArg != null) {
            $key = attribute_escape(add_query_arg($queryArg, $key));
        }
        
        $html .= "<option value='$key'$isSelected>$value</option>";
    }
    
    $html .= '</select>';
    
    return $html;
}


/**
 * Returns a link for the user to follow in order to grant access to
 * Google Photos via AuthSub.
 */
function goldengate_get_authsub_link() {
    $redirect = goldengate_get_current_url();
    $scope = 'http://picasaweb.google.com/data/';
    $authSubURL = Zend_Gdata_AuthSub::getAuthSubTokenUri($redirect, $scope, false, true);
    return $authSubURL;
}

/**
 * Returns an array of available albums, indexed by albumID
 * @param Zend_Gdata_Photos $service An authenticated service object.
 * @return mixed An array of albums or a WP_Error object if unable to
 *     retrieve the album list.
 */
function goldengate_get_album_list($service) {
    
    $query = new Zend_Gdata_Photos_UserQuery();
    $query->setParam('kind','album');
    $albums = array();
    
    try {
        $userFeed = $service->getUserFeed(null, $query);

        foreach($userFeed as $entry) {
            if($entry instanceof Zend_Gdata_Photos_AlbumEntry) {
                
                $albumId = $entry->getGphotoId()->text;
                $albums[$albumId] = $entry->getTitle();
            }
        }
    
    }
    catch(Zend_Gdata_App_Exception $e) {
        return new WP_Error('gdata-error', $e->getMessage());
    }
    
    return $albums;
}

/**
 * Decides what album to default to. Tries to remember the last album used.
 * @param mixed $albums An array of albums indexed by ID.
 * @return string The ID of the album to display first.
 */
function goldengate_get_album_choice($albums) {

    //try to get the last album used
    if(get_option('goldengate_last_album') != null) {
        $lastAlbum = get_option('goldengate_last_album');
        if(array_key_exists($lastAlbum, $albums)) {
            return $lastAlbum;
        }
        else {
            //default to the first album retrieved.
            $id_list = array_keys($albums);
            return $id_list[0];
        }
    }
    else {
        //default to the first album retrieved.
        $id_list = array_keys($albums);
        return $id_list[0];    
    }
}

/**
 * Decides the value of an option, choosing a default if the option
 * is not set.
 * @param string $option The option name.
 * @param string $default The default value of the option.
 */
function goldengate_get_option($option, $default) {
  $option_value = get_option($option);
  
  if($option_value != null) {
    return $option_value;
  }
  else {
    update_option($option, $default);
    return $default;
  }
}

/**
 * Gets the active AuthSub token.
 * @return string The currently active AuthSub token.
 */
function goldengate_get_authsub_token() {
  $multipleAuthSub = goldengate_get_option('goldengate_multiple_authsub', 0);
  
  if($multipleAuthSub) {
    $current_user = wp_get_current_user();
    return get_usermeta($current_user->ID, 'goldengate_authsub_token');
  }
  else {
    return get_option('goldengate_authsub_token');
  }
}

/**
 * Sets the active AuthSub token.
 * @param string $token The AuthSub token to set as active.
 */
function goldengate_set_authsub_token($token) {
  
  $multipleAuthSub = goldengate_get_option('goldengate_multiple_authsub', 0);
  
  if($multipleAuthSub) {
    $current_user = wp_get_current_user();
    return update_usermeta($current_user->ID, 'goldengate_authsub_token', $token);
  }
  else {
    return update_option('goldengate_authsub_token', $token);
  }
}

/**
 * Retrieve POST variables in a sane way.
 * @param string $var The name of the POST variable you are retrieving.
 * @return string The contents of that variable.
 */
function goldengate_grab_post($var) {
    if(isset($_POST[$var])) {
        return attribute_escape($_POST[$var]);
    }
    else {
        return null;
    }
}

/**
 * Retrieve GET variables in a sane way.
 * @param string $var The name of the GET variable you are retrieving.
 * @return string The contents of that variable.
 */
function goldengate_grab_get($var) {
    if(isset($_GET[$var])) {
        return attribute_escape($_GET[$var]);
    }
    else {
        return null;
    }
}

/**
 * Constructs the present URL of the page the user is viewing.
 * @return string The URL the user is accessing.
 */
function goldengate_get_current_url() {
    
    if (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on') {
        $protocol = 'https://';
        $ssl = true;
    }
    else {
        $protocol = 'http://';
        $ssl = false;
    }
    
    $port = $_SERVER['SERVER_PORT'];
    if($port != '' && ((!$ssl && $port != '80') || ($ssl && $port != '443'))) {
        $port = ":$port";
    }
    else {
        $port = '';
    }
    
    $host = $_SERVER['SERVER_NAME'];
    $requestURI = attribute_escape($_SERVER['REQUEST_URI']);
    
    return $protocol . $host . $port . $requestURI;
    
}

/**
 * Prints out an error div.
 */
function goldengate_print_error($message = null) {
    if($message == null) {
        $errors = goldengate_set_error();
        foreach($errors as $error) {
            goldengate_print_error($error);
        }
    }
    else {
        echo "<div class='error'><p><strong>GoldenGate Plugin: $message</strong></p></div>";
    }
}

/**
 * Sets an error message to be printed later.
 * @return mixed Returns an array of existing errors.
 */
function goldengate_set_error($error = null) {
    static $errors = array();

    if($error != null) {
        $errors[] = $error;
    }

    return $errors;
}

/**
 * Adds additional pages to the admin menu.
 */
function goldengate_add_pages() {
  add_options_page('GoldenGate Options', 'GoldenGate Options', 8, 'goldengateoptions', 'goldengate_options_page');
}

/**
 * Adds an options page that allows the user to view/clear
 * their AuthSub token from Picasa Web Albums.
 */
function goldengate_options_page() {
    
    $actionURL = get_option('siteurl') . "/wp-admin/options-general.php?page=goldengateoptions";
    $token = goldengate_get_authsub_token();
    $pageSize = goldengate_get_option('goldengate_page_size', GG_DEFAULT_PAGE_SIZE);
    $multipleAuthSub = goldengate_get_option('goldengate_multiple_authsub', 0);
    $multipleAuthSub ? $multipleAuthSub = 'checked' : $multipleAuthSub = '';
    $useSslProxy = goldengate_get_option('goldengate_use_ssl_proxy', 0);
    $useSslProxy ? $useSslProxy = 'checked' : $useSslProxy = '';
    $sslProxyHost = goldengate_get_option('goldengate_ssl_proxy_host', '');
    $sslProxyPort = goldengate_get_option('goldengate_ssl_proxy_port', '');

    
    if(goldengate_grab_post('gg_clear') == 'Y' ) {

      goldengate_set_authsub_token('');
      $token = '';
      echo '<div class="updated"><p><strong>Auth Token cleared.</strong></p></div>';
    }
    
    if(goldengate_grab_post('gg_save') == 'Y' ) {
      
      $pageSize = goldengate_grab_post('gg_page_size');
      update_option('goldengate_page_size', $pageSize);
      
      $multipleAuthSub = goldengate_grab_post('gg_user_linking');
      if($multipleAuthSub == null) {$multipleAuthSub = 0;}
      update_option('goldengate_multiple_authsub', $multipleAuthSub);
      $multipleAuthSub ? $multipleAuthSub = 'checked' : $multipleAuthSub = '';
      
      $useSslProxy = goldengate_grab_post('gg_use_ssl_proxy');
      if($useSslProxy == null) { $useSslProxy = 0; }
      update_option('goldengate_use_ssl_proxy', $useSslProxy);
      $useSslProxy ? $useSslProxy = 'checked' : $useSslProxy = '';
      
      $sslProxyHost = goldengate_grab_post('gg_ssl_proxy_host');
      update_option('goldengate_ssl_proxy_host', $sslProxyHost);
      
      $sslProxyPort = goldengate_grab_post('gg_ssl_proxy_port');
      update_option('goldengate_ssl_proxy_port', $sslProxyPort);
      
      echo '<div class="updated"><p><strong>Preferences saved.</strong></p></div>';
    }
    
    echo <<<EOH
    
<div class="wrap">
<h2>GoldenGate Options</h2>


<h3>Site Preferences</h3>

<form name="goldengateauthsub" method="post" action="{$actionURL}">
<input type="hidden" name="gg_save" value="Y" />
<p>Items per page:
<input type="text" name="gg_page_size" value="$pageSize" size="5" />
</p>
<p>
<input type="checkbox" name="gg_user_linking" value="1" $multipleAuthSub /> Enable per-user account linking.
</p>
<p>
<input type="checkbox" name="gg_use_ssl_proxy" value="1" $useSslProxy /> Use proxy server for SSL? (GoDaddy required)
</p>
<p>SSL Proxy host (e.g. proxy.shr.secureserver.net):
<input type="text" name="gg_ssl_proxy_host" value="$sslProxyHost" size="25" />
</p>
<p>SSL Proxy port (e.g. 3128):
<input type="text" name="gg_ssl_proxy_port" value="$sslProxyPort" size="5" />
</p>
<p class="submit">
<input type="submit" name="Submit" value="Save" />
</p>
</form>

<h3>Authentication</h3>

<form name="goldengateauthsub" method="post" action="{$actionURL}">
<input type="hidden" name="gg_clear" value="Y" />
<p>AuthSub token:
<input type="text" readonly="readonly" name="gg_token" value="$token" size="30" style="background-color: #ddd;" />
</p>
<p class="submit">
<input type="submit" name="Submit" value="Clear Auth Token" />
</p>
</form>
</div>
EOH;
}

?>
