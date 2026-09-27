<?php
declare(strict_types=1);

final class ProviderCenterService {
    private const KINDS=['ai','hosting','messaging','voice','generic','browser','media','social','storage'];

    public static function customProviders():array{
        try{
            $rows=db()->query("SELECT * FROM providers ORDER BY enabled DESC,FIELD(status,'verified','working','untested','degraded','failed','disabled'),FIELD(kind,'ai','browser','media','social','messaging','voice','storage','hosting','generic'),label,provider_key")->fetchAll();
        }catch(Throwable){return [];}
        $out=[];
        foreach($rows as $row){
            $cfg=json_decode((string)($row['config_json']??'{}'),true);
            if(!is_array($cfg))$cfg=[];
            if(($cfg['managed_by']??'')==='provider_center'){$row['config']=$cfg;$out[]=$row;}
        }
        return $out;
    }

    public static function save(array $d):string{
        $key=strtolower(trim((string)($d['provider_key']??'')));
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,78}$/D',$key))throw new RuntimeException('provider_key_invalid');
        $label=trim((string)($d['label']??''));if($label==='')throw new RuntimeException('provider_label_required');
        $kind=trim((string)($d['kind']??'generic'));if(!in_array($kind,self::KINDS,true))throw new RuntimeException('provider_kind_invalid');
        $baseUrl=trim((string)($d['base_url']??''));
        if($baseUrl!==''&&!self::safePublicUrl($baseUrl,$kind==='ai'))throw new RuntimeException('provider_base_url_invalid');
        $model=trim((string)($d['model']??''));
        $models=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\r\n,]+/u',(string)($d['models']??''))?:[]))));
        if($model!==''&&!in_array($model,$models,true))array_unshift($models,$model);
        $notes=pb_substr(trim((string)($d['notes']??'')),0,1200);
        $enabled=!empty($d['enabled']);
        $driver=$kind==='ai'?'openai_compatible':match($kind){'browser'=>'browser_bridge','media'=>'media_bridge','social'=>'social_bridge','storage'=>'storage_bridge','messaging'=>'messaging_bridge','voice'=>'voice_bridge','hosting'=>'hosting_bridge',default=>'generic_bridge'};

        $existing=null;try{$q=db()->prepare('SELECT provider_key,config_json FROM providers WHERE provider_key=?');$q->execute([$key]);$existing=$q->fetch()?:null;}catch(Throwable){}
        if($existing){
            $old=json_decode((string)($existing['config_json']??'{}'),true);if(!is_array($old))$old=[];
            if(($old['managed_by']??'')!=='provider_center')throw new RuntimeException('provider_builtin_locked');
        }
        $cfg=['managed_by'=>'provider_center','base_url'=>$baseUrl,'models'=>$models,'notes'=>$notes];
        $q=db()->prepare("INSERT INTO providers(provider_key,kind,label,driver,enabled,config_json,status,last_error,last_checked_at) VALUES (?,?,?,?,?,?,'untested',NULL,NULL) ON DUPLICATE KEY UPDATE kind=VALUES(kind),label=VALUES(label),driver=VALUES(driver),enabled=VALUES(enabled),config_json=VALUES(config_json),status='untested',last_error=NULL");
        $q->execute([$key,$kind,pb_substr($label,0,160),$driver,$enabled?1:0,j($cfg)]);
        $secrets=[];
        $apiKey=trim((string)($d['api_key']??''));if($apiKey!=='')$secrets['providers.'.$key.'.api_key']=$apiKey;
        if($baseUrl!=='')$secrets['providers.'.$key.'.base_url']=$baseUrl;
        if($model!=='')$secrets['providers.'.$key.'.model']=$model;
        if($secrets)SecretVault::save($secrets);
        Audit::log('owner',(string)(Auth::user()['id']??''),'provider.save','provider',$key,'executed',null,null,['kind'=>$kind,'enabled'=>$enabled,'has_base_url'=>$baseUrl!=='','models'=>count($models)]);
        return $key;
    }

    public static function toggle(string $key,bool $enabled):void{
        self::requireManaged($key);
        db()->prepare("UPDATE providers SET enabled=?,status=? WHERE provider_key=?")->execute([$enabled?1:0,$enabled?'untested':'disabled',$key]);
        Audit::log('owner',(string)(Auth::user()['id']??''),'provider.toggle','provider',$key,'executed',null,null,['enabled'=>$enabled]);
    }

    public static function get(string $key):array{
        $q=db()->prepare('SELECT * FROM providers WHERE provider_key=? LIMIT 1');$q->execute([$key]);$row=$q->fetch();if(!$row)throw new RuntimeException('provider_unknown');
        $cfg=json_decode((string)($row['config_json']??'{}'),true);$row['config']=is_array($cfg)?$cfg:[];return $row;
    }

    public static function test(string $key):array{
        $p=self::get($key);
        if((string)$p['kind']!=='ai')throw new RuntimeException('provider_test_requires_ai');
        return ConnectionTester::test($key);
    }

    private static function requireManaged(string $key):array{
        $p=self::get($key);if(($p['config']['managed_by']??'')!=='provider_center')throw new RuntimeException('provider_builtin_locked');return $p;
    }

    private static function safePublicUrl(string $url,bool $allowLoopbackAi=false):bool{
        $p=parse_url($url);if(!$p||!in_array(strtolower((string)($p['scheme']??'')),['https','http'],true)||empty($p['host']))return false;
        $host=strtolower((string)$p['host']);
        if($allowLoopbackAi&&in_array($host,['127.0.0.1','localhost','::1'],true))return true;
        if(in_array($host,['localhost','0.0.0.0','::1'],true))return false;
        if(filter_var($host,FILTER_VALIDATE_IP))return !self::privateIp($host);
        return true;
    }

    private static function privateIp(string $ip):bool{
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false;
    }
}
