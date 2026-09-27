<?php
declare(strict_types=1);
final class TelegramClient {
    private static function token():string{return trim((string)config('connections.telegram.bot_token',''));}
    public static function defaultChatId():string{return trim((string)config('connections.telegram.default_chat_id',''));}
    public static function webhookSecret():string{return trim((string)config('connections.telegram.webhook_secret',''));}
    public static function configured():bool{return self::token()!=='';}
    private static function endpoint(string $method):string{
        if(!self::configured())throw new RuntimeException('telegram_not_configured');
        $token=self::token();if(!preg_match('/^[0-9]{5,}:[A-Za-z0-9_-]{20,}$/',$token))throw new RuntimeException('telegram_token_invalid');
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,80}$/',$method))throw new RuntimeException('telegram_method_invalid');
        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }
    public static function test():array{$r=HttpClient::json('GET',self::endpoint('getMe'),[],null,20);if(empty($r['ok']))throw new RuntimeException('telegram_test_failed');return ['id'=>$r['result']['id']??null,'username'=>$r['result']['username']??null,'name'=>$r['result']['first_name']??null];}
    public static function registerWebhook():array{
        $secret=self::webhookSecret();if(strlen($secret)<16||strlen($secret)>256||!preg_match('/^[A-Za-z0-9_-]+$/',$secret))throw new RuntimeException('telegram_webhook_secret_invalid');
        $url=rtrim((string)config('app.base_url'),' /').'/webhooks/telegram.php';$url=Security::publicUrl($url);
        $r=HttpClient::json('POST',self::endpoint('setWebhook'),[],['url'=>$url,'secret_token'=>$secret,'allowed_updates'=>['message','edited_message','channel_post','edited_channel_post','callback_query'],'drop_pending_updates'=>false],30);
        if(empty($r['ok']))throw new RuntimeException('telegram_webhook_register_failed');return ['ok'=>true,'url'=>$url,'description'=>$r['description']??null];
    }
    public static function verifyWebhookHeader(string $header):bool{$secret=self::webhookSecret();return $secret!==''&&$header!==''&&hash_equals($secret,$header);}
    public static function sendMessage(string $chatId,string $text):array{$chatId=trim($chatId)?:self::defaultChatId();$text=trim($text);if($chatId===''||$text==='')throw new RuntimeException('telegram_message_incomplete');$r=HttpClient::json('POST',self::endpoint('sendMessage'),[],['chat_id'=>$chatId,'text'=>pb_substr($text,0,4096),'disable_web_page_preview'=>false],30);if(empty($r['ok']))throw new RuntimeException('telegram_send_failed');return $r;}
    public static function sendVideoUrl(string $chatId,string $videoUrl,string $caption=''):array{$chatId=trim($chatId)?:self::defaultChatId();if($chatId==='')throw new RuntimeException('telegram_chat_required');$videoUrl=Security::publicUrl($videoUrl);$r=HttpClient::json('POST',self::endpoint('sendVideo'),[],['chat_id'=>$chatId,'video'=>$videoUrl,'caption'=>pb_substr(trim($caption),0,1024),'supports_streaming'=>true],60);if(empty($r['ok']))throw new RuntimeException('telegram_video_send_failed');return $r;}
    public static function inboundContact(array $update):?array{
        $message=$update['message']??$update['edited_message']??$update['channel_post']??$update['edited_channel_post']??null;
        if(!is_array($message)&&isset($update['callback_query'])&&is_array($update['callback_query']))$message=$update['callback_query']['message']??null;
        $from=is_array($message)?($message['from']??null):null;if(!is_array($from)&&isset($update['callback_query']['from'])&&is_array($update['callback_query']['from']))$from=$update['callback_query']['from'];
        if(!is_array($from)||empty($from['id']))return null;
        $name=trim((string)($from['first_name']??'').' '.(string)($from['last_name']??''));$username=trim((string)($from['username']??''));$chat=is_array($message)?(array)($message['chat']??[]):[];
        return ['external_ref'=>(string)$from['id'],'display_name'=>$name,'username'=>$username,'chat_id'=>(string)($chat['id']??''),'chat_type'=>(string)($chat['type']??''),'language_code'=>(string)($from['language_code']??'')];
    }
}
