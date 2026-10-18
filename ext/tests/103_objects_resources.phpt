--TEST--
typetrace accum: objects, enums, closures, resources, class_cap
--INI--
typetrace.class_cap=3
--FILE--
<?php
namespace App;
enum Suit { case Hearts; }
class A {} class B {} class C {} class D {}
function c(...$v) { return json_decode(\typetrace_classify(...$v), true); }

$r = c(new A, new A, new B, fn() => 1, Suit::Hearts);
echo json_encode($r['object']), "\n";          // 4 distinct classes > cap 3
$r = c(new B, new A, new B);
echo json_encode($r['object']), "\n";
$r = c(fn() => 1, function () {}, \Closure::fromCallable('strlen'));
echo json_encode($r['object']), "\n";
$r = c(Suit::Hearts);
echo json_encode($r['object']), json_encode($r['literals']), "\n";
$r = c(new A, new B, new C, new A);
echo json_encode($r['object']), "\n";
$r = c(new A, new B, new C, new D, new A);
echo json_encode($r['object']), "\n";
$r = c(\STDIN, \STDOUT, \STDIN);
echo json_encode($r['resource']), "\n";
$f = fopen('php://memory', 'r'); fclose($f);
$r = c($f, \STDIN);
echo json_encode($r['resource']), "\n";
// merge propagates class overflow
echo json_encode(json_decode(\typetrace_merge_classify([new A, new B], [new C, new D]), true)['object']), "\n";
echo json_encode(json_decode(\typetrace_merge_classify([new A, new B], [new B, new C]), true)['object']), "\n";
echo json_encode(json_decode(\typetrace_merge_classify([new A, new B, new C, new D], [new A]), true)['object']), "\n";
?>
--EXPECT--
{"n":5,"classes":[],"overflow":true}
{"n":3,"classes":{"App\\A":1,"App\\B":2},"overflow":false}
{"n":3,"classes":{"Closure":3},"overflow":false}
{"n":1,"classes":{"App\\Suit":1},"overflow":false}{"overflow":false,"values":[["enum","App\\Suit::Hearts",1]]}
{"n":4,"classes":{"App\\A":2,"App\\B":1,"App\\C":1},"overflow":false}
{"n":5,"classes":[],"overflow":true}
{"n":3,"types":{"stream":3}}
{"n":2,"types":{"Unknown":1,"stream":1}}
{"n":4,"classes":[],"overflow":true}
{"n":4,"classes":{"App\\A":1,"App\\B":2,"App\\C":1},"overflow":false}
{"n":5,"classes":[],"overflow":true}
