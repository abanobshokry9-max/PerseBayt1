<?php
declare(strict_types=1);
define('PB_ROOT',dirname(__DIR__,3));
require_once __DIR__.'/SecretVault.php';
$GLOBALS['PB_CONFIG']=SecretVault::apply(require PB_ROOT.'/private/config.php');
date_default_timezone_set((string)($GLOBALS['PB_CONFIG']['app']['timezone']??'Africa/Cairo'));
spl_autoload_register(static function(string $class):void{$file=__DIR__.'/'.$class.'.php';if(is_file($file))require_once $file;});
function config(string $path,$default=null){$v=$GLOBALS['PB_CONFIG'];foreach(explode('.',$path) as $p){if(!is_array($v)||!array_key_exists($p,$v))return $default;$v=$v[$p];}return $v;}
function db():PDO{return Database::connection((array)config('db',[]));}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function pb_substr(string $value,int $start,?int $length=null,?string $encoding=null):string{if(function_exists('mb_substr'))return $length===null?mb_substr($value,$start,null,$encoding?:'UTF-8'):mb_substr($value,$start,$length,$encoding?:'UTF-8');return $length===null?substr($value,$start):(string)substr($value,$start,$length);}
function pb_strlen(string $value,?string $encoding=null):int{return function_exists('mb_strlen')?mb_strlen($value,$encoding?:'UTF-8'):strlen($value);}
function pb_strtolower(string $value,?string $encoding=null):string{return function_exists('mb_strtolower')?mb_strtolower($value,$encoding?:'UTF-8'):strtolower($value);}
function pb_strpos(string $haystack,string $needle,int $offset=0,?string $encoding=null):int|false{return function_exists('mb_strpos')?mb_strpos($haystack,$needle,$offset,$encoding?:'UTF-8'):strpos($haystack,$needle,$offset);}
function j($v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function now_utc():string{return gmdate('Y-m-d H:i:s');}
function utc_ts(?string $value):int|false{$value=trim((string)$value);if($value==='')return false;if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$value)){try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->getTimestamp();}catch(Throwable){return false;}}$ts=strtotime($value);return $ts===false?false:$ts;}
function uid(string $prefix='T'):string{return $prefix.strtoupper(bin2hex(random_bytes(6)));}
function setting(string $key,$default=''){try{$q=db()->prepare('SELECT value_text FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:$v;}catch(Throwable){return $default;}}
function put_setting(string $key,$value):void{$q=db()->prepare('INSERT INTO settings(setting_key,value_text) VALUES (?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');$q->execute([$key,(string)$value]);}
function request_ip():string{$remote=trim((string)($_SERVER['REMOTE_ADDR']??''));if(!filter_var($remote,FILTER_VALIDATE_IP))$remote='';$trusted=(array)config('app.trusted_proxy_ips',[]);if((bool)config('app.trust_proxy',false)&&$remote!==''&&in_array($remote,$trusted,true)){foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP'] as $h){$v=trim((string)($_SERVER[$h]??''));if($v!==''&&filter_var($v,FILTER_VALIDATE_IP))return substr($v,0,45);}}return substr($remote,0,45);}
Security::headers();
Security::enforceRequestSize(4194304);
set_exception_handler(static function(Throwable $e):void{
    $safeError=Security::redactSecrets($e->getMessage(),500);
    try{$ref='ERR-'.strtoupper(substr(hash('sha256',microtime(true).'|'.random_bytes(16)),0,10));}catch(Throwable){$ref='ERR-'.strtoupper(substr(hash('sha256',microtime(true).'|'.mt_rand()),0,10));}
    error_log('ELMETR['.$ref.'] '.get_class($e).': '.$safeError);
    try{
        $actor=class_exists('Audit',false)?Audit::actor():['type'=>'system','id'=>''];
        db()->prepare('INSERT INTO system_errors(error_ref,request_uri,http_method,error_class,error_message,actor_type,actor_id) VALUES (?,?,?,?,?,?,?)')->execute([$ref,pb_substr((string)($_SERVER['REQUEST_URI']??''),0,1200),pb_substr((string)($_SERVER['REQUEST_METHOD']??''),0,12),pb_substr(get_class($e),0,190),pb_substr($safeError,0,1000),pb_substr((string)($actor['type']??'system'),0,40),pb_substr((string)($actor['id']??''),0,120)]);
    }catch(Throwable){}
    if(PHP_SAPI==='cli'){fwrite(STDERR,$ref.' '.$safeError.PHP_EOL);return;}
    $code=match($e->getMessage()){'csrf_invalid'=>419,'request_too_large'=>413,'login_rate_limited'=>429,default=>500};http_response_code($code);
    if(str_contains($_SERVER['REQUEST_URI']??'','/webhooks/')){header('Content-Type: text/plain; charset=utf-8');echo 'error';return;}
    $doctor='/api/admin/doctor.php';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body dir="rtl" style="font-family:system-ui;padding:40px;background:#07110d;color:#fff"><h1>حدث خطأ</h1><p>تم تسجيل الخطأ بدون عرض أي أسرار.</p><p style="opacity:.78">مرجع الخطأ: <code>'.e($ref).'</code></p><p><a href="'.e($doctor).'" style="color:#35e39b">افتح فحص وإصلاح النظام</a></p></body>';
});

// إذا تم رفع ملفات Company OS قبل استيراد قاعدة البيانات المطابقة للإصدار النظيف، لا نترك صفحات الإدارة تنهار
// باستعلامات على جداول/أعمدة غير موجودة. نوجّه صفحات GET إلى System Doctor الذي يعمل بصورة دفاعية.
if(PHP_SAPI!=='cli' && (($_SERVER['REQUEST_METHOD']??'GET')==='GET')){
    $uri=(string)($_SERVER['REQUEST_URI']??'');
    if(str_contains($uri,'/api/admin/')){
        $path=(string)(parse_url($uri,PHP_URL_PATH)?:'');$base=basename($path);
        if(!in_array($base,['login.php','logout.php','doctor.php','action.php'],true)){
            try{if(!SystemDoctor::schemaReady()){header('Location: /api/admin/doctor.php?schema=required');exit;}}catch(Throwable){}
        }
    }
}
