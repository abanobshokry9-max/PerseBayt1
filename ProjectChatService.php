<?php
declare(strict_types=1);
final class ProjectChatService {
    public static function post(int $projectId,string $senderType,string $senderRef,string $body,string $kind='message',?string $sourceType=null,?string $sourceId=null,bool $pinned=false,array $meta=[]):int{
        ProjectService::get($projectId);$body=trim($body);if($body==='')throw new RuntimeException('message_required');if(!in_array($senderType,['owner','agent','system'],true))$senderType='system';$allowed=['message','master_brief','client_update','handoff','qa','decision','system'];if(!in_array($kind,$allowed,true))$kind='message';
        $q=db()->prepare('INSERT INTO project_chat_messages(project_id,sender_type,sender_ref,message_kind,body_text,source_type,source_id,pinned,metadata_json) VALUES (?,?,?,?,?,?,?,?,?)');$q->execute([$projectId,$senderType,$senderRef?:null,$kind,$body,$sourceType,$sourceId,$pinned?1:0,$meta?j($meta):null]);$id=(int)db()->lastInsertId();
        Audit::log($senderType==='owner'?'owner':($senderType==='agent'?'agent':'system'),$senderRef?:'system','project_chat.post','project',(string)$projectId,'verified',$projectId,null,['kind'=>$kind,'message_id'=>$id]);return $id;
    }
    public static function recent(int $projectId,int $limit=80):array{$q=db()->prepare("SELECT c.*,a.display_name agent_name FROM project_chat_messages c LEFT JOIN agents a ON c.sender_type='agent' AND a.slug=c.sender_ref WHERE c.project_id=? ORDER BY c.id DESC LIMIT ".max(1,min(300,$limit)));$q->execute([$projectId]);return array_reverse($q->fetchAll());}
    public static function pinMasterBrief(int $projectId,string $body,int $briefId):int{return self::post($projectId,'agent','ramy',$body,'master_brief','project_brief',(string)$briefId,true);}
}
