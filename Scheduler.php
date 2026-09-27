<?php
declare(strict_types=1);
final class Scheduler {
    public static function ensureWorkerCronIfStale(int $maxAgeSeconds=300):bool{
        $last=(string)setting('runtime.worker_heartbeat_at','');
        if($last!==''&&utc_ts($last)!==false&&utc_ts($last)>=time()-max(60,$maxAgeSeconds))return true;
        $attempt=(string)setting('runtime.worker_cron_check_at','');
        if($attempt!==''&&utc_ts($attempt)!==false&&utc_ts($attempt)>=time()-600)return false;
        put_setting('runtime.worker_cron_check_at',now_utc());
        try{$domain=(string)(parse_url((string)config('app.base_url',''),PHP_URL_HOST)?:'persebayt.com');HostingerClient::ensureWorkerCron($domain,'* * * * *');return true;}catch(Throwable $e){error_log('ELMETR worker cron ensure: '.Security::redactSecrets($e->getMessage()));try{Notifications::add('warning','system','عامل التشغيل محتاج مراجعة','تم تسجيل المهمة لكن تعذر التأكد من Cron تلقائيًا: '.AdminUi::humanError(Security::redactSecrets($e->getMessage())),'provider','hostinger');}catch(Throwable){}return false;}
    }
    private static function due(string $settingKey,int $seconds):bool{
        $last=(string)setting($settingKey,'');$ts=utc_ts($last);return $ts===false||$ts<time()-max(60,$seconds);
    }
    public static function tick():array{
        $out=['hunt_queued'=>false,'inventory_queued'=>false,'whatsapp_recovery'=>null,'social_queue'=>null,'social_reconcile'=>null,'social_metrics'=>null,'reviews_reconciled'=>0,'orphaned_tasks_reconciled'=>0,'expired_actions'=>0,'stale_jobs'=>0,'dead_jobs'=>0,'autonomy'=>null,'followups'=>null];
        db()->exec("UPDATE pending_actions SET state='expired' WHERE state='pending' AND expires_at<=NOW()");
        $out['expired_actions']=(int)db()->query('SELECT ROW_COUNT()')->fetchColumn();
        db()->exec("UPDATE jobs SET state='queued',locked_at=NULL,lease_token=NULL,lease_expires_at=NULL,available_at=DATE_ADD(NOW(),INTERVAL 2 MINUTE),next_retry_at=DATE_ADD(NOW(),INTERVAL 2 MINUTE),error_code=COALESCE(error_code,'stale_worker_recovered') WHERE state='running' AND ((lease_expires_at IS NOT NULL AND lease_expires_at<NOW()) OR (lease_expires_at IS NULL AND locked_at<DATE_SUB(NOW(),INTERVAL 20 MINUTE))) AND attempts<max_attempts");
        $out['stale_jobs']=(int)db()->query('SELECT ROW_COUNT()')->fetchColumn();
        $dead=db()->query("SELECT id,task_id FROM jobs WHERE state='running' AND ((lease_expires_at IS NOT NULL AND lease_expires_at<NOW()) OR (lease_expires_at IS NULL AND locked_at<DATE_SUB(NOW(),INTERVAL 20 MINUTE))) AND attempts>=max_attempts")->fetchAll();
        foreach($dead as $job){
            db()->prepare("UPDATE jobs SET state='failed',locked_at=NULL,lease_token=NULL,lease_expires_at=NULL,finished_at=NOW(),error_code=COALESCE(error_code,'stale_worker_exhausted') WHERE id=? AND state='running'")->execute([(int)$job['id']]);
            if($job['task_id']){try{$t=TaskService::get((int)$job['task_id']);if(!in_array($t['status'],['completed','cancelled','failed'],true))TaskService::fail((int)$job['task_id'],'stale_worker_exhausted','انتهت آخر محاولة للعامل دون اكتمال موثّق.');}catch(Throwable){}}
        }
        $out['dead_jobs']=count($dead);
        try{$out['orphaned_tasks_reconciled']=TaskService::reconcileOrphanedJobs(30);}catch(Throwable $e){error_log('ELMETR orphan task scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['reviews_reconciled']=Workflow::reconcilePendingReviews(20);}catch(Throwable $e){error_log('ELMETR review reconcile: '.Security::redactSecrets($e->getMessage()));}
        try{$out['walid_runs_reconciled']=OpportunitySearchService::reconcileRuns(30);}catch(Throwable $e){error_log('ELMETR walid run reconcile: '.Security::redactSecrets($e->getMessage(),180));}

        if(setting('whatsapp.auto_resume_pending','1')!=='0'&&MetaClient::configured()){
            try{
                $pending=(int)db()->query("SELECT COUNT(*) FROM whatsapp_pending_messages WHERE state IN ('pending','failed') AND sent_at IS NULL")->fetchColumn();
                $templatePending=strtoupper((string)setting('whatsapp.template_submission_state',''))==='PENDING';
                $minutes=max(2,min(60,(int)setting('whatsapp.auto_recovery_interval_minutes','5')));
                $last=(string)setting('whatsapp.last_auto_recovery_at','');$due=$last===''||utc_ts($last)===false||utc_ts($last)<time()-($minutes*60);
                if($due&&($pending>0||$templatePending)){
                    put_setting('whatsapp.last_auto_recovery_at',now_utc());
                    try{$wr=CommunicationGateway::recoverWhatsAppQueue(12);$out['whatsapp_recovery']=$wr;put_setting('whatsapp.last_auto_recovery_state','ok');put_setting('whatsapp.last_auto_recovery_error','');put_setting('whatsapp.last_auto_recovery_result',j($wr));}
                    catch(Throwable $e){$safe=pb_substr(Security::redactSecrets($e->getMessage()),0,300);put_setting('whatsapp.last_auto_recovery_state','failed');put_setting('whatsapp.last_auto_recovery_error',$safe);$out['whatsapp_recovery']=['error'=>AdminUi::humanError($safe)];error_log('ELMETR whatsapp auto recovery: '.$safe);}
                }
            }catch(Throwable $e){error_log('ELMETR whatsapp scheduler: '.Security::redactSecrets($e->getMessage()));}
        }

        try{$out['social_queue']=SocialPublisher::queueDue(30);}catch(Throwable $e){error_log('ELMETR social scheduler queue: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['social_reconcile']=SocialPublisher::reconcilePending(30);}catch(Throwable $e){error_log('ELMETR social scheduler reconcile: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['social_metrics']=SocialMetricsService::scheduledRefresh();}catch(Throwable $e){error_log('ELMETR social metrics scheduler: '.Security::redactSecrets($e->getMessage(),180));}

        if(setting('opportunities.auto_hunt','0')==='1'){
            $hours=max(1,(int)setting('opportunities.hunt_interval_hours','24'));
            $last=db()->query("SELECT completed_at FROM opportunity_search_runs WHERE state IN ('completed','partial') ORDER BY id DESC LIMIT 1")->fetchColumn();
            $due=!$last||utc_ts((string)$last)<time()-($hours*3600);
            $pending=(int)db()->query("SELECT COUNT(*) FROM opportunity_search_runs WHERE state IN ('queued','running')")->fetchColumn();
            if($due&&!$pending){try{$walid=AgentService::bySlug('walid');if(AgentService::runnable($walid)){Workflow::huntOpportunities('بحث دوري تلقائي كل '.$hours.' ساعة');$out['hunt_queued']=true;}}catch(Throwable $e){error_log('ELMETR opportunity auto hunt: '.Security::redactSecrets($e->getMessage()));}}
        }

        if(setting('hosting.auto_inventory','0')==='1'){
            $hours=max(6,(int)setting('hosting.inventory_interval_hours','48'));
            $last=db()->query("SELECT completed_at FROM project_scans WHERE state='completed' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $due=!$last||utc_ts((string)$last)<time()-($hours*3600);
            $pending=(int)db()->query("SELECT COUNT(*) FROM project_scans WHERE state IN ('queued','running')")->fetchColumn();
            if($due&&!$pending){try{Workflow::scanHosting('فهرسة Hostinger دورية كل '.$hours.' ساعة');$out['inventory_queued']=true;}catch(Throwable $e){error_log('ELMETR hosting auto inventory: '.Security::redactSecrets($e->getMessage()));}}
        }
        try{$out['followups']=AgentFollowupService::due(20);}catch(Throwable $e){error_log('ELMETR followup scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['autonomy']=AgentAutonomyService::tick(max(4,min(12,(int)setting('agents.autonomy_tick_limit','12'))));}catch(Throwable $e){error_log('ELMETR autonomy scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['workflow_tick']=AgentWorkflowService::tick(20);}catch(Throwable $e){error_log('ELMETR workflow tick scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{if(setting('agents.workflow_runtime_enabled','1')==='1'){$out['workflow_cycles']=self::workflowCycles();}}catch(Throwable $e){error_log('ELMETR workflow cycle scheduler: '.Security::redactSecrets($e->getMessage(),180));}

        try{
            if(setting('agency.events_enabled','1')==='1'&&self::due('agency.events_last_scheduler_at',30*60)){
                put_setting('agency.events_last_scheduler_at',now_utc());$out['agency_events']=AgencyEventService::generate();
            }
        }catch(Throwable $e){error_log('ELMETR agency event scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{
            if(setting('prospecting.enabled','1')==='1'){$hours=max(2,min(168,(int)setting('prospecting.interval_hours','12')));if(self::due('prospecting.last_scheduler_at',$hours*3600)){$pending=(int)db()->query("SELECT COUNT(*) FROM tasks t JOIN agents a ON a.id=t.assigned_agent_id WHERE a.slug='walid' AND t.status IN ('queued','assigned','working','waiting') AND t.context_json LIKE '%walid_company_prospecting%'")->fetchColumn();if(!$pending){put_setting('prospecting.last_scheduler_at',now_utc());$out['prospecting']=ProspectingService::queue((string)setting('prospecting.default_market','egypt_arab'));}}}
        }catch(Throwable $e){error_log('ELMETR prospecting scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{
            if(setting('agents.performance_enabled','1')==='1'&&self::due('agents.performance_last_scheduler_at',6*3600)){put_setting('agents.performance_last_scheduler_at',now_utc());$out['performance']=AgentPerformanceService::snapshotAll();}
        }catch(Throwable $e){error_log('ELMETR performance scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{
            if(setting('agents.brain_enabled','1')==='1'&&self::due('agents.brain_last_consolidation_at',24*3600)){put_setting('agents.brain_last_consolidation_at',now_utc());$brain=[];foreach(db()->query("SELECT id,slug FROM agents WHERE is_active=1 AND status<>'disabled' ORDER BY id")->fetchAll() as $a){try{$brain[$a['slug']]=AgentBrainService::consolidateAgent((int)$a['id']);}catch(Throwable $e){$brain[$a['slug']]=['state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}}$out['brain_consolidation']=$brain;}
        }catch(Throwable $e){error_log('ELMETR brain scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{
            if(setting('goals.metric_sync_enabled','1')==='1'&&self::due('goals.metric_sync_last_scheduler_at',15*60)){put_setting('goals.metric_sync_last_scheduler_at',now_utc());$out['goal_metrics']=GoalMetricEngine::syncAll();}
        }catch(Throwable $e){error_log('ELMETR goal metric scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{
            if(self::due('providers.capability_sync_last_at',15*60)){put_setting('providers.capability_sync_last_at',now_utc());$out['provider_capabilities']=ProviderCapabilityRouter::syncFromProviders();}
        }catch(Throwable $e){error_log('ELMETR provider capability scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['media_poll']=MediaBridgeClient::pollPending(20);}catch(Throwable $e){error_log('ELMETR media poll scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['agency_due']=AgencyEventService::dispatchDue(20);}catch(Throwable $e){error_log('ELMETR agency due scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{
            if(setting('runtime.validation.scheduler_enabled','1')==='1')$out['runtime_validation']=RuntimeValidationService::scheduled();
        }catch(Throwable $e){error_log('ELMETR runtime validation scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{
            if(setting('retention.scheduler_enabled','1')==='1'&&self::due('retention.last_scheduler_at',24*3600)){put_setting('retention.last_scheduler_at',now_utc());$out['retention']=RetentionService::run();}
        }catch(Throwable $e){error_log('ELMETR retention scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{$out['free_model_scout']=FreeModelScoutService::scheduled();}catch(Throwable $e){error_log('ELMETR free model scout scheduler: '.Security::redactSecrets($e->getMessage(),220));}
        try{WalidLearningService::sync();}catch(Throwable $e){error_log('ELMETR walid learning scheduler: '.Security::redactSecrets($e->getMessage(),180));}
        try{AgentService::reconcileRuntimeStates();}catch(Throwable $e){error_log('ELMETR agent reconcile: '.Security::redactSecrets($e->getMessage(),180));}
        return $out;
    }

    private static function workflowCycles():array{
        $spec=[
            ['ramy','ramy_daily_review',24*3600],
            ['walid','walid_learning_review',24*3600],
            ['basant','basant_creator_review',6*3600],
            ['emad','emad_retest_queue',4*3600],
        ];$out=[];
        foreach($spec as [$slug,$key,$seconds]){try{$setting='workflow.cycle.'.$key.'.last_at';if(!self::due($setting,$seconds))continue;$a=AgentService::bySlug($slug);if(!AgentService::runnable($a))continue;put_setting($setting,now_utc());$out[$key]=AgentWorkflowService::execute((int)$a['id'],$key,['scheduler'=>true]);}catch(Throwable $e){$out[$key]=['state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}}
        return $out;
    }
}

