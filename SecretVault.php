<?php
declare(strict_types=1);
final class SecretVault {
    private static ?array $cache = null;
    private static function dir(): string { return PB_ROOT.'/private/runtime'; }
    private static function key(bool $create=false): string {
        $path=self::dir().'/master.key';
        if(!is_file($path) && $create){
            if(!is_dir(self::dir())) mkdir(self::dir(),0700,true);
            file_put_contents($path,base64_encode(random_bytes(32)),LOCK_EX); @chmod($path,0600);
        }
        $raw=is_file($path)?trim((string)file_get_contents($path)):'';
        $key=base64_decode($raw,true);
        if($key===false || strlen($key)!==32) throw new RuntimeException('vault_key_unavailable');
        return $key;
    }
    private static function allowed(string $k): bool {
        if(in_array($k,['db.pass','ai.api_key','ai.model','ai.enabled','ai.max_daily_calls','meta.app_secret','meta.verify_token','meta.access_token','meta.phone_number_id','meta.graph_version','hostinger.api_token','calls.bridge_url','calls.bridge_secret'],true)) return true;
        return (bool)preg_match('/^(?:providers|connections|agents|sources|twilio|accounts)\.[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/D',$k);
    }
    public static function all(): array {
        if(self::$cache!==null) return self::$cache;
        $path=self::dir().'/secrets.enc'; if(!is_file($path)) return self::$cache=[];
        $e=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($e['v']??null)!==1) throw new RuntimeException('vault_format_invalid');
        $iv=base64_decode((string)$e['iv'],true);$tag=base64_decode((string)$e['tag'],true);$cipher=base64_decode((string)$e['data'],true);
        if(!function_exists('openssl_decrypt'))throw new RuntimeException('openssl_extension_missing');$plain=openssl_decrypt($cipher,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'elmetr-v1');
        if($plain===false) throw new RuntimeException('vault_authentication_failed');
        $values=json_decode($plain,true,512,JSON_THROW_ON_ERROR); if(!is_array($values)) $values=[];
        return self::$cache=array_filter($values,fn($v,$k)=>self::allowed((string)$k),ARRAY_FILTER_USE_BOTH);
    }
    public static function get(string $key,$default='') { $v=self::all(); return $v[$key]??$default; }
    public static function apply(array $config): array {
        foreach(self::all() as $key=>$value){
            $parts=explode('.',$key);$ref=&$config;
            foreach($parts as $part){if(!isset($ref[$part])||!is_array($ref[$part]))$ref[$part]=[];$ref=&$ref[$part];}
            $ref=$value;unset($ref);
        }
        return $config;
    }
    public static function save(array $changes): void {
        foreach($changes as $k=>$v) if(!self::allowed((string)$k)||!is_scalar($v)) throw new InvalidArgumentException('vault_field_invalid');
        if(!is_dir(self::dir())) mkdir(self::dir(),0700,true);
        $lock=fopen(self::dir().'/vault.lock','c');@chmod(self::dir().'/vault.lock',0600); if(!$lock||!flock($lock,LOCK_EX)) throw new RuntimeException('vault_lock_failed');
        try{
            self::$cache=null;$values=array_replace(self::all(),$changes);$key=self::key(true);$iv=random_bytes(12);$tag='';
            if(!function_exists('openssl_encrypt'))throw new RuntimeException('openssl_extension_missing');$cipher=openssl_encrypt(json_encode($values,JSON_THROW_ON_ERROR),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'elmetr-v1',16);
            if($cipher===false) throw new RuntimeException('vault_encrypt_failed');
            $payload=json_encode(['v'=>1,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)],JSON_THROW_ON_ERROR);
            $tmp=self::dir().'/vault-'.bin2hex(random_bytes(8)).'.tmp';file_put_contents($tmp,$payload,LOCK_EX);@chmod($tmp,0600);rename($tmp,self::dir().'/secrets.enc');self::$cache=$values;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function forget(array $keys): void {
        foreach($keys as $k) if(!self::allowed((string)$k)) throw new InvalidArgumentException('vault_field_invalid');
        if(!is_dir(self::dir())) mkdir(self::dir(),0700,true);
        $lock=fopen(self::dir().'/vault.lock','c');@chmod(self::dir().'/vault.lock',0600); if(!$lock||!flock($lock,LOCK_EX)) throw new RuntimeException('vault_lock_failed');
        try{
            self::$cache=null;$values=self::all();foreach($keys as $k)unset($values[(string)$k]);$key=self::key(true);$iv=random_bytes(12);$tag='';
            if(!function_exists('openssl_encrypt'))throw new RuntimeException('openssl_extension_missing');$cipher=openssl_encrypt(json_encode($values,JSON_THROW_ON_ERROR),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'elmetr-v1',16);
            if($cipher===false)throw new RuntimeException('vault_encrypt_failed');
            $payload=json_encode(['v'=>1,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)],JSON_THROW_ON_ERROR);
            $tmp=self::dir().'/vault-'.bin2hex(random_bytes(8)).'.tmp';file_put_contents($tmp,$payload,LOCK_EX);@chmod($tmp,0600);rename($tmp,self::dir().'/secrets.enc');self::$cache=$values;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function presence(): array { $out=[];foreach(self::all() as $k=>$v)$out[$k]=is_string($v)?trim($v)!=='':$v!==null;return $out; }
}
