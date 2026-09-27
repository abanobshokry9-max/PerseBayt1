<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$lastReconcile=(string)setting('runtime.dashboard_reconcile_at','');$lastTs=utc_ts($lastReconcile);
if($lastTs===false||$lastTs<time()-30){
    try{put_setting('runtime.dashboard_reconcile_at',now_utc());TaskService::reconcileOrphanedJobs(15);OpportunitySearchService::reconcileRuns(15);}catch(Throwable $e){error_log('ELMETR dashboard reconcile: '.Security::redactSecrets($e->getMessage(),180));}
}

$agents=[];try{$agents=AgentService::runtimeSnapshot();}catch(Throwable){}
foreach($agents as &$a){$a['status_label']=AdminUi::label((string)($a['status']??'idle'));$a['last_seen_display']=AdminUi::date($a['last_seen_at']??null);}unset($a);

$heartbeat=(string)setting('runtime.worker_heartbeat_at','');$ts=utc_ts($heartbeat);$workerRunState=(string)setting('runtime.worker_last_run_state','');$workerRunError=(string)setting('runtime.worker_last_run_error','');$workerHealthy=$ts!==false&&(time()-$ts)<600&&$workerRunState!=='failed';
$lastRun=null;try{$lastRun=db()->query("SELECT id,state,raw_found,qualified_found,review_found,warning_count,completed_at,created_at,summary_json FROM opportunity_search_runs ORDER BY id DESC LIMIT 1")->fetch()?:null;}catch(Throwable){}

$queue=['queued'=>0,'running'=>0,'waiting'=>0,'failed'=>0];
try{$queue=['queued'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='queued'")->fetchColumn(),'running'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='running'")->fetchColumn(),'waiting'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='waiting'")->fetchColumn(),'failed'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn()];}catch(Throwable){}

$pipeline=[];try{foreach(db()->query("SELECT workflow_stage,COUNT(*) n FROM projects GROUP BY workflow_stage")->fetchAll() as $r)$pipeline[(string)$r['workflow_stage']]=(int)$r['n'];}catch(Throwable){}
$projects=[];try{$projects=db()->query("SELECT id,name,primary_domain,workflow_stage,status,updated_at FROM projects ORDER BY updated_at DESC LIMIT 10")->fetchAll();foreach($projects as &$p){$p['workflow_label']=AdminUi::label((string)$p['workflow_stage']);$p['technical_label']=AdminUi::label((string)$p['status']);$p['updated_display']=AdminUi::date($p['updated_at']);}unset($p);}catch(Throwable){}

$providers=[];try{$providers=db()->query("SELECT provider_key,kind,label,enabled,status,last_error,last_checked_at FROM providers ORDER BY FIELD(kind,'ai','messaging','hosting','generic','voice'),provider_key")->fetchAll();}catch(Throwable){}
$configured=function(string $key):bool{return match($key){
    'openai'=>trim((string)config('ai.api_key',''))!=='',
    'gemini'=>trim((string)config('providers.gemini.api_key',''))!=='',
    'groq'=>trim((string)config('providers.groq.api_key',''))!=='',
    'anthropic'=>trim((string)config('providers.anthropic.api_key',''))!=='',
    'ollama'=>trim((string)config('providers.ollama.base_url',''))!=='',
    'hostinger'=>trim((string)config('hostinger.api_token',''))!=='',
    'meta_whatsapp'=>trim((string)config('meta.access_token',''))!==''&&trim((string)config('meta.phone_number_id',''))!=='',
    'twilio'=>trim((string)config('twilio.account_sid',''))!==''&&trim((string)config('twilio.auth_token',''))!=='',
    'generic_bridge'=>trim((string)config('connections.generic_message.url',''))!=='',
    'generic_call_bridge'=>trim((string)config('calls.bridge_url',''))!=='',
    'media_bridge'=>trim((string)config('connections.media.url',''))!=='',
    default=>(bool)setting('provider.'.$key.'.configured','0')
};};
foreach($providers as &$p){$p['configured']=$configured((string)$p['provider_key']);$p['status_label']=AdminUi::label((string)$p['status']);$p['last_checked_display']=AdminUi::date($p['last_checked_at']);$p['last_error']=Security::redactSecrets((string)($p['last_error']??''),180);}unset($p);

$jobs=[];try{$jobs=db()->query("SELECT j.id,j.kind,j.state,j.attempts,j.max_attempts,j.error_code,j.created_at,j.updated_at,j.started_at,j.finished_at,j.duration_ms,a.slug agent_slug,a.display_name agent_name,t.title task_title,p.name project_name FROM jobs j LEFT JOIN agents a ON a.id=j.agent_id LEFT JOIN tasks t ON t.id=j.task_id LEFT JOIN projects p ON p.id=j.project_id ORDER BY j.id DESC LIMIT 16")->fetchAll();foreach($jobs as &$jv){$jv['state_label']=AdminUi::label((string)$jv['state']);$jv['kind_label']=AdminUi::label((string)$jv['kind']);$jv['updated_display']=AdminUi::date($jv['updated_at']);$jv['error_display']=$jv['error_code']?AdminUi::humanError((string)$jv['error_code']):'';}unset($jv);}catch(Throwable){}
$evidence=[];try{$evidence=db()->query("SELECT e.id,e.evidence_type,e.label,e.state,e.created_at,t.title task_title,p.name project_name,a.display_name agent_name FROM task_evidence e JOIN tasks t ON t.id=e.task_id LEFT JOIN projects p ON p.id=e.project_id LEFT JOIN agents a ON a.id=e.agent_id ORDER BY e.id DESC LIMIT 14")->fetchAll();foreach($evidence as &$ev){$ev['state_label']=AdminUi::label((string)$ev['state']);$ev['created_display']=AdminUi::date($ev['created_at']);}unset($ev);}catch(Throwable){}
$issues=[];try{$issues=db()->query("SELECT i.id,i.title,i.severity,i.status,i.blocking_delivery,i.page_label,i.url,i.created_at,p.name project_name FROM project_issues i JOIN projects p ON p.id=i.project_id ORDER BY i.id DESC LIMIT 14")->fetchAll();foreach($issues as &$iv){$iv['severity_label']=AdminUi::label((string)$iv['severity']);$iv['status_label']=AdminUi::label((string)$iv['status']);$iv['created_display']=AdminUi::date($iv['created_at']);}unset($iv);}catch(Throwable){}
$timeline=[];try{$timeline=db()->query("SELECT e.id,e.project_id,e.from_stage,e.to_stage,e.actor_type,e.actor_id,e.reason,e.created_at,p.name project_name FROM project_stage_events e JOIN projects p ON p.id=e.project_id ORDER BY e.id DESC LIMIT 16")->fetchAll();foreach($timeline as &$tv){$tv['from_label']=AdminUi::label((string)($tv['from_stage']??''));$tv['to_label']=AdminUi::label((string)$tv['to_stage']);$tv['created_display']=AdminUi::date($tv['created_at']);}unset($tv);}catch(Throwable){}
$errors=[];try{$errors=SystemDoctor::recentErrors(10);foreach($errors as &$er){$er['created_display']=AdminUi::date($er['created_at']??null);$er['error_message']=Security::redactSecrets((string)($er['error_message']??''),220);$er['error_display']=AdminUi::humanError((string)($er['error_message']??''));}unset($er);}catch(Throwable){}

$arabicTechnical=static function(string $raw):string{
    $raw=trim($raw);if($raw==='')return 'لا يوجد خطأ مسجل حاليًا.';
    $l=strtolower($raw);
    if(str_contains($l,'customer_context_missing'))return 'تعذر العثور على سياق العميل؛ يحتاج النظام إلى ربط الرسالة بالعميل أو المشروع الصحيح.';
    if(str_contains($l,'401')||str_contains($l,'unauth'))return 'فشل التحقق من مفتاح الوصول؛ المفتاح غير صالح أو انتهت صلاحيته.';
    if(str_contains($l,'403')||str_contains($l,'forbidden'))return 'تم رفض صلاحية الوصول إلى الخدمة الحالية.';
    if(str_contains($l,'timeout')||str_contains($l,'timed out'))return 'انتهت مهلة الاتصال بالخدمة قبل اكتمال الطلب.';
    if(str_contains($l,'connection')||str_contains($l,'connect'))return 'تعذر إتمام الاتصال بالخدمة؛ راجع حالة الربط ثم أعد الاختبار.';
    $human=AdminUi::humanError($raw);
    return preg_match('/[A-Za-z]{3,}/',$human)?'تم تسجيل خطأ تقني؛ افتح الفحص الكامل لعرض سببه ومعالجته.':$human;
};

/* بيانات الصفحة التقنية التفاعلية */
$activeTasks=0;try{$activeTasks=(int)db()->query("SELECT COUNT(*) FROM tasks WHERE status IN ('new','queued','assigned','working','waiting','blocked','needs_review','needs_fix','retesting')")->fetchColumn();}catch(Throwable){}
$ramyMs=null;$agentsMs=null;try{
    $rows=db()->query("SELECT a.slug,AVG(j.duration_ms) avg_ms FROM jobs j JOIN agents a ON a.id=j.agent_id WHERE j.state='done' AND j.duration_ms IS NOT NULL AND j.finished_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY a.slug")->fetchAll();
    $vals=[];foreach($rows as $r){$v=(float)$r['avg_ms'];$vals[]=$v;if((string)$r['slug']==='ramy')$ramyMs=$v;}if($vals)$agentsMs=array_sum($vals)/count($vals);
}catch(Throwable){}
$outTotal=0;$outOk=0;try{$r=db()->query("SELECT COUNT(*) total,SUM(CASE WHEN status NOT IN ('failed','error') THEN 1 ELSE 0 END) ok FROM messages WHERE direction='outbound' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetch();$outTotal=(int)($r['total']??0);$outOk=(int)($r['ok']??0);}catch(Throwable){}
$messageSuccess=$outTotal>0?(int)round(($outOk/$outTotal)*100):null;
$techMetrics=['ramy_speed_seconds'=>$ramyMs!==null?round($ramyMs/1000,1):null,'agents_speed_seconds'=>$agentsMs!==null?round($agentsMs/1000,1):null,'active_tasks'=>$activeTasks,'failed_24h'=>$queue['failed'],'message_success_percent'=>$messageSuccess,'message_total'=>$outTotal,'overall_ok'=>$workerHealthy&&$queue['failed']===0];

$team=[];try{$team=TeamChatService::recent(12);}catch(Throwable){}
foreach($team as &$m){
    $m['sender_name']=($m['sender_type']??'')==='owner'?'أبانوب':(($m['sender_type']??'')==='system'?'النظام':((string)($m['agent_name']??'')?:AdminUi::label((string)($m['sender_ref']??'agent'))));
    $m['created_display']=AdminUi::date($m['created_at']??null);$m['kind_label']=AdminUi::label((string)($m['message_kind']??'note'));
    $m['body_text']=pb_substr((string)($m['body_text']??''),0,260);
}unset($m);

$autonomy=[];try{$autonomy=db()->query("SELECT a.id agent_id,a.slug,a.display_name,x.brain_enabled,x.initiative_enabled,x.followup_enabled,x.last_brain_state,x.last_brain_note,x.last_think_at,x.next_run_at FROM agent_autonomy x JOIN agents a ON a.id=x.agent_id WHERE a.is_active=1 ORDER BY FIELD(a.slug,'ramy','walid','ayman','emad','samir-social','video-director','community-manager','basant','free-model-scout'),a.id LIMIT 20")->fetchAll();foreach($autonomy as &$a){$a['last_think_display']=AdminUi::date($a['last_think_at']??null);$a['next_run_display']=AdminUi::date($a['next_run_at']??null);$a['last_brain_note']=pb_substr((string)($a['last_brain_note']??''),0,180);}unset($a);}catch(Throwable){}
$initiatives=[];try{$initiatives=array_slice(AgentInitiativeService::all(30),0,10);foreach($initiatives as &$i){$i['state_label']=AdminUi::label((string)$i['state']);$i['created_display']=AdminUi::date($i['created_at']??null);$i['title']=pb_substr((string)$i['title'],0,150);}unset($i);}catch(Throwable){}

/* حالة الوكلاء الحالية فقط: لا نلوّن الوكيل بالأحمر بسبب فشل قديم انتهى أثره. */
$agentActivity=[];
try{
    $core=db()->query("SELECT id,slug,display_name,status,current_task_id,last_error_code FROM agents WHERE is_active=1 ORDER BY FIELD(slug,'ramy','walid','ayman','emad','samir-social','video-director','community-manager','basant','free-model-scout'),id")->fetchAll();
    $activeTaskQ=db()->prepare("SELECT t.id,t.title,t.status,t.project_id,t.updated_at,p.name project_name FROM tasks t LEFT JOIN projects p ON p.id=t.project_id WHERE t.assigned_agent_id=? AND t.status IN ('new','queued','assigned','working','waiting','blocked','needs_review','needs_fix','retesting') ORDER BY FIELD(t.status,'working','retesting','needs_fix','blocked','needs_review','assigned','queued','waiting','new'),t.updated_at DESC,t.id DESC LIMIT 1");
    $runQ=db()->prepare("SELECT id,kind,state,task_id,project_id,updated_at FROM jobs WHERE agent_id=? AND state='running' ORDER BY id DESC LIMIT 1");
    $taskFailQ=db()->prepare("SELECT error_code,updated_at FROM jobs WHERE agent_id=? AND task_id=? AND state='failed' ORDER BY COALESCE(finished_at,updated_at) DESC,id DESC LIMIT 1");
    foreach($core as $a){
        $id=(int)$a['id'];$activeTaskQ->execute([$id]);$task=$activeTaskQ->fetch()?:null;$runQ->execute([$id]);$run=$runQ->fetch()?:null;
        $problem=(string)$a['status']==='error'||($task&&in_array((string)$task['status'],['blocked','needs_fix'],true));$problemCode='';$problemAt='';
        if($problem){$problemCode=(string)($a['last_error_code']??'');if($task){$taskFailQ->execute([$id,(int)$task['id']]);$tf=$taskFailQ->fetch()?:null;if($tf){$problemCode=(string)($tf['error_code']??$problemCode);$problemAt=(string)($tf['updated_at']??'');}if($problemAt==='')$problemAt=(string)$task['updated_at'];}}
        $working=(bool)$run||($task&&in_array((string)$task['status'],['working','retesting'],true));
        $waiting=$task&&in_array((string)$task['status'],['new','queued','assigned','waiting','needs_review'],true);
        $flowState=$problem?'problem':($working?'working':($waiting?'waiting':'idle'));
        $agentActivity[(string)$a['slug']]=[
            'slug'=>(string)$a['slug'],'name'=>(string)$a['display_name'],'health_state'=>(string)$a['status'],'flow_state'=>$flowState,'is_working'=>$working,'has_problem'=>$problem,
            'task_id'=>$task?(int)$task['id']:null,'task_title'=>$task?(string)$task['title']:'','task_status'=>$task?(string)$task['status']:'','task_status_label'=>$task?AdminUi::label((string)$task['status']):'لا توجد مهمة نشطة','project_id'=>$task&&$task['project_id']?(int)$task['project_id']:null,'project_name'=>$task?(string)($task['project_name']??''):'',
            'job_id'=>$run?(int)$run['id']:null,'problem_code'=>$problemCode,'problem_display'=>$problemCode!==''?$arabicTechnical($problemCode):($problem?'يوجد تعثر حالي يحتاج متابعة.':''),'problem_at'=>$problemAt,'problem_at_display'=>AdminUi::date($problemAt?:null)
        ];
    }
}catch(Throwable $e){error_log('ELMETR agent activity feed: '.Security::redactSecrets($e->getMessage(),180));}

/* كروت تُنشأ تلقائيًا من العمل الحقيقي الجاري، بدون نسب تقدم مختلقة. */
$activeWorkCards=[];
try{
    $rows=db()->query("SELECT t.id,t.title,t.description,t.status,t.project_id,t.updated_at,a.slug agent_slug,a.display_name agent_name,p.name project_name FROM tasks t LEFT JOIN agents a ON a.id=t.assigned_agent_id LEFT JOIN projects p ON p.id=t.project_id WHERE t.status IN ('new','queued','assigned','working','waiting','blocked','needs_review','needs_fix','retesting') ORDER BY FIELD(t.status,'working','retesting','needs_fix','blocked','needs_review','assigned','queued','waiting','new'),t.updated_at DESC,t.id DESC LIMIT 12")->fetchAll();
    foreach($rows as $r)$activeWorkCards[]=['id'=>'task_'.(int)$r['id'],'type'=>'task','type_label'=>'مهمة','title'=>pb_substr((string)$r['title'],0,120),'detail'=>pb_substr((string)($r['description']??''),0,150),'state'=>(string)$r['status'],'state_label'=>AdminUi::label((string)$r['status']),'agent_slug'=>(string)($r['agent_slug']??''),'agent_name'=>(string)($r['agent_name']??'النظام'),'project_id'=>$r['project_id']?(int)$r['project_id']:null,'project_name'=>(string)($r['project_name']??''),'updated_display'=>AdminUi::date($r['updated_at']),'href'=>'tasks.php?id='.(int)$r['id']];
}catch(Throwable){}
try{
    $represented=[];foreach($activeWorkCards as $wc)if(!empty($wc['project_id']))$represented[(int)$wc['project_id']]=1;
    $projectRows=db()->query("SELECT id,name,workflow_stage,status,updated_at FROM projects WHERE status<>'archived' AND workflow_stage IN ('negotiating','agreed','deposit_pending','awaiting_execution','in_development','awaiting_qa','qa','needs_fix','ready_delivery') ORDER BY updated_at DESC,id DESC LIMIT 8")->fetchAll();
    foreach($projectRows as $r){$pid=(int)$r['id'];if(isset($represented[$pid]))continue;$activeWorkCards[]=['id'=>'project_'.$pid,'type'=>'project','type_label'=>'مشروع','title'=>pb_substr((string)$r['name'],0,120),'detail'=>'المرحلة الحالية: '.AdminUi::label((string)$r['workflow_stage']).'.','state'=>(string)$r['workflow_stage'],'state_label'=>AdminUi::label((string)$r['workflow_stage']),'agent_slug'=>'ramy','agent_name'=>'رامي','project_id'=>$pid,'project_name'=>(string)$r['name'],'updated_display'=>AdminUi::date($r['updated_at']),'href'=>'projects.php?id='.$pid];if(count($activeWorkCards)>=14)break;}
}catch(Throwable){}
try{
    $runs=db()->query("SELECT r.id,r.task_id,r.state,r.raw_found,r.qualified_found,r.review_found,r.warning_count,r.created_at,r.completed_at,a.slug agent_slug,a.display_name agent_name FROM opportunity_search_runs r LEFT JOIN agents a ON a.id=r.agent_id WHERE r.state IN ('queued','running') ORDER BY r.id DESC LIMIT 4")->fetchAll();
    foreach($runs as $r)$activeWorkCards[]=['id'=>'search_'.(int)$r['id'],'type'=>'search','type_label'=>'بحث فرص','title'=>'بحث وليد عن فرص ومشاريع','detail'=>'تم جمع '.(int)$r['raw_found'].' نتيجة، وتأهل '.(int)$r['qualified_found'].' حتى الآن.','state'=>(string)$r['state'],'state_label'=>AdminUi::label((string)$r['state']),'agent_slug'=>(string)($r['agent_slug']??'walid'),'agent_name'=>(string)($r['agent_name']??'وليد'),'project_id'=>null,'project_name'=>'','updated_display'=>AdminUi::date($r['completed_at']?:$r['created_at']),'href'=>'opportunities.php'];
}catch(Throwable){}
foreach($initiatives as $iv){if(!in_array((string)$iv['state'],['proposed','approved','queued','executing'],true))continue;$activeWorkCards[]=['id'=>'initiative_'.(int)$iv['id'],'type'=>'initiative','type_label'=>'مبادرة','title'=>pb_substr((string)$iv['title'],0,120),'detail'=>pb_substr((string)($iv['summary']??$iv['rationale']??''),0,150),'state'=>(string)$iv['state'],'state_label'=>AdminUi::label((string)$iv['state']),'agent_slug'=>(string)($iv['agent_slug']??''),'agent_name'=>(string)($iv['agent_name']??'وكيل'),'project_id'=>$iv['project_id']?(int)$iv['project_id']:null,'project_name'=>'','updated_display'=>$iv['created_display']??'—','href'=>'initiatives.php'];if(count($activeWorkCards)>=18)break;}
$activeWorkCards=array_slice($activeWorkCards,0,18);

/* خريطة كل الوكلاء ومهامهم: تستخدمها الصفحة التقنية لإنشاء كارت لكل وكيل وربط مهامه به. */
$agentLanes=[];
try{
    $taskRows=db()->query("SELECT t.id,t.assigned_agent_id,t.title,t.description,t.status,t.project_id,t.updated_at,p.name project_name FROM tasks t LEFT JOIN projects p ON p.id=t.project_id WHERE t.assigned_agent_id IS NOT NULL AND (t.status IN ('new','queued','assigned','working','waiting','blocked','needs_review','needs_fix','retesting') OR (t.status='failed' AND t.updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR))) ORDER BY FIELD(t.status,'working','retesting','needs_fix','blocked','needs_review','assigned','queued','waiting','new','failed'),t.updated_at DESC,t.id DESC LIMIT 100")->fetchAll();
    $tasksByAgent=[];
    /* مشاكل المشاريع المفتوحة تظهر ككروت حمراء مرتبطة بصاحب المهمة/المراجع حتى لو لم تكن المهمة نفسها blocked بعد. */
    try{
        $issueRows=db()->query("SELECT i.id,i.project_id,i.task_id,i.source_agent_id,i.severity,i.title,i.description,i.status,i.created_at,p.name project_name,COALESCE(t.assigned_agent_id,i.source_agent_id,(SELECT id FROM agents WHERE slug='ramy' LIMIT 1)) owner_agent_id FROM project_issues i JOIN projects p ON p.id=i.project_id LEFT JOIN tasks t ON t.id=i.task_id WHERE i.status IN ('open','working') ORDER BY FIELD(i.severity,'critical','high','medium','low','info'),i.id DESC LIMIT 60")->fetchAll();
        foreach($issueRows as $i){$aid=(int)($i['owner_agent_id']??0);if($aid<1||count($tasksByAgent[$aid]??[])>=3)continue;$tasksByAgent[$aid][]=['id'=>'issue_'.(int)$i['id'],'title'=>'مشكلة بالمشروع: '.pb_substr((string)$i['title'],0,100),'detail'=>pb_substr('الخطورة: '.AdminUi::label((string)$i['severity']).' — '.(string)($i['description']??''),0,150),'state'=>'failed','state_label'=>'مشكلة مشروع','project_id'=>(int)$i['project_id'],'project_name'=>(string)($i['project_name']??''),'updated_display'=>AdminUi::date($i['created_at']),'href'=>'project.php?id='.(int)$i['project_id'],'cancelable'=>false,'kind'=>'issue'];}
    }catch(Throwable){}
    foreach($taskRows as $t){$aid=(int)$t['assigned_agent_id'];if(count($tasksByAgent[$aid]??[])>=8)continue;$tasksByAgent[$aid][]=['id'=>(int)$t['id'],'title'=>pb_substr((string)$t['title'],0,120),'detail'=>pb_substr((string)($t['description']??''),0,120),'state'=>(string)$t['status'],'state_label'=>AdminUi::label((string)$t['status']),'project_id'=>$t['project_id']?(int)$t['project_id']:null,'project_name'=>(string)($t['project_name']??''),'updated_display'=>AdminUi::date($t['updated_at']),'href'=>'tasks.php?id='.(int)$t['id'],'cancelable'=>!in_array((string)$t['status'],['completed','cancelled','failed'],true),'kind'=>'task'];}
    foreach($agents as $a){if((int)($a['is_active']??1)!==1)continue;$aid=(int)$a['id'];$slug=(string)$a['slug'];$aa=$agentActivity[$slug]??null;$laneTasks=$tasksByAgent[$aid]??[];$state=$aa['flow_state']??((string)$a['status']==='error'?'problem':((string)$a['status']==='working'?'working':($laneTasks?'waiting':'idle')));$agentLanes[]=['id'=>$aid,'slug'=>$slug,'name'=>(string)$a['display_name'],'role_title'=>(string)($a['role_title']??''),'specialty'=>(string)($a['specialty']??''),'state'=>$state,'state_label'=>$state==='problem'?'يوجد خطأ':($state==='working'?'يعمل الآن':($state==='waiting'?'لديه عمل منتظر':'متاح')),'href'=>'agent-profile.php?slug='.rawurlencode($slug),'tasks'=>$laneTasks];}
}catch(Throwable $e){error_log('ELMETR agent lanes feed: '.Security::redactSecrets($e->getMessage(),180));}

$providerByKey=[];foreach($providers as $p)$providerByKey[(string)$p['provider_key']]=$p;
$diagnostics=[];
foreach(['meta_whatsapp'=>'واتساب','hostinger'=>'هوستنجر'] as $key=>$name){
    if(!isset($providerByKey[$key]))continue;$p=$providerByKey[$key];$bad=in_array((string)$p['status'],['failed'],true)||!$p['configured'];
    $diagnostics[]=['key'=>$key,'title'=>$name,'state'=>$bad?'error':'ok','state_label'=>$bad?'تحتاج إصلاح':'سليم','detail'=>$bad?$arabicTechnical((string)$p['last_error']):'الاتصال مهيأ والحالة الحالية '.AdminUi::label((string)$p['status']).'.','at_display'=>$p['last_checked_display']??'—'];
}
foreach($agentActivity as $slug=>$aa){if(empty($aa['has_problem']))continue;$diagnostics[]=['key'=>'agent_'.$slug,'title'=>(string)$aa['name'].' — تعثر حالي','state'=>'error','state_label'=>'المسار مقطوع','detail'=>pb_substr((string)($aa['problem_display']?:'يوجد تعثر حالي في الوكيل أو مهمته النشطة.'),0,190),'at_display'=>$aa['problem_at_display']??'—'];if(count($diagnostics)>=6)break;}
foreach(array_slice($errors,0,4) as $er)$diagnostics[]=['key'=>'system_error_'.$er['id'],'title'=>'خطأ بالنظام','state'=>'error','state_label'=>'تحتاج متابعة','detail'=>pb_substr($arabicTechnical((string)($er['error_message']??'')),0,190),'at_display'=>$er['created_display']??'—'];

$liveEvents=[];
foreach(array_slice($jobs,0,10) as $j){
    $text=((string)($j['agent_name']??'')?:'النظام').' · '.($j['state_label']??AdminUi::label((string)$j['state'])).' — '.((string)($j['task_title']??'')?:'عملية نظامية');
    $liveEvents[]=['id'=>'j'.(int)$j['id'],'at'=>(string)($j['updated_at']??''),'at_display'=>$j['updated_display']??'—','state'=>(string)$j['state'],'agent_slug'=>(string)($j['agent_slug']??''),'text'=>pb_substr($text,0,180)];
}
foreach(array_slice(array_reverse($team),0,6) as $m)$liveEvents[]=['id'=>'m'.(int)$m['id'],'at'=>(string)($m['created_at']??''),'at_display'=>$m['created_display']??'—','state'=>'message','agent_slug'=>(string)($m['sender_ref']??''),'text'=>'رسالة في دردشة الفريق من '.(string)$m['sender_name'].' — '.pb_substr((string)$m['body_text'],0,120)];
usort($liveEvents,static fn($a,$b)=>strcmp((string)$b['at'],(string)$a['at']));$liveEvents=array_slice($liveEvents,0,14);

$onlineCount=count(array_filter($agents,static fn($a)=>!in_array((string)($a['status']??''),['disabled','error'],true)));

echo j([
    'ok'=>true,'release'=>ReleaseInfo::VERSION,'agents'=>$agents,'working'=>count(array_filter($agents,static fn($a)=>($a['status']??'')==='working')),'online_count'=>$onlineCount,
    'worker'=>['healthy'=>$workerHealthy,'heartbeat'=>$heartbeat,'heartbeat_display'=>AdminUi::date($heartbeat),'last_run_state'=>$workerRunState,'last_run_error'=>$workerRunError],
    'queue'=>$queue,'pipeline'=>$pipeline,'projects'=>$projects,'providers'=>$providers,'recent_jobs'=>$jobs,'evidence'=>$evidence,'issues'=>$issues,'timeline'=>$timeline,'errors'=>$errors,'last_walid_run'=>$lastRun,
    'tech_metrics'=>$techMetrics,'team_chat'=>$team,'autonomy'=>$autonomy,'initiatives'=>$initiatives,'agent_activity'=>$agentActivity,'agent_lanes'=>$agentLanes,'active_work_cards'=>$activeWorkCards,'diagnostics'=>array_slice($diagnostics,0,8),'live_events'=>$liveEvents,'at'=>now_utc()
]);
