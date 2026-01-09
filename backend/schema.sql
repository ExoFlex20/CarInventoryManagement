-- Schema for Car Inventory System
CREATE DATABASE IF NOT EXISTS car_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE car_inventory;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_name VARCHAR(120),
    phone VARCHAR(50),
    email VARCHAR(120),
    address TEXT
);

CREATE TABLE IF NOT EXISTS parts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    quantity INT NOT NULL DEFAULT 0,
    reorder_level INT DEFAULT 5,
    price DECIMAL(10,2),
    supplier_id INT,
    barcode VARCHAR(120) UNIQUE,
    location VARCHAR(120),
    lead_time_days INT DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_parts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT chk_parts_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_parts_reorder CHECK (reorder_level >= 0),
    CONSTRAINT chk_parts_price CHECK (price IS NULL OR price >= 0)
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'staff'
);

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_id INT NOT NULL,
    change_type ENUM('in','out') NOT NULL,
    quantity INT NOT NULL,
    note VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT,
    CONSTRAINT fk_stock_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_stock_qty CHECK (quantity >= 0)
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT,
    status ENUM('draft','ordered','received','cancelled') NOT NULL DEFAULT 'draft',
    expected_date DATE,
    notes TEXT,
    created_by INT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_po_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    part_id INT NOT NULL,
    qty_ordered INT NOT NULL,
    qty_received INT NOT NULL DEFAULT 0,
    price DECIMAL(10,2),
    CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE,
    CONSTRAINT chk_poi_qty_ordered CHECK (qty_ordered >= 0),
    CONSTRAINT chk_poi_qty_received CHECK (qty_received >= 0)
);

CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_id INT NOT NULL,
    reserved_qty INT NOT NULL,
    status ENUM('open','fulfilled','cancelled') NOT NULL DEFAULT 'open',
    reference_code VARCHAR(120),
    note VARCHAR(255),
    created_by INT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_res_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE,
    CONSTRAINT fk_res_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_res_qty CHECK (reserved_qty >= 0)
);

CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('part','supplier','purchase_order') NOT NULL,
    entity_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS app_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    context TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Sample data
INSERT INTO suppliers (name, contact_name, phone, email, address) VALUES
('OEM Parts Co', 'Lara Mendoza', '+1 415 555 0188', 'orders@oemparts.example', '200 Industrial Rd'),
('QuickFit Auto', 'Samir Patel', '+1 415 555 0199', 'samir@quickfit.example', '44 Harbor Ave'),
('DriveLine Wholesale', 'Mei Zhou', '+1 206 555 0105', 'sales@driveline.example', '88 Cascade St'),
('TurboSpare', 'Jonas Eriksen', '+1 801 555 0144', 'support@turbospare.example', '512 Mountain Pkwy'),
('GreenLight Auto', 'Veronica Ruiz', '+1 312 555 0167', 'hello@greenlight.example', '903 Lake Shore Dr'),
('Metro Motors', 'Chris Osei', '+1 718 555 0122', 'orders@metromotors.example', '12 Queens Blvd'),
('Northwind Parts', 'Rina Takeda', '+1 917 555 0150', 'rtakeda@northwind.example', '75 Hudson Ave'),
('AllTrack Supply', 'Owen Price', '+1 415 555 0203', 'ops@alltrack.example', '240 Sunset Ave'),
('Prime Rotor', 'Fatima Khan', '+1 916 555 0191', 'fkhan@primerotor.example', '650 Capital Way'),
('AxleWorks', 'David Kim', '+1 503 555 0110', 'd.kim@axleworks.example', '41 Bridge St');

INSERT INTO parts (name, sku, description, quantity, reorder_level, price, supplier_id) VALUES
('Brake Pad Set', 'BP-001', 'Front brake pad set', 18, 10, 79.99, 1),
('Oil Filter', 'OF-002', 'Standard oil filter', 45, 20, 9.49, 2),
('Spark Plug', 'SP-003', 'Copper spark plug', 60, 30, 4.99, 2),
('Air Filter', 'AF-004', 'Cabin air filter', 12, 8, 19.99, 1),
('Fuel Pump Assembly', 'FP-005', 'Electric fuel pump', 20, 8, 139.00, 3),
('Alternator 120A', 'AL-006', '120A alternator', 14, 6, 199.00, 3),
('Starter Motor', 'SM-007', 'High-torque starter', 10, 4, 179.00, 4),
('Timing Belt Kit', 'TB-008', 'Timing belt with tensioner', 22, 10, 129.00, 4),
('Water Pump', 'WP-009', 'Aluminum housing water pump', 30, 12, 89.00, 5),
('Radiator', 'RA-010', 'Aluminum core radiator', 8, 4, 229.00, 5),
('AC Compressor', 'AC-011', '7-groove AC compressor', 9, 3, 349.00, 6),
('Condensor', 'AC-012', 'AC condensor assembly', 11, 4, 189.00, 6),
('Heater Core', 'HC-013', 'Heater core copper', 16, 6, 119.00, 6),
('Power Steering Pump', 'PS-014', 'Hydraulic pump', 13, 5, 159.00, 7),
('Rack and Pinion', 'RP-015', 'Reman steering rack', 7, 3, 399.00, 7),
('Tie Rod End', 'TR-016', 'Front tie rod end', 40, 18, 24.00, 7),
('Ball Joint', 'BJ-017', 'Lower ball joint', 35, 15, 32.50, 7),
('Control Arm', 'CA-018', 'Front lower control arm', 18, 8, 119.00, 8),
('Sway Bar Link', 'SB-019', 'Stabilizer link', 44, 18, 18.00, 8),
('Shock Absorber Front', 'SH-020', 'Gas front shock', 25, 10, 99.00, 8),
('Shock Absorber Rear', 'SH-021', 'Gas rear shock', 27, 10, 92.00, 8),
('Coil Spring', 'CS-022', 'Rear coil spring', 15, 6, 79.00, 8),
('Wheel Bearing Hub', 'WB-023', 'Front wheel hub with bearing', 19, 8, 139.00, 9),
('CV Axle LH', 'CV-024', 'Left front CV axle', 12, 5, 189.00, 9),
('CV Axle RH', 'CV-025', 'Right front CV axle', 12, 5, 189.00, 9),
('U-Joint', 'UJ-026', 'Universal joint', 28, 12, 26.00, 9),
('Drive Shaft Rear', 'DS-027', 'Rear driveshaft assembly', 6, 2, 449.00, 9),
('Clutch Kit', 'CK-028', 'Clutch disc, plate, bearing', 14, 6, 299.00, 10),
('Flywheel', 'FW-029', 'Dual mass flywheel', 8, 3, 379.00, 10),
('Slave Cylinder', 'SC-030', 'Hydraulic slave cylinder', 20, 8, 64.00, 10),
('Master Cylinder', 'MC-031', 'Clutch master cylinder', 22, 8, 69.00, 10),
('Brake Rotor Front', 'BR-032', 'Vented rotor front', 32, 14, 59.00, 1),
('Brake Rotor Rear', 'BR-033', 'Solid rotor rear', 30, 14, 49.00, 1),
('Brake Caliper LF', 'BC-034', 'Left front caliper reman', 11, 4, 139.00, 1),
('Brake Caliper RF', 'BC-035', 'Right front caliper reman', 11, 4, 139.00, 1),
('Brake Caliper LR', 'BC-036', 'Left rear caliper reman', 12, 4, 129.00, 1),
('Brake Caliper RR', 'BC-037', 'Right rear caliper reman', 12, 4, 129.00, 1),
('ABS Sensor Front', 'AS-038', 'Front wheel speed sensor', 26, 10, 42.00, 2),
('ABS Sensor Rear', 'AS-039', 'Rear wheel speed sensor', 24, 10, 39.00, 2),
('Oxygen Sensor Upstream', 'O2-040', 'Upstream O2 sensor', 28, 12, 55.00, 2),
('Oxygen Sensor Downstream', 'O2-041', 'Downstream O2 sensor', 26, 12, 52.00, 2),
('MAP Sensor', 'MS-042', 'Manifold absolute pressure sensor', 18, 8, 68.00, 3),
('MAF Sensor', 'MF-043', 'Mass air flow sensor', 16, 6, 119.00, 3),
('Ignition Coil', 'IC-044', 'Pencil ignition coil', 42, 16, 38.00, 3),
('Throttle Body', 'TB-045', 'Electronic throttle body', 10, 4, 219.00, 3),
('EGR Valve', 'EGR-046', 'EGR valve assembly', 13, 6, 159.00, 4),
('Fuel Injector', 'FI-047', 'High impedance injector', 36, 14, 62.00, 4),
('Fuel Rail Pressure Sensor', 'FR-048', 'Rail pressure sensor', 18, 8, 79.00, 4),
('Intake Gasket Set', 'IG-049', 'Intake manifold gasket kit', 40, 18, 24.00, 5),
('Head Gasket Set', 'HG-050', 'MLS head gasket kit', 9, 3, 189.00, 5),
('Valve Cover Gasket', 'VC-051', 'Rubber valve cover gasket', 38, 16, 21.00, 5),
('Serpentine Belt', 'SB-052', '7PK serpentine belt', 55, 22, 29.00, 6),
('Belt Tensioner', 'BT-053', 'Automatic belt tensioner', 22, 9, 79.00, 6),
('Idler Pulley', 'IP-054', 'Idler pulley with bearing', 28, 12, 34.00, 6),
('Radiator Hose Upper', 'RH-055', 'Upper radiator hose', 34, 14, 22.00, 6),
('Radiator Hose Lower', 'RH-056', 'Lower radiator hose', 34, 14, 22.00, 6),
('Thermostat', 'TH-057', 'Thermostat with housing', 30, 12, 35.00, 6),
('Coolant Reservoir', 'CR-058', 'Expansion tank', 16, 6, 54.00, 6),
('Wiper Blade 22"', 'WB-059', 'All-season 22 inch', 60, 24, 12.00, 7),
('Wiper Blade 24"', 'WB-060', 'All-season 24 inch', 60, 24, 13.00, 7),
('Headlight Bulb H11', 'HL-061', 'Halogen H11 bulb', 80, 30, 11.00, 7),
('Headlight Bulb 9005', 'HL-062', 'Halogen 9005 bulb', 80, 30, 11.00, 7),
('LED Fog Light Kit', 'FL-063', 'LED fog light pair', 22, 8, 79.00, 7),
('Battery 65 Group', 'BT-064', '12V AGM battery group 65', 14, 6, 179.00, 8),
('Battery 35 Group', 'BT-065', '12V AGM battery group 35', 16, 6, 169.00, 8),
('Wheel Lug Nut', 'LN-066', 'Chrome lug nut', 120, 60, 1.10, 8),
('Wheel Stud', 'WS-067', 'Press-in wheel stud', 90, 40, 2.80, 8),
('Hub Cap 16"', 'HC-068', '16 inch hub cap', 24, 8, 18.00, 9),
('Tire Pressure Sensor', 'TP-069', 'Programmable TPMS sensor', 40, 18, 49.00, 9),
('Jack Kit', 'JK-070', 'Scissor jack with wrench', 12, 5, 79.00, 10),
('Emergency Triangle', 'ET-071', 'Reflective warning triangle', 28, 12, 15.00, 10),
('First Aid Kit', 'FA-072', 'Vehicle first aid kit', 32, 12, 19.00, 10),
('Fire Extinguisher', 'FE-073', '2.5 lb ABC extinguisher', 18, 6, 36.00, 10),
('Floor Mat Set', 'FM-074', 'All-weather floor mats', 20, 8, 69.00, 10);

-- Sample purchase orders
INSERT INTO purchase_orders (supplier_id, status, expected_date, notes, created_by) VALUES
((SELECT id FROM suppliers WHERE name = 'OEM Parts Co'), 'ordered', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Brake and rotor replenishment', 1),
((SELECT id FROM suppliers WHERE name = 'GreenLight Auto'), 'ordered', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Sensors and electrics', 1),
((SELECT id FROM suppliers WHERE name = 'AllTrack Supply'), 'draft', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'Chassis hardware', 1);

INSERT INTO purchase_order_items (purchase_order_id, part_id, qty_ordered, qty_received, price)
SELECT 1, id, 24, 0, 55.00 FROM parts WHERE sku = 'BR-032'
UNION ALL SELECT 1, id, 24, 0, 49.00 FROM parts WHERE sku = 'BR-033'
UNION ALL SELECT 1, id, 40, 0, 82.00 FROM parts WHERE sku = 'BP-001'
UNION ALL SELECT 2, id, 30, 0, 37.00 FROM parts WHERE sku = 'IC-044'
UNION ALL SELECT 2, id, 18, 0, 118.00 FROM parts WHERE sku = 'MF-043'
UNION ALL SELECT 2, id, 18, 0, 72.00 FROM parts WHERE sku = 'MS-042'
UNION ALL SELECT 3, id, 50, 0, 0.95 FROM parts WHERE sku = 'LN-066'
UNION ALL SELECT 3, id, 40, 0, 2.40 FROM parts WHERE sku = 'WS-067'
UNION ALL SELECT 3, id, 30, 0, 17.00 FROM parts WHERE sku = 'SB-019';

-- Sample reservations
INSERT INTO reservations (part_id, reserved_qty, status, reference_code, note, created_by)
SELECT id, 4, 'open', 'JOB-101', 'Front brake job', 1 FROM parts WHERE sku = 'BP-001'
UNION ALL SELECT id, 2, 'open', 'JOB-102', 'Rotor replacement', 1 FROM parts WHERE sku = 'BR-032'
UNION ALL SELECT id, 6, 'open', 'JOB-103', 'Tune-up kit', 1 FROM parts WHERE sku = 'SP-003'
UNION ALL SELECT id, 3, 'open', 'JOB-104', 'Suspension refresh', 1 FROM parts WHERE sku = 'SH-020';

-- Sample stock movements
INSERT INTO stock_movements (part_id, change_type, quantity, note, user_id)
SELECT id, 'in', 20, 'Initial receipt', 1 FROM parts WHERE sku = 'BP-001'
UNION ALL SELECT id, 'in', 40, 'Initial receipt', 1 FROM parts WHERE sku = 'OF-002'
UNION ALL SELECT id, 'in', 30, 'Initial receipt', 1 FROM parts WHERE sku = 'SP-003'
UNION ALL SELECT id, 'out', 6, 'Prep for JOB-101', 1 FROM parts WHERE sku = 'BP-001'
UNION ALL SELECT id, 'out', 2, 'Prep for JOB-102', 1 FROM parts WHERE sku = 'BR-032'
UNION ALL SELECT id, 'out', 6, 'Prep for JOB-103', 1 FROM parts WHERE sku = 'SP-003'
UNION ALL SELECT id, 'in', 25, 'Restock shocks', 1 FROM parts WHERE sku = 'SH-020';

-- Sample attachments metadata
INSERT INTO attachments (entity_type, entity_id, file_name, file_url, mime_type)
SELECT 'part', id, 'bp-001-spec.pdf', 'https://example.com/specs/bp-001.pdf', 'application/pdf' FROM parts WHERE sku = 'BP-001'
UNION ALL SELECT 'part', id, 'mf-043-datasheet.pdf', 'https://example.com/specs/mf-043.pdf', 'application/pdf' FROM parts WHERE sku = 'MF-043'
UNION ALL SELECT 'purchase_order', 1, 'po-1.pdf', 'https://example.com/po/po-1.pdf', 'application/pdf';

INSERT INTO users (username, password_hash, role) VALUES
('admin', '$2y$10$rnoUqOx1qB5ZrXeknc7jiONFb91Bnkw2NbalaXot4bLA7MPPV2bNO', 'admin')
ON DUPLICATE KEY UPDATE username = username;
