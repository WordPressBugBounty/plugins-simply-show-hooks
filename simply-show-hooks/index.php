<?php
/*
Plugin Name: Simply Show Hooks
Plugin URI: https://www.calyxagency.com/#plugins
Description: Simply Show Hooks helps theme or plugin developers to quickly see where all the action and filter hooks are on any WordPress page.
Version: 1.3.0
Contributors: stuartobrien, cxthemes
Author: Stuart O'Brien, cxThemes
Author URI: https://www.calyxagency.com/#plugins
License: GPLv2 or later
Text Domain: simply-show-hooks
*/

defined( 'ABSPATH' ) || exit;

class CX_Simply_Show_Hooks {
	
	private $status;
	
	private $all_hooks = array();
	
	private $recent_hooks = array();
	
	private $ignore_hooks = array();
	
	private $doing = 'collect';
	

	/**
	*  Instantiator
	*/
	public static function get_instance() {
		
		static $instance = null;
		
		if ( null === $instance ) {
			$instance = new self();
			$instance->init();
		}
		
		return $instance;
	}
	
	/**
	 * Construct and initialize the main plugin class
	 */
	
	public function __construct() {}
	
	function init() {
		
		// Use this to set any tags known to cause display problems.
		// Will be display in sidebar.
		$this->ignore_hooks = apply_filters( 'simply_show_hooks_ignore_hooks', array(
			'attribute_escape',
			'body_class',
			'the_post',
			'post_edit_form_tag',
			//'gettext',
		) );

		// Cookie default and pluggable helpers are available after plugins load.
		add_action( 'plugins_loaded', array( $this, 'bootstrap_status' ), 0 );

		// Capabilities are reliable on init; attach runtime hooks only for allowed users.
		add_action( 'init', array( $this, 'bootstrap_hooks' ), 0 );

		// Init the plugin.
		add_action( 'init', array( $this, 'plugin_init' ) );
	}

	/**
	 * Allowed hook display status values.
	 *
	 * @return string[]
	 */
	private function get_allowed_statuses() {
		return array( 'off', 'show-action-hooks', 'show-filter-hooks' );
	}

	/**
	 * Whether the current user may use hook display features.
	 *
	 * @return bool
	 */
	private function user_can_use() {
		return current_user_can( 'manage_options' ) && $this->plugin_active();
	}

	/**
	 * Read persisted status from cookie once pluggable helpers are available.
	 */
	public function bootstrap_status() {
		if ( isset( $this->status ) ) {
			return;
		}

		if ( ! isset( $_COOKIE['cxssh_status'] ) ) {
			$this->set_status_cookie( 'off' );
		}

		if ( isset( $_COOKIE['cxssh_status'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_COOKIE['cxssh_status'] ) );
			$this->status = in_array( $status, $this->get_allowed_statuses(), true ) ? $status : 'off';
		} else {
			$this->status = 'off';
		}
	}

	/**
	 * Apply toggle requests and attach runtime hooks for authorized users only.
	 */
	public function bootstrap_hooks() {
		if ( isset( $_REQUEST['cxssh-hooks'] ) && isset( $_REQUEST['_wpnonce'] ) && $this->user_can_use() ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
			if ( wp_verify_nonce( $nonce, 'cxssh-toggle-hooks' ) ) {
				$status = sanitize_text_field( wp_unslash( $_REQUEST['cxssh-hooks'] ) );
				if ( in_array( $status, $this->get_allowed_statuses(), true ) ) {
					$this->set_status_cookie( $status );
					$this->status = $status;
				}
			}
		}

		if ( ! $this->user_can_use() ) {
			$this->status = 'off';
			$this->detach_hooks();
			return;
		}

		$this->attach_hooks();
	}
	
	/**
	 * Persist the hook display preference cookie.
	 *
	 * @param string $status Hook display status.
	 */
	private function set_status_cookie( $status ) {
		setcookie(
			'cxssh_status',
			$status,
			time() + ( DAY_IN_SECONDS * 100 ),
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	/**
	 * Build a nonce-protected URL for toggling hook display.
	 *
	 * @param string $status Hook display status.
	 * @param string $url    Base URL.
	 * @return string
	 */
	private function get_toggle_url( $status, $url = '' ) {
		if ( '' === $url ) {
			$url = remove_query_arg( 'cxssh-hooks' );
		}
		return wp_nonce_url( add_query_arg( 'cxssh-hooks', $status, $url ), 'cxssh-toggle-hooks' );
	}
	
	/**
	 * Helper function to attach the filter that render all the hook labels.
	 */
	public function attach_hooks() {
		if ( ! $this->user_can_use() ) {
			return;
		}

		if ( 'show-action-hooks' === $this->status || 'show-filter-hooks' === $this->status ) {
			
			add_filter( 'all', array( $this, 'hook_all_hooks' ), 100 );
			add_action( 'shutdown', array( $this, 'notification_switch' ) );
			add_action( 'shutdown', array( $this, 'filter_hooks_panel' ) );
		}
	}
	
	/**
	 * Helper function to detach the filter that render all the hook labels.
	 */
	public function detach_hooks() {
		
		remove_filter( 'all', array( $this, 'hook_all_hooks' ), 100 );
		remove_action( 'shutdown', array( $this, 'notification_switch' ) );
		remove_action( 'shutdown', array( $this, 'filter_hooks_panel' ) );
	}
	
	
	/*
	 * Admin Menu top bar
	 */
	function admin_bar_menu( $wp_admin_bar ) {
		
		// Suspend the hooks rendering.
		$this->detach_hooks();
		
		// Setup a base URL and clear it of the intial `cxssh-hooks` arg.
		$url = remove_query_arg( 'cxssh-hooks' );
		
		if ( 'show-action-hooks' == $this->status ) {
			
			$title 	= __( 'Stop Showing Action Hooks' , 'simply-show-hooks' );
			$href 	= $this->get_toggle_url( 'off', $url );
			$css 	= 'cxssh-hooks-on cxssh-hooks-normal';
		}
		else {
			
			$title 	= __( 'Show Action Hooks' , 'simply-show-hooks' );
			$href 	= $this->get_toggle_url( 'show-action-hooks', $url );
			$css 	= '';
		}
		
		$wp_admin_bar->add_menu( array(
			'title'		=> '<span class="ab-icon"></span><span class="ab-label">' . __( 'Simply Show Hooks' , 'simply-show-hooks' ) . '</span>',
			'id'		=> 'cxssh-main-menu',
			'parent'	=> false,
			'href'		=> $href,
		) );
		
		$wp_admin_bar->add_menu( array(
			'title'		=> $title,
			'id'		=> 'cxssh-simply-show-hooks',
			'parent'	=> 'cxssh-main-menu',
			'href'		=> $href,
			'meta'		=> array( 'class' => $css ),
		) );
		
		
		if ( $this->status=="show-filter-hooks" ) {
			
			$title	= __( 'Stop Showing Action & Filter Hooks' , 'simply-show-hooks' );
			$href 	= $this->get_toggle_url( 'off', $url );
			$css 	= 'cxssh-hooks-on cxssh-hooks-sidebar';
		}
		else {
			
			$title	= __( 'Show Action & Filter Hooks' , 'simply-show-hooks' );
			$href 	= $this->get_toggle_url( 'show-filter-hooks', $url );
			$css 	= '';
		}
		
		$wp_admin_bar->add_menu( array(
			'title'		=> $title,
			'id'		=> 'cxssh-show-all-hooks',
			'parent'	=> 'cxssh-main-menu',
			'href'		=> $href,
			'meta'		=> array( 'class' => $css ),
		) );
		
		// De-suspend the hooks rendering.
		$this->attach_hooks();
	}
	
	// Custom css to add icon to admin bar edit button.
	function add_builder_edit_button_css() {
		?>
		<style>
		#wp-admin-bar-cxssh-main-menu .ab-icon:before{
			font-family: "dashicons" !important;
			content: "\f323" !important;
			font-size: 16px !important;
		}
		</style>
		<?php
	}

	/*
	 * Notification Switch
	 * Displays notification interface that will alway display
	 * even if the interface is corrupted in other places.
	 */
	function notification_switch() {
		
		// Suspend the hooks rendering.
		$this->detach_hooks();
		
		// Setup a base URL and clear it of the intial `cxssh-hooks` arg.
		$url = $this->get_toggle_url( 'off' );
		?>
		<a class="cxssh-notification-switch" href="<?php echo esc_url( $url ); ?>">
			<span class="cxssh-notification-indicator"></span>
			<?php esc_html_e( 'Stop Showing Hooks' , 'simply-show-hooks' ); ?>
		</a>
		<?php
		
		// De-suspend the hooks rendering.
		$this->attach_hooks();
	}
	
	function plugin_init() {
		if ( ! $this->user_can_use() ) {
			$this->status = 'off';
			$this->detach_hooks();
			return;
		}
		
		// Enqueue Scripts/Styles - in head of admin
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_script' ) );
		
		// Top Admin Bar
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu'), 90 );
		// Top Admin Bar Styles
		add_action( 'wp_print_styles', array( $this, 'add_builder_edit_button_css' ) );
		add_action( 'admin_print_styles', array( $this, 'add_builder_edit_button_css' ) );
		
		if ( $this->status == 'show-action-hooks' || $this->status == 'show-filter-hooks' ) {
			
			//Final hook - render the nested action array
			add_action( 'admin_head', array( $this, 'render_head_hooks'), 100 ); // Back-end - Admin
			add_action( 'wp_head', array( $this, 'render_head_hooks'), 100 ); // Front-end
			add_action( 'login_head', array( $this, 'render_head_hooks'), 100 ); // Login
			add_action( 'customize_controls_print_scripts', array( $this, 'render_head_hooks'), 100 ); // Customizer
		}
	}
	
	/**
	 * Enqueue Scripts
	 */
	
	public function enqueue_script() {
		wp_register_style(
			'cxssh-main-css',
			plugins_url( 'assets/css/cxssh-main.css', __FILE__ ),
			array(),
			'1.1.0',
			'screen'
		);
		wp_enqueue_style( 'cxssh-main-css' );
	}
	
	/**
	 * Render Head Hooks
	 */
	function render_head_hooks() {
		
		// Render all the hooks so far
		$this->render_hooks();
		
		// Add header marker to hooks collection
		// $this->all_hooks[] = array( 'End Header. Start Body', false, 'marker' );
		
		// Change to doing 'write' which will write the hook as it happens
		$this->doing = 'write';
	}
	
	/**
	 * Render all hooks already in the collection
	 */
	function render_hooks() {
		
		foreach ( $this->all_hooks as $nested_value ) {
			
			if ( 'action' == $nested_value['type'] ) {
				
				$this->render_action( $nested_value );
			}
		}
	}
	
	/**
	 * Hook all hooks
	 */
	
	public function hook_all_hooks( $hook ) {
		global $wp_actions, $wp_filter;
		
		if ( ! in_array( $hook, $this->recent_hooks ) ) {
			
			if ( isset( $wp_actions[$hook] ) ) {
				
				// Action
				$this->all_hooks[] = array(
					'ID'       => $hook,
					'callback' => false,
					'type'     => 'action',
				);
			}
			else {
				
				// Filter
				$this->all_hooks[] = array(
					'ID'       => $hook,
					'callback' => false,
					'type'     => 'filter',
				);
			}
		}
		
		// if ( isset( $wp_actions[$hook] ) && $wp_actions[$hook] == 1 && !in_array( $hook, $this->ignore_hooks ) ) {
		// if (  ( isset( $wp_actions[$hook] ) || isset( $wp_filter[$hook] ) ) && !in_array( $hook, $this->ignore_hooks ) ) {
		if ( isset( $wp_actions[$hook] ) && !in_array( $hook, $this->recent_hooks ) && !in_array( $hook, $this->ignore_hooks ) ) {
			
			if ( 'write' == $this->doing ) {
				$this->render_action( end( $this->all_hooks ) );
			}
		}
		else{
			// s('(skiped-hook!)');
			// $this->render_action( $hook );
		}
		
		// Discarded functionality: if the hook was
		// run recently then don't show it again.
		// Better to use the once run or always run theory.
		
		$this->recent_hooks[] = $hook;
		
		if ( count( $this->recent_hooks ) > 100 ) {
			array_shift( $this->recent_hooks );
		}
	}
	
	/**
	 *
	 * Render action
	 */
	function render_action( $args = array() ) {
		global $wp_filter;
		
		// Get all the nested hooks
		$nested_hooks = ( isset( $wp_filter[ $args['ID'] ] ) ) ? $wp_filter[ $args['ID'] ] : false ;
		
		// Count the number of functions on this hook
		$nested_hooks_count = 0;
		if ( $nested_hooks ) {
			foreach ($nested_hooks as $key => $value) {
				$nested_hooks_count += count($value);
			}
		}
		?>
		<span style="display:none;" class="cxssh-hook cxssh-hook-<?php echo esc_attr( $args['type'] ); ?> <?php echo ( $nested_hooks ) ? 'cxssh-hook-has-hooks' : ''; ?>" >
			
			<?php
			if ( 'action' == $args['type'] ) {
				?>
				<span class="cxssh-hook-type cxssh-hook-type">A</span>
				<?php
			}
			else if ( 'filter' == $args['type'] ) {
				?>
				<span class="cxssh-hook-type cxssh-hook-type">F</span>
				<?php
			}
			?>
			
			<?php
			
			// Main - Write the action hook name.
			echo esc_html( $args['ID'] );
			
			// Write the count number if any function are hooked.
			if ( $nested_hooks_count ) {
				?>
				<span class="cxssh-hook-count">
					<?php echo esc_html( $nested_hooks_count ); ?>
				</span>
				<?php
			}
			
			// Write out list of all the function hooked to an action.
			if ( isset( $wp_filter[$args['ID']] ) ):
				
				$nested_hooks = $wp_filter[$args['ID']];
				
				if ( $nested_hooks ):
					?>
					<ul class="cxssh-hook-dropdown">
						
						<li class="cxssh-hook-heading">
							<strong><?php echo esc_html( $args['type'] ); ?>:</strong> <?php echo esc_html( $args['ID'] ); ?>
						</li>
						
						<?php
						foreach ( $nested_hooks as $nested_key => $nested_value ) :
							
							// Show the priority number if the following hooked functions
							?>
							<li class="cxssh-priority">
								<span class="cxssh-priority-label"><strong><?php esc_html_e( 'Priority:', 'simply-show-hooks' ); ?></strong> <?php echo esc_html( $nested_key ); ?></span>
							</li>
							<?php
							
							foreach ( $nested_value as $nested_inner_key => $nested_inner_value ) :
								
								// Show all teh functions hooked to this priority of this hook
								?>
								<li>
									<?php
									if ( $nested_inner_value['function'] && is_array( $nested_inner_value['function'] ) && count( $nested_inner_value['function'] ) > 1 ):
										
										// Hooked function ( of type object->method() )
										?>
										<span class="cxssh-function-string">
											<?php
											$classname = false;
											
											if ( is_object( $nested_inner_value['function'][0] ) || is_string( $nested_inner_value['function'][0] ) ) {
												
												if ( is_object( $nested_inner_value['function'][0] ) ) {
													$classname = get_class($nested_inner_value['function'][0] );
												}
												
												if ( is_string( $nested_inner_value['function'][0] ) ) {
													$classname = $nested_inner_value['function'][0];
												}
												
												if ( $classname ) {
													echo esc_html( $classname );
													?>&ndash;&gt;<?php
												}
											}
											echo esc_html( $nested_inner_value['function'][1] );
											?>
										</span>
										<?php
									else :
										
										// Hooked function ( of type function() )
										?>
										<span class="cxssh-function-string">
											<?php echo esc_html( $nested_inner_key ); ?>
										</span>
										<?php
									endif;
									?>
									
								</li>
								<?php
								
							endforeach;
							
						endforeach;
						?>
						
					</ul>
					<?php
				endif;
				
			endif;
			?>
		</span>
		<?php
	}
	
	/*
	 * Filter Hooks Panel
	 */
	function filter_hooks_panel() {
		global $wp_filter, $wp_actions;
		?>
		<div class="cxssh-nested-hooks-block <?php echo ( 'show-filter-hooks' === $this->status ) ? 'cxssh-active' : ''; ?>">
			<?php
			foreach ( $this->all_hooks as $va_nested_value ) {
				
				if ( 'action' == $va_nested_value['type'] || 'filter' == $va_nested_value['type'] ) {
					$this->render_action( $va_nested_value );
				}
				else{
					?>
					<div class="cxssh-collection-divider">
						<?php echo esc_html( $va_nested_value['ID'] ); ?>
					</div>
					<?php
				}
				
				/*
				?>
				<div class="va-action">
					<?php echo $va_nested_value ?>
				</div>
				<?php
				*/
			}
			?>
		</div>
		<?php
	}
	
	function plugin_active() {
		
		// Filters to deactivate our plugin - backend, frontend or sitewide.
		// add_filter( 'simply_show_hooks_active', '__return_false' );
		// add_filter( 'simply_show_hooks_backend_active', '__return_false' );
		// add_filter( 'simply_show_hooks_frontend_active', '__return_false' );
		
		if ( ! apply_filters( 'simply_show_hooks_active', TRUE ) ) {
			
			// Sitewide.
			return FALSE;
		}
		
		if ( is_admin() ) {
			
			// Backend.
			if ( ! apply_filters( 'simply_show_hooks_backend_active', TRUE ) ) return FALSE;
		}
		else {
			
			// Frontend.
			if ( ! apply_filters( 'simply_show_hooks_frontend_active', TRUE ) ) return FALSE;
		}
		
		return TRUE;
	}
}

CX_Simply_Show_Hooks::get_instance();
