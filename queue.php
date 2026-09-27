<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
AdminUi::header('طابور التنفيذ الحي','queue');echo AdminUi::flash();
$csrf=Auth::csrf();
?>
<section class="tech-hero card">
  <div><span class="eyebrow">LIVE JOB QUEUE</span><h2>ما الذي يعمل الآن؟ ومن ينتظر؟ ولماذا؟</h2><p>عرض حي للـJobs والـWorker وإعادة المحاولة. الصفحة تفرق بين «في الطابور» و«يعمل فعليًا» و«ينتظر Retry»، وتوقظ Worker تلقائيًا عند وجود Jobs جاهزة بدون عامل نشط.</p></div>
  <div class="tech-live"><span class="live-dot"></span><b>LIVE</b><small data-q-updated>تحديث كل ثانيتين</small></div>
</section>

<div class="metric-grid metric-grid-4">
 <section class="card stat-card"><span class="muted small">Worker</span><div class="stat" data-q-worker>—</div><small data-q-heartbeat>—</small></section>
 <section class="card stat-card"><span class="muted small">في الطابور</span><div class="stat" data-q-count="queued">0</div><small data-q-oldest>أقدم انتظار: —</small></section>
 <section class="card stat-card"><span class="muted small">يعمل الآن</span><div class="stat" data-q-count="running">0</div><small>Jobs بدأت فعليًا</small></section>
 <section class="card stat-card"><span class="muted small">ينتظر Retry</span><div class="stat" data-q-count="waiting">0</div><small>ليس تنفيذًا نشطًا</small></section>
</div>
<div class="metric-grid metric-grid-4" style="margin-top:12px">
 <section class="card stat-card"><span class="muted small">فشل خلال 24h</span><div class="stat" data-q-count="failed">0</div><small>تحتاج مراجعة أو إعادة تشغيل</small></section>
 <section class="card stat-card"><span class="muted small">اكتمل خلال 24h</span><div class="stat" data-q-count="done">0</div><small>عمليات انتهت بنجاح</small></section>
 <section class="card stat-card"><span class="muted small">جاهز الآن للعامل</span><div class="stat" data-q-due>0</div><small>available_at وصل</small></section>
 <section class="card stat-card"><span class="muted small">آخر Search وليد</span><div class="stat" style="font-size:20px" data-q-walid>—</div><small data-q-walid-note>—</small></section>
</div>

<section class="card" style="margin-top:14px">
 <div class="card-title"><div><h2>تحكم سريع</h2><span class="muted small">تحكم وتشخيص للعامل. عند تفعيل CLI Cron، الأزرار تجدول التنفيذ للدورة التالية بدل تشغيل بحث طويل داخل طلب المتصفح.</span></div><a class="btn secondary small-btn" href="health.php">حالة النظام</a></div>
 <div class="actions">
  <form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="worker_kick"><input type="hidden" name="limit" value="3"><input type="hidden" name="return" value="queue.php"><button class="btn">إيقاظ Worker الآن</button></form>
  <form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="run_worker"><input type="hidden" name="limit" value="3"><input type="hidden" name="return" value="queue.php"><button class="btn secondary">جدولة Job للدورة التالية</button></form>
  <form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="install_worker_cron"><input type="hidden" name="return" value="queue.php"><button class="btn secondary">تأكد من Cron Worker</button></form>
  <a class="btn secondary" href="tasks.php">كل المهام</a><a class="btn secondary" href="technology.php">المسار التقني</a>
 </div>
</section>

<section class="card" style="margin-top:14px">
 <div class="card-title"><div><h2>العمليات الحية</h2><span class="muted small">Running أولًا، ثم Queued/Waiting/Failed. زمن الانتظار محسوب من قاعدة البيانات.</span></div><span class="muted small" data-q-kick>—</span></div>
 <div class="table-wrap"><table><thead><tr><th>Job</th><th>النوع</th><th>الوكيل / المهمة</th><th>الحالة</th><th>الانتظار / التشغيل</th><th>المحاولة</th><th>الخطأ / Retry</th><th>إجراء</th></tr></thead><tbody data-q-jobs><tr><td colspan="8"><div class="empty">جاري تحميل الطابور…</div></td></tr></tbody></table></div>
</section>

<style>
.queue-error{max-width:420px;white-space:normal}.queue-error b{display:block;margin-bottom:4px}.queue-time{font-variant-numeric:tabular-nums}.queue-running{box-shadow:inset 3px 0 0 var(--ok,#42e69a)}.queue-queued{box-shadow:inset 3px 0 0 var(--accent,#74f2a7)}.queue-failed{box-shadow:inset 3px 0 0 var(--danger,#ef6b73)}.queue-waiting{box-shadow:inset 3px 0 0 #e7b65b}
</style>
<script>
(function(){
 const CSRF=<?=json_encode($csrf,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const fmt=s=>{s=Math.max(0,Number(s||0));if(s<60)return Math.floor(s)+'ث';if(s<3600)return Math.floor(s/60)+'د '+Math.floor(s%60)+'ث';return Math.floor(s/3600)+'س '+Math.floor((s%3600)/60)+'د';};
 const badge=(state,label)=>'<span class="badge s-'+esc(String(state||'').replaceAll('_','-'))+'">'+esc(label||state||'—')+'</span>';
 function jobRow(j){
   let timing='—';if(j.state==='running')timing='يعمل '+fmt(j.running_seconds);else if(j.state==='queued')timing=(Number(j.ready_in_seconds)>0?'جاهز بعد '+fmt(j.ready_in_seconds):'ينتظر '+fmt(j.wait_seconds));else if(j.state==='waiting')timing=(Number(j.retry_in_seconds)>0?'Retry بعد '+fmt(j.retry_in_seconds):'معلّق');else if(j.duration_ms)timing=fmt(Number(j.duration_ms)/1000);
   let err='—';if(j.error_code){err='<div class="queue-error"><b>'+esc(j.error_title||j.error_human||j.error_code)+'</b><small>'+esc(j.error_code)+'</small>'+(j.error_fix?'<small>'+esc(j.error_fix)+'</small>':'')+'</div>';}
   let action=j.task_id?'<a class="btn secondary small-btn" href="tasks.php?id='+Number(j.task_id)+'">المهمة</a>':'—';
   if(j.state==='failed'||j.state==='waiting')action='<form method="post" action="action.php" style="display:inline"><input type="hidden" name="csrf" value="'+esc(CSRF)+'"><input type="hidden" name="action" value="job_retry"><input type="hidden" name="job_id" value="'+Number(j.id)+'"><input type="hidden" name="return" value="queue.php"><button class="btn small-btn">Retry الآن</button></form> '+action;
   return '<tr class="queue-'+esc(j.state)+'"><td><b>#'+Number(j.id)+'</b><small class="table-sub">'+esc(j.created_display)+'</small></td><td>'+esc(j.kind_label||j.kind)+(j.live_detail?'<small class="table-sub">'+esc(j.live_detail)+'</small>':'')+'</td><td><b>'+esc(j.agent_name||'System')+'</b><small class="table-sub">'+esc(j.task_title||'بدون مهمة')+(j.project_name?' · '+esc(j.project_name):'')+'</small></td><td>'+badge(j.state,j.state_label)+'</td><td class="queue-time">'+esc(timing)+'</td><td>'+Number(j.attempts||0)+'/'+Number(j.max_attempts||0)+'</td><td>'+err+'</td><td><div class="actions">'+action+'</div></td></tr>';
 }
 async function refresh(){try{const r=await fetch('queue-feed.php',{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error('HTTP '+r.status);const d=await r.json();if(!d.ok)return;
   const cli=d.worker.cron_mode==='cli';document.querySelector('[data-q-worker]').textContent=cli?(d.worker.healthy?'CLI Healthy':'CLI Scheduled'):(d.worker.healthy?'Healthy':'Fault');let hb=(d.worker.heartbeat_display||'لا يوجد Heartbeat');if(cli&&Number.isFinite(Number(d.worker.next_tick_seconds)))hb+=' · الدورة التالية خلال '+fmt(Number(d.worker.next_tick_seconds));if(d.worker.last_run_state==='failed'&&d.worker.last_run_error)hb+=' · آخر تشغيل فشل: '+d.worker.last_run_error;document.querySelector('[data-q-heartbeat]').textContent=hb;
   for(const k of ['queued','running','waiting','failed','done']){const el=document.querySelector('[data-q-count="'+k+'"]');if(el)el.textContent=Number((d.counts||{})[k]||0);}
   document.querySelector('[data-q-due]').textContent=Number(d.due_queued||0);document.querySelector('[data-q-oldest]').textContent='أقدم انتظار: '+fmt(d.oldest_wait_seconds||0);
   const w=d.last_walid_run||null;document.querySelector('[data-q-walid]').textContent=w?(w.state_label||w.state):'لا يوجد';document.querySelector('[data-q-walid-note]').textContent=w?('Raw '+Number(w.raw_found||0)+' · مؤهل '+Number(w.qualified_found||0)+' · مراجعة '+Number(w.review_found||0)):'لم يبدأ بحث بعد';
   const kick=document.querySelector('[data-q-kick]');if(kick){const ks=d.worker.last_kick_state||'—';const kc=d.worker.last_kick_code||'';kick.textContent=(kc==='worker_cli_cron_scheduled'?'CLI Cron: مجدول للدورة التالية':'آخر Wakeup: '+ks+' '+kc)+(d.worker.last_kick_error?' · '+d.worker.last_kick_error:'');}
   const body=document.querySelector('[data-q-jobs]');body.innerHTML=(d.jobs||[]).map(jobRow).join('')||'<tr><td colspan="8"><div class="empty">الطابور فارغ حاليًا.</div></td></tr>';
   document.querySelector('[data-q-updated]').textContent='آخر تحديث: '+new Date().toLocaleTimeString('ar-EG');
 }catch(e){document.querySelector('[data-q-updated]').textContent='تعذر التحديث — سيعاد تلقائيًا';}}
 refresh();setInterval(refresh,2000);
})();
</script>
<?php AdminUi::footer();
