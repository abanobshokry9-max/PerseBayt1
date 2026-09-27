<?php
declare(strict_types=1);
final class CallBridgeClient {
    public static function configured():bool{return (bool)filter_var((string)config('calls.bridge_url',''),FILTER_VALIDATE_URL)&&(string)config('calls.bridge_secret','')!=='';}
    private static function post(array $payload):array{
        if(!self::configured())throw new RuntimeException('call_bridge_not_configured');
        $url=Security::publicUrl((string)config('calls.bridge_url',''));$secret=(string)config('calls.bridge_secret','');
        return HttpClient::json('POST',$url,['Authorization'=>'Bearer '.$secret,'X-Elmetr-Bridge'=>'1'],$payload,35);
    }
    private static function hooks():array{$base=rtrim((string)config('app.base_url'),' /');return ['speech_webhook'=>$base.'/webhooks/generic-call.php','status_webhook'=>$base.'/webhooks/generic-call.php'];}
    public static function whatsappEvent(array $call,?array $agent=null):array{
        return self::post(['event'=>'whatsapp.call_event','call'=>$call,'agent'=>$agent?['id'=>(int)$agent['id'],'slug'=>(string)$agent['slug'],'name'=>(string)$agent['display_name']]:null]+self::hooks());
    }
    public static function whatsappOutbound(string $to,?array $agent=null):array{
        return self::post(['event'=>'whatsapp.call_start','to'=>$to,'agent'=>$agent?['id'=>(int)$agent['id'],'slug'=>(string)$agent['slug'],'name'=>(string)$agent['display_name']]:null]+self::hooks());
    }
    public static function call(string $to,?array $agent=null):array{
        return self::post(['event'=>'call.start','to'=>$to,'agent'=>$agent?['id'=>(int)$agent['id'],'slug'=>(string)$agent['slug'],'name'=>(string)$agent['display_name']]:null,'inbound_webhook'=>rtrim((string)config('app.base_url'),' /').'/webhooks/generic-call.php']+self::hooks());
    }
    public static function health():array{
        if(!self::configured())throw new RuntimeException('call_bridge_not_configured');
        return self::post(['event'=>'health.check','timestamp'=>now_utc()]+self::hooks());
    }
}
