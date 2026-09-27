<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$slug=preg_replace('/[^a-z0-9_-]/','',(string)($_GET['slug']??''));
if($slug===''){header('Location: /api/admin/team.php');exit;}
$agent=AgentService::bySlug($slug);$aid=(int)$agent['id'];
$schemas=AgentDataService::schemasForAgent($aid,false);$selectedId=(int)($_GET['schema_id']??0);$selected=null;$fields=[];$rows=[];
if($selectedId){$selected=AgentDataService::schema($selectedId);if((int)$selected['agent_id']!==$aid)throw new RuntimeException('agent_data_schema_scope_mismatch');$fields=AgentDataService::fields($selectedId);$rows=AgentDataService::rows($selectedId,120);}
$projects=db()->query("SELECT id,name,primary_domain,workflow_stage FROM projects ORDER BY updated_at DESC LIMIT 150")->fetchAll();
AdminUi::header('بيانات '.$agent['display_name'],'agents');echo AdminUi::flash();
$base='agents.php?slug='.rawurlencode($slug).'&tab=';
$tabs=['overview'=>['نظرة عامة',$base.'overview'],'tasks'=>['المهام والأداء',$base.'tasks'],'memory'=>['الذاكرة والتعلم',$base.'memory'],'data'=>['Data Studio','agent-data.php?slug='.rawurlencode($slug)],'conversation'=>['المحادثة المباشرة',$base.'conversation'],'permissions'=>['الصلاحيات والأدوات',$base.'permissions'],'communication'=>['التواصل والقنوات',$base.'communication'],'audit'=>['النشاط والأخطاء',$base.'audit']];echo AdminUi::tabs($tabs,'data');
?>
<section class="agent-data-hero card">
  <div><span class="eyebrow">AGENT DATA STUDIO</span><h2><?=e($agent['display_name'])?> — بيانات مخصصة آمنة</h2><p class="muted">أنشئ مجموعات بيانات وحقولًا للوكيل بدون تعديل عشوائي على جداول النظام. البيانات المرتبطة بالمشروع تدخل تلقائيًا في سياق المهمة عند تشغيل أداة Data Studio.</p></div>
  <div class="technical-badge">Schemas <?=count($schemas)?></div>
</section>
<div class="grid">
<section class="card span-4">
  <div class="card-title"><h2>مجموعات البيانات</h2></div>
  <?php if(!$schemas):?><?=AdminUi::empty('لا توجد مجموعة بيانات لهذا الوكيل بعد.')?><?php endif?>
  <?php foreach($schemas as $s):?><a class="data-schema-link <?=($selectedId===(int)$s['id'])?'active':''?>" href="agent-data.php?slug=<?=e($slug)?>&schema_id=<?=$s['id']?>"><div><b><?=e($s['label'])?></b><small><?=e($s['schema_key'])?> · <?=e($s['scope']==='project'?'مرتبطة بالمشروع':'عامة للوكيل')?></small></div><span><?=e((string)$s['field_count'])?> حقل · <?=e((string)$s['row_count'])?> سجل</span></a><?php endforeach?>
  <form method="post" action="action.php" class="subtle data-studio-form">
    <?=AdminUi::csrf()?><input type="hidden" name="action" value="agent_data_schema_create"><input type="hidden" name="agent_id" value="<?=$aid?>"><input type="hidden" name="return" value="/api/admin/agent-data.php?slug=<?=e($slug)?>">
    <h3>مجموعة جديدة</h3><div class="field"><label>الاسم</label><input name="label" required maxlength="160" placeholder="مثال: بيانات العملاء المحتملين"></div><div class="field"><label>المفتاح التقني</label><input name="schema_key" required maxlength="80" pattern="[A-Za-z0-9_]+" placeholder="leads_data"></div><div class="field"><label>النطاق</label><select name="scope"><option value="agent">عام للوكيل</option><option value="project">مرتبط بمشروع</option></select></div><div class="field"><label>الوصف</label><textarea name="description" rows="3" placeholder="متى يستخدم الوكيل هذه البيانات؟"></textarea></div><button class="btn">إنشاء المجموعة</button>
  </form>
</section>
<section class="card span-8">
<?php if(!$selected):?>
  <div class="data-empty-panel"><span>◇</span><h2>اختر مجموعة بيانات</h2><p class="muted">أو أنشئ مجموعة جديدة ثم أضف الحقول والسجلات التي يحتاجها الوكيل في عمله.</p></div>
<?php else:?>
  <div class="card-title"><div><h2><?=e($selected['label'])?></h2><span class="muted small"><?=e($selected['description']?:'بدون وصف')?> · <?=e($selected['scope']==='project'?'Project Scope':'Agent Scope')?></span></div><span class="badge s-verified"><?=e($selected['schema_key'])?></span></div>
  <div class="data-field-chips"><?php foreach($fields as $f):?><span><b><?=e($f['label'])?></b><small><?=e($f['field_key'])?> · <?=e($f['field_type'])?><?=$f['is_required']?' · مطلوب':''?></small></span><?php endforeach?></div>
  <details class="subtle data-builder" open><summary><b>إضافة حقل</b></summary><form method="post" action="action.php" class="form-grid" style="margin-top:12px"><?=AdminUi::csrf()?><input type="hidden" name="action" value="agent_data_field_add"><input type="hidden" name="schema_id" value="<?=$selectedId?>"><input type="hidden" name="return" value="/api/admin/agent-data.php?slug=<?=e($slug)?>&schema_id=<?=$selectedId?>"><div class="field"><label>اسم الحقل</label><input name="label" required maxlength="160" placeholder="اسم العميل"></div><div class="field"><label>المفتاح</label><input name="field_key" required pattern="[A-Za-z0-9_]+" placeholder="client_name"></div><div class="field"><label>نوع البيانات</label><select name="field_type"><?php foreach(AgentDataService::types() as $type):?><option value="<?=e($type)?>"><?=e($type)?></option><?php endforeach?></select></div><div class="field"><label>قيمة افتراضية</label><input name="default_value" placeholder="اختياري"></div><label class="check"><input type="checkbox" name="is_required" value="1"> الحقل مطلوب</label><div><button class="btn secondary">إضافة الحقل</button></div></form></details>
  <?php if($fields):?><details class="subtle data-builder" open><summary><b>إضافة سجل بيانات</b></summary><form method="post" action="action.php" class="form-grid" style="margin-top:12px"><?=AdminUi::csrf()?><input type="hidden" name="action" value="agent_data_row_add"><input type="hidden" name="schema_id" value="<?=$selectedId?>"><input type="hidden" name="return" value="/api/admin/agent-data.php?slug=<?=e($slug)?>&schema_id=<?=$selectedId?>"><div class="field"><label>عنوان السجل</label><input name="row_label" maxlength="190" placeholder="اختياري"></div><?php if($selected['scope']==='project'):?><div class="field"><label>المشروع</label><select name="project_id" required><option value="">اختر مشروعًا</option><?php foreach($projects as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?><?=!empty($p['primary_domain'])?' — '.e($p['primary_domain']):''?></option><?php endforeach?></select></div><?php endif?><?php foreach($fields as $f):$k=(string)$f['field_key'];$type=(string)$f['field_type'];?><div class="field <?=in_array($type,['long_text','json'],true)?'span-field':''?>"><label><?=e($f['label'])?><?=$f['is_required']?' *':''?></label><?php if($type==='long_text'||$type==='json'):?><textarea name="data[<?=e($k)?>]" rows="3" <?=$f['is_required']?'required':''?> placeholder="<?=e($type==='json'?'JSON صالح':'')?>"></textarea><?php elseif($type==='boolean'):?><select name="data[<?=e($k)?>]"><option value="0">لا</option><option value="1">نعم</option></select><?php else:$htmlType=match($type){'number','decimal'=>'number','date'=>'date','datetime'=>'datetime-local','email'=>'email','url'=>'url','phone'=>'tel',default=>'text'};?><input type="<?=e($htmlType)?>" name="data[<?=e($k)?>]" <?=$type==='decimal'?'step="any"':''?> <?=$f['is_required']?'required':''?>><?php endif?></div><?php endforeach?><div><button class="btn">حفظ السجل</button></div></form></details><?php endif?>
  <div class="card-title" style="margin-top:18px"><h2>السجلات الأخيرة</h2><span class="muted small"><?=count($rows)?> سجل معروض</span></div>
  <?php if(!$rows):?><?=AdminUi::empty('لا توجد سجلات بعد.')?><?php else:?><div class="data-row-list"><?php foreach($rows as $row):$data=json_decode((string)$row['data_json'],true);if(!is_array($data))$data=[];?><article class="data-row-card"><header><div><b><?=e($row['row_label']?:('#'.$row['id']))?></b><small><?=e($row['project_name']?:'بيانات عامة')?> · <?=e(AdminUi::date($row['updated_at']))?></small></div><span>#<?=$row['id']?></span></header><dl><?php foreach($fields as $f):$v=$data[$f['field_key']]??null;if(is_array($v))$v=j($v);if(is_bool($v))$v=$v?'نعم':'لا';?><dt><?=e($f['label'])?></dt><dd><?=e($v===null||$v===''?'—':(string)$v)?></dd><?php endforeach?></dl></article><?php endforeach?></div><?php endif?>
<?php endif?>
</section></div>
<?php AdminUi::footer();
