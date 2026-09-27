<?php
declare(strict_types=1);
/**
 * Creates/returns an isolated database for project staging. Production credentials are never sent to AI.
 * Creation prefers dedicated staging DB admin credentials from the encrypted vault. If unavailable,
 * a configured production DB user may be used only when that DB user has CREATE DATABASE privilege.
 */
final class StagingDatabaseService {
    public static function ensureForProject(int $projectId,int $sourceDbId=0):array{
        if($projectId<1)throw new RuntimeException('project_required');
        $q=db()->prepare("SELECT * FROM project_databases WHERE project_id=? AND environment='staging' AND status<>'removed' ORDER BY id DESC LIMIT 1");$q->execute([$projectId]);$existing=$q->fetch();
        if($existing && ProjectDatabaseClient::configured((int)$existing['id']))return $existing+['created'=>false];

        $source=null;
        if($sourceDbId>0){$q=db()->prepare("SELECT * FROM project_databases WHERE id=? AND project_id=? AND environment='production' AND status<>'removed'");$q->execute([$sourceDbId,$projectId]);$source=$q->fetch()?:null;}
        if(!$source){$q=db()->prepare("SELECT * FROM project_databases WHERE project_id=? AND environment='production' AND status<>'removed' ORDER BY CASE status WHEN 'active' THEN 0 ELSE 1 END,id LIMIT 1");$q->execute([$projectId]);$source=$q->fetch()?:null;}

        $host=trim((string)config('connections.staging_db.host',''));
        $port=(int)config('connections.staging_db.port',3306);if($port<1||$port>65535)$port=3306;
        $user=trim((string)config('connections.staging_db.user',''));
        $pass=(string)config('connections.staging_db.password','');
        if(($host===''||$user===''||$pass==='') && $source){
            $host=trim((string)($source['db_host']?:'localhost'));$port=(int)($source['db_port']?:3306);$user=trim((string)$source['db_user']);
            $pass=(string)config('connections.project_db.'.(int)$source['id'].'.password','');
        }
        if($host===''||$user===''||$pass==='')throw new RuntimeException('staging_database_admin_credentials_required');

        $prefix=preg_replace('/[^a-z0-9_]/i','_',trim((string)config('connections.staging_db.prefix','pb_stg'))?:'pb_stg');
        $dbName=substr($prefix.'_p'.$projectId.'_'.substr(hash('sha256',(string)$projectId),0,6),0,64);
        $server=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $server->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`','``',$dbName).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        if($existing){
            $id=(int)$existing['id'];db()->prepare("UPDATE project_databases SET db_name=?,db_user=?,db_host=?,db_port=?,source_database_id=?,status='active',connection_state='unconfigured',last_seen_at=NOW() WHERE id=?")->execute([$dbName,$user,$host,$port,$source['id']??null,$id]);
        }else{
            db()->prepare("INSERT INTO project_databases(project_id,db_name,db_user,db_host,db_port,status,connection_state,last_seen_at,environment,source_database_id,metadata_json) VALUES (?,?,?,?,?,'active','unconfigured',NOW(),'staging',?,?)")->execute([$projectId,$dbName,$user,$host,$port,$source['id']??null,j(['managed_by'=>'StagingDatabaseService','created_at'=>now_utc()])]);$id=(int)db()->lastInsertId();
        }
        SecretVault::save(['connections.project_db.'.$id.'.password'=>$pass]);
        $clone=['tables'=>0,'rows'=>0,'mode'=>'blank'];
        if($source && ProjectDatabaseClient::configured((int)$source['id']))$clone=self::cloneSafe((int)$source['id'],$id);
        $test=ProjectDatabaseClient::test($id);if(empty($test['ok']))throw new RuntimeException('staging_database_verification_failed');
        Audit::log('system','staging','staging_database.ensure','project_database',(string)$id,'verified',$projectId,null,['source_database_id'=>$source['id']??null,'clone'=>$clone]);
        return self::row($id)+['created'=>true,'clone'=>$clone];
    }

    private static function cloneSafe(int $sourceId,int $targetId):array{
        $src=ProjectDatabaseClient::connection($sourceId);$dst=ProjectDatabaseClient::connection($targetId);$tables=$src->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        if(count($tables)>160)throw new RuntimeException('staging_clone_table_limit');
        $dst->exec('SET FOREIGN_KEY_CHECKS=0');$tableCount=0;$rowCount=0;
        try{
            foreach($tables as $row){$table=(string)($row[0]??'');if($table==='')continue;$quoted='`'.str_replace('`','``',$table).'`';$cr=$src->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_ASSOC);$create=(string)($cr['Create Table']??array_values($cr)[1]??'');if($create==='')continue;
                $dst->exec('DROP TABLE IF EXISTS '.$quoted);$dst->exec($create);$tableCount++;
                $st=$src->query('SELECT * FROM '.$quoted);$cols=null;$insert=null;
                while($r=$st->fetch(PDO::FETCH_ASSOC)){$rowCount++;if($rowCount>50000)throw new RuntimeException('staging_clone_row_limit');if($cols===null){$names=array_keys($r);$cols='`'.implode('`,`',array_map(static fn($x)=>str_replace('`','``',(string)$x),$names)).'`';$insert=$dst->prepare('INSERT INTO '.$quoted.' ('.$cols.') VALUES ('.implode(',',array_fill(0,count($names),'?')).')');}$insert->execute(array_values($r));}
            }
        }finally{$dst->exec('SET FOREIGN_KEY_CHECKS=1');}
        return ['tables'=>$tableCount,'rows'=>$rowCount,'mode'=>'schema_and_data'];
    }
    private static function row(int $id):array{$q=db()->prepare('SELECT * FROM project_databases WHERE id=?');$q->execute([$id]);return $q->fetch()?:[];}
}
