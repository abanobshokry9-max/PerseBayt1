<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    Auth::verifyCsrf();
    try{$provider=(string)($_POST['provider']??'');IntegrationHealthService::assertProviderAllowed($provider);$r=ConnectionTester::test($provider);$_SESSION['flash']='اختبار '.AdminUi::provider($provider).': '.(($r['state']??'')==='verified'?'نجح':'لم ينجح').' · '.AdminUi::humanError((string)($r['code']??''));$_SESSION['flash_type']=($r['state']??'')==='verified'?'success':'warning';}
    catch(Throwable $e){$_SESSION['flash']=AdminUi::humanError(Security::redactSecrets($e->getMessage(),180));$_SESSION['flash_type']='error';}
    header('Location: /api/admin/integration-health.php');exit;
}
$h=IntegrationHealthService::snapshot();$s=(array)$h['summary'];
AdminUi::header('مركز صحة التكاملات','integration_health');echo AdminUi::flash();
?>
<section class="dashboard-welcome"><div><span class="eyebrow">LIVE INTEGRATION HEALTH · <?=e(ReleaseInfo::VERSION)?></span><h2>آخر دليل اتصال لكل خدمة</h2><p>Configured لا تعني شغالة. الحكم يعتمد على آخر اختبار حقيقي محفوظ في connection_tests. الاختبار يتم لخدمة واحدة عند الضغط لتجنب استهلاك أو نشر غير مقصود.</p></div><div class="quick-actions"><a class="btn secondary" href="health.php">حالة النظام</a><a class="btn secondary" href="deployment-status.php">بصمة النشر</a></div></section>
<div class="metric-grid metric-grid-4">
<?=AdminUi::stat('الخدمات',(int)$s['providers'],'المراقبة')?>
<?=AdminUi::stat('Configured',(int)$s['configured'],'مهيأة محليًا')?>
<?=AdminUi::stat('Verified',(int)$s['verified'],'آخر اختبار ناجح')?>
<?=AdminUi::stat('Failed',(int)$s['failed'],'آخر اختبار فاشل')?>
</div>
<section class="card"><div class="card-title"><h2>الخدمات الخارجية</h2></div><div class="table-wrap"><table><thead><tr><th>الخدمة</th><th>Configured</th><th>آخر حالة</th><th>آخر اختبار</th><th>النتيجة</th><th></th></tr></thead><tbody>
<?php foreach((array)$h['providers'] as $r):?><tr><td><b><?=e((string)$r['label'])?></b><small class="mono"><?=e((string)$r['provider'])?></small></td><td><?=AdminUi::badge($r['configured']===null?'unknown':($r['configured']?'verified':'unconfigured'))?></td><td><?=AdminUi::badge((string)$r['state'])?></td><td><?=!empty($r['last_test_at'])?e(AdminUi::date((string)$r['last_test_at'])):'—'?></td><td><?=e(!empty($r['result_code'])?AdminUi::humanError((string)$r['result_code']):'—')?></td><td><form method="post"><?=AdminUi::csrf()?><input type="hidden" name="provider" value="<?=e((string)$r['provider'])?>"><button class="btn secondary" type="submit">اختبار الآن</button></form></td></tr><?php endforeach?>
</tbody></table></div></section>
<section class="card"><div class="card-title"><h2>Provider Capability Routes</h2></div><div class="table-wrap"><table class="compact"><thead><tr><th>Capability</th><th>Routes</th><th>Enabled</th><th>Healthy</th><th>Unhealthy</th></tr></thead><tbody><?php foreach((array)$h['capability_routes'] as $r):?><tr><td class="mono"><?=e((string)$r['capability'])?></td><td><?=e((string)$r['routes'])?></td><td><?=e((string)$r['enabled'])?></td><td><?=e((string)$r['healthy'])?></td><td><?=e((string)$r['unhealthy'])?></td></tr><?php endforeach?></tbody></table></div></section>
<?php AdminUi::footer();
