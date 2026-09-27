<?php
declare(strict_types=1);
final class GenericMessageClient {
    private static function url():string{return trim((string)config('connections.generic_message.url',''));}
    private static function token():string{return trim((string)config('connections.generic_message.token',''));}
    public static function configured():bool{return (bool)filter_var(self::url(),FILTER_VALIDATE_URL)&&self::token()!=='';}
    public static function send(string $to,string $body):array{
        if(!self::configured())throw new RuntimeException('generic_message_not_configured');$url=Security::publicUrl(self::url());
        $payload=['event'=>'message.send','to'=>$to,'from'=>(string)config('connections.generic_message.from_ref',''),'body'=>pb_substr($body,0,5000),'callback_url'=>rtrim((string)config('app.base_url'),' /').'/webhooks/generic-message.php'];
        $r=HttpClient::json('POST',$url,['Authorization'=>'Bearer '.self::token(),'X-Elmetr-Bridge'=>'1'],$payload,35);
        return is_array($r)?$r:['ok'=>true];
    }
    public static function verifyInbound(string $secret):bool{$expected=(string)config('connections.generic_message.inbound_secret','');return $expected!==''&&$secret!==''&&hash_equals($expected,$secret);}
}
