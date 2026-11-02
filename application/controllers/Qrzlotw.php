<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/*
 * Import LoTW confirmations into the QRZ Logbook.
 *
 * Called by the Cron Manager (index.php/qrzlotw/sync, authenticated with the
 * master-cron header token) and manually from the QRZ Logbook page.
 */
class Qrzlotw extends CI_Controller {

	function __construct() {
		parent::__construct();

		if (MAINTENANCE_MODE && $this->session->userdata('user_id') == '') {
			echo __("Maintenance Mode is active. Try again later.") . "\n";
			redirect('user/login');
		}
	}

	public function index() {
		redirect('dashboard');
	}

	public function sync() {
		$this->load->helper('cronauth');
		if (!cronauth_allowed(3)) {
			$this->output->set_status_header(403);
			exit();
		}

		$this->load->model('cron_model');
		$this->cron_model->set_last_run('qrzlotw_sync');

		$this->load->model('qrzlotw_model');

		if ($this->user_model->authorize(3)) {
			// manual run: only the logged-in user's own credentials
			$users = $this->qrzlotw_model->get_users_to_sync($this->session->userdata('user_id'));
		} else {
			// scheduled run: all users with QRZ.com credentials
			$users = $this->qrzlotw_model->get_users_to_sync();
		}

		if (empty($users)) {
			echo __("No user has configured QRZ.com credentials.") . "\n";
			return;
		}

		$failures = 0;
		foreach ($users as $user) {
			$result = $this->qrzlotw_model->sync_user($user);
			echo $result['message'] . "\n";
			if (!empty($result['log'])) {
				echo $result['log'] . "\n";
			}
			if (!$result['ok']) {
				$failures++;
			}
		}

		if ($failures > 0 && $failures === count($users)) {
			$this->output->set_status_header(500);
		}
	}
}
