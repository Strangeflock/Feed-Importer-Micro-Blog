<?php

/**
 * The class that handles the admin screen
 */
class rssMBAdmin {

	/**
	 * The options
	 * 
	 * @var array 
	 */
	var $options;

	/**
	 *  Start
	 * 
	 * @global object $mb_feed_importer
	 */
	public function __construct() {

		$this->load_options();

		// initialise logging
		$this->log = new rssMBLog();
		$this->log->init();

		// load the form processor
		$this->processor = new rssMBAdminProcessor();
	}

	private function load_options() {
		global $mb_feed_importer;

		// add options
		$this->options = $mb_feed_importer->options;

		// check for valid key when we don't have it cached
		// actually this populates the settings with our defaults on the first plugin activation
			// update options
			$new_options = array(
				'feeds' => $this->options['feeds'],
				'settings' => $this->options['settings'],
				'latest_import' => $this->options['latest_import'],
				'imports' => $this->options['imports'],
				'upgraded' => $this->options['upgraded']
			);
			// update in db
			update_option('rss_mb_feeds', $new_options);

	}

	/**
	 * Initialise and hook all actions
	 */
	public function init() {

		// add to admin menu
		add_action('admin_menu', array($this, 'admin_menu'));

		// process and save options prior to screen ui display
		add_action('load-settings_page_rss_mb', array($this, 'save_options'));

		// load scripts and styles we need
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));

		// manage meta data on post deletion and restoring
		add_action('wp_trash_post', array($this, 'delete_post')); // trashing a post
//		add_action('before_delete_post', array($this, 'delete_post')); // deleting a post permanently
		add_action('untrash_post', array($this, 'restore_post')); // restoring a post from trash

		// the ajax for adding new feeds (table rows)
		add_action('wp_ajax_rss_mb_add_row', array($this, 'add_row'));

		// the ajax for stats chart
		add_action('wp_ajax_rss_mb_stats', array($this, 'ajax_stats'));

		// the ajax for importing feeds via admin
		add_action('wp_ajax_rss_mb_import', array($this, 'ajax_import'));

		// disable the feed author dropdown for invalid/absent API keys
		// add_filter('wp_dropdown_users', array($this, 'disable_user_dropdown'));

		// Add 10 minutes in frequency.
		add_filter('cron_schedules', array($this, 'rss_mb_cron_add'));

		// trigger on Export
		if ( isset($_POST['export_opml']) ) {
			$this->opml = new Rss_mb_opml();
			$this->opml->export();
		}

	}

	/**
	 * Add to admin menu
	 */
	function admin_menu() {

		add_options_page( 	'Feed Importer for Micro.blog', 
							'Feed Importer for Micro.blog', 
							'manage_options', 
							'rss_mb', 
							array($this, 'screen')
							);
	}

	/**
	 * Enqueue our admin css and js
	 * 
	 * @param string $hook The current screens hook
	 * @return null
	 */
	public function enqueue($hook) {

		// don't load if it isn't our screen
		if ($hook != 'settings_page_rss_mb') {
			return;
		}

		// register scripts & styles
		wp_enqueue_style('rss-mb', RSS_MB_URL . 'app/assets/css/style.css', array(), RSS_MB_VERSION);

		wp_enqueue_style('rss-mb-jquery-ui-css', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8.21/themes/redmond/jquery-ui.css', array(), RSS_MB_VERSION);

		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-datepicker');
		wp_enqueue_script('jquery-ui-progressbar');

		wp_enqueue_script('modernizr', RSS_MB_URL . 'app/assets/js/modernizr.custom.32882.js', array(), RSS_MB_VERSION, true);
		wp_enqueue_script('phpjs-uniqid', RSS_MB_URL . 'app/assets/js/uniqid.js', array(), RSS_MB_VERSION, true);
		wp_enqueue_script('rss-mb', RSS_MB_URL . 'app/assets/js/main.js', array('jquery'), RSS_MB_VERSION, true);

		// localise ajaxuel for use
		$localise_args = array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'pluginurl' => RSS_MB_URL,
			'l18n' => array(
				'unsaved' => __( 'You have unsaved changes on this page. Do you want to leave this page and discard your changes or stay on this page?', 'rss_mb' )
			)
		);
		wp_localize_script('rss-mb', 'rss_mb', $localise_args);
	}

	// add post URL to rss_mb_deleted_posts when trashing
	function delete_post($post_id) {
		$rss_mb_deleted_posts = get_option( 'rss_mb_deleted_posts', array() );
		$source_md5 = get_post_meta($post_id, 'rss_mb_source_md5', true);
		if ( $source_md5 && ! in_array( $source_md5, $rss_mb_deleted_posts ) ) {
			// add this source URL hash to the "deleted" metadata
			$rss_mb_deleted_posts[] = $source_md5;
			update_option('rss_mb_deleted_posts', $rss_mb_deleted_posts);
		}
	}

	// remove post URL from rss_mb_deleted_posts when restoring from trash
	function restore_post($post_id) {
		$rss_mb_deleted_posts = get_option( 'rss_mb_deleted_posts', array() );
		$source_md5 = get_post_meta($post_id, 'rss_mb_source_md5', true);
		if ( $source_md5 && in_array( $source_md5, $rss_mb_deleted_posts ) ) {
			// remove this source URL hash from the "deleted" metadata
			$rss_mb_deleted_posts = array_diff( $rss_mb_deleted_posts, array( $source_md5 ) );
			update_option('rss_mb_deleted_posts', $rss_mb_deleted_posts);
		}
	}

	function rss_mb_cron_add($schedules) {

		$schedules['minutes_10'] = array(
			'interval' => 300,
			'display' => '5 minutes'
		);
		return $schedules;
	}

	/**
	 * save any options submitted before the screen/ui get displayed
	 */
	function save_options() {

		// load the form processor
		$this->processor->process();

		
			// purge "deleted posts" cache when requested
			$this->processor->purge_deleted_posts_cache();
		
	}

	/**
	 * Display the screen/ui
	 */
	function screen() {

		// it'll process any submitted form data
		// reload the options just in case
		$this->load_options();

		// display a success message
		if ( isset($_GET['deleted_cache_purged']) || isset($_GET['settings-updated']) || isset($_GET['import']) && @$_GET['settings-updated'] ) { ?>
		<div id="message" class="updated">
			<?php
				if( isset($_GET['deleted_cache_purged']) && $_GET['deleted_cache_purged'] == 'true' ) {
			?>
			<p><strong><?php _e('Cache for Deleted posts was purged.') ?></strong></p>
			<?php
			}
				if( isset($_GET['settings-updated']) && $_GET['settings-updated'] ) {
			?>
			<p><strong><?php _e('Settings saved.') ?></strong></p>
			<?php
			}
			?>
		</div>
		<?php
			// import feeds via AJAX but only when Save is done
			if( isset($_GET['import']) && isset($_GET['settings-updated']) && $_GET['settings-updated'] ) {
		?>
		<script type="text/javascript">
		<?php $ids = array();
			if ( is_array($this->options['feeds']) ) :
				foreach ($this->options['feeds'] as $f) :
					$ids[] = $f['id'];
				endforeach;
			endif; ?>
			if ( feeds !== undefined ) {
				feeds.set( <?php echo json_encode($ids); ?> );
			}  else {
				var feeds = <?php echo json_encode($ids); ?>;
			}
			</script>
			<?php
				}
			}

			// display an error message
			if( isset($_GET['message']) && $_GET['message'] > 1 ) { ?>
				<div id="message" class="error">
			<?php
				switch ( $_GET['message'] ) {
					case 2: { ?>
						<p><strong><?php _e('Invalid API key!', 'rss_api'); ?></strong></p>
					<?php }
				}
			?>
			</div>
			<?php
		}

		global $mb_feed_importer;

		// include the template for the ui
		include( RSS_MB_PATH . 'app/templates/admin-ui.php');
	}

	/**
	 * Add a new row for a new feed
	 */
	function add_row() {

		include( RSS_MB_PATH . 'app/templates/feed-table-row.php');
		die();
	}

	/**
	 * Generate stats data and return
	 */
	function ajax_stats() {

		include( RSS_MB_PATH . 'app/templates/stats.php');
		die();
	}

	/**
	 * Import any feeds
	 */
	function ajax_import() {
		global $mb_feed_importer;

		$this->load_options();

		// if there's nothing for processing or invalid data, bail
		if ( ! isset($_POST['feed']) ) {
			wp_send_json_error(array('message'=>'no feed provided'));
		}

		$_found = false;
		foreach ( $this->options['feeds'] as $id => $f ) {
			if ( $f['id'] == $_POST['feed'] ) {
				$_found = $id;
				break;
			}
		}
		if ( $_found === false ) {
			wp_send_json_error(array('message'=>'wrong feed id provided'));
		}

		// TODO: make this better
		if ( $_found == 0 ) {
			
			// update options
			$new_options = array(
				'feeds' => $this->options['feeds'],
				'settings' => $this->options['settings'],
				'latest_import' => $this->options['latest_import'],
				'imports' => $this->options['imports'],
				'upgraded' => $this->options['upgraded']
			);
			// update in db
			update_option('rss_mb_feeds', $new_options);
		}

		$post_count = 0;

		$f = $this->options['feeds'][$_found];

		$engine = new rssMBEngine();

		// filter cache lifetime
		add_filter('wp_feed_cache_transient_lifetime', array($engine, 'frequency'));

		// prepare, import feed and count imported posts
		if ( $items = $engine->do_import($f) ) {
			$post_count += count($items);
		}

		remove_filter('wp_feed_cache_transient_lifetime', array($engine, 'frequency'));

		if ( $items === false ) {
			// there were an wp_error doing fetch_feed
			wp_send_json_error(array('url'=>$f['url']));
		}

		// reformulate import count
		$imports = intval($this->options['imports']) + $post_count;

		// update options
		$new_options = array(
			'feeds' => $this->options['feeds'],
			'settings' => $this->options['settings'],
			'latest_import' => date("Y-m-d H:i:s"),
			'imports' => $imports,
			'upgraded' => $this->options['upgraded']
		);
		// update in db
		update_option('rss_mb_feeds', $new_options);

		global $mb_feed_importer;
		// reload options
		$mb_feed_importer->load_options();

		// log this
		rssMBLog::log($post_count);

		wp_send_json_success(array('count'=>$post_count, 'url'=>$f['url']));

	}

	/**
	 * Disable the user dropdwon for each feed
	 * 
	 * @param string $output The html of the select dropdown
	 * @return string
	 */
	function disable_user_dropdown($output) {

		// if we have a valid key we don't need to disable anything
		
		return $output;
		

		// check if this is the feed dropdown (and not any other)
		preg_match('/rss-mb-specific-feed-author/i', $output, $matched);

		// this is not our dropdown, no need to disable
		if (empty($matched)) {
			return $output;
		}

		// otherwise just disable the dropdown
		return str_replace('<select ', '<select disabled="disabled" ', $output);
	}

	/**
	 * Walker class function for category multiple checkbox
	 */
	function wp_category_checklist_rss_mb($post_id = 0, $descendants_and_self = 0, $selected_cats = false, $popular_cats = false, $walker = null, $checked_ontop = true) {

		$cat = "";
		if (empty($walker) || !is_a($walker, 'Walker'))
			$walker = new Walker_Category_Checklist;
		$descendants_and_self = (int) $descendants_and_self;
		$args = array();
		if (is_array($selected_cats))
			$args['selected_cats'] = $selected_cats;
		elseif ($post_id)
			$args['selected_cats'] = wp_get_post_categories($post_id);
		else
			$args['selected_cats'] = array();

		if ($descendants_and_self) {
			$categories = get_categories("child_of=$descendants_and_self&hierarchical=0&hide_empty=0");
			$self = get_category($descendants_and_self);
			array_unshift($categories, $self);
		} else {
			$categories = get_categories('get=all');
		}
		if ($checked_ontop) {
			// Post process $categories rather than adding an exclude to the get_terms() query to keep the query the same across all posts (for any query cache)
			$checked_categories = array();
			$keys = array_keys($categories);
			foreach ($keys as $k) {
				if (in_array($categories[$k]->term_id, $args['selected_cats'])) {
					$checked_categories[] = $categories[$k];
					unset($categories[$k]);
				}
			}
			// Put checked cats on top
			$cat = $cat . call_user_func_array(array(
						&$walker,
						'walk'
							), array(
						$checked_categories,
						0,
						$args
			));
		}
		// Then the rest of them
		$cat = $cat . call_user_func_array(array(
					&$walker,
					'walk'
						), array(
					$categories,
					0,
					$args
		));
		return $cat;
	}

	function rss_mb_tags_dropdown($fid, $seleced_tags) {

		if ($tags = get_tags(array('orderby' => 'name', 'hide_empty' => false))) {

			echo '<select name="' . $fid . '-tags_id[]" id="tag" class="postform">';

			foreach ($tags as $tag) {
				$strsel = "";
				if (!empty($seleced_tags)) {

					if ($seleced_tags[0] == $tag->term_id) {
						$strsel = "selected='selected'";
					}
				}
				echo '<option value="' . $tag->term_id . '" ' . $strsel . '>' . $tag->name . '</option>';
			}
			echo '</select> ';
		}
	}

	function rss_mb_tags_checkboxes($fid, $seleced_tags) {

		$tags = get_tags(array('hide_empty' => false));
		if ($tags) {
			$checkboxes = "<ul>";

			foreach ($tags as $tag) :
				$strsel = "";
				if (in_array($tag->term_id, $seleced_tags))
					$strsel = "checked='checked'";

				$checkboxes .=
						'<li><label for="tag-' . $tag->term_id . '">
								<input type="checkbox" name="' . $fid . '-tags_id[]" value="' . $tag->term_id . '" id="tag-' . $tag->term_id . '" ' . $strsel . ' />' . $tag->name . '
							</label></li>';
			endforeach;
			$checkboxes .= "</ul>";
			print $checkboxes;
		}
	}

}
