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
4. **Thin Controllers:** Offload complex business logic to Service classes where appropriate.
