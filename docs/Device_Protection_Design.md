# Device identity and reset-resilient control design

## FoneFlex server-side identity binding

The v0.5 app binds a phone's valid IMEI to one inventory row. Hire-purchase agreements reference that inventory row. Agreement creation produces a device event. Customer handset actions such as factory reset, SIM replacement or app deletion cannot erase FoneFlex's database record, payment ledger or agreement reference.

The IMEI registry stores keyed HMAC digests rather than a second plain-text copy. The original IMEI field remains in the inventory table for normal staff workflows and must be protected by tenant permissions and production database security. Cross-workspace duplicate checks prevent the same IMEI from being silently added to multiple retailers.

## Physical device enforcement

A factory reset erases ordinary user-installed Android apps and local app state. An ordinary PHP/PWA application, an IMEI scanner or a QR code printed by FoneFlex cannot make a phone permanently managed. Reset-resilient controls must be applied through supported firmware/OEM provisioning and an approved provider programme before handover.

## Provider selection guidance

### Samsung phones sold on hire purchase

Samsung Knox Guard is the recommended first provider evaluation for compatible Samsung stock. Samsung describes Knox Guard as a cloud service designed to protect enterprise devices and payment plans, with remote lock/unlock, overdue-payment actions and controls that can restrict factory reset and firmware tampering. The retailer/FoneFlex must apply and receive any required Samsung account/partner approval, licences, device eligibility and API credentials. FoneFlex must then implement calls against the official Knox Guard REST API and provider webhook/status flow. No endpoint, credential format or signature scheme should be guessed.

### Other Android manufacturers

Use the manufacturer's own financing/retail device-protection service or an approved financing-lock provider only where the provider supports the exact brand/model/region and provides a documented API and contract. Android zero-touch and setup QR are useful for enterprise-owned device provisioning/re-enrolment but are not the financing lock. Google's Android Management API policy expressly excludes payment-status-based controls for consumer and retail financing.

### iPhone

Apple Automated Device Enrolment is intended for organisation-owned Apple devices assigned through Apple Business/School Manager and an MDM. Supervised devices can be configured to prevent users from removing MDM enrolment, but this does not create a general consumer hire-purchase payment lock. Do not use a customer's personal Apple ID or personal Activation Lock as a finance enforcement mechanism. Any future iPhone path must be reviewed with Apple/authorised channel partners and counsel.

## Safe lock/unlock policy for future provider API integration

- No device lock command may be sent solely because an arbitrary date has passed. Use the agreement's lawful notice/default/arrears process and authorised business roles.
- Lock actions require an auditable reason, agreement and payment-state snapshot, customer notification/support contact and the retailer's authorised approval.
- Restore full functionality promptly when verified amounts due are paid or a valid dispute/adjustment is approved.
- Release only after server-side reconciliation confirms full settlement, pending payments are cleared, disputes are resolved and the provider confirms the release command.
- Every request, provider reference, response, actor, timestamp, retry and outcome must be retained. Idempotency and webhook signature verification are mandatory.
- Provide a human exception process for payment delays, erroneous balances, emergency access and provider outages.
- Keep a provider integration disabled until credentials, contract, device eligibility, legal review, customer disclosures, UAT and independent security tests are complete.
