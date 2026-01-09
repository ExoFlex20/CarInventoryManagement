# PROJECT FOR CC105 and DC101 

Jinlord Patrick B. Fule
BSCS 2C

# Car Parts Inventory System

Web-based inventory for automotive parts. Built with PHP + MySQL (XAMPP stack) and a lightweight HTML/CSS/JS frontend.

## Stack
- PHP 8+ (runs under XAMPP/Apache)
- MySQL (Workbench for imports)
- Vanilla HTML/CSS/JS

## Quick start
1. Copy project into `C:/xampp/htdocs/CarInventorySystem` (already here).
2. Import database: open MySQL Workbench and run `backend/schema.sql` (creates database `car_inventory` with sample data and admin user `admin` / `admin123`).
3. Configure credentials: duplicate `backend/.env.example` to `backend/.env` and set `DB_*` values and `ALLOWED_ORIGIN` if the frontend is hosted elsewhere.
4. Start XAMPP Apache and MySQL services.
5. Visit `http://localhost/CarInventorySystem/public/index.html` to use the UI. API lives at `http://localhost/CarInventorySystem/backend/index.php`.

## API routes (high level)
- `GET /health` — ping
- Auth: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` (Bearer token)
- Parts
  - `GET /parts?search=&supplier_id=&low_only=1&active=0&page=&page_size=`
  - `GET /parts/{id}`
  - `POST /parts` create/update with barcode, location, lead_time_days, is_active
  - `PUT /parts/{id}`
  - `DELETE /parts/{id}` (admin)
  - `GET /parts/export` CSV, `POST /parts/import` CSV body `{csv}` (admin)
- Suppliers: CRUD at `/suppliers`
- Stock: `POST /stock/in|out` (respects open reservations); `GET /stock/movements?limit=&part_id=`
- Reservations: `GET /reservations`, `POST /reservations`, `PUT /reservations/{id}/status` (fulfill triggers stock out)
- Purchase orders: `GET /purchase-orders`, `GET /purchase-orders/{id}`, `POST /purchase-orders` (items inline), `PUT /purchase-orders/{id}` update status/expected, `PUT /purchase-orders/{id}/receive` to receive items and stock-in
- Alerts: `GET /alerts/low`
- Attachments metadata: `GET /attachments/{entity}/{id}`, `POST /attachments`
- Reports: `GET /reports/summary`, `GET /reports/slow-movers`

Headers: `Content-Type: application/json`. CORS origin is `*` by default; adjust via `ALLOWED_ORIGIN`.

## Files of interest
- Backend router: `backend/index.php`
- DB config/helpers: `backend/config.php`
- Schema + seed: `backend/schema.sql`
- Frontend UI: `public/index.html`, `public/styles.css`, `public/script.js`

## Notes
- Low stock = `quantity <= reorder_level`.
- Stock-out respects open reservations; fulfilling a reservation deducts stock.
- Purchase order receiving stocks items in and logs movements.
- Import/export is CSV text-based; admin auth required for import.
- Default admin user: `admin` / `admin123` (change in DB for production).
