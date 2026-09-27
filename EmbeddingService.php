<?php
declare(strict_types=1);
final class EmbeddingService {
    public static function configured():bool{try{return ProviderCapabilityRouter::preferred('embeddings')!==null;}catch(Throwable){return false;}}
    public static function embed(string $text,int $agentId=0):?array{$text=trim($text);if($text==='')return null;try{if($agentId<=0)$agentId=(int)AgentService::bySlug('ramy')['id'];$r=ProviderCapabilityRouter::embedding($agentId,substr($text,0,12000));$v=$r['vector']??null;return is_array($v)&&count($v)>8?array_map('floatval',$v):null;}catch(Throwable $e){error_log('ELMETR embedding: '.Security::redactSecrets($e->getMessage(),180));return null;}}
    public static function cosine(array $a,array $b):float{$n=min(count($a),count($b));if($n<1)return 0.0;$dot=$aa=$bb=0.0;for($i=0;$i<$n;$i++){$x=(float)$a[$i];$y=(float)$b[$i];$dot+=$x*$y;$aa+=$x*$x;$bb+=$y*$y;}return $aa>0&&$bb>0?$dot/(sqrt($aa)*sqrt($bb)):0.0;}
}
