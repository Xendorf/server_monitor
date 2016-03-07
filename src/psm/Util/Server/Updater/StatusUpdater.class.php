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

/**
 * The status class is for checking the status of a server.
 *
 * @see \psm\Util\Server\Updater\StatusNotifier
 * @see \psm\Util\Server\Updater\Autorun
 */
namespace psm\Util\Server\Updater;
use psm\Service\Database;

class StatusUpdater {
	public $error = '';

	public $rtime = 0;

	public $status_new = false;

	/**
	 * Database service
	 * @var \psm\Service\Database $db
	 */
	protected $db;

	/**
	 * Server id to check
	 * @var int $server_id
	 */
	protected $server_id;

	/**
	 * Server information
	 * @var array $server
	 */
	protected $server;
    
    /**
     * SNMP information result
     * @var array $snmp
     **/
    protected $snmp = array('raw' => '', 'convert' => '');

	function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * The function its all about. This one checks whether the given ip and port are up and running!
	 * If the server check fails it will try one more time, depending on the $max_runs.
	 *
	 * Please note: if the server is down but has not met the warning threshold, this will return true
	 * to avoid any "we are down" events.
	 * @param int $server_id
	 * @param int $max_runs how many times should the script recheck the server if unavailable. default is 2
	 * @return boolean TRUE if server is up, FALSE otherwise
	 */
	public function update($server_id, $max_runs = 2) {
		$this->server_id = $server_id;
		$this->error = '';
		$this->rtime = '';

		// get server info from db
		$this->server = $this->db->selectRow(PSM_DB_PREFIX . 'servers', array(
			'server_id' => $server_id,
		), array(
			'server_id', 'ip', 'port', 'label', 'type', 'pattern', 'status', 'rtime', 'active', 'warning_threshold', 'warning_threshold_counter', 'timeout',
            'snmp_oid',
		));
		
		// get snmp info from db
		$snmp = $this->db->selectRow(PSM_DB_PREFIX .'snmp', array(
			'server_id' => $server_id,
		), array(
			'snmp_id', 'snmp_community', 'snmp_version'
		));
		// if not found ... 
		if (empty($snmp['snmp_id']))
		{
			$snmp = array(
				'snmp_id'        => '0',
				'snmp_community' => '',
				'snmp_version'   => '',
				);
		}
		
		// merge / add to $server
		foreach ($snmp as $snmp_field => $snmp_value)
		{
			if (empty($this->server[$snmp_field])) $this->server[$snmp_field] = $snmp_value;
		}
		
		if(empty($this->server)) {
			return false;
		}

		if (psm_is_cli()) echo 'Server: '. $this->server['label'] .' [type: '. $this->server['type'] .']' ."\n";
		switch($this->server['type']) {
			case 'service':
				$this->status_new = $this->updateService($max_runs);
				break;
			case 'website':
				$this->status_new = $this->updateWebsite($max_runs);
				break;
			case 'ping':
				$this->status_new = $this->updatePing($max_runs);
				break;
			case 'snmp':
				$this->status_new = $this->updateSNMP($max_runs);
				break;
		}
		if (psm_is_cli()) echo 'Server: '. $this->server['label'] .' [result: '. ($this->status_new ? 'on' : 'off') .']' ."\n";

		// update server status
		$save = array(
			'last_check' => date('Y-m-d H:i:s'),
			'error' => $this->error,
			'rtime' => $this->rtime,
            'snmp_value_raw' => trim(''. $this->snmp['raw']),
            'snmp_value_convert' => trim(''. $this->snmp['convert']),
		);

		// log the uptime before checking the warning threshold,
		// so that the warnings can still be reviewed in the server history.
		psm_log_uptime($this->server_id, (int) $this->status_new, $this->rtime);

		if($this->status_new == true) {
			// if the server is on, add the last_online value and reset the error threshold counter
			$save['status'] = 'on';
			$save['last_online'] = date('Y-m-d H:i:s');
			$save['warning_threshold_counter'] = 0;
		} else {
			// server is offline, increase the error counter
			$save['warning_threshold_counter'] = $this->server['warning_threshold_counter'] + 1;

			if($save['warning_threshold_counter'] < $this->server['warning_threshold']) {
				// the server is offline but the error threshold has not been met yet.
				// so we are going to leave the status "on" for now while we are in a sort of warning state..
				$save['status'] = 'on';
				$this->status_new = true;
			} else {
				$save['status'] = 'off';
			}
		}

		$this->db->save(PSM_DB_PREFIX . 'servers', $save, array('server_id' => $this->server_id));

		return $this->status_new;

	}

    /**
     * Check the current server with a ping and hope to get a pong
     * @param int $max_runs
     * @param int $run
     * @return boolean
     */
    protected function updatePing($max_runs, $run = 1) {
        $errno		= 0;
        /* timeout min: 5 sec */
        $timeout	= ($this->server['timeout'] < 5 ? 5 : $this->server['timeout']);
        /* ICMP ping packet with a pre-calculated checksum */
        $package	= "\x08\x00\x7d\x4b\x00\x00\x00\x00PingHost";
        /* save response time */
        $starttime 	= microtime(true);
        
        /* if ipv6 we have to use AF_INET6 */
        if (psm_validate_ipv6($this->server['ip'])) {
            /* Need to remove [] on ipv6 address */
            $this->server['ip'] = trim($this->server['ip'], '[]');
            $socket  = socket_create(AF_INET6, SOCK_RAW, 1);
        } else {
            $socket  = socket_create(AF_INET, SOCK_RAW, 1);
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
        socket_connect($socket, $this->server['ip'], null);
        socket_send($socket, $package, strLen($package), 0);
        
        /* if ping fails it returns false ... */
        $status = (@socket_read($socket, 255)) ? true : false;
        $this->rtime = (microtime(true) - $starttime);
        /* ... and error reason */
        if (!$status) $this->error = socket_last_error() .': '. socket_strerror(socket_last_error());
        
        socket_close($socket);
        
        /* check if server is available and rerun if asked. */
        if(!$status && $run < $max_runs) {
            return $this->updatePing($max_runs, $run + 1);
        }
        
        return $status;
    }
    
	/**
	 * Check the current server as a service
	 * @param int $max_runs
	 * @param int $run
	 * @return boolean
	 */
	protected function updateService($max_runs, $run = 1) {
		$errno = 0;
		// save response time
		$starttime = microtime(true);
		
		$fp = fsockopen ($this->server['ip'], $this->server['port'], $errno, $this->error, 10);
		
		$status = ($fp === false) ? false : true;
		$this->rtime = (microtime(true) - $starttime);
		
		if(is_resource($fp)) {
			fclose($fp);
		}
        
		// check if server is available and rerun if asked.
		if(!$status && $run < $max_runs) {
			return $this->updateService($max_runs, $run + 1);
		}

		return $status;
	}

    /**
     * Check the current server with a ping and hope to get a pong
     * @param int $max_runs
     * @param int $run
     * @return boolean
     */
    protected function updateSNMP($max_runs, $run = 1) {
        
        $objSnmp = new \psm\Util\Server\SnmpManager(
                $this->server['ip'],
                $this->server['snmp_community'],
                $this->server['port'],
                $this->server['snmp_version'],
                $this->server['timeout'],
                $this->db
            );
        $objSnmp->debug = true;
        if ($objSnmp->output['result'] === false)
        {
            $this->error = $objSnmp->output['error'];
            return false;
        }
        
        $oid = $this->server['snmp_oid'];
        
        /* save start time */
        $starttime 	= microtime(true);
        
        /* get oid value */
        $status = $objSnmp->_('query', $oid);
        
        /* save response time */
        $this->rtime = (microtime(true) - $starttime);
        
        /* ... and error reason if the case */
        if ($status === false)
        {
            $this->error = $objSnmp->output['error'];
        }
        else
        {
            $this->snmp['raw'] = $objSnmp->output['result'];
            $this->snmp['convert'] = $objSnmp->output['convert'];
        }
        
        /* check if server is available and rerun if asked. */
        if($status === false && $run < $max_runs) {
            return $this->updateSNMP($max_runs, $run + 1);
        }
        
        return ($status === false ? false : true);
    }
    
	/**
	 * Check the current server as a website
	 * @param int $max_runs
	 * @param int $run
	 * @return boolean
	 */
	protected function updateWebsite($max_runs, $run = 1) {
		$starttime = microtime(true);

		// Parse a URL and return its components
		$url = parse_url($this->server['ip']);
		
		// Build url
		$this->server['ip'] = $url['scheme'] .'://'. (psm_validate_ipv6($url['host']) ? '['. $url['host'] .']' : $url['host']) .':'. $this->server['port'] . (isset($url['path']) ? $url['path'] : '') . (isset($url['query']) ? '?'. $url['query'] : '');
		
		// We're only interested in the header, because that should tell us plenty!
		// unless we have a pattern to search for!
		$curl_result = psm_curl_get(
			$this->server['ip'],
			true,
			($this->server['pattern'] == '' ? false : true),
			$this->server['timeout']
		);

		$this->rtime = (microtime(true) - $starttime);

		// the first line would be the status code..
		$status_code = strtok($curl_result, "\r\n");
		// keep it general
		// $code[1][0] = status code
		// $code[2][0] = name of status code
		$code_matches = array();
		preg_match_all("/[A-Z]{2,5}\/\d\.\d\s(\d{3})\s(.*)/", $status_code, $code_matches);

		if(empty($code_matches[0])) {
			// somehow we dont have a proper response.
			$this->error = psm_get_lang('error_server_no_response');
			$result = false;
		} else {
			$code = $code_matches[1][0];
			$msg = $code_matches[2][0];

			// All status codes starting with a 4 or higher mean trouble!
			if(substr($code, 0, 1) >= '4') {
				$this->error = $code . ' ' . $msg;
				$result = false;
			} else {
				$result = true;
			}
		}
		if($this->server['pattern'] != '') {
			// Check to see if the pattern was found.
			if(!preg_match("/{$this->server['pattern']}/i", $curl_result)) {
				$this->error = psm_get_lang('error_server_pattern_not_found');
				$result = false;
			}
		}

		// check if server is available and rerun if asked.
		if(!$result && $run < $max_runs) {
			return $this->updateWebsite($max_runs, $run + 1);
		}

		return $result;
	}

	/**
	 * Get the error returned by the update function
	 *
	 * @return string
	 */
	public function getError() {
		return $this->error;
	}

	/**
	 * Get the response time of the server
	 *
	 * @return string
	 */
	public function getRtime() {
		return $this->rtime;
	}
}
