--TEST--
typetrace observer: return values, void, no return, by-ref returns
--INI--
typetrace.enable=1
--FILE--
<?php
function r_int() { return 5; }
function r_void(): void { }
function r_none() { }
function r_early($x) { if ($x) { return 'a'; } return 1; }
function &r_ref(array &$arr) { return $arr[0]; }
function r_typed(): ?int { return null; }

r_int(); r_int();
r_void(); r_none(); r_early(true); r_early(false); r_typed();
$a = [10, 20]; $x = &r_ref($a);

$fs = json_decode(typetrace_report(), true)['functions'];
foreach (['r_int', 'r_void', 'r_none', 'r_early', 'r_ref', 'r_typed'] as $k) {
    $r = $fs[$k]['return'];
    echo $k, ' declared=', var_export($r['declared'], true), ' by_ref=', var_export($r['by_ref'], true),
        ' generator=', var_export($r['generator'], true), ' acc=',
        $r['acc'] === null ? 'null' : $r['acc']['n'] . ' null=' . ($r['acc']['null'] ?? 0) . ' int=' . ($r['acc']['int']['n'] ?? 0) . ' string=' . ($r['acc']['string']['n'] ?? 0), "\n";
}
?>
--EXPECT--
r_int declared=NULL by_ref=false generator=false acc=2 null=0 int=2 string=0
r_void declared='void' by_ref=false generator=false acc=1 null=1 int=0 string=0
r_none declared=NULL by_ref=false generator=false acc=1 null=1 int=0 string=0
r_early declared=NULL by_ref=false generator=false acc=2 null=0 int=1 string=1
r_ref declared=NULL by_ref=true generator=false acc=1 null=0 int=1 string=0
r_typed declared='?int' by_ref=false generator=false acc=1 null=1 int=0 string=0
