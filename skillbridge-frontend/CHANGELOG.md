# SkillBridge Changelog

This changelog records the implementation work completed across the SkillBridge Laravel backend and React frontend demo.

## [Current] — Frontend API Foundation and Demo Integration

### Frontend

The React/Vite frontend was built around the **Civic Blueprints** visual direction: limestone canvas, ink navy hierarchy, Blueprint Cobalt actions, marigold attention markers, ruled editorial surfaces, numbered sections, and visible handoff states.

The dashboard includes a responsive workspace rail, seeker/provider role switching, job recommendations, provider matching, recent activity, notification popover, trust metrics, verification and transaction navigation, mobile navigation, generated hero and collaboration artwork, and a custom SkillBridge bridge mark.

Authentication integration was added through a typed Laravel API client and Sanctum-aware auth context. The frontend now supports login, registration, logout, current-user loading, local bearer-token persistence, role selection, a dedicated `/auth` route, and a transparent demo-mode fallback when `VITE_API_BASE_URL` is not configured. The dashboard loads the live job count when a Laravel API URL and valid token are available while retaining safe presentation data for offline demos.

### Verification

TypeScript checking, production build, desktop screenshots, mobile screenshots, dashboard rendering, and authentication-page rendering were completed successfully. The frontend is saved at checkpoint `95a67872`.

## Phase 9 — Final API and Security Audit

The generic job update endpoint no longer accepts direct lifecycle-status changes, ensuring job state remains controlled by the intended bid and contract workflows.

Message thread read-state access now requires the same valid participant relationship used for sending messages, closing an identified authorization gap.

Admin-only middleware was registered in Laravel 12 bootstrap configuration. Global skill creation was restricted to administrators while provider skill attachment and removal remained available through the existing flow.

Contract completion was extended to either authenticated contract participant for the frontend demo flow, with notification delivery to the other participant. Regression coverage was added for provider-triggered completion.

The root Laravel smoke-test failure was repaired by restoring the expected runtime cache directory structure without changing API behavior.

## Phase 8 — Admin and Moderation

Admin verification moderation endpoints were added for listing submitted documents, approving a document, rejecting a document, and recording a rejection reason.

Verification approval updates the user verification state. Moderation endpoints are protected by Sanctum authentication and the dedicated admin middleware.

Admin skill management behavior was aligned with the moderation scope. Non-admin users cannot create global skills.

Feature coverage was added for admin authorization, non-admin rejection, verification approval, verification rejection, and restricted skill creation.

## Phase 7 — Notifications, Verification Documents, and Donations

Laravel database notifications were added with a reusable `SkillBridgeNotification` payload carrying event type, message, and related resource identifiers. Notifications are synchronous so the frontend demo can display records immediately without a queue worker.

Notification endpoints were added for listing notifications, marking a single notification as read, and marking all notifications as read. Notifications are triggered for bids, messages, contract creation/completion, escrow release, reviews, and verification submissions.

Verification document submission and listing were implemented with user ownership, pending-status enforcement, validated document upload support, explicit media-path compatibility, administrator notification, approval, rejection, and user verification-state updates.

Donation campaign support was added using the documented campaign schema. Public listing and detail endpoints are available, while campaign creation and owner-only draft updates require authentication. Monetary fields use decimal casts and status values are controlled.

The implementation intentionally does not invent an individual donation contribution or payment gateway flow because the supplied campaign schema does not define contribution amount or payment transaction fields.

## Phase 6 — Provider Matching

A deterministic provider-matching service was added using existing data only. The score considers matching skills, category, rating, bid and completed-contract history, and location proximity.

The suggested-provider endpoint returns ranked providers with an explainable score breakdown and matching skills. Job-owner authorization and limit validation were added.

## Phase 5 — Reviews and Ratings

Review listing and creation were implemented for completed contracts. Eligibility requires the authenticated user to be a contract participant and prevents self-review.

A reviewer can submit only one review per contract. Ratings accept integer values from 1 through 5, comments are validated, and the reviewed user’s rating is recalculated from received reviews.

A database-level unique constraint was added for reviewer and contract pairs. Review authorization, validation, duplicate prevention, completion requirements, both participant directions, and rating recalculation were covered by feature tests.

## Earlier Completed Backend Modules

The existing backend modules were preserved and verified: authentication and profile management, skills, jobs, bids, contracts, messaging, and transactions/escrow.

Existing ownership rules, participant checks, contract state transitions, transaction recipient handling, and model relationships were retained except where the final audit identified a confirmed security or demo-flow issue.

## Sanctum Repair

The Laravel authentication controller already called Sanctum token creation correctly, but the project was missing the `personal_access_tokens` table migration. The migration below was added without changing the controller:

```text
database/migrations/2026_08_24_000001_create_personal_access_tokens_table.php
```

After the migration was added, fresh migrations completed successfully and register/login token creation worked end to end.

## Consolidated Verification Results

| Area | Result |
|---|---|
| Laravel fresh migrations | All migrations ran successfully on the SQLite verification configuration. |
| Sanctum token storage | `personal_access_tokens` migration ran and token creation was verified. |
| Laravel API routes | Final route inspection reported 49 API routes. |
| Laravel feature tests | 34 tests passed with 105 assertions in the audited backend state. |
| Frontend TypeScript | Passed. |
| Frontend production build | Passed. |
| Frontend responsive verification | Desktop and mobile dashboard/auth screenshots verified. |

## Important Local Setup Notes

The Laravel backend should be configured with the local database credentials in its `.env` file. For XAMPP, start Apache and MySQL, create the configured database, and run:

```bash
composer install --no-dev
php artisan key:generate
php artisan migrate
php artisan serve
```

For the frontend live API connection, configure the Vite environment variable outside the repository according to the local environment:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000
```

When this variable is omitted, the frontend remains usable in demo mode. When it is configured, the Laravel backend must allow the frontend origin through CORS and the browser must send the Sanctum bearer token for protected endpoints.

## Known Scope Boundaries

The current frontend is an API-ready demo shell rather than a complete production marketplace UI. Dedicated live pages for job detail, bid management, contracts, messaging, reviews, verification, transactions, donations, and admin moderation remain suitable next additions.

The Donations module currently manages campaign records. Real donor contributions, payment processing, refunds, and webhooks require an explicit contribution schema and payment-provider design before implementation.
