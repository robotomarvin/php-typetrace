--TEST--
typetrace registry: anonymous classes get distinct NUL-free keys, class fields and object stats
--INI--
typetrace.enable=1
--FILE--
<?php
class Foo {}
$a = new class { public function f(int $x) { return $x; } };
$b = new class { public function f(string $x) { return $x; } };
$c = new class extends Foo { public function f(array $x) { return $x; } };
$a->f(1); $b->f('s'); $c->f([]);
function take($o) { return $o; }
take($a); take($b); take($c); take(new Foo);

$json = typetrace_report();
var_dump(strpos($json, '\u0000') === false);
$r = json_decode($json, true);
$base = basename(__FILE__);
foreach ($r['functions'] as $k => $f) {
    if ($f['kind'] !== 'method') continue;
    echo str_replace(dirname(__FILE__) . '/', '', $k), ' class=', str_replace(dirname(__FILE__) . '/', '', $f['class']),
        ' declared=', $f['params'][0]['declared'], ' calls=', $f['calls'], "\n";
}
$cls = array_keys($r['functions']['take']['params'][0]['acc']['object']['classes']);
sort($cls);
foreach ($cls as $c) echo str_replace(dirname(__FILE__) . '/', '', $c), "\n";
?>
--EXPECT--
bool(true)
Foo@anonymous@219_anon_class_keys.php:5::f class=Foo@anonymous@219_anon_class_keys.php:5 declared=array calls=1
class@anonymous@219_anon_class_keys.php:3::f class=class@anonymous@219_anon_class_keys.php:3 declared=int calls=1
class@anonymous@219_anon_class_keys.php:4::f class=class@anonymous@219_anon_class_keys.php:4 declared=string calls=1
Foo
Foo@anonymous@219_anon_class_keys.php:5
class@anonymous@219_anon_class_keys.php:3
class@anonymous@219_anon_class_keys.php:4
