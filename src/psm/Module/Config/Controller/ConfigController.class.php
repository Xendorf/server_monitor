<?php
/**
 * PHP Server Monitor
 * Monitor your servers and websites.
 *
 * This file is part of PHP Server Monitor.
 * PHP Server Monitor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP Server Monitor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP Server Monitor.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package     phpservermon
 * @author      Pepijn Over <pep@neanderthal-technology.com>
 * @copyright   Copyright (c) 2008-2014 Pepijn Over <pep@neanderthal-technology.com>
 * @license     http://www.gnu.org/licenses/gpl.txt GNU GPL v3
 * @version     Release: v3.1.1
 * @link        http://www.phpservermonitor.org/
 **/

namespace psm\Module\Config\Controller;
use psm\Module\AbstractController;
use psm\Service\Database;

class ConfigController extends AbstractController {

	/**
	 * Checkboxes
	 * @var array $checkboxes
	 */
	protected $checkboxes = array(
		'email_status',
		'email_smtp',
		'sms_status',
		'pushover_status',
		'log_status',
		'log_email',
		'log_sms',
		'log_pushover',
		'show_update',
	);

	/**
	 * Fields for saving
	 * @var array $fields
	 */
	protected $fields = array(
		'email_from_name',
		'email_from_email',
		'email_smtp_host',
		'email_smtp_port',
		'email_smtp_username',
		'email_smtp_password',
		'sms_gateway_username',
		'sms_gateway_password',
		'sms_from',
		'pushover_api_token',
	);

	private $default_tab = 'general';

	function __construct(Database $db, \Twig_Environment $twig) {
		parent::__construct($db, $twig);

		$this->setMinUserLevelRequired(PSM_USER_ADMIN);

		$this->setActions(array(
			'index', 'save',
		), 'index');
	}

	/**
	 * Populate all the config fields with values from the database
	 *
	 * @return string
	 */
	protected function executeIndex() {
		$this->twig->addGlobal('subtitle', psm_get_lang('menu', 'config'));
		$tpl_data = $this->getLabels();

		$config_db = $this->db->select(
			PSM_DB_PREFIX . 'config',
			null,
			array('key', 'value')
		);

		$config = array();
		foreach($config_db as $entry) {
			$config[$entry['key']] = $entry['value'];
		}

		// generate language array
		$lang_keys = psm_get_langs();
		$tpl_data['language_current'] = (isset($config['language']))
				? $config['language']
				: 'en_US';
		$tpl_data['languages'] = array();
		foreach($lang_keys as $key => $label) {
			$tpl_data['languages'][] = array(
				'value' => $key,
				'label' => $label,
			);
		}

		// @todo these selected values can easily be rewritten in the template using twig
		$tpl_data['sms_selected_' . $config['sms_gateway']] = 'selected="selected"';
		$tpl_data['alert_type_selected_' . $config['alert_type']] = 'selected="selected"';
		$smtp_sec = isset($config['email_smtp_security']) ? $config['email_smtp_security'] : '';
		$tpl_data['email_smtp_security_selected_' . $smtp_sec] = 'selected="selected"';
		$tpl_data['auto_refresh_servers'] = (isset($config['auto_refresh_servers'])) ? $config['auto_refresh_servers'] : '0';
		$tpl_data['log_retention_period'] = (isset($config['log_retention_period'])) ? $config['log_retention_period'] : '365';
        /* default value for server settings */
        $arrDefault = array(
            'default_warning_threshold' => '1',
            'default_timeout'           => '10',
            'default_type'              => 'service',
            'default_active'            => 'yes',
            'default_email'             => 'yes',
            'default_sms'               => 'yes',
            'default_pushover'          => 'yes',
            'default_port'              => '0',
            'default_snmp_community'    => 'public',
            'default_snmp_version'      => '2c',
            'default_snmp_oid'          => 'sysDescr',
            );
        foreach ($arrDefault as $default_field => $default_value )
        {
            $tpl_data[$default_field] = (isset($config[$default_field]) ? $config[$default_field] : $default_value);
        }

		foreach($this->checkboxes as $input_key) {
			$tpl_data[$input_key . '_checked'] =
				(isset($config[$input_key]) && (int) $config[$input_key] == 1)
				? 'checked="checked"'
				: '';
		}
		foreach($this->fields as $input_key) {
			$tpl_data[$input_key] = (isset($config[$input_key])) ? $config[$input_key] : '';
		}

		$tpl_data[$this->default_tab . '_active'] = 'active';

		$testmodals = array('email', 'sms', 'pushover');
		foreach($testmodals as $modal_id) {
			$modal = new \psm\Util\Module\Modal($this->twig, 'test' . ucfirst($modal_id), \psm\Util\Module\Modal::MODAL_TYPE_OKCANCEL);
			$this->addModal($modal);
			$modal->setTitle(psm_get_lang('servers', 'send_' . $modal_id));
			$modal->setMessage(psm_get_lang('config', 'test_' . $modal_id));
			$modal->setOKButtonLabel(psm_get_lang('config', 'send'));
		}
		
		// SNMP tab
		$tpl_data['snmp_oid_list'] = $this->db->select(PSM_DB_PREFIX .'snmp_oid', null, null, '', 'oid_id');

		return $this->twig->render('module/config/config.tpl.html', $tpl_data);
	}

	/**
	 * If a post has been done, gather all the posted data
	 * and save it to the database
	 */
	protected function executeSave() {
		if(!empty($_POST)) {
			// save new config
			$clean = array(
				'language' => $_POST['language'],
				'sms_gateway' => $_POST['sms_gateway'],
				'alert_type' => $_POST['alert_type'],
				'email_smtp_security' =>
					in_array($_POST['email_smtp_security'], array('', 'ssl', 'tls'))
					? $_POST['email_smtp_security']
					: '',
				'auto_refresh_servers' => intval(psm_POST('auto_refresh_servers', 0)),
				'log_retention_period' => intval(psm_POST('log_retention_period', 365)),
                'default_warning_threshold' => intval(psm_POST('default_warning_threshold', 1)),
                'default_timeout'           => intval(psm_POST('default_timeout', 10)),
                'default_type'              => psm_POST('default_type', 'service'),
                'default_active'            => psm_POST('default_active', 'yes'),
                'default_email'             => psm_POST('default_email', 'yes'),
                'default_sms'               => psm_POST('default_sms', 'yes'),
                'default_pushover'          => psm_POST('default_pushover', 'yes'),
                'default_port'              => intval(psm_POST('default_port', 0)),
                'default_snmp_community'    => psm_POST('default_snmp_community', 'public'),
                'default_snmp_version'      => psm_POST('default_snmp_version', '2c'),
                'default_snmp_oid'          => psm_POST('default_snmp_oid', 'sysDescr'),
 			);
			foreach($this->checkboxes as $input_key) {
				$clean[$input_key] = (isset($_POST[$input_key])) ? '1': '0';
			}
			foreach($this->fields as $input_key) {
				if(isset($_POST[$input_key])) {
					$clean[$input_key] = $_POST[$input_key];
				}
			}
			$language_refresh = ($clean['language'] != psm_get_conf('language'));
			foreach($clean as $key => $value) {
				psm_update_conf($key, $value);
			}
			$this->addMessage(psm_get_lang('config', 'updated'), 'success');

			if(!empty($_POST['test_email'])) {
				$this->testEmail();
			} elseif(!empty($_POST['test_sms'])) {
				$this->testSMS();
			} elseif(!empty($_POST['test_pushover'])) {
				$this->testPushover();
			}

			if($language_refresh) {
				header('Location: ' . psm_build_url(array('mod' => 'config'), true, false));
				die();
			}

			if(isset($_POST['general_submit'])) {
				$this->default_tab = 'general';
			} elseif(isset($_POST['email_submit']) || !empty($_POST['test_email'])) {
				$this->default_tab = 'email';
			} elseif(isset($_POST['sms_submit']) || !empty($_POST['test_sms'])) {
				$this->default_tab = 'sms';
			} elseif(isset($_POST['pushover_submit']) || !empty($_POST['test_pushover'])) {
				$this->default_tab = 'pushover';
			}
		}
		return $this->initializeAction('index');
	}

	/**
	 * Execute email test
	 *
	 * @todo move test to separate class
	 */
	protected function testEmail() {
		$mail = psm_build_mail();
		$message = psm_get_lang('config', 'test_message');
		$mail->Subject	= psm_get_lang('config', 'test_subject');
		$mail->Priority	= 1;
		$mail->Body		= $message;
		$mail->AltBody	= str_replace('<br/>', "\n", $message);
		$user = $this->user->getUser();
		$mail->AddAddress($user->email, $user->name);
		if($mail->Send()) {
			$this->addMessage(psm_get_lang('config', 'email_sent'), 'success');
		} else {
			$this->addMessage(psm_get_lang('config', 'email_error') . ': ' . $mail->ErrorInfo, 'error');
		}
	}

	/**
	 * Execute SMS test
	 *
	 * @todo move test to separate class
	 */
	protected function testSMS() {
		$sms = psm_build_sms();
		if($sms) {
			$user = $this->user->getUser();
			if(empty($user->mobile)) {
				$this->addMessage(psm_get_lang('config', 'sms_error_nomobile'), 'error');
			} else {
				$sms->addRecipients($user->mobile);
				if($sms->sendSMS(psm_get_lang('config', 'test_message'))) {
					$this->addMessage(psm_get_lang('config', 'sms_sent'), 'success');
				} else {
					$this->addMessage(psm_get_lang('config', 'sms_error'), 'error');
				}
			}
		}
	}

	/**
	 * Execute pushover test
	 *
	 * @todo move test to separate class
	 */
	protected function testPushover() {
		$pushover = psm_build_pushover();
		$pushover->setDebug(true);
		$user = $this->user->getUser();
		$api_token = psm_get_conf('pushover_api_token');

		if(empty($api_token)) {
			$this->addMessage(psm_get_lang('config', 'pushover_error_noapp'), 'error');
		} elseif(empty($user->pushover_key)) {
			$this->addMessage(psm_get_lang('config', 'pushover_error_nokey'), 'error');
		} else {
			$pushover->setPriority(0);
			$pushover->setTitle(psm_get_lang('config', 'test_subject'));
			$pushover->setMessage(psm_get_lang('config', 'test_message'));
			$pushover->setUser($user->pushover_key);
			if($user->pushover_device != '') {
				$pushover->setDevice($user->pushover_device);
			}
			$result = $pushover->send();

			if(isset($result['output']->status) && $result['output']->status == 1) {
				$this->addMessage(psm_get_lang('config', 'pushover_sent'), 'success');
			} else {
				if(isset($result['output']->errors->error)) {
					$error = $result['output']->errors->error;
				} else {
					$error = 'Unknown';
				}
				$this->addMessage(sprintf(psm_get_lang('config', 'pushover_error'), $error), 'error');
			}
		}
	}

	protected function getLabels() {
		return array(
			'label_tab_email' => psm_get_lang('config', 'tab_email'),
			'label_tab_sms' => psm_get_lang('config', 'tab_sms'),
			'label_tab_pushover' => psm_get_lang('config', 'tab_pushover'),
			'label_tab_snmp' => psm_get_lang('config', 'tab_snmp'),
			'label_settings_email' => psm_get_lang('config', 'settings_email'),
			'label_settings_sms' => psm_get_lang('config', 'settings_sms'),
			'label_settings_pushover' => psm_get_lang('config', 'settings_pushover'),
			'label_settings_notification' => psm_get_lang('config', 'settings_notification'),
			'label_settings_log' => psm_get_lang('config', 'settings_log'),
			'label_settings_snmp' => psm_get_lang('config', 'settings_snmp'),
			'label_settings_default' => psm_get_lang('config', 'settings_default'),
			'label_settings_default_snmp' => psm_get_lang('config', 'settings_snmp'),
			'label_general' => psm_get_lang('config', 'general'),
			'label_language' => psm_get_lang('config', 'language'),
			'label_show_update' => psm_get_lang('config', 'show_update'),
			'label_email_status' => psm_get_lang('config', 'email_status'),
			'label_email_from_email' => psm_get_lang('config', 'email_from_email'),
			'label_email_from_name' => psm_get_lang('config', 'email_from_name'),
			'label_email_smtp' => psm_get_lang('config', 'email_smtp'),
			'label_email_smtp_host' => psm_get_lang('config', 'email_smtp_host'),
			'label_email_smtp_port' => psm_get_lang('config', 'email_smtp_port'),
			'label_email_smtp_security' => psm_get_lang('config', 'email_smtp_security'),
			'label_email_smtp_security_none' => psm_get_lang('config', 'email_smtp_security_none'),
			'label_email_smtp_username' => psm_get_lang('config', 'email_smtp_username'),
			'label_email_smtp_password' => psm_get_lang('config', 'email_smtp_password'),
			'label_email_smtp_noauth' => psm_get_lang('config', 'email_smtp_noauth'),
			'label_sms_status' => psm_get_lang('config', 'sms_status'),
			'label_sms_gateway' => psm_get_lang('config', 'sms_gateway'),
			'label_sms_gateway_mosms' => psm_get_lang('config', 'sms_gateway_mosms'),
			'label_sms_gateway_mollie' => psm_get_lang('config', 'sms_gateway_mollie'),
			'label_sms_gateway_spryng' => psm_get_lang('config', 'sms_gateway_spryng'),
			'label_sms_gateway_inetworx' => psm_get_lang('config', 'sms_gateway_inetworx'),
			'label_sms_gateway_clickatell' => psm_get_lang('config', 'sms_gateway_clickatell'),
			'label_sms_gateway_textmarketer' => psm_get_lang('config', 'sms_gateway_textmarketer'),
			'label_sms_gateway_smsit' => psm_get_lang('config', 'sms_gateway_smsit'),
			'label_sms_gateway_smsglobal' => psm_get_lang('config', 'sms_gateway_smsglobal'),
			'label_sms_gateway_username' => psm_get_lang('config', 'sms_gateway_username'),
			'label_sms_gateway_password' => psm_get_lang('config', 'sms_gateway_password'),
			'label_sms_from' => psm_get_lang('config', 'sms_from'),
			'label_pushover_description' => psm_get_lang('config', 'pushover_description'),
			'label_pushover_status' => psm_get_lang('config', 'pushover_status'),
			'label_pushover_clone_app' => psm_get_lang('config', 'pushover_clone_app'),
			'pushover_clone_url' => PSM_PUSHOVER_CLONE_URL,
			'label_pushover_api_token' => psm_get_lang('config', 'pushover_api_token'),
			'label_pushover_api_token_description' => sprintf(
				psm_get_lang('config', 'pushover_api_token_description'),
				PSM_PUSHOVER_CLONE_URL
			),
			'label_alert_type' => psm_get_lang('config', 'alert_type'),
			'label_alert_type_description' => psm_get_lang('config', 'alert_type_description'),
			'label_alert_type_status' => psm_get_lang('config', 'alert_type_status'),
			'label_alert_type_offline' => psm_get_lang('config', 'alert_type_offline'),
			'label_alert_type_always' => psm_get_lang('config', 'alert_type_always'),
			'label_log_status' => psm_get_lang('config', 'log_status'),
			'label_log_status_description' => psm_get_lang('config', 'log_status_description'),
			'label_log_email' => psm_get_lang('config', 'log_email'),
			'label_log_sms' => psm_get_lang('config', 'log_sms'),
			'label_log_pushover' => psm_get_lang('config', 'log_pushover'),
			'label_auto_refresh' => psm_get_lang('config', 'auto_refresh'),
			'label_auto_refresh_servers' => psm_get_lang('config', 'auto_refresh_servers'),
			'label_seconds' => psm_get_lang('config', 'seconds'),
			'label_save' => psm_get_lang('system', 'save'),
			'label_test' => psm_get_lang('config', 'test'),
			'label_log_retention_period' => psm_get_lang('config', 'log_retention_period'),
			'label_log_retention_period_description' => psm_get_lang('config', 'log_retention_period_description'),
			'label_log_retention_days' => psm_get_lang('config', 'log_retention_days'),
			/* SNMP Label */
			'label_action' => psm_get_lang('system', 'action'),
			'label_edit' => psm_get_lang('system', 'edit'),
			'label_delete' => psm_get_lang('system', 'delete'),
			'label_add_new' => psm_get_lang('system', 'add_new'),
			'label_oid_id' => psm_get_lang('config', 'oid_id'),
			'label_oid_name' => psm_get_lang('config', 'oid_name'),
			'label_oid_label' => psm_get_lang('config', 'oid_label'),
			'label_oid_string' => psm_get_lang('config', 'oid_string'),
			'label_oid_conversion' => psm_get_lang('config', 'oid_conversion'),
			'label_oid_status_up' => psm_get_lang('config', 'oid_status_up'),
			'label_oid_status_warning' => psm_get_lang('config', 'oid_status_warning'),
			'label_oid_status_error' => psm_get_lang('config', 'oid_status_error'),
			'tooltip_oid_string' => psm_get_lang('config', 'oid_string_tooltip'),
			'tooltip_oid_conversion' => psm_get_lang('config', 'oid_conversion_tooltip'),
			'tooltip_oid_status_up' => psm_get_lang('config', 'oid_status_up_tooltip'),
			'tooltip_oid_status_warning' => psm_get_lang('config', 'oid_status_warning_tooltip'),
			'tooltip_oid_status_error' => psm_get_lang('config', 'oid_status_error_tooltip'),
            /* Default value label */
            'label_default_warning_threshold' => psm_get_lang('servers', 'warning_threshold'),
            'label_default_timeout' => psm_get_lang('servers', 'timeout'),
            'label_default_type' => psm_get_lang('servers', 'type'),
            'label_default_port' => psm_get_lang('servers', 'port'),
            'label_default_active' => psm_get_lang('servers', 'monitoring'),
            'label_default_email' => psm_get_lang('servers', 'send_email'),
            'label_default_sms' => psm_get_lang('servers', 'send_sms'),
            'label_default_pushover' => psm_get_lang('servers', 'pushover'),
            'label_snmp_community' => psm_get_lang('snmp', 'community'),
            'label_snmp_version' => psm_get_lang('snmp', 'version'),
            'label_snmp_oid' => psm_get_lang('snmp', 'oid'),
            'label_seconds' => psm_get_lang('common', 'seconds_small'),
			'label_website' => psm_get_lang('servers', 'type_website'),
			'label_service' => psm_get_lang('servers', 'type_service'),
            'label_ping' => psm_get_lang('servers', 'type_ping'),
            'label_snmp' => psm_get_lang('servers', 'type_snmp'),
            'label_yes' => psm_get_lang('system', 'yes'),
            'label_no' => psm_get_lang('system', 'no'),
            'label_snmp_oid_sysuptime' => psm_get_lang('snmp', 'oid_sysuptime'),
            'label_snmp_oid_sysdescr' => psm_get_lang('snmp', 'oid_sysdescr'),
            'label_snmp_oid_sysobjectid' => psm_get_lang('snmp', 'oid_sysobjectid'),
            'label_snmp_oid_syscontact' => psm_get_lang('snmp', 'oid_syscontact'),
            'label_snmp_oid_sysname' => psm_get_lang('snmp', 'oid_sysname'),
            'label_snmp_oid_syslocation' => psm_get_lang('snmp', 'oid_syslocation'),
            'label_snmp_oid_sysservices' => psm_get_lang('snmp', 'oid_sysservices'),
		);
	}
}
