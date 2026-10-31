# SkillBridge Frontend Integration TODO

## Laravel API Connection

- [x] Revalidate supplied Laravel routes, SQL tables, and frontend endpoint assumptions.
- [x] Harden API base URL, bearer token persistence, and response normalization.
- [x] Connect live dashboard, jobs, bids, and notification-ready states; missing backend contracts route is handled gracefully.
- [x] Verify build and browser behavior with and without the API URL.
- [x] Save and deliver the connected frontend checkpoint.

## Vite HMR/WebSocket Fix

- [x] Inspect Vite server and HMR configuration.
- [x] Apply the smallest compatible WebSocket/HMR fix.
- [x] Restart and verify the preview without console WebSocket errors; latest browser logs show `[vite] connected`.

## Live API Pages

- [x] Verify dashboard, jobs, job details, and bids endpoint shapes from Laravel source; contracts/messages/profile remain for the secondary pass.
- [x] Add shared API helpers and StatusBadge component.
- [x] Build live dashboard summary wiring with API-backed job count, active contract count, rating, and recent jobs, retaining clearly labeled demo fallback when API is not configured.
- [x] Build jobs listing with search, category filter, pagination, skeleton loading, honest empty state, and API error state.
- [x] Build job details with provider bid form, existing proposal state, seeker proposal list, accept action, and created-contract state.
- [ ] Add secondary contract, messaging, and profile pages where API shapes are confirmed.
- [x] Verify `/jobs` and `/jobs/:id` at mobile viewport; `pnpm check` and `pnpm build` pass.

## JSX Conversion

- [x] Convert all frontend `.tsx` and `.ts` source files used by the app to `.jsx` and `.js`.
- [x] Remove TypeScript-only syntax and update imports/configuration.
- [x] Verify the JavaScript/JSX app with a production build and browser checks.
- [x] Save and deliver the JSX checkpoint.

- [x] Add environment-based Laravel API client with Sanctum bearer-token support.
- [x] Add auth context with local token persistence, current-user loading, login, register, and logout.
- [x] Add polished login and registration screens matching Civic Blueprints.
- [x] Add route-aware auth gate so the dashboard remains usable in demo mode while live auth is available.
- [x] Wire dashboard actions to live API where endpoints are available, with safe demo fallback when no API URL is configured.
- [x] Verify TypeScript, production build, and responsive browser flows.
- [x] Save a new frontend checkpoint and deliver the updated project.
