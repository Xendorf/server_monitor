<?php

echo '<pre>'. var_export(snmptable("192.168.75.1", "public", ".1"), true) .'</pre>';

function snmptable($host, $community, $oid) {
    // TODO: get original state and restore at bottom
    snmp_set_oid_numeric_print(TRUE);
    snmp_set_quick_print(TRUE);
    snmp_set_enum_print(TRUE); 

    $retval = array();
    $raw = snmprealwalk($host, $community, $oid);
    if (count($raw) == 0) return ($retval); // no data
    
    $prefix_length = 0; 
    $largest = 0;
    foreach ($raw as $key => $value) {
        if ($prefix_length == 0) {
            // don't just use $oid's length since it may be non-numeric
            $prefix_elements = count(explode('.',$oid));
            $tmp = '.' . strtok($key, '.');
            while ($prefix_elements > 1) {
                $tmp .= '.' . strtok('.');
                $prefix_elements--;
            }
            $tmp .= '.';
            $prefix_length = strlen($tmp);
        }
        $key = substr($key, $prefix_length);
        $index = explode('.', $key, 2);
        isset($retval[$index[1]]) or $retval[$index[1]] = array();
        if ($largest < $index[0]) $largest = $index[0];
        $retval[$index[1]][$index[0]] = $value;
    }

    if (count($retval) == 0) return ($retval); // no data

    // fill in holes and blanks the agent may "give" you
    foreach($retval as $k => $x) {
        for ($i = 1; $i <= $largest; $i++) {
        if (! isset($retval[$k][$i])) {
                $retval[$k][$i] = '';
            }
        }
        ksort($retval[$k]);
    }
    return($retval);
}
?>