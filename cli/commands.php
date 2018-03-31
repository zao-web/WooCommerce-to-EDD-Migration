<?php
namespace Migrate_Woo\CLI;

/**
 * CLI commands.
 *
 * @todo Update for new EDD APIs
 * @todo Update for new WC APIs
 * @todo Support pagination on API calls for large amounts of data.
 * @todo Reviews not mapping
 * @todo Map subscription
 * @todo support sale price
 * @todo Currently, variation products are not being mapped as part of the product map.
 *
 * @link With thanks to https://github.com/rtCamp/woocommerce-to-easydigitaldownloads
 */
class Commands {

	/**
	 * The CLI logs directory.
	 *
	 * @var string
	 */
	protected static $log_dir = __DIR__;
	protected $cli;
	protected $edd_cat_slug = 'download_category';
	protected $wc_cat_slug  = 'product_cat';
	protected $edd_tag_slug = 'download_tag';
	protected $wc_tag_slug  = 'product_tag';
	protected $wc_edd_cat_map     = array();
	protected $wc_edd_tag_map     = array();
	protected $wc_edd_product_map = array();
	protected $wc_edd_coupon_map = array();
	protected $current_page       = 0;
	protected $per_page           = 400;

	public function __construct( $args = array(), $assoc_args = array() ) {
		$this->cli = new Actions( $args, $assoc_args, self::$log_dir );
	}

	public static function set_log_dir( $log_dir ) {
		self::$log_dir = $log_dir;
	}

	/**
	 * Inserts Attachment with Parent Post as $edd_product_id
	 *
	 * @param $old_attachment_id
	 * @param $edd_product_id
	 *
	 * @return int
	 */
	public function wc_edd_insert_attachment( $old_attachment_id, $edd_product_id ) {
		// $filename should be the path to a file in the upload directory.
		$filename = get_attached_file( $old_attachment_id );

		// The ID of the post this attachment is for.
		$parent_post_id = $edd_product_id;

		// Check the type of tile. We'll use this as the 'post_mime_type'.
		$filetype = wp_check_filetype( basename( $filename ), null );

		// Get the path to the upload directory.
		$wp_upload_dir = wp_upload_dir();

		// Prepare an array of post data for the attachment.
		$attachment = array(
			'guid'           => $wp_upload_dir['url'] . '/' . basename( $filename ),
			'post_mime_type' => $filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
			'post_content'   => '',
			'post_status'    => 'inherit'
		);

		// Insert the attachment.
		$attach_id = wp_insert_attachment( $attachment, $filename, $parent_post_id );
		return $attach_id;
	}

	/**
	 * Step One: Migrate Taxonomies.
	 *
	 * @todo Account for all registered taxonomies, not just category/tags - DRY
	 * @return [type] [description]
	 */
	public function migrate_taxonomies() {

		// Fetch Category from WC
		$wc_cat_terms = get_terms( $this->wc_cat_slug, array( 'hide_empty' => false ) );

		$this->cli->success_message( "WC Cat fetched ..." );

		$progress = $this->cli->progress_bar( count( $wc_cat_terms ) );

		foreach ( $wc_cat_terms as $t ) {
			$args = array();

			// Check for Parent Term; if any
			if ( ! empty( $t->parent ) && isset( $this->wc_edd_cat_map[ $t->parent ] ) ) {
				$args['parent'] = $this->wc_edd_cat_map[ $t->parent ];
				$term_exists = term_exists( $this->edd_cat_slug, $t->name, $this->wc_edd_cat_map[ $t->parent ] );
			} else {
				$term_exists = term_exists( $this->edd_cat_slug, $t->name );
			}

			if ( ! $term_exists ) {
				$edd_term = wp_insert_term( $t->name, $this->edd_cat_slug, $args );
			}

			if ( ! is_wp_error( $edd_term ) ) {
				// maintain array of category mapping
				$this->wc_edd_cat_map[ $t->term_id ] = $edd_term['term_id'];
			} else {
				$this->cli->warning_message( "$t->name -- Category not migrated because : ", $edd_term );
			}

			$progress->tick();
		}

		$progress->finish();

		unset( $progress );

		$this->cli->success_message( 'EDD Categories migrated ..' );

		$this->wc_edd_tag_map = array();

		// Fetch Tag from WC
		$wc_tag_terms = get_terms( $this->wc_tag_slug, array( 'hide_empty' => false ) );
		$this->cli->success_message( "WC Tag fetched ..." );

		$progress = $this->cli->progress_bar( count( $wc_tag_terms ) );

		foreach ( $wc_tag_terms as $t ) {
			$edd_term = wp_insert_term( $t->name, $this->edd_tag_slug );

			if ( ! is_wp_error( $edd_term ) ) {
				// maintain array of tag mapping
				$this->wc_edd_tag_map[ $t->term_id ] = $edd_term[ 'term_id' ];
			} else {
				$this->cli->warning_message( "$t->name -- Tag not migrated because : ", $edd_term );
			}
		}

		$progress->finish();

		unset( $progress );

		$this->cli->success_message( 'EDD Tags migrated ..' );
	}

	/**
	 * Step Two: Migrate Products
	 *
	 * @todo Use filterable meta map to avoid lots of duplication
	 * @todo Update APIs used to migrate, avoid deprecation notices
	 *
	 * @param  [type] $this->cli [description]
	 * @return [type]      [description]
	 */
	public function migrate_products() {

		$this->cli->confirm( 'Do you want to migrate WooCommerce products to EDD products?' );

		$wc_product_cpt  = 'product';
		$edd_product_cpt = 'download';

		// Fetch WC Products
		$args = array(
			'post_type'      => $wc_product_cpt,
		    'posts_per_page' => -1,
		    'post_status'    => 'any',
		);

		$wc_product_list = get_posts( $args );

		$this->cli->success_message( "WC Products fetched ..." );

		$progress = $this->cli->progress_bar( count( $wc_product_list ) );

		foreach( $wc_product_list as $p ) {

			// WC Product Object
			$product    = wc_get_product( $p->ID );
			$product_id = $product->get_id();

			$this->cli->success_message( "Product - $product_id" );

			// Fetch WC Categories
			$wc_cat_terms = wp_get_post_terms( $product_id, $this->wc_cat_slug );

			$this->cli->success_message( "WC Product Category fetched ..." );

			$edd_cat_terms = array();

			if ( ! is_wp_error( $wc_cat_terms ) ) {
				foreach ( $wc_cat_terms as $t ) {
					if ( isset( $this->wc_edd_cat_map[ $t->term_id ] ) ) {
						$edd_cat_terms[] = intval( $this->wc_edd_cat_map[ $t->term_id ] );
					}
				}
			}

			// Fetch WC Tags
			$wc_tag_terms = wp_get_object_terms( $product_id, $this->wc_tag_slug );
			$this->cli->success_message( "WC Product Tag fetched ..." );

			$edd_tag_terms = array();
			if ( ! $wc_tag_terms instanceof WP_Error ) {
				foreach ( $wc_tag_terms as $t ) {
					if ( isset( $this->wc_edd_tag_map[ $t->term_id ] ) ) {
						$edd_tag_terms[] = intval( $this->wc_edd_tag_map[ $t->term_id ] );
					}
				}
			}

			$data = array(
				'post_content'   => $p->post_content,
			    'post_title'     => $p->post_title,
			    'post_status'    => $p->post_status,
			    'post_type'      => $edd_product_cpt,
			    'post_author'    => $p->post_author,
			    'post_parent'    => $p->post_parent,
			    'post_excerpt'   => $p->post_excerpt,
			    'post_date'      => $p->post_date,
			    'post_date_gmt'  => $p->post_date_gmt,
			    'comment_status' => $p->comment_status,
			);

			$edd_product_id = wp_insert_post( $data );

			if ( empty( $edd_product_id ) || is_wp_error( $edd_product_id ) ) {
				$this->cli->warning_message( "Product Not Migrated : ", $p );
				$progress->tick();
				continue;
			}

			$this->wc_edd_product_map[ $product_id ] = $edd_product_id;

			$this->cli->success_message( "WC Product migrated..." );
			$progress->tick();
			update_post_meta( $edd_product_id, '_wc_product_id', $product_id );

			// Assign Category
			$terms = wp_set_object_terms( $edd_product_id, $edd_cat_terms, $this->edd_cat_slug );

			if ( is_wp_error( $terms ) ) {
				$this->cli->warning_message( "Product Categories Failed to Assign : ", $terms );
				$progress->tick();
				continue;
			}

			$this->cli->success_message( "WC Category migrated..." );

			// Assign Tag
			$terms = wp_set_object_terms( $edd_product_id, $edd_tag_terms, $this->edd_tag_slug );

			if ( is_wp_error( $terms ) ) {
				$this->cli->warning_message( "Product Tags Failed to Assign : ", $terms );
				$progress->tick();
				continue;
			}

			$this->cli->success_message( "WC Tag migrated..." );

			// Featured Image
			$wc_product_featured_image = get_post_thumbnail_id( $product_id );

			if ( ! empty( $wc_product_featured_image ) ) {

				// Set featured image
				$edd_product_fi_meta_id = set_post_thumbnail( $edd_product_id, $wc_product_featured_image );

				if ( empty( $edd_product_fi_meta_id ) ) {
					$this->cli->warning_message( "Feature Image could not be set for Product ... : ", $p );
					$progress->tick();
					continue;
				}
			}

			$this->cli->success_message( "WC Featured Image migrated..." );

			// Product Gallery
			$attachment_ids = $product->get_gallery_attachment_ids();

			if ( $attachment_ids ) {

				foreach ( $attachment_ids as $attachment_id ) {

					// insert new attachment for new product
					$attach_id = $this->wc_edd_insert_attachment( $attachment_id, $edd_product_id );

					if ( empty( $attach_id ) ) {
						$this->cli->warning_message( "Gallery Image ID $attachment_id could not be set for Product ... : ", $p );
						$progress->tick();
						continue;
					}
				}

				$this->cli->success_message( "WC Gallery migrated..." );
			}

			$type = $product->get_type();

			if ( 'variable' === $type || 'variable-subscription' === $type ) {

				$this->cli->success_message( "Migrating a variable product..." );

				$args = array(
					'post_type'		=> 'product_variation',
					'post_status' 	=> array( 'private', 'publish' ),
					'numberposts' 	=> -1,
					'orderby' 		=> 'menu_order',
					'order' 		=> 'asc',
					'post_parent' 	=> $product_id,
				);

				$wc_variations = get_posts( $args );

				update_post_meta( $edd_product_id, '_variable_pricing', 1 );

				$edd_variations = array();

				if ( $wc_variations ) {

					foreach ( $wc_variations as $variation ) {
						$this->cli->success_message( "Variation - $variation->ID..." );

						// Downloadable Files
						// TODO: Map Variation to Price Assignment
						$wc_dl_files       = maybe_unserialize( get_post_meta( $variation->ID, '_downloadable_files', true ) );
						$edd_dl_files      = array();
						$edd_dl_files_slug = 'edd_download_files';

						foreach ( $wc_dl_files as $wc_file ) {

							$this->cli->success_message( "Old File : ".$wc_file[ 'file' ] );

							// Prepare array entry for downloaded file
							$edd_dl_files[] = array(
								'attachment_id' => '',
								'name'          => $wc_file['name'],
								'file'          => $wc_file['file'],
							);
						}

						// Store downloadable files into meta table
						if ( ! empty( $edd_dl_files ) ) {
							update_post_meta( $edd_product_id, $edd_dl_files_slug, $edd_dl_files );
							$this->cli->success_message( "WC Downloadable Files migrated" );
						}

						// Download Limit
						// Take old value from WC meta and save it into EDD meta.
						$edd_dl_limit_slug = '_edd_download_limit';
						$wc_dl_limit_slug  = '_download_limit';

						update_post_meta( $edd_product_id, $edd_dl_limit_slug, get_post_meta( $variation->ID, $wc_dl_limit_slug, true ) );

						$this->cli->success_message( "WC Download Limit : " . get_post_meta( $variation->ID, $wc_dl_limit_slug, true ) . " migrated ..." );

						// Download Expiry
						$wc_dl_expiry_slug         = "_download_expiry";
						$edd_dl_expiry_unit_slug   = "_edd_sl_exp_unit";
						$edd_dl_expiry_length_slug = "_edd_sl_exp_length";

						update_post_meta( $edd_product_id, $edd_dl_expiry_length_slug, get_post_meta( $variation->ID, $wc_dl_expiry_slug, true ) );
						update_post_meta( $edd_product_id, $edd_dl_expiry_unit_slug, 'days' );

						$this->cli->success_message( "WC Download Expiry : " . get_post_meta( $variation->ID, $wc_dl_expiry_slug, true ) . " migrated ..." );

						$attributes     = maybe_unserialize( get_post_meta( $product_id, '_product_attributes', true ) );
						$variation_data = get_post_meta( $variation->ID );
						$index          = 1;

						foreach ( $attributes as $attr ) {

							// Only deal with attributes that are variations
							if ( ! $attr[ 'is_variation' ] ) {
								continue;
							}

							$variation_selected_value = isset( $variation_data[ 'attribute_' . sanitize_title( $attr['name'] ) ][0] ) ? $variation_data[ 'attribute_' . sanitize_title( $attr['name'] ) ][0] : '';

							$this->cli->success_message( "Variation Value : $variation_selected_value ..." );
							$this->cli->success_message( "Variation Price : ".get_post_meta( $variation->ID, '_regular_price', true ) . " ..." );
							$this->cli->success_message( "Variation Activation : ".get_post_meta( $variation->ID, '_api_activations', true )." ..." );

							$variations = array(
								'index' => $index++,
							    'name' => $variation_selected_value,
							    'amount' => get_post_meta( $variation->ID, '_regular_price', true ),
							    'license_limit' => get_post_meta( $variation->ID, '_api_activations', true ),
							);

							if ( 'variable-subscription' === $type ) {
								$variations['recurring']      = 'yes';
								$variations['trial-quantity'] = get_post_meta( $variation->ID, '_subscription_trial_length', true );
								$variations['trial-unit']     = get_post_meta( $variation->ID, '_subscription_trial_period', true );
								$variations['period']         = get_post_meta( $variation->ID, '_subscription_period', true );
								$variations['times']          = get_post_meta( $variation->ID, '_subscription_period_interval', true );
								$variations['signup_fee']     = get_post_meta( $variation->ID, '_subscription_sign_up_fee', true );
								$variations['amount']         = get_post_meta( $variation->ID, '_subscription_price', true );
							}

							$edd_variations[] = $variations;
						}
					}
				}

				// Software Version
				$wc_api_version_slug = '_api_new_version';
				$edd_sl_version_slug = '_edd_sl_version';

				update_post_meta( $edd_product_id, $edd_sl_version_slug, get_post_meta( $variation->ID, $wc_api_version_slug, true ) );

				$this->cli->success_message( "WC Product Version : " . get_post_meta( $variation->ID, $wc_api_version_slug, true ) . " migrated ..." );

				// Store Variations in EDD
				$edd_variations_slug = 'edd_variable_prices';
				if ( ! empty( $edd_variations ) ) {
					update_post_meta( $edd_product_id, $edd_variations_slug, $edd_variations );
					$this->cli->success_message( "WC Variations migrated ..." );

				}
			} else {
				// Downloadable Files

				$wc_dl_files       = $product->get_files();
				$edd_dl_files      = array();
				$edd_dl_files_slug = 'edd_download_files';

				foreach( $wc_dl_files as $wc_file ) {

					$this->cli->success_message( "Old File : " . $wc_file[ 'file' ] );

					// Prepare aray entry for downloaded file
					$edd_dl_files[] = array(
						'attachment_id' => '',
						'name' => $wc_file['name'],
						'file' => $wc_file[ 'file' ],
					);
				}

				// Store downloadable files into meta table
				if ( ! empty( $edd_dl_files ) ) {
					update_post_meta( $edd_product_id, $edd_dl_files_slug, $edd_dl_files );
					$this->cli->success_message( "WC Downloadable Files migrated ..." );
				}

				// Download Limit
				// Take old value from WC meta and save it into EDD meta.
				$edd_dl_limit_slug = '_edd_download_limit';
				$wc_dl_limit_slug  = '_download_limit';

				update_post_meta( $edd_product_id, $edd_dl_limit_slug, get_post_meta( $product_id, $wc_dl_limit_slug, true ) );

				$this->cli->success_message( "WC Download Limit : " . get_post_meta( $product_id, $wc_dl_limit_slug, true ) . " migrated ..." );

				// Price
				// Take old value from WC meta and save it into EDD meta.
				$edd_product_price_slug = 'edd_price';
				$wc_product_price_slug  = '_regular_price';

				update_post_meta( $edd_product_id, $edd_product_price_slug, get_post_meta( $product_id, $wc_product_price_slug, true ) );

				$this->cli->success_message( "WC Product Price : " . get_post_meta( $product_id, $wc_product_price_slug, true ) . " migrated ..." );

				// Activation Limit
				$wc_activation_limit_slug  = '_api_activations_parent';
				$edd_activation_limit_slug = '_edd_sl_limit';

				update_post_meta( $edd_product_id, $edd_activation_limit_slug, get_post_meta( $product_id, $wc_activation_limit_slug, true ) );
				$this->cli->success_message( "WC Activation Limit : " . get_post_meta( $product_id, $wc_activation_limit_slug, true ) . " migrated ..." );

				// Download Expiry
				$wc_dl_expiry_slug         = "_download_expiry";
				$edd_dl_expiry_unit_slug   = "_edd_sl_exp_unit";
				$edd_dl_expiry_length_slug = "_edd_sl_exp_length";

				update_post_meta( $edd_product_id, $edd_dl_expiry_length_slug, get_post_meta( $product_id, $wc_dl_expiry_slug, true ) );
				update_post_meta( $edd_product_id, $edd_dl_expiry_unit_slug, 'days' );

				$this->cli->success_message( "WC Download Expiry : " . get_post_meta( $product_id, $wc_dl_expiry_slug, true ) . " migrated ..." );
			}

			// Sales
			// Take old value from WC meta and save it into EDD meta.
			$edd_product_sales_slug = '_edd_download_sales';
			$wc_product_sales_slug  = 'total_sales';

			update_post_meta( $edd_product_id, $edd_product_sales_slug, get_post_meta( $product_id, $wc_product_sales_slug, true ) );
			$this->cli->success_message( "WC Product Total Sales : " . get_post_meta( $product_id, $wc_product_sales_slug, true ) . " migrated ..." );

			// API Enabled - Licensing Enabled
			$wc_api_slug = '_is_api';
			$edd_sl_slug = '_edd_sl_enabled';

			update_post_meta( $edd_product_id, $edd_sl_slug, get_post_meta( $product_id, $wc_api_slug, true ) );

			$this->cli->success_message( "WC Product API Enabled : " . get_post_meta( $product_id, $wc_api_slug, true ) . " migrated ..." );

			// Software Version
			$wc_api_version_slug = '_api_new_version';
			$edd_sl_version_slug = '_edd_sl_version';

			update_post_meta( $edd_product_id, $edd_sl_version_slug, get_post_meta( $product_id, $wc_api_version_slug, true ) );

			$this->cli->success_message( "WC Product Version : " . get_post_meta( $product_id, $wc_api_version_slug, true ) . " migrated ..." );

			// Old Plugin Update Info
			update_post_meta( $edd_product_id, '_product_update_slug', get_post_meta( $product_id, '_product_update_slug', true ) );
			update_post_meta( $edd_product_id, '_product_update_version', get_post_meta( $product_id, '_product_update_version', true ) );

			// Product Demo URL
			update_post_meta( $edd_product_id, '_product_live_demo_url', get_post_meta( $product_id, '_product_live_demo_url', true ) );

			// Reviews
			$args = array(
				'post_id' => $product_id,
				'approve' => 'approve',
			);

			$wc_reviews = get_comments( $args );
			$this->cli->success_message( "Product Reviews fetched ..." );

			foreach ( $wc_reviews as $comment ) {

				$this->cli->success_message( "WC Review - $comment->comment_ID" );

				$comment_data = array(
					'comment_post_ID'      => $this->wc_edd_product_map[ $product_id ],
					'comment_author'       => $comment->comment_author,
					'comment_author_email' => $comment->comment_author_email,
					'comment_content'      => $comment->comment_content,
					'comment_approved'     => 1,
				);

				$edd_review_id = wp_insert_comment( $comment_data );

				// Update relevant data from old comment
				wp_update_comment( array(
					'comment_ID'       => $edd_review_id,
					'comment_date'     => $comment->comment_date,
					'comment_date_gmt' => $comment->comment_date_gmt,
				) );

				update_comment_meta( $edd_review_id, '_wc_review_id', $comment->comment_ID );

				// Migrate Rating
				update_comment_meta( $edd_review_id, '_wc_rating', get_comment_meta( $comment->comment_ID, 'rating', true ) );

				$this->cli->success_message( "WC Review migrated ..." );
			}

			// Earnings
			// TODO - Do it when migrating orders i.e. Payment History
			$progress->tick();

		}

		$progress->finish();
	}

	public function migrate_coupons() {
		$this->cli->confirm( 'Do you want to migrate WooCommerce coupons to EDD discounts?' );
		$this->cli::stop_the_insanity( 3 );

		$wc_coupon_cpt  = 'shop_coupon';
		$edd_coupon_cpt = 'edd_discount';

		// Fetch WC Coupons
		$args = array(
			'post_type'      => $wc_coupon_cpt,
			'posts_per_page' => -1,
			'post_status'    => 'any',
		);

		$wc_coupon_list = get_posts( $args );

		$this->cli->success_message( "WC Coupons fetched ..." );
		$progress = $this->cli->progress_bar( count( $wc_coupon_list ) );

		foreach( $wc_coupon_list as $c ) {

			// WC Coupon Object
			$code   = $c->post_title;
			$status = ( $c->post_status == 'publish' ) ? 'active' : 'inactive';
			$coupon = new \WC_Coupon( $code );

			$this->cli->success_message( "Coupon - $c->ID" );

			$data = array(
				'post_content' => $c->post_content,
				'post_title'   => $c->post_title,
				'post_status'  => $status,
				'post_type'    => $edd_coupon_cpt,
				'post_author'  => $c->post_author,
				'post_parent'  => $c->post_parent,
				'post_excerpt' => $c->post_excerpt,
				'post_date'    => $c->post_date,
				'post_date_gmt' => $c->post_date_gmt,
				'comment_status' => $c->comment_status,
			);

			$edd_coupon_id = wp_insert_post( $data );

			// Adjust according to EDD Format
			$expiry_date = get_post_meta( $c->ID, 'expiry_date', true );
			$expiry_date = new \DateTime( $expiry_date );

			$expiry_date->add( new \DateInterval( 'PT23H59M59S' ) );

			$discount_type = get_post_meta( $c->ID, 'discount_type', true );

			$data = array(
				// TODO - Update uses when migrating Orders
				// 'uses' => {number},
				'name'   => $c->post_excerpt,
				'status' => $status,
				'code'   => $code,
				'max'    => get_post_meta( $c->ID, 'usage_limit', true ),
				'amount' => get_post_meta( $c->ID, 'coupon_amount', true ),
				'expiration' => $expiry_date->format('m/d/Y H:i:s'),
				'type' => ( strstr( $discount_type, 'percent' ) == false ) ? 'flat' : 'percent',
				'min_price' => get_post_meta( $c->ID, 'minimum_amount', true ),
				'products' => array_map( 'intval', explode( ',', get_post_meta( $c->ID, 'product_ids', true ) ) ),
				'product_condition' => 'any',
				'excluded-products' => array_map( 'intval', explode( ',', get_post_meta( $c->ID, 'exclude_product_ids', true ) ) ),
				'not_global' => true,
				'use_once'  => false,
			);

			edd_store_discount( $data, $edd_coupon_id );

			$this->wc_edd_coupon_map[ $c->ID ] = $edd_coupon_id;
			$this->cli->success_message( "WC Coupon migrated ..." );

			update_post_meta( $edd_coupon_id, '_wc_coupon_id', $c->ID );
			$progress->tick();
		}

		$progress->finish();
	}

	public function migrate_orders() {

		$this->cli->confirm( 'Do you want to migrate WooCommerce orders to EDD orders?' );

		$this->cli::stop_the_insanity( 3 );

		$wc_order_cpt  = 'shop_order';
		$edd_order_cpt = 'edd_payment';

		// Fetch WC Orders
		$args = array(
			'post_type'      => $wc_order_cpt,
			'posts_per_page' => $this->per_page,
			'post_status'    => 'any',
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		if ( $this->current_page > 0 ) {
			$args['offset'] = $this->current_page * $this->per_page;
		}

		$wc_order_list = get_posts( $args );

		if ( empty( $wc_order_list ) ) {
			$this->cli->warning_message( "No orders to migrate" );
		}

		$this->cli->success_message( "WC Orders fetched ..." );
		$progress = $this->cli->progress_bar( count( $wc_order_list ) );

		$wc_edd_order_map = array();

		foreach( $wc_order_list as $o ) {

			// WC Order Object
			$order = new \WC_Order( $o );
			$order_id = $order->get_id();

			$this->cli->success_message( "Order - $order_id" );

			// Process Order Status
			switch ( $order->get_status() ) {
				case 'pending':
				case 'processing':
				case 'on-hold':
					$status = 'pending';
					break;
				case 'completed':
					$status = 'publish';
					break;
				case 'cancelled':
					$status = 'abandoned';
					break;
				case 'refunded':
					$status = 'refunded';
					break;
				case 'failed':
					$status = 'failed';
					break;
				default:
					$status = 'pending';
					break;
			}

			$this->cli->success_message( "Status : $status" );
			$break_loop = false;

			// Decide the customer from email used. If new then create new.
			$email = get_post_meta( $order_id, '_billing_email', true );

			if ( empty( $email ) ) {
				$email = get_user_by( 'id', $order->get_customer_id() )->user_email;
			}

			$user  = get_user_by( 'email', $email );

			if ( ! $user ) {
				$first_name = get_post_meta( $order_id, '_billing_first_name', true );
				$last_name  = get_post_meta( $order_id, '_billing_last_name', true );
				$password   = wp_generate_password();
				$user_id    = wp_insert_user(
					array(
						'user_email' 	=> sanitize_email( $email ),
						'user_login' 	=> sanitize_email( $email ),
						'user_pass'		=> $password,
						'first_name'	=> sanitize_text_field( $first_name ),
						'last_name' 	=> sanitize_text_field( $last_name ),
					)
				);

				if ( is_wp_error( $user ) ) {
					$this->cli->confirm( "Error inserting user : " . $user->get_error_message() . ' Continue?' );
					$user_id  =$user->get_error_message();
				}
			} else {
				$user_id = $user->ID;
				$email   = $user->user_email;
			}

			if ( is_wp_error( $user_id ) ) {
				$this->cli->warning_message( "User could not be created. Invalid Email. So order could not be migrated : ", $user_id );
				$progress->tick();
				continue;
			}

			$this->cli->success_message( "USER ID : $user_id" );

			// Prepare Products array & cart array for the order.
			$downloads    = array();
			$cart_details = array();
			$wc_items     = $order->get_items();

			// Decide whether any coupon is used for discount or not.
			$wc_coupon = $order->get_used_coupons();

			if ( ! empty( $wc_coupon ) ) {
				$wc_coupon = new \WC_Coupon( $wc_coupon[0] );
			} else {
				$wc_coupon = null;
			}

			// Line Items from the WC Order
			foreach ( $wc_items as $item ) {
				$product    = $order->get_product_from_item( $item );
				$parent_id  = $product->get_parent_id();
				$product_id = $parent_id ? $parent_id : $product->get_id();

				//TODO Better support for variation mapping.

				$item['quantity'] = $item['qty'];
				$item['data']     = $product;

				if ( ! isset( $this->wc_edd_product_map[ $product_id ] ) || empty( $this->wc_edd_product_map[ $product_id ] ) ) {
					$this->cli->warning_message( "EDD Product Not available for this WC Product : ", compact( 'item', 'product', 'product_idUser could not be created' ) );
					$progress->tick();
					$break_loop = true;
					break;
				}

				$download    = edd_get_download( $this->wc_edd_product_map[ $product_id ] );

				$item_number = array(
					'id'       => $download->ID,
					'options'  => array(),
					'quantity' => $item[ 'qty' ],
				);

				$downloads[] = $item_number;

				$_wc_cart_disc_meta = get_post_meta( $order_id, '_cart_discount', true );
				$_wc_cart_disc_meta = floatval( $_wc_cart_disc_meta );

				$_wc_order_disc_meta = get_post_meta( $order_id, '_order_discount', true );
				$_wc_order_disc_meta = floatval( $_wc_order_disc_meta );

				// Cart Discount Logic for migration - Two Types : 1. Cart Discount 2. Product Discount
				if ( ! empty( $_wc_cart_disc_meta ) ) {
					$item_price = $item[ 'line_subtotal' ];
					$discount = ( floatval( $item[ 'line_subtotal' ] ) - floatval( $item[ 'line_total' ] ) ) * $item[ 'qty' ];
					$subtotal = ( $item[ 'line_subtotal' ] * $item[ 'qty' ] ) - $discount;
					$price = $subtotal;  // $item[ 'line_total' ]
				} else {
					$item_price = $item[ 'line_subtotal' ];
					$discount = ( ! empty( $wc_coupon ) ) ? $wc_coupon->get_discount_amount( $item_price, $item ) : 0;
					$subtotal = ( $item[ 'line_subtotal' ] * $item[ 'qty' ] ) - $discount;
					$price = $subtotal;  // $item[ 'line_total' ]
				}

				$cart_details[] = array(
					'id'          => $download->ID,
					'name'        => $download->post_title,
					'item_number' => $item_number,
					'item_price'  => $item_price,
					'subtotal'    => $subtotal,
					'price'       => $price,
					'discount'    => $discount,
					'fees'        => array(),
					'tax'         => 0,
					'quantity'    => $item['qty'],
				);
			}

			// If Products & Cart array is not prepared ( loop broken in between ) then skip the order.
			if ( $break_loop ) {
				$this->cli->warning_message( "WC Order could not be migrated" );
				$progress->tick();
				continue;
			}

			// If no products found in the order then also skip the order.
			if ( empty( $downloads ) || empty( $cart_details ) ) {
				$this->cli->warning_message( "No products found, so order not migrated" );
				$progress->tick();
				continue;
			}

			$data = array(
				'currency'     => 'USD', //TODO: Support non-USD currencies
				'downloads'    => $downloads,
				'cart_details' => $cart_details,
				'price'        => get_post_meta( $order_id, '_order_total', true ),
				'purchase_key' => get_post_meta( $order_id, '_order_key', true ),
				'user_info'    => array(
					'id'         => $user_id,
					'email'      => $email,
					'first_name' => get_post_meta( $order_id, '_billing_first_name', true ),
					'last_name'  => get_post_meta( $order_id, '_billing_last_name', true ),
					'discount'   => ( ! empty( $wc_coupon ) && isset( $this->wc_edd_coupon_map[ $wc_coupon->get_id() ] ) && ! empty( $this->wc_edd_coupon_map[ $wc_coupon->get_id() ] ) ) ? $wc_coupon->get_code() : '',
					'address'    => array(
						'line1'   => get_post_meta( $order_id, '_billing_address_1', true ),
						'line2'   => get_post_meta( $order_id, '_billing_address_2', true ),
						'city'    => get_post_meta( $order_id, '_billing_city', true ),
						'zip'     => get_post_meta( $order_id, '_billing_postcode', true ),
						'country' => get_post_meta( $order_id, '_billing_country', true ),
						'state'   => get_post_meta( $order_id, '_billing_state', true ),
					),
				),
				'user_id'    => $user_id,
				'user_email' => $email,
				'status'     => 'pending',
				'parent'     => $o->post_parent,
				'post_date'  => $o->post_date,
				'gateway'    => get_post_meta( $order_id, '_payment_method', true ),
			);

			$payment_id = edd_insert_payment( $data );

			remove_action( 'edd_update_payment_status', 'edd_trigger_purchase_receipt', 10 );
			remove_action( 'edd_complete_purchase'    , 'edd_trigger_purchase_receipt', 999 );

			edd_update_payment_status( $payment_id, $status );

			$wc_edd_order_map[ $order_id ] = $payment_id;

			$this->cli->success_message( "WC Order migrated" );

			// Update relevent data.
			update_post_meta( $payment_id, '_edd_payment_user_ip', get_post_meta( $order_id, '_customer_ip_address', true ) );
			update_post_meta( $payment_id, '_wc_order_key', get_post_meta( $order_id, '_order_key', true ) );
			update_post_meta( $payment_id, '_edd_payment_mode', 'live' );
			update_post_meta( $payment_id, '_edd_completed_date', get_post_meta( $order_id, '_completed_date', true ) );

			update_post_meta( $payment_id, '_wc_order_id', $order_id );

			// Order Notes
			$args = array(
				'post_id' => $order_id,
				'approve' => 'approve',
			);

			$wc_notes = get_comments( $args );

			$this->cli->success_message( "Order Notes fetched" );

			foreach ( $wc_notes as $note ) {

				$this->cli->success_message( "WC Order Note - $note->comment_ID" );

				$edd_note_id = edd_insert_payment_note( $payment_id, $note->comment_content );

				// Update relevant data from old comment
				wp_update_comment( array(
					'comment_ID'           => $edd_note_id,
					'comment_date'         => $note->comment_date,
					'comment_date_gmt'     => $note->comment_date_gmt,
					'comment_author'       => $note->comment_author,
					'comment_author_email' => $note->comment_author_email,
				) );

				update_comment_meta( $edd_note_id, '_wc_order_note_id', $note->comment_ID );

				$this->cli->success_message( "WC Order Note migrated" );
			}

			$progress->tick();

			$this->maybe_migrate_licenses( $order, $payment_id );

		}

		$progress->finish();
		$this->current_page++;
		$this->migrate_orders();
	}

	public function maybe_migrate_subscriptions() {

	}

	public function maybe_migrate_stripe_subscriptions() {

	}

	public function maybe_migrate_licenses( $wc_order, $edd_payment_id ) {

		if ( ! class_exists( 'WC_AM_Helpers' ) ) {
			return $this->cli->warning_message( 'WooCommerce API Key Manager not active' );
		}

		if ( ! class_exists( 'EDD_Software_Licensing' ) ) {
			return $this->cli->warning_message( 'EDD Software Licensing not active' );
		}

		if ( ! WC_AM_Helpers()->has_api_product( $wc_order ) ) {
			return $this->cli->warning_message( 'This order has no API product.' );
		}

		$user_id       = $wc_order->get_customer_id();
		$order_id      = $wc_order->get_id();
		$customer_keys = WC_AM_Helpers()->get_users_data( $wc_order->get_customer_id() );
		$cart_details  = edd_get_payment( $edd_payment_id )->cart_details;

		$this->cli->success_message( "WC SL fetched" );

		$wc_edd_sl_map = array();

		foreach ( $wc_sl_list as $api_key => $data ) {

			if ( $order_id !== $data['order_id'] ) {
				continue;
			}

			// Add api_key to metadata for pre-existing keys
			add_filter( 'edd_sl_insert_license_args', function( $args ) use ( $api_key ) {
				$args['meta_input'] = array(
					'_edd_sl_keys' => $api_key
				);

				return $args;
			} );

			$progress = $this->cli->progress_bar( count( $cart_details ) );

			foreach ( $cart_details as $index => $item ) {

				$license = edd_software_licensing()->generate_license( $item['id'], $edd_payment_id, 'default', $item, $index );

				if ( ! empty( $license ) ) {
					$progress->tick();
				}
			}

			$progress->finish();
		}

		$this->cli->success_message( "WC SL imported" );
	}

	public function maybe_migrate_remaining_users() {

	}

	public function migrate( $args, $assoc_args ) {

		if ( is_null( $this->cli ) ) {
			$this->cli = new Actions( $args, $assoc_args, self::$log_dir );
		}
		$this->cli->disable_emails();

		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) || ! is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ) ) {
			$this->cli->error_message( "WC & EDD Not Activated." );
		}

		$this->cli->success_message( "WC & EDD activated ..." );

		/**
		 * Step 1
		 * Category & Tag Migrate
		 */
		 $this->migrate_taxonomies();

		/**
		 * Step 2
		 * Product Migrate
		 */
		 $this->migrate_products();

		/**
		 * Step 3
		 * Coupons Migrate
		 */
		$this->migrate_coupons();

		/**
		 * Step 4
		 * Orders Migrate (includes Software Licensing)
		 */
		$this->migrate_orders();

		/**
		 * Step 5
		 * Migrate WooCommerce Subscriptions to EDD Recurring Payments
		 */
		$this->maybe_migrate_subscriptions();

		/**
		 * Step 6
		 * Check for Stripe Gateways, and confirm migration
		 */
		$this->maybe_migrate_stripe_subscriptions();

		/**
		 * Step 8
		 * Migrate Remaining Users (Customers are migrated as part of the Orders process)
		 */
		$this->maybe_migrate_remaining_users();

		$this->cli->success_message( 'Migration complete! Please note: This CLI tool currently migrates most data via older APIs - we rely on EDD to migrate the newly migrated data to their new structures. Go to your WordPress Dashboard and run the upgrade routines now.' );

	}

}
