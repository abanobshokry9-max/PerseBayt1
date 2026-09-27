<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$scope=(string)($_GET['scope']??'company');
$agentId=(int)($_GET['agent_id']??0);
$projectId=(int)($_GET['project_id']??0);
$agents=AgentService::all();
$projects=ProjectService::all();
AdminUi::header('الذاكرة والتعلم','memory');
echo AdminUi::flash();
echo AdminUi::tabs([
    'company'=>['ذاكرة الشركة','memory.php?scope=company'],
    'agents'=>['ذاكرة الوكلاء','memory.php?scope=agents'],
    'projects'=>['ذاكرة المشاريع','memory.php?scope=projects'],
    'learning'=>['تعلم الوكلاء','memory.php?scope=learning'],
],$scope);
?>
<?php if($scope==='company'):
    $rows=db()->query("SELECT * FROM company_memory WHERE active=1 ORDER BY updated_at DESC LIMIT 300")->fetchAll();
?>
<div class="grid">
<section class="card span-8"><div class="card-title"><div><h2>ذاكرة الشركة المشتركة</h2><span class="muted small">يمكن للمالك تعديل أو حذف أي معلومة من الذاكرة النشطة. الحذف Soft Delete للحفاظ على سجل التدقيق.</span></div></div>
<?php if(!$rows):?><?=AdminUi::empty('لا توجد ذاكرة شركة نشطة.')?><?php endif?>
<?php foreach($rows as $m):?>
<details class="subtle" style="margin-bottom:10px"><summary><b><?=e(AdminUi::label($m['category']))?></b> — <?=e(pb_substr($m['body_text'],0,150))?> <span class="muted small">· <?=e(AdminUi::date($m['updated_at']))?></span></summary>
<form method="post" action="action.php" class="top-gap"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_update_company"><input type="hidden" name="id" value="<?=$m['id']?>"><input type="hidden" name="return" value="/api/admin/memory.php?scope=company"><div class="field"><label>التصنيف</label><input name="category" required value="<?=e($m['category'])?>"></div><div class="field"><label>المعرفة</label><textarea name="body" rows="4" required><?=e($m['body_text'])?></textarea></div><button class="btn secondary small-btn">حفظ التعديل</button></form>
<form method="post" action="action.php" class="top-gap"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_disable_company"><input type="hidden" name="id" value="<?=$m['id']?>"><input type="hidden" name="return" value="/api/admin/memory.php?scope=company"><button class="btn danger small-btn" data-confirm="حذف هذه المعلومة من الذاكرة النشطة؟">حذف من الذاكرة</button></form>
</details>
<?php endforeach?>
</section>
<section class="card span-4"><div class="card-title"><h2>إضافة معرفة عامة</h2></div><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_add_company"><input type="hidden" name="return" value="/api/admin/memory.php?scope=company"><div class="field"><label>التصنيف</label><input name="category" required placeholder="سياسة / طريقة شغل / تفضيلات المالك"></div><div class="field"><label>المعرفة</label><textarea name="body" required></textarea></div><button class="btn">حفظ المعرفة</button></form></section>
</div>
<?php elseif($scope==='agents'):
    if(!$agentId&&$agents)$agentId=(int)$agents[0]['id'];
    $q=db()->prepare("SELECT m.*,a.display_name FROM agent_memory m JOIN agents a ON a.id=m.agent_id WHERE m.agent_id=? AND m.active=1 ORDER BY m.importance DESC,m.updated_at DESC");$q->execute([$agentId]);$rows=$q->fetchAll();
?>
<div class="toolbar"><form method="get"><input type="hidden" name="scope" value="agents"><select name="agent_id" onchange="this.form.submit()"><?php foreach($agents as $a):?><option value="<?=$a['id']?>" <?=$agentId==$a['id']?'selected':''?>><?=e($a['display_name'])?></option><?php endforeach?></select></form></div>
<div class="grid"><section class="card span-8"><div class="card-title"><div><h2>ذاكرة الوكيل</h2><span class="muted small">مستقلة لكل وكيل ويمكن للمالك مراجعتها وتعديلها أو حذفها.</span></div></div>
<?php if(!$rows):?><?=AdminUi::empty('لا توجد ذاكرة نشطة لهذا الوكيل.')?><?php endif?>
<?php foreach($rows as $m):?>
<details class="subtle" style="margin-bottom:10px"><summary><b><?=e(AdminUi::label($m['memory_type']))?></b> — <?=e(pb_substr($m['body_text'],0,150))?> <span class="muted small">· أهمية <?=(int)$m['importance']?>%</span></summary>
<form method="post" action="action.php" class="top-gap"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_update_agent"><input type="hidden" name="id" value="<?=$m['id']?>"><input type="hidden" name="return" value="<?=e('/api/admin/memory.php?scope=agents&agent_id='.$agentId)?>"><div class="form-row"><div class="field"><label>النوع</label><select name="memory_type"><?php foreach(['core','experience','project','owner_preference','relationship','procedure'] as $x):?><option value="<?=e($x)?>" <?=$m['memory_type']===$x?'selected':''?>><?=e(AdminUi::label($x))?></option><?php endforeach?></select></div><div class="field"><label>الأهمية</label><input type="number" name="importance" min="1" max="100" value="<?=(int)$m['importance']?>"></div></div><div class="field"><label>المعرفة</label><textarea name="body" rows="5" required><?=e($m['body_text'])?></textarea></div><button class="btn secondary small-btn">حفظ التعديل</button></form>
<form method="post" action="action.php" class="top-gap"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_disable_agent"><input type="hidden" name="id" value="<?=$m['id']?>"><input type="hidden" name="return" value="<?=e('/api/admin/memory.php?scope=agents&agent_id='.$agentId)?>"><button class="btn danger small-btn" data-confirm="حذف هذه المعلومة من ذاكرة الوكيل النشطة؟">حذف من الذاكرة</button></form>
</details>
<?php endforeach?>
</section><section class="card span-4"><div class="card-title"><h2>تدريب / معرفة جديدة</h2></div><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_add_agent"><input type="hidden" name="agent_id" value="<?=$agentId?>"><input type="hidden" name="return" value="<?=e('/api/admin/memory.php?scope=agents&agent_id='.$agentId)?>"><div class="field"><label>النوع</label><select name="memory_type"><?php foreach(['core','experience','project','owner_preference','relationship','procedure'] as $x):?><option value="<?=e($x)?>"><?=e(AdminUi::label($x))?></option><?php endforeach?></select></div><div class="field"><label>المعرفة</label><textarea name="body" required></textarea></div><div class="field"><label>الأهمية 1-100</label><input type="number" name="importance" min="1" max="100" value="70"></div><button class="btn">إضافة للذاكرة</button></form></section></div>
<?php elseif($scope==='projects'):
    if(!$projectId&&$projects)$projectId=(int)$projects[0]['id'];
    $q=db()->prepare("SELECT * FROM project_memory WHERE project_id=? AND active=1 ORDER BY updated_at DESC");$q->execute([$projectId]);$rows=$q->fetchAll();
?>
<div class="toolbar"><form method="get"><input type="hidden" name="scope" value="projects"><select name="project_id" onchange="this.form.submit()"><?php foreach($projects as $p):?><option value="<?=$p['id']?>" <?=$projectId==$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endforeach?></select></form></div>
<div class="grid"><section class="card span-8"><div class="card-title"><div><h2>ذاكرة المشروع</h2><span class="muted small">قرارات وبنية ومشكلات وحلول تخص المشروع فقط.</span></div></div>
<?php if(!$rows):?><?=AdminUi::empty('لا توجد ذاكرة نشطة لهذا المشروع.')?><?php endif?>
<?php foreach($rows as $m):?>
<details class="subtle" style="margin-bottom:10px"><summary><b><?=e(AdminUi::label($m['category']))?></b> — <?=e(pb_substr($m['body_text'],0,150))?> <span class="muted small">· <?=e(AdminUi::date($m['updated_at']))?></span></summary>
<form method="post" action="action.php" class="top-gap"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_update_project"><input type="hidden" name="id" value="<?=$m['id']?>"><input type="hidden" name="return" value="<?=e('/api/admin/memory.php?scope=projects&project_id='.$projectId)?>"><div class="field"><label>التصنيف</label><input name="category" required value="<?=e($m['category'])?>"></div><div class="field"><label>المعلومة</label><textarea name="body" rows="5" required><?=e($m['body_text'])?></textarea></div><button class="btn secondary small-btn">حفظ التعديل</button></form>
<form method="post" action="action.php" class="top-gap"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_disable_project"><input type="hidden" name="id" value="<?=$m['id']?>"><input type="hidden" name="return" value="<?=e('/api/admin/memory.php?scope=projects&project_id='.$projectId)?>"><button class="btn danger small-btn" data-confirm="حذف هذه المعلومة من ذاكرة المشروع النشطة؟">حذف من الذاكرة</button></form>
</details>
<?php endforeach?>
</section><section class="card span-4"><div class="card-title"><h2>إضافة قرار/معلومة</h2></div><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="memory_add_project"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="return" value="<?=e('/api/admin/memory.php?scope=projects&project_id='.$projectId)?>"><div class="field"><label>التصنيف</label><input name="category" required placeholder="بنية المشروع / قرار / مشكلة معروفة"></div><div class="field"><label>المعلومة</label><textarea name="body" required></textarea></div><button class="btn">حفظ</button></form></section></div>
<?php else:
    $rows=db()->query("SELECT l.*,a.display_name,p.name project_name,t.title task_title FROM agent_learning l JOIN agents a ON a.id=l.agent_id LEFT JOIN projects p ON p.id=l.project_id LEFT JOIN tasks t ON t.id=l.source_task_id ORDER BY FIELD(l.status,'pending','approved','disabled'),l.id DESC LIMIT 400")->fetchAll();
?>
<section class="card"><div class="card-title"><div><h2>سجل تعلم الوكلاء</h2><span class="muted small">ما تعلمه كل وكيل، المصدر، الثقة، وحالة اعتماد المالك.</span></div></div>
<div class="table-wrap"><table><thead><tr><th>الوكيل</th><th>المشروع/المهمة</th><th>النوع</th><th>التعلم</th><th>الثقة</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody><?php foreach($rows as $l):?><tr><td><a class="link" href="agent-profile.php?slug=<?=e(AgentService::byId((int)$l['agent_id'])['slug'])?>"><?=e($l['display_name'])?></a></td><td><?=e(($l['project_name']?:'عام').($l['task_title']?' · '.$l['task_title']:''))?></td><td><?=e(AdminUi::label($l['learning_type']))?></td><td><?=e(pb_substr($l['learning_text'],0,300))?></td><td><?=(int)$l['confidence']?>%</td><td><?=AdminUi::badge($l['status'])?></td><td><?=e(AdminUi::date($l['created_at']))?></td></tr><?php endforeach?></tbody></table></div></section>
<?php endif; AdminUi::footer();
