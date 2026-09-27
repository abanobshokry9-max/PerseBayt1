<?php
declare(strict_types=1);

final class TeamChatService {
    public static function ready():bool{
        try{return (bool)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='team_chat_messages'")->fetchColumn();}
        catch(Throwable){return false;}
    }

    public static function recent(int $limit=40):array{
        if(!self::ready())return [];
        $limit=max(1,min(120,$limit));
        $sql="SELECT m.*,a.display_name agent_name,p.name project_name,t.title task_title
              FROM team_chat_messages m
              LEFT JOIN agents a ON m.sender_type='agent' AND a.slug=m.sender_ref
              LEFT JOIN projects p ON p.id=m.project_id
              LEFT JOIN tasks t ON t.id=m.task_id
              ORDER BY m.id DESC LIMIT ".$limit;
        return array_reverse(db()->query($sql)->fetchAll());
    }

    public static function post(string $senderType,string $senderRef,string $receiverRef,string $body,string $kind='note',?int $projectId=null,?int $taskId=null,array $meta=[]):int{
        if(!self::ready())return 0;
        $body=trim($body);if($body==='')return 0;
        if(!in_array($senderType,['owner','agent','system'],true))$senderType='system';
        if(!in_array($kind,['command','reply','status','task','result','note'],true))$kind='note';
        $q=db()->prepare("INSERT INTO team_chat_messages(sender_type,sender_ref,receiver_ref,message_kind,body_text,project_id,task_id,meta_json) VALUES (?,?,?,?,?,?,?,?)");
        $q->execute([$senderType,pb_substr($senderRef,0,120),pb_substr($receiverRef?:'team',0,120),$kind,$body,$projectId,$taskId,$meta?j($meta):null]);
        return (int)db()->lastInsertId();
    }

    private static function addressedAgent(string $text):string{
        $t=trim($text);
        if(!preg_match('/^(?:يا\s*)?(رامي|ramy|وليد|walid|أيمن|ايمن|ayman|عماد|emad|سمير(?:\s+سوشيال)?|samir(?:-social)?|منى|مونا|مخرج\s+الفيديو|video-director|بسنت|basant|مدير\s+المجتمع|community-manager)(?:\s|[:،,\-]|$)/ui',$t,$m))return 'ramy';
        return match(pb_strtolower((string)$m[1])){
            'وليد','walid'=>'walid',
            'أيمن','ايمن','ayman'=>'ayman',
            'عماد','emad'=>'emad',
            'سمير','سمير سوشيال','samir','samir-social'=>'samir-social',
            'منى','مونا','مخرج الفيديو','video-director'=>'video-director',
            'بسنت','basant'=>'basant',
            'مدير المجتمع','community-manager'=>'community-manager',
            default=>'ramy'
        };
    }

    public static function ownerCommand(string $text):array{
        $text=trim($text);if($text==='')throw new RuntimeException('message_required');
        $target=self::addressedAgent($text);
        $agent=AgentService::bySlug($target);
        self::post('owner','1',$target==='ramy'?'team':$target,$text,'command');
        if($target==='ramy')$out=CommunicationGateway::dashboard($text);
        else{
            // The group room is one shared operating context. If the owner currently has
            // a focused project with Ramy, carry that project into the addressed agent's
            // direct session so commands such as "أيمن نفذ ده" do not lose context.
            try{
                $owner=ConversationService::ownerSession();
                $ownerCtx=ConversationService::activeContext((int)$owner['id']);
                $agentSession=ConversationService::agentOwnerSession($target);
                $agentCtx=ConversationService::activeContext((int)$agentSession['id']);
                if(empty($agentCtx['project']['id'])&&!empty($ownerCtx['project']['id']))
                    ConversationService::focus((int)$agentSession['id'],(int)$ownerCtx['project']['id'],null,(int)$agent['id']);
            }catch(Throwable){}
            $out=CommunicationGateway::dashboardAgent($target,$text,true);
        }
        $reply=trim((string)($out['reply']??''));
        if($reply!=='')self::post('agent',$target,'team',$reply,'reply',null,(int)($out['task_id']??$out['execution']['primary_task']??0)?:null,['action'=>$out['action']??null,'direct_owner_command'=>true]);
        return $out+['sender_slug'=>$target,'sender_name'=>(string)$agent['display_name']];
    }

    public static function agentMessage(string $fromSlug,string $toSlug,string $body,?int $taskId=null,?int $projectId=null,array $meta=[]):void{
        self::post('agent',$fromSlug,$toSlug,$body,'command',$projectId,$taskId,$meta);
    }

    public static function dispatchAgentCommand(string $fromSlug,string $toSlug,string $body,?int $taskId=null,?int $projectId=null):array{
        $body=trim($body);if($body==='')throw new RuntimeException('agent_message_incomplete');
        $from=AgentService::assertRunnable(AgentService::bySlug($fromSlug));$to=AgentService::assertRunnable(AgentService::bySlug($toSlug));$requester=(string)$from['id'];$ownerAuthorized=$fromSlug==='ramy';
        $execution=match($toSlug){
            'walid'=>Workflow::huntOpportunities('تكليف من '.$from['display_name'].' عبر شات الفريق: '.$body,null,null,[],'agent',$requester),
            'ayman'=>$projectId?Workflow::assignAyman($projectId,$body,'high','agent',$requester,$ownerAuthorized):throw new RuntimeException('project_context_missing'),
            'emad'=>Workflow::requestEmadReview($projectId,$taskId,$body,'agent',$requester),
            'ramy'=>['tasks'=>[$tid=TaskService::create('ramy','متابعة من '.$from['display_name'],$body,$projectId,['job_kind'=>'agent_task','peer_command'=>1,'from_agent'=>$fromSlug],'high',$ownerAuthorized,null,'agent',$requester)],'primary_task'=>$tid,'summary'=>'رامي استلم التكليف في شات الفريق وبدأ متابعته.'],
            default=>['tasks'=>[$tid=TaskService::create($toSlug,'تكليف من '.$from['display_name'],$body,$projectId,['job_kind'=>'agent_task','peer_command'=>1,'from_agent'=>$fromSlug],'high',$ownerAuthorized,null,'agent',$requester)],'primary_task'=>$tid,'summary'=>$to['display_name'].' استلم التكليف وبدأ تنفيذه.']
        };
        self::post('system','dispatcher','team',(string)($execution['summary']??'تم تمرير التكليف.'),'status',$projectId,(int)($execution['primary_task']??0)?:null,['from'=>$fromSlug,'to'=>$toSlug,'command_bus'=>1]);
        return $execution;
    }

    public static function taskAssigned(array $task,string $requestedBy,string $requestedId):void{
        $sender='ramy';
        if($requestedBy==='owner'){$type='owner';$sender='1';}
        elseif($requestedBy==='agent'){$type='agent';try{$a=AgentService::byId((int)$requestedId);$sender=(string)$a['slug'];}catch(Throwable){$sender='ramy';}}
        else{$type='system';$sender='system';}
        $to=(string)($task['agent_slug']??'team');
        self::post($type,$sender,$to,'تكليف #'.(int)$task['id'].': '.(string)$task['title']."\n".pb_substr((string)$task['description'],0,800),'task',$task['project_id']?(int)$task['project_id']:null,(int)$task['id']);
    }

    public static function taskResult(array $task,string $state,string $summary=''):void{
        $slug=(string)($task['agent_slug']??'system');
        $label=$state==='completed'||$state==='needs_review'?'خلصت':'حصلت مشكلة';
        $text='المهمة #'.(int)$task['id'].' '.$label.($summary!==''?' — '.pb_substr($summary,0,900):'');
        self::post('agent',$slug,'team',$text,'result',$task['project_id']?(int)$task['project_id']:null,(int)$task['id'],['state'=>$state]);
    }

    public static function bubble(array $m):string{
        $type=(string)($m['sender_type']??'system');
        $name=$type==='owner'?'أبانوب':($type==='agent'?((string)($m['agent_name']??'')?:AdminUi::label((string)($m['sender_ref']??'agent'))):'النظام');
        $cls=$type==='owner'?'owner':($type==='agent'?'agent':'system');
        $to=(string)($m['receiver_ref']??'team');
        $meta=AdminUi::date($m['created_at']??null).' · '.AdminUi::label((string)($m['message_kind']??'note'));
        if($to!=='team')$meta.=' · إلى '.AdminUi::label($to);
        if(!empty($m['project_id']))$meta.=' · مشروع #'.(int)$m['project_id'].(!empty($m['project_name'])?' '.(string)$m['project_name']:'');
        if(!empty($m['task_id']))$meta.=' · مهمة #'.(int)$m['task_id'].(!empty($m['task_title'])?' '.(string)$m['task_title']:'');
        return '<div class="msg '.e($cls).' team-msg"><div class="msg-head"><b>'.e($name).'</b><span>'.e($meta).'</span></div><div class="msg-body">'.nl2br(e((string)($m['body_text']??''))).'</div></div>';
    }
}
