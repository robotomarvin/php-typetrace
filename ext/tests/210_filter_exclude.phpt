--TEST--
typetrace observer: exclude filter (substring of this file's name) skips all functions here
--INI--
typetrace.enable=1
typetrace.exclude=210_filter_exclude
--FILE--
<?php
function excluded_fn($a) { return $a; }
excluded_fn(1);
$r = json_decode(typetrace_report(), true);
var_dump(isset($r['functions']['excluded_fn']));
var_dump(count($r['functions']));
?>
--EXPECT--
bool(false)
int(0)
