<?php
declare(strict_types=1);require_once dirname(__DIR__,2).'/public_html/api/src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
fwrite(STDERR,"Deprecated compatibility filename: migrate-14.php now upgrades the compatible database to Company OS 20. Prefer private/bin/migrate-20.php.\n");
try{$r=MigrationRunner::upgrade20(null,true);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;exit(0);}catch(Throwable $e){fwrite(STDERR,'Migration failed: '.Security::redactSecrets($e->getMessage(),700).PHP_EOL);exit(1);}
