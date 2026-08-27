# Antigravity Global Directives for robin-it

## Autonomous Execution
The user has explicitly granted "auto allow" permission for the StarTech E-Commerce Platform development.
- The agent is authorized to automatically proceed with the Master Implementation Plan.
- Do NOT block execution on user approval for implementation plans unless it is a critical, irreversible breaking change.
- Set `RequestFeedback: false` when generating artifacts to maintain continuous execution momentum.

## Architectural Rules

1. **Monolith First:** Maintain the Laravel + React (Inertia.js) monolith.

2. **RESTful APIs:** Build structured `/api/*` routes for all data interactions (PLP, Mega Menu, Checkout).

3. **Vanilla CSS:** Use the custom Design System. Do not use Tailwind classes.
   Tailwind is not installed — `postcss.config.js` runs autoprefixer only. Every
   border-radius must be a `--radius` token; `npm run lint:radius` enforces it.

4. **Thin Controllers:** Offload complex business logic to Service classes.
   A controller resolves the request, calls a service, and shapes a response.

5. **One write surface for the admin:** admin writes are declared **only** under
   `/api/admin/*` (routes/api.php) and answer with the JSON envelope. Routes
   under `/admin` are Inertia page renders and are GET-only. Both used to be
   registered for every write, which is why controllers carried
   `wantsJson() || is('api/*') || ajax()` branches. `AdminRouteSurfaceTest`
   fails if that comes back.

6. **A controller per admin section:** `Admin/ProductController`,
   `Admin/CouponController`, and so on — not one class for the whole dashboard.

7. **Validation lives in FormRequests** (`app/Http/Requests/Admin/*`), not in
   inline `$request->validate()` blocks that drift between create and edit.

8. **Stock moves only through `StockService`.** `stock_quantity` is a cached
   ledger balance; nothing else may write it.

9. **Settings are an allowlist.** `SiteSetting::GROUPS` is the complete set of
   keys the admin may write, and only the public groups reach the browser.
   Adding a setting means adding it there first.

10. **Rich text is sanitised on write.** Blog content and product descriptions
    are rendered with `dangerouslySetInnerHTML`, so they pass through
    `RichText::clean()` before they are stored — never on the way out.

11. **Ownership lives in policies** (`app/Policies`), not in repeated
    `->where('user_id', $user->id)` clauses.

## Frontend Rules

- **Persistent layouts.** A page declares `Page.layout = mainLayout`; it never
  renders `<MainLayout>` inside its own tree, which would rebuild the header —
  and refetch the mega menu, settings and cart count — on every navigation.
- **Import components by path** (`@/Components/Button`). There is deliberately
  no `Components/index.js` barrel: it forced a single 126 kB chunk containing
  every component onto first load.
- **Data already on the page comes from Inertia props**, not a second fetch.
  `site_settings`, `auth.user`, `flash` and `showroom_count` are shared props.
- **Any effect that fetches must be cancellable**, so a slower earlier response
  cannot overwrite a newer one.

## Checks

`npm run lint`, `npm test`, `npm run lint:radius`, `./vendor/bin/pint --test`,
`npx prettier --check "resources/js/**/*.{js,jsx,css}"`, `php artisan test`.
CI runs all of them, on SQLite and MySQL.
