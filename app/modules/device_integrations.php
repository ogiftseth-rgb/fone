<?php
declare(strict_types=1);

/**
 * FoneFlex v0.6 OEM adapters.
 * Samsung Knox Guard: official token-library helper + documented REST operations.
 * Apple Business API: OAuth client assertion + ADE MDM-server assignment.
 * Apple remote lock/unlock is deliberately delegated to the selected MDM service.
 */

function ffdi_key(): string
{
    global $config;
    $raw=(string)($config['security']['data_encryption_key']??'');
    if(strlen($raw)<32) throw new RuntimeException('Configure the existing security.data_encryption_key before saving OEM credentials.');
    return hash('sha256',$raw,true);
}
function ffdi_encrypt(string $plain): string
{
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',ffdi_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false) throw new RuntimeException('Could not encrypt provider credentials.');
    return json_encode(['v'=>1,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)],JSON_UNESCAPED_SLASHES);
}
function ffdi_decrypt(string $packed): string
{
    $x=json_decode($packed,true);if(!is_array($x)||empty($x['iv'])||empty($x['tag'])||empty($x['data']))throw new RuntimeException('Stored provider credentials are not readable.');
    $plain=openssl_decrypt(base64_decode($x['data'],true),'aes-256-gcm',ffdi_key(),OPENSSL_RAW_DATA,base64_decode($x['iv'],true),base64_decode($x['tag'],true));
    if($plain===false)throw new RuntimeException('Provider credentials cannot be decrypted. Check that the original data_encryption_key is unchanged.');
    return $plain;
}
function ffdi_b64url(string $data): string { return rtrim(strtr(base64_encode($data),'+/','-_'),'='); }
function ffdi_connection(string $provider,int $organizationId): ?array
{
    $orgScope='ORG:'.$organizationId;
    return get_one('SELECT * FROM device_provider_connections WHERE provider_key=? AND (scope_code=? OR (scope_code="GLOBAL" AND multi_tenant_authorized=1)) ORDER BY CASE WHEN scope_code=? THEN 0 ELSE 1 END LIMIT 1',[$provider,$orgScope,$orgScope]);
}
function ffdi_secret(array $connection): array
{
    $x=json_decode(ffdi_decrypt((string)$connection['secrets_ciphertext']),true);
    if(!is_array($x))throw new RuntimeException('Provider credentials are invalid. Re-save the integration credentials.');return $x;
}
function ffdi_save_connection(string $provider,int $organizationId,array $public,array $secrets,string $label,string $environment,string $region,bool $multiTenant): void
{
    $isPlatform=is_platform_admin();$scope=$isPlatform?'GLOBAL':'ORG:'.$organizationId;$orgValue=$isPlatform?null:$organizationId;
    $old=get_one('SELECT * FROM device_provider_connections WHERE scope_code=? AND provider_key=?',[$scope,$provider]);
    if($provider==='samsung_knox_guard'){
        $oldSecrets=$old?ffdi_secret($old):[];
        $client=trim((string)($secrets['client_id']??''));$keys=trim((string)($secrets['keys_json']??''));
        if($client==='')$client=(string)($oldSecrets['client_id']??'');if($keys==='')$keys=(string)($oldSecrets['keys_json']??'');
        if($client===''||$keys==='')throw new RuntimeException('Enter the Samsung Knox Client ID and full keys.json content.');
        $keyData=json_decode($keys,true);if(!is_array($keyData)||empty($keyData['Private'])||empty($keyData['Public']))throw new RuntimeException('The Samsung keys.json must contain both Private and Public fields.');
        $secrets=['client_id'=>$client,'keys_json'=>$keys];
    }elseif($provider==='apple_business_api'){
        $oldSecrets=$old?ffdi_secret($old):[];
        $client=trim((string)($secrets['client_id']??''));$kid=trim((string)($secrets['key_id']??''));$pem=trim((string)($secrets['private_key_pem']??''));
        if($client==='')$client=(string)($oldSecrets['client_id']??'');if($kid==='')$kid=(string)($oldSecrets['key_id']??'');if($pem==='')$pem=(string)($oldSecrets['private_key_pem']??'');
        if($client===''||$kid===''||$pem==='')throw new RuntimeException('Enter the Apple Business API Client ID, Key ID and private key (.p8 PEM).');
        $key=@openssl_pkey_get_private($pem);if($key===false)throw new RuntimeException('Apple private key is not a valid PEM private key.');$details=openssl_pkey_get_details($key);if(($details['type']??-1)!==OPENSSL_KEYTYPE_EC)throw new RuntimeException('Apple Business API client assertion requires an EC private key.');
        $secrets=['client_id'=>$client,'key_id'=>$kid,'private_key_pem'=>$pem];
        if(empty($public['mdm_server_id']))throw new RuntimeException('Choose or enter the Apple Business MDM server ID before saving.');
    }else throw new RuntimeException('Unsupported provider.');
    $encrypted=ffdi_encrypt(json_encode($secrets,JSON_UNESCAPED_SLASHES));
    $stmt=db()->prepare('INSERT INTO device_provider_connections(scope_code,organization_id,provider_key,connection_name,environment,region_code,public_config_json,secrets_ciphertext,multi_tenant_authorized,connection_status,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,"configured",?,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),connection_name=VALUES(connection_name),environment=VALUES(environment),region_code=VALUES(region_code),public_config_json=VALUES(public_config_json),secrets_ciphertext=VALUES(secrets_ciphertext),multi_tenant_authorized=VALUES(multi_tenant_authorized),connection_status="configured",last_error=NULL,updated_by=VALUES(updated_by),updated_at=NOW()');
    $stmt->execute([$scope,$orgValue,$provider,$label,$environment,$region?:null,json_encode($public,JSON_UNESCAPED_SLASHES),$encrypted,$multiTenant?1:0,(int)user()['id'],(int)user()['id']]);
    audit('device.provider_connection_saved','provider_connection',null,['provider'=>$provider,'scope'=>$scope,'environment'=>$environment]);
}
function ffdi_http(string $method,string $url,array $headers=[],?array $body=null): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required for OEM API integrations. Enable extension=curl in WampServer PHP and restart Apache.');
    $ch=curl_init($url);$h=$headers;$h[]='Accept: application/json';
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$h,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false];
    if($body!==null){$h[]='Content-Type: application/json';$opts[CURLOPT_HTTPHEADER]=$h;$opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);}
    curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$errno=curl_errno($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false||$errno)throw new RuntimeException('Provider network request failed: '.($err?:'cURL error '.$errno));
    $json=json_decode((string)$raw,true);if($status<200||$status>=300){$message=is_array($json)?($json['message']??$json['error']['message']??$json['error']??''):(string)$raw;$message=is_string($message)?$message:json_encode($message);throw new RuntimeException('Provider returned HTTP '.$status.': '.mb_substr($message,0,500));}
    return ['status'=>$status,'json'=>is_array($json)?$json:[],'raw'=>mb_substr((string)$raw,0,12000)];
}
function ffdi_node_binary(): string
{
    global $config;$v=(string)($config['integrations']['node_binary']??'');if($v!=='')return $v;
    if(PHP_OS_FAMILY==='Windows'&&is_file('C:\\Program Files\\nodejs\\node.exe'))return 'C:\\Program Files\\nodejs\\node.exe';
    return 'node';
}
function ffdi_knox_token(array $connection,array $secrets): string
{
    $script=dirname(__DIR__,2).'/integration/knox-token/token.js';if(!is_file($script))throw new RuntimeException('Samsung token helper is missing. Restore integration/knox-token/token.js from the FoneFlex v0.6 package.');
    $payload=json_encode(['clientId'=>$secrets['client_id'],'keysJson'=>$secrets['keys_json'],'region'=>$connection['region_code']?:'eu'],JSON_UNESCAPED_SLASHES);
    $cmd=[ffdi_node_binary(),$script];$pipes=[];$proc=@proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($script));
    if(!is_resource($proc))throw new RuntimeException('Could not start Node.js. Install Node.js LTS and npm install in FoneFlex\\integration\\knox-token.');
    fwrite($pipes[0],$payload);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($proc);$data=json_decode((string)$out,true);
    if($code!==0||!is_array($data)||empty($data['token']))throw new RuntimeException('Samsung token generation failed. '.mb_substr((string)($data['error']??$err??$out),0,600));
    return (string)$data['token'];
}
function ffdi_samsung_base(array $connection): string
{
    $region=strtolower((string)($connection['region_code']??'eu'))==='us'?'us':'eu';$env=(string)$connection['environment'];
    return 'https://'.$region.'-kcs-api.samsungknox.com/kcs/v1.1/'.($env==='development'?'kg-integration':'kg');
}
function ffdi_samsung_request(array $connection,string $method,string $path,?array $body=null): array
{
    if($connection['scope_code']==='GLOBAL' && (int)$connection['multi_tenant_authorized']!==1)throw new RuntimeException('Global Samsung credentials cannot be used across retailers until Samsung has expressly authorised this FoneFlex multi-tenant integration.');
    $secret=ffdi_secret($connection);$token=ffdi_knox_token($connection,$secret);$tx='FF'.date('ymdHis').strtoupper(bin2hex(random_bytes(3)));
    return ffdi_http($method,ffdi_samsung_base($connection).$path,['x-knox-apitoken: '.$token,'x-knox-transactionId: '.$tx],$body);
}
function ffdi_apple_der_to_raw(string $der): string
{
    $p=0;if(ord($der[$p++])!==0x30)throw new RuntimeException('Unexpected ES256 signature encoding.');$len=ord($der[$p++]);if($len&0x80){$n=$len&0x7f;$len=0;for($i=0;$i<$n;$i++)$len=($len<<8)|ord($der[$p++]);}
    if(ord($der[$p++])!==0x02)throw new RuntimeException('Unexpected ES256 signature R.');$rLen=ord($der[$p++]);$r=substr($der,$p,$rLen);$p+=$rLen;
    if(ord($der[$p++])!==0x02)throw new RuntimeException('Unexpected ES256 signature S.');$sLen=ord($der[$p++]);$s=substr($der,$p,$sLen);
    $r=ltrim($r,"\0");$s=ltrim($s,"\0");if(strlen($r)>32||strlen($s)>32)throw new RuntimeException('Invalid ES256 signature length.');return str_pad($r,32,"\0",STR_PAD_LEFT).str_pad($s,32,"\0",STR_PAD_LEFT);
}
function ffdi_apple_token(array $connection,array $secret): string
{
    $now=time();$header=['alg'=>'ES256','kid'=>$secret['key_id'],'typ'=>'JWT'];$claims=['iss'=>$secret['client_id'],'iat'=>$now,'exp'=>$now+300,'aud'=>'https://account.apple.com/auth/oauth2/v2/token','sub'=>$secret['client_id'],'jti'=>bin2hex(random_bytes(16))];
    $input=ffdi_b64url(json_encode($header)).'.'.ffdi_b64url(json_encode($claims));$key=@openssl_pkey_get_private($secret['private_key_pem']);if($key===false)throw new RuntimeException('Apple API private key could not be loaded.');
    if(!openssl_sign($input,$der,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Could not sign Apple API client assertion.');$assertion=$input.'.'.ffdi_b64url(ffdi_apple_der_to_raw($der));
    $post=http_build_query(['grant_type'=>'client_credentials','client_id'=>$secret['client_id'],'client_assertion_type'=>'urn:ietf:params:oauth:client-assertion-type:jwt-bearer','client_assertion'=>$assertion,'scope'=>'business.api']);
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required for Apple Business API.');
    $ch=curl_init('https://account.apple.com/auth/oauth2/token');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);
    if($raw===false||$status<200||$status>=300||empty($data['access_token']))throw new RuntimeException('Apple OAuth token request failed (HTTP '.$status.'): '.mb_substr((string)($data['error_description']??$data['error']??$err??$raw),0,500));return (string)$data['access_token'];
}
function ffdi_apple_request(array $connection,string $method,string $path,?array $body=null): array
{
    $secret=ffdi_secret($connection);$token=ffdi_apple_token($connection,$secret);return ffdi_http($method,'https://api-business.apple.com'.$path,['Authorization: Bearer '.$token,'Content-Type: application/json'],$body);
}
function ffdi_apple_mdm_servers(array $connection): array
{
    $r=ffdi_apple_request($connection,'GET','/v1/mdmServers?limit=1000');return $r['json']['data']??[];
}
function ffdi_apple_find_org_device(array $connection,string $serial): ?array
{
    $path='/v1/orgDevices?limit=1000';$guard=0;
    while($path&&$guard++<20){$r=ffdi_apple_request($connection,'GET',$path);foreach(($r['json']['data']??[]) as $d){$s=(string)($d['attributes']['serialNumber']??$d['id']??'');if(strcasecmp($s,$serial)===0)return $d;}$next=$r['json']['links']['next']??null;$path=is_string($next)?(str_starts_with($next,'http')?(string)(parse_url($next,PHP_URL_PATH).'?'.parse_url($next,PHP_URL_QUERY)): $next):'';}
    return null;
}
function ffdi_device_action_log(int $org,int $item,?int $agreement,int $connectionId,string $provider,string $action,string $status,string $reason,array $summary=[],?string $requestId=null): int
{
    $ref='FF'.strtoupper(substr($provider,0,2)).'-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));
    $s=db()->prepare('INSERT INTO device_provider_actions(organization_id,inventory_item_id,agreement_id,provider_connection_id,provider_key,action_type,action_status,request_reference,provider_request_id,reason,response_summary,requested_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$org,$item,$agreement,$connectionId,$provider,$action,$status,$ref,$requestId,$reason?:null,json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),(int)(user()['id']??0)]);return (int)db()->lastInsertId();
}
function ffdi_provider_record(int $org,int $item,string $provider,int $connectionId,string $uid,string $approveId='',array $updates=[]): void
{
    $old=get_one('SELECT * FROM device_provider_devices WHERE organization_id=? AND inventory_item_id=? AND provider_key=?',[$org,$item,$provider]);
    $values=array_merge(['approve_id'=>$approveId?:($old['approve_id']??null),'provider_object_id'=>$old['provider_object_id']??null,'provider_upload_id'=>$old['provider_upload_id']??null,'provider_assignment_activity_id'=>$old['provider_assignment_activity_id']??null,'enrollment_status'=>$old['enrollment_status']??'not_submitted','last_provider_state'=>$old['last_provider_state']??null],$updates);
    db()->prepare('INSERT INTO device_provider_devices(organization_id,inventory_item_id,provider_connection_id,provider_key,device_uid,approve_id,provider_object_id,provider_upload_id,provider_assignment_activity_id,enrollment_status,last_provider_state,last_checked_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE provider_connection_id=VALUES(provider_connection_id),device_uid=VALUES(device_uid),approve_id=VALUES(approve_id),provider_object_id=VALUES(provider_object_id),provider_upload_id=VALUES(provider_upload_id),provider_assignment_activity_id=VALUES(provider_assignment_activity_id),enrollment_status=VALUES(enrollment_status),last_provider_state=VALUES(last_provider_state),last_checked_at=NOW(),updated_at=NOW()')->execute([$org,$item,$connectionId,$provider,$uid,$values['approve_id'],$values['provider_object_id'],$values['provider_upload_id'],$values['provider_assignment_activity_id'],$values['enrollment_status'],$values['last_provider_state']]);
}
function ffdi_agreement_checks(int $org,int $agreementId): array
{
    $a=get_one('SELECT * FROM agreements WHERE id=? AND organization_id=?',[$agreementId,$org]);if(!$a)throw new RuntimeException('Hire-purchase agreement not found.');
    $paid=(float)(get_one('SELECT COALESCE(SUM(amount),0) total FROM payments WHERE agreement_id=? AND organization_id=? AND payment_status="verified"',[$agreementId,$org])['total']??0);
    $pending=(int)(get_one('SELECT COUNT(*) c FROM payments WHERE agreement_id=? AND organization_id=? AND payment_status="pending"',[$agreementId,$org])['c']??0);
    $disputes=(int)(get_one('SELECT COUNT(*) c FROM customer_disputes WHERE agreement_id=? AND organization_id=? AND status IN ("open","reviewing")',[$agreementId,$org])['c']??0);
    $overdue=(int)(get_one('SELECT COUNT(*) c FROM installment_schedules WHERE agreement_id=? AND organization_id=? AND due_date<CURDATE() AND status IN ("pending","partial")',[$agreementId,$org])['c']??0);
    $balance=max(0,round((float)$a['hp_price']-$paid,2));return ['agreement'=>$a,'paid'=>$paid,'balance'=>$balance,'pending'=>$pending,'disputes'=>$disputes,'overdue'=>$overdue];
}
function ffdi_require_device_notice(int $org,int $agreementId,int $itemId): array
{
    $c=get_one('SELECT * FROM device_management_consents WHERE organization_id=? AND agreement_id=? AND inventory_item_id=? ORDER BY id DESC LIMIT 1',[$org,$agreementId,$itemId]);if(!$c)throw new RuntimeException('Before contacting the OEM provider, record that the customer received and acknowledged the separate device-financing and device-management notice.');return $c;
}
function ffdi_action_submit(int $connectionId,int $org,int $itemId,?int $agreementId,string $provider,string $action,string $reason,callable $operation): int
{
    $id=ffdi_device_action_log($org,$itemId,$agreementId,$connectionId,$provider,$action,'queued',$reason,[]);
    try{$result=$operation();$json=$result['json']??[];$requestId=(string)($json['requestedId']??$json['activityId']??$json['data']['id']??$json['id']??'');$summary=['http_status'=>$result['status']??200,'response'=>$json];db()->prepare('UPDATE device_provider_actions SET action_status="submitted",provider_request_id=?,response_summary=? WHERE id=?')->execute([$requestId?:null,json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),$id]);audit('device.provider_action_submitted','device_provider_action',$id,['provider'=>$provider,'action'=>$action,'request_reference'=>get_one('SELECT request_reference FROM device_provider_actions WHERE id=?',[$id])['request_reference']??null]);return $id;
    }catch(Throwable $e){db()->prepare('UPDATE device_provider_actions SET action_status="failed",response_summary=? WHERE id=?')->execute([json_encode(['error'=>mb_substr($e->getMessage(),0,800)]),$id]);audit('device.provider_action_failed','device_provider_action',$id,['provider'=>$provider,'action'=>$action]);throw $e;}
}
function ffdi_device_notice_post(int $org,int $itemId,int $agreementId): void
{
    require_role(['owner','admin']);$a=get_one('SELECT * FROM agreements WHERE id=? AND organization_id=? AND inventory_item_id=?',[$agreementId,$org,$itemId]);if(!$a)throw new RuntimeException('Agreement and phone do not match.');
    $name=post_string('consent_customer_name',160);$method=post_string('acknowledgement_method',30);$summary=post_string('notice_summary',1000);
    if($name===''||!in_array($method,['written','digital','verbal'],true)||mb_strlen($summary)<30)throw new RuntimeException('Record the customer name, acknowledgement method and a meaningful notice summary.');
    db()->prepare('INSERT INTO device_management_consents(organization_id,customer_id,agreement_id,inventory_item_id,consent_customer_name,acknowledgement_method,notice_version,notice_summary,recorded_by) VALUES(?,?,?,?,?,?,"device-financing-v1",?,?)')->execute([$org,$a['customer_id'],$agreementId,$itemId,$name,$method,$summary,(int)user()['id']]);
    if(function_exists('ffdev_log_event'))ffdev_log_event($org,$itemId,'device_management_notice_recorded',['agreement_id'=>$agreementId,'method'=>$method,'notice_version'=>'device-financing-v1']);audit('device.management_notice_recorded','agreement',$agreementId,['inventory_item_id'=>$itemId,'method'=>$method]);
}
function ffdi_handle_device_action(int $org,int $itemId,int $agreementId,string $action): void
{
    require_role(['owner','admin']);$item=get_one('SELECT * FROM inventory_items WHERE id=? AND organization_id=?',[$itemId,$org]);if(!$item)throw new RuntimeException('Phone not found in this business.');$a=get_one('SELECT * FROM agreements WHERE id=? AND organization_id=? AND inventory_item_id=?',[$agreementId,$org,$itemId]);if(!$a)throw new RuntimeException('The agreement does not match this phone.');
    $reason=post_string('reason',500);if(mb_strlen($reason)<10)throw new RuntimeException('Enter a clear reason of at least 10 characters for this provider action.');
    $provider=$action==='apple_assign'?'apple_business_api':'samsung_knox_guard';$connection=ffdi_connection($provider,$org);if(!$connection)throw new RuntimeException('No approved '.$provider.' connection is configured for this business.');
    if($provider==='samsung_knox_guard' && $connection['scope_code']==='GLOBAL' && (int)$connection['multi_tenant_authorized']!==1)throw new RuntimeException('Samsung has not authorised this global FoneFlex connection for multi-tenant use.');
    $notice=ffdi_require_device_notice($org,$agreementId,$itemId);$checks=ffdi_agreement_checks($org,$agreementId);$deviceUid=(string)($item['imei1']?:$item['serial_no']);if($deviceUid==='')throw new RuntimeException('Device IMEI/serial number is missing.');
    if($action==='knox_upload'){
        if(in_array($checks['agreement']['status'],['draft','terminated','settled'],true))throw new RuntimeException('Only a live, non-draft hire-purchase plan can be enrolled.');
        $approve='FF'.$org.'-'.$agreementId;if(strlen($approve)>90)$approve=substr($approve,0,90);
        ffdi_provider_record($org,$itemId,$provider,(int)$connection['id'],$deviceUid,$approve,['enrollment_status'=>'upload_pending']);
        ffdi_action_submit((int)$connection['id'],$org,$itemId,$agreementId,$provider,'device_upload',$reason,function()use($connection,$deviceUid,$approve){return ffdi_samsung_request($connection,'POST','/devices/uploads',['deviceList'=>[['deviceUid'=>$deviceUid,'approveId'=>$approve]],'autoAccept'=>false,'autoLock'=>false,'enableBlockFactoryReset'=>true]);});
        ffdev_log_event($org,$itemId,'knox_guard_device_upload_submitted',['agreement_id'=>$agreementId,'imei_present'=>true,'factory_reset_block_requested'=>true]);
    }elseif($action==='knox_approve'){
        $map=get_one('SELECT * FROM device_provider_devices WHERE organization_id=? AND inventory_item_id=? AND provider_key=?',[$org,$itemId,$provider]);if(!$map||empty($map['approve_id']))throw new RuntimeException('Submit the device to Knox Guard before approving it.');
        ffdi_action_submit((int)$connection['id'],$org,$itemId,$agreementId,$provider,'device_approve',$reason,function()use($connection,$deviceUid,$map,$reason){return ffdi_samsung_request($connection,'POST','/devices/approve',['deviceUid'=>$deviceUid,'approveId'=>$map['approve_id'],'approveComment'=>$reason]);});
        ffdi_provider_record($org,$itemId,$provider,(int)$connection['id'],$deviceUid,$map['approve_id'],['enrollment_status'=>'approval_submitted']);
        ffdev_log_event($org,$itemId,'knox_guard_approval_submitted',['agreement_id'=>$agreementId]);
    }elseif($action==='knox_lock'){
        if($checks['overdue']<1)throw new RuntimeException('A remote lock is not permitted: there is no verified overdue installment.');if($checks['pending']>0||$checks['disputes']>0)throw new RuntimeException('A remote lock is blocked while a payment is pending verification or a customer dispute is open.');if(in_array($checks['agreement']['status'],['settled','terminated','draft'],true))throw new RuntimeException('This agreement is not eligible for a remote lock.');
        ffdi_action_submit((int)$connection['id'],$org,$itemId,$agreementId,$provider,'device_lock',$reason,function()use($connection,$deviceUid,$reason){return ffdi_samsung_request($connection,'POST','/devices/lock',['deviceUid'=>$deviceUid,'message'=>'FoneFlex account restriction. Contact your retailer. Reference: '.$reason]);});ffdev_log_event($org,$itemId,'knox_guard_lock_submitted',['agreement_id'=>$agreementId,'reason'=>$reason]);
    }elseif($action==='knox_unlock'){
        if($checks['pending']>0||$checks['disputes']>0||$checks['overdue']>0)throw new RuntimeException('Unlock is blocked until overdue amounts, pending payments and open disputes have been resolved.');
        ffdi_action_submit((int)$connection['id'],$org,$itemId,$agreementId,$provider,'device_unlock',$reason,function()use($connection,$deviceUid,$reason){return ffdi_samsung_request($connection,'POST','/devices/unlock',['deviceUid'=>$deviceUid,'message'=>'FoneFlex restriction removed. '.$reason]);});ffdev_log_event($org,$itemId,'knox_guard_unlock_submitted',['agreement_id'=>$agreementId,'reason'=>$reason]);
    }elseif($action==='knox_complete'){
        if($checks['balance']>0.005||$checks['pending']>0||$checks['disputes']>0||$checks['agreement']['status']!=='settled')throw new RuntimeException('Permanent completion is allowed only after the agreement is settled, the full price is verified as paid, and no payment or dispute remains pending.');
        ffdi_action_submit((int)$connection['id'],$org,$itemId,$agreementId,$provider,'device_complete',$reason,function()use($connection,$deviceUid,$reason){return ffdi_samsung_request($connection,'POST','/devices/complete',['deviceUid'=>$deviceUid,'message'=>'Hire-purchase completed. '.$reason]);});ffdev_log_event($org,$itemId,'knox_guard_completion_submitted',['agreement_id'=>$agreementId,'reason'=>$reason]);
    }elseif($action==='apple_assign'){
        if($checks['agreement']['status']==='draft'||$checks['agreement']['status']==='terminated')throw new RuntimeException('Only a valid organisation-owned device sale can be assigned to Apple Business MDM.');
        $serial=trim((string)($item['serial_no']??''));if($serial==='')throw new RuntimeException('Apple ADE requires the device serial number; IMEI is not a substitute.');
        $public=json_decode((string)$connection['public_config_json'],true)?:[];$mdmId=(string)($public['mdm_server_id']??'');if($mdmId==='')throw new RuntimeException('Set the Apple Business MDM server ID in Device integrations first.');
        $device=ffdi_apple_find_org_device($connection,$serial);if(!$device)throw new RuntimeException('This serial number was not found in this Apple Business organisation. Confirm the eligible device was added by Apple or an authorised reseller.');
        $servers=ffdi_apple_mdm_servers($connection);$serverFound=false;foreach($servers as $s)if((string)($s['id']??'')===$mdmId)$serverFound=true;if(!$serverFound)throw new RuntimeException('The configured Apple MDM server ID was not found in this Apple Business account.');
        $body=['data'=>['type'=>'orgDeviceActivities','attributes'=>['activityType'=>'ASSIGN_DEVICES'],'relationships'=>['mdmServer'=>['data'=>['type'=>'mdmServers','id'=>$mdmId]],'devices'=>['data'=>[['type'=>'orgDevices','id'=>(string)$device['id']]]]]]];
        $result=ffdi_action_submit((int)$connection['id'],$org,$itemId,$agreementId,$provider,'ade_assign',$reason,function()use($connection,$body){return ffdi_apple_request($connection,'POST','/v1/orgDeviceActivities',$body);});
        $last=get_one('SELECT provider_request_id FROM device_provider_actions WHERE id=?',[$result]);ffdi_provider_record($org,$itemId,$provider,(int)$connection['id'],$serial,'',['provider_assignment_activity_id'=>$last['provider_request_id']??null,'enrollment_status'=>'ade_assignment_submitted']);ffdev_log_event($org,$itemId,'apple_ade_assignment_submitted',['agreement_id'=>$agreementId,'mdm_server_configured'=>true]);
    }else throw new RuntimeException('Unknown device integration action.');
}

function ffdi_render_connection_form(string $provider,int $org): void
{
    $c=is_platform_admin()?get_one('SELECT * FROM device_provider_connections WHERE scope_code=\"GLOBAL\" AND provider_key=?',[$provider]):ffdi_connection($provider,$org);$public=$c?json_decode((string)$c['public_config_json'],true)?:[]:[];$owner=!is_platform_admin();$prefix=$provider==='samsung_knox_guard'?'Samsung Knox Guard':'Apple Business API / ADE';
    echo '<div class="panel"><div class="panel-head"><div><h3>'.h($prefix).'</h3><p class="muted">'.($c?'Saved connection · credentials are encrypted and never displayed again.':'No connection saved yet.').'</p></div><span class="pill '.($c?'success':'warning').'">'.($c?'Configured':'Credentials required').'</span></div>';
    if($provider==='samsung_knox_guard'){
        echo '<p>For direct merchant-owned integration, enter the Knox API Client ID and original keys.json file contents. Samsung must approve the Knox Guard account and API access. The reset-block flag is requested during device upload but is only applied if Samsung has enabled the feature for your account.</p><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_connection"><input type="hidden" name="provider" value="samsung_knox_guard"><div class="form-grid"><div><label>Connection name</label><input required name="connection_name" maxlength="150" value="'.h($c['connection_name']??'Samsung Knox Guard').'"></div><div><label>API region</label><select name="region"><option value="eu"'.(($c['region_code']??'eu')==='eu'?' selected':'').'>EU</option><option value="us"'.(($c['region_code']??'')==='us'?' selected':'').'>US</option></select></div><div><label>Environment</label><select name="environment"><option value="development"'.(($c['environment']??'')==='development'?' selected':'').'>Development</option><option value="production"'.(($c['environment']??'production')==='production'?' selected':'').'>Production</option></select></div><div><label>Knox API Client ID</label><input name="client_id" autocomplete="off" placeholder="Enter Client Identifier" value=""></div><div class="span-2"><label>Full keys.json content</label><textarea name="keys_json" rows="6" autocomplete="off" placeholder="Paste keys.json JSON. It contains your private key; it will be encrypted at rest and never displayed again."></textarea><small>Do not email or share this key. Generate/download it from the official Knox API Portal. Leave blank to retain the previously saved key.</small></div></div>';
        if(is_platform_admin())echo '<label class="checkline"><input type="checkbox" name="multi_tenant_authorized" value="1"'.(!empty($c['multi_tenant_authorized'])?' checked':'').'> I confirm Samsung has expressly authorised this FoneFlex global account to operate across multiple independent retailer tenants.</label><p class="hint">A checkbox is only an internal attestation; obtain written Samsung approval before enabling global multi-tenant use. If you do not have that approval, configure each retailer’s own account.</p>';
        echo '<button class="btn primary">Save encrypted Samsung credentials</button></form>';
    }else{
        echo '<p>Apple Business API is used to verify organisation device inventory and assign eligible organisation-owned devices to an MDM server for Automated Device Enrolment. It does not itself provide consumer-financing payment lock controls. The MDM service associated with the server performs device commands.</p><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_connection"><input type="hidden" name="provider" value="apple_business_api"><div class="form-grid"><div><label>Connection name</label><input required name="connection_name" maxlength="150" value="'.h($c['connection_name']??'Apple Business ADE').'"></div><div><label>Apple API Client ID</label><input name="client_id" autocomplete="off" placeholder="Client ID"></div><div><label>Apple Key ID</label><input name="key_id" autocomplete="off" placeholder="Key ID"></div><div><label>MDM server ID</label><input required name="mdm_server_id" value="'.h($public['mdm_server_id']??'').'" placeholder="ID from Apple Business"></div><div class="span-2"><label>Apple private key (.p8 PEM)</label><textarea name="private_key_pem" rows="6" autocomplete="off" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"></textarea><small>Leave blank to retain previously saved key. This private key is encrypted in FoneFlex. Never send it in chat or email.</small></div></div><button class="btn primary">Save encrypted Apple credentials</button></form>';
    }
    if($c){echo '<form method="post" style="margin-top:12px"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="test_connection"><input type="hidden" name="provider" value="'.h($provider).'"><button class="btn">Test connection</button></form><p class="hint">Last test: '.h($c['last_tested_at']??'Not tested').' · Status: '.h($c['connection_status']).'</p>';}echo '</div>';
}
function ffdi_render_integrations(int $org): void
{
    $platform=is_platform_admin();$scope=$platform?'GLOBAL':'ORG:'.$org;$params=[$scope];$connections=get_all('SELECT * FROM device_provider_connections WHERE scope_code=? ORDER BY provider_key',$params);
    layout_start('Device integrations','device-integrations');alert_box();echo '<div class="page-heading"><div><div class="eyebrow">OEM & DEVICE ENROLMENT CONNECTORS</div><h1>Device integrations</h1><p class="muted">Configure supported provider connections for this '.($platform?'FoneFlex platform account':'retailer workspace').'. Credentials are encrypted at rest.</p></div><span class="pill warning">Live vendor access required</span></div>';
    echo '<div class="info-note"><b>Production boundary:</b> These connectors use official documented API flows. Actual operation requires your own approved Samsung Knox Guard account/API access, or Apple Business API account and an active MDM server. The application cannot create vendor eligibility, register a device with Apple/Samsung or switch on a provider feature without the vendor account.</div>';
    ffdi_render_connection_form('samsung_knox_guard',$org);ffdi_render_connection_form('apple_business_api',$org);
    $where=$platform?'':' WHERE i.organization_id=?';$rows=get_all('SELECT i.id,i.organization_id,i.inventory_no,i.manufacturer,i.model,i.imei1,i.serial_no,a.id agreement_id,a.agreement_no,a.status agreement_status,o.name organization_name FROM inventory_items i JOIN organizations o ON o.id=i.organization_id LEFT JOIN (SELECT aa.* FROM agreements aa JOIN (SELECT organization_id,inventory_item_id,MAX(id) mx FROM agreements WHERE status NOT IN ("draft","terminated") GROUP BY organization_id,inventory_item_id) z ON z.mx=aa.id) a ON a.organization_id=i.organization_id AND a.inventory_item_id=i.id'.$where.' ORDER BY i.created_at DESC LIMIT 250',$platform?[]:[$org]);
    echo '<div class="panel"><div class="panel-head"><h3>Devices available for OEM management</h3><input class="search-input" data-table-search="ffDeviceIntegrationTable" placeholder="Search IMEI, serial, model, agreement or retailer"></div><div class="table-wrap"><table id="ffDeviceIntegrationTable"><thead><tr><th>Device / retailer</th><th>IMEI / serial</th><th>Agreement</th><th>Plan status</th><th>Device controls</th></tr></thead><tbody>';
    foreach($rows as $r){$url='?r=device-integration-device&id='.(int)$r['id'].($platform?'&org='.(int)$r['organization_id']:'');echo '<tr><td><b>'.h($r['manufacturer'].' '.$r['model']).'</b><small>'.h($r['inventory_no']).($platform?' · '.h($r['organization_name']):'').'</small></td><td>'.h($r['imei1']??'—').'<small>'.h($r['serial_no']??'').'</small></td><td>'.h($r['agreement_no']??'No active plan').'</td><td>'.h($r['agreement_status']??'—').'</td><td><a class="tiny-btn" href="'.h($url).'">Open provider controls</a></td></tr>';}
    if(!$rows)echo '<tr><td colspan="5" class="empty">No phones are available in this workspace.</td></tr>';echo '</tbody></table></div></div>';
    if($platform)echo '<div class="info-note"><b>Multi-tenant note:</b> A global Samsung connection is only usable for retailer devices if Samsung has expressly approved FoneFlex to act as a third-party portal/provider for those separate tenants. Otherwise each retailer must supply a separately authorised connection. Apple Business organisations and their device/MDM assignments are also organisation-specific.</div>';
    layout_end();
}
function ffdi_handle_routes(string $route): bool
{
    if(!in_array($route,['device-integrations','device-integration-device'],true))return false;
    $platform=is_platform_admin();$targetOrg=$platform?(int)($_GET['org']??0):org_id();if($platform)require_role(['platform_admin']);else require_role(['owner','admin']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $action=post_string('action',40);
        if($action==='save_connection'){
            $provider=post_string('provider',40);$label=post_string('connection_name',150);$environment=post_string('environment',20)?:'production';$region=post_string('region',10)?:'eu';$public=[];$secret=[];
            if($provider==='samsung_knox_guard'){$secret=['client_id'=>post_string('client_id',250),'keys_json'=>(string)($_POST['keys_json']??'')];$multi=$platform&&isset($_POST['multi_tenant_authorized']);if(!in_array($environment,['development','production'],true)||!in_array($region,['eu','us'],true))throw new RuntimeException('Select a valid Samsung API region and environment.');}
            elseif($provider==='apple_business_api'){$secret=['client_id'=>post_string('client_id',300),'key_id'=>post_string('key_id',100),'private_key_pem'=>(string)($_POST['private_key_pem']??'')];$public=['mdm_server_id'=>post_string('mdm_server_id',150)];$environment='production';$region='global';$multi=false;}
            else throw new RuntimeException('Unsupported OEM provider.');
            ffdi_save_connection($provider,$targetOrg,$public,$secret,$label?:$provider,$environment,$region,$multi??false);flash('success','Provider credentials saved encrypted. Test the connection before enrolling any customer handset.');redirect('?r=device-integrations'.($platform?'&org='.$targetOrg:''));
        }
        if($action==='test_connection'){
            $provider=post_string('provider',40);$connection=is_platform_admin()?get_one('SELECT * FROM device_provider_connections WHERE scope_code=\"GLOBAL\" AND provider_key=?',[$provider]):ffdi_connection($provider,$targetOrg);if(!$connection)throw new RuntimeException('Save a provider connection first.');
            if($provider==='samsung_knox_guard'){$testConnection=$connection;if($testConnection['scope_code']==='GLOBAL')$testConnection['scope_code']='TEST_ONLY';$res=ffdi_samsung_request($testConnection,'GET','/authorization');$summary=['provider'=>'Samsung Knox Guard','authorization'=>'API responded','response'=>$res['json']];}
            elseif($provider==='apple_business_api'){$servers=ffdi_apple_mdm_servers($connection);$summary=['provider'=>'Apple Business API','mdm_server_count'=>count($servers),'mdm_servers'=>array_map(fn($s)=>['id'=>$s['id']??'','name'=>$s['attributes']['name']??''],array_slice($servers,0,20))];}
            else throw new RuntimeException('Unknown provider.');
            db()->prepare('UPDATE device_provider_connections SET connection_status="tested",last_tested_at=NOW(),last_error=NULL WHERE id=?')->execute([$connection['id']]);audit('device.provider_connection_tested','provider_connection',(int)$connection['id'],['provider'=>$provider,'result'=>'success']);flash('success','Connection test succeeded. '.($provider==='apple_business_api'?'The Apple MDM server list has been retrieved.':'Samsung Knox Guard API authorised the connection.'));redirect('?r=device-integrations'.($platform?'&org='.$targetOrg:''));
        }
        $itemId=(int)($_POST['inventory_item_id']??0);$agreementId=(int)($_POST['agreement_id']??0);$return='?r=device-integration-device&id='.$itemId.($platform?'&org='.$targetOrg:'');
        if($action==='record_device_notice'){if($platform)throw new RuntimeException('Only the retailer owner/admin may record the customer device-management notice.');ffdi_device_notice_post($targetOrg,$itemId,$agreementId);flash('success','Customer device-financing notice acknowledgement recorded.');redirect($return);}
        ffdi_handle_device_action($targetOrg,$itemId,$agreementId,$action);flash('success','Provider action was submitted. “Submitted” means the provider accepted the API request; it does not prove that the handset has completed the command. Check the provider portal/device status.');redirect($return);
    }
    if($route==='device-integrations'){ffdi_render_integrations($targetOrg);return true;}
    $itemId=(int)($_GET['id']??0);$item=get_one('SELECT i.*,o.name organization_name FROM inventory_items i JOIN organizations o ON o.id=i.organization_id WHERE i.id=? AND i.organization_id=?',[$itemId,$targetOrg]);if(!$item)throw new RuntimeException('Phone not found in the selected business workspace.');
    $agreement=get_one('SELECT a.*,c.full_name,c.phone,c.id customer_id FROM agreements a JOIN customers c ON c.id=a.customer_id WHERE a.inventory_item_id=? AND a.organization_id=? AND a.status NOT IN ("draft","terminated") ORDER BY a.id DESC LIMIT 1',[$itemId,$targetOrg]);
    $consent=$agreement?get_one('SELECT * FROM device_management_consents WHERE organization_id=? AND agreement_id=? AND inventory_item_id=? ORDER BY id DESC LIMIT 1',[$targetOrg,$agreement['id'],$itemId]):null;
    $events=$agreement?get_all('SELECT * FROM device_provider_actions WHERE organization_id=? AND inventory_item_id=? ORDER BY requested_at DESC LIMIT 50',[$targetOrg,$itemId]):[];
    $mapS=get_one('SELECT * FROM device_provider_devices WHERE organization_id=? AND inventory_item_id=? AND provider_key="samsung_knox_guard"',[$targetOrg,$itemId]);$mapA=get_one('SELECT * FROM device_provider_devices WHERE organization_id=? AND inventory_item_id=? AND provider_key="apple_business_api"',[$targetOrg,$itemId]);
    $sConn=ffdi_connection('samsung_knox_guard',$targetOrg);$aConn=ffdi_connection('apple_business_api',$targetOrg);$checks=$agreement?ffdi_agreement_checks($targetOrg,(int)$agreement['id']):null;
    layout_start('OEM device controls','device-integrations');alert_box();echo '<div class="page-heading"><div><div class="eyebrow">OEM DEVICE CONTROL</div><h1>'.h($item['manufacturer'].' '.$item['model']).'</h1><p class="muted">'.h($item['inventory_no']).' · '.h($item['organization_name']).'</p></div><a class="btn" href="?r=device-integrations'.($platform?'&org='.$targetOrg:'').'">Back to integrations</a></div>';
    echo '<div class="kpi-grid two"><div class="kpi-card"><span>IMEI 1</span><strong>'.h($item['imei1']??'—').'</strong></div><div class="kpi-card"><span>Serial number</span><strong>'.h($item['serial_no']??'—').'</strong></div></div>';
    if(!$agreement){echo '<div class="alert warning">This phone has no active hire-purchase agreement. Link a customer agreement before using financing-device controls or Apple ADE assignment.</div>';layout_end();return true;}
    echo '<div class="panel"><h3>Hire-purchase binding</h3><div class="health-list"><div><span>Agreement</span><b>'.h($agreement['agreement_no']).'</b></div><div><span>Customer</span><b>'.h($agreement['full_name']).'</b></div><div><span>Status</span><b>'.h($agreement['status']).'</b></div><div><span>Verified paid</span><b>'.money($checks['paid']).'</b></div><div><span>Outstanding</span><b>'.money($checks['balance']).'</b></div><div><span>Overdue installments</span><b>'.(int)$checks['overdue'].'</b></div><div><span>Pending payments</span><b>'.(int)$checks['pending'].'</b></div><div><span>Open disputes</span><b>'.(int)$checks['disputes'].'</b></div></div></div>';
    echo '<div class="panel"><h3>Customer device-financing notice</h3><p class="muted">Record the separate notice covering OEM enrolment, possible device restrictions in the event of contractual default, remote unlock after cure/full settlement, customer support and any applicable data sharing. This is not a substitute for legal review or a signed customer agreement.</p>';
    if($consent){echo '<div class="alert success">Acknowledgement recorded for '.h($consent['consent_customer_name']).' on '.h($consent['recorded_at']).' via '.h($consent['acknowledgement_method']).'.</div>';}elseif(!$platform){echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="record_device_notice"><input type="hidden" name="inventory_item_id" value="'.$itemId.'"><input type="hidden" name="agreement_id" value="'.(int)$agreement['id'].'"><div class="form-grid"><div><label>Customer name acknowledging notice</label><input required name="consent_customer_name" value="'.h($agreement['full_name']).'"></div><div><label>Acknowledgement method</label><select name="acknowledgement_method" required><option value="">Choose</option><option value="written">Written / signed</option><option value="digital">Digital</option><option value="verbal">Verbal (record details)</option></select></div><div class="span-2"><label>Notice summary confirmed with customer</label><textarea required name="notice_summary" minlength="30" rows="3" placeholder="Summarise the reset/enrolment programme, default process, restrictions, support and release process explained to the customer."></textarea></div></div><label class="checkline"><input type="checkbox" required> I confirm this acknowledgement is accurate and I have explained the customer notice.</label><button class="btn primary">Record acknowledgement</button></form>';}else echo '<div class="alert warning">Customer notice acknowledgement is not yet recorded by the retailer owner/admin.</div>';echo '</div>';
    echo '<div class="section-grid"><div class="panel"><h3>Samsung Knox Guard</h3><p class="muted">Connection: '.h($sConn?'configured':'not configured').' · Registration: '.h($mapS['enrollment_status']??'not submitted').'</p><p>Use only on eligible Samsung devices with Knox Guard account approval. Upload requests the factory-reset block policy, but Samsung only applies a feature that is enabled for the account and device.</p>';
    if($sConn&&$consent){$form=function(string $act,string $label,string $help='')use($itemId,$agreement){echo '<form method="post" style="margin:8px 0"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="'.h($act).'"><input type="hidden" name="inventory_item_id" value="'.$itemId.'"><input type="hidden" name="agreement_id" value="'.(int)$agreement['id'].'"><label>Reason</label><input required minlength="10" maxlength="500" name="reason" placeholder="Record the business reason"><button class="btn">'.h($label).'</button>'.($help?'<small class="hint">'.h($help).'</small>':'').'</form>';};$form('knox_upload','1. Upload device to Knox Guard','Adds the IMEI to the Knox account; device may then need approval.');$form('knox_approve','2. Approve enrolled device','Approve the pending device against the hire-purchase reference.');if($checks['overdue']>0&&$checks['pending']===0&&$checks['disputes']===0)$form('knox_lock','3. Request remote lock','Available only when an installment is overdue, with no pending payment or open dispute.');if($checks['overdue']===0&&$checks['pending']===0&&$checks['disputes']===0)$form('knox_unlock','Request remote unlock','Available when there are no overdue installments, pending payments or open disputes.');if($checks['balance']<=0.005&&$checks['pending']===0&&$checks['disputes']===0&&$agreement['status']==='settled')$form('knox_complete','4. Submit final device completion','Samsung completion can have a provider-defined completion period; verify status in Knox Guard.');}else echo '<div class="alert warning">'.($platform?'Platform Admin is in oversight mode; retailer owner/admin must initiate customer device actions.':(!$sConn?'Configure Samsung Knox Guard first.':'Record the customer device-management notice first.')).'</div>';echo '</div>';
    echo '<div class="panel"><h3>Apple Business / ADE</h3><p class="muted">Connection: '.h($aConn?'configured':'not configured').' · Assignment: '.h($mapA['enrollment_status']??'not assigned').'</p><p>ADE applies to eligible organisation-owned iPhones/iPads present in Apple Business and assigned to an MDM server. Assignment is not proof that setup/enrolment has completed and ADE itself is not a financing payment lock.</p>';
    if($aConn&&$consent&&$item['serial_no'])echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="apple_assign"><input type="hidden" name="inventory_item_id" value="'.$itemId.'"><input type="hidden" name="agreement_id" value="'.(int)$agreement['id'].'"><label>Reason for MDM assignment</label><input required minlength="10" maxlength="500" name="reason" placeholder="Confirm organisation ownership and ADE purpose"><button class="btn">Assign to configured Apple MDM server</button></form>';
    else echo '<div class="alert warning">'.($platform?'Retailer owner/admin must initiate Apple device assignment.':(!$aConn?'Configure Apple Business API first.':(!$consent?'Record the customer/device-management notice first.':'Apple ADE requires the device serial number.'))).'</div>';
    echo '<div class="info-note">To send MDM commands such as device lock, lost-mode or unlock, FoneFlex still needs a selected MDM provider/service with its own authenticated command API. Apple Business API only handles organisation device management inventory and MDM assignment here. Do not use Managed Lost Mode as a payment collection lock.</div></div></div>';
    echo '<div class="panel"><h3>OEM action history</h3><div class="table-wrap"><table><thead><tr><th>Date</th><th>Provider</th><th>Action</th><th>Status</th><th>Provider request</th><th>Reason</th></tr></thead><tbody>';foreach($events as $ev)echo '<tr><td>'.h($ev['requested_at']).'</td><td>'.h($ev['provider_key']).'</td><td>'.h(str_replace('_',' ',$ev['action_type'])).'</td><td><span class="pill '.($ev['action_status']==='failed'?'danger':'warning').'">'.h($ev['action_status']).'</span></td><td>'.h($ev['provider_request_id']??$ev['request_reference']).'</td><td>'.h($ev['reason']??'').'</td></tr>';if(!$events)echo '<tr><td colspan="6" class="empty">No OEM actions have been submitted.</td></tr>';echo '</tbody></table></div><p class="hint">Submitted means the API accepted the request; it does not mean the handset received or completed the command. Confirm device state in the OEM/MDM portal or status API.</p></div>';
    layout_end();return true;
}
