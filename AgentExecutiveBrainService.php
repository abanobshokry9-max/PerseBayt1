<?php
declare(strict_types=1);

/** Executive signal loop: deterministic actions around the AI autonomy planner. */
final class AgentExecutiveBrainService {
    public static function tick():array{
        if(setting('agents.executive_brain_enabled','1')==='0')return ['enabled'=>false];
        $out=['enabled'=>true,'qa_handoffs'=>0,'stalls'=>0,'failed_jobs'=>0,'whatsapp_pending'=>0];
        try{$out['qa_handoffs']=self::handoffReadyQa();}catch(Throwable $e){$out['qa_error']=pb_substr(Security::redactSecrets($e->getMessage()),0,160);}
        try{$out['stalls']=self::surfaceStalls();}catch(Throwable $e){$out['stall_error']=pb_substr(Security::redactSecrets($e->getMessage()),0,160);}
        try{$out['failed_jobs']=self::surfaceFailedJobs();}catch(Throwable $e){$out['job_error']=pb_substr(Security::redactSecrets($e->getMessage()),0,160);}
        try{$out['whatsapp_pending']=(int)db()->query("SELECT COUNT(*) FROM whatsapp_pending_messages WHERE state IN ('pending','failed') AND sent_at IS NULL")->fetchColumn();}catch(Throwable){}
        put_setting('agents.executive_brain_last_at',now_utc());put_setting('agents.executive_brain_last_result',j($out));return $out;
    }

    private static function handoffReadyQa():int{
        $q=db()->query("SELECT t.id,t.project_id,t.title FROM tasks t JOIN agents a ON a.id=t.assigned_agent_id WHERE a.slug='ayman' AND t.status IN ('needs_review','completed') AND t.project_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tasks r JOIN agents ra ON ra.id=r.assigned_agent_id WHERE r.parent_task_id=t.id AND ra.slug='emad') ORDER BY t.updated_at ASC LIMIT 3");$n=0;
        foreach($q->fetchAll() as $t){try{$r=Workflow::requestEmadReview((int)$t['project_id'],(int)$t['id'],'تسليم تلقائي من العقل التنفيذي بعد انتهاء تنفيذ أيمن. راجع المتطلبات والأدلة واعمل إعادة اختبار فعلية.','agent',(string)AgentService::bySlug('ramy')['id']);$n++;TeamChatService::post('agent','ramy','team','حوّلت المهمة #'.(int)$t['id'].' إلى عماد تلقائيًا للمراجعة بعد اكتمال تنفيذ أيمن.','status',(int)$t['project_id'],(int)($r['primary_task']??0)?:null,['brain_generated'=>1]);}catch(Throwable){}}
        return $n;
    }

    private static function surfaceStalls():int{
        if(!self::due('agents.executive_brain_last_stall_notice_at',600))return 0;
        $q=db()->query("SELECT t.id,t.title,t.status,t.project_id,a.slug,a.display_name,TIMESTAMPDIFF(MINUTE,t.updated_at,NOW()) age_minutes FROM tasks t JOIN agents a ON a.id=t.assigned_agent_id WHERE t.status IN ('blocked','waiting') AND t.updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE) ORDER BY t.updated_at ASC LIMIT 5");$rows=$q->fetchAll();if(!$rows)return 0;
        $parts=[];foreach($rows as $r)$parts[]='#'.(int)$r['id'].' '.$r['display_name'].' — '.AdminUi::label((string)$r['status']).' منذ '.(int)$r['age_minutes'].' دقيقة';
        TeamChatService::post('agent','ramy','team','العقل التنفيذي رصد مهام متوقفة تحتاج متابعة: '.implode(' | ',$parts),'status',null,null,['brain_generated'=>1]);put_setting('agents.executive_brain_last_stall_notice_at',now_utc());return count($rows);
    }

    private static function surfaceFailedJobs():int{
        if(!self::due('agents.executive_brain_last_failed_notice_at',600))return 0;
        $rows=db()->query("SELECT j.id,j.error_code,a.display_name FROM jobs j LEFT JOIN agents a ON a.id=j.agent_id WHERE j.state='failed' AND j.updated_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR) ORDER BY j.id DESC LIMIT 5")->fetchAll();if(!$rows)return 0;
        $parts=[];foreach($rows as $r)$parts[]='عملية #'.(int)$r['id'].' '.($r['display_name']?:'النظام').' — '.AdminUi::humanError((string)($r['error_code']??'unknown'));
        TeamChatService::post('agent','ramy','team','تم رصد فشل حديث وسأبقيه ظاهرًا للفريق بدل أن يمر بصمت: '.implode(' | ',$parts),'status',null,null,['brain_generated'=>1]);put_setting('agents.executive_brain_last_failed_notice_at',now_utc());return count($rows);
    }

    private static function due(string $key,int $seconds):bool{$ts=utc_ts((string)setting($key,''));return $ts===false||$ts<time()-$seconds;}
}
