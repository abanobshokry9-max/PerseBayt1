<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$fp=DeploymentFingerprintService::snapshot();
AdminUi::header('بصمة النشر والاستعادة','deployment_status');echo AdminUi::flash();
$release=(array)$fp['release'];$runtime=(array)$fp['runtime'];$recovery=(array)$fp['recovery'];$code=(array)$fp['code'];
?>
<section class="dashboard-welcome"><div><span class="eyebrow">DEPLOYMENT FINGERPRINT · <?=e(ReleaseInfo::VERSION)?></span><h2>هوية النسخة الفعلية وحالة الاستعادة</h2><p>الصفحة تعرض بصمة كود غير حساسة، إصدار التطبيق والـSchema، حالة Worker، Staging ونسخ Vault/Config المشفرة بدون كشف أي مفتاح أو كلمة مرور.</p></div><div class="quick-actions"><a class="btn" href="post-install-acceptance.php">فحص ما بعد التركيب</a><a class="btn secondary" href="integration-health.php">صحة التكاملات</a></div></section>
<div class="metric-grid metric-grid-4">
<?=AdminUi::stat('الإصدار',e((string)$release['app']),'المسجل: '.e((string)$release['stored_version']))?>
<?=AdminUi::stat('Schema',e((string)$release['schema_active']),'المطلوب: '.e((string)$release['schema_expected']))?>
<?=AdminUi::stat('Worker',!empty($runtime['worker_healthy'])?'Healthy':'Stale / Unknown',!empty($runtime['worker_heartbeat_at'])?AdminUi::date((string)$runtime['worker_heartbeat_at']):'لا يوجد heartbeat')?>
<?=AdminUi::stat('Recovery',!empty($recovery['ready'])?'Ready':'Needs attention','Vault '.(int)$recovery['verified_vault_snapshots'].' · Config '.(int)$recovery['verified_config_snapshots'])?>
</div>
<div class="grid">
<section class="card span-6"><div class="card-title"><h2>بصمة الكود</h2></div><dl class="kvs"><dt>SHA256</dt><dd class="mono"><?=e((string)$code['sha256'])?></dd><dt>ملفات حرجة</dt><dd><?=e((string)$code['files'])?></dd><dt>ملفات مفقودة</dt><dd><?=e((string)count((array)$code['missing']))?></dd><dt>Staging</dt><dd><?=e((string)$runtime['staging_domain'])?></dd></dl><?php if(!empty($code['missing'])):?><pre class="json-box"><?=e(j($code['missing']))?></pre><?php endif?></section>
<section class="card span-6"><div class="card-title"><h2>قاعدة البيانات والإصدار</h2></div><dl class="kvs"><dt>Migration 20.0</dt><dd><?=AdminUi::badge(!empty($fp['database']['base_migration_recorded'])?'verified':'failed')?></dd><dt>Maintenance 20.1</dt><dd><?=AdminUi::badge(!empty($fp['database']['maintenance_marker_recorded'])?'verified':'warning')?></dd><dt>آخر Release History</dt><dd><?=e((string)($release['latest_history']['version']??'—'))?></dd><dt>Build</dt><dd><?=e((string)($release['latest_history']['build_date']??'—'))?></dd></dl></section>
<section class="card span-12"><div class="card-title"><h2>جاهزية الاستعادة</h2></div><dl class="kvs"><dt>Vault snapshots verified</dt><dd><?=e((string)$recovery['verified_vault_snapshots'])?></dd><dt>Config snapshots verified</dt><dd><?=e((string)$recovery['verified_config_snapshots'])?></dd><dt>آخر Backup متحقق</dt><dd><?php $b=(array)($recovery['last_verified_backup']??[]);?><?=$b?'#'.e((string)$b['id']).' · '.e((string)$b['kind']).' · '.e(AdminUi::date((string)($b['verified_at']?:$b['created_at']))):'لا يوجد Backup مشروع متحقق مسجل'?></dd></dl></section>
</div>
<?php AdminUi::footer();
