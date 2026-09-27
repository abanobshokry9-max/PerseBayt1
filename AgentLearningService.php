<?php
declare(strict_types=1);
final class AgentLearningService {
    public static function propose(int $agentId,string $type,string $text,?int $projectId=null,?int $taskId=null,int $confidence=60):int{
        $text=trim($text);if($text==='')throw new RuntimeException('learning_text_required');MemoryService::assertSafeMemory($text);
        $allowed=['experience','procedure','owner_preference','quality_rule','search_preference','technical_pattern'];if(!in_array($type,$allowed,true))$type='experience';
        $q=db()->prepare("INSERT INTO agent_learning(agent_id,project_id,source_task_id,learning_type,learning_text,status,confidence) VALUES (?,?,?,?,?,'pending',?)");$q->execute([$agentId,$projectId,$taskId,$type,pb_substr($text,0,8000),max(0,min(100,$confidence))]);return (int)db()->lastInsertId();
    }
    public static function proposeAuto(int $agentId,string $type,string $text,?int $projectId=null,?int $taskId=null,int $confidence=60):int{
        Permissions::requireAgent($agentId,'learning.propose');$id=self::propose($agentId,$type,$text,$projectId,$taskId,$confidence);
        $safeTypes=['experience','procedure','quality_rule','search_preference','technical_pattern'];$auto=(string)setting('learning.safe_auto_memory','1')==='1';if($auto&&$confidence>=85&&in_array($type,$safeTypes,true)){try{self::approve($id);db()->prepare('UPDATE agent_learning SET owner_note=? WHERE id=?')->execute(['تم تحويل التعلم الآمن تلقائيًا إلى ذاكرة تشغيلية فقط؛ لم يتم تعديل System Prompt.',$id]);}catch(Throwable $e){error_log('ELMETR safe agent learning: '.Security::redactSecrets($e->getMessage(),180));}}return $id;
    }
    public static function get(int $id):array{$q=db()->prepare('SELECT * FROM agent_learning WHERE id=?');$q->execute([$id]);$l=$q->fetch();if(!$l)throw new RuntimeException('learning_not_found');return $l;}
    public static function listForAgent(int $agentId,int $limit=100):array{$q=db()->prepare('SELECT l.*,p.name project_name,t.title task_title FROM agent_learning l LEFT JOIN projects p ON p.id=l.project_id LEFT JOIN tasks t ON t.id=l.source_task_id WHERE l.agent_id=? ORDER BY FIELD(l.status,\'pending\',\'approved\',\'disabled\'),l.id DESC LIMIT '.max(1,min(300,$limit)));$q->execute([$agentId]);return $q->fetchAll();}
    private static function memoryType(array $l):string{return match($l['learning_type']){'owner_preference'=>'owner_preference','procedure','quality_rule'=>'procedure','search_preference','technical_pattern','experience'=>'experience',default=>'experience'};}
    public static function approve(int $id):void{$l=self::get($id);MemoryService::rememberAgent((int)$l['agent_id'],self::memoryType($l),(string)$l['learning_text'],'learning',(string)$id,80,$l['project_id']?(int)$l['project_id']:null);db()->prepare("UPDATE agent_learning SET status='approved',reviewed_at=NOW() WHERE id=?")->execute([$id]);}
    public static function edit(int $id,string $text,int $confidence,string $ownerNote=''):void{$l=self::get($id);$text=trim($text);if($text==='')throw new RuntimeException('learning_text_required');MemoryService::assertSafeMemory($text);db()->prepare('UPDATE agent_learning SET learning_text=?,confidence=?,owner_note=?,status=IF(status=\'disabled\',\'pending\',status),updated_at=NOW() WHERE id=?')->execute([pb_substr($text,0,8000),max(0,min(100,$confidence)),trim($ownerNote)?:null,$id]);}
    public static function promoteToInstruction(int $id):void{
        $l=self::get($id);$a=AgentService::byId((int)$l['agent_id']);$text=trim((string)$l['learning_text']);if($text==='')throw new RuntimeException('learning_text_required');MemoryService::assertSafeMemory($text);
        $prompt=(string)$a['system_prompt'];$marker='تعليمات ثابتة معتمدة من تعلم الوكيل:';$line='- '.$text;if(!str_contains($prompt,$line)){$prompt=rtrim($prompt)."\n\n".$marker."\n".$line;db()->prepare('UPDATE agents SET system_prompt=? WHERE id=?')->execute([$prompt,(int)$a['id']]);}
        MemoryService::rememberAgent((int)$a['id'],self::memoryType($l),$text,'learning_instruction',(string)$id,95,$l['project_id']?(int)$l['project_id']:null);db()->prepare("UPDATE agent_learning SET status='approved',reviewed_at=NOW(),promoted_to_instruction_at=NOW() WHERE id=?")->execute([$id]);Audit::log('owner','1','agent.learning_promoted','agent_learning',(string)$id,'verified',$l['project_id']?(int)$l['project_id']:null,$l['source_task_id']?(int)$l['source_task_id']:null,['agent_id'=>(int)$a['id']]);
    }
    public static function disable(int $id):void{self::get($id);db()->prepare("UPDATE agent_learning SET status='disabled',reviewed_at=NOW() WHERE id=?")->execute([$id]);}
    public static function delete(int $id):void{self::get($id);db()->prepare('DELETE FROM agent_learning WHERE id=?')->execute([$id]);}
}
