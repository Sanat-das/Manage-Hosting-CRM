-- =============================================================
-- Hosting Provider CRM - Devices Sample Data
-- =============================================================
-- Run after reference-seed.sql to populate devices table
-- Only hardware (server / switch / pdu) is seeded; licenses and domains
-- live in their own modules. (Manufacturer, model) pairs here map onto
-- device types resolved or created by app:import-reference-data.
-- =============================================================

-- Servers (3)
INSERT IGNORE INTO `devices` (`asset_tag`, `serial_number`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `status`, `lifecycle_state`, `notes`) VALUES
('SRV-001', 'SN-SRV-2024-001', 'Dell', 'PowerEdge R750', 'Dell Technologies', '2024-01-15', 12500.00, '2027-01-15', 1, 1, 1, 'installed', 'installed', 'Primary web server - Mumbai DC'),
('SRV-002', 'SN-SRV-2024-002', 'Dell', 'PowerEdge R750', 'Dell Technologies', '2024-02-20', 12500.00, '2027-02-20', 1, 1, 5, 'installed', 'installed', 'Secondary web server - Bangalore DC'),
('SRV-003', 'SN-SRV-2024-003', 'HP', 'ProLiant DL380 Gen10', 'Hewlett Packard Enterprise', '2024-03-10', 11800.00, '2027-03-10', 1, 1, 9, 'installed', 'installed', 'VPS Node - Virtualization host');

-- Switches (2)
INSERT IGNORE INTO `devices` (`asset_tag`, `serial_number`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `status`, `lifecycle_state`, `notes`) VALUES
('SW-001', 'SN-SW-2024-001', 'Arista', '7280R3 10GbE Switch', 'Arista Networks', '2024-01-10', 8500.00, '2027-01-10', 1, 1, 42, 'installed', 'installed', 'Top-of-rack switch - Rack A1'),
('SW-002', 'SN-SW-2024-002', 'Arista', '7280R3 10GbE Switch', 'Arista Networks', '2024-01-10', 8500.00, '2027-01-10', 1, 1, 41, 'installed', 'installed', 'Redundant switch - Rack A1');

-- PDUs (3; 2 active + 1 in maintenance)
INSERT IGNORE INTO `devices` (`asset_tag`, `serial_number`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `status`, `lifecycle_state`, `notes`) VALUES
('PDU-001', 'SN-PDU-2024-001', 'APC', 'Switched Rack PDU AP8981', 'Schneider Electric', '2024-01-10', 1200.00, '2027-01-10', 1, 1, 42, 'installed', 'installed', 'Primary PDU - Rack A1 Left'),
('PDU-002', 'SN-PDU-2024-002', 'APC', 'Switched Rack PDU AP8981', 'Schneider Electric', '2024-01-10', 1200.00, '2027-01-10', 1, 1, 41, 'installed', 'installed', 'Redundant PDU - Rack A1 Right');

INSERT IGNORE INTO `devices` (`asset_tag`, `serial_number`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `status`, `lifecycle_state`, `notes`) VALUES
('PDU-003', 'SN-PDU-2023-001', 'APC', 'Switched Rack PDU AP8981', 'Schneider Electric', '2023-01-10', 1200.00, '2026-01-10', 1, 1, 40, 'maintenance', 'maintenance', 'PDU under maintenance - firmware update in progress');

-- Summary:
-- Servers: 3 (SRV-001, SRV-002, SRV-003)
-- Switches: 2 (Arista 7280R3 10GbE)
-- PDUs: 3 (2 active + 1 in maintenance)
-- TOTAL: 8 hardware assets
