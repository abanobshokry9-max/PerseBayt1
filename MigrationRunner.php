<?php
declare(strict_types=1);
/** Resume-safe Company OS 20 superset migration runner. */
final class MigrationRunner {
    private const VERSION='20.0';
    private const MIGRATION_ID='companyos_20_0_final_unified';
    private const DEFAULT_SQL='/database/upgrade_companyos_17_19_to_20_0_final_unified.sql';

    public static function upgrade20(?string $path=null,bool $requireBackup=true):array{return self::run(self::VERSION,self::MIGRATION_ID,$path?:PB_ROOT.self::DEFAULT_SQL,$requireBackup);}
    /** Compatibility entry points: the final supported target is Company OS 20. */
    public static function upgrade19(?string $path=null,bool $requireBackup=true):array{return self::upgrade20($path,$requireBackup);}
    public static function upgrade17(?string $path=null,bool $requireBackup=true):array{return self::upgrade20($path,$requireBackup);}
    public static function upgrade14(?string $path=null,bool $requireBackup=true):array{return self::upgrade20($path,$requireBackup);}
    public static function status(string $version=self::VERSION):array{self::ensureLedger();$q=db()->prepare('SELECT * FROM schema_migration_steps WHERE migration_version=? ORDER BY step_order,id');$q->execute([$version]);return $q->fetchAll();}

    private static function run(string $version,string $migrationId,string $path,bool $requireBackup):array{
        if(!is_file($path))throw new RuntimeException('migration_file_missing');
        $pre=MigrationPreflightService::assertSafe($requireBackup);self::ensureLedger();$sql=(string)file_get_contents($path);if(trim($sql)==='')throw new RuntimeException('migration_file_empty');$steps=self::splitSteps($sql);if(count($steps)<20)throw new RuntimeException('migration_steps_missing_or_incomplete');
        $out=[];foreach($steps as $id=>$segment)$out[]=self::runStep($version,$id,$segment);
        try{PromptCanonicalizerService::applyAll();}catch(Throwable $e){error_log('PerseBayt canonical prompts 20: '.Security::redactSecrets($e->getMessage(),180));}
        try{ProviderCapabilityRouter::syncFromProviders();}catch(Throwable $e){error_log('PerseBayt provider sync 20: '.Security::redactSecrets($e->getMessage(),180));}
        $post=SchemaPreflightService::verify();if(empty($post['ok']))throw new RuntimeException('post_migration_schema_verification_failed:'.implode(',',array_slice((array)$post['issues'],0,16)));
        $checksum=hash_file('sha256',$path);db()->prepare("INSERT INTO schema_migrations(migration_id,version,checksum_sha256,state,details_json,applied_at,verified_at) VALUES (?,?,?,'verified',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE version=VALUES(version),checksum_sha256=VALUES(checksum_sha256),state='verified',details_json=VALUES(details_json),applied_at=NOW(),verified_at=NOW()")
            ->execute([$migrationId,$version,$checksum,j(['runner'=>'MigrationRunner','steps'=>array_keys($steps),'verified_at'=>now_utc(),'resume_safe'=>true,'superset'=>['17','19']])]);
        put_setting('system.version',ReleaseInfo::VERSION);put_setting('system.schema',$version);put_setting('companyos.version',ReleaseInfo::VERSION);put_setting('companyos.schema_version',$version);
        return ['ok'=>true,'version'=>$version,'checksum'=>$checksum,'preflight'=>$pre,'steps'=>$out,'postflight'=>$post];
    }
    private static function runStep(string $version,string $id,string $segment):array{
        $q=db()->prepare('SELECT state,checksum_sha256 FROM schema_migration_steps WHERE migration_version=? AND step_id=? LIMIT 1');$q->execute([$version,$id]);$old=$q->fetch();$sum=hash('sha256',$segment);if($old&&$old['state']==='verified'&&hash_equals((string)$old['checksum_sha256'],$sum))return ['step'=>$id,'state'=>'already_verified','checksum'=>$sum];
        $order=self::stepOrder($id);db()->prepare("INSERT INTO schema_migration_steps(migration_version,step_id,step_order,checksum_sha256,state,started_at,attempts) VALUES (?,?,?,?,'running',NOW(),1) ON DUPLICATE KEY UPDATE step_order=VALUES(step_order),checksum_sha256=VALUES(checksum_sha256),state='running',started_at=NOW(),finished_at=NULL,last_error=NULL,attempts=attempts+1")->execute([$version,$id,$order,$sum]);
        try{$count=0;foreach(self::statements($segment) as $stmt){$trim=trim($stmt);if($trim==='')continue;db()->exec($trim);$count++;}self::verifyStep($id);db()->prepare("UPDATE schema_migration_steps SET state='verified',statement_count=?,finished_at=NOW(),last_error=NULL WHERE migration_version=? AND step_id=?")->execute([$count,$version,$id]);return ['step'=>$id,'state'=>'verified','statements'=>$count,'checksum'=>$sum];}
        catch(Throwable $e){$safe=Security::redactSecrets($e->getMessage(),650);db()->prepare("UPDATE schema_migration_steps SET state='failed',finished_at=NOW(),last_error=? WHERE migration_version=? AND step_id=?")->execute([$safe,$version,$id]);throw new RuntimeException('migration_step_failed:'.$id.':'.$safe,0,$e);}
    }
    private static function ensureLedger():void{
        db()->exec("CREATE TABLE IF NOT EXISTS schema_migrations (migration_id VARCHAR(120) NOT NULL,version VARCHAR(40) NOT NULL,checksum_sha256 CHAR(64) DEFAULT NULL,state ENUM('applied','verified','failed') NOT NULL DEFAULT 'applied',details_json LONGTEXT DEFAULT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,verified_at DATETIME DEFAULT NULL,PRIMARY KEY(migration_id),KEY idx_schema_migrations_version(version,state)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS schema_migration_steps (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,migration_version VARCHAR(40) NOT NULL,step_id VARCHAR(120) NOT NULL,step_order INT UNSIGNED NOT NULL DEFAULT 0,checksum_sha256 CHAR(64) NOT NULL,state ENUM('pending','running','verified','failed') NOT NULL DEFAULT 'pending',statement_count INT UNSIGNED NOT NULL DEFAULT 0,attempts INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME DEFAULT NULL,finished_at DATETIME DEFAULT NULL,last_error VARCHAR(700) DEFAULT NULL,PRIMARY KEY(id),UNIQUE KEY uq_schema_migration_step(migration_version,step_id),KEY idx_schema_migration_state(migration_version,state,step_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    private static function splitSteps(string $sql):array{$parts=preg_split('/^--\s*@step:\s*([a-zA-Z0-9_.-]+)\s*$/m',$sql,-1,PREG_SPLIT_DELIM_CAPTURE);if(!$parts||count($parts)<3)return [];$out=[];for($i=1;$i<count($parts);$i+=2){$id=trim((string)$parts[$i]);$segment=(string)($parts[$i+1]??'');if($id!==''&&trim($segment)!=='')$out[$id]=$segment;}return $out;}
    private static function statements(string $sql):array{
        $out=[];$buf='';$len=strlen($sql);$quote=null;$lineComment=false;$blockComment=false;
        for($i=0;$i<$len;$i++){$c=$sql[$i];$n=$i+1<$len?$sql[$i+1]:'';if($lineComment){if($c==="\n"){$lineComment=false;$buf.=$c;}continue;}if($blockComment){if($c==='*'&&$n==='/'){$blockComment=false;$i++;}continue;}if($quote===null){if($c==='-'&&$n==='-'&&($i+2>=$len||ctype_space($sql[$i+2]))){$lineComment=true;$i++;continue;}if($c==='#'){$lineComment=true;continue;}if($c==='/'&&$n==='*'){$blockComment=true;$i++;continue;}if($c==="'"||$c==='"'||$c==='`'){$quote=$c;$buf.=$c;continue;}if($c===';'){$out[]=trim($buf);$buf='';continue;}$buf.=$c;continue;}$buf.=$c;if($c==='\\'&&$quote!=='`'&&$i+1<$len){$buf.=$sql[++$i];continue;}if($c===$quote){if($i+1<$len&&$sql[$i+1]===$quote){$buf.=$sql[++$i];continue;}$quote=null;}}
        if(trim($buf)!=='')$out[]=trim($buf);return $out;
    }
    private static function verifyStep(string $id):void{
        $checks=match($id){
            '17_policy_superset'=>[['table','agent_policy_rules'],['table','agent_policy_decisions']],
            '17_workflow_superset'=>[['table','agent_workflow_steps'],['column','agent_workflow_runs','waiting_task_id']],
            '17_sources_and_vault'=>[['table','secure_vault_snapshots'],['table','secure_config_snapshots']],
            '19_provider_routes'=>[['provider_cap','embeddings'],['provider_cap','web_search'],['provider_cap','image'],['provider_cap','video'],['provider_cap','browser']],
            '20_superset_integrity'=>[['table','agent_policy_rules'],['table','agent_workflow_steps'],['table','secure_vault_snapshots'],['column','agent_policy_decisions','permission_key'],['column','agent_autonomy','auto_execute_max_risk']],
            '20_policy_and_workflow_canonical'=>[['policy','workflow.run'],['policy','production.*']],
            '20_prompt_canonicalization'=>[['prompt_clean20']],
            '20_data_sources_and_routes'=>[['source','agent_policy_rules','canonical'],['source','agent_workflow_steps','canonical'],['provider_cap','image'],['provider_cap','video'],['provider_cap','browser']],
            '20_runtime_validation_and_release'=>[['setting','system.schema','20.0'],['scenario','v20_core_acceptance']],
            default=>[]
        };
        foreach($checks as $c){
            if($c[0]==='table'&&!self::tableExists($c[1]))throw new RuntimeException('verify_missing_table:'.$c[1]);
            if($c[0]==='column'&&!self::columnExists($c[1],$c[2]))throw new RuntimeException('verify_missing_column:'.$c[1].'.'.$c[2]);
            if($c[0]==='setting'&&(string)setting($c[1],'')!==$c[2])throw new RuntimeException('verify_setting_failed:'.$c[1]);
            if($c[0]==='source'){$q=db()->prepare('SELECT status FROM data_source_registry WHERE source_key=?');$q->execute([$c[1]]);if((string)$q->fetchColumn()!==$c[2])throw new RuntimeException('verify_data_source_failed:'.$c[1]);}
            if($c[0]==='provider_cap'){$q=db()->prepare('SELECT COUNT(*) FROM provider_capability_routes WHERE capability=? AND enabled=1');$q->execute([$c[1]]);if((int)$q->fetchColumn()<1)throw new RuntimeException('verify_provider_capability_failed:'.$c[1]);}
            if($c[0]==='scenario'){$q=db()->prepare('SELECT enabled FROM runtime_validation_scenarios WHERE scenario_key=?');$q->execute([$c[1]]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('verify_scenario_failed:'.$c[1]);}
            if($c[0]==='policy'){$q=db()->prepare('SELECT enabled FROM agent_policy_rules WHERE action_pattern=?');$q->execute([$c[1]]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('verify_policy_failed:'.$c[1]);}
            if($c[0]==='prompt_clean20'){$q=db()->query("SELECT COUNT(*) FROM agents WHERE is_active=1 AND slug IN ('ramy','walid','ayman','emad','samir-social','video-director','basant','community-manager') AND (system_prompt LIKE '%socialpanelova.com%' OR system_prompt LIKE '%[CompanyOS 4.%' OR system_prompt LIKE '%[CompanyOS 5.%' OR system_prompt NOT LIKE '%Company OS 20.0%')");if((int)$q->fetchColumn()>0)throw new RuntimeException('verify_canonical_prompt_20_failed');}
        }
    }
    private static function tableExists(string $t):bool{$q=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$t]);return (int)$q->fetchColumn()>0;}
    private static function columnExists(string $t,string $c):bool{$q=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$t,$c]);return (int)$q->fetchColumn()>0;}
    private static function stepOrder(string $id):int{if(preg_match('/^(\d+)_/',$id,$m))return ((int)$m[1])*10+match(true){str_contains($id,'ledger')||str_contains($id,'integrity')||str_contains($id,'brain')=>1,str_contains($id,'policy')||str_contains($id,'creator')=>2,str_contains($id,'workflow')=>3,str_contains($id,'provider')||str_contains($id,'routes')=>4,str_contains($id,'prompt')=>5,str_contains($id,'source')||str_contains($id,'vault')=>6,str_contains($id,'runtime')||str_contains($id,'release')=>7,default=>9};return 999;}
}
