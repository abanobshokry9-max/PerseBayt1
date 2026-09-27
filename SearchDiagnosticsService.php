<?php
declare(strict_types=1);

final class SearchDiagnosticsService {
    public static function snapshot():array{
        $lastRaw=(string)setting('opportunities.last_search_diagnostics_json','');
        $last=[];if($lastRaw!==''){try{$d=json_decode($lastRaw,true,512,JSON_THROW_ON_ERROR);if(is_array($d))$last=$d;}catch(Throwable){}}
        $sources=[];
        try{$sources=db()->query("SELECT id,name,source_key,source_type,base_url,enabled,priority,last_run_at,last_state,last_error FROM opportunity_sources ORDER BY enabled DESC,priority DESC,id ASC LIMIT 30")->fetchAll();}catch(Throwable){}
        return [
            'provider'=>WebSearchClient::provider(),
            'serper'=>WebSearchClient::hasKey('serper'),
            'brave'=>WebSearchClient::hasKey('brave'),
            'tavily'=>WebSearchClient::hasKey('tavily'),
            'openrouter_ai'=>OpenRouterService::configured(),
            'openrouter_paid_web'=>setting('opportunities.openrouter_web_paid_search','0')==='1',
            'public_fallback'=>setting('opportunities.trusted_public_fallback','1')==='1',
            'marketplace_seeds'=>setting('opportunities.marketplace_seed_discovery','1')==='1',
            'bing_html'=>setting('opportunities.bing_html_fallback','1')==='1',
            'sources'=>$sources,
            'last'=>$last,
        ];
    }

    public static function run():array{
        $started=microtime(true);$checks=[];$usableLinks=0;
        foreach(array_slice(WebSearchClient::marketplaceSeeds(),0,5) as $seed){
            $row=['source'=>(string)($seed['source']??''),'url'=>(string)($seed['url']??''),'state'=>'failed','http_status'=>0,'detail_links'=>0,'samples'=>[],'elapsed_ms'=>0];
            $t=microtime(true);
            try{
                $url=Security::publicUrl((string)$seed['url']);
                $r=HttpClient::probe($url,max(3,min(8,(int)setting('opportunities.source_timeout_seconds','6'))),300000);
                $status=(int)($r['status']??0);$row['http_status']=$status;$row['final_url']=(string)($r['final_url']??$url);
                if($status>=200&&$status<300){
                    $links=OpportunitySourceRules::projectLinks((string)($r['body']??''),(string)($r['final_url']??$url),12);
                    $row['detail_links']=count($links);$usableLinks+=count($links);$row['samples']=array_slice(array_values(array_map(static fn($x)=>(string)($x['url']??''),$links)),0,3);
                    $row['state']=$links?'verified':'empty';
                    if(!$links)$row['error']='no_individual_project_links';
                }else{$row['error']='http_'.$status;}
            }catch(Throwable $e){$row['error']=pb_substr(Security::redactSecrets($e->getMessage(),180),0,180);}
            $row['elapsed_ms']=(int)round((microtime(true)-$t)*1000);$checks[]=$row;
        }

        $webRows=[];$webDiag=[];$webError='';
        try{
            $webRows=WebSearchClient::search('site:upwork.com/freelance-jobs wordpress OR php OR api project',5);
            $webDiag=WebSearchClient::lastDiagnostics();
        }catch(Throwable $e){$webError=pb_substr(Security::redactSecrets($e->getMessage(),180),0,180);$webDiag=WebSearchClient::lastDiagnostics();}
        $ok=$usableLinks>0||count($webRows)>0;
        $out=[
            'state'=>$ok?'verified':'failed',
            'code'=>$ok?'project_discovery_ok':'project_discovery_unavailable',
            'direct_sources'=>$checks,
            'direct_project_links'=>$usableLinks,
            'web_search_count'=>count($webRows),
            'web_search_samples'=>array_slice(array_values(array_map(static fn($x)=>['title'=>(string)($x['title']??''),'url'=>(string)($x['url']??''),'source'=>(string)($x['source']??'')],$webRows)),0,5),
            'web_search_diagnostics'=>$webDiag,
            'web_search_error'=>$webError,
            'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
            'tested_at'=>now_utc(),
        ];
        try{put_setting('opportunities.last_search_diagnostics_json',j($out));put_setting('opportunities.last_search_diagnostics_at',now_utc());}catch(Throwable){}
        try{db()->prepare('INSERT INTO connection_tests(provider_key,state,result_code,details_json) VALUES (?,?,?,?)')->execute(['web_search',$out['state'],$out['code'],j($out)]);}catch(Throwable){}
        try{Notifications::add($ok?'success':'warning','connections',$ok?'نجح تشخيص بحث وليد':'فشل تشخيص بحث وليد',$ok?'تم العثور على روابط مشاريع فردية من مصدر عام واحد على الأقل.':'المصادر العامة ومحرك البحث لم يعيدا روابط مشاريع فردية قابلة للاستخدام.','provider','web_search');}catch(Throwable){}
        return $out;
    }
}
