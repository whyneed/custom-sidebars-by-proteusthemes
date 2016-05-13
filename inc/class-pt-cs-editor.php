<?php
/**
 * Provides all the functionality for editing sidebars on the widgets page. *Singleton*
 *
 * @package pt-cs
 */

// Initialize this class in the main plugin class.
add_action( 'pt_cs_init', array( 'PT_CS_Editor', 'instance' ) );

/**
 * Provides all the functionality for editing sidebars on the widgets page.
 */
class PT_CS_Editor extends PT_CS_Main {

	/**
	 * Returns the singleton object.
	 */
	public static function instance() {
		static $inst = null;

		if ( null === $inst ) {
			$inst = new PT_CS_Editor();
		}

		return $inst;
	}

	/**
	 * Constructor is private -> singleton.
	 */
	private function __construct() {
		if ( is_admin() ) {

			// Add the sidebar metabox to posts.
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

			// Save the options from the sidebars-metabox.
			add_action( 'save_post', array( $this, 'store_replacements' ) );

			// Handle ajax requests.
			add_action( 'cs_ajax_request', array( $this, 'handle_ajax' ) );
		}
	}

	/**
	 * Handles the ajax requests.
	 *
	 * @param string $action String for the appropriate action.
	 */
	public function handle_ajax( $action ) {
		$req = (object) array(
			'status' => 'ERR',
		);
		$is_json   = true;
		$handle_it = false;
		$view_file = '';

		$sb_id = isset( $_POST['sb'] ) ? $_POST['sb'] : null;

		switch ( $action ) {
			case 'get':
			case 'save':
			case 'delete':
			case 'get-location':
			case 'set-location':
			case 'replaceable':
				$handle_it = true;
				$req->status = 'OK';
				$req->action = $action;
				$req->id = $sb_id;
				break;
		}

		// The ajax request was not meant for us...
		if ( ! $handle_it ) {
			return false;
		}

		$sb_data = self::get_sidebar( $sb_id );

		if ( ! current_user_can( self::$cap_required ) ) {
			$req = self::req_err(
				$req,
				esc_html__( 'You do not have permission for this', PT_CS_TD )
			);
		} else {
			switch ( $action ) {

				// Return details for the specified sidebar.
				case 'get':
					$req->sidebar = $sb_data;
					break;

				// Save or insert the specified sidebar.
				case 'save':
					$req = $this->save_item( $req, $_POST );
					break;

				// Delete the specified sidebar.
				case 'delete':
					$req->sidebar = $sb_data;
					$req = $this->delete_item( $req );
					break;

				// Get the location data.
				case 'get-location':
					$req->sidebar = $sb_data;
					$req = $this->get_location_data( $req );
					break;

				// Update the location data.
				case 'set-location':
					$req->sidebar = $sb_data;
					$req = $this->set_location_data( $req );
					break;

				// Toggle theme sidebar replaceable-flag.
				case 'replaceable':
					$req = $this->set_replaceable( $req );
					break;
			}
		}

		// Make the ajax response either as JSON or plain text.
		if ( $is_json ) {
			self::json_response( $req );
		} else {
			ob_start();
			include PT_CS_VIEWS_DIR . $view_file;
			$resp = ob_get_clean();

			self::plain_response( $resp );
		}
	}

	/**
	 * Saves the item specified by $data array and populates the response
	 * object. When $req->id is empty a new sidebar will be created. Otherwise
	 * the existing sidebar is updated.
	 *
	 * @param  object $req Initial response object.
	 * @param  array  $data Sidebar data to save (typically this is $_POST).
	 * @return object Updated response object.
	 */
	private function save_item( $req, $data ) {
		$sidebars = self::get_custom_sidebars();
		$sb_id    = $req->id;
		$sb_desc  = isset( $data['description'] ) ? stripslashes( trim( $data['description'] ) ) : '';

		if ( function_exists( 'mb_substr' ) ) {
			$sb_name = isset( $data['name'] ) ? mb_substr( stripslashes( trim( $data['name'] ) ), 0, 40 ) : '';
		} else {
			$sb_name = isset( $data['name'] ) ? substr( stripslashes( trim( $data['name'] ) ), 0, 40 ) : '';
		}

		if ( empty( $sb_name ) ) {
			return self::req_err(
				$req,
				esc_html__( 'Sidebar-name cannot be empty', PT_CS_TD )
			);
		}

		if ( empty( $sb_id ) ) {

			// Create a new sidebar.
			$action = 'insert';
			$num    = count( $sidebars );
			do {
				$num += 1;
				$sb_id = self::$sidebar_prefix . $num;
			} while ( self::get_sidebar( $sb_id, 'cust' ) );

			$sidebar = array(
				'id' => $sb_id,
			);
		} else {

			// Update existing sidebar.
			$action = 'update';
			$sidebar = self::get_sidebar( $sb_id, 'cust' );

			if ( ! $sidebar ) {
				return self::req_err(
					$req,
					esc_html__( 'The sidebar does not exist', PT_CS_TD )
				);
			}
		}

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $sb_desc ) > 200 ) {
				$sb_desc = mb_substr( $sb_desc, 0, 200 );
			}
		} else {
			if ( strlen( $sb_desc ) > 200 ) {
				$sb_desc = substr( $sb_desc, 0, 200 );
			}
		}

		// Populate the sidebar object.
		$sidebar['name']          = $sb_name;
		$sidebar['description']   = $sb_desc;
		$sidebar['before_widget'] = isset( $data['before_widget'] ) ? stripslashes( trim( $data['before_widget'] ) ) : '';
		$sidebar['after_widget']  = isset( $data['after_widget'] ) ? stripslashes( trim( $data['after_widget'] ) ) : '';
		$sidebar['before_title']  = isset( $data['before_title'] ) ? stripslashes( trim( $data['before_title'] ) ) : '';
		$sidebar['after_title']   = isset( $data['after_title'] ) ? stripslashes( trim( $data['after_title'] ) ) : '';

		if ( 'insert' === $action ) {
			$sidebars[]   = $sidebar;
			$req->message = sprintf(
				esc_html__( 'Created new sidebar %1$s', PT_CS_TD ),
				'<strong>' . esc_html( $sidebar['name'] ) . '</strong>'
			);
		} else {
			$found = false;
			foreach ( $sidebars as $ind => $item ) {
				if ( $item['id'] === $sb_id ) {
					$req->message = sprintf(
						esc_html__( 'Updated sidebar %1$s', PT_CS_TD ),
						'<strong>' . esc_html( $sidebar['name'] ) . '</strong>'
					);
					$sidebars[ $ind ] = $sidebar;
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return self::req_err(
					$req,
					esc_html__( 'The sidebar was not found', PT_CS_TD )
				);
			}
		}

		// Save the changes.
		self::set_custom_sidebars( $sidebars );
		self::refresh_sidebar_widgets();

		$req->action = $action;
		$req->data   = $sidebar;

		return $req;
	}

	/**
	 * Delete the specified sidebar and update the response object.
	 *
	 * @param  object $req Initial response object.
	 * @return object Updated response object.
	 */
	private function delete_item( $req ) {
		$sidebars = self::get_custom_sidebars();
		$sidebar  = self::get_sidebar( $req->id, 'cust' );

		if ( ! $sidebar ) {
			return self::req_err(
				$req,
				esc_html__( 'The sidebar does not exist', PT_CS_TD )
			);
		}

		$found = false;
		foreach ( $sidebars as $ind => $item ) {
			if ( $item['id'] == $req->id ) {
				$found = true;
				$req->message = sprintf(
					esc_html__( 'Deleted sidebar %1$s</strong>', PT_CS_TD ),
					'<strong>' . esc_html( $req->sidebar['name'] ) . '</strong>'
				);
				unset( $sidebars[ $ind ] );
				break;
			}
		}

		if ( ! $found ) {
			return self::req_err(
				$req,
				esc_html__( 'The sidebar was not found', PT_CS_TD )
			);
		}

		// Save the changes.
		self::set_custom_sidebars( $sidebars );
		self::refresh_sidebar_widgets();

		return $req;
	}

	/**
	 * Save the replaceable flag of a theme sidebar.
	 *
	 * @param  object $req Initial response object.
	 * @return object Updated response object.
	 */
	private function set_replaceable( $req ) {
		$state = isset( $_POST['state'] ) ? $_POST['state'] : '' ;

		$options = self::get_options();
		if ( 'true' === $state ) {
			$req->status = true;
			if ( ! in_array( $req->id, $options['modifiable'] ) ) {
				$options['modifiable'][] = $req->id;
			}
		} else {
			$req->status = false;
			foreach ( $options['modifiable'] as $i => $sb_id ) {
				if ( $sb_id == $req->id ) {
					unset( $options['modifiable'][ $i ] );
					break;
				}
			}
		}
		$options['modifiable'] = array_values( $options['modifiable'] );
		self::set_options( $options );
		$req->replaceable = (object) $options['modifiable'];

		return $req;
	}

	/**
	 * Populates the response object for the "get-location" ajax call.
	 * Location data defines where a custom sidebar is displayed, i.e. on which
	 * pages it is used and which theme-sidebars are replaced.
	 *
	 * @param  object $req Initial response object.
	 * @return object Updated response object.
	 */
	private function get_location_data( $req ) {
		$defaults     = self::get_options();
		$raw_posttype = self::get_post_types( 'objects' );
		$raw_cat      = self::get_all_categories();

		$archive_type = array(
			'_blog'    => esc_html__( 'Front Page', PT_CS_TD ),
			'_search'  => esc_html__( 'Search Results', PT_CS_TD ),
			'_404'     => esc_html__( 'Not found (404)', PT_CS_TD ),
			'_authors' => esc_html__( 'Any Author Archive', PT_CS_TD ),
			'_tags'    => esc_html__( 'Tag Archives', PT_CS_TD ),
			'_date'    => esc_html__( 'Date Archives', PT_CS_TD ),
		);

		// Collect required data for all posttypes.
		$posttypes = array();
		foreach ( $raw_posttype as $item ) {
			$sel_single = isset( $defaults['post_type_single'][ $item->name ] ) ? $defaults['post_type_single'][ $item->name ] : array();

			$posttypes[ $item->name ] = array(
				'name'   => $item->labels->name,
				'single' => self::get_array( $sel_single ),
			);
		}

		// Extract the data from categories list that we need.
		$categories = array();
		foreach ( $raw_cat as $item ) {
			$sel_single  = isset( $defaults['category_single'][ $item->term_id ] ) ? $defaults['category_single'][ $item->term_id ]: array();
			$sel_archive = isset( $defaults['category_archive'][ $item->term_id ] ) ? $defaults['category_archive'][ $item->term_id ] : array();

			$categories[ $item->term_id ] = array(
				'name'    => $item->name,
				'count'   => $item->count,
				'single'  => self::get_array( $sel_single ),
				'archive' => self::get_array( $sel_archive ),
			);
		}

		// Build a list of archive types.
		$archives = array(); // Start with a copy of the posttype list.
		foreach ( $raw_posttype as $item ) {
			if ( 'post' === $item->name ) {
				$label = esc_html__( 'Post Index', PT_CS_TD );
			} else {
				if ( ! $item->has_archive ) { continue; }
				$label = sprintf(
					esc_html__( '%1$s Archives', PT_CS_TD ),
					$item->labels->singular_name
				);
			}

			$sel_archive = isset( $defaults['post_type_archive'][ $item->name ] ) ? $defaults['post_type_archive'][ $item->name ] : array();

			$archives[ $item->name ] = array(
				'name'    => $label,
				'archive' => self::get_array( $sel_archive ),
			);
		}

		foreach ( $archive_type as $key => $name ) {
			$sel_archive = isset( $defaults[ substr( $key, 1 ) ] ) ? $defaults[ substr( $key, 1 ) ] : array();

			$archives[ $key ] = array(
				'name'    => $name,
				'archive' => self::get_array( $sel_archive ),
			);
		}

		$req->replaceable = $defaults['modifiable'];
		$req->posttypes   = $posttypes;
		$req->categories  = $categories;
		$req->archives    = $archives;

		return $req;
	}

	/**
	 * Save location data for a single sidebar and populate the response object.
	 * Location data defines where a custom sidebar is displayed, i.e. on which
	 * pages it is used and which theme-sidebars are replaced.
	 *
	 * @param  object $req Initial response object.
	 * @return object Updated response object.
	 */
	private function set_location_data( $req ) {
		$options      = self::get_options();
		$sidebars     = $options['modifiable'];
		$raw_posttype = self::get_post_types( 'objects' );
		$raw_cat      = self::get_all_categories();
		$data         = isset( $_POST['cs'] ) ? $_POST['cs'] : array();
		$special_arc  = array(
			'blog',
			'404',
			'tags',
			'authors',
			'search',
			'date',
		);

		// Update the options.
		foreach ( $sidebars as $sb_id ) {
			// Post-type settings.
			foreach ( $raw_posttype as $item ) {
				$pt = $item->name;
				if ( is_array( @$data['pt'][ $sb_id ] ) && in_array( $pt, $data['pt'][ $sb_id ] ) ) {
					$options['post_type_single'][ $pt ][ $sb_id ] = $req->id;
				} else if ( isset( $options['post_type_single'][ $pt ][ $sb_id ] ) && $options['post_type_single'][ $pt ][ $sb_id ] == $req->id ) {
					unset( $options['post_type_single'][ $pt ][ $sb_id ] );
				}

				if ( is_array( @$data['arc'][ $sb_id ] ) && in_array( $pt, $data['arc'][ $sb_id ] ) ) {
					$options['post_type_archive'][ $pt ][ $sb_id ] = $req->id;
				} else if ( isset( $options['post_type_archive'][ $pt ][ $sb_id ] ) && $options['post_type_archive'][ $pt ][ $sb_id ] == $req->id ) {
					unset( $options['post_type_archive'][ $pt ][ $sb_id ] );
				}
			}

			// Category settings.
			foreach ( $raw_cat as $item ) {
				$cat = $item->term_id;
				if ( is_array( @$data['cat'][ $sb_id ] ) && in_array( $cat, $data['cat'][ $sb_id ] ) ) {
					$options['category_single'][ $cat ][ $sb_id ] = $req->id;
				} else if ( isset( $options['category_single'][ $cat ][ $sb_id ] ) && $options['category_single'][ $cat ][ $sb_id ] == $req->id ) {
					unset( $options['category_single'][ $cat ][ $sb_id ] );
				}

				if ( is_array( @$data['arc-cat'][ $sb_id ] ) && in_array( $cat, $data['arc-cat'][ $sb_id ] ) ) {
					$options['category_archive'][ $cat ][ $sb_id ] = $req->id;
				} else if ( isset( $options['category_archive'][ $cat ][ $sb_id ] ) && $options['category_archive'][ $cat ][ $sb_id ] == $req->id ) {
					unset( $options['category_archive'][ $cat ][ $sb_id ] );
				}
			}

			foreach ( $special_arc as $key ) {
				if ( is_array( @$data['arc'][ $sb_id ] ) && in_array( '_' . $key, $data['arc'][ $sb_id ] ) ) {
					$options[ $key ][ $sb_id ] = $req->id;
				} else if ( isset( $options[ $key ][ $sb_id ] ) && $options[ $key ][ $sb_id ] == $req->id ) {
					unset( $options[ $key ][ $sb_id ] );
				}
			}
		}

		$req->message = sprintf(
			esc_html__( 'Updated sidebar %1$s settings.', PT_CS_TD ),
			'<strong>' . esc_html( $req->sidebar['name'] ) . '</strong>'
		);
		self::set_options( $options );
		return $req;
	}

	/**
	 * Registers the "Sidebars" meta box in the post-editor.
	 */
	public function add_meta_box() {

		$post_type = get_post_type();
		if ( ! $post_type ) { return false; }
		if ( ! self::supported_post_type( $post_type ) ) { return false; }

		/**
		 * Option that can be set in wp-config.php to remove the custom sidebar meta box.
		 *
		 * @option bool true will hide all meta boxes.
		 */
		if ( defined( 'CUSTOM_SIDEBAR_DISABLE_METABOXES' ) && CUSTOM_SIDEBAR_DISABLE_METABOXES ) {
			return false;
		}

		$pt_obj = get_post_type_object( $post_type );
		if ( $pt_obj->publicly_queryable || $pt_obj->public ) {
			add_meta_box(
				'customsidebars-mb',
				esc_html__( 'Sidebars', PT_CS_TD ),
				array( $this, 'print_metabox_editor' ),
				$post_type,
				'side'
			);
		}
	}

	/**
	 * Renders the Custom Sidebars meta box in the post-editor.
	 */
	public function print_metabox_editor() {
		global $post;
		$this->print_sidebars_form( $post->ID, 'metabox' );
	}

	/**
	 * Renders the Custom Sidebars form.
	 *
	 * @param int    $post_id The post-ID to display.
	 * @param string $type Which form to display. 'metabox/quick-edit/col-sidebars'.
	 */
	protected function print_sidebars_form( $post_id, $type = 'metabox' ) {
		global $wp_registered_sidebars;

		$replacements = self::get_replacements( $post_id );

		$available = $wp_registered_sidebars;
		ksort( $available );
		$sidebars = self::get_options( 'modifiable' );
		$selected = array();
		if ( ! empty( $sidebars ) ) {
			foreach ( $sidebars as $s ) {
				if ( isset( $replacements[ $s ] ) ) {
					$selected[ $s ] = $replacements[ $s ];
				} else {
					$selected[ $s ] = '';
				}
			}
		}

		include PT_CS_VIEWS_DIR . 'metabox.php';
	}

	/**
	 * Saves the options from the metabox.
	 *
	 * @param int $post_id The post-ID to display.
	 */
	public function store_replacements( $post_id ) {
		global $action;

		if ( ! current_user_can( self::$cap_required ) ) {
			return;
		}

		/*
		 * Verify if this is an auto save routine. If it is our form has not
		 * been submitted, so we don't want to do anything
		 * (Copied and pasted from wordpress add_metabox_tutorial)
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		/*
		 * 'editpost' .. Saved from full Post-Editor screen.
		 * 'inline-save' .. Saved via the quick-edit form.
		 * We do not (yet) offer a bulk-editing option for custom sidebars.
		 */
		if ( 'editpost' !== $action ) {
			return $post_id;
		}

		// Make sure meta is added to the post, not a revision.
		if ( $the_post = wp_is_post_revision( $post_id ) ) {
			$post_id = $the_post;
		}

		$sidebars = self::get_options( 'modifiable' );
		$data = array();
		if ( ! empty( $sidebars ) ) {
			foreach ( $sidebars as $sb_id ) {
				if ( isset( $_POST[ 'cs_replacement_' . $sb_id ] ) ) {
					$replacement = $_POST[ 'cs_replacement_' . $sb_id ];
					if ( ! empty( $replacement ) && '' != $replacement ) {
						$data[ $sb_id ] = $replacement;
					}
				}
			}
		}

		self::set_post_meta( $post_id, $data );
	}
}
