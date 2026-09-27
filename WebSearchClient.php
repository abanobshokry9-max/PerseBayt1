<?php
declare(strict_types=1);

final class WebSearchClient {
    private static array $lastDiagnostics=[];

    public static function provider():string{
        $p=trim((string)setting('opportunities.search_provider','auto'));
        return $p!==''?$p:'auto';
    }
    private static function genericFallbackEnabled():bool{
        // 7.0.4: keep a zero-key emergency path for Walid, but accept ONLY known individual project URLs.
        // This avoids the old noisy DuckDuckGo/Bing behavior while preventing a queued search from becoming unusable when paid APIs are absent.
        return setting('opportunities.trusted_public_fallback','1')==='1';
    }
    private static function trustedPublicRows(array $rows):array{
        // Keep both individual project URLs and trusted marketplace listing pages.
        // OpportunitySearchService expands listing pages into individual project links before saving,
        // so dropping listings here was causing Bing to report useful marketplace pages as zero results.
        $out=[];foreach($rows as $row){
            $url=trim((string)($row['url']??''));if($url==='')continue;
            $c=OpportunitySourceRules::classify($url);
            if(!$c['trusted']||!in_array($c['kind'],['detail','listing'],true))continue;
            $row['source']=$c['source']?:($row['source']??'Public Search');
            $row['_source_kind']=$c['kind'];
            $out[]=$row;
        }
        return $out;
    }

    public static function marketplaceSeeds():array{
        // Free deterministic starting points. These are public marketplace listing pages, not opportunities.
        // They are expanded into individual project URLs later and are never stored as project cards.
        if(setting('opportunities.marketplace_seed_discovery','1')!=='1')return [];
        return [
            ['title'=>'مشاريع برمجة وتطوير حديثة — نفذلي','url'=>'https://nafezly.com/projects/specialize/development','snippet'=>'قائمة عامة لمشاريع البرمجة والتطوير','published_at'=>'','source'=>'Nafezly','_source_kind'=>'listing'],
            ['title'=>'مشاريع تطوير مواقع وتطبيقات — مستقل','url'=>'https://mostaql.com/projects?category=development&sort=latest','snippet'=>'قائمة عامة لمشاريع تطوير المواقع والتطبيقات','published_at'=>'','source'=>'Mostaql','_source_kind'=>'listing'],
            ['title'=>'WordPress jobs — Freelancer','url'=>'https://www.freelancer.com/jobs/wordpress/','snippet'=>'Public WordPress project listing','published_at'=>'','source'=>'Freelancer','_source_kind'=>'listing'],
            ['title'=>'PHP jobs — Freelancer','url'=>'https://www.freelancer.com/jobs/php/','snippet'=>'Public PHP project listing','published_at'=>'','source'=>'Freelancer','_source_kind'=>'listing'],
            ['title'=>'Website Design jobs — Freelancer','url'=>'https://www.freelancer.com/jobs/website-design/','snippet'=>'Public website design project listing','published_at'=>'','source'=>'Freelancer','_source_kind'=>'listing'],
        ];
    }
    public static function configured():bool{
        $p=self::provider();
        if($p==='auto'){
            foreach(['serper','brave','tavily'] as $x)if(self::key($x)!=='')return true;
            if(setting('opportunities.openrouter_web_fallback','1')==='1' && OpenRouterService::configured())return true;
            if(setting('opportunities.openai_web_fallback','1')==='1' && OpenAIClient::configured())return true;
            return self::genericFallbackEnabled();
        }
        if(in_array($p,['duckduckgo','bing_rss','bing_html'],true))return true;
        if($p==='openai_web')return OpenAIClient::configured();
        if($p==='openrouter_web')return OpenRouterService::configured();
        return in_array($p,['serper','brave','tavily'],true) && self::key($p)!=='';
    }
    public static function lastDiagnostics():array{return self::$lastDiagnostics;}
    public static function hasKey(string $provider):bool{return self::key($provider)!=='';}
    private static function key(string $provider):string{return trim((string)SecretVault::get('sources.'.$provider.'.api_key',''));}

    public static function search(string $query,int $limit=10):array{
        $selected=self::provider();$limit=max(1,min(20,$limit));self::$lastDiagnostics=[];
        $providers=[];
        $emergency=self::genericFallbackEnabled();
        if($selected==='auto'){
            foreach(['serper','brave','tavily'] as $p)if(self::key($p)!=='')$providers[]=$p;
            if(!$providers){
                // Free/fast path first. Current Walid queries use strict site:project-detail patterns,
                // and trustedPublicRows() discards all non-project Bing results.
                if($emergency){$providers[]='bing_rss';if(setting('opportunities.bing_html_fallback','1')==='1')$providers[]='bing_html';}
                // OpenRouter inference can be free while web search is billed separately.
                // Do not use paid web search unless the owner explicitly enables it.
                if(setting('opportunities.openrouter_web_paid_search','0')==='1' && setting('opportunities.openrouter_web_fallback','1')==='1' && OpenRouterService::configured())$providers[]='openrouter_web';
                elseif(setting('opportunities.openai_web_fallback','0')==='1' && OpenAIClient::configured())$providers[]='openai_web';
            }
        }else{
            if($selected==='openai_web' && !OpenAIClient::configured())throw new RuntimeException('openai_not_configured');
            if($selected==='openrouter_web' && !OpenRouterService::configured())throw new RuntimeException('openrouter_not_configured');
            $providers[]=$selected;
            if($emergency && !in_array($selected,['duckduckgo','bing_rss','bing_html'],true)){$providers[]='bing_rss';if(setting('opportunities.bing_html_fallback','1')==='1')$providers[]='bing_html';}
        }
        if(!$providers)throw new RuntimeException('search_api_not_configured');
        $providers=array_values(array_unique($providers));$all=[];$seen=[];$perProvider=$limit;
        foreach($providers as $provider){
            try{
                if(in_array($provider,['serper','brave','tavily'],true)&&self::key($provider)===''){
                    self::$lastDiagnostics[]=['provider'=>$provider,'state'=>'skipped','error'=>'missing_key','count'=>0];continue;
                }
                if($provider==='openai_web'&&!OpenAIClient::configured()){self::$lastDiagnostics[]=['provider'=>$provider,'state'=>'skipped','error'=>'openai_not_configured','count'=>0];continue;}
                if($provider==='openrouter_web'&&!OpenRouterService::configured()){self::$lastDiagnostics[]=['provider'=>$provider,'state'=>'skipped','error'=>'openrouter_not_configured','count'=>0];continue;}
                $providerQuery=self::queryForProvider($query,$provider);$started=microtime(true);
                $rows=match($provider){
                    'duckduckgo'=>self::duckDuckGo($providerQuery,$perProvider),
                    'bing_rss'=>self::bingRss($providerQuery,$perProvider),
                    'bing_html'=>self::bingHtml($providerQuery,$perProvider),
                    'serper'=>self::serper($providerQuery,$perProvider,self::key($provider)),
                    'brave'=>self::brave($providerQuery,$perProvider,self::key($provider)),
                    'tavily'=>self::tavily($providerQuery,$perProvider,self::key($provider)),
                    'openai_web'=>OpenAIClient::webSearchSources($providerQuery,$perProvider),
                    'openrouter_web'=>OpenRouterService::webSearchSources($providerQuery,$perProvider),
                    default=>throw new RuntimeException('web_search_provider_unsupported')
                };
                $rawCount=count($rows);if(in_array($provider,['duckduckgo','bing_rss','bing_html'],true))$rows=self::trustedPublicRows($rows);
                self::$lastDiagnostics[]=['provider'=>$provider,'state'=>$rows?'ok':'empty','count'=>count($rows),'raw_count'=>$rawCount,'trusted_only'=>in_array($provider,['duckduckgo','bing_rss','bing_html'],true),'elapsed_ms'=>(int)round((microtime(true)-$started)*1000)];
                foreach($rows as $row){
                    $url=trim((string)($row['url']??''));$title=trim((string)($row['title']??''));if($url===''||$title==='')continue;
                    $key=hash('sha256',pb_strtolower($url.'|'.$title));if(isset($seen[$key]))continue;$seen[$key]=true;$all[]=$row;if(count($all)>=$limit*2)break;
                }
                // Search providers are fallbacks, not a requirement to query every provider. Stop once this query has enough unique candidates.
                if(count($all)>=min($limit,3))break;
            }catch(Throwable $e){self::$lastDiagnostics[]=['provider'=>$provider,'state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage(),220),0,220),'count'=>0];}
        }
        return array_slice($all,0,$limit);
    }

    /** Open-web search for company prospecting. Unlike search(), this does not restrict free fallbacks to marketplace project URLs. */
    public static function searchGeneral(string $query,int $limit=10):array{
        $selected=self::provider();$limit=max(1,min(20,$limit));self::$lastDiagnostics=[];$providers=[];
        if($selected==='auto'){
            foreach(['serper','brave','tavily'] as $p)if(self::key($p)!=='')$providers[]=$p;
            if(!$providers){$providers[]='bing_rss';if(setting('opportunities.bing_html_fallback','1')==='1')$providers[]='bing_html';if(setting('opportunities.openrouter_web_paid_search','0')==='1'&&OpenRouterService::configured())$providers[]='openrouter_web';elseif(setting('opportunities.openai_web_fallback','0')==='1'&&OpenAIClient::configured())$providers[]='openai_web';}
        }else $providers[]=$selected;
        $providers=array_values(array_unique($providers));$all=[];$seen=[];
        foreach($providers as $provider){
            try{
                if(in_array($provider,['serper','brave','tavily'],true)&&self::key($provider)==='')continue;
                $q=self::queryForProvider($query,$provider);$started=microtime(true);
                $rows=match($provider){'duckduckgo'=>self::duckDuckGo($q,$limit),'bing_rss'=>self::bingRss($q,$limit),'bing_html'=>self::bingHtml($q,$limit),'serper'=>self::serper($q,$limit,self::key($provider)),'brave'=>self::brave($q,$limit,self::key($provider)),'tavily'=>self::tavily($q,$limit,self::key($provider)),'openai_web'=>OpenAIClient::webSearchSources($q,$limit),'openrouter_web'=>OpenRouterService::webSearchSources($q,$limit),default=>throw new RuntimeException('web_search_provider_unsupported')};
                self::$lastDiagnostics[]=['provider'=>$provider,'state'=>$rows?'ok':'empty','count'=>count($rows),'raw_count'=>count($rows),'trusted_only'=>false,'elapsed_ms'=>(int)round((microtime(true)-$started)*1000)];
                foreach($rows as $row){$url=trim((string)($row['url']??''));$title=trim((string)($row['title']??''));if($url===''||$title==='')continue;$fp=hash('sha256',pb_strtolower($url.'|'.$title));if(isset($seen[$fp]))continue;$seen[$fp]=1;$all[]=$row;if(count($all)>=$limit)break 2;}
            }catch(Throwable $e){self::$lastDiagnostics[]=['provider'=>$provider,'state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage(),220),0,220),'count'=>0];}
        }
        return array_slice($all,0,$limit);
    }

    private static function queryForProvider(string $query,string $provider):string{
        if(in_array($provider,['duckduckgo','bing_rss','bing_html'],true)){
            $query=(string)preg_replace('/\s+after:\d{4}-\d{2}-\d{2}\b/i','',$query);
            $query=trim((string)preg_replace('/\s+/u',' ',$query));
        }
        return $query;
    }

    private static function duckDuckGo(string $q,int $limit):array{
        $urls=[
            'https://html.duckduckgo.com/html/?'.http_build_query(['q'=>$q,'kl'=>'wt-wt'],'','&',PHP_QUERY_RFC3986),
            'https://lite.duckduckgo.com/lite/?'.http_build_query(['q'=>$q],'','&',PHP_QUERY_RFC3986),
        ];
        $lastError='';
        foreach($urls as $url){
            try{$r=HttpClient::probe($url,4,450000);}catch(Throwable $e){$lastError=$e->getMessage();continue;}
            $status=(int)($r['status']??0);if($status<200||$status>=300){$lastError='web_search_http_'.$status;continue;}
            $html=(string)($r['body']??'');if($html==='')continue;
            $out=self::parseDuckHtml($html,$limit);if($out)return $out;
        }
        if($lastError!=='')throw new RuntimeException($lastError);
        return [];
    }
    private static function parseDuckHtml(string $html,int $limit):array{
        $out=[];$patterns=[
            '/<a[^>]+class=["\'][^"\']*result__a[^"\']*["\'][^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',
            '/<a[^>]+class=["\'][^"\']*result-link[^"\']*["\'][^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',
            '/<a[^>]+rel=["\']nofollow["\'][^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu'
        ];
        foreach($patterns as $pattern){
            if(!preg_match_all($pattern,$html,$matches,PREG_SET_ORDER))continue;
            foreach($matches as $m){
                $href=html_entity_decode((string)$m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');$title=trim(html_entity_decode(strip_tags((string)$m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                if(str_starts_with($href,'//'))$href='https:'.$href;
                if(str_contains($href,'duckduckgo.com/l/?')){$qs=parse_url($href,PHP_URL_QUERY);parse_str((string)$qs,$parts);if(!empty($parts['uddg']))$href=(string)$parts['uddg'];}
                if(!filter_var($href,FILTER_VALIDATE_URL)||$title===''||str_contains((string)parse_url($href,PHP_URL_HOST),'duckduckgo.com'))continue;
                $out[]=['title'=>pb_substr($title,0,300),'url'=>$href,'snippet'=>'','published_at'=>'','source'=>'DuckDuckGo'];if(count($out)>=$limit)break 2;
            }
        }
        return $out;
    }
    private static function bingRss(string $q,int $limit):array{
        $url='https://www.bing.com/search?'.http_build_query(['q'=>$q,'format'=>'rss','count'=>$limit],'','&',PHP_QUERY_RFC3986);
        $r=HttpClient::probe($url,5,450000);$status=(int)($r['status']??0);if($status<200||$status>=300)throw new RuntimeException('bing_rss_http_'.$status);
        $body=(string)($r['body']??'');if(trim($body)==='')return [];
        $out=[];
        if(function_exists('simplexml_load_string')){libxml_use_internal_errors(true);$xml=simplexml_load_string($body);if($xml){foreach(($xml->channel->item??[]) as $item){$title=trim((string)($item->title??''));$link=trim((string)($item->link??''));if($title===''||!filter_var($link,FILTER_VALIDATE_URL))continue;$out[]=['title'=>$title,'url'=>$link,'snippet'=>trim(strip_tags((string)($item->description??''))),'published_at'=>(string)($item->pubDate??''),'source'=>'Bing RSS'];if(count($out)>=$limit)break;}}}
        if(!$out&&preg_match_all('/<item\b[^>]*>(.*?)<\/item>/isu',$body,$items)){foreach($items[1] as $chunk){$field=static function(string $name,string $xml):string{if(!preg_match('/<'.preg_quote($name,'/').'\b[^>]*>(.*?)<\/'.preg_quote($name,'/').'>/isu',$xml,$m))return '';return trim(html_entity_decode(strip_tags(preg_replace('/<!\[CDATA\[(.*?)\]\]>/su','$1',$m[1])??$m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));};$title=$field('title',$chunk);$link=$field('link',$chunk);if($title===''||!filter_var($link,FILTER_VALIDATE_URL))continue;$out[]=['title'=>$title,'url'=>$link,'snippet'=>$field('description',$chunk),'published_at'=>$field('pubDate',$chunk),'source'=>'Bing RSS'];if(count($out)>=$limit)break;}}
        if(!$out)throw new RuntimeException('bing_rss_invalid_or_empty');return $out;
    }

    private static function bingHtml(string $q,int $limit):array{
        $url='https://www.bing.com/search?'.http_build_query(['q'=>$q,'count'=>max(5,min(20,$limit)),'setlang'=>'en-US'],'','&',PHP_QUERY_RFC3986);
        $r=HttpClient::probe($url,6,600000);$status=(int)($r['status']??0);if($status<200||$status>=300)throw new RuntimeException('bing_html_http_'.$status);
        $html=(string)($r['body']??'');if(trim($html)==='')return [];
        $out=[];$seen=[];
        $patterns=[
            '/<li[^>]+class=["\'][^"\']*b_algo[^"\']*["\'][^>]*>.*?<h2[^>]*>\s*<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',
            '/<h2[^>]*>\s*<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',
        ];
        foreach($patterns as $pattern){
            if(!preg_match_all($pattern,$html,$matches,PREG_SET_ORDER))continue;
            foreach($matches as $m){
                $link=html_entity_decode(trim((string)$m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');$title=trim(html_entity_decode(strip_tags((string)$m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                if(!filter_var($link,FILTER_VALIDATE_URL)||$title==='')continue;$host=strtolower((string)(parse_url($link,PHP_URL_HOST)?:''));if($host===''||str_contains($host,'bing.com'))continue;
                $fp=hash('sha256',pb_strtolower($link));if(isset($seen[$fp]))continue;$seen[$fp]=true;
                $out[]=['title'=>pb_substr($title,0,300),'url'=>$link,'snippet'=>'','published_at'=>'','source'=>'Bing HTML'];if(count($out)>=$limit)break 2;
            }
        }
        if(!$out)throw new RuntimeException('bing_html_invalid_or_empty');return $out;
    }

    private static function serper(string $q,int $limit,string $key):array{$r=HttpClient::json('POST','https://google.serper.dev/search',['X-API-KEY'=>$key],['q'=>$q,'num'=>$limit],45);$out=[];foreach((array)($r['organic']??[]) as $x){$out[]=['title'=>(string)($x['title']??''),'url'=>(string)($x['link']??''),'snippet'=>(string)($x['snippet']??''),'published_at'=>(string)($x['date']??''),'source'=>'Serper / Google'];}return array_slice($out,0,$limit);}
    private static function brave(string $q,int $limit,string $key):array{$url='https://api.search.brave.com/res/v1/web/search?'.http_build_query(['q'=>$q,'count'=>$limit,'safesearch'=>'moderate'],'','&',PHP_QUERY_RFC3986);$r=HttpClient::json('GET',$url,['X-Subscription-Token'=>$key],null,45);$out=[];foreach((array)($r['web']['results']??[]) as $x)$out[]=['title'=>(string)($x['title']??''),'url'=>(string)($x['url']??''),'snippet'=>strip_tags((string)($x['description']??'')),'published_at'=>(string)($x['age']??$x['page_age']??''),'source'=>'Brave Search'];return array_slice($out,0,$limit);}
    private static function tavily(string $q,int $limit,string $key):array{$r=HttpClient::json('POST','https://api.tavily.com/search',[],['api_key'=>$key,'query'=>$q,'max_results'=>$limit,'search_depth'=>'advanced','include_answer'=>false],60);$out=[];foreach((array)($r['results']??[]) as $x)$out[]=['title'=>(string)($x['title']??''),'url'=>(string)($x['url']??''),'snippet'=>(string)($x['content']??''),'published_at'=>(string)($x['published_date']??''),'source'=>'Tavily'];return array_slice($out,0,$limit);}
}
