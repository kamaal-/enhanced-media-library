<?php

/**
 *  wpuxss_eml_unregister_taxonomy_for_object_type
 *
 *  Unassign taxonomy
 *
 *  @since    1.0
 *  @created  10/10/13
 */

function wpuxss_eml_unregister_taxonomy_for_object_type( $taxonomy, $object_type ) 
{
	global $wp_taxonomies;

	if ( ! isset( $wp_taxonomies[ $taxonomy ] ) )
		return false;

	if ( ! get_post_type_object( $object_type ) )
		return false;

	$key = array_search( $object_type, $wp_taxonomies[ $taxonomy ]->object_type, true );
	if ( false === $key )
		return false;

	unset( $wp_taxonomies[ $taxonomy ]->object_type[ $key ] );
	return true;
}




/**
 *  wpuxss_eml_taxonomies_validate
 *
 *  @type     callback function
 *  @since    1.0
 *  @created  28/09/13
 */ 
 
function wpuxss_eml_taxonomies_validate($input)
{		
	if ( !$input ) $input = array();

	foreach ( $input as $taxonomy => $params )
	{	
		$sanitized_taxonomy = sanitize_key($taxonomy);
		
		if ( $sanitized_taxonomy !== $taxonomy ) 
		{
			$input[$sanitized_taxonomy] = $input[$taxonomy];
			unset($input[$taxonomy]);
			$taxonomy = $sanitized_taxonomy;
		}
		
		if ( !isset($params['hierarchical']) )
			$input[$taxonomy]['hierarchical'] = 0;		
		
		if ( !isset($params['sort']) )
			$input[$taxonomy]['sort'] = 0;
		
		if ( !isset($params['show_admin_column']) )
			$input[$taxonomy]['show_admin_column'] = 0;
		
		if ( !isset($params['show_in_nav_menus']) )
			$input[$taxonomy]['show_in_nav_menus'] = 0;

		if ( !isset($params['assigned']) )
			$input[$taxonomy]['assigned'] = 0;

		if ( !isset($params['admin_filter']) )
			$input[$taxonomy]['admin_filter'] = 0;
		
		if ( !isset($params['media_uploader_filter']) )
			$input[$taxonomy]['media_uploader_filter'] = 0;
			
		$input[$taxonomy]['hierarchical'] = intval($input[$taxonomy]['hierarchical']);
		$input[$taxonomy]['sort'] = intval($input[$taxonomy]['sort']);
		$input[$taxonomy]['show_admin_column'] = intval($input[$taxonomy]['show_admin_column']);
		$input[$taxonomy]['show_in_nav_menus'] = intval($input[$taxonomy]['show_in_nav_menus']);
		$input[$taxonomy]['assigned'] = intval($input[$taxonomy]['assigned']);
		$input[$taxonomy]['admin_filter'] = intval($input[$taxonomy]['admin_filter']);
		$input[$taxonomy]['media_uploader_filter'] = intval($input[$taxonomy]['media_uploader_filter']);
		
		if ( isset($params['labels']) )
		{
			$default_labels = array(
				'menu_name' => $params['labels']['name'],
				'all_items' => 'All ' . $params['labels']['name'],
				'edit_item' => 'Edit ' . $params['labels']['singular_name'],
				'view_item' => 'View ' . $params['labels']['singular_name'], 
				'update_item' => 'Update ' . $params['labels']['singular_name'],
				'add_new_item' => 'Add New ' . $params['labels']['singular_name'],
				'new_item_name' => 'New ' . $params['labels']['singular_name'] . ' Name',
				'parent_item' => 'Parent ' . $params['labels']['singular_name'],
				'search_items' => 'Search ' . $params['labels']['name']
			);
			
			foreach ( $params['labels'] as $label => $value )
			{
				$input[$taxonomy]['labels'][$label] = sanitize_text_field($value);
				
				if ( empty($value) && isset($default_labels[$label]) ) 
				{
					$input[$taxonomy]['labels'][$label] = sanitize_text_field($default_labels[$label]);
				}
			}
		}
		
		if ( isset($params['rewrite']['slug']) )
			$input[$taxonomy]['rewrite']['slug'] = sanitize_key($params['rewrite']['slug']);
	}
		
	return $input;
}




/**
 *  wpuxss_eml_ajax_query_attachments
 *
 *  Based on /wp-admin/includes/ajax-actions.php
 *
 *  @since    1.0
 *  @created  03/08/13
 */

add_action( 'wp_ajax_query-attachments', 'wpuxss_eml_ajax_query_attachments', 0 );

function wpuxss_eml_ajax_query_attachments() 
{
	if ( ! current_user_can( 'upload_files' ) )
		wp_send_json_error();

	$query = isset( $_REQUEST['query'] ) ? (array) $_REQUEST['query'] : array();
	$query = array_intersect_key( $query, array_flip( array(
		's', 'order', 'orderby', 'posts_per_page', 'paged', 'post_mime_type',
		'post_parent', 'post__in', 'post__not_in', 'taxonomy', 'term_id',
	) ) );


	$query['post_type'] = 'attachment';
	$query['post_status'] = 'inherit';
	if ( current_user_can( get_post_type_object( 'attachment' )->cap->read_private_posts ) )
		$query['post_status'] .= ',private';
	if ( isset( $query['taxonomy'] ) && isset( $query['term_id'] ) && $query['term_id'] != 0) 
	{
		$query['tax_query'] = array(
			array(
				'taxonomy' => $query['taxonomy'],
				'field' => 'id',
				'terms' => $query['term_id']
			)
		);
	}
	else 
	{
		$query['tax_query'] = null;
	}
	unset ( $query['taxonomy'] );
	unset ( $query['term_id'] );
		
	$query = new WP_Query( $query );

	$posts = array_map( 'wp_prepare_attachment_for_js', $query->posts );
	$posts = array_filter( $posts );

	wp_send_json_success( $posts );
}




/**
 *  wpuxss_eml_restrict_manage_posts
 *
 *  Filter media by category
 *
 *  @since    1.0
 *  @created  11/08/13
 */

add_action('restrict_manage_posts','wpuxss_eml_restrict_manage_posts');

function wpuxss_eml_restrict_manage_posts() 
{
	global $pagenow, $wp_query;
	
	$wpuxss_eml_taxonomies = get_option('wpuxss_eml_taxonomies');
	
	if ( $pagenow == 'upload.php' ) 
	{
		foreach ( get_object_taxonomies('attachment','object') as $taxonomy ) 
		{
			if ( $wpuxss_eml_taxonomies[$taxonomy->name]['admin_filter'] )
			{	
				if ( isset($wp_query->query[$taxonomy->name]) )
				{
					$selected = $wp_query->query[$taxonomy->name];
				}
				elseif ( isset($wp_query->query['taxonomy']) && isset($wp_query->query['term']) )
				{
					$term = get_term_by( 'slug', $wp_query->query['term'], $taxonomy->name );
					$selected = $term->term_id;
				}
				else 
				{
					$selected = 0;
				}
					
				wp_dropdown_categories(
					array(
						'show_option_all' =>  $taxonomy->labels->all_items,
						'taxonomy'        =>  $taxonomy->name,
						'name'            =>  $taxonomy->name,
						'orderby'         =>  'name',
						'selected'        =>  $selected,
						'hierarchical'    =>  true,
						'depth'           =>  3,
						'show_count'      =>  false,
						'hide_if_empty'   =>  true
					)
				);
			}
		}
	}
}




/**
 *  wpuxss_eml_parse_query
 *
 *  @since    1.0
 *  @created  11/08/13
 */
 
add_filter('parse_query', 'wpuxss_eml_parse_query');

function wpuxss_eml_parse_query($query) 
{
	global $pagenow;
	
	if ( $pagenow=='upload.php' )
	{
		$qv = &$query->query_vars;
		
		foreach ( get_object_taxonomies('attachment','object') as $taxonomy ) 
		{
			if ( isset($qv[$taxonomy->name]) && is_numeric($qv[$taxonomy->name]) ) 
			{
				$term = get_term_by('id', $qv[$taxonomy->name], $taxonomy->name);
				if ($term) $qv[$taxonomy->name] = $term->slug;
			}
		}
	}
}




/**
 *  wpuxss_eml_attachment_fields_to_edit
 *
 *  @since    1.0
 *  @created  14/08/13
 */
 
add_filter( 'attachment_fields_to_edit', 'wpuxss_eml_attachment_fields_to_edit', 10, 2 );

function wpuxss_eml_attachment_fields_to_edit( $form_fields, $post ) 
{	
	foreach ( get_attachment_taxonomies($post->ID) as $taxonomy ) 
	{
		$terms = get_object_term_cache($post->ID, $taxonomy);
		
		$t = (array) get_taxonomy($taxonomy);
		if ( ! $t['public'] || ! $t['show_ui'] )
			continue;
		if ( empty($t['label']) )
			$t['label'] = $taxonomy;
		if ( empty($t['args']) )
			$t['args'] = array();
		
		
		if ( false === $terms )
			$terms = wp_get_object_terms($post->ID, $taxonomy, $t['args']);
			
		$values = array();
	
		foreach ( $terms as $term )
			$values[] = $term->slug;
			
		$t['value'] = join(', ', $values);
		$t['show_in_edit'] = false;
		
		if ( $t['hierarchical'] ) 
		{
			ob_start();
			
				wp_terms_checklist( $post->ID, array( 'taxonomy' => $taxonomy, 'checked_ontop' => false, 'walker' => new Walker_Media_Taxonomy_Checklist() ) );
				
				if ( ob_get_contents() != false )
					$html = '<ul class="term-list">' . ob_get_contents() . '</ul>';
				else
					$html = '<ul class="term-list"><li>No ' . $t['label'] . '</li></ul>';
			
			ob_end_clean();
			
			$html .= '<input type="hidden" class="text" id="attachments-'.$post->ID.'-'.$taxonomy.'" name="attachments['.$post->ID.']['.$taxonomy.']" value="'.$t['value'].'">';
			
			$t['input'] = 'html';
			$t['html'] = $html; 
		}
	
		$form_fields[$taxonomy] = $t;
	}

	return $form_fields;
}




/**
 *  Walker_Media_Taxonomy_Checklist
 *
 *  Based on /wp-includes/category-template.php
 *
 *  @since    1.0
 *  @created  09/09/13
 */

class Walker_Media_Taxonomy_Checklist extends Walker 
{
	var $tree_type = 'category';
	var $db_fields = array ('parent' => 'parent', 'id' => 'term_id'); 

	function start_lvl( &$output, $depth = 0, $args = array() ) 
	{
		$indent = str_repeat("\t", $depth);
		$output .= "$indent<ul class='children'>\n";
	}

	function end_lvl( &$output, $depth = 0, $args = array() ) 
	{
		$indent = str_repeat("\t", $depth);
		$output .= "$indent</ul>\n";
	}

	function start_el( &$output, $category, $depth = 0, $args = array(), $id = 0 ) 
	{
		extract($args);
		
		if ( empty($taxonomy) )
			$taxonomy = 'category';

		if ( $taxonomy == 'category' )
			$name = 'post_category';
		else
			$name = 'tax_input['.$taxonomy.']';

		$class = in_array( $category->term_id, $popular_cats ) ? ' class="popular-category"' : '';
		$output .= "\n<li id='{$taxonomy}-{$category->term_id}'$class>" . '<label class="selectit"><input value="' . $category->slug . '" type="checkbox" name="'.$name.'[]" id="in-'.$taxonomy.'-' . $category->term_id . '"' . checked( in_array( $category->term_id, $selected_cats ), true, false ) . disabled( empty( $args['disabled'] ), false, false ) . ' /> ' . esc_html( apply_filters('the_category', $category->name )) . '</label>';
	}

	function end_el( &$output, $category, $depth = 0, $args = array() ) 
	{
		$output .= "</li>\n";
	}
}




/**
 *  wpuxss_eml_pre_get_posts
 *
 *  Taxonomy archive specific query (front-end)
 *
 *  @since    1.0
 *  @created  03/08/13
 */

add_action( 'pre_get_posts', 'wpuxss_eml_pre_get_posts' );

function wpuxss_eml_pre_get_posts( $query )
{
	$wpuxss_eml_taxonomies = get_option('wpuxss_eml_taxonomies');
	
	foreach ( $wpuxss_eml_taxonomies as $taxonomy => $params )
	{
		if ( $params['assigned'] && $query->is_main_query() && is_tax($taxonomy) && !is_admin() )
		{
			$query->set( 'post_status', 'inherit' );
		}	
	}
}

?>