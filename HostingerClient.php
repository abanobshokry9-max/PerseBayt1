<?php
declare(strict_types=1);
final class HostingerClient {
    private const BASE='https://developers.hostinger.com';
    private static function token():string{$t=(string)config('hostinger.api_token','');if(strlen($t)<20)throw new RuntimeException('hostinger_not_configured');return $t;}
    private static function api(string $method,string $path,array $query=[],?array $body=null):array{
        $url=self::BASE.$path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
        try{return HttpClient::json($method,$url,['Authorization'=>'Bearer '.self::token()],$body,45);}catch(Throwable $e){$m=trim(Security::redactSecrets($e->getMessage(),500));$l=strtolower($m);if(str_starts_with($l,'http_401'))throw new RuntimeException('hostinger_http_401:'.$m,0,$e);if(str_starts_with($l,'http_403'))throw new RuntimeException('hostinger_http_403:'.$m,0,$e);throw $e;}
    }
    public static function websites():array{
        $out=[];for($page=1;$page<=10;$page++){$r=self::api('GET','/api/hosting/v1/websites',['page'=>$page,'per_page'=>100]);$rows=$r['data']??$r;if(!is_array($rows))break;foreach($rows as $x)if(is_array($x))$out[]=$x;$meta=$r['meta']??[];if(!$meta||count($rows)<100||($meta['current_page']??1)>=($meta['last_page']??1))break;}return $out;
    }
    public static function dnsZone(string $domain):array{$domain=Security::domain($domain);$r=self::api('GET','/api/dns/v1/zones/'.rawurlencode($domain));return is_array($r)?$r:[];}
    public static function dnsSnapshots(string $domain):array{$domain=Security::domain($domain);$r=self::api('GET','/api/dns/v1/snapshots/'.rawurlencode($domain));$rows=$r['data']??$r;return is_array($rows)?$rows:[];}
    public static function updateDnsZone(string $domain,array $zone,bool $overwrite=false):array{$domain=Security::domain($domain);if(!$zone)throw new RuntimeException('dns_zone_required');$clean=[];$allowed=['A','AAAA','CNAME','MX','TXT','CAA','SRV','NS'];foreach($zone as $rr){if(!is_array($rr))throw new RuntimeException('dns_record_invalid');$name=trim((string)($rr['name']??''));$type=strtoupper(trim((string)($rr['type']??'')));$ttl=(int)($rr['ttl']??14400);$records=$rr['records']??[];if($name===''||!in_array($type,$allowed,true)||!is_array($records)||!$records)throw new RuntimeException('dns_record_invalid');$out=[];foreach($records as $r){if(!is_array($r)||trim((string)($r['content']??''))==='')throw new RuntimeException('dns_content_invalid');$out[]=['content'=>trim((string)$r['content'])];}$clean[]=['name'=>$name,'type'=>$type,'ttl'=>max(60,min(86400,$ttl)),'records'=>$out];if(count($clean)>300)throw new RuntimeException('dns_record_limit');}$body=['overwrite'=>$overwrite,'zone'=>$clean];self::api('POST','/api/dns/v1/zones/'.rawurlencode($domain).'/validate',[],$body);$before=[];try{$before=self::dnsSnapshots($domain);}catch(Throwable){}$r=self::api('PUT','/api/dns/v1/zones/'.rawurlencode($domain),[],$body);Audit::log('owner','1','dns.update','domain',$domain,'executed',null,null,['records'=>count($clean),'overwrite'=>$overwrite,'snapshot_before'=>$before[0]['id']??null]);Notifications::add('warning','domains','تم إرسال تحديث سجلات الدومين',$domain.' — تم التحقق من صيغة السجلات قبل التطبيق.','domain',$domain);return ['result'=>$r,'records'=>count($clean),'snapshot_before'=>$before[0]['id']??null];}
    public static function addDnsRecord(string $domain,string $name,string $type,string $content,int $ttl=14400):array{$domain=Security::domain($domain);$name=trim($name);$type=strtoupper(trim($type));$content=trim($content);if($name===''||$content===''||!in_array($type,['A','AAAA','CNAME','MX','TXT','CAA','SRV','NS'],true))throw new RuntimeException('dns_record_invalid');if(strlen($content)>2000)throw new RuntimeException('dns_content_too_long');$current=self::dnsZone($domain);foreach($current as $rr){if(!is_array($rr)||strcasecmp((string)($rr['name']??''),$name)!==0||strtoupper((string)($rr['type']??''))!==$type)continue;foreach((array)($rr['records']??[]) as $r)if(trim((string)($r['content']??''))===$content)return ['already_exists'=>true,'records'=>0];}$r=self::updateDnsZone($domain,[['name'=>$name,'type'=>$type,'ttl'=>max(60,min(86400,$ttl)),'records'=>[['content'=>$content]]]],false);return ['already_exists'=>false]+$r;}
    public static function domainPortfolio():array{
        $out=[];for($page=1;$page<=10;$page++){$r=self::api('GET','/api/domains/v1/portfolio',['page'=>$page,'per_page'=>100]);$rows=$r['data']??$r;if(!is_array($rows))break;foreach($rows as $x)if(is_array($x))$out[]=$x;$meta=$r['meta']??[];if(!$meta||count($rows)<100||($meta['current_page']??1)>=($meta['last_page']??1))break;}return $out;
    }
    private static function websiteIdentity(array $x):array{
        $domain=strtolower(trim((string)($x['domain']??$x['domain_name']??'')));$user=(string)($x['username']??$x['account_username']??$x['hosting_username']??'');$root=(string)($x['root_directory']??'');if($user===''&&preg_match('#/home/([^/]+)/#',$root,$m))$user=$m[1];$status=array_key_exists('is_enabled',$x)?((bool)$x['is_enabled']?'active':'disabled'):(string)($x['status']??'');
        return ['domain'=>$domain,'username'=>$user,'root'=>$root,'id'=>(string)($x['id']??$x['uid']??$x['website_uid']??''),'type'=>(string)($x['website_type']??$x['type']??'other'),'status'=>$status,'order_id'=>(string)($x['order_id']??'')];
    }
    public static function siteByDomain(string $domain):array{$domain=strtolower(trim($domain));foreach(self::websites() as $x){$s=self::websiteIdentity($x);if($s['domain']===$domain)return $s;}throw new RuntimeException('hostinger_site_not_found');}
    public static function listFiles(string $domain,string $username,string $directory='',int $depth=3,int $max=500):array{
        $domain=Security::domain($domain);if($username==='')throw new RuntimeException('hostinger_username_missing');$path='/api/hosting/v1/accounts/'.rawurlencode($username).'/domains/'.rawurlencode($domain).'/files';$max=max(1,min(5000,$max));$out=[];$offset=0;$total=null;
        while(count($out)<$max){$batch=min(500,$max-count($out));$r=self::api('GET',$path,['directory'=>ltrim($directory,'/'),'max_depth'=>max(1,min(10,$depth)),'max_items'=>$batch,'offset'=>$offset]);$items=$r['items']??$r['data']??[];if(!is_array($items)||!$items)break;foreach($items as $item){if(is_array($item))$out[]=$item;if(count($out)>=$max)break;}$pageCount=(int)($r['total_items_current_page']??count($items));$total=isset($r['total_items'])?(int)$r['total_items']:$total;$offset+=(max(1,$pageCount));if($pageCount<=0||($total!==null&&$offset>=$total)||count($items)<$batch)break;}
        return ['items'=>$out,'total_items'=>$total??count($out),'offset'=>0,'total_items_current_page'=>count($out)];
    }
    public static function readFile(string $domain,string $username,string $path):array{
        self::safePath($path);$url='/api/hosting/v1/accounts/'.rawurlencode($username).'/domains/'.rawurlencode($domain).'/files/content';return self::api('GET',$url,['path'=>ltrim($path,'/'),'from_line'=>0,'max_lines'=>5000]);
    }
    public static function databases(string $domain,string $username):array{
        if($username==='')return [];$url='/api/hosting/v1/accounts/'.rawurlencode($username).'/databases';
        // Hostinger rejected is_assigned on the current API contract with HTTP 422. The domain filter is sufficient here.
        $r=self::api('GET',$url,['domain'=>$domain,'page'=>1,'per_page'=>100]);$rows=$r['data']??$r;return is_array($rows)?$rows:[];
    }
    private static function safePath(string $path):void{$p=ltrim(str_replace('\\','/',$path),'/');if($p===''||strlen($p)>700||str_contains($p,'..')||preg_match('/[\x00-\x1f]/',$p))throw new RuntimeException('unsafe_path');if(preg_match('#(?:^|/)(?:\.env(?:\.[^/]+)?|wp-config(?:-[^/]+)?\.php|config(?:\.[^/]+)?\.php|credentials?(?:\.[^/]+)?|secrets?(?:\.[^/]+)?|master\.key|secrets\.enc|id_(?:rsa|ed25519)|authorized_keys|\.htpasswd)(?:$|/)#i',$p))throw new RuntimeException('secret_file_blocked');}
    private static function detectTech(array $items,string $hint=''):string{
        $names=[];foreach($items as $it)if(is_array($it))$names[]=strtolower((string)($it['path']??$it['name']??''));$blob=' '.implode(' ',$names).' ';
        if(str_contains($blob,'wp-content')||str_contains($blob,'wp-admin'))return 'WordPress/PHP';if(str_contains($blob,'package.json'))return 'Node/JavaScript';if(str_contains($blob,'composer.json'))return 'PHP/Composer';if(str_contains($blob,'artisan'))return 'Laravel/PHP';if(str_contains($blob,'index.php'))return 'PHP';if(str_contains($blob,'index.html'))return 'Static HTML';return $hint?:'Unknown';
    }
    private static function domainName(array $d):string{return strtolower(trim((string)($d['domain']??$d['domain_name']??$d['name']??'')));}
    private static function importantPath(string $path):bool{$p=strtolower($path);foreach(['index.php','index.html','composer.json','package.json','artisan','.htaccess','wp-content','routes/','src/','app/','public/','api/','admin/'] as $x)if(str_contains($p,$x))return true;return false;}
    private static function persistFiles(int $projectId,int $scanId,array $files):void{
        $pdo=db();$seen=0;
        foreach(array_slice($files,0,2000) as $it){if(!is_array($it))continue;$path=(string)($it['path']??$it['name']??'');$path=ltrim(str_replace('\\','/',$path),'/');if($path===''||strlen($path)>700)continue;$rawType=strtolower((string)($it['type']??$it['item_type']??''));$type=in_array($rawType,['file','directory','symlink'],true)?$rawType:(str_ends_with($path,'/')?'directory':'unknown');$ext=$type==='file'?strtolower((string)pathinfo($path,PATHINFO_EXTENSION)):'';$size=isset($it['size'])&&is_numeric($it['size'])?(int)$it['size']:(isset($it['size_bytes'])&&is_numeric($it['size_bytes'])?(int)$it['size_bytes']:null);$modified=(string)($it['modified_at']??$it['updated_at']??'');$modifiedSql=null;if($modified!==''){$ts=strtotime($modified);if($ts)$modifiedSql=gmdate('Y-m-d H:i:s',$ts);}$meta=['permissions'=>$it['permissions']??null,'mime'=>$it['mime_type']??$it['mime']??null];$pdo->prepare("INSERT INTO project_files(project_id,path,item_type,extension,size_bytes,modified_at,is_important,status,last_scan_id,metadata_json,last_seen_at) VALUES (?,?,?,?,?,?,?,'active',?,?,NOW()) ON DUPLICATE KEY UPDATE item_type=VALUES(item_type),extension=VALUES(extension),size_bytes=VALUES(size_bytes),modified_at=VALUES(modified_at),is_important=VALUES(is_important),status='active',last_scan_id=VALUES(last_scan_id),metadata_json=VALUES(metadata_json),last_seen_at=NOW()") ->execute([$projectId,$path,$type,$ext?:null,$size,$modifiedSql,self::importantPath($path)?1:0,$scanId,j($meta)]);$seen++;}
        $pdo->prepare("UPDATE project_files SET status='missing' WHERE project_id=? AND (last_scan_id IS NULL OR last_scan_id<>?)")->execute([$projectId,$scanId]);
    }
    public static function scanProjects(int $scanId,int $agentId,?callable $heartbeat=null):array{
        $pdo=db();$beat=static function()use($heartbeat){if($heartbeat)$heartbeat();};$beat();
        $pdo->prepare("UPDATE project_scans SET state='running',started_at=NOW(),completed_at=NULL WHERE id=?")->execute([$scanId]);
        // The top-level inventories must succeed. If either fails we abort instead of falsely removing sites/domains.
        $sites=self::websites();$beat();
        $portfolio=self::domainPortfolio();$beat();
        $seenSites=[];$seenDomains=[];$dbCount=0;$updated=0;$warnings=[];

        foreach($portfolio as $d){$beat();
            $dn=self::domainName($d);if($dn==='')continue;$seenDomains[$dn]=true;
            $q=$pdo->prepare("INSERT INTO project_domains(domain_name,status,last_seen_at,provider_id) VALUES (?,'available',NOW(),?) ON DUPLICATE KEY UPDATE last_seen_at=NOW(),status=IF(project_id IS NULL,'available','active'),provider_id=VALUES(provider_id),removed_at=NULL");
            $q->execute([$dn,(string)($d['id']??'')]);
        }

        foreach($sites as $raw){$beat();
            $s=self::websiteIdentity($raw);$domain=$s['domain'];if($domain==='')continue;$seenSites[$domain]=true;$seenDomains[$domain]=true;
            $q=$pdo->prepare("SELECT id,technology,metadata_json FROM projects WHERE primary_domain=? OR (provider_website_id<>'' AND provider_website_id=?) LIMIT 1");$q->execute([$domain,$s['id']]);$existing=$q->fetch();$pid=(int)($existing['id']??0);$oldMeta=$existing?json_decode((string)($existing['metadata_json']??'{}'),true):[];if(!is_array($oldMeta))$oldMeta=[];

            $files=[];$filesOk=false;$fileError='';
            try{$beat();$r=self::listFiles($domain,$s['username'],'',4,2000);$files=$r['items']??[];if(!is_array($files))throw new RuntimeException('invalid_file_inventory');$filesOk=true;}
            catch(Throwable $e){$fileError=pb_substr(Security::redactSecrets($e->getMessage()),0,180);$warnings[]=['domain'=>$domain,'area'=>'files','error'=>$fileError];}

            $tech=$filesOk?self::detectTech($files,$s['type']):((string)($existing['technology']??'')?:($s['type']?:'Unknown'));
            $oldFingerprint=(string)($oldMeta['fingerprint']??'');
            $fingerprint=$filesOk?hash('sha256',$domain.'|'.$s['root'].'|'.$s['id'].'|'.implode('|',array_slice(array_map(fn($i)=>(string)($i['path']??$i['name']??''),$files),0,300))):$oldFingerprint;
            $meta=array_merge($oldMeta,['provider_status'=>$s['status'],'website_type'=>$s['type'],'fingerprint'=>$fingerprint,'order_id'=>$s['order_id'],'file_inventory_state'=>$filesOk?'verified':'warning']);
            if($filesOk)$meta['file_count']=count($files);else{$meta['file_inventory_error']=$fileError;$meta['file_count']=$oldMeta['file_count']??null;}
            $status=in_array(strtolower($s['status']),['active','running','live','enabled'],true)?'active':'unknown';

            if($pid){
                $pdo->prepare("UPDATE projects SET name=COALESCE(NULLIF(name,''),?),primary_domain=?,document_root=?,hosting_account=?,provider_website_id=?,technology=?,status=IF(status='archived',status,?),hosting_presence='present',last_seen_hosting_at=NOW(),unavailable_since=NULL,last_scan_at=NOW(),metadata_json=? WHERE id=?")->execute([$domain,$domain,$s['root'],$s['username'],$s['id'],$tech,$status,j($meta),$pid]);
            }else{
                $pdo->prepare("INSERT INTO projects(uid,name,primary_domain,document_root,hosting_account,provider_website_id,technology,status,workflow_stage,hosting_presence,last_seen_hosting_at,source,is_system_project,last_scan_at,metadata_json) VALUES (?,?,?,?,?,?,?,?,'discovered','present',NOW(),'hosting_scan',?,NOW(),?)")->execute([substr(hash('sha256',$domain),0,16),$domain,$domain,$s['root'],$s['username'],$s['id'],$tech,$status,$domain==='persebayt.com'?1:0,j($meta)]);$pid=(int)$pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO project_domains(domain_name,project_id,status,last_seen_at,provider_id) VALUES (?,?,'active',NOW(),?) ON DUPLICATE KEY UPDATE project_id=VALUES(project_id),status='active',last_seen_at=NOW(),provider_id=VALUES(provider_id),removed_at=NULL")->execute([$domain,$pid,$s['id']]);
            $ramyId=(int)AgentService::bySlug('ramy')['id'];$pdo->prepare("INSERT INTO agent_project_access(agent_id,project_id,access_scope) VALUES (?,?,'manage') ON DUPLICATE KEY UPDATE access_scope='manage'")->execute([$ramyId,$pid]);
            if($filesOk)self::persistFiles($pid,$scanId,$files);

            $dbOk=false;$seenDbNames=[];
            try{
                $beat();$dbs=self::databases($domain,$s['username']);$beat();$dbOk=true;
                foreach($dbs as $d){
                    if(!is_array($d))continue;$name=(string)($d['name']??'');if($name==='')continue;$seenDbNames[]=$name;
                    $user=(string)($d['user']??'');if($user===''){$users=$d['users']??[];$user=is_array($users)&&isset($users[0]['name'])?(string)$users[0]['name']:'';}
                    $host=(string)($d['host']??'localhost');$port=(int)($d['port']??3306);$providerRef=(string)($d['id']??$d['uid']??'');
                    $pdo->prepare("INSERT INTO project_databases(project_id,db_name,db_user,db_host,db_port,provider_ref,status,last_seen_at,metadata_json) VALUES (?,?,?,?,?,?,'active',NOW(),?) ON DUPLICATE KEY UPDATE db_user=VALUES(db_user),db_host=VALUES(db_host),db_port=VALUES(db_port),provider_ref=VALUES(provider_ref),status='active',last_seen_at=NOW(),metadata_json=VALUES(metadata_json)")->execute([$pid,$name,$user,$host?:'localhost',$port?:3306,$providerRef?:null,j(['created_at'=>$d['created_at']??null,'disk_usage_mb'=>$d['disk_usage_mb']??null,'max_size_mb'=>$d['max_size_mb']??null])]);$dbCount++;
                }
                if($seenDbNames){$ph=implode(',',array_fill(0,count($seenDbNames),'?'));$args=array_merge([$pid],$seenDbNames);$pdo->prepare("UPDATE project_databases SET status='missing' WHERE project_id=? AND status='active' AND db_name NOT IN ($ph)")->execute($args);}else{$pdo->prepare("UPDATE project_databases SET status='missing' WHERE project_id=? AND status='active'")->execute([$pid]);}
            }catch(Throwable $e){$warnings[]=['domain'=>$domain,'area'=>'databases','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}

            $mem='آخر فحص: التقنية '.$tech.'، مجلد '.$s['root'].'.';
            if($filesOk)$mem.=' ملفات مكتشفة '.count($files).'.';else $mem.=' تعذر تحديث فهرس الملفات في هذه الدورة.';
            if(!$dbOk)$mem.=' تعذر تحديث فهرس قواعد البيانات في هذه الدورة.';
            MemoryService::rememberProject($pid,'inventory',$mem,'scan',(string)$scanId);$updated++;$beat();
        }

        // A domain/site is considered removed only because the top-level site and portfolio inventories succeeded above.
        $q=$pdo->query("SELECT d.domain_name,d.project_id,p.source,p.is_system_project FROM project_domains d LEFT JOIN projects p ON p.id=d.project_id WHERE d.status IN ('active','available')");
        foreach($q->fetchAll() as $r){
            $dn=strtolower((string)$r['domain_name']);if(isset($seenSites[$dn]))continue;
            if(isset($seenDomains[$dn])){
                if($r['project_id']){$pdo->prepare("UPDATE projects SET hosting_presence='unavailable',unavailable_since=COALESCE(unavailable_since,NOW()) WHERE id=?")->execute([(int)$r['project_id']]);Audit::log('agent',(string)$agentId,'project.hosting_unavailable','project',(string)$r['project_id'],'verified',(int)$r['project_id'],null,['domain'=>$dn]);}
                $pdo->prepare("UPDATE project_domains SET status='available',project_id=NULL,last_seen_at=NOW(),removed_at=NULL WHERE domain_name=?")->execute([$dn]);
            }else{
                if($r['project_id']){$pdo->prepare("UPDATE projects SET hosting_presence='removed',unavailable_since=COALESCE(unavailable_since,NOW()),status=IF(source='hosting_scan' AND is_system_project=0,'archived',status) WHERE id=?")->execute([(int)$r['project_id']]);Audit::log('agent',(string)$agentId,'project.removed_from_hosting','project',(string)$r['project_id'],'verified',(int)$r['project_id'],null,['domain'=>$dn]);}
                $pdo->prepare("UPDATE project_domains SET status='removed',project_id=NULL,removed_at=COALESCE(removed_at,NOW()) WHERE domain_name=?")->execute([$dn]);
            }
        }

        $beat();$summary=['websites'=>count($sites),'domains'=>count($seenDomains),'projects_updated'=>$updated,'databases'=>$dbCount,'warnings'=>array_slice($warnings,0,50),'warning_count'=>count($warnings)];
        $pdo->prepare("UPDATE project_scans SET state='completed',websites_found=?,projects_updated=?,domains_updated=?,databases_found=?,summary_json=?,completed_at=NOW() WHERE id=?")->execute([count($sites),$updated,count($seenDomains),$dbCount,j($summary),$scanId]);put_setting('projects.last_scan_at',now_utc());put_setting('hosting.last_inventory_at',now_utc());
        if($warnings)Notifications::add('warning','projects','اكتملت فهرسة الاستضافة بتحذيرات','تم تحديث '.$updated.' مشروع، لكن توجد '.count($warnings).' نقطة لم يكتمل فحصها. راجع نتيجة الفهرسة.','project_scan',(string)$scanId);
        else Notifications::add('success','projects','اكتملت فهرسة الاستضافة','تم تحديث '.$updated.' مشروع و'.count($seenDomains).' دومين.','project_scan',(string)$scanId);
        Audit::log('agent',(string)$agentId,'hosting.inventory_scan','project_scan',(string)$scanId,$warnings?'executed':'verified',null,null,$summary);return $summary;
    }
    private static function uploadCreds(array $site):array{return self::api('POST','/api/hosting/v1/files/upload-urls',[],['username'=>$site['username'],'domain'=>$site['domain']]);}
    private static function tus(array $cred,string $path,string $bytes):void{
        $base=rtrim((string)($cred['url']??''),'/');$host=strtolower((string)parse_url($base,PHP_URL_HOST));if($base===''||(!str_ends_with($host,'.hstgr.io')&&$host!=='hstgr.io'))throw new RuntimeException('invalid_upload_url');$parts=array_map('rawurlencode',explode('/',ltrim($path,'/')));$url=$base.'/'.implode('/',$parts).'?override=true';$common=['X-Auth: '.(string)$cred['auth_key'],'X-Auth-Rest: '.(string)$cred['rest_auth_key'],'Tus-Resumable: 1.0.0'];
        foreach([['POST',['Upload-Length: '.strlen($bytes),'Upload-Offset: 0'],'',201],['PATCH',['Content-Type: application/offset+octet-stream','Upload-Offset: 0'],$bytes,204]] as [$method,$extra,$body,$expected]){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge($common,$extra),CURLOPT_POSTFIELDS=>$body,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>60]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('hostinger_upload_network:'.pb_substr($err,0,160));if($code!==$expected)throw new RuntimeException('hostinger_upload_http_'.$code);}
    }
    private static function backupFile(int $projectId,int $taskId,string $path,string $content):string{$dir=PB_ROOT.'/private/runtime/file-backups/'.date('Ymd');if(!is_dir($dir))mkdir($dir,0700,true);$name='p'.$projectId.'-t'.$taskId.'-'.substr(hash('sha256',$path.'|'.$content),0,16).'.bak';file_put_contents($dir.'/'.$name,$content,LOCK_EX);@chmod($dir.'/'.$name,0600);$ref='file-backups/'.date('Ymd').'/'.$name;db()->prepare("INSERT INTO backups(project_id,task_id,provider,kind,location_ref,checksum,status,verified_at) VALUES (?,?,'local','files',?,?,'verified',NOW())")->execute([$projectId,$taskId?:null,$ref,hash('sha256',$content)]);return $ref;}
    private static function canonicalVerifyBytes(string $bytes,bool $textLike):string{
        if(!$textLike)return $bytes;
        if(str_starts_with($bytes,"\xEF\xBB\xBF"))$bytes=substr($bytes,3);
        return str_replace(["\r\n","\r"],"\n",$bytes);
    }
    private static function verifyUploadedFile(array $site,string $path,string $expected,bool $textLike):?string{
        /* Hostinger may serve the previous file for a short moment after a TUS upload.
           Re-read with a bounded backoff and accept only an exact/canonical byte match. */
        foreach([0,180000,420000,800000,1300000] as $wait){
            if($wait>0)usleep($wait);
            try{$verify=self::readFile((string)$site['domain'],(string)$site['username'],$path);$actual=(string)($verify['content']??'');}
            catch(Throwable){continue;}
            if(hash_equals(hash('sha256',$expected),hash('sha256',$actual)))return $actual;
            if($textLike&&hash_equals(hash('sha256',self::canonicalVerifyBytes($expected,true)),hash('sha256',self::canonicalVerifyBytes($actual,true))))return $actual;
        }
        return null;
    }
    public static function writeFile(int $projectId,int $taskId,string $path,string $newContent,bool $allowCreate=false,string $domainOverride='',string $usernameOverride='',bool $allowBinary=false):array{
        $q=db()->prepare('SELECT * FROM projects WHERE id=?');$q->execute([$projectId]);$p=$q->fetch();if(!$p)throw new RuntimeException('project_not_found');$domain=trim($domainOverride)!==''?$domainOverride:(string)$p['primary_domain'];if($domain==='')throw new RuntimeException('project_domain_missing');$usernameOverride=trim($usernameOverride);$site=$usernameOverride!==''?['domain'=>Security::domain($domain),'username'=>$usernameOverride]:self::siteByDomain($domain);self::safePath($path);$before='';$existed=true;
        try{$old=self::readFile($site['domain'],$site['username'],$path);$before=(string)($old['content']??'');}catch(Throwable $e){if(!$allowCreate||!str_starts_with($e->getMessage(),'http_404'))throw $e;$existed=false;}
        if($existed&&$before===$newContent)return ['changed'=>false,'created'=>false,'path'=>$path,'hash'=>hash('sha256',$before),'target_domain'=>$site['domain']];$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$base=strtolower(basename($path));$textExt=['php','html','htm','css','js','json','txt','md','sql','xml','svg','map'];$binaryExt=['jpg','jpeg','png','webp','gif','ico','woff','woff2','ttf','eot','pdf'];$allowed=$allowBinary?array_merge($textExt,$binaryExt):$textExt;if(!in_array($ext,$allowed,true)&&$base!=='.htaccess')throw new RuntimeException('unsupported_edit_type');$max=$allowBinary?2097152:500000;if(strlen($newContent)>$max)throw new RuntimeException('edit_too_large');if($ext==='php'){try{token_get_all($newContent,TOKEN_PARSE);}catch(Throwable){throw new RuntimeException('php_syntax_invalid');}}
        $backup=$existed?self::backupFile($projectId,$taskId,$path,$before):null;$textLike=in_array($ext,$textExt,true)||$base==='.htaccess';
        $cred=self::uploadCreds($site);self::tus($cred,$path,$newContent);$after=self::verifyUploadedFile($site,$path,$newContent,$textLike);
        if($after===null){
            /* One fresh credential/upload attempt handles expired upload URLs and delayed propagation
               without silently reporting success. */
            $cred=self::uploadCreds($site);self::tus($cred,$path,$newContent);$after=self::verifyUploadedFile($site,$path,$newContent,$textLike);
        }
        if($after===null)throw new RuntimeException('write_verification_failed_after_retry');
        $ev=['path'=>$path,'created'=>!$existed,'backup'=>$backup,'before_hash'=>$existed?hash('sha256',$before):null,'after_hash'=>hash('sha256',$after),'target_domain'=>$site['domain'],'verification'=>'readback_retry'];$actor=Audit::actor();Audit::log((string)$actor['type'],(string)$actor['id'],$existed?'file.write':'file.create','file',$path,'verified',$projectId,$taskId?:null,$ev);return ['changed'=>true]+$ev;
    }
    public static function restoreFileBackup(int $projectId,int $taskId,string $path,string $backupRef,string $domainOverride='',string $usernameOverride=''):array{
        self::safePath($path);$backupRef=ltrim(str_replace('\\','/',$backupRef),'/');if(!preg_match('#^file-backups/\d{8}/[a-zA-Z0-9._-]+\.bak$#',$backupRef))throw new RuntimeException('invalid_backup_reference');$full=PB_ROOT.'/private/runtime/'.$backupRef;if(!is_file($full))throw new RuntimeException('backup_file_missing');$bytes=file_get_contents($full);if($bytes===false)throw new RuntimeException('backup_read_failed');$q=db()->prepare("SELECT COUNT(*) FROM backups WHERE project_id=? AND task_id=? AND kind='files' AND location_ref=? AND status='verified'");$q->execute([$projectId,$taskId,$backupRef]);if((int)$q->fetchColumn()<1)throw new RuntimeException('backup_not_verified');$r=self::writeFile($projectId,$taskId,$path,$bytes,false,$domainOverride,$usernameOverride,true);Audit::log('owner','1','file.restore','file',$path,'verified',$projectId,$taskId,['backup_ref'=>$backupRef,'target_domain'=>$domainOverride?:null]);return $r;
    }
    public static function subdomains(int $projectId):array{$p=ProjectService::get($projectId);$site=self::siteByDomain((string)$p['primary_domain']);$r=self::api('GET','/api/hosting/v1/accounts/'.rawurlencode($site['username']).'/websites/'.rawurlencode($site['domain']).'/subdomains');$rows=$r['data']??$r;return is_array($rows)?$rows:[];}
    public static function createSubdomain(int $projectId,string $subdomain,string $directory,bool $publicDirectory=true):array{$p=ProjectService::get($projectId);$site=self::siteByDomain((string)$p['primary_domain']);$subdomain=strtolower(trim($subdomain));$directory=trim(str_replace('\\','/',$directory),'/');if(!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$subdomain)||$directory===''||str_contains($directory,'..'))throw new RuntimeException('invalid_subdomain');$r=self::api('POST','/api/hosting/v1/accounts/'.rawurlencode($site['username']).'/websites/'.rawurlencode($site['domain']).'/subdomains',[],['subdomain'=>$subdomain,'directory'=>$directory,'is_using_public_directory'=>$publicDirectory]);Audit::log('owner','1','subdomain.create','project',(string)$projectId,'executed',$projectId,null,['subdomain'=>$subdomain,'directory'=>$directory]);Notifications::add('success','domains','تم إنشاء دومين فرعي',$subdomain.'.'.$site['domain'],'project',(string)$projectId);return $r;}
    public static function deleteSubdomain(int $projectId,string $subdomain):array{$p=ProjectService::get($projectId);$site=self::siteByDomain((string)$p['primary_domain']);$subdomain=strtolower(trim($subdomain));if(!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$subdomain))throw new RuntimeException('invalid_subdomain');$r=self::api('DELETE','/api/hosting/v1/accounts/'.rawurlencode($site['username']).'/websites/'.rawurlencode($site['domain']).'/subdomains/'.rawurlencode($subdomain));Audit::log('owner','1','subdomain.delete','project',(string)$projectId,'executed',$projectId,null,['subdomain'=>$subdomain]);return $r;}
    public static function cronJobs(string $username):array{$rows=self::api('GET','/api/hosting/v1/accounts/'.rawurlencode($username).'/cron-jobs');$rows=$rows['data']??$rows;return is_array($rows)?$rows:[];}
    private static function workerHttpToken():string{$key='connections.worker.http_token';$token=trim((string)SecretVault::get($key,''));if(!preg_match('/^[a-f0-9]{64}$/D',$token)){$token=bin2hex(random_bytes(32));SecretVault::save([$key=>$token]);}return $token;}
    public static function ensureWorkerCron(string $domain='persebayt.com',string $schedule='* * * * *'):array{
        $site=self::siteByDomain($domain);if($site['username']===''||$site['root']==='')throw new RuntimeException('hosting_path_missing');
        $domainRoot=dirname(rtrim((string)$site['root'],'/'));$worker=$domainRoot.'/private/bin/worker.php';
        $cliCommand="/usr/bin/php ".escapeshellarg($worker)." 1 >/dev/null 2>&1";
        $safeCommand="/usr/bin/php ".$worker." 1 >/dev/null 2>&1";
        $cron=self::cronJobs($site['username']);
        foreach($cron as $c){$cmd=(string)($c['command']??'');if(is_array($c)&&str_contains($cmd,'/private/bin/worker.php')){put_setting('runtime.worker_cron_mode','cli');put_setting('runtime.worker_cli_path',$worker);return ['created'=>false,'cron'=>$c,'mode'=>'cli','command'=>$safeCommand];}}
        // Create a CLI worker even if an old HTTPS cron exists. The web cron endpoint becomes a no-op
        // for scheduled requests once cli mode is active, while explicit X-PerseBayt-Wakeup requests
        // stay available for lightweight diagnostics.
        $r=self::api('POST','/api/hosting/v1/accounts/'.rawurlencode($site['username']).'/cron-jobs',[],['time'=>$schedule,'command'=>$cliCommand]);
        put_setting('runtime.worker_cron_mode','cli');put_setting('runtime.worker_cli_path',$worker);
        Audit::log('owner','1','cron.ensure','provider','hostinger','verified',null,null,['schedule'=>$schedule,'mode'=>'cli','worker_path'=>$worker]);
        Notifications::add('success','system','تم تركيب Worker CLI','تم تشغيل عامل الطابور كل دقيقة من PHP CLI حتى لا تتوقف مهام وليد بسبب مهلة HTTP/FPM.','provider','hostinger');
        return ['created'=>true,'cron'=>$r,'mode'=>'cli','command'=>$safeCommand];
    }
    public static function wordpressInstallation(string $domain,string $username=''):?array{$q=['domain'=>$domain,'ownership'=>'owned'];if($username!=='')$q['username']=$username;$rows=self::api('GET','/api/hosting/v1/wordpress/installations',$q);$rows=$rows['data']??$rows;if(!is_array($rows))return null;foreach($rows as $r)if(is_array($r)&&strtolower((string)($r['domain']??''))===strtolower($domain))return $r;return null;}
    public static function setMaintenance(int $projectId,bool $enabled):array{$q=db()->prepare('SELECT * FROM projects WHERE id=?');$q->execute([$projectId]);$p=$q->fetch();if(!$p)throw new RuntimeException('project_not_found');$site=self::siteByDomain((string)$p['primary_domain']);$wp=self::wordpressInstallation($site['domain'],$site['username']);if(!$wp||empty($wp['id']))throw new RuntimeException('maintenance_supported_for_wordpress_only');$path='/api/hosting/v1/accounts/'.rawurlencode($site['username']).'/wordpress/'.rawurlencode((string)$wp['id']).'/maintenance/toggle';self::api('PATCH',$path,[],['enabled'=>$enabled]);$status=self::api('GET','/api/hosting/v1/accounts/'.rawurlencode($site['username']).'/wordpress/'.rawurlencode((string)$wp['id']).'/maintenance/status');$actual=strtolower((string)($status['status']??''));$ok=$enabled?$actual==='enabled':$actual==='disabled';if(!$ok)throw new RuntimeException('maintenance_verification_failed');db()->prepare('UPDATE projects SET status=? WHERE id=?')->execute([$enabled?'maintenance':'active',$projectId]);Audit::log('owner','1','website.maintenance','project',(string)$projectId,'verified',$projectId,null,['enabled'=>$enabled,'wordpress_id'=>(string)$wp['id']]);Notifications::add('success','projects',$enabled?'تم إيقاف الموقع للصيانة':'تم تشغيل الموقع','تم التحقق من حالة الصيانة عبر Hostinger.','project',(string)$projectId);return ['enabled'=>$enabled,'verified'=>true,'status'=>$actual];}
    public static function resolveProjectDatabase(int $projectId,string $databaseRef=''):string{
        $ref=trim($databaseRef);if($ref!==''){$q=db()->prepare("SELECT db_name FROM project_databases WHERE project_id=? AND db_name=? AND status<>'removed' LIMIT 1");$q->execute([$projectId,$ref]);$name=(string)($q->fetchColumn()?:'');if($name==='')throw new RuntimeException('database_not_found_in_project');return $name;}
        $q=db()->prepare("SELECT db_name FROM project_databases WHERE project_id=? AND status='active' ORDER BY id");$q->execute([$projectId]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);if(count($rows)===1)return (string)$rows[0];if(!$rows)throw new RuntimeException('project_database_missing');throw new RuntimeException('multiple_databases_need_name');
    }
    public static function deleteDatabase(int $projectId,string $databaseName,bool $confirmed):void{
        if(!$confirmed)throw new RuntimeException('destructive_confirmation_required');$q=db()->prepare('SELECT * FROM projects WHERE id=?');$q->execute([$projectId]);$p=$q->fetch();if(!$p)throw new RuntimeException('project_not_found');
        $q=db()->prepare("SELECT id FROM project_databases WHERE project_id=? AND db_name=? AND status<>'removed' LIMIT 1");$q->execute([$projectId,$databaseName]);$dbId=(int)($q->fetchColumn()?:0);if(!$dbId)throw new RuntimeException('database_not_found_in_project');
        $b=db()->prepare("SELECT COUNT(*) FROM backups WHERE project_id=? AND status='verified' AND created_at>=DATE_SUB(NOW(),INTERVAL 72 HOUR) AND (kind IN ('full','restore_point') OR (kind='database' AND location_ref LIKE ?))");$b->execute([$projectId,'%/p'.$projectId.'-db'.$dbId.'-%']);if((int)$b->fetchColumn()<1)throw new RuntimeException('verified_database_backup_required');$username=trim((string)$p['hosting_account']);if($username==='')throw new RuntimeException('hostinger_username_missing');
        self::api('DELETE','/api/hosting/v1/accounts/'.rawurlencode($username).'/databases/'.rawurlencode($databaseName));db()->prepare("UPDATE project_databases SET status='removed',last_seen_at=NOW() WHERE id=?")->execute([$dbId]);Audit::log('owner','1','database.delete','project_database',(string)$dbId,'executed',$projectId,null,['database'=>$databaseName]);Notifications::add('critical','projects','تم حذف قاعدة بيانات','تم تنفيذ الحذف بعد التحقق من نسخة احتياطية أو نقطة رجوع صالحة.','project',(string)$projectId);
    }
    public static function deleteWebsite(int $projectId,int $taskId,bool $confirmed):void{
        if(!$confirmed)throw new RuntimeException('destructive_confirmation_required');$q=db()->prepare('SELECT * FROM projects WHERE id=?');$q->execute([$projectId]);$p=$q->fetch();if(!$p)throw new RuntimeException('project_not_found');$b=db()->prepare("SELECT COUNT(*) FROM backups WHERE project_id=? AND kind IN ('full','restore_point') AND status='verified' AND created_at>=DATE_SUB(NOW(),INTERVAL 72 HOUR)");$b->execute([$projectId]);if((int)$b->fetchColumn()<1)throw new RuntimeException('verified_backup_required');self::api('DELETE','/api/hosting/v1/websites/'.rawurlencode((string)$p['primary_domain']));Audit::log('owner','1','website.delete','project',(string)$projectId,'executed',$projectId,$taskId);Notifications::add('critical','projects','تم إرسال حذف موقع إلى Hostinger','سيتم فحص الاستضافة للتأكد من اكتمال الحذف.','project',(string)$projectId);
    }
}
