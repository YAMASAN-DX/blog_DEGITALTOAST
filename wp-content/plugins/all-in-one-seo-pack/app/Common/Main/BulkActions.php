<?php
namespace AIOSEO\Plugin\Common\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles bulk actions for TruSEO eligible post types.
 *
 * @since 5.0.0
 */
class BulkActions {
	/**
	 * Construct method.
	 *
	 * @since 5.0.0
	 */
	public function __construct() {
		// Delay hook registration until admin_init to ensure translations are loaded.
		add_action( 'admin_init', [ $this, 'registerHooks' ] );
	}

	/**
	 * Register hooks for bulk actions.
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function registerHooks() {
		// Register hooks for all TruSEO-eligible post types.
		$postTypes = aioseo()->helpers->getTruSeoEligiblePostTypes();
		foreach ( $postTypes as $postType ) {
			add_filter( 'bulk_actions-edit-' . $postType, [ $this, 'registerTruSeoResetBulkAction' ] );
			add_filter( 'handle_bulk_actions-edit-' . $postType, [ $this, 'handleTruSeoResetBulkAction' ], 10, 3 );
		}

		// And for TruSEO-eligible taxonomies. A term list's screen ID is also `edit-{name}`, and core
		// runs the same handle_bulk_actions filter from edit-tags.php — but with term IDs.
		// NOTE: Pro only. The term analysis columns live on the Pro `aioseo_terms` table, so in Lite
		// there is nothing to reset.
		if ( aioseo()->pro ) {
			$taxonomies = aioseo()->helpers->getTruSeoEligibleTaxonomies();
			foreach ( $taxonomies as $taxonomy ) {
				add_filter( 'bulk_actions-edit-' . $taxonomy, [ $this, 'registerTruSeoResetBulkAction' ] );
				add_filter( 'handle_bulk_actions-edit-' . $taxonomy, [ $this, 'handleTermTruSeoResetBulkAction' ], 10, 3 );
			}
		}

		// Register admin notice hook.
		add_action( 'admin_notices', [ $this, 'showAdminNotice' ] );

		// Remove query arg from URL after notice is shown.
		add_filter( 'removable_query_args', [ $this, 'addRemovableQueryArgs' ] );
	}

	/**
	 * Register the TruSEO Reset bulk action.
	 *
	 * @since 5.0.0
	 *
	 * @param  array $bulkActions The existing bulk actions.
	 * @return array              The modified bulk actions.
	 */
	public function registerTruSeoResetBulkAction( $bulkActions ) {
		$bulkActions[ AIOSEO_PLUGIN_SHORT_NAME ]['aioseo_truseo_reset'] = __( 'Regenerate TruSEO score', 'all-in-one-seo-pack' );

		return $bulkActions;
	}

	/**
	 * Handle the TruSEO Reset bulk action.
	 *
	 * @since 5.0.0
	 *
	 * @param  string $redirectTo The redirect URL.
	 * @param  string $doAction   The action being performed.
	 * @param  array  $postIds    The post IDs to process.
	 * @return string             The modified redirect URL.
	 */
	public function handleTruSeoResetBulkAction( $redirectTo, $doAction, $postIds ) {
		if ( 'aioseo_truseo_reset' !== $doAction ) {
			return $redirectTo;
		}

		// Only reset posts the current user is allowed to edit; skip the rest.
		$postIds = array_filter( array_map( 'intval', (array) $postIds ), function( $postId ) {
			return current_user_can( 'edit_post', $postId );
		} );

		// Bail before the query runs — an empty list would drop the WHERE clause and reset every row.
		if ( empty( $postIds ) ) {
			return $redirectTo;
		}

		// Reset TruSEO fields for selected posts.
		aioseo()->core->db->update( 'aioseo_posts' )
			->whereIn( 'post_id', $postIds )
			->set( [
				'truseo'              => null,
				'focus_keyword'       => null,
				'additional_keywords' => null,
				'keyphrases'          => null,
				'page_analysis'       => null,
				'seo_score'           => 0
			] )
			->run();

		// Add success notice.
		$redirectTo = add_query_arg( 'aioseo_truseo_reset', count( $postIds ), $redirectTo );

		return $redirectTo;
	}

	/**
	 * Handle the TruSEO Reset bulk action on a term list.
	 *
	 * NOTE: Core passes term IDs here (from `delete_tags`), not post IDs, so this cannot share the
	 * post handler.
	 *
	 * @since 5.0.1
	 *
	 * @param  string $redirectTo The redirect URL.
	 * @param  string $doAction   The action being performed.
	 * @param  array  $termIds    The term IDs to process.
	 * @return string             The modified redirect URL.
	 */
	public function handleTermTruSeoResetBulkAction( $redirectTo, $doAction, $termIds ) {
		if ( 'aioseo_truseo_reset' !== $doAction ) {
			return $redirectTo;
		}

		$termIds = array_filter( array_map( 'intval', (array) $termIds ), function( $termId ) {
			$term = aioseo()->helpers->getTerm( $termId );
			if ( ! is_a( $term, 'WP_Term' ) ) {
				return false;
			}

			$taxonomy = get_taxonomy( $term->taxonomy );

			return $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
		} );

		// Bail before the query runs — an empty list would drop the WHERE clause and reset every row.
		if ( empty( $termIds ) ) {
			return $redirectTo;
		}

		// NOTE: No `keyphrases`/`page_analysis` here — those are post-only stores with no column on
		// the terms table.
		aioseo()->core->db->update( 'aioseo_terms' )
			->whereIn( 'term_id', $termIds )
			->set( [
				'truseo'              => null,
				'focus_keyword'       => null,
				'additional_keywords' => null,
				'seo_score'           => 0
			] )
			->run();

		// Core leaves the location empty for filtered bulk actions on term screens, so without a
		// fallback the notice arg has nothing to attach to and the success message never shows.
		// NOTE: wp_get_referer() returns false when the referer matches the current URI — which is
		// exactly the case here, since the list table posts back to itself.
		if ( empty( $redirectTo ) ) {
			$redirectTo = wp_get_raw_referer();
		}

		if ( empty( $redirectTo ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$redirectTo = remove_query_arg(
				[ '_wp_http_referer', '_wpnonce', 'action', 'action2', 'delete_tags' ],
				esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			);
		}

		if ( empty( $redirectTo ) ) {
			return $redirectTo;
		}

		return add_query_arg( 'aioseo_truseo_reset', count( $termIds ), $redirectTo );
	}

	/**
	 * Display admin notice after bulk action completes.
	 *
	 * @since   5.0.0
	 * @version 5.0.1 Names the object type being reset instead of always saying "post".
	 *
	 * @return void
	 */
	public function showAdminNotice() {
		if ( ! empty( $_REQUEST['aioseo_truseo_reset'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Recommended
			$count  = intval( $_REQUEST['aioseo_truseo_reset'] ); // phpcs:ignore HM.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Recommended
			$screen = aioseo()->helpers->getCurrentScreen();
			$nouns  = ! empty( $screen->taxonomy )
				? aioseo()->helpers->getTaxonomyContentNouns( $screen->taxonomy )
				: aioseo()->helpers->getPostTypeContentNouns( ! empty( $screen->post_type ) ? $screen->post_type : '' );

			printf(
				'<div class="notice-truseo-reset notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						// Translators: 1 - The number of objects reset, 2 - The object noun, e.g. "categories".
						__( 'TruSEO data reset for %1$d %2$s.', 'all-in-one-seo-pack' ),
						$count,
						1 === $count ? $nouns['singular'] : $nouns['plural']
					)
				)
			);
		}
	}

	/**
	 * Add our custom query arg to the removable query args list.
	 *
	 * @since 5.0.0
	 *
	 * @param  array $args The existing removable query args.
	 * @return array       The modified removable query args.
	 */
	public function addRemovableQueryArgs( $args ) {
		$args[] = 'aioseo_truseo_reset';

		return $args;
	}
}