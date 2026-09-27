<?php
declare(strict_types=1);

/** Company OS 20.1 post-install acceptance: deterministic internal checks plus existing synthetic runtime acceptance. */
final class PostInstallAcceptanceService {
    private static function item(array &$items,string $key,string $label,bool $ok,string $severity='fail',array $details=[]):void{
        $items[]=['key'=>$key,'label'=>$label,'state'=>$ok?'passed':($severity==='warning'?'warning':'failed'),'details'=>$details];
    }
    private static function scalar(string $sql,array $params=[],int $default=0):int{try{$q=db()->prepare($sql);$q->execute($params);$v=$q->fetchColumn();return $v===false?$default:(int)$v;}catch(Throwable){return $default;}}
    public static function run(string $requestedBy='owner'):array{
        $items=[];
        try{ReleaseInfo::activate();self::item($items,'release','Release 20.1.4 runtime activated',str_starts_with(ReleaseInfo::VERSION,'20.1.')&&(string)setting('system.version','')===ReleaseInfo::VERSION,'fail',['version'=>ReleaseInfo::VERSION,'stored'=>(string)setting('system.version','')]);}catch(Throwable $e){self::item($items,'release','Release activation',false,'fail',['error'=>Security::redactSecrets($e->getMessage(),240)]);}
        try{$schema=SchemaPreflightService::verify();self::item($items,'schema','Schema 20.0 preflight',!empty($schema['ok']),'fail',['issues'=>array_slice((array)($schema['issues']??[]),0,20)]);}catch(Throwable $e){self::item($items,'schema','Schema 20.0 preflight',false,'fail',['error'=>Security::redactSecrets($e->getMessage(),240)]);}
        try{$doctor=SystemDoctor::report();$failed=[];foreach((array)($doctor['checks']??[]) as $k=>$c)if(empty($c['ok']))$failed[]=$k;self::item($items,'doctor','System Doctor',!empty($doctor['ok']),'fail',['failed_checks'=>$failed,'schema_issues'=>$doctor['schema_issues']??[]]);}catch(Throwable $e){self::item($items,'doctor','System Doctor',false,'fail',['error'=>Security::redactSecrets($e->getMessage(),240)]);}
        self::item($items,'db_reconnect_guard','MySQL stale-connection recovery',method_exists(Database::class,'isDisconnect')&&method_exists(Database::class,'reconnect'),'fail',['release'=>ReleaseInfo::VERSION]);
        try{$runtime=RuntimeValidationService::run('v20_core_acceptance','post_install_20_1');self::item($items,'runtime','V20 core runtime acceptance',($runtime['state']??'')==='passed','fail',['run_id'=>$runtime['run_id']??null,'state'=>$runtime['state']??'unknown','summary'=>$runtime['summary']??[]]);}catch(Throwable $e){self::item($items,'runtime','V20 core runtime acceptance',false,'fail',['error'=>Security::redactSecrets($e->getMessage(),240)]);}
        $agents=self::scalar("SELECT COUNT(*) FROM agents WHERE is_active=1 AND slug IN ('ramy','walid','ayman','emad','samir-social','video-director','basant','community-manager')");self::item($items,'agents','ثمانية وكلاء أساسيين نشطين',$agents===8,'fail',['active_core_agents'=>$agents]);
        $caps=self::scalar("SELECT COUNT(DISTINCT capability) FROM provider_capability_routes WHERE enabled=1 AND capability IN ('text','coding','vision','embeddings','web_search','image','video','stt','tts','realtime_voice','browser','external_api')");self::item($items,'provider_capabilities','مسارات قدرات الذكاء الأساسية',$caps===12,'fail',['registered'=>$caps,'required'=>12]);
        $wf=self::scalar("SELECT COUNT(*) FROM agent_workflow_templates WHERE enabled=1 AND COALESCE(state,'active')='active'");$steps=self::scalar("SELECT COUNT(*) FROM agent_workflow_steps WHERE enabled=1");self::item($items,'workflow','Workflow runtime',$wf>0&&$steps>0,'fail',['templates'=>$wf,'steps'=>$steps]);
        $rules=self::scalar("SELECT COUNT(*) FROM agent_policy_rules WHERE enabled=1");self::item($items,'policy','Central policy rules',$rules>=6,'fail',['rules'=>$rules]);
        $vault=self::scalar("SELECT COUNT(*) FROM secure_vault_snapshots WHERE state='verified'");$cfg=self::scalar("SELECT COUNT(*) FROM secure_config_snapshots WHERE state='verified'");self::item($items,'snapshots','Encrypted config/vault snapshots',$vault>0&&$cfg>0,'fail',['vault'=>$vault,'config'=>$cfg]);
        $fp=DeploymentFingerprintService::snapshot();self::item($items,'fingerprint','Deployment fingerprint',empty($fp['code']['missing'])&&!empty($fp['code']['sha256']),'fail',$fp);
        self::item($items,'staging','Official staging domain',(string)setting('staging.domain','')==='nourmakkah.com','fail',['domain'=>(string)setting('staging.domain','')]);
        self::item($items,'worker','Worker heartbeat',!empty($fp['runtime']['worker_healthy']),'warning',['heartbeat_at'=>$fp['runtime']['worker_heartbeat_at']??null,'last_state'=>$fp['runtime']['worker_last_state']??'']);
        $health=IntegrationHealthService::snapshot();self::item($items,'integrations','External integration health has no known failed test',(int)($health['summary']['failed']??0)===0,'warning',$health['summary']);
        $fails=count(array_filter($items,static fn($x)=>$x['state']==='failed'));$warnings=count(array_filter($items,static fn($x)=>$x['state']==='warning'));$state=$fails?'failed':($warnings?'warning':'passed');$result=['state'=>$state,'ok'=>$fails===0,'failed'=>$fails,'warnings'=>$warnings,'checks'=>$items,'generated_at'=>now_utc(),'requested_by'=>$requestedBy];
        try{put_setting('post_install.20_1.last_state',$state);put_setting('post_install.20_1.last_at',now_utc());put_setting('post_install.20_1.last_result_json',j($result));}catch(Throwable){}
        return $result;
    }
    public static function latest():array{
        $raw=(string)setting('post_install.20_1.last_result_json','');if($raw==='')return [];
        try{$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}
    }
}
