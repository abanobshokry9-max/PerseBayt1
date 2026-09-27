<?php
declare(strict_types=1);

/** Rami's proactive owner-escalation lane. Every owner decision gets dashboard evidence and a best-effort WhatsApp ping. */
final class OwnerDecisionService {
    public static function request(string $topic,string $summary,?int $customerId=null,?int $projectId=null,string $severity='info',string $dedupeContext=''):array {
        $topic=pb_substr(trim($topic),0,160);$summary=pb_substr(trim($summary),0,1200);if($summary==='')return ['created'=>false,'sent'=>false,'reason'=>'empty'];
        $normalized=pb_strtolower(trim(preg_replace('/\s+/u',' ',$topic.'|'.$summary)??''));$key=hash('sha256',($customerId?:0).'|'.($projectId?:0).'|'.($dedupeContext!==''?pb_strtolower(trim($dedupeContext)):$normalized));$hours=max(1,min(48,(int)setting('ramy.owner_decision_dedupe_hours','6')));
        try{
            $q=db()->prepare("SELECT * FROM owner_decision_requests WHERE dedupe_key=? AND state='pending' AND created_at>=DATE_SUB(NOW(),INTERVAL ".$hours." HOUR) ORDER BY id DESC LIMIT 1");$q->execute([$key]);$existing=$q->fetch();
            if($existing){db()->prepare('UPDATE owner_decision_requests SET repeat_count=repeat_count+1,updated_at=NOW() WHERE id=?')->execute([(int)$existing['id']]);return ['created'=>false,'sent'=>(string)$existing['whatsapp_state']==='sent','request_id'=>(int)$existing['id'],'deduped'=>true];}
        }catch(Throwable){}
        // A retried webhook can produce a slightly different AI summary. Suppress near-identical owner
        // escalations for the same customer/project/topic in a short window before creating a notification.
        try{
            $q=db()->prepare("SELECT * FROM owner_decision_requests WHERE state='pending' AND topic=? AND COALESCE(customer_id,0)=? AND COALESCE(project_id,0)=? AND created_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE) ORDER BY id DESC LIMIT 4");
            $q->execute([$topic?:'قرار يحتاج المالك',$customerId?:0,$projectId?:0]);
            $newNorm=pb_strtolower(trim(preg_replace('/\s+/u',' ',$summary)??''));
            foreach($q->fetchAll() as $near){$oldNorm=pb_strtolower(trim(preg_replace('/\s+/u',' ',(string)($near['summary_text']??''))??''));$pct=0.0;if($oldNorm!==''&&$newNorm!=='')similar_text($oldNorm,$newNorm,$pct);if($pct>=72){db()->prepare('UPDATE owner_decision_requests SET repeat_count=repeat_count+1,updated_at=NOW() WHERE id=?')->execute([(int)$near['id']]);return ['created'=>false,'sent'=>(string)$near['whatsapp_state']==='sent','request_id'=>(int)$near['id'],'deduped'=>true,'near_duplicate'=>true];}}
        }catch(Throwable){}
        Notifications::add(in_array($severity,['critical','warning','info'],true)?$severity:'info','customers',$topic!==''?$topic:'قرار عميل يحتاج المالك',$summary,$customerId?'customer':'system',$customerId?(string)$customerId:null);
        $id=0;try{db()->prepare("INSERT INTO owner_decision_requests(dedupe_key,topic,summary_text,customer_id,project_id,state,whatsapp_state) VALUES (?,?,?,?,?,'pending','queued')")->execute([$key,$topic?:'قرار يحتاج المالك',$summary,$customerId,$projectId]);$id=(int)db()->lastInsertId();}catch(Throwable){}
        $body=self::ramyMessage($topic,$summary,$customerId,$projectId);$sent=false;$error='';
        try{$r=CommunicationGateway::agentToOwner('ramy',$body,'whatsapp');$sent=!empty($r['sent']);}
        catch(Throwable $e){$error=pb_substr(Security::redactSecrets($e->getMessage(),220),0,220);Notifications::add('warning','communications','رامي لم يستطع إرسال قرار المالك على واتساب','تم حفظ القرار في النظام، لكن إرسال واتساب للمالك تعذر: '.AdminUi::humanError($error),'owner_decision',$id?(string)$id:null);}
        if($id){try{db()->prepare('UPDATE owner_decision_requests SET whatsapp_state=?,whatsapp_error=?,whatsapp_sent_at=IF(?,NOW(),whatsapp_sent_at),updated_at=NOW() WHERE id=?')->execute([$sent?'sent':'failed',$error?:null,$sent?1:0,$id]);}catch(Throwable){}}
        return ['created'=>true,'sent'=>$sent,'request_id'=>$id,'error'=>$error];
    }

    private static function ramyMessage(string $topic,string $summary,?int $customerId,?int $projectId):string {
        $refs=[];
        if($customerId){
            $label='عميل #'.$customerId;
            try{$q=db()->prepare('SELECT display_name,phone FROM customers WHERE id=? LIMIT 1');$q->execute([$customerId]);$c=$q->fetch();if($c){$name=trim((string)($c['display_name']??''));$phone=preg_replace('/\D+/','',(string)($c['phone']??''))??'';$label=($name!==''?$name:'عميل').' #'.$customerId.($phone!==''?' · +'.$phone:'');}}catch(Throwable){}
            $refs[]=$label;
        }
        if($projectId){
            $label='مشروع #'.$projectId;
            try{$q=db()->prepare('SELECT name FROM projects WHERE id=? LIMIT 1');$q->execute([$projectId]);$name=trim((string)($q->fetchColumn()?:''));if($name!=='')$label=$name.' #'.$projectId;}catch(Throwable){}
            $refs[]=$label;
        }
        $ref=$refs?' ('.implode(' · ',$refs).')':'';$head=$topic!==''?$topic:'قرار محتاج رأيك';
        return pb_substr("يا بومبو، محتاج رأيك في {$head}{$ref}.\n".$summary."\nردّ عليا هنا بالقرار وأنا أكمل مع العميل.",0,1100);
    }
}
