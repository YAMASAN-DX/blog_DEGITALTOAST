<?php
/*
Plugin Name: SiteGuard WP Plugin
Plugin URI: https://www.jp-secure.com/siteguard_wp_plugin_en/
Description: Adds WordPress login and admin protections, including CAPTCHA, login lock, login alerts, renamed login URLs, and SiteGuard WAF tuning support.
Author: JP-Secure
Author URI: https://www.eg-secure.co.jp/
Text Domain: siteguard
Domain Path: /languages/
Version: 1.8.9
*/

/*
	Copyright 2014 EG Secure Solutions Inc (JP-Secure Inc)

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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$data = get_file_data( __FILE__, array( 'version' => 'Version' ) );
define( 'SITEGUARD_VERSION', $data['version'] );

define( 'SITEGUARD_PATH', plugin_dir_path( __FILE__ ) );
define( 'SITEGUARD_URL_PATH', plugin_dir_url( __FILE__ ) );

define( 'SITEGUARD_RENAME_MODE_HTACCESS', '0');
define( 'SITEGUARD_RENAME_MODE_STUB', '1');

define( 'SITEGUARD_LOGIN_NOSELECT', 4 );
define( 'SITEGUARD_LOGIN_SUCCESS', 0 );
define( 'SITEGUARD_LOGIN_FAILED', 1 );
define( 'SITEGUARD_LOGIN_FAIL_ONCE', 2 );
define( 'SITEGUARD_LOGIN_LOCKED', 3 );

define( 'SITEGUARD_LOGIN_TYPE_NOSELECT', 2 );
define( 'SITEGUARD_LOGIN_TYPE_NORMAL', 0 );
define( 'SITEGUARD_LOGIN_TYPE_XMLRPC', 1 );

require_once 'classes/siteguard-base.php';
require_once 'classes/siteguard-config.php';
require_once 'classes/siteguard-htaccess.php';
require_once 'classes/siteguard-admin-filter.php';
require_once 'classes/siteguard-rename-login.php';
require_once 'classes/siteguard-login-history.php';
require_once 'classes/siteguard-login-lock.php';
require_once 'classes/siteguard-login-alert.php';
require_once 'classes/siteguard-captcha.php';
require_once 'classes/siteguard-disable-xmlrpc.php';
require_once 'classes/siteguard-disable-pingback.php';
require_once 'classes/siteguard-disable-author-query.php';
require_once 'classes/siteguard-waf-exclude-rule.php';
require_once 'classes/siteguard-updates-notify.php';
require_once 'admin/siteguard-menu-init.php';

global $siteguard_htaccess;
global $siteguard_config;
global $siteguard_admin_filter;
global $siteguard_rename_login;
global $siteguard_loginlock;
global $siteguard_loginalert;
global $siteguard_captcha;
global $siteguard_login_history;
global $siteguard_xmlrpc;
global $siteguard_pingback;
global $siteguard_author_query;
global $siteguard_waf_exclude_rule;
global $siteguard_updates_notify;

$siteguard_htaccess         = new SiteGuard_Htaccess();
$siteguard_config           = new SiteGuard_Config();
$siteguard_admin_filter     = new SiteGuard_AdminFilter();
$siteguard_rename_login     = new SiteGuard_RenameLogin();
$siteguard_loginlock        = new SiteGuard_LoginLock();
$siteguard_loginalert       = new SiteGuard_LoginAlert();
$siteguard_login_history    = new SiteGuard_LoginHistory();
$siteguard_captcha          = new SiteGuard_CAPTCHA();
$siteguard_xmlrpc           = new SiteGuard_Disable_XMLRPC();
$siteguard_pingback         = new SiteGuard_Disable_Pingback();
$siteguard_author_query     = new SiteGuard_Disable_Author_Query();
$siteguard_waf_exclude_rule = new SiteGuard_WAF_Exclude_Rule();
$siteguard_updates_notify   = new SiteGuard_UpdatesNotify();

function siteguard_activate() {
	global $siteguard_config, $siteguard_admin_filter, $siteguard_rename_login, $siteguard_login_history, $siteguard_captcha, $siteguard_loginlock, $siteguard_loginalert, $siteguard_xmlrpc, $siteguard_pingback, $siteguard_author_query, $siteguard_waf_exclude_rule, $siteguard_updates_notify;

	// Whether this is a first-time install, decided before the first update()
	// below creates the option. See the version bookkeeping at the end.
	$is_fresh_install = ! is_array( get_option( 'siteguard_config' ) );

	load_plugin_textdomain(
		'siteguard',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	$siteguard_config->set( 'show_admin_notices', '0' );
	$siteguard_config->update();
	$siteguard_admin_filter->init();
	$siteguard_rename_login->init();
	$siteguard_login_history->init();
	$siteguard_captcha->init();
	$siteguard_loginlock->init();
	$siteguard_loginalert->init();
	$siteguard_xmlrpc->init();
	$siteguard_pingback->init();
	$siteguard_author_query->init();
	$siteguard_waf_exclude_rule->init();
	$siteguard_updates_notify->init();

	if ( $is_fresh_install ) {
		// One piece of 1.7.x state does outlive the plugin: its .htaccess blocks.
		// Deleting the plugin through WordPress runs the deactivation hook, which
		// clears them, but a directory removed by hand (FTP) leaves them in place,
		// and the Admin Filter block ("RewriteRule ^wp-admin 404-siteguard") locks
		// administrators out of /wp-admin/. upgrade() takes care of this for an
		// in-place update; a fresh install skips upgrade() entirely because of the
		// version recorded below, so the same cleanup has to happen here.
		// clear_settings() does nothing when the mark is absent.
		SiteGuard_Htaccess::clear_settings( $siteguard_admin_filter->get_mark() );
		SiteGuard_Htaccess::clear_settings( $siteguard_xmlrpc->get_mark() );

		// Record the version now. Every migration block in upgrade() repairs
		// state left by an older release, and the init() calls above have just
		// built the current state from scratch, so there is nothing to migrate.
		//
		// Without this the stored version stays empty (treated as 0.0.0) and
		// upgrade() runs on every request until one of them finishes, calling
		// SiteGuard_RenameLogin::feature_on() — and its loopback .htaccess
		// self-test — many times in parallel right after activation.
		$siteguard_config->set( 'version', SITEGUARD_VERSION );
		$siteguard_config->update();
	}
}
register_activation_hook( __FILE__, 'siteguard_activate' );

function siteguard_deactivate() {
	global $siteguard_config;
	$siteguard_config->set( 'show_admin_notices', '0' );
	$siteguard_config->update();
	SiteGuard_RenameLogin::feature_off();
	SiteGuard_AdminFilter::feature_off();
	SiteGuard_Disable_XMLRPC::feature_off();
	SiteGuard_WAF_Exclude_Rule::feature_off();
	SiteGuard_UpdatesNotify::feature_off();
}
register_deactivation_hook( __FILE__, 'siteguard_deactivate' );


class SiteGuard extends SiteGuard_Base {
	const UPGRADE_LOCK_TRANSIENT = 'siteguard_upgrade_lock';

	protected $menu_init;
	function __construct() {
		global $siteguard_config;
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
		$this->htaccess_check();
		// upgrade() must run on every request, not only admin_init, so that
		// upgrades from 1.7.x can clean up legacy .htaccess blocks even when
		// /wp-admin/ would otherwise be locked out by those very rules.
		add_action( 'init', array( $this, 'upgrade' ), 0 );
		if ( is_admin() ) {
			include 'admin/siteguard-menu-login-history.php';
			$this->menu_init = new SiteGuard_Menu_Init();
			add_action( 'init', array( $this, 'set_cookie' ) );
			if ( '0' === $siteguard_config->get( 'show_admin_notices' ) && '1' === $siteguard_config->get( 'renamelogin_enable' ) ) {
				add_action( 'admin_notices', array( $this, 'admin_notices' ) );
				$siteguard_config->set( 'show_admin_notices', '1' );
				$siteguard_config->update();
			}
		}
	}
	function set_cookie() {
		SiteGuard_Menu_Login_History::set_cookie();
	}
	function plugins_loaded() {
		load_plugin_textdomain(
			'siteguard',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
	function htaccess_check() {
		global $siteguard_config, $siteguard_rename_login;

		// A self-test request is the plugin looking at itself mid-rebuild, so it
		// must not judge the .htaccess state at all.
		//
		// The other mid-rebuild case — feature_on() having removed the block it
		// is about to write back — is checked in rename_rebuild_in_progress()
		// below, at the point where something would actually be changed. Reading
		// that transient here instead would cost two option lookups on every
		// single request just to confirm that nothing is wrong.
		if ( siteguard_is_self_test_request() ) {
			return;
		}

		// Only check whether the SiteGuard marker block still exists in .htaccess.
		// The actual ".htaccess effectiveness" probe (test_htaccess) is performed
		// only when the user toggles a feature on, to avoid loopback HTTP
		// requests on every WordPress request.
		if ( '1' === $siteguard_config->get( 'waf_exclude_rule_enable' ) ) {
			if ( ! SiteGuard_Htaccess::is_exists_setting( SiteGuard_WAF_Exclude_Rule::get_mark() ) ) {
				$siteguard_config->set( 'waf_exclude_rule_enable', '0' );
				$siteguard_config->update();
			}
		}
		if ( '1' === $siteguard_config->get( 'renamelogin_enable' ) ) {
			// Act only on a mode that was actually recorded. "renamelogin_stub"
			// arrived in 1.8.0, and nothing in upgrade() backfills it — the only
			// migration that would (via feature_on()) is gated on < 1.2.5 — so an
			// install updated from 1.7.x keeps it unset until an administrator
			// saves the Rename Login screen. That install is working: it is served
			// by the .htaccess block 1.7.x wrote, and is_stub_mode() reads the
			// unset value as "not stub", so the URLs handed out match.
			//
			// Treating the unset value as stub mode here would make the branch
			// below "repair" that healthy install — write a stub file, then delete
			// the block that is actually serving the login page — and no later
			// migration would undo it.
			$mode = $siteguard_config->get( 'renamelogin_stub' );
			if ( SITEGUARD_RENAME_MODE_HTACCESS === $mode ) {
				if ( ! SiteGuard_Htaccess::is_exists_setting( SiteGuard_RenameLogin::get_mark() )
					&& ! $this->rename_rebuild_in_progress()
				) {
					$siteguard_config->set( 'renamelogin_enable', '0' );
					$siteguard_config->update();
				}
			} elseif ( SITEGUARD_RENAME_MODE_STUB === $mode ) {
				// Stub (.php) mode. Restore the agreement between what is
				// recorded, what the server does and which files exist:
				// concurrent feature_on() runs could leave any combination
				// behind (each one starts by deleting both the .htaccess block
				// and the current stub file before deciding again), and the stub
				// file can also go missing on its own, e.g. removed by an
				// administrator who did not recognise it in the site root.
				$stub_exists  = file_exists( $siteguard_rename_login->stub_abspath() );
				$block_exists = $siteguard_rename_login->can_use_htaccess()
					&& SiteGuard_Htaccess::is_exists_setting( SiteGuard_RenameLogin::get_mark() );

				// The healthy shape of stub mode: the stub is there and no
				// leftover block. Both have to be looked at to know that — the
				// "leftover block" case is exactly the one where the stub is
				// present too — so the reads above cannot be skipped; on Nginx
				// can_use_htaccess() returns before touching the file.
				if ( $stub_exists && ! $block_exists ) {
					return;
				}
				if ( $this->rename_rebuild_in_progress() ) {
					return;
				}

				// Put the stub back first. It is the entry point the recorded
				// settings advertise, and it has to exist before the .htaccess
				// block — which may be the only one working right now — is
				// taken away.
				if ( ! $stub_exists ) {
					$stub_exists = $siteguard_rename_login->ensure_stub();
				}

				if ( $block_exists && $stub_exists ) {
					// The block rewrites "<slug>(.*)" to "wp-login.php$1", so the
					// "<slug>.php" URL shown on the settings screen turns into
					// "wp-login.php.php" and returns 404 — while the login form
					// rendered at the extensionless URL posts to that same dead
					// ".php" address. Dropping the block leaves the stub serving
					// the URL that is actually advertised.
					//
					// Converging on the recorded mode is also the only safe move
					// when it cannot be told whether the block does anything:
					// can_use_htaccess() only knows that this is Apache and that
					// the file is writable, not whether the server reads it at
					// all (AllowOverride None). Keeping the block and switching
					// the recorded mode to match it would, in that case, point
					// the settings screen at a URL that nothing serves.
					SiteGuard_Htaccess::clear_settings( SiteGuard_RenameLogin::get_mark() );
				} elseif ( $block_exists ) {
					// The stub could not be written (a read-only site root), so
					// the .htaccess block is the only way in that is left.
					// Record the mode that matches it rather than removing it.
					//
					// A block in .htaccess is good evidence that .htaccess works
					// here: feature_on() only writes one after its self-test has
					// passed. That is why this is preferred over turning the
					// feature off — it keeps a login URL that is very likely
					// serving, instead of exposing wp-login.php again.
					$siteguard_config->set( 'renamelogin_stub', SITEGUARD_RENAME_MODE_HTACCESS );
					$siteguard_config->set( 'renamelogin_stub_reason', array() );
					$siteguard_config->update();
				} elseif ( ! $stub_exists ) {
					// Neither entry point exists and the stub cannot be written
					// (a read-only site root): nothing serves the login page at
					// all, and retrying the same failing write on every request
					// would never change that. Hand the login page back to
					// wp-login.php, exactly as the .htaccess branch above does
					// when its block has gone missing — being able to log in
					// matters more than keeping the URL hidden.
					// maybe_notice_stub_failed() explains the cause once an
					// administrator is back in.
					siteguard_error_log( 'Rename Login turned off: the stub file is missing and cannot be created.' );
					$siteguard_config->set( 'renamelogin_enable', '0' );
					$siteguard_config->update();
				}
			}
		}
	}
	/**
	 * Whether SiteGuard_RenameLogin::feature_on() is rebuilding the .htaccess
	 * block right now. Between its clear_settings() and update_settings() the
	 * block is legitimately absent, and a request landing in that window must
	 * not read that as "the feature is broken".
	 *
	 * Only called once a discrepancy has been seen, so the option lookups stay
	 * off the path of ordinary requests.
	 *
	 * @return bool
	 */
	private function rename_rebuild_in_progress() {
		return (bool) get_transient( SiteGuard_RenameLogin::HTACCESS_REBUILD_TRANSIENT );
	}

	function admin_notices() {
		global $siteguard_rename_login;
		echo '<div class="updated" style="background-color:#719f1d;"><p><span style="border: 4px solid #def1b8;padding: 4px 4px;color:#fff;font-weight:bold;background-color:#038bc3;">';
		echo esc_html__( 'The login page URL has been changed.', 'siteguard' ) . '</span>';
		printf(
			'<span style="color:#eee;">'
			. esc_html__( 'Please bookmark the %1$s. You can change this setting %2$s.', 'siteguard' )
			. '</span></p></div>',
			'<a style="color:#fff;text-decoration:underline;" href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'new login URL', 'siteguard' ) . '</a>',
			'<a style="color:#fff;text-decoration:underline;" href="' . esc_url( menu_page_url( 'siteguard_rename_login', false ) ) . '">' . esc_html__( 'here', 'siteguard' ) . '</a>'
		);
		$siteguard_rename_login->send_notify();
	}
	function upgrade() {
		global $siteguard_config, $siteguard_rename_login, $siteguard_admin_filter, $siteguard_loginalert, $siteguard_updates_notify, $siteguard_login_history, $siteguard_xmlrpc, $siteguard_author_query, $siteguard_waf_exclude_rule;
		$upgrade_ok  = true;
		$old_version = $siteguard_config->get( 'version' );
		if ( '' === $old_version ) {
			$old_version = '0.0.0';
		}
		if ( $old_version === SITEGUARD_VERSION ) {
			return;
		}
		// The self-test request of an upgrade already in progress. Migrating
		// from here would start a second upgrade (and a third, and so on: each
		// self-test that falls through to WordPress boots the plugin again)
		// before the first one has recorded the new version.
		if ( siteguard_is_self_test_request() ) {
			return;
		}
		// Advisory lock: the version is only recorded once the migration
		// finishes, so without it every request that arrives in the meantime
		// repeats the same work — including feature_on() and its loopback
		// self-test. It is released below so that a failed upgrade is retried
		// on the next request as before; the timeout only covers a request
		// that dies midway.
		if ( get_transient( self::UPGRADE_LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::UPGRADE_LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
		if ( version_compare( $old_version, '1.0.6' ) < 0 ) {
			if ( '1' === $siteguard_config->get( 'admin_filter_enable' ) ) {
				if ( true !== $siteguard_admin_filter->feature_on( $this->get_ip() ) ) {
					siteguard_error_log( 'Failed to update at admin_filter from ' . $old_version . ' to ' . SITEGUARD_VERSION . '.' );
					$upgrade_ok = false;
				}
			}
		}
		if ( version_compare( $old_version, '1.1.1' ) < 0 ) {
			$siteguard_loginalert->init();
		}
		if ( version_compare( $old_version, '1.2.0' ) < 0 ) {
			$siteguard_updates_notify->init();
		}
		if ( version_compare( $old_version, '1.2.5' ) < 0 ) {
			if ( '1' === $siteguard_config->get( 'admin_filter_enable' ) ) {
				$siteguard_admin_filter->cvt_status_for_1_2_5( $this->get_ip() );
			}
			if ( '1' === $siteguard_config->get( 'renamelogin_enable' ) ) {
				if ( true !== $siteguard_rename_login->feature_on() ) {
					siteguard_error_log( 'Failed to update at rename_login from ' . $old_version . ' to ' . SITEGUARD_VERSION . '.' );
					$upgrade_ok = false;
				}
			}
		}
		if ( version_compare( $old_version, '1.3.0' ) < 0 ) {
			$siteguard_login_history->init();
			$siteguard_xmlrpc->init();
		}
		if ( version_compare( $old_version, '1.5.0' ) < 0 ) {
			$admin_filter_exclude_path = $siteguard_config->get( 'admin_filter_exclude_path' );
			if ( false === strpos( $admin_filter_exclude_path, 'site-health.php' ) ) {
				$admin_filter_exclude_path .= ', site-health.php';
				$siteguard_config->set( 'admin_filter_exclude_path', $admin_filter_exclude_path );
				$siteguard_config->update();
			}
		}
		if ( version_compare( $old_version, '1.5.1' ) < 0 ) {
			if ( '1' === $siteguard_config->get( 'admin_filter_enable' ) ) {
				if ( true !== $siteguard_admin_filter->feature_on( $this->get_ip() ) ) {
					siteguard_error_log( 'Failed to update at admin_filter from ' . $old_version . ' to ' . SITEGUARD_VERSION . '.' );
					$upgrade_ok = false;
				}
			}
			if ( '1' === $siteguard_config->get( 'disable_xmlrpc_enable' ) ) {
				if ( true !== $siteguard_xmlrpc->feature_on() ) {
					siteguard_error_log( 'Failed to update at disable_xmlrpc from ' . $old_version . ' to ' . SITEGUARD_VERSION . '.' );
					$upgrade_ok = false;
				}
			}
		}
		if ( version_compare( $old_version, '1.6.0' ) < 0 ) {
			$siteguard_author_query->init();
		}
		if ( version_compare( $old_version, '1.7.0' ) < 0 ) {
			if ( '1' === $siteguard_config->get( 'admin_filter_enable' ) ) {
				if ( true !== $siteguard_admin_filter->feature_on( $this->get_ip() ) ) {
					siteguard_error_log( 'Failed to update at admin_filter from ' . $old_version . ' to ' . SITEGUARD_VERSION . '.' );
					$upgrade_ok = false;
				}
			}
		}
		if ( version_compare( $old_version, '1.8.0' ) < 0 ) {
			// Legacy Nginx-exposure cleanup and the rescue_enable default. These
			// are unrelated to the /wp-admin/ lockout and ran when the install
			// first reached 1.8.0, so they stay gated on < 1.8.0. The Admin
			// Filter / XML-RPC .htaccess blocks (which cause the lockout) are
			// cleared in the < 1.8.3 block below.
			if ( '' === $siteguard_config->get( 'rescue_enable' ) ) {
				$siteguard_config->set( 'rescue_enable', '1' );
				$siteguard_config->update();
			}
			// Remove legacy error.log left by previous versions; logging now
			// uses PHP error_log() so the file would only sit web-exposed on Nginx.
			$legacy_log = SITEGUARD_PATH . 'error.log';
			if ( file_exists( $legacy_log ) ) {
				@unlink( $legacy_log );
			}
			// Remove legacy plugin-directory tmp/ used for .htaccess rebuilds.
			// `clear_settings()` / `update_settings()` now short-circuit on
			// Nginx (no .htaccess in use), so this directory will not be
			// recreated there. On Apache it will be regenerated as needed
			// by make_tmp_dir(). Existing orphan tempnam files would be
			// web-exposed on Nginx without the .htaccess inside the dir.
			$legacy_tmp = SITEGUARD_PATH . 'tmp';
			if ( is_dir( $legacy_tmp ) ) {
				$entries = @scandir( $legacy_tmp );
				if ( is_array( $entries ) ) {
					foreach ( $entries as $entry ) {
						if ( '.' === $entry || '..' === $entry ) {
							continue;
						}
						$path = $legacy_tmp . DIRECTORY_SEPARATOR . $entry;
						if ( is_file( $path ) ) {
							if ( ! @unlink( $path ) ) {
								@chmod( $path, 0644 );
								@unlink( $path );
							}
						}
					}
				}
				@rmdir( $legacy_tmp );
			}
			// Remove legacy CAPTCHA answer files (*.txt). Pre-1.8.0 stored
			// them at WP_CONTENT_DIR/siteguard/ with an .htaccess block on
			// .txt; on Nginx that block does not apply and the salt+hash
			// would be readable. New answer files use .php with a stub
			// prefix and live in the same directory.
			$captcha_dir = path_join( WP_CONTENT_DIR, 'siteguard' );
			if ( is_dir( $captcha_dir ) ) {
				$entries = @scandir( $captcha_dir );
				if ( is_array( $entries ) ) {
					foreach ( $entries as $entry ) {
						if ( preg_match( '/\.txt$/', $entry ) ) {
							$path = $captcha_dir . DIRECTORY_SEPARATOR . $entry;
							if ( is_file( $path ) ) {
								if ( ! @unlink( $path ) ) {
									@chmod( $path, 0644 );
									@unlink( $path );
								}
							}
						}
					}
				}
			}
		}
		if ( version_compare( $old_version, '1.8.3' ) < 0 ) {
			// Admin Page IP Filter and XML-RPC protection moved from .htaccess
			// to PHP in 1.8.x, leaving their 1.7.x .htaccess blocks orphaned.
			// The Admin Filter block ("RewriteRule ^wp-admin 404-siteguard")
			// blocks /wp-admin/ at the Apache layer and can lock administrators
			// out. clear_settings() is idempotent (a no-op when the mark is
			// absent), so gate this on the fix release (< 1.8.3) rather than
			// < 1.8.0: that also recovers the rare install whose stored version
			// already advanced past 1.8.0 while the block survived (e.g. the
			// .htaccess was briefly unwritable during the 1.8.0 upgrade).
			//
			// Rename Login and WAF Tuning Support still use .htaccess in 1.8.x
			// with the same mark and block format as 1.7.x, so their blocks are
			// the current, valid mechanism — they are intentionally NOT touched
			// here (clearing WAF without a rebuild would drop working rules).
			SiteGuard_Htaccess::clear_settings( $siteguard_admin_filter->get_mark() );
			SiteGuard_Htaccess::clear_settings( $siteguard_xmlrpc->get_mark() );
		}
		if ( $upgrade_ok && SITEGUARD_VERSION !== $old_version ) {
			$siteguard_config->set( 'version', SITEGUARD_VERSION );
			$siteguard_config->update();
		}
		delete_transient( self::UPGRADE_LOCK_TRANSIENT );
	}
}
$siteguard = new SiteGuard();
