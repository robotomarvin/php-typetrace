--TEST--
typetrace props: properties of distinct anonymous classes are separate records
--INI--
typetrace.enable=1
--FILE--
<?php
$a = new class { public int $v = 0; };
$b = new class { public string $v = ''; };
$a->v = 5;
$b->v = 'x';
$json = typetrace_report();
var_dump(strpos($json, '\u0000') === false);
$p = json_decode($json, true)['properties'];
ksort($p);
foreach ($p as $k => $r) {
    echo str_replace(dirname(__FILE__) . '/', '', $k), ' class=', str_replace(dirname(__FILE__) . '/', '', $r['class']),
        ' declared=', $r['declared'], ' n=', $r['acc']['n'], "\n";
}
?>
--EXPECT--
bool(true)
class@anonymous@311_anon_class_props.php:2::$v class=class@anonymous@311_anon_class_props.php:2 declared=int n=1
class@anonymous@311_anon_class_props.php:3::$v class=class@anonymous@311_anon_class_props.php:3 declared=string n=1
