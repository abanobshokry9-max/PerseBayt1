<?php
declare(strict_types=1);
final class Permissions {
    public static function agent(int $agentId,string $key):bool{$q=db()->prepare('SELECT allowed FROM agent_permissions WHERE agent_id=? AND permission_key=?');$q->execute([$agentId,$key]);return (int)$q->fetchColumn()===1;}
    public static function requireAgent(int $agentId,string $key):void{if(!self::agent($agentId,$key))throw new RuntimeException('permission_denied:'.$key);}
    public static function dangerous(string $key):bool{$q=db()->prepare('SELECT dangerous FROM permissions WHERE permission_key=?');$q->execute([$key]);return (int)$q->fetchColumn()===1;}
    public static function projectScope(int $agentId,int $projectId):string{$q=db()->prepare('SELECT access_scope FROM agent_project_access WHERE agent_id=? AND project_id=?');$q->execute([$agentId,$projectId]);return (string)($q->fetchColumn()?:'');}
    public static function project(int $agentId,int $projectId,string $needed='read'):bool{$rank=[''=>0,'read'=>1,'work'=>2,'manage'=>3];return ($rank[self::projectScope($agentId,$projectId)]??0)>=($rank[$needed]??99);}
    public static function requireProject(int $agentId,int $projectId,string $needed='read'):void{if(!self::project($agentId,$projectId,$needed))throw new RuntimeException('project_access_denied:'.$needed);}
}
