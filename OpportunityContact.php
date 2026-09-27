<?php
declare(strict_types=1);

/**
 * Extracts only contact methods that are publicly visible in the fetched source.
 * It never logs in, bypasses a platform, or invents a handle/number.
 */
final class OpportunityContact {
    private const SOCIAL_HOSTS = [
        'facebook.com'=>'facebook',
        'fb.com'=>'facebook',
        'instagram.com'=>'instagram',
        't.me'=>'telegram',
        'telegram.me'=>'telegram',
        'linkedin.com'=>'linkedin',
        'x.com'=>'x',
        'twitter.com'=>'x',
    ];

    public static function extract(string $html,string $text,string $sourceUrl=''):array {
        $items=[];
        $add=static function(string $type,string $value,string $url='',string $label='',string $source='public_page') use (&$items):void {
            $value=trim(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if($value==='')return;
            $key=pb_strtolower($type.'|'.$value.'|'.$url);
            foreach($items as $it){
                if(($it['_key']??'')===$key)return;
            }
            $items[]=['type'=>$type,'label'=>$label!==''?$label:self::label($type),'value'=>pb_substr($value,0,300),'url'=>pb_substr($url,0,1200),'source'=>$source,'_key'=>$key];
        };

        if($html!==''){
            if(preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/iu',$html,$m)){
                foreach(array_slice($m[1],0,500) as $hrefRaw){
                    $href=trim(html_entity_decode((string)$hrefRaw,ENT_QUOTES|ENT_HTML5,'UTF-8'));
                    if($href==='')continue;
                    if(str_starts_with(pb_strtolower($href),'mailto:')){
                        $mail=trim(preg_replace('/\?.*$/','',substr($href,7))??'');
                        if(filter_var($mail,FILTER_VALIDATE_EMAIL))$add('email',$mail,'mailto:'.$mail);
                        continue;
                    }
                    if(str_starts_with(pb_strtolower($href),'tel:')){
                        $phone=self::phone(substr($href,4));if($phone!=='')$add('phone',$phone,'tel:+'.$phone);
                        continue;
                    }
                    $abs=self::absoluteUrl($href,$sourceUrl);
                    if($abs==='')continue;
                    $host=pb_strtolower((string)(parse_url($abs,PHP_URL_HOST)?:''));
                    $host=preg_replace('/^www\./','',$host)??$host;
                    $path=(string)(parse_url($abs,PHP_URL_PATH)?:'');
                    $type='';
                    if($host==='wa.me'||str_contains($host,'whatsapp.com'))$type='whatsapp';
                    else foreach(self::SOCIAL_HOSTS as $domain=>$kind){if($host===$domain||str_ends_with($host,'.'.$domain)){$type=$kind;break;}}
                    if($type!==''){
                        $value=$abs;
                        if($type==='whatsapp'){
                            if(preg_match('#/(?:send/)?([0-9]{8,16})(?:/|$|\?)#',$path,$pm))$value='+'.$pm[1];
                            elseif(($q=parse_url($abs,PHP_URL_QUERY))&&preg_match('/(?:^|&)phone=([0-9]{8,16})/',$q,$pm))$value='+'.$pm[1];
                        }
                        $add($type,$value,$abs);
                    }
                }
            }
        }

        $combined=trim($text.' '.strip_tags($html));
        if($combined!==''){
            if(preg_match_all('/(?<![A-Z0-9._%+\-])([A-Z0-9._%+\-]{1,64}@[A-Z0-9.\-]+\.[A-Z]{2,24})(?![A-Z0-9._%+\-])/iu',$combined,$m)){
                foreach(array_unique($m[1]) as $mail){if(filter_var($mail,FILTER_VALIDATE_EMAIL))$add('email',$mail,'mailto:'.$mail);}
            }
            if(preg_match_all('/(?:whats\s*app|واتس(?:اب)?|هاتف|تليفون|تلفون|موبايل|phone|mobile|tel(?:ephone)?)\s*[:\-]?\s*(\+?[0-9][0-9\s().\-]{7,22})/iu',$combined,$m)){
                foreach($m[1] as $raw){$phone=self::phone((string)$raw);if($phone!=='')$add('phone',$phone,'tel:+'.$phone);}
            }
        }

        // The project/ad page itself is always a valid route for platform messaging or source follow-up.
        $platform=self::platformMethod($sourceUrl);
        if($platform!==null)$add($platform['type'],$platform['value'],$sourceUrl,$platform['label'],'source_url');

        foreach($items as &$it)unset($it['_key']);unset($it);
        usort($items,static fn($a,$b)=>self::priority((string)$a['type'])<=>self::priority((string)$b['type']));
        return array_slice($items,0,20);
    }

    public static function primary(array $items):string {
        if(!$items)return '';
        usort($items,static fn($a,$b)=>self::priority((string)($a['type']??''))<=>self::priority((string)($b['type']??'')));
        $x=$items[0]??[];return trim((string)($x['value']??''));
    }

    public static function summary(array $items,int $limit=4):string {
        $parts=[];foreach(array_slice($items,0,max(1,$limit)) as $it){$parts[]=self::label((string)($it['type']??'')).': '.trim((string)($it['value']??''));}
        return implode(' | ',$parts);
    }

    public static function directScore(array $items):int {
        if(!$items)return 30;
        $types=array_values(array_unique(array_map(static fn($x)=>(string)($x['type']??''),$items)));
        if(array_intersect($types,['whatsapp','phone','email']))return 96;
        if(array_intersect($types,['telegram','instagram','facebook','linkedin','x']))return 82;
        if(in_array('platform_message',$types,true))return 68;
        return 50;
    }

    public static function label(string $type):string {
        return match($type){
            'whatsapp'=>'واتساب','phone'=>'هاتف','email'=>'بريد إلكتروني','telegram'=>'تيليجرام','instagram'=>'إنستجرام','facebook'=>'فيسبوك','linkedin'=>'لينكدإن','x'=>'X / تويتر','platform_message'=>'رسائل المنصة','website'=>'موقع',default=>'تواصل'
        };
    }

    private static function priority(string $type):int {
        return match($type){'whatsapp'=>1,'phone'=>2,'email'=>3,'telegram'=>4,'instagram'=>5,'facebook'=>6,'linkedin'=>7,'x'=>8,'platform_message'=>9,default=>20};
    }

    private static function phone(string $raw):string {
        $digits=preg_replace('/\D+/','',$raw)??'';
        if(str_starts_with($digits,'00'))$digits=substr($digits,2);
        if(strlen($digits)<8||strlen($digits)>16)return '';
        return $digits;
    }

    private static function absoluteUrl(string $href,string $base):string {
        $href=trim($href);if($href==='')return '';
        if(preg_match('#^https?://#i',$href))return filter_var($href,FILTER_VALIDATE_URL)?$href:'';
        if(str_starts_with($href,'//')){$u='https:'.$href;return filter_var($u,FILTER_VALIDATE_URL)?$u:'';}
        if(!str_starts_with($href,'/'))return '';
        $scheme=(string)(parse_url($base,PHP_URL_SCHEME)?:'https');$host=(string)(parse_url($base,PHP_URL_HOST)?:'');
        if($host==='')return '';$u=$scheme.'://'.$host.$href;return filter_var($u,FILTER_VALIDATE_URL)?$u:'';
    }

    private static function platformMethod(string $url):?array {
        if($url===''||!filter_var($url,FILTER_VALIDATE_URL))return null;
        $host=pb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
        foreach(['freelancer.com','upwork.com','peopleperhour.com','mostaql.com','khamsat.com','workana.com','guru.com','nafezly.com','ureed.com'] as $domain){
            if($host===$domain||str_ends_with($host,'.'.$domain))return ['type'=>'platform_message','label'=>'رسائل المنصة','value'=>$domain];
        }
        return null;
    }
}
