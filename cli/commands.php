<?php
namespace Migrate_Woo\CLI;

/**
 * CLI commands.
 *
 * @todo Update for new EDD APIs
 * @todo Update for new WC APIs
 * @todo Reviews not mapping
 * @todo License key expiration and lifetimes not mapping
 * @todo Map subscriptions (renewals are set as their own sub)
 * @todo ensure that paypal standard subscription meta is set as EDD Subscriber profile ID.
 * @todo EDD Subs - I'm seeing today's date as the date created and a year from today as the "Expiration Date" and "Renewal Date"
 * @todo support sale price
 *
 * @link With thanks to https://github.com/rtCamp/woocommerce-to-easydigitaldownloads
 */
class Commands {

	protected $cli;
	protected $edd_cat_slug       = 'download_category';
	protected $wc_cat_slug        = 'product_cat';
	protected $edd_tag_slug       = 'download_tag';
	protected $wc_tag_slug        = 'product_tag';
	protected $wc_edd_cat_map     = array();
	protected $wc_edd_tag_map     = array();
	protected $wc_edd_product_map = array();
	protected $wc_edd_coupon_map  = array();
	protected $current_page       = 0;
	protected $per_page           = 400;
	protected $test_mode          = false;
	protected $total              = 0;
	protected $test_ids           = array( 48606, 48596, 46129, 43230, 42430, 42427, 42395, 25970 );

	/**
	 * The CLI logs directory.
	 *
	 * @var string
	 */
	protected static $log_dir = __DIR__;

	public function __construct( $args = array(), $assoc_args = array() ) {
		$this->cli = new Actions( $args, $assoc_args, self::$log_dir );
		$this->set_test_mode();
		add_filter( 'woocommerce_subscriptions_is_duplicate_site', '__return_true' );
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

	public function map_variation_to_price_id( $item, $download ) {
		$variable_prices = edd_get_variable_prices( $download->ID );

		if ( empty( $variable_prices ) ) {
			return array();
		}

		$item_subtotal = floatval( $item->get_subtotal() );

		$price_id = 0;

		foreach ( $variable_prices as $_price_id => $price ) {
			$price = floatval( $price['amount'] ) + floatval( $price['signup_fee'] );

			if ( $price === $item_subtotal ) {
				$price_id = $_price_id;
				break;
			}
		}

		return array( 'quantity' => $item['qty'], 'price_id' => $price_id );
	}

	public function is_renewal_payment( $order ) {
		return absint( $order->get_meta( '_subscription_renewal' ) );
	}

	public function set_test_mode() {
		if ( $this->test_mode ) {
			add_filter( 'edd_is_test_mode', '__return_true' );
		}
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
				update_post_meta( $edd_product_id, $edd_dl_expiry_unit_slug, 'years' );

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
				'uses'   => $coupon->get_usage_count(),
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
			$this->reset_pagination_args();
			return $this->cli->warning_message( "No orders to migrate" );
		}

		$this->cli->success_message( "WC Orders fetched ..." );
		$progress = $this->cli->progress_bar( count( $wc_order_list ) );

		foreach ( $wc_order_list as $o ) {

			// WC Order Object
			$order = new \WC_Order( $o );
			$order_id = $order->get_id();

			$already_migrated = $order->get_meta( 'edd_id' );

			if ( $already_migrated ) {
				$this->cli->warning_message( "Order $order_id has already been migrated. See EDD Payment ID# $already_migrated" );
				continue;
			}

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
					$status = 'cancelled';
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

			//TODO: Investigate whether or not EDD supports a "pending" status on renewals, since renewals are themselves a status.

			$parent     = $o->post_parent;
			$is_renewal = $this->is_renewal_payment( $order );

			if ( $is_renewal ) {
				$status = 'edd_subscription';
				if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
					$subs   = wcs_get_subscriptions_for_renewal_order( $order_id );
					$sub    = array_pop( $subs );
					$parent = $sub->get_parent_id();
				}
				$this->cli->success_message( "Order #$order_id is a renewal order" );
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
					'options'  => $this->map_variation_to_price_id( $item, $download ),
					'quantity' => $item['qty'],
				);

				if ( $is_renewal ) {
					$item_number['options']['is_renewal'] = '1';
				}

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
				'parent'     => $parent,
				'post_date'  => $o->post_date,
				'gateway'    => get_post_meta( $order_id, '_payment_method', true ),
			);

			$payment_id = edd_insert_payment( $data );

			remove_action( 'edd_update_payment_status', 'edd_trigger_purchase_receipt', 10 );
			remove_action( 'edd_complete_purchase'    , 'edd_trigger_purchase_receipt', 999 );

			edd_set_payment_transaction_id( $payment_id, $order->get_transaction_id() );
			edd_update_payment_status( $payment_id, $status );

			$this->cli->success_message( "WC Order migrated" );

			// Update relevent data.
			update_post_meta( $payment_id, '_edd_payment_user_ip', get_post_meta( $order_id, '_customer_ip_address', true ) );
			update_post_meta( $payment_id, '_wc_order_key', get_post_meta( $order_id, '_order_key', true ) );
			update_post_meta( $payment_id, '_edd_payment_mode', 'live' );
			update_post_meta( $payment_id, '_edd_completed_date', get_post_meta( $order_id, '_completed_date', true ) );
			update_post_meta( $payment_id, '_wc_order_id', $order_id );

			$this->maybe_migrate_subscription_meta( $payment_id, $order );

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

			$this->maybe_migrate_licenses( $order, $payment_id );

			$order->add_meta_data( 'edd_id', $payment_id );
			$order->save_meta_data();
			$progress->tick();

		}

		$progress->finish();
		$this->current_page++;
		$this->migrate_orders();
	}

	public function reset_pagination_args() {
		$this->current_page = 0;
		$this->per_page     = 400;
	}

	public function maybe_migrate_subscription_meta( $edd_payment_id, $wc_order ) {
		$payment_method = $wc_order->get_payment_method();

		$meta_map = [];

		if ( 'stripe' === $payment_method ) {
			$meta_map['_stripe_customer_id'] = '_edd_stripe_customer_id';
			$meta_map['_stripe_card_id']     = '_edd_stripe_card_id';   // Not used in EDD, but saving for posterity.
			$meta_map['_stripe_source_id']   = '_edd_stripe_source_id'; // Not used in EDD, but saving for posterity.
		}

		if ( 'paypal' === $payment_method ) {
			$meta_map['Payer PayPal address']    = '_edd_paypal_payer'; // TODO: EDD sets the customer_email to this if it exists.
			$meta_map['_paypal_subscription_id'] = '_edd_subscription_id'; //TODO Determine the correct subscription ID meta key here.
		}

		foreach ( $meta_map as $wc_key => $edd_key ) {
			$value = $wc_order->get_meta( $wc_key );

			if ( ! empty( $value ) ) {
				update_post_meta( $edd_payment_id, $edd_key, $value );
				$this->cli->success_message( "Migrated $wc_key of $value to $edd_key" );
			}
		}
	}

	/**
	 * Copied from EDD Recurring Payments, but removed coupling to admin redirects.
	 *
	 * @return [type] [description]
	 */
	public function maybe_migrate_subscriptions() {

		if ( ! class_exists( 'EDD_Subscriptions_DB' ) ) {
			$this->cli->warning_message( "EDD_Subscriptions_DB not available" );
			return;
		}

		$test_mode  = edd_is_test_mode() ? 'Test' : 'Live';

		$mid_process = $this->current_page > 0;

		if ( ! $mid_process ) {
			$this->cli->confirm( "Shop is currently in $test_mode mode. This means any Stripe API calls will be made to the $test_mode environment. Do you want to proceed?" );
		}

		global $wpdb;

		$subs = new \EDD_Subscriptions_DB;

		$table_exists = get_option( $subs->table_name . '_db_version' );

		if ( ! $table_exists ) {
			@$subs->create_table();
		}

		// Check if we have any payments before moving on
		$has_payments = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'edd_payment' LIMIT 1" );

		if ( empty( $has_payments ) ) {
			$this->cli->error_message( "No subscription payments found to migrate." );
		}

		$total = $this->total;

		if ( ! $total ) {
			$total_sql   = "SELECT COUNT(ID) as total_payments FROM $wpdb->posts WHERE post_type = 'edd_payment' AND post_status IN ('publish','revoked','cancelled');";
			$results     = $wpdb->get_row( $total_sql, 0 );

			$this->total = $results->total_payments;
		}

		$progress = $this->cli->progress_bar( $this->total );

		$payment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = 'edd_payment' AND post_status IN ('publish','revoked','cancelled') ORDER BY post_date ASC LIMIT %d,%d;",
				$mid_process ? $this->current_page * $this->per_page : $this->current_page,
				$this->per_page
			)
		);

		if ( $payment_ids ) {

			EDD_Recurring()->includes_admin();

			if ( ! function_exists( 'edd_set_upgrade_complete' ) ) {
				require_once EDD_PLUGIN_DIR . 'includes/admin/upgrades/upgrade-functions.php';
			}

			foreach ( $payment_ids as $payment_id ) {

				$edd_payment = edd_get_payment( $payment_id );

				if ( 'stripe' === $edd_payment->gateway ) {
					$this->maybe_migrate_stripe_subscriptions( $edd_payment );
				}

				if  ( 'paypal' === $edd_payment->gateway ) {
					$this->maybe_migrate_paypal_subscriptions( $edd_payment );
				}

			}

			$this->current_page++;
			$this->maybe_migrate_subscriptions();
		} else {
			$this->reset_pagination_args();
			$this->cli->success_message( 'Completed migrating subscriptions' );
		}
	}

	public function get_subscriptions( $payment ) {

		$subscriptions = [];

		foreach ( $payment->cart_details as $key => $item ) {

			$download_id = $item['id'];
			$download    = edd_get_download( $item['id'] );

			if ( edd_has_variable_prices( $download_id ) ) {
				$price_id  = $item['item_number']['options']['price_id'];
				$recurring = edd_recurring()->is_price_recurring( $download_id, $price_id );
				$times     = edd_recurring()->get_times( $price_id, $download_id );
				$period    = edd_recurring()->get_period( $price_id, $download_id );
				$fees      = edd_recurring()->get_signup_fee( $price_id, $download_id );
			} else {
				$recurring = edd_recurring()->is_recurring( $download_id );
				$times     = edd_recurring()->get_times_single( $download_id );
				$period    = edd_recurring()->get_period_single( $download_id );
				$fees      = edd_recurring()->get_signup_fee_single( $download_id );
			}

			if ( ! $recurring ) {
				continue;
			}

			if ( edd_get_option( 'recurring_one_time_discounts' ) ) {
				$recurring_tax    = edd_calculate_tax( $item['subtotal'] );
				$recurring_amount = $item['subtotal'] + $recurring_tax;
			} else {
				$recurring_tax    = edd_calculate_tax( $item['price'] - $item['tax'] );
				$recurring_amount = $item['price'];
			}

			// Determine tax amount for any fees if it's more than $0
			$fee_tax = $fees > 0 ? edd_calculate_tax( $fees ) : 0;

			$args = array(
				'id'               => $item['id'],
				'name'             => $item['name'],
				'price_id'         => isset( $item['item_number']['options']['price_id'] ) ? $item['item_number']['options']['price_id'] : false,
				'initial_amount'   => edd_sanitize_amount( $item['price'] + $fees + $fee_tax ),
				'recurring_amount' => edd_sanitize_amount( $recurring_amount ),
				'initial_tax'      => edd_use_taxes() ? edd_sanitize_amount( $item['tax'] + $fee_tax ) : 0,
				'recurring_tax'    => edd_use_taxes() ? edd_sanitize_amount( $recurring_tax ) : 0,
				'signup_fee'       => edd_sanitize_amount( $fees ),
				'period'           => $period,
				'frequency'        => 1, // Hard-coded to 1 for now but here in case we offer it later. Example: charge every 3 weeks
				'bill_times'       => $times,
				'profile_id'       => '', // Profile ID for this subscription - This is set by the payment gateway
				'transaction_id'   => '', // Transaction ID for this subscription - This is set by the payment gateway
			);

			$subscriptions[] = apply_filters( 'edd_recurring_subscription_pre_gateway_args', $args, $item );
		}

		return $subscriptions;
	}

	public function record_signup( $payment, $subscriptions, $subscriber, $stripe, $next_date ) {

		// Set subscription_payment
		$payment->update_meta( '_edd_subscription_payment', true );

		/*
		 * We need to delete pending subscription records to prevent duplicates. This ensures no duplicate subscription records are created when a purchase is being recovered. See:
		 * https://github.com/easydigitaldownloads/edd-recurring/issues/707
		 * https://github.com/easydigitaldownloads/edd-recurring/issues/762
		 */
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}edd_subscriptions WHERE parent_payment_id = %d AND status = 'pending';", $payment->ID ) );

		// Now create the subscription record(s)
		foreach ( $subscriptions as $subscription ) {

			if( isset( $subscription['status'] ) ) {
				$status  = $subscription['status'];
			} else {
				$status = 'active';
			}

			$trial_period = ! empty( $subscription['has_trial'] ) ? $subscription['trial_quantity'] . ' ' . $subscription['trial_unit'] : '';

			$args = array(
				'product_id'        => $subscription['id'],
				'user_id'           => $payment->payment_meta['user_info']['id'],
				'parent_payment_id' => $payment->ID,
				'status'            => $status,
				'period'            => $subscription['period'],
				'initial_amount'    => $subscription['initial_amount'],
				'recurring_amount'  => $subscription['recurring_amount'],
				'bill_times'        => $subscription['bill_times'],
				'expiration'        => $next_date,
				'trial_period'      => $trial_period,
				'profile_id'        => $subscription['profile_id'],
				'transaction_id'    => $subscription['transaction_id'],
			);

			$args = apply_filters( 'edd_recurring_pre_record_signup_args', $args, $stripe );
			$sub = $subscriber->add_subscription( $args );
		}
	}

	public function get_subscriber( $stripe_object ) {

		$purchase_data = $stripe_object->purchase_data;

		$user_id       = $purchase_data['user_info']['id'];
		$email         = $purchase_data['user_info']['email'];

		if ( empty( $user_id ) ) {
			$subscriber = new \EDD_Recurring_Subscriber( $email );
		} else {
			$subscriber = new \EDD_Recurring_Subscriber( $user_id, true );
		}

		if ( empty( $subscriber->id ) ) {

			$name = '';

			if ( ! empty( $purchase_data['user_info']['first_name'] ) ) {
				$name = $purchase_data['user_info']['first_name'];
			}

			if ( ! empty( $purchase_data['user_info']['last_name'] ) ) {
				$name .= ' ' . $purchase_data['user_info']['last_name'];
			}

			$subscriber_data = array(
				'name'    => $name,
				'email'   => $purchase_data['user_info']['email'],
				'user_id' => $user_id,
			);

			$subscriber->create( $subscriber_data );
		}

		return $subscriber;
	}

	public function maybe_migrate_paypal_subscriptions( $edd_payment ) {

		if ( ! class_exists( 'EDD_Recurring_Paypal' ) ) {
			$this->cli->warning_message( 'EDD_Recurring_PayPal class not available. Enable EDD Recurring Payments plugin.' );
			return;
		}

		$wc_id = $edd_payment->get_meta( '_wc_order_id' );
		$ids   = implode( ', ', $this->test_ids );

		if ( ! in_array( $wc_id, $this->test_ids ) ) {
			$this->cli->warning_message( "Currently, we are only migrating Test IDs. For EDD Payment $edd_payment->ID, the related WC Order is $wc_id, which is not in the test IDs: $ids." );
			return;
		}

		$edd_recurring_paypal = new \EDD_Recurring_PayPal;
		$edd_recurring_paypal->init();

		$edd_recurring_paypal->purchase_data              = apply_filters( 'edd_recurring_purchase_data', $edd_payment->payment_meta );
		$edd_recurring_paypal->purchase_data['user_email'] = $edd_payment->payment_meta['user_info']['email'];

		$subscriber                        = $this->get_subscriber( $edd_recurring_paypal );
		$edd_recurring_paypal->customer_id = $subscriber->id;

		$this->cli->warning_message( 'Subscriber object: ', $subscriber );

		$edd_recurring_paypal->subscriptions = $this->get_subscriptions( $edd_payment );

		if ( empty( $edd_recurring_paypal->subscriptions ) ) {
			$this->cli->warning_message( "Not a recurring order." );
			return;
		}

		do_action( 'edd_recurring_pre_create_payment_profiles', $edd_recurring_paypal );

		$profile_id = $edd_payment->get_meta( '_edd_subscription_id' );

		if ( empty( $profile_id ) ) {
			$this->cli->warning_message( "PayPal Subscription ID is not set, we cannot migrate this subscription." );
			return;
		}

		add_filter( 'edd_recurring_subscription_pre_gateway_args', function( $args ) use ( $profile_id ) {
			$args['profile_id'] = $profile_id;

			return $args;
		} );

		$this->cli->warning_message( "Fetching WC Subscription." );

		$subs   = wcs_get_subscriptions_for_order( $wc_id, array( 'order_type' => 'any' ) );
		$sub    = array_pop( $subs );
		$status = $sub->get_status();

		if ( 'active' !== $status ) {
			$this->cli->warning_message( "This WC Subcription is not active, we will not create a Stripe subscription.", $sub );
			return;
		}

		// Only create one subscription per order (renewals should not create additional subscriptions)
		$created = $sub->get_meta( 'edd_paypal_created' );

		if ( $created ) {
			$this->cli->warning_message( 'This subscription has already been synchronized in PayPal and attached to EDD.' );
			return;
		}

		$edd_recurring_paypal->create_payment_profiles();

		do_action( 'edd_recurring_post_create_payment_profiles', $edd_recurring_paypal );

		// Record the subscriptions and finish up
		$this->record_signup( $edd_payment, $edd_recurring_paypal->subscriptions, $subscriber, $edd_recurring_paypal, $sub->get_date( 'next_payment' ) );

		$errors = edd_get_errors();

		if ( ! empty( $errors ) ) {
			$this->cli->warning_message( 'Errors: ', $errors );
		} else {

			$sub->update_status( 'on-hold', __( 'Status set to on-hold, migrated to EDD.' ), true );
			$sub->add_meta_data( 'edd_paypal_created', current_time( 'timestamp' ) );
			$sub->save_meta_data();

			$this->cli->success_message( 'Successfully migrated subscription' );
		}

		$this->cli->confirm( 'Continue on to the next PayPal migration?' );
	}

	/**
	 * Handle creation of Stripe Product, Plan, and Subscription object.
	 *
	 * @param  [type] $order [description]
	 * @return [type]        [description]
	 */
	public function maybe_migrate_stripe_subscriptions( $edd_payment ) {

		if ( ! class_exists( '\Stripe\Stripe' ) ) {
			return $this->cli->warning_message( 'Stripe API is not available. Enable EDD Stripe plugin.' );
		}

		if ( ! class_exists( 'EDD_Recurring_Stripe' ) ) {
			$this->cli->warning_message( 'EDD_Recurring_Stripe class not available. Enable EDD Recurring Payments plugin.' );
			return;
		}

		$wc_id = $edd_payment->get_meta( '_wc_order_id' );
		$ids   = implode( ', ', $this->test_ids );

		if ( ! in_array( $wc_id, $this->test_ids ) ) {
			$this->cli->warning_message( "Currently, we are only migrating Test IDs. For EDD Payment $edd_payment->ID, the related WC Order is $wc_id, which is not in the test IDs: $ids." );
			return;
		}

		$edd_recurring_stripe = new \EDD_Recurring_Stripe;
		$edd_recurring_stripe->init();

		$edd_recurring_stripe->purchase_data              = apply_filters( 'edd_recurring_purchase_data', $edd_payment->payment_meta );
		$edd_recurring_stripe->purchase_data['user_email'] = $edd_payment->payment_meta['user_info']['email'];

		$subscriber                        = $this->get_subscriber( $edd_recurring_stripe );
		$edd_recurring_stripe->customer_id = $subscriber->id;

		$this->cli->warning_message( 'Subscriber object: ', $subscriber );

		$edd_recurring_stripe->subscriptions = $this->get_subscriptions( $edd_payment );

		if ( empty( $edd_recurring_stripe->subscriptions ) ) {
			$this->cli->warning_message( "Not a recurring order." );
			return;
		}

		do_action( 'edd_recurring_pre_create_payment_profiles', $edd_recurring_stripe );

		$card_id   = $edd_payment->get_meta( '_edd_stripe_card_id' );
		$source_id = $edd_payment->get_meta( '_edd_stripe_source_id' );

		if ( $source_id ) {
			$_POST['edd_stripe_existing_card'] = $source_id;
		} else if ( $card_id ) {
			$_POST['edd_stripe_existing_card'] = $card_id;
		} else {
			$this->cli->warning_message( "Both Card ID and Source ID are empty, we cannot create a subscription." );
			return;
		}

		$customer_id = $edd_payment->get_meta( '_edd_stripe_customer_id' );

		$customer_swap = function( $id, $subscriber ) use ( $customer_id ) {
			$this->cli->warning_message( 'Found customer ID on EDD Payment: ', $customer_id );

			if ( empty( $customer_id ) ) {
				return $id;
			}

			return $customer_id;
		};

		add_filter( 'edd_recurring_get_customer_id', $customer_swap, 10, 2 );


		$this->cli->warning_message( "Fetching WC Subscription." );

		$subs   = wcs_get_subscriptions_for_order( $wc_id, array( 'order_type' => 'any' ) );
		$sub    = array_pop( $subs );
		$status = $sub->get_status();

		if ( 'active' !== $status ) {
			$this->cli->warning_message( "This WC Subcription is not active, we will not create a Stripe subscription.", $sub );
			return;
		}

		// Only create one subscription per order (renewals should not create additional subscriptions)
		$created = $sub->get_meta( 'edd_stripe_created' );

		if ( $created ) {
			$this->cli->warning_message( 'This subscription has already been generated in Stripe and attached to EDD.' );
			return;
		}

		add_filter( 'edd_recurring_create_stripe_subscription_args', function( $args ) use ( $sub ) {
			$args['billing_cycle_anchor'] = strtotime( $sub->get_date( 'next_payment' ) );
			return $args;
		} );

		$edd_recurring_stripe->create_payment_profiles();

		do_action( 'edd_recurring_post_create_payment_profiles', $edd_recurring_stripe );

		// Record the subscriptions and finish up
		$this->record_signup( $edd_payment, $edd_recurring_stripe->subscriptions, $subscriber, $edd_recurring_stripe, $sub->get_date( 'next_payment' ) );

		remove_filter( 'edd_recurring_get_customer_id', $customer_swap, 10, 2 );

		$errors = edd_get_errors();

		unset( $_POST['edd_stripe_existing_card'] );

		if ( ! empty( $errors ) ) {
			$this->cli->warning_message( 'Errors: ', $errors );
		} else {

			$sub->update_status( 'on-hold', __( 'Status set to on-hold, migrated to EDD.' ), true );
			$sub->add_meta_data( 'edd_stripe_created', current_time( 'timestamp' ) );
			$sub->save_meta_data();

			$this->cli->success_message( 'Successfully migrated subscription' );
		}

		$this->cli->confirm( 'Continue on to the next Stripe migration?' );
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

		global $wpdb;

		$user_id       = $wc_order->get_customer_id();
		$order_id      = $wc_order->get_id();
		$customer_keys = WC_AM_Helpers()->get_users_data( $wc_order->get_customer_id() );
		$edd_payment   = edd_get_payment( $edd_payment_id );
		$cart_details  = $edd_payment->cart_details;

		$activations = get_user_meta( $user_id, $wpdb->get_blog_prefix() . WC_AM_HELPERS()->user_meta_key_activations . $wc_order->get_order_key() );

		if ( ! empty( $activations ) ) {
			$activations = $activations[0];
		}

		$this->cli->success_message( "WC SL fetched" );

		foreach ( $customer_keys as $api_key => $data ) {

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

			foreach ( $cart_details as $index => $item ) {

				$download = edd_get_download( $item['id'] );

				if ( ! $download->_edd_sl_enabled ) {
					continue;
				}

				$prices       = edd_get_variable_prices( $item['id'] );
				$price_id     = $item['item_number']['options']['price_id'];

				if ( isset( $prices[ $price_id ] ) ) {
					$price_object = $prices[ $price_id ];
					$is_lifetime = false !== stristr( $price_object['name'], 'lifetime' );
					$length      = $price_object['times'] . ' ' . $price_object['period'];
				} else {
					$is_lifetime = false;
					$length      = '1 year';
				}

				$date_paid = $wc_order->get_date_completed();

				if ( ! $date_paid ) {
					$date_paid = $wc_order->get_date_paid();
				}

				$hash = md5( json_encode( array( 'item_id' => $item['id'], 'args' => array(
					'license_length' => $length,
					'expiration_date' => strtotime( "+$length", $date_paid->getTimestamp() ),
					'is_lifetime'     => $is_lifetime
				) ) ) );

				$migrated_licenses = (array) $edd_payment->get_meta( 'edd_sl_migrated' );

				if ( in_array( $hash, $migrated_licenses ) ) {
					continue;
				}

				$license  = ( new \EDD_SL_License() )->create(
					$item['id'],
					$edd_payment_id,
					$price_id,
					$index,
					array(
						'license_length' => $length,
						'expiration_date' => strtotime( "+$length", $date_paid->getTimestamp() ),
						'is_lifetime'     => $is_lifetime
					)
				);

				if ( empty( $license ) ) {
					$this->cli->warning_message( "WC SL could not be imported" );
				} else {
					$migrated_licenses[] = $hash;
					$edd_payment->update_meta( 'edd_sl_migrated', $migrated_licenses );
					$this->cli->success_message( "WC SL imported" );

					if ( ! empty( $activations ) ) {
						$this->cli->success_message( "Activating license for " . count( $activations ) . ' sites.' );

						$license_id = array_pop( $license );
						$license =  ( new \EDD_SL_License( $license_id ) );

						$meta = [];

						foreach ( $activations as $activation ) {
							$meta[] = $activation;
							$license->add_site( $activation['activation_domain'] );
							$status =  (bool) $activation['activation_active'] ? 'active' : 'inactive';
							$license->set_status( $status );
							$this->cli->success_message( "Activated license for " .  $activation['activation_domain'] );
						}

						$license->update_meta( 'edd_historical_wcam_data', $meta );
					}

				}
			}
		}


	}

	public function maybe_migrate_remaining_users() {

	}

	public function migrate( $args, $assoc_args ) {

		if ( is_null( $this->cli ) ) {
			$this->cli = new Actions( $args, $assoc_args, self::$log_dir );
		}

		$this->set_test_mode();

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
		 * Orders Migrate (includes Software Licensing, PayPal/Stripe recurring info)
		 */
		$this->migrate_orders();

		/**
		 * Step 6
		 * Check for Stripe Gateways, and confirm migration
		 */
		$this->maybe_migrate_subscriptions();

		$this->cli->success_message( 'Migration complete! Please note: This CLI tool currently migrates most data via older APIs - we rely on EDD to migrate the newly migrated data to their new structures. Go to your WordPress Dashboard and run the upgrade routines now.' );

	}

	private function reset() {
		// wp db reset --yes && wp db import local-2018-03-29-6ea391b.sql && wp plugin activate stop-emails && wp plugin activate debug-bar && wp plugin activate debug-bar-console && wp plugin deactivate wpmandrill && wp plugin deactivate edd-mail-chimp && wp plugin activate wp-cli-woo-to-edd-migration
	}
}
