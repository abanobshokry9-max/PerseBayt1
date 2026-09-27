<?php
declare(strict_types=1);
final class SocialMetricsService {
    public static function capturePublication(int $publicationId):array{
        $q=db()->prepare("SELECT p.*,a.external_ref account_external_ref FROM social_publications p LEFT JOIN social_accounts a ON a.id=p.account_id WHERE p.id=?");$q->execute([$publicationId]);$p=$q->fetch();if(!$p)throw new RuntimeException('social_publication_not_found');if((string)$p['state']!=='published'||trim((string)$p['external_post_id'])==='')throw new RuntimeException('social_publication_not_published');$platform=(string)$p['platform'];$views=0;$likes=0;$comments=0;$shares=0;$followers=null;$raw=[];
        if($platform==='youtube'){$r=YouTubeClient::videoStats((string)$p['external_post_id']);$s=(array)($r['statistics']??[]);$views=(int)($s['viewCount']??0);$likes=(int)($s['likeCount']??0);$comments=(int)($s['commentCount']??0);$raw=$r;}
        elseif(in_array($platform,['facebook','instagram'],true)){$r=MetaSocialClient::metrics($platform,(string)($p['account_external_ref']??''),(string)$p['external_post_id']);$likes=(int)($r['likes']??0);$comments=(int)($r['comments']??0);$shares=(int)($r['shares']??0);$raw=$r['raw']??$r;}
        else throw new RuntimeException('social_metrics_platform_not_supported');
        db()->prepare('INSERT INTO social_metrics(publication_id,account_id,platform,views_count,likes_count,comments_count,shares_count,followers_count,raw_json,captured_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')->execute([$publicationId,$p['account_id']?:null,$platform,$views,$likes,$comments,$shares,$followers,$raw?j($raw):null]);return ['publication_id'=>$publicationId,'platform'=>$platform,'views'=>$views,'likes'=>$likes,'comments'=>$comments,'shares'=>$shares];
    }
    public static function refreshRecent(int $limit=20):array{
        $limit=max(1,min(100,$limit));$rows=db()->query("SELECT p.id FROM social_publications p WHERE p.state='published' AND p.platform IN ('youtube','facebook','instagram') AND p.published_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY COALESCE((SELECT MAX(m.captured_at) FROM social_metrics m WHERE m.publication_id=p.id),'1970-01-01') ASC,p.id DESC LIMIT ".$limit)->fetchAll();$ok=0;$errors=[];foreach($rows as $r){try{self::capturePublication((int)$r['id']);$ok++;}catch(Throwable $e){$errors[(int)$r['id']]=Security::redactSecrets($e->getMessage(),160);}}return ['captured'=>$ok,'errors'=>$errors];
    }
    public static function scheduledRefresh():array{
        $minutes=max(15,min(1440,(int)setting('social.metrics_interval_minutes','60')));$last=(string)setting('social.metrics_last_refresh_at','');if($last!==''&&utc_ts($last)!==false&&utc_ts($last)>time()-($minutes*60))return ['skipped'=>true];put_setting('social.metrics_last_refresh_at',now_utc());return self::refreshRecent(20);
    }
}
