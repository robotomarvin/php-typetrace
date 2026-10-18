--TEST--
typetrace accum: bool/null and literal sets, overflow causes
--INI--
typetrace.literal_cap=3
typetrace.string_literal_max=5
--FILE--
<?php
enum Dir: string { case Up = 'up'; case Down = 'down'; }
enum Plain { case A; }
function c(...$v) { return json_decode(typetrace_classify(...$v), true); }
function lit(...$v) { $r = c(...$v); echo json_encode($r['literals'] ?? null), "\n"; }

$r = c(null, true, false, true);
echo json_encode([$r['n'], $r['null'], $r['true'], $r['false'], isset($r['literals'])]), "\n";
lit(null, true, false);
lit(5, 5, 'asc', 'asc', 'asc', 1.5);
lit(2.0, 'b', 1, 'a', -3);                 // 4 distinct > cap 3
lit(3, 3, 3);
lit('abcde');                              // exactly string_literal_max
lit('abcdef');                             // too long
lit('abcdef', 1);                          // overflow sticks
lit(NAN);
lit(INF, 1);
lit([]);
lit(new stdClass);
lit(fn() => 1);
lit(STDIN);
lit(1, [], 1);
lit(Dir::Up, Dir::Down, Dir::Up, Plain::A);
lit(Dir::Up, 1, 'x', 2.5);
lit(0.0, -0.0);
lit(null, true, 7, false);                 // bool/null do not disturb literals
// sorted: ints numerically, then strings binary, floats numerically, then enums
lit(3, -3, 'b');
lit(1.5, 'a', 10);
lit(1.5, -2.5, 3);
$r = c(Dir::Up);
echo json_encode($r['object']), "\n";
?>
--EXPECT--
[4,1,2,1,false]
null
{"overflow":false,"values":[["int",5,2],["string","asc",3],["float",1.5,1]]}
{"overflow":true,"values":[]}
{"overflow":false,"values":[["int",3,3]]}
{"overflow":false,"values":[["string","abcde",1]]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":true,"values":[]}
{"overflow":false,"values":[["enum","Dir::Down",1],["enum","Dir::Up",2],["enum","Plain::A",1]]}
{"overflow":true,"values":[]}
{"overflow":false,"values":[["float",0,2]]}
{"overflow":false,"values":[["int",7,1]]}
{"overflow":false,"values":[["int",-3,1],["int",3,1],["string","b",1]]}
{"overflow":false,"values":[["int",10,1],["string","a",1],["float",1.5,1]]}
{"overflow":false,"values":[["int",3,1],["float",-2.5,1],["float",1.5,1]]}
{"n":1,"classes":{"Dir":1},"overflow":false}
