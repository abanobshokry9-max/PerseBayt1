<?php
declare(strict_types=1);
/**
 * Company OS 20 owner-side post-install verifier.
 * Safe by design: no publishing, customer messaging, destructive actions,
 * production edits, media generation, browser automation or billable AI calls.
 */
require_once dirname(__DIR__,2).'/public_html/api/src/bootstrap.php';

$out=[
    'release'=>ReleaseInfo::VERSION,
    'schema'=>ReleaseInfo::SCHEMA,
    'checked_at'=>gmdate('c'),
    'ok'=>false,
    'checks'=>[],
    'warnings'=>[],
];
$redact=static fn(string $s):string=>Security::redactSecrets($s,800);
$record=static function(string $key,bool $ok,$details=null)use(&$out):void{
    $out['checks'][$key]=['ok'=>$ok,'details'=>$details];
};
try{
    $record('package_ready',ReleaseInfo::packageReady(),ReleaseInfo::packageReady()?'required files present':'required files missing');

    $pre=MigrationPreflightService::check(false);
    $record('environment_preflight',(bool)($pre['ok']??false),[
        'failures'=>$pre['failures']??[],
        'warnings'=>$pre['warnings']??[],
        'server'=>$pre['server']??'',
        'schema_before'=>$pre['schema']??'',
    ]);

    $schema=['ok'=>false,'issues'=>['schema_preflight_not_run']];
    try{
        $schema=SchemaPreflightService::verify();
        $record('schema_preflight',(bool)($schema['ok']??false),[
            'issues'=>$schema['issues']??[],
            'migration_state'=>$schema['migration']['state']??null,
        ]);
    }catch(Throwable $e){
        $schema=['ok'=>false,'issues'=>[$redact($e->getMessage())]];
        $record('schema_preflight',false,['issues'=>$schema['issues'],'migration_state'=>null]);
    }

    if(($schema['ok']??false)===true){
        try{SchemaPreflightService::markVerified();$record('schema_mark_verified',true,'migration 20 marked verified');}
        catch(Throwable $e){$record('schema_mark_verified',false,$redact($e->getMessage()));}
    }else{
        $record('schema_mark_verified',false,'skipped because schema preflight failed');
    }

    try{
        $sync=ProviderCapabilityRouter::syncFromProviders();
        $record('provider_route_sync',true,is_array($sync)?$sync:['result'=>$sync]);
    }catch(Throwable $e){$record('provider_route_sync',false,$redact($e->getMessage()));}

    try{
        PromptCanonicalizerService::applyAll();
        $record('canonical_prompts',true,'canonical prompts applied/preserved with version history');
    }catch(Throwable $e){$record('canonical_prompts',false,$redact($e->getMessage()));}

    try{
        ReleaseInfo::activate();
        $record('release_activation',(string)setting('system.version','')===ReleaseInfo::VERSION,[
            'system.version'=>(string)setting('system.version',''),
            'system.schema'=>(string)setting('system.schema',''),
        ]);
    }catch(Throwable $e){$record('release_activation',false,$redact($e->getMessage()));}

    try{
        $doctor=SystemDoctor::report();
        $failed=[];
        foreach((array)($doctor['checks']??[]) as $key=>$c)if(empty($c['ok']))$failed[]=$key;
        $record('system_doctor',(bool)($doctor['ok']??false),[
            'failed_checks'=>$failed,
            'schema_issues'=>$doctor['schema_issues']??[],
        ]);
    }catch(Throwable $e){$record('system_doctor',false,$redact($e->getMessage()));}

    try{
        $validation=RuntimeValidationService::run('v20_core_acceptance','post_install_cli');
        $record('runtime_acceptance',($validation['state']??'')==='passed',[
            'run_id'=>$validation['run_id']??null,
            'state'=>$validation['state']??'unknown',
            'summary'=>$validation['summary']??[],
        ]);
    }catch(Throwable $e){$record('runtime_acceptance',false,$redact($e->getMessage()));}

    try{
        $q=db()->query("SELECT slug,status,is_active,current_task_id,last_error_code FROM agents WHERE slug IN ('ramy','walid','ayman','emad','samir-social','video-director','basant','community-manager') ORDER BY id");
        $agents=$q->fetchAll(PDO::FETCH_ASSOC);
        $bad=array_values(array_filter($agents,static fn($a)=>(int)$a['is_active']!==1||(string)$a['status']==='disabled'));
        $record('core_agents',$bad===[],['count'=>count($agents),'inactive_or_disabled'=>array_map(static fn($a)=>$a['slug'],$bad)]);
    }catch(Throwable $e){$record('core_agents',false,$redact($e->getMessage()));}

    try{
        $caps=(int)db()->query("SELECT COUNT(DISTINCT capability) FROM provider_capability_routes WHERE enabled=1 AND capability IN ('text','coding','vision','embeddings','web_search','image','video','stt','tts','realtime_voice','browser','external_api')")->fetchColumn();
        $record('provider_capabilities',$caps===12,['registered_required_capabilities'=>$caps,'required'=>12]);
    }catch(Throwable $e){$record('provider_capabilities',false,$redact($e->getMessage()));}

    try{
        $workflows=(int)db()->query("SELECT COUNT(*) FROM agent_workflow_templates WHERE enabled=1 AND COALESCE(state,'active')='active'")->fetchColumn();
        $steps=(int)db()->query("SELECT COUNT(*) FROM agent_workflow_steps WHERE enabled=1")->fetchColumn();
        $record('workflow_runtime',$workflows>0&&$steps>0,['templates'=>$workflows,'steps'=>$steps]);
    }catch(Throwable $e){$record('workflow_runtime',false,$redact($e->getMessage()));}

    try{
        $rules=(int)db()->query("SELECT COUNT(*) FROM agent_policy_rules WHERE enabled=1")->fetchColumn();
        $record('policy_runtime',$rules>=6,['enabled_rules'=>$rules]);
    }catch(Throwable $e){$record('policy_runtime',false,$redact($e->getMessage()));}

    try{
        $vault=(int)db()->query("SELECT COUNT(*) FROM secure_vault_snapshots WHERE state='verified'")->fetchColumn();
        $cfg=(int)db()->query("SELECT COUNT(*) FROM secure_config_snapshots WHERE state='verified'")->fetchColumn();
        $record('encrypted_snapshots',$vault>0&&$cfg>0,['vault_snapshots'=>$vault,'config_snapshots'=>$cfg]);
    }catch(Throwable $e){$record('encrypted_snapshots',false,$redact($e->getMessage()));}

    $failed=array_keys(array_filter($out['checks'],static fn($c)=>empty($c['ok'])));
    $out['failed_checks']=$failed;
    $out['ok']=$failed===[];
}catch(Throwable $e){
    $out['fatal_error']=$redact($e->getMessage());
    $out['ok']=false;
}

echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($out['ok']?0:2);
