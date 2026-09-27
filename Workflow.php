<?php
declare(strict_types=1);
final class Workflow {
    public static function fullProjectReview(int $projectId,string $request,string $priority='high'):array{
        $p=self::project($projectId);if(StagingService::eligible($p))StagingService::ensure($projectId);$needsInventory=empty($p['document_root'])||empty($p['technology'])||empty($p['last_scan_at']);$ids=[];$prev=null;
        if($needsInventory){$inventory=self::scanHosting('تحديث الخريطة التقنية قبل تنفيذ: '.$p['name'],$projectId);$prev=(int)$inventory['primary_task'];$ids[]=$prev;}
        $ay=TaskService::create('ayman','تنفيذ طلب المالك على '.$p['name'],$request,$projectId,['workflow'=>'full_review','request'=>$request,'auto_review'=>1,'fix_cycle'=>0,'job_kind'=>'ayman_execute'],$priority,true,null,'owner','1',false);
        if($prev)TaskService::addDependency($ay,$prev);TaskService::queueIfReady($ay);$ids[]=$ay;
        ProjectChatService::post($projectId,'agent','ramy','تم تجهيز دورة التنفيذ للمهمة #'.$ay.($prev?' بعد اكتمال فهرسة الاستضافة #'.$prev:'').'.','handoff','task',(string)$ay,false,['request'=>$request]);
        return ['tasks'=>$ids,'primary_task'=>$ay,'summary'=>'تم تجهيز دورة العمل: '.($needsInventory?'فهرسة الاستضافة ← ':'').'أيمن ← عماد.'];
    }
    public static function assignAyman(?int $projectId,string $request,string $priority='high',string $requestedBy='owner',string $requestedId='1',bool $ownerAuthorized=true):array{
        if(!$projectId)throw new RuntimeException('project_context_missing');$p=self::project($projectId);if(StagingService::eligible($p))StagingService::ensure($projectId);
        $id=TaskService::create('ayman','تنفيذ فني',$request,$projectId,['request'=>$request,'auto_review'=>1,'fix_cycle'=>0,'job_kind'=>'ayman_execute'],$priority,$ownerAuthorized,null,$requestedBy,$requestedId);
        ProjectChatService::post($projectId,'agent','ramy','أيمن استلم مهمة التنفيذ #'.$id.': '.$request,'handoff','task',(string)$id);
        return ['tasks'=>[$id],'primary_task'=>$id,'summary'=>'بدأت مهمة أيمن وسيتم تحويلها تلقائيًا إلى عماد للمراجعة بعد وجود دليل تنفيذ.'];
    }

    public static function requestEmadReview(?int $projectId,?int $taskId,string $request='مراجعة التنفيذ',string $requestedBy='agent',string $requestedId=''):array{
        $emad=AgentService::assertRunnable(AgentService::bySlug('emad'));
        if(!$taskId&&$projectId){$ayman=(int)AgentService::bySlug('ayman')['id'];$q=db()->prepare("SELECT id FROM tasks WHERE project_id=? AND assigned_agent_id=? AND status IN ('needs_review','completed') ORDER BY id DESC LIMIT 1");$q->execute([$projectId,$ayman]);$taskId=(int)($q->fetchColumn()?:0);}
        if(!$taskId)throw new RuntimeException('task_context_missing');
        $orig=TaskService::get($taskId);if((string)$orig['agent_slug']!=='ayman'||!in_array((string)$orig['status'],['needs_review','completed'],true))throw new RuntimeException('task_not_ready_for_review');
        $projectId=$projectId?:($orig['project_id']?(int)$orig['project_id']:null);
        $q=db()->prepare("SELECT id FROM tasks WHERE parent_task_id=? AND assigned_agent_id=? AND status NOT IN ('cancelled','failed','completed') ORDER BY id DESC LIMIT 1");$q->execute([$taskId,(int)$emad['id']]);$existing=(int)($q->fetchColumn()?:0);
        if($existing)return ['tasks'=>[$existing],'primary_task'=>$existing,'summary'=>'عماد عنده مراجعة شغالة بالفعل لنفس المهمة.'];
        $rid=TaskService::create('emad','مراجعة: '.$orig['title'],trim($request)?:'راجع عمل أيمن واختبر فعليًا ثم ارفع تقريرًا حقيقيًا.',$projectId,['review_of'=>$taskId,'peer_command'=>1],'high',true,$taskId,$requestedBy,$requestedId?:'1');
        if($projectId)try{$sender='ramy';if($requestedBy==='agent'&&ctype_digit($requestedId)){try{$sender=(string)AgentService::byId((int)$requestedId)['slug'];}catch(Throwable){}}ProjectChatService::post($projectId,'agent',$sender,'تم تحويل المهمة #'.$taskId.' إلى عماد للمراجعة بطلب من شات الفريق.','handoff','task',(string)$rid);}catch(Throwable){}
        return ['tasks'=>[$rid],'primary_task'=>$rid,'summary'=>'بدأ عماد مراجعة المهمة #'.$taskId.' وسيعيد النتيجة والأدلة إلى شات الفريق.'];
    }

    public static function prepareStaging(int $projectId):array{
        $p=self::project($projectId);$t=StagingService::ensure($projectId);$summary=!empty($t['is_staging'])?'تم تجهيز محطة الاختبار للمشروع على https://'.$t['domain'].'/ قبل أي تعديل على موقع العميل.':'المشروع يعمل على بيئة التنفيذ الحالية ولا يحتاج Staging منفصل.';return ['tasks'=>[],'primary_task'=>null,'summary'=>$summary,'target'=>$t];
    }
    public static function promoteStaging(int $projectId):array{
        $ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));$p=self::project($projectId);$task=TaskService::create('ramy','نقل النسخة المعتمدة إلى الدومين النهائي','انقل النسخة التي اعتمدها عماد من محطة الاختبار إلى الدومين النهائي بعد QA واكتمال بوابة الدفع، مع Backup وإثبات لكل ملف/تغيير قاعدة بيانات.',$projectId,['job_kind'=>'agent_task','operation'=>'staging_promote'],'high',true,null,'owner','1',false);
        try{$result=StagingService::promote($projectId,$task);TaskEvidence::add($task,'deployment','ترقية من Staging إلى الدومين النهائي',$result,'verified',(int)$ramy['id']);TaskService::complete($task,$result,'تم نقل النسخة المعتمدة من محطة الاختبار إلى الدومين النهائي بعد QA وبوابة الدفع.');try{ProjectChatService::post($projectId,'agent','ramy','تم نقل النسخة المعتمدة من محطة الاختبار إلى الدومين النهائي '.$p['primary_domain'].'.','delivery','task',(string)$task,false,$result);}catch(Throwable){}CustomerDeliveryService::onProjectApproved($projectId);return ['tasks'=>[$task],'primary_task'=>$task,'summary'=>'تم نقل النسخة المعتمدة من Staging إلى '.$p['primary_domain'].' مع تسجيل أدلة النشر.','result'=>$result];}
        catch(Throwable $e){TaskService::fail($task,$e->getMessage(),'النقل النهائي اتوقف ولم يتم تسجيله كنجاح. '.AdminUi::humanError($e->getMessage()));throw $e;}
    }
    public static function huntOpportunities(string $reason='أمر المالك',?int $lookbackDays=null,?int $rawTarget=null,array $queries=[],string $requestedBy='owner',string $requestedId='1'):array{
        $walid=AgentService::assertRunnable(AgentService::bySlug('walid'));AgentService::requireTool((int)$walid['id'],'opportunity_hunter');Permissions::requireAgent((int)$walid['id'],'opportunities.hunt');
        $q=db()->prepare("SELECT j.task_id,j.state FROM jobs j WHERE j.kind='walid_opportunity_search' AND j.agent_id=? AND j.state IN ('queued','running','waiting') ORDER BY FIELD(j.state,'running','queued','waiting'),j.id DESC LIMIT 1");$q->execute([(int)$walid['id']]);$active=$q->fetch();
        if($active&&$active['task_id'])return ['tasks'=>[(int)$active['task_id']],'primary_task'=>(int)$active['task_id'],'summary'=>'وليد عنده بحث فرص شغال أو منتظر بالفعل؛ لن أكرر نفس البحث.'];
        $days=max(1,min(60,$lookbackDays??(int)setting('opportunities.lookback_days','15')));$target=max(5,min(100,$rawTarget??(int)setting('opportunities.default_raw_target','30')));
        db()->prepare("INSERT INTO opportunity_search_runs(agent_id,state,date_from,date_to,requested_limit,queries_json) VALUES (?,'queued',DATE_SUB(NOW(),INTERVAL ? DAY),NOW(),?,?)")->execute([(int)$walid['id'],$days,$target,$queries?j($queries):null]);$run=(int)db()->lastInsertId();
        $id=TaskService::create('walid','صيد فرص ومشاريع حديثة',$reason,null,['job_kind'=>'walid_opportunity_search','search_run_id'=>$run,'lookback_days'=>$days,'raw_target'=>$target,'queries'=>$queries],'high',true,null,$requestedBy,$requestedId);
        db()->prepare('UPDATE opportunity_search_runs SET task_id=? WHERE id=?')->execute([$id,$run]);
        $kick=WorkerWakeup::kickNow(1);
        $kickState=(string)($kick['state']??'failed');
        $summary='تم إنشاء بحث وليد خلال آخر '.$days.' يومًا. حالة بدء التنفيذ: '.($kickState==='verified'?'تم تشغيل Worker':($kickState==='dispatched'?'تم إرسال Worker للتنفيذ':'المهمة في الطابور وسيعيد النظام إيقاظ Worker تلقائيًا')).'.';
        return ['tasks'=>[$id],'primary_task'=>$id,'search_run_id'=>$run,'worker_kick'=>$kick,'summary'=>$summary];
    }
    public static function scanHosting(string $reason='أمر المالك',?int $projectId=null):array{
        $ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));AgentService::requireTool((int)$ramy['id'],'hostinger');AgentService::requireTool((int)$ramy['id'],'projects');
        $q=db()->prepare("SELECT j.task_id,j.state FROM jobs j WHERE j.kind='hosting_inventory' AND j.agent_id=? AND j.state IN ('queued','running','waiting') ORDER BY FIELD(j.state,'running','queued','waiting'),j.id DESC LIMIT 1");$q->execute([(int)$ramy['id']]);$active=$q->fetch();
        if($active&&$active['task_id'])return ['tasks'=>[(int)$active['task_id']],'primary_task'=>(int)$active['task_id'],'summary'=>'فهرسة Hostinger شغالة أو منتظرة بالفعل؛ لن أكررها.'];
        $scan=self::ensureScan($reason);$id=TaskService::create('ramy','فهرسة الاستضافة',$reason,$projectId,['job_kind'=>'hosting_inventory','scan_id'=>$scan,'scan_scope'=>$projectId?'project':'all'],'high',true);
        return ['tasks'=>[$id],'primary_task'=>$id,'scan_id'=>$scan,'summary'=>'تم بدء فهرسة Hostinger التقنية تحت إدارة رامي.'];
    }
    public static function scanNow(string $reason='أمر المالك'):array{return self::scanHosting($reason,null);}
    private static function ensureScan(string $reason):int{$ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));db()->prepare("INSERT INTO project_scans(agent_id,state,summary_json) VALUES (?,'queued',?)")->execute([(int)$ramy['id'],j(['reason'=>$reason,'kind'=>'hosting_inventory'])]);return (int)db()->lastInsertId();}
    public static function afterTask(int $taskId):void{
        $t=TaskService::get($taskId);$taskCtx=json_decode((string)($t['context_json']??'{}'),true)?:[];
        if($t['agent_slug']==='emad'&&$t['status']==='completed'&&!empty($taskCtx['recovery_execution'])){self::completeRecovery($t,$taskCtx);return;}
        if($t['agent_slug']==='walid'){$q=db()->prepare('SELECT task_id FROM task_dependencies WHERE depends_on_task_id=?');$q->execute([$taskId]);foreach($q->fetchAll() as $r)TaskService::queueIfReady((int)$r['task_id']);return;}
        if($t['agent_slug']==='ayman'&&$t['status']==='needs_review'){if(!empty($t['project_id'])){try{ProjectStageService::set((int)$t['project_id'],'awaiting_qa','agent','ayman','أيمن أنهى التنفيذ وسلم الأدلة',true);ProjectChatService::post((int)$t['project_id'],'agent','ayman','اكتمل تنفيذ المهمة #'.$taskId.' وتم تسليمها لعماد للمراجعة.','handoff','task',(string)$taskId);}catch(Throwable){}}self::ensureEmadReview($t);return;}
        if($t['agent_slug']!=='emad'||$t['status']!=='completed')return;
        $parent=(int)($t['parent_task_id']??0);if(!$parent)return;$review=db()->prepare('SELECT * FROM reviews WHERE task_id=? ORDER BY id DESC LIMIT 1');$review->execute([$parent]);$r=$review->fetch();if(!$r)return;$emadId=(int)AgentService::bySlug('emad')['id'];$ramyId=(int)AgentService::bySlug('ramy')['id'];$orig=TaskService::get($parent);$ctx=json_decode((string)($orig['context_json']??'{}'),true)?:[];$root=(int)($ctx['root_task']??0);if(!$root)$root=$orig['parent_task_id']?(int)$orig['parent_task_id']:$parent;try{$rootTask=TaskService::get($root);}catch(Throwable){$rootTask=$orig;$root=$parent;}$projectId=$orig['project_id']?(int)$orig['project_id']:null;
        if(in_array($r['status'],['approved','approved_warning'],true)){
            MemoryService::learnFromTask($parent,'نتيجة مراجعة عماد المعتمدة: '.pb_substr((string)$r['summary'],0,1200),'procedure');self::rememberRamy($ramyId,'procedure','عماد اعتمد المهمة #'.$parent.': '.pb_substr((string)$r['summary'],0,900),(string)$r['id'],70,$projectId);
            $rs=$r['status']==='approved'?'approved':'approved_warning';$before=(string)$orig['status'];db()->prepare("UPDATE tasks SET status='completed',review_status=?,completed_at=NOW() WHERE id=?")->execute([$rs,$parent]);TaskService::event($parent,'agent',(string)$emadId,'review_approved',$before,'completed',['review_id'=>$r['id'],'review_status'=>$rs]);
            if($root&&$root!==$parent){$rb=(string)$rootTask['status'];db()->prepare("UPDATE tasks SET status='completed',review_status=?,completed_at=NOW() WHERE id=?")->execute([$rs,$root]);TaskService::event($root,'agent',(string)$emadId,'review_cycle_completed',$rb,'completed',['review_id'=>$r['id'],'final_task'=>$parent]);}
            $doneTask=$root?:$parent;Notifications::add('success','delivery','تم اعتماد المهمة','عماد اعتمد المهمة #'.$parent.($root&&$root!==$parent?' وأغلق دورة الإصلاح للمهمة #'.$root:'').'.','task',(string)$doneTask);db()->prepare("UPDATE change_requests SET status='completed' WHERE task_id=? AND status='working'")->execute([$doneTask]);if($parent!==$doneTask)db()->prepare("UPDATE change_requests SET status='completed' WHERE task_id=? AND status='working'")->execute([$parent]);if($projectId){try{ProjectStageService::set($projectId,'ready_delivery','agent','emad','اجتازت مراجعة الجودة',true);ProjectChatService::post($projectId,'agent','emad','اجتازت المراجعة للمهمة #'.$doneTask.'. '.pb_substr((string)$r['summary'],0,1200),'qa','review',(string)$r['id']);}catch(Throwable){}CustomerDeliveryService::onProjectApproved($projectId);}self::finalizeApprovedChange($orig,$ctx,$projectId,$r);self::queueDependents($doneTask);self::ownerUpdate('عماد خلص المراجعة واعتمد المهمة #'.$doneTask.'. النتيجة: '.pb_substr((string)$r['summary'],0,1200),$projectId,$doneTask);return;
        }
        if(in_array($r['status'],['rejected','needs_fixes'],true)){
            MemoryService::learnFromTask($parent,'خطأ اكتشفه عماد ويجب عدم تكراره: '.pb_substr((string)$r['summary'],0,1400),'experience');self::rememberRamy($ramyId,'experience','عماد رفض المهمة #'.$parent.' بسبب: '.pb_substr((string)$r['summary'],0,900),(string)$r['id'],80,$projectId);
            $cycle=max(0,(int)($ctx['fix_cycle']??0));$max=max(1,(int)setting('workflow.max_fix_cycles',(string)$rootTask['max_attempts']));if($cycle>=$max){$before=(string)$orig['status'];db()->prepare("UPDATE tasks SET status='blocked',review_status='blocked' WHERE id=?")->execute([$parent]);TaskService::event($parent,'agent',(string)$emadId,'review_blocked',$before,'blocked',['review_id'=>$r['id'],'reason'=>'fix_cycle_limit','cycle'=>$cycle,'max'=>$max]);if($root!==$parent){$rb=(string)$rootTask['status'];db()->prepare("UPDATE tasks SET status='blocked',review_status='blocked' WHERE id=?")->execute([$root]);TaskService::event($root,'agent',(string)$emadId,'review_cycle_blocked',$rb,'blocked',['review_id'=>$r['id'],'cycle'=>$cycle,'max'=>$max]);}Notifications::add('critical','qa','المهمة اتوقفت وعايزة تدخل','وصلت دورة الإصلاح للحد الآمن وتحتاج تدخل المالك.','task',(string)$root);self::ownerUpdate('عماد وقف دورة الإصلاح للمهمة #'.$root.' بعد '.$max.' دورات إصلاح. السبب الأخير: '.pb_substr((string)$r['summary'],0,1000),$projectId,$root);return;}
            $before=(string)$orig['status'];if($projectId){try{ProjectStageService::set($projectId,'needs_fix','agent','emad','المراجعة تحتاج تصحيح',true);ProjectChatService::post($projectId,'agent','emad','ملاحظات المراجعة للمهمة #'.$parent.': '.pb_substr((string)$r['summary'],0,1800),'qa','review',(string)$r['id']);}catch(Throwable){}}db()->prepare("UPDATE tasks SET status='needs_fix',review_status='rejected' WHERE id=?")->execute([$parent]);TaskService::event($parent,'agent',(string)$emadId,'review_needs_fix',$before,'needs_fix',['review_id'=>$r['id'],'fix_cycle'=>$cycle+1]);$fixCtx=['fix_of'=>$parent,'root_task'=>$root,'review_id'=>$r['id'],'auto_review'=>1,'fix_cycle'=>$cycle+1];foreach(['approved_change_proposal','proposal_type','auto_promote_after_qa'] as $k)if(array_key_exists($k,$ctx))$fixCtx[$k]=$ctx[$k];$fix=TaskService::create('ayman','إصلاح ملاحظات عماد: '.$orig['title'],"نفّذ ملاحظات عماد التالية كتكملة لنفس المشروع بدون طلب موافقة جديدة:\n".$r['summary'],$projectId,$fixCtx,'high',true,$parent,'agent',(string)$emadId);Notifications::add('warning','qa','أعاد عماد المهمة لأيمن','تم إنشاء مهمة إصلاح #'.$fix.' من تقرير المراجعة.','task',(string)$fix);return;
        }
        if($r['status']==='blocked'){
            self::rememberRamy($ramyId,'experience','عماد لم يقدر يعتمد المهمة #'.$parent.' بسبب مانع حقيقي: '.pb_substr((string)$r['summary'],0,900),(string)$r['id'],80,$projectId);$before=(string)$orig['status'];db()->prepare("UPDATE tasks SET status='blocked',review_status='blocked' WHERE id=?")->execute([$parent]);TaskService::event($parent,'agent',(string)$emadId,'review_blocked',$before,'blocked',['review_id'=>$r['id'],'reason'=>'qa_blocked']);if($root!==$parent){$rb=(string)$rootTask['status'];db()->prepare("UPDATE tasks SET status='blocked',review_status='blocked' WHERE id=?")->execute([$root]);TaskService::event($root,'agent',(string)$emadId,'review_cycle_blocked',$rb,'blocked',['review_id'=>$r['id'],'reason'=>'qa_blocked']);}Notifications::add('critical','qa','عماد وقف التسليم بسبب مانع',(string)$r['summary'],'task',(string)$root);self::ownerUpdate('عماد وقف اعتماد المهمة #'.$root.' لأن فيه مانع حقيقي: '.pb_substr((string)$r['summary'],0,1200),$projectId,$root);return;
        }
    }
    public static function afterFailure(int $taskId,string $code,string $summary=''):?int{
        $t=TaskService::get($taskId);if((string)$t['agent_slug']!=='ayman'||empty($t['project_id']))return null;
        $ctx=json_decode((string)($t['context_json']??'{}'),true)?:[];
        /* عماد مراجع مستقل، وليس منفذًا احتياطيًا لكود أيمن.
           إبقاء الفصل بين التنفيذ والمراجعة يمنع تضارب الأدوار ويجعل حالة كل وكيل واضحة. */
        if(!empty($ctx['recovery_execution'])||!empty($ctx['safe_retry_after_write_verify']))return null;
        $hardStop=['task_cancelled','task_not_active','ayman_project_required','project_context_missing','project_database_credentials_required','system_project_owner_authorization_required'];
        foreach($hardStop as $prefix)if($code===$prefix||str_starts_with($code,$prefix.':'))return null;
        if(str_starts_with($code,'agent_recovery_required:'))return null;
        $root=(int)($ctx['root_task']??0);if(!$root)$root=$taskId;
        $reason=$summary!==''?$summary:AdminUi::humanError($code);
        $isWriteVerify=str_starts_with($code,'write_verification_failed');
        if($isWriteVerify){
            $q=db()->prepare("SELECT id FROM tasks WHERE parent_task_id=? AND assigned_agent_id=? AND status NOT IN ('cancelled','failed','completed') AND context_json LIKE '%\"safe_retry_after_write_verify\":1%' ORDER BY id DESC LIMIT 1");$q->execute([$taskId,(int)AgentService::bySlug('ayman')['id']]);$existing=(int)($q->fetchColumn()?:0);if($existing)return $existing;
            $retryCtx=$ctx;$retryCtx['job_kind']='ayman_execute';$retryCtx['safe_retry_after_write_verify']=1;$retryCtx['fix_of']=$taskId;$retryCtx['root_task']=$root;$retryCtx['original_error']=$code;$retryCtx['original_summary']=$reason;
            $rid=TaskService::create('ayman','إعادة تنفيذ آمنة بعد تعثر التحقق: '.$t['title'],"أعد قراءة الحالة الحالية ثم نفّذ نفس طلب المالك بأقل تغيير لازم. تم ترقية التحقق من الكتابة ليعيد القراءة عدة مرات ويعيد رفع الملف مرة واحدة عند تأخر مزامنة هوستنجر. خذ Backup قبل أي كتابة ولا تعتبر التنفيذ ناجحًا إلا بعد قراءة الملف والتحقق منه.\n\nالطلب الأصلي:\n".(string)$t['description'],(int)$t['project_id'],$retryCtx,'critical',true,$taskId,'system','workflow');
            try{ProjectChatService::post((int)$t['project_id'],'agent','ramy','أيمن تعثر في التحقق من الكتابة للمهمة #'.$taskId.'. أنشأت محاولة آمنة واحدة #'.$rid.' لنفس أيمن بعد ترقية التحقق. عماد سيظل مراجعًا مستقلًا بعد نجاح التنفيذ.','handoff','task',(string)$rid,false,['retry_for'=>$taskId,'error'=>$code]);}catch(Throwable){}
            Notifications::add('warning','tasks','إعادة محاولة آمنة لأيمن','تم إنشاء محاولة واحدة محسنة للمهمة #'.$taskId.' بعد فشل التحقق من الكتابة، بدون تحويل التنفيذ إلى عماد.','task',(string)$rid);
            return $rid;
        }
        try{ProjectChatService::post((int)$t['project_id'],'agent','ramy','توقف تنفيذ أيمن في المهمة #'.$taskId.'. لن أحول البرمجة إلى عماد؛ يحتاج السبب إلى تشخيص رامي ثم إعادة التكليف الآمن لأيمن. السبب: '.pb_substr($reason,0,700),'status','task',(string)$taskId,false,['error'=>$code]);}catch(Throwable){}
        Notifications::add('critical','tasks','توقف تنفيذ أيمن ويحتاج تشخيص','المهمة #'.$taskId.' لم تُحوّل إلى عماد لأن عماد مراجع مستقل. راجع السبب ثم أعد التكليف بعد الإصلاح.','task',(string)$taskId);
        return null;
    }

    private static function completeRecovery(array $recovery,array $ctx):void{
        $fallback=(int)($ctx['fallback_for']??$recovery['parent_task_id']??0);if(!$fallback)return;
        try{$orig=TaskService::get($fallback);}catch(Throwable){return;}
        $before=(string)$orig['status'];$evidence=[];
        try{$q=db()->prepare("SELECT evidence_json,details_json FROM task_events WHERE task_id=? AND event_type='completed' ORDER BY id DESC LIMIT 1");$q->execute([(int)$recovery['id']]);$row=$q->fetch();if($row)$evidence=json_decode((string)($row['evidence_json']??'{}'),true)?:[];}catch(Throwable){}
        if(empty($evidence['execution_verified'])){Notifications::add('critical','qa','الاستلام الاحتياطي انتهى بدون دليل كافٍ','لم يتم إغلاق مهمة أيمن الأصلية لأن دليل execution_verified غير موجود في الاستلام الاحتياطي.','task',(string)$recovery['id']);return;}

        // Recovery proves that code was changed, not that QA passed. Force a fresh review run.
        db()->prepare("UPDATE tasks SET status='needs_review',review_status='pending',completed_at=NULL WHERE id=?")->execute([$fallback]);
        TaskService::event($fallback,'agent',(string)$recovery['assigned_agent_id'],'fallback_recovered',$before,'needs_review',['recovery_task'=>(int)$recovery['id'],'mode'=>'emad_takeover','requires_independent_retest'=>1]);
        $root=(int)($ctx['root_task']??0);if($root&&$root!==$fallback){try{$rt=TaskService::get($root);if(!in_array((string)$rt['status'],['cancelled'],true)){db()->prepare("UPDATE tasks SET status='needs_review',review_status='pending',completed_at=NULL WHERE id=?")->execute([$root]);TaskService::event($root,'agent',(string)$recovery['assigned_agent_id'],'fallback_cycle_recovered',(string)$rt['status'],'needs_review',['recovery_task'=>(int)$recovery['id'],'failed_task'=>$fallback,'requires_independent_retest'=>1]);}}catch(Throwable){}}
        $projectId=!empty($recovery['project_id'])?(int)$recovery['project_id']:null;
        if($projectId){try{ProjectStageService::set($projectId,'awaiting_qa','agent','emad','تم تنفيذ المسار الاحتياطي ويحتاج إعادة اختبار مستقلة قبل الاعتماد',true);ProjectChatService::post($projectId,'agent','emad','اكتمل المسار الاحتياطي للمهمة #'.$fallback.' في المهمة #'.$recovery['id'].' بأدلة تنفيذ. لم يتم اعتماد المشروع؛ تم تحويله إلى إعادة اختبار مستقلة قبل اعتماد الجودة.','qa','task',(string)$recovery['id'],false,['fallback_for'=>$fallback,'requires_independent_retest'=>1]);}catch(Throwable){}}

        $emad=(int)AgentService::bySlug('emad')['id'];$q=db()->prepare("SELECT id FROM tasks WHERE parent_task_id=? AND assigned_agent_id=? AND status NOT IN ('cancelled','failed','completed') AND context_json LIKE '%\"recovery_retest\":1%' ORDER BY id DESC LIMIT 1");$q->execute([$fallback,$emad]);$reviewTask=(int)($q->fetchColumn()?:0);
        if(!$reviewTask){$reviewTask=TaskService::create('emad','إعادة اختبار مستقلة بعد التنفيذ الاحتياطي: '.$orig['title'],'ابدأ مراجعة جديدة من الصفر بعد انتهاء التنفيذ الاحتياطي. لا تعتمد تقرير التنفيذ السابق. شغّل اختبارات الخادم واختبارات المتصفح عند توفرها، وافتح ملاحظات فنية لأي فشل.',$projectId,['review_of'=>$fallback,'recovery_retest'=>1,'recovery_task'=>(int)$recovery['id'],'request'=>(string)$orig['description']],'critical',true,$fallback,'system','workflow');}
        try{OwnerDecisionService::request('تنفيذ احتياطي يحتاج إعادة اختبار','تم تشغيل مسار تنفيذ احتياطي تاريخي للمهمة #'.$fallback.'. النظام لم يعتبر ذلك اعتماد جودة وأنشأ إعادة اختبار مستقلة #'.$reviewTask.' قبل السماح بالتسليم.',null,$projectId,'warning','recovery-retest-'.$fallback);}catch(Throwable){}
        Notifications::add('warning','qa','المسار الاحتياطي مكتمل وينتظر إعادة الاختبار','تم تنفيذ الاستلام الاحتياطي بأدلة، لكن المشروع لن يصبح جاهزًا للتسليم قبل إكمال المراجعة المستقلة #'.$reviewTask.'.','task',(string)$reviewTask);
        self::ownerUpdate('أيمن تعثر في المهمة #'.$fallback.'، وتم تشغيل مسار تنفيذ احتياطي تاريخي في المهمة #'.$recovery['id'].' بأدلة. لم أسجل اعتماد جودة؛ تم إنشاء إعادة اختبار مستقلة #'.$reviewTask.' قبل التسليم.',$projectId,$fallback);
    }

    public static function reconcileFailedAyman(int $hours=24,int $limit=8):int{
        $hours=max(1,min(168,$hours));$limit=max(1,min(30,$limit));$ayman=(int)AgentService::bySlug('ayman')['id'];
        $q=db()->prepare("SELECT t.id FROM tasks t WHERE t.assigned_agent_id=? AND t.status='failed' AND t.project_id IS NOT NULL AND t.owner_authorized=1 AND t.updated_at>=DATE_SUB(NOW(),INTERVAL ".$hours." HOUR) AND NOT EXISTS (SELECT 1 FROM tasks x WHERE x.parent_task_id=t.id AND x.context_json LIKE '%\"safe_retry_after_write_verify\":1%') ORDER BY t.id DESC LIMIT ".$limit);$q->execute([$ayman]);$count=0;
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$id=(int)$id;$code='ayman_failed';$summary='فشل سابق يحتاج تشخيص رامي وإعادة محاولة آمنة عند قابلية السبب للإصلاح.';try{$e=db()->prepare("SELECT details_json FROM task_events WHERE task_id=? AND event_type='failed' ORDER BY id DESC LIMIT 1");$e->execute([$id]);$d=json_decode((string)($e->fetchColumn()?:'{}'),true)?:[];$code=(string)($d['error_code']??$code);$summary=(string)($d['summary']??$summary);}catch(Throwable){}try{if(self::afterFailure($id,$code,$summary)!==null)$count++;}catch(Throwable){}}return $count;
    }

    public static function reconcilePendingReviews(int $limit=20):int{
        $emad=AgentService::bySlug('emad');if(!AgentService::runnable($emad))return 0;$q=db()->prepare("SELECT t.*,a.slug agent_slug,a.display_name agent_name,p.name project_name,p.primary_domain FROM tasks t LEFT JOIN agents a ON a.id=t.assigned_agent_id LEFT JOIN projects p ON p.id=t.project_id WHERE a.slug='ayman' AND t.status='needs_review' ORDER BY t.id ASC LIMIT ".max(1,min(100,$limit)));$q->execute();$count=0;foreach($q->fetchAll() as $t)if(self::ensureEmadReview($t)!==null)$count++;return $count;
    }
    private static function ensureEmadReview(array $t):?int{
        $taskId=(int)$t['id'];$emad=AgentService::bySlug('emad');if(!AgentService::runnable($emad)){Notifications::add('warning','qa','المهمة مستنية عماد','أيمن خلص المهمة #'.$taskId.' لكن عماد متوقف حاليًا. الشغل محفوظ وهيتنقل للمراجعة أول ما عماد يشتغل.','task',(string)$taskId);return null;}
        $q=db()->prepare("SELECT id FROM tasks WHERE parent_task_id=? AND assigned_agent_id=? AND status NOT IN ('cancelled','failed','completed') ORDER BY id DESC LIMIT 1");$q->execute([$taskId,(int)$emad['id']]);$existing=(int)($q->fetchColumn()?:0);if($existing)return $existing;
        $ctx=['review_of'=>$taskId,'request'=>$t['description']];$rid=TaskService::create('emad','مراجعة: '.$t['title'],'راجع عمل أيمن واختبر فعليًا ثم ارفع تقريرًا حقيقيًا.',$t['project_id']?(int)$t['project_id']:null,$ctx,'high',true,$taskId,'agent',(string)AgentService::bySlug('ayman')['id']);try{ProspectPrototypeService::linkEmadTask($taskId,$rid);}catch(Throwable){}Notifications::add('info','qa','انتقلت المهمة إلى عماد','المهمة #'.$taskId.' جاهزة للمراجعة.','task',(string)$rid);return $rid;
    }
    private static function finalizeApprovedChange(array $task,array $ctx,?int $projectId,array $review):void{
        $proposalId=(int)($ctx['approved_change_proposal']??0);if($proposalId<1)return;
        try{
            $promotion=null;
            if(!empty($ctx['auto_promote_after_qa'])&&$projectId&&setting('learning.auto_promote_owner_approved_system_changes','1')==='1')$promotion=self::promoteStaging($projectId);
            db()->prepare("UPDATE agent_change_proposals SET state='implemented',implemented_at=NOW() WHERE id=?")->execute([$proposalId]);
            Audit::log('system','workflow','learning.change_implemented','agent_change_proposal',(string)$proposalId,'verified',$projectId,(int)$task['id'],['review_id'=>(int)($review['id']??0),'auto_promoted'=>(bool)$promotion]);
            Notifications::add('success','agents','تم تطبيق تطوير الوكيل المعتمد','اكتمل تنفيذ ومراجعة الاقتراح #'.$proposalId.($promotion?' وتم نقله إلى PerseBayt بعد QA.':' وتم اعتماده بعد QA.'),'agent_change_proposal',(string)$proposalId);
        }catch(Throwable $e){
            $safe=Security::redactSecrets($e->getMessage(),240);db()->prepare("UPDATE agent_change_proposals SET state='failed',owner_note=CONCAT(COALESCE(owner_note,''),CASE WHEN COALESCE(owner_note,'')='' THEN '' ELSE '\n' END,?) WHERE id=?")->execute(['فشل النشر بعد QA: '.$safe,$proposalId]);
            Notifications::add('critical','deployments','فشل نشر تطوير ذاتي معتمد','الاقتراح #'.$proposalId.' اجتاز QA لكن النشر النهائي لم يكتمل: '.AdminUi::humanError($safe),'agent_change_proposal',(string)$proposalId);
        }
    }

    private static function rememberRamy(int $ramyId,string $type,string $body,string $sourceId,int $importance,?int $projectId):void{if(!AgentService::tool($ramyId,'memory'))return;try{MemoryService::rememberAgent($ramyId,$type,$body,'review',$sourceId,$importance,$projectId);}catch(Throwable $e){error_log('ELMETR ramy memory: '.$e->getMessage());}}
    private static function ownerUpdate(string $body,?int $projectId,?int $taskId):void{try{CommunicationGateway::ramyDashboardUpdate($body,$projectId,$taskId);}catch(Throwable $e){error_log('ELMETR owner update: '.$e->getMessage());}}
    private static function queueDependents(int $taskId):void{$q=db()->prepare('SELECT task_id FROM task_dependencies WHERE depends_on_task_id=?');$q->execute([$taskId]);foreach($q->fetchAll() as $r)TaskService::queueIfReady((int)$r['task_id']);}
    private static function project(int $id):array{$q=db()->prepare('SELECT * FROM projects WHERE id=?');$q->execute([$id]);$p=$q->fetch();if(!$p)throw new RuntimeException('project_not_found');return $p;}
}
