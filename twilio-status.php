<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
$base=rtrim((string)config('app.base_url'),' /');$query=(string)($_SERVER['QUERY_STRING']??'');$url=$base.'/webhooks/twilio-status.php'.($query!==''?'?'.$query:'');
if(!TwilioClient::verify($url,$_POST,(string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''))){http_response_code(401);exit;}
$sid=(string)($_POST['CallSid']??'');$st=(string)($_POST['CallStatus']??'');$vc=(int)($_GET['vc']??0);$map=['queued'=>'requested','initiated'=>'requested','ringing'=>'ringing','in-progress'=>'connected','completed'=>'completed','busy'=>'failed','failed'=>'failed','no-answer'=>'failed','canceled'=>'failed'];$state=$map[$st]??'requested';try{db()->prepare('UPDATE calls SET state=?,ended_at=IF(? IN (\'completed\',\'failed\'),NOW(),ended_at),raw_json=? WHERE provider_call_id=?')->execute([$state,$state,j($_POST),$sid]);}catch(Throwable){}if($vc>0){try{VoiceAgentCenterService::updateCallState($vc,$state,$sid);}catch(Throwable){}}http_response_code(204);
