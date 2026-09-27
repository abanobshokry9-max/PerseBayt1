<?php
declare(strict_types=1);
final class OpenAIClient {
    public static function configured(): bool {
        $k=(string)config('ai.api_key','');
        return strlen(trim($k))>=20;
    }
    private static function key(): string {$k=(string)config('ai.api_key','');if(strlen($k)<20)throw new RuntimeException('openai_not_configured');return $k;}
    private static function model(?array $agent=null): string {$m='';$provider=pb_strtolower(trim((string)($agent['provider_key']??'')));if($provider===''||$provider==='openai')$m=trim((string)($agent['model']??''));if($m==='')$m=trim((string)setting('ai.openai.last_working_model',''));if($m==='')$m=trim((string)config('ai.model',''));if($m==='')$m='gpt-5.6';return $m;}
    private static function modelError(Throwable $e):bool{$m=strtolower($e->getMessage());return str_starts_with($m,'http_404:')&&(str_contains($m,'model')||str_contains($m,'access')||str_contains($m,'does not exist'));}
    public static function availableModels():array{$r=HttpClient::json('GET','https://api.openai.com/v1/models',['Authorization'=>'Bearer '.self::key()],null,35);$ids=[];foreach((array)($r['data']??[]) as $row){$id=trim((string)($row['id']??''));if($id==='')continue;$l=strtolower($id);if(!str_starts_with($l,'gpt-'))continue;if(preg_match('/(?:audio|realtime|transcribe|tts|image|moderation|embedding)/',$l))continue;$ids[$id]=1;}return array_keys($ids);}
    private static function candidates(string $failed):array{$ids=self::availableModels();usort($ids,static function($a,$b){$score=static function($x){$s=0;$l=strtolower($x);if(!preg_match('/-20[0-9]{2}-[0-9]{2}-[0-9]{2}$/',$l))$s+=40;if(str_contains($l,'mini'))$s+=20;if(preg_match('/^gpt-[0-9]+(?:\.[0-9]+)?(?:-mini)?$/',$l))$s+=50;return $s;};return $score($b)<=>$score($a)?:strcmp($a,$b);});return array_values(array_filter($ids,static fn($x)=>$x!==$failed));}
    private static function responses(array $payload,int $timeout):array{try{$r=HttpClient::json('POST','https://api.openai.com/v1/responses',['Authorization'=>'Bearer '.self::key()],$payload,$timeout);put_setting('ai.openai.last_working_model',(string)($r['model']??$payload['model']??''));put_setting('ai.openai.last_model_verified_at',now_utc());return $r;}catch(Throwable $first){if(!self::modelError($first))throw $first;$failed=(string)($payload['model']??'');$last=$first;$tries=0;foreach(self::candidates($failed) as $candidate){if(++$tries>10)break;$retry=$payload;$retry['model']=$candidate;try{$r=HttpClient::json('POST','https://api.openai.com/v1/responses',['Authorization'=>'Bearer '.self::key()],$retry,$timeout);put_setting('ai.openai.last_working_model',(string)($r['model']??$candidate));put_setting('ai.openai.last_model_verified_at',now_utc());return $r;}catch(Throwable $e){$last=$e;$m=strtolower($e->getMessage());if(!(self::modelError($e)||str_starts_with($m,'http_400:')))throw $e;}}throw new RuntimeException('openai_models_unavailable:'.pb_substr(Security::redactSecrets($last->getMessage(),220),0,220),0,$last);}}
    public static function json(array $agent,string $instructions,array $input,array $schema,string $name='result',int $max=5000): array {
        $items=[];foreach($input as $m){$role=in_array($m['role']??'user',['user','assistant','developer'],true)?$m['role']:'user';$items[]=['role'=>$role,'content'=>[['type'=>$role==='assistant'?'output_text':'input_text','text'=>(string)($m['content']??'')]]];}
        $payload=['model'=>self::model($agent),'instructions'=>$instructions,'input'=>$items,'store'=>false,'max_output_tokens'=>$max,'text'=>['format'=>['type'=>'json_schema','name'=>$name,'strict'=>true,'schema'=>$schema]]];
        $r=self::responses($payload,75);
        $text='';foreach(($r['output']??[]) as $o)if(($o['type']??'')==='message')foreach(($o['content']??[]) as $c)if(($c['type']??'')==='output_text')$text.=(string)($c['text']??'');
        if($text==='')throw new RuntimeException('openai_empty_output');$obj=json_decode($text,true);if(!is_array($obj))throw new RuntimeException('openai_invalid_json');
        $usage=$r['usage']??[];return ['data'=>$obj,'input_tokens'=>(int)($usage['input_tokens']??0),'output_tokens'=>(int)($usage['output_tokens']??0),'model'=>(string)($r['model']??self::model($agent))];
    }

    public static function webSearchSources(string $query,int $limit=10): array {
        $query=trim($query);$limit=max(1,min(20,$limit));if($query==='')return [];
        $model=trim((string)setting('opportunities.openai_web_model',''));if($model==='')$model=self::model(null);
        $lookback=max(1,min(60,(int)setting('opportunities.lookback_days','15')));
        $prompt="Search the public web for REAL buyer/client project listings matching this query. Prefer posts from the last {$lookback} days. Return INDIVIDUAL project/job detail URLs whenever possible, not category pages, search pages, freelancer profiles, seller services, articles, tutorials, news, Wikipedia, website builders, generic homepages or login pages. Focus on a buyer asking someone to build, redesign, fix or integrate a website/WordPress/web application. Return sources only through the web search tool. Query: ".$query;
        $payload=[
            'model'=>$model,'store'=>false,'max_output_tokens'=>900,
            'tools'=>[['type'=>'web_search','search_context_size'=>'medium']],
            'input'=>$prompt
        ];
        $r=self::responses($payload,40);
        $rows=[];$seen=[];$summary='';
        foreach((array)($r['output']??[]) as $o){
            if(($o['type']??'')==='web_search_call'){
                foreach((array)($o['action']['sources']??[]) as $src){
                    $url=trim((string)($src['url']??''));if(!filter_var($url,FILTER_VALIDATE_URL))continue;
                    $key=hash('sha256',pb_strtolower($url));if(isset($seen[$key]))continue;$seen[$key]=true;
                    $host=(string)(parse_url($url,PHP_URL_HOST)?:'Web source');$path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
                    $title=$host.($path!==''?' — '.str_replace(['-','_','/'],' ',$path):'');
                    $rows[]=['title'=>pb_substr($title,0,300),'url'=>$url,'snippet'=>'','published_at'=>'','source'=>'OpenAI Web Search'];
                }
            }
            if(($o['type']??'')==='message'){
                foreach((array)($o['content']??[]) as $c){
                    if(($c['type']??'')!=='output_text')continue;$txt=trim((string)($c['text']??''));if($txt!=='')$summary.=($summary!==''?"\n":'').$txt;
                    foreach((array)($c['annotations']??[]) as $ann){
                        if(($ann['type']??'')!=='url_citation')continue;
                        $url=trim((string)($ann['url']??($ann['url_citation']['url']??'')));if(!filter_var($url,FILTER_VALIDATE_URL))continue;
                        $key=hash('sha256',pb_strtolower($url));$title=trim((string)($ann['title']??($ann['url_citation']['title']??'')));
                        if(isset($seen[$key])){
                            if($title!=='')foreach($rows as &$row)if($row['url']===$url&&str_starts_with((string)$row['title'],(string)(parse_url($url,PHP_URL_HOST)?:''))){$row['title']=pb_substr($title,0,300);break;}unset($row);
                            continue;
                        }
                        $seen[$key]=true;if($title==='')$title=(string)(parse_url($url,PHP_URL_HOST)?:'Web source');
                        $rows[]=['title'=>pb_substr($title,0,300),'url'=>$url,'snippet'=>'','published_at'=>'','source'=>'OpenAI Web Search'];
                    }
                }
            }
        }
        // Do not copy one model summary onto every URL. That made unrelated/listing pages inherit buyer-intent text
        // and pass Walid's gate as false opportunities. Per-source evidence must come from the search provider or detail page.
        return array_slice($rows,0,$limit);
    }

    public static function text(array $agent,string $instructions,array $input,int $max=2500): array {
        $items=[];foreach($input as $m)$items[]=['role'=>in_array($m['role']??'user',['user','assistant','developer'],true)?$m['role']:'user','content'=>(string)($m['content']??'')];
        $r=self::responses(['model'=>self::model($agent),'instructions'=>$instructions,'input'=>$items,'store'=>false,'max_output_tokens'=>$max],75);
        $text='';foreach(($r['output']??[]) as $o)if(($o['type']??'')==='message')foreach(($o['content']??[]) as $c)if(($c['type']??'')==='output_text')$text.=(string)($c['text']??'');
        $usage=$r['usage']??[];return ['text'=>trim($text),'input_tokens'=>(int)($usage['input_tokens']??0),'output_tokens'=>(int)($usage['output_tokens']??0),'model'=>(string)($r['model']??self::model($agent))];
    }
}
