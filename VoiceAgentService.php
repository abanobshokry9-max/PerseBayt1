<?php
declare(strict_types=1);
/** Compatibility facade. VoiceAgentCenterService is canonical in Company OS 20. */
final class VoiceAgentService {
    public static function createConversation(int $agentId,string $channel='dashboard',string $direction='inbound',?int $contactId=null):int{return VoiceAgentCenterService::createConversation($agentId,$channel,'internal');}
    public static function processOwnerAudio(int $agentId,string $audioPath):array{return VoiceAgentCenterService::processAudio($agentId,$audioPath);}
    public static function startOwnerCall(int $agentId):array{return VoiceAgentCenterService::startOwnerCall($agentId,'whatsapp');}
    public static function recent(int $limit=100):array{return array_slice(VoiceAgentCenterService::recent(max(1,min(300,$limit))),0,$limit);}
    public static function turn(int $id):?array{try{$q=db()->prepare('SELECT t.*,v.agent_id,a.display_name agent_name FROM voice_turns t JOIN voice_conversations v ON v.id=t.conversation_id JOIN agents a ON a.id=v.agent_id WHERE t.id=?');$q->execute([$id]);return $q->fetch()?:null;}catch(Throwable){return null;}}
}
