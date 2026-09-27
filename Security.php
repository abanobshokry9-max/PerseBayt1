<?php
declare(strict_types=1);
final class Security {
    public static function domain(string $domain): string {
        $domain=strtolower(trim($domain));$domain=rtrim($domain,'.');
        if($domain===''||strlen($domain)>253||!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',$domain))throw new RuntimeException('invalid_domain');
        return $domain;
    }
    public static function publicUrl(string $url,array $allowedHosts=[]): string {
        $url=trim($url);$p=parse_url($url);if(!is_array($p)||!in_array(strtolower((string)($p['scheme']??'')),['http','https'],true)||empty($p['host']))throw new RuntimeException('invalid_url');
        $host=strtolower((string)$p['host']);
        if($allowedHosts){$ok=false;foreach($allowedHosts as $a){$a=self::domain((string)$a);if($host===$a||str_ends_with($host,'.'.$a)){$ok=true;break;}}if(!$ok)throw new RuntimeException('url_host_not_allowed');}
        if(filter_var($host,FILTER_VALIDATE_IP)){if(!self::publicIp($host))throw new RuntimeException('private_address_blocked');return $url;}
        $ips=[];$records=@dns_get_record($host,DNS_A|DNS_AAAA)?:[];foreach($records as $r){$ip=(string)($r['ip']??$r['ipv6']??'');if($ip!=='')$ips[]=$ip;}if(!$ips){$fallback=@gethostbynamel($host)?:[];$ips=array_merge($ips,$fallback);}if(!$ips)throw new RuntimeException('host_unresolved');foreach(array_unique($ips) as $ip)if(!self::publicIp($ip))throw new RuntimeException('private_address_blocked');
        return $url;
    }
    private static function publicIp(string $ip): bool {return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;}
    public static function safeReturn(string $candidate,string $fallback='/api/admin/'): string {
        $p=parse_url($candidate,PHP_URL_PATH)?:$fallback;$q=parse_url($candidate,PHP_URL_QUERY);if(!str_starts_with($p,'/api/admin/'))return $fallback;return $p.($q?'?'.$q:'');
    }

    public static function redactSecrets(string $text,int $max=4000): string {
        $text=(string)$text;
        $patterns=[
            '/(Authorization\s*:\s*Bearer\s+)[^\s"\']+/i',
            '/(Bearer\s+)[A-Za-z0-9._~+\/-]{12,}/i',
            '/((?:api[_-]?key|access[_-]?token|auth[_-]?token|password|passwd|secret|app[_-]?secret|verify[_-]?token)\s*[=:]\s*)[^\s,;"\']{6,}/i',
            '/([?&](?:key|token|access_token|api_key|secret)=)[^&\s]+/i',
        ];
        foreach($patterns as $pattern)$text=(string)preg_replace($pattern,'$1[REDACTED]',$text);
        try{
            foreach(SecretVault::all() as $value){
                if(!is_string($value))continue;$value=trim($value);if(strlen($value)>=8)$text=str_replace($value,'[REDACTED]',$text);
            }
        }catch(Throwable){}
        $limit=max(100,$max);return function_exists('mb_substr')?pb_substr($text,0,$limit):substr($text,0,$limit);
    }

    public static function enforceRequestSize(int $maxBytes=4194304): void {
        if(PHP_SAPI==='cli')return;
        $len=(int)($_SERVER['CONTENT_LENGTH']??0);
        if($len>$maxBytes)throw new RuntimeException('request_too_large');
    }
    public static function headers(): void {
        if(PHP_SAPI==='cli'||headers_sent())return;
        header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header('Permissions-Policy: camera=(), geolocation=(), payment=(), usb=()');header('Cross-Origin-Opener-Policy: same-origin');header('Cross-Origin-Resource-Policy: same-site');header('X-Permitted-Cross-Domain-Policies: none');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'");
        if(str_contains((string)($_SERVER['REQUEST_URI']??''),'/api/admin')||str_contains((string)($_SERVER['REQUEST_URI']??''),'/api/chat'))header('Cache-Control: no-store, private');
        if((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https')header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
