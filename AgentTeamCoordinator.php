<?php
declare(strict_types=1);

/**
 * Turns the shared team room into an operating room instead of a passive log.
 * It consumes directed agent-to-agent messages once, lets the receiver react,
 * and can execute a safe internal hand-off through the existing command bus.
 */
final class AgentTeamCoordinator {
    public static function tick(int $limit=6):array{
        if(setting('agents.team_coordinator_enabled','1')==='0'||!TeamChatService::ready())return ['enabled'=>false,'checked'=>0,'replied'=>0,'actions'=>0,'items'=>[]];
        $limit=max(1,min(12,$limit));$last=max(0,(int)setting('agents.team_coordinator_last_message_id','0'));
        $sql="SELECT m.*,p.name project_name,t.title task_title FROM team_chat_messages m LEFT JOIN projects p ON p.id=m.project_id LEFT JOIN tasks t ON t.id=m.task_id WHERE m.id>? AND m.sender_type='agent' AND m.receiver_ref NOT IN ('team','owner','1') ORDER BY m.id ASC LIMIT ".$limit;
        $q=db()->prepare($sql);$q->execute([$last]);$rows=$q->fetchAll();$items=[];$replied=0;$actions=0;
        foreach($rows as $m){$id=(int)$m['id'];try{
            $meta=[];try{$meta=json_decode((string)($m['meta_json']??''),true)?:[];}catch(Throwable){}
            $from=(string)$m['sender_ref'];$to=(string)$m['receiver_ref'];if($from===$to){self::mark($id);continue;}
            $target=AgentService::assertRunnable(AgentService::bySlug($to));
            $body=trim((string)$m['body_text']);if($body===''){self::mark($id);continue;}

            // Messages already executed by CommunicationGateway::agentCommand need only a human-like acknowledgement.
            $alreadyDispatched=!empty($meta['already_dispatched'])||!empty($meta['command_bus']);
            $execution=null;
            if((string)$m['message_kind']==='command'&&!$alreadyDispatched){
                $execution=TeamChatService::dispatchAgentCommand($from,$to,$body,$m['task_id']?(int)$m['task_id']:null,$m['project_id']?(int)$m['project_id']:null);$actions++;
            }

            $reply=self::reply($target,$m,$execution);
            if($reply!==''){
                TeamChatService::post('agent',$to,'team',$reply,'reply',$m['project_id']?(int)$m['project_id']:null,$execution['primary_task']??($m['task_id']?(int)$m['task_id']:null),['coordinator_generated'=>1,'source_message_id'=>$id,'from_agent'=>$from]);$replied++;
            }
            $items[]=['message_id'=>$id,'from'=>$from,'to'=>$to,'state'=>'processed','executed'=>$execution!==null];
        }catch(Throwable $e){$safe=pb_substr(Security::redactSecrets($e->getMessage()),0,180);$items[]=['message_id'=>$id,'state'=>'failed','error'=>$safe];try{TeamChatService::post('system','coordinator','team','تعذر إكمال تنسيق رسالة الفريق #'.$id.': '.AdminUi::humanError($safe),'status',$m['project_id']?(int)$m['project_id']:null,$m['task_id']?(int)$m['task_id']:null,['coordinator_generated'=>1]);}catch(Throwable){} }
            finally{self::mark($id);}
        }
        return ['enabled'=>true,'checked'=>count($rows),'replied'=>$replied,'actions'=>$actions,'items'=>$items];
    }

    private static function reply(array $agent,array $message,?array $execution):string{
        if($execution!==null){$summary=trim((string)($execution['summary']??''));return $summary!==''?$summary:'استلمت التكليف وبدأت تنفيذه من خلال مسار الفريق.';}
        $recent=[];try{foreach(TeamChatService::recent(16) as $r)$recent[]=['من'=>(string)($r['sender_ref']??''),'إلى'=>(string)($r['receiver_ref']??'team'),'نوع'=>AdminUi::label((string)($r['message_kind']??'note')),'نص'=>pb_substr((string)($r['body_text']??''),0,700)];}catch(Throwable){}
        $task=null;if(!empty($message['task_id'])){try{$task=TaskService::get((int)$message['task_id']);}catch(Throwable){}}
        $schema=['type'=>'object','additionalProperties'=>false,'properties'=>['reply'=>['type'=>'string']],'required'=>['reply']];
        $prompt=(string)$agent['system_prompt']."\nأنت الآن داخل دردشة تشغيل حقيقية مع باقي فريق الوكلاء. رد على زميلك برد قصير عملي بالعربية المصرية المهنية. لا تقل إنك مجرد نموذج. لا تدّع تنفيذ شيء غير مثبت. إذا الرسالة مجرد تحديث، أكد ما فهمته وما الخطوة التالية في تخصصك. إذا يوجد عائق واضح، اذكره بدقة. لا تكرر النص حرفيًا.";
        $payload=['المرسل'=>(string)$message['sender_ref'],'الرسالة'=>(string)$message['body_text'],'المشروع'=>(string)($message['project_name']??''),'المهمة'=>$task,'آخر_دردشة'=>$recent];
        try{$r=AiGateway::json($agent,$prompt,[['role'=>'user','content'=>j($payload)]],$schema,'team_agent_reply',900);return pb_substr(trim((string)($r['data']['reply']??'')),0,900);}catch(Throwable){return 'استلمت التحديث وهتابع الجزء الخاص بي حسب حالة المهمة الحالية، وأبلغ الفريق لو ظهر عائق أو نتيجة جديدة.';}
    }

    private static function mark(int $id):void{if($id>(int)setting('agents.team_coordinator_last_message_id','0'))put_setting('agents.team_coordinator_last_message_id',(string)$id);}
}
