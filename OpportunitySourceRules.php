<?php
declare(strict_types=1);

final class OpportunitySourceRules {
    public static function classify(string $url):array{
        $url=trim($url);
        $host=pb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
        $host=preg_replace('/^www\./','',$host)?:$host;
        $path=pb_strtolower((string)(parse_url($url,PHP_URL_PATH)?:'/'));
        $path='/'.ltrim($path,'/');
        $path=rtrim($path,'/'); if($path==='')$path='/';
        $q=pb_strtolower((string)(parse_url($url,PHP_URL_QUERY)?:''));

        $result=['trusted'=>false,'kind'=>'other','source'=>'web','host'=>$host,'path'=>$path,'reason'=>''];
        if($host==='')return $result;

        if(self::hostIs($host,'upwork.com')){
            $result['trusted']=true;$result['source']='Upwork';
            if(preg_match('#^/freelance-jobs/apply/[^/]+_~[0-9]+$#u',$path)||preg_match('#^/jobs/~[0-9a-z]+$#u',$path))$result['kind']='detail';
            elseif(str_starts_with($path,'/freelance-jobs'))$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'freelancer.com')){
            $result['trusted']=true;$result['source']='Freelancer';
            if(preg_match('#^/projects/[^/]+/[^/]+$#u',$path))$result['kind']='detail';
            elseif(str_starts_with($path,'/projects')||str_starts_with($path,'/jobs')||str_starts_with($path,'/job-search'))$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'peopleperhour.com')){
            $result['trusted']=true;$result['source']='PeoplePerHour';
            $segments=array_values(array_filter(explode('/',$path),'strlen'));
            if(($segments[0]??'')==='freelance-jobs' && count($segments)>=3)$result['kind']='detail';
            elseif(str_starts_with($path,'/freelance-jobs'))$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'mostaql.com')){
            $result['trusted']=true;$result['source']='Mostaql';
            if($path==='/project/create'||str_starts_with($path,'/project/create/')){$result['kind']='blocked';$result['reason']='project_create_template';return $result;}
            if(preg_match('#^/project/[^/]+$#u',$path))$result['kind']='detail';
            elseif($path==='/projects'||str_starts_with($path,'/projects/'))$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'khamsat.com')){
            $result['trusted']=true;$result['source']='Khamsat';
            if(preg_match('#^/community/requests/[^/]+$#u',$path))$result['kind']='detail';
            elseif($path==='/community/requests'||str_starts_with($path,'/community/requests/'))$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'workana.com')){
            $result['trusted']=true;$result['source']='Workana';
            if(preg_match('#^/(?:[a-z]{2}/)?job/[^/]+$#u',$path))$result['kind']='detail';
            elseif(preg_match('#^/(?:[a-z]{2}/)?jobs(?:/.*)?$#u',$path))$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'guru.com')){
            $result['trusted']=true;$result['source']='Guru';
            if(preg_match('#^/jobs/[^/]+(?:/[0-9]+)?$#u',$path)&&$path!=='/jobs')$result['kind']='detail';
            elseif($path==='/jobs'||str_starts_with($path,'/d/jobs')||str_starts_with($path,'/jobs/'))$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'nafezly.com')){
            $result['trusted']=true;$result['source']='Nafezly';
            // Nafizly exposes /project/create-guest?template_type=portfolio pages that mirror a freelancer portfolio.
            // They look like project pages to a naive path matcher but are seller/portfolio content, not buyer demand.
            if($path==='/project/create-guest'||str_starts_with($path,'/portfolio')||str_contains($q,'template_type=portfolio')||str_contains($q,'freelancer=')){
                $result['kind']='blocked';$result['reason']='seller_or_portfolio_template';return $result;
            }
            // Real Nafizly projects use a numeric id at the beginning of the slug: /project/55364-title...
            if(preg_match('#^/project/[0-9]+(?:-[^/]+)?$#u',$path))$result['kind']='detail';
            elseif(str_starts_with($path,'/projects')||$path==='/project')$result['kind']='listing';
            return $result;
        }
        if(self::hostIs($host,'ureed.com')){
            $result['trusted']=true;$result['source']='Ureed';
            if(preg_match('#/(?:project|job)/[^/]+$#u',$path))$result['kind']='detail';
            elseif(str_contains($path,'/jobs')||str_contains($path,'/projects'))$result['kind']='listing';
            return $result;
        }
        return $result;
    }

    public static function isClosedText(string $text):bool{
        $head=pb_strtolower(pb_substr(preg_replace('/\s+/u',' ',trim($text))?:trim($text),0,14000));
        if($head==='')return false;
        return (bool)preg_match('/(job is no longer available|no longer accepting proposals|not accepting proposals|closed for bidding|project (?:is )?closed|\bclosed\s+posted\b|\bcompleted\s+posted\b|\bcancelled\s+posted\b|\bcanceled\s+posted\b|\bawarded to\b|المشروع مغلق|تم إغلاق المشروع|تم اغلاق المشروع|انتهى استقبال العروض|تم إلغاء المشروع|تم الغاء المشروع|projet ferm[eé]|projeto conclu[ií]do|\brealizado\b|\bfinalizado\b|\bcancelado\b)/u',$head);
    }

    public static function projectLinks(string $html,string $baseUrl,int $limit=30):array{
        $limit=max(1,min(100,$limit));$out=[];$seen=[];
        if(!preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',$html,$matches,PREG_SET_ORDER))return [];
        foreach($matches as $m){
            $href=html_entity_decode(trim((string)$m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');
            if($href===''||str_starts_with($href,'#')||preg_match('#^(?:javascript|mailto|tel):#i',$href))continue;
            $url=self::resolveUrl($baseUrl,$href);if($url==='')continue;
            $c=self::classify($url);if(!$c['trusted']||$c['kind']!=='detail')continue;
            $key=hash('sha256',pb_strtolower($url));if(isset($seen[$key]))continue;$seen[$key]=true;
            $title=trim(html_entity_decode(strip_tags((string)$m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $title=preg_replace('/\s+/u',' ',$title)?:$title;
            if($title===''||pb_strlen($title)<3)$title=(string)$c['source'].' project';
            $out[]=['title'=>pb_substr($title,0,300),'url'=>$url,'snippet'=>'','published_at'=>'','source'=>(string)$c['source']];
            if(count($out)>=$limit)break;
        }
        return $out;
    }

    public static function normalizePublishedAt(string $raw,string $detailText=''):?string{
        $raw=trim($raw);
        if($raw!==''){
            $ts=strtotime($raw);if($ts!==false)return gmdate('Y-m-d H:i:s',$ts);
            $rel=self::relativeTimestamp($raw);if($rel!==null)return gmdate('Y-m-d H:i:s',$rel);
        }
        $text=pb_substr(preg_replace('/\s+/u',' ',trim($detailText))?:trim($detailText),0,20000);
        if($text==='')return null;
        $rel=self::relativeTimestamp($text);if($rel!==null)return gmdate('Y-m-d H:i:s',$rel);
        $patterns=[
            '/\bposted\s+(?:on\s+)?([A-Z][a-z]+\s+\d{1,2},\s+\d{4})\b/iu',
            '/\bpublished\s+on\s+(?:the\s+)?([A-Z][a-z]+\s+\d{1,2},\s+\d{4})\b/iu',
            '/\b(?:posted|published)\s+(\d{4}-\d{2}-\d{2})\b/iu',
            '/\b(?:posted|published)\s+(\d{1,2}\/\d{1,2}\/\d{4})\b/iu',
        ];
        foreach($patterns as $re)if(preg_match($re,$text,$m)){ $ts=strtotime((string)$m[1]); if($ts!==false)return gmdate('Y-m-d H:i:s',$ts); }
        return null;
    }

    private static function relativeTimestamp(string $text):?int{
        $now=time();$s=pb_strtolower($text);
        if(preg_match('/\b(?:posted\s+)?(?:today|just now)\b/u',$s))return $now;
        if(preg_match('/\b(?:posted\s+)?yesterday\b/u',$s))return $now-86400;
        if(preg_match('/\b(?:posted\s+)?(?:about\s+)?(\d+)\s*(minutes?|mins?|hours?|hrs?|days?|weeks?)\s+ago\b/u',$s,$m)){
            return $now-(int)$m[1]*match(true){str_starts_with($m[2],'min')=>60,str_starts_with($m[2],'hour')||str_starts_with($m[2],'hr')=>3600,str_starts_with($m[2],'day')=>86400,default=>604800};
        }
        if(preg_match('/منذ\s+(\d+)\s*(دقيقة|دقائق|ساعة|ساعات|يوم|أيام|ايام|أسبوع|اسبوع|أسابيع|اسابيع)/u',$s,$m)){
            $unit=(string)$m[2];$sec=str_contains($unit,'دقيق')?60:(str_contains($unit,'ساع')?3600:(str_contains($unit,'يوم')||str_contains($unit,'ايام')||str_contains($unit,'أيام')?86400:604800));return $now-(int)$m[1]*$sec;
        }
        if(preg_match('/(?:il y a|hace)\s+(\d+)\s*(minute|minutes|heure|heures|jour|jours|semaine|semaines|hora|horas|d[ií]a|d[ií]as|semana|semanas)/u',$s,$m)){
            $u=(string)$m[2];$sec=(str_contains($u,'minute'))?60:((str_contains($u,'heure')||str_contains($u,'hora'))?3600:((str_contains($u,'jour')||str_contains($u,'dí')||str_contains($u,'di'))?86400:604800));return $now-(int)$m[1]*$sec;
        }
        return null;
    }

    private static function resolveUrl(string $base,string $href):string{
        if(filter_var($href,FILTER_VALIDATE_URL))return $href;
        $p=parse_url($base);if(!$p||empty($p['scheme'])||empty($p['host']))return '';
        if(str_starts_with($href,'//'))return $p['scheme'].':'.$href;
        $origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
        if(str_starts_with($href,'/'))return $origin.$href;
        $basePath=(string)($p['path']??'/');$dir=rtrim(str_replace('\\','/',dirname($basePath)),'/');
        $path=$dir.'/'.$href;$parts=[];foreach(explode('/',$path) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
        return $origin.'/'.implode('/',$parts);
    }

    private static function hostIs(string $host,string $domain):bool{return $host===$domain||str_ends_with($host,'.'.$domain);}
}
