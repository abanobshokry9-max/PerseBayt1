<?php
declare(strict_types=1);

/**
 * Read-only operational audit assembled from the live Company OS database.
 * It deliberately does not expose raw secrets and never treats "configured"
 * as proof that an external service is healthy.
 */
final class RuntimeAuditService {
    private static function rows(string $sql,array $params=[]):array {
        try{$q=db()->prepare($sql);$q->execute($params);return $q->fetchAll()?:[];}catch(Throwable $e){return [['__query_error'=>Security::redactSecrets($e->getMessage(),300)]];}
    }
    private static function row(string $sql,array $params=[]):?array {
        $r=self::rows($sql,$params);if(!$r||isset($r[0]['__query_error']))return $r[0]??null;return $r[0];
    }
    private static function scalar(string $sql,array $params=[],int|float|string $default=0):int|float|string {
        try{$q=db()->prepare($sql);$q->execute($params);$v=$q->fetchColumn();return $v===false?$default:$v;}catch(Throwable){return $default;}
    }
    private static function issue(array &$issues,string $code,string $severity,string $title,string $evidence,string $fix):void {
        $issues[]=['code'=>$code,'severity'=>$severity,'title'=>$title,'evidence'=>$evidence,'fix'=>$fix];
    }
    private static function json(string|null $raw):array {if(!$raw)return [];try{$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}}

    public static function dashboardMetrics():array {
        $business="is_system_project=0 AND COALESCE(source,'manual')<>'hosting_scan'";
        $metrics=[];
        $metrics['raw_today']=(int)self::scalar("SELECT COALESCE(SUM(raw_found),0) FROM opportunity_search_runs WHERE state IN ('completed','partial') AND DATE(COALESCE(completed_at,created_at))=CURDATE()");
        $metrics['qualified_open']=(int)self::scalar("SELECT COUNT(*) FROM opportunities WHERE fit_status='qualified' AND status IN ('new','needs_review')");
        $metrics['active_offers']=(int)self::scalar("SELECT COUNT(*) FROM quotes q LEFT JOIN projects p ON p.id=q.project_id WHERE q.status IN ('draft','owner_review','sent','accepted') AND (q.project_id IS NULL OR (p.is_system_project=0 AND COALESCE(p.source,'manual')<>'hosting_scan' AND p.workflow_stage<>'closed'))");
        $metrics['projects_open']=(int)self::scalar("SELECT COUNT(*) FROM projects WHERE {$business} AND workflow_stage<>'closed'");
        $metrics['tasks_active']=(int)self::scalar("SELECT COUNT(*) FROM tasks t LEFT JOIN projects p ON p.id=t.project_id WHERE t.status IN ('queued','assigned','working','waiting','blocked','needs_review','review_failed','needs_fix','retesting') AND (t.project_id IS NULL OR (p.is_system_project=0 AND COALESCE(p.source,'manual')<>'hosting_scan'))");
        $metrics['customers_active']=(int)self::scalar("SELECT COUNT(*) FROM customers WHERE status IN ('lead','negotiating','active')");
        $metrics['agents_working']=(int)self::scalar("SELECT COUNT(*) FROM agents WHERE is_active=1 AND status='working'");
        $metrics['jobs_queued']=(int)self::scalar("SELECT COUNT(*) FROM jobs WHERE state='queued'");
        $metrics['jobs_running']=(int)self::scalar("SELECT COUNT(*) FROM jobs WHERE state='running'");
        $metrics['jobs_failed_24h']=(int)self::scalar("SELECT COUNT(*) FROM jobs WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        return $metrics;
    }

    public static function snapshot():array {
        try{AgentService::reconcileRuntimeStates();}catch(Throwable){}
        $issues=[];
        $hb=(string)setting('runtime.worker_heartbeat_at','');$hbTs=utc_ts($hb);$lastRunState=(string)setting('runtime.worker_last_run_state','');$lastRunError=(string)setting('runtime.worker_last_run_error','');$lastRunAt=(string)setting('runtime.worker_last_run_at','');$workerHealthy=$hbTs!==false&&(time()-$hbTs)<600&&$lastRunState!=='failed';
        $lastKick=['state'=>(string)setting('runtime.worker_last_kick_state',''),'mode'=>(string)setting('runtime.worker_last_kick_mode',''),'at'=>(string)setting('runtime.worker_last_kick_at',''),'code'=>(string)setting('runtime.worker_last_kick_code','')];
        if(!$workerHealthy){$ev=$hb!==''?'آخر Heartbeat: '.$hb:'لا يوجد Heartbeat مسجل';if($lastRunState==='failed')$ev.=' · آخر تشغيل فشل: '.($lastRunError?:'خطأ غير مسجل');self::issue($issues,'worker_stale','critical','عامل التشغيل غير سليم',$ev,'تحقق من Cron ثم شغّل Worker. إصدار 7.0.7 يصلح خطأ MariaDB في Lease/Claim ويعرض آخر خطأ تشغيل بدل الاعتماد على Heartbeat وحده.');}

        $stuckQueued=(int)self::scalar("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','waiting') AND available_at<=NOW() AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $staleRunning=(int)self::scalar("SELECT COUNT(*) FROM jobs WHERE state='running' AND ((lease_expires_at IS NOT NULL AND lease_expires_at<NOW()) OR updated_at<DATE_SUB(NOW(),INTERVAL 20 MINUTE))");
        if($stuckQueued>0)self::issue($issues,'jobs_stuck_queued','high','Jobs معلقة في الطابور',$stuckQueued.' Job متاحة منذ أكثر من 10 دقائق','شغّل reconcile + Worker وأعد فقط الـJobs القابلة للمحاولة؛ لا تغيّر حالة الوكيل إلى working قبل lock فعلي للـJob.');
        if($staleRunning>0)self::issue($issues,'jobs_stale_running','high','Jobs تظهر Running بدون Lease حي',$staleRunning.' Job Running قديمة','أعدها للطابور عبر reconciliation وسجّل stale_worker_recovered.');

        $walid=self::row("SELECT a.id,a.status,a.current_task_id,a.last_seen_at,a.last_error_code FROM agents a WHERE a.slug='walid' LIMIT 1")?:[];
        $walidJob=self::row("SELECT j.id,j.state,j.attempts,j.max_attempts,j.error_code,j.available_at,j.started_at,j.finished_at,j.updated_at,t.status task_status,t.title task_title FROM jobs j LEFT JOIN tasks t ON t.id=j.task_id WHERE j.agent_id=? AND j.kind IN ('walid_opportunity_search','walid_scan') ORDER BY j.id DESC LIMIT 1",[(int)($walid['id']??0)])?:[];
        $walidRun=self::row("SELECT * FROM opportunity_search_runs WHERE agent_id=? ORDER BY id DESC LIMIT 1",[(int)($walid['id']??0)])?:[];
        $walidSummary=self::json((string)($walidRun['summary_json']??''));
        if($walidJob){
            $state=(string)($walidJob['state']??'');$updated=utc_ts((string)($walidJob['updated_at']??''));
            if(in_array($state,['queued','waiting'],true)&&$updated!==false&&$updated<time()-600)self::issue($issues,'walid_job_not_consumed','critical','مهمة وليد دخلت Queue ولم يلتقطها Worker','Job #'.($walidJob['id']??'?').' حالتها '.$state.' منذ '.($walidJob['updated_at']??''),'إصلاح Worker/Cron/self-kick أولًا؛ اتصال الوكيل أو AI وحده لا ينفذ البحث.');
            if($state==='failed')self::issue($issues,'walid_job_failed','high','آخر Job لوليد فشلت',(string)($walidJob['error_code']??'بدون error_code'),'اعرض error_code وتشخيص Search Run ثم أصلح المزود أو AI route قبل إعادة المحاولة.');
        }
        if($walidRun){
            $runState=(string)($walidRun['state']??'');
            if($runState==='failed')self::issue($issues,'walid_run_failed','high','آخر Search Run لوليد فشل',(string)($walidSummary['error']??'Search Run failed'),'راجع search_diagnostics/warnings ولا تعتبر zero result نجاحًا إذا كانت كل المحركات متعطلة.');
            if(in_array($runState,['completed','partial'],true)&&(int)($walidRun['raw_found']??0)===0){
                $diag=(array)($walidSummary['search_diagnostics']??[]);$evidence=$diag?'المحركات رجعت صفر/تحذيرات؛ راجع تشخيص المزود.':'Run اكتمل بدون نتائج خام.';
                self::issue($issues,'walid_zero_raw','medium','بحث وليد لم ينتج فرصًا خامًا',$evidence,'استخدم Search API موثوقًا أو OpenRouter Web Search ثم Buyer Intent Gate؛ لا تعتمد على DuckDuckGo/Bing العام وحدهما.');
            }
        }
        $searchConfigured=false;$searchProvider='';try{$searchConfigured=WebSearchClient::configured();$searchProvider=WebSearchClient::provider();}catch(Throwable $e){$searchProvider='error:'.Security::redactSecrets($e->getMessage(),120);}
        if(!$searchConfigured)self::issue($issues,'walid_search_provider_unavailable','critical','لا يوجد مزود بحث فعلي جاهز لوليد','المزود الحالي: '.($searchProvider?:'غير محدد'),'اربط Serper/Brave/Tavily أو فعّل OpenRouter Web Search. OpenRouter كـAI Primary لا يعني أن Web Search تعمل تلقائيًا.');

        $opQuality=[
            'open_total'=>(int)self::scalar("SELECT COUNT(*) FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review')"),
            'missing_title_ar'=>(int)self::scalar("SELECT COUNT(*) FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') AND (title_ar IS NULL OR title_ar='')"),
            'missing_details_ar'=>(int)self::scalar("SELECT COUNT(*) FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') AND (details_ar IS NULL OR details_ar='')"),
            'budget_numeric'=>(int)self::scalar("SELECT COUNT(*) FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') AND (budget_min IS NOT NULL OR budget_max IS NOT NULL)"),
            'missing_advertised_text'=>(int)self::scalar("SELECT COUNT(*) FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') AND (budget_min IS NOT NULL OR budget_max IS NOT NULL) AND (advertised_budget_text IS NULL OR advertised_budget_text='')"),
            'missing_offer_with_budget'=>(int)self::scalar("SELECT COUNT(*) FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') AND (budget_min IS NOT NULL OR budget_max IS NOT NULL) AND (suggested_offer IS NULL OR suggested_offer<=0)"),
        ];
        if($opQuality['missing_title_ar']>0||$opQuality['missing_details_ar']>0)self::issue($issues,'walid_arabic_data_incomplete','medium','بيانات عربية ناقصة في فرص مفتوحة',$opQuality['missing_title_ar'].' عنوان عربي ناقص · '.$opQuality['missing_details_ar'].' تفاصيل عربية ناقصة','شغّل بحثًا جديدًا بعد 7.0.7؛ title_ar/summary_ar/details_ar أصبحت حقولًا إلزامية في تقييم وليد.');
        if($opQuality['missing_advertised_text']>0)self::issue($issues,'walid_budget_evidence_incomplete','medium','السعر المعلن غير محفوظ نصيًا لبعض الفرص',$opQuality['missing_advertised_text'].' فرصة بها أرقام ميزانية بلا advertised_budget_text','7.0.7 يحفظ النص المالي كما ظهر في المصدر إلى جانب min/max والعملـة.');

        $orConfigured=false;try{$orConfigured=OpenRouterService::configured();}catch(Throwable){}
        $activeAgents=(int)self::scalar("SELECT COUNT(*) FROM agents WHERE is_active=1");
        $orPrimary=(int)self::scalar("SELECT COUNT(DISTINCT a.id) FROM agents a JOIN agent_provider_routes r ON r.agent_id=a.id AND r.route_order=1 AND r.provider_key='openrouter' AND r.enabled=1 WHERE a.is_active=1");
        if($orConfigured&&$orPrimary<$activeAgents)self::issue($issues,'openrouter_not_primary_for_all','medium','OpenRouter ليس Primary لكل الوكلاء',$orPrimary.' من '.$activeAgents.' وكيل نشط','شغّل OpenRouterService::promoteAllAgents ثم اترك المسارات الأخرى Fallback.');
        if(!$orConfigured)self::issue($issues,'openrouter_not_configured','high','OpenRouter غير مُهيأ في الخزنة','المفتاح غير متاح لخدمة OpenRouter','احفظ API Key في Vault واختبره قبل الترقية إلى Primary.');

        $ramyId=(int)self::scalar("SELECT id FROM agents WHERE slug='ramy' LIMIT 1");
        $permTotal=(int)self::scalar("SELECT COUNT(*) FROM permissions WHERE permission_key<>'secrets.view'");
        $ramyPerm=(int)self::scalar("SELECT COUNT(*) FROM agent_permissions ap JOIN permissions p ON p.permission_key=ap.permission_key WHERE ap.agent_id=? AND ap.allowed=1 AND p.permission_key<>'secrets.view'",[$ramyId]);
        $ramySecret=(int)self::scalar("SELECT COUNT(*) FROM agent_permissions WHERE agent_id=? AND permission_key='secrets.view' AND allowed=1",[$ramyId]);
        if($ramyPerm<$permTotal)self::issue($issues,'ramy_permissions_incomplete','high','صلاحيات رامي التشغيلية غير مكتملة',$ramyPerm.' من '.$permTotal.' Permission','نفّذ مزامنة RamiAuthorityService؛ تمنح كل الصلاحيات التشغيلية وتستثني فقط عرض الأسرار الخام.');
        if($ramySecret>0)self::issue($issues,'ramy_raw_secret_access','critical','رامي يملك صلاحية عرض أسرار خام','secrets.view مفعّلة','عطّل secrets.view واترك secrets.use فقط.');

        $latestHostinger=self::row("SELECT state,result_code,details_json,created_at FROM connection_tests WHERE provider_key='hostinger' ORDER BY id DESC LIMIT 1")?:[];
        $hostConfigured=trim((string)config('hostinger.api_token',''))!=='';
        if(!$hostConfigured)self::issue($issues,'hostinger_not_configured','critical','Hostinger API غير مُهيأ','لا يوجد Token في Runtime config','احفظ Token في Vault ثم اختبر read-only websites endpoint.');
        elseif($latestHostinger&&($latestHostinger['state']??'')!=='verified')self::issue($issues,'hostinger_last_test_failed','high','آخر اختبار Hostinger فشل',(string)($latestHostinger['result_code']??'unknown'),'أعد اختبار read-only ثم افحص صلاحية/انتهاء Token وHTTP code قبل أي تعديل.');

        $systemErrors=self::rows("SELECT error_ref,request_uri,http_method,error_class,error_message,actor_type,actor_id,created_at FROM system_errors ORDER BY id DESC LIMIT 100");
        $recentSystemErrors=0;foreach($systemErrors as $er){$ts=utc_ts((string)($er['created_at']??''));if($ts!==false&&$ts>=time()-86400)$recentSystemErrors++;}
        if($recentSystemErrors>0)self::issue($issues,'system_errors_24h','high','هناك Exceptions مسجلة آخر 24 ساعة',$recentSystemErrors.' خطأ في system_errors','راجع المرجع URI/class/message من مركز صحة النظام وأصلح السبب الجذري بدل إخفاء الرسالة.');

        $failedJobs=self::rows("SELECT j.id,j.kind,j.state,j.attempts,j.max_attempts,j.error_code,j.created_at,j.updated_at,a.slug agent_slug,a.display_name agent_name,t.title task_title,p.name project_name FROM jobs j LEFT JOIN agents a ON a.id=j.agent_id LEFT JOIN tasks t ON t.id=j.task_id LEFT JOIN projects p ON p.id=j.project_id WHERE j.state IN ('failed','waiting') ORDER BY j.id DESC LIMIT 100");
        $auditFailures=self::rows("SELECT id,actor_type,actor_id,action,entity_type,entity_id,project_id,task_id,result,metadata_json,created_at FROM audit_logs WHERE result IN ('failed','blocked') ORDER BY id DESC LIMIT 100");
        $webhookFailures=self::rows("SELECT id,provider,event_type,signature_valid,processing_state,error_code,created_at FROM webhook_events WHERE processing_state='failed' ORDER BY id DESC LIMIT 100");
        $tests=self::rows("SELECT c.provider_key,c.state,c.result_code,c.details_json,c.created_at FROM connection_tests c JOIN (SELECT provider_key,MAX(id) id FROM connection_tests GROUP BY provider_key) x ON x.id=c.id ORDER BY c.provider_key");
        $agents=self::rows("SELECT id,slug,display_name,status,current_task_id,last_seen_at,last_error_code,default_ai_provider,force_openrouter_primary FROM agents ORDER BY FIELD(slug,'ramy','walid','ayman','emad'),id");

        $severityRank=['critical'=>0,'high'=>1,'medium'=>2,'low'=>3];usort($issues,static fn($a,$b)=>($severityRank[$a['severity']]??9)<=>($severityRank[$b['severity']]??9));
        return [
            'release'=>ReleaseInfo::VERSION,'generated_at'=>now_utc(),'issues'=>$issues,
            'worker'=>['healthy'=>$workerHealthy,'heartbeat_at'=>$hb?:null,'last_run_state'=>$lastRunState,'last_run_error'=>$lastRunError,'last_run_at'=>$lastRunAt?:null,'last_wakeup'=>$lastKick,'stuck_queued'=>$stuckQueued,'stale_running'=>$staleRunning],
            'walid'=>['agent'=>$walid,'last_job'=>$walidJob,'last_run'=>$walidRun,'summary'=>$walidSummary,'search_configured'=>$searchConfigured,'search_provider'=>$searchProvider],
            'openrouter'=>['configured'=>$orConfigured,'active_agents'=>$activeAgents,'primary_agents'=>$orPrimary],
            'ramy'=>['id'=>$ramyId,'permissions_granted'=>$ramyPerm,'permissions_expected'=>$permTotal,'raw_secret_view'=>$ramySecret>0],
            'hostinger'=>['configured'=>$hostConfigured,'last_test'=>$latestHostinger],
            'dashboard'=>self::dashboardMetrics(),'opportunity_quality'=>$opQuality,'agents'=>$agents,'connection_tests'=>$tests,
            'system_errors'=>$systemErrors,'failed_jobs'=>$failedJobs,'audit_failures'=>$auditFailures,'webhook_failures'=>$webhookFailures,
        ];
    }
}
