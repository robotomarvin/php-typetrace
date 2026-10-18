--TEST--
typetrace: nothing recorded means no file at shutdown (dir untouched, not created)
--INI--
typetrace.enable=1
typetrace.output_dir=/tmp/tt402_missing_dir
--FILE--
<?php
var_dump(typetrace_flush());
var_dump(is_dir('/tmp/tt402_missing_dir'));
echo "done\n";
?>
--EXPECT--
NULL
bool(false)
done
