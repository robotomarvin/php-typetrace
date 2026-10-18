--TEST--
typetrace observer: include filter that does not match this file observes nothing
--INI--
typetrace.enable=1
typetrace.include=/nonexistent/dir/
--FILE--
<?php
function not_included($a) { return $a; }
not_included(1);
var_dump(count(json_decode(typetrace_report(), true)['functions']));
?>
--EXPECT--
int(0)
