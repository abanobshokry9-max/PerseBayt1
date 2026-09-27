<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$targets=SecurityLabService::targets();
$projects=db()->query("SELECT id,name,primary_domain,workflow_stage,status FROM projects ORDER BY updated_at DESC,id DESC LIMIT 300")->fetchAll();
$labs=LabEnvironmentService::all();
$runId=max(0,(int)($_GET['run_id']??0));
$findingId=max(0,(int)($_GET['finding_id']??0));
$runDetails=null;$finding=null;
if($runId){try{$runDetails=SecurityLabService::runDetails($runId);}catch(Throwable $e){$runDetails=null;}}
if($findingId){try{$finding=SecurityLabService::finding($findingId);}catch(Throwable $e){$finding=null;}}
$auths=[];foreach($targets as $t){try{$auths[(int)$t['id']]=SecurityLabService::authorization((int)$t['id']);}catch(Throwable){$auths[(int)$t['id']]=null;}}
$sevLabel=['critical'=>'حرج','high'=>'مرتفع','medium'=>'متوسط','low'=>'منخفض','info'=>'معلومة'];
$eventLabel=['passed'=>'سليم ضمن الاختبار','finding'=>'Finding مؤكدة','blocked'=>'صدته الحماية/النطاق','manual_required'=>'مراجعة يدوية','error'=>'خطأ اختبار','skipped'=>'غير منطبق','started'=>'بدأ'];
$difficulty=['very_easy'=>'سهل جدًا','easy'=>'سهل','moderate'=>'متوسط','hard'=>'صعب','very_hard'=>'صعب جدًا','unknown'=>'غير محدد'];
AdminUi::header('الاختبارات','security_tests');echo AdminUi::flash();
?>
<section class="tech-hero card security-hero"><div><span class="eyebrow">EMAD SECURITY & QA LAB</span><h2>اختبار فعلي موثق — ضمن تصريحك فقط</h2><p>كل Target له Authorization ونطاق واضح. عماد يسجل Run + Test Events + Evidence + Findings + Retest. لا يوجد تجاوز CAPTCHA/2FA/WAF تلقائي ولا استغلال عشوائي خارج Playbook معتمد.</p></div><div class="tech-live"><span class="live-dot"></span><b>nourmakkah.com</b><small>محطة الاختبار الرسمية</small></div></section>

<div class="metric-grid metric-grid-4">
<?=AdminUi::stat('Targets',count($targets),'مواقع/بيئات مصرح بها')?>
<?=AdminUi::stat('Findings مفتوحة',array_sum(array_map(fn($x)=>(int)$x['open_findings'],$targets)),'تحتاج إصلاح/قرار')?>
<?=AdminUi::stat('Critical',array_sum(array_map(fn($x)=>(int)$x['critical_open'],$targets)),'تنبيه فوري عند ظهورها')?>
<?=AdminUi::stat('Labs',count($labs),'بيئات مشاريع على nourmakkah.com')?>
</div>

<div class="grid">
<section class="card span-8"><div class="card-title"><div><h2>Targets والنتائج</h2><span class="muted small">كارت مستقل لكل موقع مع آخر Run والاختبارات والـFindings المفتوحة.</span></div></div>
<div class="two-col security-target-grid">
<?php foreach($targets as $t):$auth=$auths[(int)$t['id']]??null;$scope=json_decode((string)($t['scope_json']??'{}'),true)?:[];?>
<article class="card subtle security-target-card" data-live-filter-item data-search="<?=e(pb_strtolower(($t['label']??'').' '.($t['base_url']??'').' '.($t['project_name']??'')))?>">
 <div class="card-title"><div><b><?=e($t['label'])?></b><small class="muted block"><?=e($t['project_name']?:'بدون مشروع')?> · <?=e($t['environment'])?></small></div><?=AdminUi::badge($t['last_run_state']?:'untested')?></div>
 <a class="link break-all" target="_blank" rel="noopener" href="<?=e($t['base_url'])?>"><?=e($t['base_url'])?></a>
 <div class="mini-kpis"><span><b><?=(int)$t['runs_count']?></b> Runs</span><span><b><?=(int)($t['last_tests_total']??0)?></b> Tests</span><span><b><?=(int)($t['last_tests_passed']??0)?></b> سليم</span><span><b><?=(int)($t['last_tests_failed']??0)?></b> Finding</span><span><b><?=(int)($t['last_tests_blocked']??0)?></b> Blocked</span><span><b><?=(int)$t['open_findings']?></b> مفتوح</span></div>
 <?php if($auth):?><p class="small muted">تصريح #<?=(int)$auth['id']?> · <?=e(pb_substr((string)$auth['scope_text'],0,180))?><?=!empty($auth['valid_until'])?' · حتى '.e(AdminUi::date($auth['valid_until'])):''?></p><?php else:?><div class="flash warning"><span>لا يوجد تصريح فعال. لا يمكن بدء Run حتى تعيد التفويض.</span></div><?php endif?>
 <div class="actions"><?php if($auth):?><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="security_run_start"><input type="hidden" name="return" value="/api/admin/security-tests.php"><input type="hidden" name="target_id" value="<?=$t['id']?>"><button class="btn">تشغيل عماد</button></form><?php endif?><?php if(!empty($t['last_run_id'])):?><a class="btn secondary" href="?run_id=<?=(int)$t['last_run_id']?>#run-details">آخر تقرير</a><?php endif?></div>
 <details><summary>النطاق وإعادة التفويض</summary><div class="details-pad"><p class="small"><b>Paths:</b> <?=e(implode('، ',(array)($scope['paths']??['/'])))?></p><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="security_authorize"><input type="hidden" name="return" value="/api/admin/security-tests.php"><input type="hidden" name="target_id" value="<?=$t['id']?>"><div class="field"><label>نطاق التصريح</label><textarea name="authorization_scope" required><?=e($auth['scope_text']??'QA وأمان غير مدمر داخل النطاق المحدد فقط.')?></textarea></div><div class="two-col"><label class="check"><input type="checkbox" name="allow_authenticated_tests" value="1" <?=!$auth||!empty($auth['allow_authenticated_tests'])?'checked':''?>> Auth</label><label class="check"><input type="checkbox" name="allow_data_proof" value="1" <?=!$auth||!empty($auth['allow_data_proof'])?'checked':''?>> Minimal proof</label></div><label class="check"><input type="checkbox" name="allow_intrusive_tests" value="1" <?=!empty($auth['allow_intrusive_tests'])?'checked':''?>> اختبارات intrusive محددة ومعتمدة فقط</label><label class="check"><input type="checkbox" name="allow_production_changes" value="1" <?=!empty($auth['allow_production_changes'])?'checked':''?>> السماح بتغيير Production داخل هذا التصريح (غير موصى به للاختبار)</label><div class="field"><label>انتهاء اختياري</label><input type="datetime-local" name="valid_until"></div><button class="btn secondary full-width">تجديد التصريح</button></form></div></details>
</article>
<?php endforeach?>
<?php if(!$targets):?><?=AdminUi::empty('لا توجد Targets بعد. أضف أول موقع/بيئة من النموذج.')?><?php endif?>
</div></section>

<section class="card span-4"><div class="card-title"><h2>إضافة Target مصرح</h2></div>
<form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="security_target_create"><input type="hidden" name="return" value="/api/admin/security-tests.php">
<div class="field"><label>اسم الموقع/البيئة</label><input name="label" required placeholder="مشروع X — Staging"></div>
<div class="field"><label>الرابط</label><input type="url" name="base_url" required placeholder="https://project.nourmakkah.com/"></div>
<div class="field"><label>المشروع</label><select name="project_id"><option value="0">بدون مشروع</option><?php foreach($projects as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?></option><?php endforeach?></select></div>
<div class="field"><label>البيئة</label><select name="environment"><option value="testing">Testing</option><option value="staging">Staging</option><option value="production">Production — مصرح فقط</option><option value="external_authorized">External authorized</option></select></div>
<div class="field"><label>المسارات المسموحة</label><textarea name="paths" placeholder="/&#10;/login&#10;/api/">/</textarea></div>
<div class="field"><label>وصف تصريحك</label><textarea name="authorization_scope" required>QA وأمان غير مدمر داخل النطاق المحدد فقط، مع Minimal Proof.</textarea></div>
<label class="check"><input type="checkbox" name="allow_authenticated_tests" value="1" checked> يسمح باختبارات الحسابات المصرح بها</label>
<label class="check"><input type="checkbox" name="allow_data_proof" value="1" checked> يسمح بأقل دليل لازم لإثبات المشكلة</label>
<label class="check"><input type="checkbox" name="allow_intrusive_tests" value="1"> اختبارات intrusive محددة في Playbook فقط</label>
<label class="check"><input type="checkbox" name="allow_production_changes" value="1"> تغيير Production ضمن التصريح</label>
<div class="field"><label>ملاحظات</label><textarea name="notes"></textarea></div><button class="btn full-width">حفظ Target + Authorization</button></form>
</section></div>

<section class="card"><div class="card-title"><div><h2>محطة nourmakkah.com</h2><span class="muted small">أيمن/عماد يجهزان لكل مشروع Folder/Domain وStaging DB إذا كانت صلاحية الإنشاء الفعلية متاحة. الفشل لا يلمس Production.</span></div></div>
<div class="grid"><form class="span-4 card subtle" method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="lab_prepare"><input type="hidden" name="return" value="/api/admin/security-tests.php"><div class="field"><label>المشروع</label><select name="project_id" required><option value="">اختار</option><?php foreach($projects as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?></option><?php endforeach?></select></div><button class="btn full-width">تجهيز/مزامنة Lab</button><p class="small muted">المزامنة تنسخ كود/وسائط آمنة فقط، ولا تنقل ملفات أسرار Production مثل .env أو config.php. بعدها يتم Health Check فعلي.</p></form><div class="span-8 two-col"><?php foreach($labs as $l):$lm=json_decode((string)($l['metadata_json']??'{}'),true)?:[];$fs=(array)($lm['file_sync']??[]);$lh=(array)($lm['health']??[]);?><article class="card subtle" style="margin:0"><div class="card-title"><b><?=e($l['project_name'])?></b><?=AdminUi::badge($l['state'])?></div><a class="link break-all" target="_blank" rel="noopener" href="https://<?=e($l['full_domain'])?>/">https://<?=e($l['full_domain'])?>/</a><div class="mini-kpis"><span><b><?=(int)($fs['files_copied']??0)?></b> منسوخ</span><span><b><?=(int)($fs['files_unchanged']??0)?></b> مطابق</span><span><b><?=(int)($fs['sensitive_files_skipped']??0)?></b> أسرار متروكة</span><span><b><?=!empty($lh['ok'])?'OK':'HTTP '.(int)($lh['status']??0)?></b> Health</span></div><p class="small muted">Folder: <?=e($l['directory_path'])?> · DB #<?=e($l['database_id']?:'—')?> · آخر فحص <?=e(AdminUi::date($l['last_health_at']))?></p></article><?php endforeach?></div></div>
</section>

<?php if($finding):?>
<section class="card" id="finding-details"><div class="card-title"><div><h2>Finding #<?=$finding['id']?> — <?=e($finding['title_ar'])?></h2><span class="muted small"><?=e($finding['technical_name']?:'')?> · <?=e($finding['target_label'])?></span></div><span class="badge s-<?=e($finding['severity'])?>"><?=e($sevLabel[$finding['severity']]??$finding['severity'])?></span></div>
<div class="three-col"><div><small class="muted">الحالة</small><p><?=AdminUi::badge($finding['status'])?></p></div><div><small class="muted">الصعوبة</small><p><?=e($difficulty[$finding['difficulty']]??$finding['difficulty'])?></p></div><div><small class="muted">المشروع</small><p><?=e($finding['project_name']?:'—')?></p></div></div>
<h3>الوصف</h3><p><?=nl2br(e($finding['description_text']))?></p><h3>التأثير</h3><p><?=nl2br(e($finding['impact_text']?:'—'))?></p><h3>Minimal proof</h3><p><?=nl2br(e($finding['proof_text']?:'—'))?></p><h3>طريقة الإصلاح</h3><p><?=nl2br(e($finding['remediation_text']?:'—'))?></p>
<div class="actions"><?php if(empty($finding['fix_task_id'])):?><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="security_finding_fix"><input type="hidden" name="finding_id" value="<?=$finding['id']?>"><input type="hidden" name="return" value="/api/admin/security-tests.php?finding_id=<?=$finding['id']?>#finding-details"><button class="btn">إرسال الإصلاح لأيمن</button></form><?php else:?><a class="btn secondary" href="tasks.php?id=<?=(int)$finding['fix_task_id']?>">مهمة أيمن #<?=(int)$finding['fix_task_id']?></a><?php endif?><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="security_finding_retest"><input type="hidden" name="finding_id" value="<?=$finding['id']?>"><input type="hidden" name="return" value="/api/admin/security-tests.php?finding_id=<?=$finding['id']?>#finding-details"><button class="btn secondary">Retest مع عماد</button></form></div></section>
<?php endif?>

<?php if($runDetails):$r=$runDetails['run'];?>
<section class="card" id="run-details"><div class="card-title"><div><h2>Security Run #<?=$r['id']?> — <?=e($r['target_label'])?></h2><span class="muted small"><?=e($r['base_url'])?> · <?=e($r['environment'])?> · Authorization #<?=$r['authorization_id']?></span></div><?=AdminUi::badge($r['state'])?></div>
<div class="mini-kpis"><span><b><?=$r['tests_total']?></b> إجمالي</span><span><b><?=$r['tests_passed']?></b> سليم</span><span><b><?=$r['tests_failed']?></b> Findings</span><span><b><?=$r['tests_blocked']?></b> Blocked/Manual</span><span><b><?=$r['findings_count']?></b> مؤكدة</span></div><p><?=nl2br(e($r['summary']?:'لا يوجد ملخص نهائي بعد — Worker قد يكون ما زال ينفذ.'))?></p>
<h3>الاختبارات</h3><div class="list-stack"><?php foreach($runDetails['events'] as $ev):$evidence=json_decode((string)($ev['evidence_json']??'{}'),true)?:[];?><article class="card subtle"><div class="card-title"><div><b><?=e($ev['label_ar']?:$ev['test_key'])?></b><small class="muted block"><?=e($ev['category']?:'')?> · <?=e($difficulty[$ev['difficulty']]??$ev['difficulty'])?></small></div><?=AdminUi::badge($ev['state'])?></div><div class="two-col"><div><small class="muted">المتوقع</small><p class="small"><?=e($ev['expected_text']?:'—')?></p></div><div><small class="muted">الفعلي</small><p class="small"><?=e($ev['actual_text']?:'—')?></p></div></div><?php if($evidence):?><details><summary>Evidence التقنية</summary><pre class="evidence-json"><?=e(json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))?></pre></details><?php endif?></article><?php endforeach?></div>
<h3>Findings</h3><div class="two-col"><?php foreach($runDetails['findings'] as $f):?><article class="card subtle" style="margin:0"><div class="card-title"><b><?=e($f['title_ar'])?></b><span class="badge s-<?=e($f['severity'])?>"><?=e($sevLabel[$f['severity']]??$f['severity'])?></span></div><p class="small"><?=e(pb_substr((string)$f['description_text'],0,300))?></p><div class="actions"><a class="btn secondary" href="?run_id=<?=$r['id']?>&finding_id=<?=$f['id']?>#finding-details">التفاصيل والإصلاح</a></div></article><?php endforeach?><?php if(!$runDetails['findings']):?><?=AdminUi::empty('لا توجد Findings مؤكدة في هذا الـRun.')?><?php endif?></div>
</section>
<?php endif?>
<?php AdminUi::footer();
