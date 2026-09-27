<?php
declare(strict_types=1);

final class ConversationService {
    public static function ownerSession():array{
        $q=db()->query("SELECT * FROM conversation_sessions WHERE subject_type='owner' AND subject_key='owner:1' AND status='open' ORDER BY id DESC LIMIT 1");
        $s=$q->fetch();
        if($s)return $s;
        $ramy=AgentService::bySlug('ramy');
        db()->prepare("INSERT INTO conversation_sessions(subject_type,subject_key,status,last_agent_id) VALUES ('owner','owner:1','open',?)")->execute([$ramy['id']]);
        return self::session((int)db()->lastInsertId());
    }

    public static function session(int $id):array{
        $q=db()->prepare('SELECT * FROM conversation_sessions WHERE id=?');
        $q->execute([$id]);$s=$q->fetch();
        if(!$s)throw new RuntimeException('session_not_found');
        return $s;
    }

    public static function normalizePhone(string $phone):string{
        $digits=preg_replace('/\D+/','',$phone)??'';
        if(str_starts_with($digits,'00'))$digits=substr($digits,2);
        if(preg_match('/^01[0125][0-9]{8}$/',$digits))$digits='20'.substr($digits,1);
        elseif(preg_match('/^1[0125][0-9]{8}$/',$digits))$digits='20'.$digits;
        return strlen($digits)>=8 && strlen($digits)<=16 ? $digits : '';
    }

    public static function phoneDisplay(string $phone):string{
        $digits=self::normalizePhone($phone);
        return $digits!==''?'+'.$digits:trim($phone);
    }

    private static function phoneVariants(string $phone):array{
        $digits=self::normalizePhone($phone);
        if($digits==='')return [];
        $variants=[$digits];
        if(str_starts_with($digits,'20')&&strlen($digits)===12)$variants[]='0'.substr($digits,2);
        return array_values(array_unique($variants));
    }

    private static function syncCustomerPhone(int $customerId,string $phone,string $channel=''):void{
        $digits=self::normalizePhone($phone);
        if($customerId<1||$digits==='')return;
        $q=db()->prepare('SELECT phone,primary_channel FROM customers WHERE id=?');$q->execute([$customerId]);$c=$q->fetch();
        if(!$c)return;
        $current=self::normalizePhone((string)($c['phone']??''));
        $primary=trim((string)($c['primary_channel']??''));
        if($current!==$digits||($channel!==''&&$primary==='')){
            db()->prepare('UPDATE customers SET phone=?,primary_channel=CASE WHEN ?<>\'\' THEN ? ELSE primary_channel END,updated_at=NOW() WHERE id=?')->execute([$digits,$channel,$channel,$customerId]);
        }
    }

    public static function ownerConversation(string $channel,string $provider):array{
        $s=self::ownerSession();
        $q=db()->prepare("SELECT * FROM conversations WHERE session_id=? AND channel_key=? AND provider=? AND status='open' ORDER BY id DESC LIMIT 1");
        $q->execute([$s['id'],$channel,$provider]);$c=$q->fetch();
        if($c)return $c;
        db()->prepare("INSERT INTO conversations(session_id,channel_key,provider,status) VALUES (?,?,?,'open')")->execute([$s['id'],$channel,$provider]);
        return self::conversation((int)db()->lastInsertId());
    }

    public static function customerConversation(string $channel,string $provider,string $externalThread,string $phone=''):array{
        $trustedPhone=self::normalizePhone($phone);
        if($trustedPhone===''&&in_array($channel,['whatsapp','sms','voice'],true))$trustedPhone=self::normalizePhone($externalThread);

        $existingCustomerId=null;
        $q=db()->prepare('SELECT * FROM conversations WHERE provider=? AND external_thread_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([$provider,$externalThread]);$c=$q->fetch();
        if($c){
            if(!empty($c['customer_id']))$existingCustomerId=(int)$c['customer_id'];
            if($existingCustomerId&&$trustedPhone!=='')self::syncCustomerPhone($existingCustomerId,$trustedPhone,$channel);
            // Keep an open thread, and preserve a blocked thread so the gateway can suppress replies.
            // A closed thread should start a fresh session when the customer writes again.
            if(in_array((string)$c['status'],['open','blocked'],true))return $c;
        }

        $cust=null;
        if($existingCustomerId){$q=db()->prepare('SELECT * FROM customers WHERE id=? LIMIT 1');$q->execute([$existingCustomerId]);$cust=$q->fetch()?:null;}
        foreach(self::phoneVariants($trustedPhone) as $variant){
            $q=db()->prepare('SELECT * FROM customers WHERE phone=? ORDER BY id ASC LIMIT 1');
            $q->execute([$variant]);$cust=$q->fetch()?:null;
            if($cust)break;
        }
        if(!$cust&&$externalThread!==''){
            $q=db()->prepare('SELECT * FROM customers WHERE external_ref=? ORDER BY id ASC LIMIT 1');
            $q->execute([$externalThread]);$cust=$q->fetch()?:null;
        }

        if(!$cust){
            db()->prepare("INSERT INTO customers(phone,external_ref,primary_channel,status) VALUES (?,?,?,'lead')")->execute([$trustedPhone?:null,$externalThread?:null,$channel]);
            $cid=(int)db()->lastInsertId();
        }else{
            $cid=(int)$cust['id'];
            if($trustedPhone!=='')self::syncCustomerPhone($cid,$trustedPhone,$channel);
            if(trim((string)($cust['external_ref']??''))===''&&$externalThread!=='')db()->prepare('UPDATE customers SET external_ref=?,updated_at=NOW() WHERE id=?')->execute([$externalThread,$cid]);
        }

        $subject='customer:'.$cid;
        $q=db()->prepare("SELECT * FROM conversation_sessions WHERE subject_type='customer' AND subject_key=? AND status='open' ORDER BY id DESC LIMIT 1");
        $q->execute([$subject]);$s=$q->fetch();
        if(!$s){
            $ramy=AgentService::bySlug('ramy');
            db()->prepare("INSERT INTO conversation_sessions(subject_type,subject_key,status,last_agent_id) VALUES ('customer',?,'open',?)")->execute([$subject,$ramy['id']]);
            $sid=(int)db()->lastInsertId();
        }else $sid=(int)$s['id'];

        db()->prepare("INSERT INTO conversations(session_id,channel_key,provider,customer_id,external_thread_id,status) VALUES (?,?,?,?,?,'open')")->execute([$sid,$channel,$provider,$cid,$externalThread]);
        return self::conversation((int)db()->lastInsertId());
    }

    public static function customerConversationFor(int $customerId,string $channel,string $provider,string $externalThread=''):array{
        if($customerId<1)throw new RuntimeException('customer_not_found');
        $q=db()->prepare('SELECT id,status FROM customers WHERE id=? LIMIT 1');$q->execute([$customerId]);$customer=$q->fetch();if(!$customer)throw new RuntimeException('customer_not_found');
        $q=db()->prepare("SELECT * FROM conversations WHERE customer_id=? AND channel_key=? AND provider=? AND status='open' ORDER BY id DESC LIMIT 1");$q->execute([$customerId,$channel,$provider]);$c=$q->fetch();if($c)return $c;
        $subject='customer:'.$customerId;$q=db()->prepare("SELECT * FROM conversation_sessions WHERE subject_type='customer' AND subject_key=? AND status='open' ORDER BY id DESC LIMIT 1");$q->execute([$subject]);$session=$q->fetch();
        if(!$session){$ramy=AgentService::bySlug('ramy');db()->prepare("INSERT INTO conversation_sessions(subject_type,subject_key,status,last_agent_id) VALUES ('customer',?,'open',?)")->execute([$subject,$ramy['id']]);$sid=(int)db()->lastInsertId();}else $sid=(int)$session['id'];
        db()->prepare("INSERT INTO conversations(session_id,channel_key,provider,customer_id,external_thread_id,status) VALUES (?,?,?,?,?,'open')")->execute([$sid,$channel,$provider,$customerId,$externalThread!==''?$externalThread:null]);
        return self::conversation((int)db()->lastInsertId());
    }

    public static function conversation(int $id):array{
        $q=db()->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$c=$q->fetch();
        if(!$c)throw new RuntimeException('conversation_not_found');
        return $c;
    }

    public static function message(int $conversationId,string $channel,string $provider,string $direction,string $senderType,?string $senderRef,string $receiverType,?string $receiverRef,string $body,string $externalId='',string $type='text',array $raw=[]):int{
        $c=self::conversation($conversationId);
        $q=db()->prepare('INSERT INTO messages(conversation_id,session_id,channel_key,provider,direction,sender_type,sender_ref,receiver_type,receiver_ref,message_type,body_text,external_id,status,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$conversationId,$c['session_id'],$channel,$provider,$direction,$senderType,$senderRef,$receiverType,$receiverRef,$type,$body,$externalId?:null,$direction==='inbound'?'received':'sent',$raw?j($raw):null]);
        $id=(int)db()->lastInsertId();
        db()->prepare('UPDATE conversation_sessions SET last_activity_at=NOW() WHERE id=?')->execute([$c['session_id']]);
        return $id;
    }

    public static function history(int $sessionId,int $limit=20):array{
        $limit=max(1,min(50,$limit));
        $q=db()->prepare("SELECT m.*,a.display_name agent_name FROM messages m LEFT JOIN agents a ON m.sender_type='agent' AND a.slug=m.sender_ref WHERE m.session_id=? ORDER BY m.id DESC LIMIT ".$limit);
        $q->execute([$sessionId]);
        return array_reverse($q->fetchAll());
    }

    public static function ownerRamyHistory(int $limit=30):array{
        $s=self::ownerSession();$limit=max(1,min(80,$limit));
        $sql="SELECT m.*,a.display_name agent_name FROM messages m LEFT JOIN agents a ON m.sender_type='agent' AND a.slug=m.sender_ref WHERE m.session_id=? AND (m.sender_type='owner' OR (m.sender_type='agent' AND m.sender_ref='ramy')) ORDER BY m.id DESC LIMIT ".$limit;
        $q=db()->prepare($sql);$q->execute([$s['id']]);
        return array_reverse($q->fetchAll());
    }

    public static function activeContext(int $sessionId):array{
        $s=self::session($sessionId);$project=null;$task=null;
        if($s['active_project_id']){$q=db()->prepare('SELECT id,name,primary_domain,status,technology FROM projects WHERE id=?');$q->execute([$s['active_project_id']]);$project=$q->fetch()?:null;}
        if($s['active_task_id']){try{$task=TaskService::get((int)$s['active_task_id']);}catch(Throwable){}}
        return ['session'=>$s,'project'=>$project,'task'=>$task,'history'=>self::history($sessionId,(int)setting('context.history_limit','20'))];
    }

    public static function focus(int $sessionId,?int $projectId,?int $taskId,?int $agentId=null):void{
        db()->prepare('UPDATE conversation_sessions SET active_project_id=?,active_task_id=?,last_agent_id=COALESCE(?,last_agent_id),last_activity_at=NOW() WHERE id=?')->execute([$projectId,$taskId,$agentId,$sessionId]);
    }
    public static function pending(int $sessionId):?array{$q=db()->prepare("SELECT * FROM pending_actions WHERE session_id=? AND state='pending' AND expires_at>NOW() ORDER BY id DESC LIMIT 1");$q->execute([$sessionId]);return $q->fetch()?:null;}
    public static function savePending(int $sessionId,array $plan,bool $confirm,string $risk='normal'):int{db()->prepare("UPDATE pending_actions SET state='cancelled' WHERE session_id=? AND state='pending'")->execute([$sessionId]);$q=db()->prepare("INSERT INTO pending_actions(session_id,action_type,plan_json,risk,state,requires_confirmation,expires_at) VALUES (?,?,?,?,'pending',?,DATE_ADD(NOW(),INTERVAL 12 HOUR))");$q->execute([$sessionId,$plan['intent']??'action',j($plan),$risk,$confirm?1:0]);return (int)db()->lastInsertId();}
    public static function close(int $sessionId):void{db()->prepare("UPDATE conversation_sessions SET status='closed',closed_at=NOW() WHERE id=?")->execute([$sessionId]);}
    public static function agentOwnerSession(string $slug):array{$key='owner:1-agent:'.$slug;$q=db()->prepare("SELECT * FROM conversation_sessions WHERE subject_type='agent_pair' AND subject_key=? AND status='open' ORDER BY id DESC LIMIT 1");$q->execute([$key]);$s=$q->fetch();if($s)return $s;$a=AgentService::bySlug($slug);$owner=self::ownerSession();db()->prepare("INSERT INTO conversation_sessions(subject_type,subject_key,status,last_agent_id,active_project_id,active_task_id) VALUES ('agent_pair',?,'open',?,?,?)")->execute([$key,$a['id'],$owner['active_project_id']?:null,$owner['active_task_id']?:null]);return self::session((int)db()->lastInsertId());}
}
