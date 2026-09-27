<?php
declare(strict_types=1);
/** Backward-compatible facade over the Company OS 20 provider router. */
final class AiCapabilityRouter {
    public static function routes(string $capability):array{return ProviderCapabilityRouter::routes($capability,true);}
    public static function webSearch(int $agentId,string $query,int $limit=10,bool $general=false):array{$r=ProviderCapabilityRouter::webSearch($agentId,$query,$limit);return (array)($r['items']??$r);}
    public static function embedding(int $agentId,string $text):?array{$text=trim($text);if($text==='')return null;try{$r=ProviderCapabilityRouter::embedding($agentId,$text);return isset($r['vector'])&&is_array($r['vector'])?array_map('floatval',$r['vector']):null;}catch(Throwable){return EmbeddingService::embed($text,$agentId);}}
    public static function transcribe(int $agentId,string $path):array{return ProviderCapabilityRouter::transcribe($agentId,$path);}
    public static function speak(int $agentId,string $text):array{return ProviderCapabilityRouter::speak($agentId,$text);}
    public static function vision(int $agentId,string $imagePath,string $prompt='حلل الصورة بدقة واذكر ما تراه فقط دون اختراع معلومات.'):array{
        if(!is_file($imagePath))throw new RuntimeException('vision_image_missing');$mime=function_exists('mime_content_type')?(string)mime_content_type($imagePath):'image/jpeg';$bytes=file_get_contents($imagePath);if($bytes===false)throw new RuntimeException('vision_image_read_failed');$data='data:'.$mime.';base64,'.base64_encode($bytes);return ProviderCapabilityRouter::vision($agentId,$prompt,$data);
    }
    public static function image(int $agentId,int $projectId,string $label,string $prompt,int $taskId=0):array{return ProviderCapabilityRouter::image($agentId,$projectId,$label,$prompt,$taskId);}
    public static function video(int $agentId,int $projectId,string $label,string $prompt,int $taskId=0):array{return ProviderCapabilityRouter::video($agentId,$projectId,$label,$prompt,$taskId);}
    public static function status():array{$out=[];foreach(ProviderCapabilityRouter::capabilities() as $cap)$out[$cap]=ProviderCapabilityRouter::routes($cap,true);return $out;}
}
