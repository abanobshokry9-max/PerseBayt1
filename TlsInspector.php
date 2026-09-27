<?php
declare(strict_types=1);
final class TlsInspector {
    public static function inspect(string $domain):array{
        $domain=Security::domain($domain);
        $ctx=stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$domain,'SNI_enabled'=>true]]);$errno=0;$err='';
        $fp=@stream_socket_client('ssl://'.$domain.':443',$errno,$err,12,STREAM_CLIENT_CONNECT,$ctx);if(!$fp)return ['ok'=>false,'error'=>$err?:('tls_'.$errno)];
        $params=stream_context_get_params($fp);fclose($fp);$cert=$params['options']['ssl']['peer_certificate']??null;if(!$cert)return ['ok'=>false,'error'=>'certificate_missing'];$p=openssl_x509_parse($cert);if(!is_array($p))return ['ok'=>false,'error'=>'certificate_parse_failed'];
        $from=(int)($p['validFrom_time_t']??0);$to=(int)($p['validTo_time_t']??0);$now=time();return ['ok'=>$from<=$now&&$to>$now,'subject'=>$p['subject']['CN']??$domain,'issuer'=>$p['issuer']['CN']??($p['issuer']['O']??'—'),'valid_from'=>$from?gmdate('Y-m-d H:i:s',$from):null,'valid_to'=>$to?gmdate('Y-m-d H:i:s',$to):null,'days_left'=>$to?(int)floor(($to-$now)/86400):null,'serial'=>$p['serialNumberHex']??null];
    }
}
