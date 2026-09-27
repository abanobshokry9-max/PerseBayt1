<?php
declare(strict_types=1);

final class SocialMediaService {
    private const PLATFORMS=['youtube','tiktok','whatsapp','telegram','facebook','instagram','other'];
    private const CONTENT_TYPES=['text','image','video','short','reel','story'];

    private static function platform(string $platform):string{
        $platform=strtolower(trim($platform));
        if(!in_array($platform,self::PLATFORMS,true))throw new RuntimeException('social_platform_invalid');
        return $platform;
    }
    private static function safeText(string $value,int $limit):string{
        $value=trim($value);if($value!=='')MemoryService::assertSafeMemory($value);return pb_substr($value,0,$limit);
    }
    public static function accounts():array{
        return db()->query("SELECT a.*,ag.display_name owner_agent_name FROM social_accounts a LEFT JOIN agents ag ON ag.id=a.owner_agent_id ORDER BY FIELD(a.platform,'youtube','tiktok','whatsapp','telegram','facebook','instagram','other'),a.id")->fetchAll();
    }
    public static function account(int $id):array{
        $q=db()->prepare('SELECT * FROM social_accounts WHERE id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new RuntimeException('social_account_not_found');return $row;
    }
    public static function upsertAccount(string $platform,string $externalRef,string $displayName,int $ownerAgentId=0,array $metadata=[]):int{
        $platform=self::platform($platform);$externalRef=self::safeText($externalRef,190);$displayName=self::safeText($displayName,190);if($displayName==='')throw new RuntimeException('social_account_name_required');if($ownerAgentId>0)AgentService::byId($ownerAgentId);
        $q=db()->prepare('SELECT id FROM social_accounts WHERE platform=? AND external_ref=? LIMIT 1');$q->execute([$platform,$externalRef]);$id=(int)($q->fetchColumn()?:0);
        if($id){db()->prepare("UPDATE social_accounts SET display_name=?,owner_agent_id=?,metadata_json=?,status='active',updated_at=NOW() WHERE id=?")->execute([$displayName,$ownerAgentId?:null,$metadata?j($metadata):null,$id]);}
        else{db()->prepare("INSERT INTO social_accounts(platform,external_ref,display_name,owner_agent_id,status,metadata_json) VALUES (?,?,?,?, 'active',?)")->execute([$platform,$externalRef,$displayName,$ownerAgentId?:null,$metadata?j($metadata):null]);$id=(int)db()->lastInsertId();}
        $actor=Audit::actor();Audit::log((string)$actor['type'],(string)$actor['id'],'social.account_upsert','social_account',(string)$id,'verified',null,null,['platform'=>$platform]);return $id;
    }
    public static function contacts(int $limit=150):array{
        $limit=max(1,min(500,$limit));return db()->query("SELECT c.*,a.display_name owner_agent_name FROM social_contacts c LEFT JOIN agents a ON a.id=c.owner_agent_id ORDER BY c.updated_at DESC,c.id DESC LIMIT ".$limit)->fetchAll();
    }
    public static function upsertContact(int $agentId,string $platform,string $externalRef,string $displayName='',string $phone='',string $email='',string $username='',string $memberRole='',array $tags=[],string $notes=''):int{
        AgentService::byId($agentId);Permissions::requireAgent($agentId,'social.contacts.write');$platform=self::platform($platform);$externalRef=self::safeText($externalRef,190);$displayName=self::safeText($displayName,190);$phone=pb_substr(trim($phone),0,80);$email=pb_substr(trim($email),0,190);$username=self::safeText($username,190);$memberRole=self::safeText($memberRole,120);$notes=self::safeText($notes,4000);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('social_contact_email_invalid');if($externalRef===''&&$phone===''&&$email===''&&$username==='')throw new RuntimeException('social_contact_reference_required');
        $id=0;if($externalRef!==''){$q=db()->prepare('SELECT id FROM social_contacts WHERE source_platform=? AND external_ref=? LIMIT 1');$q->execute([$platform,$externalRef]);$id=(int)($q->fetchColumn()?:0);}if(!$id&&$phone!==''){$q=db()->prepare('SELECT id FROM social_contacts WHERE phone=? LIMIT 1');$q->execute([$phone]);$id=(int)($q->fetchColumn()?:0);}if(!$id&&$email!==''){$q=db()->prepare('SELECT id FROM social_contacts WHERE email=? LIMIT 1');$q->execute([$email]);$id=(int)($q->fetchColumn()?:0);}if(!$id&&$username!==''){$q=db()->prepare('SELECT id FROM social_contacts WHERE source_platform=? AND username=? LIMIT 1');$q->execute([$platform,$username]);$id=(int)($q->fetchColumn()?:0);}
        $tagJson=$tags?j(array_values(array_unique(array_map('strval',$tags)))):null;
        if($id){db()->prepare("UPDATE social_contacts SET external_ref=COALESCE(NULLIF(?,''),external_ref),display_name=COALESCE(NULLIF(?,''),display_name),phone=COALESCE(NULLIF(?,''),phone),email=COALESCE(NULLIF(?,''),email),username=COALESCE(NULLIF(?,''),username),member_role=COALESCE(NULLIF(?,''),member_role),tags_json=COALESCE(?,tags_json),notes=COALESCE(NULLIF(?,''),notes),owner_agent_id=?,last_interaction_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$externalRef,$displayName,$phone,$email,$username,$memberRole,$tagJson,$notes,$agentId,$id]);}
        else{db()->prepare("INSERT INTO social_contacts(source_platform,external_ref,display_name,phone,email,username,member_role,tags_json,notes,owner_agent_id,last_interaction_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")->execute([$platform,$externalRef?:null,$displayName?:null,$phone?:null,$email?:null,$username?:null,$memberRole?:null,$tagJson,$notes?:null,$agentId]);$id=(int)db()->lastInsertId();}
        $actor=Audit::actor();Audit::log((string)$actor['type'],(string)$actor['id'],'social.contact_upsert','social_contact',(string)$id,'verified',null,null,['platform'=>$platform,'owner_agent_id'=>$agentId]);return $id;
    }

    public static function recordInteraction(int $agentId,string $platform,string $direction,string $interactionType,string $externalId='',string $body='',?int $contactId=null,array $payload=[],?string $happenedAt=null):int{
        AgentService::byId($agentId);Permissions::requireAgent($agentId,'social.contacts.write');$platform=self::platform($platform);$direction=in_array($direction,['inbound','outbound','system'],true)?$direction:'system';$interactionType=in_array($interactionType,['message','comment','member_event','publication','note','other'],true)?$interactionType:'other';$externalId=self::safeText($externalId,190);$body=self::safeText($body,12000);if($contactId){$q=db()->prepare('SELECT id FROM social_contacts WHERE id=?');$q->execute([$contactId]);if(!$q->fetchColumn())throw new RuntimeException('social_contact_not_found');}if($payload)MemoryService::assertSafeMemory(j($payload));$when=$happenedAt&&strtotime($happenedAt)!==false?date('Y-m-d H:i:s',strtotime($happenedAt)):now_utc();
        if($externalId!==''){$q=db()->prepare('SELECT id FROM social_interactions WHERE platform=? AND external_id=? LIMIT 1');$q->execute([$platform,$externalId]);$existing=(int)($q->fetchColumn()?:0);if($existing)return $existing;}
        db()->prepare('INSERT INTO social_interactions(contact_id,owner_agent_id,platform,direction,interaction_type,external_id,body_text,payload_json,happened_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$contactId,$agentId,$platform,$direction,$interactionType,$externalId?:null,$body?:null,$payload?j($payload):null,$when]);$id=(int)db()->lastInsertId();if($contactId)db()->prepare('UPDATE social_contacts SET last_interaction_at=?,updated_at=NOW() WHERE id=?')->execute([$when,$contactId]);return $id;
    }
    public static function interactions(int $limit=200):array{$limit=max(1,min(500,$limit));return db()->query("SELECT i.*,c.display_name contact_name,c.username contact_username,a.display_name owner_agent_name FROM social_interactions i LEFT JOIN social_contacts c ON c.id=i.contact_id LEFT JOIN agents a ON a.id=i.owner_agent_id ORDER BY i.happened_at DESC,i.id DESC LIMIT ".$limit)->fetchAll();}

    public static function draftContent(int $agentId,?int $projectId,string $contentType,string $title,string $caption,string $prompt='',?int $assetId=null,?string $scheduledAt=null):int{
        AgentService::byId($agentId);Permissions::requireAgent($agentId,'social.content.write');$contentType=strtolower(trim($contentType));if(!in_array($contentType,self::CONTENT_TYPES,true))$contentType='text';if($projectId)ProjectService::get($projectId);if($assetId){$q=db()->prepare('SELECT id FROM project_assets WHERE id=?');$q->execute([$assetId]);if(!$q->fetchColumn())throw new RuntimeException('project_asset_not_found');}$title=self::safeText($title,220);$caption=self::safeText($caption,12000);$prompt=self::safeText($prompt,12000);if($title===''&&$caption==='')throw new RuntimeException('social_content_empty');if($scheduledAt!==null&&trim($scheduledAt)!==''&&strtotime($scheduledAt)===false)throw new RuntimeException('social_schedule_invalid');
        db()->prepare("INSERT INTO social_content(created_by_agent_id,project_id,content_type,title,caption,prompt_text,asset_id,status,owner_approved,scheduled_at) VALUES (?,?,?,?,?,?,?,'draft',0,?)")->execute([$agentId,$projectId,$contentType,$title?:null,$caption?:null,$prompt?:null,$assetId,$scheduledAt&&trim($scheduledAt)!==''?date('Y-m-d H:i:s',strtotime($scheduledAt)):null]);$id=(int)db()->lastInsertId();$actor=Audit::actor();Audit::log((string)$actor['type'],(string)$actor['id'],'social.content_draft','social_content',(string)$id,'verified',$projectId,null,['content_type'=>$contentType]);return $id;
    }
    public static function content(int $id):array{$q=db()->prepare('SELECT * FROM social_content WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('social_content_not_found');return $r;}
    public static function approveContent(int $id):void{self::content($id);db()->prepare("UPDATE social_content SET owner_approved=1,status=IF(status='draft','approved',status),approved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);Audit::log('owner','1','social.content_approve','social_content',(string)$id,'verified');}
    public static function publications(int $limit=100):array{$limit=max(1,min(300,$limit));return db()->query("SELECT p.*,c.title,c.content_type,a.display_name account_name FROM social_publications p JOIN social_content c ON c.id=p.content_id LEFT JOIN social_accounts a ON a.id=p.account_id ORDER BY p.id DESC LIMIT ".$limit)->fetchAll();}
    public static function recordPublication(int $contentId,?int $accountId,string $platform,string $state,string $externalId='',string $externalUrl='',array $providerResponse=[],string $errorCode=''):int{
        self::content($contentId);if($accountId)self::account($accountId);$platform=self::platform($platform);$state=in_array($state,['queued','publishing','published','failed'],true)?$state:'failed';db()->prepare('INSERT INTO social_publications(content_id,account_id,platform,state,external_post_id,external_url,provider_response_json,error_code,published_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$contentId,$accountId,$platform,$state,$externalId?:null,$externalUrl?:null,$providerResponse?j($providerResponse):null,$errorCode?:null,$state==='published'?now_utc():null]);$id=(int)db()->lastInsertId();db()->prepare('UPDATE social_content SET status=?,updated_at=NOW() WHERE id=?')->execute([$state==='published'?'published':($state==='failed'?'failed':'publishing'),$contentId]);return $id;
    }
    public static function summary():array{
        $out=[];foreach(['social_accounts','social_contacts','social_interactions','social_content','social_publications','video_productions'] as $t){try{$out[$t]=(int)db()->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();}catch(Throwable){$out[$t]=0;}}return $out;
    }
}
