<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * QRZ.com "Import from LoTW" integration.
 *
 * - stores QRZ.com credentials per user (users.user_qrzcom_*)
 * - registers a daily cron job which runs after the hourly LoTW sync
 */
class Migration_qrzlotw_sync extends CI_Migration {

	public function up()
	{
		if (!$this->db->field_exists('user_qrzcom_name', 'users')) {
			$this->dbforge->add_column('users', array(
				'user_qrzcom_name' => array('type' => 'VARCHAR', 'constraint' => 64, 'null' => TRUE)
			));
		}
		if (!$this->db->field_exists('user_qrzcom_password', 'users')) {
			$this->dbforge->add_column('users', array(
				'user_qrzcom_password' => array('type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE)
			));
		}
		if (!$this->db->field_exists('user_qrzcom_totp_secret', 'users')) {
			$this->dbforge->add_column('users', array(
				'user_qrzcom_totp_secret' => array('type' => 'VARCHAR', 'constraint' => 128, 'null' => TRUE)
			));
		}

		if ($this->db->table_exists('cron')) {
			$exists = $this->db->where('id', 'qrzlotw_sync')->count_all_results('cron');
			if ($exists == 0) {
				$this->db->insert('cron', array(
					'id' => 'qrzlotw_sync',
					'enabled' => 0,
					'status' => 'pending',
					'description' => 'Import LoTW confirmations into QRZ Logbook',
					'function' => 'index.php/qrzlotw/sync',
					'expression' => '20 4 * * *',
					'last_run' => null,
					'next_run' => null
				));
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
				$this->dbforge->drop_column('users', $column);
			}
		}
	}
}
