<?php
declare(strict_types=1);

/**
 * Keeps Ramy's operational authority in sync with the Company OS.
 * Raw secret disclosure is deliberately excluded: Ramy may use secrets via
 * configured clients, but never receives secrets.view.
 */
final class RamiAuthorityService {
    public static function sync(bool $syncAi=true): array {
        $q=db()->query("SELECT id FROM agents WHERE slug='ramy' LIMIT 1");
        $rid=(int)($q->fetchColumn()?:0);
        if($rid<1)throw new RuntimeException('ramy_not_found');

        $pdo=db();$pdo->beginTransaction();
        try{
            // Every registered operational permission, except viewing raw secrets.
            $pdo->prepare("INSERT INTO agent_permissions(agent_id,permission_key,allowed) SELECT ?,permission_key,1 FROM permissions WHERE permission_key<>'secrets.view' ON DUPLICATE KEY UPDATE allowed=1")->execute([$rid]);
            $pdo->prepare("INSERT INTO agent_permissions(agent_id,permission_key,allowed) VALUES (?,'secrets.view',0) ON DUPLICATE KEY UPDATE allowed=0")->execute([$rid]);

            // Superset of tools used by every agent + Ramy/hosting tools that may not
            // currently be attached to another agent.
            $tools=$pdo->query("SELECT DISTINCT tool_key FROM agent_tools WHERE allowed=1")->fetchAll(PDO::FETCH_COLUMN);
            $mandatory=['ai_code','api_test','backup','communications','database','database_read','data_studio','deploy','hostinger','hostinger_discovery','hostinger_files','http_test','logs','master_brief','media_generation','memory','meta_whatsapp','notifications','opportunity_hunter','project_chat','project_files','projects','reporting','task_context','task_orchestrator','team_chat','web_search','social_media','video_production','youtube','tiktok','telegram'];
            $tools=array_values(array_unique(array_filter(array_merge(array_map('strval',$tools),$mandatory))));
            $toolStmt=$pdo->prepare("INSERT INTO agent_tools(agent_id,tool_key,allowed,config_json) VALUES (?,?,1,'{}') ON DUPLICATE KEY UPDATE allowed=1");
            foreach($tools as $tool)$toolStmt->execute([$rid,$tool]);

            // Ramy manages every current project. New projects are covered the next
            // time this sync runs and by the per-intent project guard below.
            $pdo->prepare("INSERT INTO agent_project_access(agent_id,project_id,access_scope) SELECT ?,id,'manage' FROM projects ON DUPLICATE KEY UPDATE access_scope='manage'")->execute([$rid]);

            // Ramy can coordinate every agent directly; other agents can report to him.
            $agents=$pdo->query("SELECT id FROM agents WHERE id<>".$rid." AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            $relOut=$pdo->prepare("INSERT INTO agent_relationships(from_agent_id,to_agent_id,can_message,can_start,requires_manager_approval) VALUES (?,?,1,1,0) ON DUPLICATE KEY UPDATE can_message=1,can_start=1,requires_manager_approval=0");
            $relIn=$pdo->prepare("INSERT INTO agent_relationships(from_agent_id,to_agent_id,can_message,can_start,requires_manager_approval) VALUES (?,?,1,0,0) ON DUPLICATE KEY UPDATE can_message=1,requires_manager_approval=0");
            foreach($agents as $aid){$aid=(int)$aid;$relOut->execute([$rid,$aid]);$relIn->execute([$aid,$rid]);}

            // Preserve the owner-first model while letting Ramy operate every configured
            // communication channel without a secondary Ramy approval loop.
            $channels=$pdo->query("SELECT DISTINCT channel_key FROM agent_channel_permissions")->fetchAll(PDO::FETCH_COLUMN);
            $channelStmt=$pdo->prepare("INSERT INTO agent_channel_permissions(agent_id,channel_key,can_send_owner,can_receive_owner,can_start,requires_ramy_approval,emergency_enabled,rate_limit_per_hour) VALUES (?,?,1,1,1,0,1,120) ON DUPLICATE KEY UPDATE can_send_owner=1,can_receive_owner=1,can_start=1,requires_ramy_approval=0,emergency_enabled=1,rate_limit_per_hour=GREATEST(rate_limit_per_hour,120)");
            foreach($channels as $channel)if(trim((string)$channel)!=='')$channelStmt->execute([$rid,(string)$channel]);

            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

        $aiPromoted=0;
        if($syncAi&&OpenRouterService::configured()){
            try{$aiPromoted=OpenRouterService::promoteAllAgents();}catch(Throwable $e){error_log('ELMETR ramy authority openrouter sync: '.Security::redactSecrets($e->getMessage(),220));}
        }
        try{put_setting('ramy.authority_last_sync_at',now_utc());put_setting('ramy.authority_mode','all_operational_except_raw_secrets');}catch(Throwable){}
        return ['ramy_id'=>$rid,'permissions'=>'all_operational_except_secrets.view','tools'=>count($tools),'projects'=>'all_manage','agents'=>count($agents),'openrouter_promoted'=>$aiPromoted];
    }

    public static function ensureProject(int $projectId):void{
        if($projectId<1)return;
        $q=db()->query("SELECT id FROM agents WHERE slug='ramy' LIMIT 1");$rid=(int)($q->fetchColumn()?:0);if($rid<1)return;
        db()->prepare("INSERT INTO agent_project_access(agent_id,project_id,access_scope) VALUES (?,?,'manage') ON DUPLICATE KEY UPDATE access_scope='manage'")->execute([$rid,$projectId]);
    }
}
