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
 * @since       phpservermon 3.0.0
 **/

namespace psm\Util\Server;
use psm\Service\Database;
use psm\Service\User;

/**
 * Run an update on all servers.
 *
 * If you provide a User service instance it will be
 * restricted to that user only.
 */
class UpdateManager {

	/**
	 * Database service
	 * @var \psm\Service\Database $db
	 */
	protected $db;

	/**
	 * User service
	 * @var \psm\Service\User $user
	 */
	protected $user;

	/**
	 * ID Server to update
	 * @var $serverid
	 */
	protected $serverid;
    
	function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Go :-)
	 */
	public function run() {
		// check if we need to restrict the servers to a certain user
		$sql_join = '';
        $sql_where = '';
        $sql_order = '';

		if($this->user != null && $this->user->getUserLevel() > PSM_USER_ADMIN) {
			// restrict by user_id
			$sql_join = "JOIN `".PSM_DB_PREFIX."users_servers` AS `us` ON (
						`us`.`user_id`={$this->user->getUserId()}
						AND `us`.`server_id`=`s`.`server_id`
						)";
		}
        
        $sql_where .= ($sql_where != '' ? ' AND ' : ' WHERE ') .'`active`=\'yes\' ';
        
        if ($this->serverid > 0 && $this->user->getUserLevel() == PSM_USER_ADMIN)
        {
            $sql_where .= ($sql_where != '' ? ' AND ' : ' WHERE ') .'`s`.`server_id` = \''. $this->serverid .'\' ';
        }
        
        $sql_order = 'ORDER BY `s`.`label`';

		$sql = "SELECT `s`.`server_id`,`s`.`ip`,`s`.`port`,`s`.`label`,`s`.`type`,`s`.`pattern`,`s`.`status`,`s`.`active`,`s`.`email`,`s`.`sms`,`s`.`pushover`
				FROM `".PSM_DB_PREFIX."servers` AS `s`
				{$sql_join} 
				{$sql_where} 
                {$sql_order} ";

		$servers = $this->db->query($sql);

		$updater = new Updater\StatusUpdater($this->db);
		$notifier = new Updater\StatusNotifier($this->db);

		foreach($servers as $server) {
			$status_old = ($server['status'] == 'on') ? true : false;
			$status_new = $updater->update($server['server_id']);
			// notify the nerds if applicable
			$notifier->notify($server['server_id'], $status_old, $status_new);
		}

		// clean-up time!! archive all records
		$archive = new ArchiveManager($this->db);
		$archive->archive();
		$archive->cleanup();
	}

	/**
	 * Set a user to restrict the servers being updated
	 * @param \psm\Service\User $user
	 */
	public function setUser(User $user) {
		$this->user = $user;
	}

	/**
	 * Set a server id to restrict the servers being updated
	 * @param $serverid
	 */
	public function setServerID($serverid) {
		if ($serverid > 0) $this->serverid = $serverid;
	}
}