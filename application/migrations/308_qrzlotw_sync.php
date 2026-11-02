<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * QRZ.com "Import from LoTW" integration.
 *
 * - stores QRZ.com credentials per user (users.user_qrzcom_*)
 * - registers a daily cron job which runs after the hourly LoTW sync
 *
 * Plain ALTER/INSERT statements are used on purpose: dbforge's add_column()
 * silently does nothing on some MariaDB/CI combinations.
 */
class Migration_qrzlotw_sync extends CI_Migration {

	public function up()
	{
		if (!$this->db->field_exists('user_qrzcom_name', 'users')) {
			$this->db->query('ALTER TABLE `users` ADD COLUMN `user_qrzcom_name` VARCHAR(64) NULL');
		}
		if (!$this->db->field_exists('user_qrzcom_password', 'users')) {
			$this->db->query('ALTER TABLE `users` ADD COLUMN `user_qrzcom_password` VARCHAR(255) NULL');
		}
		if (!$this->db->field_exists('user_qrzcom_totp_secret', 'users')) {
			$this->db->query('ALTER TABLE `users` ADD COLUMN `user_qrzcom_totp_secret` VARCHAR(128) NULL');
		}

		if ($this->db->table_exists('cron')) {
			$exists = $this->db->where('id', 'qrzlotw_sync')->count_all_results('cron');
			if ($exists == 0) {
				$this->db->query(
					'INSERT INTO `cron` (`id`, `enabled`, `status`, `description`, `function`, `expression`, `last_run`, `next_run`) '
					. 'VALUES (?, 0, ?, ?, ?, ?, NULL, NULL)',
					array(
						'qrzlotw_sync',
						'pending',
						'Import LoTW confirmations into QRZ Logbook',
						'index.php/qrzlotw/sync',
						'20 4 * * *'
					)
				);
			}
		}
	}

	public function down()
	{
		if ($this->db->table_exists('cron')) {
			$this->db->where('id', 'qrzlotw_sync');
			$this->db->delete('cron');
		}

		foreach (array('user_qrzcom_name', 'user_qrzcom_password', 'user_qrzcom_totp_secret') as $column) {
			if ($this->db->field_exists($column, 'users')) {
				$this->db->query('ALTER TABLE `users` DROP COLUMN `' . $column . '`');
			}
		}
	}
}
