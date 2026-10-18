--TEST--
typetrace observer: closures, arrow fns, static/inherited/trait methods
--INI--
typetrace.enable=1
--FILE--
<?php
trait T { public function tm($t) { return $t; } }
class Base { public function m($a) { return $a; } public static function s($a) { return $a; } }
class Child extends Base { use T; }
class Other extends Base { public function m($a) { return parent::m($a); } }

$c = function ($x) { return $x; };
$c(1); $c(2);
$arrow = fn($y) => $y * 2;
$arrow(3);
for ($i = 0; $i < 3; $i++) {
    $fresh = function ($z) { return $z; };   // new closure object each time, same key
    $fresh($i);
}
Base::s('static');
(new Child)->m('inherited');
(new Base)->m('base');
(new Child)->tm('trait');
(new Other)->m('other');

$fs = json_decode(typetrace_report(), true)['functions'];
$keys = array_filter(array_keys($fs), fn($k) => !str_starts_with($k, 'typetrace'));
sort($keys);
foreach ($keys as $k) {
    $f = $fs[$k];
    $label = str_starts_with($k, '{closure}@') ? '{closure}@' . basename(substr($k, 10, strrpos($k, '#') - 10)) : $k;
    echo $label, ' kind=', $f['kind'], ' class=', var_export($f['class'], true), ' calls=', $f['calls'], "\n";
}
?>
--EXPECT--
Base::m kind=method class='Base' calls=3
Base::s kind=method class='Base' calls=1
Child::tm kind=method class='Child' calls=1
Other::m kind=method class='Other' calls=1
{closure}@208_closures_methods.php:12 kind=closure class=NULL calls=3
{closure}@208_closures_methods.php:7 kind=closure class=NULL calls=2
{closure}@208_closures_methods.php:9 kind=closure class=NULL calls=1
