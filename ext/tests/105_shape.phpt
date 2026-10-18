--TEST--
typetrace accum: map shapes, optional keys, shape overflow, recursion guard
--INI--
typetrace.shape_cap=3
typetrace.array_depth=3
--FILE--
<?php
function c(...$v) { return json_decode(typetrace_classify(...$v), true); }
function shape(...$v) {
    $s = c(...$v)['array']['shape'] ?? null;
    if ($s) foreach ($s['keys'] as $k => &$e) { $e['acc'] = $e['acc'] ? array_keys($e['acc']) : null; }
    echo json_encode($s), "\n";
}
shape([1, 2, 3]);                                   // lists have no shape
shape([]);
shape(['id' => 1, 'name' => 'a']);
shape(['id' => 1, 'name' => 'a'], ['id' => 2], ['id' => 3, 'name' => null]);
shape([5 => 'x', 'k' => 1]);                        // int keys as decimal strings
shape(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);    // one map > cap
shape(['a' => 1, 'b' => 2], ['c' => 3, 'd' => 4]);  // union > cap
shape(['a' => 1], [1, 2], ['a' => 1]);               // lists don't contribute
shape(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 9]);   // exactly cap is fine
// nested shapes
$s = c(['u' => ['x' => 1], 'v' => ['x' => 2, 'y' => 3]])['array']['shape']['keys'];
echo json_encode(array_keys($s['u']['acc']['array']['shape']['keys'])), "\n";
// depth limit: beyond depth the shape entries carry no acc
$s = c(['a' => ['b' => ['c' => ['d' => 1]]]])['array']['shape'];
$d1 = $s['keys']['a']['acc']['array']['shape'];
$d2 = $d1['keys']['b']['acc']['array']['shape'];
$d3 = $d2['keys']['c'];
$d4 = $d3['acc']['array']['shape']['keys']['d'];   // depth 3: counted, no acc
echo json_encode([$d3['n'], $d3['acc'] === null, $d4['n'], $d4['acc'] === null]), "\n";
echo json_encode(strpos(typetrace_classify(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]), '"overflow":true,"keys":{}') !== false), "\n";
// recursive array
$a = [1];
$a[] = &$a;
$r = c($a)['array'];
echo json_encode([$r['n'], $r['truncated'], $r['values']['array']['n'], $r['values']['array']['truncated']]), "\n";
$m = ['k' => 1];
$m['self'] = &$m;
$r = c($m)['array'];
echo json_encode([$r['n'], $r['map'], $r['values']['array']['n'], $r['values']['array']['truncated']]), "\n";
var_dump(count($a), count($m));
?>
--EXPECT--
null
null
{"n":1,"overflow":false,"keys":{"id":{"n":1,"acc":["n","int","literals"]},"name":{"n":1,"acc":["n","string","literals"]}}}
{"n":3,"overflow":false,"keys":{"id":{"n":3,"acc":["n","int","literals"]},"name":{"n":2,"acc":["n","null","string","literals"]}}}
{"n":1,"overflow":false,"keys":{"5":{"n":1,"acc":["n","string","literals"]},"k":{"n":1,"acc":["n","int","literals"]}}}
{"n":1,"overflow":true,"keys":[]}
{"n":2,"overflow":true,"keys":[]}
{"n":2,"overflow":false,"keys":{"a":{"n":2,"acc":["n","int","literals"]}}}
{"n":2,"overflow":false,"keys":{"a":{"n":2,"acc":["n","int","literals"]},"b":{"n":1,"acc":["n","int","literals"]},"c":{"n":1,"acc":["n","int","literals"]}}}
["x"]
[1,false,1,true]
true
[1,false,1,true]
[1,1,1,true]
int(2)
int(2)
