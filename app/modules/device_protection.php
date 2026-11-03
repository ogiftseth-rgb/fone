<?php
declare(strict_types=1);

/**
 * FoneFlex v0.5 device identity scanning and OEM-protection register.
 * This module does not emulate an OEM lock or call undocumented provider APIs.
 */
function ffdev_imei_is_valid(string $imei): bool
{
    if (!preg_match('/^\d{15}$/', $imei)) return false;
    $sum = 0;
    for ($i = 0; $i < 14; $i++) {
        $n = (int)$imei[$i];
        if (($i % 2) === 1) { $n *= 2; if ($n > 9) $n -= 9; }
        $sum += $n;
    }
    return ((10 - ($sum % 10)) % 10) === (int)$imei[14];
}

function ffdev_hmac(string $value): string
{
    global $config;
    $key = (string)($config['security']['data_encryption_key'] ?? '');
    if (strlen($key) < 32) throw new RuntimeException('Configure the stable security.data_encryption_key before registering phone IMEIs.');
    return hash_hmac('sha256', $value, $key, true);
}

function ffdev_log_event(int $orgId, int $itemId, string $event, array $details = [], ?int $actorId = null): void
{
    $actorId ??= (int)(user()['id'] ?? 0);
    $stmt = db()->prepare('INSERT INTO device_protection_events(organization_id,inventory_item_id,event_type,event_details,actor_user_id) VALUES(?,?,?,?,?)');
    $stmt->execute([$orgId, $itemId, $event, json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $actorId ?: null]);
}

function ffdev_provider_label(string $key): string
{
    return [
        'unconfigured' => 'Not configured',
        'samsung_knox_guard' => 'Samsung Knox Guard',
        'android_zero_touch' => 'Android zero-touch (enterprise enrolment)',
        'android_setup_qr' => 'Android setup QR (enterprise enrolment)',
        'apple_ade' => 'Apple Automated Device Enrolment (organisation-owned)',
        'other_approved' => 'Other approved financing provider',
    ][$key] ?? 'Unknown provider';
}

function ffdev_status_label(string $status): string
{
    return [
        'not_assessed' => 'Not assessed', 'eligible' => 'Eligible — enrolment pending',
        'unsupported' => 'Unsupported', 'enrolment_pending' => 'Enrolment pending',
        'provider_confirmed' => 'Provider enrolment confirmed', 'failed' => 'Enrolment failed',
        'release_requested' => 'Release requested', 'released' => 'Released',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function ffdev_ensure_record(int $orgId, int $itemId, ?int $actorId = null): void
{
    $stmt = db()->prepare('INSERT IGNORE INTO device_protection_records(organization_id,inventory_item_id,provider_key,protection_status,created_by,updated_by) VALUES(?,?,"unconfigured","not_assessed",?,?)');
    $stmt->execute([$orgId, $itemId, $actorId ?: (int)(user()['id'] ?? 0), $actorId ?: (int)(user()['id'] ?? 0)]);
}

function ffdev_render_scanner(): void
{
    echo <<<'HTML'
<div class="panel" style="margin-top:12px">
  <div class="panel-head"><div><h3>Scan phone box / device identifier</h3><p class="muted">Use the rear camera to scan a QR or barcode on the box. If the code contains an IMEI or serial number, FoneFlex will place it in the matching field.</p></div><span class="pill neutral">Camera scanner</span></div>
  <div class="section-grid">
    <div>
      <video id="ffDeviceScanVideo" autoplay playsinline muted style="display:none;width:100%;max-height:260px;background:#111;border-radius:12px;object-fit:cover"></video>
      <div class="form-grid"><button type="button" class="btn primary" id="ffDeviceScanStart">Start camera scanner</button><button type="button" class="btn" id="ffDeviceScanStop" disabled>Stop scanner</button></div>
      <p class="hint" id="ffDeviceScanStatus" role="status">Scanner is stopped. Camera access requires HTTPS or localhost and browser permission.</p>
    </div>
    <div class="health-list">
      <div><span>Scanned code</span><b id="ffDeviceScanValue">None</b></div>
      <div><span>Scanner support</span><b id="ffDeviceScanSupport">Checking browser…</b></div>
      <div><span>IMEI validation</span><b id="ffImeiCheck">Not checked</b></div>
    </div>
  </div>
  <p class="hint">A retail EAN/UPC product barcode normally identifies the product, not the handset IMEI. If the IMEI is not encoded, read it from the box label or display it on the handset with <b>*#06#</b>. Do not treat a product SKU as an IMEI.</p>
</div>
<script>
(() => {
  const video=document.getElementById('ffDeviceScanVideo');
  const start=document.getElementById('ffDeviceScanStart');
  const stop=document.getElementById('ffDeviceScanStop');
  const status=document.getElementById('ffDeviceScanStatus');
  const support=document.getElementById('ffDeviceScanSupport');
  const valueBox=document.getElementById('ffDeviceScanValue');
  const imeiCheck=document.getElementById('ffImeiCheck');
  const payloadInput=document.getElementById('ffScanPayload');
  const formatInput=document.getElementById('ffScanFormat');
  let stream=null, detector=null, running=false, busy=false, frame=0;
  function validImei(value){
    if(!/^\d{15}$/.test(value)) return false;
    let sum=0;
    for(let i=0;i<14;i++){let n=Number(value[i]);if(i%2===1){n*=2;if(n>9)n-=9;}sum+=n;}
    return ((10-(sum%10))%10)===Number(value[14]);
  }
  function setValue(id,val){const el=document.getElementById(id);if(el&&val)el.value=String(val).trim();}
  function parsePayload(raw,format){
    valueBox.textContent=raw.length>42?raw.slice(0,39)+'…':raw;
    payloadInput.value=raw; formatInput.value=format||'unknown';
    let found1='',found2='',serial='',manufacturer='',model='';
    try{
      const obj=JSON.parse(raw);
      if(obj&&typeof obj==='object'){
        found1=String(obj.imei1||obj.IMEI1||obj.imei||obj.IMEI||'').replace(/\D/g,'');
        found2=String(obj.imei2||obj.IMEI2||'').replace(/\D/g,'');
        serial=String(obj.serial||obj.serialNumber||obj.sn||obj.SN||'');
        manufacturer=String(obj.manufacturer||obj.brand||'');model=String(obj.model||obj.deviceModel||'');
      }
    }catch(e){}
    const m1=raw.match(/\bIMEI\s*1?\s*[:=#\- ]\s*(\d{15})/i);
    const m2=raw.match(/\bIMEI\s*2\s*[:=#\- ]\s*(\d{15})/i);
    const sn=raw.match(/\b(?:S\/N|SERIAL(?:\s*NUMBER)?|SN)\s*[:=#\- ]\s*([A-Z0-9-]{6,40})/i);
    if(!found1&&m1)found1=m1[1];if(!found2&&m2)found2=m2[1];if(!serial&&sn)serial=sn[1];
    if(!found1&&!found2&&/^\d{15}$/.test(raw))found1=raw;
    if(found1&&validImei(found1)){setValue('ffImei1',found1);imeiCheck.textContent='IMEI 1 checksum valid';}
    else if(found1){imeiCheck.textContent='IMEI 1 checksum invalid — verify manually';}
    if(found2&&validImei(found2)){setValue('ffImei2',found2);}
    else if(found2){imeiCheck.textContent='IMEI 2 checksum invalid — verify manually';}
    if(serial)setValue('ffSerialNo',serial);
    if(manufacturer)setValue('ffManufacturer',manufacturer);if(model)setValue('ffModel',model);
    if(!found1&&!found2&&!serial){status.textContent='Code scanned, but it does not contain a recognisable IMEI or serial number. Enter the IMEI from the phone or box label. A product barcode is not proof of device identity.';}
    else status.textContent='Code decoded. Verify each populated identifier against the device screen/box label before saving.';
  }
  async function scanLoop(){
    if(!running||busy)return;
    busy=true;
    try{
      if(video.readyState>=2){const results=await detector.detect(video);if(results.length){parsePayload(results[0].rawValue,results[0].format);stopScanner();busy=false;return;}}
    }catch(e){status.textContent='Scanner read error: '+e.message;}
    busy=false;frame=requestAnimationFrame(scanLoop);
  }
  async function startScanner(){
    if(!('BarcodeDetector' in window)){support.textContent='Native barcode scanner unavailable';status.textContent='This browser does not support BarcodeDetector. Use manual IMEI/serial entry or open FoneFlex in a supported Chromium browser over HTTPS.';return;}
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){status.textContent='Camera API unavailable. Use HTTPS and a supported browser.';return;}
    try{
      const formats=await BarcodeDetector.getSupportedFormats();
      const preferred=['qr_code','code_128','code_39','ean_13','ean_8','upc_a','upc_e','itf','data_matrix','pdf417'];
      const selected=preferred.filter(f=>formats.includes(f));
      detector=new BarcodeDetector({formats:selected});
      stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}},audio:false});
      video.srcObject=stream;video.style.display='block';await video.play();running=true;start.disabled=true;stop.disabled=false;support.textContent='Supported';status.textContent='Point the camera at the phone box QR/barcode and hold steady.';scanLoop();
    }catch(e){status.textContent='Could not start camera scanner: '+e.message;stopScanner();}
  }
  function stopScanner(){running=false;if(frame)cancelAnimationFrame(frame);if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;video.srcObject=null;video.style.display='none';start.disabled=false;stop.disabled=true;}
  start.addEventListener('click',startScanner);stop.addEventListener('click',stopScanner);window.addEventListener('pagehide',stopScanner);
  support.textContent=('BarcodeDetector' in window)?'Available':'Manual fallback required';
})();
</script>
HTML;
}

function ffdev_render_phone_form(): void
{
    echo '<div class="modal" id="phoneModal"><form method="post" class="modal-card large" id="ffPhoneForm"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save"><input type="hidden" name="scan_method" id="ffScanMethod" value="manual"><input type="hidden" name="scan_payload" id="ffScanPayload"><input type="hidden" name="scan_format" id="ffScanFormat"><div class="modal-head"><h3>Register phone and device identity</h3><button type="button" class="modal-close">×</button></div>';
    ffdev_render_scanner();
    echo '<div class="form-grid"><div><label>Manufacturer / brand</label><input id="ffManufacturer" required name="manufacturer" placeholder="Samsung"></div><div><label>Model</label><input id="ffModel" required name="model" placeholder="Galaxy A15"></div><div><label>Storage capacity</label><input name="storage_capacity" placeholder="128GB"></div><div><label>Colour</label><input name="colour"></div><div><label>IMEI 1 <span class="required">*</span></label><input id="ffImei1" required name="imei1" inputmode="numeric" maxlength="15" pattern="[0-9]{15}" placeholder="15 digits"><small>Required. The checksum is validated before saving.</small></div><div><label>IMEI 2 (dual-SIM phones)</label><input id="ffImei2" name="imei2" inputmode="numeric" maxlength="15" pattern="[0-9]{15}" placeholder="15 digits"></div><div><label>Serial number</label><input id="ffSerialNo" name="serial_no" maxlength="100"></div><div><label>Supplier</label><input name="supplier_name"></div><div><label>Purchase cost (KES)</label><input required type="number" min="0" step="0.01" name="purchase_cost"></div><div><label>Cash selling price (KES)</label><input required type="number" min="0.01" step="0.01" name="cash_price"></div><div><label>Financing eligibility</label><select name="financing_eligibility"><option value="pending_provider_review">Pending provider review</option><option value="unknown">Not checked</option><option value="unsupported">Unsupported</option></select></div></div><div class="info-note"><b>Identity rule:</b> The IMEI is the device identifier used to bind this stock record to the hire-purchase agreement. Scanning a product barcode alone does not enrol or protect the handset. After saving, complete the OEM/provider enrolment check in Device management.</div><button class="btn primary full">Save phone identity to inventory</button></form></div>';
}

function ffdev_handle_phone_inventory(): never
{
    require_role(['owner','admin','sales','accountant','auditor']);
    $oid=org_id();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {
        require_role(['owner','admin','sales']);
        $manufacturer=post_string('manufacturer',80);$model=post_string('model',120);
        $imei1=preg_replace('/\D/','',post_string('imei1',30));$imei2=preg_replace('/\D/','',post_string('imei2',30));
        $serial=post_string('serial_no',100);$cost=post_amount('purchase_cost');$price=post_amount('cash_price');
        $scanMethod=post_string('scan_method',20)==='camera'?'camera':'manual';$scanPayload=post_string('scan_payload',1000);$scanFormat=post_string('scan_format',50);
        if(!$manufacturer||!$model||$price<=0)throw new RuntimeException('Manufacturer, model and a valid cash price are required.');
        if(!ffdev_imei_is_valid($imei1))throw new RuntimeException('Enter a valid 15-digit IMEI 1. Check the IMEI using *#06# or the manufacturer label.');
        if($imei2!==''&&(!ffdev_imei_is_valid($imei2)||$imei2===$imei1))throw new RuntimeException('IMEI 2 must be a different valid 15-digit IMEI.');
        $ids=$imei2!==''?[$imei1,$imei2]:[$imei1,$imei1];
        $duplicate=get_one('SELECT id FROM inventory_items WHERE imei1 IN (?,?) OR imei2 IN (?,?) LIMIT 1',[$ids[0],$ids[1],$ids[0],$ids[1]]);
        if($duplicate)throw new RuntimeException('This device IMEI is already registered in FoneFlex. Reuse the existing phone record if it is a returned device; do not register it as a new handset.');
        $eligibility=post_string('financing_eligibility',40);if(!in_array($eligibility,['unknown','pending_provider_review','unsupported'],true))$eligibility='pending_provider_review';
        db()->beginTransaction();
        $no='PHN-'.date('ym').'-'.strtoupper(bin2hex(random_bytes(3)));
        db()->prepare('INSERT INTO inventory_items(organization_id,inventory_no,manufacturer,model,storage_capacity,colour,imei1,imei2,serial_no,purchase_cost,cash_price,supplier_name,financing_eligibility) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$oid,$no,$manufacturer,$model,post_string('storage_capacity',40),post_string('colour',50),$imei1,$imei2?:null,$serial?:null,$cost,$price,post_string('supplier_name',160)?:null,$eligibility]);
        $itemId=(int)db()->lastInsertId();
        db()->prepare('INSERT INTO device_imei_registry(imei_hmac,organization_id,inventory_item_id,imei_slot) VALUES(?,?,?,"imei1")')->execute([ffdev_hmac($imei1),$oid,$itemId]);
        if($imei2!=='')db()->prepare('INSERT INTO device_imei_registry(imei_hmac,organization_id,inventory_item_id,imei_slot) VALUES(?,?,?,"imei2")')->execute([ffdev_hmac($imei2),$oid,$itemId]);
        $scanDigest=$scanPayload!==''?ffdev_hmac($scanPayload):null;
        db()->prepare('INSERT INTO device_protection_records(organization_id,inventory_item_id,provider_key,protection_status,scan_method,scan_format,scan_digest,created_by,updated_by) VALUES(?,?,"unconfigured","not_assessed",?,?,?,?,?)')
            ->execute([$oid,$itemId,$scanMethod,$scanFormat?:null,$scanDigest,(int)user()['id'],(int)user()['id']]);
        ffdev_log_event($oid,$itemId,'phone_registered',['inventory_no'=>$no,'scan_method'=>$scanMethod,'scan_format'=>$scanFormat?:null,'imei1_checksum_valid'=>true]);
        db()->commit();
        audit('inventory.phone_identity_registered','inventory_item',$itemId,['inventory_no'=>$no,'scan_method'=>$scanMethod]);
        flash('success','Phone registered. Its IMEI is now bound to this inventory record. Complete the OEM/provider enrolment assessment before representing it as reset-resilient.');
        redirect('?r=phones');
    }
    $rows=get_all('SELECT i.*,d.provider_key,d.protection_status,a.agreement_no FROM inventory_items i LEFT JOIN device_protection_records d ON d.inventory_item_id=i.id AND d.organization_id=i.organization_id LEFT JOIN agreements a ON a.inventory_item_id=i.id AND a.organization_id=i.organization_id AND a.status NOT IN ("terminated","draft") WHERE i.organization_id=? ORDER BY i.created_at DESC LIMIT 300',[$oid]);
    layout_start('Phone inventory','phones');alert_box();
    echo '<div class="page-heading"><div><div class="eyebrow">STOCK & DEVICE IDENTITY</div><h1>Phone inventory</h1><p class="muted">Scan the phone box or QR code, validate the IMEI, and bind the handset to its FoneFlex inventory record before creating a hire-purchase agreement.</p></div><button class="btn primary" data-modal="phoneModal">＋ Scan / add phone</button></div>';
    echo '<div class="info-note"><b>Factory-reset protection is a separate OEM step.</b> FoneFlex keeps the IMEI and agreement relationship on its server, so resetting the phone does not erase its finance record. Preventing the handset itself from escaping device controls after a reset requires supported OEM/financing-provider enrolment, not merely scanning the IMEI.</div>';
    echo '<div class="panel"><div class="panel-head"><h3>Inventory register</h3><input class="search-input" data-table-search="phonesTable" placeholder="Search model, IMEI or agreement…"></div><div class="table-wrap"><table id="phonesTable"><thead><tr><th>Phone / serial</th><th>IMEI 1</th><th>Agreement</th><th>Stock status</th><th>OEM protection</th><th>Action</th></tr></thead><tbody>';
    if(!$rows)echo '<tr><td colspan="6" class="empty">No stock registered.</td></tr>';
    foreach($rows as $r){$pstatus=$r['protection_status']??'not_assessed';echo '<tr><td><b>'.h($r['manufacturer'].' '.$r['model']).'</b><small>'.h($r['inventory_no']).' · SN '.h($r['serial_no']??'—').'</small></td><td>'.h($r['imei1']).'</td><td>'.h($r['agreement_no']??'Not assigned').'</td><td><span class="pill '.($r['status']==='in_stock'?'success':'neutral').'">'.h(str_replace('_',' ',$r['status'])).'</span></td><td><span class="pill '.($pstatus==='provider_confirmed'?'success':($pstatus==='unsupported'?'danger':'warning')).'">'.h(ffdev_status_label($pstatus)).'</span></td><td><a class="tiny-btn" href="?r=device-protection&id='.(int)$r['id'].'">Protection record</a></td></tr>';}
    echo '</tbody></table></div></div>';
    ffdev_render_phone_form();
    echo <<<'HTML'
<script>
document.getElementById('ffPhoneForm')?.addEventListener('submit',function(){const p=document.getElementById('ffScanPayload').value;document.getElementById('ffScanMethod').value=p?'camera':'manual';});
</script>
HTML;
    layout_end();exit;
}

function ffdev_handle_protection_update(int $itemId, int $targetOrg): void
{
    require_role(['owner','admin']);
    $item=get_one('SELECT * FROM inventory_items WHERE id=? AND organization_id=?',[$itemId,$targetOrg]);
    if(!$item)throw new RuntimeException('Phone not found in this business inventory.');
    $provider=post_string('provider_key',60);$status=post_string('protection_status',40);$method=post_string('enrollment_method',40);
    $reference=post_string('provider_device_ref',150);$note=post_string('provider_notes',1000);
    $providers=['unconfigured','samsung_knox_guard','android_zero_touch','android_setup_qr','apple_ade','other_approved'];
    $statuses=['not_assessed','eligible','unsupported','enrolment_pending','provider_confirmed','failed','released'];
    $methods=['manual','reseller_preload','zero_touch','setup_qr','apple_ade'];
    if(!in_array($provider,$providers,true)||!in_array($status,$statuses,true)||!in_array($method,$methods,true))throw new RuntimeException('Select a supported provider, status and enrolment method.');
    if($status==='provider_confirmed'&&($provider==='unconfigured'||$reference===''||$note===''))throw new RuntimeException('A provider reference and verification note are required before recording provider-confirmed enrolment.');
    if($provider==='apple_ade'&&$status==='provider_confirmed'&&!str_contains(strtolower($note),'organization-owned')&&!str_contains(strtolower($note),'organisation-owned'))throw new RuntimeException('Apple Automated Device Enrolment is for organisation-owned devices. Confirm this explicitly in the verification note.');
    if(in_array($provider,['android_zero_touch','android_setup_qr','apple_ade'],true)&&$status==='provider_confirmed'){
        $note .= ' [Enterprise enrolment only: this is not a consumer finance-payment lock.]';
    }
    db()->beginTransaction();
    ffdev_ensure_record($targetOrg,$itemId,(int)user()['id']);
    $old=get_one('SELECT * FROM device_protection_records WHERE inventory_item_id=? AND organization_id=? FOR UPDATE',[$itemId,$targetOrg]);
    $note = mb_substr($note, 0, 1000);
    db()->prepare('UPDATE device_protection_records SET provider_key=?,protection_status=?,enrollment_method=?,provider_device_ref=?,provider_notes=?,provider_checked_by=?,provider_checked_at=NOW(),updated_by=?,updated_at=NOW() WHERE id=?')
        ->execute([$provider,$status,$method,$reference?:null,$note?:null,(int)user()['id'],(int)user()['id'],$old['id']]);
    $financingEligibility = $status === 'unsupported' ? 'unsupported' : 'pending_provider_review';
    if ($status === 'provider_confirmed' && in_array($provider, ['samsung_knox_guard','other_approved'], true)) $financingEligibility = 'eligible';
    db()->prepare('UPDATE inventory_items SET financing_eligibility=? WHERE id=? AND organization_id=?')->execute([$financingEligibility,$itemId,$targetOrg]);
    ffdev_log_event($targetOrg,$itemId,'provider_enrolment_status_updated',['provider'=>$provider,'status'=>$status,'method'=>$method,'provider_reference'=>$reference?:null,'note'=>$note]);
    db()->commit();
    audit('device.protection_status_updated','inventory_item',$itemId,['provider'=>$provider,'status'=>$status]);
    flash('success','Device protection record updated. FoneFlex records this as a staff-confirmed provider status; it has not independently verified an OEM API response.');
}

function ffdev_handle_device_routes(string $route): bool
{
    if($route==='phones'){ffdev_handle_phone_inventory();return true;}

    if($route==='device-management'){
        $platform=is_platform_admin();
        if($platform)require_role(['platform_admin']);else require_role(['owner','admin','sales','collections','auditor']);
        $where='';$params=[];if(!$platform){$where=' WHERE i.organization_id=?';$params[] = org_id();}
        $rows=get_all('SELECT i.id,i.organization_id,i.inventory_no,i.manufacturer,i.model,i.imei1,i.serial_no,i.status,o.name organization_name,d.provider_key,d.protection_status,d.provider_device_ref,a.agreement_no,c.full_name FROM inventory_items i JOIN organizations o ON o.id=i.organization_id LEFT JOIN device_protection_records d ON d.inventory_item_id=i.id AND d.organization_id=i.organization_id LEFT JOIN (SELECT organization_id,inventory_item_id,MAX(id) agreement_id FROM agreements WHERE status NOT IN ("terminated","draft") GROUP BY organization_id,inventory_item_id) ax ON ax.organization_id=i.organization_id AND ax.inventory_item_id=i.id LEFT JOIN agreements a ON a.id=ax.agreement_id LEFT JOIN customers c ON c.id=a.customer_id'.$where.' ORDER BY i.created_at DESC LIMIT 500',$params);
        layout_start('Device enrolment & protection','device-management');alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">OEM / FINANCING PROVIDER CONTROL</div><h1>Device enrolment & protection</h1><p class="muted">Track the handset’s OEM enrolment status and the FoneFlex agreement binding. A factory reset does not delete this server-side record.</p></div>'.($platform?'<span class="pill neutral">All retailers</span>':'<a class="btn" href="?r=phones">＋ Scan / add phone</a>').'</div>';
        echo '<div class="info-note"><b>What survives a factory reset?</b> The FoneFlex IMEI, inventory and hire-purchase link remain in the server database. For the device itself to automatically re-enrol or retain a financing restriction after reset, it must have been enrolled through a supported OEM or financing programme. FoneFlex cannot create this capability by QR scan alone.</div>';
        echo '<div class="section-grid"><div class="panel"><h3>Recommended Android financing route</h3><p>For supported Samsung devices, Samsung Knox Guard is designed for resellers and device-financing plans. It supports remote lock/unlock and restricts factory reset and other tampering actions while enrolled.</p><a class="text-link" href="https://docs.samsungknox.com/admin/knox-guard/" target="_blank" rel="noopener">Samsung Knox Guard documentation ↗</a></div><div class="panel"><h3>Enterprise enrolment is not a finance lock</h3><p>Android zero-touch / setup QR and Apple Automated Device Enrolment can re-provision organisation-managed devices in approved scenarios. They must not be presented as a consumer repayment lock.</p><p class="hint">Google expressly disallows using Android Management API for device-financing restrictions based on payment status.</p></div></div>';
        echo '<div class="panel"><div class="panel-head"><h3>'.($platform?'All retailers’ devices':'Your device register').'</h3><input class="search-input" data-table-search="deviceTable" placeholder="Search IMEI, customer, agreement or retailer…"></div><div class="table-wrap"><table id="deviceTable"><thead><tr><th>Retailer / phone</th><th>IMEI / serial</th><th>Hire-purchase plan</th><th>Provider</th><th>Enrolment status</th><th>Control</th></tr></thead><tbody>';
        foreach($rows as $r){$p=$r['provider_key']??'unconfigured';$s=$r['protection_status']??'not_assessed';$label=ffdev_provider_label($p);$action=$platform?'?r=device-protection&id='.(int)$r['id'].'&org='.(int)$r['organization_id']:'?r=device-protection&id='.(int)$r['id'];echo '<tr><td><b>'.h($r['manufacturer'].' '.$r['model']).'</b><small>'.h($r['organization_name']).' · '.h($r['inventory_no']).'</small></td><td>'.h($r['imei1']).'<small>SN '.h($r['serial_no']??'—').'</small></td><td>'.h($r['agreement_no']??'Not assigned').'<small>'.h($r['full_name']??'').'</small></td><td>'.h($label).'</td><td><span class="pill '.($s==='provider_confirmed'?'success':($s==='unsupported'||$s==='failed'?'danger':'warning')).'">'.h(ffdev_status_label($s)).'</span></td><td><a class="tiny-btn" href="'.h($action).'">View / update</a></td></tr>';}
        if(!$rows)echo '<tr><td colspan="6" class="empty">No phones are registered.</td></tr>';
        echo '</tbody></table></div></div>';layout_end();return true;
    }

    if($route==='device-protection'){
        $platform=is_platform_admin();$itemId=(int)($_GET['id']??0);$targetOrg=$platform?(int)($_GET['org']??0):org_id();
        if($platform)require_role(['platform_admin']);else require_role(['owner','admin','sales','collections','auditor']);
        $item=get_one('SELECT i.*,o.name organization_name FROM inventory_items i JOIN organizations o ON o.id=i.organization_id WHERE i.id=? AND i.organization_id=?',[$itemId,$targetOrg]);
        if(!$item)throw new RuntimeException('Phone was not found in the selected business workspace.');
        if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='update_identity'){
            if(!$platform)require_role(['owner','admin']);
            if($item['status']!=='in_stock')throw new RuntimeException('Device identifiers can only be corrected while the phone is in stock and not assigned to a hire-purchase agreement.');
            $active=get_one('SELECT id FROM agreements WHERE inventory_item_id=? AND organization_id=? AND status NOT IN ("terminated","draft") LIMIT 1',[$itemId,$targetOrg]);
            if($active)throw new RuntimeException('This phone is already bound to a hire-purchase plan. Its identity cannot be changed.');
            $newImei1=preg_replace('/\D/','',post_string('imei1',30));$newImei2=preg_replace('/\D/','',post_string('imei2',30));$newSerial=post_string('serial_no',100);
            if(!ffdev_imei_is_valid($newImei1))throw new RuntimeException('Enter a valid 15-digit IMEI 1.');
            if($newImei2!==''&&(!ffdev_imei_is_valid($newImei2)||$newImei2===$newImei1))throw new RuntimeException('IMEI 2 must be a different valid 15-digit IMEI.');
            $ids=$newImei2!==''?[$newImei1,$newImei2]:[$newImei1,$newImei1];
            $duplicate=get_one('SELECT id FROM inventory_items WHERE id<>? AND (imei1 IN (?,?) OR imei2 IN (?,?)) LIMIT 1',[$itemId,$ids[0],$ids[1],$ids[0],$ids[1]]);
            if($duplicate)throw new RuntimeException('This IMEI is already registered to another phone in FoneFlex.');
            db()->beginTransaction();
            db()->prepare('UPDATE inventory_items SET imei1=?,imei2=?,serial_no=? WHERE id=? AND organization_id=?')->execute([$newImei1,$newImei2?:null,$newSerial?:null,$itemId,$targetOrg]);
            db()->prepare('INSERT IGNORE INTO device_imei_registry(imei_hmac,organization_id,inventory_item_id,imei_slot) VALUES(?,?,?,"imei1")')->execute([ffdev_hmac($newImei1),$targetOrg,$itemId]);
            if($newImei2!=='')db()->prepare('INSERT IGNORE INTO device_imei_registry(imei_hmac,organization_id,inventory_item_id,imei_slot) VALUES(?,?,?,"imei2")')->execute([ffdev_hmac($newImei2),$targetOrg,$itemId]);
            ffdev_log_event($targetOrg,$itemId,'legacy_device_identity_corrected',['imei1_checksum_valid'=>true,'imei2_present'=>$newImei2!=='','serial_present'=>$newSerial!=='']);
            db()->commit();audit('device.identity_corrected','inventory_item',$itemId);flash('success','Device identity corrected and IMEI registry updated.');
            redirect('?r=device-protection&id='.$itemId.($platform?'&org='.$targetOrg:''));
        }
        if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_protection'){
            if(!$platform)require_role(['owner','admin']);
            ffdev_handle_protection_update($itemId,$targetOrg);
            redirect('?r=device-protection&id='.$itemId.($platform?'&org='.$targetOrg:''));
        }
        ffdev_ensure_record($targetOrg,$itemId,(int)user()['id']);
        $record=get_one('SELECT * FROM device_protection_records WHERE inventory_item_id=? AND organization_id=?',[$itemId,$targetOrg]);
        $agreement=get_one('SELECT a.*,c.full_name,c.phone FROM agreements a JOIN customers c ON c.id=a.customer_id WHERE a.inventory_item_id=? AND a.organization_id=? AND a.status NOT IN ("terminated","draft") ORDER BY a.id DESC LIMIT 1',[$itemId,$targetOrg]);
        $events=get_all('SELECT e.*,u.full_name actor_name FROM device_protection_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.inventory_item_id=? AND e.organization_id=? ORDER BY e.created_at DESC LIMIT 100',[$itemId,$targetOrg]);
        $canEdit=$platform||in_array(user()['role']??'', ['owner','admin'],true);
        layout_start('Device protection record','device-management');alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">DEVICE IDENTITY & OEM ENROLMENT</div><h1>'.h($item['manufacturer'].' '.$item['model']).'</h1><p class="muted">'.h($item['inventory_no']).' · '.h($item['organization_name']).'</p></div><a class="btn" href="?r=device-management">Back to device register</a></div>';
        echo '<div class="kpi-grid two"><div class="kpi-card"><span>IMEI 1</span><strong>'.h($item['imei1']).'</strong><small>Server-side inventory identifier</small></div><div class="kpi-card"><span>Serial number</span><strong>'.h($item['serial_no']??'—').'</strong><small>Secondary hardware identifier</small></div></div>';
        echo '<div class="panel"><h3>FoneFlex agreement binding</h3><div class="health-list"><div><span>Agreement</span><b>'.h($agreement['agreement_no']??'Not yet assigned').'</b></div><div><span>Customer</span><b>'.h($agreement['full_name']??'—').'</b></div><div><span>Plan status</span><b>'.h($agreement['status']??'—').'</b></div><div><span>Phone stock status</span><b>'.h($item['status']).'</b></div></div><div class="info-note">The device-to-plan link is stored in FoneFlex, not on the handset. A customer factory reset cannot remove the inventory record, payment history or agreement link from FoneFlex. The reset-resistant device controls below are separate and depend on the provider shown.</div></div>';
        if($canEdit && !$agreement && $item['status']==='in_stock'){
            echo '<div class="panel"><h3>Complete / correct device identity</h3><p class="muted">For phone records created before v0.5, verify the IMEI before assigning the handset to a hire-purchase plan.</p><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="update_identity"><div class="form-grid"><div><label>IMEI 1</label><input name="imei1" required maxlength="15" inputmode="numeric" pattern="[0-9]{15}" value="'.h($item['imei1']??'').'"></div><div><label>IMEI 2</label><input name="imei2" maxlength="15" inputmode="numeric" pattern="[0-9]{15}" value="'.h($item['imei2']??'').'"></div><div><label>Serial number</label><input name="serial_no" maxlength="100" value="'.h($item['serial_no']??'').'"></div></div><button class="btn">Save verified device identifiers</button></form></div>';
        }
        echo '<div class="panel"><h3>OEM / financing provider enrolment</h3>';
        if($canEdit){echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_protection"><div class="form-grid"><div><label>Provider / enrolment programme</label><select name="provider_key">';$providers=['unconfigured'=>'Not configured','samsung_knox_guard'=>'Samsung Knox Guard','android_zero_touch'=>'Android zero-touch (enterprise enrolment only)','android_setup_qr'=>'Android setup QR (enterprise enrolment only)','apple_ade'=>'Apple Automated Device Enrolment (organisation-owned only)','other_approved'=>'Other approved financing provider'];foreach($providers as $k=>$v)echo '<option value="'.h($k).'"'.($record['provider_key']===$k?' selected':'').'>'.h($v).'</option>';echo '</select></div><div><label>Enrolment method</label><select name="enrollment_method">';$methods=['manual'=>'Manually verified in provider portal','reseller_preload'=>'Reseller / OEM pre-registration','zero_touch'=>'Android zero-touch','setup_qr'=>'Android setup QR','apple_ade'=>'Apple Automated Device Enrolment'];foreach($methods as $k=>$v)echo '<option value="'.h($k).'"'.($record['enrollment_method']===$k?' selected':'').'>'.h($v).'</option>';echo '</select></div><div><label>Provider status</label><select name="protection_status">';$statuses=['not_assessed'=>'Not assessed','eligible'=>'Eligible — enrolment pending','unsupported'=>'Unsupported','enrolment_pending'=>'Enrolment pending','provider_confirmed'=>'Provider enrolment confirmed','failed'=>'Failed','released'=>'Released'];foreach($statuses as $k=>$v)echo '<option value="'.h($k).'"'.($record['protection_status']===$k?' selected':'').'>'.h($v).'</option>';echo '</select></div><div><label>Provider device reference</label><input name="provider_device_ref" maxlength="150" value="'.h($record['provider_device_ref']??'').'" placeholder="Reference shown in OEM portal"></div><div class="span-2"><label>Verification note / evidence reference</label><textarea name="provider_notes" rows="3" maxlength="1000" placeholder="Record who checked the OEM portal, date, provider reference and exact status.">'.h($record['provider_notes']??'').'</textarea></div></div><p class="hint">Only choose “Provider enrolment confirmed” after checking the actual OEM/financing-provider portal. This is a manual audit attestation in this release; there is no live provider API connection yet.</p><button class="btn primary">Save provider status</button></form>';}else{echo '<div class="health-list"><div><span>Provider</span><b>'.h(ffdev_provider_label($record['provider_key'])).'</b></div><div><span>Status</span><b>'.h(ffdev_status_label($record['protection_status'])).'</b></div><div><span>Provider reference</span><b>'.h($record['provider_device_ref']??'—').'</b></div><div><span>Last checked</span><b>'.h($record['provider_checked_at']??'—').'</b></div></div>' ;}
        $enterpriseOnly=in_array($record['provider_key'],['android_zero_touch','android_setup_qr','apple_ade'],true);
        if($record['protection_status']==='provider_confirmed'&&$enterpriseOnly)echo '<div class="alert warning">The device is recorded as enterprise-enrolled, but this is not an authorised consumer financing payment lock. Do not represent it as payment-default protection.</div>';
        elseif($record['protection_status']==='provider_confirmed'&&in_array($record['provider_key'],['samsung_knox_guard','other_approved'],true))echo '<div class="alert success">A staff member has recorded this device as enrolled with a financing-capable provider. FoneFlex has not independently verified the provider status by API. Test a controlled reset/re-enrolment with the provider before handing the phone to a customer.</div>';
        else echo '<div class="alert warning">This device is not currently confirmed by a financing-capable OEM/provider. Do not promise that its lock or enrolment will survive a factory reset.</div>';
        echo '</div><div class="panel"><div class="panel-head"><h3>Device identity and protection audit history</h3></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Event</th><th>Details</th><th>Actor</th></tr></thead><tbody>';foreach($events as $e)echo '<tr><td>'.date('d M Y H:i',strtotime($e['created_at'])).'</td><td>'.h(str_replace('_',' ',$e['event_type'])).'</td><td>'.h((string)$e['event_details']).'</td><td>'.h($e['actor_name']??'System').'</td></tr>';if(!$events)echo '<tr><td colspan="4" class="empty">No device events recorded.</td></tr>';echo '</tbody></table></div></div>';
        layout_end();return true;
    }
    return false;
}
