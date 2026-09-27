<?php
declare(strict_types=1);
final class CloudStorageService {
    public static function preferred():string{return trim((string)setting('cloud.storage_provider','none'))?:'none';}
    public static function configured(?string $provider=null):bool {
        $provider=$provider?:self::preferred();
        return match($provider){
            's3','cloud_s3'=>self::secret('connections.cloud_s3.endpoint')!==''&&self::secret('connections.cloud_s3.bucket')!==''&&self::secret('connections.cloud_s3.access_key')!==''&&self::secret('connections.cloud_s3.secret_key')!=='',
            'google_drive'=>self::googleToken(false)!=='',
            'dropbox'=>self::secret('connections.dropbox.token')!=='',
            default=>false,
        };
    }
    public static function test(string $provider):array {
        return match($provider){
            's3','cloud_s3'=>self::testS3(),
            'google_drive'=>self::testGoogleDrive(),
            'dropbox'=>self::testDropbox(),
            default=>throw new RuntimeException('cloud_provider_unknown'),
        };
    }
    public static function mirrorFile(string $absolutePath,string $remoteName='',?string $provider=null):array {
        if(!is_file($absolutePath)||!is_readable($absolutePath))throw new RuntimeException('cloud_local_file_missing');
        $provider=$provider?:self::preferred();if($provider==='none')return ['uploaded'=>false,'provider'=>'none','reason'=>'disabled'];
        if(!self::configured($provider))throw new RuntimeException('cloud_provider_not_configured');
        $remoteName=trim($remoteName)!==''?ltrim(str_replace('\\','/',$remoteName),'/'):'persebayt/'.date('Y/m/d').'/'.basename($absolutePath);
        if(str_contains($remoteName,'..')||strlen($remoteName)>900)throw new RuntimeException('cloud_remote_name_invalid');
        $bytes=file_get_contents($absolutePath);if($bytes===false)throw new RuntimeException('cloud_local_read_failed');
        $r=match($provider){'s3','cloud_s3'=>self::putS3($remoteName,$bytes),'google_drive'=>self::putGoogleDrive($remoteName,$bytes),'dropbox'=>self::putDropbox($remoteName,$bytes),default=>throw new RuntimeException('cloud_provider_unknown')};
        try{db()->prepare("INSERT INTO cloud_objects(provider_key,object_key,local_path_hash,size_bytes,checksum,state,remote_ref,metadata_json,created_at) VALUES (?,?,?,?,?,'uploaded',?,?,NOW())")->execute([$provider,$remoteName,hash('sha256',$absolutePath),strlen($bytes),hash('sha256',$bytes),(string)($r['remote_ref']??$remoteName),j($r)]);}catch(Throwable){}
        return ['uploaded'=>true,'provider'=>$provider,'remote_name'=>$remoteName]+$r;
    }
    public static function mirrorIfEnabled(string $absolutePath,string $remoteName=''):array {
        if(setting('cloud.auto_backup','0')!=='1')return ['uploaded'=>false,'reason'=>'auto_backup_disabled'];
        try{return self::mirrorFile($absolutePath,$remoteName);}catch(Throwable $e){try{Notifications::add('warning','cloud','تعذر رفع النسخة الاحتياطية إلى السحابة',AdminUi::humanError($e->getMessage()),'provider',self::preferred());}catch(Throwable){}return ['uploaded'=>false,'reason'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}
    }
    private static function secret(string $key):string{return trim((string)SecretVault::get($key,''));}
    private static function testS3():array {
        if(!self::configured('s3'))throw new RuntimeException('cloud_s3_not_configured');
        $r=self::s3Request('GET','', '',['list-type'=>'2','max-keys'=>'1']);return ['ok'=>true,'provider'=>'cloud_s3','status'=>$r['status'],'bucket'=>self::secret('connections.cloud_s3.bucket')];
    }
    private static function putS3(string $name,string $bytes):array {$r=self::s3Request('PUT',$name,$bytes,[]);return ['remote_ref'=>$name,'status'=>$r['status'],'etag'=>$r['headers']['etag']??null];}
    private static function s3Request(string $method,string $object,string $body,array $query):array {
        $endpoint=rtrim(self::secret('connections.cloud_s3.endpoint'),'/');$bucket=self::secret('connections.cloud_s3.bucket');$region=self::secret('connections.cloud_s3.region')?:'auto';$access=self::secret('connections.cloud_s3.access_key');$secret=self::secret('connections.cloud_s3.secret_key');
        if($endpoint===''||$bucket===''||$access===''||$secret==='')throw new RuntimeException('cloud_s3_not_configured');Security::publicUrl($endpoint);
        $segments=array_map('rawurlencode',array_values(array_filter(explode('/',trim($object,'/')),static fn($x)=>$x!=='')));$path='/'.rawurlencode($bucket).($segments?'/'.implode('/',$segments):'');
        ksort($query);$canonicalQuery=http_build_query($query,'','&',PHP_QUERY_RFC3986);$url=$endpoint.$path.($canonicalQuery!==''?'?'.$canonicalQuery:'');
        $host=(string)parse_url($endpoint,PHP_URL_HOST);$amzDate=gmdate('Ymd\\THis\\Z');$date=substr($amzDate,0,8);$payloadHash=hash('sha256',$body);
        $canonicalHeaders='host:'.$host."\n".'x-amz-content-sha256:'.$payloadHash."\n".'x-amz-date:'.$amzDate."\n";$signed='host;x-amz-content-sha256;x-amz-date';
        $canonical=$method."\n".$path."\n".$canonicalQuery."\n".$canonicalHeaders."\n".$signed."\n".$payloadHash;$scope=$date.'/'.$region.'/s3/aws4_request';$toSign='AWS4-HMAC-SHA256'."\n".$amzDate."\n".$scope."\n".hash('sha256',$canonical);
        $kDate=hash_hmac('sha256',$date,'AWS4'.$secret,true);$kRegion=hash_hmac('sha256',$region,$kDate,true);$kService=hash_hmac('sha256','s3',$kRegion,true);$kSigning=hash_hmac('sha256','aws4_request',$kService,true);$sig=hash_hmac('sha256',$toSign,$kSigning);
        $auth='AWS4-HMAC-SHA256 Credential='.$access.'/'.$scope.', SignedHeaders='.$signed.', Signature='.$sig;
        return self::raw($method,$url,['Authorization: '.$auth,'x-amz-date: '.$amzDate,'x-amz-content-sha256: '.$payloadHash,'Content-Type: application/octet-stream'],$body,60);
    }
    private static function testDropbox():array {
        $token=self::secret('connections.dropbox.token');if($token==='')throw new RuntimeException('cloud_dropbox_not_configured');$r=self::raw('POST','https://api.dropboxapi.com/2/users/get_current_account',['Authorization: Bearer '.$token,'Content-Type: application/json'],'null',40);$json=json_decode($r['body'],true)?:[];return ['ok'=>true,'provider'=>'dropbox','account_id'=>$json['account_id']??null,'email'=>$json['email']??null];
    }
    private static function putDropbox(string $name,string $bytes):array {
        $token=self::secret('connections.dropbox.token');$path='/'.ltrim($name,'/');$arg=j(['path'=>$path,'mode'=>'overwrite','autorename'=>false,'mute'=>true]);$r=self::raw('POST','https://content.dropboxapi.com/2/files/upload',['Authorization: Bearer '.$token,'Dropbox-API-Arg: '.$arg,'Content-Type: application/octet-stream'],$bytes,90);$json=json_decode($r['body'],true)?:[];return ['remote_ref'=>$json['id']??$path,'path_display'=>$json['path_display']??$path];
    }
    private static function googleToken(bool $refresh=true):string {
        $access=self::secret('connections.google_drive.access_token');$refreshToken=self::secret('connections.google_drive.refresh_token');$client=self::secret('connections.google_drive.client_id');$secret=self::secret('connections.google_drive.client_secret');
        if(!$refresh||$refreshToken===''||$client===''||$secret==='')return $access;
        try{$r=HttpClient::form('POST','https://oauth2.googleapis.com/token',[],['client_id'=>$client,'client_secret'=>$secret,'refresh_token'=>$refreshToken,'grant_type'=>'refresh_token'],35);$new=trim((string)($r['access_token']??''));if($new!==''){SecretVault::save(['connections.google_drive.access_token'=>$new]);return $new;}}catch(Throwable $e){if($access==='')throw $e;}
        return $access;
    }
    private static function testGoogleDrive():array {
        $token=self::googleToken(true);if($token==='')throw new RuntimeException('cloud_google_drive_not_configured');$r=HttpClient::json('GET','https://www.googleapis.com/drive/v3/about?fields=user(displayName,emailAddress),storageQuota',['Authorization'=>'Bearer '.$token],null,40);return ['ok'=>true,'provider'=>'google_drive','user'=>$r['user']??null,'storageQuota'=>$r['storageQuota']??null];
    }
    private static function putGoogleDrive(string $name,string $bytes):array {
        $token=self::googleToken(true);if($token==='')throw new RuntimeException('cloud_google_drive_not_configured');$boundary='pb'.bin2hex(random_bytes(12));$meta=j(['name'=>basename($name),'appProperties'=>['persebayt_path'=>$name]]);$payload='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".$meta."\r\n--".$boundary."\r\nContent-Type: application/octet-stream\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";$r=self::raw('POST','https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name',['Authorization: Bearer '.$token,'Content-Type: multipart/related; boundary='.$boundary],$payload,120);$json=json_decode($r['body'],true)?:[];return ['remote_ref'=>$json['id']??$name,'name'=>$json['name']??basename($name)];
    }
    private static function raw(string $method,string $url,array $headers,string $body='',int $timeout=45):array {
        $url=Security::publicUrl($url);$ch=function_exists('curl_init')?curl_init($url):false;if(!$ch)throw new RuntimeException('curl_extension_missing');$respHeaders=[];
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$respHeaders){$p=strpos($line,':');if($p!==false)$respHeaders[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return strlen($line);}]);if($method!=='GET'&&$method!=='HEAD')curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('network_error:'.$err);if($status<200||$status>=300)throw new RuntimeException('cloud_http_'.$status.':'.pb_substr(strip_tags((string)$raw),0,220));return ['status'=>$status,'body'=>(string)$raw,'headers'=>$respHeaders];
    }
}
