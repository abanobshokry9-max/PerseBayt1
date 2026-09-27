<?php
declare(strict_types=1);
final class WorkerWakeup {
    private static bool $scheduled=false;

    private static function token():string{
        $key='connections.worker.http_token';
        $token=trim((string)SecretVault::get($key,''));
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)){
            $token=bin2hex(random_bytes(32));
            SecretVault::save([$key=>$token]);
        }
        return $token;
    }

    private static function endpoint(int $limit):string{
        // app.base_url in PerseBayt normally ends with /api. Building the worker URL by
        // appending another /api produced /api/api/cron-worker.php on shared hosting,
        // leaving queued jobs (especially Walid searches) without an immediate worker.
        $configured=trim((string)config('app.base_url','https://persebayt.com'));
        $scheme=(string)(parse_url($configured,PHP_URL_SCHEME)?:'https');
        $host=(string)(parse_url($configured,PHP_URL_HOST)?:'persebayt.com');
        $port=parse_url($configured,PHP_URL_PORT);
        if(!in_array(strtolower($scheme),['http','https'],true))$scheme='https';
        $origin=$scheme.'://'.$host.($port?':'.(int)$port:'');
        return $origin.'/api/cron-worker.php?limit='.max(1,min(5,$limit));
    }

    /**
     * Fire a protected HTTPS request back to cron-worker.php. This is the fallback
     * for shared hosting environments where fastcgi_finish_request() is unavailable.
     * A client timeout after the TCP/TLS connection was established is treated as
     * dispatched because cron-worker.php uses ignore_user_abort(true).
     */
    public static function kickHttp(int $limit=2):array{
        if(!function_exists('curl_init'))return ['state'=>'failed','code'=>'worker_http_kick_curl_missing'];
        $url=self::endpoint($limit);$token=self::token();$raw='';$status=0;$errno=0;$error='';$connect=0.0;$total=0.0;
        $ch=curl_init($url);if(!$ch)return ['state'=>'failed','code'=>'worker_http_kick_curl_init_failed'];
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Accept: application/json','X-Worker-Token: '.$token,'X-PerseBayt-Wakeup: 1'],
            CURLOPT_POSTFIELDS=>'',
            CURLOPT_CONNECTTIMEOUT_MS=>1800,
            CURLOPT_TIMEOUT_MS=>3500,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        ]);
        $result=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$connect=(float)curl_getinfo($ch,CURLINFO_CONNECT_TIME);$total=(float)curl_getinfo($ch,CURLINFO_TOTAL_TIME);$errno=curl_errno($ch);$error=curl_error($ch);if(is_string($result))$raw=$result;curl_close($ch);
        $state='failed';$code='worker_http_kick_failed';
        if($status>=200&&$status<300){$state='verified';$code='worker_http_kick_ok';}
        elseif($errno===28&&$connect>0){$state='dispatched';$code='worker_http_kick_dispatched';}
        elseif($status===403){$code='worker_http_kick_forbidden';}
        elseif($status>0){$code='worker_http_kick_http_'.$status;}
        elseif($errno>0){$code='worker_http_kick_curl_'.$errno;}
        $details=['state'=>$state,'code'=>$code,'http_status'=>$status,'curl_errno'=>$errno,'connect_ms'=>(int)round($connect*1000),'elapsed_ms'=>(int)round($total*1000)];$decoded=$raw!==''?json_decode($raw,true):null;if($state==='failed'&&is_array($decoded)){$remote=(string)($decoded['detail']??$decoded['error']??'');if($remote!=='')$details['remote_error']=pb_substr(Security::redactSecrets($remote),0,220);if(!empty($decoded['error_ref']))$details['error_ref']=pb_substr((string)$decoded['error_ref'],0,80);}
        if($state==='failed'&&$error!=='')$details['error']=pb_substr(Security::redactSecrets($error),0,180);
        try{put_setting('runtime.worker_last_kick_at',now_utc());put_setting('runtime.worker_last_kick_mode','https');put_setting('runtime.worker_last_kick_state',$state);put_setting('runtime.worker_last_kick_code',$code);put_setting('runtime.worker_last_kick_error',(string)($details['remote_error']??$error??''));}catch(Throwable){}
        try{Audit::log('system','worker-wakeup','worker.http_kick','runtime','worker',$state==='failed'?'failed':'executed',null,null,$details);}catch(Throwable){}
        return $details;
    }

    public static function kickNow(int $limit=2):array{
        if(defined('PB_WORKER_REQUEST')&&PB_WORKER_REQUEST)return ['state'=>'skipped','code'=>'worker_request_active'];
        if(PHP_SAPI==='cli'){
            $r=Worker::run(max(1,min(5,$limit)));
            return ['state'=>'verified','code'=>'worker_cli_run_ok','processed_count'=>count($r)];
        }
        if((string)setting('runtime.worker_cron_mode','')==='cli'){
            try{put_setting('runtime.worker_last_kick_at',now_utc());put_setting('runtime.worker_last_kick_mode','cli_cron');put_setting('runtime.worker_last_kick_state','scheduled');put_setting('runtime.worker_last_kick_code','worker_cli_cron_scheduled');}catch(Throwable){}
            return ['state'=>'scheduled','code'=>'worker_cli_cron_scheduled','processed_count'=>0,'next_tick_seconds'=>60-(int)gmdate('s')];
        }
        return self::kickHttp($limit);
    }

    public static function schedule():void{
        if(self::$scheduled||PHP_SAPI==='cli'||(defined('PB_WORKER_REQUEST')&&PB_WORKER_REQUEST)||setting('runtime.worker_autokick','1')!=='1')return;
        if((string)setting('runtime.worker_cron_mode','')==='cli'){try{put_setting('runtime.worker_last_kick_at',now_utc());put_setting('runtime.worker_last_kick_mode','cli_cron');put_setting('runtime.worker_last_kick_state','scheduled');put_setting('runtime.worker_last_kick_code','worker_cli_cron_scheduled');}catch(Throwable){}return;}
        self::$scheduled=true;
        if(function_exists('fastcgi_finish_request')){
            try{put_setting('runtime.worker_last_kick_mode','fastcgi_shutdown');put_setting('runtime.worker_last_kick_at',now_utc());}catch(Throwable){}
            register_shutdown_function(static function():void{
                try{
                    if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
                    @fastcgi_finish_request();
                    ignore_user_abort(true);@set_time_limit(180);
                    $runtime=PB_ROOT.'/private/runtime';if(!is_dir($runtime))@mkdir($runtime,0700,true);
                    $fh=@fopen($runtime.'/worker-wakeup.lock','c+');if(!$fh||!@flock($fh,LOCK_EX|LOCK_NB)){if(is_resource($fh))@fclose($fh);return;}
                    try{Worker::run(max(1,min(2,(int)setting('runtime.worker_autokick_limit','1'))));}
                    finally{@flock($fh,LOCK_UN);@fclose($fh);}
                }catch(Throwable $e){error_log('ELMETR worker autokick: '.Security::redactSecrets($e->getMessage(),220));}
            });
            return;
        }
        // Shared-hosting fallback: start the protected worker endpoint over HTTPS.
        try{self::kickHttp(max(1,min(2,(int)setting('runtime.worker_autokick_limit','1'))));}
        catch(Throwable $e){error_log('ELMETR worker HTTPS autokick: '.Security::redactSecrets($e->getMessage(),220));}
    }
}
