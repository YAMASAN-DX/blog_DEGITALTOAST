<?php
//////////////////////////////////////////////////
//親テーマのCSSを読み込む
//////////////////////////////////////////////////
function fit_head_child() {
	if ( get_option('fit_seo_cssLoad') == "value2" && get_option('fit_seo_cssLoad-main')) {
		echo '<link class="css-async" rel href="'.get_template_directory_uri().'/style.css">'."\n";
	}else{
		echo '<link rel="stylesheet" href="'.get_template_directory_uri().'/style.css">'."\n";
	}
	if (is_singular()){
		if ( get_option('fit_seo_cssLoad') == "value2" && get_option('fit_seo_cssLoad-content')) {
			echo '<link class="css-async" rel href="'.get_template_directory_uri().'/css/content.css">'."\n";
		}else{
			echo '<link rel="stylesheet" href="'.get_template_directory_uri().'/css/content.css">'."\n";
		}
	}
}
add_action('wp_head', 'fit_head_child');



//////////////////////////////////////////////////
//下記ユーザーカスタマイズエリア
//////////////////////////////////////////////////

/*	wp_headの余分な表記を消す
------------------------------------------------------------------------------*/
if (!is_admin()) {
	//　head内（ヘッダー）から不要なコード削除
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'index_rel_link' );
	remove_action( 'wp_head', 'parent_post_rel_link', 10, 0 );
	remove_action( 'wp_head', 'start_post_rel_link', 10, 0 );
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10, 0 );
	remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0 );
	remove_action('wp_head', 'feed_links', 2);
	remove_action('wp_head', 'feed_links_extra', 3);

	//head内（ヘッダー）絵文字削除
	remove_action('wp_head', 'print_emoji_detection_script', 7);
	remove_action('admin_print_scripts', 'print_emoji_detection_script');
	remove_action('wp_print_styles', 'print_emoji_styles' );
	remove_action('admin_print_styles', 'print_emoji_styles');

	//head内（ヘッダー）Embed系の記述削除
	remove_action('wp_head','rest_output_link_wp_head');
	remove_action('wp_head','wp_oembed_add_discovery_links');
	remove_action('wp_head','wp_oembed_add_host_js');
	remove_action('template_redirect', 'rest_output_link_header', 11 );
}


?>