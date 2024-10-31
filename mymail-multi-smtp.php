<?php
/*
Plugin Name: MyMail Multi SMTP
Plugin URI: https://evp.to/mymail?utm_campaign=wporg&utm_source=Multi+SMTP+for+MyMail
Description: Allows to use multiple SMTP connection for the MyMail Newsletter Plugin
Version: 0.2.1
Author: EverPress
Author URI: https://everpress.co

License: GPLv2 or later
*/


define( 'MYMAIL_MULTISMTP_VERSION', '0.2.1' );
define( 'MYMAIL_MULTISMTP_REQUIRED_VERSION', '1.3.2' );
define( 'MYMAIL_MULTISMTP_DOMAIN', 'mymail-multismtp' );


class MyMailMultiSMTP {

	private $plugin_path;
	private $plugin_url;

	/**
	 *
	 */
	public function __construct() {

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url  = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );

		load_plugin_textdomain( 'mymail-multismtp', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function activate( $network_wide ) {

		if ( function_exists( 'mymail' ) ) {

			mymail_notice( sprintf( __( 'Change the delivery method on the %s!', 'mymail-multismtp' ), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', false, 'delivery_method' );

			$defaults = array(
				'multismtp_current'       => 0,
				'multismtp_campaignbased' => false,
				'multismtp'               =>
				array(
					array(
						'active'      => true,
						'send_limit'  => mymail_option( 'send_limit', 10000 ),
						'send_period' => mymail_option( 'send_period', 24 ),
						'host'        => mymail_option( 'smtp_host' ),
						'port'        => mymail_option( 'smtp_port', 25 ),
						'timeout'     => mymail_option( 'smtp_timeout', 10 ),
						'secure'      => mymail_option( 'smtp_secure' ),
						'auth'        => mymail_option( 'smtp_auth' ),
						'user'        => mymail_option( 'smtp_user' ),
						'pwd'         => mymail_option( 'smtp_pwd' ),
					),
				),
			);

			$mymail_options = mymail_options();

			foreach ( $defaults as $key => $value ) {
				if ( ! isset( $mymail_options[ $key ] ) ) {
					mymail_update_option( $key, $value );
				}
			}
		}

	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function deactivate( $network_wide ) {

		if ( function_exists( 'mymail' ) ) {

			if ( mymail_option( 'deliverymethod' ) == 'multismtp' ) {
				mymail_update_option( 'deliverymethod', 'simple' );
				mymail_notice( sprintf( __( 'Change the delivery method on the %s!', 'mymail-multismtp' ), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', false, 'delivery_method' );
			}
		}

	}


	/**
	 * init function.
	 *
	 * init the plugin
	 *
	 * @access public
	 * @return void
	 */
	public function init() {

		if ( ! function_exists( 'mymail' ) ) {

			add_action( 'admin_notices', array( $this, 'notice' ) );

		} else {

			add_filter( 'mymail_delivery_methods', array( $this, 'delivery_method' ) );
			add_action( 'mymail_deliverymethod_tab_multismtp', array( $this, 'deliverytab' ) );

			add_filter( 'mymail_verify_options', array( $this, 'verify_options' ) );

			if ( mymail_option( 'deliverymethod' ) == 'multismtp' ) {
				add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
				add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
				add_action( 'mymail_initsend', array( $this, 'initsend' ) );
				add_action( 'mymail_presend', array( $this, 'presend' ) );
				add_action( 'mymail_dosend', array( $this, 'dosend' ) );
				add_filter( 'pre_set_transient__mymail_send_period', array( $this, 'save_sent_within_period' ) );
			}
		}

		if ( function_exists( 'mailster' ) ) {

			add_action(
				'admin_notices',
				function() {

					$name = 'MyMail Multi SMTP';
					$slug = 'mailster-multi-smtp/mailster-multi-smtp.php';

					$install_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . dirname( $slug ) ), 'install-plugin_' . dirname( $slug ) );

					$search_url = add_query_arg(
						array(
							's'    => $slug,
							'tab'  => 'search',
							'type' => 'term',
						),
						admin_url( 'plugin-install.php' )
					);

					?>
			<div class="error">
				<p>
				<strong><?php echo esc_html( $name ); ?></strong> is deprecated in Mailster and no longer maintained! Please switch to the <a href="<?php echo esc_url( $search_url ); ?>">new version</a> as soon as possible or <a href="<?php echo esc_url( $install_url ); ?>">install it now!</a>
				</p>
			</div>
					<?php

				}
			);
		}

	}


	/**
	 * initsend function.
	 *
	 * uses mymail_initsend hook to set initial settings
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function initsend( $mailobject ) {

		global $mymail_multismtp_sent_within_period, $mymail_multismtp_sentlimitreached;

		$server = $this->getnextserver();

		if ( $server ) {

			$mailobject->mailer->Mailer     = 'smtp';
			$mailobject->mailer->SMTPSecure = $server['secure'];
			$mailobject->mailer->Host       = $server['host'];
			$mailobject->mailer->Port       = $server['port'];
			$mailobject->mailer->SMTPAuth   = ! ! $server['auth'];

			if ( $mailobject->mailer->SMTPAuth ) {
				$mailobject->mailer->AuthType = $server['auth'];
				$mailobject->mailer->Username = $server['user'];
				$mailobject->mailer->Password = $server['pwd'];

			}
		} else {

			$mymail_multismtp_sentlimitreached = true;

		}

	}




	/**
	 * getnextserver function.
	 *
	 * get the next available server
	 *
	 * @access public
	 * @param unknown $use   (optional)
	 * @param unknown $round (optional)
	 * @return void
	 */
	public function getnextserver( $use = null, $round = 0 ) {

		global $mymail_multismtp_current, $mymail_multismtp_sent_within_period, $mymail_multismtp_sentlimitreached;

		$mymail_multismtp_current = is_null( $use ) ? mymail_option( 'multismtp_current', 0 ) : $use;
		// get all servers
		$servers = $this->getactiveservers();

		// seems no server has limits left
		if ( $round > count( $servers ) ) {
			return false; }

		// use first if current not available
		if ( ! isset( $servers[ $mymail_multismtp_current ] ) ) {
			return $this->getnextserver( 0, $round + 1 ); }

		$server = $servers[ $mymail_multismtp_current ];

		// define some transients for the limits
		if ( ! get_transient( '_mymail_send_period_timeout_' . $mymail_multismtp_current ) ) {
			set_transient( '_mymail_send_period_timeout_' . $mymail_multismtp_current, true, $server['send_period'] * 3600 );
		} else {

			$mymail_multismtp_sent_within_period = get_transient( '_mymail_send_period_' . $mymail_multismtp_current );

		}

		if ( ! $mymail_multismtp_sent_within_period ) {
			$mymail_multismtp_sent_within_period = 0; }

		$mymail_multismtp_sentlimitreached = $mymail_multismtp_sent_within_period >= $server['send_limit'];

		// send limit has been reached
		if ( $mymail_multismtp_sentlimitreached ) {
			// next server
			return $this->getnextserver( $mymail_multismtp_current + 1, $round + 1 );
		}
		// user next next time
		mymail_update_option( 'multismtp_current', $mymail_multismtp_current + 1 );

		return $server;

	}


	/**
	 *
	 *
	 * @param unknown $value
	 * @return unknown
	 */
	public function save_sent_within_period( $value ) {

		global $mymail_multismtp_current, $mymail_multismtp_sent_within_period, $mymail_multismtp_sentlimitreached;

		if ( $mymail_multismtp_sent_within_period ) {
			set_transient( '_mymail_send_period_' . $mymail_multismtp_current, $mymail_multismtp_sent_within_period ); }

		return $value;
	}


	/**
	 * getactiveservers function.
	 *
	 * uses the mymail_presend hook to apply settings before each mail
	 *
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function getactiveservers() {

		$servers = mymail_option( 'multismtp', array() );

		$return = array();

		$i = 0;
		foreach ( $servers as $server ) {
			if ( isset( $server['active'] ) && $server['active'] ) {
				$return[ $i++ ] = $server; }
		}

		return $return;
	}




	/**
	 * presend function.
	 *
	 * uses the mymail_presend hook to apply settings before each mail
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function presend( $mailobject ) {

		$mailobject->pre_send();

	}


	/**
	 * dosend function.
	 *
	 * uses the ymail_dosend hook and triggers the send
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function dosend( $mailobject ) {

		global $mymail_multismtp_current, $mymail_multismtp_sent_within_period, $mymail_multismtp_sentlimitreached;

		if ( ! $mymail_multismtp_sentlimitreached ) {
			$mailobject->do_send();
		} else {
			add_filter( 'pre_set_transient__mymail_send_period', create_function( '$value', 'return ' . mymail_option( 'send_limit' ) . ';' ) );

			// get the earliest possible time
			$servers = $this->getactiveservers();
			$count   = count( $servers );
			$time    = $count ? 10000000000 : time();
			for ( $i = 0; $i < $count; $i++ ) {
				$time = min( $time, get_option( '_transient_timeout__mymail_send_period_timeout_' . $i, $time ) );
			}
			update_option( '_transient_timeout__mymail_send_period_timeout', $time );

			$msg = __( 'Sent limit of all servers has been reached!', 'mymail-multismtp' );
			$mailobject->set_error( $msg );
		}
		if ( $mailobject->sent ) {
			$mymail_multismtp_sent_within_period++;
		} else {
			$servers = $this->getactiveservers();
			$mailobject->set_error( sprintf( __( 'Server #%1$d (%2$s) threw that error', 'mymail-multismtp' ), intval( $mymail_multismtp_current ) + 1, $servers[ $mymail_multismtp_current ]['host'] ) );
		}

	}


	/**
	 * save_post function.
	 *
	 * @access public
	 * @return void
	 * @param mixed $post_id
	 * @param mixed $post
	 */
	public function save_post( $post_id, $post ) {

		if ( isset( $_POST['mymail_multismtp'] ) && $post->post_type == 'newsletter' ) {

			$save = get_post_meta( $post_id, 'mymail-multismtp', true );
			$save = wp_parse_args( $_POST['mymail_multismtp'], $save );
			update_post_meta( $post_id, 'mymail-multismtp', $save );

		}

	}


	/**
	 * add_meta_boxes function.
	 *
	 * @access public
	 * @return void
	 */
	public function add_meta_boxes() {

		global $post;

		if ( mymail_option( 'multismtp_campaignbased' ) ) {
			add_meta_box( 'mymail_multismtp', 'Multi SMTP', array( $this, 'metabox' ), 'newsletter', 'side', 'low' );
		}
	}


	/**
	 * metabox function.
	 *
	 * @access public
	 * @return void
	 */
	public function metabox() {

		global $post;

		$readonly = ( in_array( $post->post_status, array( 'finished', 'active' ) ) || $post->post_status == 'autoresponder' && ! empty( $_GET['showstats'] ) ) ? 'readonly disabled' : '';

		$data = wp_parse_args(
			get_post_meta( $post->ID, 'mymail-multismtp', true ),
			array(
				'use_global' => true,
				'selected'   => null,
			)
		);

		$server = $this->getactiveservers();

		?>
		<style>#mymail_multismtp {display: inherit;}</style>
		<p><label><input type="radio" name="mymail_multismtp[use_global]" value="1" <?php echo $readonly; ?><?php checked( ! empty( $data['use_global'] ) ); ?> onchange="jQuery('.mymail-multismtp-server').prop('disabled', jQuery(this).is(':checked')).prop('readonly', jQuery(this).is(':checked'))"> <?php _e( 'use global settings', 'mymail-multismtp' ); ?></label></p><hr>
		<p><label><input type="radio" name="mymail_multismtp[use_global]" value="0" <?php echo $readonly; ?><?php checked( empty( $data['use_global'] ) ); ?> onchange="jQuery('.mymail-multismtp-server').prop('disabled', !jQuery(this).is(':checked')).prop('readonly', !jQuery(this).is(':checked'))"> <?php _e( 'use these servers for this campaign', 'mymail-multismtp' ); ?></label></p>
		<h4></h4>
		<ul>
		<?php

		if ( ! empty( $data['use_global'] ) ) {
			$readonly = 'readonly disabled'; }

		foreach ( $server as $i => $option ) {
			if ( ! isset( $option['active'] ) ) {
				continue;
			}
			?>
			<li><label><input type="checkbox" class="mymail-multismtp-server" name="mymail_multismtp[selected][]" value="<?php echo $i; ?>" <?php echo $readonly; ?><?php checked( is_null( $data['selected'] ) || in_array( $i, $data['selected'] ) ); ?>> <?php echo '#' . ( $i + 1 ) . ' <strong>' . esc_attr( $option['host'] ) . '</strong>'; ?></label></li>
			<?php
		}
		?>
		</ul>
		<?php
	}



	/**
	 * delivery_method function.
	 *
	 * add the delivery method to the options
	 *
	 * @access public
	 * @param mixed $delivery_methods
	 * @return void
	 */
	public function delivery_method( $delivery_methods ) {
		$delivery_methods['multismtp'] = 'Multi SMTP';
		return $delivery_methods;
	}


	/**
	 * deliverytab function.
	 *
	 * the content of the tab for the options
	 *
	 * @access public
	 * @return void
	 */
	public function deliverytab() {

			wp_enqueue_script( 'mymail-multismtp-settings-script', $this->plugin_url . '/js/script.js', array( 'jquery' ), MYMAIL_MULTISMTP_VERSION );
			wp_enqueue_style( 'mymail-multismtp-settings-style', $this->plugin_url . '/css/style.css', array(), MYMAIL_MULTISMTP_VERSION );

		?>
		<?php
		/*
		?><table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Campaign based', 'mymail-multismtp'); ?></th>
				<td><label><input type="checkbox" name="mymail_options[multismtp_campaignbased]" value="1" <?php checked(mymail_option('multismtp_campaignbased')) ?>> <?php _e('select servers on a campaign basis', 'mymail-multismtp') ?></label> </td>
			</tr>
		</table>
		<?php */
		?>
		<h4><?php _e( 'SMTP Servers', 'mymail-multismtp' ); ?>:</h4>
		<p class="description"><?php _e( 'Add new SMTP servers with the button. You can disable each server with the checkbox on the top. The used server will be changed every time you send a message. If you define limits for each server the general limits get overwritten with the proper values', 'mymail-multismtp' ); ?></p>
		<?php
		$options = mymail_option( 'multismtp' );

		ksort( $options );

		foreach ( $options as $i => $option ) {
			?>
		<div class="mymail-multismtp-server">
		<div class="mymail-multismtp-buttons">
			<a class="mymail-multismtp-remove" href="#"><?php _e( 'remove', 'mymail-multismtp' ); ?></a>
		</div>
		<h5><?php echo esc_attr( $option['host'] ); ?></h5>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e( 'Active', 'mymail-multismtp' ); ?></th>
				<td><label><input type="hidden" name="mymail_options[multismtp][<?php echo $i; ?>][active]" value=""><input type="checkbox" name="mymail_options[multismtp][<?php echo $i; ?>][active]" value="1" <?php checked( isset( $option['active'] ) && $option['active'] ); ?>> <?php _e( 'use this server', 'mymail-multismtp' ); ?></label> </td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Limits', 'mymail-multismtp' ); ?><p class="description"><?php _e( 'define the limits for this server', 'mymail-multismtp' ); ?></p></th>
				<td><p><?php echo sprintf( __( 'Send max %1$s within %2$s hours', 'mymail-multismtp' ), '<input type="text" name="mymail_options[multismtp][' . $i . '][send_limit]" value="' . $option['send_limit'] . '" class="small-text">', '<input type="text" name="mymail_options[multismtp][' . $i . '][send_period]" value="' . $option['send_period'] . '" class="small-text">' ); ?></p>
			<p class="description"><?php echo sprintf( __( 'You can still send %1$s mails within the next %2$s', 'mymail-multismtp' ), '<strong>' . max( 0, $option['send_limit'] - ( ( get_transient( '_mymail_send_period_timeout_' . $i ) ? get_transient( '_mymail_send_period_' . $i ) : 0 ) ) ) . '</strong>', '<strong>' . human_time_diff( ( get_transient( '_mymail_send_period_timeout_' . $i ) ? get_option( '_transient_timeout__mymail_send_period_timeout_' . $i, ( time() + $option['send_period'] * 3600 ) ) : time() + $option['send_period'] * 3600 ) ) . '</strong>' ); ?>
			</p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">SMTP Host : Port</th>
				<td><input type="text" name="mymail_options[multismtp][<?php echo $i; ?>][host]" value="<?php echo esc_attr( $option['host'] ); ?>" class="regular-text ">:<input type="text" name="mymail_options[multismtp][<?php echo $i; ?>][port]" value="<?php echo $option['port']; ?>" class="small-text smtp"></td>
			</tr>
			<tr valign="top">
				<th scope="row">Timeout</th>
				<td><span><input type="text" name="mymail_options[multismtp][<?php echo $i; ?>][timeout]" value="<?php echo $option['timeout']; ?>" class="small-text"> <?php _e( 'seconds', 'mymail-multismtp' ); ?></span></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Secure connection', 'mymail-multismtp' ); ?></th>
				<?php $secure = $option['secure']; ?>
				<td>
				<label><input type="radio" name="mymail_options[multismtp][<?php echo $i; ?>][secure]" value=""
																					  <?php
																						if ( ! $secure ) {
																							echo ' checked'; }
																						?>
				 class="smtp secure" data-port="25"> <?php _e( 'none', 'mymail-multismtp' ); ?></label>
				<label><input type="radio" name="mymail_options[multismtp][<?php echo $i; ?>][secure]" value="ssl"
																					  <?php
																						if ( $secure == 'ssl' ) {
																							echo ' checked'; }
																						?>
				 class="smtp secure" data-port="465"> SSL</label>
				<label><input type="radio" name="mymail_options[multismtp][<?php echo $i; ?>][secure]" value="tls"
																					  <?php
																						if ( $secure == 'tls' ) {
																							echo ' checked'; }
																						?>
				 class="smtp secure" data-port="465"> TLS</label>
				 </td>
			</tr>
			<tr valign="top">
				<th scope="row">SMTPAuth</th>
				<td>
				<?php $smtpauth = $option['auth']; ?>
				<label>
				<select name="mymail_options[multismtp][<?php echo $i; ?>][auth]">
					<option value="0" <?php selected( ! $smtpauth ); ?>><?php _e( 'none', 'mymail' ); ?></option>
					<option value="PLAIN" <?php selected( 'PLAIN', $smtpauth ); ?>>PLAIN</option>
					<option value="LOGIN" <?php selected( 'LOGIN', $smtpauth ); ?>>LOGIN</option>
					<option value="CRAM-MD5" <?php selected( 'CRAM-MD5', $smtpauth ); ?>>CRAM-MD5</option>
				</select></label>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Username', 'mymail-multismtp' ); ?></th>
				<td><input type="text" name="mymail_options[multismtp][<?php echo $i; ?>][user]" value="<?php echo esc_attr( $option['user'] ); ?>" class="regular-text"></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Password', 'mymail-multismtp' ); ?></th>
				<td><input type="password" name="mymail_options[multismtp][<?php echo $i; ?>][pwd]" value="<?php echo esc_attr( $option['pwd'] ); ?>" class="regular-text"></td>
			</tr>
		</table>
		</div>

			<?php
		}

		?>
		<input type="hidden" name="mymail_options[multismtp_current]" value="<?php echo esc_attr( mymail_option( 'multismtp_current' ) ); ?>">
		<p><a class="button mymail-multismtp-add"><?php _e( 'add SMTP Server', 'mymail-multismtp' ); ?></a></p>
		<?php

	}


	/**
	 * notice function.
	 *
	 * Notice if MyMail is not available
	 *
	 * @access public
	 * @return void
	 */
	public function notice() {
		?>
	<div id="message" class="error">
	  <p>
	   <strong>Multi SMTP for MyMail</strong> requires the <a href="https://evp.to/mymail?utm_campaign=wporg&utm_source=Multi+SMTP+for+MyMail">MyMail Newsletter Plugin</a>, at least version <strong><?php echo MYMAIL_MULTISMTP_REQUIRED_VERSION; ?></strong>. Plugin deactivated.
	  </p>
	</div>
		<?php
	}


	/**
	 * mymail_amazonses_verify_options function.
	 *
	 * some verification if options are saved
	 *
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function verify_options( $options ) {

		// only if delivery method is active
		if ( $options['deliverymethod'] == 'multismtp' ) {

			$count      = count( $options['multismtp'] );
			$time       = time();
			$send_limit = $send_period = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! isset( $options['multismtp'][ $i ]['active'] ) ) {
					continue; }
				$time        = min( $time, get_option( '_transient_timeout__mymail_send_period_timeout_' . $i, $time ) );
				$send_limit += intval( $options['multismtp'][ $i ]['send_limit'] );
				$send_period = max( $options['multismtp'][ $i ]['send_period'], $send_period );
				if ( function_exists( 'fsockopen' ) ) {
					$host = $options['multismtp'][ $i ]['host'];
					$port = $options['multismtp'][ $i ]['port'];
					$conn = fsockopen( $host, $port, $errno, $errstr, $options['multismtp'][ $i ]['timeout'] );

					if ( is_resource( $conn ) ) {

						fclose( $conn );

					} else {

						add_settings_error( 'mymail_options', 'mymail_options', sprintf( __( 'Not able to connected to %1$s via port %2$s! You may not be able to send mails cause of the locked port %3$s. Please contact your host or choose a different delivery method!', 'mymail-multismtp' ), '"' . $host . '"', $port, $port ) );
						unset( $options['multismtp'][ $i ]['active'] );

					}
				}
			}

			$options['send_limit']  = $send_limit;
			$options['send_period'] = $send_period;

		}

		return $options;
	}


}


new MyMailMultiSMTP();
