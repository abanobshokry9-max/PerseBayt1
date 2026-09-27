<?php
declare(strict_types=1);
final class BackupService {
    public static function rollbackTask(int $taskId):array{
        $task=TaskService::get($taskId);$projectId=(int)($task['project_id']??0);if($projectId<1)throw new RuntimeException('rollback_project_missing');
        $q=db()->prepare("SELECT * FROM deployments WHERE task_id=? ORDER BY id DESC LIMIT 1");$q->execute([$taskId]);$dep=$q->fetch();if(!$dep)throw new RuntimeException('rollback_deployment_missing');
        $e=json_decode((string)($dep['evidence_json']??'{}'),true);if(!is_array($e))$e=[];$changes=(array)($e['files_changed']??[]);$dbEvidence=$e['database']??null;
        if(!$changes&&!is_array($dbEvidence))throw new RuntimeException('rollback_evidence_missing');
        $out=['task_id'=>$taskId,'project_id'=>$projectId,'deployment_id'=>(int)$dep['id'],'restored'=>0,'failed'=>0,'not_restorable_created'=>[],'database_restore_required'=>false,'errors'=>[]];
        foreach(array_reverse($changes) as $c){if(!is_array($c)||empty($c['changed']))continue;$path=(string)($c['path']??'');if($path==='')continue;if(!empty($c['created'])){$out['not_restorable_created'][]=$path;continue;}if(empty($c['backup'])){$out['failed']++;$out['errors'][]=$path.': backup reference missing';continue;}try{HostingerClient::restoreFileBackup($projectId,$taskId,$path,(string)$c['backup']);$out['restored']++;}catch(Throwable $ex){$out['failed']++;$out['errors'][]=$path.': '.pb_substr($ex->getMessage(),0,140);}}
        if(is_array($dbEvidence)&&!empty($dbEvidence['changed'])){$out['database_restore_required']=true;$out['database_backup']=$dbEvidence['backup']??null;}
        $complete=$out['failed']===0&&!$out['not_restorable_created']&&!$out['database_restore_required'];
        $state=$complete?'rolled_back':'failed';
        db()->prepare("INSERT INTO deployments(project_id,task_id,state,version_ref,before_hash,after_hash,evidence_json,verified_at) VALUES (?,?,?,'owner-rollback',?,?,?,?)")->execute([$projectId,$taskId,$state,$dep['after_hash']??null,$dep['before_hash']??null,j(['rollback'=>$out]),$complete?now_utc():null]);
        $actor=Audit::actor();TaskService::event($taskId,(string)$actor['type'],(string)$actor['id'],'rollback',$task['status'],$task['status'],['complete'=>$complete],$out);
        Audit::log('owner','1','task.rollback','task',(string)$taskId,$complete?'verified':'failed',$projectId,$taskId,$out);
        if($complete)Notifications::add('success','deployments','تم إرجاع ملفات المهمة','تمت استعادة '.(int)$out['restored'].' ملف من النسخ المتحققة للمهمة #'.$taskId.'.','task',(string)$taskId);
        else Notifications::add('critical','deployments','الرجوع للنسخة محتاج تدخل إضافي','تمت استعادة ما أمكن للمهمة #'.$taskId.'، لكن توجد أجزاء لا يمكن استعادتها تلقائيًا بأمان.','task',(string)$taskId);
        return $out+['complete'=>$complete];
    }
    public static function createProjectDatabaseBackups(int $projectId):array{
        ProjectService::get($projectId);$q=db()->prepare("SELECT id,db_name FROM project_databases WHERE project_id=? AND status='active' ORDER BY id");$q->execute([$projectId]);$rows=$q->fetchAll();if(!$rows)throw new RuntimeException('project_database_missing');$done=[];$failed=[];foreach($rows as $r){try{if(!ProjectDatabaseClient::configured((int)$r['id'])){$failed[]=['database'=>$r['db_name'],'error'=>'credentials_not_configured'];continue;}$done[]=['database'=>$r['db_name']]+ProjectDatabaseClient::backup((int)$r['id'],0);}catch(Throwable $e){$failed[]=['database'=>$r['db_name'],'error'=>pb_substr($e->getMessage(),0,160)];}}
        Audit::log('owner','1','project.database_backup','project',(string)$projectId,$failed?'failed':'verified',$projectId,null,['created'=>count($done),'failed'=>$failed]);
        if($done)db()->prepare('UPDATE projects SET last_backup_at=NOW() WHERE id=?')->execute([$projectId]);
        return ['created'=>$done,'failed'=>$failed,'complete'=>count($done)>0&&count($failed)===0];
    }
}
