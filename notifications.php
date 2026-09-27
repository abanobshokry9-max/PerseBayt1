<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$sev=(string)($_GET['severity']??'');
$where=$sev&&in_array($sev,['info','success','warning','critical'],true)?' WHERE severity='.db()->quote($sev):'';
$rows=db()->query('SELECT * FROM notifications'.$where.' ORDER BY id DESC LIMIT 500')->fetchAll();
// فتح الصفحة يعلّم فقط الصفوف المعروضة حاليًا كمقروءة، وليس كل سجل الإشعارات.
$visibleUnread=array_values(array_filter(array_map(static fn($x)=>empty($x['read_at'])?(int)$x['id']:0,$rows)));
if($visibleUnread){try{$ph=implode(',',array_fill(0,count($visibleUnread),'?'));$q=db()->prepare("UPDATE notifications SET read_at=NOW() WHERE read_at IS NULL AND id IN ($ph)");$q->execute($visibleUnread);}catch(Throwable){}}
AdminUi::header('مركز الإشعارات','notifications');echo AdminUi::flash();
?>
<div class="toolbar"><a class="btn secondary" href="notifications.php">الكل</a><?php foreach(['critical'=>'حرج','warning'=>'تحذير','success'=>'نجاح','info'=>'معلومة'] as $k=>$l):?><a class="btn secondary" href="?severity=<?=$k?>"><?=$l?></a><?php endforeach?></div>
<section class="card"><div class="card-title"><h2>الإشعارات</h2><span class="muted small"><?=count($rows)?> إشعار</span></div><?php if(!$rows):?><?=AdminUi::empty('لا توجد إشعارات.')?><?php else:?><div class="table-wrap"><table><thead><tr><th>الحالة</th><th>العنوان</th><th>التفاصيل</th><th>الوقت</th></tr></thead><tbody><?php foreach($rows as $x):?><tr><td><?=AdminUi::badge($x['severity'])?></td><td><b><?=e($x['title'])?></b><small class="muted" style="display:block"><?=e(AdminUi::label($x['category']))?></small></td><td><?=e($x['body_text'])?></td><td><?=e(AdminUi::date($x['created_at']))?></td></tr><?php endforeach?></tbody></table></div><?php endif?></section>
<?php AdminUi::footer();
