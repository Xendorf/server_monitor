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
 * @author      Jérôme Cabanis <http://lauraly.com>
 * @author      Pepijn Over <pep@neanderthal-technology.com>
 * @copyright   Copyright (c) 2008-2014 Pepijn Over <pep@neanderthal-technology.com>
 * @license     http://www.gnu.org/licenses/gpl.txt GNU GPL v3
 * @version     Release: v3.1.1
 * @link        http://www.phpservermonitor.org/
 **/

namespace psm\Util\Server;
use psm\Service\Database;

/**
 * History util, create HTML for server graphs
 */
class SnmpManager {

	/**
	 * Database service
	 * @var \psm\Service\Database $db
	 */
	protected $db;
	
    protected $_objSNMP       = null;
    protected $hostname       = 'localhost';
    protected $port           = '161'; /* from 1 to 65535 */
    protected $community      = 'public';
    protected $version        = '2C'; /* 1 or 2C */
    protected $valueretrieval = 'plain'; /* plain, libray or object */
    protected $timeout        = 10; /* from 1 to 100 sec */
    protected $retries        = 5; /* from 1 to 100 times */
    protected $autoconversion = true; /* possibile solo per valueretrieval di tipo "plain" */
    protected $last_error     = '';
    
    protected $variable = array(
    	'hostname','port', 'community', 'version', 'valueretrieval', 'timeout', 'retries', 'autoconversion'
		);
    
    public $debug = true;
    
    public $output = array(
        'result'  => '',
        'convert' => '',
        'error'   => '',
        );
    
    protected $arr_custom_oid = array();
    
    protected $arr_system_oid = array(
        'sysDescr'    => '.1.3.6.1.2.1.1.1.0',
        'sysObjectID' => '.1.3.6.1.2.1.1.2.0',
        'sysUpTime'   => '.1.3.6.1.2.1.1.3.0',
        'sysContact'  => '.1.3.6.1.2.1.1.4.0',
        'sysName'     => '.1.3.6.1.2.1.1.5.0',
        'sysLocation' => '.1.3.6.1.2.1.1.6.0',
        'sysServices' => '.1.3.6.1.2.1.1.7.0',
        );

    /**
     * SnmpManager::__construct()
     * Inizializzazione classe SNMP
     * 
     * @param string $host hostname / ip di destinazione
     * @param string $community stringa identificativa community
     * @param string $port porta di connessione
     * @param string $version versione SNMP (1 o 2c)
     * @param integer $timeout timeout in secondi
     * @return
     */
    public function __construct($host='localhost', $community='public', $port='161', $version='2c', $timeout=10, Database $db)
    {
    	$this->db = $db;
    	
        $this->output['error'] = '';
        if (!$this->_('set', 'hostname', $host))       $this->output['error'] = ($this->output['error'] != '' ? ', ' : '') . str_replace('{value}', $host, psm_get_lang('snmp', 'error_value_hostname'));
        if (!$this->_('set', 'community', $community)) $this->output['error'] = ($this->output['error'] != '' ? ', ' : '') . str_replace('{value}', $host, psm_get_lang('snmp', 'error_value_community'));
        if (!$this->_('set', 'port', $port))           $this->output['error'] = ($this->output['error'] != '' ? ', ' : '') . str_replace('{value}', $port, psm_get_lang('snmp', 'error_value_port'));
        if (!$this->_('set', 'version', $version))     $this->output['error'] = ($this->output['error'] != '' ? ', ' : '') . str_replace('{value}', $version, psm_get_lang('snmp', 'error_value_version'));
        if (!$this->_('set', 'timeout', $timeout))     $this->output['error'] = ($this->output['error'] != '' ? ', ' : '') . str_replace('{value}', $timeout, psm_get_lang('snmp', 'error_value_timeout'));
        
        // load oids from db
        $arrTmpOids = $this->db->select(
        		PSM_DB_PREFIX .'snmp_oid',
        		null,      /* no where condition */
        		null,      /* all fields         */
        		'',        /* no limit           */
        		'oid_name' /* order by oid_name  */
			);
        if ($this->debug) $this->_debug(__FUNCTION__, 'oids load from db = '. var_export($arrTmpOids, true));
        // if present 1 or more ...
        if (count($arrTmpOids) > 0)
        {
        	// ... populate $this->arr_custom_oid array using oid_name as index key
        	foreach ($arrTmpOids as $arrOid)
        	{
        		$this->arr_custom_oid[$arrOid['oid_name']] = $arrOid;
        	}
        	if ($this->debug) $this->_debug(__FUNCTION__, 'oids custom = '. var_export($this->arr_custom_oid, true));
        }
        
        $this->output['result'] = ($this->output['error'] != '' ? false : true);
        return $this->output;
    }

    public function _()
    {
        $args = func_get_args();
        $count = count($args);
        /* no arguments */
        if ($count < 1) return false;
        
        /* estrae il primo elemento dall'array */
        $task = array_shift($args);
        if ($this->debug) $this->_debug(__FUNCTION__, 'task = '. $task);
        
        if ($task == 'get' || $task == 'set')
        {
            $fn_name = 'fn'. ucfirst($task) .'Var';
            if (is_callable($fn_name, true) && method_exists(__CLASS__, $fn_name))
            {
                if ($this->debug) $this->_debug(__FUNCTION__, 'fn_name '. $fn_name .' is callable');
                return $this->$fn_name($args);
            }
            else if ($this->debug) $this->_debug(__FUNCTION__, 'fn_name '. $fn_name .' is <strong>not</strong> callable');
        }
        elseif ($task == 'query')
        {
            /* recupera il valore del OID richiesto */
            $oid = $args[0];
            if ($this->fnSNMP_Connect($args) === false) return false;
            $out = $this->fnSNMP_Get($oid);
            $this->fnSNMP_Close();
            return $out;
        }
        elseif ($task == 'convert')
        {
            $oid = $args[0];
            $value = $args[1];
            switch ($oid)
            {
                case 'sysUpTime':
                case 'sysServices':
                    $fn_name = 'fnConvert_'. $oid;
                    return $this->$fn_name($value);
                    break;
                
                default:
                    if ($this->debug) $this->_debug(__FUNCTION__, 'value conversion for oid '. $oid .' not necessary.');
                    return $value;
            }
        }
        else if ($this->debug) $this->_debug(__FUNCTION__, 'task '. $task .' not exists');
    }

    private function _debug($function, $text)
    {
        echo '<span style="font-family: Courier; display:block;background-color:#eaeaea;padding: 2px;">';
        echo '<i>'. __CLASS__ .'-&gt;'. $function .'</i> &gt;&gt;&gt; '. $text .'<br/>';
        echo '</span>';
    }

    private function fnSNMP_Close()
    {
        if (is_object($this->_objSNMP))
        {
            if ($this->debug) $this->_debug(__FUNCTION__, 'close SNMP connection');
            $this->_objSNMP->close();
        }
        $this->_objSNMP = null;
    }

    private function fnSNMP_Connect()
    {
        $oHost      = $this->hostname;
        $oCommunity = $this->community;
        $oPort      = $this->port;
        $oTimeout   = $this->timeout * 1000; /* sec -> millisec */
        $oRetries   = $this->retries;
        $oResult    = '';
        $oError     = '';
        switch ($this->version)
        {
            /**
             * SNMP::VERSION_1 = 0
             * SNMP::VERSION_2c = 1
             * SNMP::VERSION_2C = 1
             * SNMP::VERSION_3 = 3
             **/
            case '1': $oVersion = 0; break; 
            default : $oVersion = 1; break;
        }
        switch ($this->valueretrieval)
        {
            case 'library': $oValueRetrieval = SNMP_VALUE_LIBRARY; break;
            case 'object' : $oValueRetrieval = SNMP_VALUE_OBJECT;  break;
            default       : $oValueRetrieval = SNMP_VALUE_PLAIN;   break;
        }
        $oOidOutputFormat = SNMP_OID_OUTPUT_NUMERIC;
        
        if ($this->debug) $this->_debug(__FUNCTION__, 'start SNMP connection with <strong>'. $oHost .'</strong> on port <strong>'. $oPort .'</strong>');
        $this->_objSNMP = new \SNMP($oVersion, $oHost . ($oPort != 161 ? ':'. $oPort : ''), $oCommunity, $oTimeout, $oRetries);
        
        if ($this->debug) $this->_debug(__FUNCTION__, 'set SNMP valueretrieval = '. $oValueRetrieval);
        $this->_objSNMP->valueretrieval = $oValueRetrieval;

        if ($this->debug) $this->_debug(__FUNCTION__, 'set SNMP oid_output_format = '. $oOidOutputFormat);
        $this->_objSNMP->oid_output_format = $oOidOutputFormat;
        
        return true;
    }
    
    private function fnSNMP_Get($oid)
    {
        $oGet = $oid;
        $oGetKey = '';
        if (array_key_exists($oid, $this->arr_system_oid))
        {
            $oGet = $this->arr_system_oid[$oid];
            $oGetKey = $oid;
        }
        else
        {
	        if (array_key_exists($oid, $this->arr_custom_oid))
	        {
	            $oGet = $this->arr_custom_oid[$oid]['oid_string'];
	            $oGetKey = $oid;
	        }
        }
        if ($this->debug) $this->_debug(__FUNCTION__, 'get value for oid = '. $oGet .($oGet != $oid ? ' (key: '. $oid .')' : ''));
        
        $oResult = @$this->_objSNMP->get($oGet);
        if ($this->debug) $this->_debug(__FUNCTION__, 'result get = '. ($oResult === false ? 'failed' : 'success'));
        if ($oResult === false)
        {
            $this->output['result'] = false;
            $this->output['error'] = $this->_objSNMP->getError();
            if ($this->debug) $this->_debug(__FUNCTION__, 'SNMP error: <strong>'. $this->output['error'] .'</strong>');
        }
        else
        {
            $this->output['result'] = $oResult;
            $this->output['convert'] = false;
            if ($this->_objSNMP->valueretrieval == SNMP_VALUE_PLAIN && $this->autoconversion == true && $oGetKey != '')
            {
                if ($this->debug) $this->_debug(__FUNCTION__, 'convert value '. $oResult);
                $this->output['convert'] = $this->_('convert', $oGetKey, $oResult);
            }
            if ($this->debug) $this->_debug(__FUNCTION__, 'value for oid '. $oGetKey .' = '. (is_object($oResult) ? var_export($oResult, true) : $this->output['convert']) .'');
        }
    }

    private function fnConvert_sysUpTime($value)
    {
        if ($this->debug) $this->_debug(__FUNCTION__, $value);
        $x = floor($value / 100);
        $seconds = $x % 60;
        $x = floor($x / 60);
        $minutes = $x % 60;
        $x = floor($x / 60);
        $hours = $x % 24;
        $x = floor($x / 24);
        $days = $x;
        
        return $days .' {{day'. ($days != 1 ? 's' : '') .'}} '.
               $hours .' {{hour'. ($hours != 1 ? 's' : '') .'}} '. 
               $minutes .' {{minute'. ($minutes != 1 ? 's' : '') .'}} '. 
               $seconds .' {{second'. ($seconds != 1 ? 's' : '') .'}}';
    }
    
    private function fnGetVar()
    {
        $args = func_get_arg(0);
        if (count($args) < 1) return false;
        $name = $args[0];
        if (isset($this->arr_system_oid[$name]))
        {
            if ($this->debug) $this->_debug(__FUNCTION__, 'var '. $name .' are present in arr_system_oid: '. $this->arr_system_oid[$name]);
            return $this->arr_system_oid[$name];
        }
        if (isset($this->$name))
        {
            if ($this->debug) $this->_debug(__FUNCTION__, 'var '. $name .' are present: '. $this->$name);
            return $this->$name;
        }
        if ($this->debug) $this->_debug(__FUNCTION__, 'var '. $name .' not exist');
        return;
    }

    private function fnSetVar()
    {
        // TODO: completare la funzione inserendo eventuali controlli in base alla variabile da impostare?
        $args = func_get_arg(0);
        if (count($args) < 2) return false;
        $name = $args[0];
        $value = $args[1];
        
        if (!in_array($name, $this->variable))
        {
			if ($this->debug) $this->_debug(__FUNCTION__, $name .' can not be set: reserved or unknow');
        	return false;
        }
        
        if (isset($this->$name))
        {
            if ($this->debug) $this->_debug(__FUNCTION__, 'var '. $name .' are present');
            switch (strtolower($name))
            {
                case 'version':
                    $value = strtoupper($value);
                    if ($value != '1' && $value != '2C')
                    {
                        if ($this->debug) $this->_debug(__FUNCTION__, 'value for var '. $name .' are <strong>invalid</strong>');
                        return false;
                    }
                    break;
                    
                case 'port':
                    if ($value < 1 || $value > 65535)
                    {
                        if ($this->debug) $this->_debug(__FUNCTION__, 'value for var '. $name .' are <strong>invalid</strong>');
                        return false;
                    }
                    break;
                
                case 'timeout':
                case 'retries':
                    if ($value < 1 || $value > 100)
                    {
                        if ($this->debug) $this->_debug(__FUNCTION__, 'value for var '. $name .' are <strong>invalid</strong>');
                        return false;
                    }
                    break;
            }
            if ($this->debug) $this->_debug(__FUNCTION__, 'value for var '. $name .' are changed from <strong>'. $this->$name .'</strong> to <strong>'. $value .'</strong>');
            $this->$name = $value;
            return true;
        }
        
        return false;
    }
}
