<?php
declare(strict_types=1);
/**
 * Company OS 20 unified workflow runtime.
 * Normalized step source-of-truth with compatibility steps_json, workflow-level risk,
 * owner approval and per-step policy/evidence. Supports waiting child tasks and resume.
 */
final class AgentWorkflowService {
    private const ACTIONS=[
        'task.create','resource.create','team.message','owner.notify','followup.schedule','policy.check',
        'web.search','external.propose','media.request','security.run',
        'creator.intelligence','agency.events.due','review.ready_retests','review.opportunity_learning',
        'owner.brief','initiative.execute'
    ];

    public static function createTemplate(array $d,int $createdByAgentId=0):int{
        if($createdByAgentId>0){
            Permissions::requireAgent($createdByAgentId,'workflows.manage');
            AgentPolicyEngine::require($createdByAgentId,'workflow.template.manage',[
                'required_capability'=>'workflow_builder','required_permission'=>'workflows.manage','risk'=>'medium'
            ]);
        }
        $key=self::key((string)($d['workflow_key']??$d['key']??''));
        $name=trim((string)($d['name']??$d['label']??$key));
        if($key===''||$name==='')throw new RuntimeException('workflow_template_invalid');
        $desc=trim((string)($d['description']??$d['description_text']??''));
        $steps=self::normalizeSteps($d['steps']??$d['steps_json']??[]);
        if(!$steps)throw new RuntimeException('workflow_steps_required');
        $risk=self::risk((string)($d['risk_level']??'low'));
        $requires=!empty($d['requires_owner_approval']);
        $enabled=!array_key_exists('enabled',$d)||!empty($d['enabled']);
        $state=$enabled?'active':'disabled';
        $config=(array)($d['config']??[]);
        db()->prepare("INSERT INTO agent_workflow_templates(workflow_key,name,description,description_text,steps_json,risk_level,requires_owner_approval,enabled,version_no,state,created_by_agent_id,created_by_user_id,config_json)
            VALUES (?,?,?,?,?,?,?,?,1,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),description_text=VALUES(description_text),steps_json=VALUES(steps_json),risk_level=VALUES(risk_level),requires_owner_approval=VALUES(requires_owner_approval),enabled=VALUES(enabled),version_no=version_no+1,state=VALUES(state),created_by_agent_id=VALUES(created_by_agent_id),created_by_user_id=VALUES(created_by_user_id),config_json=VALUES(config_json),updated_at=NOW()")
            ->execute([$key,pb_substr($name,0,220),pb_substr($desc,0,8000),pb_substr($desc,0,8000),j($steps),$risk,$requires?1:0,$enabled?1:0,$state,$createdByAgentId?:null,$createdByAgentId?null:(int)(Auth::user()['id']??1),$config?j($config):'{}']);
        $q=db()->prepare('SELECT id FROM agent_workflow_templates WHERE workflow_key=?');$q->execute([$key]);$id=(int)$q->fetchColumn();
        // Preserve workflow history: disable prior normalized steps instead of deleting them.
        db()->prepare('UPDATE agent_workflow_steps SET enabled=0 WHERE workflow_id=?')->execute([$id]);
        foreach($steps as $i=>$s){
            db()->prepare('INSERT INTO agent_workflow_steps(workflow_id,step_no,label_ar,action_key,config_json,blocking,on_failure,risk_level,requires_owner_approval,enabled) VALUES (?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE label_ar=VALUES(label_ar),action_key=VALUES(action_key),config_json=VALUES(config_json),blocking=VALUES(blocking),on_failure=VALUES(on_failure),risk_level=VALUES(risk_level),requires_owner_approval=VALUES(requires_owner_approval),enabled=1')
                ->execute([$id,$i+1,pb_substr((string)($s['label']??$s['action']),0,220),(string)$s['action'],j((array)($s['params']??[])),!empty($s['blocking'])?1:0,self::onFailure((string)($s['on_failure']??'stop')),self::risk((string)($s['risk_level']??'low')),!empty($s['requires_owner_approval'])?1:0]);
        }
        Audit::log($createdByAgentId?'agent':'owner',$createdByAgentId?(string)$createdByAgentId:(string)(Auth::user()['id']??1),'workflow.template_save','agent_workflow_template',(string)$id,'verified',null,null,['workflow_key'=>$key,'steps'=>count($steps),'risk'=>$risk]);
        return $id;
    }
    public static function saveTemplate(array $d,int $ownerId):int{return self::createTemplate($d,0);}

    public static function assign(int $agentId,int $workflowId,bool $enabled=true,array $config=[]):void{
        AgentService::byId($agentId);self::template($workflowId);
        db()->prepare('INSERT INTO agent_workflow_assignments(agent_id,workflow_id,enabled,config_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),config_json=VALUES(config_json),updated_at=NOW()')->execute([$agentId,$workflowId,$enabled?1:0,$config?j($config):'{}']);
    }
    public static function templates(bool $enabledOnly=true):array{
        $where=$enabledOnly?" WHERE COALESCE(enabled,1)=1 AND COALESCE(state,'active')='active'":'';
        return db()->query('SELECT * FROM agent_workflow_templates'.$where.' ORDER BY name,id')->fetchAll();
    }
    public static function assigned(int $agentId):array{
        $q=db()->prepare("SELECT t.*,a.enabled assignment_enabled,a.config_json assignment_config_json FROM agent_workflow_assignments a JOIN agent_workflow_templates t ON t.id=a.workflow_id WHERE a.agent_id=? AND a.enabled=1 AND COALESCE(t.enabled,1)=1 AND COALESCE(t.state,'active')='active' ORDER BY t.name");$q->execute([$agentId]);return $q->fetchAll();
    }
    public static function templatesForAgent(int $agentId):array{
        $q=db()->prepare("SELECT w.*,COALESCE(a.enabled,0) assigned FROM agent_workflow_templates w LEFT JOIN agent_workflow_assignments a ON a.workflow_id=w.id AND a.agent_id=? ORDER BY COALESCE(w.state,'active')='active' DESC,w.name");$q->execute([$agentId]);return $q->fetchAll();
    }
    public static function template(int $id):array{$q=db()->prepare('SELECT * FROM agent_workflow_templates WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('workflow_not_found');return $r;}
    public static function steps(int $id):array{$q=db()->prepare('SELECT * FROM agent_workflow_steps WHERE workflow_id=? AND COALESCE(enabled,1)=1 ORDER BY step_no');$q->execute([$id]);$rows=$q->fetchAll();if($rows)return $rows;$w=self::template($id);$steps=self::normalizeSteps($w['steps_json']??[]);$out=[];foreach($steps as $i=>$s)$out[]=['step_no'=>$i+1,'label_ar'=>$s['label']??$s['action'],'action_key'=>$s['action'],'config_json'=>j($s['params']??[]),'blocking'=>!empty($s['blocking'])?1:0,'on_failure'=>self::onFailure((string)($s['on_failure']??'stop')),'risk_level'=>self::risk((string)($s['risk_level']??'low')),'requires_owner_approval'=>!empty($s['requires_owner_approval'])?1:0];return $out;}

    public static function execute(int $agentId,string $key,array $ctx=[]):array{
        $q=db()->prepare("SELECT t.id FROM agent_workflow_templates t JOIN agent_workflow_assignments a ON a.workflow_id=t.id WHERE a.agent_id=? AND a.enabled=1 AND COALESCE(t.enabled,1)=1 AND COALESCE(t.state,'active')='active' AND t.workflow_key=? LIMIT 1");$q->execute([$agentId,$key]);$id=(int)($q->fetchColumn()?:0);if(!$id)throw new RuntimeException('agent_workflow_not_assigned');$r=self::start($agentId,$id,$ctx,(int)($ctx['project_id']??0)?:null,(int)($ctx['task_id']??0)?:null);return ['run_id'=>(int)$r['id'],'state'=>$r['state'],'run'=>$r];
    }
    public static function start(int $agentId,int $workflowId,array $context=[],?int $projectId=null,?int $taskId=null):array{
        AgentService::assertRunnable($agentId);$w=self::template($workflowId);
        $q=db()->prepare('SELECT COUNT(*) FROM agent_workflow_assignments WHERE agent_id=? AND workflow_id=? AND enabled=1');$q->execute([$agentId,$workflowId]);if((int)$q->fetchColumn()<1)throw new RuntimeException('workflow_not_assigned');
        $risk=self::risk((string)($w['risk_level']??'low'));$ownerRequired=(int)($w['requires_owner_approval']??0)===1;
        $policy=AgentPolicyEngine::require($agentId,'workflow.run',['project_id'=>$projectId,'task_id'=>$taskId,'required_capability'=>'workflow_runtime','required_permission'=>'workflows.execute','required_access'=>$projectId?'read':'','risk'=>$risk,'owner_approval_required'=>$ownerRequired,'owner_approved'=>!empty($context['owner_approved'])]);
        db()->prepare("INSERT INTO agent_workflow_runs(workflow_id,agent_id,project_id,source_task_id,state,current_step,context_json,policy_decision_id,started_at,updated_at) VALUES (?,?,?,?,'running',0,?,?,NOW(),NOW())")->execute([$workflowId,$agentId,$projectId,$taskId,j($context),(int)($policy['id']??0)?:null]);
        return self::advance((int)db()->lastInsertId());
    }
    public static function advance(int $runId):array{
        $run=self::run($runId);if(!in_array((string)$run['state'],['running','waiting'],true))return $run;
        $steps=self::steps((int)$run['workflow_id']);$ctx=json_decode((string)($run['context_json']??'{}'),true);if(!is_array($ctx))$ctx=[];
        $waitingTask=(int)($run['waiting_task_id']??0);if($waitingTask){$t=TaskService::get($waitingTask);if(!in_array((string)$t['status'],['completed','failed','cancelled'],true))return $run+['waiting_on_task'=>$waitingTask];if((string)$t['status']==='failed')return self::fail($runId,'blocking_task_failed');db()->prepare("UPDATE agent_workflow_runs SET waiting_task_id=NULL,state='running',updated_at=NOW() WHERE id=?")->execute([$runId]);$run=self::run($runId);}
        $current=(int)$run['current_step'];
        foreach($steps as $s){$no=(int)$s['step_no'];if($no<=$current)continue;$cfg=json_decode((string)($s['config_json']??'{}'),true);if(!is_array($cfg))$cfg=[];$cfg=self::interpolate($cfg,$ctx);$stepId=self::stepStart($runId,$no,(string)$s['action_key']);
            try{
                $stepRisk=self::risk((string)($s['risk_level']??'low'));$stepApproval=(int)($s['requires_owner_approval']??0)===1;
                AgentPolicyEngine::require((int)$run['agent_id'],'workflow.step.'.(string)$s['action_key'],['project_id'=>$run['project_id']?(int)$run['project_id']:null,'task_id'=>$run['source_task_id']?(int)$run['source_task_id']:null,'risk'=>$stepRisk,'owner_approval_required'=>$stepApproval,'owner_approved'=>!empty($ctx['owner_approved'])]);
                $result=self::executeStep($run,$s,$cfg,$ctx);self::stepFinish($stepId,'completed',$result);$ctx['steps'][(string)$no]=$result;db()->prepare('UPDATE agent_workflow_runs SET current_step=?,context_json=?,updated_at=NOW() WHERE id=?')->execute([$no,j($ctx),$runId]);
                if(!empty($s['blocking'])&&isset($result['task_id'])){db()->prepare("UPDATE agent_workflow_runs SET state='waiting',waiting_task_id=?,updated_at=NOW() WHERE id=?")->execute([(int)$result['task_id'],$runId]);return self::run($runId)+['result'=>$result];}
            }catch(Throwable $e){$safe=Security::redactSecrets($e->getMessage(),600);self::stepFinish($stepId,'failed',['error'=>$safe]);$mode=self::onFailure((string)($s['on_failure']??'stop'));if($mode==='continue'){$ctx['steps'][(string)$no]=['error'=>$safe];continue;}if($mode==='owner')Notifications::add('warning','workflows','Workflow needs owner review',$safe,'workflow_run',(string)$runId);return self::fail($runId,$safe);}
        }
        db()->prepare("UPDATE agent_workflow_runs SET state='completed',completed_at=NOW(),finished_at=NOW(),current_step=?,result_json=?,updated_at=NOW() WHERE id=?")->execute([count($steps),j($ctx['steps']??[]),$runId]);return self::run($runId);
    }
    public static function tick(int $limit=20):array{$limit=max(1,min(100,$limit));$rows=db()->query("SELECT id FROM agent_workflow_runs WHERE state IN ('running','waiting') ORDER BY updated_at,id LIMIT ".$limit)->fetchAll(PDO::FETCH_COLUMN);$out=[];foreach($rows as $id){try{$r=self::advance((int)$id);$out[]=['id'=>(int)$id,'state'=>$r['state']];}catch(Throwable $e){$out[]=['id'=>(int)$id,'state'=>'failed','error'=>Security::redactSecrets($e->getMessage(),180)];}}return $out;}
    public static function run(int $id):array{$q=db()->prepare('SELECT r.*,w.name workflow_name,a.display_name agent_name FROM agent_workflow_runs r JOIN agent_workflow_templates w ON w.id=r.workflow_id JOIN agents a ON a.id=r.agent_id WHERE r.id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('workflow_run_not_found');return $r;}

    private static function executeStep(array $run,array $s,array $cfg,array $ctx):array{
        $aid=(int)$run['agent_id'];$project=$run['project_id']?(int)$run['project_id']:null;$sourceTask=$run['source_task_id']?(int)$run['source_task_id']:null;$action=(string)$s['action_key'];
        return match($action){
            'task.create'=>['task_id'=>TaskService::create((string)($cfg['agent_slug']??AgentService::byId($aid)['slug']),(string)($cfg['title']??'Workflow task'),(string)($cfg['description']??''),$project,(array)($cfg['context']??[]),(string)($cfg['priority']??'normal'),false,$sourceTask,'agent',(string)$aid)],
            'resource.create'=>AgentResourceService::request($aid,(string)($cfg['resource_type']??''),(array)($cfg['payload']??$cfg),$project,$sourceTask),
            'team.message'=>self::team($aid,$project,$sourceTask,$cfg),
            'owner.notify'=>self::owner($aid,$cfg),
            'followup.schedule'=>self::follow($aid,$project,$sourceTask,$cfg),
            'policy.check'=>AgentPolicyEngine::evaluate($aid,(string)($cfg['action_key']??'workflow.policy_check'),$cfg+['project_id'=>$project,'task_id'=>$sourceTask]),
            'web.search'=>ProviderCapabilityRouter::webSearch($aid,(string)($cfg['query']??''),(int)($cfg['limit']??8)),
            'external.propose'=>['action_id'=>ExternalActionGateway::propose($aid,(int)($cfg['contact_id']??0)?:null,(string)($cfg['channel']??'dashboard'),(string)($cfg['action_key']??'workflow.external'),(array)($cfg['payload']??$cfg),(string)($cfg['reason']??'Workflow external action'),!array_key_exists('requires_owner_approval',$cfg)||!empty($cfg['requires_owner_approval']),$sourceTask,(int)($cfg['initiative_id']??0)?:null)],
            'media.request'=>['task_id'=>TaskService::create('video-director',(string)($cfg['title']??'Media request'),(string)($cfg['description']??''),$project,['workflow_run'=>(int)$run['id']]+(array)($cfg['context']??[]),'normal',false,$sourceTask,'agent',(string)$aid)],
            'security.run'=>self::security($aid,$cfg),
            'creator.intelligence'=>self::creatorIntelligence($cfg),
            'agency.events.due'=>AgencyEventService::dispatchDue((int)($cfg['limit']??20)),
            'review.ready_retests'=>self::readyRetests((int)($cfg['limit']??20)),
            'review.opportunity_learning'=>self::learningReview($cfg),
            'owner.brief'=>CoreAgentInitiativeService::execute(AgentService::byId($aid),'prepare_owner_brief',$cfg),
            'initiative.execute'=>AgentInitiativeService::execute((int)($cfg['initiative_id']??0),!empty($ctx['owner_approved'])),
            default=>throw new RuntimeException('workflow_action_invalid:'.$action)
        };
    }
    private static function security(int $aid,array $cfg):array{$target=(int)($cfg['target_id']??0);if($target<1)throw new RuntimeException('security_target_required');return SecurityLabService::startRun($target,(string)($cfg['run_type']??'full_safe_review'),'agent',(string)$aid);}
    private static function creatorIntelligence(array $cfg):array{if(!empty($cfg['creator_id']))return CreatorIntelligenceService::analyze((string)$cfg['creator_id'],(int)($cfg['months']??4),true);return CreatorIntelligenceService::refreshAll((int)($cfg['limit']??1200));}
    private static function readyRetests(int $limit):array{$limit=max(1,min(100,$limit));$ids=db()->query("SELECT id FROM security_findings WHERE status='ready_retest' ORDER BY id LIMIT ".$limit)->fetchAll(PDO::FETCH_COLUMN);$out=[];foreach($ids as $id){try{$out[]=['finding_id'=>(int)$id,'retest'=>SecurityLabService::startRetest((int)$id)];}catch(Throwable $e){$out[]=['finding_id'=>(int)$id,'error'=>Security::redactSecrets($e->getMessage(),180)];}}return ['processed'=>count($out),'items'=>$out];}
    private static function learningReview(array $cfg):array{try{WalidLearningService::sync();}catch(Throwable){}return ['profile'=>WalidLearningService::profile(),'queries'=>WalidLearningService::learnedQueries((int)($cfg['limit']??6))];}
    private static function team(int $aid,?int $project,?int $task,array $cfg):array{$a=AgentService::byId($aid);$to=(string)($cfg['to_slug']??'team');$text=(string)($cfg['text']??'');if($to==='team'){TeamChatService::post('agent',(string)$a['slug'],'team',$text,'note',$project,$task);return ['sent'=>true];}$r=TeamChatService::dispatchAgentCommand((string)$a['slug'],$to,$text,$task,$project);return ['sent'=>true,'dispatch'=>$r];}
    private static function owner(int $aid,array $cfg):array{$a=AgentService::byId($aid);$r=CommunicationGateway::agentToOwner((string)$a['slug'],(string)($cfg['text']??''),'dashboard');return ['sent'=>true,'session_id'=>$r['session_id']??null];}
    private static function follow(int $aid,?int $project,?int $task,array $cfg):array{$due=(string)($cfg['due_at']??'');if($due===''&&!empty($cfg['after_hours']))$due=gmdate('Y-m-d H:i:s',time()+((int)$cfg['after_hours']*3600));if($due==='')$due=gmdate('Y-m-d H:i:s',time()+3600);$id=AgentFollowupService::schedule($aid,(string)($cfg['purpose']??'Workflow follow-up'),$due,(string)($cfg['message']??''),$project,$task,(int)($cfg['contact_id']??0)?:null,(string)($cfg['channel']??'dashboard'));return ['followup_id'=>$id];}
    private static function stepStart(int $runId,int $no,string $action):int{db()->prepare("INSERT INTO agent_workflow_run_steps(run_id,step_no,action_key,state,started_at) VALUES (?,?,?,'running',NOW()) ON DUPLICATE KEY UPDATE state='running',started_at=NOW(),completed_at=NULL,finished_at=NULL,error_text=NULL,error_code=NULL")->execute([$runId,$no,$action]);$q=db()->prepare('SELECT id FROM agent_workflow_run_steps WHERE run_id=? AND step_no=?');$q->execute([$runId,$no]);return (int)$q->fetchColumn();}
    private static function stepFinish(int $id,string $state,array $result):void{$err=$state==='failed'?pb_substr((string)($result['error']??''),0,1000):null;db()->prepare('UPDATE agent_workflow_run_steps SET state=?,result_json=?,error_text=?,error_code=?,completed_at=NOW(),finished_at=NOW() WHERE id=?')->execute([$state,j($result),$err,$err?pb_substr($err,0,220):null,$id]);}
    private static function fail(int $runId,string $error):array{$safe=pb_substr(Security::redactSecrets($error),0,1000);db()->prepare("UPDATE agent_workflow_runs SET state='failed',error_text=?,error_code=?,completed_at=NOW(),finished_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$safe,pb_substr($safe,0,220),$runId]);return self::run($runId);}
    private static function normalizeSteps(mixed $raw):array{$steps=is_array($raw)?$raw:(json_decode((string)$raw,true)?:[]);$out=[];foreach(array_slice($steps,0,100) as $s){if(!is_array($s))continue;$action=(string)($s['action']??$s['action_key']??'');$map=['notify_owner'=>'owner.notify','create_task'=>'task.create','web_search'=>'web.search','resource_request'=>'resource.create','external_propose'=>'external.propose','request_media'=>'media.request','security_run'=>'security.run','set_followup'=>'followup.schedule'];$action=$map[$action]??$action;if(!in_array($action,self::ACTIONS,true))throw new RuntimeException('workflow_action_unsupported:'.$action);$out[]=['action'=>$action,'label'=>(string)($s['label']??$action),'params'=>(array)($s['params']??$s['config']??[]),'blocking'=>!empty($s['blocking']),'on_failure'=>self::onFailure((string)($s['on_failure']??'stop')),'risk_level'=>self::risk((string)($s['risk_level']??'low')),'requires_owner_approval'=>!empty($s['requires_owner_approval'])];}return $out;}
    private static function interpolate(array $v,array $ctx):array{$walk=function($x)use(&$walk,$ctx){if(is_array($x)){foreach($x as $k=>$y)$x[$k]=$walk($y);return $x;}if(!is_string($x))return $x;return preg_replace_callback('/\{\{([a-z0-9_.-]+)\}\}/i',function($m)use($ctx){$cur=$ctx;foreach(explode('.',$m[1]) as $p){if(!is_array($cur)||!array_key_exists($p,$cur))return ''; $cur=$cur[$p];}return is_scalar($cur)?(string)$cur:'';},$x);};return $walk($v);}
    private static function key(string $v):string{$v=strtolower(trim($v));return substr((string)preg_replace('/[^a-z0-9_.-]+/','-',$v),0,120);}
    private static function risk(string $v):string{$v=strtolower(trim($v));return in_array($v,['none','low','medium','high','critical','destructive'],true)?$v:'medium';}
    private static function onFailure(string $v):string{return in_array($v,['stop','continue','owner'],true)?$v:'stop';}
}
