<?php
declare(strict_types=1);

final class ServiceAccountService {
    public static function all(int $limit=300): array {
        $limit=max(1,min(500,$limit));
        return db()->query("SELECT s.*,a.display_name owner_agent_name FROM service_accounts s LEFT JOIN agents a ON a.id=s.owner_agent_id ORDER BY FIELD(s.status,'challenge','error','pending','active','disabled'),s.id DESC LIMIT ".$limit)->fetchAll();
    }

    public static function get(int $id): array {
        $q=db()->prepare('SELECT s.*,a.display_name owner_agent_name FROM service_accounts s LEFT JOIN agents a ON a.id=s.owner_agent_id WHERE s.id=?');
        $q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('service_account_not_found');return $r;
    }

    public static function create(array $d,string $requestedByType='owner',string $requestedById='1'): int {
        $platform=trim((string)($d['platform']??''));$name=trim((string)($d['display_name']??''));
        if($platform===''||$name==='')throw new RuntimeException('service_account_fields_required');
        if(!preg_match('/^[a-z0-9_.-]{2,80}$/i',$platform))throw new RuntimeException('service_account_platform_invalid');
        $username=trim((string)($d['username']??''));$email=trim((string)($d['email']??''));$url=trim((string)($d['login_url']??''));
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('email_invalid');
        if($url!==''&&!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('url_invalid');
        $owner=(int)($d['owner_agent_id']??0)?:null;if($owner!==null)AgentService::byId($owner);
        $password=(string)($d['password']??'');
        $q=db()->prepare("INSERT INTO service_accounts(platform,display_name,username,email,login_url,owner_agent_id,requested_by_type,requested_by_id,purpose,status,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,'active',?)");
        $q->execute([$platform,pb_substr($name,0,190),$username!==''?pb_substr($username,0,190):null,$email!==''?pb_substr($email,0,190):null,$url!==''?pb_substr($url,0,1200):null,$owner,$requestedByType,pb_substr($requestedById,0,100),pb_substr(trim((string)($d['purpose']??'')),0,8000),j((array)($d['metadata']??[]))]);
        $id=(int)db()->lastInsertId();
        if($password!==''){
            $ref='accounts.'.$id.'.password';
            try{SecretVault::save([$ref=>$password]);db()->prepare('UPDATE service_accounts SET secret_ref=? WHERE id=?')->execute([$ref,$id]);}
            catch(Throwable $e){db()->prepare('DELETE FROM service_accounts WHERE id=?')->execute([$id]);throw $e;}
        }
        Audit::log($requestedByType,$requestedById,'account.create','service_account',(string)$id,'verified',null,null,['platform'=>$platform,'owner_agent_id'=>$owner,'has_secret'=>$password!=='']);
        return $id;
    }

    public static function createGenerated(int $agentId,array $d): int {
        Permissions::requireAgent($agentId,'accounts.create');
        $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%_-';$password='';for($i=0;$i<24;$i++)$password.=$alphabet[random_int(0,strlen($alphabet)-1)];
        $d['password']=$password;$d['owner_agent_id']=$d['owner_agent_id']??$agentId;
        try{return self::create($d,'agent',(string)$agentId);}finally{$password=str_repeat('*',strlen($password));unset($d['password']);}
    }

    public static function update(int $id,array $d): void {
        $r=self::get($id);$name=trim((string)($d['display_name']??$r['display_name']));if($name==='')throw new RuntimeException('service_account_fields_required');
        $username=trim((string)($d['username']??$r['username']??''));$email=trim((string)($d['email']??$r['email']??''));$url=trim((string)($d['login_url']??$r['login_url']??''));
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('email_invalid');if($url!==''&&!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('url_invalid');
        $owner=(int)($d['owner_agent_id']??($r['owner_agent_id']??0))?:null;if($owner!==null)AgentService::byId($owner);
        $status=(string)($d['status']??$r['status']);if(!in_array($status,['active','disabled','challenge','error','pending'],true))$status=(string)$r['status'];
        db()->prepare('UPDATE service_accounts SET display_name=?,username=?,email=?,login_url=?,owner_agent_id=?,purpose=?,status=? WHERE id=?')->execute([pb_substr($name,0,190),$username!==''?pb_substr($username,0,190):null,$email!==''?pb_substr($email,0,190):null,$url!==''?pb_substr($url,0,1200):null,$owner,pb_substr(trim((string)($d['purpose']??$r['purpose']??'')),0,8000),$status,$id]);
        $password=(string)($d['password']??'');if($password!==''){$ref=(string)($r['secret_ref']?:('accounts.'.$id.'.password'));SecretVault::save([$ref=>$password]);db()->prepare('UPDATE service_accounts SET secret_ref=? WHERE id=?')->execute([$ref,$id]);}
        Audit::log('owner',(string)(Auth::user()['id']??1),'account.update','service_account',(string)$id,'verified',null,null,['status'=>$status]);
    }

    public static function revealForOwner(int $id): string {
        Auth::requireOwner();$r=self::get($id);$ref=trim((string)($r['secret_ref']??''));if($ref==='')return '';
        $value=(string)SecretVault::get($ref,'');Audit::log('owner',(string)(Auth::user()['id']??1),'account.secret_reveal','service_account',(string)$id,'verified');return $value;
    }

    /** Internal execution only. Never include this result in model prompts/evidence/logs. */
    public static function credentialForExecution(int $agentId,int $id): array {
        Permissions::requireAgent($agentId,'accounts.use');$r=self::get($id);if((string)$r['status']!=='active')throw new RuntimeException('service_account_not_active');
        $ref=trim((string)($r['secret_ref']??''));$password=$ref!==''?(string)SecretVault::get($ref,''):'';
        db()->prepare('UPDATE service_accounts SET last_used_at=NOW() WHERE id=?')->execute([$id]);
        return ['id'=>$id,'platform'=>(string)$r['platform'],'login_url'=>(string)($r['login_url']??''),'username'=>(string)($r['username']??''),'email'=>(string)($r['email']??''),'password'=>$password];
    }

    /** Internal account-provisioning credential. Pending accounts are allowed only for create_account flows. */
    public static function credentialForProvisioning(int $agentId,int $id): array {
        Permissions::requireAgent($agentId,'accounts.create');$r=self::get($id);if(!in_array((string)$r['status'],['pending','challenge'],true))throw new RuntimeException('service_account_not_pending_for_provisioning');
        $ref=trim((string)($r['secret_ref']??''));$password=$ref!==''?(string)SecretVault::get($ref,''):'';
        return ['id'=>$id,'platform'=>(string)$r['platform'],'login_url'=>(string)($r['login_url']??''),'username'=>(string)($r['username']??''),'email'=>(string)($r['email']??''),'password'=>$password];
    }

    public static function setStatus(int $id,string $status,array $meta=[]): void {
        self::get($id);if(!in_array($status,['active','disabled','challenge','error','pending'],true))throw new RuntimeException('service_account_status_invalid');
        db()->prepare('UPDATE service_accounts SET status=?,metadata_json=? WHERE id=?')->execute([$status,$meta?j($meta):null,$id]);
    }
}
