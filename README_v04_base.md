# FoneFlex v0.4 — Complete customer onboarding and KYC workflow (WampServer)

This release corrects the earlier split process where staff created a short customer record and then had to open a separate KYC page. **New customers are now created through one guided onboarding wizard** that captures the customer profile and KYC material in one submission.

## What the new onboarding process captures

1. **Customer biodata:** full legal name, primary and alternative Kenyan mobile numbers, email (optional), date of birth, nationality, and optional gender.
2. **Identity:** document type and ID/passport number. The ID number is encrypted before it is stored in the database; the plain-text number is not displayed again.
3. **Camera-only identity photographs:** ID front, ID back, and customer photograph are taken using the device camera. The form deliberately does not provide a gallery/file picker for these three images. The user can preview and retake each capture before submission.
4. **Address and location:** county, town, estate/village, street/road, nearest landmark/directions, plus optional GPS coordinates and accuracy. GPS is captured only after a browser permission prompt; there is no continuous tracking.
5. **Next of kin:** full name, mobile number, relationship and optional address. The next-of-kin number must be different from the customer's primary number.
6. **M-Pesa statement, when necessary:** the staff member must select that a statement is required, record the specific assessment reason, and attach the statement. Accepted formats are PDF/JPEG/PNG, up to 10 MB. If not requested, no statement is collected.
7. **Privacy notice and consent record:** records the customer name, the consent/acknowledgement method, and staff confirmations that the notice was explained, the customer was informed about next-of-kin collection, and the required consent or other documented lawful basis was recorded.

## Submission and review workflow

- Customer and KYC rows are created in a database transaction. If the database write fails, the transaction is rolled back and any files written for that failed submission are removed.
- All three camera images are mandatory. A requested M-Pesa statement must be attached before the onboarding record can be submitted.
- Identity images and M-Pesa statements are stored under `storage/private/kyc`, outside the public document root. They are served only through the authenticated, audited KYC document route.
- The record is submitted as `pending_review`. Only the retailer owner/administrator may view private documents and make KYC approval decisions.
- If an M-Pesa statement was requested, an owner/administrator must mark it `reviewed` before the KYC record can be approved. Rejected statements require a review note.
- Staff can return to the existing **Customer KYC** page to update KYC details or view the record status.

The consent fields are an auditable staff attestation, not an electronic signature. Retailers must retain any signed written consent evidence under their approved process. The form text is a workflow aid, not a substitute for a privacy notice reviewed for the retailer's actual purposes, lawful bases, recipients, retention periods and data-subject-rights process.

## Apply v0.4 safely to an existing v0.3 WAMP installation

1. Back up `C:\wamp64\www\FoneFlex` and export the `foneflex` database in phpMyAdmin.
2. Extract the **FoneFlex v0.4 WAMP Overlay** ZIP into `C:\wamp64\www`, allowing it to merge into the existing `FoneFlex` folder. Keep your existing `config\config.php` and all customer data. Do not overwrite the configuration file.
3. Confirm that migrations 002, 003 and 004 have not already been applied. If you are already on v0.3, run **only** `database\migrations\004_complete_customer_onboarding.sql` once, against the `foneflex` database. Do not re-import `database\schema.sql`, and do not rerun migrations 002 or 003.
4. Check the active WAMP PHP settings. Because the browser submits three camera images plus an optional statement, use at least these local development settings in the active PHP `php.ini`:

```ini
file_uploads = On
upload_max_filesize = 12M
post_max_size = 32M
max_file_uploads = 10
max_execution_time = 120
memory_limit = 256M
```

Restart all WampServer services after changing `php.ini`. The active Apache PHP configuration may differ from the CLI PHP configuration.

5. Ensure `security.data_encryption_key` is already set in `config\config.php` to the same stable secret used for v0.3. Do not regenerate it or change it after encrypted ID numbers exist.
6. Run the deployment database/configuration check:

```powershell
& 'C:\wamp64\bin\php\php8.3.14\php.exe' 'C:\wamp64\www\FoneFlex\bin\check_v04.php'
```

Adjust the PHP version folder if your WAMP installation uses a different version. The check verifies required columns and encryption-key configuration; it does not create tables or run migrations.

7. Configure trusted local HTTPS for `foneflex.local` before using camera or GPS capture. Modern browsers generally block `getUserMedia` and geolocation on ordinary non-secure local hostnames. The browser must grant camera/location permissions.

## v0.4 test checklist — use synthetic records only

- Open **Customers → New customer onboarding**. Confirm the wizard has four sections and the customer is not inserted until the final submission.
- Enter biodata and confirm mandatory fields reject missing/invalid details.
- Capture ID front, ID back and the customer photograph. Confirm previews appear, retake replaces the previous capture, and no gallery selector exists for those identity images.
- Try to continue without any one camera image; the wizard should stop and ask for the missing image.
- Enter county, town and at least one estate/street/landmark. Test GPS denial and GPS success; manual address entry must still allow completion when GPS is declined.
- Enter next-of-kin information. Test that the same number cannot be used as the customer's primary number.
- Submit without a statement when it is not required. Then test a second record with statement required: the reason and a PDF/JPEG/PNG attachment must be provided.
- Submit without one of the privacy/consent confirmations; the wizard should stop. Verify the consent name and method are stored in the new KYC columns.
- Confirm the customer row and KYC row are both created and the record is `pending_review`. Confirm files are outside `public` and cannot be opened by guessing a URL.
- Sign in as a sales user and confirm they cannot access private KYC document viewing or approve KYC. Sign in as owner/admin and view the documents through the authenticated route.
- For a statement-required record, confirm KYC approval is blocked until the statement is marked reviewed. Confirm rejection requires a reason.
- Confirm another retailer cannot see the customer's profile or documents.

## Known limitations

- The wizard and database persistence are implemented in this code release, but this build environment does not have your local WAMP database. Migration 004 and the operational checklist must be run on your local test installation before using live customer data.
- Camera/GPS features depend on browser permissions and trusted HTTPS. They cannot be verified using this server-side package alone.
- The M-Pesa statement is stored for a staff-led review; this release does not parse bank/M-Pesa transactions automatically, fetch statements from Safaricom, make automated credit decisions or connect to Daraja.
- The consent workflow stores an auditable staff attestation; it is not a biometric or digital customer signature.
- Before production, complete privacy/legal review, data retention and deletion procedures, user access reviews, secure backups, restore testing, independent security testing and ODPC compliance assessment as applicable.


# FoneFlex — Multi-tenant Phone Hire-Purchase SaaS

**Flexible Payments. Smarter Phone Ownership.**

This is the first executable FoneFlex foundation, built as a **PHP web application** with JavaScript, CSS, MySQL/MariaDB, and PWA install metadata. It is not a native Android app. Merchants use the same hosted application from a PC browser or install it on supported Android/iOS/desktop devices as a PWA.

## Included in this first foundation

- Multi-tenant merchant registration: each retailer gets a separate `organization_id` workspace.
- Platform administrator login and merchant overview.
- Owner login, role-aware access checks, password hashing, CSRF protection, session regeneration, audit events.
- Customer registration and search, with a staff attestation that the privacy notice was provided.
- Customer profile with all agreements and contribution history, plus a browser-printable statement.
- Phone inventory registration, IMEI duplicate checks, stock status, financing eligibility label.
- Hire-purchase agreements and configurable daily/weekly/fortnightly/monthly schedules.
- Deposit capture, payment ledger, principal/interest allocation, receipts, manual verification of M-Pesa references.
- 30% (configurable per merchant in the database) FoneFlex share of **verified interest actually allocated to payments**.
- Merchant view of accrued FoneFlex charges.
- Platform view of merchant workspaces and accrued commission totals, with admin controls for per-merchant subscription fee, commission rate, and workspace status.
- Merchant subscription summary view.
- Responsive SmartHR-inspired admin layout, installable PWA manifest, app shell cache, icons, and mobile sidebar.
- Basic business dashboard and financial reconciliation overview.

## Not yet connected (must not be represented as live)

1. **Safaricom Daraja:** this foundation can record M-Pesa references as pending, lets an authorized owner/accountant verify only after independently checking the Safaricom statement, and lets them reject an invalid pending reference. STK Push, callback signature/validation, automatic reconciliation, and production credentials are not yet integrated.
2. **SMS/WhatsApp/email providers:** communication screens and automated reminder engine are a subsequent implementation stage.
3. **Android financing lock:** the device page is a compatibility/record register only. No device is remotely restricted by this code. A financing-specific OEM/provider agreement, supported devices, approved integration and legal review are prerequisites.
4. **Subscription billing:** tenant and subscription records are created; online subscription checkout, automated invoicing, payment collection, suspension/reactivation and tax invoices remain to be connected.
5. **Full production hardening:** independent security review, penetration testing, backups/restore drills, legal review, privacy impact assessment, and deployment monitoring are required before handling live customer information.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `json`, and `fileinfo` enabled.
- MySQL 8+ or MariaDB 10.6+.
- Apache with `mod_rewrite` or PHP's built-in development server.
- HTTPS for production/PWA installation.

> The container used to assemble this starter does not have a MySQL PDO driver available, so database-backed end-to-end tests could not be executed here. All PHP files should be linted in your deployment environment, then tested against a fresh MySQL/MariaDB database before production.

## Installation on WampServer (Windows)

These steps are for **WampServer**, not WSL, Ubuntu, or XAMPP.

### A. Check WampServer

1. Start WampServer and wait until its tray icon is **green** (Apache and the database service are running).
2. Open the WampServer menu and select a supported PHP 8.2+ version.
3. Under **PHP → PHP Extensions**, enable `pdo_mysql`, `mbstring`, `openssl`, and `fileinfo` if they are not already enabled. JSON is normally enabled in supported PHP versions. Restart all WampServer services.
4. In phpMyAdmin, confirm whether WampServer is running MySQL or MariaDB and note the database port (commonly `3306`, but use the port configured on your machine).

### B. Place the project files

1. Extract the ZIP archive.
2. Copy the inner `FoneFlex` project folder to `C:\wamp64\www\FoneFlex` (adjust `wamp64` if your WampServer installation uses a different folder name).
3. Keep the `app`, `config`, `database`, `bin`, and `storage` folders outside the public document root. The Apache virtual host below points only to the project's `public` directory.
4. The project includes a root `.htaccess` that denies direct web access to private project files and a `public/.htaccess` that allows the public application directory. Keep both files in place.

### C. Create the database

1. Open WampServer → **phpMyAdmin**.
2. Create a database named `foneflex` using collation `utf8mb4_unicode_ci`, or run the SQL below in phpMyAdmin as a database administrator:

```sql
CREATE DATABASE IF NOT EXISTS foneflex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'foneflex_user'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON foneflex.* TO 'foneflex_user'@'localhost';
FLUSH PRIVILEGES;
```

If that username already exists, use `ALTER USER` to change its password and re-apply the grant. If your database service uses a different host/port, adjust the application configuration accordingly.

3. Select the `foneflex` database in phpMyAdmin, choose **Import**, and import `C:\wamp64\www\FoneFlex\database\schema.sql`.
4. Confirm that the tables were created. Do not import the schema repeatedly into a database containing business data.

### D. Configure the application

1. In File Explorer, copy `config\config.example.php` to `config\config.php`.
2. Open `config\config.php` in a code editor and set the database host, port, database, username, and password. For a typical local WampServer setup, the host is `127.0.0.1` and the database is `foneflex`; use the actual port shown in WampServer.
3. Set `app_url` to `http://foneflex.local` for the virtual-host setup below. Keep `app_env` as `local` and `app_debug` as `true` only during local development. Never use debug mode in production.

### E. Configure a local Apache virtual host (recommended)

Using a virtual host makes Apache serve only the `public` folder, instead of exposing the private application, configuration, database, and setup files.

1. In WampServer, enable Apache `rewrite_module` if it is not enabled (the current starter does not rely on complex rewrite rules, but keep Apache overrides enabled for `.htaccess`).
2. Open the active Apache `httpd-vhosts.conf` file through the WampServer Apache menu, or find it under `C:\wamp64\bin\apache\apache2.4.x\conf\extra\httpd-vhosts.conf` (the version folder varies).
3. Ensure the main Apache configuration includes the virtual-host file and that `AllowOverride All` is permitted for the project public directory.
4. Add this virtual host, adjusting the folder path if needed:

```apache
<VirtualHost *:80>
    ServerName foneflex.local
    DocumentRoot "c:/wamp64/www/FoneFlex/public"
    <Directory "c:/wamp64/www/FoneFlex/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
    </Directory>
    ErrorLog "logs/foneflex_error.log"
    CustomLog "logs/foneflex_access.log" common
</VirtualHost>
```

5. Open Notepad **as Administrator** and edit `C:\Windows\System32\drivers\etc\hosts`. Add:

```text
127.0.0.1 foneflex.local
```

6. Save the files and choose **Restart All Services** from the WampServer tray menu.
7. Browse to `http://foneflex.local`. If Apache does not start, check the WampServer Apache error log and validate the virtual-host path and syntax.

For a quick local-only test without a virtual host, the URL may be `http://localhost/FoneFlex/public/`, but keep the root `.htaccess` and public `.htaccess` files in place so the private project folders are not exposed.

### F. Create the first FoneFlex platform administrator

Open PowerShell and locate the PHP version folder installed by WampServer:

```powershell
Get-ChildItem C:\wamp64\bin\php
```

Then run the setup script with the actual PHP executable path (replace the example version folder with the one on your PC):

```powershell
& 'C:\wamp64\bin\php\php8.3.x\php.exe' 'C:\wamp64\www\FoneFlex\bin\create_platform_admin.php'
```

Enter the administrator's name, email address, and a unique password of at least 14 characters. This is a command-line-only setup script; do not make it accessible through the browser. If your installation is in another folder, update both paths.

### G. WAMP troubleshooting checklist

- **Database connection error:** check that the MySQL/MariaDB service is running, the port is correct, and the `config/config.php` credentials match the database user.
- **`could not find driver`:** enable `pdo_mysql` in the active WampServer PHP extensions menu, restart all services, and verify that the PHP CLI used for setup also loads `pdo_mysql`.
- **`mb_substr` undefined:** enable `mbstring` and restart services.
- **HTTP 500:** inspect the Apache error log and PHP error log from the WampServer menu; temporarily use `app_debug=true` only on the local development machine.
- **Virtual host does not resolve:** confirm the hosts-file entry, Apache virtual-host configuration, and restart Apache.
- **PWA install unavailable:** use HTTPS for non-local deployment. Browser PWA installation on localhost is generally treated as a secure development origin; a LAN or public deployment needs HTTPS.

## Installing FoneFlex on devices

- **Android:** open the secure FoneFlex URL in Chrome (or another supported browser), then use the browser menu's **Install app** or **Add to Home screen** action.
- **iPhone/iPad:** open the secure FoneFlex URL in Safari, tap **Share**, then **Add to Home Screen** and enable opening as a web app where the iOS version offers that option.
- **Desktop:** use the browser's install icon/menu where supported, or keep using the secure URL.
- Installation creates a shortcut/app-like browser experience. It does not install a native Android package or grant remote phone-lock privileges.

## WampServer deployment note

Use the WampServer Apache virtual host described above as the normal local development entry point. The recommended project URL is `http://foneflex.local`. Do not use Linux paths, WSL commands, PHP-FPM instructions, or an Ubuntu Apache configuration for this Windows/WampServer setup.

For a quick one-off PHP check only, WampServer's PHP executable can run its built-in server from PowerShell, but this is optional and is not the normal WAMP deployment method.

## Revenue-share definition and workflow

Default commission rate in the Pilot plan: **30%**. For each verified payment, the application allocates value against the oldest outstanding installment schedule. It then records the portion allocated to interest and creates:

`FoneFlex platform fee = interest amount collected in this payment × merchant commission rate`

Example: if KSh 500 of a verified contribution is allocated to interest, FoneFlex accrues KSh 150 at a 30% share. If no interest is allocated, FoneFlex accrues no commission for that payment. Principal, unpaid interest, pending M-Pesa records, and unverified expected amounts are excluded.

A merchant's actual contractual fee rate must be agreed in a signed FoneFlex Merchant SaaS & Revenue Share Agreement. Monthly subscription charges are separate. Any reversals/refunds must reverse their original commission proportionately; the reversal workflow is a required next-stage enhancement before production billing.

## Roles in this foundation

- `platform_admin`: FoneFlex SaaS owner/platform administrator.
- `owner`: merchant business owner.
- `admin`: merchant administrator.
- `sales`: customer, stock and agreement operations.
- `collections`: payment and customer follow-up operations.
- `cashier`: payment capture.
- `accountant`: payments, commission and financial review.
- `auditor`: read-only reporting.

The initial screens and permissions are a baseline. Production rollout must complete a formal role-permission matrix and tests for every route.

## Business and regulatory checklist before launch in Kenya

- Confirm each retailer's legal status, hire-purchase licensing requirements and agreement templates with a Kenyan lawyer. The Hire-Purchase Act section 18 contains a licensing requirement for carrying on hire-purchase business; section 3 defines the Act's application by agreement type/value and corporate hirer status. Do not assume the scope threshold removes all licensing duties.
- The Act section 15 restricts recovery of possession after two-thirds of the hire-purchase price has been paid or tendered, except by suit. Remote restriction is not a shortcut around those legal protections. Configure a legal-review hold before any device restriction.
- Obtain Kenyan legal advice on whether FoneFlex's 30% revenue share, role in collecting payments, marketing, or financing operations would make it a regulated credit provider/agent or otherwise trigger additional licensing. The initial architecture assumes the merchant is creditor/owner and FoneFlex is a software/service provider; the real contracts and actual conduct must match that assumption.
- Determine whether FoneFlex is a data controller, processor, or both for each processing activity, and complete relevant ODPC registration and data-processing agreements as applicable.
- Prepare a data protection notice, retention/deletion schedule, access/correction process, incident response, and vendor/hosting processor contracts.
- Have an accountant advise on VAT, withholding tax, invoicing, revenue-share recognition and record keeping.

## Next development milestones

1. Complete merchant administration, subscription plans, automated monthly billing, invoices, commission statements, settlement and reversal ledger.
2. Complete customer statement, agreement PDF generation, payment allocation policy, arrears reminders, disputes, and automated schedule status jobs.
3. Integrate Daraja sandbox then production with signed/validated callback flow, idempotency and automated reconciliation.
4. Integrate an approved SMS gateway and scheduled reminders with consent/preferences and delivery tracking.
5. Complete supported financing-provider/OEM discovery and build an adapter only after written provider confirmation and device testing.
6. Add automated test suite, tenant isolation tests, audit integrity, backups, and security test report.

## Security warning

This code is an initial development baseline, not a production security certification. Do not upload live customer ID documents, M-Pesa secrets, or real customer records until configuration, HTTPS, database access, backups, authorization, legal terms, privacy controls, and an independent security review are complete.

---

# FoneFlex v0.2 — Additional merchant modules (WampServer)

This release adds three operational areas on top of the v0.1 WAMP installation:

- **Team & access:** merchant owners/admins can create and maintain staff accounts for Administrator, Sales Agent, Collections Officer, Cashier, Accountant, and Auditor roles. Platform administrators and owner accounts cannot be created or changed from the merchant staff screen.
- **Collections & arrears:** overdue installment list, days-late calculations, agreement-level case workspace, contact/follow-up log, promises to pay, customer dispute register, and dispute resolution. An open dispute marks the agreement `under_review`; when all open disputes are closed, the agreement returns to `overdue`, `active`, or `settled` based on the current schedule and balance.
- **Agreement documents & customer statements:** printable agreement summary with installment schedule, plus a printable customer statement with all agreements and payment history. Use the browser's Print → Save as PDF option.

## Update an existing v0.1 installation

1. **Back up first.** In phpMyAdmin, export the `foneflex` database. Also copy `C:\wamp64\www\FoneFlex` to a separate backup folder.
2. Download and extract the `FoneFlex v0.2 WAMP Overlay` ZIP into `C:\wamp64\www`. Allow it to merge into the existing `FoneFlex` folder and replace the updated `app\bootstrap.php` file. Do not delete or replace `config\config.php`.
3. Open `http://localhost/phpmyadmin`, select the `foneflex` database, open the **SQL** tab, and run `database\migrations\002_collections_and_disputes.sql` from the project. This migration creates only the two new tables; do not import `database\schema.sql` again.
4. In PowerShell, run the database check using the PHP version installed in your WAMP directory. Example (adjust the PHP folder if yours differs):

```powershell
& 'C:\wamp64\bin\php\php8.3.14\php.exe' 'C:\wamp64\www\FoneFlex\bin\check_v02.php'
```

5. Restart WampServer services, sign in, and check that **Team & access** and **Collections & arrears** appear in the left menu.

## v0.2 testing checklist

- Create a test staff account with a unique email and log in using that account.
- Check that a sales role cannot open Team & access or mark pending payments as verified.
- Create a test agreement with future installment dates, then adjust test dates in a non-production database to exercise the arrears list.
- Open a collection case and record a call, a promise to pay, and a test dispute.
- Confirm an open dispute marks that agreement under review, then resolve it and check that its status is recalculated from current arrears.
- Open an agreement and customer statement, then use Print → Save as PDF.

## Limitations in this increment

- SMS/WhatsApp are not sent by the contact log; they require a configured messaging provider and delivery callback integration.
- Branch-level data partitioning, customer self-service login, automated Daraja STK Push/callbacks, subscription checkout/invoicing and commission reversals are not included in v0.2.
- The agreement page is a printable system-generated summary, not a substitute for a Kenyan-lawyer-reviewed hire-purchase contract.
- Database-backed pages have passed PHP syntax checks only in this build environment. Run `bin/check_v02.php` and the workflow checklist against your WAMP database; report any error text for a targeted fix.

## Security reminder

Use a test business and test customers while validating this release. Do not use live device restriction, production payment credentials, or real identity documents until legal review, provider onboarding, security review, backups, and production HTTPS are complete.

---

# FoneFlex v0.3 — Customer KYC, platform oversight and plan completion (WampServer)

This is an overlay release for the v0.2 WAMP package. It adds:

- **Customer KYC register:** camera-only capture of ID front, ID back and the customer's photograph; encrypted ID/passport number; date of birth; physical address; optional GPS coordinates; next-of-kin name, phone, relationship and address.
- **Optional M-Pesa statement collection:** the retailer can record why a statement is required, the period requested and securely store a PDF/JPEG/PNG statement. It is not mandatory for every customer.
- **Private document storage:** identity images and M-Pesa statements are written under `storage/private/kyc`, outside the public document root. Viewing is restricted to the retailer owner/admin and audited.
- **KYC review:** KYC records are submitted for review and can be verified/rejected by the business owner or administrator, with a recorded reviewer and reason.
- **Super Administrator global registers:** all retailers remain visible in Platform control; new **All phones** and **All payments** pages provide cross-tenant inventory and payment visibility, with search and access audit records.
- **Platform services management:** enable/disable FoneFlex modules while separately displaying whether an external provider integration is actually connected.
- **Plan completion:** the merchant business owner and FoneFlex Super Administrator can complete an agreement after the full hire-purchase price has been covered by verified payments, pending payments are cleared and there are no open disputes. Completion records the agreement as settled and logs a device-release request.

## Apply v0.3 safely

1. Back up `C:\wamp64\www\FoneFlex` and export the `foneflex` database in phpMyAdmin. Do not skip the backup.
2. Extract the **FoneFlex v0.3 WAMP Overlay** ZIP into `C:\wamp64\www`, allowing the files to merge into the existing `FoneFlex` folder. Keep your existing `config\config.php`; do not replace it with `config.example.php`.
3. In phpMyAdmin, select the `foneflex` database. Confirm that the v0.2 migration `database\migrations\002_collections_and_disputes.sql` has already been run. Then run `database\migrations\003_kyc_platform_and_releases.sql` once. Do not re-import `database\schema.sql` into an existing installation.
4. Add the following key inside the existing `security` array in `config\config.php`:

```php
'data_encryption_key' => 'PASTE_A_UNIQUE_64_CHARACTER_HEX_KEY_HERE',
```

Generate a key in PowerShell using the WAMP PHP executable:

```powershell
& 'C:\wamp64\bin\php\php8.3.14\php.exe' -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Keep this key backed up in a secure password manager or secrets vault. **Do not change it after customer ID numbers have been encrypted**, unless you first build and run a proper key-rotation process. Do not send the key to FoneFlex support or place it in source control.

5. Run the v0.3 database/configuration check (using the PHP executable installed in WAMP):

```powershell
& 'C:\wamp64\bin\php\php8.3.14\php.exe' 'C:\wamp64\www\FoneFlex\bin\check_v03.php'
```

6. Camera capture and GPS permissions require a secure browser context. `http://foneflex.local` is not necessarily treated as secure by browsers. Configure trusted local HTTPS for the WAMP virtual host before capturing real customer images or location. The app's camera permission policy now allows camera and geolocation access from the FoneFlex origin; the browser still requires the user to grant permission.
7. Run the PHP syntax checks in the deployment folder and restart WampServer services. Then log in as a merchant owner and platform administrator to verify that the new navigation items appear.

## v0.3 operational checks

- Register a test customer and open **Customer KYC**. Capture ID front, ID back and customer photograph using the browser camera. Confirm the form does not offer gallery upload for those three images.
- Enter county, town and a physical address detail; capture GPS only after the customer grants permission. Add next-of-kin details and record the privacy/notice confirmations.
- Request an M-Pesa statement on a test record and attach a non-sensitive test PDF. Confirm the statement is accessible only through the authenticated, audited KYC document route.
- Verify and reject test KYC records using owner/admin accounts. Confirm a sales account cannot review documents or make approval decisions.
- Sign in as Super Administrator. Confirm that All phones and All payments include test records from multiple retailer workspaces, and Services management can toggle module availability.
- Complete a test agreement using verified payments. Confirm a pending/unverified payment, outstanding balance or open dispute blocks plan completion. Once eligible, use **Complete & release** and confirm that the agreement is marked settled and a release request is logged.
- Confirm that each retailer can see only its own customer KYC and agreements, while the Super Administrator can see cross-tenant phone and payment registers.

## Important limitation: remote device release

The agreement completion workflow is implemented, including full-payment checks, permission checks, an audit record and a device-release request ledger. **An actual remote phone unlock/release is not yet connected.** The current package has no approved OEM/financing-provider API, provider credentials or device-specific integration, so it deliberately records `blocked_no_provider` rather than falsely reporting that a command was sent. To activate remote release, FoneFlex must contract with an authorised financing-device provider, confirm supported models and legal terms, and implement/test that provider's authenticated release API and callback. Do not attempt to bypass Android security or use consumer device-management APIs for financing restrictions.

## Privacy and production readiness

Use synthetic/test customer records until HTTPS, access controls, data retention/deletion rules, controller/processor arrangements, ODPC obligations, a privacy impact assessment where appropriate, secure backups and independent security review have been completed. KYC files include highly sensitive personal information; do not place `storage/private` under the public web folder and do not send documents through email or chat for debugging.

This build was syntax-checked in the package environment. The package environment does not provide your WAMP database, so run the migration and the operational checks above against your local test database before handling live data.
