<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$root=dirname(__DIR__,2);$runtime=!is_dir($root.'/database');$pass=[];$fail=[];
$check=function(string $name,bool $ok,string $detail='')use(&$pass,&$fail){$line=$name.($detail!==''?' - '.$detail:'');if($ok)$pass[]=$line;else $fail[]=$line;};
$required=['public_html/api/src/PostInstallAcceptanceService.php','public_html/api/src/IntegrationHealthService.php','public_html/api/src/DeploymentFingerprintService.php','public_html/api/admin/post-install-acceptance.php','public_html/api/admin/integration-health.php','public_html/api/admin/deployment-status.php','private/bin/post-install-verify-201.php'];
foreach($required as $r)$check('required:'.$r,is_file($root.'/'.$r));
$release=@file_get_contents($root.'/public_html/api/src/ReleaseInfo.php')?:'';$check('release_20_1',preg_match("/VERSION='20\.1\.[^']*'/",$release)===1);$check('schema_stays_20_0',str_contains($release,"SCHEMA='20.0'"));
$self=@file_get_contents($root.'/private/bin/package-selfcheck.php')?:'';$check('selfcheck_auto_owner',str_contains($self,'$ownerSignals')&&str_contains($self,"--no-secrets")&&str_contains($self,"--owner"));
if(!$runtime){$sql=@file_get_contents($root.'/database/upgrade_companyos_20_0_to_20_1_maintenance.sql')?:'';$san=preg_replace('/--[^\n]*/','',$sql);$check('maintenance_marker',str_contains($sql,'companyos_20_1_maintenance'));$check('upgrade_non_destructive',!preg_match('/\b(?:DELETE\s+FROM|TRUNCATE\s+(?:TABLE\s+)?|DROP\s+TABLE|DROP\s+DATABASE)\b/i',(string)$san));}else{$check('runtime_delivery_sql_optional',true,'deployed runtime package');}
$php=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile()&&strtolower($f->getExtension())==='php')$php[]=$f->getPathname();$bad=[];foreach($php as $file){$o=[];$c=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1',$o,$c);if($c!==0)$bad[]=str_replace($root.'/','',$file);}$check('php_lint_all',$bad===[],count($php).' files'.($bad?' bad='.implode(',',$bad):''));
$o=[];$c=0;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/private/bin/static-call-check.php').' 2>&1',$o,$c);$check('static_class_calls',$c===0,implode(' | ',$o));
$o=[];$c=0;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/private/bin/package-selfcheck.php').' --owner --runtime 2>&1',$o,$c);$check('package_selfcheck_owner',$c===0,'exit='.$c);
echo "PerseBayt Company OS 20.1.4 runtime-closure acceptance\nPASS=".count($pass)." FAIL=".count($fail)."\n";foreach($fail as $x)echo '[FAIL] '.$x.PHP_EOL;foreach($pass as $x)echo '[PASS] '.$x.PHP_EOL;exit($fail?2:0);
