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

/**
 * 著者アイコンURLを表示名から取得する
 *
 * @param int $user_id ユーザーID。
 * @return string アイコン画像URL。
 */
function fit_get_author_icon_url( $user_id = 0 ) {
	$admin_icon  = 'https://d-toast.com/wp-content/uploads/2026/09/men.png';
	$editor_icon = 'https://d-toast.com/wp-content/uploads/2026/09/women.png';

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		$user_id = (int) get_the_author_meta( 'ID' );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return '';
	}

	$slug  = (string) $user->user_nicename;
	$login = (string) $user->user_login;
	$name  = (string) $user->display_name;
	$nick  = (string) $user->nickname;
	$group = (string) $user->user_group;
	$haystack = $name . ' ' . $nick . ' ' . $group . ' ' . $slug . ' ' . $login;

	$editor_keys = array(
		'henshutyo',
		'編集長デジトー',
		'編集者デジトー',
		'編集長',
		'編集者',
	);
	$admin_keys = array(
		'gran_master',
		'デジトー 管理人',
		'デジトー管理人',
		'管理人デジトー',
		'管理人',
	);

	if ( 2 === $user_id ) {
		return $editor_icon;
	}
	if ( 1 === $user_id ) {
		return $admin_icon;
	}

	foreach ( $editor_keys as $key ) {
		if ( false !== mb_strpos( $haystack, $key ) ) {
			return $editor_icon;
		}
	}
	foreach ( $admin_keys as $key ) {
		if ( false !== mb_strpos( $haystack, $key ) ) {
			return $admin_icon;
		}
	}

	return '';
}

/**
 * アバター取得元からWP_Userを解決する
 *
 * @param mixed $id_or_email ユーザーID・メール・WP_User・コメントなど。
 * @return WP_User|false
 */
function fit_resolve_user_from_avatar_id( $id_or_email ) {
	if ( $id_or_email instanceof WP_User ) {
		return $id_or_email;
	}
	if ( $id_or_email instanceof WP_Post ) {
		return get_user_by( 'id', (int) $id_or_email->post_author );
	}
	if ( $id_or_email instanceof WP_Comment || ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) ) ) {
		$user_id = (int) $id_or_email->user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		return get_user_by( 'id', $user_id );
	}
	if ( is_numeric( $id_or_email ) ) {
		return get_user_by( 'id', (int) $id_or_email );
	}
	return false;
}

/**
 * ログインユーザーのコメントアバターをアカウント画像へ差し替える
 *
 * ゲストコメント（user_id なし）は Gravatar のままにする。
 *
 * @param array $args        アバター引数。
 * @param mixed $id_or_email アバター取得元。
 * @return array
 */
function fit_pre_get_avatar_data( $args, $id_or_email ) {
	$is_comment = $id_or_email instanceof WP_Comment
		|| ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) );
	if ( $is_comment && (int) $id_or_email->user_id <= 0 ) {
		return $args;
	}

	$user = fit_resolve_user_from_avatar_id( $id_or_email );
	if ( ! $user ) {
		return $args;
	}
	$icon_url = fit_get_author_icon_url( $user->ID );
	if ( ! $icon_url ) {
		return $args;
	}
	$args['url']          = $icon_url;
	$args['found_avatar'] = true;
	return $args;
}
add_filter( 'pre_get_avatar_data', 'fit_pre_get_avatar_data', 20, 2 );

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