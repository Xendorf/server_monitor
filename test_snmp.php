<?php
$cyb = false;
$objSNMP = new SNMP(SNMP::VERSION_2C, ($cyb == true ? "192.168.83.254" : "192.168.75.1"), "public");

$objSNMP->valueretrieval = SNMP_VALUE_PLAIN;
/* iso.3.6.1.4.1.21067.2 */
/* iso.3.6.1.4.1.890.3.2.10 */
echo '<pre>'. var_export($objSNMP->walk('.1.3.6.1.4.1'), true) .'</pre>'; /* .1.3.6.1.2.1.1.1 */

$objSNMP->close();
?>