<?php // @codingStandardsIgnoreLine
/**
 * Rank Math SEO - Action Scheduler Repair Plugin.
 *
 * @package      RANK_MATH
 * @copyright    Copyright (C) 2019, Rank Math - support@rankmath.com
 * @link         https://rankmath.com
 * @since        0.9.0
 *
 * @wordpress-plugin
 * Plugin Name:       Repair Action Scheduler
 * Version:           1.0
 * Plugin URI:        https://s.rankmath.com/home
 * Description:       Fix database errors related to the Action Scheduler library. The plugin checks and creates the tables necessary for Action Scheduler version 3.0.16. The faulty tables will be renamed and new ones will be created instead. This plugin runs once and does NOT need to stay activated on the site.
 * Author:            Rank Math
 * Author URI:        https://s.rankmath.com/home
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       repair-action-scheduler
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Updater class.
 */
class Repair_Action_Scheduler {

	const ACTIONS_TABLE = 'actionscheduler_actions';
	const CLAIMS_TABLE  = 'actionscheduler_claims';
	const GROUPS_TABLE  = 'actionscheduler_groups';
	const LOG_TABLE     = 'actionscheduler_logs';

	/**
	 * Tables and their primary columns.
	 *
	 * @var array
	 */
	private $tables = array();
	
	private $notices = array();

	/**
	 * 1. Check if the actionscheduler tables exist in the DB.
	 * 2. Check if PRIMARY KEY is set.
	 *     3. If not set, then rename it and create a new table.
	 * 4. Check if AUTO_INCREMENT is set.
	 *     5. If not set, then rename it and create a new table.
	 */
	public function __construct() {
		if ( get_option( 'ras_notices' ) === false ) {
			$this->do_repair();
			return;
		}

		$this->notices = get_option( 'ras_notices', array() );
		add_action( 'admin_notices', array( $this, 'show_notices' ) );
		
	}

	private function add_notice( $message ) {
		$this->notices[] = $message;
	}

	public function show_notices() {
		if ( empty( $this->notices ) ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible repair-action-scheduler-notice">';
		if ( count( $this->notices ) == 1 ) {
			$this->add_notice( '<em>' . __( 'No actions performed.', 'repair-action-scheduler' ) . '</em>' );
		}

		$this->add_notice( '<em>' . __( 'The Repair Action Scheduler plugin has been automatically deactivated.', 'repair-action-scheduler' ) . '</em>' );
		foreach ( $this->notices as $message ) {
			echo '<p>'.$message.'</p>';
		}
		echo '</div>';
		update_option( 'ras_notices', array() );

		deactivate_plugins( plugin_basename( __FILE__ ) );
	}

	private function save_data() {
		update_option( 'ras_notices', $this->notices );
	}

	private function do_repair() {
		if ( substr( get_option( 'schema-ActionScheduler_StoreSchema', '' ), 0, 1 ) > 3 ) {
			$this->add_notice( __( 'The Repair Action Scheduler could not run because the repair database schema is obsolete.', 'repair-action-scheduler' ) );
			$this->save_data();
			return;
		}

		$this->suffix = '_' . substr( md5( microtime() ), 0, 4 );
		$this->add_notice( '<strong>' . __( 'The Repair Action Scheduler process is complete.', 'repair-action-scheduler' ) . '</strong>' . __( 'The following actions have been performed:', 'repair-action-scheduler' ) );

		$this->tables = array(
			self::ACTIONS_TABLE => 'action_id',
			self::CLAIMS_TABLE  => 'claim_id',
			self::GROUPS_TABLE  => 'group_id',
			self::LOG_TABLE     => 'log_id',
		);

		$do_reset = false;
		foreach ( $this->tables as $table => $primary_column ) {
			if ( ! $this->table_exists( $table ) ) {
				$this->create_table( $table );
			}

			if ( ! $do_reset && ( ! $this->has_primary_key( $table ) || ! $this->has_auto_increment( $table ) ) ) {
				$do_reset = true;
			}
		}

		// Re-add all the tables, otherwise interlinked IDs might not match.
		if ( $do_reset ) {
			foreach ( $this->tables as $table => $primary_column ) {
				$this->rename_table( $table );
				$this->create_table( $table );
			}
		}

		$this->save_data();
	}

	private function has_primary_key( $table ) {
		global $wpdb;
		$table_name  = $wpdb->prefix . $table;
		$column_name = $this->tables[ $table ];
		$has_primary = $wpdb->query( "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_KEY = 'PRI' AND TABLE_NAME = '{$table_name}' AND COLUMN_NAME='{$column_name}'" ); // phpcs:ignore

		return (bool) $has_primary;
	}

	private function has_auto_increment( $table ) {
		global $wpdb;
		$table_name  = $wpdb->prefix . $table;
		$column_name = $this->tables[ $table ];
		$has_auto_increment = $wpdb->query( "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$table_name}' AND COLUMN_NAME='{$column_name}' AND EXTRA like '%auto_increment%'" ); // phpcs:ignore

		return (bool) $has_auto_increment;
	}

	private function rename_options() {
		$options = array(
			'schema-ActionScheduler_LoggerSchema' => '3',
			'schema-ActionScheduler_StoreSchema' => '2'
		);
		foreach ( $options as $option => $version ) {
			if ( $stored = get_option( $option ) ) {
				$value_to_save = (string) $version . '.0.' . time();
				update_option( $option, $value_to_save );

				// Translators: placeholder is the table name wrapped in CODE tags.
				$this->add_notice( sprintf( __( 'Renamed option: %1$s to %2$s', 'repair-action-scheduler' ), '<code>' . $option . '</code>', '<code>' . $option . $this->suffix . '</code>' ) );
			}
		}
	}

	private function table_exists( $table ) {
		global $wpdb;
		$tables = $wpdb->query( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" ); // phpcs:ignore
		return (bool) $tables;
	}

	private function create_table( $table ) {
		global $wpdb;
		$wpdb->query( $this->get_table_schema( $table ) ); // phpcs:ignore
		// Translators: placeholder is the table name wrapped in CODE tags.
		$this->add_notice( sprintf( __( 'Created table: %s', 'repair-action-scheduler' ), '<code>' . $table . '</code>' ) );
	}

	private function maybe_deactivate_analytics_module() {
		$modules = get_option( 'rank_math_modules' );
		if ( ! is_array( $modules ) ) {
			return;
		}

		$new_modules = array_values( array_diff( $modules, array( 'analytics', 'search-console' ) ) );
		$this->add_notice( __( 'Deactivated the Analytics module in Rank Math.', 'repair-action-scheduler' ) );
	}

	private function rename_table( $table ) {
		global $wpdb;
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}{$table} RENAME TO {$wpdb->prefix}{$table}{$this->suffix};" ); // phpcs:ignore
		// Translators: placeholder is the table name wrapped in CODE tags.
		$this->add_notice( sprintf( __( 'Renamed table: %1$s to %2$s', 'repair-action-scheduler' ), '<code>' . $table . '</code>', '<code>' . $table . $this->suffix . '</code>' ) );
	}

	private function get_table_schema( $table ) {
		global $wpdb;
		$table_name       = $wpdb->prefix . $table;
		$charset_collate  = $wpdb->get_charset_collate();
		$max_index_length = 191; // @see wp_get_db_schema()
		switch ( $table ) {

			case self::ACTIONS_TABLE:
				return "CREATE TABLE {$table_name} (
					action_id bigint(20) unsigned NOT NULL auto_increment,
					hook varchar(191) NOT NULL,
					status varchar(20) NOT NULL,
					scheduled_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
					scheduled_date_local datetime NOT NULL default '0000-00-00 00:00:00',
					args varchar($max_index_length),
					schedule longtext,
					group_id bigint(20) unsigned NOT NULL default '0',
					attempts int(11) NOT NULL default '0',
					last_attempt_gmt datetime NOT NULL default '0000-00-00 00:00:00',
					last_attempt_local datetime NOT NULL default '0000-00-00 00:00:00',
					claim_id bigint(20) unsigned NOT NULL default '0',
					extended_args varchar(8000) DEFAULT NULL,
					PRIMARY KEY  (action_id),
					KEY hook (hook($max_index_length)),
					KEY status (status),
					KEY scheduled_date_gmt (scheduled_date_gmt),
					KEY args (args($max_index_length)),
					KEY group_id (group_id),
					KEY last_attempt_gmt (last_attempt_gmt),
					KEY claim_id (claim_id)
					) $charset_collate";

			case self::CLAIMS_TABLE:
				return "CREATE TABLE {$table_name} (
						claim_id bigint(20) unsigned NOT NULL auto_increment,
						date_created_gmt datetime NOT NULL default '0000-00-00 00:00:00',
						PRIMARY KEY  (claim_id),
						KEY date_created_gmt (date_created_gmt)
						) $charset_collate";

			case self::GROUPS_TABLE:
				return "CREATE TABLE {$table_name} (
						group_id bigint(20) unsigned NOT NULL auto_increment,
						slug varchar(255) NOT NULL,
						PRIMARY KEY  (group_id),
						KEY slug (slug($max_index_length))
						) $charset_collate";

			case self::LOG_TABLE:
				return "CREATE TABLE {$table_name} (
						log_id bigint(20) unsigned NOT NULL auto_increment,
						action_id bigint(20) unsigned NOT NULL,
						message text NOT NULL,
						log_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
						log_date_local datetime NOT NULL default '0000-00-00 00:00:00',
						PRIMARY KEY  (log_id),
						KEY action_id (action_id),
						KEY log_date_gmt (log_date_gmt)
						) $charset_collate";

			default:
				return '';
		}
	}
}

register_deactivation_hook( __FILE__, 'repair_action_scheduler_clean' );
function repair_action_scheduler_clean() {
	delete_option( 'ras_notices' );
}

$repair_action_scheduler = new Repair_Action_Scheduler();
