<?php
declare(strict_types=1);
final class AgentBuilderService {
    public static function roleTemplates():array{return db()->query("SELECT * FROM agent_role_templates WHERE enabled=1 ORDER BY label_ar,template_key")->fetchAll();}
    public static function capabilityCatalog():array{return db()->query("SELECT * FROM agent_capability_catalog WHERE enabled=1 ORDER BY label_ar,capability_key")->fetchAll();}
    public static function permissionCatalog():array{return db()->query("SELECT * FROM permissions ORDER BY category,permission_key")->fetchAll();}
    public static function toolsCatalog():array{try{return db()->query("SELECT DISTINCT tool_key FROM agent_tools ORDER BY tool_key")->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable){return [];}}
    public static function create(array $d):array{
        Auth::requireOwner();$caps=(array)($d['capabilities']??[]);$manualPerms=self::csv((string)($d['permission_keys_csv']??''));$manualTools=self::csv((string)($d['tool_keys_csv']??''));$workflows=array_values(array_unique(array_map('intval',(array)($d['workflow_ids']??[]))));
        $payload=[
            'slug'=>(string)($d['slug']??''),'display_name'=>(string)($d['display_name']??''),'role_title'=>(string)($d['role_title']??''),'specialty'=>(string)($d['specialty']??''),'description'=>(string)($d['description']??''),'manager_id'=>(int)($d['manager_id']??0),'provider_key'=>(string)($d['provider_key']??'openrouter'),'model'=>(string)($d['model']??''),'system_prompt'=>(string)($d['system_prompt']??''),'owner_communication'=>(string)($d['owner_communication']??'through_ramy'),'whatsapp_enabled'=>!empty($d['whatsapp_enabled'])?1:0,'role_template'=>(string)($d['role_template']??'restricted'),'permission_keys'=>$manualPerms,'tool_keys'=>$manualTools,'capability_keys'=>[],
            'initiative_enabled'=>!empty($d['initiative_enabled']),'cadence_minutes'=>(int)($d['cadence_minutes']??240),'max_active_tasks'=>(int)($d['max_active_tasks']??1),'mission_text'=>(string)($d['mission_text']??''),'initiative_scope'=>(string)($d['initiative_scope']??'owner_team')
        ];
        if(trim($payload['display_name'])===''||trim($payload['role_title'])===''||trim($payload['specialty'])===''||trim($payload['system_prompt'])==='')throw new RuntimeException('agent_builder_required_fields_missing');
        $id=AgentService::create($payload);
        foreach($caps as $key=>$level){$key=trim((string)$key);$level=AgentCapabilityService::normalizeLevel((string)$level);if($key===''||$level==='none')continue;AgentCapabilityService::setWithLevel($id,$key,$level,true,null,null,[]);}
        self::saveBudget($id,$d);self::saveAutonomy($id,$d);self::saveChannels($id,$d);self::saveRoutes($id,$d);foreach($workflows as $wid)if($wid>0)AgentWorkflowService::assign($id,$wid,true);self::assignGoals($id,$d);
        try{db()->prepare("INSERT INTO agent_builder_profiles(agent_id,role_template,memory_mode,semantic_memory_enabled,config_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE role_template=VALUES(role_template),memory_mode=VALUES(memory_mode),semantic_memory_enabled=VALUES(semantic_memory_enabled),config_json=VALUES(config_json),updated_at=NOW()")
            ->execute([$id,(string)$payload['role_template'],(string)($d['memory_mode']??'layered'),!empty($d['semantic_memory_enabled'])?1:0,j(['created_via'=>'agent_builder_v17','communication'=>(array)($d['channels']??[]),'workflows'=>$workflows])]);}catch(Throwable){}
        return ['agent_id'=>$id,'agent'=>AgentService::byId($id)];
    }
    private static function saveBudget(int $id,array $d):void{
        $daily=max(0,(float)($d['daily_ai_usd']??0));$monthly=max(0,(float)($d['monthly_ai_usd']??0));$search=max(0,(float)($d['daily_search_usd']??0));$media=max(0,(float)($d['monthly_media_usd']??0));$warn=max(10,min(100,(int)($d['warn_percent']??80)));$hard=!empty($d['hard_stop'])?1:0;
        db()->prepare("INSERT INTO agent_budget_policies(agent_id,daily_ai_usd,monthly_ai_usd,daily_search_usd,monthly_media_usd,warn_percent,hard_stop) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE daily_ai_usd=VALUES(daily_ai_usd),monthly_ai_usd=VALUES(monthly_ai_usd),daily_search_usd=VALUES(daily_search_usd),monthly_media_usd=VALUES(monthly_media_usd),warn_percent=VALUES(warn_percent),hard_stop=VALUES(hard_stop)")->execute([$id,$daily?:null,$monthly?:null,$search?:null,$media?:null,$warn,$hard]);
    }

    private static function saveAutonomy(int $id,array $d):void{
        try{AgentAutonomyService::saveBrainPolicy($id,[
            'brain_enabled'=>!array_key_exists('brain_enabled',$d)||!empty($d['brain_enabled']),
            'max_initiatives_per_day'=>(int)($d['max_initiatives_per_day']??6),
            'initiative_min_value'=>(int)($d['initiative_min_value']??60),
            'auto_execute_max_risk'=>(string)($d['auto_execute_max_risk']??'low'),
            'followup_enabled'=>!array_key_exists('followup_enabled',$d)||!empty($d['followup_enabled']),
            'learning_enabled'=>!array_key_exists('learning_enabled',$d)||!empty($d['learning_enabled']),
            'self_improvement_enabled'=>!array_key_exists('self_improvement_enabled',$d)||!empty($d['self_improvement_enabled']),
            'daily_ai_call_budget'=>(int)($d['daily_ai_call_budget']??60),
            'daily_external_action_budget'=>(int)($d['daily_external_action_budget']??20),
            'daily_search_budget'=>(int)($d['daily_search_budget']??40),
            'daily_media_budget'=>(int)($d['daily_media_budget']??20),
            'daily_voice_budget'=>(int)($d['daily_voice_budget']??40),
            'daily_browser_budget'=>(int)($d['daily_browser_budget']??80),
        ]);}catch(Throwable $e){error_log('PerseBayt Agent Builder autonomy: '.Security::redactSecrets($e->getMessage(),180));}
    }
    private static function saveChannels(int $id,array $d):void{
        $channels=(array)($d['channels']??[]);foreach(['dashboard','whatsapp','voice'] as $ch){$on=!empty($channels[$ch]);$send=$on?1:0;$receive=$on?1:0;$start=$on?1:0;$approval=$ch==='dashboard'?0:1;db()->prepare("INSERT INTO agent_channel_permissions(agent_id,channel_key,can_send_owner,can_receive_owner,can_start,requires_ramy_approval,emergency_enabled,rate_limit_per_hour) VALUES (?,?,?,?,?,?,0,?) ON DUPLICATE KEY UPDATE can_send_owner=VALUES(can_send_owner),can_receive_owner=VALUES(can_receive_owner),can_start=VALUES(can_start),requires_ramy_approval=VALUES(requires_ramy_approval),updated_at=NOW()")
            ->execute([$id,$ch,$send,$receive,$start,$approval,$ch==='dashboard'?60:10]);}
    }
    private static function saveRoutes(int $id,array $d):void{
        $provider=trim((string)($d['provider_key']??'openrouter'))?:'openrouter';$model=trim((string)($d['model']??''))?:null;foreach(['text','coding'] as $cap){try{ProviderCapabilityRouter::setAgentPreference($id,$cap,$provider,$model,1,true);if($provider!=='openai')ProviderCapabilityRouter::setAgentPreference($id,$cap,'openai',null,2,true);}catch(Throwable){}}
    }
    private static function assignGoals(int $id,array $d):void{foreach((array)($d['goal_ids']??[]) as $gid){$gid=(int)$gid;if($gid>0)try{CompanyGoalService::assign($gid,$id,'Assigned by Agent Builder',1.0);}catch(Throwable){}}}
    private static function csv(string $v):array{return array_values(array_unique(array_filter(array_map('trim',preg_split('/[,\r\n]+/',$v)?:[]))));}
}
