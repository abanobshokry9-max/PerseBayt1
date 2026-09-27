<?php
declare(strict_types=1);

final class SystemDatabaseRepair {
    private static function qi(string $s):string {
        if($s===''||str_contains($s,'`'))throw new RuntimeException('invalid_identifier');
        return '`'.str_replace('`','``',$s).'`';
    }

    private static function sqlValue(PDO $pdo,$v):string {
        if($v===null)return 'NULL';
        if(is_bool($v))return $v?'1':'0';
        $s=(string)$v;
        if($s!==''&&!preg_match('//u',$s))return '0x'.bin2hex($s);
        return $pdo->quote($s);
    }

    public static function backup(int $repairRunId=0,int $maxBytes=83886080):array {
        $pdo=db();$dir=PB_ROOT.'/private/runtime/system-db-backups/'.date('Ymd');
        if(!is_dir($dir))mkdir($dir,0700,true);
        $name='companyos-r'.$repairRunId.'-'.date('His').'-'.bin2hex(random_bytes(4)).'.sql';$path=$dir.'/'.$name;
        $fh=fopen($path,'wb');if(!$fh)throw new RuntimeException('system_database_backup_open_failed');@chmod($path,0600);
        $written=0;$write=function(string $x)use($fh,&$written,$maxBytes){$written+=strlen($x);if($written>$maxBytes)throw new RuntimeException('system_database_backup_size_limit');if(fwrite($fh,$x)===false)throw new RuntimeException('system_database_backup_write_failed');};
        try{
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $objects=$pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);$tables=[];$views=[];
            foreach($objects as $obj){$n=(string)($obj[0]??'');$type=strtoupper((string)($obj[1]??'BASE TABLE'));if($n==='')continue;if($type==='VIEW')$views[]=$n;else $tables[]=$n;}
            foreach($tables as $table){
                $cr=$pdo->query('SHOW CREATE TABLE '.self::qi($table))->fetch();$create=(string)($cr['Create Table']??array_values($cr)[1]??'');if($create==='')throw new RuntimeException('system_database_backup_create_missing');
                $write("DROP TABLE IF EXISTS ".self::qi($table).";\n".$create.";\n");$st=$pdo->query('SELECT * FROM '.self::qi($table));
                while($row=$st->fetch(PDO::FETCH_ASSOC)){$cols=array_map(fn($c)=>self::qi((string)$c),array_keys($row));$vals=array_map(fn($v)=>self::sqlValue($pdo,$v),array_values($row));$write('INSERT INTO '.self::qi($table).' ('.implode(',',$cols).') VALUES ('.implode(',',$vals).');'."\n");}
                $write("\n");
            }
            foreach($views as $view){$cr=$pdo->query('SHOW CREATE VIEW '.self::qi($view))->fetch();$create=(string)($cr['Create View']??array_values($cr)[1]??'');if($create==='')continue;$create=preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/i','',$create);$write("DROP VIEW IF EXISTS ".self::qi($view).";\n".$create.";\n\n");}
            $write("SET FOREIGN_KEY_CHECKS=1;\n");fclose($fh);
            $sha=hash_file('sha256',$path);$ref='system-db-backups/'.date('Ymd').'/'.$name;
            Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'system_database.backup','database','company_os','verified',null,null,['repair_run_id'=>$repairRunId,'bytes'=>$written,'sha256'=>$sha,'location'=>$ref]);
            return ['bytes'=>$written,'sha256'=>$sha,'location'=>$ref];
        }catch(Throwable $e){if(is_resource($fh))fclose($fh);@unlink($path);throw $e;}
    }

    private static function normalizeStatement(string $sql):string {
        $sql=trim(rtrim(trim($sql),"; \t\r\n"));
        if($sql===''||str_contains($sql,';'))throw new RuntimeException('multiple_sql_statements_not_allowed');
        if(preg_match('~(?:--|/\*|\*/|\#)~',$sql))throw new RuntimeException('sql_comments_not_allowed');
        if(preg_match('/\b(DROP|TRUNCATE|RENAME|GRANT|REVOKE|CREATE\s+USER|ALTER\s+USER|LOAD\s+DATA|INTO\s+OUTFILE|DELETE\s+FROM|REPLACE\s+INTO|CALL|DO\s+SLEEP)\b/i',$sql))throw new RuntimeException('destructive_sql_blocked');
        if(!preg_match('/^(?:CREATE\s+TABLE|CREATE\s+(?:UNIQUE\s+)?INDEX|ALTER\s+TABLE|INSERT\s+INTO|UPDATE\s+)/i',$sql))throw new RuntimeException('sql_operation_not_allowed');
        if(preg_match('/^ALTER\s+TABLE/i',$sql)&&(!preg_match('/\bADD\b/i',$sql)||preg_match('/\b(?:DROP|CHANGE|MODIFY|RENAME)\b/i',$sql)))throw new RuntimeException('alter_only_add_allowed');
        if(preg_match('/^UPDATE\s+/i',$sql)&&!preg_match('/\bWHERE\b/i',$sql))throw new RuntimeException('update_requires_where');
        if(preg_match('/\b(?:users|audit_logs|backups)\b/i',$sql))throw new RuntimeException('protected_system_table');
        return $sql;
    }

    public static function executePlan(int $repairRunId,array $statements):array {
        if(!$statements)return ['changed'=>false,'backup'=>null,'statements'=>[]];
        $ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));$rid=(int)$ramy['id'];
        Permissions::requireAgent($rid,'database.write');Permissions::requireAgent($rid,'backup.create');AgentService::requireTool($rid,'database');
        $clean=[];
        foreach(array_slice($statements,0,8) as $s){$sql=is_array($s)?(string)($s['sql']??''):(string)$s;$sql=self::normalizeStatement($sql);$schema=(bool)preg_match('/^(?:CREATE|ALTER)/i',$sql);if($schema)Permissions::requireAgent($rid,'database.schema');$clean[]=['sql'=>$sql,'reason'=>is_array($s)?pb_substr((string)($s['reason']??''),0,500):''];}
        if(!$clean)return ['changed'=>false,'backup'=>null,'statements'=>[]];
        $backup=self::backup($repairRunId);$pdo=db();$results=[];$done=0;
        try{
            foreach($clean as $x){$affected=$pdo->exec($x['sql']);$done++;$hash=hash('sha256',$x['sql']);$results[]=['operation'=>strtoupper((string)strtok($x['sql'],' ')),'affected'=>$affected,'hash'=>$hash,'reason'=>$x['reason']];Audit::log('agent',(string)$rid,'system_database.statement','database','company_os','verified',null,null,['repair_run_id'=>$repairRunId,'operation'=>strtoupper((string)strtok($x['sql'],' ')),'affected'=>$affected,'sql_hash'=>$hash,'backup'=>$backup['location']]);}
        }catch(Throwable $e){Audit::log('agent',(string)$rid,'system_database.plan','database','company_os','failed',null,null,['repair_run_id'=>$repairRunId,'completed'=>$done,'total'=>count($clean),'backup'=>$backup['location'],'error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,300)]);throw new RuntimeException('system_database_plan_partial_failure:backup='.$backup['location'].':completed='.$done.':'.$e->getMessage(),0,$e);}
        return ['changed'=>true,'backup'=>$backup,'statements'=>$results];
    }
}
