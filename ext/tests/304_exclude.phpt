--TEST--
typetrace props: writes from excluded files are not recorded
--INI--
typetrace.enable=1
typetrace.exclude=tests/
--FILE--
<?php
class E { public $x; }
$e = new E; $e->x = 1;
var_dump(json_decode(typetrace_report(), true)['properties']);
?>
--EXPECT--
array(0) {
}
