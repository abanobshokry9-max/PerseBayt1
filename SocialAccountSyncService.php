<?php
declare(strict_types=1);
final class SocialAccountSyncService {
    public static function syncAll(int $ownerAgentId=0):array{
        if($ownerAgentId<=0){try{$ownerAgentId=(int)AgentService::bySlug('samir-social')['id'];}catch(Throwable){$ownerAgentId=0;}}
        $out=['meta'=>null,'youtube'=>null,'tiktok'=>null,'telegram'=>null,'errors'=>[],'synced'=>0];
        if(MetaSocialClient::configured()){try{$out['meta']=MetaSocialClient::syncAccounts($ownerAgentId);$out['synced']+=(int)($out['meta']['synced']??0);}catch(Throwable $e){$out['errors']['meta_social']=Security::redactSecrets($e->getMessage(),180);}}
        if(YouTubeClient::configured()){try{$r=YouTubeClient::test();$id=(string)($r['channel_id']??'');if($id!==''){SocialMediaService::upsertAccount('youtube',$id,(string)($r['title']??('YouTube '.$id)),$ownerAgentId,['source'=>'youtube_api']);$out['youtube']=$r;$out['synced']++;}}catch(Throwable $e){$out['errors']['youtube']=Security::redactSecrets($e->getMessage(),180);}}
        if(TikTokClient::configured()){try{$r=TikTokClient::test();$user=(string)($r['creator_username']??'');$ref=$user!==''?$user:'configured_creator';SocialMediaService::upsertAccount('tiktok',$ref,$user!==''?'@'.$user:'TikTok Creator',$ownerAgentId,['source'=>'tiktok_api']);$out['tiktok']=$r;$out['synced']++;}catch(Throwable $e){$out['errors']['tiktok']=Security::redactSecrets($e->getMessage(),180);}}
        if(TelegramClient::configured()){try{$r=TelegramClient::test();$chat=TelegramClient::defaultChatId();$ref=$chat!==''?$chat:(string)($r['id']??'configured_bot');$name=(string)($r['username']??$r['name']??'Telegram');SocialMediaService::upsertAccount('telegram',$ref,$name,$ownerAgentId,['bot_id'=>$r['id']??null,'default_chat_id_configured'=>$chat!=='','source'=>'telegram_api']);$out['telegram']=$r;$out['synced']++;}catch(Throwable $e){$out['errors']['telegram']=Security::redactSecrets($e->getMessage(),180);}}
        return $out;
    }
}
