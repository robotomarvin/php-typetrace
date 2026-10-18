--TEST--
typetrace observer: by-ref params record in (acc) and out values
--INI--
typetrace.enable=1
--FILE--
<?php
function br(&$x, $y) { $x = (string)$x . '!'; }
$v = 1; br($v, 0);
$v = 2; br($v, 0);
$w = 'a'; br($w, 0);

function br_unset(&$x) { unset($x); }
$q = 1; br_unset($q);

$fs = json_decode(typetrace_report(), true)['functions'];
$p = $fs['br']['params'][0];
echo 'by_ref=', var_export($p['by_ref'], true), ' in int=', $p['acc']['int']['n'], ' in string=', $p['acc']['string']['n'], "\n";
echo 'out n=', $p['out']['n'], ' string=', $p['out']['string']['n'], "\n";
var_dump($fs['br']['params'][1]['by_ref'], $fs['br']['params'][1]['out']);
var_dump($fs['br_unset']['params'][0]['out']);
?>
--EXPECT--
by_ref=true in int=2 in string=1
out n=3 string=3
bool(false)
NULL
NULL
