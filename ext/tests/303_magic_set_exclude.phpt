--TEST--
typetrace props: __set classes (calls routed to __set are not recorded)
--INI--
typetrace.enable=1
--FILE--
<?php
function show(array $props) {
    ksort($props);
    foreach ($props as $k => $p) {
        $acc = $p["acc"]; $t = [];
        if ($acc) {
            foreach (["null", "true", "false"] as $s) if ($acc[$s] ?? 0) $t[] = $s;
            foreach (["int", "float", "string", "array", "object"] as $s) if ((is_array($acc[$s] ?? null) ? $acc[$s]["n"] : 0) > 0) $t[] = $s . ":" . $acc[$s]["n"];
        }
        echo $k, $p["static"] ? " static" : "", " decl=", $p["declared"] ?? "-", " a=", $p["sources"]["assign"], " s=", $p["sources"]["sweep"], " ", implode(",", $t), "\n";
    }
}
function props() { return json_decode(typetrace_report(), true)["properties"]; }
class M { public $decl; public $gone = 1;
    function __set($n, $v) { echo "__set $n\n"; }
    function run() { $this->decl = 1; unset($this->gone); $this->gone = 2; $this->undeclared = 3; } }
$m = new M;
$m->run();
$m->other = 4;
$m->decl = 'direct';
show(props());
?>
--EXPECT--
__set gone
__set undeclared
__set other
M::$decl decl=- a=2 s=0 int:1,string:1
