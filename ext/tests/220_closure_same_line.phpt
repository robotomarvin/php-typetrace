--TEST--
typetrace registry: closure keys carry a deterministic signature hash; same-line closures stay apart
--INI--
typetrace.enable=1
--FILE--
<?php
function sig(string $canon): string {
    $h = 5381;
    foreach (str_split($canon) as $c) $h = ($h * 33 + ord($c)) & 0xFFFFFFFF;
    return sprintf('%08x', $h);
}
$line = __LINE__ + 1;
$fs = [fn(int $x) => $x, fn(string $y) => $y, fn(int $x) => $x, fn(?int ...$z): int => 1];
$fs[0](1); $fs[1]('a'); $fs[2](2); $fs[2](3); $fs[3](1);
$r = json_decode(typetrace_report(), true)['functions'];
$base = '{closure}@' . __FILE__ . ':' . $line . '#';
$want = [
    $base . sig('1;x|0|0|int;r|0|') => 'x:int calls=3',
    $base . sig('1;y|0|0|string;r|0|') => 'y:string calls=1',
    $base . sig('1;z|0|1|?int;r|0|int') => 'z:?int calls=1',
];
foreach ($want as $k => $label) {
    $f = $r[$k] ?? null;
    echo $f ? $f['params'][0]['name'] . ':' . $f['params'][0]['declared'] . ' calls=' . $f['calls'] : "MISSING $k", "\n";
}
var_dump(count(array_filter($r, fn($f) => $f['kind'] === 'closure')));
?>
--EXPECT--
x:int calls=3
y:string calls=1
z:?int calls=1
int(3)
