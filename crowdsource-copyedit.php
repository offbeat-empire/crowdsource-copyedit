<?php
/*
Plugin Name: Crowdsource Copyedit
Description: Allow site visitors to submit copyediting suggestions on posts and pages.
Version: 1.0
Author: Jennifer M. Dodd
Author URI: http://uncommoncontent.com/
License: GPLv2 or later
Text Domain: crowdsource-copyedit
Domain Path: /languages/
*/

/*  Copyright 2013  Jennifer M. Dodd  (email : jmdodd@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


if ( !defined( 'ABSPATH' ) ) exit;


if ( !class_exists( 'UCC_Crowdsource_Copyedit' ) ) {
class UCC_Crowdsource_Copyedit {
	public static $instance;
	public static $version;
	public static $plugin_dir;
	public static $plugin_url;
	public static $comment_type;
	public static $comment_approved;
	public static $comment_spam;
	public static $options;
	public static $actions;

	public function __construct() {
		self::$instance = $this;
		$this->version = '2013010723';
		
		// Useful pathinfo
		$this->plugin_dir = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		// Filter comment type/approved 
		$this->comment_type     = apply_filters( 'ucc_csce_comment_type',     'ucc-csce-copyedit' );
		$this->comment_approved = apply_filters( 'ucc_csce_comment_approved', 'ucc-csce-approved' );
		$this->comment_spam     = apply_filters( 'ucc_csce_comment_spam',     'ucc-csce-spam'     );
		
		// Default settings
		/* @todo
		$options = get_option( '_ucc_csce_options' );
		*/
		$options = false;
		if ( !$options ) {
			$options = array(
				'allow_all'          => true,
				'auto_add'           => false,
				'add_before'         => false,
				'post_types'         => array( 'post', 'page' ),
				'require'            => array(
					'copyedit_author'       => true,
					'copyedit_author_email' => true,
					'original_text'         => false
				),
				'max_characters'     => 200,
				'no-js_compat'       => false,
				'email_notification' => true,
				'email_to'           => 'copyeditor@offbeatempire.com'
			);
			/* @todo
			update_option( '_ucc_csce_options', $options );
			*/
		}
		$options = apply_filters( 'ucc_csce_options', $options );
		$this->options = $options;

		$this->actions = array(
			'delete',
		);

		// Languages
		load_plugin_textdomain( 'crowdsource-copyedit', false, basename( dirname( __FILE__ ) ) . '/languages' );

		// Filter and sanitize form submissions
		add_filter( 'ucc_csce_original_text',  'trim'                );
		add_filter( 'ucc_csce_original_text',  'balanceTags'         );
		add_filter( 'ucc_csce_original_text',  'wp_rel_nofollow'     );
		add_filter( 'ucc_csce_original_text',  'wp_filter_kses'      );
		add_filter( 'ucc_csce_edited_text',    'trim'                );
		add_filter( 'ucc_csce_edited_text',    'balanceTags'         );
		add_filter( 'ucc_csce_edited_text',    'wp_rel_nofollow'     );
		add_filter( 'ucc_csce_edited_text',    'wp_filter_kses'      );
		add_filter( 'ucc_csce_notes',          'trim'                );
		add_filter( 'ucc_csce_notes',          'balanceTags'         );
		add_filter( 'ucc_csce_notes',          'wp_rel_nofollow'     );
		add_filter( 'ucc_csce_notes',          'wp_filter_kses'      );

		/* @todo
		// Admin-side plugin settings
		if ( is_admin() )
			add_action( 'admin_init', array( $this, 'register_admin_settings' ), 15 );
		*/

		// Admin-side custom UI
		add_action( 'add_meta_boxes', array( $this, 'add_copyedit_meta_box' ) );

		// Externals
		add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		// User-side form
		if ( $options['auto_add'] )
			add_action( 'the_content', array( $this, 'auto_add' ) );

		// Regular form callback
		add_action( 'wp', array( $this, 'copyedit_handler' ), 12 );

		// Form callbacks 
		add_action( 'wp_ajax_ucc_csce_copyedit', array( $this, 'copyedit_handler' ) );
		if ( $this->options['allow_all'] )
			add_action( 'wp_ajax_nopriv_ucc_csce_copyedit', array( $this, 'copyedit_handler' ) );

		add_action( 'admin_init', array( $this, 'admin_handler' ) );
	} // __construct

	public function admin_handler() {

		$errors  = array();
		$message = '';

		// Check nonce if this is a submission, otherwise just return
		if ( isset( $_GET['csce_action'] ) )
			check_admin_referer( '_ucc_csce_admin_nonce', 'csce_nonce' );
		else
			return;

		// Set some default values
		$comment_id = 0;
		$action = '';

		// Action
		if ( isset( $_GET['csce_action'] ) && in_array( $_GET['csce_action'], $this->actions ) )
			$action = $_GET['csce_action'];
		else
			return;

		// Comment id
		if ( isset( $_GET['csce_comment_id'] ) ) {
			$comment_id = absint( $_GET['csce_comment_id'] );
			$comment    = get_comment( $comment_id );
			if ( !$comment ) {
				$comment_id  = 0;
			}
		}

		if ( empty( $comment ) )
			return;

		// Cap check
		if ( !current_user_can( 'edit_post', $comment->comment_post_ID ) )
			return;

		wp_delete_comment( $comment_id, true );
	}

	public function add_copyedit_meta_box() {
		if ( !current_user_can( 'edit_posts' ) )
			return;

		$post_types = $this->options['post_types'];
		foreach ( (array) $post_types as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				add_meta_box(
					'ucc_csce_metabox',
					__( 'Copyedits', 'crowdsource-copyedit' ),
					array( &$this, 'copyedit_meta_box_cb' ),
					$post_type,
					'normal',
					'high'
				);
			}
		}
	}

	public function copyedit_meta_box_cb() {
		global $post;

		// Setup postdata
		$post_id = $post->ID;
		$post_type_object = get_post_type_object( $post->post_type );

		// Default args 
		$args = array(
			'post_id'      => $post_id,
			'comment_type' => $this->comment_type
		);

		// Approved copyedits
		$approved_comments = get_comments( array_merge(
			$args, array( 'status' => $this->comment_approved )
		) );

		// Spam copyedits
		$spam_comments     = get_comments( array_merge(
			$args, array( 'status' => $this->comment_spam )
		) ); ?>

		<table class="widefat fixed">

		<?php if ( empty( $approved_comments ) && empty( $spam_comments ) ) : ?>

		<tr>
		<td><p><?php esc_html_e( 'No copyedits found.', 'crowdsource-copyedit' ); ?></p></td>
		<tr>

		<?php else :

			wp_list_comments( array(
				'type' => $this->comment_type,
				'callback' => array( $this, 'the_comment' )
			), $approved_comments );

			wp_list_comments( array(
				'type' => $this->comment_type,
				'callback' => array( $this, 'the_comment' )
			), $spam_comments );

		endif; ?>

		</table>

		<?php
	}

	public function the_comment( $comment, $args, $depth ) {
		$original_text = get_comment_meta( $comment->comment_ID, '_ucc_csce_original_text', true );
		$notes         = get_comment_meta( $comment->comment_ID, '_ucc_csce_notes', true );
		?>

		<tr class="comment">
		<td class="author column-author">

			<strong><?php echo get_avatar( $comment->comment_author_email, 32 ); ?>
			<?php echo comment_author_email_link( $comment->comment_author ); ?>
			</strong>

		</td>
		<td class="comment column-comment">

			<div class="submitted-on">
			<?php printf( __( "Submitted on %s at %s", 'crowdsource-copyedit' ), get_comment_date( get_option( 'date_format' ) ), get_comment_time() ); ?>
			</div><!-- .submitted-on -->

			<?php if ( !empty( $original_text ) ) : ?>

				<h4><?php _e( 'Original text:', 'crowdsource-copyedit' ); ?></h4>

				<div class="original-text">
				<?php echo apply_filters( 'comment_text', $original_text ); ?>
				</div><!-- .original-text -->

			<?php endif; ?>

			<h4><?php _e( 'Copyedit suggestion:', 'crowdsource-copyedit' ); ?></h4>

				<div class="edited-text">
				<?php comment_text(); ?>
				</div><!-- .edited-text -->

			<?php if ( !empty( $notes ) ) : ?> 

				<h4><?php _e( 'Notes:', 'crowdsource-copyedit' ); ?></h4>

				<div class="notes">
				<?php echo apply_filters( 'comment_text', $notes ); ?>
				</div><!-- .notes -->

			<?php endif; ?>

			<p><a href="<?php echo esc_url( $this->generate_delete_url( $comment->comment_ID ) ); ?>">Delete Copyedit</a></p>

		</td>
		</tr>

		<?php
	}

	public function copyedit_handler() {

		// Bail if not a POST action
		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) )
			return;

		// Check for our form
		if ( !isset( $_POST['ucc_csce'] ) )
			return;

		/* @todo
		// Banned IP check
		*/

		// Define local variables
		$comment_post_ID = $comment_id = $user_id = 0;
		$comment_author = $comment_author_email = $comment_author_IP = '';
		$original_text = $edited_text = $notes = '';
		$errors = array();

		/** Reporter Details ******************************************/
		// Is logged in
		if ( is_user_logged_in() ) {
			$current_user         = wp_get_current_user();
			$user_id              = get_current_user_id(); 
			$comment_author       = empty( $current_user->display_name ) ? esc_sql( $current_user->display_name ) : esc_sql( $current_user->user_login );
			$comment_author_email = esc_sql( $current_user->user_email   );
			$comment_author_url   = esc_sql( $current_user->user_url     );

		// Visitor
		} elseif ( $this->options['allow_all'] ) {

			// Name
			$user_id = get_current_user_id();
			if ( isset( $_POST['ucc_csce']['copyedit_author'] ) )
				$comment_author = apply_filters( 'ucc_csce_copyedit_author', trim( strip_tags( $_POST['ucc_csce']['copyedit_author'] ) ) );

			if ( $this->options['require']['copyedit_author'] )
				if ( empty( $comment_author ) )
					$errors[] = __( 'Reporter name is required.', 'crowdsource-copyedit' );

			// Common sense name checks
			if ( strlen( $comment_author ) > 30 )
				$errors[] = __( 'Reporter name is too long.', 'crowdsource-copyedit' );
			if ( strip_tags( $comment_author ) != $comment_author )
				$errors[] = __( 'Reporter name is invalid.', 'crowdsource-copyedit' );

			// Email address
			if ( isset( $_POST['ucc_csce']['copyedit_author_email'] ) )
				$comment_author_email = apply_filters( 'ucc_csce_copyedit_author_email', trim( sanitize_email( $_POST['ucc_csce']['copyedit_author_email'] ) ) );

			if ( $this->options['require']['copyedit_author_email'] )
				if ( empty( $comment_author_email ) )
					$errors[] = __( 'Reporter email is required.', 'crowdsource-copyedit' );

			// URL
			$comment_author_url = '';

		// No permission
		} else {
			$errors[] = __( 'You do not have permission to make a copyedit report.', 'crowdsource-copyedit' );
		}

		/** Original Text *********************************************/

		if ( !empty( $_POST['ucc_csce']['original_text'] ) ) {
			$original_text = $_POST['ucc_csce']['original_text'];

			// Filter and sanitize
			if ( strlen( $original_text ) > $this->options['max_characters'] )
				$errors[] = sprintf( __( 'The original text entered must be less than %d characters.', 'crowdsource-copyedit' ), esc_html( $this->options['max_characters'] ) );
			$original_text = apply_filters( 'ucc_csce_original_text', $original_text );

		// Maybe required
		} else {
			if ( $this->options['require']['original_text'] )
				$errors[] = __( 'You did not include the original text.', 'crowdsource-copyedit' );
		}

		/** Edited Text	***********************************************/

		if ( !empty( $_POST['ucc_csce']['edited_text'] ) ) {
			$edited_text = $_POST['ucc_csce']['edited_text'];

			// Filter and sanitize
			if ( strlen( $edited_text ) > $this->options['max_characters'] )
				$errors[] = sprintf( __( 'The edited text entered must be less than %d characters.', 'crowdsource-copyedit' ), esc_html( $this->options['max_characters'] ) );
			$edited_text = apply_filters( 'ucc_csce_edited_text', $edited_text );			

		// Required
		} else {
			$errors[] = __( 'You did not make any corrections.', 'crowdsource-copyedit' );
		}

		if ( $original_text == $edited_text )
			$errors[] = __( 'You have not made a copyedit change.', 'crowdsource-copyedit' );

		/** Notes *****************************************************/
		
		if ( !empty( $_POST['ucc_csce']['notes'] ) ) {
			$notes = $_POST['ucc_csce']['notes'];

			// Filter and sanitize
			if ( strlen( $notes ) > $this->options['max_characters'] )
				$errors[] = sprintf( __( 'The notes entered must be less than %d characters.', 'crowdsource-copyedit' ), esc_html( $this->options['max_characters'] ) );
			$notes = apply_filters( 'ucc_csce_notes', $notes );
		}

		/** Post ID ***************************************************/

		if ( isset( $_POST['ucc_csce']['post_id'] ) ) {
			$comment_post_ID = (int) $_POST['ucc_csce']['post_id'];

		// Required
		} else {
			$errors[] = __( 'No post ID specified.', 'crowdsource-copyedit' );
		}

		/** Comment Data **********************************************/

		$comment_data = array(
			'comment_post_ID'      => $comment_post_ID,
			'comment_author'       => $comment_author,
			'comment_author_email' => $comment_author_email,
			'comment_author_url'   => $comment_author_url,
			'comment_content'      => $edited_text,
			'comment_type'         => $this->comment_type,
			'comment_parent'       => 0,
			'user_id'              => $user_id
		);

		/** Insert Comment ********************************************/

		if ( empty( $errors ) ) {
			$comment_data = array_merge( $comment_data, array(
				'comment_author_IP' => preg_replace( '/[^0-9a-fA-F:., ]/', '',$_SERVER['REMOTE_ADDR'] ),
				'comment_agent'     => substr($_SERVER['HTTP_USER_AGENT'], 0, 254),
				'comment_date'      => current_time('mysql'),
				'comment_date_gmt'  => current_time('mysql', 1)
			) );

			switch ( wp_allow_comment( $comment_data ) ) {
				case 'spam':
					$comment_data['comment_approved'] = $this->comment_spam;
				case 0:
				case 1:
				default:
					$comment_data['comment_approved'] = $this->comment_approved;
			}

			// Filter and insert comment
			$comment_data = wp_filter_comment( $comment_data );
			$comment_id   = wp_insert_comment( $comment_data );

			if ( $comment_id ) {

				// Add comment meta for notes and original text
				if ( !empty( $original_text ) )
					update_comment_meta( $comment_id, '_ucc_csce_original_text', $original_text );
				if ( !empty( $notes ) )
					update_comment_meta( $comment_id, '_ucc_csce_notes',         $notes         );

			} else {
				$errors[] = __( 'Unable to add copyedit.', 'crowdsource-copyedit' );
			}

			/** Notification **************************************/

			if ( empty( $errors ) && $this->options['email_notification'] && !empty( $this->options['email_to'] ) ) {
				$to      = $this->options['email_to'];
				$subject = sprintf( __( "[Crowdsource Copyedit]: New copyedit on '%s'", 'crowdsource-copyedit' ), get_the_title( $comment_post_ID ) );
				$message = sprintf( __( "A new copyedit suggestion has been posted for <a href=\"%s\">%s</a>. <a href=\"%s\">Edit this post.</a>", 'crowdsource-copyedit' ),
					get_permalink(      $comment_post_ID ),
					get_the_title(      $comment_post_ID ),
					get_edit_post_link( $comment_post_ID, '' )
				);
				$message .= '<p>' . esc_html__( "Reporter name: ", 'crowdsource-copyedit' ) . $comment_author . '<br />';
				$message .= esc_html__( "Reporter email: ", 'crowdsource-copyedit' ) . $comment_author_email . '</p>';
				$message .= '<p><strong>' . esc_html__( "Original text:" ) . '</strong><br />' . esc_html( $original_text ) . '</p>';
				$message .= '<p><strong>' . esc_html__( "Copyedit suggestion:" ) . '</strong><br />' . esc_html( $edited_text ) . '</p>';
				$message .= '<p><strong>' . esc_html__( "Notes:" ) . '</strong><br />' . esc_html( $notes ) . '</p>';

				add_filter( 'wp_mail_content_type', array( $this, 'wp_mail_content_type' ) );
				wp_mail( $to, $subject, $message );
				remove_filter( 'wp_mail_content_type', array( $this, 'wp_mail_content_type' ) );
			}

			$retval = array(
				'success' => true,
				'message' => '<p class="success">' . __( 'Thank you for your copyedit submission.', 'crowdsource-copyedit' ) . '</p>'
			);

		/** Errors ****************************************************/

		} else {
			$retval = array(
				'success' => false,
				'errors'  => '<ul class="errors"><li>' . implode( "</li>\n<li>", $errors ) . '</li></ul>'
			);
		}

		/** JSON response *********************************************/
		echo json_encode( $retval );
		die;
	}

	public function wp_mail_content_type( $content_type ) {
		return 'text/html';
	}

	public function admin_enqueue_scripts() {
		wp_enqueue_style(
			'crowdsource-copyedit',
			$this->plugin_url . 'css/crowdsource-copyedit-admin.css',
			false,
			$this->version
		);
	}

	public function enqueue_scripts() {
		if ( is_single() ) {
			$nonce = wp_create_nonce( '_ucc_csce_nonce' );
			wp_enqueue_script(
				'crowdsource-copyedit',
				$this->plugin_url . 'js/crowdsource-copyedit.js',
				array(
					'jquery',
					'jquery-ui-dialog'
				),
				$this->version
			);
			wp_localize_script( 
				'crowdsource-copyedit', 
				'ucc_csce_localizations', 
				array( 
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce' => $nonce
				)
			);
			wp_enqueue_style(
				'crowdsource-copyedit',
				$this->plugin_url . 'css/crowdsource-copyedit.css',	
				false,
				$this->version
			);
		}
	}

	public function generate_form( $post_id = 0 ) {
		global $post;

		if ( !$post_id )
			$post_id = $post->ID;

		if ( !$post_id )
			return;

		$form = array();

		$form[] = '<div style="display: none;" id="ucc-csce-copyedit-wrapper" title="' . esc_attr__( 'Suggest a Copyedit', 'crowdsource-copyedit' ) . '">';
		$form[] = '<div id="ucc-csce-copyedit-messages"></div>';
		$form[] = '<form id="ucc-csce-new-copyedit" method="post" action="">';
		$form[] = '<fieldset>';
		if ( !is_user_logged_in() ) {

			// Copyedit author
			$form[] = '<label for="ucc_csce[copyedit_author]">' . esc_html__( 'Reporter Name', 'crowdsource-copyedit' );
			if ( $this->options['require']['copyedit_author'] )
				$form[] = '<span class="required">*</span>';
			$form[] = '</label>';
			$form[] = '<input type="text" name="ucc_csce[copyedit_author]" id="ucc-csce-copyedit-author" /><br />';

			// Copyedit author email
		 	$form[] = '<label for="ucc_csce[copyedit_author_email]">' . esc_html__( 'Reporter Email', 'crowdsource-copyedit' );
			if ( $this->options['require']['copyedit_author_email'] )
				$form[] = '<span class="required">*</span>';
			$form[] = '</label>';
			$form[] = '<input type="text" name="ucc_csce[copyedit_author_email]" id="ucc-csce-copyedit-author-email" /><br />';
		}

		// Original text
		$form[] = '<label for="ucc_csce[original_text]">' . esc_html__( 'Original text', 'crowdsource-copyedit' ) . '</label><br />';
		$form[] = '<span class="help">' . esc_html__( 'Enter the original text here.', 'crowdsource-copyedit' ) . '</span><br />';
		$form[] = '<textarea name="ucc_csce[original_text]" rows="3" cols="40" id="ucc-csce-original-text"></textarea><br />';

		// Edited text
		$form[] = '<label for="ucc_csce[edited_text]">' . esc_html__( 'Edited text', 'crowdsource-copyedit' ) . '<span class="required">*</span></label><br />';
		$form[] = '<span class="help">' . esc_html__( 'Enter your suggested copyedit here.', 'crowdsource-copyedit' ) . '</span><br />';
		$form[] = '<textarea name="ucc_csce[edited_text]" rows="3" cols="40" id="ucc-csce-edited-text"></textarea><br />';

		// Notes
		$form[] = '<label for="ucc_csce[notes]">' . esc_html__( 'Notes', 'crowdsource-copyedit' ) . '</label><br />';
		$form[] = '<span class="help">' . esc_html__( 'You can add a note for the editor here.', 'crowdsource-copyedit' ) . '</span><br />';
		$form[] = '<textarea name="ucc_csce[notes]" rows="2" cols="40" id="ucc-csce-notes"></textarea></p>';

		$form[] = '<input type="hidden" name="ucc_csce[post_id]" value="' . esc_attr__( $post_id ) . '" id="ucc-csce-post-id" />';


		$form[] = '<span class="required">*</span> ' . esc_html__( 'Required information.', 'crowdsource-copyedit' );
		$form[] = '</fieldset>';
		$form[] = '</form>';
		$form[] = '</div>';

		$form[] = '<button class="menu-toggle" id="ucc-csce-copyedit" style="display: none;">' . esc_html__( 'Suggest a copyedit', 'crowdsource-copyedit' ) . '</button>';

		$form = implode( "\n", $form );
		return $form;
	}

	public function generate_delete_url( $comment_id = 0 ) {
		$comment = get_comment( $comment_id );
		if ( !$comment )
			return false;

		$url = add_query_arg(
			array(
				'csce_action'        => 'delete',
				'csce_nonce'         => wp_create_nonce( '_ucc_csce_admin_nonce' ),
				'csce_comment_id'    => $comment->comment_ID,
			),
			get_edit_post_link( $comment->comment_post_ID )
		);
		return $url;
	}

	// Prepend or append copyedit submission form to the_content
	public function auto_add( $content ) {
		global $post;
		$options = $this->options;

		// Post exists
		if ( $post && is_singular( $options['post_types'] ) ) {
			$post_id = $post->ID;
			$post_type = $post->post_type;

			// Only deal with some post types
			if ( in_array( $post_type, $options['post_types'] ) ) {
				$form = $this->generate_form( $post_id );

				// Return the content and form
				if ( $this->options['add_before'] )
					return $form . $content;
				else
					return $content . $form;
			}
		}

		return $content;
	}
} }


/**
 * Template function
 */
function ucc_csce_form( $post_id = 0, $echo = true ) {
	global $post;

	if ( empty( $post_id ) )
		$post_id = $post->ID;
	if ( empty( $post_id ) )
		return;

	$instance = new UCC_Crowdsource_Copyedit;
	$form = $instance->generate_form( $post_id );

	if ( $echo )
		echo $form;
	else
		return $form;
}

// Load plugin
if ( !function_exists( 'ucc_csce_loader' ) ) {
function ucc_csce_loader() {
	new UCC_Crowdsource_Copyedit;
} }
add_action( 'init', 'ucc_csce_loader' );
