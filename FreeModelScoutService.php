<?php
declare(strict_types=1);

/**
 * Daily free-model/provider discovery and resilient routing support.
 * Important: this service never farms accounts, bypasses CAPTCHA/2FA, or creates
 * duplicate accounts to evade provider quotas. It can auto-configure only an
 * already-authorized provider/key or a provider that offers an official
 * provisioning API. Otherwise onboarding is recorded as human_required.
 */
final class FreeModelScoutService {
    private const AGENT_SLUG='free-model-scout';

    public static function knownProviders():array{
        return [
            'openrouter'=>['label'=>'OpenRouter Free Router','driver'=>'openrouter','base_url'=>'https://openrouter.ai/api/v1','models_url'=>'https://openrouter.ai/api/v1/models','signup_url'=>'https://openrouter.ai/settings/keys','docs_url'=>'https://openrouter.ai/docs','free_kind'=>'shared_free_quota','priority'=>10,'public_models'=>true],
            'gemini'=>['label'=>'Google Gemini','driver'=>'gemini','base_url'=>'https://generativelanguage.googleapis.com/v1beta','models_url'=>'https://generativelanguage.googleapis.com/v1beta/models','signup_url'=>'https://aistudio.google.com/app/apikey','docs_url'=>'https://ai.google.dev/gemini-api/docs','free_kind'=>'provider_free_tier','priority'=>20],
            'groq'=>['label'=>'Groq','driver'=>'openai_compatible','base_url'=>'https://api.groq.com/openai/v1','models_url'=>'https://api.groq.com/openai/v1/models','signup_url'=>'https://console.groq.com/keys','docs_url'=>'https://console.groq.com/docs','free_kind'=>'provider_free_tier','priority'=>30],
            'cerebras'=>['label'=>'Cerebras','driver'=>'openai_compatible','base_url'=>'https://api.cerebras.ai/v1','models_url'=>'https://api.cerebras.ai/v1/models','signup_url'=>'https://cloud.cerebras.ai/','docs_url'=>'https://inference-docs.cerebras.ai/','free_kind'=>'provider_free_tier','priority'=>40],
            'mistral'=>['label'=>'Mistral AI','driver'=>'openai_compatible','base_url'=>'https://api.mistral.ai/v1','models_url'=>'https://api.mistral.ai/v1/models','signup_url'=>'https://console.mistral.ai/','docs_url'=>'https://docs.mistral.ai/','free_kind'=>'provider_free_tier','priority'=>50],
            'huggingface'=>['label'=>'Hugging Face Inference','driver'=>'openai_compatible','base_url'=>'https://router.huggingface.co/v1','models_url'=>'','signup_url'=>'https://huggingface.co/settings/tokens','docs_url'=>'https://huggingface.co/docs/inference-providers/','free_kind'=>'provider_free_tier','priority'=>60],
        ];
    }

    public static function agent():array{
        try{return AgentService::bySlug(self::AGENT_SLUG);}catch(Throwable){return ['id'=>0,'slug'=>self::AGENT_SLUG,'display_name'=>'نور — صياد الموديلات المجانية','status'=>'disabled','is_active'=>0];}
    }

    public static function scheduled():array{
        if(setting('free_model_scout.enabled','1')!=='1')return ['state'=>'disabled'];
        $hours=max(6,min(168,(int)setting('free_model_scout.interval_hours','24')));
        $last=(string)setting('free_model_scout.last_run_at','');$ts=utc_ts($last);
        if($ts!==false&&$ts>=time()-($hours*3600))return ['state'=>'not_due','last_run_at'=>$last];
        return self::run('scheduler');
    }

    public static function run(string $trigger='manual'):array{
        $agent=self::agent();$agentId=(int)($agent['id']??0);$runId=self::startRun($trigger);
        $stats=['models_found'=>0,'models_new'=>0,'providers_checked'=>0,'providers_working'=>0,'providers_human_required'=>0,'web_candidates'=>0,'errors'=>[]];
        try{
            if($agentId>0)AgentService::runtimeStatus($agentId,'working');
            foreach(self::knownProviders() as $key=>$def){
                try{
                    self::ensureCandidate($key,$def);$stats['providers_checked']++;
                    $configured=self::configured($key);
                    self::updateCandidateState($key,$configured?'configured':'human_required',$configured?'credential_present':'manual_signup_or_key_required');
                    if(!$configured){$stats['providers_human_required']++;continue;}
                    self::ensureProviderRow($key,$def);
                    $models=self::discoverModels($key,$def);$stats['models_found']+=count($models);
                    foreach($models as $m){if(self::upsertModel($key,$m,$def))$stats['models_new']++;}
                    try{
                        $test=self::safeProviderTest($key);$working=!empty($test['ok']);
                        self::updateCandidateState($key,$working?'working':'configured',$working?'provider_test_ok':(string)($test['error']??'provider_test_failed'));
                        if($working)$stats['providers_working']++;
                    }catch(Throwable $e){self::updateCandidateState($key,'configured',Security::redactSecrets($e->getMessage(),260));}
                }catch(Throwable $e){$stats['errors'][]=$key.':'.pb_substr(Security::redactSecrets($e->getMessage(),220),0,220);self::updateCandidateState($key,'failed',end($stats['errors'])?:'failed');}
            }
            try{$stats['web_candidates']=self::discoverWebCandidates();}catch(Throwable $e){$stats['errors'][]='web:'.pb_substr(Security::redactSecrets($e->getMessage(),200),0,200);}
            self::refreshCatalogHealth();
            put_setting('free_model_scout.last_run_at',now_utc());
            put_setting('free_model_scout.last_state',$stats['errors']?'partial':'completed');
            put_setting('free_model_scout.last_summary',j($stats));
            self::finishRun($runId,$stats['errors']?'partial':'completed',$stats,null);
            self::dailyNotification($stats);
            if($agentId>0)AgentService::runtimeStatus($agentId,'idle');
            return ['state'=>$stats['errors']?'partial':'completed','run_id'=>$runId]+$stats;
        }catch(Throwable $e){
            $safe=pb_substr(Security::redactSecrets($e->getMessage(),300),0,300);self::finishRun($runId,'failed',$stats,$safe);put_setting('free_model_scout.last_run_at',now_utc());put_setting('free_model_scout.last_state','failed');
            try{Notifications::add('critical','providers','فشل تقرير نور اليومي','تعذر إكمال اكتشاف الموديلات المجانية: '.AdminUi::humanError($safe),'free_model_scout_run',(string)$runId);}catch(Throwable){}
            if($agentId>0)try{AgentService::runtimeStatus($agentId,'error',null,'free_model_scout_failed');}catch(Throwable){}
            throw $e;
        }
    }

    public static function augmentRoutes(array $routes,string $capability,int $agentId):array{
        if(setting('free_model_scout.route_pool_enabled','1')!=='1'||!in_array($capability,['text','coding'],true))return $routes;
        $max=max(1,min(4,(int)setting('free_model_scout.max_models_per_provider','2')));
        $defs=self::knownProviders();$byProvider=[];foreach($routes as $r)$byProvider[(string)($r['provider_key']??'')][]=$r;
        $preferred=[];$rest=[];
        try{
            $rows=db()->query("SELECT c.provider_key,c.state,c.free_kind,c.priority_order,p.driver,p.config_json,p.enabled FROM free_provider_candidates c LEFT JOIN providers p ON p.provider_key=c.provider_key WHERE c.auto_route=1 AND c.state IN ('configured','working') ORDER BY c.priority_order,c.provider_key")->fetchAll();
        }catch(Throwable){$rows=[];}
        foreach($rows as $row){
            $pk=(string)$row['provider_key'];if(!self::configured($pk))continue;
            $base=$byProvider[$pk][0]??['route_order'=>180+(int)($row['priority_order']??50),'provider_key'=>$pk,'model'=>'','driver'=>(string)($row['driver']??($defs[$pk]['driver']??$pk)),'config_json'=>(string)($row['config_json']??'{}'),'scout_route'=>1];
            $models=self::bestModels($pk,$max);
            if($pk==='openrouter'){
                // openrouter/free already rotates across free models. Extra OpenRouter models do not evade a shared quota.
                $base['model']='openrouter/free';$preferred[]=$base;continue;
            }
            if(!$models){$preferred[]=$base;continue;}
            foreach($models as $idx=>$model){$x=$base;$x['model']=$model;$x['model_key']=$model;$x['route_order']=180+(int)($row['priority_order']??50)+$idx;$x['scout_route']=1;$preferred[]=$x;}
        }
        $preferredProviders=[];foreach($preferred as $r)$preferredProviders[(string)$r['provider_key']]=1;
        foreach($routes as $r){if(isset($preferredProviders[(string)($r['provider_key']??'')]))continue;$rest[]=$r;}
        return array_values(array_merge($preferred,$rest));
    }

    public static function providerWideFailure(string $provider,string $error):bool{
        $e=pb_strtolower($error);
        if(str_contains($e,'http_429')||str_contains($e,'quota')||str_contains($e,'rate_limit')||str_contains($e,'rate limit'))return true;
        if(str_contains($e,'http_401')||str_contains($e,'http_403')||str_contains($e,'invalid api key')||str_contains($e,'authentication'))return true;
        return false;
    }

    public static function dashboard():array{
        $summary=['models'=>0,'working_models'=>0,'providers'=>0,'working_providers'=>0,'human_required'=>0];
        try{$summary['models']=(int)db()->query('SELECT COUNT(*) FROM free_model_catalog WHERE active=1')->fetchColumn();$summary['working_models']=(int)db()->query("SELECT COUNT(*) FROM free_model_catalog WHERE active=1 AND health_state='working'")->fetchColumn();$summary['providers']=(int)db()->query('SELECT COUNT(*) FROM free_provider_candidates')->fetchColumn();$summary['working_providers']=(int)db()->query("SELECT COUNT(*) FROM free_provider_candidates WHERE state='working'")->fetchColumn();$summary['human_required']=(int)db()->query("SELECT COUNT(*) FROM free_provider_candidates WHERE state='human_required'")->fetchColumn();}catch(Throwable){}
        $models=[];$providers=[];$runs=[];try{$models=db()->query("SELECT * FROM free_model_catalog ORDER BY FIELD(health_state,'working','available','untested','quota_exhausted','failed','disabled'),provider_key,score DESC,last_seen_at DESC LIMIT 150")->fetchAll();}catch(Throwable){}try{$providers=db()->query('SELECT * FROM free_provider_candidates ORDER BY priority_order,provider_key')->fetchAll();}catch(Throwable){}try{$runs=db()->query('SELECT * FROM free_model_scout_runs ORDER BY id DESC LIMIT 20')->fetchAll();}catch(Throwable){}
        return ['summary'=>$summary,'models'=>$models,'providers'=>$providers,'runs'=>$runs,'last_run_at'=>setting('free_model_scout.last_run_at',''),'last_state'=>setting('free_model_scout.last_state','never')];
    }

    private static function configured(string $provider):bool{
        if($provider==='openrouter')return OpenRouterService::configured();
        if($provider==='gemini')return strlen(trim((string)SecretVault::get('providers.gemini.api_key','')))>10;
        return strlen(trim((string)SecretVault::get('providers.'.$provider.'.api_key','')))>10;
    }
    private static function authHeaders(string $provider):array{
        $key=trim((string)SecretVault::get('providers.'.$provider.'.api_key',''));if($key==='')return [];
        return ['Authorization'=>'Bearer '.$key];
    }
    private static function discoverModels(string $provider,array $def):array{
        if($provider==='openrouter'){
            $r=HttpClient::json('GET',(string)$def['models_url'],[],null,35);$out=[];
            foreach((array)($r['data']??[]) as $m){if(!is_array($m))continue;$id=trim((string)($m['id']??''));if($id==='')continue;$pricing=(array)($m['pricing']??[]);$prompt=(string)($pricing['prompt']??'');$completion=(string)($pricing['completion']??'');$free=str_ends_with($id,':free')||((float)$prompt===0.0&&(float)$completion===0.0&&$prompt!==''&&$completion!=='');if(!$free)continue;$out[]=['model_key'=>$id,'label'=>(string)($m['name']??$id),'context_length'=>(int)($m['context_length']??0),'metadata'=>$m,'free_kind'=>'zero_price'];}
            return $out;
        }
        if($provider==='gemini'){
            $key=trim((string)SecretVault::get('providers.gemini.api_key',''));if($key==='')return [];$r=HttpClient::json('GET',(string)$def['models_url'].'?key='.rawurlencode($key),[],null,35);$out=[];
            foreach((array)($r['models']??[]) as $m){if(!is_array($m))continue;$id=preg_replace('#^models/#','',trim((string)($m['name']??'')))??'';if($id===''||!str_starts_with($id,'gemini-'))continue;$methods=(array)($m['supportedGenerationMethods']??[]);if($methods&&!in_array('generateContent',$methods,true))continue;$out[]=['model_key'=>$id,'label'=>(string)($m['displayName']??$id),'context_length'=>(int)($m['inputTokenLimit']??0),'metadata'=>$m,'free_kind'=>'provider_free_tier'];}
            return $out;
        }
        $url=(string)($def['models_url']??'');if($url==='')return [];$r=HttpClient::json('GET',$url,self::authHeaders($provider),null,35);$rows=(array)($r['data']??$r['models']??[]);$out=[];
        foreach($rows as $m){if(is_string($m))$m=['id'=>$m];if(!is_array($m))continue;$id=trim((string)($m['id']??$m['name']??''));if($id==='')continue;$out[]=['model_key'=>$id,'label'=>(string)($m['name']??$m['display_name']??$id),'context_length'=>(int)($m['context_window']??$m['context_length']??0),'metadata'=>$m,'free_kind'=>'provider_free_tier'];}
        return $out;
    }
    private static function upsertModel(string $provider,array $m,array $def):bool{
        $key=pb_substr(trim((string)($m['model_key']??'')),0,190);if($key==='')return false;$q=db()->prepare('SELECT id FROM free_model_catalog WHERE provider_key=? AND model_key=?');$q->execute([$provider,$key]);$exists=(bool)$q->fetchColumn();$score=self::scoreModel($provider,$key,(int)($m['context_length']??0));
        db()->prepare("INSERT INTO free_model_catalog(provider_key,model_key,label,free_kind,source_url,api_base_url,context_length,score,health_state,metadata_json,active,discovered_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?, 'available',?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE label=VALUES(label),free_kind=VALUES(free_kind),source_url=VALUES(source_url),api_base_url=VALUES(api_base_url),context_length=VALUES(context_length),score=VALUES(score),metadata_json=VALUES(metadata_json),active=1,last_seen_at=NOW(),health_state=CASE WHEN health_state='disabled' THEN health_state ELSE 'available' END")->execute([$provider,$key,pb_substr((string)($m['label']??$key),0,190),(string)($m['free_kind']??$def['free_kind']),(string)($def['docs_url']??''),(string)($def['base_url']??''),(int)($m['context_length']??0),$score,j((array)($m['metadata']??[]))]);
        return !$exists;
    }
    private static function scoreModel(string $provider,string $model,int $context):int{
        $s=50+min(20,(int)floor($context/16000));$low=pb_strtolower($model);if(str_contains($low,'flash')||str_contains($low,'mini')||str_contains($low,'small'))$s+=8;if(str_contains($low,'coder')||str_contains($low,'code'))$s+=6;if(str_contains($low,'preview')||str_contains($low,'experimental'))$s-=8;if($provider==='openrouter')$s+=4;return max(1,min(100,$s));
    }
    private static function bestModels(string $provider,int $limit):array{
        try{$q=db()->prepare("SELECT model_key FROM free_model_catalog WHERE provider_key=? AND active=1 AND health_state IN ('working','available','untested') ORDER BY FIELD(health_state,'working','available','untested'),score DESC,last_seen_at DESC LIMIT ".max(1,min(8,$limit)));$q->execute([$provider]);return array_values(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)));}catch(Throwable){return [];}
    }
    private static function safeProviderTest(string $provider):array{
        try{$r=AiGateway::testProvider($provider);return ['ok'=>true,'model'=>$r['model']??null];}catch(Throwable $e){$safe=pb_substr(Security::redactSecrets($e->getMessage(),240),0,240);$state=self::providerWideFailure($provider,$safe)&&str_contains(pb_strtolower($safe),'429')?'quota_exhausted':'failed';try{db()->prepare('UPDATE free_model_catalog SET health_state=?,last_tested_at=NOW(),last_error=? WHERE provider_key=? AND active=1')->execute([$state,$safe,$provider]);}catch(Throwable){}return ['ok'=>false,'error'=>$safe];}
    }
    private static function refreshCatalogHealth():void{
        try{db()->exec("UPDATE free_model_catalog m JOIN free_provider_candidates c ON c.provider_key=m.provider_key SET m.health_state='working',m.last_error=NULL,m.last_tested_at=NOW() WHERE m.active=1 AND c.state='working'");}catch(Throwable){}
        try{db()->exec("UPDATE free_model_catalog m JOIN free_provider_candidates c ON c.provider_key=m.provider_key SET m.health_state=CASE WHEN m.health_state='disabled' THEN 'disabled' ELSE 'untested' END WHERE m.active=1 AND c.state='human_required'");}catch(Throwable){}
    }
    private static function ensureCandidate(string $key,array $def):void{
        db()->prepare("INSERT INTO free_provider_candidates(provider_key,label,driver,base_url,signup_url,docs_url,free_kind,state,auto_route,priority_order,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,?,'new',?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE label=VALUES(label),driver=VALUES(driver),base_url=VALUES(base_url),signup_url=VALUES(signup_url),docs_url=VALUES(docs_url),free_kind=VALUES(free_kind),last_seen_at=NOW()")->execute([$key,$def['label'],$def['driver'],$def['base_url'],$def['signup_url'],$def['docs_url'],$def['free_kind'],in_array($key,['openrouter','gemini','groq'],true)?1:0,(int)$def['priority']]);
    }
    private static function ensureProviderRow(string $key,array $def):void{
        if(!$key||!self::configured($key))return;$cfg=$def['base_url']?j(['base_url'=>$def['base_url']]):'{}';db()->prepare("INSERT INTO providers(provider_key,kind,label,driver,enabled,config_json,status,last_error,last_checked_at) VALUES (?,'ai',?,?,1,?,'untested',NULL,NULL) ON DUPLICATE KEY UPDATE label=VALUES(label),driver=VALUES(driver),enabled=1,config_json=CASE WHEN COALESCE(config_json,'') IN ('','{}') THEN VALUES(config_json) ELSE config_json END")->execute([$key,$def['label'],$def['driver'],$cfg]);
    }
    private static function updateCandidateState(string $key,string $state,string $note=''):void{
        if(!in_array($state,['new','human_required','configured','working','failed','disabled'],true))$state='failed';try{db()->prepare('UPDATE free_provider_candidates SET state=?,last_note=?,last_checked_at=NOW() WHERE provider_key=?')->execute([$state,pb_substr(Security::redactSecrets($note,500),0,500),$key]);}catch(Throwable){}
    }
    private static function discoverWebCandidates():int{
        if(setting('free_model_scout.web_discovery_enabled','1')!=='1')return 0;$queries=[
            'new free tier LLM API official model provider',
            'free AI inference API official models developer',
            'new openai compatible free tier API LLM official'
        ];$known=array_keys(self::knownProviders());$seen=[];$count=0;
        foreach($queries as $query){foreach(WebSearchClient::searchGeneral($query,6) as $row){$url=trim((string)($row['url']??''));if($url==='')continue;$host=pb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));$host=preg_replace('/^www\./','',$host)??$host;if($host===''||isset($seen[$host]))continue;$seen[$host]=1;$skip=false;foreach(self::knownProviders() as $k=>$d){foreach([(string)$d['signup_url'],(string)$d['docs_url'],(string)$d['base_url']] as $u){$kh=pb_strtolower((string)(parse_url($u,PHP_URL_HOST)?:''));if($kh!==''&&($host===$kh||str_ends_with($host,'.'.$kh)||str_ends_with($kh,'.'.$host))){$skip=true;break 2;}}}if($skip)continue;$key='web_'.substr(hash('sha256',$host),0,18);db()->prepare("INSERT INTO free_provider_candidates(provider_key,label,driver,base_url,signup_url,docs_url,free_kind,state,auto_route,priority_order,source_url,first_seen_at,last_seen_at,last_note) VALUES (?,?, 'unknown','',?,?, 'unknown','human_required',0,200,?,NOW(),NOW(),'اكتشاف ويب جديد؛ يحتاج تحقق المالك من شروط المجاني وواجهة API قبل الربط') ON DUPLICATE KEY UPDATE label=VALUES(label),source_url=VALUES(source_url),last_seen_at=NOW()")->execute([$key,pb_substr((string)($row['title']??$host),0,160),$url,$url,$url]);$count++;}}
        return $count;
    }
    private static function dailyNotification(array $s):void{
        $title=$s['errors']?'تقرير نور اليومي — اكتمل بتحذيرات':'تقرير نور اليومي — اكتشاف الموديلات المجانية';$body='موديلات مرصودة: '.(int)$s['models_found'].'، جديدة: '.(int)$s['models_new'].'، مزودات شغالة: '.(int)$s['providers_working'].'/'.(int)$s['providers_checked'].'، تحتاج تسجيل/مفتاح بشري: '.(int)$s['providers_human_required'].'، مرشحين جدد من الويب: '.(int)$s['web_candidates'].'.';if($s['errors'])$body.=' تحذيرات: '.pb_substr(implode(' | ',$s['errors']),0,600);Notifications::add($s['errors']?'warning':'success','providers',$title,$body,'free_model_scout',self::AGENT_SLUG);
    }
    private static function startRun(string $trigger):int{db()->prepare("INSERT INTO free_model_scout_runs(trigger_key,state,started_at) VALUES (?,'running',NOW())")->execute([pb_substr($trigger,0,40)]);return (int)db()->lastInsertId();}
    private static function finishRun(int $id,string $state,array $stats,?string $error):void{try{db()->prepare('UPDATE free_model_scout_runs SET state=?,summary_json=?,error_text=?,completed_at=NOW() WHERE id=?')->execute([$state,j($stats),$error,$id]);}catch(Throwable){} }
}
