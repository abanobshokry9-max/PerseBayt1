<?php
declare(strict_types=1);

/**
 * Read-only deployment identity and recovery-readiness snapshot.
 * Never exposes secret values; only presence, hashes of code files and health metadata.
 */
final class DeploymentFingerprintService {
    private static function scalar(string $sql,array $params=[],mixed $default=null):mixed{
        try{$q=db()->prepare($sql);$q->execute($params);$v=$q->fetchColumn();return $v===false?$default:$v;}catch(Throwable){return $default;}
    }
    private static function row(string $sql,array $params=[]):array{
        try{$q=db()->prepare($sql);$q->execute($params);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:[];}catch(Throwable $e){return ['error'=>Security::redactSecrets($e->getMessage(),220)];}
    }
    private static function codeFingerprint():array{
        $root=PB_ROOT;
        $files=[
            'public_html/api/src/ReleaseInfo.php',
            'public_html/api/src/AgentPolicyEngine.php',
            'public_html/api/src/AgentWorkflowService.php',
            'public_html/api/src/ProviderCapabilityRouter.php',
            'public_html/api/src/Scheduler.php',
            'public_html/api/src/PostInstallAcceptanceService.php',
            'public_html/api/src/IntegrationHealthService.php',
            'public_html/api/src/DeploymentFingerprintService.php',
            'private/bin/package-selfcheck.php',
        ];
        $parts=[];$missing=[];
        foreach($files as $rel){$path=$root.'/'.$rel;if(!is_file($path)){$missing[]=$rel;continue;}$parts[$rel]=hash_file('sha256',$path)?:'';}
        ksort($parts,SORT_STRING);
        return ['sha256'=>hash('sha256',j($parts)),'files'=>count($parts),'missing'=>$missing];
    }
    public static function snapshot():array{
        $code=self::codeFingerprint();
        $workerAt=(string)setting('runtime.worker_heartbeat_at','');$workerTs=utc_ts($workerAt);
        $workerHealthy=$workerTs!==false&&(time()-$workerTs)<600&&(string)setting('runtime.worker_last_run_state','')!=='failed';
        $lastBackup=self::row("SELECT id,project_id,task_id,provider,kind,location_ref,checksum,status,verified_at,created_at FROM backups WHERE status IN ('verified','restored') ORDER BY COALESCE(verified_at,created_at) DESC,id DESC LIMIT 1");
        $vault=(int)self::scalar("SELECT COUNT(*) FROM secure_vault_snapshots WHERE state='verified' AND CHAR_LENGTH(envelope_sha256)=64",[],0);
        $config=(int)self::scalar("SELECT COUNT(*) FROM secure_config_snapshots WHERE state='verified' AND CHAR_LENGTH(config_sha256)=64",[],0);
        $migration20=(int)self::scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id='companyos_20_0_final_unified' AND state IN ('applied','verified')",[],0);
        $maintenance=(int)self::scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id='companyos_20_1_maintenance' AND state IN ('applied','verified')",[],0);
        $latestRelease=self::row("SELECT version,schema_version,build_date,created_at FROM release_history ORDER BY id DESC LIMIT 1");
        $staging=(string)setting('staging.domain','');
        $recoveryReady=$vault>0&&$config>0&&$migration20>0;
        return [
            'release'=>['app'=>ReleaseInfo::VERSION,'schema_expected'=>ReleaseInfo::SCHEMA,'schema_active'=>(string)setting('system.schema',''),'stored_version'=>(string)setting('system.version',''),'latest_history'=>$latestRelease],
            'code'=>$code,
            'database'=>['base_migration_recorded'=>$migration20>0,'maintenance_marker_recorded'=>$maintenance>0],
            'runtime'=>['worker_healthy'=>$workerHealthy,'worker_heartbeat_at'=>$workerAt?:null,'worker_last_state'=>(string)setting('runtime.worker_last_run_state',''),'staging_domain'=>$staging],
            'recovery'=>['ready'=>$recoveryReady,'verified_vault_snapshots'=>$vault,'verified_config_snapshots'=>$config,'last_verified_backup'=>$lastBackup?:null],
            'generated_at'=>now_utc(),
        ];
    }
}
