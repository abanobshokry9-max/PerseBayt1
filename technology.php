<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
try{AgentService::reconcileRuntimeStates();}catch(Throwable){}

$agents=[];try{$agents=AgentService::runtimeSnapshot();}catch(Throwable){}
$agentBySlug=[];foreach($agents as $a)$agentBySlug[(string)$a['slug']]=$a;
$providers=[];try{$providers=db()->query("SELECT provider_key,label,enabled,status,last_error,last_checked_at FROM providers ORDER BY FIELD(kind,'messaging','hosting','ai','browser','generic','voice'),provider_key")->fetchAll();}catch(Throwable){}
$providerByKey=[];foreach($providers as $p)$providerByKey[(string)$p['provider_key']]=$p;

$heartbeat=(string)setting('runtime.worker_heartbeat_at','');$hbTs=utc_ts($heartbeat);$workerHealthy=$hbTs!==false&&(time()-$hbTs)<600;
$qCount=['queued'=>0,'running'=>0,'waiting'=>0,'failed'=>0];
try{$qCount=['queued'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='queued'")->fetchColumn(),'running'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='running'")->fetchColumn(),'waiting'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='waiting'")->fetchColumn(),'failed'=>(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn()];}catch(Throwable){}
$activeTasks=0;try{$activeTasks=(int)db()->query("SELECT COUNT(*) FROM tasks WHERE status IN ('new','queued','assigned','working','waiting','blocked','needs_review','needs_fix','retesting')")->fetchColumn();}catch(Throwable){}

$ramyMs=null;$allMs=null;try{
 $rows=db()->query("SELECT a.slug,AVG(j.duration_ms) avg_ms FROM jobs j JOIN agents a ON a.id=j.agent_id WHERE j.state='done' AND j.duration_ms IS NOT NULL AND j.finished_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY a.slug")->fetchAll();
 $vals=[];foreach($rows as $r){$v=(float)$r['avg_ms'];$vals[]=$v;if((string)$r['slug']==='ramy')$ramyMs=$v;}if($vals)$allMs=array_sum($vals)/count($vals);
}catch(Throwable){}
$outTotal=0;$outOk=0;try{$r=db()->query("SELECT COUNT(*) total,SUM(CASE WHEN status NOT IN ('failed','error') THEN 1 ELSE 0 END) ok FROM messages WHERE direction='outbound' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetch();$outTotal=(int)($r['total']??0);$outOk=(int)($r['ok']??0);}catch(Throwable){}
$messageRate=$outTotal>0?(int)round(($outOk/$outTotal)*100):null;

$team=[];try{$team=TeamChatService::recent(8);}catch(Throwable){}
$initiatives=[];try{$initiatives=array_slice(AgentInitiativeService::all(20),0,7);}catch(Throwable){}
$autonomy=[];try{$autonomy=db()->query("SELECT a.slug,a.display_name,x.brain_enabled,x.initiative_enabled,x.followup_enabled,x.last_brain_state,x.last_brain_note,x.last_think_at,x.next_run_at FROM agent_autonomy x JOIN agents a ON a.id=x.agent_id WHERE a.is_active=1 ORDER BY FIELD(a.slug,'ramy','walid','ayman','emad','samir-social','video-director','community-manager','basant','free-model-scout'),a.id LIMIT 20")->fetchAll();}catch(Throwable){}
$errors=[];try{$errors=SystemDoctor::recentErrors(8);}catch(Throwable){}
$recentJobs=[];try{$recentJobs=db()->query("SELECT j.id,j.kind,j.state,j.error_code,j.updated_at,j.error_text,j.result_json,a.slug agent_slug,a.display_name agent_name,t.title task_title,p.name project_name FROM jobs j LEFT JOIN agents a ON a.id=j.agent_id LEFT JOIN tasks t ON t.id=j.task_id LEFT JOIN projects p ON p.id=j.project_id ORDER BY j.id DESC LIMIT 12")->fetchAll();}catch(Throwable){}

$agentTasks=[];$agentTaskCounts=[];$agentErrorCounts=[];$agentDoneToday=[];$agentLast24=[];$agentLastError=[];$agentRecentDone=[];
try{
 $trows=db()->query("SELECT t.id,t.title,t.status,t.priority,t.updated_at,t.created_at,t.agent_id,a.slug agent_slug FROM tasks t LEFT JOIN agents a ON a.id=t.agent_id WHERE t.status IN ('new','queued','assigned','working','waiting','blocked','needs_review','needs_fix','retesting') ORDER BY FIELD(t.priority,'critical','high','normal','low'),t.updated_at DESC LIMIT 400")->fetchAll();
 foreach($trows as $t){$slug=(string)($t['agent_slug']??'');if($slug==='')continue;$agentTasks[$slug][]=$t;}
 $crow=db()->query("SELECT a.slug,COUNT(*) c FROM tasks t JOIN agents a ON a.id=t.agent_id WHERE t.status IN ('new','queued','assigned','working','waiting','blocked','needs_review','needs_fix','retesting') GROUP BY a.slug")->fetchAll();foreach($crow as $r)$agentTaskCounts[(string)$r['slug']]=(int)$r['c'];
 $erow=db()->query("SELECT a.slug,COUNT(*) c FROM jobs j JOIN agents a ON a.id=j.agent_id WHERE j.state='failed' AND j.updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY a.slug")->fetchAll();foreach($erow as $r)$agentErrorCounts[(string)$r['slug']]=(int)$r['c'];
 $drow=db()->query("SELECT a.slug,COUNT(*) c FROM jobs j JOIN agents a ON a.id=j.agent_id WHERE j.state='done' AND j.finished_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY a.slug")->fetchAll();foreach($drow as $r)$agentDoneToday[(string)$r['slug']]=(int)$r['c'];
 $lrow=db()->query("SELECT a.slug,MAX(j.updated_at) last_at FROM jobs j JOIN agents a ON a.id=j.agent_id WHERE j.updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY a.slug")->fetchAll();foreach($lrow as $r)$agentLast24[(string)$r['slug']]=(string)$r['last_at'];
 $xrow=db()->query("SELECT a.slug,j.error_code,j.updated_at,j.kind FROM jobs j JOIN agents a ON a.id=j.agent_id WHERE j.state='failed' AND j.updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY j.updated_at DESC")->fetchAll();foreach($xrow as $r){if(!isset($agentLastError[(string)$r['slug']]))$agentLastError[(string)$r['slug']]=$r;}
 $rd=db()->query("SELECT t.id,t.title,t.status,t.updated_at,a.slug agent_slug FROM tasks t JOIN agents a ON a.id=t.agent_id WHERE t.status IN ('completed','done') AND t.updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY t.updated_at DESC LIMIT 40")->fetchAll();
 foreach($rd as $r){$slug=(string)$r['agent_slug'];if(!isset($agentRecentDone[$slug]))$agentRecentDone[$slug]=$r;}
}catch(Throwable){}

$disabledAgents=[];
try{$disabledAgents=db()->query("SELECT id,slug,display_name,status,is_active,last_error_code,updated_at FROM agents WHERE is_active=0 OR status='disabled' OR status='error'")->fetchAll();}catch(Throwable){}

$priorityOrder=['ramy'=>0,'walid'=>1,'ayman'=>2,'emad'=>3,'samir-social'=>4,'video-director'=>5,'community-manager'=>6,'basant'=>7,'free-model-scout'=>8];
$statusWeight=['working'=>0,'online'=>0,'running'=>0,'idle'=>1,'waiting'=>2,'queued'=>2,'error'=>3,'failed'=>3,'disabled'=>4];
$orderedAgents=$agents;
usort($orderedAgents,static function($a,$b)use($priorityOrder,$statusWeight,$agentTaskCounts,$agentErrorCounts){
 $sa=(string)($a['slug']??'');$sb=(string)($b['slug']??'');
 $ea=$agentErrorCounts[$sa]??0;$eb=$agentErrorCounts[$sb]??0;if($ea!==$eb)return $eb<=>$ea;
 $ta=$agentTaskCounts[$sa]??0;$tb=$agentTaskCounts[$sb]??0;if($ta!==$tb)return $tb<=>$ta;
 $wa=$statusWeight[(string)($a['status']??'idle')]??9;$wb=$statusWeight[(string)($b['status']??'idle')]??9;if($wa!==$wb)return $wa<=>$wb;
 return ($priorityOrder[$sa]??99)<=>($priorityOrder[$sb]??99);
});

$stateClass=static function(string $s,bool $enabled=true):string{
 if(!$enabled)return 's-off';
 return match($s){'working','running','verified','done','completed','approved','online'=>'s-working','failed','error','critical','blocked'=>'s-error','queued','waiting','needs_review','retesting','proposed','approved_warning'=>'s-waiting',default=>'s-idle'};
};
$providerState=static function(?array $p)use($stateClass):string{return !$p?'s-off':$stateClass((string)($p['status']??''),(bool)($p['enabled']??false));};
$agentState=static function(string $slug)use($agentBySlug,$stateClass):string{return isset($agentBySlug[$slug])?$stateClass((string)($agentBySlug[$slug]['status']??'idle'),true):'s-off';};
$agentTask=static function(string $slug)use($agentBySlug):string{return isset($agentBySlug[$slug])?((string)($agentBySlug[$slug]['current_task_title']??'')?:AdminUi::label((string)($agentBySlug[$slug]['status']??'idle'))):'غير متاح';};
$senderName=static function(array $m)use($agentBySlug):string{
 if(($m['sender_type']??'')==='owner')return 'أبانوب';
 if(($m['sender_type']??'')==='system')return 'النظام';
 $slug=(string)($m['sender_ref']??'');return (string)($m['agent_name']??($agentBySlug[$slug]['display_name']??AdminUi::label($slug)));
};
$taskStatusClass=static function(string $s):string{
 return match($s){'working','running'=>'is-running','blocked','failed'=>'is-blocked','needs_fix','needs_review'=>'is-needs-fix','retesting'=>'is-retesting','waiting'=>'is-waiting','queued'=>'is-queued','completed','done'=>'is-done',default=>'is-new'};
};
$taskStatusLabel=static function(string $s):string{
 return match($s){'working'=>'قيد التنفيذ','running'=>'قيد التنفيذ','blocked'=>'متوقف','failed'=>'فشل','needs_fix'=>'يحتاج إصلاح','needs_review'=>'تحت المراجعة','retesting'=>'إعادة اختبار','waiting'=>'ينتظر','queued'=>'في الطابور','new'=>'جديد','assigned'=>'مُكلّف','completed'=>'مكتمل','done'=>'مكتمل',default=>AdminUi::label($s)};
};
$agentCls=static function(string $slug,string $st)use($agentErrorCounts):string{
 $err=$agentErrorCounts[$slug]??0;if($err>0)return 'is-error';
 if(in_array($st,['disabled','error','failed'],true))return 'is-error';
 if(in_array($st,['working','running','online'],true))return 'is-working';
 if(in_array($st,['waiting','queued','needs_review'],true))return 'is-waiting';
 return 'is-idle';
};

$workerNodes=[
 'walid'=>['x'=>51.25,'y'=>11.11,'icon'=>'⌁','label'=>'وليد الباحث'],
 'ayman'=>['x'=>64.375,'y'=>11.11,'icon'=>'⚙','label'=>'أيمن المنفذ'],
 'emad'=>['x'=>51.25,'y'=>33.33,'icon'=>'✓','label'=>'عماد المراجع'],
 'samir-social'=>['x'=>64.375,'y'=>33.33,'icon'=>'✎','label'=>'سمير سوشيال'],
 'video-director'=>['x'=>51.25,'y'=>55.56,'icon'=>'▶','label'=>'منى الفيديو'],
 'community-manager'=>['x'=>64.375,'y'=>55.56,'icon'=>'☷','label'=>'مدير المجتمع'],
 'basant'=>['x'=>51.25,'y'=>77.78,'icon'=>'✿','label'=>'بسنت الفارس'],
 'free-model-scout'=>['x'=>64.375,'y'=>77.78,'icon'=>'✦','label'=>'نور الموديلات'],
];

$healthGood=$workerHealthy&&$qCount['failed']===0;
$whatsapp=$providerByKey['meta_whatsapp']??null;$hostinger=$providerByKey['hostinger']??null;
$onlineCount=count(array_filter($agents,static fn($a)=>!in_array((string)($a['status']??''),['disabled','error'],true)));

AdminUi::header('المسار التقني الحي','technology');echo AdminUi::flash();
?>
<style>
.tech-v30{--tx-bg:#0b1220;--tx-panel:#0f172a;--tx-line:#1e293b;--tx-line2:#243347;--tx-mute:#7c8ba1;--tx-green:#22c55e;--tx-red:#ef4444;--tx-amber:#f59e0b;--tx-blue:#3b82f6;--tx-violet:#8b5cf6;--tx-teal:#14b8a6;--tx-pink:#ec4899;color:#e2e8f0}
.tech-v30 .tech30-hero{display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center;padding:22px 24px;border-radius:18px;background:radial-gradient(1200px 400px at 10% -10%,rgba(59,130,246,.28),transparent 60%),radial-gradient(900px 300px at 100% 0%,rgba(139,92,246,.22),transparent 55%),linear-gradient(180deg,#0d1526,#0a101d);border:1px solid var(--tx-line2);box-shadow:0 30px 80px -40px rgba(59,130,246,.45);position:relative;overflow:hidden}
.tech-v30 .tech30-hero::after{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(148,163,184,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(148,163,184,.05) 1px,transparent 1px);background-size:32px 32px;pointer-events:none}
.tech-v30 .tech30-hero h2{margin:6px 0 8px;font-size:26px;color:#f8fafc;letter-spacing:-.5px}
.tech-v30 .tech30-hero p{margin:0;color:var(--tx-mute);line-height:1.75;font-size:13.5px;max-width:820px}
.tech-v30 .tech30-kicker{display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border-radius:999px;background:rgba(34,197,94,.12);color:#4ade80;font-size:11.5px;font-weight:700;letter-spacing:.4px;border:1px solid rgba(34,197,94,.25)}
.tech-v30 .tech30-kicker i{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.7);animation:pulseDot 1.6s infinite}
@keyframes pulseDot{0%{box-shadow:0 0 0 0 rgba(34,197,94,.7)}70%{box-shadow:0 0 0 12px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
.tech-v30 .tech30-actions{display:flex;flex-wrap:wrap;gap:8px;position:relative;z-index:1}
.tech-v30 .tech30-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--tx-line2);background:linear-gradient(180deg,#16223a,#101a2e);color:#e2e8f0;font-size:12.5px;font-weight:600;text-decoration:none;cursor:pointer;transition:.2s}
.tech-v30 .tech30-btn:hover{border-color:#3b82f6;background:linear-gradient(180deg,#1e293b,#16223a);transform:translateY(-1px)}
.tech-v30 .tech30-btn.primary{background:linear-gradient(180deg,#2563eb,#1d4ed8);border-color:#1d4ed8;color:#fff}
.tech-v30 .tech30-live{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25)}
.tech-v30 .tech30-live .dot{width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 12px #22c55e;animation:pulseDot 1.8s infinite}
.tech-v30 .tech30-live b{font-size:12.5px;color:#86efac;display:block}
.tech-v30 .tech30-live small{color:#64748b;font-size:10.5px}

.tech-v30 .tech30-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0}
.tech-v30 .tech30-kpi{position:relative;padding:16px;border-radius:14px;background:linear-gradient(180deg,#0f172a,#0b1322);border:1px solid var(--tx-line);overflow:hidden;transition:.25s}
.tech-v30 .tech30-kpi:hover{transform:translateY(-2px);border-color:#334155}
.tech-v30 .tech30-kpi::before{content:"";position:absolute;top:0;right:0;width:60%;height:3px;background:linear-gradient(90deg,transparent,var(--accent))}
.tech-v30 .tech30-kpi.green{--accent:#22c55e}.tech-v30 .tech30-kpi.blue{--accent:#3b82f6}.tech-v30 .tech30-kpi.amber{--accent:#f59e0b}.tech-v30 .tech30-kpi.red{--accent:#ef4444}.tech-v30 .tech30-kpi.teal{--accent:#14b8a6}.tech-v30 .tech30-kpi.violet{--accent:#8b5cf6}
.tech-v30 .tech30-kpi span.ic{display:inline-flex;width:32px;height:32px;align-items:center;justify-content:center;border-radius:9px;background:color-mix(in oklab,var(--accent) 20%,transparent);color:var(--accent);font-size:15px}
.tech-v30 .tech30-kpi small{display:block;margin-top:10px;color:#94a3b8;font-size:11px;letter-spacing:.3px}
.tech-v30 .tech30-kpi b{display:block;margin-top:4px;color:#f1f5f9;font-size:19px;font-weight:800}
.tech-v30 .tech30-kpi em{display:block;margin-top:2px;color:#64748b;font-size:10.5px;font-style:normal}

.tech-v30 .tech30-panel{border-radius:16px;background:linear-gradient(180deg,#0f172a,#0a1220);border:1px solid var(--tx-line);overflow:hidden}
.tech-v30 .tech30-panel-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--tx-line);background:linear-gradient(180deg,#101a2e,#0c1524)}
.tech-v30 .tech30-panel-h .title{display:flex;align-items:center;gap:10px}
.tech-v30 .tech30-panel-h .title span.ic{display:inline-flex;width:30px;height:30px;align-items:center;justify-content:center;border-radius:9px;background:rgba(59,130,246,.15);color:#60a5fa;font-size:14px}
.tech-v30 .tech30-panel-h h3{margin:0;font-size:14.5px;color:#f1f5f9;letter-spacing:-.2px}
.tech-v30 .tech30-panel-h p{margin:2px 0 0;font-size:11px;color:#64748b}
.tech-v30 .tech30-panel-h a{font-size:11.5px;color:#60a5fa;text-decoration:none;padding:6px 10px;border-radius:8px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2)}
.tech-v30 .tech30-panel-h a:hover{background:rgba(59,130,246,.16)}

/* ============ Flow v32 ============ */
.tech-v30 .flow32-wrap{padding:14px}
.tech-v30 .flow32-scroll{overflow:auto;border-radius:14px;background:
 radial-gradient(800px 400px at 15% -10%,rgba(59,130,246,.14),transparent 60%),
 radial-gradient(700px 400px at 85% 110%,rgba(139,92,246,.12),transparent 60%),
 #080f1c;border:1px solid var(--tx-line2);position:relative}
.tech-v30 .flow32-scroll::before{content:"";position:absolute;inset:0;
 background-image:linear-gradient(rgba(148,163,184,.04) 1px,transparent 1px),
                  linear-gradient(90deg,rgba(148,163,184,.04) 1px,transparent 1px);
 background-size:40px 40px;pointer-events:none;border-radius:14px}
.tech-v30 .flow32-board{position:relative;min-width:1600px;height:900px}
.tech-v30 .flow32-svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible}

.tech-v30 .flow32-line{fill:none;stroke-width:2;stroke-linecap:round;
 transition:stroke-width .25s,opacity .25s,stroke .25s;
 opacity:.35;stroke-dasharray:8 10}
.tech-v30 .flow32-line.is-active{
 opacity:1;stroke-width:2.6;stroke-dasharray:14 8;
 animation:flow32Dash 1s linear infinite;
 filter:drop-shadow(0 0 8px currentColor)}
.tech-v30 .flow32-line.is-cut{
 stroke:#ef4444 !important;opacity:.95;
 stroke-dasharray:3 6;stroke-width:3;
 animation:flow32Cut 1.1s ease-in-out infinite;
 filter:drop-shadow(0 0 10px rgba(239,68,68,.7))}
@keyframes flow32Dash{to{stroke-dashoffset:-44}}
@keyframes flow32Cut{50%{opacity:.35;stroke-width:4}}

.line-green{stroke:#22c55e}.line-blue{stroke:#3b82f6}.line-amber{stroke:#f59e0b}
.line-violet{stroke:#8b5cf6}.line-teal{stroke:#14b8a6}.line-pink{stroke:#ec4899}

.tech-v30 .flow32-cut-mark{
 position:absolute;transform:translate(-50%,-50%);
 width:22px;height:22px;border-radius:50%;
 background:radial-gradient(circle,#ef4444 0 40%,rgba(239,68,68,.2) 45% 70%,transparent 75%);
 border:2px solid #ef4444;display:none;
 align-items:center;justify-content:center;
 color:#fff;font-size:11px;font-weight:900;
 box-shadow:0 0 20px rgba(239,68,68,.9);
 animation:flow32CutMark 1.4s ease-in-out infinite;z-index:4}
.tech-v30 .flow32-cut-mark.is-on{display:flex}
@keyframes flow32CutMark{50%{transform:translate(-50%,-50%) scale(1.25);opacity:.75}}

.tech-v30 .flow32-packet{
 position:absolute;top:0;left:0;
 width:10px;height:10px;border-radius:50%;
 background:#fff;pointer-events:none;
 opacity:0;transition:opacity .3s;
 box-shadow:0 0 10px currentColor;
 offset-rotate:0deg;
 offset-distance:0%;
 will-change:offset-distance}
.tech-v30 .flow32-packet.is-on{opacity:1;
 animation:flow32Packet var(--dur,3.2s) linear infinite;
 animation-delay:var(--delay,0s)}
.tech-v30 .flow32-packet.pause{animation-play-state:paused}
@keyframes flow32Packet{
 0%{offset-distance:0%}
 100%{offset-distance:100%}}
.flow32-packet.p1{color:#22c55e;background:#22c55e}
.flow32-packet.p2{color:#3b82f6;background:#3b82f6}
.flow32-packet.p3{color:#f59e0b;background:#f59e0b}
.flow32-packet.p4{color:#8b5cf6;background:#8b5cf6}
.flow32-packet.p5{color:#14b8a6;background:#14b8a6}

.tech-v30 .flow32-node{
 position:absolute;left:var(--x);top:var(--y);transform:translate(-50%,-50%);
 display:flex;flex-direction:column;align-items:center;gap:5px;
 padding:10px 14px;min-width:140px;border-radius:14px;
 background:linear-gradient(180deg,#131f38,#0c1626);
 border:1px solid #243347;color:#cbd5e1;text-decoration:none;
 font-size:11px;font-weight:700;cursor:pointer;
 transition:.3s;text-align:center;
 box-shadow:0 14px 32px -18px rgba(0,0,0,.9);z-index:2}
.tech-v30 .flow32-node:hover{transform:translate(-50%,-50%) scale(1.05);
 border-color:#3b82f6;z-index:5}
.tech-v30 .flow32-node .ic{
 display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;
 border-radius:10px;background:rgba(148,163,184,.14);font-size:15px;color:#cbd5e1}
.tech-v30 .flow32-node b{font-size:12.5px;color:#f1f5f9;line-height:1.1}
.tech-v30 .flow32-node small{
 font-size:9.5px;color:#94a3b8;font-weight:600;
 min-height:11px;max-width:170px;
 overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tech-v30 .flow32-node .stDot{
 position:absolute;top:7px;left:7px;width:8px;height:8px;border-radius:50%;background:#475569}
.tech-v30 .flow32-node .count{
 position:absolute;top:5px;right:6px;min-width:18px;height:18px;padding:0 5px;
 border-radius:999px;background:#1e293b;color:#94a3b8;
 font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;
 border:1px solid #334155}
.tech-v30 .flow32-node.has-tasks .count{
 background:rgba(245,158,11,.18);color:#fbbf24;border-color:rgba(245,158,11,.35)}

.tech-v30 .flow32-node.s-working{
 border-color:rgba(34,197,94,.6);
 background:linear-gradient(180deg,rgba(34,197,94,.1),#0c1626);
 animation:nodeWork 2.4s ease-in-out infinite}
.tech-v30 .flow32-node.s-working .stDot{
 background:#22c55e;box-shadow:0 0 10px #22c55e;animation:pulseDot 1.6s infinite}
.tech-v30 .flow32-node.s-error{
 border-color:rgba(239,68,68,.7);
 background:linear-gradient(180deg,rgba(239,68,68,.12),#0c1626);
 animation:nodeErr 1.6s ease-in-out infinite}
.tech-v30 .flow32-node.s-error .stDot{
 background:#ef4444;box-shadow:0 0 10px #ef4444;animation:pulseDot 1.2s infinite}
.tech-v30 .flow32-node.s-waiting{
 border-color:rgba(245,158,11,.55)}
.tech-v30 .flow32-node.s-waiting .stDot{
 background:#f59e0b;box-shadow:0 0 10px #f59e0b}
.tech-v30 .flow32-node.s-idle .stDot{background:#64748b}
.tech-v30 .flow32-node.s-off{opacity:.55}
.tech-v30 .flow32-node.primary{
 background:linear-gradient(180deg,#1e40af,#1d4ed8);
 border-color:#3b82f6;
 box-shadow:0 0 0 3px rgba(59,130,246,.2),0 24px 60px -20px rgba(59,130,246,.7);
 min-width:160px;padding:12px 16px}
.tech-v30 .flow32-node.primary b{color:#fff;font-size:13.5px}
.tech-v30 .flow32-node.primary small{color:#bfdbfe}
.tech-v30 .flow32-node.primary .ic{background:rgba(255,255,255,.15);color:#fff}
@keyframes nodeWork{
 0%,100%{box-shadow:0 0 0 2px rgba(34,197,94,.15),0 14px 32px -18px rgba(34,197,94,.45)}
 50%{box-shadow:0 0 0 10px rgba(34,197,94,.22),0 14px 32px -18px rgba(34,197,94,.7)}}
@keyframes nodeErr{
 0%,100%{box-shadow:0 0 0 2px rgba(239,68,68,.15),0 14px 32px -18px rgba(239,68,68,.45)}
 50%{box-shadow:0 0 0 10px rgba(239,68,68,.24),0 14px 32px -18px rgba(239,68,68,.7)}}

.tech-v30 .flow32-task-bubble{
 position:absolute;left:var(--x);top:var(--y);
 transform:translate(-50%,-120%);
 min-width:170px;max-width:220px;
 padding:8px 10px;border-radius:10px;
 background:linear-gradient(180deg,#1e293b,#0f172a);
 border:1px solid rgba(34,197,94,.55);
 color:#e2e8f0;font-size:10.5px;
 box-shadow:0 18px 40px -18px rgba(34,197,94,.6);
 z-index:6;pointer-events:none;
 animation:taskFloat 3.6s ease-in-out infinite;
 display:flex;flex-direction:column;gap:4px}
.tech-v30 .flow32-task-bubble::after{
 content:"";position:absolute;bottom:-7px;left:50%;transform:translateX(-50%) rotate(45deg);
 width:12px;height:12px;background:#0f172a;border-right:1px solid rgba(34,197,94,.55);
 border-bottom:1px solid rgba(34,197,94,.55)}
.tech-v30 .flow32-task-bubble.is-error{
 border-color:rgba(239,68,68,.6);
 box-shadow:0 18px 40px -18px rgba(239,68,68,.7)}
.tech-v30 .flow32-task-bubble.is-error::after{
 border-color:rgba(239,68,68,.6)}
.tech-v30 .flow32-task-bubble b{
 font-size:11px;font-weight:800;color:#f8fafc;line-height:1.2;
 overflow:hidden;text-overflow:ellipsis;display:-webkit-box;
 -webkit-line-clamp:2;-webkit-box-orient:vertical}
.tech-v30 .flow32-task-bubble .tag{
 display:inline-flex;align-self:flex-start;padding:2px 6px;
 border-radius:6px;font-size:9px;font-weight:800;
 background:rgba(34,197,94,.2);color:#86efac;letter-spacing:.3px}
.tech-v30 .flow32-task-bubble.is-error .tag{
 background:rgba(239,68,68,.2);color:#fca5a5}
.tech-v30 .flow32-task-bubble .bar{
 height:3px;border-radius:3px;background:rgba(15,23,42,.9);overflow:hidden}
.tech-v30 .flow32-task-bubble .bar i{
 display:block;height:100%;width:40%;border-radius:3px;
 background:linear-gradient(90deg,#22c55e,#4ade80);
 animation:taskBar 1.8s ease-in-out infinite}
.tech-v30 .flow32-task-bubble.is-error .bar i{
 background:#ef4444;animation:none;width:100%}
@keyframes taskFloat{0%,100%{transform:translate(-50%,-120%)}50%{transform:translate(-50%,-130%)}}
@keyframes taskBar{0%{transform:translateX(-100%)}50%{transform:translateX(150%)}100%{transform:translateX(260%)}}

.tech-v30 .flow32-tip{
 position:absolute;bottom:14px;left:14px;
 padding:11px 15px;border-radius:12px;
 background:linear-gradient(180deg,#101a2e,#0b1424);
 border:1px solid #243347;color:#94a3b8;font-size:11px;
 max-width:340px;z-index:10;
 box-shadow:0 18px 40px -20px rgba(0,0,0,.9)}
.tech-v30 .flow32-tip b{display:block;color:#e2e8f0;font-size:11.5px;margin-bottom:3px}
.tech-v30 .flow32-tip small{color:#64748b;display:block;margin-top:3px;font-size:10px}

.tech-v30 .flow32-stuck{
 position:absolute;bottom:14px;right:14px;
 padding:10px 14px;border-radius:12px;
 background:linear-gradient(180deg,rgba(239,68,68,.12),#0b1424);
 border:1px solid rgba(239,68,68,.45);
 color:#fca5a5;font-size:11px;font-weight:800;z-index:10;
 display:flex;align-items:center;gap:8px}
.tech-v30 .flow32-stuck.hidden{display:none}
.tech-v30 .flow32-stuck .n{
 display:inline-flex;width:22px;height:22px;border-radius:6px;
 background:rgba(239,68,68,.25);align-items:center;justify-content:center;
 font-weight:900;font-size:12px;color:#fff}

/* ===== Agents cards ===== */
.tech-v30 .agents31-wrap{padding:16px}
.tech-v30 .agents31-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px}
@media (max-width:900px){.tech-v30 .agents31-grid{grid-template-columns:1fr}}
.tech-v30 .agent31{position:relative;border-radius:14px;background:linear-gradient(180deg,#101a2e,#0b1424);border:1px solid var(--tx-line2);padding:14px;transition:.25s;overflow:hidden}
.tech-v30 .agent31::before{content:"";position:absolute;inset:0;border-radius:14px;padding:1px;background:linear-gradient(135deg,var(--accent,transparent),transparent 40%);-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;pointer-events:none;opacity:.9}
.tech-v30 .agent31.is-error{--accent:#ef4444}
.tech-v30 .agent31.is-working{--accent:#22c55e}
.tech-v30 .agent31.is-waiting{--accent:#f59e0b}
.tech-v30 .agent31.is-idle{--accent:#475569}
.tech-v30 .agent31.is-off{--accent:#334155;opacity:.72}
.tech-v30 .agent31:hover{transform:translateY(-2px);border-color:#334155;box-shadow:0 20px 40px -20px rgba(59,130,246,.35)}
.tech-v30 .agent31-h{display:flex;align-items:center;gap:12px}
.tech-v30 .agent31-av{flex:0 0 auto;width:48px;height:48px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff;background:linear-gradient(135deg,var(--accent),color-mix(in oklab,var(--accent) 60%,#0b1220));box-shadow:0 8px 24px -8px var(--accent);position:relative}
.tech-v30 .agent31-av .st{position:absolute;bottom:-3px;left:-3px;width:14px;height:14px;border-radius:50%;border:2.5px solid #0b1424;background:#475569}
.tech-v30 .agent31.is-working .agent31-av .st{background:#22c55e;box-shadow:0 0 10px #22c55e}
.tech-v30 .agent31.is-error .agent31-av .st{background:#ef4444;box-shadow:0 0 10px #ef4444;animation:pulseDot 1.2s infinite}
.tech-v30 .agent31.is-waiting .agent31-av .st{background:#f59e0b;box-shadow:0 0 10px #f59e0b}
.tech-v30 .agent31-info{min-width:0;flex:1}
.tech-v30 .agent31-info b{display:block;color:#f1f5f9;font-size:14px;font-weight:800;line-height:1.2}
.tech-v30 .agent31-info small{display:block;color:#94a3b8;font-size:11px;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tech-v30 .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:999px;font-size:10.5px;font-weight:700;letter-spacing:.2px}
.tech-v30 .pill.s-working{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3)}
.tech-v30 .pill.s-error{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.tech-v30 .pill.s-waiting{background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3)}
.tech-v30 .pill.s-idle{background:rgba(71,85,105,.2);color:#94a3b8;border:1px solid rgba(71,85,105,.35)}
.tech-v30 .pill.s-off{background:rgba(51,65,85,.2);color:#64748b;border:1px solid rgba(51,65,85,.4)}
.tech-v30 .agent31-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:12px}
.tech-v30 .agent31-stats div{padding:7px 8px;border-radius:9px;background:rgba(15,23,42,.7);border:1px solid rgba(30,41,59,.7);text-align:center}
.tech-v30 .agent31-stats div b{display:block;color:#e2e8f0;font-size:13px;font-weight:800}
.tech-v30 .agent31-stats div small{display:block;color:#64748b;font-size:9.5px;margin-top:1px}
.tech-v30 .agent31-stats div.err b{color:#f87171}
.tech-v30 .agent31-stats div.ok b{color:#4ade80}
.tech-v30 .agent31-current{margin-top:11px;padding:10px 12px;border-radius:10px;background:linear-gradient(180deg,rgba(34,197,94,.08),rgba(15,23,42,.6));border:1px solid rgba(34,197,94,.28);position:relative;overflow:hidden}
.tech-v30 .agent31-current.is-idle{background:linear-gradient(180deg,rgba(71,85,105,.1),rgba(15,23,42,.6));border-color:rgba(71,85,105,.3)}
.tech-v30 .agent31-current.is-error{background:linear-gradient(180deg,rgba(239,68,68,.1),rgba(15,23,42,.6));border-color:rgba(239,68,68,.3)}
.tech-v30 .agent31-current::after{content:"";position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#22c55e,transparent);animation:runBar 2s linear infinite}
.tech-v30 .agent31-current.is-idle::after{background:linear-gradient(90deg,transparent,#475569,transparent);animation:none}
.tech-v30 .agent31-current.is-error::after{background:linear-gradient(90deg,transparent,#ef4444,transparent)}
@keyframes runBar{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}
.tech-v30 .agent31-current .hd{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:5px}
.tech-v30 .agent31-current .hd b{color:#e2e8f0;font-size:11.5px;font-weight:800}
.tech-v30 .agent31-current .hd .tag{font-size:10px;font-weight:800;padding:3px 8px;border-radius:999px;background:rgba(34,197,94,.18);color:#86efac;border:1px solid rgba(34,197,94,.35)}
.tech-v30 .agent31-current.is-idle .hd .tag{background:rgba(71,85,105,.2);color:#94a3b8;border-color:rgba(71,85,105,.35)}
.tech-v30 .agent31-current.is-error .hd .tag{background:rgba(239,68,68,.18);color:#fca5a5;border-color:rgba(239,68,68,.35)}
.tech-v30 .agent31-current p{margin:0;color:#94a3b8;font-size:11px;line-height:1.55}
.tech-v30 .agent31-current .progress{margin-top:7px;height:5px;border-radius:4px;background:rgba(15,23,42,.8);overflow:hidden;position:relative}
.tech-v30 .agent31-current .progress i{display:block;height:100%;width:35%;border-radius:4px;background:linear-gradient(90deg,#22c55e,#4ade80);animation:barSlide 1.8s ease-in-out infinite}
.tech-v30 .agent31-current.is-idle .progress i{background:#475569;animation:none;width:100%;opacity:.4}
.tech-v30 .agent31-current.is-error .progress i{background:#ef4444;animation:none;width:100%}
@keyframes barSlide{0%{transform:translateX(-100%)}50%{transform:translateX(160%)}100%{transform:translateX(260%)}}

.tech-v30 .agent31-err{margin-top:10px;padding:9px 11px;border-radius:10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#fca5a5;font-size:11px;display:flex;gap:8px;align-items:flex-start}
.tech-v30 .agent31-err b{color:#fecaca}
.tech-v30 .agent31-err code{background:rgba(0,0,0,.35);padding:1px 6px;border-radius:5px;font-size:10.5px;color:#fecaca;cursor:pointer}

.tech-v30 .agent31-tasks{margin-top:11px;display:flex;flex-direction:column;gap:6px;max-height:280px;overflow:auto;padding-right:2px}
.tech-v30 .agent31-tasks::-webkit-scrollbar{width:5px}
.tech-v30 .agent31-tasks::-webkit-scrollbar-thumb{background:#334155;border-radius:6px}
.tech-v30 .task31{position:relative;padding:9px 11px 9px 14px;border-radius:10px;background:linear-gradient(180deg,#0f172a,#0b1424);border:1px solid var(--tx-line2);display:flex;gap:9px;align-items:flex-start;text-decoration:none;transition:.2s}
.tech-v30 .task31::before{content:"";position:absolute;right:0;top:8px;bottom:8px;width:3px;border-radius:3px;background:var(--tc,#475569)}
.tech-v30 .task31.is-running{--tc:#22c55e}
.tech-v30 .task31.is-blocked{--tc:#ef4444}
.tech-v30 .task31.is-needs-fix{--tc:#f59e0b}
.tech-v30 .task31.is-waiting{--tc:#3b82f6}
.tech-v30 .task31.is-queued{--tc:#8b5cf6}
.tech-v30 .task31.is-retesting{--tc:#14b8a6}
.tech-v30 .task31.is-done{--tc:#22c55e}
.tech-v30 .task31.is-new{--tc:#64748b}
.tech-v30 .task31:hover{border-color:#3b82f6;background:linear-gradient(180deg,#101a2e,#0d172a);transform:translateX(-2px)}
.tech-v30 .task31 .ic{flex:0 0 auto;width:22px;height:22px;border-radius:6px;background:color-mix(in oklab,var(--tc) 20%,transparent);color:var(--tc);font-size:11px;display:inline-flex;align-items:center;justify-content:center;font-weight:700}
.tech-v30 .task31 .body{min-width:0;flex:1}
.tech-v30 .task31 .body b{display:block;color:#e2e8f0;font-size:11.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tech-v30 .task31 .body small{display:block;color:#64748b;font-size:10px;margin-top:2px}
.tech-v30 .task31 .st{flex:0 0 auto;align-self:center;font-size:9.5px;font-weight:700;padding:3px 7px;border-radius:999px;color:var(--tc);background:color-mix(in oklab,var(--tc) 15%,transparent);border:1px solid color-mix(in oklab,var(--tc) 35%,transparent)}
.tech-v30 .no-tasks31{padding:10px 12px;border-radius:10px;background:rgba(15,23,42,.5);border:1px dashed var(--tx-line2);color:#64748b;font-size:11px;text-align:center}

.tech-v30 .chat31{padding:12px;display:flex;flex-direction:column;gap:8px;max-height:520px;overflow:auto}
.tech-v30 .chat31::-webkit-scrollbar{width:6px}.tech-v30 .chat31::-webkit-scrollbar-thumb{background:#334155;border-radius:6px}
.tech-v30 .chat31-row{display:flex;gap:9px;padding:9px 10px;border-radius:10px;background:linear-gradient(180deg,#101a2e,#0c1424);border:1px solid var(--tx-line2)}
.tech-v30 .chat31-row .av{flex:0 0 auto;width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;color:#fff;background:linear-gradient(135deg,#3b82f6,#1d4ed8);font-size:13px}
.tech-v30 .chat31-row .body{min-width:0;flex:1}
.tech-v30 .chat31-row .head{display:flex;justify-content:space-between;align-items:center}
.tech-v30 .chat31-row .head b{color:#e2e8f0;font-size:11.5px;font-weight:800}
.tech-v30 .chat31-row .head time{color:#64748b;font-size:10px}
.tech-v30 .chat31-row p{margin:3px 0 0;color:#94a3b8;font-size:11px;line-height:1.6}

.tech-v30 .err31-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-bottom:1px solid var(--tx-line);background:linear-gradient(180deg,#101a2e,#0c1524);flex-wrap:wrap}
.tech-v30 .err31-head h3{margin:0;font-size:13px;color:#f1f5f9}
.tech-v30 .err31-head .actions{display:flex;gap:6px;flex-wrap:wrap}
.tech-v30 .err31-head button{padding:6px 11px;border-radius:8px;font-size:11px;font-weight:700;border:1px solid var(--tx-line2);background:rgba(15,23,42,.6);color:#cbd5e1;cursor:pointer;transition:.2s}
.tech-v30 .err31-head button:hover{border-color:#3b82f6;color:#93c5fd}
.tech-v30 .err31-head button.primary{background:linear-gradient(180deg,#2563eb,#1d4ed8);border-color:#1d4ed8;color:#fff}
.tech-v30 .err31-head button.done{background:rgba(34,197,94,.2);border-color:rgba(34,197,94,.5);color:#86efac}
.tech-v30 .err31-list{padding:12px;display:flex;flex-direction:column;gap:8px;max-height:560px;overflow:auto}
.tech-v30 .err31-item{position:relative;padding:11px 12px;border-radius:11px;background:linear-gradient(180deg,rgba(239,68,68,.06),#0b1424);border:1px solid rgba(239,68,68,.3)}
.tech-v30 .err31-item .row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tech-v30 .err31-item .badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:800;background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.35)}
.tech-v30 .err31-item .badge.info{background:rgba(59,130,246,.16);color:#93c5fd;border-color:rgba(59,130,246,.35)}
.tech-v30 .err31-item h4{margin:6px 0 3px;color:#f1f5f9;font-size:12.5px;font-weight:800}
.tech-v30 .err31-item .msg{margin:0 0 6px;color:#cbd5e1;font-size:11.5px;line-height:1.65}
.tech-v30 .err31-item .tech{margin:0 0 6px;padding:8px 10px;border-radius:8px;background:rgba(0,0,0,.35);color:#94a3b8;font-size:10.5px;font-family:ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;max-height:160px;overflow:auto}
.tech-v30 .err31-item .fix{padding:8px 10px;border-radius:8px;background:rgba(34,197,94,.08);border:1px dashed rgba(34,197,94,.35);color:#86efac;font-size:11px;line-height:1.65}
.tech-v30 .err31-item .fix b{color:#4ade80}
.tech-v30 .err31-item .footer{display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap}
.tech-v30 .err31-item .footer time{color:#64748b;font-size:10px}
.tech-v30 .err31-item .footer .copy{margin-inline-start:auto;padding:5px 10px;font-size:10.5px;border-radius:8px;background:rgba(59,130,246,.14);color:#93c5fd;border:1px solid rgba(59,130,246,.3);cursor:pointer;font-weight:700;transition:.2s}
.tech-v30 .err31-item .footer .copy:hover{background:rgba(59,130,246,.24)}
.tech-v30 .err31-item .footer .copy.done{background:rgba(34,197,94,.2);color:#86efac;border-color:rgba(34,197,94,.4)}

.tech-v30 .brain31{padding:12px;display:flex;flex-direction:column;gap:8px}
.tech-v30 .brain31-row{display:flex;gap:10px;align-items:center;padding:9px 11px;border-radius:10px;background:linear-gradient(180deg,#101a2e,#0b1424);border:1px solid var(--tx-line2)}
.tech-v30 .brain31-row .bul{width:26px;height:26px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:12px}
.tech-v30 .brain31-row .bul.on{background:rgba(34,197,94,.15);color:#4ade80}
.tech-v30 .brain31-row .bul.off{background:rgba(71,85,105,.2);color:#94a3b8}
.tech-v30 .brain31-row b{color:#e2e8f0;font-size:11.5px;font-weight:800}
.tech-v30 .brain31-row p{margin:2px 0 0;color:#94a3b8;font-size:10.5px;line-height:1.55}
.tech-v30 .brain31-row .sw{margin-inline-start:auto;width:28px;height:16px;border-radius:999px;background:#334155;position:relative}
.tech-v30 .brain31-row .sw.on{background:#22c55e}
.tech-v30 .brain31-row .sw::after{content:"";position:absolute;top:2px;left:2px;width:12px;height:12px;border-radius:50%;background:#fff;transition:.2s}
.tech-v30 .brain31-row .sw.on::after{left:14px}
.tech-v30 .log31{padding:12px;display:flex;flex-direction:column;gap:6px;max-height:400px;overflow:auto}
.tech-v30 .log31-row{display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border-radius:9px;background:rgba(15,23,42,.5);border:1px solid rgba(30,41,59,.7)}
.tech-v30 .log31-row i{flex:0 0 auto;width:8px;height:8px;border-radius:50%;margin-top:5px}
.tech-v30 .log31-row i.s-working{background:#22c55e;box-shadow:0 0 8px #22c55e}
.tech-v30 .log31-row i.s-error{background:#ef4444;box-shadow:0 0 8px #ef4444}
.tech-v30 .log31-row i.s-waiting{background:#f59e0b}
.tech-v30 .log31-row i.s-idle{background:#475569}
.tech-v30 .log31-row time{color:#64748b;font-size:10px;min-width:80px}
.tech-v30 .log31-row p{margin:0;color:#cbd5e1;font-size:11px;line-height:1.5}
.tech-v30 .log31-row p b{color:#f1f5f9}
.tech-v30 .empty{padding:18px;text-align:center;color:#64748b;font-size:11.5px}
.tech-v30 .bottom31{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
@media (max-width:1000px){.tech-v30 .bottom31{grid-template-columns:1fr}}
.tech-v30 .mid31{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;margin-top:16px}
@media (max-width:1200px){.tech-v30 .mid31{grid-template-columns:1fr}}
.tech-v30 .disabled-banner{margin:14px 0;padding:12px 15px;border-radius:12px;background:linear-gradient(180deg,rgba(245,158,11,.08),rgba(15,23,42,.6));border:1px solid rgba(245,158,11,.35);color:#fbbf24;font-size:12px}
.tech-v30 .disabled-banner b{color:#fde68a;font-size:13px;display:block;margin-bottom:4px}
.tech-v30 .disabled-banner ul{margin:6px 0 0;padding-inline-start:18px;line-height:1.8}
.tech-v30 .disabled-banner li{color:#fde68a}
.tech-v30 .disabled-banner li small{color:#94a3b8;display:block;font-size:10.5px}
</style>

<div class="tech-v30" data-tech-live-root data-tech-csrf="<?=e(Auth::csrf())?>">

 <section class="tech30-hero">
  <div style="position:relative;z-index:1">
   <span class="tech30-kicker"><i></i> مراقبة حية لسير العمل · v32</span>
   <h2>المسار التقني التفاعلي</h2>
   <p>مخطط سير عمل حي يعرض كل الوكلاء وحالاتهم من قاعدة البيانات. الخطوط تنبض على المسار النشط، وتتحوّل لقطعٍ أحمر وميض على المسار المتوقف، وكل مهمة نشطة تظهر كفقاعة عائمة فوق وكيلها مباشرة.</p>
  </div>
  <div class="tech30-actions">
   <button type="button" class="tech30-btn" data-flow-pause><span>⏸</span><b>إيقاف الحركة</b></button>
   <button type="button" class="tech30-btn" data-tech-refresh><span>↻</span><b>تحديث الآن</b></button>
   <a class="tech30-btn primary" href="queue.php"><span>⚡</span><b>الطابور الحي</b></a>
   <div class="tech30-live"><span class="dot"></span><div><b>النظام يعمل الآن</b><small data-tech-updated>تحديث حي كل ثانيتين</small></div></div>
  </div>
 </section>

 <section class="tech30-kpis">
  <article class="tech30-kpi green"><span class="ic">ϟ</span><small>سرعة رد رامي</small><b data-kpi="ramy_speed"><?=e($ramyMs!==null?number_format($ramyMs/1000,1).' ث':'لا بيانات')?></b><em>متوسط آخر ٢٤ ساعة</em></article>
  <article class="tech30-kpi blue"><span class="ic">◉</span><small>سرعة الوكلاء</small><b data-kpi="agents_speed"><?=e($allMs!==null?number_format($allMs/1000,1).' ث':'لا بيانات')?></b><em>من التنفيذات المكتملة</em></article>
  <article class="tech30-kpi amber"><span class="ic">▣</span><small>المهام النشطة</small><b data-kpi="active_tasks"><?=e((string)$activeTasks)?></b><em data-kpi-sub="queue"><?=e((string)$qCount['running'])?> تُنفّذ الآن</em></article>
  <article class="tech30-kpi red"><span class="ic">!</span><small>أخطاء ٢٤ ساعة</small><b data-kpi="critical_errors"><?=e((string)$qCount['failed'])?></b><em>تحتاج متابعة</em></article>
  <article class="tech30-kpi teal"><span class="ic">✓</span><small>نجاح الرسائل</small><b data-kpi="message_success"><?=e($messageRate===null?'—':$messageRate.'٪')?></b><em><?=e($outTotal?'من '.$outTotal.' رسالة':'لا رسائل حديثة')?></em></article>
  <article class="tech30-kpi violet"><span class="ic">♥</span><small>الحالة العامة</small><b data-kpi="overall"><?=e($healthGood?'يعمل بكفاءة':'يحتاج متابعة')?></b><em data-kpi-sub="worker"><?=e($workerHealthy?'العامل متصل':'العامل متأخر')?></em></article>
 </section>

 <?php if($disabledAgents):?>
 <div class="disabled-banner">
  <b>⚠ يوجد <?=count($disabledAgents)?> وكيل/مزود معطل حاليًا</b>
  <ul>
  <?php foreach($disabledAgents as $da):$isAgent=isset($da['slug']);
   $name=$isAgent?(string)$da['display_name']:(string)($da['provider_key']??$da['label']??'—');
   $reason=(string)($da['last_error_code']??'');
  ?>
   <li>
    <strong><?=e($name)?></strong> — الحالة: <em><?=e($isAgent?(string)$da['status']:'مزود معطل')?></em>
    <?php if($reason):?><small>السبب المسجل: <?=e($reason)?></small><?php else:?><small>معطل يدويًا أو بانتظار تهيئة إعدادات/مفاتيح/موافقة.</small><?php endif?>
   </li>
  <?php endforeach?>
  </ul>
 </div>
 <?php endif?>

 <!-- ===== Flow v32 ===== -->
 <section class="tech30-panel">
  <div class="tech30-panel-h">
   <div class="title">
    <span class="ic">⌘</span>
    <div>
     <h3>مخطط سير العمل التقني — Live v32</h3>
     <p>حركة الرسائل والمهام لحظة بلحظة. الخطوط تنبض على المسار النشط، وتتقطع أحمر على المسار المتوقف.</p>
    </div>
   </div>
   <div style="display:flex;gap:6px">
    <button type="button" class="tech30-btn" data-flow-fit>ملاءمة العرض</button>
    <button type="button" class="tech30-btn" data-flow-refresh>تحديث الآن</button>
   </div>
  </div>

  <div class="flow32-wrap">
   <div class="flow32-scroll" data-flow-scroll>
    <div class="flow32-board" data-flow-board>

     <svg class="flow32-svg" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid meet">
      <defs>
       <filter id="techGlow32">
        <feGaussianBlur stdDeviation="3" result="b"/>
        <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
       </filter>
      </defs>

      <path id="e_wc" data-flow-edge="e_wc" data-edge-agent="capture"
            class="flow32-line line-green"
            d="M 185 200 L 235 200"/>
      <path id="e_dc" data-flow-edge="e_dc" data-edge-agent="context"
            class="flow32-line line-blue"
            d="M 185 700 L 235 700"/>
      <path id="e_cr" data-flow-edge="e_cr" data-edge-agent="ramy"
            class="flow32-line line-green"
            d="M 385 200 C 425 200, 425 450, 465 450"/>
      <path id="e_xr" data-flow-edge="e_xr" data-edge-agent="ramy"
            class="flow32-line line-blue"
            d="M 385 700 C 425 700, 425 450, 465 450"/>

      <path id="e_r_walid" data-flow-edge="e_r_walid" data-edge-agent="walid"
            class="flow32-line line-violet"
            d="M 615 450 C 680 450, 680 100, 745 100"/>
      <path id="e_r_ayman" data-flow-edge="e_r_ayman" data-edge-agent="ayman"
            class="flow32-line line-amber"
            d="M 615 450 C 700 450, 780 100, 955 100"/>
      <path id="e_r_emad" data-flow-edge="e_r_emad" data-edge-agent="emad"
            class="flow32-line line-blue"
            d="M 615 450 C 680 450, 680 300, 745 300"/>
      <path id="e_r_samir" data-flow-edge="e_r_samir" data-edge-agent="samir-social"
            class="flow32-line line-teal"
            d="M 615 450 C 700 450, 780 300, 955 300"/>
      <path id="e_r_video" data-flow-edge="e_r_video" data-edge-agent="video-director"
            class="flow32-line line-pink"
            d="M 615 450 C 680 450, 680 500, 745 500"/>
      <path id="e_r_comm" data-flow-edge="e_r_comm" data-edge-agent="community-manager"
            class="flow32-line line-green"
            d="M 615 450 C 700 450, 780 500, 955 500"/>
      <path id="e_r_basant" data-flow-edge="e_r_basant" data-edge-agent="basant"
            class="flow32-line line-violet"
            d="M 615 450 C 680 450, 680 700, 745 700"/>
      <path id="e_r_scout" data-flow-edge="e_r_scout" data-edge-agent="free-model-scout"
            class="flow32-line line-amber"
            d="M 615 450 C 700 450, 780 700, 955 700"/>

      <path id="e_ah" data-flow-edge="e_ah" data-edge-agent="hostinger"
            class="flow32-line line-amber"
            d="M 1105 100 C 1140 100, 1140 165, 1195 165"/>
      <path id="e_ee" data-flow-edge="e_ee" data-edge-agent="evidence"
            class="flow32-line line-green"
            d="M 895 300 C 1020 300, 1030 465, 1195 465"/>
      <path id="e_fm" data-flow-edge="e_fm" data-edge-agent="memory"
            class="flow32-line line-violet"
            d="M 1105 700 C 900 700, 780 795, 615 795"/>
      <path id="e_bd" data-flow-edge="e_bd" data-edge-agent="delivery"
            class="flow32-line line-pink"
            d="M 895 700 C 1100 700, 1250 530, 1395 485"/>
      <path id="e_hd" data-flow-edge="e_hd" data-edge-agent="delivery"
            class="flow32-line line-amber"
            d="M 1345 200 C 1370 200, 1370 350, 1395 415"/>
      <path id="e_ed" data-flow-edge="e_ed" data-edge-agent="delivery"
            class="flow32-line line-green"
            d="M 1345 500 C 1370 500, 1370 490, 1395 485"/>
      <path id="e_md" data-flow-edge="e_md" data-edge-agent="delivery"
            class="flow32-line line-violet"
            d="M 615 830 C 900 830, 1200 600, 1395 485"/>
     </svg>

     <?php
      $edgePids=['e_wc'=>1,'e_dc'=>2,'e_cr'=>1,'e_xr'=>2,
                 'e_r_walid'=>4,'e_r_ayman'=>3,'e_r_emad'=>2,'e_r_samir'=>5,
                 'e_r_video'=>4,'e_r_comm'=>1,'e_r_basant'=>4,'e_r_scout'=>3,
                 'e_ah'=>3,'e_ee'=>1,'e_fm'=>4,'e_bd'=>5,'e_hd'=>3,'e_ed'=>1,'e_md'=>4];
      $edgePathMap=[
        'e_wc'=>'M 185 200 L 235 200',
        'e_dc'=>'M 185 700 L 235 700',
        'e_cr'=>'M 385 200 C 425 200, 425 450, 465 450',
        'e_xr'=>'M 385 700 C 425 700, 425 450, 465 450',
        'e_r_walid'=>'M 615 450 C 680 450, 680 100, 745 100',
        'e_r_ayman'=>'M 615 450 C 700 450, 780 100, 955 100',
        'e_r_emad'=>'M 615 450 C 680 450, 680 300, 745 300',
        'e_r_samir'=>'M 615 450 C 700 450, 780 300, 955 300',
        'e_r_video'=>'M 615 450 C 680 450, 680 500, 745 500',
        'e_r_comm'=>'M 615 450 C 700 450, 780 500, 955 500',
        'e_r_basant'=>'M 615 450 C 680 450, 680 700, 745 700',
        'e_r_scout'=>'M 615 450 C 700 450, 780 700, 955 700',
        'e_ah'=>'M 1105 100 C 1140 100, 1140 165, 1195 165',
        'e_ee'=>'M 895 300 C 1020 300, 1030 465, 1195 465',
        'e_fm'=>'M 1105 700 C 900 700, 780 795, 615 795',
        'e_bd'=>'M 895 700 C 1100 700, 1250 530, 1395 485',
        'e_hd'=>'M 1345 200 C 1370 200, 1370 350, 1395 415',
        'e_ed'=>'M 1345 500 C 1370 500, 1370 490, 1395 485',
        'e_md'=>'M 615 830 C 900 830, 1200 600, 1395 485',
      ];
      foreach($edgePathMap as $eid=>$path):
        $pid=$edgePids[$eid]??1;
      ?>
       <div class="flow32-packet p<?=$pid?>"
            data-packet="<?=$eid?>"
            style="offset-path:path('<?=$path?>');--dur:3.4s;--delay:<?=rand(0,20)/10?>s"
            aria-hidden="true"></div>
     <?php endforeach?>

     <div class="flow32-cut-mark" data-cut-for="e_r_ayman" style="left:780px;top:270px">✕</div>
     <div class="flow32-cut-mark" data-cut-for="e_r_emad"  style="left:680px;top:375px">✕</div>
     <div class="flow32-cut-mark" data-cut-for="e_ee"     style="left:1020px;top:385px">✕</div>

     <a href="integrations.php"
        class="flow32-node <?=e($providerState($whatsapp))?>"
        style="--x:6.875%;--y:22.22%"
        data-tech-node="whatsapp" data-node-title="واتساب"
        data-node-desc="استقبال الرسائل من العملاء ونقلها إلى النظام" data-edge-agent="whatsapp">
      <i class="stDot"></i><span class="ic">☎</span><b>واتساب</b>
      <small data-node-status="whatsapp"><?=e($whatsapp?AdminUi::label((string)$whatsapp['status']):'غير مهيأ')?></small>
     </a>
     <a href="team-chat.php" class="flow32-node s-working"
        style="--x:6.875%;--y:77.78%"
        data-tech-node="dashboard" data-node-title="دردشة الفريق"
        data-node-desc="أوامر المالك وتواصل رامي والوكلاء" data-edge-agent="dashboard">
      <i class="stDot"></i><span class="ic">✉</span><b>دردشة الفريق</b>
      <small><?=e((string)$onlineCount)?> متصل</small>
     </a>

     <button type="button"
             class="flow32-node <?=e(($qCount['queued']+$qCount['running'])>0?'s-working':'s-idle')?>"
             style="--x:19.375%;--y:22.22%"
             data-tech-node="capture" data-node-title="التقاط الرسالة"
             data-node-desc="تسجيل الرسالة وربطها بالمحادثة قبل التحليل" data-edge-agent="capture">
      <i class="stDot"></i><span class="ic">◌</span><b>التقاط الرسالة</b>
      <small data-node-status="capture"><?=e(($qCount['queued']+$qCount['running'])>0?'نشط':'في الانتظار')?></small>
     </button>
     <button type="button"
             class="flow32-node <?=e($workerHealthy?'s-working':'s-error')?>"
             style="--x:19.375%;--y:77.78%"
             data-tech-node="context" data-node-title="فهم السياق"
             data-node-desc="تحديد العميل والمشروع والمهمة والسياق السابق" data-edge-agent="context">
      <i class="stDot"></i><span class="ic">⌕</span><b>فهم السياق</b>
      <small data-node-status="context"><?=e($workerHealthy?'يعمل':'متابعة')?></small>
     </button>

     <a href="agent-profile.php?slug=ramy"
        class="flow32-node primary <?=e($agentState('ramy'))?>"
        style="--x:33.75%;--y:50%"
        data-tech-node="ramy" data-node-title="رامي المدير"
        data-node-desc="يفهم الطلب ويقرر الخطوة ويوزع العمل على الفريق">
      <i class="stDot"></i>
      <?php $ramyActiveCount=$agentTaskCounts['ramy']??0;if($ramyActiveCount>0):?>
       <span class="count"><?=e((string)$ramyActiveCount)?></span>
      <?php endif?>
      <span class="ic">✦</span><b>رامي المدير</b>
      <small data-node-status="ramy"><?=e($agentTask('ramy'))?></small>
     </a>

     <?php foreach($workerNodes as $slug=>$np):
      $nodeState=$agentState($slug);
      $tcount=$agentTaskCounts[$slug]??0;
     ?>
     <a href="agent-profile.php?slug=<?=rawurlencode($slug)?>"
        class="flow32-node <?=e($nodeState)?><?=e($tcount>0?' has-tasks':'')?>"
        style="--x:<?=$np['x']?>%;--y:<?=$np['y']?>%"
        data-tech-node="<?=e($slug)?>"
        data-node-title="<?=e($np['label'])?>"
        data-node-desc="منفذ أو مراجع داخل دورة العمل"
        data-edge-agent="<?=e($slug)?>">
      <i class="stDot"></i>
      <?php if($tcount>0):?><span class="count"><?=e((string)$tcount)?></span><?php endif?>
      <span class="ic"><?=e($np['icon'])?></span><b><?=e($np['label'])?></b>
      <small data-node-status="<?=e($slug)?>"><?=e($agentTask($slug))?></small>
     </a>
     <?php endforeach?>

     <a href="memory.php" class="flow32-node s-working"
        style="--x:33.75%;--y:92.22%"
        data-tech-node="memory" data-node-title="الذاكرة"
        data-node-desc="سياق العميل والمشروع والقرارات السابقة" data-edge-agent="memory">
      <i class="stDot"></i><span class="ic">▤</span><b>الذاكرة</b>
      <small>متاحة للفريق</small>
     </a>
     <a href="hosting.php" class="flow32-node <?=e($providerState($hostinger))?>"
        style="--x:79.375%;--y:22.22%"
        data-tech-node="hostinger" data-node-title="هوستنجر"
        data-node-desc="الوصول للاستضافة والملفات وقاعدة البيانات" data-edge-agent="hostinger">
      <i class="stDot"></i><span class="ic">▥</span><b>هوستنجر</b>
      <small data-node-status="hostinger"><?=e($hostinger?AdminUi::label((string)$hostinger['status']):'غير مهيأ')?></small>
     </a>
     <a href="audit.php" class="flow32-node s-working"
        style="--x:79.375%;--y:55.56%"
        data-tech-node="evidence" data-node-title="الأدلة والسجل"
        data-node-desc="حفظ الأدلة وسجل كل خطوة ونتيجة" data-edge-agent="evidence">
      <i class="stDot"></i><span class="ic">▧</span><b>الأدلة والسجل</b>
      <small>تسجيل مستمر</small>
     </a>
     <a href="projects.php"
        class="flow32-node <?=e($qCount['failed']?'s-waiting':'s-working')?>"
        style="--x:91.875%;--y:50%"
        data-tech-node="delivery" data-node-title="النتيجة والمتابعة"
        data-node-desc="إعادة النتيجة إلى رامي ثم العميل أو خطوة المتابعة التالية"
        data-edge-agent="delivery">
      <i class="stDot"></i><span class="ic">➜</span><b>النتيجة والمتابعة</b>
      <small><?=e($qCount['failed']?'متابعة مطلوبة':'جاهز')?></small>
     </a>

     <div data-flow-tasks></div>

     <div class="flow32-tip" data-flow-focus>
      <b>اضغط على أي عقدة</b>
      <span>ستظهر هنا وظيفتها وحالتها الحالية.</span>
      <small>المخطط مبني على بيانات حية من قاعدة البيانات — تحديث كل ثانيتين.</small>
     </div>
     <div class="flow32-stuck hidden" data-flow-stuck>
      <span class="n" data-flow-stuck-n>0</span>
      <span>مهمة متوقفة تحتاج إصلاح</span>
     </div>
    </div>
   </div>
  </div>
 </section>

 <div class="mid31">
  <section class="tech30-panel">
   <div class="tech30-panel-h">
    <div class="title"><span class="ic">◎</span><div><h3>الوكلاء والمهام المتصلة</h3><p>مرتبون حسب الأخطاء ثم الأحمال النشطة. كل مهمة نشطة تظهر ككرت أسفل وكيلها.</p></div></div>
    <a href="agents.php">كل الوكلاء ←</a>
   </div>
   <div class="agents31-wrap">
    <div class="agents31-grid">
     <?php foreach($orderedAgents as $a):
      $slug=(string)$a['slug'];
      $st=(string)($a['status']??'idle');
      $stCls=$stateClass($st,true);
      $agentClass=$agentCls($slug,$st);
      $tasks=$agentTasks[$slug]??[];
      $errCount=$agentErrorCounts[$slug]??0;
      $doneCount=$agentDoneToday[$slug]??0;
      $activeCount=$agentTaskCounts[$slug]??0;
      $lastErr=$agentLastError[$slug]??null;
      $lastAt=$agentLast24[$slug]??(string)($a['last_seen_at']??'');
      $runningTask=null;$pendingTask=null;
      foreach($tasks as $t){if(in_array((string)$t['status'],['working','running'],true)){$runningTask=$t;break;}}
      if(!$runningTask && $tasks)$pendingTask=$tasks[0];
      $recentDone=$agentRecentDone[$slug]??null;
     ?>
     <article class="agent31 <?=e($agentClass)?>" data-agent-lane="<?=e($slug)?>">
      <div class="agent31-h">
       <span class="agent31-av" style="--accent:<?=e($agentClass==='is-error'?'#ef4444':($agentClass==='is-working'?'#22c55e':($agentClass==='is-waiting'?'#f59e0b':'#475569')))?>"><?=e(pb_substr((string)$a['display_name'],0,1))?><i class="st"></i></span>
       <div class="agent31-info">
        <b><?=e((string)$a['display_name'])?></b>
        <small><?=e(pb_substr((string)($a['role_title']??''),0,80))?></small>
       </div>
       <span class="pill <?=e($stCls)?>"><?=e(AdminUi::label($st))?></span>
      </div>

      <div class="agent31-stats">
       <div><b><?=e((string)$activeCount)?></b><small>مهام نشطة</small></div>
       <div class="ok"><b><?=e((string)$doneCount)?></b><small>مكتملة ٢٤س</small></div>
       <div class="err"><b><?=e((string)$errCount)?></b><small>أخطاء ٢٤س</small></div>
       <div><b><?=e($lastAt?AdminUi::date($lastAt):'—')?></b><small>آخر نشاط</small></div>
      </div>

      <?php if($runningTask):?>
      <div class="agent31-current">
       <div class="hd"><b>⚡ قيد التنفيذ الآن</b><span class="tag"><?=e($taskStatusLabel((string)$runningTask['status']))?></span></div>
       <p><?=e(pb_substr((string)$runningTask['title'],0,140))?></p>
       <div class="progress"><i></i></div>
      </div>
      <?php elseif($recentDone):?>
      <div class="agent31-current is-idle">
       <div class="hd"><b>✓ آخر مهمة مكتملة</b><span class="tag"><?=e($taskStatusLabel((string)$recentDone['status']))?></span></div>
       <p><?=e(pb_substr((string)$recentDone['title'],0,140))?> — <?=e(AdminUi::date($recentDone['updated_at']))?></p>
      </div>
      <?php elseif($pendingTask):?>
      <div class="agent31-current is-idle">
       <div class="hd"><b>◷ في الطابور</b><span class="tag"><?=e($taskStatusLabel((string)$pendingTask['status']))?></span></div>
       <p><?=e(pb_substr((string)$pendingTask['title'],0,140))?></p>
      </div>
      <?php else:?>
      <div class="agent31-current is-idle">
       <div class="hd"><b>— لا توجد مهام نشطة</b><span class="tag">خامل</span></div>
       <p>الوكيل جاهز ولا يوجد عمل قيد التنفيذ.</p>
      </div>
      <?php endif?>

      <?php if($lastErr):?>
      <div class="agent31-err">
       <span>⚠</span>
       <div><b>آخر خطأ:</b> <code data-copy-snippet="<?=e((string)($lastErr['error_code']?:'unknown'))?>"><?=e((string)($lastErr['error_code']?:'unknown'))?></code> — <span style="text-decoration:underline dotted;cursor:pointer" data-copy-snippet-btn="<?=e((string)($lastErr['error_code']?:''))?>">نسخ</span></div>
      </div>
      <?php endif?>

      <div class="agent31-tasks">
       <?php if(!$tasks):?>
        <div class="no-tasks31">لا توجد مهام نشطة الآن</div>
       <?php else: foreach(array_slice($tasks,0,6) as $t):$tCls=$taskStatusClass((string)$t['status']);?>
        <a class="task31 <?=e($tCls)?>" href="tasks.php?id=<?=e((string)$t['id'])?>">
         <span class="ic"><?=e($tCls==='is-running'?'▶':($tCls==='is-blocked'?'✕':($tCls==='is-needs-fix'?'⚒':($tCls==='is-done'?'✓':'•'))))?></span>
         <div class="body">
          <b><?=e(pb_substr((string)$t['title'],0,70))?></b>
          <small><?=e(AdminUi::date($t['updated_at']?:$t['created_at']))?></small>
         </div>
         <span class="st"><?=e($taskStatusLabel((string)$t['status']))?></span>
        </a>
       <?php endforeach; if(count($tasks)>6):?>
        <a class="task31 is-new" href="tasks.php?agent=<?=e($slug)?>"><span class="ic">+</span><div class="body"><b>+<?=e((string)(count($tasks)-6))?> مهمة أخرى</b><small>عرض كل المهام</small></div></a>
       <?php endif; endif;?>
      </div>
     </article>
     <?php endforeach?>
    </div>
   </div>
  </section>

  <aside class="tech30-panel">
   <div class="tech30-panel-h">
    <div class="title"><span class="ic">✉</span><div><h3>دردشة الفريق</h3><p>تعاون رامي والوكلاء الآن</p></div></div>
    <a href="team-chat.php">فتح ←</a>
   </div>
   <div class="chat31" data-tech-team-chat>
    <?php if(!$team):?><div class="empty">لا توجد رسائل فريق حديثة.</div><?php endif?>
    <?php foreach($team as $m):$name=$senderName($m);?>
    <article class="chat31-row" data-sender="<?=e((string)$m['sender_ref'])?>">
     <span class="av"><?=e(pb_substr($name,0,1))?></span>
     <div class="body">
      <div class="head"><b><?=e($name)?></b><time><?=e(AdminUi::date($m['created_at']??null))?></time></div>
      <p><?=e(pb_substr((string)$m['body_text'],0,150))?></p>
     </div>
    </article>
    <?php endforeach?>
   </div>
  </aside>
 </div>

 <section class="tech30-panel" style="margin-top:16px" data-tech-error-console>
  <div class="err31-head">
   <h3>⚒ مركز تشخيص الأخطاء — انسخ النص وأرسله لأي وكيل للإصلاح</h3>
   <div class="actions">
    <button type="button" data-err-copy-all>نسخ كل الأخطاء</button>
    <button type="button" class="primary" data-err-copy-repair>نسخ تقرير الإصلاح الموحد</button>
   </div>
  </div>
  <div class="err31-list" data-tech-errors-list>
   <?php
    $allErrs=[];
    foreach([['row'=>$whatsapp,'name'=>'واتساب'],['row'=>$hostinger,'name'=>'هوستنجر']] as $dp){
     $p=$dp['row'];if(!$p)continue;
     $raw=(string)($p['last_error']??'');if($raw==='')continue;
     $fix='';
     if(str_contains($raw,'401'))$fix='افتح صفحة الربط، أعد حفظ المفتاح، واختبر الاتصال. قد يكون المفتاح منتهي أو لم يُوسَّع نطاقه.';
     elseif(str_contains($raw,'403'))$fix='راجع نطاق صلاحيات المفتاح مع المزود، وتأكد من عدم حجب IP الاستضافة.';
     elseif(str_contains($raw,'429'))$fix='الحصة اليومية أو الحد الأقصى للمعدل انتهى. راجع Fallback متاح أو انتظر تجديد الحصة.';
     else $fix='افتح صفحة الفحص الكامل لمراجعة السياق وطريقة الإصلاح المقترحة.';
     $allErrs[]=['title'=>$dp['name'].' — خطأ ربط','code'=>(string)($p['provider_key']??''),'raw'=>$raw,'human'=>$raw,'fix'=>$fix,'at'=>$p['last_checked_at']??null];
    }
    foreach($errors as $er){
     $raw=(string)($er['error_message']??'');$code=(string)($er['error_code']??'');
     $human=AdminUi::humanError($raw);
     $fix='';
     if(str_contains($raw,'401'))$fix='راجع مفاتيح الوصول والـTokens.';
     elseif(str_contains($raw,'404')||str_contains($raw,'not found'))$fix='راجع المسار أو اسم الملف أو المورد. أعد الفحص بعد التصحيح.';
     elseif(str_contains($raw,'syntax')||str_contains($raw,'parse'))$fix='راجع صياغة الملف (PHP/JSON) قبل الرفع.';
     elseif(str_contains($raw,'ai_routes')||str_contains($raw,'quota'))$fix='راجع مزودي الذكاء والـFallbacks والحصص.';
     else $fix='افتح صفحة الفحص الكامل وراجع السياق الكامل.';
     $allErrs[]=['title'=>'خطأ نظام','code'=>$code,'raw'=>$raw,'human'=>$human,'fix'=>$fix,'at'=>$er['created_at']??null];
    }
    foreach($recentJobs as $j){
     if((string)$j['state']!=='failed')continue;
     $raw=(string)($j['error_text']??($j['error_code']??''));if($raw==='')continue;
     $fix='';
     if(str_contains($raw,'selected_file_not_found'))$fix='الملف المحدد غير موجود في بيئة Staging. راجع فهرس الملفات أو أعد إنشاء الملف.';
     elseif(str_contains($raw,'write_verification_failed'))$fix='فشل التحقق من الكتابة بعد الرفع. أعد المحاولة وافحص صلاحيات المجلد.';
     elseif(str_contains($raw,'php_syntax_invalid'))$fix='صياغة PHP غير صحيحة. راجع السطر المذكور في السجل.';
     else $fix='راجع سجل المهمة الكامل للتفاصيل.';
     $allErrs[]=['title'=>'مهمة فاشلة: '.pb_substr((string)($j['task_title']?:$j['kind']),0,60),'code'=>(string)($j['error_code']??''),'raw'=>$raw,'human'=>AdminUi::humanError($raw),'fix'=>$fix,'at'=>$j['updated_at']??null];
    }
   ?>
   <?php if(!$allErrs):?>
    <div class="empty">لا توجد أخطاء حديثة. النظام يعمل بسلاسة ✓</div>
   <?php else: foreach(array_slice($allErrs,0,12) as $e):?>
    <article class="err31-item" data-err-item>
     <div class="row">
      <span class="badge">خطأ</span>
      <?php if($e['code']):?><span class="badge info"><?=e(pb_substr((string)$e['code'],0,40))?></span><?php endif?>
      <h4><?=e((string)$e['title'])?></h4>
     </div>
     <p class="msg"><?=e(pb_substr((string)$e['human'],0,300))?></p>
     <?php if($e['raw'] && $e['raw']!==$e['human']):?>
      <pre class="tech" data-err-raw><?=e(pb_substr((string)$e['raw'],0,900))?></pre>
     <?php endif?>
     <div class="fix"><b>الإصلاح المقترح:</b> <?=e((string)$e['fix'])?></div>
     <div class="footer">
      <time><?=e(AdminUi::date($e['at']))?></time>
      <button type="button" class="copy" data-copy-item>نسخ تقرير الإصلاح</button>
     </div>
    </article>
   <?php endforeach; endif?>
  </div>
 </section>

 <div class="bottom31">
  <section class="tech30-panel">
   <div class="tech30-panel-h"><div class="title"><span class="ic">✦</span><div><h3>العقل والمبادرة</h3><p>قرارات ومبادرات بدون انتظار أمر في كل خطوة</p></div></div><a href="initiatives.php">المركز</a></div>
   <div class="brain31" data-tech-autonomy>
    <?php if(!$autonomy):?><div class="empty">لا توجد بيانات مبادرة.</div><?php endif?>
    <?php foreach($autonomy as $row):$on=((int)$row['brain_enabled'] && (int)$row['initiative_enabled']);?>
    <article class="brain31-row">
     <span class="bul <?=e($on?'on':'off')?>">✦</span>
     <div>
      <b><?=e((string)$row['display_name'])?></b>
      <p><?=e(pb_substr((string)($row['last_brain_note']?:'العقل مفعّل وينتظر الجولة التالية.'),0,120))?></p>
     </div>
     <span class="sw <?=e($on?'on':'')?>"></span>
    </article>
    <?php endforeach?>
   </div>
  </section>

  <section class="tech30-panel">
   <div class="tech30-panel-h"><div class="title"><span class="ic">▤</span><div><h3>سجل النظام المباشر</h3><p>آخر ما يحدث في التنفيذ لحظة بلحظة</p></div></div><a href="audit.php">السجل</a></div>
   <div class="log31" data-tech-live-log>
    <?php if(!$recentJobs):?><div class="empty">لا توجد أحداث حديثة.</div><?php endif?>
    <?php foreach($recentJobs as $j):?>
    <article class="log31-row">
     <i class="<?=e($stateClass((string)$j['state']))?>"></i>
     <time><?=e(AdminUi::date($j['updated_at']))?></time>
     <p><b><?=e($j['agent_name']?:'النظام')?></b> · <?=e(AdminUi::label((string)$j['state']))?> — <?=e(pb_substr((string)($j['task_title']?:'عملية نظامية'),0,100))?></p>
    </article>
    <?php endforeach?>
   </div>
  </section>
 </div>
</div>

<script>
(function(){
 const root=document.querySelector('[data-tech-live-root]');if(!root)return;
 const csrf=root.dataset.techCsrf||'';
 let paused=false;

 const pauseBtn=root.querySelector('[data-flow-pause]');
 pauseBtn?.addEventListener('click',()=>{
  paused=!paused;
  const b=pauseBtn.querySelector('b');
  if(b)b.textContent=paused?'تشغيل الحركة':'إيقاف الحركة';
  root.querySelectorAll('.flow32-packet').forEach(p=>p.classList.toggle('pause',paused));
  root.querySelectorAll('.flow32-line').forEach(l=>l.style.animationPlayState=paused?'paused':'running');
 });

 function attachCopy(el,getText,labelDone){
  el.addEventListener('click',async ev=>{
   ev.preventDefault();
   try{
    await navigator.clipboard.writeText(getText());
    el.classList.add('done');
    const orig=el.textContent;
    el.textContent=labelDone||'✓ تم النسخ';
    setTimeout(()=>{el.classList.remove('done');el.textContent=orig;},1400);
   }catch(e){}
  });
 }
 root.querySelectorAll('[data-copy-item]').forEach(btn=>{
  const item=btn.closest('[data-err-item]');if(!item)return;
  attachCopy(btn,()=>{
   const title=item.querySelector('h4')?.textContent||'';
   const code=item.querySelector('.badge.info')?.textContent||'';
   const msg=item.querySelector('.msg')?.textContent||'';
   const raw=item.querySelector('[data-err-raw]')?.textContent||'';
   const fix=item.querySelector('.fix')?.textContent||'';
   return `【تقرير خطأ】\nالعنوان: ${title}\nالكود: ${code}\nالوصف: ${msg}\n${raw?('التفاصيل:\n'+raw+'\n'):''}${fix}`;
  });
 });
 root.querySelectorAll('[data-copy-snippet-btn]').forEach(el=>{
  attachCopy(el,()=>el.dataset.copySnippetBtn||'');
 });
 const copyAllBtn=root.querySelector('[data-err-copy-all]');
 copyAllBtn?.addEventListener('click',async()=>{
  const items=[...root.querySelectorAll('[data-err-item]')].map(it=>{
   const title=it.querySelector('h4')?.textContent||'';
   const code=it.querySelector('.badge.info')?.textContent||'';
   const raw=it.querySelector('[data-err-raw]')?.textContent||'';
   const fix=it.querySelector('.fix')?.textContent||'';
   return `• ${title} [${code}]\n${raw}\n${fix}`;
  }).join('\n\n');
  try{await navigator.clipboard.writeText(items);copyAllBtn.classList.add('done');const t=copyAllBtn.textContent;copyAllBtn.textContent='✓ تم النسخ';setTimeout(()=>{copyAllBtn.classList.remove('done');copyAllBtn.textContent=t;},1400);}catch(e){}
 });
 const copyRepairBtn=root.querySelector('[data-err-copy-repair]');
 copyRepairBtn?.addEventListener('click',async()=>{
  const lines=[];
  root.querySelectorAll('[data-err-item]').forEach(it=>{
   const title=it.querySelector('h4')?.textContent||'';
   const code=it.querySelector('.badge.info')?.textContent||'';
   const raw=it.querySelector('[data-err-raw]')?.textContent||'';
   const fix=it.querySelector('.fix')?.textContent||'';
   lines.push(`### ${title}\n- كود: ${code}\n- سياق: ${raw.split('\n').slice(0,3).join(' | ')}\n- إصلاح: ${fix}`);
  });
  const text=`【تقرير إصلاح موحد】\nالتاريخ: ${new Date().toLocaleString('ar-EG')}\nعدد الأخطاء: ${lines.length}\n\n${lines.join('\n\n')}\n\nملاحظة: بعد الإصلاح، أعد الفحص واطلب Retest موثّق.`;
  try{await navigator.clipboard.writeText(text);copyRepairBtn.classList.add('done');const t=copyRepairBtn.textContent;copyRepairBtn.textContent='✓ تم النسخ';setTimeout(()=>{copyRepairBtn.classList.remove('done');copyRepairBtn.textContent=t;},1600);}catch(e){}
 });

 const focusBox=root.querySelector('[data-flow-focus]');
 root.querySelectorAll('[data-tech-node]').forEach(node=>{
  node.addEventListener('click',e=>{
   if(node.tagName==='A')return;
   e.preventDefault();
   const t=node.dataset.nodeTitle||'';
   const d=node.dataset.nodeDesc||'';
   const st=node.querySelector('[data-node-status]')?.textContent||'—';
   if(focusBox){
    focusBox.innerHTML='<b>'+t+'</b><span>'+d+' • الحالة: '+st+'</span><small>عرض من بيانات حية.</small>';
   }
  });
 });

 function setPacket(eid,on){
  const p=root.querySelector('.flow32-packet[data-packet="'+eid+'"]');
  if(!p)return;
  p.classList.toggle('is-on',!!on);
 }
 function setCutMark(eid,on){
  const m=root.querySelector('[data-cut-for="'+eid+'"]');
  if(m)m.classList.toggle('is-on',!!on);
 }

 const tasksHost=root.querySelector('[data-flow-tasks]');
 const workerNodeCoords={
  'ramy'             :{x:33.75,y:50},
  'walid'            :{x:51.25,y:11.11},
  'ayman'            :{x:64.375,y:11.11},
  'emad'             :{x:51.25,y:33.33},
  'samir-social'     :{x:64.375,y:33.33},
  'video-director'   :{x:51.25,y:55.56},
  'community-manager':{x:64.375,y:55.56},
  'basant'           :{x:51.25,y:77.78},
  'free-model-scout' :{x:64.375,y:77.78},
 };
 function renderTaskBubbles(tasks){
  if(!tasksHost)return;
  tasksHost.innerHTML='';
  if(!tasks||!tasks.length)return;
  tasks.slice(0,6).forEach((t,i)=>{
   const c=workerNodeCoords[t.agent_slug]||null;
   if(!c)return;
   const isErr=(t.state==='failed'||t.state==='blocked'||t.state==='needs_fix');
   const el=document.createElement('div');
   el.className='flow32-task-bubble'+(isErr?' is-error':'');
   const offsetX=(i%2===0)?-18:18;
   el.style.left='calc('+c.x+'% + '+offsetX+'px)';
   el.style.top=(c.y-6)+'%';
   el.style.animationDelay=(-i*0.4)+'s';
   const safeTitle=String(t.title||'').replace(/[<>&]/g,m=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[m]));
   el.innerHTML='<span class="tag">'+(isErr?'متوقف':'جاري التنفيذ')+'</span><b>'+safeTitle+'</b><div class="bar"><i></i></div>';
   tasksHost.appendChild(el);
  });
 }

 const updated=root.querySelector('[data-tech-updated]');
 const stuckEl=root.querySelector('[data-flow-stuck]');
 const stuckN=root.querySelector('[data-flow-stuck-n]');
 let currentEdges={};

 const refresh=async()=>{
  if(paused)return;
  try{
   const r=await fetch('api/tech-live.php',{
    headers:{'X-CSRF-Token':csrf,'X-Requested-With':'fetch'},
    credentials:'same-origin'
   });
   if(!r.ok)return;
   const j=await r.json();if(!j||!j.ok)return;
   const d=j.data||{};

   const set=(k,v)=>{const el=root.querySelector('[data-kpi="'+k+'"]');if(el)el.textContent=v;};
   const setSub=(k,v)=>{const el=root.querySelector('[data-kpi-sub="'+k+'"]');if(el)el.textContent=v;};
   if(d.kpis){
    set('ramy_speed',d.kpis.ramy_speed||'—');
    set('agents_speed',d.kpis.agents_speed||'—');
    set('active_tasks',String(d.kpis.active_tasks??'—'));
    set('critical_errors',String(d.kpis.critical_errors??'—'));
    set('message_success',d.kpis.message_success||'—');
    set('overall',d.kpis.overall||'—');
    setSub('queue',d.kpis.running_text||'');
    setSub('worker',d.kpis.worker_text||'');
   }

   const agentStateMap={};
   if(d.agents){
    d.agents.forEach(a=>{
     agentStateMap[a.slug]=a;
     const node=root.querySelector('[data-tech-node="'+a.slug+'"]');
     if(node){
      node.classList.remove('s-working','s-error','s-waiting','s-idle','s-off');
      node.classList.add(a.state_cls||'s-idle');
      node.classList.toggle('has-tasks',(a.active_tasks||0)>0);
      const cnt=node.querySelector('.count');
      if((a.active_tasks||0)>0){
       if(cnt)cnt.textContent=String(a.active_tasks);
       else {const c=document.createElement('span');c.className='count';c.textContent=String(a.active_tasks);node.prepend(c);}
      }else if(cnt)cnt.remove();
      const stNode=node.querySelector('[data-node-status="'+a.slug+'"]');
      if(stNode)stNode.textContent=a.label||'';
     }
     const lane=root.querySelector('[data-agent-lane="'+a.slug+'"]');
     if(lane){
      lane.classList.remove('is-error','is-working','is-waiting','is-idle','is-off');
      lane.classList.add(a.cls||'is-idle');
      const pill=lane.querySelector('.pill');
      if(pill){pill.className='pill '+(a.state_cls||'s-idle');pill.textContent=a.label||'';}
      const sd=lane.querySelectorAll('.agent31-stats div');
      if(sd.length>=3){
       sd[0].querySelector('b').textContent=String(a.active_tasks??0);
       sd[1].querySelector('b').textContent=String(a.done_today??0);
       sd[2].querySelector('b').textContent=String(a.errors_24h??0);
      }
     }
    });
   }

   const edgesToCheck=[
    'e_wc','e_dc','e_cr','e_xr',
    'e_r_walid','e_r_ayman','e_r_emad','e_r_samir',
    'e_r_video','e_r_comm','e_r_basant','e_r_scout',
    'e_ah','e_ee','e_fm','e_bd','e_hd','e_ed','e_md'
   ];
   let stuckCount=0;
   edgesToCheck.forEach(eid=>{
    const line=root.querySelector('[data-flow-edge="'+eid+'"]');
    if(!line)return;
    const target=line.dataset.edgeAgent||'';
    const a=agentStateMap[target];
    let active=false,cut=false;
    if(a){
     active=(a.cls==='is-working'||a.cls==='is-waiting');
     cut=(a.cls==='is-error');
    }else if(['capture','context','memory','evidence','delivery','hostinger'].includes(target)){
     active=true;
    }
    if(cut)stuckCount++;
    line.classList.toggle('is-active',active&&!cut);
    line.classList.toggle('is-cut',cut);
    const prev=currentEdges[eid]||false;
    const now=active&&!cut;
    if(prev!==now){
     setPacket(eid,now);
     currentEdges[eid]=now;
    }
    setCutMark(eid,cut);
   });

   if(stuckEl){
    if(stuckCount>0){stuckEl.classList.remove('hidden');if(stuckN)stuckN.textContent=String(stuckCount);}
    else stuckEl.classList.add('hidden');
   }

   if(d.active_tasks_list&&Array.isArray(d.active_tasks_list)){
    renderTaskBubbles(d.active_tasks_list);
   }

   if(updated)updated.textContent='آخر تحديث '+new Date().toLocaleTimeString('ar-EG');
  }catch(e){}
 };

 root.querySelector('[data-tech-refresh]')?.addEventListener('click',refresh);
 root.querySelector('[data-flow-refresh]')?.addEventListener('click',refresh);
 root.querySelector('[data-flow-fit]')?.addEventListener('click',()=>{
  const s=root.querySelector('[data-flow-scroll]');if(s)s.scrollLeft=0;
 });

 document.querySelectorAll('[data-flow-edge]').forEach(line=>{
  const target=line.dataset.edgeAgent||'';
  const node=root.querySelector('[data-tech-node="'+target+'"]');
  const working=node && (node.classList.contains('s-working')||node.classList.contains('s-error'));
  const cut=node && node.classList.contains('s-error');
  if(working){
   line.classList.add(cut?'is-cut':'is-active');
   if(!cut)setPacket(line.dataset.flowEdge,true);
   currentEdges[line.dataset.flowEdge]=!cut;
  }
  setCutMark(line.dataset.flowEdge,cut);
 });

 setInterval(refresh,2000);
 setTimeout(refresh,600);
})();
</script>
<?php AdminUi::footer(); ?>