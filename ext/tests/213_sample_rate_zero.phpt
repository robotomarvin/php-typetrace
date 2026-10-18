--TEST--
typetrace observer: sample_rate=0 counts calls but samples none
--INI--
typetrace.enable=1
typetrace.sample_rate=0
--FILE--
<?php
function sr($a) { return $a; }
for ($i = 0; $i < 50; $i++) sr($i);
$f = json_decode(typetrace_report(), true)['functions']['sr'];
echo 'calls=', $f['calls'], ' sampled=', $f['sampled'], ' param acc=', var_export($f['params'][0]['acc'], true),
    ' ret=', var_export($f['return']['acc'], true), ' defaulted=', $f['params'][0]['defaulted'], "\n";
?>
--EXPECT--
calls=50 sampled=0 param acc=NULL ret=NULL defaulted=0
