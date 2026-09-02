-- =============================================================
-- Hosting Provider CRM - Inventory Assets Sample Data
-- =============================================================
-- Run after seed.sql to populate inventory_assets table
-- =============================================================

-- Servers (3)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('SRV-001', 'SN-SRV-2024-001', 'server', 'Dell', 'PowerEdge R750', 'Dell Technologies', '2024-01-15', 12500.00, '2027-01-15', 1, 1, 1, NULL, 'installed', 'installed', 'Primary web server - Mumbai DC'),
('SRV-002', 'SN-SRV-2024-002', 'server', 'Dell', 'PowerEdge R750', 'Dell Technologies', '2024-02-20', 12500.00, '2027-02-20', 1, 1, 5, NULL, 'installed', 'installed', 'Secondary web server - Bangalore DC'),
('SRV-003', 'SN-SRV-2024-003', 'server', 'HP', 'ProLiant DL380 Gen10', 'Hewlett Packard Enterprise', '2024-03-10', 11800.00, '2027-03-10', 1, 1, 9, NULL, 'installed', 'installed', 'VPS Node - Virtualization host');

-- CPUs (6)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('CPU-001', 'SN-CPU-2024-001', 'cpu', 'Intel', 'Xeon Gold 6338', 'Intel Corporation', '2024-01-15', 2800.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'Installed in SRV-001'),
('CPU-002', 'SN-CPU-2024-002', 'cpu', 'Intel', 'Xeon Gold 6338', 'Intel Corporation', '2024-01-15', 2800.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'Installed in SRV-001'),
('CPU-003', 'SN-CPU-2024-003', 'cpu', 'Intel', 'Xeon Gold 6338', 'Intel Corporation', '2024-02-20', 2800.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'Installed in SRV-002'),
('CPU-004', 'SN-CPU-2024-004', 'cpu', 'Intel', 'Xeon Gold 6338', 'Intel Corporation', '2024-02-20', 2800.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'Installed in SRV-002'),
('CPU-005', 'SN-CPU-2024-005', 'cpu', 'AMD', 'EPYC 7543', 'Advanced Micro Devices', '2024-03-10', 3200.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'Installed in SRV-003'),
('CPU-006', 'SN-CPU-2024-006', 'cpu', 'AMD', 'EPYC 7543', 'Advanced Micro Devices', '2024-03-10', 3200.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'Installed in SRV-003');

-- RAM Modules (12)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('RAM-001', 'SN-RAM-2024-001', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-01-15', 180.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'DIMM A1 in SRV-001'),
('RAM-002', 'SN-RAM-2024-002', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-01-15', 180.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'DIMM A2 in SRV-001'),
('RAM-003', 'SN-RAM-2024-003', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-01-15', 180.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'DIMM B1 in SRV-001'),
('RAM-004', 'SN-RAM-2024-004', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-01-15', 180.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'DIMM B2 in SRV-001'),
('RAM-005', 'SN-RAM-2024-005', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-02-20', 180.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'DIMM A1 in SRV-002'),
('RAM-006', 'SN-RAM-2024-006', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-02-20', 180.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'DIMM A2 in SRV-002'),
('RAM-007', 'SN-RAM-2024-007', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-02-20', 180.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'DIMM B1 in SRV-002'),
('RAM-008', 'SN-RAM-2024-008', 'ram_module', 'Samsung', 'DDR4-3200 32GB ECC', 'Samsung Semiconductor', '2024-02-20', 180.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'DIMM B2 in SRV-002'),
('RAM-009', 'SN-RAM-2024-009', 'ram_module', 'SK Hynix', 'DDR4-3200 64GB ECC', 'SK Hynix Inc.', '2024-03-10', 350.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'DIMM A1 in SRV-003'),
('RAM-010', 'SN-RAM-2024-010', 'ram_module', 'SK Hynix', 'DDR4-3200 64GB ECC', 'SK Hynix Inc.', '2024-03-10', 350.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'DIMM A2 in SRV-003'),
('RAM-011', 'SN-RAM-2024-011', 'ram_module', 'SK Hynix', 'DDR4-3200 64GB ECC', 'SK Hynix Inc.', '2024-03-10', 350.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'DIMM B1 in SRV-003'),
('RAM-012', 'SN-RAM-2024-012', 'ram_module', 'SK Hynix', 'DDR4-3200 64GB ECC', 'SK Hynix Inc.', '2024-03-10', 350.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'DIMM B2 in SRV-003');

-- SSDs (6)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('SSD-001', 'SN-SSD-2024-001', 'ssd', 'Samsung', 'PM9A3 1.92TB NVMe', 'Samsung Semiconductor', '2024-01-15', 320.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'Boot drive in SRV-001'),
('SSD-002', 'SN-SSD-2024-002', 'ssd', 'Samsung', 'PM9A3 1.92TB NVMe', 'Samsung Semiconductor', '2024-01-15', 320.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'Data drive in SRV-001'),
('SSD-003', 'SN-SSD-2024-003', 'ssd', 'Samsung', 'PM9A3 1.92TB NVMe', 'Samsung Semiconductor', '2024-02-20', 320.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'Boot drive in SRV-002'),
('SSD-004', 'SN-SSD-2024-004', 'ssd', 'Samsung', 'PM9A3 1.92TB NVMe', 'Samsung Semiconductor', '2024-02-20', 320.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'Data drive in SRV-002'),
('SSD-005', 'SN-SSD-2024-005', 'ssd', 'Intel', 'D5-P5316 3.2TB NVMe', 'Intel Corporation', '2024-03-10', 580.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'Boot drive in SRV-003'),
('SSD-006', 'SN-SSD-2024-006', 'ssd', 'Intel', 'D5-P5316 3.2TB NVMe', 'Intel Corporation', '2024-03-10', 580.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'Data drive in SRV-003');

-- HDDs (3)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('HDD-001', 'SN-HDD-2024-001', 'hdd', 'Seagate', 'Exos X18 18TB SAS', 'Seagate Technology', '2024-01-15', 420.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'Backup storage in SRV-001'),
('HDD-002', 'SN-HDD-2024-002', 'hdd', 'Seagate', 'Exos X18 18TB SAS', 'Seagate Technology', '2024-02-20', 420.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'Backup storage in SRV-002'),
('HDD-003', 'SN-HDD-2024-003', 'hdd', 'Seagate', 'Exos X18 18TB SAS', 'Seagate Technology', '2024-03-10', 420.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'Backup storage in SRV-003');

-- NICs (6)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('NIC-001', 'SN-NIC-2024-001', 'nic', 'Intel', 'X710-DA2 10GbE', 'Intel Corporation', '2024-01-15', 280.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'Primary NIC in SRV-001'),
('NIC-002', 'SN-NIC-2024-002', 'nic', 'Intel', 'X710-DA2 10GbE', 'Intel Corporation', '2024-01-15', 280.00, '2027-01-15', NULL, NULL, NULL, 1, 'installed', 'installed', 'Secondary NIC in SRV-001'),
('NIC-003', 'SN-NIC-2024-003', 'nic', 'Intel', 'X710-DA2 10GbE', 'Intel Corporation', '2024-02-20', 280.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'Primary NIC in SRV-002'),
('NIC-004', 'SN-NIC-2024-004', 'nic', 'Intel', 'X710-DA2 10GbE', 'Intel Corporation', '2024-02-20', 280.00, '2027-02-20', NULL, NULL, NULL, 2, 'installed', 'installed', 'Secondary NIC in SRV-002'),
('NIC-005', 'SN-NIC-2024-005', 'nic', 'Mellanox', 'ConnectX-6 25GbE', 'NVIDIA Corporation', '2024-03-10', 450.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'Primary NIC in SRV-003'),
('NIC-006', 'SN-NIC-2024-006', 'nic', 'Mellanox', 'ConnectX-6 25GbE', 'NVIDIA Corporation', '2024-03-10', 450.00, '2027-03-10', NULL, NULL, NULL, 3, 'installed', 'installed', 'Secondary NIC in SRV-003');

-- Switches (2)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('SW-001', 'SN-SW-2024-001', 'switch', 'Arista', '7280R3 10GbE Switch', 'Arista Networks', '2024-01-10', 8500.00, '2027-01-10', 1, 1, 42, NULL, 'installed', 'installed', 'Top-of-rack switch - Rack A1'),
('SW-002', 'SN-SW-2024-002', 'switch', 'Arista', '7280R3 10GbE Switch', 'Arista Networks', '2024-01-10', 8500.00, '2027-01-10', 1, 1, 41, NULL, 'installed', 'installed', 'Redundant switch - Rack A1');

-- PDUs (2)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('PDU-001', 'SN-PDU-2024-001', 'pdu', 'APC', 'Switched Rack PDU AP8981', 'Schneider Electric', '2024-01-10', 1200.00, '2027-01-10', 1, 1, 42, NULL, 'installed', 'installed', 'Primary PDU - Rack A1 Left'),
('PDU-002', 'SN-PDU-2024-002', 'pdu', 'APC', 'Switched Rack PDU AP8981', 'Schneider Electric', '2024-01-10', 1200.00, '2027-01-10', 1, 1, 41, NULL, 'installed', 'installed', 'Redundant PDU - Rack A1 Right');

-- Software Licenses (4)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('LIC-001', NULL, 'software_license', 'cPanel', 'cPanel & WHM', 'cPanel Inc.', '2024-01-20', 1200.00, '2025-01-20', NULL, NULL, NULL, 1, 'assigned', 'assigned', 'cPanel license for SRV-001'),
('LIC-002', NULL, 'software_license', 'cPanel', 'cPanel & WHM', 'cPanel Inc.', '2024-02-25', 1200.00, '2025-02-25', NULL, NULL, NULL, 2, 'assigned', 'assigned', 'cPanel license for SRV-002'),
('LIC-003', NULL, 'software_license', 'CloudLinux', 'CloudLinux OS', 'CloudLinux Inc.', '2024-01-20', 800.00, '2025-01-20', NULL, NULL, NULL, 1, 'assigned', 'assigned', 'CloudLinux license for SRV-001'),
('LIC-004', NULL, 'software_license', 'CloudLinux', 'CloudLinux OS', 'CloudLinux Inc.', '2024-02-25', 800.00, '2025-02-25', NULL, NULL, NULL, 2, 'assigned', 'assigned', 'CloudLinux license for SRV-002');

-- IPv4 Addresses (6)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('IP4-001', NULL, 'ipv4_address', NULL, '103.15.50.10', 'ARIN', '2023-06-01', 0.00, NULL, 1, NULL, NULL, 1, 'assigned', 'assigned', 'Public IP for SRV-001'),
('IP4-002', NULL, 'ipv4_address', NULL, '103.15.50.20', 'ARIN', '2023-06-01', 0.00, NULL, 1, NULL, NULL, 2, 'assigned', 'assigned', 'Public IP for SRV-002'),
('IP4-003', NULL, 'ipv4_address', NULL, '103.15.50.30', 'ARIN', '2023-06-01', 0.00, NULL, 1, NULL, NULL, 3, 'assigned', 'assigned', 'Public IP for SRV-003'),
('IP4-004', NULL, 'ipv4_address', NULL, '192.168.1.10', NULL, NULL, 0.00, NULL, 1, NULL, NULL, 1, 'assigned', 'assigned', 'Private IP for SRV-001'),
('IP4-005', NULL, 'ipv4_address', NULL, '192.168.1.20', NULL, NULL, 0.00, NULL, 1, NULL, NULL, 2, 'assigned', 'assigned', 'Private IP for SRV-002'),
('IP4-006', NULL, 'ipv4_address', NULL, '192.168.1.30', NULL, NULL, 0.00, NULL, 1, NULL, NULL, 3, 'assigned', 'assigned', 'Private IP for SRV-003');

-- Domains (3)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('DOM-001', NULL, 'domain', NULL, 'hostingprovider.com', 'GoDaddy', '2023-01-15', 15.00, '2026-01-15', NULL, NULL, NULL, NULL, 'assigned', 'assigned', 'Primary company domain'),
('DOM-002', NULL, 'domain', NULL, 'hostingprovider.net', 'GoDaddy', '2023-01-15', 15.00, '2026-01-15', NULL, NULL, NULL, NULL, 'assigned', 'assigned', 'Secondary company domain'),
('DOM-003', NULL, 'domain', NULL, 'client1.com', 'Namecheap', '2024-03-20', 12.00, '2025-03-20', NULL, NULL, NULL, NULL, 'assigned', 'assigned', 'Client domain');

-- SSL Certificates (2)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('SSL-001', NULL, 'ssl_certificate', NULL, 'hostingprovider.com', 'Let''s Encrypt', '2024-06-01', 0.00, '2024-09-01', NULL, NULL, NULL, 20, 'assigned', 'assigned', 'Wildcard SSL for main domain'),
('SSL-002', NULL, 'ssl_certificate', NULL, 'client1.com', 'Comodo', '2024-03-20', 75.00, '2025-03-20', NULL, NULL, NULL, 22, 'assigned', 'assigned', 'SSL for client domain');

-- Other Hardware (1 - GPU for future AI hosting)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('GPU-001', 'SN-GPU-2024-001', 'gpu', 'NVIDIA', 'A100 80GB PCIe', 'NVIDIA Corporation', '2024-06-15', 15000.00, '2027-06-15', 1, 1, 13, NULL, 'in_stock', 'in_stock', 'GPU for AI/ML hosting - not yet installed');

-- Maintenance Record (1 example)
INSERT IGNORE INTO `inventory_assets` (`asset_tag`, `serial_number`, `asset_type`, `manufacturer`, `model`, `vendor`, `purchase_date`, `purchase_cost`, `warranty_expiry`, `datacenter_id`, `rack_id`, `rack_u_position`, `parent_asset_id`, `status`, `lifecycle_state`, `notes`) VALUES
('PDU-003', 'SN-PDU-2023-001', 'pdu', 'APC', 'Switched Rack PDU AP8981', 'Schneider Electric', '2023-01-10', 1200.00, '2026-01-10', 1, 1, 40, NULL, 'maintenance', 'maintenance', 'PDU under maintenance - firmware update in progress');

-- Summary:
-- Servers: 3 (SRV-001, SRV-002, SRV-003)
-- CPUs: 6 (2 per server)
-- RAM: 12 (4 per server)
-- SSDs: 6 (2 per server)
-- HDDs: 3 (1 per server)
-- NICs: 6 (2 per server)
-- Switches: 2 (Arista 10GbE)
-- PDUs: 3 (2 active + 1 in maintenance)
-- Software Licenses: 4 (2 cPanel + 2 CloudLinux)
-- IPv4 Addresses: 6 (3 public + 3 private)
-- Domains: 3
-- SSL Certificates: 2
-- GPU: 1 (in stock)
-- TOTAL: 57 assets
