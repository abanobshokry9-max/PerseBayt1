<?php
declare(strict_types=1);
final class OpportunitySearchService {
    private static function phaseLabel(string $phase):string{return match($phase){'starting'=>'بدء البحث','searching'=>'البحث في المصادر','expanding'=>'توسيع صفحات المشاريع','verifying'=>'التحقق من صفحات المشاريع','evaluating'=>'التحليل والتسعير بالعربية','saving'=>'حفظ وترتيب الفرص','completed'=>'اكتمل البحث',default=>$phase};}
    private static function progress(int $runId,int $taskId,string $phase,array $extra=[]):void{
        try{Worker::heartbeatTask($taskId);}catch(Throwable){}
        $payload=array_merge(['phase'=>$phase,'phase_label'=>self::phaseLabel($phase),'progress_at'=>now_utc()],$extra);
        try{
            $q=db()->prepare("SELECT summary_json FROM opportunity_search_runs WHERE id=? LIMIT 1");$q->execute([$runId]);$prev=json_decode((string)($q->fetchColumn()?:'{}'),true);if(!is_array($prev))$prev=[];
            // Keep diagnostics from previous phases so the live queue never hides the actual provider error.
            $payload=array_merge($prev,$payload);
            db()->prepare("UPDATE opportunity_search_runs SET summary_json=? WHERE id=? AND state='running'")->execute([j($payload),$runId]);
        }catch(Throwable){}
    }
    private static function timeLeft(float $deadline):float{return max(0.0,$deadline-microtime(true));}
    private static function candidatePriority(array $row):int{
        $score=0;$class=OpportunitySourceRules::classify((string)($row['url']??''));$detail=(string)($row['_detail_status']??'');
        if($class['trusted']&&$class['kind']==='detail')$score+=95;elseif($class['trusted'])$score+=25;else $score-=15;
        if($detail==='verified')$score+=110;elseif(in_array($detail,['blocked_403','login_or_challenge'],true))$score+=10;
        $money=OpportunityMoney::evidence($row);if(($money['budget_min']??null)!==null)$score+=70;elseif(($money['currency']??'UNK')!=='UNK')$score+=20;
        if(OpportunitySourceRules::normalizePublishedAt((string)($row['published_at']??''))!==null)$score+=30;
        $e=self::buyerIntentEvidence($row);if(!empty($e['buyer_request']))$score+=55;if(!empty($e['project_terms']))$score+=35;if(!empty($e['web_service']))$score+=30;
        $text=(string)($row['title']??'').' '.(string)($row['snippet']??'');
        if(self::genericEditorial($text))$score-=150;if(self::prohibitedAutomationRisk($text))$score-=180;
        if(preg_match('/(api integration|rest api|crm|n8n|zapier|make\.com|automation|wordpress|woocommerce|bug fix|fix|dashboard|admin panel|landing page|video editing|motion graphics|short video|reels|logo design|graphic design|social media design|thumbnail|image generation|content creation|social media management|account setup|email setup|excel|spreadsheet|data cleanup|تكامل|ربط api|أتمتة|ووردبريس|إصلاح|لوحة تحكم|صفحة هبوط|مونتاج|تحرير فيديو|فيديو قصير|ريلز|موشن جرافيك|تصميم لوجو|تصميم شعار|تصميم سوشيال|صور|إنشاء صور|ادارة سوشيال|إدارة سوشيال|إنشاء محتوى|انشاء محتوى|إنشاء حساب|انشاء حساب|إعداد بريد|اكسل|إكسل|تنظيف بيانات)/iu',$text))$score+=35;
        if(preg_match('/(full mobile app|complete mobile app|social network|marketplace platform|منصة ضخمة|تطبيق كامل)/iu',$text)&&($money['budget_min']??null)===null)$score-=35;
        return $score;
    }
    private static function prioritizeCandidates(array $rows,int $limit):array{
        usort($rows,static fn(array $a,array $b)=>self::candidatePriority($b)<=>self::candidatePriority($a));
        return array_slice($rows,0,max(1,$limit));
    }
    private static function decodeJsonText(string $text):array{
        $text=trim($text);$d=json_decode($text,true);if(is_array($d))return $d;
        if(preg_match('/```(?:json)?\\s*(\\{.*\\}|\\[.*\\])\\s*```/su',$text,$m)){$d=json_decode($m[1],true);if(is_array($d))return $d;}
        if(preg_match('/(\\{.*\\})/su',$text,$m)){$d=json_decode($m[1],true);if(is_array($d))return $d;}
        throw new RuntimeException('ai_invalid_json');
    }
    private static function needsArabicRewrite(string $title):bool{
        $title=trim($title);if($title==='')return true;
        preg_match_all('/[\x{0600}-\x{06FF}]/u',$title,$ar);preg_match_all('/[A-Za-z]/u',$title,$lat);
        $a=count($ar[0]);$l=count($lat[0]);if(preg_match('/\b(?:backend|developer|development|website|management|orders?|products?|using|needed|required|high profit|mobile app|full stack)\b/i',$title))return true;return $a<6||($l>18&&$a<max(8,(int)round($l*.45)));
    }
    private static function arabicTitleFallback(string $title,string $type=''):string{
        $raw=trim($title);$low=pb_strtolower($raw.' '.$type);
        $tech=[];foreach(['Laravel','WordPress','WooCommerce','REST API','API','ASP.NET Core','PHP','n8n','Make.com','Zapier','CRM','RAG','LLM','Flutter','React','Next.js','Node.js','Python'] as $t)if(stripos($raw,$t)!==false||stripos($type,$t)!==false)$tech[]=$t;
        $subject=match(true){
            preg_match('/(landing page|صفحة هبوط)/u',$low)=>'صفحة هبوط',
            preg_match('/(wordpress|woocommerce|ووردبريس|ووكومرس)/u',$low)=>'موقع ووردبريس',
            preg_match('/(api|backend|back-end|laravel|asp\.net|باك اند|خلفية)/u',$low)=>'واجهة خلفية وربط API',
            preg_match('/(automation|n8n|zapier|make\.com|أتمتة|اتمتة)/u',$low)=>'نظام أتمتة وربط خدمات',
            preg_match('/(chatbot|ai bot|شات بوت|ذكاء اصطناعي|rag|llm)/u',$low)=>'حل ذكاء اصطناعي وشات بوت',
            preg_match('/(video editing|video production|motion graphics|reels|short video|مونتاج|تحرير فيديو|إنتاج فيديو|انتاج فيديو|موشن جرافيك|ريلز)/u',$low)=>'إنتاج وتحرير فيديو',
            preg_match('/(logo design|graphic design|social media design|thumbnail|image generation|banner design|تصميم شعار|تصميم لوجو|جرافيك|تصميم سوشيال|صورة مصغرة|إنشاء صور|انشاء صور|بانر)/u',$low)=>'تصميم صور وهوية بصرية',
            preg_match('/(social media management|content creation|content calendar|ادارة سوشيال|إدارة سوشيال|إدارة محتوى|ادارة محتوى|إنشاء محتوى|انشاء محتوى)/u',$low)=>'إدارة محتوى وسوشيال ميديا',
            preg_match('/(account setup|create account|email setup|business profile|إنشاء حساب|انشاء حساب|إعداد حساب|اعداد حساب|إنشاء بريد|انشاء بريد)/u',$low)=>'إعداد حسابات وخدمات رقمية',
            preg_match('/(excel|spreadsheet|google sheets|data cleanup|data entry|اكسل|إكسل|جداول بيانات|تنظيف بيانات|إدخال بيانات|ادخال بيانات)/u',$low)=>'تنظيم ومعالجة بيانات',
            preg_match('/(ecommerce|e-commerce|store|متجر)/u',$low)=>'متجر إلكتروني',
            preg_match('/(mobile app|flutter|android|ios|تطبيق جوال)/u',$low)=>'تطبيق جوال',
            preg_match('/(dashboard|admin panel|لوحة تحكم)/u',$low)=>'لوحة تحكم',
            preg_match('/(website|web platform|web app|موقع|منصة ويب)/u',$low)=>'موقع أو منصة ويب',
            default=>'مشروع برمجي',
        };
        $action=(bool)preg_match('/(fix|repair|bug|troubleshoot|إصلاح|اصلاح|مشكلة)/u',$low)?'إصلاح وتطوير ':'تطوير ';
        $suffix=$tech?' باستخدام '.implode(' و',array_values(array_unique($tech))):'';
        if(preg_match('/(developer|مطور)/u',$low)&&preg_match('/(mobile app|تطبيق جوال)/u',$low)&&str_contains($low,'laravel'))return 'مطلوب مطور Laravel للواجهة الخلفية لتطبيق جوال';
        if(str_contains($low,'rest api')&&str_contains($low,'product')&&str_contains($low,'order'))return 'تطوير واجهة REST API لإدارة المنتجات والطلبات'.($suffix!==''?$suffix:'');
        return pb_substr($action.$subject.$suffix,0,300);
    }
    private static function genericEditorial(string $text):bool{
        $t=pb_strtolower($text);
        return (bool)preg_match('/(high profit margin|profit margin products|top\s+\d+|\d+\s+(?:best|profitable|products?|ideas?)|for\s+20[2-9][0-9]|guide|tutorial|how to|what is|tips|best practices|article|blog|news|دليل|كيفية|أفضل \d+|نصائح|مقال|أخبار|منتجات مربحة|أفكار مشاريع)/u',$t);
    }
    private static function prohibitedAutomationRisk(string $text):bool{
        $t=pb_strtolower($text);
        return (bool)preg_match('/(bypass\s+(?:captcha|cloudflare|anti.?bot|security)|evade\s+(?:ban|blocking)|avoid\s+(?:ban|blocking)|captcha\s+bypass|anti.?bot\s+bypass|تقليل احتمالية الحظر|تجاوز (?:الكابتشا|الحماية|أنظمة الحماية)|تخطي (?:الكابتشا|الحماية)|التحايل على.*حماية)/u',$t);
    }
    public static function reconcileRuns(int $limit=30):int{
        $limit=max(1,min(100,$limit));$fixed=0;
        $rows=db()->query("SELECT r.id,r.task_id,r.state,r.created_at,t.status task_status FROM opportunity_search_runs r LEFT JOIN tasks t ON t.id=r.task_id WHERE r.state IN ('queued','running') ORDER BY r.id ASC LIMIT ".$limit)->fetchAll();
        foreach($rows as $r){
            $rid=(int)$r['id'];$taskId=(int)($r['task_id']??0);
            $activeJob=false;
            if($taskId>0){$q=db()->prepare("SELECT COUNT(*) FROM jobs WHERE task_id=? AND kind='walid_opportunity_search' AND state IN ('queued','running','waiting')");$q->execute([$taskId]);$activeJob=(int)$q->fetchColumn()>0;}
            if($activeJob)continue;
            $taskStatus=(string)($r['task_status']??'');
            if($taskId>0&&!in_array($taskStatus,['completed','cancelled','failed'],true)){
                try{if($taskStatus==='working'){db()->prepare("UPDATE tasks SET status='waiting' WHERE id=? AND status='working'")->execute([$taskId]);TaskService::event($taskId,'system','walid_reconciler','orphaned_run_recovered','working','waiting',['run_id'=>$rid]);}$taskStatus=(string)TaskService::get($taskId)['status'];if(TaskService::queueIfReady($taskId))continue;}catch(Throwable $e){error_log('ELMETR walid run requeue: '.Security::redactSecrets($e->getMessage(),160));}
            }
            if($taskStatus==='completed'){$state='completed';$summary='task_completed_without_active_job';}
            elseif(in_array($taskStatus,['failed','cancelled'],true)){$state='failed';$summary='task_'.$taskStatus;}
            else{
                $created=utc_ts((string)($r['created_at']??''));if($created!==false&&$created>time()-1800)continue;
                $state='failed';$summary='orphaned_search_run';
            }
            db()->prepare("UPDATE opportunity_search_runs SET state=?,completed_at=COALESCE(completed_at,NOW()),summary_json=CASE WHEN summary_json IS NULL OR summary_json='' THEN ? ELSE summary_json END WHERE id=? AND state IN ('queued','running')")->execute([$state,j(['reconciled'=>true,'reason'=>$summary]),$rid]);$fixed++;
        }
        return $fixed;
    }
    public static function runTask(int $taskId):array{
        $task=TaskService::start($taskId);$walid=AgentService::assertRunnable(AgentService::bySlug('walid'));AgentService::requireTool((int)$walid['id'],'opportunity_hunter');Permissions::requireAgent((int)$walid['id'],'opportunities.hunt');
        $ctx=json_decode((string)($task['context_json']??'{}'),true)?:[];$days=max(1,min(60,(int)($ctx['lookback_days']??setting('opportunities.lookback_days','15'))));$target=max(5,min(100,(int)($ctx['raw_target']??setting('opportunities.default_raw_target','30'))));$runId=(int)($ctx['search_run_id']??0);
        if(!$runId){db()->prepare("INSERT INTO opportunity_search_runs(agent_id,task_id,state,date_from,date_to,requested_limit,queries_json) VALUES (?,?,'queued',DATE_SUB(NOW(),INTERVAL ? DAY),NOW(),?,?)")->execute([(int)$walid['id'],$taskId,$days,$target,j(self::queries($ctx,$days))]);$runId=(int)db()->lastInsertId();}
        db()->prepare("UPDATE opportunity_search_runs SET state='running',started_at=COALESCE(started_at,NOW()) WHERE id=?")->execute([$runId]);
        $warnings=[];$candidates=[];$sourcesUsed=[];$searchDiagnostics=[];$searchReachable=false;$searchRawResults=0;$sourceReachable=false;$preGateCount=0;$queries=array_slice(self::queries($ctx,$days),0,max(6,min(16,(int)setting('opportunities.max_queries_per_run','12'))));$searchEstimate=max(0,(float)setting('budgets.search_estimated_request_usd','0.005'))*max(1,count($queries));$budgetReservation=0;try{$budgetReservation=AiBudgetService::reserve((int)$walid['id'],'search',$searchEstimate,'opportunity-search-'.$runId,20);}catch(Throwable $be){throw $be;}$cutoff=time()-$days*86400;$overallDeadline=microtime(true)+max(75,min(210,(int)setting('opportunities.run_time_budget_seconds','150')));$searchDeadline=min($overallDeadline-45,microtime(true)+max(25,min(75,(int)setting('opportunities.search_time_budget_seconds','55'))));self::progress($runId,$taskId,'starting',['query_count'=>count($queries),'target'=>$target]);
        try{
            // Always start with a small set of public marketplace listing seeds. They are not stored as
            // opportunities; expandTrustedListings() must turn them into individual project URLs first.
            // This gives Walid a free deterministic discovery path even when no paid search API key exists.
            if(setting('opportunities.marketplace_seed_discovery','1')==='1'){
                $seedRows=WebSearchClient::marketplaceSeeds();
                foreach($seedRows as $r){$r['query']='builtin_marketplace_seed';$r['auth_requirement']='public';$candidates[]=self::candidate($r);}
                if($seedRows){$sourcesUsed['builtin:marketplace_seeds']=true;self::progress($runId,$taskId,'searching',['marketplace_seeds'=>count($seedRows),'candidates'=>count($candidates),'seconds_left'=>(int)self::timeLeft($overallDeadline)]);}
            }
            if(WebSearchClient::configured() && AgentService::tool((int)$walid['id'],'web_search')){
                foreach($queries as $queryIndex=>$query){
                    TaskService::assertContinuable($taskId);self::progress($runId,$taskId,'searching',['query_index'=>$queryIndex+1,'query_count'=>count($queries),'candidates'=>count($candidates),'seconds_left'=>(int)self::timeLeft($overallDeadline)]);if(microtime(true)>=$searchDeadline){$warnings[]=['area'=>'search','error'=>'search_time_budget_reached'];break;}if(count($candidates)>=$target*2)break;
                    try{
                        $rows=AiCapabilityRouter::webSearch((int)$walid['id'],$query,min(10,max(5,(int)ceil($target/max(1,count($queries))))),false);
                        $diag=WebSearchClient::lastDiagnostics();$searchRawResults+=count($rows);
                        foreach($diag as $d){
                            $provider=(string)($d['provider']??'unknown');$state=(string)($d['state']??'unknown');
                            $sourcesUsed['search:'.$provider]=true;$searchDiagnostics[]=['query'=>$query]+$d;
                            if(in_array($state,['ok','empty'],true))$searchReachable=true;
                            if($state==='failed')$warnings[]=['area'=>'web_search_provider','query'=>$query,'provider'=>$provider,'error'=>(string)($d['error']??'provider_failed')];
                        }
                        foreach($rows as $r){$r['query']=$query;$r['auth_requirement']='public';$candidates[]=self::candidate($r);}
                        self::progress($runId,$taskId,'searching',['query_index'=>$queryIndex+1,'query_count'=>count($queries),'candidates'=>count($candidates),'provider_results'=>count($rows),'last_search_diagnostics'=>array_slice($diag,-4),'search_raw_total'=>$searchRawResults,'seconds_left'=>(int)self::timeLeft($overallDeadline)]);
                    }catch(Throwable $e){$warnings[]=['area'=>'web_search','query'=>$query,'error'=>pb_substr($e->getMessage(),0,220)];}
                }
            }else{$warnings[]=['area'=>'web_search','error'=>WebSearchClient::configured()?'agent_tool_disabled:web_search':'web_search_provider_not_configured'];}
            foreach(self::sources() as $sourceIndex=>$source){TaskService::assertContinuable($taskId);self::progress($runId,$taskId,'searching',['source_index'=>$sourceIndex+1,'candidates'=>count($candidates),'seconds_left'=>(int)self::timeLeft($overallDeadline)]);if(microtime(true)>=$searchDeadline){$warnings[]=['area'=>'source','error'=>'search_time_budget_reached'];break;}try{if((string)$source['auth_requirement']!=='public'){$code=(string)$source['auth_requirement'];$warnings[]=['area'=>'source','source'=>$source['name'],'error'=>$code];db()->prepare("UPDATE opportunity_sources SET last_run_at=NOW(),last_state='warning',last_error=? WHERE id=?")->execute([$code,(int)$source['id']]);continue;}$rows=self::sourceCandidates($source);foreach($rows as $r){$r['source']=$source['name'];$r['auth_requirement']='public';$r['source_id']=$source['id'];$candidates[]=self::candidate($r);}$sourcesUsed['source:'.$source['source_key']]=true;$sourceReachable=true;db()->prepare("UPDATE opportunity_sources SET last_run_at=NOW(),last_state='ok',last_error=NULL WHERE id=?")->execute([(int)$source['id']]);}catch(Throwable $e){$warnings[]=['area'=>'source','source'=>$source['name'],'error'=>pb_substr($e->getMessage(),0,220)];db()->prepare("UPDATE opportunity_sources SET last_run_at=NOW(),last_state='failed',last_error=? WHERE id=?")->execute([pb_substr($e->getMessage(),0,300),(int)$source['id']]);}}
            // Search engines often return category/listing pages. Expand trusted platform listings into individual project URLs first,
            // then drop the listing itself so it can never be stored as an opportunity card.
            self::progress($runId,$taskId,'expanding',['candidates'=>count($candidates),'seconds_left'=>(int)self::timeLeft($overallDeadline)]);$candidates=self::expandTrustedListings($candidates,$target,$warnings,$overallDeadline,$taskId,$runId);
            $candidates=self::dedupeRaw($candidates);$preGateCount=count($candidates);
            // Buyer-intent gate FIRST: never spend fetch/AI capacity on articles, homepages, seller content or platform listing pages.
            $candidates=array_values(array_filter($candidates,[self::class,'isLikelyCandidate']));
            if(count($candidates)>$target*2)$candidates=array_slice($candidates,0,$target*2);
            self::progress($runId,$taskId,'verifying',['candidates'=>count($candidates),'seconds_left'=>(int)self::timeLeft($overallDeadline)]);$candidates=self::enrichPublicCandidates($candidates,$target,$warnings,$overallDeadline,$taskId,$runId);
            // Re-check after reading the detail page; enrichment can prove that a promising snippet is not a real project.
            $candidates=array_values(array_filter($candidates,[self::class,'isLikelyCandidate']));
            $candidatePool=count($candidates);$evaluationLimit=max(4,min(10,(int)setting('opportunities.evaluation_limit','8')));$candidates=self::prioritizeCandidates($candidates,$evaluationLimit);$rawFound=count($candidates);
            if($rawFound===0){
                $reachable=$searchReachable||$sourceReachable;
                // Search-engine rows alone are not enough to call this a successful no-match run.
                // We need at least one individual candidate after listing expansion; otherwise discovery itself failed.
                $verifiedSearchData=$preGateCount>0;
                $zeroSummary=['raw_found'=>0,'qualified'=>0,'needs_review'=>0,'rejected'=>0,'duplicates'=>0,'saved'=>0,'shortlisted'=>0,'lookback_days'=>$days,'provider'=>WebSearchClient::provider(),'result_state'=>($reachable&&$verifiedSearchData)?'no_matches':'search_unavailable','raw_before_buyer_gate'=>$preGateCount,'rejected_by_buyer_gate'=>$preGateCount,'sources'=>array_keys($sourcesUsed),'search_diagnostics'=>array_slice($searchDiagnostics,0,80),'warnings'=>array_slice($warnings,0,30)];
                if($reachable&&$verifiedSearchData){
                    db()->prepare("UPDATE opportunity_search_runs SET state='completed',raw_found=0,qualified_found=0,review_found=0,rejected_found=0,duplicates_found=0,warning_count=?,sources_json=?,summary_json=?,completed_at=NOW() WHERE id=?")->execute([count($warnings),j(array_keys($sourcesUsed)),j($zeroSummary),$runId]);
                    TaskEvidence::add($taskId,'opportunity_search','دليل بحث وليد — لا توجد نتائج مطابقة',['run_id'=>$runId]+$zeroSummary,'verified',(int)$walid['id']);
                    try{AiBudgetService::record((int)$walid['id'],WebSearchClient::provider(),WebSearchClient::provider(),'search',0,0,['run_id'=>$runId,'queries'=>count($queries),'raw'=>0,'reservation_id'=>$budgetReservation],$taskId,null);}catch(Throwable){}
                    TaskService::complete($taskId,['search_run_id'=>$runId,'stats'=>$zeroSummary],'اكتمل البحث بنجاح ولم توجد فرص تطابق شروط Buyer Intent في هذه الدورة.');
                    Notifications::add('info','opportunities','اكتمل بحث وليد بدون فرص مطابقة','البحث عمل بصورة سليمة، لكن لا توجد فرص اجتازت شروط الشراء والتحقق في هذه الدورة.','opportunity_search_run',(string)$runId);
                    return $zeroSummary;
                }
                $warnings[]=['area'=>'search','error'=>'search_unavailable','provider'=>WebSearchClient::provider(),'diagnostics'=>array_slice($searchDiagnostics,0,40)];
                $zeroSummary['warnings']=array_slice($warnings,0,30);
                db()->prepare("UPDATE opportunity_search_runs SET state='failed',raw_found=0,warning_count=?,sources_json=?,summary_json=?,completed_at=NOW() WHERE id=?")->execute([count($warnings),j(array_keys($sourcesUsed)),j($zeroSummary),$runId]);
                throw new RuntimeException('walid_search_unavailable');
            }
            if($candidatePool>$rawFound)$warnings[]=['area'=>'evaluation','error'=>'candidate_pool_trimmed_for_runtime','candidate_pool'=>$candidatePool,'evaluated'=>$rawFound];db()->prepare("UPDATE opportunity_search_runs SET raw_found=?,warning_count=?,sources_json=? WHERE id=?")->execute([$rawFound,count($warnings),j(array_keys($sourcesUsed)),$runId]);
            self::progress($runId,$taskId,'evaluating',['candidate_pool'=>$candidatePool,'evaluating'=>$rawFound,'seconds_left'=>(int)self::timeLeft($overallDeadline)]);$rawIds=[];foreach($candidates as $i=>$base){$rawIds[$i]=self::storeRaw($runId,$base);}
            $stats=['qualified'=>0,'review'=>0,'rejected'=>0,'duplicate'=>0,'saved'=>0];$offset=0;$batches=array_chunk($candidates,3);
            foreach($batches as $batchIndex=>$batch){TaskService::assertContinuable($taskId);self::progress($runId,$taskId,'evaluating',['batch'=>$batchIndex+1,'batches'=>count($batches),'processed'=>$offset,'total'=>$rawFound,'seconds_left'=>(int)self::timeLeft($overallDeadline)]);$evaluated=self::timeLeft($overallDeadline)<20?self::fallbackEvaluate($batch):self::evaluateBatch($walid,$batch,$days,$warnings);foreach($evaluated as $idx=>$ev){$base=$batch[$idx]??null;if(!$base)continue;$rawId=(int)($rawIds[$offset+$idx]??self::storeRaw($runId,$base));$saved=self::persistEvaluation($ev,$base,$cutoff,(int)$walid['id']);if($saved['duplicate']){$stats['duplicate']++;db()->prepare("UPDATE opportunity_raw_items SET processing_state='duplicate',opportunity_id=? WHERE id=?")->execute([$saved['opportunity_id']?:null,$rawId]);continue;}$stats[$saved['fit_status']]++;$stats['saved']++;db()->prepare("UPDATE opportunity_raw_items SET processing_state=?,opportunity_id=? WHERE id=?")->execute([$saved['fit_status']==='rejected'?'rejected':'processed',$saved['opportunity_id'],$rawId]);}$offset+=count($batch);}
            self::progress($runId,$taskId,'saving',['saved'=>$stats['saved'],'seconds_left'=>(int)self::timeLeft($overallDeadline)]);$shortlisted=self::applyShortlist($runId);$state=$warnings?'partial':'completed';$summary=['raw_found'=>$rawFound,'candidate_pool'=>$candidatePool,'qualified'=>$stats['qualified'],'needs_review'=>$stats['review'],'rejected'=>$stats['rejected'],'duplicates'=>$stats['duplicate'],'saved'=>$stats['saved'],'shortlisted'=>$shortlisted,'lookback_days'=>$days,'result_state'=>'completed','raw_before_buyer_gate'=>$preGateCount,'rejected_by_buyer_gate'=>max(0,$preGateCount-$rawFound),'sources'=>array_keys($sourcesUsed),'search_diagnostics'=>array_slice($searchDiagnostics,0,80),'warnings'=>array_slice($warnings,0,30)];db()->prepare("UPDATE opportunity_search_runs SET state=?,raw_found=?,qualified_found=?,review_found=?,rejected_found=?,duplicates_found=?,warning_count=?,sources_json=?,summary_json=?,completed_at=NOW() WHERE id=?")->execute([$state,$rawFound,$stats['qualified'],$stats['review'],$stats['rejected'],$stats['duplicate'],count($warnings),j(array_keys($sourcesUsed)),j($summary),$runId]);
            TaskEvidence::add($taskId,'opportunity_search','دليل بحث وليد',['run_id'=>$runId]+$summary,'verified',(int)$walid['id']);AgentLearningService::propose((int)$walid['id'],'search_preference','في بحث #'.$runId.' تم العثور على '.$stats['qualified'].' فرص مؤهلة و'.$stats['review'].' تحتاج مراجعة من '.$rawFound.' نتيجة خام.',null,$taskId,65);try{AiBudgetService::record((int)$walid['id'],WebSearchClient::provider(),WebSearchClient::provider(),'search',0,0,['run_id'=>$runId,'queries'=>count($queries),'raw'=>$rawFound,'reservation_id'=>$budgetReservation],$taskId,null);}catch(Throwable){}TaskService::complete($taskId,['search_run_id'=>$runId,'stats'=>$summary],'اكتمل بحث وليد وحفظت النتائج بأدلة المصدر.');Notifications::add($warnings?'warning':'success','opportunities','اكتمل بحث وليد','تم فحص '.$rawFound.' نتيجة وحفظ '.$stats['saved'].' فرصة. المؤهل: '.$stats['qualified'].'، يحتاج مراجعة: '.$stats['review'].'.','opportunity_search_run',(string)$runId);return $summary;
        }catch(Throwable $e){try{if(!empty($budgetReservation))AiBudgetService::releaseReservation($budgetReservation);}catch(Throwable){}db()->prepare("UPDATE opportunity_search_runs SET state='failed',warning_count=?,summary_json=?,completed_at=NOW() WHERE id=?")->execute([count($warnings)+1,j(['error'=>pb_substr($e->getMessage(),0,500),'warnings'=>$warnings]),$runId]);throw $e;}
    }
    private static function queries(array $ctx,?int $lookbackDays=null):array{
        if(!empty($ctx['queries'])&&is_array($ctx['queries']))$requested=array_values(array_filter(array_map('trim',$ctx['queries'])));else $requested=[];
        $saved=json_decode((string)setting('opportunities.search_queries_json',''),true);$saved=is_array($saved)?array_values(array_filter(array_map('trim',$saved))):[];
        // Provider-neutral queries. Freshness is verified from each detail page instead of relying on Google-only `after:` syntax.
        $defaults=[
            // Balanced across the actual team capabilities. Owner-entered queries still come first.
            'site:nafezly.com/project/ مطلوب API أتمتة n8n واتساب CRM ووردبريس إصلاح موقع ميزانية',
            'site:mostaql.com/project/ مطلوب API تكامل أتمتة ووردبريس لوحة تحكم إصلاح موقع ميزانية',
            'site:upwork.com/freelance-jobs/apply/ "fixed price" WordPress API integration CRM automation n8n PHP',
            'مصر السعودية الإمارات مطلوب تصميم شعار هوية بصرية صور سوشيال ميديا مشروع ميزانية',
            'looking for video editor reels motion graphics fixed price freelance project',
            'مطلوب إدارة سوشيال ميديا إنشاء محتوى وجدولة منشورات مشروع ميزانية',
            'looking for spreadsheet Excel Google Sheets data cleanup automation fixed price project',
            'مطلوب إنشاء حساب إعداد بريد business profile خدمة رقمية مقابل ميزانية',
            'مطلوب شات بوت خدمة عملاء AI RAG واتساب CRM مشروع ميزانية',
            'looking for graphic designer logo social media posts thumbnails fixed price project',
            'site:freelancer.com/projects/ API integration WordPress PHP automation fixed budget',
            'مصر السعودية الإمارات مطلوب مونتاج فيديو reels motion graphics فيديو إعلاني ميزانية',
            'looking for account setup email business profile digital service fixed price project',
            'مطلوب Excel Google Sheets تنظيف بيانات إدخال بيانات أتمتة تقرير ميزانية',
            'need AI chatbot RAG customer support automation fixed price project',
            'مطلوب إصلاح موقع ووردبريس PHP API لوحة تحكم مشروع ميزانية',
            'looking for PHP Laravel backend bug fix dashboard API fixed price project',
            'request for proposal website API integration automation dashboard budget',
            'recherche développeur API WordPress automatisation CRM projet budget',
        ];
        // Owner-entered queries come first, then the v7.0 discovery profile. Legacy saved queries are only supplemental and cannot crowd out the defaults.
        $learned=setting('opportunities.closed_loop_learning','1')==='1'?WalidLearningService::learnedQueries(6):[];$all=array_merge($requested,$learned,$defaults,$saved);$out=[];$seen=[];$max=max(6,min(24,(int)setting('opportunities.max_queries_per_run','16')));
        foreach($all as $q){$q=trim((string)$q);if($q==='')continue;$k=pb_strtolower($q);if(isset($seen[$k]))continue;$seen[$k]=true;$out[]=$q;if(count($out)>=$max)break;}
        return $out;
    }

    private static function sources():array{return db()->query("SELECT * FROM opportunity_sources WHERE enabled=1 AND source_type<>'search_api' AND base_url IS NOT NULL AND base_url<>'' ORDER BY priority DESC,id ASC LIMIT 30")->fetchAll();}
    private static function candidate(array $r):array{return ['title'=>trim((string)($r['title']??'')),'url'=>trim((string)($r['url']??$r['source_url']??'')),'snippet'=>trim(strip_tags((string)($r['snippet']??$r['description']??$r['raw_text']??''))),'published_at'=>trim((string)($r['published_at']??'')),'source'=>trim((string)($r['source']??'ويب عام'))?:'ويب عام','source_item_id'=>trim((string)($r['source_item_id']??'')),'auth_requirement'=>(string)($r['auth_requirement']??'public'),'source_id'=>(int)($r['source_id']??0),'query'=>(string)($r['query']??'')];}
    private static function sourceCandidates(array $source):array{
        if((string)$source['auth_requirement']!=='public')throw new RuntimeException((string)$source['auth_requirement']);
        $probe=HttpClient::probe((string)$source['base_url'],max(4,min(10,(int)setting('opportunities.source_timeout_seconds','6'))),450000);if((int)$probe['status']<200||(int)$probe['status']>=300)throw new RuntimeException('source_http_'.$probe['status']);$body=(string)$probe['body'];$type=(string)$source['source_type'];
        if($type==='rss'){$xml=@simplexml_load_string($body);if(!$xml)throw new RuntimeException('source_rss_invalid');$out=[];$items=$xml->channel->item??$xml->entry??[];foreach($items as $item){$out[]=['title'=>(string)($item->title??''),'url'=>(string)($item->link['href']??$item->link??''),'snippet'=>(string)($item->description??$item->summary??''),'published_at'=>(string)($item->pubDate??$item->published??$item->updated??''),'source_item_id'=>(string)($item->guid??$item->id??'')];if(count($out)>=30)break;}return $out;}
        if($type==='json'){$data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('source_json_invalid');$rows=$data['items']??$data['results']??$data['data']??$data;$out=[];foreach((array)$rows as $x){if(!is_array($x))continue;$out[]=['title'=>(string)($x['title']??$x['name']??''),'url'=>(string)($x['url']??$x['link']??''),'snippet'=>(string)($x['description']??$x['summary']??$x['content']??''),'published_at'=>(string)($x['published_at']??$x['date']??''),'source_item_id'=>(string)($x['id']??'')];if(count($out)>=30)break;}return $out;}
        $finalUrl=(string)($probe['final_url']??$source['base_url']);
        if($type==='web'){
            // Prefer deterministic link extraction while the hrefs still exist. The old path converted HTML
            // to plain text first, which destroyed project URLs and forced AI to guess them.
            $links=OpportunitySourceRules::projectLinks($body,$finalUrl,30);
            if($links)return $links;
        }
        $text=self::htmlText($body);return self::extractFromPage($source,$text,$finalUrl);
    }
    private static function htmlText(string $html):string{$html=preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is',' ',$html);$text=html_entity_decode(strip_tags((string)$html),ENT_QUOTES|ENT_HTML5,'UTF-8');$text=preg_replace('/\s+/u',' ',(string)$text);return pb_substr(trim((string)$text),0,120000);}
    private static function extractFromPage(array $source,string $text,string $url):array{
        $walid=AgentService::bySlug('walid');$schema=['type'=>'object','additionalProperties'=>false,'properties'=>['items'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>['title'=>['type'=>'string'],'url'=>['type'=>'string'],'description'=>['type'=>'string'],'published_at'=>['type'=>'string'],'source_item_id'=>['type'=>'string']],'required'=>['title','url','description','published_at','source_item_id']]]],'required'=>['items']];$r=AiGateway::json($walid,'استخرج فقط طلبات مشاريع حقيقية من النص. لا تعتبر عروض البائعين فرصًا. إذا الرابط النسبي غير واضح استخدم رابط الصفحة الأصلية. لا تخترع تاريخًا أو بيانات غير موجودة.',[['role'=>'user','content'=>"SOURCE: {$source['name']}\nPAGE_URL: {$url}\nPAGE_TEXT:\n".$text]],$schema,'walid_source_extract',5000);$out=[];foreach((array)$r['data']['items'] as $x)$out[]=['title'=>$x['title'],'url'=>trim((string)$x['url'])?:$url,'snippet'=>$x['description'],'published_at'=>$x['published_at'],'source_item_id'=>$x['source_item_id']];return $out;
    }
    private static function isLikelyCandidate(array $row):bool{
        $url=trim((string)($row['url']??''));$title=trim((string)($row['title']??''));$snippet=trim((string)($row['snippet']??''));if($title===''||$url==='')return false;
        $host=pb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
        foreach(['canva.com','canalplus.com','wix.com','squarespace.com','wikipedia.org','youtube.com','medium.com','britannica.com','shopify.com'] as $blocked)if($host===$blocked||str_ends_with($host,'.'.$blocked))return false;
        $class=OpportunitySourceRules::classify($url);if($class['kind']==='blocked')return false;if($class['trusted']&&$class['kind']!=='detail')return false;
        $text=pb_strtolower($title.' '.$snippet);
        if(self::genericEditorial($text)||self::prohibitedAutomationRisk($text))return false;
        $editorial=(bool)preg_match('/(guide|tutorial|how to|definition|history of|what is|tips for|examples of|best practices|learn how|complete guide|gu[ií]a|d[eé]finition|histoire|conseils|article|blog|news|شرح|دليل|تعريف|ما هو|كيفية|أفضل طرق|نصائح|مقال|أخبار)/u',$text);
        $explicitRequest=(bool)preg_match('/(looking for|need(?:ed)?|seeking|hiring|wanted|required|we need|i need|project brief|request for proposal|rfp|rfq|مطلوب|نبحث عن|أبحث عن|ابحث عن|احتاج|أحتاج|محتاج|أريد|اريد|يرجى من المتقدمين|تقديم عرض|recherche|cherche|besoin)/u',$text);
        $buyerIntent=$explicitRequest||(bool)preg_match('/(project|freelance|contract|budget|proposal|مشروع|ميزانية|عرض سعر|تنفيذ|projet|budget|devis)/u',$text);
        $service=(bool)preg_match('/(website|web site|web design|web development|web developer|wordpress|woocommerce|landing page|php|laravel|frontend|front-end|backend|html|css|javascript|shopify|ecommerce|e-commerce|redesign|api integration|api development|automation|workflow automation|n8n|zapier|make\.com|chatbot|ai bot|artificial intelligence|rag|llm|crm automation|whatsapp automation|customer support ai|data extraction|web scraping|scraping|python automation|video editing|video production|motion graphics|reels|short video|logo design|graphic design|social media design|thumbnail|image generation|banner design|content creation|social media management|account setup|create account|email setup|business profile|excel|spreadsheet|google sheets|data cleanup|data entry|موقع|ويب|ووردبريس|متجر|صفحة هبوط|برمجة|تصميم|تطوير|واجهة|تكامل api|ربط api|أتمتة|اتمتة|ذكاء اصطناعي|بوت|شات بوت|واتساب|إدارة عملاء|استخراج بيانات|مونتاج|تحرير فيديو|إنتاج فيديو|انتاج فيديو|موشن جرافيك|ريلز|تصميم شعار|تصميم لوجو|جرافيك|تصميم سوشيال|إنشاء صور|انشاء صور|إنشاء محتوى|انشاء محتوى|إدارة سوشيال|ادارة سوشيال|إنشاء حساب|انشاء حساب|إعداد حساب|اعداد حساب|إنشاء بريد|انشاء بريد|اكسل|إكسل|جداول بيانات|تنظيف بيانات|إدخال بيانات|ادخال بيانات|site web|d[eé]veloppeur web|automatisation|chatbot|intelligence artificielle)/u',$text);
        $sellerPage=(bool)preg_match('/(hire me|our services|we offer|agency services|pricing plans|portfolio|خدماتنا|نقدم خدمات|باقات|اعرض خدمات|freelancer profile|seller profile|service provider|أعمالي|معرض الأعمال|وظفني|سوف يتم توظيف)/u',$text);
        $jobSignals=(bool)preg_match('/(budget|fixed price|hourly|proposal|bids?|deadline|deliverables?|scope|client|employer|posted|published|proposals|project id|ميزانية|مدة التنفيذ|تفاصيل المشروع|صاحب المشروع|العروض|المتقدمين|موعد التسليم|devis|appel d.offres)/u',$text);
        if($editorial&&!$explicitRequest)return false;if($sellerPage&&!$explicitRequest)return false;
        if($class['trusted']&&$class['kind']==='detail'){
            $detailState=(string)($row['_detail_status']??'');
            if($service&&($explicitRequest||$jobSignals))return true;
            if($detailState===''&&$service)return true;
            if(in_array($detailState,['blocked_403','login_or_challenge','fetch_failed'],true)&&$service&&$explicitRequest&&trim($snippet)!=='')return true;
            return false;
        }
        // Generic web pages must prove actual buyer demand. A vague article mentioning "project" is not enough.
        $detailState=(string)($row['_detail_status']??'');
        return $explicitRequest&&$service&&($jobSignals||$detailState==='verified');
    }

    private static function buyerIntentEvidence(array $row):array{
        $text=pb_strtolower(trim((string)($row['title']??'').' '.(string)($row['snippet']??'')));
        $patterns=[
            'buyer_request'=>'/(looking for|need(?:ed)?|seeking|hiring|wanted|required|مطلوب|نبحث عن|أبحث عن|ابحث عن|أحتاج|احتاج|محتاج|recherche|cherche|besoin)/u',
            'project_terms'=>'/(project|freelance|contract|budget|proposal|rfp|rfq|مشروع|ميزانية|عرض سعر|مدة التنفيذ|تفاصيل المشروع|projet|budget|devis)/u',
            'web_service'=>'/(website|web site|web design|web development|web developer|wordpress|woocommerce|landing page|php|laravel|frontend|backend|api|api integration|automation|n8n|zapier|make\.com|chatbot|artificial intelligence|rag|llm|crm|whatsapp automation|data extraction|scraping|video editing|video production|motion graphics|reels|short video|logo design|graphic design|social media design|thumbnail|image generation|banner design|content creation|social media management|account setup|create account|email setup|business profile|excel|spreadsheet|google sheets|data cleanup|data entry|موقع|ويب|ووردبريس|متجر|صفحة هبوط|برمجة|تصميم|تطوير|واجهة|تكامل|أتمتة|اتمتة|ذكاء اصطناعي|بوت|واتساب|استخراج بيانات|مونتاج|تحرير فيديو|إنتاج فيديو|انتاج فيديو|موشن جرافيك|ريلز|تصميم شعار|تصميم لوجو|جرافيك|تصميم سوشيال|إنشاء صور|انشاء صور|إنشاء محتوى|انشاء محتوى|إدارة سوشيال|ادارة سوشيال|إنشاء حساب|انشاء حساب|إعداد حساب|اعداد حساب|إنشاء بريد|انشاء بريد|اكسل|إكسل|جداول بيانات|تنظيف بيانات|إدخال بيانات|ادخال بيانات|site web|développeur web|automatisation|intelligence artificielle)/u'
        ];
        $out=[];foreach($patterns as $k=>$re)if(preg_match($re,$text,$m))$out[$k]=pb_substr((string)$m[0],0,120);
        $out['detail_status']=(string)($row['_detail_status']??'not_checked');return $out;
    }

    private static function expandTrustedListings(array $rows,int $target,array &$warnings,float $deadline,int $taskId,int $runId):array{
        $out=[];$expandedPages=0;$expandedLinks=0;$maxPages=max(1,min(8,(int)setting('opportunities.listing_expand_pages','4')));$maxLinks=max(10,min(120,$target*3));
        foreach($rows as $row){
            if(microtime(true)>=$deadline){$warnings[]=['area'=>'listing_expand','error'=>'overall_time_budget_reached'];break;}TaskService::assertContinuable($taskId);self::progress($runId,$taskId,'expanding',['pages'=>$expandedPages,'links'=>$expandedLinks,'seconds_left'=>(int)self::timeLeft($deadline)]);
            $class=OpportunitySourceRules::classify((string)($row['url']??''));
            if(!$class['trusted']||$class['kind']==='other'){$out[]=$row;continue;}
            if($class['kind']==='blocked'){$warnings[]=['area'=>'source_gate','url'=>pb_substr((string)($row['url']??''),0,300),'error'=>(string)($class['reason']??'blocked_platform_path')];continue;}
            if($class['kind']==='detail'){$row['source']=(string)$class['source'];$out[]=$row;continue;}
            if($class['kind']!=='listing')continue;
            if($expandedPages>=$maxPages||$expandedLinks>=$maxLinks){$warnings[]=['area'=>'listing_expand','url'=>pb_substr((string)($row['url']??''),0,300),'error'=>'listing_expand_budget_reached'];continue;}
            $expandedPages++;
            try{
                $url=Security::publicUrl((string)$row['url']);$probe=HttpClient::probe($url,max(3,min(10,(int)setting('opportunities.detail_timeout_seconds','6'))),350000);$status=(int)($probe['status']??0);
                if($status<200||$status>=300){$warnings[]=['area'=>'listing_expand','url'=>pb_substr($url,0,300),'error'=>'http_'.$status];continue;}
                $links=OpportunitySourceRules::projectLinks((string)($probe['body']??''),(string)($probe['final_url']??$url),min(40,$maxLinks-$expandedLinks));
                if(!$links){$warnings[]=['area'=>'listing_expand','url'=>pb_substr($url,0,300),'error'=>'no_individual_project_links'];continue;}
                foreach($links as $x){$x['query']=(string)($row['query']??'');$x['auth_requirement']='public';$out[]=self::candidate($x);$expandedLinks++;if($expandedLinks>=$maxLinks)break;}
            }catch(Throwable $e){$warnings[]=['area'=>'listing_expand','url'=>pb_substr((string)($row['url']??''),0,300),'error'=>pb_substr(Security::redactSecrets($e->getMessage(),220),0,220)];}
        }
        return $out;
    }

    private static function dedupeRaw(array $rows):array{$out=[];$seen=[];foreach($rows as $r){$key=hash('sha256',pb_strtolower(trim($r['url'].'|'.$r['title'].'|'.$r['snippet'])));if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$r;}return $out;}
    private static function enrichPublicCandidates(array $rows,int $target,array &$warnings,float $deadline,int $taskId,int $runId):array{
        $configured=max(4,min(16,(int)setting('opportunities.detail_fetch_limit','8')));$limit=min($configured,max(4,$target));$timeout=max(3,min(7,(int)setting('opportunities.detail_timeout_seconds','5')));$seen=0;$out=[];
        foreach($rows as $row){
            if(microtime(true)>=$deadline){$warnings[]=['area'=>'candidate_detail','error'=>'overall_time_budget_reached'];break;}TaskService::assertContinuable($taskId);if($seen%2===0)self::progress($runId,$taskId,'verifying',['checked'=>$seen,'limit'=>$limit,'seconds_left'=>(int)self::timeLeft($deadline)]);
            $class=OpportunitySourceRules::classify((string)($row['url']??''));
            if($class['trusted']&&$class['kind']!=='detail'){$row['_detail_status']='not_individual_project';continue;}
            if($seen>=$limit||empty($row['url'])||($row['auth_requirement']??'public')!=='public'){$row['_detail_status']=$seen>=$limit?'not_checked':((string)($row['auth_requirement']??'unknown'));$out[]=$row;continue;}
            $seen++;
            try{
                $url=Security::publicUrl((string)$row['url']);
                $probe=HttpClient::probe($url,$timeout,260000);$status=(int)($probe['status']??0);
                if($status===401){$row['_detail_status']='login_required';$row['auth_requirement']='login_required';$warnings[]=['area'=>'candidate_detail','url'=>pb_substr($url,0,300),'error'=>'login_required'];$out[]=$row;continue;}
                if($status===403){$row['_detail_status']='blocked_403';$row['auth_requirement']='unknown';$warnings[]=['area'=>'candidate_detail','url'=>pb_substr($url,0,300),'error'=>'blocked_or_login_required_http_403'];$out[]=$row;continue;}
                if($status<200||$status>=300){$row['_detail_status']='http_'.$status;$warnings[]=['area'=>'candidate_detail','url'=>pb_substr($url,0,300),'error'=>'http_'.$status];$out[]=$row;continue;}
                $type=strtolower((string)($probe['content_type']??''));if($type!==''&&!str_contains($type,'text/')&&!str_contains($type,'html')&&!str_contains($type,'json')){$row['_detail_status']='unsupported_content_type';$out[]=$row;continue;}
                $body=(string)($probe['body']??'');$text=str_contains($type,'json')?pb_substr($body,0,120000):self::htmlText($body);
                $finalUrl=(string)($probe['final_url']??$row['url']);$finalClass=OpportunitySourceRules::classify($finalUrl);
                if($finalClass['trusted']&&$finalClass['kind']!=='detail'){$warnings[]=['area'=>'candidate_detail','url'=>pb_substr($finalUrl,0,300),'error'=>'redirected_to_non_project_page'];continue;}
                $low=pb_strtolower($text);$challenge=(bool)preg_match('/(sign in|log in|login required|access denied|cloudflare|verify you are human|captcha|تسجيل الدخول|سجل الدخول|غير مصرح)/u',$low);
                if($challenge){$row['_detail_status']='login_or_challenge';$row['auth_requirement']='login_required';$warnings[]=['area'=>'candidate_detail','url'=>pb_substr($url,0,300),'error'=>'login_or_challenge'];$out[]=$row;continue;}
                if(OpportunitySourceRules::isClosedText($text)){$warnings[]=['area'=>'candidate_detail','url'=>pb_substr($url,0,300),'error'=>'project_closed_or_unavailable'];continue;}
                $published=OpportunitySourceRules::normalizePublishedAt((string)($row['published_at']??''),$text);if($published!==null)$row['published_at']=$published;
                $detailSignals=(bool)preg_match('/(budget|fixed price|hourly|proposal|bids?|deadline|deliverables?|scope|client|employer|posted|published|looking for|need(?:ed)?|seeking|hiring|required|project id|proposals|project|مطلوب|أحتاج|احتاج|ميزانية|مدة التنفيذ|تفاصيل المشروع|صاحب المشروع|العروض|موعد التسليم|عرض سعر|devis|appel d.offres)/u',$low);
                if(pb_strlen($text)>=100&&$detailSignals){$row['_detail_status']='verified';$row['snippet']=pb_substr(trim((string)$row['snippet'])."

DETAIL_PAGE:
".$text,0,22000);$row['url']=$finalUrl;if($finalClass['trusted'])$row['source']=(string)$finalClass['source'];}
                elseif(pb_strlen($text)>=80){$row['_detail_status']='detail_not_project';$warnings[]=['area'=>'candidate_detail','url'=>pb_substr($url,0,300),'error'=>'detail_not_project'];}
                else{$row['_detail_status']='detail_too_short';}
            }catch(Throwable $e){$row['_detail_status']='fetch_failed';$warnings[]=['area'=>'candidate_detail','url'=>pb_substr((string)($row['url']??''),0,300),'error'=>pb_substr(Security::redactSecrets($e->getMessage(),220),0,220)];}
            $out[]=$row;
        }
        return $out;
    }

    private static function evaluateBatch(array $walid,array $batch,int $days,array &$warnings):array{
        // Keep the AI contract small. Budget/currency/profit scoring are deterministic in PHP; the model focuses
        // on Arabic presentation, scope understanding and commercial judgement. This is far more reliable on free routers.
        $itemProps=[
            'title_ar'=>['type'=>'string'],'summary_ar'=>['type'=>'string'],'details_ar'=>['type'=>'string'],
            'client_name'=>['type'=>'string'],'client_contact'=>['type'=>'string'],'client_type'=>['type'=>'string','enum'=>['business','individual','agency','unknown']],
            'country'=>['type'=>'string'],'category'=>['type'=>'string'],'opportunity_type'=>['type'=>'string'],'tags'=>['type'=>'array','maxItems'=>6,'items'=>['type'=>'string']],
            'estimated_days'=>['type'=>['integer','null'],'minimum'=>1,'maximum'=>90],'difficulty'=>['type'=>'string','enum'=>['easy','medium','hard','unknown']],
            'risk_level'=>['type'=>'string'],'fit_status'=>['type'=>'string','enum'=>['qualified','review','rejected']],
            'source_quality'=>['type'=>'integer','minimum'=>0,'maximum'=>100],'intent_confidence'=>['type'=>'integer','minimum'=>0,'maximum'=>100],
            'requirements_clarity'=>['type'=>'integer','minimum'=>0,'maximum'=>100],'client_trust'=>['type'=>'integer','minimum'=>0,'maximum'=>100],'competition'=>['type'=>'integer','minimum'=>0,'maximum'=>100],
            'selection_reason'=>['type'=>'string'],'rejection_reason'=>['type'=>'string'],'missing_fields'=>['type'=>'array','items'=>['type'=>'string']],
        ];
        $required=array_keys($itemProps);$schema=['type'=>'object','additionalProperties'=>false,'properties'=>['items'=>['type'=>'array','maxItems'=>6,'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>$itemProps,'required'=>$required]]],'required'=>['items']];
        $instructions=$walid['system_prompt']."\nأنت وليد، محلل فرص تجارية لفريق رقمي مصري متعدد التخصصات. المطلوب جودة لا كمية. الفريق يستطيع تنفيذ تطوير المواقع وWordPress وPHP/APIs والأتمتة والذكاء الاصطناعي، تصميم الصور والهويات، إنتاج ومونتاج الفيديو والـReels، إنشاء المحتوى وإدارة السوشيال، تنظيم البيانات والجداول، وإعداد الحسابات والخدمات الرقمية المشروعة عندما تسمح شروط المنصة. لا تحصر الفرص في الويب فقط. لكل نتيجة: اكتب title_ar بالعربية الطبيعية المبسطة بالكامل؛ اترك فقط أسماء التقنيات الضرورية مثل Laravel وREST API وASP.NET وWordPress بالإنجليزية. ممنوع نسخ العنوان الإنجليزي كما هو. summary_ar جملة أو جملتان بالعربية تشرح المطلوب ببساطة. details_ar شرح عربي واضح من 3 إلى 6 نقاط أو جمل: ماذا يريد العميل، أهم الوظائف، ما الذي سننفذه، وأي قيود ظاهرة. لا تخترع معلومات. ارفض المقالات والـSEO والقوائم التعليمية والـportfolio وصفحات البائعين. ارفض مشروعات التحايل على CAPTCHA/anti-bot/أنظمة الحماية أو تجاوز الحظر. أعط أولوية للأعمال الواضحة سريعة التنفيذ وعالية الهامش عبر قدرات الفريق: API/CRM/WhatsApp automation وn8n/Make/Zapier وWordPress/PHP fixes وbackend/dashboard وAI chatbot/RAG، وكذلك تصميم الصور والشعارات والسوشيال، الفيديو والمونتاج والـReels، إدارة المحتوى والبيانات والجداول وإعداد الحسابات الرقمية المشروعة. fit_status=qualified فقط إذا الطلب حقيقي وواضح والمصدر موثوق؛ review عند نقص السعر أو التواصل؛ rejected للمحتوى غير المشروع/غير التجاري. لا تستخرج السعر هنا؛ النظام يستخرجه حرفيًا من المصدر. آخر {$days} يومًا هو النطاق.";
        $items=[];
        try{
            if(OpenRouterService::configured()){
                $prompt=$instructions."\nأعد JSON صالحًا فقط مطابقًا للـSchema التالي بدون Markdown:\n".j($schema);
                $payload=['model'=>OpenRouterService::preferredModel(),'messages'=>[['role'=>'system','content'=>$prompt],['role'=>'user','content'=>j(['candidates'=>$batch])]],'max_completion_tokens'=>max(1200,min(3600,(int)setting('opportunities.ai_max_completion_tokens','2600'))),'temperature'=>0];
                $resp=OpenRouterService::chat($payload,max(12,min(30,(int)setting('opportunities.ai_timeout_seconds','24'))),1);$data=self::decodeJsonText(OpenRouterService::messageText($resp));$items=(array)($data['items']??$data);
            }else{$r=AiGateway::json($walid,$instructions,[['role'=>'user','content'=>j(['candidates'=>$batch])]],$schema,'walid_opportunity_eval_compact',3200);$items=(array)($r['data']['items']??[]);}
        }catch(Throwable $e){
            $warnings[]=['area'=>'ai_evaluation','error'=>pb_substr(Security::redactSecrets($e->getMessage(),220),0,220),'fallback'=>'per_item_then_heuristic'];
            // A single malformed item should not downgrade the whole batch to identical score/title. Retry compactly per item.
            if(OpenRouterService::configured()){
                foreach($batch as $i=>$row){
                    try{
                        $singleSchema=$schema;$singleSchema['properties']['items']['maxItems']=1;
                        $prompt=$instructions."\nحلل نتيجة واحدة فقط وأعد JSON {\"items\":[...]} مطابقًا للـSchema بدون Markdown:\n".j($singleSchema);
                        $resp=OpenRouterService::chat(['model'=>OpenRouterService::preferredModel(),'messages'=>[['role'=>'system','content'=>$prompt],['role'=>'user','content'=>j(['candidates'=>[$row]])]],'max_completion_tokens'=>1000,'temperature'=>0],15,1);
                        $d=self::decodeJsonText(OpenRouterService::messageText($resp));$one=(array)(($d['items']??[])[0]??[]);if($one)$items[$i]=$one;
                    }catch(Throwable $ignored){$items[$i]=self::fallbackEvaluation($row);}
                }
            }
        }
        $out=[];
        foreach($batch as $i=>$base){$item=(array)($items[$i]??self::fallbackEvaluation($base));$out[]=self::normalizeEvaluation(self::hydrateAiItem($item,$base),$base);}
        return $out;
    }

    private static function hydrateAiItem(array $item,array $base):array{
        $money=OpportunityMoney::evidence($base);$difficulty=(string)($item['difficulty']??'unknown');
        $execution=match($difficulty){'easy'=>90,'medium'=>75,'hard'=>42,default=>58};
        $titleAr=trim((string)($item['title_ar']??''));if(self::needsArabicRewrite($titleAr))$titleAr=self::arabicTitleFallback((string)($base['title']??''),(string)($item['opportunity_type']??''));
        $summary=trim((string)($item['summary_ar']??''));if($summary==='')$summary='العميل يطلب '.self::arabicTitleFallback((string)($base['title']??''),(string)($item['opportunity_type']??'')).'، وتحتاج التفاصيل النهائية للمراجعة قبل تقديم العرض.';
        $details=trim((string)($item['details_ar']??''));if($details==='')$details=$summary;
        return [
            'title'=>(string)($base['title']??''),'title_ar'=>$titleAr,'summary_ar'=>$summary,'details_ar'=>$details,'description'=>(string)($base['snippet']??''),'published_at'=>(string)($base['published_at']??''),
            'client_name'=>(string)($item['client_name']??''),'client_contact'=>(string)($item['client_contact']??''),'client_type'=>(string)($item['client_type']??'unknown'),'country'=>(string)($item['country']??''),'language'=>preg_match('/[\x{0600}-\x{06FF}]/u',(string)($base['title']??'').' '.(string)($base['snippet']??''))?'ar':'en',
            'budget_min'=>$money['budget_min'],'budget_max'=>$money['budget_max'],'currency'=>$money['currency'],'category'=>(string)($item['category']??'خدمات رقمية'),'opportunity_type'=>(string)($item['opportunity_type']??'Digital Project'),'tags'=>(array)($item['tags']??[]),
            'estimated_cost'=>0,'suggested_offer'=>null,'estimated_days'=>isset($item['estimated_days'])&&is_numeric($item['estimated_days'])?(int)$item['estimated_days']:null,'score'=>0,
            'source_quality'=>(int)($item['source_quality']??55),'intent_confidence'=>(int)($item['intent_confidence']??60),'risk_level'=>(string)($item['risk_level']??'unknown'),'difficulty'=>$difficulty,'fit_status'=>(string)($item['fit_status']??'review'),'login_requirement'=>(string)($base['auth_requirement']??'unknown'),'missing_fields'=>(array)($item['missing_fields']??[]),
            'score_breakdown'=>['freshness'=>60,'requirements_clarity'=>(int)($item['requirements_clarity']??55),'budget_quality'=>$money['budget_min']!==null?90:35,'profitability'=>55,'execution_fit'=>$execution,'client_trust'=>(int)($item['client_trust']??45),'contactability'=>40,'competition'=>(int)($item['competition']??55),'source_quality'=>(int)($item['source_quality']??55)],
            'selection_reason'=>(string)($item['selection_reason']??''),'rejection_reason'=>(string)($item['rejection_reason']??''),
        ];
    }

    private static function fallbackEvaluate(array $batch):array{$out=[];foreach($batch as $row)$out[]=self::normalizeEvaluation(self::fallbackEvaluation($row),$row);return $out;}
    private static function fallbackEvaluation(array $row):array{
        $title=(string)($row['title']??'فرصة تحتاج مراجعة');$snippet=(string)($row['snippet']??'');$text=pb_strtolower(trim($title.' '.$snippet));$url=(string)($row['url']??'');$host=pb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
        $buyer=(bool)preg_match('/(looking for|need(?:ed)?|seeking|hiring|wanted|required|we need|i need|مطلوب|نبحث عن|أبحث عن|احتاج|أحتاج|محتاج|أريد|يرجى من المتقدمين|recherche|besoin)/u',$text);$service=(bool)preg_match('/(website|web design|web development|wordpress|woocommerce|landing page|php|laravel|frontend|backend|shopify|ecommerce|redesign|api|automation|n8n|zapier|make\.com|chatbot|artificial intelligence|rag|llm|crm|whatsapp|data extraction|scraping|video editing|video production|motion graphics|reels|short video|logo design|graphic design|social media design|thumbnail|image generation|content creation|social media management|account setup|create account|email setup|excel|spreadsheet|data cleanup|data entry|موقع|ويب|ووردبريس|متجر|صفحة هبوط|برمجة|تصميم|تطوير|تكامل|أتمتة|اتمتة|ذكاء اصطناعي|بوت|واتساب|استخراج بيانات|مونتاج|تحرير فيديو|إنتاج فيديو|انتاج فيديو|موشن جرافيك|ريلز|تصميم شعار|تصميم لوجو|جرافيك|تصميم سوشيال|إنشاء صور|انشاء صور|إنشاء محتوى|انشاء محتوى|إدارة سوشيال|ادارة سوشيال|إنشاء حساب|انشاء حساب|إعداد حساب|اعداد حساب|إنشاء بريد|انشاء بريد|اكسل|إكسل|جداول بيانات|تنظيف بيانات|إدخال بيانات|ادخال بيانات)/u',$text);$strong=$buyer&&$service&&!self::genericEditorial($text)&&!self::prohibitedAutomationRisk($text);
        $sourceQuality=match(true){str_contains($host,'upwork.com')=>94,str_contains($host,'mostaql.com')=>90,str_contains($host,'freelancer.com')=>88,str_contains($host,'nafezly.com')=>86,str_contains($host,'peopleperhour.com')=>84,str_contains($host,'workana.com')=>82,str_contains($host,'guru.com')=>80,default=>55};$intent=$strong?82:32;
        $type='Digital Project';$tags=[];if(preg_match('/(chatbot|ai bot|شات بوت|بوت)/u',$text)){$type='AI Chatbot';$tags[]='AI Chatbot';}if(preg_match('/(rag|llm|knowledge base|قاعدة معرفة)/u',$text)){$type='RAG / LLM';$tags[]='RAG';}if(preg_match('/(n8n|zapier|make\.com|workflow automation|أتمتة|اتمتة)/u',$text)){$type='Workflow Automation';$tags[]='Automation';}if(preg_match('/(whatsapp|واتساب|crm)/u',$text)){$tags[]='WhatsApp / CRM';}if(preg_match('/(api integration|api development|rest api|تكامل api|ربط api)/u',$text)){$type='API Integration';$tags[]='API';}if(preg_match('/(data extraction|scraping|استخراج بيانات)/u',$text)){$type='Data Extraction';$tags[]='Data';}if(preg_match('/(video editing|video production|motion graphics|reels|short video|مونتاج|تحرير فيديو|إنتاج فيديو|انتاج فيديو|موشن جرافيك|ريلز)/u',$text)){$type='Video Production';$tags[]='Video';}if(preg_match('/(logo design|graphic design|social media design|thumbnail|image generation|banner design|تصميم شعار|تصميم لوجو|جرافيك|تصميم سوشيال|إنشاء صور|انشاء صور|بانر)/u',$text)){$type='Visual Design';$tags[]='Design';}if(preg_match('/(social media management|content creation|content calendar|إدارة سوشيال|ادارة سوشيال|إنشاء محتوى|انشاء محتوى)/u',$text)){$type='Social Content';$tags[]='Social';}if(preg_match('/(account setup|create account|email setup|business profile|إنشاء حساب|انشاء حساب|إعداد حساب|اعداد حساب|إنشاء بريد|انشاء بريد)/u',$text)){$type='Account Setup';$tags[]='Account Setup';}if(preg_match('/(excel|spreadsheet|google sheets|data cleanup|data entry|اكسل|إكسل|جداول بيانات|تنظيف بيانات|إدخال بيانات|ادخال بيانات)/u',$text)){$type='Data / Spreadsheet';$tags[]='Spreadsheet';}if(str_contains($text,'wordpress')||str_contains($text,'ووردبريس')){$type='WordPress';$tags[]='WordPress';}if(str_contains($text,'landing page')||str_contains($text,'صفحة هبوط')){$type='Landing Page';$tags[]='Landing Page';}if(str_contains($text,'ecommerce')||str_contains($text,'متجر'))$tags[]='E-commerce';if(!$tags)$tags[]='Digital Development';
        $lang=preg_match('/[\x{0600}-\x{06FF}]/u',$text)?'ar':'en';$money=OpportunityMoney::evidence($row);$titleAr=self::arabicTitleFallback($title,$type);
        $detailText=trim((string)($row['snippet']??''));$summary='المطلوب باختصار: '.$titleAr.'. '.($money['raw']!==''?'الميزانية المعلنة: '.$money['raw'].'. ':'').'سيتم تقدير تكلفة التنفيذ والعرض المقترح آليًا قبل التواصل.';
        $details='المشروع يبدو طلب تنفيذ فعلي في مجال '.$titleAr.'. سيتم الاعتماد على وصف المصدر الأصلي لتحديد النطاق والمدة والتكلفة، وأي معلومة غير موجودة ستظل معلّمة كمعلومة ناقصة بدل اختراعها.';
        $break=['freshness'=>55,'requirements_clarity'=>$strong?65:35,'budget_quality'=>$money['budget_min']!==null?90:35,'profitability'=>55,'execution_fit'=>$service?75:40,'client_trust'=>45,'contactability'=>35,'competition'=>55,'source_quality'=>$sourceQuality];
        return ['title'=>$title,'title_ar'=>$titleAr,'summary_ar'=>$summary,'details_ar'=>$details,'description'=>$detailText,'published_at'=>(string)($row['published_at']??''),'client_name'=>'','client_contact'=>'','client_type'=>'unknown','country'=>'','language'=>$lang,'budget_min'=>$money['budget_min'],'budget_max'=>$money['budget_max'],'currency'=>$money['currency'],'category'=>'خدمات رقمية','opportunity_type'=>$type,'tags'=>$tags,'estimated_cost'=>0,'suggested_offer'=>null,'estimated_days'=>null,'score'=>0,'source_quality'=>$sourceQuality,'intent_confidence'=>$intent,'risk_level'=>self::prohibitedAutomationRisk($text)?'high':'unknown','difficulty'=>'unknown','fit_status'=>$strong?'review':'rejected','login_requirement'=>(string)($row['auth_requirement']??'unknown'),'missing_fields'=>array_values(array_filter([$money['budget_min']===null?'ميزانية العميل غير معلنة':null,'بيانات العميل','وسيلة التواصل','التحليل الذكي الكامل لم يكتمل وتم استخدام تقييم محلي'])),'score_breakdown'=>$break,'selection_reason'=>$strong?'طلب شراء/تنفيذ واضح من مصدر مشروع؛ تم استخدام تقييم محلي احتياطي مع تسعير داخلي.':'','rejection_reason'=>$strong?'':(self::genericEditorial($text)?'محتوى مقالي/تعليمي وليس طلب مشروع.':(self::prohibitedAutomationRisk($text)?'مشروع أتمتة عالي المخاطر يتضمن تجاوز/تفادي أنظمة الحماية أو الحظر.':'لا توجد نية شراء واضحة لخدمة يستطيع الفريق تنفيذها.'))];
    }


    private static function normalizeEvaluation(array $ev,array $base):array{
        $money=OpportunityMoney::evidence($base);
        $ev['_currency_meta']=$money;
        // Source evidence wins over AI. Unknown stays unknown; we never silently fall back to EGP.
        $ev['currency']=$money['currency'];
        if($money['budget_min']!==null){
            $ev['budget_min']=$money['budget_min'];
            $ev['budget_max']=$money['budget_max'];
        }elseif($money['currency']==='UNK'){
            $ev['budget_min']=null;$ev['budget_max']=null;
        }
        $missing=array_values(array_filter(array_map('strval',(array)($ev['missing_fields']??[]))));
        if($money['currency']==='UNK')$missing[]='عملة الميزانية غير مؤكدة من المصدر';
        if(!empty($money['conflict']))$missing[]='المصدر يحتوي إشارات لأكثر من عملة ويحتاج مراجعة';
        $detail=(string)($base['_detail_status']??'not_checked');
        if($detail!=='verified')$missing[]='صفحة المصدر لم تُتحقق بالكامل: '.$detail;
        $published=self::dateSql((string)($base['published_at']??$ev['published_at']??''));
        if($published===null)$missing[]='تاريخ النشر غير مؤكد من صفحة المشروع';
        $ev['missing_fields']=array_values(array_unique($missing));
        if((string)($ev['fit_status']??'review')==='qualified'&&($money['currency']==='UNK'||$detail!=='verified'||!empty($money['conflict'])||$published===null))$ev['fit_status']='review';

        $b=(array)($ev['score_breakdown']??[]);$difficulty=(string)($ev['difficulty']??'unknown');$risk=pb_strtolower((string)($ev['risk_level']??'unknown'));
        $cost=$money['currency']==='UNK'?0:max(0,(float)($ev['estimated_cost']??0));
        $offer=($money['currency']!=='UNK'&&is_numeric($ev['suggested_offer']??null))?max(0,(float)$ev['suggested_offer']):0;
        $ev['estimated_cost']=$cost;$ev['suggested_offer']=$offer>0?$offer:null;
        $profit=$offer>0?max(0,$offer-$cost):0;
        $margin=$offer>0&&$cost>0?($profit/$offer):null;
        $minMargin=max(0.0,min(0.90,(float)setting('opportunities.minimum_verified_margin_percent','25')/100));
        // A card is only "qualified" when profitability is actually measurable in the SAME verified currency.
        // Unknown/zero cost is not treated as 100% profit; it is sent to review instead of being overstated.
        if((string)($ev['fit_status']??'review')==='qualified'){
            if($offer<=0||$cost<=0||$margin===null){$ev['fit_status']='review';$missing[]='هامش الربح غير قابل للتحقق من البيانات الحالية';}
            elseif($margin<$minMargin){$ev['fit_status']='review';$missing[]='هامش الربح المتوقع أقل من '.(int)round($minMargin*100).'% ويحتاج مراجعة';}
        }
        $ev['missing_fields']=array_values(array_unique($missing));
        $execution=max(0,min(100,(int)($b['execution_fit']??50)));$profitability=max(0,min(100,(int)($b['profitability']??($profit>0?70:45))));$clarity=max(0,min(100,(int)($b['requirements_clarity']??50)));$fresh=max(0,min(100,(int)($b['freshness']??50)));$trust=max(0,min(100,(int)($b['client_trust']??45)));$contact=max(0,min(100,(int)($b['contactability']??40)));$source=max(0,min(100,(int)($ev['source_quality']??$b['source_quality']??50)));$intent=max(0,min(100,(int)($ev['intent_confidence']??50)));
        $difficultyBonus=match($difficulty){'easy'=>100,'medium'=>70,'hard'=>35,default=>50};$costEfficiency=$offer>0?max(0,min(100,(int)round((1-min(1,$cost/max(1,$offer)))*100))):55;$riskScore=(str_contains($risk,'low')||str_contains($risk,'منخفض'))?90:((str_contains($risk,'high')||str_contains($risk,'مرتفع'))?35:60);
        $verificationScore=$detail==='verified'?100:55;$currencyScore=$money['currency']==='UNK'?35:100;
        $score=(int)round($execution*.18+$profitability*.16+$costEfficiency*.12+$difficultyBonus*.10+$clarity*.10+$intent*.10+$fresh*.06+$trust*.04+$contact*.03+$source*.03+$riskScore*.01+$verificationScore*.04+$currencyScore*.03);
        $ev['score']=max(0,min(100,$score));$b['execution_fit']=$execution;$b['profitability']=$profitability;$b['cost_efficiency']=$costEfficiency;$b['difficulty_fit']=$difficultyBonus;$b['requirements_clarity']=$clarity;$b['intent_confidence']=$intent;$b['freshness']=$fresh;$b['client_trust']=$trust;$b['contactability']=$contact;$b['source_quality']=$source;$b['risk_safety']=$riskScore;$b['source_verification']=$verificationScore;$b['currency_evidence']=$currencyScore;$ev['score_breakdown']=$b;
        $summary=trim((string)($ev['summary_ar']??''));if($summary==='')$summary='فرصة خدمة رقمية مدفوعة من '.((string)($base['source']??'مصدر عام')).' تحتاج مراجعة التفاصيل قبل التواصل.';$ev['summary_ar']=pb_substr($summary,0,900);$detailsAr=trim((string)($ev['details_ar']??''));if($detailsAr==='')$detailsAr=$ev['summary_ar'];$ev['details_ar']=pb_substr($detailsAr,0,5000);
        $titleAr=trim((string)($ev['title_ar']??''));if(self::needsArabicRewrite($titleAr))$titleAr=self::arabicTitleFallback((string)($base['title']??''),(string)($ev['opportunity_type']??''));$ev['title_ar']=pb_substr($titleAr,0,300);
        if(trim((string)($ev['selection_reason']??''))===''&&($ev['fit_status']??'')!=='rejected')$ev['selection_reason']='تم ترشيحها بعد التحقق من نية الشراء والمصدر، مع فصل العملة وعدم تحويل الأسعار بين العملات.';return $ev;
    }

    private static function storeRaw(int $runId,array $base):int{$hash=hash('sha256',pb_strtolower(trim($base['source'].'|'.$base['url'].'|'.$base['title'].'|'.$base['snippet'])));$q=db()->prepare("INSERT INTO opportunity_raw_items(run_id,source_id,source_name,source_item_id,source_url,title,published_at,raw_text,raw_hash) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");$q->execute([$runId,$base['source_id']?:null,$base['source'],$base['source_item_id']?:null,$base['url']?:null,$base['title']?:null,self::dateSql($base['published_at']),pb_substr($base['snippet'],0,20000),$hash]);return (int)db()->lastInsertId();}
    private static function persistEvaluation(array $ev,array $base,int $cutoff,int $agentId):array{
        $title=trim((string)($base['title']??''))?:trim((string)($ev['title']??''))?:'فرصة بدون عنوان';
        $titleAr=trim((string)($ev['title_ar']??''))?:$title;
        $desc=trim((string)($ev['description']??''))?:trim((string)($base['snippet']??''));
        $published=self::dateSql((string)($ev['published_at']??$base['published_at']??''));
        $fit=(string)($ev['fit_status']??'review');$missing=(array)($ev['missing_fields']??[]);
        $login=(string)($ev['login_requirement']??'unknown');if($login==='unknown'&&!empty($base['auth_requirement']))$login=(string)$base['auth_requirement'];
        if($published&&utc_ts($published)<$cutoff){$fit='rejected';$ev['rejection_reason']=trim((string)($ev['rejection_reason']??''))?:'الفرصة أقدم من النطاق الزمني المحدد';}
        $sourceUrl=trim((string)($base['url']??''));if($sourceUrl!==''){$uq=db()->prepare("SELECT id,fit_status FROM opportunities WHERE source_url=? ORDER BY id ASC LIMIT 1");$uq->execute([$sourceUrl]);$existing=$uq->fetch();if($existing){$existingId=(int)$existing['id'];db()->prepare('UPDATE opportunities SET last_verified_at=NOW() WHERE id=?')->execute([$existingId]);return ['duplicate'=>true,'opportunity_id'=>$existingId,'fit_status'=>(string)($existing['fit_status']??'review')];}}
        $finger=self::fingerprint($title,(string)($ev['client_name']??''),(string)($base['url']??''),(string)($base['source']??''),$desc);
        $q=db()->prepare('SELECT * FROM opportunity_fingerprints WHERE fingerprint=?');$q->execute([$finger]);$known=$q->fetch();
        if($known){db()->prepare('UPDATE opportunity_fingerprints SET last_seen_at=NOW() WHERE fingerprint=?')->execute([$finger]);$knownOpp=(int)($known['opportunity_id']??0);if($knownOpp>0){try{db()->prepare('UPDATE opportunities SET last_verified_at=NOW() WHERE id=?')->execute([$knownOpp]);}catch(Throwable){}}return ['duplicate'=>true,'opportunity_id'=>$knownOpp,'fit_status'=>(string)$known['decision']==='rejected'?'rejected':'review'];}

        $currency=OpportunityMoney::normalizeCurrency((string)($ev['currency']??''));
        $budgetMin=is_numeric($ev['budget_min']??null)?(float)$ev['budget_min']:null;$budgetMax=is_numeric($ev['budget_max']??null)?(float)$ev['budget_max']:null;
        $contacts=is_array($base['_contacts']??null)?$base['_contacts']:OpportunityContact::extract((string)($base['_detail_html']??''),(string)($base['_detail_text']??$base['snippet']??''),(string)($base['url']??''));
        $contactPrimary=OpportunityContact::primary($contacts);$contactSummary=OpportunityContact::summary($contacts);
        $pricingCurrency=$currency==='UNK'?OpportunityCostEstimator::internalCurrency():$currency;
        $costInfo=setting('opportunities.cost_learning','1')==='1'?OpportunityCostEstimator::blend($ev,$base,$pricingCurrency):OpportunityCostEstimator::heuristic($ev,$base,$pricingCurrency);
        $cost=max(0,(float)($costInfo['cost']??0));
        $estimatedDays=is_numeric($ev['estimated_days']??null)?max(1,min(180,(int)$ev['estimated_days'])):max(1,min(180,(int)($costInfo['estimated_days']??4)));
        if((string)($ev['difficulty']??'unknown')==='unknown'&&!empty($costInfo['difficulty']))$ev['difficulty']=(string)$costInfo['difficulty'];
        if($currency!=='UNK'&&($budgetMin!==null||$budgetMax!==null))$pricing=PricingPolicy::recommend($budgetMin,$budgetMax,$cost,(string)($ev['risk_level']??'unknown'));
        else $pricing=PricingPolicy::recommendFromCost($cost,(string)($ev['risk_level']??'unknown'),$estimatedDays);
        $offer=is_numeric($pricing['price']??null)?max(0,(float)$pricing['price']):null;
        $profit=$offer!==null?max(0,$offer-$cost):0;$minMargin=max(0.0,min(0.90,(float)setting('opportunities.minimum_verified_margin_percent','30')/100));
        if($fit==='qualified'&&($offer===null||$offer<=0||$cost<=0||($profit/max(1,$offer))<$minMargin)){$fit='review';$missing[]='هامش الربح أقل من الحد المطلوب أو غير متحقق';}
        if($currency==='UNK'){
            if($fit==='qualified')$fit='review';
            $missing[]='ميزانية العميل غير معلنة؛ التكلفة والعرض أدناه تقدير داخلي بعملة '.$pricingCurrency;
            $costInfo['source']='internal_'.$pricingCurrency.'_'.(string)($costInfo['source']??'heuristic');
        }
        if(!empty($pricing['owner_required'])){$fit='review';$missing[]=(string)($pricing['reason']??'التسعير يحتاج مراجعة المالك');}
        $depositPercent=max(0,min(100,(float)setting('business.deposit_percent','40')));$deposit=$offer!==null?round($offer*$depositPercent/100,2):null;
        if(!$contacts)$missing[]='لا توجد وسيلة تواصل عامة مؤكدة';
        $ev['estimated_cost']=$cost;$ev['suggested_offer']=$offer;$ev['estimated_days']=$estimatedDays;$ev['missing_fields']=array_values(array_unique(array_filter(array_map('strval',$missing))));
        $score=OpportunityScoringService::score($ev,$base,$contacts,$costInfo);$learn=WalidLearningService::preferenceAdjustment((string)$base['source'],(string)($ev['country']??''),(string)($ev['category']??''),(string)($ev['opportunity_type']??''));$score['score']=max(0,min(100,(int)$score['score']+(int)$learn['delta']));$score['breakdown']['closed_loop_learning']=$learn;$ev['score']=$score['score'];$ev['score_breakdown']=$score['breakdown'];
        $status=$fit==='rejected'?'rejected':($fit==='review'?'needs_review':'new');
        $market=match(true){self::countryHas((string)($ev['country']??''),['مصر','egypt'])=>'egypt',self::countryHas((string)($ev['country']??''),['السعود','الإمارات','الامارات','الكويت','قطر','البحرين','عمان','الأردن','jordan','saudi','emirates','uae','kuwait','qatar','bahrain','oman'])=>'arab',trim((string)($ev['country']??''))!==''=>'international',default=>'unknown'};
        $raw=['query'=>$base['query']??'','snippet'=>$base['snippet']??'','detail_status'=>$base['_detail_status']??'not_checked','source_integrity'=>OpportunitySourceRules::classify((string)($base['url']??'')),'currency_evidence'=>$ev['_currency_meta']??OpportunityMoney::evidence($base),'buyer_intent'=>self::buyerIntentEvidence($base)];
        $advertisedBudget=trim((string)($ev['_currency_meta']['raw']??''));
        $sql="INSERT INTO opportunities(source,source_item_id,source_url,published_at,discovered_at,title,title_ar,client_name,client_contact,contact_methods_json,contactability_notes,client_type,country,language,market_scope,budget_min,budget_max,currency,advertised_budget_text,estimated_cost,cost_estimate_source,cost_confidence,cost_learning_samples,suggested_offer,suggested_deposit,projected_profit,estimated_days,score,score_version,source_quality,intent_confidence,buyer_intent_evidence_json,fit_status,difficulty,login_requirement,missing_fields_json,score_breakdown_json,risk_level,category,opportunity_type,tags_json,requirements_text,details_ar,summary_ar,raw_details,fingerprint,selection_reason,rejection_reason,last_verified_at,contact_verified_at,discovered_by_agent_id,status,auto_rejected_at) VALUES (?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?) ON DUPLICATE KEY UPDATE source=VALUES(source),source_url=VALUES(source_url),published_at=COALESCE(VALUES(published_at),published_at),title=VALUES(title),title_ar=VALUES(title_ar),client_name=VALUES(client_name),client_contact=VALUES(client_contact),contact_methods_json=VALUES(contact_methods_json),contactability_notes=VALUES(contactability_notes),client_type=VALUES(client_type),country=VALUES(country),language=VALUES(language),market_scope=VALUES(market_scope),budget_min=VALUES(budget_min),budget_max=VALUES(budget_max),currency=VALUES(currency),advertised_budget_text=VALUES(advertised_budget_text),estimated_cost=VALUES(estimated_cost),cost_estimate_source=VALUES(cost_estimate_source),cost_confidence=VALUES(cost_confidence),cost_learning_samples=VALUES(cost_learning_samples),suggested_offer=VALUES(suggested_offer),suggested_deposit=VALUES(suggested_deposit),projected_profit=VALUES(projected_profit),estimated_days=VALUES(estimated_days),score=VALUES(score),score_version=VALUES(score_version),source_quality=VALUES(source_quality),intent_confidence=VALUES(intent_confidence),buyer_intent_evidence_json=VALUES(buyer_intent_evidence_json),fit_status=VALUES(fit_status),difficulty=VALUES(difficulty),login_requirement=VALUES(login_requirement),missing_fields_json=VALUES(missing_fields_json),score_breakdown_json=VALUES(score_breakdown_json),risk_level=VALUES(risk_level),category=VALUES(category),opportunity_type=VALUES(opportunity_type),tags_json=VALUES(tags_json),requirements_text=VALUES(requirements_text),details_ar=VALUES(details_ar),summary_ar=VALUES(summary_ar),raw_details=VALUES(raw_details),selection_reason=VALUES(selection_reason),rejection_reason=VALUES(rejection_reason),contact_verified_at=VALUES(contact_verified_at),auto_rejected_at=COALESCE(auto_rejected_at,VALUES(auto_rejected_at)),last_verified_at=NOW(),discovered_by_agent_id=VALUES(discovered_by_agent_id)";
        $q=db()->prepare($sql);$autoRejected=$fit==='rejected'?now_utc():null;$contactVerified=$contacts?now_utc():null;
        $q->execute([(string)$base['source'],$base['source_item_id']?:null,$base['url']?:null,$published,$title,$titleAr,trim((string)($ev['client_name']??''))?:null,$contactPrimary?:trim((string)($ev['client_contact']??''))?:null,$contacts?j($contacts):null,$contactSummary?:null,(string)($ev['client_type']??'unknown'),trim((string)($ev['country']??''))?:null,trim((string)($ev['language']??''))?:null,$market,$budgetMin,$budgetMax,$currency,$advertisedBudget?:null,$cost,(string)($costInfo['source']??'unknown'),(int)($costInfo['confidence']??0),(int)($costInfo['sample_count']??0),$offer,$deposit,$profit,$estimatedDays,(int)$score['score'],(int)$score['version'],max(0,min(100,(int)($ev['source_quality']??0))),max(0,min(100,(int)($ev['intent_confidence']??0))),j(self::buyerIntentEvidence($base)),$fit,(string)($ev['difficulty']??'unknown'),$login,$ev['missing_fields']?j($ev['missing_fields']):null,j($score['breakdown']),trim((string)($ev['risk_level']??''))?:null,trim((string)($ev['category']??''))?:null,trim((string)($ev['opportunity_type']??''))?:null,j(array_values(array_slice((array)($ev['tags']??[]),0,8))),$desc,trim((string)($ev['details_ar']??''))?:null,trim((string)($ev['summary_ar']??''))?:null,j($raw),$finger,trim((string)($ev['selection_reason']??''))?:null,trim((string)($ev['rejection_reason']??''))?:null,$contactVerified,$agentId,$status,$autoRejected]);
        $oppId=(int)db()->lastInsertId();if(!$oppId){$qq=db()->prepare('SELECT id FROM opportunities WHERE fingerprint=?');$qq->execute([$finger]);$oppId=(int)$qq->fetchColumn();}
        OpportunityCostEstimator::record($oppId,$ev,$pricingCurrency,$costInfo);
        db()->prepare("INSERT INTO opportunity_fingerprints(fingerprint,opportunity_id,decision,title_normalized,client_normalized,domain_normalized,source_normalized,reason) VALUES (?,?,?,LOWER(?),LOWER(?),LOWER(?),LOWER(?),?) ON DUPLICATE KEY UPDATE opportunity_id=VALUES(opportunity_id),decision=VALUES(decision),reason=VALUES(reason),last_seen_at=NOW()")->execute([$finger,$oppId,$fit==='rejected'?'rejected':'active',$title,(string)($ev['client_name']??''),(string)(parse_url((string)($base['url']??''),PHP_URL_HOST)?:''),(string)$base['source'],$fit==='rejected'?(string)($ev['rejection_reason']??''):null]);
        return ['duplicate'=>false,'opportunity_id'=>$oppId,'fit_status'=>$fit];
    }


    public static function cleanupLegacyNoise(int $limit=250):array{
        $limit=max(1,min(1000,$limit));$rows=db()->query("SELECT id,source,source_url,title,summary_ar,requirements_text,fit_status,status FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') ORDER BY id ASC LIMIT ".$limit)->fetchAll();$rejected=0;$duplicates=0;$seen=[];
        foreach($rows as $row){$id=(int)$row['id'];$url=trim((string)($row['source_url']??''));if($url!==''&&isset($seen[pb_strtolower($url)])){db()->prepare("UPDATE opportunities SET fit_status='rejected',status='rejected',rejection_reason='مكرر لنفس رابط المشروع',auto_rejected_at=COALESCE(auto_rejected_at,NOW()) WHERE id=?")->execute([$id]);$duplicates++;continue;}if($url!=='')$seen[pb_strtolower($url)]=$id;
            $class=OpportunitySourceRules::classify($url);$probe=['url'=>$url,'title'=>(string)($row['title']??''),'snippet'=>trim((string)($row['summary_ar']??'').' '.(string)($row['requirements_text']??'')),'_detail_status'=>'legacy'];
            $deterministicBad=($class['trusted']&&$class['kind']!=='detail')||!self::isLikelyCandidate($probe);
            if($deterministicBad){db()->prepare("UPDATE opportunities SET fit_status='rejected',status='rejected',rejection_reason=COALESCE(NULLIF(rejection_reason,''),'نتيجة تاريخية لا تجتاز بوابة نية الشراء/رابط المشروع الحالية'),auto_rejected_at=COALESCE(auto_rejected_at,NOW()) WHERE id=?")->execute([$id]);$rejected++;}
        }
        return ['checked'=>count($rows),'rejected'=>$rejected,'duplicates'=>$duplicates];
    }

    public static function repairOpenCards(int $limit=200):array{
        $limit=max(1,min(1000,$limit));$rows=db()->query("SELECT * FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') ORDER BY id DESC LIMIT ".$limit)->fetchAll();$updated=0;$rejected=0;
        foreach($rows as $o){
            $raw=json_decode((string)($o['raw_details']??''),true);if(!is_array($raw))$raw=[];
            $base=['title'=>(string)($o['title']??''),'url'=>(string)($o['source_url']??''),'snippet'=>(string)($raw['snippet']??$o['requirements_text']??$o['summary_ar']??''),'published_at'=>(string)($o['published_at']??''),'source'=>(string)($o['source']??''),'auth_requirement'=>(string)($o['login_requirement']??'public'),'_detail_status'=>(string)($raw['detail_status']??'legacy')];
            $class=OpportunitySourceRules::classify((string)$base['url']);$text=(string)$base['title'].' '.(string)$base['snippet'];
            if($class['kind']==='blocked'||self::genericEditorial($text)||self::prohibitedAutomationRisk($text)||!self::isLikelyCandidate($base)){
                $reason=$class['kind']==='blocked'?'صفحة بائع/Portfolio أو Template وليست مشروع عميل':(self::genericEditorial($text)?'محتوى مقالي/تعليمي وليس مشروع عميل':(self::prohibitedAutomationRisk($text)?'أتمتة عالية المخاطر تتضمن تجاوز/تفادي أنظمة حماية':'لا يجتاز بوابة نية الشراء الحالية'));
                db()->prepare("UPDATE opportunities SET fit_status='rejected',status='rejected',rejection_reason=?,auto_rejected_at=COALESCE(auto_rejected_at,NOW()),shortlist_rank=NULL,shortlisted_at=NULL WHERE id=?")->execute([$reason,(int)$o['id']]);$rejected++;continue;
            }
            $money=OpportunityMoney::evidence($base);$currency=$money['currency'];$pricingCurrency=$currency==='UNK'?OpportunityCostEstimator::internalCurrency():$currency;
            $tags=json_decode((string)($o['tags_json']??''),true);if(!is_array($tags))$tags=[];
            $ev=['title_ar'=>(string)($o['title_ar']??''),'summary_ar'=>(string)($o['summary_ar']??''),'details_ar'=>(string)($o['details_ar']??''),'category'=>(string)($o['category']??'خدمات رقمية'),'opportunity_type'=>(string)($o['opportunity_type']??'Digital Project'),'tags'=>$tags,'difficulty'=>(string)($o['difficulty']??'unknown'),'risk_level'=>(string)($o['risk_level']??'unknown'),'estimated_days'=>$o['estimated_days']??null,'estimated_cost'=>(float)($o['estimated_cost']??0),'source_quality'=>(int)($o['source_quality']??55),'intent_confidence'=>(int)($o['intent_confidence']??60),'budget_min'=>$money['budget_min'],'budget_max'=>$money['budget_max'],'currency'=>$currency,'score_breakdown'=>[]];
            $titleAr=trim((string)$ev['title_ar']);if(self::needsArabicRewrite($titleAr))$titleAr=self::arabicTitleFallback((string)$base['title'],(string)$ev['opportunity_type']);
            $summary=trim((string)$ev['summary_ar']);if($summary===''||str_contains($summary,'يحتاج مراجعة التفاصيل الأصلية'))$summary='المطلوب باختصار: '.$titleAr.'. '.($money['raw']!==''?'الميزانية المعلنة: '.$money['raw'].'. ':'').'تم تقدير تكلفة التنفيذ والعرض المقترح مبدئيًا.';
            $detailsAr=trim((string)$ev['details_ar']);if($detailsAr==='')$detailsAr='المشروع عبارة عن '.$titleAr.'. المطلوب مراجعة وصف العميل الأصلي وتأكيد النطاق والوظائف قبل إرسال العرض. '.($money['raw']!==''?'السعر المنشور من العميل: '.$money['raw'].'. ':'العميل لم يعلن ميزانية واضحة، لذلك التسعير المعروض تقدير داخلي مبدئي.');
            $costInfo=OpportunityCostEstimator::blend($ev,$base,$pricingCurrency);$cost=max(0,(float)($costInfo['cost']??0));$days=is_numeric($o['estimated_days']??null)?max(1,(int)$o['estimated_days']):max(1,(int)($costInfo['estimated_days']??4));$difficulty=(string)($o['difficulty']??'unknown');if($difficulty==='unknown')$difficulty=(string)($costInfo['difficulty']??'medium');
            $pricing=($currency!=='UNK'&&($money['budget_min']!==null||$money['budget_max']!==null))?PricingPolicy::recommend($money['budget_min'],$money['budget_max'],$cost,(string)$ev['risk_level']):PricingPolicy::recommendFromCost($cost,(string)$ev['risk_level'],$days);$offer=is_numeric($pricing['price']??null)?(float)$pricing['price']:null;$profit=$offer!==null?max(0,$offer-$cost):0;$deposit=$offer!==null?round($offer*max(0,min(100,(float)setting('business.deposit_percent','40')))/100,2):null;
            if($currency==='UNK')$costInfo['source']='internal_'.$pricingCurrency.'_'.(string)($costInfo['source']??'heuristic');
            $ev['title_ar']=$titleAr;$ev['summary_ar']=$summary;$ev['estimated_cost']=$cost;$ev['suggested_offer']=$offer;$ev['estimated_days']=$days;$ev['difficulty']=$difficulty;$ev['budget_min']=$money['budget_min'];$ev['budget_max']=$money['budget_max'];$ev['currency']=$currency;
            $contacts=json_decode((string)($o['contact_methods_json']??''),true);if(!is_array($contacts))$contacts=[];$score=OpportunityScoringService::score($ev,$base,$contacts,$costInfo);
            $shortlistFloor=max(0,min(100,(int)setting('opportunities.shortlist_min_score','70')));
            db()->prepare("UPDATE opportunities SET title_ar=?,summary_ar=?,details_ar=?,budget_min=COALESCE(?,budget_min),budget_max=COALESCE(?,budget_max),currency=CASE WHEN ?<>'UNK' THEN ? ELSE currency END,advertised_budget_text=COALESCE(NULLIF(?,''),advertised_budget_text),estimated_cost=?,cost_estimate_source=?,cost_confidence=?,cost_learning_samples=?,suggested_offer=?,suggested_deposit=?,projected_profit=?,estimated_days=?,difficulty=?,score=?,score_version=?,shortlist_rank=CASE WHEN ?<? THEN NULL ELSE shortlist_rank END,shortlisted_at=CASE WHEN ?<? THEN NULL ELSE shortlisted_at END WHERE id=?")
                ->execute([$titleAr,$summary,$detailsAr,$money['budget_min'],$money['budget_max'],$currency,$currency,$money['raw'],$cost,(string)($costInfo['source']??'heuristic'),(int)($costInfo['confidence']??0),(int)($costInfo['sample_count']??0),$offer,$deposit,$profit,$days,$difficulty,(int)$score['score'],(int)$score['version'],(int)$score['score'],$shortlistFloor,(int)$score['score'],$shortlistFloor,(int)$o['id']]);$updated++;
        }
        return ['checked'=>count($rows),'updated'=>$updated,'rejected'=>$rejected];
    }

    private static function countryHas(string $country,array $needles):bool{$x=pb_strtolower($country);foreach($needles as $n)if(str_contains($x,pb_strtolower($n)))return true;return false;}

    private static function applyShortlist(int $runId):int{
        $limit=max(1,min(50,(int)setting('opportunities.default_shortlist_target','10')));
        db()->prepare("UPDATE opportunities o JOIN opportunity_raw_items r ON r.opportunity_id=o.id SET o.shortlist_rank=NULL,o.shortlisted_at=NULL WHERE r.run_id=?")->execute([$runId]);
        $requireDate=setting('opportunities.shortlist_requires_verified_date','1')==='1';
        $dateSql=$requireDate?" AND o.published_at IS NOT NULL AND o.published_at>=(SELECT date_from FROM opportunity_search_runs WHERE id=? LIMIT 1)":"";
        $minScore=max(0,min(100,(int)setting('opportunities.shortlist_min_score','70')));
        $q=db()->prepare("SELECT DISTINCT o.id FROM opportunity_raw_items r JOIN opportunities o ON o.id=r.opportunity_id WHERE r.run_id=? AND o.status<>'rejected' AND o.fit_status IN ('qualified','review') AND o.score>=?".$dateSql." ORDER BY FIELD(o.fit_status,'qualified','review'),o.score DESC,CASE WHEN o.suggested_offer>0 THEN o.projected_profit/o.suggested_offer ELSE 0 END DESC,o.projected_profit DESC,FIELD(o.difficulty,'easy','medium','unknown','hard'),COALESCE(o.estimated_days,999) ASC,o.published_at DESC LIMIT ".$limit);$args=[$runId,$minScore];if($requireDate)$args[]=$runId;$q->execute($args);$rank=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$rank++;db()->prepare('UPDATE opportunities SET shortlist_rank=?,shortlisted_at=NOW() WHERE id=?')->execute([$rank,(int)$id]);}return $rank;
    }
    private static function fingerprint(string $title,string $client,string $url,string $source,string $desc):string{$domain=(string)(parse_url($url,PHP_URL_HOST)?:'');$norm=static fn(string $x)=>pb_strtolower(trim(preg_replace('/\s+/u',' ',$x)));return hash('sha256',$norm($source).'|'.$norm($url).'|'.$norm($title).'|'.$norm($client).'|'.$norm($domain).'|'.pb_substr($norm($desc),0,600));}
    private static function dateSql(string $date):?string{return OpportunitySourceRules::normalizePublishedAt($date);}
}
