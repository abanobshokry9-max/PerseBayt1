<?php
declare(strict_types=1);

/** Read-only integration health index built from configuration presence and last real connection test. */
final class IntegrationHealthService {
    public const PROVIDERS=[
        'openai','openrouter','web_search','hostinger','meta_whatsapp','media_bridge','browser_automation','browser_qa',
        'youtube','tiktok','telegram','meta_social','twilio','generic_bridge','generic_call_bridge','email'
    ];
    private static function lastTests():array{
        try{$rows=db()->query("SELECT c.provider_key,c.state,c.result_code,c.details_json,c.created_at FROM connection_tests c JOIN (SELECT provider_key,MAX(id) id FROM connection_tests GROUP BY provider_key) x ON x.id=c.id")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){$rows=[];}
        $out=[];foreach($rows as $r){$r['source']='connection_test';$out[(string)$r['provider_key']]=$r;}return $out;
    }
    private static function lastRuntimeEvidence():array{
        $map=['openrouter'=>'openrouter','meta'=>'meta_whatsapp','hostinger'=>'hostinger','browser'=>'browser_automation','browser_qa'=>'browser_qa','media'=>'media_bridge','openai_fallback'=>'openai'];
        try{$rows=db()->query("SELECT s.step_key,s.state,s.details_text,s.evidence_json,s.created_at FROM runtime_validation_steps s JOIN (SELECT step_key,MAX(id) id FROM runtime_validation_steps GROUP BY step_key) x ON x.id=s.id")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){$rows=[];}
        $out=[];foreach($rows as $r){$step=(string)($r['step_key']??'');if(!isset($map[$step]))continue;$provider=$map[$step];$e=[];if(!empty($r['evidence_json'])){$d=json_decode((string)$r['evidence_json'],true);if(is_array($d))$e=$d;}$code=(string)($e['result_code']??$e['code']??$e['error']??'');if($code==='')$code=pb_substr((string)($r['details_text']??''),0,180);$out[$provider]=['provider_key'=>$provider,'state'=>(string)($r['state']??''),'result_code'=>$code,'details_json'=>$r['evidence_json']??null,'created_at'=>$r['created_at']??null,'source'=>'runtime_validation'];}
        return $out;
    }
    private static function newer(array $a,array $b):array{
        if(!$a)return $b;if(!$b)return $a;$ta=!empty($a['created_at'])?utc_ts((string)$a['created_at']):false;$tb=!empty($b['created_at'])?utc_ts((string)$b['created_at']):false;if($tb!==false&&($ta===false||$tb>$ta))return $b;return $a;
    }
    private static function configured(string $provider):?bool{
        try{return match($provider){
            'openai'=>strlen(trim((string)SecretVault::get('providers.openai.api_key','')))>20||strlen(trim((string)config('ai.api_key','')))>20,
            'openrouter'=>OpenRouterService::configured(),
            'hostinger'=>trim((string)config('hostinger.api_token',''))!=='',
            'meta_whatsapp'=>MetaClient::configured(),
            'media_bridge'=>MediaBridgeClient::configured(),
            'browser_automation'=>BrowserAutomationService::configured(),
            'browser_qa'=>BrowserQaService::configured(),
            'youtube'=>YouTubeClient::configured(),
            'tiktok'=>TikTokClient::configured(),
            'telegram'=>TelegramClient::configured(),
            'meta_social'=>MetaSocialClient::configured(),
            'twilio'=>TwilioClient::configured(),
            'generic_bridge'=>GenericMessageClient::configured(),
            'generic_call_bridge'=>CallBridgeClient::configured(),
            'email'=>(bool)(EmailConnector::diagnostics()['configured']??false),
            'web_search'=>null,
            default=>null,
        };}catch(Throwable){return false;}
    }
    public static function snapshot():array{
        $tests=self::lastTests();$runtime=self::lastRuntimeEvidence();$rows=[];$verified=0;$failed=0;$configured=0;$untested=0;
        foreach(self::PROVIDERS as $provider){
            $cfg=self::configured($provider);if($cfg===true)$configured++;
            $t=self::newer($tests[$provider]??[],$runtime[$provider]??[]);$rawState=(string)($t['state']??'untested');
            if(($t['source']??'')==='runtime_validation'){$state=match($rawState){'passed'=>'verified','failed'=>'failed','blocked','skipped'=>$cfg===true?'failed':'untested',default=>'untested'};}else{$state=in_array($rawState,['verified','failed','untested'],true)?$rawState:'untested';}
            if($state==='verified')$verified++;elseif($state==='failed')$failed++;else $untested++;
            $age=null;if(!empty($t['created_at'])){$ts=utc_ts((string)$t['created_at']);if($ts!==false)$age=max(0,time()-$ts);}
            $rows[]=[
                'provider'=>$provider,'label'=>AdminUi::provider($provider),'configured'=>$cfg,'state'=>$state,
                'result_code'=>(string)($t['result_code']??''),'last_test_at'=>$t['created_at']??null,'age_seconds'=>$age,'evidence_source'=>(string)($t['source']??'none'),
            ];
        }
        $route=[];try{$q=db()->query("SELECT capability,COUNT(*) routes,SUM(enabled=1) enabled,SUM(health_state='healthy') healthy,SUM(health_state='unhealthy') unhealthy FROM provider_capability_routes GROUP BY capability ORDER BY capability");$route=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){}
        return ['summary'=>['providers'=>count($rows),'configured'=>$configured,'verified'=>$verified,'failed'=>$failed,'untested'=>$untested],'providers'=>$rows,'capability_routes'=>$route,'generated_at'=>now_utc()];
    }
    public static function assertProviderAllowed(string $provider):void{if(!in_array($provider,self::PROVIDERS,true))throw new RuntimeException('integration_provider_not_allowed');}
}
