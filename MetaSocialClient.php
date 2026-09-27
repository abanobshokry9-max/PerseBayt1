<?php
declare(strict_types=1);

final class MetaSocialClient {
    private static function accessToken():string{return trim((string)config('connections.meta_social.access_token',''));}
    private static function configuredPageId():string{return trim((string)config('connections.meta_social.page_id',''));}
    private static function configuredPageToken():string{return trim((string)config('connections.meta_social.page_access_token',''));}
    private static function configuredInstagramId():string{return trim((string)config('connections.meta_social.instagram_id',''));}
    private static function version():string{
        $v=trim((string)config('connections.meta_social.graph_version',''));
        if($v==='')$v=trim((string)config('meta.graph_version','v23.0'));
        if(!preg_match('/^v\d+\.\d+$/',$v))$v='v23.0';
        return $v;
    }
    private static function base():string{return 'https://graph.facebook.com/'.self::version();}
    public static function configured():bool{return self::accessToken()!==''||self::configuredPageToken()!=='';}
    private static function bearer(string $token):array{if(trim($token)==='')throw new RuntimeException('meta_social_token_missing');return ['Authorization'=>'Bearer '.trim($token)];}
    private static function userToken():string{$t=self::accessToken();if($t==='')throw new RuntimeException('meta_social_user_token_missing');return $t;}

    public static function pages():array{
        $fields='id,name,username,access_token,instagram_business_account{id,username,name}';
        $r=HttpClient::json('GET',self::base().'/me/accounts?limit=100&fields='.rawurlencode($fields),self::bearer(self::userToken()),null,40);
        return is_array($r['data']??null)?$r['data']:[];
    }

    private static function pageRecord(string $pageId=''):array{
        $pageId=trim($pageId)?:self::configuredPageId();
        if($pageId!==''&&self::configuredPageToken()!=='')return ['id'=>$pageId,'access_token'=>self::configuredPageToken()];
        foreach(self::pages() as $p){if(!is_array($p))continue;if($pageId===''||hash_equals((string)($p['id']??''),$pageId))return $p;}
        throw new RuntimeException('meta_social_page_not_found');
    }
    private static function pageToken(string $pageId=''):array{
        $p=self::pageRecord($pageId);$id=trim((string)($p['id']??''));$token=trim((string)($p['access_token']??''));
        if($id===''||$token==='')throw new RuntimeException('meta_social_page_token_missing');
        return [$id,$token,$p];
    }

    public static function test():array{
        [$pageId,$token,$p]=self::pageToken();
        $page=HttpClient::json('GET',self::base().'/'.rawurlencode($pageId).'?fields='.rawurlencode('id,name,username,instagram_business_account{id,username,name}'),self::bearer($token),null,35);
        $ig=(array)($page['instagram_business_account']??[]);
        if(!$ig&&self::configuredInstagramId()!==''){
            try{$ig=HttpClient::json('GET',self::base().'/'.rawurlencode(self::configuredInstagramId()).'?fields='.rawurlencode('id,username,name'),self::bearer($token),null,35);}catch(Throwable){}
        }
        return ['page_id'=>$page['id']??$pageId,'page_name'=>$page['name']??($p['name']??null),'page_username'=>$page['username']??null,'instagram_id'=>$ig['id']??null,'instagram_username'=>$ig['username']??null];
    }

    public static function syncAccounts(int $ownerAgentId=0):array{
        if($ownerAgentId<=0){try{$ownerAgentId=(int)AgentService::bySlug('samir-social')['id'];}catch(Throwable){$ownerAgentId=0;}}
        $pages=self::pages();$ids=[];$igCount=0;
        foreach($pages as $p){if(!is_array($p)||empty($p['id']))continue;$pid=(string)$p['id'];
            $ids[]=SocialMediaService::upsertAccount('facebook',$pid,(string)($p['name']??$p['username']??('Facebook '.$pid)),$ownerAgentId,['username'=>$p['username']??null,'source'=>'meta_graph_api']);
            $ig=(array)($p['instagram_business_account']??[]);if(!empty($ig['id'])){$igCount++;SocialMediaService::upsertAccount('instagram',(string)$ig['id'],(string)($ig['username']??$ig['name']??('Instagram '.$ig['id'])),$ownerAgentId,['page_id'=>$pid,'username'=>$ig['username']??null,'source'=>'meta_graph_api']);}
        }
        // Allow a separately configured professional account when /me/accounts does not expand it.
        $cfgIg=self::configuredInstagramId();if($cfgIg!==''&&$igCount===0){try{[$pid,$token]=self::pageToken();$ig=HttpClient::json('GET',self::base().'/'.rawurlencode($cfgIg).'?fields='.rawurlencode('id,username,name'),self::bearer($token),null,35);SocialMediaService::upsertAccount('instagram',$cfgIg,(string)($ig['username']??$ig['name']??('Instagram '.$cfgIg)),$ownerAgentId,['page_id'=>$pid,'username'=>$ig['username']??null,'source'=>'meta_graph_api']);$igCount++;}catch(Throwable){} }
        return ['facebook_pages'=>count($pages),'instagram_accounts'=>$igCount,'synced'=>count($ids)+$igCount];
    }

    public static function publishFacebookText(string $pageId,string $message):array{
        [$pageId,$token]=self::pageToken($pageId);$message=trim($message);if($message==='')throw new RuntimeException('facebook_message_required');
        return HttpClient::form('POST',self::base().'/'.rawurlencode($pageId).'/feed',self::bearer($token),['message'=>pb_substr($message,0,60000)],45);
    }
    public static function publishFacebookImage(string $pageId,string $imageUrl,string $caption=''):array{
        [$pageId,$token]=self::pageToken($pageId);$imageUrl=Security::publicUrl($imageUrl);
        return HttpClient::form('POST',self::base().'/'.rawurlencode($pageId).'/photos',self::bearer($token),['url'=>$imageUrl,'caption'=>pb_substr(trim($caption),0,60000),'published'=>'true'],60);
    }

    private static function instagramTokenFor(string $instagramId):array{
        $instagramId=trim($instagramId)?:self::configuredInstagramId();if($instagramId==='')throw new RuntimeException('instagram_account_required');
        // Instagram professional accounts are connected to a Page; locate that Page token without persisting it.
        $cfgPage=self::configuredPageId();
        if($cfgPage!==''&&self::configuredPageToken()!=='')return [$instagramId,self::configuredPageToken(),$cfgPage];
        foreach(self::pages() as $p){if(!is_array($p))continue;$ig=(array)($p['instagram_business_account']??[]);if((string)($ig['id']??'')===$instagramId){$token=trim((string)($p['access_token']??''));if($token!=='')return [$instagramId,$token,(string)($p['id']??'')];}}
        [$pageId,$token]=self::pageToken();return [$instagramId,$token,$pageId];
    }
    public static function createInstagramContainer(string $instagramId,string $mediaUrl,string $caption='',bool $reel=false):array{
        [$instagramId,$token]=self::instagramTokenFor($instagramId);$mediaUrl=Security::publicUrl($mediaUrl);$form=['caption'=>pb_substr(trim($caption),0,2200)];
        if($reel){$form['media_type']='REELS';$form['video_url']=$mediaUrl;$form['share_to_feed']='true';}else{$form['image_url']=$mediaUrl;}
        return HttpClient::form('POST',self::base().'/'.rawurlencode($instagramId).'/media',self::bearer($token),$form,60);
    }
    public static function instagramContainerStatus(string $instagramId,string $creationId):array{
        [, $token]=self::instagramTokenFor($instagramId);$creationId=trim($creationId);if($creationId==='')throw new RuntimeException('instagram_creation_id_required');
        return HttpClient::json('GET',self::base().'/'.rawurlencode($creationId).'?fields='.rawurlencode('id,status_code,status'),self::bearer($token),null,35);
    }
    public static function metrics(string $platform,string $accountRef,string $objectId):array{
        $platform=strtolower(trim($platform));$objectId=trim($objectId);if($objectId==='')throw new RuntimeException('meta_social_object_id_required');
        if($platform==='facebook'){[, $token]=self::pageToken($accountRef);$r=HttpClient::json('GET',self::base().'/'.rawurlencode($objectId).'?fields='.rawurlencode('id,shares,comments.limit(0).summary(true),likes.limit(0).summary(true)'),self::bearer($token),null,35);return ['likes'=>(int)($r['likes']['summary']['total_count']??0),'comments'=>(int)($r['comments']['summary']['total_count']??0),'shares'=>(int)($r['shares']['count']??0),'raw'=>$r];}
        if($platform==='instagram'){[, $token]=self::instagramTokenFor($accountRef);$r=HttpClient::json('GET',self::base().'/'.rawurlencode($objectId).'?fields='.rawurlencode('id,like_count,comments_count,permalink,media_type'),self::bearer($token),null,35);return ['likes'=>(int)($r['like_count']??0),'comments'=>(int)($r['comments_count']??0),'shares'=>0,'raw'=>$r];}
        throw new RuntimeException('meta_social_metrics_platform_unsupported');
    }
    public static function publishInstagramContainer(string $instagramId,string $creationId):array{
        [$instagramId,$token]=self::instagramTokenFor($instagramId);$r=HttpClient::form('POST',self::base().'/'.rawurlencode($instagramId).'/media_publish',self::bearer($token),['creation_id'=>$creationId],60);
        $id=trim((string)($r['id']??''));if($id==='')throw new RuntimeException('instagram_publish_missing_id');
        try{$m=HttpClient::json('GET',self::base().'/'.rawurlencode($id).'?fields='.rawurlencode('id,permalink,media_type,timestamp'),self::bearer($token),null,35);$r['media']=$m;}catch(Throwable){}
        return $r;
    }
}
