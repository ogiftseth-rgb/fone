# FoneFlex changelog

## v0.7 — 2 November 2026
- Replaced manually entered fixed KES interest on new agreements with an annual nominal reducing-balance rate.
- Calculates periodic interest on each period's opening outstanding principal.
- Generates an amortising installment schedule with declining interest and increasing principal components.
- Calculates and displays total agreed interest as the sum of schedule interest; displays estimated instalment before agreement creation.
- Preserves historical agreements as `legacy_flat`; existing contracts and payment allocations are not recalculated.
- Treats an agreed deposit as the initial reduction in financed principal; pending bank/M-Pesa deposits keep the agreement under review until verified.
- Pending initial deposits, when verified, are recorded as upfront principal and are not allocated a second time against scheduled installments.

## v0.6 foundation
- Added provider connection records, device enrollment references, provider action history, and customer device-management consent evidence for Samsung Knox Guard and Apple Business/ADE connector work.
- Live provider commands remain dependent on provider approval, supported devices, credentials, and an operational provider account.

## v0.5
- Added camera barcode/QR scanning for phone packaging/device labels, with manual fallback.
- Added IMEI 1 Luhn validation and optional IMEI 2 validation.
- Added cross-tenant duplicate IMEI detection and HMAC-indexed IMEI registry.
- Added device protection/provider enrolment records and device event history.
- Bound newly registered phone identities to hire-purchase agreements in the audit history.

## v0.4
- Added integrated customer biodata and KYC onboarding workflow.

## v0.3
- Added customer KYC, platform-wide retailer/phone/payment views, service controls and plan completion/release-request records.

## v0.8 — Progressive Web App
- Expanded the web app manifest with stable app identity, standalone display, platform categories and common workspace shortcuts.
- Added Android/Chromium install prompt handling and iOS/iPadOS Add to Home Screen guidance.
- Added service-worker versioning, update detection, safe offline navigation fallback and static-shell caching.
- Kept authenticated/query-string routes, APIs, customer data, KYC files, statements and payment data out of the service-worker cache.
- Added mobile safe-area support and online/offline status messaging.
- No database migration required.
