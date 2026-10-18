--TEST--
typetrace accum: merge is commutative, associative, propagates overflow
--INI--
typetrace.literal_cap=4
typetrace.class_cap=2
typetrace.shape_cap=3
typetrace.array_sample=2
--FILE--
<?php
enum E { case A; case B; }
class K1 {} class K2 {} class K3 {}
$sets = [
    'empty'   => [],
    'ints'    => [0, 1, 5, -3],
    'ints2'   => [7, 8, 9],
    'floats'  => [0.5, 2.0, NAN],
    'floats2' => [-1.5, INF, 3.25],
    'strings' => ['', '0', '1', '42', '007', ' 5', '1e3', 'abc'],
    'strings2'=> ['hello', '-9', str_repeat('x', 100), '99999999999999999999'],
    'scalars' => [null, true, false, null],
    'objs'    => [new K1, new K2, E::A],
    'objs2'   => [new K3, new K1, fn() => 1],
    'res'     => [STDIN, STDOUT],
    'arrays'  => [[], [1, 2, 3], ['a' => 1, 'b' => [1, 2]], ['a' => 'x']],
    'arrays2' => [['c' => 1.5], [[1], [2]], ['a' => null, 'd' => 1, 'e' => 2]],
    'mixed'   => [1, 'a', 2.5, null, [1], new K1, STDIN],
    'lits'    => [1, 2, 3, 'a', 'b'],
    'lits2'   => ['c', 'd', 4],
];
$bad = 0; $pairs = 0;
foreach ($sets as $an => $a) {
    foreach ($sets as $bn => $b) {
        $pairs++;
        $ab = typetrace_merge_classify($a, $b);
        $ba = typetrace_merge_classify($b, $a);
        if ($ab !== $ba) { $bad++; echo "NOT COMMUTATIVE: $an / $bn\n$ab\n$ba\n"; }
        json_decode($ab, true, 512, JSON_THROW_ON_ERROR);
        // merge == observing everything in one accumulator (holds without sampling truncation effects)
        if (!in_array('arrays', [$an, $bn]) && !in_array('arrays2', [$an, $bn]) && !in_array('mixed', [$an, $bn])) {
            $all = typetrace_classify(...$a, ...$b);
            if ($ab !== $all && !($a === [] && $b === [])) { $bad++; echo "MERGE != OBSERVE: $an / $bn\n"; }
        }
    }
}
echo "pairs=$pairs bad=$bad\n";

// associativity: merge(a, b+c) === merge(a+b, c) for triples (both equal observing everything)
$triples = [['ints', 'floats', 'strings'], ['objs', 'objs2', 'lits'], ['lits', 'lits2', 'ints'],
            ['arrays', 'arrays2', 'mixed'], ['scalars', 'res', 'strings2'], ['floats2', 'ints2', 'objs']];
$bad = 0;
foreach ($triples as [$x, $y, $z]) {
    $l = typetrace_merge_classify($sets[$x], [...$sets[$y], ...$sets[$z]]);
    $r = typetrace_merge_classify([...$sets[$x], ...$sets[$y]], $sets[$z]);
    if ($l !== $r) { $bad++; echo "NOT ASSOCIATIVE: $x $y $z\n$l\n$r\n"; }
}
echo "assoc bad=$bad\n";

function m($a, $b) { return json_decode(typetrace_merge_classify($a, $b), true); }
// overflow propagation
echo json_encode(m([1, 2, 3, 4], [5])['literals']), "\n";            // union 5 > cap 4
echo json_encode(m([1, 2, 3, 4], [1, 2])['literals']), "\n";          // union 4 == cap
echo json_encode(m([1, [1]], [2])['literals']), "\n";                 // one side overflowed
echo json_encode(m([new K1, new K2], [new K3])['object']), "\n";
echo json_encode(m([new K1], [new K1, new K2])['object']), "\n";
$s = m([['a' => 1, 'b' => 1]], [['c' => 1, 'd' => 1]])['array']['shape'];
echo json_encode([$s['n'], $s['overflow'], $s['keys']]), "\n";
$s = m([['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1]], [['a' => 1]])['array']['shape'];
echo json_encode([$s['n'], $s['overflow'], $s['keys']]), "\n";
$s = m([['a' => 1]], [['a' => 2, 'b' => 3]])['array']['shape'];
echo json_encode([$s['n'], $s['keys']['a']['n'], $s['keys']['b']['n'], $s['keys']['a']['acc']['int']['max']]), "\n";
// min/max, truncated OR, counters
$r = m([3, 10], [-4, 7]);
echo json_encode($r['int']), "\n";
$r = m([1.5], [-2.5, NAN]);
echo json_encode($r['float']), "\n";
$r = m([NAN], [1.5]);
echo json_encode($r['float']), "\n";
$r = m(['ab'], ['', 'abcd']);
echo json_encode([$r['string']['len_min'], $r['string']['len_max'], $r['string']['n']]), "\n";
$r = m([[1]], [[1, 2, 3]])['array'];
echo json_encode([$r['truncated'], $r['count_min'], $r['count_max'], $r['keys']['n'], $r['values']['n']]), "\n";
$r = m([], [null, true]);
echo json_encode([$r['n'], $r['null'], $r['true']]), "\n";
var_dump(typetrace_merge_classify([], []));
?>
--EXPECT--
pairs=256 bad=0
assoc bad=0
{"overflow":true,"values":[]}
{"overflow":false,"values":[["int",1,2],["int",2,2],["int",3,1],["int",4,1]]}
{"overflow":true,"values":[]}
{"n":3,"classes":[],"overflow":true}
{"n":3,"classes":{"K1":2,"K2":1},"overflow":false}
[2,true,[]]
[2,true,[]]
[2,2,1,2]
{"n":4,"zero":0,"one":0,"pos":3,"neg":1,"min":-4,"max":10}
{"n":3,"integral":0,"frac":2,"nonfinite":1,"min":-2.5,"max":1.5}
{"n":2,"integral":0,"frac":1,"nonfinite":1,"min":1.5,"max":1.5}
[0,4,3]
[true,1,3,3,3]
[2,1,1]
string(4) "null"
