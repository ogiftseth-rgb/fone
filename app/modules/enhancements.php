<?php
declare(strict_types=1);

/** FoneFlex v0.3 additions: KYC, global platform registers and agreement completion. */

function ff_service_row(string $key): ?array
{
    return get_one('SELECT * FROM platform_services WHERE service_key=?', [$key]);
}

function ff_service_is_enabled(string $key): bool
{
    $row = ff_service_row($key);
    return $row ? (bool)$row['enabled'] : false;
}

function ff_data_key(): string
{
    global $config;
    $raw = (string)($config['security']['data_encryption_key'] ?? '');
    if (strlen($raw) < 32) {
        throw new RuntimeException('Customer ID encryption is not configured. Add security.data_encryption_key in config/config.php (at least 32 random characters) before saving KYC information.');
    }
    return hash('sha256', $raw, true);
}

function ff_encrypt_id_number(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ff_data_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Could not encrypt the customer ID number.');
    return "FF1" . $iv . $tag . $cipher;
}

function ff_private_kyc_root(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'kyc';
}

function ff_save_camera_image(string $dataUrl, int $organizationId, int $customerId, string $slot): ?string
{
    if ($dataUrl === '') return null;
    if (!in_array($slot, ['id_front','id_back','customer_photo'], true)) throw new InvalidArgumentException('Invalid KYC image type.');
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        throw new RuntimeException('Capture ' . str_replace('_',' ', $slot) . ' using the camera preview. Gallery uploads are not accepted for ID and customer photographs.');
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) < 100 || strlen($bytes) > 8 * 1024 * 1024) throw new RuntimeException('A captured image must be valid and no larger than 8 MB.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/webp'], true)) throw new RuntimeException('The captured file is not a supported image.');
    $extension = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']];
    $folder = ff_private_kyc_root() . DIRECTORY_SEPARATOR . $organizationId . DIRECTORY_SEPARATOR . $customerId;
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) throw new RuntimeException('Could not create secure KYC document storage.');
    $file = $slot . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $path = $folder . DIRECTORY_SEPARATOR . $file;
    if (file_put_contents($path, $bytes, LOCK_EX) === false) { @unlink($path); throw new RuntimeException('Could not save the captured image.'); }
    return 'storage/private/kyc/' . $organizationId . '/' . $customerId . '/' . $file;
}

function ff_save_mpesa_statement(array $upload, int $organizationId, int $customerId): ?string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('The M-Pesa statement could not be uploaded.');
    if (($upload['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('M-Pesa statements must not exceed 10 MB.');
    if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('The M-Pesa statement must be selected through the secure upload control.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']);
    $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
    if (!isset($allowed[$mime])) throw new RuntimeException('M-Pesa statement must be a PDF, JPEG or PNG file.');
    $folder = ff_private_kyc_root() . DIRECTORY_SEPARATOR . $organizationId . DIRECTORY_SEPARATOR . $customerId;
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) throw new RuntimeException('Could not create secure KYC document storage.');
    $file = 'mpesa-statement-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $path = $folder . DIRECTORY_SEPARATOR . $file;
    if (!move_uploaded_file($upload['tmp_name'], $path)) {
        if (!copy($upload['tmp_name'], $path)) { @unlink($path); throw new RuntimeException('Could not securely store the M-Pesa statement.'); }
    }
    return 'storage/private/kyc/' . $organizationId . '/' . $customerId . '/' . $file;
}

function ff_kyc_file_path(string $relative): string
{
    $base = realpath(ff_private_kyc_root());
    $candidate = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
    if (!$base || !$candidate || !str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        throw new RuntimeException('The requested private document was not found.');
    }
    return $candidate;
}

function ff_render_kyc_camera(): void
{
    echo '<div class="panel"><h3>Camera capture — required for identity photos</h3><p class="muted">Use the camera to take the ID front, ID back and customer photograph. The form does not accept gallery uploads for these three images. Camera access requires HTTPS or localhost.</p><div class="section-grid"><div><video id="ffCamera" autoplay playsinline muted style="width:100%;max-height:300px;background:#111;border-radius:12px"></video><canvas id="ffCanvas" hidden></canvas><div class="form-grid"><button type="button" class="btn" id="ffStartRear">Start ID camera</button><button type="button" class="btn" id="ffStartFront">Switch to selfie camera</button></div><p id="ffCameraStatus" class="hint">Camera not started.</p></div><div class="health-list"><div><span>ID card front</span><b id="ffFrontStatus">Not captured</b></div><div><span>ID card back</span><b id="ffBackStatus">Not captured</b></div><div><span>Customer photograph</span><b id="ffPhotoStatus">Not captured</b></div></div></div><div class="form-grid"><div><button type="button" class="btn primary full" data-capture="id_front">Capture ID front</button><input type="hidden" name="id_front_capture" id="id_front_capture"></div><div><button type="button" class="btn primary full" data-capture="id_back">Capture ID back</button><input type="hidden" name="id_back_capture" id="id_back_capture"></div><div class="span-2"><button type="button" class="btn primary full" data-capture="customer_photo">Capture customer photograph</button><input type="hidden" name="customer_photo_capture" id="customer_photo_capture"></div></div></div>';
    echo <<<'HTML'
<script>
(() => {
  const video = document.getElementById('ffCamera');
  const status = document.getElementById('ffCameraStatus');
  let stream = null;
  async function startCamera(mode) {
    try {
      if (stream) stream.getTracks().forEach(t => t.stop());
      stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:mode},width:{ideal:1600},height:{ideal:1200}},audio:false});
      video.srcObject = stream;
      status.textContent = mode === 'user' ? 'Selfie camera is ready.' : 'Rear camera is ready.';
    } catch (e) { status.textContent = 'Camera unavailable. Use HTTPS, allow camera permission and try again. ' + e.message; }
  }
  document.getElementById('ffStartRear').addEventListener('click', () => startCamera('environment'));
  document.getElementById('ffStartFront').addEventListener('click', () => startCamera('user'));
  document.querySelectorAll('[data-capture]').forEach(button => button.addEventListener('click', () => {
    if (!stream || !video.videoWidth) { status.textContent = 'Start the camera before capturing.'; return; }
    const canvas = document.getElementById('ffCanvas');
    const maxWidth = 1800, scale = Math.min(1, maxWidth / video.videoWidth);
    canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
    const key = button.dataset.capture;
    document.getElementById(key + '_capture').value = canvas.toDataURL('image/jpeg',0.82);
    const labels = {id_front:'ffFrontStatus',id_back:'ffBackStatus',customer_photo:'ffPhotoStatus'};
    document.getElementById(labels[key]).textContent = 'Captured — ready to save';
  }));
  window.addEventListener('beforeunload', () => { if (stream) stream.getTracks().forEach(t => t.stop()); });
})();
</script>
HTML;
}


function ff_render_customer_onboarding_camera(): void
{
    echo <<<'HTML'
<div class="panel">
  <h3>Identity documents and customer photograph</h3>
  <p class="muted">All three images must be captured directly from the camera. Gallery/file selection is not provided for identity images.</p>
  <div class="ff-camera-grid">
    <div>
      <video id="ffOnboardCamera" autoplay playsinline muted></video>
      <canvas id="ffOnboardCanvas" hidden></canvas>
      <div class="form-grid">
        <button type="button" class="btn" id="ffOnboardRear">Start rear camera</button>
        <button type="button" class="btn" id="ffOnboardFront">Switch to selfie camera</button>
      </div>
      <p id="ffOnboardCameraStatus" class="hint">Camera not started. Use HTTPS and allow camera access.</p>
    </div>
    <div class="ff-capture-list">
      <div class="ff-capture-card"><div><b>ID card — front</b><small id="ffOnboardFrontStatus">Not captured</small></div><img id="ffOnboardFrontPreview" alt="ID front preview" hidden><button type="button" class="btn primary" data-onboard-capture="id_front">Capture / retake front</button><input type="hidden" name="id_front_capture" id="id_front_capture"></div>
      <div class="ff-capture-card"><div><b>ID card — back</b><small id="ffOnboardBackStatus">Not captured</small></div><img id="ffOnboardBackPreview" alt="ID back preview" hidden><button type="button" class="btn primary" data-onboard-capture="id_back">Capture / retake back</button><input type="hidden" name="id_back_capture" id="id_back_capture"></div>
      <div class="ff-capture-card"><div><b>Customer photograph</b><small id="ffOnboardPhotoStatus">Not captured</small></div><img id="ffOnboardPhotoPreview" alt="Customer photograph preview" hidden><button type="button" class="btn primary" data-onboard-capture="customer_photo">Capture / retake photograph</button><input type="hidden" name="customer_photo_capture" id="customer_photo_capture"></div>
    </div>
  </div>
  <p class="hint">Images are resized in the browser before submission and saved to private storage outside the public web directory.</p>
</div>
<style>
.ff-steps{display:flex;gap:8px;margin:0 0 16px;flex-wrap:wrap}.ff-step-indicator{padding:9px 12px;border:1px solid #e8ebf1;border-radius:8px;background:#fff;color:#778196;font-size:10px;font-weight:700}.ff-step-indicator.active{background:#edf0fa;border-color:#b9c3e8;color:#243169}.ff-wizard-step[hidden]{display:none!important}.ff-wizard-actions{display:flex;justify-content:space-between;gap:10px;margin-top:16px}.ff-camera-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:16px}.ff-camera-grid video{width:100%;min-height:220px;max-height:330px;object-fit:cover;border-radius:10px;background:#111}.ff-capture-list{display:grid;gap:10px}.ff-capture-card{border:1px solid #e8ebf1;border-radius:9px;padding:11px;display:grid;grid-template-columns:1fr 90px;gap:8px;align-items:center}.ff-capture-card small{display:block;color:#8b94a5;font-size:9px;margin-top:3px}.ff-capture-card img{width:90px;height:66px;object-fit:cover;border-radius:6px;border:1px solid #e8ebf1}.ff-capture-card button{grid-column:1/-1}.ff-gps{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.ff-gps .hint{margin:0}.ff-required{color:#c4464b}.ff-consent-copy{border:1px solid #e8ebf1;border-radius:9px;background:#fafbfe;padding:13px;color:#667188;font-size:10px;line-height:1.65}.ff-step-help{margin:0 0 14px;color:#8791a3;font-size:10px}@media(max-width:760px){.ff-camera-grid{grid-template-columns:1fr}.ff-wizard-actions{position:sticky;bottom:0;background:#f5f7fb;padding:10px 0}.ff-step-indicator{flex:1;text-align:center}}
</style>
HTML;
    echo <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', () => {
  const video = document.getElementById('ffOnboardCamera');
  const canvas = document.getElementById('ffOnboardCanvas');
  const cameraStatus = document.getElementById('ffOnboardCameraStatus');
  const form = document.getElementById('ffCustomerOnboardingForm');
  let stream = null;
  const stepPanels = Array.from(document.querySelectorAll('[data-onboard-step]'));
  const indicators = Array.from(document.querySelectorAll('[data-onboard-indicator]'));
  let currentStep = 0;
  const captured = {id_front:false,id_back:false,customer_photo:false};
  const captureMeta = {
    id_front:{status:'ffOnboardFrontStatus',preview:'ffOnboardFrontPreview',input:'id_front_capture'},
    id_back:{status:'ffOnboardBackStatus',preview:'ffOnboardBackPreview',input:'id_back_capture'},
    customer_photo:{status:'ffOnboardPhotoStatus',preview:'ffOnboardPhotoPreview',input:'customer_photo_capture'}
  };
  function showStep(index) {
    currentStep = Math.max(0, Math.min(index, stepPanels.length - 1));
    stepPanels.forEach((panel,i) => panel.hidden = i !== currentStep);
    indicators.forEach((item,i) => item.classList.toggle('active', i === currentStep));
    document.getElementById('ffOnboardPrevious').disabled = currentStep === 0;
    document.getElementById('ffOnboardNext').hidden = currentStep === stepPanels.length - 1;
    document.getElementById('ffOnboardSubmit').hidden = currentStep !== stepPanels.length - 1;
    window.scrollTo({top:0,behavior:'smooth'});
  }
  function validateCurrentStep() {
    const panel = stepPanels[currentStep];
    const fields = Array.from(panel.querySelectorAll('input,select,textarea')).filter(el => el.required && !el.disabled && el.type !== 'hidden');
    for (const field of fields) {
      if (!field.checkValidity()) { field.reportValidity(); field.focus(); return false; }
    }
    if (currentStep === 1) {
      const missing = Object.entries(captured).find(([,ok]) => !ok);
      if (missing) { cameraStatus.textContent = 'Capture the ID front, ID back and customer photograph before proceeding.'; return false; }
    }
    return true;
  }
  document.getElementById('ffOnboardPrevious').addEventListener('click', () => showStep(currentStep - 1));
  document.getElementById('ffOnboardNext').addEventListener('click', () => { if (validateCurrentStep()) showStep(currentStep + 1); });
  indicators.forEach((indicator,i) => indicator.addEventListener('click', () => { if (i <= currentStep) showStep(i); }));
  async function startCamera(mode) {
    try {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('Camera API is unavailable. Open FoneFlex over HTTPS.');
      if (stream) stream.getTracks().forEach(track => track.stop());
      stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:mode},width:{ideal:1600},height:{ideal:1200}},audio:false});
      video.srcObject = stream;
      await video.play();
      cameraStatus.textContent = mode === 'user' ? 'Front-facing camera ready.' : 'Rear-facing camera ready.';
    } catch (error) { cameraStatus.textContent = 'Camera unavailable: ' + error.message + '. Check HTTPS and browser permissions.'; }
  }
  document.getElementById('ffOnboardRear').addEventListener('click', () => startCamera('environment'));
  document.getElementById('ffOnboardFront').addEventListener('click', () => startCamera('user'));
  document.querySelectorAll('[data-onboard-capture]').forEach(button => button.addEventListener('click', () => {
    if (!stream || !video.videoWidth) { cameraStatus.textContent = 'Start the camera before taking a picture.'; return; }
    const key = button.dataset.onboardCapture;
    const maxWidth = 1500;
    const scale = Math.min(1, maxWidth / video.videoWidth);
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
    const imageData = canvas.toDataURL('image/jpeg',0.76);
    const meta = captureMeta[key];
    document.getElementById(meta.input).value = imageData;
    const preview = document.getElementById(meta.preview);
    preview.src = imageData;
    preview.hidden = false;
    document.getElementById(meta.status).textContent = 'Captured — ready to submit';
    captured[key] = true;
  }));
  const statementToggle = document.getElementById('mpesa_statement_requested');
  const statementBox = document.getElementById('ffStatementDetails');
  function toggleStatement() { statementBox.hidden = !statementToggle.checked; document.getElementById('mpesa_statement_reason').required = statementToggle.checked; document.getElementById('mpesa_statement').required = statementToggle.checked; }
  statementToggle.addEventListener('change', toggleStatement); toggleStatement();
  document.getElementById('ffGetOnboardLocation').addEventListener('click', () => {
    const status = document.getElementById('ffOnboardGpsStatus');
    if (!navigator.geolocation) { status.textContent = 'Location is unavailable in this browser.'; return; }
    status.textContent = 'Requesting location permission…';
    navigator.geolocation.getCurrentPosition(position => {
      document.getElementById('onboard_gps_latitude').value = position.coords.latitude;
      document.getElementById('onboard_gps_longitude').value = position.coords.longitude;
      document.getElementById('onboard_gps_accuracy').value = position.coords.accuracy;
      status.textContent = 'GPS coordinates captured; accuracy about ' + Math.round(position.coords.accuracy) + ' metres.';
    }, error => { status.textContent = 'GPS not captured: ' + error.message + '. You can continue using the physical address.'; }, {enableHighAccuracy:true,timeout:15000,maximumAge:60000});
  });
  form.addEventListener('submit', event => {
    for (let i=0;i<stepPanels.length;i++) {
      showStep(i);
      const panel = stepPanels[i];
      const required = Array.from(panel.querySelectorAll('input,select,textarea')).filter(el => el.required && !el.disabled && el.type !== 'hidden');
      for (const field of required) if (!field.checkValidity()) { event.preventDefault(); field.reportValidity(); field.focus(); return; }
      if (i===1 && Object.values(captured).some(value => !value)) { event.preventDefault(); cameraStatus.textContent='Capture all three required images before saving.'; return; }
    }
    if (!document.getElementById('customer_consent').checked || !document.getElementById('kin_notice_confirmed').checked || !document.getElementById('privacy_notice_provided').checked) {
      event.preventDefault(); showStep(3); alert('Please complete all customer notice and consent confirmations.'); return;
    }
    if (!confirm('Submit this customer onboarding record for KYC review?')) event.preventDefault();
    if (stream) stream.getTracks().forEach(track => track.stop());
  });
  window.addEventListener('beforeunload', () => { if (stream) stream.getTracks().forEach(track => track.stop()); });
  showStep(0);
});
</script>
HTML;
}

function handle_enhancements_route(string $route): bool
{
    $u = require_login();
    $oid = org_id();


    if ($route === 'customer-onboarding') {
        require_role(['owner','admin','sales']);
        if ($oid <= 0) throw new RuntimeException('Customer onboarding is available inside a retailer workspace.');
        if (!ff_service_is_enabled('customer_kyc')) throw new RuntimeException('Customer KYC has been disabled by the FoneFlex platform administrator.');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_customer_onboarding') {
            $name = post_string('full_name',160);
            $phone = clean_phone(post_string('phone',30));
            $alternativePhone = clean_phone(post_string('alternative_phone',30));
            $email = strtolower(post_string('email',190));
            $dob = post_string('date_of_birth',10);
            $nationality = post_string('nationality',80);
            $gender = post_string('gender',30);
            $idType = post_string('id_type',30);
            $idNumber = preg_replace('/\s+/', '', trim((string)($_POST['id_number'] ?? '')));
            $county = post_string('county',100);
            $town = post_string('town',100);
            $estate = post_string('estate',120);
            $street = post_string('street',120);
            $landmark = post_string('landmark',180);
            $kinName = post_string('next_of_kin_name',160);
            $kinPhone = clean_phone(post_string('next_of_kin_phone',30));
            $kinRelationship = post_string('next_of_kin_relationship',60);
            $kinAddress = post_string('next_of_kin_address',255);
            $latitude = trim((string)($_POST['gps_latitude'] ?? ''));
            $longitude = trim((string)($_POST['gps_longitude'] ?? ''));
            $gpsAccuracy = trim((string)($_POST['gps_accuracy'] ?? ''));
            $statementRequested = isset($_POST['mpesa_statement_requested']) ? 1 : 0;
            $statementReason = post_string('mpesa_statement_reason',255);
            $statementFrom = post_string('statement_period_from',10);
            $statementTo = post_string('statement_period_to',10);
            $consentName = post_string('consent_customer_name',160);
            $consentMethod = post_string('consent_method',20);

            if (!$name) throw new RuntimeException('Enter the customer’s full legal name as shown on the identity document.');
            if (!preg_match('/^\+254[17][0-9]{8}$/', $phone)) throw new RuntimeException('Enter a valid Kenyan mobile number, for example 0712345678 or +254712345678.');
            if (get_one('SELECT id FROM customers WHERE organization_id=? AND phone=? LIMIT 1',[$oid,$phone])) throw new RuntimeException('This mobile number is already registered in this business. Open the existing customer profile instead of creating a duplicate.');
            if ($alternativePhone !== '' && !preg_match('/^\+254[17][0-9]{8}$/', $alternativePhone)) throw new RuntimeException('Enter a valid alternative Kenyan mobile number or leave it blank.');
            if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid customer email address or leave it blank.');
            $dobDate = DateTimeImmutable::createFromFormat('!Y-m-d',$dob);
            if (!$dobDate || $dobDate->format('Y-m-d') !== $dob || $dobDate > new DateTimeImmutable('today')) throw new RuntimeException('Enter a valid date of birth that is not in the future.');
            if (!$nationality) throw new RuntimeException('Select or enter the customer’s nationality.');
            if ($gender !== '' && !in_array($gender,['female','male','other','prefer_not_to_say'],true)) throw new RuntimeException('Select a valid gender option.');
            if (!in_array($idType,['national_id','passport','alien_id'],true) || strlen($idNumber) < 4 || strlen($idNumber) > 80) throw new RuntimeException('Select the identity document type and enter a valid document number.');
            if (!$county || !$town || (!$estate && !$street && !$landmark)) throw new RuntimeException('Enter the customer’s county, town and at least one of estate/village, street/road or nearest landmark.');
            if (!$kinName || !preg_match('/^\+254[17][0-9]{8}$/',$kinPhone) || !$kinRelationship) throw new RuntimeException('Enter the next-of-kin full name, a valid Kenyan mobile number and relationship.');
            if ($kinPhone === $phone) throw new RuntimeException('The next-of-kin mobile number must be different from the customer’s primary number.');
            foreach ([$latitude,$longitude,$gpsAccuracy] as $coordinate) if ($coordinate !== '' && !is_numeric($coordinate)) throw new RuntimeException('GPS coordinates and accuracy must be numeric.');
            if ($latitude !== '' && ((float)$latitude < -90 || (float)$latitude > 90)) throw new RuntimeException('GPS latitude is outside the valid range.');
            if ($longitude !== '' && ((float)$longitude < -180 || (float)$longitude > 180)) throw new RuntimeException('GPS longitude is outside the valid range.');
            if (($latitude === '') !== ($longitude === '')) throw new RuntimeException('Both GPS latitude and longitude must be captured together.');
            if ($gpsAccuracy !== '' && (float)$gpsAccuracy < 0) throw new RuntimeException('GPS accuracy cannot be negative.');
            if ($statementRequested) {
                if (!ff_service_is_enabled('mpesa_statement_capture')) throw new RuntimeException('M-Pesa statement capture is disabled by the FoneFlex platform administrator.');
                if (strlen($statementReason) < 5) throw new RuntimeException('Explain why the M-Pesa statement is necessary.');
                if (($_FILES['mpesa_statement']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('A statement has been requested. Attach the M-Pesa statement before submitting onboarding.');
            } elseif (($_FILES['mpesa_statement']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Tick “M-Pesa statement required” and provide a reason before attaching a statement.');
            }
            foreach ([$statementFrom,$statementTo] as $periodDate) {
                if ($periodDate !== '') { $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$periodDate); if(!$parsed || $parsed->format('Y-m-d')!==$periodDate) throw new RuntimeException('Enter valid statement period dates.'); }
            }
            if ($statementFrom !== '' && $statementTo !== '' && $statementFrom > $statementTo) throw new RuntimeException('The M-Pesa statement end date must not be before the start date.');
            if (!$consentName || !in_array($consentMethod,['written','verbal','digital','other'],true)) throw new RuntimeException('Record the customer’s name and the method by which consent was obtained.');
            if (($_POST['privacy_notice_provided'] ?? '') !== '1' || ($_POST['kin_notice_confirmed'] ?? '') !== '1' || ($_POST['customer_consent'] ?? '') !== '1') throw new RuntimeException('Confirm that the privacy notice was provided, next-of-kin collection was explained and customer consent was recorded.');

            $savedFiles = [];
            try {
                db()->beginTransaction();
                $customerNo = 'CUS-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $generalLocation = implode(', ',array_filter([$estate,$town,$county]));
                $encryptedId = ff_encrypt_id_number($idNumber);
                db()->prepare('INSERT INTO customers(organization_id,customer_no,full_name,phone,alternative_phone,email,nationality,gender,id_type,id_number_encrypted,general_location,privacy_notice_version,privacy_notice_provided_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,"v2",NOW(),?)')
                    ->execute([$oid,$customerNo,$name,$phone,$alternativePhone?:null,$email?:null,$nationality,$gender?:null,$idType,$encryptedId,$generalLocation,$u['id']]);
                $customerId = (int)db()->lastInsertId();

                $front = ff_save_camera_image((string)($_POST['id_front_capture'] ?? ''),$oid,$customerId,'id_front');
                if (!$front) throw new RuntimeException('Capture the front of the customer ID using the camera.');
                $savedFiles[] = $front;
                $back = ff_save_camera_image((string)($_POST['id_back_capture'] ?? ''),$oid,$customerId,'id_back');
                if (!$back) throw new RuntimeException('Capture the back of the customer ID using the camera.');
                $savedFiles[] = $back;
                $photo = ff_save_camera_image((string)($_POST['customer_photo_capture'] ?? ''),$oid,$customerId,'customer_photo');
                if (!$photo) throw new RuntimeException('Capture the customer photograph using the camera.');
                $savedFiles[] = $photo;
                $statementPath = null;
                if ($statementRequested) {
                    $statementPath = ff_save_mpesa_statement($_FILES['mpesa_statement'],$oid,$customerId);
                    if (!$statementPath) throw new RuntimeException('The requested M-Pesa statement could not be stored.');
                    $savedFiles[]=$statementPath;
                }
                $statementStatus = $statementRequested ? 'received' : 'not_required';
                $gpsCapturedAt = ($latitude !== '' && $longitude !== '') ? date('Y-m-d H:i:s') : null;
                db()->prepare('INSERT INTO customer_kyc(organization_id,customer_id,date_of_birth,id_front_path,id_back_path,customer_photo_path,mpesa_statement_path,mpesa_statement_requested,mpesa_statement_reason,statement_period_from,statement_period_to,county,town,estate,street,nearest_landmark,gps_latitude,gps_longitude,gps_accuracy_m,gps_captured_at,next_of_kin_name,next_of_kin_phone,next_of_kin_relationship,next_of_kin_address,kin_notice_confirmed,kyc_status,consent_version,consent_customer_name,consent_method,customer_consent_confirmed,consent_recorded_at,last_updated_by,mpesa_statement_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,"pending_review","v2",?,?,1,NOW(),?,?)')
                    ->execute([$oid,$customerId,$dob,$front,$back,$photo,$statementPath,$statementRequested,$statementReason?:null,$statementFrom?:null,$statementTo?:null,$county,$town,$estate?:null,$street?:null,$landmark?:null,$latitude!==''?(float)$latitude:null,$longitude!==''?(float)$longitude:null,$gpsAccuracy!==''?(float)$gpsAccuracy:null,$gpsCapturedAt,$kinName,$kinPhone,$kinRelationship,$kinAddress?:null,$consentName,$consentMethod,$u['id'],$statementStatus]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                foreach ($savedFiles as $relative) { try { $absolute=ff_kyc_file_path($relative); @unlink($absolute); } catch (Throwable $ignored) {} }
                throw $e;
            }
            audit('customer.onboarding_completed','customer',$customerId,['customer_no'=>$customerNo,'kyc_status'=>'pending_review','mpesa_statement_status'=>$statementStatus,'gps_captured'=>$gpsCapturedAt!==null]);
            flash('success','Customer onboarding completed. The customer profile and KYC documents have been saved and submitted for review.');
            redirect('?r=customer-kyc&id='.$customerId);
        }

        layout_start('New customer onboarding','customers'); alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">CUSTOMER ACQUISITION & DUE DILIGENCE</div><h1>New customer onboarding</h1><p class="muted">Complete the customer profile and required identity capture in one guided process. The system saves the customer and KYC record together only after all mandatory information is supplied.</p></div><a class="btn" href="?r=customers">← Customer register</a></div>';
        echo '<div class="info-note"><b>Before you begin:</b> Explain the privacy notice and why each item is collected. Obtain the customer’s consent or record the applicable lawful basis. Use the customer’s device camera for the ID card and photograph. GPS capture is optional and requires permission.</div>';
        echo '<div class="ff-steps"><div class="ff-step-indicator active" data-onboard-indicator>1. Biodata</div><div class="ff-step-indicator" data-onboard-indicator>2. Identity capture</div><div class="ff-step-indicator" data-onboard-indicator>3. Address & next of kin</div><div class="ff-step-indicator" data-onboard-indicator>4. Statements & consent</div></div>';
        echo '<form id="ffCustomerOnboardingForm" method="post" enctype="multipart/form-data" novalidate><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="create_customer_onboarding">';
        echo '<section class="ff-wizard-step" data-onboard-step><div class="panel"><h3>Customer biodata</h3><p class="ff-step-help">Enter the customer’s details exactly as supplied and check the spelling against the identity document.</p><div class="form-grid"><div class="span-2"><label>Full legal name <span class="ff-required">*</span></label><input required name="full_name" maxlength="160" autocomplete="name"></div><div><label>Primary mobile number <span class="ff-required">*</span></label><input required name="phone" type="tel" placeholder="0712345678 or +254712345678" autocomplete="tel"></div><div><label>Alternative mobile number</label><input name="alternative_phone" type="tel" placeholder="Optional"></div><div><label>Email address</label><input name="email" type="email" maxlength="190" autocomplete="email"></div><div><label>Date of birth <span class="ff-required">*</span></label><input required type="date" name="date_of_birth" max="'.date('Y-m-d').'" autocomplete="bday"></div><div><label>Nationality <span class="ff-required">*</span></label><input required name="nationality" maxlength="80" value="Kenyan" list="ffNationalities"><datalist id="ffNationalities"><option value="Kenyan"><option value="Ugandan"><option value="Tanzanian"><option value="Rwandan"><option value="Burundian"><option value="South Sudanese"><option value="Somali"><option value="Ethiopian"></datalist></div><div><label>Gender (optional)</label><select name="gender"><option value="">Prefer not to specify</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option><option value="prefer_not_to_say">Prefer not to say</option></select></div></div></div></section>';
        echo '<section class="ff-wizard-step" data-onboard-step hidden><div class="panel"><h3>Identity document details</h3><div class="form-grid"><div><label>Document type <span class="ff-required">*</span></label><select required name="id_type"><option value="national_id">Kenyan National ID</option><option value="passport">Passport</option><option value="alien_id">Alien ID</option></select></div><div><label>ID / passport number <span class="ff-required">*</span></label><input required name="id_number" maxlength="80" autocomplete="off"><small class="hint">The number is encrypted before database storage. It is not shown again in plain text.</small></div></div></div>';
        ff_render_customer_onboarding_camera();
        echo '</section>';
        echo '<section class="ff-wizard-step" data-onboard-step hidden><div class="panel"><h3>Physical address and location</h3><div class="form-grid"><div><label>County <span class="ff-required">*</span></label><input required name="county" maxlength="100" autocomplete="address-level1"></div><div><label>Town / city <span class="ff-required">*</span></label><input required name="town" maxlength="100" autocomplete="address-level2"></div><div><label>Estate / village</label><input name="estate" maxlength="120"></div><div><label>Street / road</label><input name="street" maxlength="120"></div><div class="span-2"><label>Nearest landmark / directions</label><input name="landmark" maxlength="180"></div></div><input type="hidden" name="gps_latitude" id="onboard_gps_latitude"><input type="hidden" name="gps_longitude" id="onboard_gps_longitude"><input type="hidden" name="gps_accuracy" id="onboard_gps_accuracy"><div class="ff-gps"><button type="button" class="btn" id="ffGetOnboardLocation">Capture GPS location</button><span class="hint" id="ffOnboardGpsStatus">Optional — no location captured.</span></div><p class="hint">GPS is collected only when the customer grants browser permission. It is not tracked continuously.</p></div>';
        echo '<div class="panel"><h3>Next of kin</h3><div class="form-grid"><div><label>Full name <span class="ff-required">*</span></label><input required name="next_of_kin_name" maxlength="160"></div><div><label>Mobile number <span class="ff-required">*</span></label><input required name="next_of_kin_phone" type="tel" placeholder="0712345678 or +254712345678"></div><div><label>Relationship <span class="ff-required">*</span></label><select required name="next_of_kin_relationship"><option value="">Select relationship</option><option>Parent</option><option>Spouse</option><option>Child</option><option>Sibling</option><option>Other relative</option><option>Friend</option><option>Other</option></select></div><div><label>Address (optional)</label><input name="next_of_kin_address" maxlength="255"></div></div><p class="hint">Only collect next-of-kin information after explaining why the details are requested. Record that the customer has been informed.</p></div></section>';
        echo '<section class="ff-wizard-step" data-onboard-step hidden><div class="panel"><h3>M-Pesa statement / affordability document</h3><label class="checkline"><input type="checkbox" id="mpesa_statement_requested" name="mpesa_statement_requested" value="1"> An M-Pesa statement is required for this customer’s affordability or verification assessment.</label><div id="ffStatementDetails" hidden><label>Reason the statement is necessary <span class="ff-required">*</span></label><input id="mpesa_statement_reason" name="mpesa_statement_reason" maxlength="255" placeholder="Explain the specific assessment purpose"><div class="form-grid"><div><label>Statement period from</label><input type="date" name="statement_period_from"></div><div><label>Statement period to</label><input type="date" name="statement_period_to"></div></div><label>Attach statement (PDF, JPEG or PNG; max 10 MB) <span class="ff-required">*</span></label><input id="mpesa_statement" type="file" name="mpesa_statement" accept="application/pdf,image/jpeg,image/png"><p class="hint">The statement is optional unless it is requested for this assessment. It is stored privately and is not made available through a public URL.</p></div><p class="hint">Do not request an M-Pesa statement automatically for every customer. Request only what is necessary for a documented assessment purpose.</p></div>';
        echo '<div class="panel"><h3>Privacy notice and customer consent</h3><div class="ff-consent-copy"><b>FoneFlex customer data notice</b><br>Explain to the customer that the retailer collects the information shown in this form to assess and administer the proposed phone hire-purchase arrangement; to verify identity; to contact the customer about the agreement and payments; and, only where requested and justified, to assess affordability using an M-Pesa statement. Explain who will access the information, how it will be protected, how long it will be retained under the applicable retention policy, and how the customer may exercise applicable data rights. Do not promise that data will never be shared; explain any lawful service-provider or legal disclosures.</div><div class="form-grid"><div><label>Name of customer giving consent <span class="ff-required">*</span></label><input required name="consent_customer_name" maxlength="160"></div><div><label>Consent / acknowledgement method <span class="ff-required">*</span></label><select required name="consent_method"><option value="">Select method</option><option value="written">Written (signed notice/form retained by retailer)</option><option value="digital">Digital acknowledgement</option><option value="verbal">Verbal (recorded by staff)</option><option value="other">Other documented lawful basis</option></select></div></div><label class="checkline"><input type="checkbox" id="privacy_notice_provided" name="privacy_notice_provided" value="1" required> I confirm that I have provided and explained the privacy notice to the customer.</label><label class="checkline"><input type="checkbox" id="kin_notice_confirmed" name="kin_notice_confirmed" value="1" required> I confirm that the customer has been informed about collection and intended use of the next-of-kin details.</label><label class="checkline"><input type="checkbox" id="customer_consent" name="customer_consent" value="1" required> I confirm that the customer named above has acknowledged the notice and provided the recorded consent or other documented lawful basis for this collection.</label><p class="hint">This is a staff attestation. It does not create an electronic signature. Retain any written consent evidence in accordance with your approved process.</p></div></section>';
        echo '<div class="ff-wizard-actions"><button type="button" class="btn" id="ffOnboardPrevious">← Previous</button><button type="button" class="btn primary" id="ffOnboardNext">Continue →</button><button type="submit" class="btn primary" id="ffOnboardSubmit" hidden>Submit customer onboarding</button></div></form>';
        layout_end(); return true;
    }

    if ($route === 'kyc') {
        require_role(['owner','admin','sales','collections','accountant','auditor']);
        if (!ff_service_is_enabled('customer_kyc')) throw new RuntimeException('Customer KYC has been disabled by the FoneFlex platform administrator.');
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = 'SELECT c.id,c.customer_no,c.full_name,c.phone,k.kyc_status,k.updated_at FROM customers c LEFT JOIN customer_kyc k ON k.customer_id=c.id AND k.organization_id=c.organization_id WHERE c.organization_id=?';
        $params = [$oid];
        if ($q !== '') { $sql .= ' AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.customer_no LIKE ?)'; $like='%'.$q.'%'; array_push($params,$like,$like,$like); }
        $rows = get_all($sql . ' ORDER BY c.created_at DESC LIMIT 250', $params);
        layout_start('Customer KYC','kyc'); alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">CUSTOMER DUE DILIGENCE</div><h1>Customer KYC</h1><p class="muted">Capture identity documents by camera, customer address, location, next of kin and M-Pesa statements when required.</p></div></div><div class="panel"><div class="panel-head"><h3>Customer KYC register</h3><form method="get"><input type="hidden" name="r" value="kyc"><input class="search-input" name="q" value="'.h($q).'" placeholder="Search customer…"></form></div><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Phone</th><th>KYC status</th><th>Last updated</th><th></th></tr></thead><tbody>';
        foreach($rows as $r) echo '<tr><td><b>'.h($r['full_name']).'</b><small>'.h($r['customer_no']).'</small></td><td>'.h($r['phone']).'</td><td><span class="pill '.(($r['kyc_status']??'')==='verified'?'success':(($r['kyc_status']??'')==='rejected'?'danger':'warning')).'">'.h($r['kyc_status']??'not started').'</span></td><td>'.(!empty($r['updated_at'])?date('d M Y H:i',strtotime($r['updated_at'])):'—').'</td><td><a class="tiny-btn" href="?r=customer-kyc&id='.(int)$r['id'].'">Open KYC</a></td></tr>';
        if(!$rows) echo '<tr><td colspan="5" class="empty">No customers found.</td></tr>';
        echo '</tbody></table></div></div>'; layout_end(); return true;
    }

    if ($route === 'customer-kyc') {
        require_role(['owner','admin','sales']);
        if ($u['role'] === 'platform_admin' || $oid <= 0) throw new RuntimeException('KYC documents are accessed from the retailer workspace, not the global platform dashboard.');
        if (!ff_service_is_enabled('customer_kyc')) throw new RuntimeException('Customer KYC has been disabled by the FoneFlex platform administrator.');
        $cid=(int)($_GET['id']??0);
        $customer=get_one('SELECT * FROM customers WHERE id=? AND organization_id=?',[$cid,$oid]);
        if(!$customer) throw new RuntimeException('Customer not found in this business workspace.');
        $k=get_one('SELECT * FROM customer_kyc WHERE customer_id=? AND organization_id=?',[$cid,$oid]) ?? [];
        if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_kyc') {
            $idType=post_string('id_type',30);$idNumber=trim((string)($_POST['id_number']??''));
            $county=post_string('county',100);$town=post_string('town',100);$estate=post_string('estate',120);$street=post_string('street',120);$landmark=post_string('landmark',180);
            $kinName=post_string('next_of_kin_name',160);$kinPhone=clean_phone(post_string('next_of_kin_phone',30));$kinRel=post_string('next_of_kin_relationship',60);$kinAddress=post_string('next_of_kin_address',255);
            $lat=trim((string)($_POST['gps_latitude']??''));$lng=trim((string)($_POST['gps_longitude']??''));$accuracy=trim((string)($_POST['gps_accuracy']??''));
            $dob=post_string('date_of_birth',10);$requestStatement=isset($_POST['mpesa_statement_requested'])?1:0;$reason=post_string('mpesa_statement_reason',255);$from=post_string('statement_period_from',10);$to=post_string('statement_period_to',10);$consentName=post_string('consent_customer_name',160);$consentMethod=post_string('consent_method',20);
            if(!in_array($idType,['national_id','passport','alien_id'],true)||(!$idNumber && empty($customer['id_number_encrypted']))) throw new RuntimeException('Select the ID type and enter the ID/passport number.');
            if(!$kinName||strlen($kinPhone)<10||!$kinRel) throw new RuntimeException('Next of kin name, valid phone number and relationship are required.');
            if(!$county||!$town||(!$estate&&!$street&&!$landmark)) throw new RuntimeException('Enter at least the county, town and one physical address detail (estate, street or landmark).');
            if(($_POST['kin_notice_confirmed']??'')!=='1'||($_POST['customer_consent']??'')!=='1') throw new RuntimeException('Record that the customer received the privacy notice and was informed about next-of-kin details.');
            if(!$consentName||!in_array($consentMethod,['written','verbal','digital','other'],true)) throw new RuntimeException('Record the customer’s name and method of consent or documented lawful basis.');
            if(!empty($_POST['mpesa_statement_requested']) && (($_FILES['mpesa_statement']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) && empty($k['mpesa_statement_path'])) throw new RuntimeException('An M-Pesa statement has been requested. Attach it before submitting KYC for review.');
            if($dob!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dob)) throw new RuntimeException('Enter a valid date of birth.');
            foreach([$lat,$lng,$accuracy] as $v) if($v!==''&&!is_numeric($v)) throw new RuntimeException('GPS coordinates and accuracy must be numeric.');
            if($lat!==''&&((float)$lat < -90 || (float)$lat > 90)) throw new RuntimeException('Latitude is outside the valid range.');
            if($lng!==''&&((float)$lng < -180 || (float)$lng > 180)) throw new RuntimeException('Longitude is outside the valid range.');
            if($requestStatement && strlen($reason)<5) throw new RuntimeException('Explain why an M-Pesa statement is required.');
            if(($requestStatement || (($_FILES['mpesa_statement']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)) && !ff_service_is_enabled('mpesa_statement_capture')) throw new RuntimeException('M-Pesa statement capture is currently disabled by the FoneFlex platform administrator.');
            foreach([$from,$to] as $periodDate) if($periodDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$periodDate)) throw new RuntimeException('Enter valid M-Pesa statement period dates.');
            if($from!==''&&$to!==''&&$from>$to) throw new RuntimeException('The M-Pesa statement period end date must not be before the start date.');
            if($requestStatement && !$k && (($_FILES['mpesa_statement']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)) { /* statement may be supplied later */ }
            $front=ff_save_camera_image((string)($_POST['id_front_capture']??''),$oid,$cid,'id_front') ?? ($k['id_front_path']??null);
            $back=ff_save_camera_image((string)($_POST['id_back_capture']??''),$oid,$cid,'id_back') ?? ($k['id_back_path']??null);
            $photo=ff_save_camera_image((string)($_POST['customer_photo_capture']??''),$oid,$cid,'customer_photo') ?? ($k['customer_photo_path']??null);
            if(!$front||!$back||!$photo) throw new RuntimeException('Capture and save the ID front, ID back and customer photograph.');
            $statement=ff_save_mpesa_statement($_FILES['mpesa_statement']??[],$oid,$cid) ?? ($k['mpesa_statement_path']??null);
            $encrypted=$idNumber!==''?ff_encrypt_id_number($idNumber):($customer['id_number_encrypted']??null);
            db()->beginTransaction();
            db()->prepare('UPDATE customers SET id_type=?,id_number_encrypted=? WHERE id=? AND organization_id=?')->execute([$idType,$encrypted,$cid,$oid]);
            $existing=$k ? true : false;
            $sql='INSERT INTO customer_kyc(organization_id,customer_id,date_of_birth,id_front_path,id_back_path,customer_photo_path,mpesa_statement_path,mpesa_statement_requested,mpesa_statement_reason,statement_period_from,statement_period_to,county,town,estate,street,nearest_landmark,gps_latitude,gps_longitude,gps_accuracy_m,gps_captured_at,next_of_kin_name,next_of_kin_phone,next_of_kin_relationship,next_of_kin_address,kin_notice_confirmed,kyc_status,consent_version,consent_recorded_at,last_updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"pending_review","v2",NOW(),?) ON DUPLICATE KEY UPDATE date_of_birth=VALUES(date_of_birth),id_front_path=VALUES(id_front_path),id_back_path=VALUES(id_back_path),customer_photo_path=VALUES(customer_photo_path),mpesa_statement_path=VALUES(mpesa_statement_path),mpesa_statement_requested=VALUES(mpesa_statement_requested),mpesa_statement_reason=VALUES(mpesa_statement_reason),statement_period_from=VALUES(statement_period_from),statement_period_to=VALUES(statement_period_to),county=VALUES(county),town=VALUES(town),estate=VALUES(estate),street=VALUES(street),nearest_landmark=VALUES(nearest_landmark),gps_latitude=VALUES(gps_latitude),gps_longitude=VALUES(gps_longitude),gps_accuracy_m=VALUES(gps_accuracy_m),gps_captured_at=VALUES(gps_captured_at),next_of_kin_name=VALUES(next_of_kin_name),next_of_kin_phone=VALUES(next_of_kin_phone),next_of_kin_relationship=VALUES(next_of_kin_relationship),next_of_kin_address=VALUES(next_of_kin_address),kin_notice_confirmed=VALUES(kin_notice_confirmed),kyc_status="pending_review",consent_version="v1",consent_recorded_at=NOW(),last_updated_by=VALUES(last_updated_by)';
            $gpsCaptured=($lat!==''&&$lng!=='')?date('Y-m-d H:i:s'):null;
            db()->prepare($sql)->execute([$oid,$cid,$dob?:null,$front,$back,$photo,$statement,$requestStatement,$reason?:null,$from?:null,$to?:null,$county?:null,$town?:null,$estate?:null,$street?:null,$landmark?:null,$lat!==''?(float)$lat:null,$lng!==''?(float)$lng:null,$accuracy!==''?(float)$accuracy:null,$gpsCaptured,$kinName,$kinPhone,$kinRel,$kinAddress?:null,1,$u['id']]);
            $statementStatus = $requestStatement ? ($statement ? 'received' : 'requested') : 'not_required';
            db()->prepare('UPDATE customer_kyc SET consent_customer_name=?,consent_method=?,customer_consent_confirmed=1,mpesa_statement_status=? WHERE customer_id=? AND organization_id=?')->execute([$consentName,$consentMethod,$statementStatus,$cid,$oid]);
            db()->commit(); audit('customer.kyc_saved','customer',$cid,['mpesa_statement_requested'=>$requestStatement,'mpesa_statement_status'=>$statementStatus,'gps_captured'=>($lat!==''&&$lng!=='')]);
            flash('success','KYC information saved and submitted for review.'); redirect('?r=customer-kyc&id='.$cid);
        }
        if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='review_mpesa_statement') {
            require_role(['owner','admin']);
            if(!$k || empty($k['mpesa_statement_path']) || (int)$k['mpesa_statement_requested']!==1) throw new RuntimeException('No requested M-Pesa statement is available for review.');
            $statementDecision=post_string('statement_decision',20);$statementNote=post_string('statement_review_note',1000);
            if(!in_array($statementDecision,['reviewed','rejected'],true)) throw new RuntimeException('Select a valid M-Pesa statement review outcome.');
            if($statementDecision==='rejected' && strlen($statementNote)<5) throw new RuntimeException('Explain why the M-Pesa statement was rejected.');
            db()->prepare('UPDATE customer_kyc SET mpesa_statement_status=?,mpesa_statement_review_note=?,mpesa_statement_reviewed_by=?,mpesa_statement_reviewed_at=NOW() WHERE customer_id=? AND organization_id=?')->execute([$statementDecision,$statementNote?:null,$u['id'],$cid,$oid]);
            audit('customer.mpesa_statement_'.$statementDecision,'customer',$cid,['review_note'=>$statementNote]);flash('success','M-Pesa statement marked '.$statementDecision.'.');redirect('?r=customer-kyc&id='.$cid);
        }
        if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'', ['verify_kyc','reject_kyc'],true)) {
            require_role(['owner','admin']);
            if(!$k) throw new RuntimeException('Save the KYC record before reviewing it.');
            $decision=$_POST['action']==='verify_kyc'?'verified':'rejected';$note=post_string('review_note',1000);
            if($decision==='rejected'&&strlen($note)<5) throw new RuntimeException('A reason is required when rejecting KYC.');
            if($decision==='verified' && (int)($k['mpesa_statement_requested']??0)===1 && ($k['mpesa_statement_status']??'')!=='reviewed') throw new RuntimeException('Review the requested M-Pesa statement and mark it reviewed before approving this customer KYC.');
            db()->prepare('UPDATE customer_kyc SET kyc_status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE customer_id=? AND organization_id=?')->execute([$decision,$note?:null,$u['id'],$cid,$oid]);
            audit('customer.kyc_'.$decision,'customer',$cid,['review_note'=>$note]);flash('success','KYC marked '.$decision.'.');redirect('?r=customer-kyc&id='.$cid);
        }
        $k=get_one('SELECT * FROM customer_kyc WHERE customer_id=? AND organization_id=?',[$cid,$oid]) ?? [];
        layout_start('Customer KYC','kyc');alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">CUSTOMER KYC PROFILE</div><h1>'.h($customer['full_name']).'</h1><p class="muted">'.h($customer['customer_no']).' · '.h($customer['phone']).' · '.h($k['kyc_status']??'Not started').'</p></div><a class="btn" href="?r=kyc">Back to KYC register</a></div>';
        echo '<div class="panel"><h3>Customer biodata and contact details</h3><div class="health-list"><div><span>Full legal name</span><b>'.h($customer['full_name']).'</b></div><div><span>Primary phone</span><b>'.h($customer['phone']).'</b></div><div><span>Alternative phone</span><b>'.h($customer['alternative_phone']??'—').'</b></div><div><span>Email address</span><b>'.h($customer['email']??'—').'</b></div><div><span>Nationality</span><b>'.h($customer['nationality']??'—').'</b></div><div><span>Gender</span><b>'.h($customer['gender']??'—').'</b></div><div><span>Identity document type</span><b>'.h($customer['id_type']??'—').'</b></div><div><span>Privacy notice version</span><b>'.h($customer['privacy_notice_version']??'—').'</b></div></div></div>';
        echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_kyc"><div class="panel"><h3>Personal details</h3><div class="form-grid"><div><label>Date of birth</label><input type="date" name="date_of_birth" value="'.h($k['date_of_birth']??'').'"></div><div><label>ID document type</label><select required name="id_type"><option value="national_id" '.(($customer['id_type']??'')==='national_id'?'selected':'').'>Kenyan National ID</option><option value="passport" '.(($customer['id_type']??'')==='passport'?'selected':'').'>Passport</option><option value="alien_id" '.(($customer['id_type']??'')==='alien_id'?'selected':'').'>Alien ID</option></select></div><div><label>ID / passport number</label><input name="id_number" maxlength="80" autocomplete="off" placeholder="Enter to set or replace encrypted ID number"><small>Encrypted at rest; existing value is not displayed.</small></div></div></div>';
        ff_render_kyc_camera();
        echo '<div class="panel"><h3>Customer location and physical address</h3><div class="form-grid"><div><label>County</label><input name="county" value="'.h($k['county']??'').'"></div><div><label>Town</label><input name="town" value="'.h($k['town']??'').'"></div><div><label>Estate / village</label><input name="estate" value="'.h($k['estate']??'').'"></div><div><label>Street / road</label><input name="street" value="'.h($k['street']??'').'"></div><div class="span-2"><label>Nearest landmark / directions</label><input name="landmark" value="'.h($k['nearest_landmark']??'').'"></div></div><input type="hidden" name="gps_latitude" id="gps_latitude" value="'.h((string)($k['gps_latitude']??'')).'"><input type="hidden" name="gps_longitude" id="gps_longitude" value="'.h((string)($k['gps_longitude']??'')).'"><input type="hidden" name="gps_accuracy" id="gps_accuracy" value="'.h((string)($k['gps_accuracy_m']??'')).'"><button type="button" class="btn" id="ffGetLocation">Capture current GPS location</button> <span id="ffGpsStatus" class="hint">'.(!empty($k['gps_latitude'])?'Location previously captured.':'Location not captured.').'</span></div>';
        echo '<div class="panel"><h3>Next of kin</h3><div class="form-grid"><div><label>Full name</label><input required name="next_of_kin_name" value="'.h($k['next_of_kin_name']??'').'"></div><div><label>Phone number</label><input required name="next_of_kin_phone" value="'.h($k['next_of_kin_phone']??'').'"></div><div><label>Relationship</label><select required name="next_of_kin_relationship"><option value="">Select relationship</option>';foreach(['Parent','Spouse','Child','Sibling','Other relative','Friend','Other'] as $rel)echo '<option '.(($k['next_of_kin_relationship']??'')===$rel?'selected':'').'>'.h($rel).'</option>';echo '</select></div><div><label>Address (optional)</label><input name="next_of_kin_address" value="'.h($k['next_of_kin_address']??'').'"></div></div><label class="checkline"><input type="checkbox" name="kin_notice_confirmed" value="1" required '.(!empty($k['kin_notice_confirmed'])?'checked':'').'> Customer confirms they have been informed that next-of-kin contact details are being collected.</label></div>';
        echo '<div class="panel"><h3>M-Pesa statement — when required</h3><label class="checkline"><input type="checkbox" name="mpesa_statement_requested" value="1" '.(!empty($k['mpesa_statement_requested'])?'checked':'').'> Request an M-Pesa statement for affordability / verification assessment</label><label>Reason for request</label><input name="mpesa_statement_reason" value="'.h($k['mpesa_statement_reason']??'').'"><div class="form-grid"><div><label>Statement period from</label><input type="date" name="statement_period_from" value="'.h($k['statement_period_from']??'').'"></div><div><label>Statement period to</label><input type="date" name="statement_period_to" value="'.h($k['statement_period_to']??'').'"></div></div><label>Statement file (PDF/JPEG/PNG; maximum 10 MB)</label><input type="file" name="mpesa_statement" accept="application/pdf,image/jpeg,image/png"><small>'.(!empty($k['mpesa_statement_path'])?'<a href="?r=kyc-file&id='.$cid.'&doc=mpesa_statement">View securely stored statement</a>':'No statement stored').'</small></div>';
        echo '<div class="panel"><h3>Privacy notice and consent record</h3><div class="form-grid"><div><label>Name of customer giving consent</label><input required name="consent_customer_name" maxlength="160" value="'.h($k['consent_customer_name']??$customer['full_name']).'"></div><div><label>Consent / acknowledgement method</label><select required name="consent_method"><option value="">Select method</option><option value="written" '.(($k['consent_method']??'')==='written'?'selected':'').'>Written</option><option value="digital" '.(($k['consent_method']??'')==='digital'?'selected':'').'>Digital acknowledgement</option><option value="verbal" '.(($k['consent_method']??'')==='verbal'?'selected':'').'>Verbal</option><option value="other" '.(($k['consent_method']??'')==='other'?'selected':'').'>Other documented lawful basis</option></select></div></div><label class="checkline"><input type="checkbox" name="customer_consent" value="1" required> I confirm that the customer was informed about the purpose of collection, use, storage and sharing of this information and has provided the required consent or other documented lawful basis.</label><button class="btn primary">Save KYC and submit for review</button>';
        if(!empty($k['kyc_status'])) echo '<p class="hint">Current status: <b>'.h($k['kyc_status']).'</b> '.(!empty($k['review_note'])?'· '.h($k['review_note']):'').'</p>';
        echo '</div></form>';
        if($k && in_array($u['role'],['owner','admin'],true)) { echo '<div class="panel"><h3>Securely stored KYC documents</h3><div class="health-list"><div><span>ID card front</span><b><a href="?r=kyc-file&id='.$cid.'&doc=id_front">View document</a></b></div><div><span>ID card back</span><b><a href="?r=kyc-file&id='.$cid.'&doc=id_back">View document</a></b></div><div><span>Customer photograph</span><b><a href="?r=kyc-file&id='.$cid.'&doc=customer_photo">View photograph</a></b></div></div></div>'; }
        if($k && !empty($k['mpesa_statement_path']) && in_array($u['role'],['owner','admin'],true)) {
            echo '<div class="panel"><h3>Financial statement</h3><p class="muted">Review the statement only for the affordability or verification purpose recorded at collection.</p><p><a class="tiny-btn" href="?r=kyc-file&id='.$cid.'&doc=mpesa_statement">View M-Pesa statement securely</a> <span class="pill '.(($k['mpesa_statement_status']??'')==='reviewed'?'success':(($k['mpesa_statement_status']??'')==='rejected'?'danger':'warning')).'">'.h(str_replace('_',' ',$k['mpesa_statement_status']??'received')).'</span></p>';
            if(in_array($u['role'],['owner','admin'],true) && ($k['mpesa_statement_status']??'')!=='reviewed') echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="review_mpesa_statement"><label>Statement review note</label><input name="statement_review_note" value="'.h($k['mpesa_statement_review_note']??'').'" placeholder="Reason / assessment note"><button class="btn primary" name="statement_decision" value="reviewed">Mark statement reviewed</button> <button class="btn danger-btn" name="statement_decision" value="rejected">Reject statement</button></form>';
            if(!empty($k['mpesa_statement_review_note'])) echo '<p class="hint">'.h($k['mpesa_statement_review_note']).'</p>';
            echo '</div>';
        }
        if($k && in_array($u['role'],['owner','admin'],true)) echo '<div class="panel"><h3>KYC review</h3><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Review note</label><input name="review_note" value="'.h($k['review_note']??'').'"><button class="btn primary" name="action" value="verify_kyc">Verify KYC</button> <button class="btn danger-btn" name="action" value="reject_kyc">Reject KYC</button></form><p class="hint">Reviewers should view documents before approving. Every document view and decision is recorded in the audit log.</p></div>';
        echo '<script>document.getElementById("ffGetLocation").addEventListener("click",()=>{const s=document.getElementById("ffGpsStatus");if(!navigator.geolocation){s.textContent="Geolocation is unavailable in this browser.";return;}s.textContent="Requesting location permission…";navigator.geolocation.getCurrentPosition(p=>{document.getElementById("gps_latitude").value=p.coords.latitude;document.getElementById("gps_longitude").value=p.coords.longitude;document.getElementById("gps_accuracy").value=p.coords.accuracy;s.textContent="GPS captured (accuracy about "+Math.round(p.coords.accuracy)+" metres).";},e=>s.textContent="Could not capture GPS: "+e.message,{enableHighAccuracy:true,timeout:15000,maximumAge:60000});});</script>';
        layout_end(); return true;
    }

    if ($route === 'kyc-file') {
        require_role(['owner','admin']);
        if ($u['role']==='platform_admin' || $oid<=0) { http_response_code(403); exit('Access denied.'); }
        $cid=(int)($_GET['id']??0);$doc=substr((string)($_GET['doc']??''),0,40);
        $k=get_one('SELECT * FROM customer_kyc WHERE customer_id=? AND organization_id=?',[$cid,$oid]);
        $columns=['id_front'=>'id_front_path','id_back'=>'id_back_path','customer_photo'=>'customer_photo_path','mpesa_statement'=>'mpesa_statement_path'];
        if(!$k||!isset($columns[$doc])||empty($k[$columns[$doc]])) throw new RuntimeException('KYC document not found.');
        $path=ff_kyc_file_path($k[$columns[$doc]]);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        audit('customer.kyc_document_viewed','customer',$cid,['document_type'=>$doc]);
        header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="'.basename($path).'"');header('Cache-Control: no-store, private, max-age=0');header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }

    if ($route === 'plan-closures') {
        require_role(['owner']);
        $platform=is_platform_admin();
        $sql='SELECT a.id,a.organization_id,a.agreement_no,a.hp_price,a.status,a.created_at,o.name organization_name,c.full_name,c.phone,i.manufacturer,i.model,(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.agreement_id=a.id AND p.payment_status="verified") paid,(SELECT COUNT(*) FROM payments p WHERE p.agreement_id=a.id AND p.payment_status="pending") pending_count,(SELECT COUNT(*) FROM customer_disputes d WHERE d.agreement_id=a.id AND d.status IN ("open","reviewing")) dispute_count FROM agreements a JOIN organizations o ON o.id=a.organization_id JOIN customers c ON c.id=a.customer_id JOIN inventory_items i ON i.id=a.inventory_item_id';
        $params=[];if(!$platform){$sql.=' WHERE a.organization_id=?';$params[]=$oid;}
        $rows=get_all($sql.' ORDER BY a.created_at DESC LIMIT 500',$params);
        layout_start('Plan completion & device release','plan-closures');alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">HIRE-PURCHASE COMPLETION</div><h1>Plan completion & device release</h1><p class="muted">Close fully paid agreements and record a remote device-release request. Release commands require an approved, connected financing-device provider.</p></div></div><div class="info-note"><b>Important:</b> FoneFlex can verify settlement and securely record release requests. The current package has no device-provider API credentials or OEM integration, so it cannot yet send an actual remote unlock command. The release status will clearly show this limitation until an authorised provider is connected.</div><div class="panel"><div class="table-wrap"><table><thead><tr><th>Agreement / retailer</th><th>Customer</th><th>Device</th><th>HP price</th><th>Verified paid</th><th>Balance</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach($rows as $r){$balance=max(0,round((float)$r['hp_price']-(float)$r['paid'],2));$eligible=$balance<=0.005&&(int)$r['pending_count']===0&&(int)$r['dispute_count']===0&&!in_array($r['status'],['terminated','draft'],true);$latest=get_one('SELECT action_status FROM device_control_actions WHERE agreement_id=? ORDER BY id DESC LIMIT 1',[$r['id']]);echo '<tr><td><b>'.h($r['agreement_no']).'</b><small>'.h($r['organization_name']).'</small></td><td>'.h($r['full_name']).'<small>'.h($r['phone']).'</small></td><td>'.h($r['manufacturer'].' '.$r['model']).'</td><td>'.money($r['hp_price']).'</td><td>'.money($r['paid']).'</td><td>'.money($balance).'</td><td>'.h($r['status']).($r['pending_count']?' · pending payment':'').($r['dispute_count']?' · open dispute':'').'</td><td>'.($eligible?'<a class="tiny-btn" href="?r=agreement-release&id='.(int)$r['id'].'&org='.(int)$r['organization_id'].'">Complete & release</a>':'<span class="pill neutral">Not eligible</span>').(!empty($latest)?'<small>'.h(str_replace('_',' ',$latest['action_status'])).'</small>':'').'</td></tr>';}
        if(!$rows) echo '<tr><td colspan="8" class="empty">No hire-purchase agreements found.</td></tr>';
        echo '</tbody></table></div></div>';layout_end();return true;
    }

    if ($route === 'agreement-release') {
        require_role(['owner']);
        $platform=is_platform_admin();$aid=(int)($_GET['id']??0);$targetOrg=$platform?(int)($_GET['org']??0):$oid;
        $a=get_one('SELECT a.*,o.name organization_name,c.full_name,c.phone,i.manufacturer,i.model,i.imei1 FROM agreements a JOIN organizations o ON o.id=a.organization_id JOIN customers c ON c.id=a.customer_id JOIN inventory_items i ON i.id=a.inventory_item_id WHERE a.id=? AND a.organization_id=?',[$aid,$targetOrg]);
        if(!$a) throw new RuntimeException('Agreement was not found in the selected business.');
        if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='confirm_release'){
            db()->beginTransaction();
            $locked=get_one('SELECT * FROM agreements WHERE id=? AND organization_id=? FOR UPDATE',[$aid,$targetOrg]);
            if(!$locked) throw new RuntimeException('Agreement not found.');
            $paid=(float)(get_one('SELECT COALESCE(SUM(amount),0) total FROM payments WHERE agreement_id=? AND organization_id=? AND payment_status="verified"',[$aid,$targetOrg])['total']??0);
            $pending=(int)(get_one('SELECT COUNT(*) c FROM payments WHERE agreement_id=? AND organization_id=? AND payment_status="pending"',[$aid,$targetOrg])['c']??0);
            $disputes=(int)(get_one('SELECT COUNT(*) c FROM customer_disputes WHERE agreement_id=? AND organization_id=? AND status IN ("open","reviewing")',[$aid,$targetOrg])['c']??0);
            $balance=max(0,round((float)$locked['hp_price']-$paid,2));
            if($balance>0.005||$pending>0||$disputes>0||in_array($locked['status'],['terminated','draft'],true)) throw new RuntimeException('Cannot complete this plan. Ensure the full hire-purchase price is covered by verified payments, no payment is pending, and no dispute is open.');
            db()->prepare('UPDATE agreements SET balance_due=0,status="settled" WHERE id=? AND organization_id=?')->execute([$aid,$targetOrg]);
            if(function_exists('ffdev_log_event')) ffdev_log_event($targetOrg,(int)$locked['inventory_item_id'],'hire_purchase_plan_settled',['agreement_id'=>$aid,'verified_paid'=>$paid,'release_command'=>'blocked_until_provider_integration'],(int)$u['id']);
            $last=get_one('SELECT id,action_status FROM device_control_actions WHERE agreement_id=? ORDER BY id DESC LIMIT 1',[$aid]);
            if(!$last || in_array($last['action_status'],['failed','blocked_no_provider'],true)){
                $requestRef='REL-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));
                $reason='Agreement fully settled; provider integration not yet configured';
                db()->prepare('INSERT INTO device_control_actions(organization_id,agreement_id,inventory_item_id,requested_by,action_type,action_status,request_reference,reason,requested_at) VALUES(?,?,?,?,"release","blocked_no_provider",?,?,NOW())')->execute([$targetOrg,$aid,$locked['inventory_item_id'],$u['id'],$requestRef,$reason]);
                if(function_exists('ffdev_log_event')) ffdev_log_event($targetOrg,(int)$locked['inventory_item_id'],'device_release_requested',['agreement_id'=>$aid,'action_status'=>'blocked_no_provider','request_reference'=>$requestRef],(int)$u['id']);
                $actionId=(int)db()->lastInsertId();
            } else { $actionId=(int)$last['id']; }
            db()->commit();
            audit('agreement.settled_device_release_requested','agreement',$aid,['organization_id'=>$targetOrg,'verified_paid'=>$paid,'device_release_status'=>'blocked_no_provider','action_id'=>$actionId]);
            flash('warning','Hire-purchase plan closed as SETTLED. A device-release request was logged, but no remote command was sent because an authorised device-financing provider is not connected yet.');redirect('?r=plan-closures');
        }
        $paid=(float)(get_one('SELECT COALESCE(SUM(amount),0) total FROM payments WHERE agreement_id=? AND payment_status="verified"',[$aid])['total']??0);$pending=(int)(get_one('SELECT COUNT(*) c FROM payments WHERE agreement_id=? AND payment_status="pending"',[$aid])['c']??0);$disputes=(int)(get_one('SELECT COUNT(*) c FROM customer_disputes WHERE agreement_id=? AND status IN ("open","reviewing")',[$aid])['c']??0);$balance=max(0,round((float)$a['hp_price']-$paid,2));
        layout_start('Complete hire-purchase plan','plan-closures');alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">FINAL SETTLEMENT REVIEW</div><h1>Complete hire-purchase plan</h1><p class="muted">'.h($a['agreement_no']).' · '.h($a['organization_name']).'</p></div><a class="btn" href="?r=plan-closures">Back</a></div><div class="panel"><h3>Settlement verification</h3><div class="health-list"><div><span>Customer</span><b>'.h($a['full_name']).' · '.h($a['phone']).'</b></div><div><span>Device / IMEI</span><b>'.h($a['manufacturer'].' '.$a['model'].' · '.($a['imei1']??'—')).'</b></div><div><span>Hire-purchase price</span><b>'.money($a['hp_price']).'</b></div><div><span>Verified payments</span><b>'.money($paid).'</b></div><div><span>Remaining balance</span><b>'.money($balance).'</b></div><div><span>Pending payments</span><b>'.(int)$pending.'</b></div><div><span>Open disputes</span><b>'.(int)$disputes.'</b></div><div><span>Agreement status</span><b>'.h($a['status']).'</b></div></div>';
        if($balance<=0.005&&$pending===0&&$disputes===0&&!in_array($a['status'],['terminated','draft'],true)) echo '<form method="post" onsubmit="return confirm(\'Confirm that the customer has completed all payments and close this hire-purchase plan?\')"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="confirm_release"><button class="btn primary">Confirm full settlement & close plan</button></form>';
        else echo '<div class="alert warning">This plan is not eligible for completion. Clear the outstanding balance, verify pending transactions and resolve any open dispute first.</div>';
        echo '<div class="info-note">Closing the plan records the agreement as settled. An actual remote removal of a financing restriction requires an approved, supported device-financing provider integration. This installation will not falsely report that a phone has been remotely unlocked.</div></div>';
        $actions=get_all('SELECT * FROM device_control_actions WHERE agreement_id=? ORDER BY requested_at DESC',[$aid]);
        if($actions){echo '<div class="panel"><h3>Device release history</h3><div class="table-wrap"><table><thead><tr><th>Request</th><th>Status</th><th>Requested</th><th>Reason</th></tr></thead><tbody>';foreach($actions as $x)echo '<tr><td>'.h($x['request_reference']).'</td><td><span class="pill warning">'.h(str_replace('_',' ',$x['action_status'])).'</span></td><td>'.date('d M Y H:i',strtotime($x['requested_at'])).'</td><td>'.h($x['reason']).'</td></tr>';echo '</tbody></table></div></div>';}
        layout_end();return true;
    }

    if ($route === 'platform-phones') {
        require_role(['platform_admin']);$q=trim((string)($_GET['q']??''));$sql='SELECT i.*,o.name organization_name,a.agreement_no,a.status agreement_status,c.full_name customer_name,c.phone customer_phone,d.provider_key,d.protection_status FROM inventory_items i JOIN organizations o ON o.id=i.organization_id LEFT JOIN agreements a ON a.inventory_item_id=i.id AND a.organization_id=i.organization_id AND a.status<>"draft" LEFT JOIN customers c ON c.id=a.customer_id LEFT JOIN device_protection_records d ON d.inventory_item_id=i.id AND d.organization_id=i.organization_id';$params=[];if($q!==''){$sql.=' WHERE (o.name LIKE ? OR i.manufacturer LIKE ? OR i.model LIKE ? OR i.imei1 LIKE ? OR i.imei2 LIKE ? OR i.inventory_no LIKE ?)';$like='%'.$q.'%';$params=[$like,$like,$like,$like,$like,$like];}
        $page=max(1,(int)($_GET['page']??1));$perPage=100;$where='';if($q!=='')$where=' WHERE (o.name LIKE ? OR i.manufacturer LIKE ? OR i.model LIKE ? OR i.imei1 LIKE ? OR i.imei2 LIKE ? OR i.inventory_no LIKE ?)';
        $totalRows=(int)(get_one('SELECT COUNT(*) total FROM inventory_items i JOIN organizations o ON o.id=i.organization_id'.$where,$params)['total']??0);$pages=max(1,(int)ceil($totalRows/$perPage));$offset=($page-1)*$perPage;
        $rows=get_all($sql.' ORDER BY i.created_at DESC LIMIT '.$perPage.' OFFSET '.$offset,$params);audit('platform.global_phone_register_viewed','inventory',null,['page'=>$page,'rows'=>count($rows)]);
        layout_start('All platform phones','platform-phones');alert_box();echo '<div class="page-heading"><div><div class="eyebrow">GLOBAL INVENTORY OVERSIGHT</div><h1>All registered phones</h1><p class="muted">Platform-wide inventory register across all subscribed retailers.</p></div></div><div class="panel"><div class="panel-head"><h3>Phone inventory · '.number_format($totalRows).' records</h3><form method="get"><input type="hidden" name="r" value="platform-phones"><input class="search-input" name="q" value="'.h($q).'" placeholder="Search IMEI, model or retailer…"></form></div><div class="table-wrap"><table><thead><tr><th>Retailer</th><th>Inventory / device</th><th>IMEI 1 / IMEI 2</th><th>Customer / agreement</th><th>Status</th><th>Financing eligibility</th><th>OEM protection</th><th>Added</th><th></th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td><b>'.h($r['organization_name']).'</b></td><td>'.h($r['inventory_no']).'<small>'.h($r['manufacturer'].' '.$r['model'].' '.$r['storage_capacity']).'</small></td><td>'.h($r['imei1']??'—').'<small>'.h($r['imei2']??'—').'</small></td><td>'.h($r['customer_name']??'—').'<small>'.h($r['agreement_no']??'').'</small></td><td>'.h($r['status']).'</td><td>'.h(str_replace('_',' ',$r['financing_eligibility'])).'</td><td>'.h(ffdev_status_label($r['protection_status']??'not_assessed')).'</td><td>'.date('d M Y',strtotime($r['created_at'])).'</td><td><a class="tiny-btn" href="?r=device-protection&id='.(int)$r['id'].'&org='.(int)$r['organization_id'].'">Protection record</a></td></tr>';
        if(!$rows)echo '<tr><td colspan="9" class="empty">No phone inventory has been registered.</td></tr>';
        echo '</tbody></table></div><div class="panel-foot"><span>Page '.(int)$page.' of '.(int)$pages.'</span><div>'.($page>1?'<a class="tiny-btn" href="?r=platform-phones&page='.($page-1).'&q='.rawurlencode($q).'">Previous</a>':'').($page<$pages?'<a class="tiny-btn" href="?r=platform-phones&page='.($page+1).'&q='.rawurlencode($q).'">Next</a>':'').'</div></div></div>';layout_end();return true;
    }

    if ($route === 'platform-payments') {
        require_role(['platform_admin']);$q=trim((string)($_GET['q']??''));$sql='SELECT p.*,o.name organization_name,a.agreement_no,c.full_name customer_name,c.phone customer_phone,u.full_name recorded_by_name FROM payments p JOIN organizations o ON o.id=p.organization_id JOIN agreements a ON a.id=p.agreement_id JOIN customers c ON c.id=p.customer_id LEFT JOIN users u ON u.id=p.recorded_by';$params=[];if($q!==''){$sql.=' WHERE (o.name LIKE ? OR p.receipt_no LIKE ? OR p.external_reference LIKE ? OR a.agreement_no LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?)';$like='%'.$q.'%';$params=[$like,$like,$like,$like,$like,$like];}
        $page=max(1,(int)($_GET['page']??1));$perPage=100;$where='';if($q!=='')$where=' WHERE (o.name LIKE ? OR p.receipt_no LIKE ? OR p.external_reference LIKE ? OR a.agreement_no LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?)';
        $totalRows=(int)(get_one('SELECT COUNT(*) total FROM payments p JOIN organizations o ON o.id=p.organization_id JOIN agreements a ON a.id=p.agreement_id JOIN customers c ON c.id=p.customer_id'.$where,$params)['total']??0);$pages=max(1,(int)ceil($totalRows/$perPage));$offset=($page-1)*$perPage;
        $rows=get_all($sql.' ORDER BY p.received_at DESC LIMIT '.$perPage.' OFFSET '.$offset,$params);$totals=get_one('SELECT COUNT(*) n,COALESCE(SUM(CASE WHEN payment_status="verified" THEN amount ELSE 0 END),0) verified_total,COALESCE(SUM(CASE WHEN payment_status="pending" THEN amount ELSE 0 END),0) pending_total FROM payments');audit('platform.global_payment_register_viewed','payment',null,['page'=>$page,'rows'=>count($rows)]);
        layout_start('All platform payments','platform-payments');alert_box();echo '<div class="page-heading"><div><div class="eyebrow">GLOBAL COLLECTIONS OVERSIGHT</div><h1>All payment transactions</h1><p class="muted">Platform-wide payment ledger. Only verified payments count as collections.</p></div></div><div class="kpi-grid"><div class="kpi-card"><span>Total transactions</span><strong>'.(int)$totals['n'].'</strong></div><div class="kpi-card"><span>Verified collection value</span><strong>'.money($totals['verified_total']).'</strong></div><div class="kpi-card"><span>Pending value</span><strong>'.money($totals['pending_total']).'</strong></div></div><div class="panel"><div class="panel-head"><h3>Global payment ledger</h3><form method="get"><input type="hidden" name="r" value="platform-payments"><input class="search-input" name="q" value="'.h($q).'" placeholder="Search receipt, reference, retailer or customer…"></form></div><div class="table-wrap"><table><thead><tr><th>Retailer</th><th>Receipt / reference</th><th>Customer / agreement</th><th>Channel</th><th>Amount</th><th>Principal / interest</th><th>Status</th><th>Received / recorded by</th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td><b>'.h($r['organization_name']).'</b></td><td>'.h($r['receipt_no']).'<small>'.h($r['external_reference']??'—').'</small></td><td>'.h($r['customer_name']).'<small>'.h($r['agreement_no']).' · '.h($r['customer_phone']).'</small></td><td>'.h(strtoupper($r['channel'])).'</td><td>'.money($r['amount']).'</td><td>'.money($r['principal_allocated']).' / '.money($r['interest_allocated']).'</td><td><span class="pill '.($r['payment_status']==='verified'?'success':($r['payment_status']==='pending'?'warning':'danger')).'">'.h($r['payment_status']).'</span></td><td>'.date('d M Y H:i',strtotime($r['received_at'])).'<small>'.h($r['recorded_by_name']??'—').'</small></td></tr>';
        if(!$rows)echo '<tr><td colspan="8" class="empty">No payment transactions found.</td></tr>';
        echo '</tbody></table></div><div class="panel-foot"><span>Page '.(int)$page.' of '.(int)$pages.'</span><div>'.($page>1?'<a class="tiny-btn" href="?r=platform-payments&page='.($page-1).'&q='.rawurlencode($q).'">Previous</a>':'').($page<$pages?'<a class="tiny-btn" href="?r=platform-payments&page='.($page+1).'&q='.rawurlencode($q).'">Next</a>':'').'</div></div></div>';layout_end();return true;
    }

    if ($route === 'platform-services') {
        require_role(['platform_admin']);
        if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='toggle_service'){
            $id=(int)($_POST['service_id']??0);$enabled=isset($_POST['enabled'])?1:0;$service=get_one('SELECT * FROM platform_services WHERE id=?',[$id]);if(!$service)throw new RuntimeException('Platform service not found.');
            db()->prepare('UPDATE platform_services SET enabled=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$enabled,$u['id'],$id]);audit('platform.service_toggled','platform_service',$id,['service_key'=>$service['service_key'],'enabled'=>$enabled]);flash('success','Platform service setting updated.');redirect('?r=platform-services');
        }
        $rows=get_all('SELECT * FROM platform_services ORDER BY id');layout_start('Platform services','platform-services');alert_box();echo '<div class="page-heading"><div><div class="eyebrow">PLATFORM CONFIGURATION</div><h1>Services management</h1><p class="muted">Control which product modules are available to subscribed retailers.</p></div></div><div class="info-note"><b>Availability is not the same as provider integration.</b> Enabling Daraja, SMS or remote device release does not establish provider connectivity. Those integrations remain unavailable until credentials, contracts, callback security and testing have been completed.</div><div class="panel"><div class="table-wrap"><table><thead><tr><th>Service</th><th>Description</th><th>Integration state</th><th>Availability</th><th>Save</th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td><b>'.h($r['service_label']).'</b><small>'.h($r['service_key']).'</small></td><td>'.h($r['description']).'</td><td><span class="pill '.($r['integration_status']==='connected'?'success':'warning').'">'.h(str_replace('_',' ',$r['integration_status'])).'</span></td><td><form id="svc-'.(int)$r['id'].'" method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="toggle_service"><input type="hidden" name="service_id" value="'.(int)$r['id'].'"><label class="checkline"><input type="checkbox" name="enabled" value="1" '.((int)$r['enabled']?'checked':'').'> Enabled</label></form></td><td><button form="svc-'.(int)$r['id'].'" class="tiny-btn">Save</button></td></tr>';
        echo '</tbody></table></div></div>';layout_end();return true;
    }

    return false;
}
