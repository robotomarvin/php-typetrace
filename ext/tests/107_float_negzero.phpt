--TEST--
typetrace accum: -0.0 and 0.0 give order-independent min/max
--INI--
typetrace.enable=1
--FILE--
<?php
function r(...$v) { $f = json_decode(typetrace_classify(...$v), true)['float']; return json_encode([$f['min'], $f['max']], JSON_PRESERVE_ZERO_FRACTION); }
echo r(-0.0, 0.0), "\n", r(0.0, -0.0), "\n", r(-0.0), "\n";
echo typetrace_classify(-0.0, 0.0) === typetrace_classify(0.0, -0.0) ? "same\n" : "differ\n";
// merge path: accumulate across reports of different order
function g($v) { return $v; }
g(-0.0); g(0.0);
$a = json_decode(typetrace_report(), true)['functions']['g']['params'][0]['acc']['float'];
echo json_encode([$a['min'], $a['max']], JSON_PRESERVE_ZERO_FRACTION), "\n";
?>
--EXPECT--
[0.0,0.0]
[0.0,0.0]
[0.0,0.0]
same
[0.0,0.0]
