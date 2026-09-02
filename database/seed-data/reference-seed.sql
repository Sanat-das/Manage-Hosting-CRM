-- =============================================================
-- Hosting Provider CRM - Demo Data Seed (20 records)
-- =============================================================
-- Password for ALL demo accounts: password
-- bcrypt hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- =============================================================

-- 1. users (2 staff + 20 clients)
INSERT IGNORE INTO `users` (`id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `phone`, `company`, `address`, `status`, `last_login_at`) VALUES
(2,  'staff@demo.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff',  'Priya',  'Sharma',  '+91-9876543210', NULL, NULL, 'active', '2026-06-12 09:30:00'),
(3,  'staff2@demo.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff',  'Rahul',  'Verma',   '+91-9876543211', NULL, NULL, 'active', '2026-06-12 10:15:00'),
(4,  'client1@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Amit',   'Kumar',   '+91-9876543212', 'TechSolutions Pvt Ltd', '42, MG Road, Bangalore 560001', 'active', '2026-06-11 14:20:00'),
(5,  'client2@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Sunita', 'Patel',   '+91-9876543213', 'WebCraft Agency', '7B, Park Street, Kolkata 700016', 'active', '2026-06-10 11:45:00'),
(6,  'client3@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Vikram', 'Singh',   '+91-9876543214', 'Singh Enterprises', '15, Connaught Place, Delhi 110001', 'active', '2026-06-09 10:00:00'),
(7,  'client4@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Neha',   'Gupta',   '+91-9876543215', NULL, '88, CP Colony, Mumbai 400001', 'active', '2026-06-08 09:00:00'),
(8,  'client5@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Rajesh', 'Iyer',    '+91-9876543216', 'Iyer Tech Labs', '22, Anna Salai, Chennai 600002', 'active', '2026-06-12 16:00:00'),
(9,  'client6@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Meera',  'Nair',    '+91-9876543217', 'Kerala Web Solutions', '5, MG Road, Kochi 682016', 'active', '2026-06-11 12:00:00'),
(10, 'client7@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Sanjay', 'Mishra',  '+91-9876543218', 'Mishra Digital', '9, Hazratganj, Lucknow 226001', 'active', '2026-06-10 08:00:00'),
(11, 'client8@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Kavita', 'Desai',   '+91-9876543219', NULL, '33, FC Road, Pune 411004', 'active', '2026-06-09 15:30:00'),
(12, 'client9@demo.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Arun',   'Tiwari',  '+91-9876543220', 'Tiwari Enterprises', '18, Civil Lines, Jaipur 302006', 'active', '2026-06-12 11:00:00'),
(13, 'client10@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Pooja',  'Sinha',   '+91-9876543221', 'Patna Web Hub', '7, Boring Road, Patna 800001', 'active', '2026-06-08 14:00:00'),
(14, 'client11@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Manoj',  'Yadav',   '+91-9876543222', NULL, '45, MG Road, Hyderabad 500001', 'active', '2026-06-11 10:00:00'),
(15, 'client12@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Divya',  'Rao',     '+91-9876543223', 'Divya Designs', '12, Jubilee Hills, Hyderabad 500033', 'active', '2026-06-12 13:00:00'),
(16, 'client13@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Suresh', 'Babu',    '+91-9876543224', 'Chennai Hosting Co', '88, T Nagar, Chennai 600017', 'active', '2026-06-10 09:30:00'),
(17, 'client14@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Anjali', 'Menon',   '+91-9876543225', NULL, '16, MG Road, Trivandrum 695001', 'active', '2026-06-09 11:00:00'),
(18, 'client15@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Karthik','Subramanian','+91-9876543226', 'KSoft Solutions', '29, Velachery, Chennai 600042', 'active', '2026-06-13 08:15:00'),
(19, 'client16@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Nisha',  'Choudhary','+91-9876543227', 'Nisha Creations', '3, MI Road, Jaipur 302001', 'active', '2026-06-11 14:30:00'),
(20, 'client17@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Vivek',  'Gupta',   '+91-9876543228', 'Vivek IT Services', '67, Salt Lake, Kolkata 700091', 'active', '2026-06-12 10:30:00'),
(21, 'client18@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Sneha',  'Patil',   '+91-9876543229', NULL, '41, Dadar, Mumbai 400014', 'active', '2026-06-08 16:00:00'),
(22, 'client19@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Ravi',   'Prasad',  '+91-9876543230', 'Ravi Softwares', '14, Banjara Hills, Hyderabad 500034', 'active', '2026-06-10 12:00:00'),
(23, 'client20@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Geeta',  'Iyer',    '+91-9876543231', NULL, '9, Koramangala, Bangalore 560034', 'active', '2026-06-13 07:00:00'),
(24, 'client21@demo.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'Prakash','Joshi',   '+91-9876543232', 'Joshi Web Tech', '27, Deccan Gymkhana, Pune 411004', 'active', '2026-06-11 09:00:00');

-- 2. customers
INSERT IGNORE INTO `customers` (`id`, `user_id`, `company`, `tax_id`, `balance`, `credit`, `status`) VALUES
(1,  4,  'TechSolutions Pvt Ltd',  'GSTIN27AABCU1234C1Z6', 1250.00, 250.00, 'active'),
(2,  5,  'WebCraft Agency',        'GSTIN19XYZAB5678D1Z5', 3200.00, 100.00, 'active'),
(3,  6,  'Singh Enterprises',      'GSTIN07DEFG9012H1Z3',     0.00,   0.00, 'active'),
(4,  7,  NULL,                     NULL,                     540.00,   0.00, 'active'),
(5,  8,  'Iyer Tech Labs',         'GSTIN33HIJK4567L1Z9',  890.00,  50.00, 'active'),
(6,  9,  'Kerala Web Solutions',   'GSTIN32MNOP8901Q1Z2', 1500.00, 200.00, 'active'),
(7,  10, 'Mishra Digital',         'GSTIN09RSTU2345V1Z8',  320.00,   0.00, 'active'),
(8,  11, NULL,                     NULL,                    175.00,  25.00, 'active'),
(9,  12, 'Tiwari Enterprises',     'GSTIN08VWXY6789Z1Z4', 2100.00, 300.00, 'active'),
(10, 13, 'Patna Web Hub',          'GSTIN10ABCD3456E1Z1',  680.00,   0.00, 'active'),
(11, 14, NULL,                     NULL,                    950.00,  75.00, 'active'),
(12, 15, 'Divya Designs',          'GSTIN36FGHI7890J1Z5', 1340.00, 150.00, 'active'),
(13, 16, 'Chennai Hosting Co',     'GSTIN33KLMN1234P1Z3', 2750.00, 400.00, 'active'),
(14, 17, NULL,                     NULL,                    420.00,   0.00, 'active'),
(15, 18, 'KSoft Solutions',        'GSTIN33QRST5678U1Z7', 1890.00, 225.00, 'active'),
(16, 19, 'Nisha Creations',        'GSTIN08VWXY9012A1Z6',  560.00,  50.00, 'active'),
(17, 20, 'Vivek IT Services',      'GSTIN19CDEF3456G1Z9', 1120.00, 100.00, 'active'),
(18, 21, NULL,                     NULL,                    780.00,   0.00, 'active'),
(19, 22, 'Ravi Softwares',         'GSTIN36HIJK7890L1Z2', 1650.00, 175.00, 'active'),
(20, 23, NULL,                     NULL,                    290.00,  30.00, 'active'),
(21, 24, 'Joshi Web Tech',         'GSTIN27MNOP1234Q1Z8',  980.00,   0.00, 'active');

-- 3. products
INSERT IGNORE INTO `products` (`id`, `name`, `type`, `description`, `price`, `billing_cycle`, `setup_fee`, `quota_disk`, `quota_bandwidth`, `quota_email`, `quota_database`, `status`) VALUES
(1, 'Starter Shared Hosting',     'hosting', 'Personal websites & blogs. 5GB SSD, 50GB bandwidth.',    149.00, 'monthly',   0.00,  5120,   50,   5,  2, 'active'),
(2, 'Business Shared Hosting',    'hosting', 'Small business. 20GB SSD, 200GB bandwidth.',            499.00, 'monthly',   0.00, 20480,  200, 100, 10, 'active'),
(3, 'Business Annual Plan',       'hosting', 'Annual business hosting. Save 2 months!',              4999.00, 'annual',   0.00, 20480,  200, 100, 10, 'active'),
(4, 'VPS SSD 2GB',                'hosting', '2 vCPU, 2GB RAM, 40GB NVMe, 1TB transfer.',             999.00, 'monthly', 150.00, 40960, 1000, 999, 999, 'active'),
(5, 'VPS SSD 4GB',                'hosting', '4 vCPU, 4GB RAM, 80GB NVMe, 2TB transfer.',            1799.00, 'monthly', 150.00, 81920, 2000, 999, 999, 'active'),
(6, '.COM Domain Registration',   'domain',  'Register a .COM domain.',                                799.00, 'annual',   0.00,     0,    0,   0,  0, 'active'),
(7, '.IN Domain Registration',    'domain',  'Register a .IN domain.',                                 499.00, 'annual',   0.00,     0,    0,   0,  0, 'active'),
(8, 'SSL Certificate - Standard', 'addon',   'Standard SSL with domain validation.',                    999.00, 'annual',   0.00,     0,    0,   0,  0, 'active'),
(9, 'Site Builder Basic',         'addon',   'Drag-and-drop site builder.',                             249.00, 'monthly',  0.00,     0,    0,   0,  0, 'active'),
(10,'cPanel License',             'other',   'cPanel license for VPS/dedicated.',                      1200.00, 'monthly',  0.00,     0,    0,   0,  0, 'active');

-- 4. servers
INSERT IGNORE INTO `servers` (`id`, `name`, `ip_address`, `panel_type`, `api_url`, `api_username`, `max_accounts`, `status`) VALUES
(1, 'Web Server 01 - Mumbai',    '103.15.50.10', 'cpanel', 'https://srv01.hosting.local:2087', 'root', 150, 'active'),
(2, 'Web Server 02 - Bangalore', '103.15.50.20', 'cpanel', 'https://srv02.hosting.local:2087', 'root', 200, 'active'),
(3, 'VPS Node 01',               '103.15.50.30', 'custom', 'https://virt01.hosting.local:8443','admin',  50, 'active');

-- 5. orders (20)
INSERT IGNORE INTO `orders` (`id`, `customer_id`, `product_id`, `quantity`, `total`, `status`, `domain_name`, `notes`, `created_at`) VALUES
(1,  1, 1, 1,  149.00, 'active',   'techsolutions.in',      'Welcome discount',          '2026-01-05 10:00:00'),
(2,  1, 6, 1,  799.00, 'active',   'techsolutions.in',      NULL,                        '2026-01-05 10:00:00'),
(3,  2, 2, 1,  499.00, 'active',   'webcraft.digital',      'First month promo',         '2026-01-12 14:30:00'),
(4,  2, 7, 1,  499.00, 'active',   'webcraft.digital',      NULL,                        '2026-01-12 14:30:00'),
(5,  3, 4, 1,  999.00, 'active',   'singhenterprises.com',  'Managed VPS setup',         '2026-02-01 09:00:00'),
(6,  3, 6, 1,  799.00, 'active',   'singhenterprises.com',  NULL,                        '2026-02-01 09:01:00'),
(7,  4, 1, 1,  149.00, 'active',   'nehagupta.blog',        NULL,                        '2026-02-10 16:00:00'),
(8,  5, 2, 1,  499.00, 'active',   'iyerlabs.com',          NULL,                        '2026-02-15 11:00:00'),
(9,  5, 6, 1,  799.00, 'active',   'iyerlabs.com',          NULL,                        '2026-02-15 11:01:00'),
(10, 6, 3, 1, 4999.00, 'active',   'keralaweb.com',         'Annual plan - best value',  '2026-02-20 09:00:00'),
(11, 7, 1, 1,  149.00, 'active',   'mishradigital.in',      NULL,                        '2026-03-01 10:00:00'),
(12, 8, 1, 1,  149.00, 'active',   'kvdesigns.com',         NULL,                        '2026-03-05 14:00:00'),
(13, 9, 5, 1, 1799.00, 'active',   'tiwarienterprises.com', 'High performance VPS',      '2026-03-10 09:00:00'),
(14, 10,2, 1,  499.00, 'active',   'patnawebhub.com',       NULL,                        '2026-03-15 11:00:00'),
(15, 11,4, 1,  999.00, 'active',   'hydtechservices.com',   NULL,                        '2026-03-20 10:00:00'),
(16, 12,2, 1,  499.00, 'active',   'divyadesigns.com',      NULL,                        '2026-03-25 14:00:00'),
(17, 13,5, 1, 1799.00, 'active',   'chennaihosting.co',     'Premium VPS',               '2026-04-01 09:00:00'),
(18, 15,2, 1,  499.00, 'active',   'ksoftsolutions.com',    NULL,                        '2026-04-10 10:00:00'),
(19, 19,2, 1,  499.00, 'pending',  'ravisoftwares.com',     NULL,                        '2026-05-01 10:00:00'),
(20, 22,4, 1,  999.00, 'active',   'joshiwebtech.com',      NULL,                        '2026-05-10 09:00:00');

-- 6. invoices (20)
INSERT IGNORE INTO `invoices` (`id`, `invoice_no`, `customer_id`, `order_id`, `amount`, `tax`, `tax_rate`, `discount`, `total`, `status`, `due_date`, `paid_at`, `notes`, `created_at`) VALUES
(1,  'INV-10001', 1,  1,   149.00,  26.82, 18.00,   0.00,   175.82, 'paid',    '2026-01-05', '2026-01-05 10:05:00', NULL,                         '2026-01-05 10:00:00'),
(2,  'INV-10002', 1,  2,   799.00, 143.82, 18.00,   0.00,   942.82, 'paid',    '2026-01-05', '2026-01-05 10:05:00', NULL,                         '2026-01-05 10:00:00'),
(3,  'INV-10003', 2,  3,   499.00,  89.82, 18.00,  50.00,   538.82, 'paid',    '2026-01-12', '2026-01-12 14:35:00', 'First month discount',       '2026-01-12 14:30:00'),
(4,  'INV-10004', 2,  4,   499.00,  89.82, 18.00,   0.00,   588.82, 'paid',    '2026-01-12', '2026-01-12 14:35:00', NULL,                         '2026-01-12 14:30:00'),
(5,  'INV-10005', 3,  5,   999.00, 179.82, 18.00,   0.00,  1178.82, 'paid',    '2026-02-01', '2026-02-01 09:30:00', NULL,                         '2026-02-01 09:00:00'),
(6,  'INV-10006', 3,  6,   799.00, 143.82, 18.00,   0.00,   942.82, 'paid',    '2026-02-01', '2026-02-01 09:30:00', NULL,                         '2026-02-01 09:00:00'),
(7,  'INV-10007', 4,  7,   149.00,  26.82, 18.00,   0.00,   175.82, 'paid',    '2026-02-10', '2026-02-10 16:05:00', NULL,                         '2026-02-10 16:00:00'),
(8,  'INV-10008', 5,  8,   499.00,  89.82, 18.00,   0.00,   588.82, 'paid',    '2026-02-15', '2026-02-15 11:05:00', NULL,                         '2026-02-15 11:00:00'),
(9,  'INV-10009', 5,  9,   799.00, 143.82, 18.00,   0.00,   942.82, 'paid',    '2026-02-15', '2026-02-15 11:05:00', NULL,                         '2026-02-15 11:00:00'),
(10, 'INV-10010', 6, 10,  4999.00, 899.82, 18.00, 500.00,  5398.82, 'paid',    '2026-02-20', '2026-02-20 09:05:00', 'Annual plan discount',       '2026-02-20 09:00:00'),
(11, 'INV-10011', 7, 11,   149.00,  26.82, 18.00,   0.00,   175.82, 'paid',    '2026-03-01', '2026-03-01 10:05:00', NULL,                         '2026-03-01 10:00:00'),
(12, 'INV-10012', 8, 12,   149.00,  26.82, 18.00,   0.00,   175.82, 'paid',    '2026-03-05', '2026-03-05 14:05:00', NULL,                         '2026-03-05 14:00:00'),
(13, 'INV-10013', 9, 13,  1799.00, 323.82, 18.00,   0.00,  2122.82, 'paid',    '2026-03-10', '2026-03-10 09:05:00', NULL,                         '2026-03-10 09:00:00'),
(14, 'INV-10014',10, 14,   499.00,  89.82, 18.00,   0.00,   588.82, 'paid',    '2026-03-15', '2026-03-15 11:05:00', NULL,                         '2026-03-15 11:00:00'),
(15, 'INV-10015',11, 15,   999.00, 179.82, 18.00,   0.00,  1178.82, 'paid',    '2026-03-20', '2026-03-20 10:05:00', NULL,                         '2026-03-20 10:00:00'),
(16, 'INV-10016',12, 16,   499.00,  89.82, 18.00,   0.00,   588.82, 'paid',    '2026-03-25', '2026-03-25 14:05:00', NULL,                         '2026-03-25 14:00:00'),
(17, 'INV-10017',13, 17,  1799.00, 323.82, 18.00,   0.00,  2122.82, 'paid',    '2026-04-01', '2026-04-01 09:05:00', NULL,                         '2026-04-01 09:00:00'),
(18, 'INV-10018',15, 18,   499.00,  89.82, 18.00,   0.00,   588.82, 'sent',    '2026-04-10', NULL,                 NULL,                         '2026-04-10 10:00:00'),
(19, 'INV-10019',19, 19,   499.00,  89.82, 18.00,   0.00,   588.82, 'overdue', '2026-05-01', NULL,                 NULL,                         '2026-05-01 10:00:00'),
(20, 'INV-10020',21, 20,   999.00, 179.82, 18.00,   0.00,  1178.82, 'paid',    '2026-05-10', '2026-05-10 09:05:00', NULL,                         '2026-05-10 09:00:00');

-- 7. invoice_items
INSERT IGNORE INTO `invoice_items` (`id`, `invoice_id`, `description`, `quantity`, `unit_price`, `total`) VALUES
(1,  1, 'Starter Shared Hosting - Monthly',           1,  149.00,  149.00),
(2,  2, '.COM Domain Registration - 1 Year',           1,  799.00,  799.00),
(3,  3, 'Business Shared Hosting - Monthly',           1,  499.00,  499.00),
(4,  4, '.IN Domain Registration - 1 Year',            1,  499.00,  499.00),
(5,  5, 'VPS SSD 2GB - Monthly',                       1,  999.00,  999.00),
(6,  6, '.COM Domain Registration - 1 Year',           1,  799.00,  799.00),
(7,  7, 'Starter Shared Hosting - Monthly',            1,  149.00,  149.00),
(8,  8, 'Business Shared Hosting - Monthly',           1,  499.00,  499.00),
(9,  9, '.COM Domain Registration - 1 Year',           1,  799.00,  799.00),
(10,10, 'Business Annual Plan - 1 Year',               1, 4999.00, 4999.00),
(11,11, 'Starter Shared Hosting - Monthly',            1,  149.00,  149.00),
(12,12, 'Starter Shared Hosting - Monthly',            1,  149.00,  149.00),
(13,13, 'VPS SSD 4GB - Monthly',                       1, 1799.00, 1799.00),
(14,14, 'Business Shared Hosting - Monthly',           1,  499.00,  499.00),
(15,15, 'VPS SSD 2GB - Monthly',                       1,  999.00,  999.00),
(16,16, 'Business Shared Hosting - Monthly',           1,  499.00,  499.00),
(17,17, 'VPS SSD 4GB - Monthly',                       1, 1799.00, 1799.00),
(18,18, 'Business Shared Hosting - Monthly',           1,  499.00,  499.00),
(19,19, 'Business Shared Hosting - Monthly',           1,  499.00,  499.00),
(20,20, 'VPS SSD 2GB - Monthly',                       1,  999.00,  999.00);

-- 8. payments
INSERT IGNORE INTO `payments` (`id`, `invoice_id`, `amount`, `method`, `gateway_id`, `transaction_id`, `status`, `notes`, `created_at`) VALUES
(1,  1,  175.82, 'razorpay',      'pay_R001', 'txn_001', 'completed', NULL,                    '2026-01-05 10:05:00'),
(2,  2,  942.82, 'razorpay',      'pay_R002', 'txn_002', 'completed', NULL,                    '2026-01-05 10:05:00'),
(3,  3,  538.82, 'bank_transfer', NULL,        'NEFT-001','completed', 'NEFT Ref: 001',         '2026-01-12 14:35:00'),
(4,  4,  588.82, 'bank_transfer', NULL,        'NEFT-002','completed', 'NEFT Ref: 002',         '2026-01-12 14:35:00'),
(5,  5, 1178.82, 'razorpay',      'pay_R003', 'txn_003', 'completed', NULL,                    '2026-02-01 09:30:00'),
(6,  6,  942.82, 'razorpay',      'pay_R004', 'txn_004', 'completed', NULL,                    '2026-02-01 09:30:00'),
(7,  7,  175.82, 'razorpay',      'pay_R005', 'txn_005', 'completed', NULL,                    '2026-02-10 16:05:00'),
(8,  8,  588.82, 'razorpay',      'pay_R006', 'txn_006', 'completed', NULL,                    '2026-02-15 11:05:00'),
(9,  9,  942.82, 'razorpay',      'pay_R007', 'txn_007', 'completed', NULL,                    '2026-02-15 11:05:00'),
(10,10, 5398.82, 'bank_transfer', NULL,        'NEFT-003','completed', 'NEFT Ref: 003',         '2026-02-20 09:05:00'),
(11,11,  175.82, 'razorpay',      'pay_R008', 'txn_008', 'completed', NULL,                    '2026-03-01 10:05:00'),
(12,12,  175.82, 'razorpay',      'pay_R009', 'txn_009', 'completed', NULL,                    '2026-03-05 14:05:00'),
(13,13, 2122.82, 'bank_transfer', NULL,        'NEFT-004','completed', 'NEFT Ref: 004',         '2026-03-10 09:05:00'),
(14,14,  588.82, 'razorpay',      'pay_R010', 'txn_010', 'completed', NULL,                    '2026-03-15 11:05:00'),
(15,15, 1178.82, 'razorpay',      'pay_R011', 'txn_011', 'completed', NULL,                    '2026-03-20 10:05:00'),
(16,16,  588.82, 'razorpay',      'pay_R012', 'txn_012', 'completed', NULL,                    '2026-03-25 14:05:00'),
(17,17, 2122.82, 'bank_transfer', NULL,        'NEFT-005','completed', 'NEFT Ref: 005',         '2026-04-01 09:05:00'),
(18,20, 1178.82, 'razorpay',      'pay_R013', 'txn_013', 'completed', NULL,                    '2026-05-10 09:05:00');

-- 9. credits
INSERT IGNORE INTO `credits` (`id`, `customer_id`, `amount`, `type`, `description`, `created_at`) VALUES
(1,  1, 500.00, 'added', 'Initial credit deposit',       '2026-01-01 09:00:00'),
(2,  1, 250.00, 'used',  'Applied to INV-10001',         '2026-01-05 10:00:00'),
(3,  2, 500.00, 'added', 'Referral bonus',               '2026-01-10 12:00:00'),
(4,  2, 100.00, 'used',  'Partial payment INV-10003',     '2026-01-12 14:30:00'),
(5,  5, 200.00, 'added', 'Welcome bonus',                '2026-02-15 09:00:00'),
(6,  9, 300.00, 'added', 'Loyalty credit',               '2026-03-10 09:00:00'),
(7,  9, 150.00, 'used',  'Applied to INV-10013',         '2026-03-10 09:01:00'),
(8, 13, 400.00, 'added', 'Bulk order bonus',             '2026-04-01 09:00:00');

-- 10. hosting_accounts
INSERT IGNORE INTO `hosting_accounts` (`id`, `customer_id`, `product_id`, `server_id`, `order_id`, `username`, `domain`, `disk_quota`, `disk_used`, `bandwidth_quota`, `bandwidth_used`, `panel_account_id`, `username_prefix`, `password`, `status`, `created_at`) VALUES
(1,  1, 1, 1, 1, 'techsoln', 'techsolutions.in',      5120,  1250,  50,  12, 'cpan_1001', 'tch', '$2y$10$x', 'active', '2026-01-05 10:30:00'),
(2,  2, 2, 2, 3, 'webcraft', 'webcraft.digital',      20480,  3400, 200,  45, 'cpan_1002', 'wbc', '$2y$10$x', 'active', '2026-01-12 15:00:00'),
(3,  3, 4, 3, 5, 'singhen',  'singhenterprises.com',  40960,  8200, 1000, 210, 'virt_2001', 'sen', '$2y$10$x', 'active', '2026-02-01 10:00:00'),
(4,  4, 1, 1, 7, 'nehagup',  'nehagupta.blog',         5120,   120,  50,   2, 'cpan_1003', 'neh', '$2y$10$x', 'active', '2026-02-10 16:30:00'),
(5,  5, 2, 2, 8, 'iyerlab',  'iyerlabs.com',          20480,  5600, 200,  78, 'cpan_1004', 'iyl', '$2y$10$x', 'active', '2026-02-15 11:30:00'),
(6,  6, 3, 2,10, 'kweb',     'keralaweb.com',         20480,  4200, 200,  62, 'cpan_1005', 'kwb', '$2y$10$x', 'active', '2026-02-20 09:30:00'),
(7,  7, 1, 1,11, 'mishra',   'mishradigital.in',       5120,   890,  50,  15, 'cpan_1006', 'msh', '$2y$10$x', 'active', '2026-03-01 10:30:00'),
(8,  8, 1, 1,12, 'kvdes',    'kvdesigns.com',          5120,   340,  50,   8, 'cpan_1007', 'kvd', '$2y$10$x', 'active', '2026-03-05 14:30:00'),
(9,  9, 5, 3,13, 'tiwari',   'tiwarienterprises.com', 81920, 15000, 2000, 450, 'virt_2002', 'twr', '$2y$10$x', 'active', '2026-03-10 09:30:00'),
(10,10, 2, 2,14, 'pwebhub',  'patnawebhub.com',       20480,  2800, 200,  35, 'cpan_1008', 'pwh', '$2y$10$x', 'active', '2026-03-15 11:30:00'),
(11,11, 4, 3,15, 'hydtech',  'hydtechservices.com',   40960,  6100, 1000, 180, 'virt_2003', 'hyt', '$2y$10$x', 'active', '2026-03-20 10:30:00'),
(12,12, 2, 2,16, 'divdes',   'divyadesigns.com',      20480,  3100, 200,  42, 'cpan_1009', 'dvd', '$2y$10$x', 'active', '2026-03-25 14:30:00'),
(13,13, 5, 3,17, 'chhost',   'chennaihosting.co',     81920, 22000, 2000, 580, 'virt_2004', 'chn', '$2y$10$x', 'active', '2026-04-01 09:30:00'),
(14,15, 2, 2,18, 'ksoft',    'ksoftsolutions.com',    20480,  4500, 200,  68, 'cpan_1010', 'ksf', '$2y$10$x', 'active', '2026-04-10 10:30:00'),
(15,19, 2, 2,19, 'rsoft',    'ravisoftwares.com',     20480,  3900, 200,  55, 'cpan_1011', 'rsv', '$2y$10$x', 'pending','2026-05-01 10:30:00'),
(16,21, 4, 3,20, 'jweb',     'joshiwebtech.com',      40960,  4200, 1000, 120, 'virt_2005', 'jws', '$2y$10$x', 'active', '2026-05-10 09:30:00');

-- 11. domains
INSERT IGNORE INTO `domains` (`id`, `customer_id`, `order_id`, `name`, `registrar_id`, `registration_date`, `expiry_date`, `auto_renew`, `privacy_enabled`, `nameservers`, `status`, `created_at`) VALUES
(1,  1,  2, 'techsolutions.in',      'RC_10001', '2026-01-05', '2027-01-05', 1, 1, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-01-05 10:00:00'),
(2,  2,  4, 'webcraft.digital',      'RC_10002', '2026-01-12', '2027-01-12', 1, 0, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-01-12 14:30:00'),
(3,  3,  6, 'singhenterprises.com',  'RC_10003', '2026-02-01', '2027-02-01', 1, 1, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-02-01 09:00:00'),
(4,  4,  7, 'nehagupta.blog',        NULL,       '2026-02-10', '2027-02-10', 1, 0, '["pending"]',                                'pending', '2026-02-10 16:00:00'),
(5,  5,  9, 'iyerlabs.com',          'RC_10004', '2026-02-15', '2027-02-15', 1, 1, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-02-15 11:00:00'),
(6,  6, 10, 'keralaweb.com',         'RC_10005', '2026-02-20', '2027-02-20', 1, 0, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-02-20 09:00:00'),
(7,  7, 11, 'mishradigital.in',      'RC_10006', '2026-03-01', '2027-03-01', 1, 1, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-03-01 10:00:00'),
(8,  9, 13, 'tiwarienterprises.com', 'RC_10007', '2026-03-10', '2027-03-10', 1, 0, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-03-10 09:00:00'),
(9, 10, 14, 'patnawebhub.com',       'RC_10008', '2026-03-15', '2027-03-15', 1, 1, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-03-15 11:00:00'),
(10,12, 16, 'divyadesigns.com',      'RC_10009', '2026-03-25', '2027-03-25', 1, 0, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-03-25 14:00:00'),
(11,13, 17, 'chennaihosting.co',     'RC_10010', '2026-04-01', '2027-04-01', 1, 1, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-04-01 09:00:00'),
(12,15, 18, 'ksoftsolutions.com',    'RC_10011', '2026-04-10', '2027-04-10', 1, 0, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-04-10 10:00:00'),
(13,19, 19, 'ravisoftwares.com',     'RC_10012', '2026-05-01', '2027-05-01', 1, 1, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-05-01 10:00:00'),
(14,21, 20, 'joshiwebtech.com',      'RC_10013', '2026-05-10', '2027-05-10', 1, 0, '["ns1.hosting.local","ns2.hosting.local"]', 'active',  '2026-05-10 09:00:00');

-- 12. tickets (10)
INSERT IGNORE INTO `tickets` (`id`, `ticket_no`, `customer_id`, `subject`, `priority`, `status`, `department`, `assigned_to`, `created_at`) VALUES
(1,  'TKT-10001', 1,  'Unable to access webmail',                    'high',    'open',     'technical', 2, '2026-06-10 08:30:00'),
(2,  'TKT-10002', 2,  'SSL certificate renewal failed',              'urgent',  'open',     'technical', 2, '2026-06-11 09:15:00'),
(3,  'TKT-10003', 1,  'Invoice discount not applied',                'medium',  'pending',  'billing',   3, '2026-06-11 11:00:00'),
(4,  'TKT-10004', 3,  'How to set up subdomain?',                    'low',     'resolved', 'support',   2, '2026-06-08 14:00:00'),
(5,  'TKT-10005', 2,  'Want to upgrade to VPS plan',                 'medium',  'closed',   'sales',     3, '2026-05-20 10:00:00'),
(6,  'TKT-10006', 5,  'Website loading slowly',                      'high',    'open',     'technical', 2, '2026-06-12 08:00:00'),
(7,  'TKT-10007', 8,  'Need help with email setup',                  'low',     'open',     'support',   2, '2026-06-12 10:30:00'),
(8,  'TKT-10008', 9,  'VPS disk space running low',                  'urgent',  'pending',  'technical', 2, '2026-06-13 07:00:00'),
(9,  'TKT-10009', 6,  'Request for refund on invoice',               'medium',  'open',     'billing',   3, '2026-06-13 09:00:00'),
(10, 'TKT-10010',12,  'How to migrate website to new hosting?',      'low',     'open',     'support',   2, '2026-06-13 11:00:00');

-- 13. ticket_replies
INSERT IGNORE INTO `ticket_replies` (`id`, `ticket_id`, `user_id`, `message`, `is_staff`, `created_at`) VALUES
(1,  1,  4, 'Hi, I cannot log in to webmail. It shows "Connection failed".',      0, '2026-06-10 08:30:00'),
(2,  1,  2, 'IMAP service was down. It has been restarted. Please try again.',     1, '2026-06-10 09:00:00'),
(3,  1,  4, 'It works now. Thank you!',                                           0, '2026-06-10 09:15:00'),
(4,  2,  5, 'Our SSL autorenewal failed. Site shows "Not Secure".',              0, '2026-06-11 09:15:00'),
(5,  2,  2, 'Payment method expired. Certificate re-issued. Propagating in 1hr.',1, '2026-06-11 09:45:00'),
(6,  4,  6, 'How do I set up staging.singhenterprises.com?',                      0, '2026-06-08 14:00:00'),
(7,  4,  2, 'Create subdomain from cPanel. Point to subfolder under public_html.',1, '2026-06-08 14:30:00'),
(8,  4,  6, 'Thanks, that worked!',                                               0, '2026-06-08 15:00:00'),
(9,  6,  8, 'Our site loads very slowly today. Can you check?',                   0, '2026-06-12 08:00:00'),
(10, 6,  2, 'High traffic from referral. Site is fine, will stabilize shortly.',  1, '2026-06-12 08:15:00'),
(11, 6,  8, 'Working fine now. Thanks!',                                           0, '2026-06-12 08:30:00'),
(12, 9, 10, 'I want a refund for invoice INV-10019. Service not satisfactory.',   0, '2026-06-13 09:00:00'),
(13, 9,  3, 'Let me review your account. Will update shortly.',                   1, '2026-06-13 09:30:00');

-- 14. knowledge_base
INSERT IGNORE INTO `knowledge_base` (`id`, `category`, `title`, `slug`, `content`, `views`, `helpful`, `not_helpful`, `status`, `created_at`) VALUES
(1, 'getting_started', 'Welcome to Your Control Panel',     'welcome-cpanel',       '<h2>Getting Started</h2><p>Access your cPanel at yourdomain.com/cpanel</p>', 1250, 45, 3, 'published', '2026-01-01 10:00:00'),
(2, 'hosting',         'Upload Your Website via FTP',       'upload-ftp',           '<h2>FTP Upload</h2><p>Use FileZilla with your cPanel credentials.</p>', 980, 32, 5, 'published', '2026-01-05 10:00:00'),
(3, 'email',           'Setup Email on Your Phone',         'setup-email-mobile',   '<h2>Mobile Email</h2><p>IMAP: mail.yourdomain.com:993 SSL</p>', 2150, 89, 12, 'published', '2026-01-10 10:00:00'),
(4, 'domains',         'Point Domain to Our Nameservers',   'point-nameservers',    '<h2>Nameservers</h2><p>ns1.hosting.local, ns2.hosting.local</p>', 560, 18, 2, 'published', '2026-02-01 10:00:00'),
(5, 'billing',         'Understanding Your Invoice',        'understanding-invoices','<h2>Invoices</h2><p>Auto-generated on billing date. Pay via Razorpay.</p>', 430, 15, 1, 'published', '2026-02-10 10:00:00'),
(6, 'technical',       'Troubleshooting HTTP Errors',       'troubleshooting-errors','<h2>Common Errors</h2><p>500: Check .htaccess. 403: Check index file.</p>', 1870, 72, 8, 'published', '2026-03-01 10:00:00');

-- 15. chat_sessions
INSERT IGNORE INTO `chat_sessions` (`id`, `customer_id`, `operator_id`, `name`, `email`, `department`, `status`, `rating`, `started_at`, `ended_at`) VALUES
(1,  1, 2, 'Amit Kumar',    'client1@demo.com', 'technical', 'closed', 5, '2026-06-09 10:00:00', '2026-06-09 10:15:00'),
(2,  2, 2, 'Sunita Patel',  'client2@demo.com', 'technical', 'closed', 4, '2026-06-10 14:00:00', '2026-06-10 14:25:00'),
(3, NULL,NULL,'Ravi Sharma', 'ravi@example.com', 'sales',     'waiting',NULL,'2026-06-13 08:30:00', NULL);

-- 16. chat_messages
INSERT IGNORE INTO `chat_messages` (`id`, `session_id`, `user_id`, `sender_type`, `message`, `created_at`) VALUES
(1,  1,  4, 'client',   'My website is loading slowly. Can you check?',              '2026-06-09 10:00:00'),
(2,  1,  2, 'operator', 'Checking server load. One moment.',                         '2026-06-09 10:01:00'),
(3,  1,  2, 'operator', 'High traffic from referral. Site is scaling normally.',     '2026-06-09 10:05:00'),
(4,  1,  4, 'client',   'Working fine now, thanks!',                                 '2026-06-09 10:12:00'),
(5,  2,  5, 'client',   'I deleted an email folder. Can you restore from backup?',   '2026-06-10 14:00:00'),
(6,  2,  2, 'operator', 'Found a backup from yesterday. Restoring now.',             '2026-06-10 14:10:00'),
(7,  2,  5, 'client',   'Everything is back. Thank you!',                            '2026-06-10 14:22:00');

-- 17. audit_log
INSERT IGNORE INTO `audit_log` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'login',          'session',  1, '{"method":"password"}',          '127.0.0.1',    '2026-06-13 08:00:00'),
(2, 2, 'login',          'session',  2, '{"method":"password"}',          '192.168.1.10', '2026-06-13 08:30:00'),
(3, 2, 'ticket_update',  'ticket',   1, '{"status":"open"}',              '192.168.1.10', '2026-06-10 09:00:00'),
(4, 3, 'invoice_view',   'invoice',  6, '{}',                             '10.0.0.15',    '2026-06-12 11:00:00'),
(5, 4, 'login',          'session',  3, '{"method":"password"}',          '10.0.0.5',     '2026-06-11 14:20:00'),
(6, 1, 'settings_update','settings', NULL,'{"company_name":"Hosting Co"}','127.0.0.1',    '2026-06-01 12:00:00');

-- 18. email_templates
INSERT IGNORE INTO `email_templates` (`id`, `name`, `subject`, `body`, `status`) VALUES
(1, 'welcome_email',    'Welcome to {{company_name}}!',     '<h2>Welcome {{first_name}}!</h2><p>Your account is ready.</p>', 'active'),
(2, 'invoice_created',  'Invoice {{invoice_no}}',           '<p>Invoice {{invoice_no}} for {{total}} is due {{due_date}}.</p>', 'active'),
(3, 'payment_received', 'Payment Received',                 '<p>Payment of {{amount}} received for {{invoice_no}}.</p>', 'active'),
(4, 'ticket_reply',     'Reply on Ticket #{{ticket_no}}',   '<p>New reply on your ticket: {{message}}</p>', 'active'),
(5, 'payment_reminder', 'Payment Reminder',                 '<p>Invoice {{invoice_no}} for {{total}} is due soon.</p>', 'active');

-- 19. automation_log
INSERT IGNORE INTO `automation_log` (`id`, `action`, `entity_type`, `entity_id`, `status`, `message`, `created_at`, `completed_at`) VALUES
(1, 'provision_account', 'hosting_account', 1,  'success',  'Provisioned on Web Server 01', '2026-01-05 10:30:00', '2026-01-05 10:30:05'),
(2, 'provision_account', 'hosting_account', 2,  'success',  'Provisioned on Web Server 02', '2026-01-12 15:00:00', '2026-01-12 15:00:04'),
(3, 'register_domain',   'domain',          1,  'success',  'Registered via ResellerClub',  '2026-01-05 10:00:30', '2026-01-05 10:00:35'),
(4, 'provision_account', 'hosting_account', 3,  'success',  'VPS provisioned on Node 01',   '2026-02-01 10:00:00', '2026-02-01 10:15:00'),
(5, 'suspension_check',  'customer',        10, 'failed',   'Overdue invoices, suspension disabled', '2026-06-13 06:00:00', '2026-06-13 06:00:01'),
(6, 'domain_renewal',    'domain',          1,  'pending',  'Renewal check for techsolutions.in', '2026-06-13 00:00:00', NULL);
