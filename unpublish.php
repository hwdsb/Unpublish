<?php
/*
Plugin Name: Unpublish
Version: 1.3
Description: Unpublish your content
Author: Human Made Ltd
Author URI: http://hmn.md/
Plugin URI: http://hmn.md/
Text Domain: unpublish
Domain Path: /languages
*/

class Unpublish {

	public static $supports_key = 'unpublish';
	public static $deprecated_cron_key = 'unpublish_cron';
	public static $cron_key = 'unpublish_post_cron';
	public static $post_meta_key = 'unpublish_timestamp';

	protected static $instance;

	public static function get_instance() {

		if ( empty( self::$instance ) ) {
			self::$instance = new Unpublish;
			// Standard setup methods
			foreach ( array( 'setup_variables', 'includes', 'setup_actions' ) as $method ) {
				if ( method_exists( self::$instance, $method ) ) {
					self::$instance->$method();
				}
			}
		}
		return self::$instance;
	}

	private function __construct() {
		/** Prevent the class from being loaded more than once **/
	}

	/**
	 * Set up variables associated with the plugin
	 */
	private function setup_variables() {
		$this->file           = __FILE__;
		$this->basename       = plugin_basename( $this->file );
		$this->plugin_dir     = plugin_dir_path( $this->file );
		$this->plugin_url     = plugin_dir_url( $this->file );
		$this->cron_frequency = 'twicedaily';
	}

	/**
	 * Set up action associated with the plugin
	 */
	private function setup_actions() {

		add_action( 'load-post.php', array( self::$instance, 'action_load_customizations' ) );
		add_action( 'load-post-new.php', array( self::$instance, 'action_load_customizations' ) );
		add_action( 'added_post_meta', array( self::$instance, 'update_schedule' ), 10, 4 );
		add_action( 'updated_post_meta', array( self::$instance, 'update_schedule' ), 10, 4 );
		add_action( 'deleted_post_meta', array( self::$instance, 'remove_schedule' ), 10, 3 );
		add_action( 'trashed_post', array( self::$instance, 'unschedule_unpublish' ) );
		add_action( 'untrashed_post', array( self::$instance, 'reschedule_unpublish' ) );
		add_action( self::$cron_key, array( self::$instance, 'unpublish_post' ) );
		add_filter( 'is_protected_meta', array( self::$instance, 'protect_meta_key' ), 10, 3 );

		if ( wp_next_scheduled( self::$deprecated_cron_key ) ) {
			add_action( self::$deprecated_cron_key, array( self::$instance, 'unpublish_content' ) );
		}
	}

	/**
	 * Load any / all customizations to the admin
	 */
	public function action_load_customizations() {

		$post_type = get_current_screen()->post_type;
		if ( post_type_supports( $post_type, self::$supports_key ) ) {
			add_action( 'post_submitbox_misc_actions', array( self::$instance, 'render_unpublish_ui' ), 1 );
			add_action( 'admin_enqueue_scripts', array( self::$instance, 'enqueue_scripts_styles' ) );
			add_action( 'save_post_' . $post_type, array( self::$instance, 'action_save_unpublish_timestamp' ) );
		}

	}

	/**
	 *  Get month names
	 *
	 *  global WP_Locale $wp_locale
	 *
	 *  @return array Array of month names.
	 */
	protected function get_month_names() {
		global $wp_locale;

		$month_names = [];

		for ( $i = 1; $i < 13; $i = $i + 1 ) {
			$month_num     = zeroise( $i, 2 );
			$month_text    = $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) );
			$month_names[] = array(
				'value' => $month_num,
				'text'  => $month_text,
				'label' => sprintf( _x( '%1$s-%2$s', 'month number-name', 'unpublish' ), $month_num, $month_text ),
			);
		}

		return $month_names;
	}

	/**
	 *  Get post unpublish timestamp
	 *
	 *  @param  int    $post_id Post ID.
	 *  @return string Timestamp.
	 */
	private function get_unpublish_timestamp( $post_id ) {
		return get_post_meta( $post_id, self::$post_meta_key, true );
	}

	/**
	 * Render the UI for changing the unpublish time of a post
	 */
	public function render_unpublish_ui() {

		$unpublish_timestamp = $this->get_unpublish_timestamp( get_the_ID() );
		if ( ! empty( $unpublish_timestamp ) ) {
			$local_timestamp = strtotime( get_date_from_gmt( date( 'Y-m-d H:i:s', $unpublish_timestamp ) ) );
			/* translators: Unpublish box date format, see https://secure.php.net/date */
			$datetime_format = __( 'M j, Y @ H:i', 'unpublish' );
			$unpublish_date  = date_i18n( $datetime_format, $local_timestamp );
			$date_parts      = array(
				'jj' => date( 'd', $local_timestamp ),
				'mm' => date( 'm', $local_timestamp ),
				'aa' => date( 'Y', $local_timestamp ),
				'hh' => date( 'H', $local_timestamp ),
				'mn' => date( 'i', $local_timestamp ),
			);
		} else {
			$unpublish_date = '&mdash;';
			$date_parts     = array(
				'jj' => '',
				'mm' => '',
				'aa' => '',
				'hh' => '',
				'mn' => '',
			);
		}

		$vars = array(
			'unpublish_date' => $unpublish_date,
			'month_names'    => $this->get_month_names(),
			'date_parts'     => $date_parts,
			'date_units'     => array( 'aa', 'mm', 'jj', 'hh', 'mn' ),
		);

		echo $this->get_view( 'unpublish-ui', $vars ); // xss ok
	}

	/**
	 *  Enqueue scripts & styles
	 */
	public function enqueue_scripts_styles() {
		wp_enqueue_style( 'unpublish', plugins_url( 'css/unpublish.css', __FILE__ ), array(), '0.1-alpha' );
		wp_enqueue_script( 'unpublish', plugins_url( 'js/unpublish.js', __FILE__ ), array( 'jquery' ), '0.1-alpha', true );
		wp_localize_script( 'unpublish', 'unpublish', array(
			/* translators: 1: month, 2: day, 3: year, 4: hour, 5: minute */
			'dateFormat' => __( '%1$s %2$s, %3$s @ %4$s:%5$s', 'unpublish' ),
		) );
	}

	/**
	 * Add schedule
	 *
	 * @param int    $meta_id    ID of updated metadata entry.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public function update_schedule( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( self::$post_meta_key !== $meta_key ) {
			return;
		}

		if ( $meta_value ) {
			$this->schedule_unpublish( $object_id, $meta_value );
		} else {
			$this->unschedule_unpublish( $object_id );
		}
	}

	/**
	 * Remove schedule
	 *
	 * @param array  $meta_ids   An array of deleted metadata entry IDs.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 */
	public function remove_schedule( $meta_ids, $object_id, $meta_key ) {
		if ( self::$post_meta_key === $meta_key ) {
			$this->unschedule_unpublish( $object_id );
		}
	}

	/**
	 * Save the unpublish time for a given post
	 */
	public function action_save_unpublish_timestamp( $post_id ) {
		if ( ! isset( $_POST['unpublish-nonce'] ) || ! wp_verify_nonce( $_POST['unpublish-nonce'], 'unpublish' ) ) {
			return;
		}

		if ( ! post_type_supports( get_post_type( $post_id ), self::$supports_key ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$units       = array( 'aa', 'mm', 'jj', 'hh', 'mn' );
		$units_count = count( $units );
		$date_parts  = [];

		foreach ( $units as $unit ) {
			$key = sprintf( 'unpublish-%s', $unit );
			$date_parts[ $unit ] = $_POST[ $key ];
		}

		$date_parts = array_filter( $date_parts );

		// The unpublish date has just been cleared.
		if ( empty( $date_parts ) ) {
			delete_post_meta( $post_id, self::$post_meta_key );
			return;
		}

		// Bail if one of the fields is empty.
		if ( count( $date_parts ) !== $units_count ) {
			return;
		}

		$unpublish_date = vsprintf( '%04d-%02d-%02d %02d:%02d:00', $date_parts );
		$valid_date     = wp_checkdate( $date_parts['mm'], $date_parts['jj'], $date_parts['aa'], $unpublish_date );

		if ( ! $valid_date ) {
			return;
		}

		$timestamp = strtotime( get_gmt_from_date( $unpublish_date ) );

		update_post_meta( $post_id, self::$post_meta_key, $timestamp );
	}

	/**
	 * Unpublish post
	 *
	 * Invoked by cron 'unpublish_post_cron' event.
	 *
	 * @param int $post_id Post ID.
	 */
	public function unpublish_post( $post_id ) {
		$unpublish_timestamp = (int) $this->get_unpublish_timestamp( $post_id );

		if ( $unpublish_timestamp > time() ) {
			$this->schedule_unpublish( $post_id, $unpublish_timestamp );
			return;
		}

		wp_trash_post( $post_id );
	}

	/**
	 * Unschedule unpublishing post
	 *
	 * @param int $post_id Post ID.
	 */
	public function unschedule_unpublish( $post_id ) {
		wp_clear_scheduled_hook( self::$cron_key, array( $post_id ) );
	}

	/**
	 *  Schedule unpublishing post
	 *
	 *  @param  int $post_id   Post ID.
	 *  @param  int $timestamp Timestamp.
	 */
	public function schedule_unpublish( $post_id, $timestamp ) {
		$this->unschedule_unpublish( $post_id );

		if ( $timestamp > current_time( 'timestamp', true ) ) {
			wp_schedule_single_event( $timestamp, self::$cron_key, array( $post_id ) );
		}
	}

	/**
	 * Reschedule unpublishing post
	 *
	 * @param int $post_id Post ID.
	 */
	public function reschedule_unpublish( $post_id ) {
		$timestamp = $this->get_unpublish_timestamp( $post_id );

		if ( $timestamp ) {
			$this->schedule_unpublish( $post_id, $timestamp );
		}
	}

	/**
	 * Unpublish any content that needs unpublishing
	 */
	public function unpublish_content() {
		global $_wp_post_type_features;

		$post_types = array();
		foreach ( $_wp_post_type_features as $post_type => $features ) {
			if ( ! empty( $features[ self::$supports_key ] ) ) {
				$post_types[] = $post_type;
			}
		}

		$args = array(
			'fields'          => 'ids',
			'post_type'       => $post_types,
			'post_status'     => 'any',
			'posts_per_page'  => 40,
			'meta_query'      => array(
				array(
					'meta_key'    => self::$post_meta_key,
					'meta_value'  => current_time( 'timestamp' ),
					'compare'     => '<',
					'type'        => 'NUMERIC',
				),
				array(
					'meta_key'    => self::$post_meta_key,
					'meta_value'  => current_time( 'timestamp' ),
					'compare'     => 'EXISTS',
				),
			),
		);
		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				wp_trash_post( $post_id );
			}
		} else {
			// There are no posts scheduled to unpublish, we can safely remove the old cron.
			wp_clear_scheduled_hook( self::$deprecated_cron_key );
		}
	}

	/**
	 * Protect meta key so it doesn't show up on Custom Fields meta box
	 *
	 * @param bool   $protected Whether the key is protected. Default false.
	 * @param string $meta_key  Meta key.
	 * @param string $meta_type Meta type.
	 *
	 * @return bool
	 */
	public function protect_meta_key( $protected, $meta_key, $meta_type ) {
		if ( $meta_key === self::$post_meta_key && 'post' === $meta_type ) {
			$protected = true;
		}

		return $protected;
	}

	/**
	 * Get a given view (if it exists)
	 *
	 * @param string     $view      The slug of the view
	 * @return string
	 */
	public function get_view( $view, $vars = array() ) {

		if ( isset( $this->template_dir ) ) {
			$template_dir = $this->template_dir;
		} else {
			$template_dir = $this->plugin_dir . '/inc/templates/';
		}

		$view_file = $template_dir . $view . '.tpl.php';
		if ( ! file_exists( $view_file ) ) {
			return '';
		}

		extract( $vars, EXTR_SKIP );
		ob_start();
		include $view_file;
		return ob_get_clean();
	}
}

/**
 * Load the plugin
 */
function unpublish() {
	return Unpublish::get_instance();
}
add_action( 'plugins_loaded', 'unpublish' );
