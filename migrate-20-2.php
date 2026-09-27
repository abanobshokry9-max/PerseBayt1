<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/public_html/api/src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit("تشغيل سطر أوامر فقط\n");}
try{
    $settings=[
        'release.20.2.0.arabic_brain_team'=>'1','ui.language'=>'ar','ui.force_arabic_labels'=>'1',
        'agents.autonomy_enabled'=>'1','agents.team_coordinator_enabled'=>'1','agents.executive_brain_enabled'=>'1',
        'context.history_limit'=>'28','runtime.fast_response_mode'=>'1','ramy.executive_cycles_enabled'=>'1','ramy.executive_cycle_minutes'=>'10',
        'whatsapp.auto_resume_pending'=>'1','whatsapp.auto_recovery_interval_minutes'=>'2'
    ];
    foreach($settings as $k=>$v)put_setting($k,$v);
    db()->exec("UPDATE agent_autonomy SET brain_enabled=1,initiative_enabled=1,followup_enabled=1,cadence_minutes=LEAST(GREATEST(cadence_minutes,2),10),max_initiatives_per_day=GREATEST(max_initiatives_per_day,12),next_run_at=COALESCE(next_run_at,NOW()) WHERE agent_id IN (SELECT id FROM agents WHERE is_active=1)");
    db()->exec("UPDATE agent_relationships SET can_message=1,can_start=1 WHERE from_agent_id<>to_agent_id");
    try{db()->exec("INSERT IGNORE INTO agent_relationships(from_agent_id,to_agent_id,can_message,can_start,requires_manager_approval) SELECT a.id,b.id,1,1,0 FROM agents a JOIN agents b ON a.id<>b.id WHERE a.is_active=1 AND b.is_active=1");}catch(Throwable){}
    db()->prepare("INSERT INTO schema_migrations(migration_id,version,checksum_sha256,state,details_json,applied_at,verified_at) VALUES ('companyos_20_2_0_arabic_brain_team','20.2.0',NULL,'verified',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE state='verified',details_json=VALUES(details_json),verified_at=NOW()")
       ->execute([j(['base'=>'20.1.6','schema'=>'20.0','type'=>'runtime_extension','destructive'=>false,'scope'=>'arabic_ui_executive_brain_team_chat_whatsapp_speed'])]);
    db()->prepare("INSERT INTO release_history(version,schema_version,build_date,source_checksum,notes) VALUES ('20.2.0-arabic-brain-team','20.0','2026-09-27',NULL,?) ON DUPLICATE KEY UPDATE schema_version='20.0',build_date='2026-09-27',notes=VALUES(notes)")
       ->execute(['تعريب شامل للواجهة، عقل مبادرة تنفيذي، تواصل فعلي بين الوكلاء، معالجة سياق واتساب، استجابة أسرع، وصفحة تقنية حية.']);
    echo json_encode(['ok'=>true,'version'=>ReleaseInfo::VERSION,'schema'=>ReleaseInfo::SCHEMA,'message'=>'تم تطبيق ترقية 20.2.0 بنجاح.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}catch(Throwable $e){fwrite(STDERR,'فشلت الترقية: '.Security::redactSecrets($e->getMessage(),700).PHP_EOL);exit(1);}
