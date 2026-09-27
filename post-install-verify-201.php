<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once dirname(__DIR__,2).'/public_html/api/src/bootstrap.php';
try{$r=PostInstallAcceptanceService::run('post_install_cli_20_1');echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;exit(!empty($r['ok'])?0:2);}catch(Throwable $e){echo json_encode(['ok'=>false,'state'=>'failed','error'=>Security::redactSecrets($e->getMessage(),300)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;exit(2);}
