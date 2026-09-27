<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
if(!is_file($root.'/private/config.php')){
    require __DIR__.'/package-selfcheck.php';
}
require_once $root.'/public_html/api/src/bootstrap.php';
$r=SystemDoctor::report();
echo ReleaseInfo::label()." - runtime self check\n";
foreach($r['checks'] as $key=>$check){
    echo ($check['ok']?'[OK]  ':'[FAIL] ').$key.' - '.preg_replace('/\s+/u',' ',(string)$check['note'])."\n";
}
if($r['schema_issues']){
    echo "Schema issues:\n";
    foreach($r['schema_issues'] as $issue)echo ' - '.$issue."\n";
}
exit($r['ok']?0:2);
