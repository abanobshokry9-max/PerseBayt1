<?php
declare(strict_types=1);
final class AgentUsageBudgetService {
    private static function executeRetry(string $sql,array $params=[]):void{
        try{db()->prepare($sql)->execute($params);return;}catch(Throwable $e){
            if(!Database::isDisconnect($e))throw $e;
            Database::reconnect();
            db()->prepare($sql)->execute($params);
        }
    }
    private static function profile(int $agentId):array{
        $q=db()->prepare('SELECT * FROM agent_autonomy WHERE agent_id=? LIMIT 1');$q->execute([$agentId]);$r=$q->fetch();
        return $r?:['daily_ai_call_budget'=>60,'daily_external_action_budget'=>20,'daily_search_budget'=>40,'daily_media_budget'=>20,'daily_voice_budget'=>40,'daily_browser_budget'=>80];
    }
    public static function snapshot(int $agentId):array{
        AgentService::byId($agentId);$q=db()->prepare('SELECT * FROM agent_usage_daily WHERE agent_id=? AND usage_date=CURDATE() LIMIT 1');$q->execute([$agentId]);$r=$q->fetch()?:['agent_id'=>$agentId,'usage_date'=>gmdate('Y-m-d'),'ai_calls'=>0,'external_actions'=>0,'search_requests'=>0,'media_requests'=>0,'voice_actions'=>0,'browser_actions'=>0,'estimated_cost'=>0];$p=self::profile($agentId);
        foreach(['ai_call'=>'daily_ai_call_budget','external_action'=>'daily_external_action_budget','search'=>'daily_search_budget','media'=>'daily_media_budget','voice'=>'daily_voice_budget','browser'=>'daily_browser_budget'] as $kind=>$field)$r[$kind.'_budget']=(int)($p[$field]??0);
        return $r;
    }
    private static function used(array $s,string $kind):int{return match($kind){'ai_call'=>(int)$s['ai_calls'],'external_action'=>(int)$s['external_actions'],'search'=>(int)$s['search_requests'],'media'=>(int)$s['media_requests'],'voice'=>(int)$s['voice_actions'],'browser'=>(int)$s['browser_actions'],default=>0};}
    public static function authorize(int $agentId,string $kind,int $count=1):void{
        if($agentId<1||$count<1||(setting('agents.usage_count_budget_enabled',setting('agents.count_budget_enabled','1'))==='0'))return;$kind=self::normalize($kind);$s=self::snapshot($agentId);$limit=(int)($s[$kind.'_budget']??0);if($limit>0&&self::used($s,$kind)+$count>$limit)throw new RuntimeException('agent_'.$kind.'_daily_budget_exceeded');
    }
    public static function record(int $agentId,string $kind,int $count=1,float $estimatedCost=0.0,array $meta=[]):void{
        if($agentId<1||$count<1)return;$kind=self::normalize($kind);$vals=['ai_call'=>0,'external_action'=>0,'search'=>0,'media'=>0,'voice'=>0,'browser'=>0];$vals[$kind]=$count;
        self::executeRetry('INSERT INTO agent_usage_daily(agent_id,usage_date,ai_calls,external_actions,search_requests,media_requests,voice_actions,browser_actions,estimated_cost) VALUES (?,CURDATE(),?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ai_calls=ai_calls+VALUES(ai_calls),external_actions=external_actions+VALUES(external_actions),search_requests=search_requests+VALUES(search_requests),media_requests=media_requests+VALUES(media_requests),voice_actions=voice_actions+VALUES(voice_actions),browser_actions=browser_actions+VALUES(browser_actions),estimated_cost=estimated_cost+VALUES(estimated_cost),updated_at=NOW()',[$agentId,$vals['ai_call'],$vals['external_action'],$vals['search'],$vals['media'],$vals['voice'],$vals['browser'],$estimatedCost]);
        self::executeRetry('INSERT INTO agent_usage_events(agent_id,usage_type,usage_count,estimated_cost,metadata_json) VALUES (?,?,?,?,?)',[$agentId,$kind,$count,$estimatedCost,$meta?j($meta):null]);
    }
    public static function before(int $agentId,string $kind,int $count=1):void{self::authorize($agentId,$kind,$count);} public static function after(int $agentId,string $kind,int $count=1,float $cost=0,array $meta=[]):void{self::record($agentId,$kind,$count,$cost,$meta);}
    public static function beforeAi(int $agentId,array $meta=[]):void{self::before($agentId,'ai_call');} public static function afterAi(int $agentId,array $meta=[],float $cost=0):void{self::after($agentId,'ai_call',1,$cost,$meta);}
    public static function beforeExternal(int $agentId,string $kind='external_action',array $meta=[]):void{self::before($agentId,self::normalize($kind));} public static function afterExternal(int $agentId,string $kind='external_action',array $meta=[],float $cost=0):void{self::after($agentId,self::normalize($kind),1,$cost,$meta);}
    private static function normalize(string $kind):string{$kind=strtolower(trim($kind));return match($kind){'ai','ai_call'=>'ai_call','search','web_search'=>'search','media','media_generation','image','video'=>'media','voice','tts','stt','realtime_voice'=>'voice','browser','browser_action'=>'browser',default=>'external_action'};}
}
