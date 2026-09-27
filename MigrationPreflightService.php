<?php
declare(strict_types=1);
/** Company OS 20 upgrade preflight. Supports 17/19 directly and older unified bases without data loss. */
final class MigrationPreflightService {
    private const ACCEPTED_SCHEMAS=['11.0','11.1','13.0','14.0','17.0','19.0','20.0',''];
    private const CORE_TABLES=['agents','settings','providers','tasks','projects','audit_logs','backups','agent_capability_catalog','agent_capability_assignments','provider_capability_routes','release_history'];
    private const CORE_COLUMNS=[
        'agents'=>['id','slug','system_prompt','provider_key','model','is_active'],
        'agent_capability_assignments'=>['agent_id','capability_key','enabled'],
        'provider_capability_routes'=>['capability','route_order','provider_key','health_state'],
    ];
    public static function check(bool $forUpgrade=true):array{
        $checks=[];$warnings=[];
        $add=static function(string $key,bool $ok,string $note,bool $required=true)use(&$checks,&$warnings):void{
            $checks[$key]=['ok'=>$ok,'note'=>$note,'required'=>$required];if(!$ok&&!$required)$warnings[]=$key;
        };
        $add('php_version',version_compare(PHP_VERSION,'8.1.0','>='),'PHP '.PHP_VERSION.' (required >= 8.1)');
        foreach(['pdo','pdo_mysql','curl','mbstring','openssl','json','fileinfo'] as $ext)$add('ext_'.$ext,extension_loaded($ext),$ext.(extension_loaded($ext)?' available':' missing'));
        $pdo=null;$db='';$dbSize=0;$server='';$schema='';
        try{
            $pdo=db();$db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$server=(string)$pdo->query('SELECT VERSION()')->fetchColumn();$add('database_connection',$db!=='',$db.' @ '.$server);
            preg_match('/(\d+\.\d+(?:\.\d+)?)/',$server,$m);$det=(string)($m[1]??'0');$maria=stripos($server,'mariadb')!==false;$compatible=$maria?version_compare($det,'10.5.0','>='):version_compare($det,'8.0.0','>=');$add('database_compatibility',$compatible,'Detected '.$server.'; required MariaDB >= 10.5 or MySQL >= 8.0');
            $schema=(string)setting('system.schema',setting('companyos.schema_version',''));
            $add('schema_version',!$forUpgrade||in_array($schema,self::ACCEPTED_SCHEMAS,true),'current '.($schema?:'unknown').' (accepted 11.0/11.1/13.0/14.0/17.0/19.0; 20.0 verify/resume)');
            $tables=self::tables($pdo,$db);$missing=[];foreach(self::CORE_TABLES as $t)if(!isset($tables[$t]))$missing[]=$t;$add('base_schema',$missing===[],$missing?'Missing core tables: '.implode(', ',$missing):'Compatible Company OS core tables found');
            foreach(self::CORE_COLUMNS as $t=>$cols){$have=self::columns($pdo,$db,$t);$miss=[];foreach($cols as $c)if(!isset($have[$c]))$miss[]=$c;$add('columns_'.$t,$miss===[],$miss?'Missing '.$t.' columns: '.implode(', ',$miss):$t.' core columns found');}
            $expected=['agent_policy_rules','agent_policy_decisions','agent_memory_bank','agent_usage_daily','agent_usage_events','agent_goal_contributions','creator_intelligence_snapshots','resource_registry','physical_schema_requests','agent_workflow_templates','agent_workflow_steps','agent_workflow_runs','voice_conversations','data_source_registry'];
            $present=0;foreach($expected as $t)if(isset($tables[$t]))$present++;$add('unified_features_present',true,$present.'/'.count($expected).' advanced tables already present; migration will create/repair the rest',false);
            try{$q=$pdo->prepare('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');$q->execute([$db]);$dbSize=(int)$q->fetchColumn();$add('database_size',true,self::bytes($dbSize));}catch(Throwable $e){$add('database_size',false,Security::redactSecrets($e->getMessage(),180),false);}
        }catch(Throwable $e){$add('database_connection',false,Security::redactSecrets($e->getMessage(),220));}
        $runtime=PB_ROOT.'/private/runtime';$add('runtime_writable',is_dir($runtime)&&is_writable($runtime),$runtime);
        $free=@disk_free_space($runtime?:PB_ROOT);$need=max(200*1024*1024,(int)ceil($dbSize*2.75));$add('disk_space',$free!==false&&$free>$need,'free '.self::bytes((int)($free?:0)).' / required '.self::bytes($need));
        $requiredFailures=array_keys(array_filter($checks,static fn($x)=>!$x['ok']&&$x['required']));
        return ['ok'=>$requiredFailures===[],'checks'=>$checks,'warnings'=>$warnings,'failures'=>$requiredFailures,'database'=>$db,'server'=>$server,'schema'=>$schema,'database_size'=>$dbSize,'required_disk_bytes'=>$need,'checked_at'=>now_utc()];
    }
    public static function run(bool $requireBackup=true):array{return self::assertSafe($requireBackup);}
    public static function assertSafe(bool $requireBackup=true):array{
        $r=self::check(true);if(!$r['ok'])throw new RuntimeException('migration_preflight_failed:'.implode(',',(array)$r['failures']));
        if($requireBackup){$last=(string)setting('migration.last_backup_at','');$valid=$last!==''&&utc_ts($last)!==false&&utc_ts($last)>time()-24*3600;if(!$valid)$r['backup']=self::createBackup('pre_20_0');else $r['backup']=['reused'=>true,'ref'=>(string)setting('migration.last_backup_ref',''),'sha256'=>(string)setting('migration.last_backup_sha256',''),'created_at'=>$last];}
        return $r;
    }
    public static function createBackup(string $label='pre_20_0'):array{
        $pre=self::check(true);if(!$pre['ok'])throw new RuntimeException('migration_preflight_failed');$pdo=db();$db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$dir=PB_ROOT.'/private/runtime/database-backups/'.gmdate('Ymd');if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('migration_backup_directory_failed');
        $name=preg_replace('/[^a-z0-9_.-]/i','_',$label).'_'.gmdate('Ymd_His').'_'.substr(hash('sha256',microtime(true).'|'.$db),0,10).'.sql';$path=$dir.'/'.$name;$fh=fopen($path,'wb');if(!$fh)throw new RuntimeException('migration_backup_open_failed');$write=static function(string $s)use($fh):void{if(fwrite($fh,$s)===false)throw new RuntimeException('migration_backup_write_failed');};
        try{$write('-- PerseBayt database backup before Company OS 20 migration '.now_utc()."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");$tables=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);foreach($tables as $table){$qt='`'.str_replace('`','``',(string)$table).'`';$cr=$pdo->query('SHOW CREATE TABLE '.$qt)->fetch(PDO::FETCH_ASSOC);$create=(string)($cr['Create Table']??array_values($cr)[1]??'');if($create==='')throw new RuntimeException('migration_backup_create_missing:'.$table);$write("\nDROP TABLE IF EXISTS {$qt};\n{$create};\n");$st=$pdo->query('SELECT * FROM '.$qt);$batch=[];$cols=null;while($row=$st->fetch(PDO::FETCH_ASSOC)){if($cols===null)$cols=array_keys($row);$vals=[];foreach($row as $v)$vals[]=$v===null?'NULL':$pdo->quote((string)$v);$batch[]='('.implode(',',$vals).')';if(count($batch)>=100){$write('INSERT INTO '.$qt.' (`'.implode('`,`',array_map(static fn($c)=>str_replace('`','``',(string)$c),$cols)).'`) VALUES '.implode(',',$batch).";\n");$batch=[];}}if($batch&&$cols)$write('INSERT INTO '.$qt.' (`'.implode('`,`',array_map(static fn($c)=>str_replace('`','``',(string)$c),$cols)).'`) VALUES '.implode(',',$batch).";\n");}$write("SET FOREIGN_KEY_CHECKS=1;\n");}finally{fclose($fh);} @chmod($path,0600);$hash=hash_file('sha256',$path);$ref=str_replace(PB_ROOT.'/private/runtime/','',$path);put_setting('migration.last_backup_ref',$ref);put_setting('migration.last_backup_sha256',$hash);put_setting('migration.last_backup_at',now_utc());try{Audit::log('owner',(string)(Auth::user()['id']??1),'migration.backup','database',$db,'verified',null,null,['ref'=>$ref,'sha256'=>$hash,'size'=>filesize($path)]);}catch(Throwable){}return ['ref'=>$ref,'sha256'=>$hash,'size'=>filesize($path),'database'=>$db];
    }
    private static function tables(PDO $p,string $db):array{$q=$p->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');$q->execute([$db]);return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);}
    private static function columns(PDO $p,string $db,string $t):array{$q=$p->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$db,$t]);return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);}
    private static function bytes(int $v):string{$u=['B','KB','MB','GB','TB'];$i=0;$x=(float)$v;while($x>=1024&&$i<count($u)-1){$x/=1024;$i++;}return round($x,2).' '.$u[$i];}
}
