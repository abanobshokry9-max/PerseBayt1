<?php
declare(strict_types=1);
/**
 * Stores only the already-encrypted secrets.enc envelope in the database.
 * The master key is intentionally NOT stored in the database; owner package keeps it separately.
 */
final class VaultSnapshotService {
    public static function capture(string $label='current_owner_vault'):int{
        Auth::requireOwner();$path=PB_ROOT.'/private/runtime/secrets.enc';if(!is_file($path))throw new RuntimeException('vault_envelope_missing');$raw=(string)file_get_contents($path);if($raw==='')throw new RuntimeException('vault_envelope_empty');json_decode($raw,true,512,JSON_THROW_ON_ERROR);$sha=hash('sha256',$raw);
        db()->prepare("INSERT INTO secure_vault_snapshots(label,envelope_b64,envelope_sha256,source_ref,state,created_by_user_id) VALUES (?,?,?,?, 'verified',?) ON DUPLICATE KEY UPDATE envelope_b64=VALUES(envelope_b64),source_ref=VALUES(source_ref),state='verified',created_by_user_id=VALUES(created_by_user_id),created_at=NOW()")
            ->execute([pb_substr($label,0,160),base64_encode($raw),$sha,'private/runtime/secrets.enc',(int)(Auth::user()['id']??1)]);$q=db()->prepare('SELECT id FROM secure_vault_snapshots WHERE envelope_sha256=?');$q->execute([$sha]);$id=(int)$q->fetchColumn();Audit::log('owner',(string)(Auth::user()['id']??1),'vault.snapshot','secure_vault_snapshot',(string)$id,'verified',null,null,['sha256'=>$sha]);return $id;
    }
    public static function restore(int $id):array{
        Auth::requireOwner();$q=db()->prepare("SELECT * FROM secure_vault_snapshots WHERE id=? AND state='verified'");$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('vault_snapshot_not_found');$raw=base64_decode((string)$r['envelope_b64'],true);if($raw===false||!hash_equals((string)$r['envelope_sha256'],hash('sha256',$raw)))throw new RuntimeException('vault_snapshot_integrity_failed');$key=PB_ROOT.'/private/runtime/master.key';if(!is_file($key))throw new RuntimeException('vault_master_key_required');$dir=PB_ROOT.'/private/runtime';if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('vault_runtime_not_writable');$target=$dir.'/secrets.enc';$before=is_file($target)?hash_file('sha256',$target):null;$tmp=$dir.'/vault-restore-'.bin2hex(random_bytes(6)).'.tmp';file_put_contents($tmp,$raw,LOCK_EX);@chmod($tmp,0600);rename($tmp,$target);
        try{SecretVault::all();}catch(Throwable $e){if($before!==null)error_log('Vault restore validation failed; previous hash '.$before);throw new RuntimeException('vault_restore_validation_failed:'.Security::redactSecrets($e->getMessage(),180));}
        Audit::log('owner',(string)(Auth::user()['id']??1),'vault.restore','secure_vault_snapshot',(string)$id,'verified',null,null,['sha256'=>$r['envelope_sha256']]);return ['restored'=>true,'sha256'=>$r['envelope_sha256']];
    }
    public static function list():array{return db()->query("SELECT id,label,envelope_sha256,source_ref,state,created_at FROM secure_vault_snapshots ORDER BY id DESC LIMIT 50")->fetchAll();}
}
