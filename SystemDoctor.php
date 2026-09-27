<?php
declare(strict_types=1);
final class SystemDoctor {
    private const REQUIRED_TABLES=[
        'agents','agent_tools','agent_permissions','agent_memory','agent_learning','agent_provider_routes','agent_data_schemas','agent_data_fields','agent_data_rows','tasks','jobs','task_events','task_evidence','task_dependencies','notifications','settings','team_chat_messages',
        'opportunities','opportunity_sources','opportunity_search_runs','opportunity_raw_items','opportunity_fingerprints','opportunity_cost_observations',
        'projects','project_stage_events','project_briefs','project_chat_messages','project_memory','project_scans','project_databases','project_files','project_domains','project_assets','project_issues','project_cost_entries',
        'customers','customer_memory','customer_projects','quotes','payments','payment_methods','reviews','review_tests','providers','users','audit_logs','backups','deployments','connection_tests','webhook_events','calls','communication_events','messages','communication_channels','conversation_sessions','conversations','pending_actions','change_requests','agent_runs','agent_project_access','agent_relationships','agent_channel_permissions','permissions','company_memory','owner_channel_routes','owner_decision_requests','system_errors','whatsapp_pending_messages','ramy_repair_runs','cloud_objects','release_history',
        'social_accounts','social_contacts','social_interactions','social_content','social_publications','social_metrics','video_productions',
        'agent_autonomy','agent_initiatives','agent_change_proposals','agent_prompt_versions','agent_followups','agent_permission_sources','agent_tool_sources','agent_change_rollbacks','agent_role_templates','service_accounts','contact_directory','security_targets','security_authorizations','security_test_cases','security_test_runs','security_test_events','security_findings','security_retests','lab_environments','agency_imports','agency_creators','agency_creator_monthly','agency_creator_policies',
        'agent_memory_bank','agent_memory_links','agent_capability_catalog','agent_capability_assignments','company_goals','company_goal_agents','company_goal_events','agent_kpi_definitions','agent_kpi_daily','agent_performance_snapshots','agent_budget_policies','provider_pricing','agent_usage_ledger','opportunity_feedback','prospecting_runs','prospect_leads','agency_events','agency_threads','agency_thread_messages','video_scenes','video_render_jobs','social_playbooks','social_playbook_steps','social_account_runs','runtime_validation_runs','runtime_validation_steps','runtime_validation_scenarios','worker_heartbeat_log','whatsapp_call_sessions','agent_external_actions','prospect_website_audits','prospect_contact_evidence','prospect_prototypes','goal_metric_definitions','agent_budget_reservations','video_continuity_assets','video_audio_assets','social_playbook_step_runs','provider_capability_routes','data_retention_policies','system_archives','schema_migration_steps','agent_policy_rules','agent_policy_decisions','agent_usage_daily','agent_usage_events','agent_goal_contributions','creator_intelligence_snapshots','resource_registry','resource_registry_events','resource_requests','physical_schema_requests','agent_schema_requests','agent_workflow_templates','agent_workflow_steps','agent_workflow_assignments','agent_workflow_runs','agent_workflow_run_steps','voice_conversations','voice_turns','agent_capability_route_preferences','data_source_registry','schema_table_catalog','secure_vault_snapshots','secure_config_snapshots','agent_builder_profiles'
    ];
    private const REQUIRED_COLUMNS=[
        'agents'=>['current_task_id','last_seen_at','last_error_code'],
        'opportunities'=>['title_ar','contact_methods_json','advertised_budget_text','cost_confidence','cost_learning_samples','score_version','buyer_intent_evidence_json','contact_verified_at'],
        'projects'=>['opportunity_id','workflow_stage','hosting_presence','current_brief_id'],
        'project_databases'=>['environment','source_database_id'],
        'project_issues'=>['page_label','url','file_path','expected_text','actual_text','repro_steps','blocking_delivery','fix_task_id','retest_review_id','cycle_no','device','browser'],
        'jobs'=>['lease_token','lease_expires_at','next_retry_at','duration_ms'],
        'quotes'=>['deposit_percent','owner_exception_required','negotiation_notes'],
        'agent_learning'=>['promoted_to_instruction_at','owner_note'],
        'agent_data_schemas'=>['schema_key','scope','is_active'],
        'agent_data_fields'=>['field_key','field_type','is_required','sort_order'],
        'agent_data_rows'=>['agent_id','project_id','data_json','created_by_type'],
        'social_publications'=>['requested_by_agent_id','owner_approved','job_id','attempts','last_checked_at','metadata_json','updated_at'],
        'agent_memory_bank'=>['memory_kind','body_text','embedding_json','confidence','salience','last_used_at'],
        'agent_capability_catalog'=>['capability_key','tool_key','permission_keys_json','enabled','version_no','default_access_level','risk_level','requires_owner_approval','requires_backup','external_action','production_sensitive'],
        'agent_capability_assignments'=>['access_level','requires_owner_approval','requires_backup'],
        'agent_autonomy'=>['brain_enabled','max_initiatives_per_day','initiative_min_value','auto_execute_max_risk','daily_ai_call_budget','daily_external_action_budget','daily_search_budget','daily_media_budget','daily_voice_budget','daily_browser_budget'],
        'agent_initiatives'=>['uid','goal_id','summary','proposed_action','value_score','risk_level','requires_owner_approval','dedupe_key','expected_delta','actual_delta'],
        'agent_policy_decisions'=>['permission_key','action_key','project_id','task_id','decision_state','owner_approval_required','owner_approved','reason_json','rule_id'],
        'agent_workflow_steps'=>['step_no','action_key','blocking','on_failure','risk_level','requires_owner_approval','enabled'],
        'agent_workflow_runs'=>['source_task_id','current_step','waiting_task_id','result_json','policy_decision_id','error_text','error_code','completed_at','finished_at'],
        'provider_capability_routes'=>['capability','route_order','provider_key','model_key','health_state','last_checked_at','last_error','last_latency_ms','estimated_cost_usd','cost_unit','updated_at'],
        'voice_conversations'=>['uid','agent_id','state','provider_key'],
        'resource_registry'=>['resource_type','resource_key','state'],
        'company_goals'=>['title','state','priority','progress_percent','due_at'],
        'agent_budget_policies'=>['daily_ai_usd','monthly_ai_usd','daily_search_usd','monthly_media_usd','hard_stop'],
        'prospect_leads'=>['company_name','website_state','contact_methods_json','opportunity_reason','status'],
        'agency_events'=>['creator_id','supervisor_contact_id','event_type','state','thread_id','remind_at','next_action_at','resolution_code','reply_confidence','dedupe_key'],
        'video_scenes'=>['production_id','scene_no','prompt_text','continuity_json','state','asset_id','approval_required','approved_at'],
        'runtime_validation_runs'=>['scenario_key','mode_key','state','summary_json'],
        'agent_change_proposals'=>['before_snapshot_json','after_snapshot_json','rollback_state','rolled_back_at'],
        'agent_followups'=>['resolution_state','resolution_note','resolved_task_id','last_executed_at'],
        'company_goals'=>['progress_percent','forecast_value','health_state','last_metric_sync_at','completed_at'],
        'media_requests'=>['provider_job_id','idempotency_key','next_poll_at','last_checked_at','provider_state','metadata_json'],
        'secure_vault_snapshots'=>['envelope_b64','envelope_sha256','source_ref','state'],
        'secure_config_snapshots'=>['envelope_b64','envelope_sha256','config_sha256','source_ref','state'],
    ];
    public static function schemaIssues():array {
        $issues=[];try{$pdo=db();$db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');$q->execute([$db]);$tables=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);foreach(self::REQUIRED_TABLES as $t)if(empty($tables[$t]))$issues[]='missing_table:'.$t;foreach(self::REQUIRED_COLUMNS as $table=>$columns){if(empty($tables[$table]))continue;$q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$db,$table]);$have=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);foreach($columns as $c)if(empty($have[$c]))$issues[]='missing_column:'.$table.'.'.$c;}}catch(Throwable $e){$issues[]='database_error:'.Security::redactSecrets($e->getMessage(),180);}return $issues;
    }
    public static function schemaReady():bool{return self::schemaIssues()===[];}
    public static function recentErrors(int $limit=30,int $hours=48):array{$limit=max(1,min(100,$limit));$hours=max(1,min(720,$hours));try{$q=db()->prepare('SELECT error_ref,request_uri,http_method,error_class,error_message,actor_type,actor_id,created_at FROM system_errors WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? HOUR) ORDER BY id DESC LIMIT '.$limit);$q->execute([$hours]);return $q->fetchAll();}catch(Throwable){return [];}}
    public static function report():array {
        $r=['checks'=>[],'schema_issues'=>self::schemaIssues()];if(!$r['schema_issues'])ReleaseInfo::activate();$add=static function(string $key,bool $ok,string $note='')use(&$r):void{$r['checks'][$key]=['ok'=>$ok,'note'=>$note];};
        $add('php_version',version_compare(PHP_VERSION,'8.1.0','>='),'PHP '.PHP_VERSION);foreach(['curl','openssl','pdo_mysql','json','mbstring'] as $ext)$add($ext,extension_loaded($ext),'امتداد '.$ext.' '.(extension_loaded($ext)?'مفعّل':'غير مفعّل'));
        try{db()->query('SELECT 1')->fetchColumn();$add('database',true,'اتصال قاعدة البيانات يعمل');}catch(Throwable $e){$add('database',false,Security::redactSecrets($e->getMessage(),180));}
        $add('database_reconnect_guard',method_exists(Database::class,'isDisconnect')&&method_exists(Database::class,'reconnect'),'حماية إعادة الاتصال بعد طلبات AI الطويلة '.((method_exists(Database::class,'isDisconnect')&&method_exists(Database::class,'reconnect'))?'مفعّلة':'غير مفعّلة'));
        $add('schema',!$r['schema_issues'],$r['schema_issues']?implode('، ',array_slice($r['schema_issues'],0,12)):'الهيكل النظيف جاهز');
        try{$ver=(string)setting('system.version','');$add('version',$ver===ReleaseInfo::VERSION,$ver?:'غير مسجل');}catch(Throwable $e){$add('version',false,$e->getMessage());}
        try{AgentService::reconcileRuntimeStates();foreach(['ramy','walid','ayman','emad','samir-social','video-director','community-manager','basant'] as $slug){$a=AgentService::bySlug($slug);$add('agent_'.$slug,(int)$a['is_active']===1&&(string)$a['status']!=='disabled',$a['display_name'].' — '.AdminUi::label((string)$a['status']).(!empty($a['current_task_id'])?' — المهمة #'.$a['current_task_id']:''));}}catch(Throwable $e){$add('agents',false,$e->getMessage());}
        try{$limit=(int)setting('context.history_limit','50');$add('ramy_context',$limit>=20,'آخر '.$limit.' رسالة + Context Snapshot');}catch(Throwable $e){$add('ramy_context',false,$e->getMessage());}
        try{$add('web_search',WebSearchClient::configured(),'المزود: '.WebSearchClient::provider().' · Generic fallback '.(setting('opportunities.disable_generic_search_feeds','1')==='1'?'مقيد':'مسموح'));}catch(Throwable $e){$add('web_search',false,$e->getMessage());}
        try{$add('staging',StagingService::rootDomain()==='nourmakkah.com','محطة الاختبار: '.StagingService::rootDomain());}catch(Throwable $e){$add('staging',false,$e->getMessage());}
        $aiKey=trim((string)config('ai.api_key',''));$add('openai',strlen($aiKey)>=20,'إعداد OpenAI موجود؛ الاختبار الفعلي من صفحة الربط');$host=trim((string)config('hostinger.api_token',''));$add('hostinger',strlen($host)>=20,$host!==''?'إعداد Hostinger موجود؛ الاختبار الفعلي من صفحة الربط':'غير مربوط');
        $meta=trim((string)config('meta.access_token',''));$phone=trim((string)config('meta.phone_number_id',''));$verify=trim((string)config('meta.verify_token',''));$secret=trim((string)config('meta.app_secret',''));$metaConfig=strlen($meta)>30&&preg_match('/^[0-9]{5,30}$/',$phone)===1;$add('whatsapp',$metaConfig,$phone!==''?'إعداد Meta محفوظ':'بيانات Meta ناقصة');$add('whatsapp_webhook_security',strlen($verify)>=8&&strlen($secret)>=16,'Verify Token + App Secret');
        try{$add('youtube',YouTubeClient::configured(),'YouTube OAuth '.(YouTubeClient::configured()?'مربوط':'غير مربوط'));}catch(Throwable $e){$add('youtube',false,Security::redactSecrets($e->getMessage(),180));}
        try{$add('tiktok',TikTokClient::configured(),'TikTok '.(TikTokClient::configured()?'مربوط':'غير مربوط'));}catch(Throwable $e){$add('tiktok',false,Security::redactSecrets($e->getMessage(),180));}
        try{$add('telegram',TelegramClient::configured(),'Telegram '.(TelegramClient::configured()?'مربوط':'غير مربوط'));}catch(Throwable $e){$add('telegram',false,Security::redactSecrets($e->getMessage(),180));}
        try{$add('meta_social',MetaSocialClient::configured(),'Meta Social '.(MetaSocialClient::configured()?'مربوط مبدئيًا':'غير مربوط'));}catch(Throwable $e){$add('meta_social',false,Security::redactSecrets($e->getMessage(),180));}
        $runtime=PB_ROOT.'/private/runtime';$add('runtime',is_dir($runtime)&&is_writable($runtime),'private/runtime '.(is_writable($runtime)?'قابل للكتابة':'غير قابل للكتابة'));$vaultOk=is_file($runtime.'/master.key')&&is_file($runtime.'/secrets.enc');$add('vault',$vaultOk,'الخزنة المشفرة '.($vaultOk?'موجودة':'ناقصة'));
        try{$hb=(string)setting('runtime.worker_heartbeat_at','');$lastRun=(string)setting('runtime.worker_last_run_state','');$lastErr=(string)setting('runtime.worker_last_run_error','');$fresh=$hb!==''&&utc_ts($hb)!==false&&utc_ts($hb)>time()-600&&$lastRun!=='failed';$note=$hb?:'لا يوجد heartbeat';if($lastRun==='failed')$note.=' · آخر تشغيل فشل: '.($lastErr?:'خطأ غير مسجل');$add('worker',$fresh,$note);}catch(Throwable $e){$add('worker',false,$e->getMessage());}
        try{$pending=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','waiting','running')")->fetchColumn();$failed=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();$add('jobs',$failed===0,'معلّق/شغال: '.$pending.' — فشل آخر 24 ساعة: '.$failed);}catch(Throwable $e){$add('jobs',false,$e->getMessage());}
        $cloud=CloudStorageService::preferred();$add('cloud',$cloud==='none'||CloudStorageService::configured($cloud),$cloud==='none'?'النسخ السحابي اختياري وغير مفعّل':'Cloud provider: '.$cloud);
        $add('browser_qa',BrowserQaService::configured()||setting('qa.browser_required','0')!=='1',BrowserQaService::configured()?'Cloud Browser QA مربوط':(setting('qa.browser_required','0')==='1'?'Browser QA مطلوب وغير مربوط':'Browser QA اختياري حتى يتم ربط Cloud Runner'));
        try{
            if(!BrowserAutomationService::configured()){$add('browser_automation',false,'Browser Automation Bridge غير مربوط؛ إنشاء الحسابات الخارجي لن يعمل قبل ضبط URL/Token واختبار الاتصال.');}
            else{$q=db()->prepare("SELECT state,result_code,created_at FROM connection_tests WHERE provider_key='browser_automation' ORDER BY id DESC LIMIT 1");$q->execute();$last=$q->fetch();if(!$last){$add('browser_automation',false,'Browser Automation مربوط لكن لم يتم اختباره بعد. شغّل اختبار الاتصال من صفحة الربط.');}elseif((string)$last['state']==='verified'){$add('browser_automation',true,'Browser Automation Bridge متحقق منه — آخر اختبار '.AdminUi::date((string)$last['created_at']));}else{$add('browser_automation',false,'آخر اختبار Browser Automation فشل: '.AdminUi::humanError((string)$last['result_code']).' — '.AdminUi::date((string)$last['created_at']));}}
        }catch(Throwable $e){$add('browser_automation',false,Security::redactSecrets($e->getMessage(),180));}
        $r['ok']=!array_filter($r['checks'],static fn($x)=>!$x['ok'])&&!$r['schema_issues'];return $r;
    }
}
