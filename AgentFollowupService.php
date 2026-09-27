<?php
declare(strict_types=1);

final class AgentFollowupService {
    public static function schedule(int $agentId,string $purpose,string $dueAt,string $message='',?int $projectId=null,?int $sourceTaskId=null,?int $contactId=null,string $channel='dashboard'):int{
        Permissions::requireAgent($agentId,'followup.schedule');$purpose=trim($purpose);if($purpose==='')throw new RuntimeException('followup_purpose_required');$ts=utc_ts($dueAt);if($ts===false||$ts<time()-60)throw new RuntimeException('followup_due_invalid');if(!in_array($channel,['dashboard','whatsapp','team'],true))$channel='dashboard';if($contactId)ContactDirectoryService::get($contactId);
        $q=db()->prepare("INSERT INTO agent_followups(agent_id,project_id,source_task_id,contact_id,channel,purpose,message_text,due_at,state,resolution_state) VALUES (?,?,?,?,?,?,?,?,'scheduled',NULL)");$q->execute([$agentId,$projectId,$sourceTaskId,$contactId,$channel,pb_substr($purpose,0,220),pb_substr($message,0,8000)?:null,gmdate('Y-m-d H:i:s',$ts)]);return (int)db()->lastInsertId();
    }

    public static function due(int $limit=20):array{
        $limit=max(1,min(100,$limit));$q=db()->query("SELECT f.*,a.slug,a.display_name FROM agent_followups f JOIN agents a ON a.id=f.agent_id WHERE f.state='scheduled' AND f.due_at<=NOW() ORDER BY f.due_at ASC LIMIT ".$limit);$out=[];
        foreach($q->fetchAll() as $f){try{$desc='متابعة مجدولة: '.(string)$f['purpose']."\n".(string)($f['message_text']??'')."\nراجع آخر سياق وحدد قرارًا صريحًا: نفذ الآن / أعد الجدولة / ألغِ / صعّد. إذا كان المطلوب تواصلًا خارجيًا استخدم External Action Gateway والقناة المطلوبة ولا ترسل من خارج الصلاحيات.";$ctx=['job_kind'=>'agent_task','followup_id'=>(int)$f['id'],'contact_id'=>$f['contact_id']?(int)$f['contact_id']:null,'requested_channel'=>(string)$f['channel'],'approved_actions'=>[],'followup_decision_required'=>1];$tid=TaskService::create((string)$f['slug'],'متابعة: '.pb_substr((string)$f['purpose'],0,175),$desc,$f['project_id']?(int)$f['project_id']:null,$ctx,'normal',false,$f['source_task_id']?(int)$f['source_task_id']:null,'agent',(string)$f['agent_id']);db()->prepare("UPDATE agent_followups SET state='queued',created_task_id=?,last_executed_at=NOW(),resolution_state=NULL,resolution_note=NULL WHERE id=?")->execute([$tid,(int)$f['id']]);$out[]=['id'=>(int)$f['id'],'task_id'=>$tid,'agent'=>$f['slug']];}catch(Throwable $e){db()->prepare("UPDATE agent_followups SET state='failed',resolution_state='queue_failed',resolution_note=? WHERE id=?")->execute([pb_substr(Security::redactSecrets($e->getMessage()),0,900),(int)$f['id']]);$out[]=['id'=>(int)$f['id'],'error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}}
        return $out;
    }

    public static function resolveFromTask(int $taskId,string $taskState,string $summary='',array $evidence=[]):void{
        $t=TaskService::get($taskId);$ctx=json_decode((string)($t['context_json']??'{}'),true)?:[];$fid=(int)($ctx['followup_id']??0);if($fid<1){$q=db()->prepare('SELECT id FROM agent_followups WHERE created_task_id=? ORDER BY id DESC LIMIT 1');$q->execute([$taskId]);$fid=(int)($q->fetchColumn()?:0);}if($fid<1)return;
        if($taskState==='completed'){
            $rescheduled=self::evidenceHasAction($evidence,'followup.schedule');$resolution=$rescheduled?'rescheduled':'completed';db()->prepare("UPDATE agent_followups SET state='completed',resolution_state=?,resolution_note=?,resolved_task_id=?,updated_at=NOW() WHERE id=?")->execute([$resolution,pb_substr($summary,0,1000)?:null,$taskId,$fid]);
        }elseif($taskState==='blocked'){
            $hours=max(1,min(24,(int)setting('followups.blocked_retry_hours','2')));db()->prepare("UPDATE agent_followups SET state='scheduled',due_at=DATE_ADD(NOW(),INTERVAL ? HOUR),resolution_state='blocked',resolution_note=?,resolved_task_id=?,created_task_id=NULL,updated_at=NOW() WHERE id=?")->execute([$hours,pb_substr($summary,0,1000)?:'blocked',$taskId,$fid]);
        }elseif(in_array($taskState,['failed','cancelled'],true)){
            db()->prepare("UPDATE agent_followups SET state=?,resolution_state=?,resolution_note=?,resolved_task_id=?,updated_at=NOW() WHERE id=?")->execute([$taskState==='failed'?'failed':'cancelled',$taskState,pb_substr($summary,0,1000)?:null,$taskId,$fid]);
        }
    }

    public static function reschedule(int $followupId,string $dueAt,string $note=''):void{$ts=utc_ts($dueAt);if($ts===false||$ts<time()-60)throw new RuntimeException('followup_due_invalid');db()->prepare("UPDATE agent_followups SET state='scheduled',due_at=?,created_task_id=NULL,resolution_state='rescheduled',resolution_note=?,updated_at=NOW() WHERE id=?")->execute([gmdate('Y-m-d H:i:s',$ts),pb_substr($note,0,1000)?:null,$followupId]);}
    public static function cancel(int $followupId,string $note=''):void{db()->prepare("UPDATE agent_followups SET state='cancelled',resolution_state='cancelled',resolution_note=?,updated_at=NOW() WHERE id=? AND state IN ('scheduled','queued')")->execute([pb_substr($note,0,1000)?:null,$followupId]);}

    private static function evidenceHasAction(array $evidence,string $type):bool{$actions=(array)($evidence['actions']??[]);foreach($actions as $a){if(is_array($a)&&(string)($a['type']??'')===$type)return true;}return false;}
}
