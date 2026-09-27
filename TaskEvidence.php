<?php
declare(strict_types=1);
final class TaskEvidence {
    public static function add(int $taskId,string $type,string $label,array $evidence=[],string $state='captured',?int $agentId=null):int{
        $task=TaskService::get($taskId);if(!in_array($state,['captured','verified','failed','warning'],true))$state='captured';
        $q=db()->prepare('INSERT INTO task_evidence(task_id,project_id,agent_id,evidence_type,label,state,evidence_json,verified_at) VALUES (?,?,?,?,?,?,?,?)');
        $q->execute([$taskId,$task['project_id']?(int)$task['project_id']:null,$agentId?:($task['assigned_agent_id']?(int)$task['assigned_agent_id']:null),pb_substr(trim($type),0,80),pb_substr(trim($label),0,220),$state,$evidence?j($evidence):null,$state==='verified'?now_utc():null]);
        return (int)db()->lastInsertId();
    }
    public static function verifiedCount(int $taskId):int{$q=db()->prepare("SELECT COUNT(*) FROM task_evidence WHERE task_id=? AND state='verified'");$q->execute([$taskId]);return (int)$q->fetchColumn();}
    public static function list(int $taskId):array{$q=db()->prepare('SELECT e.*,a.display_name agent_name FROM task_evidence e LEFT JOIN agents a ON a.id=e.agent_id WHERE e.task_id=? ORDER BY e.id DESC');$q->execute([$taskId]);return $q->fetchAll();}
    public static function assertCompletionEvidence(int $taskId,array $inlineEvidence=[]):void{
        if(setting('workflow.require_task_evidence','1')!=='1')return;
        $task=TaskService::get($taskId);if(!in_array((string)$task['agent_slug'],['ayman','emad','walid'],true))return;
        if(self::verifiedCount($taskId)>0)return;
        if($inlineEvidence){self::add($taskId,'execution','دليل تنفيذ موثق من المهمة',$inlineEvidence,'verified');return;}
        throw new RuntimeException('task_evidence_required');
    }
}
