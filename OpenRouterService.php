<?php
declare(strict_types=1);
final class OpenRouterService {
    public const BASE_URL = 'https://openrouter.ai/api/v1';
    public const DEFAULT_MODEL = 'openrouter/free';

    public static function configured(): bool {
        return strlen(trim((string)SecretVault::get('providers.openrouter.api_key',''))) >= 10;
    }

    public static function preferredModel(): string {
        $model=trim((string)SecretVault::get('providers.openrouter.model',''));
        return $model!==''?$model:self::DEFAULT_MODEL;
    }

    public static function normalizeApiKey(string $raw): string {
        $key=trim($raw);
        if((str_starts_with($key,'\"')&&str_ends_with($key,'\"'))||(str_starts_with($key,"'")&&str_ends_with($key,"'")))$key=substr($key,1,-1);
        $key=preg_replace('/^Bearer\s+/i','',$key)??$key;
        $key=preg_replace('/\s+/u','',$key)??$key;
        return trim($key);
    }
    private static function apiKey(): string {
        $key=self::normalizeApiKey((string)SecretVault::get('providers.openrouter.api_key',''));
        if(strlen($key)<10)throw new RuntimeException('openrouter_not_configured');
        return $key;
    }
    public static function activeKeyMeta(): array {
        $key=self::normalizeApiKey((string)SecretVault::get('providers.openrouter.api_key',''));
        $path=defined('PB_ROOT')?PB_ROOT.'/private/runtime/secrets.enc':'';
        return [
            'present'=>$key!=='',
            'fingerprint'=>$key!==''?substr(hash('sha256',$key),0,12):'',
            'length'=>strlen($key),
            'format'=>$key===''?'none':(str_starts_with($key,'sk-or-')?'openrouter':'unknown'),
            'vault_mtime'=>($path!==''&&is_file($path))?(int)filemtime($path):null,
        ];
    }

    public static function keyInfo(): array {
        $r=HttpClient::json('GET',self::BASE_URL.'/key',['Authorization'=>'Bearer '.self::apiKey()],null,35);
        $data=$r['data']??[];
        return is_array($data)?$data:[];
    }

    public static function dataCollectionPolicy(): string {
        $policy=strtolower(trim((string)setting('openrouter.data_collection','allow')));
        return $policy==='deny'?'deny':'allow';
    }

    public static function headers(): array {
        $base=(string)config('app.base_url','https://persebayt.com');
        $parts=parse_url($base);
        $origin='https://persebayt.com';
        if(is_array($parts) && !empty($parts['host'])){
            $scheme=in_array(strtolower((string)($parts['scheme']??'https')),['http','https'],true)?strtolower((string)($parts['scheme']??'https')):'https';
            $origin=$scheme.'://'.(string)$parts['host'];
            if(!empty($parts['port']))$origin.=':'.(int)$parts['port'];
        }
        return ['HTTP-Referer'=>$origin,'X-Title'=>'PerseBayt Company OS','X-OpenRouter-Metadata'=>'enabled'];
    }

    /** Extract normal assistant text without ever treating hidden reasoning as the answer. */
    public static function messageText(array $response): string {
        $message=$response['choices'][0]['message']??[];
        $content=is_array($message)?($message['content']??''):'';
        if(is_string($content))return trim($content);
        if(!is_array($content))return '';
        $text='';
        foreach($content as $part){
            if(is_string($part)){$text.=$part;continue;}
            if(!is_array($part))continue;
            $type=strtolower((string)($part['type']??''));
            if($type===''||$type==='text'||$type==='output_text')$text.=(string)($part['text']??$part['content']??'');
        }
        return trim($text);
    }

    /**
     * OpenRouter can occasionally return a successful HTTP response with no final text,
     * especially when a reasoning model consumes a very small completion budget.
     * Retry an empty completion with a larger visible-answer budget.
     * Do not force reasoning off: OpenRouter free routing can select models where reasoning is mandatory.
     */
    public static function chat(array $payload,int $timeout=75,int $attempts=3): array {
        $key=self::apiKey();
        $payload['model']=trim((string)($payload['model']??''))?:self::preferredModel();
        unset($payload['max_tokens']);
        $payload['max_completion_tokens']=max(64,(int)($payload['max_completion_tokens']??256));
        // The free router may select a model where reasoning is mandatory. A legacy 'none' value
        // caused HTTP 400 errors and prevented fallback from being useful, so omit only that value.
        if(strtolower(trim((string)($payload['reasoning_effort']??'')))==='none')unset($payload['reasoning_effort']);
        $provider=is_array($payload['provider']??null)?$payload['provider']:[];
        $provider['allow_fallbacks']=true;
        if(self::dataCollectionPolicy()==='deny')$provider['data_collection']='deny';
        $payload['provider']=$provider;
        $headers=self::headers()+['Authorization'=>'Bearer '.$key];
        $diagnostics=[];
        $attempts=max(1,min(4,$attempts));
        for($i=1;$i<=$attempts;$i++){
            $request=$payload;
            if($i>1){
                // Rescue mode: give reasoning models enough room to still produce a visible final answer.
                unset($request['reasoning_effort']);
                $request['temperature']=0.1;
                $request['max_completion_tokens']=max(512,(int)$request['max_completion_tokens']);
            }
            $r=HttpClient::json('POST',self::BASE_URL.'/chat/completions',$headers,$request,$timeout);
            $text=self::messageText($r);
            $choice=$r['choices'][0]??[];$message=is_array($choice)?($choice['message']??[]):[];
            $reasoningTokens=(int)($r['usage']['completion_tokens_details']['reasoning_tokens']??0);
            $diag=[
                'attempt'=>$i,
                'model'=>(string)($r['model']??$request['model']),
                'finish_reason'=>(string)($choice['finish_reason']??''),
                'reasoning_tokens'=>$reasoningTokens,
                'completion_tokens'=>(int)($r['usage']['completion_tokens']??0),
                'refusal'=>pb_substr((string)(is_array($message)?($message['refusal']??''):''),0,120),
            ];
            if($text!=='')return $r+['_persebayt_openrouter_attempts'=>array_merge($diagnostics,[$diag])];
            $diagnostics[]=$diag;
            if($i<$attempts)usleep(150000);
        }
        $last=$diagnostics[count($diagnostics)-1]??[];
        $model=pb_substr((string)($last['model']??$payload['model']),0,100);
        $finish=pb_substr((string)($last['finish_reason']??'unknown'),0,40);
        $reason=(int)($last['reasoning_tokens']??0);
        throw new RuntimeException('openrouter_empty_output:model='.$model.';finish='.$finish.';reasoning_tokens='.$reason);
    }

    public static function inferenceTest(): array {
        $payload=[
            'model'=>self::preferredModel(),
            'messages'=>[
                ['role'=>'system','content'=>'Connection test. Return a short visible final answer only.'],
                ['role'=>'user','content'=>'Reply exactly with OK'],
            ],
            'max_completion_tokens'=>512,
            'temperature'=>0,
        ];
        $r=self::chat($payload,60,3);
        return [
            'model'=>(string)($r['model']??self::preferredModel()),
            'text'=>self::messageText($r),
            'finish_reason'=>(string)($r['choices'][0]['finish_reason']??''),
            'reasoning_tokens'=>(int)($r['usage']['completion_tokens_details']['reasoning_tokens']??0),
            'completion_tokens'=>(int)($r['usage']['completion_tokens']??0),
        ];
    }

    /**
     * Web-search bridge for Walid. Company OS 7.0.5 may choose it automatically when
     * OpenRouter is the configured AI provider and no dedicated Serper/Brave/Tavily
     * key exists. Search-tool usage is diagnosed separately because server-side web
     * search may have its own billing/availability even when openrouter/free inference
     * itself is free.
     */
    public static function webSearchSources(string $query,int $limit=5):array{
        $query=trim($query);$limit=max(1,min(10,$limit));if($query==='')return [];
        $lookback=max(1,min(60,(int)setting('opportunities.lookback_days','15')));
        // The legacy OpenRouter server tool lets the selected model decide whether to search at all.
        // Walid needs deterministic discovery, so use the web plugin: it performs one search before
        // the model writes the answer and works across model families, including openrouter/free routing.
        $system='You are PerseBayt opportunity discovery. Web results are provided to you. Return JSON only as {"items":[{"title":"...","url":"https://...","snippet":"...","published_at":"..."}]}. Include only individual buyer/client project or job detail URLs. Exclude articles, tutorials, seller services, profiles, category/search/login pages and generic homepages. Never invent URLs, budgets or dates.';
        $user='Search for real currently open buyer project opportunities, preferably posted within the last '.$lookback.' days. Query: '.$query.'\nReturn up to '.$limit.' best individual project-detail URLs.';
        $payload=[
            'model'=>self::preferredModel(),
            'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
            'plugins'=>[[
                'id'=>'web',
                'max_results'=>$limit,
                'search_prompt'=>'Use these live web results as evidence. Prefer individual freelance project/job detail pages and preserve exact source URLs.',
            ]],
            'max_completion_tokens'=>1800,
            'temperature'=>0.1,
        ];
        $timeout=max(12,min(24,(int)setting('opportunities.openrouter_web_timeout_seconds','18')));
        $r=self::chat($payload,$timeout,1);$text=self::messageText($r);
        $data=json_decode($text,true);
        if(!is_array($data)&&preg_match('/```(?:json)?\s*(\{.*\})\s*```/su',$text,$m))$data=json_decode($m[1],true);
        if(!is_array($data)&&preg_match('/(\{.*\})/su',$text,$m))$data=json_decode($m[1],true);
        $items=is_array($data)?($data['items']??[]):[];$out=[];$seen=[];
        $push=static function(string $title,string $url,string $snippet='',string $published='')use(&$out,&$seen,$limit):void{
            $title=trim(strip_tags($title));$url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if(!filter_var($url,FILTER_VALIDATE_URL)||$title==='')return;$host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));if($host==='')return;
            $fp=hash('sha256',pb_strtolower($url));if(isset($seen[$fp]))return;$seen[$fp]=true;
            $out[]=['title'=>pb_substr($title,0,300),'url'=>$url,'snippet'=>pb_substr(trim(strip_tags($snippet)),0,1000),'published_at'=>pb_substr(trim($published),0,80),'source'=>'OpenRouter Web Search'];
        };
        foreach((array)$items as $item){if(!is_array($item))continue;$push((string)($item['title']??''),(string)($item['url']??''),(string)($item['snippet']??''),(string)($item['published_at']??''));if(count($out)>=$limit)break;}
        // Rescue malformed model output: OpenRouter web responses often contain markdown citations even
        // when a free model ignored the requested JSON schema. Preserve those real URLs instead of
        // incorrectly reporting zero search results.
        if(!$out&&preg_match_all('/\[([^\]]{2,180})\]\((https?:\/\/[^)\s]+)\)/u',$text,$mm,PREG_SET_ORDER)){
            foreach($mm as $m){$push((string)$m[1],(string)$m[2]);if(count($out)>=$limit)break;}
        }
        if(!$out&&preg_match_all('~https?://[^\s<>()"\']+~u',$text,$urls)){
            foreach($urls[0] as $url){$host=(string)(parse_url($url,PHP_URL_HOST)?:'');$push($host!==''?$host:'Project opportunity',$url);if(count($out)>=$limit)break;}
        }
        if(!$out)throw new RuntimeException('openrouter_web_zero_results_or_invalid_output');
        return array_slice($out,0,$limit);
    }

    public static function forcePrimaryEnabled(): bool {
        return setting('agents.force_openrouter_primary','1')==='1';
    }

    public static function ensurePrimaryForAgent(int $agentId): void {
        if(!self::configured()||!self::forcePrimaryEnabled())return;
        $agent=AgentService::byId($agentId);if(!(int)($agent['is_active']??0))return;
        $preferred=self::preferredModel();
        $q=db()->prepare('SELECT route_order,provider_key,model,enabled FROM agent_provider_routes WHERE agent_id=? AND enabled=1 ORDER BY route_order');
        $q->execute([$agentId]);$old=$q->fetchAll();
        $first=$old[0]??null;
        if($first && (string)$first['provider_key']==='openrouter' && trim((string)($first['model']??''))===$preferred){
            if((string)($agent['provider_key']??'')!=='openrouter'||trim((string)($agent['model']??''))!==$preferred)db()->prepare("UPDATE agents SET provider_key='openrouter',model=? WHERE id=?")->execute([$preferred,$agentId]);
            return;
        }
        $routes=[['provider_key'=>'openrouter','model'=>$preferred]];
        foreach($old as $r){
            $provider=trim((string)($r['provider_key']??''));if($provider===''||$provider==='openrouter')continue;
            $duplicate=false;foreach($routes as $x)if($x['provider_key']===$provider){$duplicate=true;break;}if($duplicate)continue;
            $routes[]=['provider_key'=>$provider,'model'=>trim((string)($r['model']??''))?:null];if(count($routes)>=3)break;
        }
        if(count($routes)<3){foreach(['openai','gemini','groq','anthropic','ollama'] as $provider){$duplicate=false;foreach($routes as $x)if($x['provider_key']===$provider){$duplicate=true;break;}if($duplicate)continue;$check=db()->prepare("SELECT enabled FROM providers WHERE provider_key=? AND kind='ai' LIMIT 1");$check->execute([$provider]);if((int)($check->fetchColumn()?:0)!==1)continue;$routes[]=['provider_key'=>$provider,'model'=>null];if(count($routes)>=3)break;}}
        db()->prepare('DELETE FROM agent_provider_routes WHERE agent_id=?')->execute([$agentId]);
        $ins=db()->prepare("INSERT INTO agent_provider_routes(agent_id,route_order,provider_key,model,enabled,last_state,last_error,last_used_at) VALUES (?,?,?,?,1,'untested',NULL,NULL)");
        foreach(array_slice($routes,0,3) as $i=>$r)$ins->execute([$agentId,$i+1,$r['provider_key'],$r['model']]);
        db()->prepare("UPDATE agents SET provider_key='openrouter',model=? WHERE id=?")->execute([$preferred,$agentId]);
    }

    public static function promoteAllAgents(): int {
        if(!self::configured())throw new RuntimeException('openrouter_not_configured');
        put_setting('agents.default_ai_provider','openrouter');put_setting('agents.force_openrouter_primary','1');
        $agents=db()->query("SELECT id,slug FROM agents WHERE is_active=1 AND status<>'disabled' ORDER BY id")->fetchAll();$changed=0;
        foreach($agents as $agent){$aid=(int)$agent['id'];self::ensurePrimaryForAgent($aid);$changed++;try{Audit::log('owner',(string)(Auth::user()['id']??1),'agent.ai_routes_promote','agent',(string)$aid,'verified',null,null,['primary'=>'openrouter','model'=>self::preferredModel()]);}catch(Throwable){}}
        return $changed;
    }

    public static function promoteCoreAgents(): int { return self::promoteAllAgents(); }
}
