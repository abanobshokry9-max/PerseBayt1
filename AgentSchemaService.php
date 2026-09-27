<?php
declare(strict_types=1);
/** Compatibility facade for the Company OS 13 schema API. */
final class AgentSchemaService {
    public static function propose(int $agentId,string $label,array $fields,string $reason=''):int{return PhysicalSchemaService::request($agentId,$label,$fields,$reason);}
    public static function applyApproved(int $requestId,int $ownerId):array{return PhysicalSchemaService::approveAndApply($requestId,$ownerId);}
    public static function requests(int $limit=100):array{$rows=PhysicalSchemaService::pending();return array_slice($rows,0,max(1,min(300,$limit)));}
}
