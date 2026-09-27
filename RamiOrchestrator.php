<?php
declare(strict_types=1);
final class RamiOrchestrator {
    public static function owner(int $sessionId,string $text):array{
        $text=trim($text);if($text==='')return ['reply'=>'وصلت رسالة فارغة، ابعتلي المطلوب وأنا أتصرف.','action'=>null];
        $pending=ConversationService::pending($sessionId);
        if($pending&&ContextEngine::isExecutionWord($text)){
            $plan=json_decode((string)$pending['plan_json'],true)?:[];$out=self::execute($sessionId,$plan,true);db()->prepare("UPDATE pending_actions SET state=?,executed_at=NOW() WHERE id=?")->execute([$out['ok']?'executed':'failed',$pending['id']]);return $out;
        }
        $plan=ContextEngine::ownerPlan($sessionId,$text);
        $project=self::resolveProject($sessionId,(string)($plan['project_ref']??''));if($project){$plan['project_id']=(int)$project['id'];ConversationService::focus($sessionId,(int)$project['id'],$plan['task_id']? (int)$plan['task_id']:null,AgentService::bySlug('ramy')['id']);}
        if(($plan['destructive']??false)===true&&!($plan['execute_now']??false)){ConversationService::savePending($sessionId,$plan,true,'destructive');return ['ok'=>true,'reply'=>trim((string)$plan['summary_to_owner'])."
ده إجراء مدمّر. سأتحقق من وجود Backup/Restore Point صالح، ولو المطلوب صحيح قل: نفذ.",'action'=>'pending_destructive'];}
        if(($plan['destructive']??false)===true&&($plan['execute_now']??false))$plan['_owner_destructive_confirmed']=true;
        if(($plan['needs_ack']??false)===true&&!($plan['execute_now']??false)){ConversationService::savePending($sessionId,$plan,false,'normal');return ['ok'=>true,'reply'=>trim((string)$plan['summary_to_owner'])."\nلو ده المطلوب قل: تمام أو ابدأ.",'action'=>'pending'];}
        if(($plan['intent']??'conversation')==='conversation')return ['ok'=>true,'reply'=>trim((string)$plan['reply_only'])?:trim((string)$plan['summary_to_owner']),'action'=>null];
        return self::execute($sessionId,$plan,(bool)($plan['_owner_destructive_confirmed']??false));
    }
    private static function execute(int $sessionId,array $plan,bool $confirmed):array{
        $intent=(string)($plan['intent']??'conversation');$projectId=(int)($plan['project_id']??0);if(!$projectId){$p=self::resolveProject($sessionId,(string)($plan['project_ref']??''));$projectId=(int)($p['id']??0);}
        $previousActor=Audit::actor();$ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));Audit::setActor('agent',(string)$ramy['id']);
        try{
            // Self-heal Ramy's authority before each real action so new tools, agents and projects never leave the executive with stale permissions.
            RamiAuthorityService::sync(false);if($projectId)RamiAuthorityService::ensureProject($projectId);
            self::authorizeIntent($intent,$projectId);
            $r=match($intent){
                'hunt_opportunities'=>Workflow::huntOpportunities((string)($plan['request']?:'البحث عن فرص جديدة'),(int)($plan['lookback_days']??0)?:null,(int)($plan['raw_target']??0)?:null,(array)($plan['queries']??[])),
                'hosting_inventory','scan_projects'=>Workflow::scanHosting((string)($plan['request']?:'فهرسة Hostinger بأمر المالك'),$projectId?:null),
                'opportunity_status'=>self::opportunityStatus((int)($plan['opportunity_id']??0)),
                'approve_opportunity'=>self::approveOpportunity((int)($plan['opportunity_id']??0)),
                'reject_opportunity'=>self::rejectOpportunity((int)($plan['opportunity_id']??0),(string)($plan['request']??'')),
                'create_master_brief'=>self::createMasterBrief($projectId,(string)($plan['request']??'')),
                'full_project_review'=>$projectId?Workflow::fullProjectReview($projectId,(string)($plan['request']?:'مراجعة وإصلاح المشروع')):throw new RuntimeException('project_context_missing'),
                'staging_prepare'=>$projectId?Workflow::prepareStaging($projectId):throw new RuntimeException('project_context_missing'),
                'staging_promote'=>$projectId?Workflow::promoteStaging($projectId):throw new RuntimeException('project_context_missing'),
                'assign_ayman'=>$projectId?Workflow::assignAyman($projectId,(string)($plan['request']?:'تنفيذ المطلوب')):throw new RuntimeException('project_context_missing'),
                'review_with_emad'=>self::reviewWithEmad($sessionId,$projectId,(int)($plan['task_id']??0),(string)($plan['request']??'')),
                'agent_status'=>self::agentStatus((string)($plan['agent_slug']??'')),
                'agent_set_status'=>self::agentSetStatus((string)($plan['agent_slug']??''),(string)($plan['desired_status']??'')),
                'project_status'=>self::projectStatus($projectId),
                'task_status'=>self::taskStatus((int)($plan['task_id']??0),$sessionId),
                'task_cancel'=>self::taskCancel((int)($plan['task_id']??0),$sessionId),
                'task_retry'=>self::taskRetry((int)($plan['task_id']??0),$sessionId),
                'task_reassign'=>self::taskReassign((int)($plan['task_id']??0),$sessionId,(string)($plan['agent_slug']??'')),
                'task_priority'=>self::taskPriority((int)($plan['task_id']??0),$sessionId,(string)($plan['priority']??'')),
                'task_rollback'=>self::taskRollback((int)($plan['task_id']??0),$sessionId),
                'project_set_status'=>self::projectSetStatus($projectId,(string)($plan['desired_status']??'')),
                'project_maintenance'=>self::projectMaintenance($projectId,(bool)($plan['maintenance_enabled']??false)),
                'create_agent'=>self::createAgent($plan),
                'send_agent_message'=>self::sendAgentMessage((string)($plan['agent_slug']??''),(string)($plan['request']??''),(int)($plan['task_id']??0),$projectId),
                'account_setup'=>self::accountSetup((string)($plan['request']??'')),
                'account_setup_status'=>self::accountSetupStatus((string)($plan['request']??'')),
                'connection_test'=>self::connectionTest((string)($plan['provider_key']??'')),
                'customer_status'=>self::customerStatus((string)($plan['customer_ref']??'')),
                'contact_customer'=>self::contactCustomer($sessionId,(string)($plan['customer_ref']??''),(string)($plan['request']??''),(int)($plan['opportunity_id']??0),(string)($plan['customer_message']??'')),
                'dns_status'=>self::dnsStatus($projectId),
                'dns_add_record'=>self::dnsAddRecord($projectId,(string)($plan['dns_name']??''),(string)($plan['dns_type']??''),(string)($plan['dns_content']??''),(int)($plan['dns_ttl']??14400)),
                'subdomain_list'=>self::subdomainList($projectId),
                'subdomain_create'=>self::subdomainCreate($projectId,(string)($plan['subdomain_name']??''),(string)($plan['subdomain_directory']??'')),
                'subdomain_delete'=>self::subdomainDelete($projectId,(string)($plan['subdomain_name']??'')),
                'project_database_backup'=>self::projectDatabaseBackup($projectId),
                'install_worker_cron'=>self::installWorkerCron(),
                'ramy_diagnose_system'=>RamiRepairService::diagnoseSystem($sessionId,(string)($plan['request']??'')),
                'ramy_repair_whatsapp'=>RamiRepairService::repairWhatsApp($sessionId,(string)($plan['request']??'')),
                'ramy_repair_system'=>RamiRepairService::repairSystem($sessionId,(string)($plan['request']??'')),
                'ramy_repair_project'=>$projectId?RamiRepairService::repairProject($projectId,$sessionId,(string)($plan['request']??'')):throw new RuntimeException('project_context_missing'),
                'delete_site'=>self::deleteSite($projectId,$confirmed),
                'delete_database'=>self::deleteDatabase($projectId,(string)($plan['database_ref']??''),$confirmed),
                default=>throw new RuntimeException('ramy_action_not_supported')
            };
            $task=(int)($r['primary_task']??0);if($task)ConversationService::focus($sessionId,$projectId?:null,$task,AgentService::bySlug('ramy')['id']);$reply=(string)($r['summary']??$plan['summary_to_owner']??'تم تسجيل التنفيذ.');Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'ramy.execute',$intent,$task?(string)$task:null,'executed',$projectId?:null,$task?:null,['plan'=>$intent]);return ['ok'=>true,'reply'=>$reply,'action'=>$intent,'task_id'=>$task?:null];
        }catch(Throwable $e){$msg=$e->getMessage();$advisor=RamiErrorAdvisor::explain($e,$intent);$ownerText=RamiErrorAdvisor::ownerText($e,$intent);Notifications::add('warning','ramy','تعذر تنفيذ أمر رامي',$ownerText,'session',(string)$sessionId);Audit::log('agent',(string)$ramy['id'],'ramy.error',$intent,null,'failed',$projectId?:null,null,['error_code'=>$advisor['code'],'cause'=>$advisor['cause'],'fix'=>$advisor['fix']]);return ['ok'=>false,'reply'=>$ownerText.'
ما سجلتش العملية كنجاح.','action'=>$intent,'error'=>$advisor];}finally{Audit::setActor((string)$previousActor['type'],(string)$previousActor['id']);}
    }
    private static function authorizeIntent(string $intent,int $projectId):void{
        $ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));$rid=(int)$ramy['id'];
        $permissions=match($intent){
            'hunt_opportunities'=>['opportunities.hunt','opportunities.read'],
            'hosting_inventory','scan_projects'=>['projects.discover','hosting.read','domains.read'],
            'opportunity_status'=>['opportunities.read'],
            'approve_opportunity','reject_opportunity'=>['opportunities.manage'],
            'create_master_brief'=>['brief.write','projects.update'],
            'full_project_review','assign_ayman','review_with_emad','task_reassign','task_retry','task_priority'=>['tasks.assign'],
            'staging_prepare'=>['hosting.manage','domains.manage','projects.update'],
            'staging_promote'=>['hosting.manage','files.read','files.write','backup.create','deploy.execute'],
            'task_cancel'=>['tasks.cancel'],
            'task_rollback'=>['backup.restore'],
            'agent_status'=>['agents.view'],
            'agent_set_status'=>['agents.manage'],
            'create_agent'=>['agents.create'],
            'project_status'=>['projects.read'],
            'project_set_status'=>['projects.update'],
            'project_maintenance'=>['hosting.manage'],
            'send_agent_message'=>['communication.manage'],
            'account_setup'=>['tasks.assign','api.use'],
            'account_setup_status'=>['agents.view','api.use'],
            'connection_test'=>['api.use'],
            'customer_status'=>['communication.read'],
            'contact_customer'=>['communication.manage'],
            'dns_status','subdomain_list'=>['domains.read','hosting.read'],
            'dns_add_record','subdomain_create','subdomain_delete'=>['domains.manage'],
            'project_database_backup'=>['backup.create','database.read'],
            'install_worker_cron'=>['cron.manage'],
            'ramy_diagnose_system'=>['logs.read','hosting.read','files.read','database.read','api.use'],
            'ramy_repair_whatsapp'=>['communication.manage','communication.read','api.use'],
            'ramy_repair_system','ramy_repair_project'=>['logs.read','hosting.read','hosting.manage','files.read','files.write','database.read','database.write','database.schema','backup.create','api.use','testing.execute'],
            'delete_site'=>['projects.delete','hosting.manage','backup.restore'],
            'delete_database'=>['database.drop','backup.restore'],
            default=>[]
        };
        foreach($permissions as $permission)Permissions::requireAgent($rid,$permission);
        $tools=match($intent){
            'hunt_opportunities'=>['task_orchestrator'],
            'hosting_inventory','scan_projects'=>['task_orchestrator','hostinger','projects'],
            'opportunity_status','approve_opportunity','reject_opportunity'=>['task_orchestrator'],
            'create_master_brief'=>['projects','master_brief','project_chat'],
            'full_project_review','assign_ayman','review_with_emad','task_cancel','task_retry','task_reassign','task_priority'=>['task_orchestrator'],
            'agent_status','agent_set_status','create_agent'=>['task_orchestrator'],
            'project_status','project_set_status'=>['projects'],
            'project_maintenance','dns_status','dns_add_record','subdomain_list','subdomain_create','subdomain_delete','project_database_backup','install_worker_cron','delete_site','delete_database'=>['hostinger','projects'],
            'ramy_diagnose_system'=>['hostinger','hostinger_files','project_files','database_read','logs','api_test'],
            'ramy_repair_whatsapp'=>['communications','api_test'],
            'ramy_repair_system','ramy_repair_project'=>['hostinger','hostinger_files','project_files','database','database_read','backup','ai_code','http_test','logs','api_test','projects'],
            'account_setup','account_setup_status'=>['task_orchestrator','api_test'],
            'send_agent_message','customer_status','contact_customer'=>['communications'],
            default=>[]
        };
        foreach($tools as $tool)AgentService::requireTool($rid,$tool);
        $needsManage=in_array($intent,['create_master_brief','project_set_status','project_maintenance','dns_add_record','subdomain_create','subdomain_delete','project_database_backup','ramy_repair_project','delete_site','delete_database'],true);
        $needsRead=in_array($intent,['project_status','dns_status','subdomain_list'],true);
        if($projectId&&($needsManage||$needsRead))Permissions::requireProject($rid,$projectId,$needsManage?'manage':'read');
    }

    private static function accountSetup(string $request):array{
        $test=ConnectionTester::test('browser_automation');
        if((string)($test['state']??'failed')!=='verified')throw new RuntimeException((string)($test['code']??'browser_automation_failed'));
        $samir=AgentService::assertRunnable(AgentService::bySlug('samir-social'));
        $description="طلب المالك حرفيًا:
".trim($request)."

نفّذ كسمير سوشيال باستخدام account.create وBrowser Automation فقط بعد هذا الـPreflight الناجح. إذا كان الطلب Gmail ثم Facebook بنفس البريد، ابدأ Gmail أولًا ولا تعتبر البريد جاهزًا لفيسبوك إلا بعد وجود Evidence أن حساب Google/Gmail تم إنشاؤه أو وصل إلى نقطة تحقق بشري واضحة. عند CAPTCHA أو 2FA أو phone verification توقف وسجّل challenge واطلب تدخل المالك؛ ممنوع تجاوز الحماية أو ادعاء نجاح. لا تعرض كلمة المرور في الشات أو الـEvidence؛ تحفظ في SecretVault فقط.";
        $ctx=['job_kind'=>'agent_task','source'=>'ramy_account_setup','account_setup'=>1,'approved_actions'=>['account.create','browser.external'],'owner_request'=>$request,'browser_preflight'=>$test,'requested_by'=>'owner'];
        $taskId=TaskService::create('samir-social','إنشاء/ربط حسابات خارجية', $description,null,$ctx,'high',true,null,'owner','1',true);
        TeamChatService::post('agent','ramy','samir-social','تكليف حسابات معتمد من المالك — المهمة #'.$taskId.'؛ التزم بالتوقف عند أي تحقق بشري.', 'command',null,$taskId);
        return ['summary'=>'اختبار Browser Automation نجح. أنشأت مهمة #'.$taskId.' لسمير لإنشاء الحسابات المطلوبة، مع صلاحية account.create فقط وتوقف إجباري عند CAPTCHA/2FA/تحقق الهاتف.','primary_task'=>$taskId];
    }
    private static function accountSetupStatus(string $request=''):array{
        $test=ConnectionTester::test('browser_automation');$samir=AgentService::bySlug('samir-social');
        $q=db()->prepare("SELECT id,title,status,context_json,updated_at,completed_at FROM tasks WHERE assigned_agent_id=? ORDER BY id DESC LIMIT 20");$q->execute([(int)$samir['id']]);$latest=null;
        foreach($q->fetchAll() as $row){$ctx=json_decode((string)($row['context_json']??'{}'),true)?:[];if(($ctx['source']??'')==='ramy_account_setup'||!empty($ctx['account_setup'])){$latest=$row;break;}}
        $state=(string)($test['state']??'failed');$code=(string)($test['code']??'unknown');
        if($state!=='verified'){$ad=RamiErrorAdvisor::explain($code,'account_setup_status');$summary='مسار إنشاء الحسابات نفسه غير جاهز: '.$ad['title'].'. السبب: '.$ad['cause'].' الإصلاح: '.$ad['fix'];}
        else{$summary='Browser Automation متحقق منه الآن.';}
        if($latest)$summary.=' آخر مهمة حسابات لسمير #'.$latest['id'].' حالتها '.AdminUi::label((string)$latest['status']).' وآخر تحديث '.AdminUi::date((string)$latest['updated_at']).'.';else $summary.=' لا توجد مهمة account_setup مسجلة لسمير حتى الآن؛ الطلب السابق لم يدخل مسار إنشاء الحسابات المتخصص.';
        return ['summary'=>$summary,'browser_test'=>$test,'primary_task'=>$latest?(int)$latest['id']:0];
    }
    private static function resolveProject(int $sessionId,string $ref):?array{$s=ConversationService::session($sessionId);return ProjectService::resolve($ref,$s['active_project_id']?(int)$s['active_project_id']:null);}
    private static function agentStatus(string $slug):array{
        $slug=$slug?:'ramy';$a=AgentService::bySlug($slug);
        $q=db()->prepare("SELECT id,title,status,created_at,updated_at,completed_at FROM tasks WHERE assigned_agent_id=? ORDER BY id DESC LIMIT 5");$q->execute([$a['id']]);$recent=$q->fetchAll();
        $active=array_values(array_filter($recent,static fn($t)=>!in_array((string)$t['status'],['completed','cancelled','failed'],true)));
        $summary=$a['display_name'].' حالته '.AdminUi::label((string)$a['status']).'. ';
        if($active){$t=$active[0];$summary.='عنده '.count($active).' مهمة نشطة من آخر المهام؛ الحالية #'.$t['id'].' «'.$t['title'].'» وحالتها '.AdminUi::label((string)$t['status']).'، آخر تحديث '.AdminUi::date((string)$t['updated_at']).'.';$primary=(int)$t['id'];}
        elseif($recent){$t=$recent[0];$summary.='مفيش مهمة نشطة دلوقتي. آخر مهمة #'.$t['id'].' «'.$t['title'].'» حالتها '.AdminUi::label((string)$t['status']).($t['completed_at']?' واتقفلت '.AdminUi::date((string)$t['completed_at']):'، آخر تحديث '.AdminUi::date((string)$t['updated_at'])).'.';$primary=(int)$t['id'];}
        else{$summary.='مفيش مهام مسجلة له لحد دلوقتي.';$primary=null;}
        $summary.=' مش هقدّر نسبة إنجاز أو ميعاد من عندي؛ بعرض الحالة المسجلة فعليًا.';
        return ['summary'=>$summary,'primary_task'=>$primary];
    }
    private static function projectStatus(int $id):array{if(!$id)throw new RuntimeException('project_context_missing');$s=ProjectService::statusSummary($id);$p=$s['project'];$parts=[];foreach($s['tasks'] as $x)$parts[]=AdminUi::label((string)$x['status']).': '.$x['c'];return ['summary'=>'المشروع '.$p['name'].' ('.$p['primary_domain'].') حالته '.AdminUi::label((string)$p['status']).'. المهام: '.($parts?implode('، ',$parts):'لا توجد مهام حالية').'.','primary_task'=>null];}
    private static function taskStatus(int $id,int $sessionId):array{if(!$id){$s=ConversationService::session($sessionId);$id=(int)($s['active_task_id']??0);}if(!$id)throw new RuntimeException('task_context_missing');$t=TaskService::get($id);return ['summary'=>'المهمة #'.$id.' «'.$t['title'].'» حالتها '.AdminUi::label((string)$t['status']).'، والمسؤول '.$t['agent_name'].'.','primary_task'=>$id];}
    private static function projectSetStatus(int $id,string $status):array{if(!$id)throw new RuntimeException('project_context_missing');ProjectService::setStatus($id,$status?:'maintenance');return ['summary'=>'تم تحديث حالة المشروع إلى '.AdminUi::label($status).'.','primary_task'=>null];}
    private static function reviewWithEmad(int $sessionId,int $projectId,int $taskId,string $request):array{if(!$taskId){$s=ConversationService::session($sessionId);$taskId=(int)($s['active_task_id']??0);}if(!$taskId){$q=db()->prepare("SELECT id FROM tasks WHERE (?=0 OR project_id=?) AND assigned_agent_id=(SELECT id FROM agents WHERE slug='ayman') AND status IN ('needs_review','completed') ORDER BY id DESC LIMIT 1");$q->execute([$projectId,$projectId]);$taskId=(int)($q->fetchColumn()?:0);}if(!$taskId)throw new RuntimeException('task_context_missing');$orig=TaskService::get($taskId);if($orig['agent_slug']!=='ayman'||!in_array((string)$orig['status'],['needs_review','completed'],true))throw new RuntimeException('task_not_ready_for_review');$q=db()->prepare("SELECT id,status FROM tasks WHERE parent_task_id=? AND assigned_agent_id=(SELECT id FROM agents WHERE slug='emad') AND status NOT IN ('completed','cancelled','failed') ORDER BY id DESC LIMIT 1");$q->execute([$taskId]);$existing=$q->fetch();if($existing)return ['summary'=>'عماد بالفعل بيراجع المهمة #'.$taskId.' في مهمة المراجعة #'.$existing['id'].'.','primary_task'=>(int)$existing['id']];$rid=TaskService::create('emad','مراجعة بطلب المالك: '.$orig['title'],$request?:'راجع آخر عمل فعليًا وقدّم تقريرًا بالأدلة.',$orig['project_id']?(int)$orig['project_id']:null,['review_of'=>$taskId],'high',true,$taskId,'agent',(string)AgentService::bySlug('ramy')['id']);return ['summary'=>'شغّلت عماد على المهمة #'.$taskId.'، والتقرير هيرجع لرامي تلقائيًا.','primary_task'=>$rid];}
    private static function activeTaskId(int $id,int $sessionId):int{if($id>0)return $id;$s=ConversationService::session($sessionId);$id=(int)($s['active_task_id']??0);if(!$id)throw new RuntimeException('task_context_missing');return $id;}
    private static function agentSetStatus(string $slug,string $status):array{$slug=trim($slug);if($slug===''||$slug==='ramy'&&in_array($status,['disabled'],true))throw new RuntimeException('agent_context_missing');$a=AgentService::bySlug($slug);$allowed=['online','idle','working','disabled','error'];if(!in_array($status,$allowed,true))throw new RuntimeException('invalid_agent_status');AgentService::status((int)$a['id'],$status);Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'agent.status','agent',(string)$a['id'],'verified',null,null,['status'=>$status]);return ['summary'=>'تم تحديث حالة '.$a['display_name'].' إلى '.AdminUi::label($status).'.','primary_task'=>null];}
    private static function taskCancel(int $id,int $sessionId):array{$id=self::activeTaskId($id,$sessionId);$ramy=AgentService::bySlug('ramy');$count=TaskService::cancelTree($id,'agent',(string)$ramy['id']);return ['summary'=>'تم إلغاء المهمة #'.$id.' وإيقاف دورة العمل التابعة لها'.($count>1?' وعدد '.$count.' مهمة مرتبطة.':'.'),'primary_task'=>$id];}
    private static function taskRetry(int $id,int $sessionId):array{$id=self::activeTaskId($id,$sessionId);$t=TaskService::get($id);if(in_array($t['status'],['completed','cancelled'],true))throw new RuntimeException('task_retry_not_allowed');db()->prepare("UPDATE jobs SET state='cancelled',locked_at=NULL,lease_token=NULL,lease_expires_at=NULL,finished_at=NOW() WHERE task_id=? AND state IN ('queued','running','waiting')")->execute([$id]);db()->prepare("UPDATE tasks SET status='queued',completed_at=NULL WHERE id=?")->execute([$id]);TaskService::event($id,'agent',(string)AgentService::bySlug('ramy')['id'],'retried',(string)$t['status'],'queued');TaskService::queueIfReady($id);return ['summary'=>'تمت إعادة تشغيل المهمة #'.$id.' ومتابعتها في عامل التشغيل.','primary_task'=>$id];}
    private static function taskReassign(int $id,int $sessionId,string $slug):array{$id=self::activeTaskId($id,$sessionId);if($slug==='')throw new RuntimeException('agent_context_missing');$t=TaskService::get($id);$a=AgentService::bySlug($slug);if(!(int)$a['is_active']||(string)$a['status']==='disabled')throw new RuntimeException('agent_disabled');db()->prepare("UPDATE jobs SET state='cancelled',locked_at=NULL,lease_token=NULL,lease_expires_at=NULL,finished_at=NOW() WHERE task_id=? AND state IN ('queued','running','waiting')")->execute([$id]);db()->prepare("UPDATE tasks SET assigned_agent_id=?,status='queued',completed_at=NULL WHERE id=?")->execute([$a['id'],$id]);if(!empty($t['project_id'])){$scope=match($slug){'ramy'=>'manage','ayman'=>'work','emad'=>'read','walid'=>'read',default=>'read'};db()->prepare("INSERT INTO agent_project_access(agent_id,project_id,access_scope) VALUES (?,?,?) ON DUPLICATE KEY UPDATE access_scope=VALUES(access_scope)")->execute([$a['id'],$t['project_id'],$scope]);}TaskService::event($id,'agent',(string)AgentService::bySlug('ramy')['id'],'reassigned',(string)$t['status'],'queued',['to'=>$slug]);TaskService::queueIfReady($id);return ['summary'=>'تم نقل المهمة #'.$id.' إلى '.$a['display_name'].' وتشغيلها.','primary_task'=>$id];}
    private static function taskPriority(int $id,int $sessionId,string $priority):array{$id=self::activeTaskId($id,$sessionId);if(!in_array($priority,['low','normal','high','critical'],true))throw new RuntimeException('invalid_task_priority');db()->prepare('UPDATE tasks SET priority=? WHERE id=?')->execute([$priority,$id]);$jobPriority=['low'=>30,'normal'=>50,'high'=>75,'critical'=>100][$priority];db()->prepare("UPDATE jobs SET priority=? WHERE task_id=? AND state IN ('queued','waiting')")->execute([$jobPriority,$id]);return ['summary'=>'تم تغيير أولوية المهمة #'.$id.' إلى '.$priority.'.','primary_task'=>$id];}
    private static function taskRollback(int $id,int $sessionId):array{$id=self::activeTaskId($id,$sessionId);$r=BackupService::rollbackTask($id);$summary=$r['complete']?'تم إرجاع تعديلات الملفات للمهمة #'.$id.' والتحقق من النسخ المستخدمة.':'تم إرجاع ما يمكن بأمان للمهمة #'.$id.'، لكن يوجد جزء يحتاج تدخلًا يدويًا؛ راجع الجرس والتقرير قبل اعتبار الرجوع مكتملًا.';return ['summary'=>$summary,'primary_task'=>$id,'rollback'=>$r];}
    private static function projectDatabaseBackup(int $projectId):array{if(!$projectId)throw new RuntimeException('project_context_missing');$r=BackupService::createProjectDatabaseBackups($projectId);return ['summary'=>'تم إنشاء '.count($r['created']).' نسخة قاعدة بيانات متحققة'.($r['failed']?' مع '.count($r['failed']).' قاعدة لم يكتمل نسخها.':' بنجاح.'),'primary_task'=>null,'backup'=>$r];}
    private static function subdomainList(int $projectId):array{if(!$projectId)throw new RuntimeException('project_context_missing');$rows=HostingerClient::subdomains($projectId);$names=[];foreach($rows as $x)if(is_array($x))$names[]=(string)($x['domain']??$x['subdomain']??$x['name']??'');$names=array_values(array_filter($names));return ['summary'=>$names?'الدومينات الفرعية الحالية: '.implode('، ',array_slice($names,0,30)).'.':'لم أجد دومينات فرعية مسجلة للمشروع.','primary_task'=>null];}
    private static function subdomainCreate(int $projectId,string $name,string $directory):array{if(!$projectId)throw new RuntimeException('project_context_missing');if(trim($name)===''||trim($directory)==='')throw new RuntimeException('subdomain_context_missing');HostingerClient::createSubdomain($projectId,$name,$directory,true);return ['summary'=>'تم إرسال إنشاء الدومين الفرعي '.$name.' وربطه بالمسار '.$directory.'.','primary_task'=>null];}
    private static function subdomainDelete(int $projectId,string $name):array{if(!$projectId)throw new RuntimeException('project_context_missing');if(trim($name)==='')throw new RuntimeException('subdomain_context_missing');HostingerClient::deleteSubdomain($projectId,$name);return ['summary'=>'تم حذف الدومين الفرعي '.$name.' من المشروع.','primary_task'=>null];}
    private static function sendAgentMessage(string $slug,string $body,int $taskId,int $projectId):array{if($slug===''||trim($body)==='')throw new RuntimeException('agent_message_incomplete');$r=CommunicationGateway::agentCommand('ramy',$slug,$body,$taskId?:null,$projectId?:null);$a=AgentService::bySlug($slug);return ['summary'=>'تم إرسال تعليمات رامي إلى '.$a['display_name'].' وتشغيلها من شات الفريق. '.($r['summary']??''),'primary_task'=>$r['primary_task']??($taskId?:null),'message_id'=>$r['message_id']??null];}
    private static function opportunityStatus(int $id):array{if(!$id)throw new RuntimeException('opportunity_context_missing');$o=OpportunityService::get($id);$summary='الفرصة #'.$id.' — '.$o['title'].' — الحالة '.AdminUi::label((string)$o['status']).'، التقييم '.(int)$o['score'].'/100، المصدر '.$o['source'].'.';if($o['budget_max']!==null)$summary.=' الميزانية حتى '.number_format((float)$o['budget_max'],2).' '.AdminUi::currency((string)$o['currency']).'.';return ['summary'=>$summary,'primary_task'=>null];}
    private static function approveOpportunity(int $id):array{if(!$id)throw new RuntimeException('opportunity_context_missing');$r=OpportunityService::approve($id,'ramy');$quote='';try{$q=OpportunityService::createDraftQuote($id);$quote=' وتم تجهيز مسودة عرض #'.$q['quote_id'].'.';}catch(Throwable $e){if($e->getMessage()==='opportunity_budget_missing')$quote=' والميزانية غير معلنة، لذلك يحتاج السعر مراجعة قبل العرض.';elseif($e->getMessage()==='opportunity_currency_unverified')$quote=' وعملة المشروع غير مؤكدة من المصدر، لذلك أوقفت إنشاء عرض السعر لحد ما العملة تتأكد.';else throw $e;}$contact=self::contactApprovedOpportunity($id);return ['summary'=>'تم قبول الفرصة #'.$id.' ونقلها إلى رامي للتفاوض'.$quote.' '.$contact,'primary_task'=>null,'project_id'=>$r['project_id']];}

    private static function contactApprovedOpportunity(int $id):string{
        $o=OpportunityService::get($id);
        if(empty($o['customer_id']))return 'بيانات التواصل غير مكتملة؛ تم إبقاء الفرصة في التفاوض لحين إضافة وسيلة اتصال.';
        $contact=trim((string)($o['client_contact']??''));if($contact==='')return 'لم يجد وليد وسيلة تواصل مباشرة؛ رامي ينتظر استكمال بيانات العميل.';
        $lang=pb_strtolower(trim((string)($o['language']??'')));$type=trim((string)($o['opportunity_type']??$o['category']??'web project'));
        if(str_starts_with($lang,'ar')){$body='مرحبًا، أنا رامي من شركة المتر. راجعنا طلبكم لتنفيذ مشروع ويب، ويسعدنا مناقشة المطلوب والمدة والميزانية قبل البدء. هل يمكننا تأكيد التفاصيل الأساسية ونطاق العمل؟';}
        elseif(str_starts_with($lang,'fr')){$body='Bonjour, je suis Rami de EL-METR. Nous avons consulté votre demande pour un projet web. Nous pouvons discuter du périmètre, du délai et du budget avant de commencer. Pouvons-nous confirmer les besoins principaux du projet ?';}
        else{$body='Hello, I am Rami from EL-METR. We reviewed your request for a web project. We can discuss the scope, timeline, and budget before starting. May we confirm the main project requirements?';}
        try{
            $send=CommunicationGateway::outboundCustomer((int)$o['customer_id'],$body);
            if(!empty($send['requires_template'])){if(($send['template_state']??'')==='PENDING')return 'الفرصة جاهزة للتفاوض والرسالة الأصلية محفوظة. قدمت للنظام قالب افتتاح عبر Meta وحالته PENDING؛ أول ما يبقى Approved نقدر نبدأ التواصل، وبعد رد العميل الرسالة الأصلية هتخرج تلقائيًا.';return 'الفرصة جاهزة للتفاوض والرسالة محفوظة، لكن Meta محتاجة قالب افتتاح Approved. شغّل «تشخيص وإصلاح WhatsApp»؛ رامي هيحاول اختيار أو إنشاء قالب مناسب من غير ما يفهرس Hostinger.';}
            if(!empty($send['template'])&&!empty($send['awaiting_reply'])){OpportunityService::markContacted($id);ProjectChatService::post((int)($o['project_id']??0),'agent','ramy','Meta قبلت قالب افتتاح WhatsApp للعميل بخصوص '.($type?:'مشروع الويب').'، والرسالة الأصلية محفوظة لحد أول رد يفتح نافذة الـ24 ساعة.','client_update','opportunity',(string)$id,false,['language'=>$lang?:'unknown']);return 'Meta قبلت قالب الافتتاح للعميل، والرسالة الأصلية مستنية أول رد علشان تتبعت تلقائيًا. لسه ما نعتبرش الرسالة الأصلية وصلت.';}
            if(!empty($send['template_already_sent'])&&!empty($send['awaiting_reply']))return 'قالب الافتتاح اتبعت قبل كده ولسه مستنيين رد العميل؛ الرسالة الجديدة محفوظة وهتخرج تلقائيًا بعد الرد.';
            if(!empty($send['sent'])){OpportunityService::markContacted($id);ProjectChatService::post((int)($o['project_id']??0),'agent','ramy','Meta قبلت محاولة التواصل مع العميل بخصوص '.($type?:'مشروع الويب').'، ويتم تتبع sent/delivered/read من Webhook.','client_update','opportunity',(string)$id,false,['language'=>$lang?:'unknown']);return 'Meta قبلت إرسال الرسالة للعميل، وأنا متابع حالة التسليم والقراءة من Webhook؛ مش هاعتبرها وصلت قبل تأكيد delivered.';}
        }catch(Throwable $e){Notifications::add('warning','opportunities','تعذر بدء تواصل رامي','تم قبول الفرصة لكن الإرسال للعميل لم ينجح: '.AdminUi::humanError($e->getMessage()),'opportunity',(string)$id);}
        return 'تم تجهيز الفرصة للتفاوض، لكن إرسال أول رسالة يحتاج مراجعة قناة التواصل.';
    }
    private static function rejectOpportunity(int $id,string $reason):array{if(!$id)throw new RuntimeException('opportunity_context_missing');$reason=trim($reason);OpportunityService::reject($id,$reason?:'رفض المالك عبر رامي');return ['summary'=>'تم استبعاد الفرصة #'.$id.' وتسجيل البصمة لمنع إعادة اقتراحها بنفس الشكل.','primary_task'=>null];}
    private static function createMasterBrief(int $projectId,string $notes):array{if(!$projectId)throw new RuntimeException('project_context_missing');$override=[];if(trim($notes)!=='')$override['owner_notes']=trim($notes);$b=MasterBriefService::create($projectId,$override,false);return ['summary'=>'تم إنشاء Master Brief v'.$b['version'].' للمشروع ونشره كمرجع مثبت في شات الفريق.','primary_task'=>null,'brief_id'=>$b['id']];}
    private static function connectionTest(string $provider):array{if($provider==='')throw new RuntimeException('provider_required');$r=ConnectionTester::test($provider);$name=AdminUi::provider($provider);return ['summary'=>$r['state']==='verified'?'تم اختبار '.$name.' والاتصال شغال.':'اختبار '.$name.' فشل. '.AdminUi::humanError((string)($r['code']??'unknown')).' راجع صفحة المفاتيح والربط والجرس.','primary_task'=>null];}
    private static function resolveCustomer(string $ref,string $request=''):?array{
        $ref=trim($ref);$request=trim($request);$digits=preg_replace('/\D+/','',$ref);
        if($digits==='')$digits=preg_replace('/\D+/','',$request);
        $norm='';if($digits!==''){$norm=ConversationService::normalizePhone($digits);}
        if($norm!==''){
            $local=str_starts_with($norm,'20')?'0'.substr($norm,2):$norm;
            $q=db()->prepare("SELECT c.*,(SELECT MAX(cv.id) FROM conversations cv WHERE cv.customer_id=c.id) last_conversation_id FROM customers c WHERE c.phone IN (?,?) OR c.external_ref IN (?,?) ORDER BY COALESCE(last_conversation_id,0) DESC,c.updated_at DESC,c.id ASC LIMIT 1");
            $q->execute([$norm,$local,$norm,$local]);$c=$q->fetch();if($c)return $c;
        }
        if($ref!==''&&ctype_digit($ref)&&strlen($ref)<=8){$q=db()->prepare('SELECT * FROM customers WHERE id=? LIMIT 1');$q->execute([(int)$ref]);$c=$q->fetch();if($c)return $c;}
        $name=trim(preg_replace('/\s+/u',' ',$ref)??$ref);
        if($name!==''&&!ctype_digit($name)){
            $q=db()->prepare("SELECT c.*,(SELECT MAX(cv.id) FROM conversations cv WHERE cv.customer_id=c.id) last_conversation_id FROM customers c WHERE display_name=? OR display_name LIKE ? ORDER BY (display_name=? ) DESC,COALESCE(last_conversation_id,0) DESC,c.updated_at DESC LIMIT 2");
            $q->execute([$name,'%'.$name.'%',$name]);$rows=$q->fetchAll();if($rows)return $rows[0];
        }
        if($request!==''){
            $q=db()->query("SELECT c.*,(SELECT MAX(cv.id) FROM conversations cv WHERE cv.customer_id=c.id) last_conversation_id FROM customers c WHERE display_name IS NOT NULL AND TRIM(display_name)<>'' ORDER BY COALESCE(last_conversation_id,0) DESC,c.updated_at DESC LIMIT 80");
            foreach($q->fetchAll() as $c){$n=trim((string)$c['display_name']);if($n!==''&&pb_strpos(pb_strtolower($request),pb_strtolower($n))!==false)return $c;}
        }
        return null;
    }

    private static function customerStatus(string $ref):array{
        $c=self::resolveCustomer($ref,$ref);if(!$c)throw new RuntimeException($ref===''?'customer_context_missing':'customer_not_found');$cid=(int)$c['id'];
        $q=db()->prepare("SELECT q.id,q.status,q.amount,q.currency FROM quotes q WHERE q.customer_id=? ORDER BY q.id DESC LIMIT 1");$q->execute([$cid]);$quote=$q->fetch()?:null;
        $q=db()->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.customer_id=? AND p.status='confirmed'");$q->execute([$cid]);$paid=(float)$q->fetchColumn();
        $q=db()->prepare("SELECT m.id,m.body_text,m.status,m.created_at,m.external_id,m.raw_json FROM messages m JOIN conversations cv ON cv.id=m.conversation_id WHERE cv.customer_id=? AND m.channel_key='whatsapp' AND m.provider='meta' AND m.direction='outbound' AND m.receiver_type='customer' ORDER BY m.id DESC LIMIT 1");$q->execute([$cid]);$lastOut=$q->fetch()?:null;
        $q=db()->prepare("SELECT m.id,m.body_text,m.created_at FROM messages m JOIN conversations cv ON cv.id=m.conversation_id WHERE cv.customer_id=? AND m.channel_key='whatsapp' AND m.provider='meta' AND m.direction='inbound' AND m.sender_type='customer' ORDER BY m.id DESC LIMIT 1");$q->execute([$cid]);$lastIn=$q->fetch()?:null;
        $pending=[];try{$pending=WhatsAppPolicy::pending($cid,10);}catch(Throwable){}
        $label=trim((string)($c['display_name']??''))?:((string)($c['phone']??'')?:'#'.$cid);
        $summary='العميل '.$label.' حالته '.AdminUi::label((string)$c['status']).'.';
        if($quote){$amount=(float)$quote['amount'];$remain=max(0,$amount-$paid);$summary.=' آخر عرض #'.$quote['id'].' حالته '.AdminUi::label((string)$quote['status']).' بقيمة '.number_format($amount,2).' '.AdminUi::currency((string)($quote['currency']?:'EGP')).'، والمدفوع المؤكد '.number_format($paid,2).' والمتبقي '.number_format($remain,2).'.';}
        if($lastOut){
            $rawOut=[];try{$rawOut=json_decode((string)($lastOut['raw_json']??''),true)?:[];}catch(Throwable){}$isTemplate=(string)($rawOut['_transport']??'')==='template';
            $status=(string)$lastOut['status'];$state=match($status){'read'=>'اتقرت','delivered'=>'وصلت لجهاز العميل','sent'=>'اترسلت من Meta','accepted'=>'Meta قبلتها للإرسال ولسه مفيش تأكيد تسليم','failed'=>'فشلت',default=>'حالتها '.AdminUi::label($status)};
            $summary.=' '.($isTemplate?'آخر قالب افتتاح واتساب':'آخر رسالة واتساب').' '.$state.' ('.AdminUi::date((string)$lastOut['created_at']).').';
            if($isTemplate&&$pending)$summary.=' ده قالب فتح المحادثة فقط؛ الرسالة الأصلية لسه محفوظة ومش هتخرج كنص حر إلا بعد رد العميل.';
            if($status==='failed'){
                $eq=db()->prepare("SELECT details_json FROM communication_events WHERE message_id=? AND state='failed' ORDER BY id DESC LIMIT 1");$eq->execute([(int)$lastOut['id']]);$details=$eq->fetchColumn();if($details){$raw=json_decode((string)$details,true);if(is_array($raw)){$failure=WhatsAppPolicy::metaFailure(['raw'=>$raw]);$err=((int)($failure['code']??0)>0?(string)$failure['code'].': ':'').((string)($failure['details']??$failure['title']??'')?:'WhatsApp delivery failed');$summary.="
".RamiErrorAdvisor::ownerText($err,'customer_status');}}
            }
        } else $summary.=' مفيش رسالة واتساب صادرة مسجلة للعميل حتى الآن.';
        if($lastIn)$summary.=' آخر رد من العميل كان '.AdminUi::date((string)$lastIn['created_at']).'.';else $summary.=' ومفيش رد وارد منه مسجل حتى الآن.';
        if($pending)$summary.=' فيه '.count($pending).' رسالة محفوظة في انتظار فتح نافذة WhatsApp/رد العميل.';
        return ['summary'=>$summary,'primary_task'=>null,'customer_id'=>$cid,'last_outbound_status'=>$lastOut['status']??null,'pending_count'=>count($pending)];
    }

    private static function phoneHint(string $text):string{
        $x=strtr($text,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        if(preg_match('/(?:\+?20)?0?1[0125][0-9]{8}/',$x,$m))return ConversationService::normalizePhone((string)$m[0]);
        return '';
    }

    private static function contactCustomer(int $sessionId,string $ref,string $request,int $opportunityId=0,string $customerMessage=''):array{
        // Recover the phone from the explicit reference, the owner request, then recent owner context.
        // This prevents customer_context_missing when the parser keeps a name while the phone is in the same or previous message.
        $customer=self::resolveCustomer($ref,$request);$digits=self::phoneHint($ref.' '.$request);
        if($digits===''){
            try{foreach(array_reverse(ConversationService::history($sessionId,16)) as $h){if((string)($h['sender_type']??'')!=='owner')continue;$digits=self::phoneHint((string)($h['body_text']??''));if($digits!=='')break;}}catch(Throwable){}
        }
        $name='';
        if(preg_match('/(?:اسمه|اسم(?:ه|ها)?|العميل)\s+([^\n،,.]{2,80})/u',$request,$m))$name=trim((string)$m[1]);
        if($name===''&&$ref!==''&&self::phoneHint($ref)===''&&!ctype_digit($ref))$name=trim($ref);
        if($name===''&&preg_match('/^\s*([\p{Arabic}A-Za-z][\p{Arabic}A-Za-z\s]{1,60}?)\s+(?:عايز|عاوزه|عايزة|يريد|محتاج|طالب|بيطلب|تواصل)/u',$request,$m))$name=trim((string)$m[1]);
        if(!$customer){
            if($digits==='')throw new RuntimeException('customer_phone_missing');
            db()->prepare("INSERT INTO customers(display_name,phone,primary_channel,status,notes) VALUES (?,?,'whatsapp','lead',?)")->execute([$name?:null,$digits,pb_substr($request,0,1200)]);
            $customer=['id'=>(int)db()->lastInsertId(),'display_name'=>$name,'phone'=>$digits,'status'=>'lead'];
            try{TeamChatService::post('agent','ramy','team','أنشأت بطاقة عميل جديدة تلقائيًا من أمر المالك: '.($name?:$digits).' وربطت رقم واتساب بالسياق.','status',null,null,['customer_id'=>(int)$customer['id'],'context_recovered'=>1]);}catch(Throwable){}
        }
        $cid=(int)$customer['id'];
        if($digits==='' )$digits=ConversationService::normalizePhone((string)($customer['phone']??''));
        if($digits==='')$digits=ConversationService::normalizePhone((string)($customer['external_ref']??''));
        if($digits===''){try{$pq=db()->prepare("SELECT external_thread_id FROM conversations WHERE customer_id=? AND channel_key='whatsapp' AND external_thread_id REGEXP '^[0-9]{8,16}$' ORDER BY id DESC LIMIT 1");$pq->execute([$cid]);$digits=ConversationService::normalizePhone((string)($pq->fetchColumn()?:''));}catch(Throwable){}}
        if($digits==='')throw new RuntimeException('customer_phone_missing');
        try{db()->prepare("UPDATE customers SET phone=COALESCE(NULLIF(phone,''),?),display_name=COALESCE(NULLIF(display_name,''),?),primary_channel='whatsapp',updated_at=NOW() WHERE id=?")->execute([$digits,$name?:null,$cid]);}catch(Throwable){}
        $clientName=trim((string)($customer['display_name']??''))?:$name;$body=trim($customerMessage);
        $retry=(bool)preg_match('/(?:مرة\s*(?:تانية|ثانية)|ابعت\s*(?:تاني|تانيه|ثاني|ثانية)|أعد|اعد|إعادة|اعادة|نفس\s*الرسالة|آخر\s*رسالة)/u',$request);
        if($body===''&&$retry){
            $q=db()->prepare("SELECT m.body_text FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.customer_id=? AND m.channel_key='whatsapp' AND m.provider='meta' AND m.direction='outbound' AND m.sender_type='agent' AND (m.raw_json IS NULL OR m.raw_json NOT LIKE '%\"_transport\":\"template\"%') AND m.body_text NOT LIKE '[قالب واتساب معتمد:%' ORDER BY (m.status='failed') DESC,m.id DESC LIMIT 1");$q->execute([$cid]);$body=trim((string)($q->fetchColumn()?:''));
        }
        if($body===''){
            $ramy=AgentService::bySlug('ramy');
            try{$ai=AiGateway::text($ramy,"اكتب رسالة واتساب قصيرة وطبيعية ومهنية بالمصري من رامي في شركة المتر للعميل. افهم طلب المالك الحالي وسياق العميل. لا تقل إنك ذكاء اصطناعي، ولا تكرر تعريف طويل لو كان سبق التواصل. لو الهدف جمع متطلبات موقع اسأل فقط عن المعلومات الناقصة. لا تثبت سعرًا قبل وضوح المتطلبات. اكتب الرسالة نفسها فقط.",[['role'=>'user','content'=>"اسم العميل: ".($clientName?:'غير معروف')."\nطلب المالك: ".$request]],650);$body=trim((string)($ai['text']??''));}catch(Throwable){}
        }
        if($body==='')$body='أهلاً'.($clientName!==''?' أ/'.$clientName:'').'، معاك رامي من شركة المتر. حابب أكمل مع حضرتك تفاصيل الموقع علشان نحدد المطلوب بدقة ونبدأ الخطوات المناسبة. ممكن تبعتلي نوع النشاط، أهم الصفحات والوظائف المطلوبة، وهل المحتوى جاهز والموعد المناسب للتسليم؟';
        $send=CommunicationGateway::outboundCustomer($cid,$body);
        if(!empty($send['requires_template'])){
            $state=strtoupper((string)($send['template_state']??'MISSING'));$reason=trim((string)($send['reason']??''));
            if($state==='PENDING')$summary='جهزت رسالة '.($clientName?:$digits).' وحفظتها. نافذة واتساب مقفولة وقالب الافتتاح ما زال تحت مراجعة ميتا. النظام سيعيد فحص الاعتماد تلقائيًا، وبعد رد العميل تُرسل الرسالة الأصلية.';
            else $summary='جهزت رسالة '.($clientName?:$digits).' وحفظتها، لكن بدء المحادثة يحتاج قالب افتتاح معتمد. السبب: '.($reason!==''?AdminUi::humanError($reason):'لا يوجد قالب افتتاح معتمد صالح حاليًا.').' الرسالة لم تضيع وستظل في الطابور لإعادة المحاولة.';
            return ['summary'=>$summary,'primary_task'=>null,'customer_id'=>$cid,'waiting_template'=>true,'template_state'=>$state];
        }
        if(!empty($send['template'])&&!empty($send['awaiting_reply']))return ['summary'=>'تم إرسال قالب الافتتاح لـ'.($clientName?:$digits).' وقبلته ميتا. الرسالة الأصلية محفوظة وستخرج تلقائيًا بعد رد العميل وفتح نافذة المحادثة.','primary_task'=>null,'customer_id'=>$cid,'awaiting_reply'=>true];
        if(!empty($send['template_already_sent'])&&!empty($send['awaiting_reply']))return ['summary'=>'قالب الافتتاح أُرسل من قبل وما زلنا ننتظر رد '.($clientName?:$digits).'. حفظت الرسالة الجديدة وسترسل تلقائيًا بعد الرد.','primary_task'=>null,'customer_id'=>$cid,'awaiting_reply'=>true];
        if(empty($send['sent']))throw new RuntimeException('customer_message_send_failed');
        if($opportunityId>0){try{OpportunityService::markContacted($opportunityId);}catch(Throwable){}}
        try{TeamChatService::post('agent','ramy','team','بدأت التواصل مع '.($clientName?:$digits).' على واتساب، وسأعتمد على حالة التسليم الفعلية قبل اعتبار الرسالة وصلت.','status',null,null,['channel'=>$send['channel']??'whatsapp','customer_id'=>$cid]);}catch(Throwable){}
        return ['summary'=>'تم إرسال الرسالة لـ'.($clientName?:$digits).' وقبلتها ميتا للإرسال. سأتابع حالة الوصول والقراءة والرد الفعلي من الاستقبال التلقائي.','primary_task'=>null,'customer_id'=>$cid];
    }

    private static function dnsStatus(int $id):array{if(!$id)throw new RuntimeException('project_context_missing');$p=ProjectService::get($id);$domain=(string)$p['primary_domain'];if($domain==='')throw new RuntimeException('project_domain_missing');$zone=HostingerClient::dnsZone($domain);$tls=TlsInspector::inspect($domain);$summary='الدومين '.$domain.' عنده '.count($zone).' مجموعة من إعدادات الدومين مسجلة.';$summary.=' شهادة الأمان '.(!empty($tls['ok'])?'سليمة حتى '.($tls['valid_to']??'تاريخ غير متاح'):'تعذر التحقق: '.AdminUi::humanError((string)($tls['error']??'unknown'))).'.';return ['summary'=>$summary,'primary_task'=>null];}
    private static function dnsAddRecord(int $id,string $name,string $type,string $content,int $ttl):array{if(!$id)throw new RuntimeException('project_context_missing');$p=ProjectService::get($id);$domain=(string)$p['primary_domain'];if($domain==='')throw new RuntimeException('project_domain_missing');if(trim($name)===''||trim($type)===''||trim($content)==='')throw new RuntimeException('dns_record_incomplete');$r=HostingerClient::addDnsRecord($domain,$name,$type,$content,$ttl>0?$ttl:14400);return ['summary'=>!empty($r['already_exists'])?'إعداد الدومين موجود بالفعل بنفس القيمة؛ لم أكرر إضافته.':'تم التحقق من إعداد الدومين وإرساله إلى Hostinger للدومين '.$domain.'.','primary_task'=>null];}
    private static function projectMaintenance(int $id,bool $enabled):array{if(!$id)throw new RuntimeException('project_context_missing');$r=HostingerClient::setMaintenance($id,$enabled);$p=ProjectService::get($id);return ['summary'=>($enabled?'تم وضع ':'تم تشغيل ').$p['name'].($enabled?' في وضع الصيانة':' بعد التحقق من إلغاء وضع الصيانة').'.','primary_task'=>null,'result'=>$r];}
    private static function createAgent(array $plan):array{$name=trim((string)($plan['new_agent_name']??''));$slug=trim((string)($plan['new_agent_slug']??''));$role=trim((string)($plan['new_agent_role']??''));$specialty=trim((string)($plan['new_agent_specialty']??''));$instructions=trim((string)($plan['new_agent_instructions']??''));if($name===''||$slug===''||$role===''||$specialty==='')throw new RuntimeException('agent_definition_incomplete');if($instructions==='')$instructions='أنت '.$name.'، وكيل متخصص في '.$specialty.'. تعمل تحت إدارة رامي، تلتزم بأقل صلاحية، لا تدعي النجاح دون تحقق، وتعيد النتائج والأدلة لرامي.';$id=AgentService::create(['slug'=>$slug,'display_name'=>$name,'role_title'=>$role,'specialty'=>$specialty,'description'=>(string)($plan['request']??''),'provider_key'=>'openai','model'=>'','system_prompt'=>$instructions]);return ['summary'=>'تم إنشاء الوكيل '.$name.' بصفحة وذاكرة وصلاحيات أولية محدودة تحت إدارة رامي.','primary_task'=>null,'agent_id'=>$id];}
    private static function installWorkerCron():array{$workerDomain=(string)(parse_url((string)config('app.base_url',''),PHP_URL_HOST)?:'persebayt.com');$r=HostingerClient::ensureWorkerCron($workerDomain);return ['summary'=>$r['created']?'تم تركيب عامل التشغيل الدائم على Hostinger والتأكد من جدولة التشغيل التلقائي.':'عامل التشغيل الدائم موجود بالفعل ولم أنشئ نسخة مكررة.','primary_task'=>null];}
    private static function deleteSite(int $projectId,bool $confirmed):array{if(!$projectId)throw new RuntimeException('project_context_missing');if(!$confirmed)throw new RuntimeException('destructive_confirmation_required');$p=ProjectService::get($projectId);HostingerClient::deleteWebsite($projectId,0,true);return ['summary'=>'تم تنفيذ طلب حذف الموقع بعد التحقق من شروط النسخ الاحتياطي، وسيحدّث نظام فهرسة الاستضافة السجل في الفحص التالي.','primary_task'=>null];}
    private static function deleteDatabase(int $projectId,string $databaseRef,bool $confirmed):array{if(!$projectId)throw new RuntimeException('project_context_missing');if(!$confirmed)throw new RuntimeException('destructive_confirmation_required');$name=HostingerClient::resolveProjectDatabase($projectId,$databaseRef);HostingerClient::deleteDatabase($projectId,$name,true);return ['summary'=>'تم تنفيذ حذف قاعدة البيانات '.$name.' بعد التحقق من نسخة احتياطية أو نقطة رجوع صالحة، وسيتم تحديث حالتها في السجل.','primary_task'=>null];}
    private static function friendly(string $s):string{return match(true){str_contains($s,'project_context_missing')=>'لم أجد مشروعًا محددًا في السياق',str_contains($s,'task_context_missing')=>'لم أجد مهمة سابقة مناسبة في السياق',str_contains($s,'backup')=>'لا توجد نسخة احتياطية أو نقطة رجوع كاملة ومتحقق منها تسمح بالإجراء الكبير',str_contains($s,'not_configured')=>'التكامل المطلوب غير مُهيأ حاليًا',str_contains($s,'maintenance_supported_for_wordpress_only')=>'التحكم المباشر في وضع الصيانة عبر Hostinger مدعوم هنا لمواقع ووردبريس فقط',str_contains($s,'agent_definition_incomplete')=>'وصف الوكيل الجديد غير كافٍ لتحديد هويته وتخصصه',str_contains($s,'multiple_databases_need_name')=>'المشروع مرتبط بأكثر من قاعدة بيانات؛ لازم تحدد اسم القاعدة المقصودة قبل الحذف',str_contains($s,'project_database_missing')=>'لم أجد قاعدة بيانات نشطة مرتبطة بالمشروع',str_contains($s,'database_not_found_in_project')=>'قاعدة البيانات المذكورة غير مسجلة ضمن المشروع',str_contains($s,'agent_disabled')=>'الوكيل متوقف حاليًا؛ شغله الأول أو غيّر حالته من صفحة الوكيل',str_contains($s,'agent_tool_disabled')=>'الأداة المطلوبة مقفولة للوكيل من صفحة الصلاحيات والأدوات',str_contains($s,'task_not_ready_for_review')=>'المهمة لسه مش جاهزة لمراجعة عماد؛ لازم أيمن يكمّل التنفيذ ويسلم أدلة الأول',str_contains($s,'staging_not_qa_approved')=>'نسخة الاختبار لم تعتمد من عماد بعد',str_contains($s,'final_domain_missing')=>'حدد دومين العميل النهائي قبل النقل',str_contains($s,'staging_database_isolation_required')=>'المهمة تحتاج قاعدة بيانات اختبار منفصلة قبل لمس قاعدة الإنتاج',str_contains($s,'staging_wordpress_promotion_requires_managed_migration')=>'ووردبريس يحتاج ترحيل ملفات وقاعدة بيانات مُدار قبل التسليم',str_contains($s,'opportunity_currency_unverified')=>'عملة المشروع غير مؤكدة من المصدر؛ راجعها قبل إنشاء العرض',str_contains($s,'ramy_action_not_supported')=>'فهمت الطلب لكن مفيش مسار تنفيذ آمن متاح له حاليًا؛ مش هادّعي إن التنفيذ حصل',default=>AdminUi::humanError($s)};}
}
