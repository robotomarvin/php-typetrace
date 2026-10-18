--TEST--
typetrace props: opcache (8.2-8.4) disables JIT itself because of user opcode handlers; writes still observed
--SKIPIF--
<?php
if (PHP_VERSION_ID >= 80500) die('skip opcache is built in on 8.5+, see 308');
$out = shell_exec(PHP_BINARY . ' -n -d zend_extension=opcache -r "echo 1;" 2>&1');
if (trim((string)$out) !== '1') die('skip opcache not loadable');
?>
--INI--
typetrace.enable=1
zend_extension=opcache
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=16M
opcache.jit_hot_loop=1
opcache.jit_hot_func=1
opcache.jit_hot_return=1
opcache.jit_hot_side_exit=1
--FILE--
<?php
class J { public $x; public static $s;
    function set($v) { $this->x = $v; self::$s = $v; } }
$j = new J;
for ($i = 0; $i < 500; $i++) { $j->set($i); $j->x = 'str'; }
$r = json_decode(typetrace_report(), true)['properties'];
foreach (['J::$x', 'J::$s'] as $k) echo $k, ' ', $r[$k]['sources']['assign'], "\n";
?>
--EXPECTF--
%AWarning: JIT is incompatible with third party extensions that setup user opcode handlers. JIT disabled. in Unknown on line 0
%AJ::$x 1000
J::$s 500
