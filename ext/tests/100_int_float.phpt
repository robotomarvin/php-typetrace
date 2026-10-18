--TEST--
typetrace accum: int and float buckets
--FILE--
<?php
function c(...$v) { return json_decode(typetrace_classify(...$v), true); }
$r = c(0, 1, 2, 99, -1, -7, PHP_INT_MAX, PHP_INT_MIN);
echo json_encode($r['int']), "\n";
echo $r['n'], "\n";
$r = c(0.0, 1.0, 2.5, -0.5, 3.0, NAN, INF, -INF);
echo json_encode($r['float']), "\n";
$r = c(NAN, INF);
echo json_encode($r['float']), "\n";
$raw = typetrace_classify(3.0, 0.5);
var_dump(str_contains($raw, '"max":3.0'));
var_dump(json_decode($raw, true)['float']['max']);
// references are dereferenced, values untouched
$x = 5; $ref = &$x;
$r = c($ref);
echo json_encode($r['int']), "\n";
var_dump($x);
var_dump(typetrace_classify());
?>
--EXPECT--
{"n":8,"zero":1,"one":1,"pos":3,"neg":3,"min":-9223372036854775808,"max":9223372036854775807}
8
{"n":8,"integral":3,"frac":2,"nonfinite":3,"min":-0.5,"max":3}
{"n":2,"integral":0,"frac":0,"nonfinite":2}
bool(true)
float(3)
{"n":1,"zero":0,"one":0,"pos":1,"neg":0,"min":5,"max":5}
int(5)
string(4) "null"
