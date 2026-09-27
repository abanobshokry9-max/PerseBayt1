<?php
declare(strict_types=1);
define('PB_WORKER_REQUEST',true);
require_once __DIR__.'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$expected=trim((string)SecretVault::get('connections.worker.http_token',''));
$provided=trim((string)($_SERVER['HTTP_X_WORKER_TOKEN']??($_GET['token']??'')));
if($expected===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);echo j(['ok'=>false,'error'=>'forbidden']);exit;}
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){http_response_code(405);echo j(['ok'=>false,'error'=>'method_not_allowed']);exit;}
ignore_user_abort(true);@set_time_limit(210);
try{
    $cliMode=(string)setting('runtime.worker_cron_mode','')==='cli';$explicitWake=(string)($_SERVER['HTTP_X_PERSEBAYT_WAKEUP']??'')==='1';
    if($cliMode){
        // Never start a long Walid search inside an HTTP/FPM request once the durable CLI cron is installed.
        // Explicit wakeups only verify the protected endpoint and leave claiming to the next CLI tick.
        echo j(['ok'=>true,'processed_count'=>0,'results'=>[],'heartbeat'=>(string)setting('runtime.worker_heartbeat_at',''),'version'=>ReleaseInfo::VERSION,'mode'=>'cli','scheduled'=>true]);exit;
    }
    $limit=max(1,min(1,(int)($_GET['limit']??1)));
    $processed=Worker::run($limit);
    echo j(['ok'=>true,'processed_count'=>count($processed),'results'=>$processed,'heartbeat'=>(string)setting('runtime.worker_heartbeat_at',''),'version'=>ReleaseInfo::VERSION]);
}catch(Throwable $e){
    $safe=pb_substr(Security::redactSecrets($e->getMessage()),0,240);$ref='WRK-'.strtoupper(substr(hash('sha256',$safe.'|'.microtime(true)),0,10));error_log('ELMETR cron worker '.$ref.': '.$safe);try{put_setting('runtime.worker_last_run_state','failed');put_setting('runtime.worker_last_run_error',$safe);put_setting('runtime.worker_last_run_at',now_utc());put_setting('runtime.worker_last_error_ref',$ref);}catch(Throwable){}http_response_code(500);echo j(['ok'=>false,'error'=>'worker_failed','detail'=>$safe,'error_ref'=>$ref]);
}
