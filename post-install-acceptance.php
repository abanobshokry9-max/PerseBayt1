<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    Auth::verifyCsrf();
    try{$r=PostInstallAcceptanceService::run('owner_dashboard');$_SESSION['flash']='Post-install acceptance: '.($r['state']??'unknown').' · Failed '.(int)($r['failed']??0).' · Warnings '.(int)($r['warnings']??0);$_SESSION['flash_type']=!empty($r['ok'])?'success':'error';}
    catch(Throwable $e){$_SESSION['flash']=AdminUi::humanError(Security::redactSecrets($e->getMessage(),220));$_SESSION['flash_type']='error';}
    header('Location: /api/admin/post-install-acceptance.php');exit;
}
$r=PostInstallAcceptanceService::latest();
AdminUi::header('فحص ما بعد التركيب','post_install_acceptance');echo AdminUi::flash();
?>
<section class="dashboard-welcome"><div><span class="eyebrow">POST-INSTALL ACCEPTANCE · <?=e(ReleaseInfo::VERSION)?></span><h2>اختبار النسخة بعد الرفع والاستيراد</h2><p>الفحص يجمع Schema Preflight وSystem Doctor وRuntime Acceptance والوكلاء والـPolicy والـWorkflow والـProvider Routes والـSnapshots وبصمة النشر. الخدمات الخارجية تُعرض كتحذير إذا لم يثبت نجاحها، ولا تُعامل Configured كدليل نجاح.</p></div><div class="quick-actions"><form method="post"><?=AdminUi::csrf()?><button class="btn" type="submit">تشغيل الفحص الآن</button></form><a class="btn secondary" href="integration-health.php">صحة التكاملات</a></div></section>
<?php if(!$r):?><section class="card empty"><h2>لم يتم تشغيل الفحص بعد</h2><p class="muted">شغله بعد رفع 20.1 وتشغيل SQL الصغير.</p></section><?php else:?>
<div class="metric-grid metric-grid-4"><?=AdminUi::stat('الحالة',e((string)$r['state']),'آخر فحص')?><?=AdminUi::stat('Failed',(int)$r['failed'],'حرج')?><?=AdminUi::stat('Warnings',(int)$r['warnings'],'لا تمنع التشغيل')?><?=AdminUi::stat('الوقت',e(AdminUi::date((string)$r['generated_at'])),'UTC محفوظ')?></div>
<section class="card"><div class="card-title"><h2>النتائج</h2></div><div class="table-wrap"><table><thead><tr><th>الفحص</th><th>الحالة</th><th>التفاصيل</th></tr></thead><tbody><?php foreach((array)$r['checks'] as $c):?><tr><td><b><?=e((string)$c['label'])?></b><small class="mono"><?=e((string)$c['key'])?></small></td><td><?=AdminUi::badge((string)$c['state'])?></td><td><details><summary>عرض</summary><pre class="json-box"><?=e(j($c['details']??[]))?></pre></details></td></tr><?php endforeach?></tbody></table></div></section>
<?php endif; AdminUi::footer();
