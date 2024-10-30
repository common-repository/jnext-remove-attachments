<?php
/**
 * Plugin Name: JNext Remove Attachments
 * Plugin Url: https://www.jnext.co.in/
 * Description: Delete attached media to all posts or single post (if activated). Remove images assigned to a post to clear old archives.
 * Version: 1.1.0
 * Author: JNext Technologies
 * Author Url: https://www.jnext.co.in/
 * License: GPL2
*/

/**
 * Add jnext row action with post and page
 */
function jnext_action_links($actions, $post) {
  
    $post_type_object = get_post_type_object( $post->post_type );
    $can_edit_post    = current_user_can( 'edit_post', $post->ID );
    $title            = _draft_or_post_title();

    if( $can_edit_post && 'trash' == $post->post_status ){
    
        $actions['single_post_media'] = sprintf(
                '<a href="%s" aria-label="%s">%s</a>',
                wp_nonce_url( admin_url( sprintf( $post_type_object->_edit_link . '&amp;action=remove_media', $post->ID ) ), "remove_media_{$post->post_type}_{$post->ID}" ),
                /* translators: %s: Post title. */
                esc_attr( sprintf( __( 'Remove &#8220;%s&#8221; Media' ), $title ) ),
                sprintf( __( 'Remove %s Attached Media' ), get_post_type($post->ID) )
            );

        $actions['multiple_post_media'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',            
            wp_nonce_url( admin_url( sprintf( $post_type_object->_edit_link . '&amp;action=remove_all_media', $post->ID ) ), "remove_all_media_{$post->post_type}_{$post->ID}" ),
            /* translators: %s: Post title. */
            esc_attr( sprintf( __( 'Remove &#8220;%s&#8221; Media From Every Place' ), $title ) ),
            __( 'Remove Media From Every Place' )
        );
        
    }
    
    return $actions;
}
add_filter('post_row_actions', 'jnext_action_links', 10, 2);
add_filter('page_row_actions', 'jnext_action_links', 10, 2);

/**
 * Redirect to post or page after remove attachments
 */
function jnext_redirect_post_location( ) {
    return admin_url( "edit.php?post_type=".get_post_type() );
} 
add_filter('redirect_post_location', 'jnext_redirect_post_location');

/**
 * Add jnext notices after remove attachments
 */
function jnext_add_flash_notice( $notice = "", $type = "warning", $dismissible = true ) {
    // Here we return the notices saved on our option, if there are not notices, then an empty array is returned
    $notices = get_option( "jnext_flash_notices", array() );
 
    $dismissible_text = ( $dismissible ) ? "is-dismissible" : "";
 
    // We add our new notice.
    array_push( $notices, array( 
            "notice" => $notice, 
            "type" => $type, 
            "dismissible" => $dismissible_text
        ) );
 
    // Then we update the option with our notices array
    update_option("jnext_flash_notices", $notices );
}

/**
 * Display jnext notices after remove attachments
 */
function jnext_display_flash_notices() {
    $notices = get_option( "jnext_flash_notices", array() );
     
    // Iterate through our notices to be displayed and print them.
    foreach ( $notices as $notice ) {
        printf('<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>',
            $notice['type'],
            $notice['dismissible'],
            $notice['notice']
        );
    }
 
    // Now we reset our options to prevent notices being displayed forever.
    if( ! empty( $notices ) ) {
        delete_option( "jnext_flash_notices", array() );
    }
}
add_action( 'admin_notices', 'jnext_display_flash_notices', 12 );


/**
 * Get all attached files and check it is used or not
 */
function jnext_get_posts_by_attachment_id( $attachment_id ) {

    $used_in_posts = array();

    if ( wp_attachment_is_image( $attachment_id ) ) {
        $query = new WP_Query( array(
            'meta_key'       => '_wp_attached_file',
            'meta_value'     => $attachment_id,
            'post_type'      => 'any',  
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => -1,
        ) );
        $used_in_posts = array_merge( $used_in_posts, $query->posts );
    }
    
    $attachment_urls = array( wp_get_attachment_url( $attachment_id ) );

    if ( wp_attachment_is_image( $attachment_id ) ) {
        foreach ( get_intermediate_image_sizes() as $size ) {
            $intermediate = image_get_intermediate_size( $attachment_id, $size );
            if ( $intermediate ) {
                $attachment_urls[] = $intermediate['url'];
            }
        }
    }
    
    foreach ( $attachment_urls as $attachment_url ) {
        $query = new WP_Query( array(
            's'              => $attachment_url,
            'post_type'      => 'any',  
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'posts_per_page' => -1,
        ) );
        
        $used_in_posts = array_merge( $used_in_posts, $query->posts );
    }
    $used_in_posts = array_unique( $used_in_posts );

    return $used_in_posts;
}

/**
 * Get all attached files from postmeta
 */
function jnext_get_attached_file(){

    global $wpdb;

    /**
     * Get post meta which have _wp_attached_file meta key
     */
    $get_meta = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file'"
    ) );
    
    return $get_meta;
}

/**
 * Check meta value with current post and return its meta
 */
function jnext_get_meta_by_post_id($meta_post_id, $post_id){
    global $wpdb;

    /**
     * Check meta value by attached file id and get post meta
     */
    $get_meta_attached_file_meta = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $wpdb->postmeta WHERE meta_value LIKE '%".$meta_post_id."%' AND post_id = $post_id" 
    ) );
    
    return $get_meta_attached_file_meta;
}

/**
 * Check same meta value used in another post or page
 */
function jnext_get_other_have_same_meta($meta_post_id, $post_id){

    global $wpdb;
    
    /**
     * Check meta value with all posts meta
     */
    // $get_same_attached_post_meta = $wpdb->get_results( $wpdb->prepare(
    //     "SELECT * FROM $wpdb->postmeta WHERE meta_value = $meta_post_id" 
    // ) );
    
    $get_attached_file_with_serialized = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $wpdb->postmeta WHERE ( meta_value LIKE '%".sprintf(':\"%s\";', $meta_post_id)."%' OR meta_value = $meta_post_id ) AND post_id != $post_id"
    ) );
    
    // $get_attached_post_meta = array_merge($get_same_attached_post_meta, $get_attached_file_with_serialized);

    return $get_attached_file_with_serialized;
}

function jnext_get_current_post_have_media($meta_post_id, $post_id, $meta_post_value){

    global $wpdb;

    $current_post_have_attachement = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $wpdb->postmeta WHERE (meta_value LIKE '%".sprintf(':\"%s\";', $meta_post_id)."%' OR meta_value = '".$meta_post_value."' OR meta_value LIKE '%".sprintf('%s', urlencode($meta_post_value))."%') AND post_id = $post_id" 
    ) );

    return $current_post_have_attachement;

}

function jnext_encoded_meta_have_media($meta_value, $post_id){

    global $wpdb;
    
    $attached_postmeta_values = $wpdb->get_results( "SELECT * FROM $wpdb->postmeta WHERE meta_value LIKE '%".sprintf('%s', urlencode($meta_value))."%' AND post_id != $post_id" );
    
    return $attached_postmeta_values;
}

/**
 * Check media exist on page or post content
 */
function jnext_get_post_meta_by_image($post_id){

    global $wpdb;
    
    $current_post = get_post($post_id); // get post detail by id
    $get_img_tag = preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $current_post->post_content, $matches); // get img tag by expression from post content
    $matched = array_reverse($matches)[0]; // get image url in array
    $attach_images = array();

    foreach($matched as $match){
        $image_url = explode('/',$match); // get image url
        $image = explode('-', end($image_url))[0]; // get image name
        
        // Get post meta from image name with meta key
        $get_used_attched_file_content = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%".$image."%'" 
        ), ARRAY_A );
        
        if(!empty($get_used_attched_file_content)){
            foreach($get_used_attched_file_content as $attach_image){
                $attach_images[] = array('post_id' => $attach_image['post_id']);
            }
        }
        
    }
    return $attach_images;

}

/**
 * Delete attachments if no post is using the attchment
 * Checks if attachment is used in post should be deleted.
 */
function jnext_remove_attachments_from_posts() { 
    
    global $wpdb;
    $published_posts = $get_other_have_attached_file_meta = $get_encoded_data_have_attached_file_meta = array();

    if(! empty( $_REQUEST['post'] )){$post_id = sanitize_text_field( $_REQUEST['post']); }
    else{$post_id = ''; }
    

    if( isset( $post_id ) ){

        if(! empty( $_REQUEST['action'] )){$action = sanitize_text_field( $_REQUEST['action']); }
        else{$action = ''; }
        

        /**
         * Get attached Images
         */
        $args = array(
            'post_type' => 'attachment',
            'numberposts' => -1,
            'post_status' => null,
            'post_parent' => $post_id
        );
        
        $attachments = get_posts($args);

        if( $action == 'remove_media' ){
            
            if ($attachments) {
                
                foreach($attachments as $attachment) {

                    $attached_parent_id = wp_get_post_parent_id( $attachment->ID );

                    $get_parent_meta_attached_file_meta = jnext_get_meta_by_post_id($attachment->ID, $attached_parent_id);
                    
                    $used_in_posts = jnext_get_posts_by_attachment_id( $attachment->ID );
                    
                    if( ! empty( $used_in_posts ) ) {
                        $args = array(
                            'ID' => $attachment->ID,
                            'post_parent' => $used_in_posts[0],
                        );
                        wp_update_post( $args );
        
                    } else {
                        if(empty($get_parent_meta_attached_file_meta)){
                            
                            wp_delete_attachment( $attachment->ID, true );
                        }                                
                    }
                }
            }
            /**
             * Checked meta data used in another post or page
             * Delete media if not used in another post or page
             */
            $get_attached_meta = jnext_get_attached_file();
            
            foreach($get_attached_meta as $attach_meta){
                
                $attach_meta_post_id = $attach_meta->post_id;

                $attach_meta_post_value = $attach_meta->meta_value;
                                
                $get_meta_attached_file_meta = jnext_get_meta_by_post_id($attach_meta_post_id, $post_id);
                
                $used_meta_in_posts = jnext_get_posts_by_attachment_id( $attach_meta_post_id );
                
                if( ! empty( $used_meta_in_posts ) ) {
                    
                    $args = array(
                        'ID' => $attach_meta_post_id,
                        'post_parent' => $used_meta_in_posts[0],
                    );
                    wp_update_post( $args );
    
                } else {
                    
                    if( !empty($get_meta_attached_file_meta) ){
                        
                        $get_other_have_attached_file_meta = jnext_get_other_have_same_meta($attach_meta_post_id, $post_id);
                        
                        $get_encoded_data_have_attached_file_meta = jnext_encoded_meta_have_media($attach_meta_post_value, $post_id);
                        
                        $get_other_have_meta = array_merge($get_other_have_attached_file_meta, $get_encoded_data_have_attached_file_meta);
                        
                        if(empty($get_other_have_meta)){

                            wp_delete_attachment( $attach_meta_post_id, true );
                            
                        }
            
                    }else{

                        $parent_id = wp_get_post_parent_id( $attach_meta_post_id ); // get attachement parent id
                        
                        if($parent_id != 0){

                            $get_parent_used_attched_file_content = jnext_get_post_meta_by_image($parent_id); // check use image in post content
                            
                            $get_parent_meta_attached_file_meta = jnext_get_meta_by_post_id($attach_meta_post_id, $parent_id); // check use image in post meta
                            
                            // If not used image in post or page then removed it
                            if(empty($get_parent_meta_attached_file_meta) && empty($get_parent_meta_attached_file_meta)){
                                
                                $current_post_have_attachement = jnext_get_current_post_have_media($attach_meta_post_id, $post_id, $attach_meta_post_value);
                                
                                if(!empty($current_post_have_attachement)){
                                    
                                    wp_delete_attachment( $attach_meta_post_id, true );
                                }
                            
                            }
                        }
                    }
                }
            }

            wp_redirect( admin_url( '/edit.php?post_type='.get_post_type($post_id) ) );

            jnext_add_flash_notice( __("Remove ".get_post_type($post_id)." with all attached media."), "info", false );
            
            exit;

        }else if( $action == 'remove_all_media' ){

            /**
             * Remove attachment
             */
            if ($attachments) {

                foreach($attachments as $attachment) {

                    wp_delete_attachment( $attachment->ID, true );

                }

            }

            /**
             * Detach media from post meta
             */
            $get_attached_meta = jnext_get_attached_file();
            
            foreach($get_attached_meta as $meta){
        
                $meta_post_id = $meta->post_id;

                $get_meta_attached_file_meta = jnext_get_meta_by_post_id($meta_post_id, $post_id);
                
                if( !empty($get_meta_attached_file_meta) ){
        
                    wp_delete_attachment( $meta_post_id, true );
        
                }
            }

            /**
             * Detach media from post content
             */

            $get_used_attched_file_content = jnext_get_post_meta_by_image($post_id);
            
            if( !empty($get_used_attched_file_content) ){
                
                foreach($get_used_attched_file_content as $attach_file){
                    
                    wp_delete_attachment( $attach_file['post_id'], true );

                }

            }

            wp_redirect( admin_url( '/edit.php?post_type='.get_post_type($post_id) ) );
            jnext_add_flash_notice( __("Remove all attached media from every posts or pages."), "info", false );
            exit;
        }

    }

}
add_action( 'admin_init', 'jnext_remove_attachments_from_posts' );