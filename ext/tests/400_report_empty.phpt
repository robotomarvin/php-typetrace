--TEST--
typetrace: report with empty tables has all top-level keys, {} maps, INI config
--INI--
typetrace.enable=1
typetrace.array_sample=5
typetrace.class_cap=7
typetrace.sample_rate=0.25
typetrace.saturate_after=9
--FILE--
<?php
$raw = typetrace_report();
$r = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
echo implode(',', array_keys((array)$r)), "\n";
var_dump($r->schema, $r->generator, $r->php === PHP_VERSION, $r->sapi === PHP_SAPI, $r->pid === getmypid());
var_dump(is_float($r->started) || is_int($r->started), $r->finished >= $r->started, $r->requests);
echo json_encode($r->config), "\n";
var_dump($r->functions instanceof stdClass, $r->properties instanceof stdClass);
var_dump(str_contains($raw, '"functions":{},"properties":{}'));
?>
--EXPECTF--
schema,generator,php,sapi,pid,started,finished,requests,config,functions,properties
int(1)
string(%d) "typetrace/%s"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(1)
{"array_sample":5,"array_depth":3,"literal_cap":16,"class_cap":7,"shape_cap":32,"string_literal_max":64,"sample_rate":0.25,"saturate_after":9}
bool(true)
bool(true)
bool(true)
