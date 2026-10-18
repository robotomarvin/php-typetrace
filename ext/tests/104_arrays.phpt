--TEST--
typetrace accum: arrays, sampling, depth, truncation
--INI--
typetrace.array_sample=3
typetrace.array_depth=2
--FILE--
<?php
function c(...$v) { return json_decode(typetrace_classify(...$v), true); }
function arr(...$v) {
    $a = c(...$v)['array'];
    $keys = $a['keys']; $vals = $a['values'];
    unset($a['keys'], $a['values'], $a['shape']);
    echo json_encode($a), " keys.n=", $keys['n'] ?? 'null', " values.n=", $vals['n'] ?? 'null', "\n";
}
arr([]);
arr([1, 2]);
arr([1 => 1, 2 => 2]);        // not a list
arr(['a' => 1]);
arr([], [1], ['x' => 1], [1, 2, 3, 4, 5]);
arr([1, 2, 3]);               // exactly sample: not truncated
arr([1, 2, 3, 4]);            // more than sample
// keys / values accumulators
$a = c(['a' => 1, 'b' => 'x', 5 => 2.5])['array'];
echo json_encode($a['keys']['string']['n']), json_encode($a['keys']['int']['n']), json_encode($a['values']['float']['n']), "\n";
// depth: top level = 0, array_depth=2 -> arrays at depth 0 and 1 recurse, the array at depth 2 does not
$a = c([[[1]]])['array'];
$l1 = $a['values']['array']; $l2 = $l1['values']['array'];
echo json_encode([$a['truncated'], $l1['truncated'], $l2['truncated']]), "\n";
echo json_encode([isset($l2['values']), isset($l2['keys']), $l2['list']]), "\n";
$a = c([[1]])['array'];
echo json_encode([$a['truncated'], $a['values']['array']['truncated']]), "\n";
$a = c([[[]]])['array'];     // empty array at depth 2 is not truncated
echo json_encode([$a['values']['array']['values']['array']['empty'], $a['values']['array']['values']['array']['truncated']]), "\n";
// by-reference elements and nested sampling
$x = 7; $arr = [&$x, 8];
$a = c($arr)['array'];
echo json_encode($a['values']['int']), "\n";
var_dump($x, count($arr));
?>
--EXPECT--
{"n":1,"empty":1,"list":0,"map":0,"count_min":0,"count_max":0,"truncated":false} keys.n=null values.n=null
{"n":1,"empty":0,"list":1,"map":0,"count_min":2,"count_max":2,"truncated":false} keys.n=2 values.n=2
{"n":1,"empty":0,"list":0,"map":1,"count_min":2,"count_max":2,"truncated":false} keys.n=2 values.n=2
{"n":1,"empty":0,"list":0,"map":1,"count_min":1,"count_max":1,"truncated":false} keys.n=1 values.n=1
{"n":4,"empty":1,"list":2,"map":1,"count_min":0,"count_max":5,"truncated":true} keys.n=5 values.n=5
{"n":1,"empty":0,"list":1,"map":0,"count_min":3,"count_max":3,"truncated":false} keys.n=3 values.n=3
{"n":1,"empty":0,"list":1,"map":0,"count_min":4,"count_max":4,"truncated":true} keys.n=3 values.n=3
211
[false,false,true]
[false,false,1]
[false,false]
[1,false]
{"n":2,"zero":0,"one":0,"pos":2,"neg":0,"min":7,"max":8}
int(7)
int(2)
