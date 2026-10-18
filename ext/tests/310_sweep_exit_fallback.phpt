--TEST--
typetrace props: exit() inside a shutdown function does not break the sweep hook
--INI--
typetrace.enable=1
typetrace.sweep=1
--FILE--
<?php
class F { public $v = 1; }
$f = new F;
register_shutdown_function(function () { echo "sd1\n"; exit; });
register_shutdown_function(function () { echo "never\n"; });
register_shutdown_function(function () {});
echo "main\n";
?>
--EXPECT--
main
sd1
