# EERC LMS API (Laravel 11)

PHP API placed **next to the React app**: repo root has `src/` (Vite) and `backend/` (Laravel), so URLs and env stay easy to wire.

## Requirements

- PHP **8.2+** and [Composer](https://getcomposer.org/)

## Setup

```bash
cd backend
composer install
copy .env.example .env   # or: cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

### Auth (Laravel Sanctum — Bearer tokens)

| Method | Path | Body | Response |
| --- | --- | --- | --- |
| `POST` | `/api/register` | `name`, `email`, `password`, `password_confirmation` | `201` — `user` (LMS shape), `token`, `token_type: "Bearer"` |
| `POST` | `/api/signup` | same as register | alias of register |
| `POST` | `/api/login` | `email`, `password` | `200` — same as register; **revokes all prior tokens** so only this device stays signed in |
| `POST` | `/api/logout` | *(requires `Authorization: Bearer {token}`)* | `200` — message |

New users get `role: student`, a fresh `public_uid`, and a minimal `lms_user_profiles` row.

**All LMS JSON routes below require a valid Sanctum token** (`Authorization: Bearer {token}`), except `GET /api/health` and the login/register/signup routes above. Data is scoped to the authenticated user (no `LMS_ACTOR_PUBLIC_UID` at runtime for HTTP).

### Vite / React (Sanctum)

After a successful `POST /api/login` or `/api/register`, store the returned `token` and attach it to API calls. This repo’s axios instance reads (in order) `sessionStorage` keys `lms_sanctum_token` (set via `setLmsSanctumSession` in `src/lib/lms-sanctum-session.js`) then the JWT demo key, and sends `Authorization: Bearer …` on each request.

## Modular JSON API

Read endpoints return **one resource** each (relational ids only, no nested entities). List responses use `{ "data": [...] }` except `GET /api/user`, `GET /api/meta`, `GET /api/analytics`, and `GET /api/admin`, which return a single object. Paginated courses: `{ "data": [...], "meta": { "page", "limit", "total", "lastPage" } }`.

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/api/health` | **Public** |
| `GET` | `/api/user` | **Auth** — current user (LMS profile shape) |
| `GET` | `/api/meta` | **Auth** |
| `GET` | `/api/programs` | **Auth** |
| `GET` | `/api/courses?page=1&limit=20&status=published` | **Auth** — pagination (`limit` max 100); optional `status`: `published`, `draft`, `upcoming` |
| `GET` | `/api/enrollments` | **Auth** |
| `GET` | `/api/modules?courseId={publicId}` | **Auth** — or `?ids=id1,id2` |
| `GET` | `/api/quizzes` | **Auth** — optional `moduleId` |
| `GET` | `/api/quiz-results` | **Auth** — optional `userId` (must be you) |
| `GET` | `/api/leaderboard?type=daily\|weekly\|overall` | **Auth** — cached + `Cache-Control: stale-while-revalidate` |
| `GET` | `/api/analytics` | **Auth** — cached per user |
| `GET` | `/api/admin` | **Auth** — `{ "users": [], "uploads": [] }` |
| `GET` | `/api/notifications` | **Auth** — bell drawer list (`{ "data": [...] }`). Items include `title` (HTML), optional `message` (announcement body HTML), `category`, `type`, `isUnRead`, `createdAt`, `announcementId`. |
| `PATCH` | `/api/notifications/read-all` | **Auth** — mark all as read |
| `PATCH` | `/api/notifications/{publicId}/read` | **Auth** — mark one as read (`read_at` set); response `{ "ok", "isUnRead": false }` |
| `PATCH` | `/api/notifications/{publicId}/unread` | **Auth** — mark one as unread (`read_at` cleared); response `{ "ok", "isUnRead": true }` |

Writes (same shapes the SPA sagas expect; **all require auth** — acting user is the Bearer token’s user):

| Method | Path |
| --- | --- |
| `POST` | `/api/announcements` — **Auth** + page permission `/announcement` (instructor/admin) — body `{ "title", "body" }` (`body` optional HTML). Saves the announcement and creates one in-app notification per distinct user with an **approved** program enrollment (excluding the author). Response: `{ "id", "title", "recipientCount" }`. |
| `POST` | `/api/enrollments` — body `{ "course_id": "course-ce-review" }` |
| `PATCH` | `/api/enrollments/{publicId}` — body `{ "status": "approved" }` |
| `GET` | `/api/quizzes/{publicId}/questions` |
| `POST` | `/api/quizzes/{publicId}/attempts` |
| `PATCH` | `/api/modules/{publicId}/visibility` |
| `POST` | `/api/admin/uploads` — body `{ "title", "assetType" }` |

Domain logic: `App\Services\LmsCatalogService`. There is **no** combined bootstrap endpoint.

### CORS

Set `CORS_ALLOWED_ORIGINS` in `.env` to your Vite dev origin(s). Defaults include `3030`, `3033`, and `5173`.

### Vite / React

Set the repo root `.env` value `VITE_SERVER_URL=http://127.0.0.1:8000` (same origin as `php artisan serve`) so the SPA uses `src/services/api.js` + SWR against Laravel. Leave it empty to keep mock data in `src/services/lms.service.js` (no token needed for mocks).

With Laravel enabled, **log in first** (`POST /api/login`), then call `setLmsSanctumSession(token)` from `src/lib/lms-sanctum-session.js` so axios sends the Bearer token (see Sanctum section above).

### MySQL (optional)

Set `DB_CONNECTION=mysql` and `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env`, then `php artisan migrate --seed`.

### Import bundled SQL dump (MySQL)

`database/database.mysql` creates the **`eerc_lms`** schema matching Laravel migrations: **bigint auto-increment primary keys**, stable **`public_id`** strings on programs, courses, modules, quizzes, quiz attempts, enrollments, and admin uploads (aligned with `src/services/lms.service.js`). **`FOREIGN KEY`** constraints match Eloquent `constrained()` usage; **`courses.next_module_id`** is indexed only (no FK), like the migrations.

Import from the `backend` folder:

```bash
mysql -u root -p < database/database.mysql
```

Seeded **users** use password **`password`** (bcrypt hash in file). Point `.env` at `DB_DATABASE=eerc_lms` after import.

## Schema (normalized)

| Table | Purpose |
| --- | --- |
| `users` | Auth; `public_uid` matches SPA-facing ids; `role` (admin / instructor / student) |
| `lms_user_profiles` | Streak, watermark, active `program_id`, `session_warning`, `joined_at` |
| `user_badges` | Badge key + label per user |
| `programs` | Catalog programs (integer PK + unique **`public_id`**, `code`, `title`) |
| `courses` | Belongs to program; `slug`, stats, optional `video_hours_label`, `preview_completed` |
| `tags`, `subjects` | Normalized labels; `course_tag`, `course_subject` pivots |
| `modules` | Course sections; visibility, streaming, learning-flow step |
| `module_resources` | `format` per row (Video, PDF, eBook, …) |
| `quizzes` | Linked to course + optional module; attempt limits + pool sizes |
| `questions`, `question_options` | Normalized item bank (seed optional) |
| `user_module_progress` | Per-user progress % and last position per module |
| `quiz_attempts` | Per-user quiz runs |
| `enrollments` | User ↔ course + `status` |
| `leaderboard_entries` | Rows per `period` (daily / weekly / overall) + rank |
| `admin_uploads` | Instructor/admin asset queue |

Laravel system tables: `cache`, `jobs`, `sessions`, `personal_access_tokens`, etc.

## Next steps for the SPA

1. Add **Laravel Sanctum** session or token auth and protect mutating routes.
2. Map authenticated `User` to `LmsCatalogService` / controllers instead of `LMS_ACTOR_PUBLIC_UID`.
