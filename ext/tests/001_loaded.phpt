--TEST--
typetrace: extension loads and exposes functions
--INI--
typetrace.enable=1
--FILE--
<?php
var_dump(extension_loaded('typetrace'));
foreach (['typetrace_classify', 'typetrace_merge_classify', 'typetrace_report', 'typetrace_flush', 'typetrace_reset'] as $f) {
    var_dump(function_exists($f));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
