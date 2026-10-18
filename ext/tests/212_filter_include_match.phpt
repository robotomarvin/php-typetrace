--TEST--
typetrace observer: include filter matching this file (substring) observes
--INI--
typetrace.enable=1
typetrace.include=212_filter_include_match
--FILE--
<?php
function included($a) { return $a; }
included(1);
var_dump(isset(json_decode(typetrace_report(), true)['functions']['included']));
?>
--EXPECT--
bool(true)
