<?php
declare(strict_types=1);
final class RetentionService {
    public static function run():array{
        $batch=max(20,min(1000,(int)setting('retention.batch_size','250')));$out=[];
        $rows=db()->query('SELECT * FROM data_retention_policies WHERE enabled=1 ORDER BY data_key')->fetchAll();
        foreach($rows as $p){$key=(string)$p['data_key'];try{$out[$key]=self::runPolicy($key,(int)$p['retention_days'],(bool)$p['archive_before_delete'],$batch);}catch(Throwable $e){$out[$key]=['state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,300)];}}
        return ['items'=>$out,'ran_at'=>now_utc()];
    }
    private static function runPolicy(string $key,int $days,bool $archive,int $limit):array{
        $days=max(1,$days);$spec=self::spec($key,$days);if(!$spec)return ['state'=>'skipped','reason'=>'unsupported_policy'];
        [$table,$idExpr,$createdExpr,$where,$params]=$spec;$sql="SELECT * FROM `$table` WHERE $where ORDER BY $createdExpr ASC LIMIT ".(int)$limit;$q=db()->prepare($sql);$q->execute($params);$rows=$q->fetchAll();if(!$rows)return ['state'=>'ok','matched'=>0,'archived'=>0,'deleted'=>0];
        $archived=0;$deleted=0;$pdo=db();$pdo->beginTransaction();try{
            foreach($rows as $row){$sourceId=self::sourceId($row,$idExpr);if($archive){$payload=Security::redactSecrets(j($row),200000);$created=self::createdValue($row,$createdExpr);$a=$pdo->prepare('INSERT IGNORE INTO system_archives(data_key,source_table,source_id,payload_json,source_created_at) VALUES (?,?,?,?,?)');$a->execute([$key,$table,$sourceId,$payload,$created?:null]);$archived+=(int)$a->rowCount();}}
            $ids=array_map(fn($r)=>self::sourceId($r,$idExpr),$rows);$deleted=self::deleteByIds($table,$idExpr,$ids,$pdo);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return ['state'=>'ok','matched'=>count($rows),'archived'=>$archived,'deleted'=>$deleted];
    }
    private static function spec(string $key,int $days):?array{
        $cut="DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".(int)$days." DAY)";
        return match($key){
            'worker_heartbeat_log'=>['worker_heartbeat_log','heartbeat_minute','last_seen_at',"last_seen_at < $cut",[]],
            'runtime_validation_steps'=>['runtime_validation_steps','id','created_at',"created_at < $cut",[]],
            'jobs_terminal'=>['jobs','id','created_at',"state IN ('done','failed','cancelled') AND COALESCE(finished_at,updated_at,created_at) < $cut",[]],
            'notifications_read'=>['notifications','id','created_at',"read_at IS NOT NULL AND created_at < $cut",[]],
            'agent_usage_ledger'=>['agent_usage_ledger','id','created_at',"created_at < $cut",[]],
            'audit_logs'=>['audit_logs','id','created_at',"created_at < $cut",[]],
            default=>null,
        };
    }
    private static function sourceId(array $row,string $idExpr):string{return (string)($row[$idExpr]??'');}
    private static function createdValue(array $row,string $createdExpr):?string{return isset($row[$createdExpr])?(string)$row[$createdExpr]:null;}
    private static function deleteByIds(string $table,string $idCol,array $ids,PDO $pdo):int{
        if(!$ids)return 0;$marks=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("DELETE FROM `$table` WHERE `$idCol` IN ($marks)");$q->execute($ids);return (int)$q->rowCount();
    }
}
