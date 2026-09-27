<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$agents=AgentService::all();$ramy=AgentService::bySlug('ramy');
$ramyMessages=ConversationService::ownerRamyHistory(max(40,(int)setting('context.history_limit','50')));
$businessProject="is_system_project=0 AND COALESCE(source,'manual')<>'hosting_scan'";
$stats=[
 'raw_today'=>(int)db()->query("SELECT COALESCE(SUM(raw_found),0) FROM opportunity_search_runs WHERE state IN ('completed','partial') AND DATE(COALESCE(completed_at,created_at))=CURDATE()")->fetchColumn(),
 'qualified'=>(int)db()->query("SELECT COUNT(*) FROM opportunities WHERE fit_status='qualified' AND status IN ('new','needs_review')")->fetchColumn(),
 'offers'=>(int)db()->query("SELECT COUNT(*) FROM quotes q LEFT JOIN projects p ON p.id=q.project_id WHERE q.status IN ('draft','owner_review','sent','accepted') AND (q.project_id IS NULL OR (p.is_system_project=0 AND COALESCE(p.source,'manual')<>'hosting_scan' AND p.workflow_stage<>'closed'))")->fetchColumn(),
 'projects'=>(int)db()->query("SELECT COUNT(*) FROM projects WHERE {$businessProject} AND workflow_stage<>'closed'")->fetchColumn(),
 'active_tasks'=>(int)db()->query("SELECT COUNT(*) FROM tasks t LEFT JOIN projects p ON p.id=t.project_id WHERE t.status IN ('queued','assigned','working','waiting','blocked','needs_review','review_failed','needs_fix','retesting') AND (t.project_id IS NULL OR (p.is_system_project=0 AND COALESCE(p.source,'manual')<>'hosting_scan'))")->fetchColumn(),
 'customers'=>(int)db()->query("SELECT COUNT(*) FROM customers WHERE status IN ('lead','negotiating','active')")->fetchColumn(),
 'negotiating'=>(int)db()->query("SELECT COUNT(*) FROM projects WHERE {$businessProject} AND workflow_stage='negotiating'")->fetchColumn(),
 'development'=>(int)db()->query("SELECT COUNT(*) FROM projects WHERE {$businessProject} AND workflow_stage IN ('in_development','in_progress','awaiting_execution','waiting_execution')")->fetchColumn(),
 'qa'=>(int)db()->query("SELECT COUNT(*) FROM projects WHERE {$businessProject} AND workflow_stage IN ('awaiting_qa','waiting_review','qa','in_review')")->fetchColumn(),
 'needs_fix'=>(int)db()->query("SELECT COUNT(*) FROM projects WHERE {$businessProject} AND workflow_stage='needs_fix'")->fetchColumn(),
 'ready_delivery'=>(int)db()->query("SELECT COUNT(*) FROM projects WHERE {$businessProject} AND workflow_stage='ready_delivery'")->fetchColumn(),
 'agents_working'=>count(array_filter($agents,static fn($a)=>(string)($a['status']??'')==='working')),
 'jobs_queued'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='queued'")->fetchColumn(),
 'jobs_running'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='running'")->fetchColumn(),
];
$providerState=[];foreach(db()->query("SELECT provider_key,status,last_checked_at FROM providers WHERE provider_key IN ('openrouter','openai','hostinger','meta_whatsapp')")->fetchAll() as $ps)$providerState[$ps['provider_key']]=$ps;
$groupMoney=static function(string $sql):array{$rows=db()->query($sql)->fetchAll();$out=[];foreach($rows as $r){$c=strtoupper(trim((string)($r['currency']??'UNK')))?:'UNK';$out[$c]=(float)($r['amount']??0);}return $out;};
$formatMoney=static function(array $by):array{$primary='0 ج.م';$other=[];if(isset($by['EGP']))$primary=number_format($by['EGP'],0).' ج.م';elseif($by){$c=(string)array_key_first($by);$primary=number_format((float)$by[$c],0).' '.AdminUi::currency($c);}foreach($by as $c=>$a)if($c!=='EGP'&&$c!=='UNK'&&$a>0)$other[]=number_format($a,0).' '.AdminUi::currency($c);return [$primary,$other];};
$profitBy=$groupMoney("SELECT COALESCE(NULLIF(UPPER(currency),''),'UNK') currency,COALESCE(SUM(projected_profit),0) amount FROM opportunities WHERE status IN ('new','needs_review','approved','contacted','negotiating') AND fit_status='qualified' GROUP BY COALESCE(NULLIF(UPPER(currency),''),'UNK') ORDER BY amount DESC");
[$profitPrimary,$profitForeign]=$formatMoney($profitBy);
$collectedBy=$groupMoney("SELECT COALESCE(NULLIF(UPPER(currency),''),'UNK') currency,COALESCE(SUM(amount),0) amount FROM payments WHERE status='confirmed' GROUP BY COALESCE(NULLIF(UPPER(currency),''),'UNK') ORDER BY amount DESC");
[$collectedPrimary,$collectedForeign]=$formatMoney($collectedBy);
// Remaining money is tied to the accepted quote itself. The previous dashboard subtracted
// every historical confirmed payment from every sent/accepted quote total, which could
// understate or overstate what is actually outstanding.
$remainingBy=$groupMoney("SELECT COALESCE(NULLIF(UPPER(q.currency),''),'UNK') currency,COALESCE(SUM(GREATEST(q.amount-COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.status='confirmed' AND UPPER(p.currency)=UPPER(q.currency) AND (p.quote_id=q.id OR (p.quote_id IS NULL AND q.project_id IS NOT NULL AND p.project_id=q.project_id))),0),0)),0) amount FROM quotes q LEFT JOIN projects pr ON pr.id=q.project_id WHERE q.status='accepted' AND (q.project_id IS NULL OR (pr.is_system_project=0 AND COALESCE(pr.source,'manual')<>'hosting_scan' AND pr.workflow_stage<>'closed')) AND (q.project_id IS NULL OR NOT EXISTS (SELECT 1 FROM quotes q2 WHERE q2.project_id=q.project_id AND q2.status='accepted' AND q2.id>q.id)) GROUP BY COALESCE(NULLIF(UPPER(q.currency),''),'UNK') ORDER BY amount DESC");
foreach($remainingBy as $c=>$amount)if((float)$amount<=0)unset($remainingBy[$c]);
[$remainingPrimary,$remainingForeign]=$formatMoney($remainingBy);
$shortlistFloor=max(0,min(100,(int)setting('opportunities.shortlist_min_score','70')));$topOpp=db()->query("SELECT * FROM opportunities WHERE status IN ('new','needs_review') AND fit_status IN ('qualified','review') AND score>=".$shortlistFloor." ORDER BY FIELD(fit_status,'qualified','review'),CASE WHEN shortlist_rank IS NULL THEN 1 ELSE 0 END,shortlist_rank ASC,score DESC,CASE WHEN suggested_offer>0 THEN projected_profit/suggested_offer ELSE 0 END DESC,projected_profit DESC,COALESCE(estimated_days,999) ASC,COALESCE(published_at,last_verified_at,discovered_at,created_at) DESC LIMIT 6")->fetchAll();
$recentProjects=db()->query("SELECT p.*,(SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id AND t.status IN ('queued','assigned','working','waiting','blocked','needs_review','review_failed','needs_fix','retesting')) active_tasks FROM projects p WHERE p.is_system_project=0 AND COALESCE(p.source,'manual')<>'hosting_scan' ORDER BY p.updated_at DESC LIMIT 6")->fetchAll();
$lastRun=db()->query("SELECT * FROM opportunity_search_runs ORDER BY id DESC LIMIT 1")->fetch()?:null;
$worker=(string)setting('runtime.worker_heartbeat_at',setting('runtime.last_worker_at',''));$workerRunState=(string)setting('runtime.worker_last_run_state','');$workerHealthy=$worker!==''&&utc_ts($worker)!==false&&(time()-utc_ts($worker))<600&&$workerRunState!=='failed';$waState=(string)($providerState['meta_whatsapp']['status']??'untested');$aiState=(string)($providerState['openrouter']['status']??'untested');$hostState=(string)($providerState['hostinger']['status']??'untested');
$lastSearch=$lastRun?AdminUi::date($lastRun['completed_at']?:$lastRun['created_at']):'لم يبدأ بعد';
$hour=(int)date('G');$greeting=$hour<12?'صباح الخير':($hour<18?'مساء الخير':'مساء الخير');
AdminUi::header('نظرة عامة','dashboard');echo AdminUi::flash();
?>
<section class="home-greeting">
  <div>
    <div class="quick-status"><span data-agent-runtime="ramy" data-agent-badge><?=AdminUi::badge((string)$ramy['status'])?></span><span class="pill">آخر بحث وليد: <?=e($lastSearch)?></span><?php if($worker):?><span class="pill">التشغيل: <?=e(AdminUi::date($worker))?></span><?php endif?></div>
    <h2><?=e($greeting)?> يا بومبو</h2>
    <p>دي أهم الفرص والمشاريع وحالة الفريق من مكان واحد.</p>
  </div>
  <div class="home-greeting-icon" aria-hidden="true">ϟ</div>
</section>

<div class="metric-grid metric-grid-4">
  <?=AdminUi::stat('نتائج فحص وليد',$stats['raw_today'],'اليوم — Runs مكتملة/جزئية فقط') ?>
  <?=AdminUi::stat('فرص مؤهلة مفتوحة',$stats['qualified'],'Qualified + New/Needs Review') ?>
  <?=AdminUi::stat('عروض نشطة',$stats['offers'],'Draft / Review / Sent / Accepted') ?>
  <?=AdminUi::stat('ربح متوقع',$profitPrimary,$profitForeign?('عملات أخرى: '.implode(' · ',$profitForeign)):'من الفرص المفتوحة') ?>
  <?=AdminUi::stat('قيد التفاوض',$stats['negotiating'],'مشاريع') ?>
  <?=AdminUi::stat('قيد التنفيذ',$stats['development'],'مشاريع') ?>
  <?=AdminUi::stat('عند عماد / QA',$stats['qa'],'مشاريع') ?>
  <?=AdminUi::stat('تحتاج إصلاح',$stats['needs_fix'],'مشاريع') ?>
  <?=AdminUi::stat('جاهزة للتسليم',$stats['ready_delivery'],'مشاريع') ?>
  <?=AdminUi::stat('المبالغ المحصلة',$collectedPrimary,$collectedForeign?('عملات أخرى: '.implode(' · ',$collectedForeign)):'مدفوعات مؤكدة') ?>
  <?=AdminUi::stat('المبالغ المتبقية',$remainingPrimary,$remainingForeign?('عملات أخرى: '.implode(' · ',$remainingForeign)):'العروض المقبولة فقط ناقص مدفوعاتها المؤكدة') ?>
  <section class="card stat-card"><span class="muted small">الوكلاء العاملين</span><div class="stat" data-agents-working><?=e((string)$stats['agents_working'])?></div><small class="muted"><?=e((string)count($agents))?> وكلاء إجمالًا</small></section>
</div>
<div class="quick-status" style="margin:0 0 16px"><span class="pill" data-worker-state>Worker: <?=e($workerHealthy?'Healthy':'Stale / Unknown')?></span><span class="pill">WhatsApp: <?=e(AdminUi::label($waState))?></span><span class="pill">OpenRouter: <?=e(AdminUi::label($aiState))?></span><span class="pill">Queue: <?=e((string)$stats['jobs_queued'])?> · Running: <?=e((string)$stats['jobs_running'])?></span><span class="pill">Hostinger: <?=e(AdminUi::label($hostState))?></span></div>

<div class="dashboard-command-grid dashboard-command-grid-8">
  <a class="dashboard-command-card primary-card" href="team-chat.php"><i>✉</i><strong>شات الوكلاء</strong><small>أنت + رامي + وليد + أيمن + عماد</small></a>
  <a class="dashboard-command-card" href="communications.php"><i>☎</i><strong>محادثات العملاء</strong><small>واتساب والرسائل والتحكم</small></a>
  <a class="dashboard-command-card" href="opportunity-hunt.php"><i>⌕</i><strong>صيد المشاريع</strong><small>وليد والفرص الحديثة الحقيقية</small></a>
  <a class="dashboard-command-card" href="orders.php"><i>▣</i><strong>تقييم الفرص</strong><small>الميزانية والعملة والربحية</small></a>
  <a class="dashboard-command-card" href="projects.php"><i>◫</i><strong>المشاريع</strong><small><?=e((string)$stats['projects'])?> مشروع مفتوح</small></a>
  <a class="dashboard-command-card" href="tasks.php"><i>✓</i><strong>المهام</strong><small><?=e((string)$stats['active_tasks'])?> مهمة نشطة</small></a>
  <a class="dashboard-command-card" href="queue.php"><i>⇄</i><strong>طابور التنفيذ</strong><small>عرض حي للـJobs والـWorker وإعادة المحاولة</small></a>
  <a class="dashboard-command-card" href="team.php"><i>◉</i><strong>فريق التنفيذ</strong><small>الهويات والذاكرة والصلاحيات</small></a>
  <a class="dashboard-command-card" href="operations.php"><i>↻</i><strong>مركز التشغيل</strong><small>Worker والربط والاستضافة</small></a>
  <a class="dashboard-command-card" href="technology.php"><i>ϟ</i><strong>المسار التقني الحي</strong><small>Pipeline وQueue وEvidence وQA لحظيًا</small></a>
</div>

<div class="grid home-main-grid">
<section class="card span-7 ramy-home-chat">
  <div class="card-title team-chat-title"><div><h2>دردشتي مع رامي</h2><span class="muted small">يحمّل سياقًا تشغيليًا وذاكرة المشروع وآخر الرسائل والقرارات قبل الرد.</span></div><a class="btn secondary small-btn" href="communications.php?type=owner">السجل</a></div>
  <div class="chat-box compact-chat" data-chat><?php if(!$ramyMessages):?><?=AdminUi::empty('اكتب أول رسالة لرامي.')?><?php else:foreach($ramyMessages as $m)echo AdminUi::messageBubble($m);endif?></div>
  <form class="chat-form" data-chat-form><?=AdminUi::csrf()?><textarea name="message" placeholder="اكتب لرامي بشكل طبيعي..."></textarea><button class="btn">إرسال</button></form>
</section>

<section class="card span-5"><div class="card-title"><h2>فريق التنفيذ</h2><a class="btn secondary small-btn" href="team.php">إدارة</a></div><?php foreach($agents as $a):?><a class="agent-row" data-agent-runtime="<?=e($a['slug'])?>" href="agent-profile.php?slug=<?=e($a['slug'])?>"><div class="avatar"><?=e(pb_substr($a['display_name'],0,1))?></div><div class="grow"><b><?=e($a['display_name'])?></b><small><?=e($a['role_title'])?> · <span data-agent-task><?=e($a['current_task_id']?'المهمة #'.$a['current_task_id']:'بدون مهمة حالية')?></span> · مكتمل <?=(int)$a['completed_tasks']?> · فشل <?=(int)$a['failed_tasks']?></small></div><span data-agent-badge><?=AdminUi::badge((string)$a['status'])?></span></a><?php endforeach?></section>

<section class="card span-12 home-opportunities-v48"><div class="card-title"><div><h2>أفضل الفرص اليوم</h2><span class="muted small">مرتبة حسب التأهيل والدرجة والربحية وسهولة التنفيذ والحداثة.</span></div><a class="btn secondary small-btn" href="orders.php">كل الفرص</a></div><div class="opportunity-list-v48 home-list"><?php $displayRank=0;foreach($topOpp as $o):$displayRank++;echo AdminUi::opportunityCard($o,$displayRank,'/api/admin/',true);endforeach?><?php if(!$topOpp):?><?=AdminUi::empty('لا توجد فرص جديدة.')?><?php endif?></div></section>

<section class="card span-12"><div class="card-title"><h2>المشاريع الحالية</h2><a class="btn secondary small-btn" href="projects.php">كل المشاريع</a></div><div class="home-card-grid compact-grid"><?php foreach($recentProjects as $p):?><a class="home-action-card" href="project.php?id=<?=$p['id']?>"><b><?=e($p['name'])?></b><span><?=e($p['primary_domain']?:'بدون دومين')?> · <?=e(AdminUi::label($p['workflow_stage']??'discovered'))?></span><span><?=e((string)$p['active_tasks'])?> مهمة نشطة</span></a><?php endforeach?><?php if(!$recentProjects):?><?=AdminUi::empty('لا توجد مشاريع بعد.')?><?php endif?></div></section>
</div>
<?php AdminUi::footer();
