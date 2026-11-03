# FoneFlex v0.5 — Device scanning and OEM protection register (WampServer)

**This is an overlay release for an existing FoneFlex v0.4 installation.** It adds phone-box barcode/QR scanning, IMEI checksum validation, duplicate IMEI controls, a server-side phone-to-agreement identity history, and an OEM/financing-provider enrolment register.

## What v0.5 implements

1. **Camera barcode/QR scanner** in Phone inventory using the device's rear camera and the browser `BarcodeDetector` API where supported. The scanner can recognise IMEI/IMEI2/serial values contained in QR or barcode text/JSON. Manual entry remains available for browsers without native barcode support.
2. **IMEI validation** requiring a 15-digit IMEI 1 and validating the Luhn check digit. IMEI 2 is optional but validated when supplied. Serial number is captured separately.
3. **Global duplicate checking** across FoneFlex retailer workspaces. IMEI identifiers are also HMAC-indexed in a separate registry using the stable `security.data_encryption_key`; the raw scan payload is not retained, only its keyed digest and format.
4. **Permanent server-side device binding**: the inventory item is linked to the hire-purchase agreement and an immutable-style event history records registration, agreement binding, provider status changes, settlement and device-release requests. Factory-resetting a handset does not erase the FoneFlex server record or unlink the agreement.
5. **OEM / financing-provider register**: business owner/admin can record provider, enrolment route, provider reference, status and evidence note. FoneFlex Super Admin can view the global device register and inspect/update provider status records.
6. **Agreement control**: a phone without a valid IMEI cannot be assigned to a new hire-purchase agreement.

## Critical distinction: scan, enrolment and finance lock

- **Scanning** reads an identifier printed on the retail packaging or displayed by the device. A common EAN/UPC retail barcode identifies the product model/SKU, not a unique handset IMEI. If the scanned code does not include the IMEI, verify the handset's IMEI with `*#06#` or the OEM label and enter it manually.
- **Android setup QR / zero-touch** are enterprise device-enrolment methods. They may provision or re-enrol supported organisation-managed devices after a factory reset, but they are not a consumer finance-payment lock.
- **Samsung Knox Guard** is the most directly relevant OEM route for Samsung phones sold through financing. Samsung describes it as protecting payment plans, with remote lock/unlock and restrictions that include factory reset and firmware/binary tampering when properly enrolled. FoneFlex v0.5 records the Knox Guard/provider status; it does not yet call Samsung's API.
- **Apple Automated Device Enrolment (ADE)** is intended for organisation-owned Apple devices with an Apple Business/School Manager and compatible MDM deployment. It can prevent a supervised device's user from removing the management profile, but it is not a general consumer hire-purchase repayment lock. Do not use a customer's personal Apple ID or personal Activation Lock as a finance-control mechanism.
- **Google's Android Management API is not an acceptable substitute**: Google's current permissible-use policy explicitly prohibits payment-status-based device restrictions for consumer/retail financing and hardware-lease scenarios.

Official references:
- Samsung Knox Guard overview: https://docs.samsungknox.com/admin/knox-guard/
- Samsung Knox Guard lock/unlock: https://docs.samsungknox.com/admin/knox-guard/how-to-guides/manage-devices/lock-and-unlock-devices/
- Samsung Knox Guard API reference: https://docs.samsungknox.com/dev/knox-guard/api/
- Google Android Management API permissible use: https://developers.google.com/android/management/permissible-usage
- Android zero-touch prerequisites: https://support.google.com/work/android/answer/7514005
- Apple Automated Device Enrolment: https://support.apple.com/guide/deployment/automated-device-enrollment-management-dep73069dd57/web

## What is not connected yet

**There is no live device lock, unlock, factory-reset prevention or zero-touch provisioning API connection in this release.** The provider status is an auditable staff confirmation entered after the retailer checks the actual provider portal. A `provider_confirmed` status in this screen must not be treated as proof that FoneFlex has independently tested the OEM API or reset behaviour.

To implement live remote device control, the business must first obtain the applicable OEM/financing-provider account, partner/merchant approval, supported device list, API documentation, credentials, licence/fees, webhook/callback details and end-customer terms. For Samsung financing, the target integration should be the Samsung Knox Guard API, not Android Management API. The API adapter must be implemented against the current official API documentation and tested with approved test devices before production.

## Deploy to WAMP

1. Back up `C:\wamp64\www\FoneFlex` and export the `foneflex` database in phpMyAdmin.
2. Confirm that v0.4 files and migrations 002, 003 and 004 are already installed. Do not rerun earlier migrations.
3. Extract `FoneFlex_v0.5_WAMP_Overlay.zip` into `C:\wamp64\www`, allowing files to merge into the existing `FoneFlex` directory. Preserve `config\config.php` and your customer data.
4. In phpMyAdmin, select the `foneflex` database and run `database\migrations\005_device_scanning_and_oem_protection.sql` once.
5. Ensure `security.data_encryption_key` remains configured with the same stable secret used for customer ID encryption. Do not regenerate it; it is also used for the keyed IMEI registry.
6. Configure trusted local HTTPS for `foneflex.local` before using the browser camera. The camera scanner uses `BarcodeDetector` where the browser supports it. If unavailable, enter the IMEI and serial manually.
7. Run this PowerShell check:

```powershell
& 'C:\wamp64\bin\php\php8.3.14\php.exe' 'C:\wamp64\www\FoneFlex\bin\check_v05.php'
```

8. Restart all WAMP services and sign into FoneFlex.

## Day-to-day workflow

1. Open **Phone inventory → Scan / add phone**.
2. Scan the QR/barcode on the box. Verify scanned IMEI(s) against `*#06#` on the handset or the OEM serial/IMEI label. Enter missing values manually. Save the handset.
3. Open **Device management → Protection record**.
4. Select the correct OEM/financing programme. Register the IMEI using the retailer's approved provider portal/reseller process. Record the provider reference and verification evidence only after the provider shows the device as enrolled.
5. Pilot a test device through an actual factory reset and setup flow in accordance with the provider's approved test procedure before handing financed stock to customers.
6. Create the hire-purchase agreement. FoneFlex will maintain its server-side link between the device IMEI, inventory item, customer and agreement. The customer resetting the device does not remove the backend plan link.

## Validation performed in the build environment

- PHP syntax checks on the updated bootstrap, device module, enhancements module and checker.
- JavaScript syntax checks on the camera scanner script.
- IMEI Luhn checksum unit tests (valid, invalid checksum, incorrect length and non-numeric cases).
- The actual database migration and device browser scanner have not been run against the user's local WAMP database/device. Run migration 005 and the check script locally, then complete the pilot checklist above before entering live financed stock.


## Reducing-balance interest (v0.7)

New hire-purchase agreements ask for a nominal annual interest rate (% p.a.). The application converts it to a periodic rate using 365 periods for daily, 52 for weekly, 26 for fortnightly, or 12 for monthly schedules. Interest for each period is calculated on the opening outstanding principal. The installment is amortised so the principal reduces over time. Total agreed interest is the sum of the scheduled period interest.

Historical contracts remain labelled `legacy_flat` and are not recalculated. Run migrations 006 and 007 in sequence when moving from v0.5.
