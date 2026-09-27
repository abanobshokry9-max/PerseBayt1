(()=>{
  const menu=document.querySelector('[data-menu]'),sidebar=document.querySelector('.sidebar'),backdrop=document.querySelector('[data-sidebar-backdrop]');
  const setMenu=open=>{if(!sidebar||!menu)return;sidebar.classList.toggle('open',open);document.body.classList.toggle('menu-open',open);menu.setAttribute('aria-expanded',open?'true':'false')};
  menu?.addEventListener('click',()=>setMenu(!sidebar?.classList.contains('open')));backdrop?.addEventListener('click',()=>setMenu(false));
  sidebar?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{if(window.matchMedia('(max-width:1000px)').matches)setMenu(false)}));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')setMenu(false)});

  document.querySelectorAll('[data-flash-close]').forEach(btn=>btn.addEventListener('click',()=>btn.closest('.flash')?.remove()));

  const bell=document.querySelector('[data-notification-bell]');
  if(bell){
    let seen=false;
    bell.addEventListener('toggle',async()=>{
      if(!bell.open||seen)return;seen=true;
      const fd=new FormData();fd.set('csrf',bell.dataset.csrf||'');
      const notices=[...bell.querySelectorAll('.notice[data-notification-id]')];
      notices.forEach(n=>fd.append('ids[]',n.dataset.notificationId||''));
      if(!notices.length)return;
      try{
        const r=await fetch('/api/notifications-read.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        if(r.ok){bell.querySelector('summary span')?.remove();notices.forEach(n=>n.classList.add('seen'));}
      }catch(_){seen=false;}
    });
  }

  const append=(box,cls,name,text,meta)=>{
    const d=document.createElement('div');d.className='msg '+cls;
    const h=document.createElement('div');h.className='msg-head';
    const b=document.createElement('b');b.textContent=name;
    const s=document.createElement('span');s.textContent=meta;
    h.append(b,s);const body=document.createElement('div');body.className='msg-body';body.textContent=text;d.append(h,body);box.appendChild(d);box.scrollTop=box.scrollHeight;
  };

  const chat=document.querySelector('[data-chat]');
  if(chat){
    chat.scrollTop=chat.scrollHeight;
    const form=document.querySelector('[data-chat-form]');
    form?.addEventListener('submit',async e=>{
      e.preventDefault();const ta=form.querySelector('textarea'),btn=form.querySelector('button'),text=ta?.value.trim()||'';if(!text)return;
      append(chat,'owner','أبانوب',text,'لوحة التحكم · دلوقتي');ta.value='';btn.disabled=true;
      try{
        const fd=new FormData(form);fd.set('message',text);
        const r=await fetch('/api/chat.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'request_failed');
        append(chat,'agent','رامي',j.reply||'تم استلام الأمر.','لوحة التحكم · دلوقتي');
      }catch(_){append(chat,'agent','رامي','حصلت مشكلة في التنفيذ. راجع التنبيهات وحالة النظام.','لوحة التحكم · فشل');}
      finally{btn.disabled=false;ta?.focus();}
    });
  }

  const teamChat=document.querySelector('[data-team-chat]');
  const teamForm=document.querySelector('[data-team-chat-form]');
  if(teamChat){
    teamChat.scrollTop=teamChat.scrollHeight;
    teamForm?.addEventListener('submit',async e=>{
      e.preventDefault();const ta=teamForm.querySelector('textarea'),btn=teamForm.querySelector('button'),text=ta?.value.trim()||'';if(!text)return;
      append(teamChat,'owner','أبانوب',text,'شات الفريق · دلوقتي');ta.value='';btn.disabled=true;
      try{
        const fd=new FormData(teamForm);fd.set('message',text);
        const r=await fetch('/api/team-chat.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'request_failed');
        if(j.reply)append(teamChat,'agent',j.sender_name||'رامي',j.reply,'شات الفريق · دلوقتي');
      }catch(err){append(teamChat,'system','النظام','تعذر تنفيذ الرسالة دلوقتي. افتح التنبيهات أو فحص النظام.','شات الفريق · فشل');}
      finally{btn.disabled=false;ta?.focus();}
    });
  }

  const conversationModal=document.querySelector('.conversation-modal');
  if(conversationModal){document.addEventListener('keydown',e=>{if(e.key==='Escape'){const close=conversationModal.querySelector('.icon-close');if(close?.href)location.href=close.href;}});conversationModal.querySelector('.conversation-modal-chat')?.scrollTo(0,conversationModal.querySelector('.conversation-modal-chat')?.scrollHeight||0);}

  document.querySelectorAll('[data-confirm]').forEach(x=>x.addEventListener('click',e=>{if(!confirm(x.dataset.confirm||'متأكد إنك عايز تنفذ العملية دي؟'))e.preventDefault()}));


  const runtimeNodes=[...document.querySelectorAll('[data-agent-runtime]')];
  if(runtimeNodes.length){
    let runtimeBusy=false;
    const refreshRuntime=async()=>{
      if(runtimeBusy||document.hidden)return;runtimeBusy=true;
      try{
        const r=await fetch('/api/admin/status-feed.php',{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
        if(!r.ok)throw new Error('runtime_feed_failed');
        const data=await r.json();if(!data.ok)throw new Error('runtime_feed_invalid');
        (data.agents||[]).forEach(a=>{
          document.querySelectorAll('[data-agent-runtime="'+a.slug+'"]').forEach(node=>{
            const host=node.hasAttribute('data-agent-badge')?node:node.querySelector('[data-agent-badge]');
            const badge=host?.querySelector('.badge')||host;
            if(badge){badge.className='badge s-'+String(a.status||'idle').replaceAll('_','-');badge.textContent=a.status_label||a.status||'—';}
            const task=node.querySelector?.('[data-agent-task]');if(task)task.textContent=a.current_task_id?'المهمة #'+a.current_task_id+(a.current_task_title?' — '+a.current_task_title:''):'بدون مهمة حالية';
          });
        });
        const working=document.querySelector('[data-agents-working]');if(working)working.textContent=String(data.working??0);
        const worker=document.querySelector('[data-worker-state]');if(worker)worker.textContent='Worker: '+(data.worker?.healthy?'Healthy':'Stale / Unknown');
      }catch(_){/* keep the last verified UI snapshot */}
      finally{runtimeBusy=false;}
    };
    refreshRuntime();setInterval(refreshRuntime,5000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshRuntime();});
  }

  // Owner-only vault reveal. The raw secret is never rendered server-side and is re-hidden automatically.
  document.querySelectorAll('[data-account-secret]').forEach(btn=>btn.addEventListener('click',async()=>{
    const id=btn.dataset.accountSecret||'',out=document.querySelector('[data-secret-output="'+CSS.escape(id)+'"]');
    if(!id||!out)return;btn.disabled=true;
    try{
      const fd=new FormData();fd.set('id',id);fd.set('csrf',btn.dataset.csrf||'');
      const r=await fetch('/api/admin/account-secret.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
      const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'vault_read_failed');
      out.textContent=String(j.secret||'');
      try{await navigator.clipboard?.writeText(String(j.secret||''));btn.textContent='تم الإظهار والنسخ';}catch(_){btn.textContent='تم الإظهار';}
      setTimeout(()=>{out.textContent='••••••••••••';btn.textContent='إظهار/نسخ للمالك';},30000);
    }catch(e){btn.textContent='تعذر الإظهار';setTimeout(()=>btn.textContent='إظهار/نسخ للمالك',2500);}
    finally{btn.disabled=false;}
  }));

  // Lightweight live filtering for card-based pages (Agency, Security, Accounts, Contacts).
  document.querySelectorAll('[data-live-filter]').forEach(input=>{
    const apply=()=>{const q=String(input.value||'').trim().toLocaleLowerCase('ar');document.querySelectorAll('[data-live-filter-item]').forEach(el=>{const hay=String(el.dataset.search||el.textContent||'').toLocaleLowerCase('ar');el.hidden=q!==''&&!hay.includes(q);});};
    input.addEventListener('input',apply);apply();
  });

  // الصفحة التقنية التفاعلية 20.2.3 — جميع الوكلاء + مهام متصلة + قطع الخط بدون علامات طائرة.
  const techRoot=document.querySelector('[data-tech-live-root]');
  if(techRoot){
    const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const stateClass=s=>{s=String(s||'').toLowerCase();if(['working','running','verified','done','completed','approved','online'].includes(s))return's-working';if(['failed','error','critical','blocked','needs_fix','problem'].includes(s))return's-error';if(['queued','waiting','needs_review','retesting','proposed','assigned','new'].includes(s))return's-waiting';if(['disabled','off'].includes(s))return's-off';return's-idle';};
    const workState=s=>{s=String(s||'').toLowerCase();if(['working','running','executing','in_development','qa','retesting'].includes(s))return'is-working';if(['blocked','failed','error','needs_fix','problem'].includes(s))return'is-error';if(['queued','waiting','assigned','new','needs_review','proposed','approved','negotiating','agreed','deposit_pending','awaiting_execution','awaiting_qa','ready_delivery'].includes(s))return'is-waiting';return'is-idle';};
    const node=k=>document.querySelector('[data-tech-node="'+k+'"]');
    const nodeStatus=(k,text)=>{const el=document.querySelector('[data-node-status="'+k+'"]');if(el)el.textContent=text||'—';};
    const setNode=(k,state,text)=>{const el=node(k);if(!el)return;el.classList.remove('s-working','s-error','s-waiting','s-idle','s-off');el.classList.add(stateClass(state));if(text!==undefined)nodeStatus(k,text);};
    const burst=k=>{const el=node(k);if(!el)return;el.classList.remove('is-burst');void el.offsetWidth;el.classList.add('is-burst');setTimeout(()=>el.classList.remove('is-burst'),700);};
    const arabicTime=x=>esc(x||'—');
    const reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let techBusy=false,lastEventId='',lastTeamId=0,motionPaused=reduced;

    const edgeEls={};document.querySelectorAll('[data-flow-edge]').forEach(p=>edgeEls[p.dataset.flowEdge]=p);
    const packetEls={};document.querySelectorAll('[data-flow-packet]').forEach(c=>packetEls[c.dataset.flowPacket]=c);
    const edgeRuntime={};Object.keys(edgeEls).forEach((id,i)=>edgeRuntime[id]={state:'idle',phase:(i*.137)%1,speed:.16+(i%4)*.025,tone:'green'});
    const setEdge=(id,state,tone='green')=>{const path=edgeEls[id],packet=packetEls[id];if(!path)return;state=['live','wait','broken','idle'].includes(state)?state:'idle';const r=edgeRuntime[id]||{};r.state=state;r.tone=tone;edgeRuntime[id]=r;path.classList.remove('is-live','is-wait','is-broken','is-idle','line-red','line-green','line-amber','line-blue','line-violet');path.classList.add(state==='live'?'is-live':state==='wait'?'is-wait':state==='broken'?'is-broken':'is-idle');path.classList.add(state==='broken'?'line-red':'line-'+tone);if(packet){packet.classList.toggle('is-moving',state==='live'&&!motionPaused);packet.classList.toggle('is-waiting',state==='wait'&&!motionPaused);if(state==='broken'||state==='idle'||motionPaused){packet.classList.remove('is-moving','is-waiting');packet.setAttribute('cx','-20');packet.setAttribute('cy','-20');}}};
    let lastFrame=performance.now();
    const animatePackets=now=>{const dt=Math.min(.08,Math.max(0,(now-lastFrame)/1000));lastFrame=now;if(!motionPaused&&!reduced){Object.entries(edgeRuntime).forEach(([id,r])=>{if(!['live','wait'].includes(r.state))return;const path=edgeEls[id],packet=packetEls[id];if(!path||!packet)return;r.phase=(r.phase+dt*(r.state==='live'?r.speed:r.speed*.38))%1;try{const pt=path.getPointAtLength(path.getTotalLength()*r.phase);packet.setAttribute('cx',pt.x.toFixed(2));packet.setAttribute('cy',pt.y.toFixed(2));}catch(_){}});}requestAnimationFrame(animatePackets);};
    requestAnimationFrame(animatePackets);

    const renderTeam=rows=>{const box=document.querySelector('[data-tech-team-chat]');if(!box)return;const list=(rows||[]).slice(-10).reverse();box.innerHTML=list.map(m=>{const name=m.sender_name||'النظام',isNew=Number(m.id)>lastTeamId?' is-new':'';return '<article class="tech21-chat-row'+isNew+'" data-sender="'+esc(m.sender_ref||'')+'"><span class="tech21-avatar">'+esc(name.slice(0,1))+'</span><div><div class="tech21-chat-head"><b>'+esc(name)+'</b><time>'+arabicTime(m.created_display)+'</time></div><p>'+esc(m.body_text||'')+'</p></div></article>';}).join('')||'<div class="tech21-empty">لا توجد رسائل فريق حديثة.</div>';if(list.length)lastTeamId=Math.max(lastTeamId,...list.map(x=>Number(x.id)||0));};
    const renderAutonomy=rows=>{const box=document.querySelector('[data-tech-autonomy]');if(!box)return;box.innerHTML=(rows||[]).slice(0,20).map(a=>{const on=Number(a.brain_enabled)===1&&Number(a.initiative_enabled)===1,note=a.last_brain_note||'العقل مفعّل وينتظر إشارة تشغيلية ذات قيمة.',brain=String(a.last_brain_state||'idle');return '<article data-brain-state="'+esc(brain)+'"><span class="brain-bullet '+(on?'on':'off')+'">✦</span><div><b>'+esc(a.display_name||'وكيل')+'</b><p>'+esc(note)+'</p><span class="brain-time">آخر تفكير: '+arabicTime(a.last_think_display||'—')+' · الجولة التالية: '+arabicTime(a.next_run_display||'—')+'</span></div><span class="brain-switch '+(on?'on':'')+'"></span></article>';}).join('')||'<div class="tech21-empty">لا توجد بيانات مبادرة متاحة.</div>';};
    const renderInitiatives=rows=>{const box=document.querySelector('[data-tech-initiatives]');if(!box)return;box.innerHTML=(rows||[]).slice(0,5).map(i=>'<a href="initiatives.php"><b>'+esc(i.agent_name||'وكيل')+'</b><span>'+esc(i.title||'مبادرة')+'</span><span class="badge">'+esc(i.state_label||'قيد المتابعة')+'</span></a>').join('');};
    const renderDiagnostics=rows=>{const box=document.querySelector('[data-tech-diagnostics]');if(!box)return;box.innerHTML=(rows||[]).slice(0,8).map(d=>{const bad=d.state==='error';return '<article class="'+(bad?'is-error':'is-ok')+'"><span class="diag-icon">'+(bad?'!':'✓')+'</span><div><b>'+esc(d.title||'فحص')+'</b><p>'+esc(d.detail||'')+'</p><time>'+arabicTime(d.at_display)+'</time></div><span class="diag-state">'+esc(d.state_label||'—')+'</span></article>';}).join('')||'<div class="tech21-empty">لا توجد أخطاء حديثة.</div>';};
    const renderLog=rows=>{const box=document.querySelector('[data-tech-live-log]');if(!box)return;box.innerHTML=(rows||[]).map(ev=>'<article><i class="'+stateClass(ev.state)+'"></i><time>'+arabicTime(ev.at_display)+'</time><p>'+esc(ev.text||'حدث بالنظام')+'</p></article>').join('')||'<div class="tech21-empty">لا توجد أحداث حديثة.</div>';};

    const renderAgentMap=rows=>{const box=document.querySelector('[data-tech-agent-map]');if(!box)return;box.innerHTML=(rows||[]).map(a=>{const lane=workState(a.state),tasks=Array.isArray(a.tasks)?a.tasks:[];const taskHtml=tasks.map(t=>{const ts=workState(t.state),cancel=t.cancelable?'<button type="button" class="tech23-task-cancel" data-tech-cancel-task="'+Number(t.id||0)+'" title="إلغاء المهمة" aria-label="إلغاء المهمة">×</button>':'';const meta=[t.project_name?'<span>'+esc(t.project_name)+'</span>':'','<span>'+esc(t.updated_display||'—')+'</span>'].filter(Boolean).join('');return '<div class="tech23-task-wrap '+ts+'"><span class="tech23-task-branch '+ts+'"><i></i></span><article class="tech23-task-card '+ts+'">'+cancel+'<a href="'+esc(t.href||'#')+'"><div class="tech23-task-head"><b>'+esc(t.title||'مهمة')+'</b><em>'+esc(t.state_label||'قيد المتابعة')+'</em></div><p>'+esc(t.detail||'')+'</p><div class="tech23-task-meta">'+meta+'</div></a></article></div>';}).join('')||'<div class="tech23-no-task">لا توجد مهمة نشطة لهذا الوكيل.</div>';return '<article class="tech23-agent-lane '+lane+'" data-agent-lane="'+esc(a.slug||'')+'"><a class="tech23-agent-card" href="'+esc(a.href||'#')+'"><span class="tech23-agent-avatar">'+esc(String(a.name||'و').slice(0,1))+'</span><div><b>'+esc(a.name||'وكيل')+'</b><small>'+esc(a.role_title||a.specialty||'وكيل ضمن الفريق')+'</small></div><em>'+esc(a.state_label||'متاح')+'</em></a><span class="tech23-agent-trunk '+lane+'"><i></i></span><div class="tech23-task-stack">'+taskHtml+'</div></article>';}).join('')||'<div class="tech21-empty">لا توجد بيانات وكلاء متاحة.</div>';};

    const updateMetrics=d=>{const m=d.tech_metrics||{};const put=(k,v)=>{const el=document.querySelector('[data-kpi="'+k+'"]');if(el)el.textContent=v;};put('ramy_speed',m.ramy_speed_seconds==null?'لا بيانات':Number(m.ramy_speed_seconds).toLocaleString('ar-EG',{maximumFractionDigits:1})+' ثانية');put('agents_speed',m.agents_speed_seconds==null?'لا بيانات':Number(m.agents_speed_seconds).toLocaleString('ar-EG',{maximumFractionDigits:1})+' ثانية');put('active_tasks',Number(m.active_tasks||0).toLocaleString('ar-EG')+' مهمة');put('critical_errors',Number(m.failed_24h||0).toLocaleString('ar-EG'));put('message_success',m.message_success_percent==null?'لا بيانات':Number(m.message_success_percent).toLocaleString('ar-EG')+'٪');put('overall',m.overall_ok?'يعمل بكفاءة':'يحتاج متابعة');const q=document.querySelector('[data-kpi-sub="queue"]');if(q)q.textContent=Number(d.queue?.running||0).toLocaleString('ar-EG')+' تُنفّذ الآن';const w=document.querySelector('[data-kpi-sub="worker"]');if(w)w.textContent=d.worker?.healthy?'العامل متصل':'العامل متأخر';};

    const updateFlow=d=>{const rawAgents={};(d.agents||[]).forEach(a=>rawAgents[a.slug]=a);const act=d.agent_activity||{};const activityState=k=>act[k]?.flow_state||rawAgents[k]?.status||'idle';['ramy','walid','ayman','emad'].forEach(k=>{const a=act[k],raw=rawAgents[k];let text=a?.task_title||a?.task_status_label||raw?.current_task_title||raw?.status_label||'لا توجد مهمة نشطة';if(a?.has_problem&&a?.problem_display)text=a.problem_display;setNode(k,activityState(k),text);});const providers={};(d.providers||[]).forEach(p=>providers[p.provider_key]=p);const wa=providers.meta_whatsapp,hs=providers.hostinger;const waBad=!wa||!wa.configured||['failed','error'].includes(String(wa.status||'')),hsBad=!hs||!hs.configured||['failed','error'].includes(String(hs.status||''));setNode('whatsapp',waBad?'error':(wa?.status==='verified'?'verified':wa?.status||'waiting'),wa?(wa.configured?(wa.status_label||'قيد الفحص'):'غير مهيأ'):'غير مهيأ');setNode('hostinger',hsBad?'error':(hs?.status==='verified'?'verified':hs?.status||'waiting'),hs?(hs.configured?(hs.status_label||'قيد الفحص'):'غير مهيأ'):'غير مهيأ');const qActive=Number(d.queue?.queued||0)+Number(d.queue?.running||0)>0;setNode('context',d.worker?.healthy?'verified':'error',d.worker?.healthy?'يفهم السياق الآن':'العامل متأخر');setNode('capture',qActive?'running':'idle',Number(d.queue?.running||0)>0?'يعالج رسالة/مهمة الآن':'في الانتظار');const anyWorking=Object.values(act).some(a=>a?.flow_state==='working');const edgeForAgent=k=>activityState(k)==='problem'?'broken':activityState(k)==='working'?'live':activityState(k)==='waiting'?'wait':'idle';setEdge('f1',waBad?'broken':qActive?'live':'idle','green');setEdge('f2',anyWorking?'live':'idle','blue');setEdge('f3',!d.worker?.healthy?'broken':qActive?'live':'idle','green');setEdge('f4',!d.worker?.healthy?'broken':(activityState('ramy')==='working'||qActive)?'live':activityState('ramy')==='waiting'?'wait':'idle','green');setEdge('f5',edgeForAgent('walid'),'violet');setEdge('f6',edgeForAgent('ayman'),'amber');setEdge('f7',edgeForAgent('emad'),'blue');setEdge('f8',activityState('ramy')==='problem'?'broken':activityState('ramy')==='working'?'live':anyWorking?'wait':'idle','green');setEdge('f9',(activityState('ayman')==='problem'||hsBad)?'broken':activityState('ayman')==='working'?'live':activityState('ayman')==='waiting'?'wait':'idle','green');setEdge('f10',activityState('emad')==='problem'?'broken':activityState('emad')==='working'?'live':activityState('emad')==='waiting'?'wait':'idle','green');setEdge('f11',anyWorking?'live':'idle','green');setEdge('f12',hsBad?'broken':activityState('ayman')==='working'?'live':activityState('ayman')==='waiting'?'wait':'idle','amber');setEdge('f13',activityState('emad')==='problem'?'broken':activityState('emad')==='working'?'live':activityState('emad')==='waiting'?'wait':'idle','green');const focus=document.querySelector('[data-flow-focus]');if(focus){const problems=Object.values(act).filter(a=>a?.has_problem);if(problems.length)focus.innerHTML='<b>يوجد مسار متوقف</b><span>'+esc(problems.map(a=>a.name).join('، '))+' — الخط ينقطع عند الجزء المتأثر بدون علامات متحركة.</span>';}};

    const refreshTech=async(force=false)=>{if(techBusy||(!force&&document.hidden))return;techBusy=true;try{const r=await fetch('/api/admin/status-feed.php',{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});if(!r.ok)throw new Error('http');const d=await r.json();if(!d.ok)throw new Error('feed');updateMetrics(d);updateFlow(d);renderAgentMap(d.agent_lanes||[]);const online=document.querySelector('[data-team-online]');if(online)online.textContent=Number(d.online_count||0).toLocaleString('ar-EG');renderTeam(d.team_chat||[]);renderAutonomy(d.autonomy||[]);renderInitiatives(d.initiatives||[]);renderDiagnostics(d.diagnostics||[]);renderLog(d.live_events||[]);const latest=(d.live_events||[])[0];if(latest&&latest.id!==lastEventId){if(lastEventId!=='')burst(latest.agent_slug||'ramy');lastEventId=latest.id;}const u=document.querySelector('[data-tech-updated]');if(u)u.textContent='آخر تحديث حي: '+new Date().toLocaleTimeString('ar-EG');}catch(_){const u=document.querySelector('[data-tech-updated]');if(u)u.textContent='تعذر التحديث الحي — ستتم المحاولة تلقائيًا';}finally{techBusy=false;}};

    techRoot.addEventListener('click',async e=>{const btn=e.target.closest('[data-tech-cancel-task]');if(!btn)return;e.preventDefault();e.stopPropagation();const id=Number(btn.dataset.techCancelTask||0);if(!id||!confirm('إلغاء هذه المهمة ودورة العمل التابعة لها؟'))return;btn.disabled=true;btn.classList.add('is-busy');const old=btn.textContent;btn.textContent='…';try{const fd=new FormData();fd.set('csrf',techRoot.dataset.techCsrf||'');fd.set('action','task_cancel');fd.set('task_id',String(id));fd.set('ajax','1');fd.set('return','/api/admin/technology.php');const r=await fetch('/api/admin/action.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});let body={};try{body=await r.json();}catch(_){body={};}if(!r.ok||!body.ok)throw new Error(body.error||'cancel_failed');await refreshTech(true);}catch(_){alert('تعذر إلغاء المهمة الآن. حدّث الصفحة وحاول مرة أخرى.');}finally{btn.disabled=false;btn.classList.remove('is-busy');btn.textContent=old;}});

    document.querySelectorAll('.tech21-node').forEach(el=>{const focus=()=>{document.querySelectorAll('.tech21-node.is-selected').forEach(x=>x.classList.remove('is-selected'));el.classList.add('is-selected');const out=document.querySelector('[data-flow-focus]');if(out)out.innerHTML='<b>'+esc(el.dataset.nodeTitle||el.querySelector('b')?.textContent||'العقدة')+'</b><span>'+esc(el.dataset.nodeDesc||'')+'</span>';};el.addEventListener('mouseenter',focus);el.addEventListener('focus',focus);if(el.tagName==='BUTTON')el.addEventListener('click',focus);});
    const pause=document.querySelector('[data-flow-pause]'),board=document.querySelector('[data-flow-board]');if(motionPaused){techRoot.classList.add('motion-paused');board?.classList.add('motion-paused');const b=pause?.querySelector('b');if(b)b.textContent='تشغيل الحركة';}pause?.addEventListener('click',()=>{motionPaused=!motionPaused;techRoot.classList.toggle('motion-paused',motionPaused);board?.classList.toggle('motion-paused',motionPaused);Object.keys(edgeRuntime).forEach(id=>setEdge(id,edgeRuntime[id].state,edgeRuntime[id].tone||'green'));const b=pause.querySelector('b');if(b)b.textContent=motionPaused?'تشغيل الحركة':'إيقاف الحركة';});
    document.querySelector('[data-flow-fit]')?.addEventListener('click',()=>board?.classList.toggle('flow-fit'));document.querySelector('[data-flow-refresh]')?.addEventListener('click',()=>refreshTech(true));const scroll=document.querySelector('[data-flow-scroll]');if(scroll&&window.innerWidth<900)setTimeout(()=>{scroll.scrollLeft=Math.max(0,(scroll.scrollWidth-scroll.clientWidth)*.46);},80);refreshTech(true);setInterval(refreshTech,2000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshTech(true);});
  }



})();
