<?php
declare(strict_types=1);
final class BrowserQaService {
    public static function configured():bool{return trim((string)SecretVault::get('connections.browser_qa.url',''))!=='';}
    public static function run(string $baseUrl,array $paths=['/']):array {
        if(!self::configured())return ['configured'=>false,'tests'=>[],'screenshots'=>[],'warnings'=>['browser_qa_not_configured']];
        $url=trim((string)SecretVault::get('connections.browser_qa.url',''));$token=trim((string)SecretVault::get('connections.browser_qa.token',''));$devices=['desktop'=>['width'=>1440,'height'=>1000],'tablet'=>['width'=>820,'height'=>1180],'mobile'=>['width'=>390,'height'=>844]];
        $headers=[];if($token!=='')$headers['Authorization']='Bearer '.$token;
        $r=HttpClient::json('POST',$url,$headers,['base_url'=>$baseUrl,'paths'=>array_values(array_slice($paths,0,12)),'devices'=>$devices,'checks'=>['javascript'=>true,'console_errors'=>true,'network_errors'=>true,'links'=>true,'buttons'=>true,'forms'=>true,'responsive'=>true,'rtl'=>true,'screenshots'=>true]],120);
        return ['configured'=>true,'tests'=>(array)($r['tests']??[]),'screenshots'=>(array)($r['screenshots']??[]),'warnings'=>(array)($r['warnings']??[]),'run_id'=>$r['run_id']??null,'raw_summary'=>$r['summary']??null];
    }
    public static function test():array {
        if(!self::configured())throw new RuntimeException('browser_qa_not_configured');$base=(string)config('app.base_url','');$root=preg_replace('#/api/?$#','',$base)?:$base;$r=self::run($root,['/']);return ['ok'=>true,'configured'=>true,'test_count'=>count((array)$r['tests']),'run_id'=>$r['run_id']??null];
    }
}
