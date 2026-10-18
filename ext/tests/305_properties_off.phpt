--TEST--
typetrace props: typetrace.properties=0 records nothing (even with sweep=1)
--INI--
typetrace.enable=1
typetrace.properties=0
typetrace.sweep=1
--FILE--
<?php
class E { public $x; }
$e = new E; $e->x = 1;
var_dump(json_decode(typetrace_report(), true)['properties']);
?>
--EXPECT--
array(0) {
}
