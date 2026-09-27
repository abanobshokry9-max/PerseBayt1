<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$heartbeat=(string)setting('runtime.worker_heartbeat_at','');
$hbTs=utc_ts($heartbeat);$workerAge=$hbTs===false?null:max(0,time()-$hbTs);$lastRunState=(string)setting('runtime.worker_last_run_state','');$lastRunError=(string)setting('runtime.worker_last_run_error','');$lastRunAt=(string)setting('runtime.worker_last_run_at','');$workerHealthy=$workerAge!==null&&$workerAge<180&&$lastRunState!=='failed';
$dueQueued=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='queued' AND available_at<=NOW() AND attempts<max_attempts")->fetchColumn();
$running=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='running'")->fetchColumn();
$cronMode=(string)setting('runtime.worker_cron_mode','');$nextTickSeconds=$cronMode==='cli'?(60-(int)gmdate('s')):null;
$feedKick=null;
if($dueQueued>0&&$running===0&&$cronMode!=='cli'){
    $last=(string)setting('runtime.queue_feed_last_kick_at','');$lts=utc_ts($last);
    if($lts===false||$lts<time()-12){
        try{put_setting('runtime.queue_feed_last_kick_at',now_utc());$feedKick=WorkerWakeup::kickNow(3);}catch(Throwable $e){$feedKick=['state'=>'failed','code'=>pb_substr(Security::redactSecrets($e->getMessage()),0,120)];}
    }
}

$counts=[];foreach(['queued','running','waiting','failed','done','cancelled'] as $state){$q=db()->prepare("SELECT COUNT(*) FROM jobs WHERE state=?".($state==='failed'?" AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)":($state==='done'?" AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)":"")));$q->execute([$state]);$counts[$state]=(int)$q->fetchColumn();}
$oldest=(string)(db()->query("SELECT created_at FROM jobs WHERE state='queued' ORDER BY created_at ASC LIMIT 1")->fetchColumn()?:'');
$oldestTs=$oldest!==''?utc_ts($oldest):false;$oldestWait=$oldestTs===false?0:max(0,time()-$oldestTs);

$sql="SELECT j.id,j.kind,j.agent_id,j.task_id,j.project_id,j.state,j.priority,j.attempts,j.max_attempts,j.available_at,j.next_retry_at,j.locked_at,j.lease_expires_at,j.started_at,j.finished_at,j.duration_ms,j.error_code,j.created_at,j.updated_at,a.display_name agent_name,a.slug agent_slug,t.title task_title,t.status task_status,p.name project_name FROM jobs j LEFT JOIN agents a ON a.id=j.agent_id LEFT JOIN tasks t ON t.id=j.task_id LEFT JOIN projects p ON p.id=j.project_id WHERE j.state IN ('queued','running','waiting','failed') OR j.updated_at>=DATE_SUB(NOW(),INTERVAL 90 MINUTE) ORDER BY FIELD(j.state,'running','queued','waiting','failed','done','cancelled'),j.priority DESC,j.id DESC LIMIT 120";
$jobs=db()->query($sql)->fetchAll();
foreach($jobs as &$job){
    $created=utc_ts((string)$job['created_at']);$started=utc_ts((string)($job['started_at']??''));$available=utc_ts((string)($job['available_at']??''));$next=utc_ts((string)($job['next_retry_at']??''));
    $job['state_label']=AdminUi::label((string)$job['state']);$job['kind_label']=AdminUi::label((string)$job['kind']);
    $job['created_display']=AdminUi::date($job['created_at']);$job['updated_display']=AdminUi::date($job['updated_at']);$job['started_display']=AdminUi::date($job['started_at']);$job['next_retry_display']=AdminUi::date($job['next_retry_at']);
    $job['wait_seconds']=$created===false?0:max(0,(($started!==false?$started:time())-$created));
    $job['ready_in_seconds']=($available!==false&&$available>time())?$available-time():0;
    $job['retry_in_seconds']=($next!==false&&$next>time())?$next-time():0;
    $job['running_seconds']=$started===false?0:max(0,time()-$started);
    $job['error_human']=$job['error_code']?AdminUi::humanError((string)$job['error_code']):'';
    if($job['error_code']){$adv=RamiErrorAdvisor::explain((string)$job['error_code'],'queue');$job['error_title']=$adv['title'];$job['error_fix']=$adv['fix'];}else{$job['error_title']='';$job['error_fix']='';}
    $job['live_detail']='';
    if((string)$job['kind']==='walid_opportunity_search' && !empty($job['task_id'])){
        $rq=db()->prepare("SELECT state,raw_found,qualified_found,review_found,rejected_found,duplicates_found,warning_count,summary_json FROM opportunity_search_runs WHERE task_id=? ORDER BY id DESC LIMIT 1");$rq->execute([(int)$job['task_id']]);$wr=$rq->fetch();
        if($wr){$sum=json_decode((string)($wr['summary_json']??''),true)?:[];$phase=(string)($sum['phase_label']??$sum['phase']??'');$progressAt=(string)($sum['progress_at']??'');$job['progress_phase']=$phase;$job['progress_at']=$progressAt;$job['live_detail']='بحث وليد'.($phase!==''?' — '.$phase:'').': خام '.(int)$wr['raw_found'].' · مؤهل '.(int)$wr['qualified_found'].' · مراجعة '.(int)$wr['review_found'].' · مرفوض '.(int)$wr['rejected_found'].' · تحذير '.(int)$wr['warning_count'];if(isset($sum['query_index']))$job['live_detail'].=' · Query '.(int)$sum['query_index'].'/'.(int)($sum['query_count']??0);if(isset($sum['provider_results']))$job['live_detail'].=' · نتائج المزود '.(int)$sum['provider_results'];if(isset($sum['search_raw_total']))$job['live_detail'].=' · إجمالي البحث '.(int)$sum['search_raw_total'];if(isset($sum['checked']))$job['live_detail'].=' · تحقق '.(int)$sum['checked'].'/'.(int)($sum['limit']??0);if(isset($sum['batch']))$job['live_detail'].=' · تحليل '.(int)$sum['batch'].'/'.(int)($sum['batches']??0);$job['search_diagnostics']=array_slice((array)($sum['last_search_diagnostics']??[]),-4);if($job['search_diagnostics']){$ld=end($job['search_diagnostics']);if(is_array($ld)){$job['live_detail'].=' · '.(string)($ld['provider']??'search').' '.(string)($ld['state']??'').'('.(int)($ld['count']??0).')';}}}
    }
}unset($job);

$lastWalid=db()->query("SELECT id,state,raw_found,qualified_found,review_found,rejected_found,duplicates_found,warning_count,started_at,completed_at,created_at,summary_json FROM opportunity_search_runs ORDER BY id DESC LIMIT 1")->fetch()?:null;
if($lastWalid){$lastWalid['state_label']=AdminUi::label((string)$lastWalid['state']);$lastWalid['started_display']=AdminUi::date($lastWalid['started_at']);$lastWalid['completed_display']=AdminUi::date($lastWalid['completed_at']);}

echo j(['ok'=>true,'release'=>ReleaseInfo::VERSION,'worker'=>['healthy'=>$workerHealthy,'heartbeat'=>$heartbeat,'heartbeat_display'=>AdminUi::date($heartbeat),'age_seconds'=>$workerAge,'last_kick_at'=>(string)setting('runtime.worker_last_kick_at',''),'last_kick_state'=>(string)setting('runtime.worker_last_kick_state',''),'last_kick_code'=>(string)setting('runtime.worker_last_kick_code',''),'last_kick_error'=>(string)setting('runtime.worker_last_kick_error',''),'cron_mode'=>$cronMode,'next_tick_seconds'=>$nextTickSeconds,'last_run_state'=>$lastRunState,'last_run_error'=>$lastRunError,'last_run_at'=>$lastRunAt,'feed_kick'=>$feedKick],'counts'=>$counts,'due_queued'=>$dueQueued,'oldest_wait_seconds'=>$oldestWait,'jobs'=>$jobs,'last_walid_run'=>$lastWalid,'at'=>now_utc()]);
