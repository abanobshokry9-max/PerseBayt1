<?php
declare(strict_types=1);

/** Lightweight, public-only website audit for prospect qualification. */
final class ProspectWebsiteAuditService {
    public static function audit(int $leadId):array{
        $lead=self::lead($leadId);$url=trim((string)($lead['website_url']??''));
        if($url===''){
            $out=['classification'=>'none','overall_score'=>0,'website_url'=>'','findings'=>['no_verified_website'],'evidence'=>['lead_id'=>$leadId]];
            self::store($leadId,$out);db()->prepare("UPDATE prospect_leads SET website_state='none',updated_at=NOW() WHERE id=?")->execute([$leadId]);return $out;
        }
        $url=Security::publicUrl($url);$start=microtime(true);$probe=HttpClient::probe($url,10,650000);$elapsed=(int)round((microtime(true)-$start)*1000);$status=(int)($probe['status']??0);$html=(string)($probe['body']??'');$final=trim((string)($probe['final_url']??$url))?:$url;
        if($status<200||$status>=400||$html===''){
            $out=['classification'=>'broken','overall_score'=>0,'website_url'=>$final,'http_status'=>$status,'https_ok'=>str_starts_with(strtolower($final),'https://'),'mobile_ok'=>false,'cta_ok'=>false,'contact_form_ok'=>false,'rtl_arabic_ok'=>false,'broken_links_count'=>null,'seo_basics_score'=>0,'performance_hint_ms'=>$elapsed,'findings'=>['http_unavailable'],'evidence'=>['final_url'=>$final,'status'=>$status]];
            self::store($leadId,$out);db()->prepare("UPDATE prospect_leads SET website_url=?,website_state='broken',updated_at=NOW() WHERE id=?")->execute([$final,$leadId]);return $out;
        }
        $text=pb_strtolower(strip_tags($html));$score=0;$findings=[];
        $https=str_starts_with(strtolower($final),'https://');$score+=$https?15:0;if(!$https)$findings[]='https_missing';
        $mobile=(bool)preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i',$html)||(bool)preg_match('/@media\s*\(/i',$html);$score+=$mobile?15:0;if(!$mobile)$findings[]='mobile_responsiveness_not_evidenced';
        $cta=(bool)preg_match('/(contact|call|book|order|buy|shop|quote|whatsapp|اتصل|تواصل|اطلب|احجز|واتساب)/iu',$text);$score+=$cta?12:0;if(!$cta)$findings[]='clear_cta_not_found';
        $form=(bool)preg_match('/<form\b/i',$html);$score+=$form?8:0;if(!$form)$findings[]='contact_form_not_found';
        $arabic=(bool)preg_match('/[\x{0600}-\x{06FF}]/u',$html);$rtl=(bool)preg_match('/(?:dir\s*=\s*["\']rtl|direction\s*:\s*rtl)/i',$html);$rtlOk=!$arabic||$rtl;$score+=$rtlOk?8:0;if($arabic&&!$rtl)$findings[]='arabic_without_rtl_signal';
        $seo=0;if(preg_match('/<title[^>]*>\s*[^<]{3,}/i',$html))$seo+=35;else $findings[]='title_missing';if(preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'][^"\']{20,}/i',$html))$seo+=35;else $findings[]='meta_description_missing';if(preg_match('/<h1\b[^>]*>.*?<\/h1>/is',$html))$seo+=30;else $findings[]='h1_missing';$score+=(int)round($seo*0.15);
        $broken=self::sampleBrokenLinks($html,$final);$score+=max(0,12-min(12,$broken*3));if($broken>0)$findings[]='sample_broken_links:'.$broken;
        if($elapsed<=1500)$score+=15;elseif($elapsed<=3000)$score+=9;elseif($elapsed<=5000)$score+=4;else $findings[]='slow_response_hint';
        $score=max(0,min(100,$score));$classification=$score>=70?'present':'weak';
        $out=['classification'=>$classification,'overall_score'=>$score,'website_url'=>$final,'http_status'=>$status,'https_ok'=>$https,'mobile_ok'=>$mobile,'cta_ok'=>$cta,'contact_form_ok'=>$form,'rtl_arabic_ok'=>$rtlOk,'broken_links_count'=>$broken,'seo_basics_score'=>$seo,'performance_hint_ms'=>$elapsed,'findings'=>$findings,'evidence'=>['final_url'=>$final,'status'=>$status,'sample_only'=>true,'checked_at'=>now_utc()]];
        self::store($leadId,$out);db()->prepare('UPDATE prospect_leads SET website_url=?,website_state=?,updated_at=NOW() WHERE id=?')->execute([$final,$classification,$leadId]);return $out;
    }
    private static function sampleBrokenLinks(string $html,string $base):int{
        preg_match_all('/href\s*=\s*["\']([^"\'#]+)["\']/i',$html,$m);$host=pb_strtolower((string)(parse_url($base,PHP_URL_HOST)?:''));$seen=[];$broken=0;$n=0;
        foreach((array)($m[1]??[]) as $href){$href=trim(html_entity_decode((string)$href,ENT_QUOTES|ENT_HTML5));if($href===''||str_starts_with($href,'mailto:')||str_starts_with($href,'tel:')||str_starts_with($href,'javascript:'))continue;$url=self::absolute($base,$href);if(!$url)continue;if(pb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''))!==$host)continue;$key=preg_replace('/#.*$/','',$url);if(isset($seen[$key]))continue;$seen[$key]=1;if(++$n>8)break;try{$p=HttpClient::probe($url,5,120000);$st=(int)($p['status']??0);if($st===0||$st>=400)$broken++;}catch(Throwable){$broken++;}}
        return $broken;
    }
    private static function absolute(string $base,string $href):?string{
        if(filter_var($href,FILTER_VALIDATE_URL))return Security::publicUrl($href);$p=parse_url($base);if(!$p||empty($p['scheme'])||empty($p['host']))return null;$root=$p['scheme'].'://'.$p['host'].(!empty($p['port'])?':'.$p['port']:'');if(str_starts_with($href,'//'))return Security::publicUrl($p['scheme'].':'.$href);if(str_starts_with($href,'/'))return Security::publicUrl($root.$href);$path=(string)($p['path']??'/');$dir=rtrim(str_replace('\\','/',dirname($path)),'/.');return Security::publicUrl($root.($dir?'/'.ltrim($dir,'/'):'').'/'.$href);
    }
    private static function store(int $leadId,array $o):void{
        db()->prepare('INSERT INTO prospect_website_audits(lead_id,website_url,http_status,https_ok,mobile_ok,cta_ok,contact_form_ok,rtl_arabic_ok,broken_links_count,seo_basics_score,performance_hint_ms,overall_score,classification,findings_json,evidence_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$leadId,$o['website_url']?:null,$o['http_status']??null,isset($o['https_ok'])?($o['https_ok']?1:0):null,isset($o['mobile_ok'])?($o['mobile_ok']?1:0):null,isset($o['cta_ok'])?($o['cta_ok']?1:0):null,isset($o['contact_form_ok'])?($o['contact_form_ok']?1:0):null,isset($o['rtl_arabic_ok'])?($o['rtl_arabic_ok']?1:0):null,$o['broken_links_count']??null,$o['seo_basics_score']??null,$o['performance_hint_ms']??null,(int)($o['overall_score']??0),(string)($o['classification']??'unknown'),j($o['findings']??[]),j($o['evidence']??[])]);
    }
    private static function lead(int $id):array{$q=db()->prepare('SELECT * FROM prospect_leads WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('prospect_not_found');return $r;}
}
