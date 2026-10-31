# SkillBridge Frontend ↔ Laravel API

The frontend reads the Laravel backend URL from `VITE_API_BASE_URL`. Create a local-only `.env.local` file in this frontend project root:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000
```

Run the Laravel backend in one terminal:

```bash
cd path/to/skillbridge
php artisan serve
```

Run the frontend in another terminal:

```bash
cd path/to/skillbridge-frontend
pnpm install
pnpm dev
```

The current supplied Laravel snapshot exposes register, login, logout, `/api/me`, profile, skills, jobs, and bids. The frontend uses those routes directly with a Sanctum bearer token. Contract, messaging, review, notification, verification, donation, admin, and matching screens should remain in fallback mode until their corresponding backend routes are present in the same Laravel project.

If the browser blocks requests, configure Laravel CORS for the frontend origin and restart the Laravel server. Do not commit `.env.local` or access tokens.
