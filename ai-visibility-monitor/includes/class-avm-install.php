<?php
/**
 * Database schema installer.
 *
 * Creates custom, indexed tables for monitoring data (spec §18.4, §19).
 * Large result histories never live in wp_options or post meta.
 *
 * @package AVM
 */

namespace AVM;

defined( 'ABSPATH' ) || exit;

class Install {

	/**
	 * Return the list of table base names (without prefix).
	 *
	 * @return string[]
	 */
	public static function table_names() {
		return array(
			'avm_brands',
			'avm_competitors',
			'avm_prompts',
			'avm_runs',
			'avm_results',
			'avm_citations',
			'avm_mentions',
			'avm_recommendations',
			'avm_logs',
			'avm_queue',
		);
	}

	/**
	 * Create or upgrade all tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$queries = array();

		$queries[] = "CREATE TABLE {$p}avm_brands (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			primary_name VARCHAR(191) NOT NULL DEFAULT '',
			alt_names TEXT NULL,
			domain VARCHAR(191) NOT NULL DEFAULT '',
			details LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY domain (domain)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_competitors (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			alt_names TEXT NULL,
			domain VARCHAR(191) NOT NULL DEFAULT '',
			category VARCHAR(100) NOT NULL DEFAULT '',
			priority TINYINT NOT NULL DEFAULT 3,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY domain (domain),
			KEY status (status)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_prompts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			prompt_text TEXT NOT NULL,
			category VARCHAR(50) NOT NULL DEFAULT 'informational',
			priority VARCHAR(20) NOT NULL DEFAULT 'supporting',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
			target_page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			geo_context VARCHAR(191) NOT NULL DEFAULT '',
			last_run_at DATETIME NULL,
			next_run_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status_priority (status, priority),
			KEY next_run_at (next_run_at)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			prompt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(50) NOT NULL DEFAULT '',
			model VARCHAR(100) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			estimated_cost DECIMAL(10,5) NOT NULL DEFAULT 0.00000,
			error_code VARCHAR(50) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY prompt_id (prompt_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_results (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			prompt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			answer LONGTEXT NULL,
			brand_detected TINYINT(1) NOT NULL DEFAULT 0,
			citation_detected TINYINT(1) NOT NULL DEFAULT 0,
			recommendation VARCHAR(30) NOT NULL DEFAULT 'not_mentioned',
			brand_position INT NOT NULL DEFAULT 0,
			confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY prompt_id (prompt_id),
			KEY created_at (created_at)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_citations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			result_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(512) NOT NULL DEFAULT '',
			domain VARCHAR(191) NOT NULL DEFAULT '',
			page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			page_type VARCHAR(30) NOT NULL DEFAULT '',
			ownership VARCHAR(20) NOT NULL DEFAULT 'third_party',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY result_id (result_id),
			KEY domain (domain),
			KEY ownership (ownership)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_mentions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			result_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			organisation VARCHAR(191) NOT NULL DEFAULT '',
			ownership VARCHAR(20) NOT NULL DEFAULT 'competitor',
			position INT NOT NULL DEFAULT 0,
			classification VARCHAR(30) NOT NULL DEFAULT 'neutral',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY result_id (result_id),
			KEY organisation (organisation)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_recommendations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			prompt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(50) NOT NULL DEFAULT '',
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			evidence TEXT NULL,
			action TEXT NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY prompt_id (prompt_id),
			KEY status_priority (status, priority)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(50) NOT NULL DEFAULT '',
			severity VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NULL,
			context TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY severity (severity),
			KEY created_at (created_at)
		) {$charset};";

		$queries[] = "CREATE TABLE {$p}avm_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_type VARCHAR(50) NOT NULL DEFAULT 'prompt_scan',
			prompt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			dedupe_key VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			attempts TINYINT NOT NULL DEFAULT 0,
			available_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_error VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY status_available (status, available_at)
		) {$charset};";

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		update_option( 'avm_db_version', AVM_DB_VERSION );
	}

	/**
	 * Drop every plugin table. Used only on explicit uninstall.
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( self::table_names() as $table ) {
			$name = $wpdb->prefix . $table;
			// Table name cannot be parameterised; it is a controlled constant.
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}
}
