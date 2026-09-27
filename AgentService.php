<?php
declare(strict_types=1);
final class AgentService {
    public static function bySlug(string $slug): array {$q=db()->prepare('SELECT a.*,m.display_name manager_name FROM agents a LEFT JOIN agents m ON m.id=a.manager_id WHERE a.slug=?');$q->execute([$slug]);$a=$q->fetch();if(!$a)throw new RuntimeException('agent_not_found');return $a;}
    public static function byId(int $id): array {$q=db()->prepare('SELECT * FROM agents WHERE id=?');$q->execute([$id]);$a=$q->fetch();if(!$a)throw new RuntimeException('agent_not_found');return $a;}
    public static function all():array{
        self::reconcileRuntimeStates();
        return db()->query("SELECT a.*,m.display_name manager_name,(SELECT COUNT(*) FROM tasks t WHERE t.assigned_agent_id=a.id AND t.status='completed') completed_tasks,(SELECT COUNT(*) FROM tasks t WHERE t.assigned_agent_id=a.id AND t.status IN ('failed','blocked')) failed_tasks,(SELECT t.status FROM tasks t WHERE t.id=a.current_task_id LIMIT 1) current_task_status,(SELECT t.title FROM tasks t WHERE t.id=a.current_task_id LIMIT 1) current_task_title FROM agents a LEFT JOIN agents m ON m.id=a.manager_id ORDER BY FIELD(a.slug,'ramy','walid','ayman','emad','samir-social','video-director','community-manager','basant','free-model-scout'),a.id")->fetchAll();
    }
    public static function reconcileRuntimeStates():void{
        /*
         * حالة الوكيل هنا تعكس صحته الحالية فقط، لا نتيجة آخر مهمة انتهت.
         * فشل مهمة سابقة يظل ظاهرًا كعطل في المهمة/المسار، لكنه لا يحوّل
         * أيمن أو عماد إلى وكيل أحمر للأبد بعد أن ينتهي التنفيذ.
         */
        $agents=db()->query("SELECT id,status,is_active,current_task_id,last_seen_at,last_error_code FROM agents ORDER BY id")->fetchAll();
        $activeSql="SELECT t.id,t.status,t.updated_at FROM tasks t WHERE t.assigned_agent_id=? AND t.status IN ('working','assigned','queued','waiting','blocked','needs_review','needs_fix','retesting') ORDER BY FIELD(t.status,'working','retesting','needs_fix','needs_review','blocked','assigned','queued','waiting'),t.updated_at DESC,t.id DESC LIMIT 1";
        $active=db()->prepare($activeSql);
        $runningJob=db()->prepare("SELECT COUNT(*) FROM jobs WHERE agent_id=? AND state='running'");
        $update=db()->prepare('UPDATE agents SET last_seen_at=CASE WHEN status<>? OR COALESCE(current_task_id,0)<>COALESCE(?,0) OR last_seen_at IS NULL THEN NOW() ELSE last_seen_at END,status=?,current_task_id=?,last_error_code=CASE WHEN ?=\'error\' THEN last_error_code ELSE NULL END WHERE id=?');
        foreach($agents as $a){
            $id=(int)$a['id'];
            if(!(int)$a['is_active']||(string)$a['status']==='disabled'){
                if($a['current_task_id']!==null)db()->prepare('UPDATE agents SET current_task_id=NULL WHERE id=?')->execute([$id]);
                continue;
            }
            $active->execute([$id]);$task=$active->fetch()?:null;
            $taskId=$task?(int)$task['id']:null;
            $runningJob->execute([$id]);$hasRunning=(int)$runningJob->fetchColumn()>0;
            $state='idle';
            if($hasRunning||($task&&(string)$task['status']==='working'))$state='working';
            elseif($task&&(string)$task['status']==='blocked'&&str_starts_with((string)($a['last_error_code']??''),'agent_recovery_required:'))$state='error';
            $currentTask=$a['current_task_id']===null?null:(int)$a['current_task_id'];
            $needsWrite=(string)$a['status']!==$state||$currentTask!==$taskId||$a['last_seen_at']===null||($state!=='error'&&$a['last_error_code']!==null);
            if($needsWrite)$update->execute([$state,$taskId,$state,$taskId,$state,$id]);
        }
    }
    public static function runtimeSnapshot():array{
        $rows=self::all();$out=[];
        foreach($rows as $a)$out[]=['id'=>(int)$a['id'],'slug'=>(string)$a['slug'],'display_name'=>(string)$a['display_name'],'role_title'=>(string)($a['role_title']??''),'specialty'=>(string)($a['specialty']??''),'status'=>(string)$a['status'],'is_active'=>(int)($a['is_active']??1),'current_task_id'=>$a['current_task_id']!==null?(int)$a['current_task_id']:null,'current_task_status'=>$a['current_task_status']??null,'current_task_title'=>$a['current_task_title']??null,'last_seen_at'=>$a['last_seen_at']??null,'last_error_code'=>$a['last_error_code']??null];
        return $out;
    }
    public static function runnable(array|int|string $agent):bool{$a=is_array($agent)?$agent:(is_int($agent)?self::byId($agent):self::bySlug($agent));return (int)($a['is_active']??0)===1&&(string)($a['status']??'')!=='disabled';}
    public static function assertRunnable(array|int|string $agent):array{$a=is_array($agent)?$agent:(is_int($agent)?self::byId($agent):self::bySlug($agent));if(!self::runnable($a))throw new RuntimeException('agent_disabled');return $a;}
    public static function tool(int $agentId,string $tool):bool{$q=db()->prepare('SELECT allowed FROM agent_tools WHERE agent_id=? AND tool_key=?');$q->execute([$agentId,$tool]);return (int)$q->fetchColumn()===1;}
    public static function requireTool(int $agentId,string $tool):void{if(!self::tool($agentId,$tool))throw new RuntimeException('agent_tool_disabled:'.$tool);}
    public static function setTool(int $agentId,string $tool,bool $allowed):void{
        self::byId($agentId);$tool=trim($tool);if($tool==='')throw new RuntimeException('tool_required');
        if($allowed){
            try{AgentCapabilityService::grantTool($agentId,$tool,'owner_manual','direct');return;}catch(Throwable $e){if(!str_contains($e->getMessage(),'agent_tool_sources')&&!str_contains($e->getMessage(),'doesn'))throw $e;}
            db()->prepare("INSERT INTO agent_tools(agent_id,tool_key,allowed,config_json) VALUES (?,?,1,'{}') ON DUPLICATE KEY UPDATE allowed=1")->execute([$agentId,$tool]);
        }else{
            try{AgentCapabilityService::revokeManualTool($agentId,$tool,'direct');return;}catch(Throwable $e){if(!str_contains($e->getMessage(),'agent_tool_sources')&&!str_contains($e->getMessage(),'doesn'))throw $e;}
            db()->prepare("UPDATE agent_tools SET allowed=0 WHERE agent_id=? AND tool_key=?")->execute([$agentId,$tool]);
        }
        if($allowed){$code='agent_tool_disabled:'.$tool;db()->prepare("UPDATE jobs SET state='queued',available_at=NOW(),error_code=NULL WHERE agent_id=? AND state='waiting' AND error_code=?")->execute([$agentId,$code]);db()->prepare("UPDATE tasks t SET t.status='assigned' WHERE t.assigned_agent_id=? AND t.status='waiting' AND EXISTS (SELECT 1 FROM jobs j WHERE j.task_id=t.id AND j.state='queued')")->execute([$agentId]);}
    }
    public static function create(array $d): int {
        $slug=strtolower(preg_replace('/[^a-z0-9_-]+/','-',trim((string)$d['slug'])));if(!$slug)throw new RuntimeException('invalid_slug');
        $ramyId=(int)self::bySlug('ramy')['id'];$rawManager=array_key_exists('manager_id',$d)?(int)$d['manager_id']:$ramyId;$manager=$rawManager===-1?null:($rawManager>0?$rawManager:$ramyId);if($manager!==null)self::byId($manager);
        $roleTemplate=trim((string)($d['role_template']??'restricted'))?:'restricted';$tpl=AgentCapabilityService::roleTemplate($roleTemplate);if(!$tpl){$roleTemplate='restricted';$tpl=AgentCapabilityService::roleTemplate('restricted');}
        $relationshipPolicy=(string)($tpl['relationship_policy']??'restricted');
        $ownerCommunication=(string)($d['owner_communication']??'through_ramy');if(!in_array($ownerCommunication,['disabled','through_ramy','dashboard_only','whatsapp','dashboard_whatsapp','emergency_only'],true))$ownerCommunication='through_ramy';$whatsapp=isset($d['whatsapp_enabled'])?1:0;
        $defaultProvider=OpenRouterService::configured()?'openrouter':(trim((string)setting('agents.default_ai_provider','openai'))?:'openai');$provider=OpenRouterService::configured()?'openrouter':(trim((string)($d['provider_key']??$defaultProvider))?:$defaultProvider);$pq=db()->prepare("SELECT provider_key FROM providers WHERE provider_key=? AND kind='ai' AND enabled=1");$pq->execute([$provider]);if(!$pq->fetchColumn())$provider='openai';
        $q=db()->prepare("INSERT INTO agents(slug,display_name,role_title,specialty,description,profile_age,profile_identity,profile_work_details,profile_contact_details,manager_id,provider_key,model,system_prompt,owner_communication,whatsapp_enabled,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'idle')");
        $age=(int)($d['profile_age']??0);$age=$age>=0&&$age<=500?$age:null;
        $q->execute([$slug,trim((string)$d['display_name']),trim((string)$d['role_title']),trim((string)$d['specialty']),trim((string)($d['description']??'')),$age,trim((string)($d['profile_identity']??''))?:null,trim((string)($d['profile_work_details']??''))?:null,trim((string)($d['profile_contact_details']??''))?:null,$manager,$provider,trim((string)($d['model']??''))?:null,trim((string)$d['system_prompt']),$ownerCommunication,$whatsapp]);$id=(int)db()->lastInsertId();
        // Universal minimum. Role-specific grants are applied from agent_role_templates below.
        foreach(['agents.view','tasks.create','memory.read','memory.write','team_chat.write'] as $permission){try{AgentCapabilityService::grantPermission($id,$permission,'system','agent_create_core');}catch(Throwable){db()->prepare('INSERT INTO agent_permissions(agent_id,permission_key,allowed) VALUES (?,?,1) ON DUPLICATE KEY UPDATE allowed=1')->execute([$id,$permission]);}}
        foreach(['memory','task_context','team_chat'] as $tool){try{AgentCapabilityService::grantTool($id,$tool,'system','agent_create_core');}catch(Throwable){db()->prepare("INSERT INTO agent_tools(agent_id,tool_key,allowed,config_json) VALUES (?,?,1,'{}') ON DUPLICATE KEY UPDATE allowed=1")->execute([$id,$tool]);}}
        $peers=db()->query("SELECT id,slug FROM agents WHERE id<>".$id." AND is_active=1")->fetchAll();
        foreach($peers as $peerRow){$peer=(int)$peerRow['id'];$peerSlug=(string)$peerRow['slug'];$allow=false;$startAllowed=false;$needManager=1;
            if(in_array($relationshipPolicy,['executive','team'],true)){$allow=true;$startAllowed=true;$needManager=0;}
            elseif($relationshipPolicy==='direct_owner'){$allow=($peer===$ramyId||($manager!==null&&$peer===$manager));$startAllowed=$allow;$needManager=$allow?0:1;}
            else{$allow=($peer===$ramyId||($manager!==null&&$peer===$manager));$startAllowed=false;$needManager=1;}
            db()->prepare("INSERT INTO agent_relationships(from_agent_id,to_agent_id,can_message,can_start,requires_manager_approval) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE can_message=VALUES(can_message),can_start=VALUES(can_start),requires_manager_approval=VALUES(requires_manager_approval)")->execute([$id,$peer,$allow?1:0,$startAllowed?1:0,$needManager]);
            $reverseAllow=in_array($relationshipPolicy,['executive','team'],true)||$peer===$ramyId||($manager!==null&&$peer===$manager);
            db()->prepare("INSERT INTO agent_relationships(from_agent_id,to_agent_id,can_message,can_start,requires_manager_approval) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE can_message=VALUES(can_message),can_start=VALUES(can_start),requires_manager_approval=VALUES(requires_manager_approval)")->execute([$peer,$id,$reverseAllow?1:0,$reverseAllow?1:0,$reverseAllow?0:1]);
        }
        foreach(['dashboard','whatsapp'] as $channel){$send=$channel==='dashboard'?1:(($whatsapp&&in_array($ownerCommunication,['whatsapp','dashboard_whatsapp'],true))?1:0);$receive=$channel==='dashboard'||($channel==='whatsapp'&&$whatsapp)?1:0;$start=$channel==='dashboard'?1:0;$approval=$channel==='dashboard'?0:($slug==='ramy'?0:1);db()->prepare("INSERT INTO agent_channel_permissions(agent_id,channel_key,can_send_owner,can_receive_owner,can_start,requires_ramy_approval,emergency_enabled,rate_limit_per_hour) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE can_send_owner=VALUES(can_send_owner),can_receive_owner=VALUES(can_receive_owner),can_start=VALUES(can_start),requires_ramy_approval=VALUES(requires_ramy_approval),emergency_enabled=VALUES(emergency_enabled),rate_limit_per_hour=VALUES(rate_limit_per_hour),updated_at=NOW()")->execute([$id,$channel,$send,$receive,$start,$approval,$channel==='dashboard'?1:0,$channel==='dashboard'?30:10]);}
        foreach((array)($d['permission_keys']??[]) as $permission){$permission=trim((string)$permission);if($permission==='')continue;if(!preg_match('/^[a-z0-9_.:-]{2,120}$/i',$permission)||!AgentCapabilityService::permissionExists($permission))throw new RuntimeException('permission_not_in_catalog:'.$permission);AgentCapabilityService::grantPermission($id,$permission,'owner_manual','agent_create');}
        foreach((array)($d['tool_keys']??[]) as $tool){$tool=trim((string)$tool);if($tool!==''&&preg_match('/^[a-z0-9_.:-]{2,120}$/i',$tool))AgentCapabilityService::grantTool($id,$tool,'owner_manual','agent_create');}
        try{AgentAutonomyService::save($id,!isset($d['initiative_enabled'])||!empty($d['initiative_enabled']),(int)($d['cadence_minutes']??240),(int)($d['max_active_tasks']??1),trim((string)($d['mission_text']??$d['profile_work_details']??$d['specialty']??'')),(string)($d['initiative_scope']??'owner_team'));}catch(Throwable $e){error_log('ELMETR autonomy create: '.Security::redactSecrets($e->getMessage(),160));}
        try{
            $routeProviders=[$provider];
            $fallbacks=db()->prepare("SELECT provider_key FROM providers WHERE kind='ai' AND enabled=1 AND provider_key<>? ORDER BY FIELD(provider_key,'openrouter','openai','gemini','groq','anthropic','ollama'),provider_key LIMIT 2");$fallbacks->execute([$provider]);
            foreach($fallbacks->fetchAll(PDO::FETCH_COLUMN) as $fk)if(!in_array((string)$fk,$routeProviders,true))$routeProviders[]=(string)$fk;
            foreach(array_slice($routeProviders,0,3) as $idx=>$pk){$route=$idx+1;$model=$idx===0?(trim((string)($d['model']??''))?:null):null;db()->prepare("INSERT INTO agent_provider_routes(agent_id,route_order,provider_key,model,enabled) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE provider_key=VALUES(provider_key),model=VALUES(model),enabled=1,last_state='untested',last_error=NULL")->execute([$id,$route,$pk,$model]);}
            if(OpenRouterService::forcePrimaryEnabled()&&OpenRouterService::configured())OpenRouterService::ensurePrimaryForAgent($id);
        }catch(Throwable){}
        try{AgentCapabilityService::bootstrap($id,(array)($d['capability_keys']??[]),$roleTemplate);}catch(Throwable $e){error_log('ELMETR capability bootstrap: '.Security::redactSecrets($e->getMessage(),180));}
        $managerName=$manager===null?'أبانوب مباشرة':(string)self::byId($manager)['display_name'];MemoryService::rememberAgent($id,'core',(string)$d['system_prompt'],'system','create_agent',100);MemoryService::rememberAgent($id,'relationship','مديرك المباشر هو '.$managerName.'. التزم بسياسة التواصل المحددة في صفحتك ولا تطلب صلاحيات زيادة من نفسك.','system','create_agent',95);Audit::log('owner','1','agent.create','agent',(string)$id,'verified');Notifications::add('success','agents','تم إنشاء وكيل جديد','تم إنشاء الصفحة والذاكرة والصلاحيات والأدوات وسياسات التواصل الأولية للوكيل '.$d['display_name'],'agent',(string)$id);return $id;
    }
    public static function status(int $id,string $status):void{
        if(!in_array($status,['online','working','idle','disabled','error'],true))throw new RuntimeException('invalid_status');
        $before=self::byId($id);db()->prepare("UPDATE agents SET status=?,is_active=?,current_task_id=CASE WHEN ?='disabled' THEN NULL ELSE current_task_id END,last_seen_at=NOW(),last_error_code=CASE WHEN ?='error' THEN last_error_code ELSE NULL END WHERE id=?")->execute([$status,$status==='disabled'?0:1,$status,$status,$id]);
        if($status==='disabled'){
            db()->prepare("UPDATE jobs SET state='waiting',locked_at=NULL,lease_token=NULL,lease_expires_at=NULL,error_code='agent_disabled' WHERE agent_id=? AND state='queued'")->execute([$id]);
            db()->prepare("UPDATE tasks SET status='waiting' WHERE assigned_agent_id=? AND status IN ('queued','assigned')")->execute([$id]);
        }elseif((string)$before['status']==='disabled'||!(int)$before['is_active']){
            db()->prepare("UPDATE jobs SET state='queued',available_at=NOW(),error_code=NULL WHERE agent_id=? AND state='waiting' AND error_code='agent_disabled'")->execute([$id]);
            db()->prepare("UPDATE tasks t SET t.status='assigned' WHERE t.assigned_agent_id=? AND t.status='waiting' AND EXISTS (SELECT 1 FROM jobs j WHERE j.task_id=t.id AND j.state='queued')")->execute([$id]);
            WorkerWakeup::schedule();
        }
    }
    public static function runtimeStatus(int $id,string $status,?int $taskId=null,?string $errorCode=null):void{
        if(!in_array($status,['online','working','idle','error'],true))throw new RuntimeException('invalid_status');
        $a=self::byId($id);if((string)$a['status']==='disabled'||!(int)$a['is_active'])return;
        if(in_array($status,['idle','error'],true))$taskId=null;
        db()->prepare('UPDATE agents SET status=?,current_task_id=?,last_seen_at=NOW(),last_error_code=? WHERE id=?')->execute([$status,$taskId,$status==='error'?pb_substr((string)$errorCode,0,160):null,$id]);
    }
}
