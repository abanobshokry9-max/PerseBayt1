<?php
declare(strict_types=1);
/**
 * Company OS 20 unified policy gate.
 * One decision point for capability level, permission sources, project access,
 * risk, owner approval, production, backup, financial budget and count/rate budgets.
 */
final class AgentPolicyEngine {
    private const RISKS=['none'=>0,'low'=>1,'medium'=>2,'high'=>3,'critical'=>4,'destructive'=>5];

    public static function evaluate(int $agentId,string $actionKey,array $context=[]):array{
        $agent=AgentService::assertRunnable($agentId);
        $actionKey=trim($actionKey);
        if($actionKey===''||!preg_match('/^[a-z0-9_.:-]{2,180}$/i',$actionKey))throw new RuntimeException('policy_action_invalid');
        $rule=self::rule($actionKey);
        $capabilities=[];
        $singleCap=trim((string)($context['required_capability']??$rule['required_capability']??''));
        if($singleCap!=='')$capabilities[]=$singleCap;
        foreach((array)($context['required_capabilities']??[]) as $v){$v=trim((string)$v);if($v!=='')$capabilities[]=$v;}
        $capabilities=array_values(array_unique($capabilities));
        $permissions=[];
        $singlePerm=trim((string)($context['required_permission']??$rule['required_permission']??''));
        if($singlePerm!=='')$permissions[]=$singlePerm;
        foreach((array)($context['required_permissions']??[]) as $v){$v=trim((string)$v);if($v!=='')$permissions[]=$v;}
        $permissions=array_values(array_unique($permissions));
        $level=AgentCapabilityService::normalizeLevel((string)($context['required_level']??'use'));
        $projectId=(int)($context['project_id']??0)?:null;
        $taskId=(int)($context['task_id']??0)?:null;
        $requiredAccess=trim((string)($context['required_access']??$rule['required_access']??''));
        $risk=self::risk((string)($context['risk']??$context['risk_level']??$rule['risk_level']??'low'));
        $external=array_key_exists('external',$context)?(bool)$context['external']:(bool)($rule['is_external']??false);
        $production=array_key_exists('production',$context)?(bool)$context['production']:(bool)($rule['touches_production']??false);
        $backupRequired=array_key_exists('backup_required',$context)?(bool)$context['backup_required']:(bool)($rule['backup_required']??false);
        $approvalRequired=array_key_exists('owner_approval_required',$context)?(bool)$context['owner_approval_required']:(bool)($rule['owner_approval_required']??false);
        if(setting('policy.owner_approval_high_risk','1')==='1'&&(self::RISKS[$risk]??3)>=self::RISKS['high'])$approvalRequired=true;
        if($production||!empty($context['money'])||!empty($context['publish'])||!empty($context['secret'])||$risk==='destructive')$approvalRequired=true;
        if($external&&(bool)($rule['external_requires_approval']??false))$approvalRequired=true;
        $actor=Audit::actor();
        $ownerApproved=!empty($context['owner_approved'])||(($actor['type']??'')==='owner');
        $reasons=[];
        foreach($capabilities as $cap){try{AgentCapabilityService::require($agentId,$cap,$level);}catch(Throwable $e){$reasons[]='capability_required:'.$cap.':'.$level;}}
        foreach($permissions as $perm){if(!Permissions::agent($agentId,$perm))$reasons[]='permission_required:'.$perm;}
        if($projectId&&$requiredAccess!==''&&!Permissions::project($agentId,$projectId,$requiredAccess))$reasons[]='project_access_required:'.$requiredAccess;
        if($production)$backupRequired=true;
        if($backupRequired){if(!$projectId)$reasons[]='project_required_for_backup';elseif(!self::verifiedBackup($projectId,(int)($context['backup_max_age_hours']??72)))$reasons[]='verified_backup_required';}
        $budgetCapability=trim((string)($context['budget_capability']??$rule['budget_capability']??''));
        $estimated=max(0.0,(float)($context['estimated_cost_usd']??0));
        if($budgetCapability!==''||$estimated>0){try{AiBudgetService::assertAllowed($agentId,$budgetCapability!==''?$budgetCapability:($external?'external_api':'text'),$estimated);}catch(Throwable $e){$reasons[]=$e->getMessage();}}
        $usageKind=trim((string)($context['usage_kind']??''));
        if($usageKind!==''&&class_exists('AgentUsageBudgetService')){try{AgentUsageBudgetService::authorize($agentId,$usageKind,(int)($context['usage_count']??1));}catch(Throwable $e){$reasons[]=$e->getMessage();}}
        if($external&&setting('policy.external_action_requires_gateway','1')==='1'&&!empty($context['enforce_gateway'])&&empty($context['gateway_managed']))$reasons[]='external_action_gateway_required';
        $state=$reasons?'blocked':($approvalRequired&&!$ownerApproved?'approval_required':'allowed');
        $decisionId=self::record($agentId,$actionKey,$capabilities[0]??($context['capability_key']??null),$state,$risk,$approvalRequired,$ownerApproved,$projectId,$taskId,$context,$reasons,$rule);
        return ['id'=>$decisionId,'state'=>$state,'allowed'=>$state==='allowed','approval_required'=>$state==='approval_required','reasons'=>$reasons,'risk_level'=>$risk,'risk'=>$risk,'owner_approval_required'=>$approvalRequired,'requires_owner_approval'=>$approvalRequired,'owner_approved'=>$ownerApproved,'required_capabilities'=>$capabilities,'required_permissions'=>$permissions,'required_access'=>$requiredAccess?:null,'external'=>$external,'production'=>$production,'backup_required'=>$backupRequired,'requires_backup'=>$backupRequired,'project_id'=>$projectId,'agent'=>$agent['slug']];
    }

    public static function require(int $agentId,string $actionKey,array $context=[]):array{
        $d=self::evaluate($agentId,$actionKey,$context);
        if($d['approval_required'])throw new RuntimeException('policy_owner_approval_required:'.$actionKey);
        if(!$d['allowed'])throw new RuntimeException('policy_blocked:'.implode('|',(array)$d['reasons']));
        return $d;
    }

    /** Capability-oriented compatibility API used by Company OS 14 services. */
    public static function authorizeAction(int $agentId,string $capability,string $level='use',array $context=[]):array{
        $assignment=AgentCapabilityService::require($agentId,$capability,$level);
        $risk=self::risk((string)($context['risk']??$context['risk_level']??$assignment['catalog_risk']??'low'));
        $context['required_capability']=$capability;$context['required_level']=$level;
        if(!array_key_exists('owner_approval_required',$context))$context['owner_approval_required']=((int)($assignment['requires_owner_approval']??0)===1)||((int)($assignment['catalog_requires_owner_approval']??0)===1);
        if(!array_key_exists('backup_required',$context))$context['backup_required']=((int)($assignment['requires_backup']??0)===1)||((int)($assignment['catalog_requires_backup']??0)===1);
        if(!array_key_exists('external',$context))$context['external']=((int)($assignment['external_action']??0)===1);
        if(!array_key_exists('production',$context))$context['production']=((int)($assignment['production_sensitive']??0)===1);
        $context['risk']=$risk;$actionKey=trim((string)($context['action_key']??('capability.'.$capability)));
        $d=self::require($agentId,$actionKey,$context);
        return $d+$assignment+['capability'=>$capability,'level'=>AgentCapabilityService::normalizeLevel($level)];
    }

    public static function check(int $agentId,string $capability,string $level='use',array $context=[]):array{
        try{
            $assignment=AgentCapabilityService::require($agentId,$capability,$level);
            $context['required_capability']=$capability;$context['required_level']=$level;
            if(!array_key_exists('owner_approval_required',$context))$context['owner_approval_required']=((int)($assignment['requires_owner_approval']??0)===1)||((int)($assignment['catalog_requires_owner_approval']??0)===1);
            if(!array_key_exists('backup_required',$context))$context['backup_required']=((int)($assignment['requires_backup']??0)===1)||((int)($assignment['catalog_requires_backup']??0)===1);
            if(!array_key_exists('external',$context))$context['external']=((int)($assignment['external_action']??0)===1);
            if(!array_key_exists('production',$context))$context['production']=((int)($assignment['production_sensitive']??0)===1);
            $actionKey=trim((string)($context['action_key']??('capability.'.$capability)));
            return self::evaluate($agentId,$actionKey,$context)+$assignment+['capability'=>$capability,'level'=>AgentCapabilityService::normalizeLevel($level)];
        }catch(Throwable $e){return ['allowed'=>false,'state'=>'blocked','error'=>$e->getMessage(),'reasons'=>[$e->getMessage()],'agent_id'=>$agentId,'capability'=>$capability];}
    }

    public static function rule(string $actionKey):array{
        try{$q=db()->prepare('SELECT * FROM agent_policy_rules WHERE enabled=1 AND action_pattern=? ORDER BY id DESC LIMIT 1');$q->execute([$actionKey]);if($r=$q->fetch())return $r;
            $rows=db()->query("SELECT * FROM agent_policy_rules WHERE enabled=1 AND action_pattern LIKE '%*' ORDER BY CHAR_LENGTH(action_pattern) DESC,id DESC")->fetchAll();
            foreach($rows as $row){$prefix=rtrim((string)$row['action_pattern'],'*');if($prefix!==''&&str_starts_with($actionKey,$prefix))return $row;}
        }catch(Throwable){}
        return ['action_pattern'=>$actionKey,'risk_level'=>'low','owner_approval_required'=>0,'external_requires_approval'=>0];
    }

    private static function record(int $agentId,string $actionKey,?string $capability,string $state,string $risk,bool $approvalRequired,bool $ownerApproved,?int $projectId,?int $taskId,array $context,array $reasons,array $rule):int{
        $safe=self::sanitize($context);$id=0;
        try{db()->prepare("INSERT INTO agent_policy_decisions(agent_id,capability_key,action_key,project_id,task_id,decision,decision_state,risk_level,reason_code,owner_approval_required,owner_approved,reason_json,context_json,rule_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$agentId,$capability?substr($capability,0,140):null,substr($actionKey,0,180),$projectId,$taskId,$state==='allowed'?'allow':'deny',$state,$risk,$reasons?substr((string)$reasons[0],0,190):null,$approvalRequired?1:0,$ownerApproved?1:0,$reasons?j($reasons):null,$safe?j($safe):null,isset($rule['id'])?(int)$rule['id']:null]);$id=(int)db()->lastInsertId();}catch(Throwable $e){error_log('PerseBayt policy decision: '.Security::redactSecrets($e->getMessage(),180));}
        try{Audit::log('agent',(string)$agentId,'policy.'.$state,'agent_policy_decision',$id?(string)$id:$actionKey,$state==='allowed'?'verified':'blocked',$projectId,$taskId,['action'=>$actionKey,'capability'=>$capability,'risk'=>$risk,'reasons'=>$reasons,'context'=>$safe]);}catch(Throwable){}
        return $id;
    }

    private static function verifiedBackup(int $projectId,int $maxAgeHours=72):bool{$maxAgeHours=max(1,min(720,$maxAgeHours));try{$q=db()->prepare("SELECT COUNT(*) FROM backups WHERE project_id=? AND status='verified' AND COALESCE(verified_at,created_at)>=DATE_SUB(NOW(),INTERVAL ? HOUR)");$q->execute([$projectId,$maxAgeHours]);return (int)$q->fetchColumn()>0;}catch(Throwable){return false;}}
    private static function risk(string $risk):string{$risk=strtolower(trim($risk));return isset(self::RISKS[$risk])?$risk:'medium';}
    private static function sanitize(array $ctx):array{$walk=function(&$v,$k)use(&$walk){if(is_array($v)){foreach($v as $kk=>&$vv){if(preg_match('/password|secret|token|authorization|cookie|api[_-]?key|otp/i',(string)$kk))$vv='[REDACTED]';else $walk($vv,$kk);}unset($vv);}elseif(is_string($v))$v=Security::redactSecrets($v,2000);};$walk($ctx,'');return $ctx;}
}
