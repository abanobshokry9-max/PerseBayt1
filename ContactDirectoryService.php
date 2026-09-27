<?php
declare(strict_types=1);

final class ContactDirectoryService {
    public static function all(bool $enabledOnly=false): array {
        $where=$enabledOnly?' WHERE c.enabled=1':'';
        return db()->query("SELECT c.* FROM contact_directory c".$where." ORDER BY c.enabled DESC,c.group_key,c.display_name,c.id")->fetchAll();
    }

    public static function get(int $id): array {
        $q=db()->prepare('SELECT * FROM contact_directory WHERE id=?');$q->execute([$id]);$r=$q->fetch();
        if(!$r)throw new RuntimeException('contact_not_found');return $r;
    }

    public static function save(array $d,?int $id=null): int {
        $name=trim((string)($d['display_name']??''));if($name==='')throw new RuntimeException('contact_name_required');
        $role=trim((string)($d['role_title']??''));$phone=self::digits((string)($d['phone']??''));$wa=self::digits((string)($d['whatsapp_phone']??''));
        $email=trim((string)($d['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('email_invalid');
        $group=trim((string)($d['group_key']??''));$enabled=!empty($d['enabled'])?1:0;
        $meta=(array)($d['metadata']??[]);$notes=trim((string)($d['notes']??''));
        $vals=[pb_substr($name,0,190),$role!==''?pb_substr($role,0,190):null,$phone?:null,$wa?:null,$email!==''?pb_substr($email,0,190):null,pb_substr(trim((string)($d['telegram_ref']??'')),0,190)?:null,pb_substr(trim((string)($d['instagram_ref']??'')),0,190)?:null,pb_substr(trim((string)($d['tiktok_ref']??'')),0,190)?:null,$group!==''?pb_substr($group,0,120):null,$enabled,$meta?j($meta):null,pb_substr($notes,0,8000)?:null];
        if($id){self::get($id);$q=db()->prepare('UPDATE contact_directory SET display_name=?,role_title=?,phone=?,whatsapp_phone=?,email=?,telegram_ref=?,instagram_ref=?,tiktok_ref=?,group_key=?,enabled=?,metadata_json=?,notes=? WHERE id=?');$q->execute(array_merge($vals,[$id]));$out=$id;}
        else{$q=db()->prepare('INSERT INTO contact_directory(display_name,role_title,phone,whatsapp_phone,email,telegram_ref,instagram_ref,tiktok_ref,group_key,enabled,metadata_json,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute($vals);$out=(int)db()->lastInsertId();}
        Audit::log('owner',(string)(Auth::user()['id']??1),'contact.save','contact_directory',(string)$out,'verified',null,null,['enabled'=>(bool)$enabled,'group_key'=>$group]);
        return $out;
    }

    public static function toggle(int $id,bool $enabled): void {
        self::get($id);db()->prepare('UPDATE contact_directory SET enabled=? WHERE id=?')->execute([$enabled?1:0,$id]);
        Audit::log('owner',(string)(Auth::user()['id']??1),'contact.toggle','contact_directory',(string)$id,'verified',null,null,['enabled'=>$enabled]);
    }

    public static function whatsappNumber(int $id): string {
        $r=self::get($id);if(!(int)$r['enabled'])throw new RuntimeException('contact_disabled');$n=self::digits((string)($r['whatsapp_phone']?:$r['phone']));if($n==='')throw new RuntimeException('contact_whatsapp_missing');return $n;
    }

    private static function digits(string $v): string {return preg_replace('/\D+/','',$v)?:'';}
}
