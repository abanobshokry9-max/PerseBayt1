<?php
declare(strict_types=1);
final class VideoProductionService {
    public static function create(int $agentId,?int $projectId,int $taskId,string $title,string $concept,int $durationSeconds=30,string $aspectRatio='16:9',string $targetPlatform='youtube'):array{
        return VideoStudioService::create($agentId,$projectId,$taskId,$title,$concept,$durationSeconds,$aspectRatio,$targetPlatform);
    }
    public static function get(int $id):array{$q=db()->prepare('SELECT * FROM video_productions WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('video_production_not_found');return $r;}
    public static function approve(int $id):void{self::get($id);db()->prepare('UPDATE video_productions SET owner_approved=1,approved_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$id]);Audit::log('owner','1','video.production_approve','video_production',(string)$id,'verified');}
    public static function uploadYouTube(int $agentId,int $productionId,string $privacy='private',string $description=''):array{
        Permissions::requireAgent($agentId,'youtube.upload');$p=self::get($productionId);if(!(int)$p['owner_approved'])throw new RuntimeException('owner_approval_required:youtube.upload');if(empty($p['asset_id']))throw new RuntimeException('video_asset_not_ready');$r=YouTubeClient::uploadAsset((int)$p['asset_id'],(string)$p['title'],$description,$privacy);$videoId=(string)($r['id']??'');db()->prepare("UPDATE video_productions SET state='published',youtube_video_id=?,published_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$videoId?:null,$productionId]);return $r;
    }
}
