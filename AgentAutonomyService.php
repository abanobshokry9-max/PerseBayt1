<?php
declare(strict_types=1);

final class AgentAutonomyService {
    public static function rows():array{
        return db()->query("SELECT aa.*,a.slug,a.display_name,a.role_title,a.status,a.is_active FROM agent_autonomy aa JOIN agents a ON a.id=aa.agent_id ORDER BY a.id")->fetchAll();
    }

    public static function save(int $agentId,bool $enabled,int $cadence,int $maxActive,string $mission,string $scope='owner_team'):void{
        AgentService::byId($agentId);$cadence=max(5,min(10080,$cadence));$maxActive=max(1,min(8,$maxActive));if(!in_array($scope,['internal','owner_team','external_guarded'],true))$scope='owner_team';
        $next=$enabled?gmdate('Y-m-d H:i:s',time()+($cadence*60)):null;
        db()->prepare("INSERT INTO agent_autonomy(agent_id,initiative_enabled,cadence_minutes,max_active_tasks,mission_text,initiative_scope,next_run_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE initiative_enabled=VALUES(initiative_enabled),cadence_minutes=VALUES(cadence_minutes),max_active_tasks=VALUES(max_active_tasks),mission_text=VALUES(mission_text),initiative_scope=VALUES(initiative_scope),next_run_at=VALUES(next_run_at)")
            ->execute([$agentId,$enabled?1:0,$cadence,$maxActive,pb_substr(trim($mission),0,12000),$scope,$next]);
        Audit::log('owner',(string)(Auth::user()['id']??1),'agent.autonomy_save','agent',(string)$agentId,'verified',null,null,['enabled'=>$enabled,'cadence_minutes'=>$cadence,'max_active'=>$maxActive,'scope'=>$scope]);
    }

    public static function saveBrainPolicy(int $agentId,array $d):void{
        AgentService::byId($agentId);$risk=(string)($d['auto_execute_max_risk']??'low');if(!in_array($risk,['none','low','medium'],true))$risk='low';
        db()->prepare("UPDATE agent_autonomy SET brain_enabled=?,max_initiatives_per_day=?,initiative_min_value=?,auto_execute_max_risk=?,followup_enabled=?,learning_enabled=?,self_improvement_enabled=?,daily_ai_call_budget=?,daily_external_action_budget=?,daily_search_budget=?,daily_media_budget=?,daily_voice_budget=?,daily_browser_budget=? WHERE agent_id=?")
            ->execute([!empty($d['brain_enabled'])?1:0,max(1,min(50,(int)($d['max_initiatives_per_day']??6))),max(1,min(100,(int)($d['initiative_min_value']??60))),$risk,!empty($d['followup_enabled'])?1:0,!empty($d['learning_enabled'])?1:0,!empty($d['self_improvement_enabled'])?1:0,max(1,(int)($d['daily_ai_call_budget']??60)),max(1,(int)($d['daily_external_action_budget']??20)),max(1,(int)($d['daily_search_budget']??40)),max(1,(int)($d['daily_media_budget']??20)),max(1,(int)($d['daily_voice_budget']??40)),max(1,(int)($d['daily_browser_budget']??80)),$agentId]);
    }

    public static function tick(int $limit=12):array{
        if(setting('agents.autonomy_enabled','1')==='0')return ['enabled'=>false,'checked'=>0,'started'=>0,'items'=>[]];
        $limit=max(1,min(12,$limit));
        $q=db()->query("SELECT aa.*,a.id AS id,a.slug,a.display_name,a.role_title,a.system_prompt,a.provider_key,a.model,a.is_active,a.status FROM agent_autonomy aa JOIN agents a ON a.id=aa.agent_id WHERE aa.initiative_enabled=1 AND a.is_active=1 AND a.status<>'disabled' AND (aa.next_run_at IS NULL OR aa.next_run_at<=NOW()) ORDER BY COALESCE(aa.next_run_at,'2000-01-01') ASC LIMIT ".$limit);
        $out=[];$started=0;
        foreach($q->fetchAll() as $row){
            $agentId=(int)$row['agent_id'];$cadence=max(5,(int)$row['cadence_minutes']);
            try{
                $activeQ=db()->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_agent_id=? AND status IN ('queued','assigned','working','waiting','retesting')");$activeQ->execute([$agentId]);$active=(int)$activeQ->fetchColumn();
                $recoveryQ=db()->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_agent_id=? AND status IN ('blocked','needs_fix','needs_review')");$recoveryQ->execute([$agentId]);$recovery=(int)$recoveryQ->fetchColumn();
                if($active>=(int)$row['max_active_tasks']&&$recovery===0){$note='لديه '.$active.' مهام تشغيلية نشطة؛ لن أضيف عملاً جديدًا حتى تقل الحمولة.';$out[]=['agent'=>$row['slug'],'state'=>'busy','active'=>$active,'reason'=>$note];self::reschedule($agentId,$cadence,'busy',null,$note);continue;}
                $dailyQ=db()->prepare("SELECT COUNT(*) FROM agent_initiatives WHERE agent_id=? AND created_at>=CURDATE()");$dailyQ->execute([$agentId]);
                if((int)$dailyQ->fetchColumn()>=(int)($row['max_initiatives_per_day']??6)){$note='وصل الوكيل إلى حد المبادرات اليومي.';$out[]=['agent'=>$row['slug'],'state'=>'daily_limit','reason'=>$note];self::reschedule($agentId,$cadence,'daily_limit',null,$note);continue;}
                if((int)($row['brain_enabled']??1)!==1){$note='العقل الاستباقي معطل لهذا الوكيل.';$out[]=['agent'=>$row['slug'],'state'=>'brain_disabled','reason'=>$note];self::reschedule($agentId,$cadence,'brain_disabled',null,$note);continue;}

                $r=self::plan($row);$state=(string)($r['state']??'skip');$reason=trim((string)($r['reason']??''))?:'لم أجد خطوة ذات قيمة كافية الآن.';
                if($state!=='start'){$out[]=['agent'=>$row['slug'],'state'=>'skipped','reason'=>$reason];self::reschedule($agentId,$cadence,'skipped',null,$reason);continue;}
                $value=max(1,min(100,(int)($r['value_score']??65)));
                if($value<(int)($row['initiative_min_value']??60)){$note='الفكرة الحالية قيمتها '.$value.' من 100 وهي أقل من حد التنفيذ.';$out[]=['agent'=>$row['slug'],'state'=>'below_value_threshold','value'=>$value,'reason'=>$note];self::reschedule($agentId,$cadence,'below_value_threshold',null,$note);continue;}

                $projectId=(int)($r['project_id']??0)?:null;
                if($projectId!==null&&!Permissions::project($agentId,$projectId,'read'))$projectId=null;
                $goalId=(int)($r['goal_id']??0)?:null;if($goalId&&!CompanyGoalService::validateAssignment($goalId,$agentId))$goalId=null;
                $typed=(string)$row['slug']==='walid'?'walid_work':AgentInitiativeService::defaultAction((string)$row['slug']);

                if($typed!=='walid_work'){
                    $risk=(string)($r['risk_level']??'none');if(!in_array($risk,['none','low','medium','high','destructive'],true))$risk='high';
                    $payload=['planner_reason'=>$reason,'signals'=>$r['signals']??[]];
                    $iid=AgentInitiativeService::propose($agentId,(string)($r['title']?:'مبادرة تشغيلية'),(string)($r['description']?:$reason),$typed,$risk,$value,$goalId,$projectId,$payload,null,$reason);
                    $rank=['none'=>0,'low'=>1,'medium'=>2,'high'=>3,'destructive'=>4];$auto=(string)($row['auto_execute_max_risk']??'low');
                    if(($rank[$risk]??3)>($rank[$auto]??1)){
                        try{TeamChatService::post('agent',(string)$row['slug'],'owner','مبادرة #'.$iid.' تحتاج موافقة المالك: '.(string)($r['title']??''),'approval',$projectId,null,['initiative_id'=>$iid,'risk'=>$risk]);}catch(Throwable){}
                        $out[]=['agent'=>$row['slug'],'state'=>'proposed','initiative_id'=>$iid,'action'=>$typed,'risk'=>$risk,'requires_owner_approval'=>true];self::reschedule($agentId,$cadence,'proposed',null,'تم اقتراح مبادرة وتنتظر موافقة المالك.');continue;
                    }
                    try{
                        $done=AgentInitiativeService::execute($iid,false);$started++;
                        $note='نفّذ مبادرة استباقية: '.pb_substr((string)($r['title']??$reason),0,420);
                        $out[]=['agent'=>$row['slug'],'state'=>'completed','initiative_id'=>$iid,'action'=>$typed,'risk'=>$risk,'result'=>$done['result']??[]];self::reschedule($agentId,$cadence,'completed',null,$note,true);continue;
                    }catch(Throwable $e){
                        $safe=pb_substr(Security::redactSecrets($e->getMessage()),0,180);$out[]=['agent'=>$row['slug'],'state'=>'proposed','initiative_id'=>$iid,'error'=>$safe];self::reschedule($agentId,$cadence,'proposed',$safe,'تم حفظ المبادرة لكن تنفيذها لم يكتمل: '.AdminUi::humanError($safe));continue;
                    }
                }

                $title=trim((string)($r['title']??''));$description=trim((string)($r['description']??''));if($title===''||$description==='')throw new RuntimeException('autonomy_plan_invalid');
                $mode=(string)($r['initiative_mode']??'work');if(!in_array($mode,['work','owner_update','team_message'],true))$mode='work';
                if($mode==='owner_update'){
                    $sent=CommunicationGateway::agentToOwner((string)$row['slug'],$description,'dashboard',false,false);db()->prepare("INSERT INTO agent_initiatives(agent_id,project_id,initiative_type,title,rationale,state,evidence_json) VALUES (?,?,'owner_update',?,?,'completed',?)")->execute([$agentId,$projectId,pb_substr($title,0,220),pb_substr($reason,0,8000),j(['goal_id'=>$goalId,'sent'=>$sent])]);$iid=(int)db()->lastInsertId();if($goalId)CompanyGoalService::progress($goalId,$agentId,null,'Autonomous owner update',$iid,null,['title'=>$title]);self::reschedule($agentId,$cadence,'completed',null,'أرسل تحديثًا استباقيًا للمالك: '.$title,true);$out[]=['agent'=>$row['slug'],'state'=>'completed','initiative_id'=>$iid,'mode'=>$mode,'title'=>$title];$started++;continue;
                }
                if($mode==='team_message'){
                    $target=trim((string)($r['target_agent']??''));if($target===''||$target===(string)$row['slug'])$target='ramy';CommunicationGateway::agentMessage((string)$row['slug'],$target,$description,null,$projectId);db()->prepare("INSERT INTO agent_initiatives(agent_id,project_id,initiative_type,title,rationale,state,evidence_json) VALUES (?,?,'team_message',?,?,'completed',?)")->execute([$agentId,$projectId,pb_substr($title,0,220),pb_substr($reason,0,8000),j(['target_agent'=>$target,'goal_id'=>$goalId])]);$iid=(int)db()->lastInsertId();if($goalId)CompanyGoalService::progress($goalId,$agentId,null,'Autonomous team message',$iid,null,['title'=>$title,'target_agent'=>$target]);self::reschedule($agentId,$cadence,'completed',null,'تواصل استباقيًا مع '.$target.': '.$title,true);$out[]=['agent'=>$row['slug'],'state'=>'completed','initiative_id'=>$iid,'mode'=>$mode,'target_agent'=>$target,'title'=>$title];$started++;continue;
                }
                $ctx=['job_kind'=>'agent_task','autonomous_initiative'=>1,'initiative_scope'=>(string)$row['initiative_scope'],'approved_actions'=>[],'autonomy_reason'=>pb_substr($reason,0,1000),'company_goal_id'=>$goalId];
                $hunt=Workflow::huntOpportunities('مبادرة وليد التلقائية: '.pb_substr($description,0,800),null,null,[],'agent',(string)$agentId);$tid=(int)($hunt['primary_task']??0);if($tid<1)throw new RuntimeException('walid_autonomy_task_missing');
                db()->prepare("INSERT INTO agent_initiatives(agent_id,task_id,project_id,initiative_type,title,rationale,state) VALUES (?,?,?,'proactive_work',?,?,'queued')")->execute([$agentId,$tid,$projectId,pb_substr($title,0,220),pb_substr($reason,0,8000)]);$iid=(int)db()->lastInsertId();if($goalId)CompanyGoalService::progress($goalId,$agentId,null,'Autonomous work queued',$iid,$tid,['title'=>$title]);
                try{TeamChatService::post('agent',(string)$row['slug'],'team','بدأت مبادرة #'.$iid.': '.$title,'status',$projectId,$tid,['autonomy'=>1]);}catch(Throwable){}
                self::reschedule($agentId,$cadence,'queued',null,'بدأ مبادرة تلقائية: '.$title,true);$out[]=['agent'=>$row['slug'],'state'=>'queued','initiative_id'=>$iid,'task_id'=>$tid,'mode'=>$mode,'title'=>$title];$started++;
            }catch(Throwable $e){$safe=pb_substr(Security::redactSecrets($e->getMessage(),220),0,220);self::reschedule($agentId,$cadence,'failed',$safe,'تعطلت جولة التفكير: '.AdminUi::humanError($safe));$out[]=['agent'=>$row['slug'],'state'=>'failed','error'=>$safe];}
        }
        return ['enabled'=>true,'checked'=>count($out),'started'=>$started,'items'=>$out];
    }

    private static function signals(array $a):array{
        $agentId=(int)$a['agent_id'];$s=['failed_jobs_24h'=>0,'blocked'=>0,'needs_review'=>0,'needs_fix'=>0,'project_id'=>0,'latest_error'=>''];
        try{$q=db()->prepare("SELECT j.project_id,j.error_code FROM jobs j WHERE j.agent_id=? AND j.state='failed' AND j.updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY j.id DESC LIMIT 1");$q->execute([$agentId]);$r=$q->fetch();if($r){$s['latest_error']=(string)($r['error_code']??'');$s['project_id']=(int)($r['project_id']??0);} $q=db()->prepare("SELECT COUNT(*) FROM jobs WHERE agent_id=? AND state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)");$q->execute([$agentId]);$s['failed_jobs_24h']=(int)$q->fetchColumn();}catch(Throwable){}
        try{$q=db()->prepare("SELECT status,COUNT(*) n FROM tasks WHERE assigned_agent_id=? AND status IN ('blocked','needs_review','needs_fix') GROUP BY status");$q->execute([$agentId]);foreach($q->fetchAll() as $r)$s[(string)$r['status']]=(int)$r['n'];}catch(Throwable){}
        if((string)$a['slug']==='emad')try{$s['ayman_waiting_review']=(int)db()->query("SELECT COUNT(*) FROM tasks t JOIN agents a ON a.id=t.assigned_agent_id WHERE a.slug='ayman' AND t.status='needs_review'")->fetchColumn();}catch(Throwable){$s['ayman_waiting_review']=0;}
        if((string)$a['slug']==='ramy')try{$s['system_blocked']=(int)db()->query("SELECT COUNT(*) FROM tasks WHERE status='blocked'")->fetchColumn();$s['system_failed_jobs_24h']=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();}catch(Throwable){$s['system_blocked']=0;$s['system_failed_jobs_24h']=0;}
        if((string)$a['slug']==='samir-social')try{$s['social_failed_7d']=(int)db()->query("SELECT COUNT(*) FROM social_publications WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();$s['social_challenges']=(int)db()->query("SELECT COUNT(*) FROM social_account_runs WHERE state='challenge'")->fetchColumn();$s['social_pending_runs']=(int)db()->query("SELECT COUNT(*) FROM social_account_runs WHERE state IN ('queued','running')")->fetchColumn();}catch(Throwable){$s['social_failed_7d']=0;$s['social_challenges']=0;$s['social_pending_runs']=0;}
        if((string)$a['slug']==='video-director')try{$s['media_pending']=(int)db()->query("SELECT COUNT(*) FROM media_requests WHERE state IN ('requested','submitted','processing','generating')")->fetchColumn();$s['video_failed_scenes']=(int)db()->query("SELECT COUNT(*) FROM video_scenes WHERE state='failed'")->fetchColumn();}catch(Throwable){$s['media_pending']=0;$s['video_failed_scenes']=0;}
        if((string)$a['slug']==='basant')try{$s['agency_open_events']=(int)db()->query("SELECT COUNT(*) FROM agency_events WHERE state IN ('open','waiting_supervisor','waiting_creator','scheduled')")->fetchColumn();}catch(Throwable){$s['agency_open_events']=0;}
        if((string)$a['slug']==='community-manager')try{$s['community_open_conversations']=(int)db()->query("SELECT COUNT(*) FROM conversation_sessions WHERE subject_type='customer' AND status='open'")->fetchColumn();$s['community_whatsapp_pending']=(int)db()->query("SELECT COUNT(*) FROM whatsapp_pending_messages WHERE state IN ('pending','failed') AND sent_at IS NULL")->fetchColumn();}catch(Throwable){$s['community_open_conversations']=0;$s['community_whatsapp_pending']=0;}
        if((string)$a['slug']==='free-model-scout')try{$s['free_provider_failed']=(int)db()->query("SELECT COUNT(*) FROM free_provider_candidates WHERE state='failed'")->fetchColumn();$s['free_provider_human_required']=(int)db()->query("SELECT COUNT(*) FROM free_provider_candidates WHERE state='human_required'")->fetchColumn();$s['free_models_working']=(int)db()->query("SELECT COUNT(*) FROM free_model_catalog WHERE active=1 AND health_state='working'")->fetchColumn();}catch(Throwable){$s['free_provider_failed']=0;$s['free_provider_human_required']=0;$s['free_models_working']=0;}
        return $s;
    }

    private static function fallbackPlan(array $a,array $signals,string $source='deterministic'):array{
        $slug=(string)$a['slug'];$projectId=(int)($signals['project_id']??0);
        if($slug==='ramy'&&(((int)($signals['system_blocked']??0)>0)||((int)($signals['system_failed_jobs_24h']??0)>0)))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'مراجعة نقاط التعطل الحالية','description'=>'راجع المهام المتوقفة والوظائف الفاشلة الحديثة، لخّص السبب في دردشة الفريق وحدد صاحب الخطوة التالية بدون تنفيذ تغييرات إنتاجية مباشرة.','reason'=>'هناك إشارات تشغيلية تحتاج تنسيق المدير الآن. المصدر: '.$source,'value_score'=>88,'risk_level'=>'none','project_id'=>$projectId,'goal_id'=>0,'signals'=>$signals];
        if($slug==='ayman'&&(((int)($signals['failed_jobs_24h']??0)>0)||((int)($signals['needs_fix']??0)>0)||((int)($signals['blocked']??0)>0)))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'تشخيص تنفيذ أيمن قبل المهمة التالية','description'=>'راجع آخر فشل أو مهمة تحتاج إصلاح، حدّد السبب التقني والدليل المطلوب، وأرسل تشخيصًا داخليًا لرامي. لا تعدّل Production ضمن هذه المبادرة.','reason'=>'أيمن لديه فشل أو إصلاح حديث يحتاج تشخيصًا وقائيًا. المصدر: '.$source,'value_score'=>86,'risk_level'=>'none','project_id'=>$projectId,'goal_id'=>0,'signals'=>$signals];
        if($slug==='emad'&&(((int)($signals['ayman_waiting_review']??0)>0)||((int)($signals['needs_review']??0)>0)))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'تجهيز مراجعات عماد المستحقة','description'=>'طابق مهام أيمن الجاهزة للمراجعة مع مهام QA، أنشئ المراجعات الناقصة داخليًا واعرض ما يحتاج Retest أو دليل إضافي.','reason'=>'توجد أعمال تنفيذ جاهزة أو مراجعات معلقة. المصدر: '.$source,'value_score'=>90,'risk_level'=>'none','project_id'=>$projectId,'goal_id'=>0,'signals'=>$signals];
        if($slug==='walid')return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'بحث جديد عن فرص مربحة','description'=>'ابدأ دورة بحث جديدة عن فرص وخدمات مدفوعة مناسبة لقدرات الفريق مع أولوية مصر والخليج والعالم العربي، ثم قيّمها وسجل الأدلة ووسائل التواصل المتاحة.','reason'=>'مهمة وليد الأساسية هي البحث الاستباقي وعند غياب خطة نموذجية يمكن تشغيل دورة منظمة. المصدر: '.$source,'value_score'=>78,'risk_level'=>'none','project_id'=>0,'goal_id'=>0,'signals'=>$signals];
        if($slug==='samir-social'&&(((int)($signals['social_failed_7d']??0)>0)||((int)($signals['social_challenges']??0)>0)||((int)($signals['social_pending_runs']??0)>0)))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'مراجعة تشغيل السوشيال والحسابات','description'=>'راجع عمليات النشر والحسابات المعلقة أو المتعثرة، صنف ما يحتاج إجراء داخليًا وما يحتاج تدخل المالك، ولا تنشر خارجيًا بدون الموافقة المحفوظة.','reason'=>'هناك إشارات تشغيلية في مسار السوشيال تحتاج متابعة سمير. المصدر: '.$source,'value_score'=>82,'risk_level'=>'none','project_id'=>$projectId,'goal_id'=>0,'signals'=>$signals];
        if($slug==='video-director'&&(((int)($signals['media_pending']??0)>0)||((int)($signals['video_failed_scenes']??0)>0)))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'مراجعة إنتاج الفيديو والوسائط','description'=>'راجع طلبات الوسائط الجارية والمشاهد الفاشلة وحدد سبب التوقف والخطوة التالية قبل أي رفع أو نشر خارجي.','reason'=>'توجد طلبات وسائط جارية أو مشاهد فاشلة تحتاج متابعة من منى. المصدر: '.$source,'value_score'=>84,'risk_level'=>'none','project_id'=>$projectId,'goal_id'=>0,'signals'=>$signals];
        if($slug==='basant'&&((int)($signals['agency_open_events']??0)>0))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'مراجعة أحداث وكالة الفارس','description'=>'راجع الأحداث والمتابعات المفتوحة للمبدعين والمشرفين وحدد الحالات التي تحتاج تذكيرًا أو تصعيدًا أو قرارًا من رامي.','reason'=>'هناك أحداث وكالة مفتوحة تحتاج متابعة بسنت. المصدر: '.$source,'value_score'=>80,'risk_level'=>'none','project_id'=>0,'goal_id'=>0,'signals'=>$signals];
        if($slug==='community-manager'&&(((int)($signals['community_open_conversations']??0)>0)||((int)($signals['community_whatsapp_pending']??0)>0)))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'مراجعة المجتمع والرسائل المعلقة','description'=>'راجع محادثات العملاء المفتوحة ورسائل واتساب المعلقة، وحدد ما يحتاج متابعة داخلية أو تصعيدًا إلى رامي بدون إرسال خارجي غير مصرح.','reason'=>'هناك محادثات أو رسائل معلقة تحتاج متابعة مدير المجتمع. المصدر: '.$source,'value_score'=>83,'risk_level'=>'none','project_id'=>0,'goal_id'=>0,'signals'=>$signals];
        if($slug==='free-model-scout'&&(((int)($signals['free_provider_failed']??0)>0)||((int)($signals['free_provider_human_required']??0)>0)))return ['state'=>'start','initiative_mode'=>'work','target_agent'=>'','title'=>'مراجعة مزودي وموديلات الذكاء المجانية','description'=>'راجع المزودات المتعثرة أو التي تحتاج تدخلاً بشريًا، لخّص الخيارات المتاحة ونقاط الإعداد المطلوبة، ولا تنشئ حسابات أو تتجاوز تحققًا بشريًا.','reason'=>'كتالوج نور يحتوي مزودات تحتاج متابعة أو قرارًا. المصدر: '.$source,'value_score'=>79,'risk_level'=>'none','project_id'=>0,'goal_id'=>0,'signals'=>$signals];
        return ['state'=>'skip','initiative_mode'=>'work','target_agent'=>'','title'=>'','description'=>'','reason'=>'لا توجد إشارة تشغيلية ملموسة تستحق مبادرة الآن.','value_score'=>0,'risk_level'=>'none','project_id'=>0,'goal_id'=>0,'signals'=>$signals];
    }

    private static function plan(array $a):array{
        $agentId=(int)$a['agent_id'];$recentQ=db()->prepare("SELECT title,status,updated_at,project_id FROM tasks WHERE assigned_agent_id=? ORDER BY id DESC LIMIT 12");$recentQ->execute([$agentId]);$recent=$recentQ->fetchAll();
        $signals=self::signals($a);$mem=AgentService::tool($agentId,'memory')?MemoryService::agentContext($agentId,null,12):[];$brain=[];try{$brain=AgentBrainService::context($agentId,null,(string)$a['mission_text'],12);}catch(Throwable){}$goals=[];try{$goals=CompanyGoalService::context($agentId);}catch(Throwable){}$performance=[];try{$performance=AgentPerformanceService::latestForAgent($agentId);}catch(Throwable){}
        $agency=[];if((string)$a['slug']==='basant'){try{$agency=AgencyService::summary();}catch(Throwable){$agency=[];}}
        $schema=['type'=>'object','additionalProperties'=>false,'properties'=>['state'=>['type'=>'string','enum'=>['start','skip']],'initiative_mode'=>['type'=>'string','enum'=>['work','owner_update','team_message']],'target_agent'=>['type'=>'string'],'title'=>['type'=>'string'],'description'=>['type'=>'string'],'reason'=>['type'=>'string'],'value_score'=>['type'=>'integer'],'risk_level'=>['type'=>'string','enum'=>['none','low','medium','high','destructive']],'project_id'=>['type'=>'integer'],'goal_id'=>['type'=>'integer']],'required'=>['state','initiative_mode','target_agent','title','description','reason','value_score','risk_level','project_id','goal_id']];
        $scope=(string)($a['initiative_scope']??'owner_team');
        $prompt="You are planning one proactive action for employee ".(string)$a['display_name'].". Do not invent busywork. Use the mission, structured memory, concrete runtime signals, company goals, measured performance, and recent work. Prefer useful internal coordination, diagnosis, review, or already-authorized workflow execution. External contact is allowed only through an already authorized workflow/tool and a stored enabled contact/account; never bypass CAPTCHA/2FA, never expose secrets, and never modify production/destructive resources without the required owner gate. If there is no concrete value now, return state=skip. Initiative scope: ".$scope."\nMISSION: ".(string)$a['mission_text']."\nRUNTIME_SIGNALS: ".j($signals)."\nRECENT_TASKS: ".j($recent)."\nLEGACY_MEMORY: ".j($mem)."\nSTRUCTURED_MEMORY: ".j($brain)."\nCOMPANY_GOALS: ".j($goals)."\nPERFORMANCE: ".j($performance)."\nAGENCY_SNAPSHOT: ".j($agency);
        try{
            $r=AiGateway::json($a,$prompt,[['role'=>'user','content'=>'اقترح مبادرة واحدة فقط للساعة الحالية، أو تخطها لو لا توجد قيمة واضحة.'] ],$schema,'agent_autonomy_plan',1600);$plan=(array)$r['data'];
            if((string)($plan['state']??'skip')!=='start'){$fallback=self::fallbackPlan($a,$signals,'إشارات النظام بعد تخطي المخطط');if((string)$fallback['state']==='start')return $fallback;}
            $plan['signals']=$signals;return $plan;
        }catch(Throwable $e){return self::fallbackPlan($a,$signals,'خطة حتمية بعد تعذر نموذج التخطيط: '.pb_substr(Security::redactSecrets($e->getMessage()),0,100));}
    }

    private static function reschedule(int $agentId,int $cadence,string $state,?string $error,string $note='',bool $initiative=false):void{
        $sql='UPDATE agent_autonomy SET last_run_at=NOW(),last_think_at=NOW(),next_run_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),last_state=?,last_brain_state=?,last_error=?,last_brain_note=?'.($initiative?',last_initiative_at=NOW()':'').' WHERE agent_id=?';
        db()->prepare($sql)->execute([$cadence,pb_substr($state,0,60),pb_substr($state,0,80),$error,pb_substr($note,0,600),$agentId]);
    }
}
