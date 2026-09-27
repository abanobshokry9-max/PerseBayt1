<?php
declare(strict_types=1);
final class AiGateway {
    private static function routes(array $agent,string $capability='text'):array{
        $out=[];$seen=[];
        try{
            foreach(ProviderCapabilityRouter::routes($capability,true,(int)$agent['id']) as $r){$pk=(string)$r['provider_key'];if(isset($seen[$pk]))continue;$q=db()->prepare('SELECT driver,config_json,enabled FROM providers WHERE provider_key=? LIMIT 1');$q->execute([$pk]);$p=$q->fetch()?:[];if(array_key_exists('enabled',$p)&&(int)$p['enabled']!==1)continue;$out[]=['route_order'=>(int)$r['route_order'],'provider_key'=>$pk,'model'=>(string)($r['model_key']??''),'driver'=>(string)($p['driver']??$pk),'config_json'=>(string)($r['config_json']??$p['config_json']??'{}'),'global_capability'=>$capability];$seen[$pk]=1;}
        }catch(Throwable){}
        try{if(OpenRouterService::forcePrimaryEnabled()&&OpenRouterService::configured()&&!isset($seen['openrouter']))OpenRouterService::ensurePrimaryForAgent((int)$agent['id']);}catch(Throwable $e){error_log('ELMETR openrouter primary reconcile: '.Security::redactSecrets($e->getMessage(),180));}
        try{$q=db()->prepare("SELECT r.*,p.driver,p.config_json,p.enabled provider_enabled FROM agent_provider_routes r LEFT JOIN providers p ON p.provider_key=r.provider_key WHERE r.agent_id=? AND r.enabled=1 AND COALESCE(p.enabled,1)=1 ORDER BY r.route_order ASC");$q->execute([(int)$agent['id']]);foreach($q->fetchAll() as $r){$pk=(string)$r['provider_key'];if(isset($seen[$pk]))continue;$out[]=$r;$seen[$pk]=1;}}catch(Throwable){}
        if(!$out){
            if(OpenRouterService::forcePrimaryEnabled()&&OpenRouterService::configured())$out=[['route_order'=>1,'provider_key'=>'openrouter','model'=>OpenRouterService::preferredModel(),'driver'=>'openrouter','config_json'=>'{}']];
            else $out=[['route_order'=>1,'provider_key'=>(string)($agent['provider_key']??'openai'),'model'=>(string)($agent['model']??''),'driver'=>(string)($agent['provider_key']??'openai'),'config_json'=>'{}']];
        }
        try{if(class_exists('FreeModelScoutService'))$out=FreeModelScoutService::augmentRoutes($out,$capability,(int)$agent['id']);}catch(Throwable $e){error_log('ELMETR free model route augment: '.Security::redactSecrets($e->getMessage(),160));}
        return $out;
    }
    private static function mark(array $agent,array $route,bool $ok,string $error=''):void{try{if(!empty($route['global_capability'])){ProviderCapabilityRouter::markRouteHealth((string)$route['global_capability'],(int)$route['route_order'],$ok?'working':'failed',$ok?null:$error);return;}db()->prepare("UPDATE agent_provider_routes SET last_state=?,last_error=?,last_used_at=NOW() WHERE agent_id=? AND route_order=?")->execute([$ok?'ok':'failed',$ok?null:pb_substr($error,0,300),(int)$agent['id'],(int)$route['route_order']]);}catch(Throwable){}}
    private static function telemetry(callable $fn,string $label):void{
        try{$fn();return;}catch(Throwable $e){
            if(Database::isDisconnect($e)){try{Database::reconnect();$fn();return;}catch(Throwable $retry){$e=$retry;}}
            error_log('ELMETR ai telemetry '.$label.': '.Security::redactSecrets($e->getMessage(),180));
        }
    }
    private static function databaseFailure(Throwable $e):RuntimeException{
        try{Database::reconnect();}catch(Throwable){}
        return new RuntimeException('database_connection_lost:'.pb_substr(Security::redactSecrets($e->getMessage()),0,260),0,$e);
    }
    private static function routeAgent(array $agent,array $route):array{
        $a=$agent;$provider=(string)$route['provider_key'];$a['provider_key']=$provider;
        // A fallback route must never inherit the primary provider model (for example openrouter/free).
        // Route rows intentionally use NULL for provider-default models, so clear the agent model here.
        $model=array_key_exists('model',$route)?trim((string)($route['model']??'')):'';
        if($provider!=='openrouter' && ($model==='openrouter/free'||str_starts_with($model,'openrouter/'))) $model='';
        $a['model']=$model;
        return $a;
    }
    public static function json(array $agent,string $instructions,array $input,array $schema,string $name='result',int $max=5000,string $capability='text'):array{
        $errors=[];$blockedProviders=[];foreach(self::routes($agent,$capability) as $route){$provider=(string)$route['provider_key'];if(isset($blockedProviders[$provider]))continue;try{AgentUsageBudgetService::authorize((int)$agent['id'],'ai_call');AiBudgetService::assertAllowed((int)$agent['id'],$capability);$a=self::routeAgent($agent,$route);$r=$provider==='openai'?OpenAIClient::json($a,$instructions,$input,$schema,$name,$max):self::jsonOther($a,$route,$instructions,$input,$schema,$max);self::telemetry(static fn()=>AgentUsageBudgetService::record((int)$agent['id'],'ai_call',1,0,['gateway'=>'json','provider'=>$provider]),'usage_count_json');self::mark($agent,$route,true);$r['provider_key']=$provider;self::telemetry(static fn()=>AiBudgetService::record((int)$agent['id'],$provider,(string)($r['model']??$a['model']??''),$capability,(int)($r['input_tokens']??0),(int)($r['output_tokens']??0),['gateway'=>'json','purpose'=>$name]),'usage_ledger_json');return $r;}catch(Throwable $e){if(Database::isDisconnect($e))throw self::databaseFailure($e);$err=Security::redactSecrets($e->getMessage());self::mark($agent,$route,false,$err);if(class_exists('FreeModelScoutService')&&FreeModelScoutService::providerWideFailure($provider,$err))$blockedProviders[$provider]=1;$errors[]=$route['provider_key'].':'.$err;}}
        throw new RuntimeException('ai_routes_exhausted:'.pb_substr(implode(' | ',$errors),0,500));
    }
    public static function text(array $agent,string $instructions,array $input,int $max=2500,string $capability='text'):array{
        $errors=[];$blockedProviders=[];foreach(self::routes($agent,$capability) as $route){$provider=(string)$route['provider_key'];if(isset($blockedProviders[$provider]))continue;try{AgentUsageBudgetService::authorize((int)$agent['id'],'ai_call');AiBudgetService::assertAllowed((int)$agent['id'],$capability);$a=self::routeAgent($agent,$route);$r=$provider==='openai'?OpenAIClient::text($a,$instructions,$input,$max):self::textOther($a,$route,$instructions,$input,$max);self::telemetry(static fn()=>AgentUsageBudgetService::record((int)$agent['id'],'ai_call',1,0,['gateway'=>'text','provider'=>$provider]),'usage_count_text');self::mark($agent,$route,true);$r['provider_key']=$provider;self::telemetry(static fn()=>AiBudgetService::record((int)$agent['id'],$provider,(string)($r['model']??$a['model']??''),$capability,(int)($r['input_tokens']??0),(int)($r['output_tokens']??0),['gateway'=>'text']),'usage_ledger_text');return $r;}catch(Throwable $e){if(Database::isDisconnect($e))throw self::databaseFailure($e);$err=Security::redactSecrets($e->getMessage());self::mark($agent,$route,false,$err);if(class_exists('FreeModelScoutService')&&FreeModelScoutService::providerWideFailure($provider,$err))$blockedProviders[$provider]=1;$errors[]=$route['provider_key'].':'.$err;}}
        throw new RuntimeException('ai_routes_exhausted:'.pb_substr(implode(' | ',$errors),0,500));
    }
    public static function directProviderText(string $provider,string $prompt,int $max=1200):array{
        $prompt=trim($prompt);if($prompt==='')throw new RuntimeException('message_required');$max=max(64,min(4000,$max));
        $agent=AgentService::bySlug('ramy');
        if($provider==='openai'){
            $a=$agent;$a['provider_key']='openai';$m=trim((string)SecretVault::get('providers.openai.model',''));if($m!=='')$a['model']=$m;else $a['model']='';
            return OpenAIClient::text($a,'أجب على طلب الاختبار التالي بوضوح وباختصار. لا تنفذ أي إجراء خارجي.',[['role'=>'user','content'=>$prompt]],$max);
        }
        if($provider==='openrouter'){
            $route=['provider_key'=>'openrouter','route_order'=>0,'driver'=>'openrouter','config_json'=>'{}','model'=>OpenRouterService::preferredModel()];
            $a=self::routeAgent($agent,$route);return self::textOther($a,$route,'أجب على طلب الاختبار التالي بوضوح وباختصار. لا تنفذ أي إجراء خارجي.',[['role'=>'user','content'=>$prompt]],$max);
        }
        $q=db()->prepare("SELECT provider_key,driver,config_json FROM providers WHERE provider_key=? AND kind='ai' AND enabled=1 LIMIT 1");$q->execute([$provider]);$row=$q->fetch();if(!$row)throw new RuntimeException('provider_unknown_or_disabled');
        $route=$row+['route_order'=>0,'model'=>trim((string)SecretVault::get('providers.'.$provider.'.model',''))];
        $a=self::routeAgent($agent,$route);return self::textOther($a,$route,'أجب على طلب الاختبار التالي بوضوح وباختصار. لا تنفذ أي إجراء خارجي.',[['role'=>'user','content'=>$prompt]],$max);
    }
    public static function testProvider(string $provider):array{
        $agent=AgentService::bySlug('ramy');
        if($provider==='openai'){$r=OpenAIClient::text($agent,'أجب بكلمة OK فقط.',[['role'=>'user','content'=>'connection test']],24);return ['model'=>$r['model']??null,'text'=>$r['text']??''];}
        if($provider==='openrouter')return OpenRouterService::inferenceTest();
        $q=db()->prepare("SELECT provider_key,driver,config_json FROM providers WHERE provider_key=? AND kind='ai' LIMIT 1");$q->execute([$provider]);$row=$q->fetch();if(!$row)throw new RuntimeException('provider_unknown');
        $route=$row+['route_order'=>0,'model'=>trim((string)SecretVault::get('providers.'.$provider.'.model',''))];
        $r=self::textOther($agent,$route,'أجب بكلمة OK فقط.',[['role'=>'user','content'=>'connection test']],64);
        return ['model'=>$r['model']??null,'text'=>$r['text']??''];
    }
    private static function cfg(array $route):array{$c=json_decode((string)($route['config_json']??'{}'),true);return is_array($c)?$c:[];}
    private static function secret(string $key):string{return trim((string)SecretVault::get($key,''));}
    private static function messages(array $input,string $instructions):array{$m=[['role'=>'system','content'=>$instructions]];foreach($input as $x)$m[]=['role'=>($x['role']??'user')==='assistant'?'assistant':'user','content'=>(string)($x['content']??'')];return $m;}
    private static function extractJson(string $text):array{$text=trim($text);$d=json_decode($text,true);if(is_array($d))return $d;if(preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/su',$text,$m)){$d=json_decode($m[1],true);if(is_array($d))return $d;}if(preg_match('/(\{.*\})/su',$text,$m)){$d=json_decode($m[1],true);if(is_array($d))return $d;}throw new RuntimeException('ai_invalid_json');}
    private static function model(array $agent,array $route,string $provider):string{
        $raw=array_key_exists('model',$route)?($route['model']??''):($agent['model']??'');
        $m=trim((string)$raw);
        // Repair legacy cross-provider route pollution caused by promoting OpenRouter as the primary.
        if($provider!=='openrouter' && ($m==='openrouter/free'||str_starts_with($m,'openrouter/'))) $m='';
        if($m!=='')return $m;
        $m=self::secret('providers.'.$provider.'.model');if($m!=='')return $m;
        return match($provider){'openrouter'=>OpenRouterService::DEFAULT_MODEL,'gemini'=>'gemini-2.5-flash','anthropic'=>'claude-sonnet-4-5','groq'=>'llama-3.3-70b-versatile',default=>throw new RuntimeException('ai_model_not_configured:'.$provider)};
    }
    private static function geminiCandidates(string $preferred,array $discovered=[]):array{
        $rows=[$preferred,self::secret('providers.gemini.model')];
        foreach($discovered as $m)$rows[]=$m;
        // Last-resort documented stable names only. Never invent future Gemini model names.
        $rows[]='gemini-2.5-flash';$rows[]='gemini-2.5-flash-lite';
        $out=[];foreach($rows as $m){$m=trim((string)$m);$m=preg_replace('#^models/#','',$m)??$m;if($m!==''&&!in_array($m,$out,true))$out[]=$m;}return $out;
    }
    private static function geminiDiscoverModels(string $key):array{
        $r=HttpClient::json('GET','https://generativelanguage.googleapis.com/v1beta/models?key='.rawurlencode($key),[],null,35);
        $scored=[];
        foreach((array)($r['models']??[]) as $row){
            if(!is_array($row))continue;
            $name=preg_replace('#^models/#','',trim((string)($row['name']??'')))??'';if($name===''||!str_starts_with($name,'gemini-'))continue;
            $methods=(array)($row['supportedGenerationMethods']??$row['supportedActions']??[]);
            $can=false;foreach($methods as $method)if(strcasecmp((string)$method,'generateContent')===0){$can=true;break;}
            if(!$can)continue;
            $low=strtolower($name);$score=0;if(str_contains($low,'flash'))$score+=100;if(str_contains($low,'lite'))$score-=8;if(str_contains($low,'pro'))$score+=35;if(str_contains($low,'preview')||str_contains($low,'experimental')||str_contains($low,'exp'))$score-=25;
            if(preg_match('/([0-9]+)(?:\.([0-9]+))?/', $name,$m))$score+=(int)$m[1]*5+(int)($m[2]??0);
            $scored[$name]=$score;
        }
        arsort($scored,SORT_NUMERIC);
        try{put_setting('ai.gemini_discovered_models',j(array_keys($scored)));put_setting('ai.gemini_models_discovered_at',now_utc());}catch(Throwable){}
        return array_keys($scored);
    }
    private static function rememberGeminiModel(string $preferred,string $model):void{
        if($model===''||$model===$preferred)return;
        try{SecretVault::save(['providers.gemini.model'=>$model]);}catch(Throwable){}
        try{db()->prepare("UPDATE agent_provider_routes SET model=? WHERE provider_key='gemini' AND (model IS NULL OR model='' OR model=?)")->execute([$model,$preferred]);}catch(Throwable){}
        try{db()->prepare("UPDATE providers SET status='verified',last_error=NULL,last_checked_at=NOW() WHERE provider_key='gemini'")->execute();}catch(Throwable){}
        try{put_setting('ai.gemini_last_model_repair',$preferred.' -> '.$model);put_setting('ai.gemini_last_model_repair_at',now_utc());}catch(Throwable){}
    }
    private static function geminiGenerate(string $preferred,string $key,array $payload):array{
        $errors=[];$tried=[];$discovered=[];$discoveryTried=false;
        while(true){
            $candidates=self::geminiCandidates($preferred,$discovered);$progress=false;
            foreach($candidates as $model){
                if(isset($tried[$model]))continue;$tried[$model]=true;$progress=true;
                try{
                    $r=HttpClient::json('POST','https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($key),[],$payload,75);
                    self::rememberGeminiModel($preferred,$model);
                    return ['response'=>$r,'model'=>$model];
                }catch(Throwable $e){
                    $err=Security::redactSecrets($e->getMessage(),320);$errors[]=$model.':'.$err;
                    $low=pb_strtolower($err);$recoverable=str_contains($low,'http_404')||str_contains($low,'not found')||str_contains($low,'not supported for generatecontent')||str_contains($low,'model is not found')||str_contains($low,'unexpected model name format')||str_contains($low,'invalid model name');
                    if(!$recoverable)throw $e;
                    if(!$discoveryTried){
                        $discoveryTried=true;
                        try{$discovered=self::geminiDiscoverModels($key);}catch(Throwable $listError){$errors[]='models.list:'.Security::redactSecrets($listError->getMessage(),220);}
                    }
                }
            }
            if(!$progress)break;
            $remaining=false;foreach(self::geminiCandidates($preferred,$discovered) as $candidate){if(!isset($tried[$candidate])){$remaining=true;break;}}
            if(!$remaining)break;
        }
        throw new RuntimeException('gemini_models_unavailable:'.pb_substr(implode(' | ',$errors),0,700));
    }
    private static function jsonOther(array $agent,array $route,string $instructions,array $input,array $schema,int $max):array{
        $schemaText=j($schema);
        $prompt=$instructions."\nأعد JSON صالحًا فقط مطابقًا لهذا JSON Schema. لا تضف Markdown ولا شرحًا قبل أو بعد JSON:\n".$schemaText;
        try{return self::other($agent,$route,$prompt,$input,$max,true);}
        catch(Throwable $e){
            // Free-router models vary. Give OpenRouter one strict JSON repair attempt before moving to provider fallback.
            if((string)($route['provider_key']??'')==='openrouter' && str_contains($e->getMessage(),'ai_invalid_json')){
                $repair=$prompt."\nالمحاولة السابقة لم تنتج JSON صالحًا. هذه محاولة إصلاح أخيرة: أخرج JSON واحدًا فقط يبدأ بـ { أو [ وينتهي بالقوس المطابق، ولا تكتب أي نص آخر.";
                return self::other($agent,$route,$repair,$input,max(1200,$max),true);
            }
            throw $e;
        }
    }
    private static function textOther(array $agent,array $route,string $instructions,array $input,int $max):array{return self::other($agent,$route,$instructions,$input,$max,false);}
    private static function other(array $agent,array $route,string $instructions,array $input,int $max,bool $jsonMode):array{
        $provider=(string)$route['provider_key'];$model=self::model($agent,$route,$provider);$cfg=self::cfg($route);
        if($provider==='gemini'){
            $key=self::secret('providers.gemini.api_key');if(strlen($key)<10)throw new RuntimeException('gemini_not_configured');$parts=[];foreach($input as $m)$parts[]=['text'=>(string)($m['content']??'')];$payload=['system_instruction'=>['parts'=>[['text'=>$instructions]]],'contents'=>[['role'=>'user','parts'=>$parts]],'generationConfig'=>['maxOutputTokens'=>$max]+($jsonMode?['responseMimeType'=>'application/json']:[])];$g=self::geminiGenerate($model,$key,$payload);$r=$g['response'];$model=(string)$g['model'];$text=(string)($r['candidates'][0]['content']['parts'][0]['text']??'');$usage=$r['usageMetadata']??[];$out=['text'=>trim($text),'input_tokens'=>(int)($usage['promptTokenCount']??0),'output_tokens'=>(int)($usage['candidatesTokenCount']??0),'model'=>$model];
        }elseif($provider==='anthropic'){
            $key=self::secret('providers.anthropic.api_key');if(strlen($key)<10)throw new RuntimeException('anthropic_not_configured');$messages=[];foreach($input as $m)$messages[]=['role'=>($m['role']??'user')==='assistant'?'assistant':'user','content'=>(string)($m['content']??'')];$r=HttpClient::json('POST','https://api.anthropic.com/v1/messages',['x-api-key'=>$key,'anthropic-version'=>'2023-06-01'],['model'=>$model,'system'=>$instructions,'messages'=>$messages,'max_tokens'=>$max],75);$text='';foreach((array)($r['content']??[]) as $c)if(($c['type']??'')==='text')$text.=(string)($c['text']??'');$out=['text'=>trim($text),'input_tokens'=>(int)($r['usage']['input_tokens']??0),'output_tokens'=>(int)($r['usage']['output_tokens']??0),'model'=>$model];
        }else{
            $key=self::secret('providers.'.$provider.'.api_key');
            if($provider==='openrouter'){
                if(strlen($key)<10)throw new RuntimeException('openrouter_not_configured');
                // Do not require response_format from the free router: not every free endpoint supports it.
                // The prompt already asks for JSON and extractJson() validates the final output.
                $payload=['model'=>$model,'messages'=>self::messages($input,$instructions),'max_completion_tokens'=>max(256,$max),'temperature'=>$jsonMode?0:0.2];
                $r=OpenRouterService::chat($payload,90,3);
                $text=OpenRouterService::messageText($r);
                $out=['text'=>trim($text),'input_tokens'=>(int)($r['usage']['prompt_tokens']??0),'output_tokens'=>(int)($r['usage']['completion_tokens']??0),'model'=>(string)($r['model']??$model)];
            }else{
                $base=trim((string)($cfg['base_url']??self::secret('providers.'.$provider.'.base_url')));if($base==='')$base=$provider==='groq'?'https://api.groq.com/openai/v1':'';if($base==='')throw new RuntimeException('ai_provider_base_url_missing:'.$provider);$headers=[];if($key!=='')$headers['Authorization']='Bearer '.$key;$payload=['model'=>$model,'messages'=>self::messages($input,$instructions),'max_tokens'=>$max,'temperature'=>0.2];if($jsonMode)$payload['response_format']=['type'=>'json_object'];$r=$provider==='ollama'?HttpClient::jsonLoopback('POST',rtrim($base,'/').'/chat/completions',$headers,$payload,75):HttpClient::json('POST',rtrim($base,'/').'/chat/completions',$headers,$payload,75);$text=(string)($r['choices'][0]['message']['content']??'');$out=['text'=>trim($text),'input_tokens'=>(int)($r['usage']['prompt_tokens']??0),'output_tokens'=>(int)($r['usage']['completion_tokens']??0),'model'=>(string)($r['model']??$model)];
            }
        }
        if($out['text']==='')throw new RuntimeException('ai_empty_output');if($jsonMode){$out['data']=self::extractJson($out['text']);unset($out['text']);}return $out;
    }
}
