<?php
declare(strict_types=1);
final class ContextSnapshotService {
    public static function forOwnerSession(int $sessionId,int $historyLimit=50):array {
        $historyLimit=max(20,min(100,$historyLimit));
        $s=ConversationService::session($sessionId);
        $projectId=(int)($s['active_project_id']??0);$taskId=(int)($s['active_task_id']??0);
        $snapshot=['session'=>['id'=>$sessionId,'summary'=>(string)($s['context_summary']??''),'project_id'=>$projectId?:null,'task_id'=>$taskId?:null],'messages'=>[],'project'=>null,'task'=>null,'opportunity'=>null,'customer'=>null,'pending_action'=>null,'latest_walid'=>null,'latest_ayman'=>null,'latest_emad'=>null,'project_memory'=>[],'owner_preferences'=>[]];
        try{$snapshot['messages']=ConversationService::history($sessionId,$historyLimit);}catch(Throwable){}
        if($projectId){
            try{$snapshot['project']=ProjectService::get($projectId);}catch(Throwable){}
            try{$q=db()->prepare('SELECT * FROM opportunities WHERE project_id=? ORDER BY id DESC LIMIT 1');$q->execute([$projectId]);$snapshot['opportunity']=$q->fetch()?:null;}catch(Throwable){}
            try{$q=db()->prepare('SELECT c.* FROM customers c JOIN customer_projects cp ON cp.customer_id=c.id WHERE cp.project_id=? ORDER BY cp.created_at DESC,cp.customer_id DESC LIMIT 1');$q->execute([$projectId]);$snapshot['customer']=$q->fetch()?:null;}catch(Throwable){}
            try{$snapshot['project_memory']=MemoryService::project($projectId,40);}catch(Throwable){}
        }
        if($taskId){try{$snapshot['task']=TaskService::get($taskId);}catch(Throwable){}}
        try{$snapshot['pending_action']=ConversationService::pending($sessionId)?:null;}catch(Throwable){}
        foreach(['walid'=>2,'ayman'=>3,'emad'=>4] as $name=>$agentId){
            try{$q=db()->prepare('SELECT ar.*,t.title task_title,t.status task_status,t.project_id FROM agent_runs ar LEFT JOIN tasks t ON t.id=ar.task_id WHERE ar.agent_id=? ORDER BY ar.id DESC LIMIT 1');$q->execute([$agentId]);$snapshot['latest_'.$name]=$q->fetch()?:null;}catch(Throwable){}
        }
        try{$snapshot['owner_preferences']=MemoryService::company(30);}catch(Throwable){}
        return $snapshot;
    }
}
