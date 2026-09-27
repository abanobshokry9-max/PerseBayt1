<?php
declare(strict_types=1);
final class Auth {
    private const IDLE_TIMEOUT=3600;
    private const ABSOLUTE_TIMEOUT=43200;
    public static function start(): void {
        if(session_status()===PHP_SESSION_ACTIVE){self::enforceSessionAge();return;}
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
        session_name((string)config('app.session_name','ELMETRADMIN'));
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();self::enforceSessionAge();
    }
    private static function enforceSessionAge():void{
        if(empty($_SESSION['uid']))return;$now=time();$created=(int)($_SESSION['created_at']??$now);$last=(int)($_SESSION['last_seen_at']??$now);
        if($now-$last>max(900,(int)setting('security.session_idle_minutes','60')*60)||$now-$created>max(3600,(int)setting('security.session_absolute_hours','12')*3600)){self::logout();return;}$_SESSION['last_seen_at']=$now;
    }
    public static function user(): ?array {self::start();$id=(int)($_SESSION['uid']??0);if(!$id)return null;$q=db()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([$id]);$u=$q->fetch()?:null;if(!$u)self::logout();return $u;}
    public static function requireOwner(): array {$u=self::user();if(!$u){header('Location: /api/admin/login.php');exit;}if(($u['role']??'')!=='owner'){Audit::log('system',(string)$u['id'],'auth.owner_denied','user',(string)$u['id'],'blocked');self::logout();http_response_code(403);exit('غير مسموح');}Audit::setActor('owner',(string)$u['id']);return $u;}
    private static function loginRatePolicy():array{
        $raw=trim((string)setting('security.login_rate_limit','8/15m'));
        if(preg_match('/^(\d{1,3})\s*\/\s*(\d{1,4})\s*([mhd])$/i',$raw,$m)){
            $limit=max(1,min(100,(int)$m[1]));$window=max(1,(int)$m[2]);$unit=strtolower($m[3]);
            $minutes=$unit==='h'?$window*60:($unit==='d'?$window*1440:$window);
            return [$limit,max(1,min(43200,$minutes))];
        }
        return [8,15];
    }
    private static function failureCount(string $ip,int $minutes):int{$minutes=max(1,min(43200,$minutes));$q=db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='auth.login_failed' AND ip_address=? AND created_at>=DATE_SUB(NOW(),INTERVAL {$minutes} MINUTE)");$q->execute([$ip]);return (int)$q->fetchColumn();}
    private static function normalizePhone(string $value):string{
        $digits=(string)preg_replace('/\D+/','',$value);if(str_starts_with($digits,'00'))$digits=substr($digits,2);
        if(strlen($digits)===11&&str_starts_with($digits,'0'))$digits='20'.substr($digits,1);
        elseif(strlen($digits)===10&&str_starts_with($digits,'1'))$digits='20'.$digits;
        return $digits;
    }
    private static function phoneCandidates(string $value):array{
        $digits=(string)preg_replace('/\D+/','',$value);$normalized=self::normalizePhone($value);$out=[];
        foreach([$normalized,$digits] as $x)if($x!==''&&!in_array($x,$out,true))$out[]=$x;
        if(str_starts_with($normalized,'20')&&strlen($normalized)===12){$legacy=substr($normalized,2);if($legacy!==''&&!in_array($legacy,$out,true))$out[]=$legacy;}
        return array_slice(array_pad($out,3,''),0,3);
    }
    public static function login(string $login,string $password): bool {
        $login=pb_substr(trim($login),0,190);if(strlen($password)>1024)return false;
        [$limit,$minutes]=self::loginRatePolicy();$ip=request_ip();if(self::failureCount($ip,$minutes)>=$limit){Audit::log('anonymous',$ip,'auth.login_rate_limited',null,null,'blocked',null,null,['login_hash'=>hash('sha256',pb_strtolower(trim($login))),'limit'=>$limit,'window_minutes'=>$minutes]);throw new RuntimeException('login_rate_limited');}
        [$p1,$p2,$p3]=self::phoneCandidates($login);$q=db()->prepare("SELECT * FROM users WHERE is_active=1 AND role='owner' AND (email=? OR phone IN (?,?,?)) LIMIT 1");$q->execute([$login,$p1,$p2,$p3]);$u=$q->fetch();
        if(!$u||!password_verify($password,(string)$u['password_hash'])){Audit::log('anonymous',$ip,'auth.login_failed','user',null,'failed',null,null,['login_hash'=>hash('sha256',pb_strtolower(trim($login)))]);usleep(random_int(120000,320000));return false;}
        $normalized=self::normalizePhone((string)($u['phone']??''));if($normalized!==''&&$normalized!==(string)($u['phone']??'')){try{db()->prepare('UPDATE users SET phone=? WHERE id=?')->execute([$normalized,$u['id']]);$u['phone']=$normalized;}catch(Throwable){}}
        self::start();session_regenerate_id(true);$_SESSION=['uid'=>(int)$u['id'],'created_at'=>time(),'last_seen_at'=>time(),'csrf'=>bin2hex(random_bytes(24))];db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);if(password_needs_rehash((string)$u['password_hash'],PASSWORD_DEFAULT))db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$u['id']]);Audit::setActor('owner',(string)$u['id']);Audit::log('owner',(string)$u['id'],'auth.login','user',(string)$u['id'],'verified');return true;
    }
    public static function changePassword(int $ownerId,string $current,string $new):void{$u=self::requireOwner();if((int)$u['id']!==$ownerId||!password_verify($current,(string)$u['password_hash']))throw new RuntimeException('current_password_invalid');if(strlen($new)<12||!preg_match('/\p{L}/u',$new)||!preg_match('/\d/',$new))throw new RuntimeException('password_too_weak');$hash=password_hash($new,PASSWORD_DEFAULT);db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash,$ownerId]);session_regenerate_id(true);Audit::log('owner',(string)$ownerId,'auth.password_changed','user',(string)$ownerId,'verified');}
    public static function logout():void{if(session_status()!==PHP_SESSION_ACTIVE)self::start();$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}if(session_status()===PHP_SESSION_ACTIVE)session_destroy();}
    public static function csrf(): string {self::start();if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return (string)$_SESSION['csrf'];}
    public static function verifyCsrf(): void {self::start();$t=(string)($_POST['csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??'');if($t===''||!hash_equals((string)($_SESSION['csrf']??''),$t))throw new RuntimeException('csrf_invalid');}
}
