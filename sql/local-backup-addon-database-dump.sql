/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.6.23-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: local
-- ------------------------------------------------------
-- Server version	10.6.23-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `event` varchar(100) DEFAULT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_log_customer_id_index` (`customer_id`),
  KEY `activity_log_user_id_index` (`user_id`),
  KEY `activity_log_action_index` (`action`),
  KEY `activity_log_event_index` (`event`),
  KEY `activity_log_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `activity_log_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
INSERT INTO `activity_log` VALUES (1,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:153.0) Gecko/20100101 Firefox/153.0','2026-08-07 04:48:28');
INSERT INTO `activity_log` VALUES (2,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 06:16:15');
INSERT INTO `activity_log` VALUES (3,NULL,1,NULL,'Signed out',NULL,'127.0.0.1','auth.logout',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 06:18:01');
INSERT INTO `activity_log` VALUES (4,NULL,2,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 06:18:15');
INSERT INTO `activity_log` VALUES (5,NULL,2,NULL,'Signed out',NULL,'127.0.0.1','auth.logout',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 06:19:49');
INSERT INTO `activity_log` VALUES (6,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 06:20:23');
INSERT INTO `activity_log` VALUES (7,1,1,'order_status_changed','Order status changed from \'pending\' to \'cancelled\'','{\"order_id\":3,\"order_number\":\"ORD-2026-00003\",\"from\":\"pending\",\"to\":\"cancelled\",\"by\":\"admin@localhost.com\"}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `activity_log` VALUES (8,1,1,'order_status_changed','Order status changed from \'pending\' to \'cancelled\'','{\"order_id\":2,\"order_number\":\"ORD-2026-00002\",\"from\":\"pending\",\"to\":\"cancelled\",\"by\":\"admin@localhost.com\"}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `activity_log` VALUES (9,NULL,NULL,NULL,'Failed login attempt',NULL,'127.0.0.1','auth.failed',NULL,NULL,'{\"email\":\"admin@localhost.com\"}','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 08:52:35');
INSERT INTO `activity_log` VALUES (10,NULL,NULL,NULL,'Failed login attempt',NULL,'127.0.0.1','auth.failed',NULL,NULL,'{\"email\":\"admin@localhost.com\"}','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 08:53:12');
INSERT INTO `activity_log` VALUES (11,NULL,NULL,NULL,'Failed login attempt',NULL,'127.0.0.1','auth.failed',NULL,NULL,'{\"email\":\"admin@localhost.com\"}','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 08:53:43');
INSERT INTO `activity_log` VALUES (12,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-08-07 08:55:24');
INSERT INTO `activity_log` VALUES (13,1,1,'order_status_changed','Order status changed from \'pending\' to \'cancelled\'','{\"order_id\":1,\"order_number\":\"ORD-2026-00001\",\"from\":\"pending\",\"to\":\"cancelled\",\"by\":\"admin@localhost.com\"}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `activity_log` VALUES (14,1,1,'customer.deleted','Customer deleted','{\"event_dispatched\":true}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `activity_log` VALUES (16,5,1,'customer.created','Customer created','{\"event_dispatched\":true}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `activity_log` VALUES (17,NULL,6,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:153.0) Gecko/20100101 Firefox/153.0','2026-08-07 09:32:30');
INSERT INTO `activity_log` VALUES (18,NULL,NULL,'impersonation_started','Admin Sanat Das (admin@localhost.com) started impersonating Somenath Patra (some@gmail.com)','{\"impersonator_id\":1,\"impersonated_id\":6}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `activity_log` VALUES (19,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:153.0) Gecko/20100101 Firefox/153.0','2026-08-07 09:32:49');
INSERT INTO `activity_log` VALUES (20,NULL,NULL,'impersonation_stopped','Admin Sanat Das (admin@localhost.com) stopped impersonating, returned to their own session','{\"impersonator_id\":1,\"impersonated_id\":6}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `activity_log` VALUES (21,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:153.0) Gecko/20100101 Firefox/153.0','2026-08-08 03:51:08');
INSERT INTO `activity_log` VALUES (22,NULL,1,'auth.login','Demo: admin@localhost.com signed in to the admin panel','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"user\",\"subject_id\":1}','192.0.2.24','auth.login','user',1,'{\"demo\":true,\"sequence\":1}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 02:35:00');
INSERT INTO `activity_log` VALUES (23,NULL,13,'auth.login','Demo: support@example.com support agent signed in for the morning shift','{\"actor\":\"support@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"user\",\"subject_id\":6}','192.0.2.51','auth.login','user',6,'{\"demo\":true,\"sequence\":2}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 02:58:00');
INSERT INTO `activity_log` VALUES (24,5,14,'customer.created','Demo: sales@example.com created a customer account','{\"actor\":\"sales@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"customer\",\"subject_id\":5}','198.51.100.17','created','customer',5,'{\"demo\":true,\"sequence\":3}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 03:21:00');
INSERT INTO `activity_log` VALUES (25,6,14,'customer.updated','Demo: sales@example.com updated customer billing details','{\"actor\":\"sales@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"customer\",\"subject_id\":6}','203.0.113.9','updated','customer',6,'{\"demo\":true,\"sequence\":4}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 03:44:00');
INSERT INTO `activity_log` VALUES (26,7,13,'customer.note.added','Demo: support@example.com logged a call note against the customer','{\"actor\":\"support@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"customer\",\"subject_id\":7}','192.0.2.24','created','customer',7,'{\"demo\":true,\"sequence\":5}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 04:07:00');
INSERT INTO `activity_log` VALUES (30,NULL,1,'product.created','Demo: admin@localhost.com published a new product to the catalog','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"product\",\"subject_id\":1}','192.0.2.24','created','product',1,'{\"demo\":true,\"sequence\":9}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 05:39:00');
INSERT INTO `activity_log` VALUES (31,NULL,1,'product.pricing.updated','Demo: admin@localhost.com revised the annual price of a product','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"product\",\"subject_id\":6}','192.0.2.51','updated','product',6,'{\"demo\":true,\"sequence\":10}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 06:02:00');
INSERT INTO `activity_log` VALUES (32,5,13,'ticket.opened','Demo: support@example.com opened a support ticket for the customer','{\"actor\":\"support@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"ticket\",\"subject_id\":9}','198.51.100.17','created','ticket',9,'{\"demo\":true,\"sequence\":11}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 06:25:00');
INSERT INTO `activity_log` VALUES (33,6,13,'ticket.replied','Demo: support@example.com replied to a support ticket','{\"actor\":\"support@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"ticket\",\"subject_id\":10}','203.0.113.9','created','ticket',10,'{\"demo\":true,\"sequence\":12}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 06:48:00');
INSERT INTO `activity_log` VALUES (34,7,13,'ticket.closed','Demo: support@example.com closed a resolved support ticket','{\"actor\":\"support@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"ticket\",\"subject_id\":11}','192.0.2.24','updated','ticket',11,'{\"demo\":true,\"sequence\":13}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 07:11:00');
INSERT INTO `activity_log` VALUES (35,5,1,'hosting_account.suspended','Demo: admin@localhost.com suspended a hosting account for non-payment','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"hosting_account\",\"subject_id\":1}','192.0.2.51','updated','hosting_account',1,'{\"demo\":true,\"sequence\":14}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 07:34:00');
INSERT INTO `activity_log` VALUES (36,6,1,'hosting_account.unsuspended','Demo: admin@localhost.com restored a hosting account after payment','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"hosting_account\",\"subject_id\":2}','198.51.100.17','updated','hosting_account',2,'{\"demo\":true,\"sequence\":15}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 07:57:00');
INSERT INTO `activity_log` VALUES (37,8,15,'marketing.consent.recorded','Demo: marketing@example.com recorded a marketing consent opt-in','{\"actor\":\"marketing@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"customer\",\"subject_id\":8}','203.0.113.9','created','customer',8,'{\"demo\":true,\"sequence\":16}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 08:20:00');
INSERT INTO `activity_log` VALUES (38,NULL,1,'settings.updated','Demo: admin@localhost.com changed the invoice numbering prefix','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"user\",\"subject_id\":1}','192.0.2.24','updated','user',1,'{\"demo\":true,\"sequence\":17}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 08:43:00');
INSERT INTO `activity_log` VALUES (39,NULL,1,'auth.logout','Demo: admin@localhost.com signed out of the admin panel','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"user\",\"subject_id\":1}','192.0.2.51','auth.logout','user',1,'{\"demo\":true,\"sequence\":18}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 09:06:00');
INSERT INTO `activity_log` VALUES (40,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:153.0) Gecko/20100101 Firefox/153.0','2026-08-08 23:44:59');
INSERT INTO `activity_log` VALUES (41,5,14,'order.placed','Demo: sales@example.com placed an order for a hosting plan','{\"actor\":\"sales@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"order\",\"subject_id\":4}','192.0.2.51','created','order',4,'{\"demo\":true,\"sequence\":6}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 04:30:00');
INSERT INTO `activity_log` VALUES (42,6,14,'order.status.changed','Demo: sales@example.com moved an order from \'pending\' to \'active\'','{\"actor\":\"sales@example.com\",\"source\":\"demo-seeder\",\"subject_type\":\"order\",\"subject_id\":5}','198.51.100.17','updated','order',5,'{\"demo\":true,\"sequence\":7}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 04:53:00');
INSERT INTO `activity_log` VALUES (43,7,1,'invoice.paid','Demo: admin@localhost.com marked an invoice as paid after a bank transfer','{\"actor\":\"admin@localhost.com\",\"source\":\"demo-seeder\",\"subject_type\":\"order\",\"subject_id\":6}','203.0.113.9','updated','order',6,'{\"demo\":true,\"sequence\":8}','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 05:16:00');
INSERT INTO `activity_log` VALUES (44,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Symfony','2026-08-09 02:27:28');
INSERT INTO `activity_log` VALUES (45,NULL,1,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 02:31:06');
INSERT INTO `activity_log` VALUES (46,NULL,11,NULL,'Signed in',NULL,'127.0.0.1','auth.login',NULL,NULL,NULL,'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:153.0) Gecko/20100101 Firefox/153.0','2026-08-09 03:48:43');
INSERT INTO `activity_log` VALUES (47,NULL,NULL,'impersonation_started','Admin Sanat Das (admin@localhost.com) started impersonating Emeka Santos (client5@example.com)','{\"impersonator_id\":1,\"impersonated_id\":11}','127.0.0.1',NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adminlte_permission_role`
--

DROP TABLE IF EXISTS `adminlte_permission_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `adminlte_permission_role` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adminlte_permission_role_permission_id_role_id_unique` (`permission_id`,`role_id`),
  KEY `adminlte_permission_role_role_id_foreign` (`role_id`),
  CONSTRAINT `adminlte_permission_role_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `adminlte_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adminlte_permission_role_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `adminlte_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adminlte_permission_role`
--

LOCK TABLES `adminlte_permission_role` WRITE;
/*!40000 ALTER TABLE `adminlte_permission_role` DISABLE KEYS */;
INSERT INTO `adminlte_permission_role` VALUES (33,1,1);
INSERT INTO `adminlte_permission_role` VALUES (34,2,1);
INSERT INTO `adminlte_permission_role` VALUES (29,3,1);
INSERT INTO `adminlte_permission_role` VALUES (7,4,1);
INSERT INTO `adminlte_permission_role` VALUES (52,4,2);
INSERT INTO `adminlte_permission_role` VALUES (69,4,3);
INSERT INTO `adminlte_permission_role` VALUES (85,4,4);
INSERT INTO `adminlte_permission_role` VALUES (94,4,5);
INSERT INTO `adminlte_permission_role` VALUES (103,4,6);
INSERT INTO `adminlte_permission_role` VALUES (2,5,1);
INSERT INTO `adminlte_permission_role` VALUES (65,5,3);
INSERT INTO `adminlte_permission_role` VALUES (83,5,4);
INSERT INTO `adminlte_permission_role` VALUES (37,6,1);
INSERT INTO `adminlte_permission_role` VALUES (80,6,3);
INSERT INTO `adminlte_permission_role` VALUES (91,6,4);
INSERT INTO `adminlte_permission_role` VALUES (109,6,6);
INSERT INTO `adminlte_permission_role` VALUES (36,7,1);
INSERT INTO `adminlte_permission_role` VALUES (117,7,4);
INSERT INTO `adminlte_permission_role` VALUES (1,8,1);
INSERT INTO `adminlte_permission_role` VALUES (50,8,2);
INSERT INTO `adminlte_permission_role` VALUES (38,9,1);
INSERT INTO `adminlte_permission_role` VALUES (6,10,1);
INSERT INTO `adminlte_permission_role` VALUES (51,10,2);
INSERT INTO `adminlte_permission_role` VALUES (68,10,3);
INSERT INTO `adminlte_permission_role` VALUES (84,10,4);
INSERT INTO `adminlte_permission_role` VALUES (93,10,5);
INSERT INTO `adminlte_permission_role` VALUES (102,10,6);
INSERT INTO `adminlte_permission_role` VALUES (3,11,1);
INSERT INTO `adminlte_permission_role` VALUES (66,11,3);
INSERT INTO `adminlte_permission_role` VALUES (5,12,1);
INSERT INTO `adminlte_permission_role` VALUES (67,12,3);
INSERT INTO `adminlte_permission_role` VALUES (92,12,5);
INSERT INTO `adminlte_permission_role` VALUES (4,13,1);
INSERT INTO `adminlte_permission_role` VALUES (35,14,1);
INSERT INTO `adminlte_permission_role` VALUES (79,14,3);
INSERT INTO `adminlte_permission_role` VALUES (90,14,4);
INSERT INTO `adminlte_permission_role` VALUES (99,14,5);
INSERT INTO `adminlte_permission_role` VALUES (108,14,6);
INSERT INTO `adminlte_permission_role` VALUES (30,15,1);
INSERT INTO `adminlte_permission_role` VALUES (115,15,4);
INSERT INTO `adminlte_permission_role` VALUES (32,16,1);
INSERT INTO `adminlte_permission_role` VALUES (116,16,4);
INSERT INTO `adminlte_permission_role` VALUES (98,16,5);
INSERT INTO `adminlte_permission_role` VALUES (31,17,1);
INSERT INTO `adminlte_permission_role` VALUES (26,18,1);
INSERT INTO `adminlte_permission_role` VALUES (76,18,3);
INSERT INTO `adminlte_permission_role` VALUES (24,19,1);
INSERT INTO `adminlte_permission_role` VALUES (75,19,3);
INSERT INTO `adminlte_permission_role` VALUES (25,20,1);
INSERT INTO `adminlte_permission_role` VALUES (113,20,3);
INSERT INTO `adminlte_permission_role` VALUES (18,21,1);
INSERT INTO `adminlte_permission_role` VALUES (56,21,2);
INSERT INTO `adminlte_permission_role` VALUES (73,21,3);
INSERT INTO `adminlte_permission_role` VALUES (114,21,4);
INSERT INTO `adminlte_permission_role` VALUES (95,21,5);
INSERT INTO `adminlte_permission_role` VALUES (106,21,6);
INSERT INTO `adminlte_permission_role` VALUES (15,22,1);
INSERT INTO `adminlte_permission_role` VALUES (72,22,3);
INSERT INTO `adminlte_permission_role` VALUES (17,23,1);
INSERT INTO `adminlte_permission_role` VALUES (112,23,3);
INSERT INTO `adminlte_permission_role` VALUES (16,24,1);
INSERT INTO `adminlte_permission_role` VALUES (28,25,1);
INSERT INTO `adminlte_permission_role` VALUES (60,25,2);
INSERT INTO `adminlte_permission_role` VALUES (78,25,3);
INSERT INTO `adminlte_permission_role` VALUES (27,26,1);
INSERT INTO `adminlte_permission_role` VALUES (77,26,3);
INSERT INTO `adminlte_permission_role` VALUES (14,27,1);
INSERT INTO `adminlte_permission_role` VALUES (55,27,2);
INSERT INTO `adminlte_permission_role` VALUES (71,27,3);
INSERT INTO `adminlte_permission_role` VALUES (105,27,6);
INSERT INTO `adminlte_permission_role` VALUES (12,28,1);
INSERT INTO `adminlte_permission_role` VALUES (13,29,1);
INSERT INTO `adminlte_permission_role` VALUES (9,30,1);
INSERT INTO `adminlte_permission_role` VALUES (53,30,2);
INSERT INTO `adminlte_permission_role` VALUES (70,30,3);
INSERT INTO `adminlte_permission_role` VALUES (104,30,6);
INSERT INTO `adminlte_permission_role` VALUES (8,31,1);
INSERT INTO `adminlte_permission_role` VALUES (41,32,1);
INSERT INTO `adminlte_permission_role` VALUES (40,33,1);
INSERT INTO `adminlte_permission_role` VALUES (39,34,1);
INSERT INTO `adminlte_permission_role` VALUES (45,35,1);
INSERT INTO `adminlte_permission_role` VALUES (64,35,2);
INSERT INTO `adminlte_permission_role` VALUES (82,35,3);
INSERT INTO `adminlte_permission_role` VALUES (101,35,5);
INSERT INTO `adminlte_permission_role` VALUES (110,35,6);
INSERT INTO `adminlte_permission_role` VALUES (43,36,1);
INSERT INTO `adminlte_permission_role` VALUES (62,36,2);
INSERT INTO `adminlte_permission_role` VALUES (81,36,3);
INSERT INTO `adminlte_permission_role` VALUES (44,37,1);
INSERT INTO `adminlte_permission_role` VALUES (63,37,2);
INSERT INTO `adminlte_permission_role` VALUES (100,37,5);
INSERT INTO `adminlte_permission_role` VALUES (42,38,1);
INSERT INTO `adminlte_permission_role` VALUES (61,38,2);
INSERT INTO `adminlte_permission_role` VALUES (22,39,1);
INSERT INTO `adminlte_permission_role` VALUES (59,39,2);
INSERT INTO `adminlte_permission_role` VALUES (74,39,3);
INSERT INTO `adminlte_permission_role` VALUES (89,39,4);
INSERT INTO `adminlte_permission_role` VALUES (97,39,5);
INSERT INTO `adminlte_permission_role` VALUES (107,39,6);
INSERT INTO `adminlte_permission_role` VALUES (19,40,1);
INSERT INTO `adminlte_permission_role` VALUES (57,40,2);
INSERT INTO `adminlte_permission_role` VALUES (87,40,4);
INSERT INTO `adminlte_permission_role` VALUES (21,41,1);
INSERT INTO `adminlte_permission_role` VALUES (58,41,2);
INSERT INTO `adminlte_permission_role` VALUES (88,41,4);
INSERT INTO `adminlte_permission_role` VALUES (96,41,5);
INSERT INTO `adminlte_permission_role` VALUES (20,42,1);
INSERT INTO `adminlte_permission_role` VALUES (49,43,1);
INSERT INTO `adminlte_permission_role` VALUES (46,44,1);
INSERT INTO `adminlte_permission_role` VALUES (48,45,1);
INSERT INTO `adminlte_permission_role` VALUES (47,46,1);
INSERT INTO `adminlte_permission_role` VALUES (11,47,1);
INSERT INTO `adminlte_permission_role` VALUES (54,47,2);
INSERT INTO `adminlte_permission_role` VALUES (86,47,4);
INSERT INTO `adminlte_permission_role` VALUES (10,48,1);
INSERT INTO `adminlte_permission_role` VALUES (23,49,1);
INSERT INTO `adminlte_permission_role` VALUES (111,50,1);
/*!40000 ALTER TABLE `adminlte_permission_role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adminlte_permissions`
--

DROP TABLE IF EXISTS `adminlte_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `adminlte_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adminlte_permissions_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adminlte_permissions`
--

LOCK TABLES `adminlte_permissions` WRITE;
/*!40000 ALTER TABLE `adminlte_permissions` DISABLE KEYS */;
INSERT INTO `adminlte_permissions` VALUES (1,'products.groups','Manage Product Groups','2026-08-07 04:48:20','2026-08-07 04:48:20');
INSERT INTO `adminlte_permissions` VALUES (2,'products.options','Manage Configurable Options','2026-08-07 04:48:20','2026-08-07 04:48:20');
INSERT INTO `adminlte_permissions` VALUES (3,'products.addons','Manage Product Addons','2026-08-07 04:48:20','2026-08-07 04:48:20');
INSERT INTO `adminlte_permissions` VALUES (4,'dashboard.view','View Dashboard','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (5,'analytics.view','View Analytics','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (6,'reports.view','View Reports','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (7,'reports.export','Export Reports','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (8,'activity.view','View Activity Log','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (9,'search','Use Global Search','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (10,'customers.view','View Customers','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (11,'customers.create','Create Customers','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (12,'customers.edit','Edit Customers','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (13,'customers.delete','Delete Customers','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (14,'products.view','View Products','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (15,'products.create','Create Products','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (16,'products.edit','Edit Products','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (17,'products.delete','Delete Products','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (18,'orders.view','View Orders','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (19,'orders.create','Create Orders','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (20,'orders.edit','Edit Orders','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (21,'invoices.view','View Invoices','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (22,'invoices.create','Create Invoices','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (23,'invoices.edit','Edit Invoices','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (24,'invoices.delete','Delete Invoices','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (25,'payments.view','View Payments','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (26,'payments.create','Record Payments','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (27,'hosting.view','View Hosting & Infrastructure','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (28,'hosting.manage','Manage Hosting & Infrastructure','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (29,'hosting.server_groups','Manage Server Groups','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (30,'domains.view','View Domains','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (31,'domains.manage','Manage Domains','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (32,'settings.view','View Settings','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (33,'settings.manage','Manage Settings','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (34,'settings.edit','Edit Settings','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (35,'tickets.view','View Tickets','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (36,'tickets.create','Create Tickets','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (37,'tickets.edit','Edit Tickets','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (38,'tickets.assign','Assign Tickets','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (39,'kb.view','View Knowledge Base','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (40,'kb.create','Create KB Articles','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (41,'kb.edit','Edit KB Articles','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (42,'kb.delete','Delete KB Articles','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (43,'users.view','View Users','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (44,'users.create','Create Users','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (45,'users.edit','Edit Users','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (46,'users.delete','Delete Users','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (47,'email.view','View Email Log','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (48,'email.manage','Manage Email','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (49,'manage-roles','Manage Roles & Permissions','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_permissions` VALUES (50,'orders.delete','Delete Orders','2026-08-07 04:48:21','2026-08-07 04:48:21');
/*!40000 ALTER TABLE `adminlte_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adminlte_role_user`
--

DROP TABLE IF EXISTS `adminlte_role_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `adminlte_role_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adminlte_role_user_role_id_user_id_unique` (`role_id`,`user_id`),
  KEY `adminlte_role_user_user_id_foreign` (`user_id`),
  CONSTRAINT `adminlte_role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `adminlte_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adminlte_role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adminlte_role_user`
--

LOCK TABLES `adminlte_role_user` WRITE;
/*!40000 ALTER TABLE `adminlte_role_user` DISABLE KEYS */;
INSERT INTO `adminlte_role_user` VALUES (1,1,1);
INSERT INTO `adminlte_role_user` VALUES (3,2,13);
INSERT INTO `adminlte_role_user` VALUES (4,3,14);
INSERT INTO `adminlte_role_user` VALUES (5,4,15);
/*!40000 ALTER TABLE `adminlte_role_user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adminlte_roles`
--

DROP TABLE IF EXISTS `adminlte_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `adminlte_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adminlte_roles_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adminlte_roles`
--

LOCK TABLES `adminlte_roles` WRITE;
/*!40000 ALTER TABLE `adminlte_roles` DISABLE KEYS */;
INSERT INTO `adminlte_roles` VALUES (1,'admin','Administrator','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_roles` VALUES (2,'support','Support Agent','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_roles` VALUES (3,'sales','Sales','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_roles` VALUES (4,'marketing','Marketing','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_roles` VALUES (5,'editor','Editor','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_roles` VALUES (6,'viewer','Viewer','2026-08-07 04:48:21','2026-08-07 04:48:21');
INSERT INTO `adminlte_roles` VALUES (7,'client','Client','2026-08-07 06:14:45','2026-08-07 06:14:45');
/*!40000 ALTER TABLE `adminlte_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_relationships`
--

DROP TABLE IF EXISTS `asset_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_relationships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_kind` varchar(100) NOT NULL,
  `parent_id` bigint(20) unsigned NOT NULL,
  `child_kind` varchar(100) NOT NULL,
  `child_id` bigint(20) unsigned NOT NULL,
  `relationship_type` varchar(50) NOT NULL DEFAULT 'hosted_on',
  `label` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_relationships_parent_child_type_unique` (`parent_kind`,`parent_id`,`child_kind`,`child_id`,`relationship_type`),
  KEY `asset_relationships_parent_kind_parent_id_index` (`parent_kind`,`parent_id`),
  KEY `asset_relationships_child_kind_child_id_index` (`child_kind`,`child_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_relationships`
--

LOCK TABLES `asset_relationships` WRITE;
/*!40000 ALTER TABLE `asset_relationships` DISABLE KEYS */;
INSERT INTO `asset_relationships` VALUES (1,'inventory_asset',1,'inventory_asset',7,'contains','NVMe storage tier',0,'Demo: 7.68TB U.2 drive installed in the primary host.','2026-08-08 04:57:30','2026-08-08 04:57:30');
INSERT INTO `asset_relationships` VALUES (2,'inventory_asset',1,'inventory_asset',9,'contains','10GbE uplink card',1,'Demo: dual-port NIC installed in the primary host.','2026-08-08 04:57:30','2026-08-08 04:57:30');
INSERT INTO `asset_relationships` VALUES (3,'inventory_asset',1,'inventory_asset',10,'contains','32GB DDR4 module',2,'Demo: memory module installed in the primary host.','2026-08-08 04:57:30','2026-08-08 04:57:30');
INSERT INTO `asset_relationships` VALUES (4,'inventory_asset',2,'inventory_asset',8,'contains','Backup storage tier',3,'Demo: 18TB SATA drive installed in the secondary host.','2026-08-08 04:57:30','2026-08-08 04:57:30');
INSERT INTO `asset_relationships` VALUES (5,'inventory_asset',3,'inventory_asset',1,'manages','ToR switch port 1/0/12',4,'Demo: access switch serving the primary host.','2026-08-08 04:57:30','2026-08-08 04:57:30');
INSERT INTO `asset_relationships` VALUES (6,'inventory_asset',3,'inventory_asset',2,'manages','ToR switch port 1/0/14',5,'Demo: access switch serving the secondary host.','2026-08-08 04:57:30','2026-08-08 04:57:30');
INSERT INTO `asset_relationships` VALUES (7,'inventory_asset',5,'inventory_asset',3,'manages','Edge router uplink',6,'Demo: edge router feeding the top-of-rack switch.','2026-08-08 04:57:30','2026-08-08 04:57:30');
INSERT INTO `asset_relationships` VALUES (8,'inventory_asset',6,'inventory_asset',4,'hosted_on','Protected power feed',7,'Demo: spare switch cabled to the rack UPS.','2026-08-08 04:57:30','2026-08-08 04:57:30');
/*!40000 ALTER TABLE `asset_relationships` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_log_user_id_index` (`user_id`),
  KEY `audit_log_action_index` (`action`),
  KEY `audit_log_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `audit_log_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,1,'user.login','user',1,'Demo: Administrator signed in from the office network.','192.0.2.24','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 02:30:00');
INSERT INTO `audit_log` VALUES (2,1,'user.created','user',6,'Demo: Client login provisioned during demo onboarding.','192.0.2.51','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 02:47:00');
INSERT INTO `audit_log` VALUES (3,1,'user.role.assigned','user',7,'Demo: Role membership granted through the RBAC screen.','198.51.100.17','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 03:04:00');
INSERT INTO `audit_log` VALUES (4,14,'customer.created','customer',5,'Demo: Customer account opened from an inbound enquiry.','203.0.113.9','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 03:21:00');
INSERT INTO `audit_log` VALUES (5,14,'customer.updated','customer',6,'Demo: Billing address and tax id corrected.','192.0.2.24','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 03:38:00');
INSERT INTO `audit_log` VALUES (6,13,'customer.note.added','customer',7,'Demo: Call summary attached to the customer record.','192.0.2.51','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 03:55:00');
INSERT INTO `audit_log` VALUES (7,15,'customer.exported','customer',8,'Demo: Consented contacts exported for a newsletter run.','198.51.100.17','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 04:12:00');
INSERT INTO `audit_log` VALUES (8,1,'product.created','product',1,'Demo: Catalog entry published to the order form.','203.0.113.9','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 04:29:00');
INSERT INTO `audit_log` VALUES (9,1,'product.updated','product',6,'Demo: Feature list and quota summary revised.','192.0.2.24','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 04:46:00');
INSERT INTO `audit_log` VALUES (10,1,'product.pricing.updated','product',7,'Demo: Annual price reduced for the summer campaign.','192.0.2.51','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 05:03:00');
INSERT INTO `audit_log` VALUES (15,1,'hosting_account.suspended','hosting_account',1,'Demo: Account suspended for a non-payment reminder cycle.','198.51.100.17','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 06:28:00');
INSERT INTO `audit_log` VALUES (16,1,'hosting_account.unsuspended','hosting_account',2,'Demo: Account restored after the balance was cleared.','203.0.113.9','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 06:45:00');
INSERT INTO `audit_log` VALUES (17,1,'server.updated','server',1,'Demo: Node capacity limits adjusted after a RAM upgrade.','192.0.2.24','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 07:02:00');
INSERT INTO `audit_log` VALUES (18,1,'settings.updated','setting',1,'Demo: Invoice numbering prefix changed in company settings.','192.0.2.51','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 07:19:00');
INSERT INTO `audit_log` VALUES (19,13,'ticket.opened','ticket',9,'Demo: Support request raised on behalf of the customer.','192.0.2.24','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 05:54:00');
INSERT INTO `audit_log` VALUES (20,13,'ticket.closed','ticket',10,'Demo: Issue resolved and confirmed by the requester.','192.0.2.51','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 06:11:00');
INSERT INTO `audit_log` VALUES (21,14,'order.placed','order',4,'Demo: Order captured on behalf of the customer by phone.','198.51.100.17','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 05:20:00');
INSERT INTO `audit_log` VALUES (22,14,'order.approved','order',5,'Demo: Fraud review cleared, order released to provisioning.','203.0.113.9','Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0','2026-07-01 05:37:00');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `automation_log`
--

DROP TABLE IF EXISTS `automation_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_log_status_index` (`status`),
  KEY `automation_log_action_index` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `automation_log`
--

LOCK TABLES `automation_log` WRITE;
/*!40000 ALTER TABLE `automation_log` DISABLE KEYS */;
INSERT INTO `automation_log` VALUES (1,'invoice.generate','customer',5,'success','Demo: Recurring invoice generated for the monthly billing run.','2026-07-01 02:30:00','2026-07-01 08:02:00');
INSERT INTO `automation_log` VALUES (2,'invoice.reminder','customer',6,'success','Demo: First overdue reminder emailed to the billing contact.','2026-07-01 03:01:00','2026-07-01 08:33:00');
INSERT INTO `automation_log` VALUES (3,'invoice.overdue.suspend','customer',7,'pending','Demo: Suspension queued, awaiting the 7-day grace period.','2026-07-01 03:32:00',NULL);
INSERT INTO `automation_log` VALUES (4,'payment.retry','customer',8,'failed','Demo: Gateway declined the stored card (do_not_honour).','2026-07-01 04:03:00','2026-07-01 09:35:00');
INSERT INTO `automation_log` VALUES (5,'service.provision','hosting_account',1,'success','Demo: cPanel account created and welcome email dispatched.','2026-07-01 04:34:00','2026-07-01 10:06:00');
INSERT INTO `automation_log` VALUES (6,'service.suspend','hosting_account',2,'success','Demo: Account suspended by the overdue-invoice automation.','2026-07-01 05:05:00','2026-07-01 10:37:00');
INSERT INTO `automation_log` VALUES (7,'service.terminate','hosting_account',3,'pending','Demo: Termination scheduled after the retention window.','2026-07-01 05:36:00',NULL);
INSERT INTO `automation_log` VALUES (8,'domain.renew','product',1,'failed','Demo: Registrar API timed out after 3 attempts.','2026-07-01 06:07:00','2026-07-01 11:39:00');
INSERT INTO `automation_log` VALUES (9,'ssl.renew','product',6,'success','Demo: Certificate renewed and installed on the node.','2026-07-01 06:38:00','2026-07-01 12:10:00');
INSERT INTO `automation_log` VALUES (10,'backup.verify','server',1,'success','Demo: Nightly backup checksum verified, 0 corrupt archives.','2026-07-01 07:09:00','2026-07-01 12:41:00');
INSERT INTO `automation_log` VALUES (11,'usage.aggregate','server',2,'success','Demo: Hourly usage counters rolled up into usage_records.','2026-07-01 07:40:00','2026-07-01 13:12:00');
INSERT INTO `automation_log` VALUES (13,'ticket.autoclose','ticket',9,'pending','Demo: Awaiting customer response before auto-closing.','2026-07-01 08:11:00',NULL);
/*!40000 ALTER TABLE `automation_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing_cycles`
--

DROP TABLE IF EXISTS `billing_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_cycles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `cycle_start` date NOT NULL,
  `cycle_end` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','partial','paid','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_cycles_customer_id_index` (`customer_id`),
  CONSTRAINT `billing_cycles_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_cycles`
--

LOCK TABLES `billing_cycles` WRITE;
/*!40000 ALTER TABLE `billing_cycles` DISABLE KEYS */;
INSERT INTO `billing_cycles` VALUES (1,6,'2026-05-01','2026-05-31',150.00,150.00,'paid','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `billing_cycles` VALUES (2,6,'2026-06-01','2026-06-30',175.00,70.00,'partial','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `billing_cycles` VALUES (3,7,'2026-05-01','2026-05-31',300.00,300.00,'paid','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `billing_cycles` VALUES (4,7,'2026-06-01','2026-06-30',325.00,325.00,'paid','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `billing_cycles` VALUES (5,8,'2026-05-01','2026-05-31',450.00,450.00,'paid','2026-08-09 01:25:01','2026-08-09 01:49:35');
INSERT INTO `billing_cycles` VALUES (6,8,'2026-06-01','2026-06-30',475.00,190.00,'partial','2026-08-09 01:25:01','2026-08-09 01:49:35');
INSERT INTO `billing_cycles` VALUES (7,9,'2026-05-01','2026-05-31',600.00,600.00,'paid','2026-08-09 01:25:02','2026-08-09 01:49:35');
INSERT INTO `billing_cycles` VALUES (8,9,'2026-06-01','2026-06-30',625.00,625.00,'paid','2026-08-09 01:25:02','2026-08-09 01:49:35');
INSERT INTO `billing_cycles` VALUES (9,10,'2026-05-01','2026-05-31',750.00,750.00,'paid','2026-08-09 01:25:02','2026-08-09 01:49:35');
INSERT INTO `billing_cycles` VALUES (10,10,'2026-06-01','2026-06-30',775.00,310.00,'partial','2026-08-09 01:25:02','2026-08-09 01:49:35');
/*!40000 ALTER TABLE `billing_cycles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('hosting-crm-cache-0de06d35aa65da4264752ec050764c7b','i:1;',1786262525);
INSERT INTO `cache` VALUES ('hosting-crm-cache-0de06d35aa65da4264752ec050764c7b:timer','i:1786262525;',1786262525);
INSERT INTO `cache` VALUES ('hosting-crm-cache-2cdfda0a171effae10efa4800c210891','i:1;',1786267183);
INSERT INTO `cache` VALUES ('hosting-crm-cache-2cdfda0a171effae10efa4800c210891:timer','i:1786267183;',1786267183);
INSERT INTO `cache` VALUES ('hosting-crm-cache-e00cf25ad42683b3df678c61f42c6bda','i:3;',1786267174);
INSERT INTO `cache` VALUES ('hosting-crm-cache-e00cf25ad42683b3df678c61f42c6bda:timer','i:1786267174;',1786267174);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalog_products`
--

DROP TABLE IF EXISTS `catalog_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sku` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `product_type` enum('shared_hosting','reseller','vps','dedicated','domain','addon','bundle','license','other') NOT NULL DEFAULT 'shared_hosting',
  `provisioning_method` enum('manual','cpanel','plesk','directadmin','proxmox','vmware','hyperv','solusvm','virtualizor','docker','kubernetes','api','custom_script') NOT NULL DEFAULT 'manual',
  `provisioning_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provisioning_config`)),
  `billing_model` enum('one_time','recurring','usage_based','tiered') NOT NULL DEFAULT 'recurring',
  `require_domain` tinyint(1) NOT NULL DEFAULT 0,
  `show_in_order` tinyint(1) NOT NULL DEFAULT 1,
  `only_admin` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive','retired') NOT NULL DEFAULT 'active',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `catalog_products_sku_unique` (`sku`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalog_products`
--

LOCK TABLES `catalog_products` WRITE;
/*!40000 ALTER TABLE `catalog_products` DISABLE KEYS */;
INSERT INTO `catalog_products` VALUES (8,'DEMO-STARTER','Demo Starter Shared Hosting',1,'Demo Starter Shared Hosting catalog entry.','shared_hosting','cpanel',NULL,'recurring',1,1,0,0,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (9,'DEMO-BUSINESS','Demo Business Shared Hosting',1,'Demo Business Shared Hosting catalog entry.','shared_hosting','cpanel',NULL,'recurring',1,1,0,1,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (10,'DEMO-RESELLER','Demo Reseller Bronze',2,'Demo Reseller Bronze catalog entry.','reseller','cpanel',NULL,'recurring',1,1,0,2,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (11,'DEMO-VPS-2GB','Demo Cloud VPS 2GB',3,'Demo Cloud VPS 2GB catalog entry.','vps','virtualizor',NULL,'recurring',0,1,0,3,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (12,'DEMO-VPS-8GB','Demo Cloud VPS 8GB',3,'Demo Cloud VPS 8GB catalog entry.','vps','virtualizor',NULL,'recurring',0,1,0,4,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (13,'DEMO-DEDICATED','Demo Dedicated E3 Server',4,'Demo Dedicated E3 Server catalog entry.','dedicated','custom_script',NULL,'recurring',0,1,0,5,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (14,'DEMO-DOMAIN-COM','Demo .com Domain Registration',5,'Demo .com Domain Registration catalog entry.','domain','manual',NULL,'one_time',1,1,0,6,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (15,'DEMO-ADDON','Demo SSL & Backup Addon',6,'Demo SSL & Backup Addon catalog entry.','addon','manual',NULL,'one_time',1,1,0,7,'active',1,'2026-08-08 05:11:47','2026-08-08 05:12:22',NULL);
INSERT INTO `catalog_products` VALUES (16,'DEMO-CAT-001','Demo Starter Shared Hosting',1,NULL,'shared_hosting','cpanel',NULL,'recurring',1,1,0,10,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
INSERT INTO `catalog_products` VALUES (17,'DEMO-CAT-002','Demo Business Shared Hosting',1,NULL,'shared_hosting','cpanel',NULL,'recurring',1,1,0,20,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
INSERT INTO `catalog_products` VALUES (18,'DEMO-CAT-003','Demo Reseller Bronze',2,NULL,'reseller','cpanel',NULL,'recurring',1,1,0,30,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
INSERT INTO `catalog_products` VALUES (19,'DEMO-CAT-004','Demo Cloud VPS 2GB',3,NULL,'vps','virtualizor',NULL,'recurring',0,1,0,40,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
INSERT INTO `catalog_products` VALUES (20,'DEMO-CAT-005','Demo Cloud VPS 8GB',3,NULL,'vps','virtualizor',NULL,'recurring',0,1,0,50,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
INSERT INTO `catalog_products` VALUES (21,'DEMO-CAT-006','Demo Dedicated E3 Server',4,NULL,'dedicated','custom_script',NULL,'recurring',0,1,0,60,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
INSERT INTO `catalog_products` VALUES (22,'DEMO-CAT-007','Demo .com Domain',5,NULL,'domain','manual',NULL,'one_time',1,1,0,70,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
INSERT INTO `catalog_products` VALUES (23,'DEMO-CAT-008','Demo SSL & Backup Addon',6,NULL,'addon','manual',NULL,'one_time',1,1,0,80,'active',1,'2026-08-08 05:25:23','2026-08-09 01:49:36',NULL);
/*!40000 ALTER TABLE `catalog_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `sender_type` enum('client','operator','system') NOT NULL DEFAULT 'client',
  `message` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_messages_session_id_index` (`session_id`),
  CONSTRAINT `chat_messages_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_messages`
--

LOCK TABLES `chat_messages` WRITE;
/*!40000 ALTER TABLE `chat_messages` DISABLE KEYS */;
INSERT INTO `chat_messages` VALUES (20,6,6,'client','Hi, I need help with my hosting service.','2026-08-06 02:15:01');
INSERT INTO `chat_messages` VALUES (21,6,13,'operator','Please update your nameservers to ns1.examplehost.test and ns2.examplehost.test.','2026-08-06 02:18:01');
INSERT INTO `chat_messages` VALUES (22,6,6,'client','Can you check why my website is down?','2026-08-06 02:21:01');
INSERT INTO `chat_messages` VALUES (23,6,13,'operator','Hello! I would be happy to help. Could you share your domain name?','2026-08-06 02:24:01');
INSERT INTO `chat_messages` VALUES (24,6,NULL,'system','Conversation closed by agent. Rate your experience in the survey below.','2026-08-06 02:27:01');
INSERT INTO `chat_messages` VALUES (25,7,NULL,'client','Hi, I need help with my hosting service.','2026-08-05 22:15:01');
INSERT INTO `chat_messages` VALUES (26,7,14,'operator','Please update your nameservers to ns1.examplehost.test and ns2.examplehost.test.','2026-08-05 22:18:01');
INSERT INTO `chat_messages` VALUES (27,7,14,'operator','I see the issue on our end. It should be fixed in a few minutes.','2026-08-05 22:21:01');
INSERT INTO `chat_messages` VALUES (28,8,8,'client','Hi, I need help with my hosting service.','2026-08-05 18:15:01');
INSERT INTO `chat_messages` VALUES (29,8,15,'operator','Please update your nameservers to ns1.examplehost.test and ns2.examplehost.test.','2026-08-05 18:18:01');
INSERT INTO `chat_messages` VALUES (30,8,8,'client','Can you check why my website is down?','2026-08-05 18:21:01');
INSERT INTO `chat_messages` VALUES (31,8,NULL,'system','Conversation closed by agent. Rate your experience in the survey below.','2026-08-05 18:24:01');
INSERT INTO `chat_messages` VALUES (32,9,NULL,'client','Hi, I need help with my hosting service.','2026-08-05 14:15:01');
INSERT INTO `chat_messages` VALUES (33,9,13,'operator','Please update your nameservers to ns1.examplehost.test and ns2.examplehost.test.','2026-08-05 14:18:01');
INSERT INTO `chat_messages` VALUES (34,10,10,'client','Hi, I need help with my hosting service.','2026-08-05 10:15:01');
INSERT INTO `chat_messages` VALUES (35,10,14,'operator','Please update your nameservers to ns1.examplehost.test and ns2.examplehost.test.','2026-08-05 10:18:01');
INSERT INTO `chat_messages` VALUES (36,10,10,'client','Can you check why my website is down?','2026-08-05 10:21:01');
INSERT INTO `chat_messages` VALUES (37,10,14,'operator','Hello! I would be happy to help. Could you share your domain name?','2026-08-05 10:24:01');
INSERT INTO `chat_messages` VALUES (38,10,10,'client','How do I point my domain to your server?','2026-08-05 10:27:01');
/*!40000 ALTER TABLE `chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_sessions`
--

DROP TABLE IF EXISTS `chat_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `operator_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `department` enum('sales','support','billing','technical') NOT NULL DEFAULT 'support',
  `status` enum('waiting','active','closed') NOT NULL DEFAULT 'waiting',
  `rating` tinyint(4) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_sessions_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_sessions`
--

LOCK TABLES `chat_sessions` WRITE;
/*!40000 ALTER TABLE `chat_sessions` DISABLE KEYS */;
INSERT INTO `chat_sessions` VALUES (6,5,13,'Demo Customer 5','chat-customer-5@example.com','sales','active',NULL,'2026-08-06 07:45:01',NULL);
INSERT INTO `chat_sessions` VALUES (7,NULL,14,'Guest Visitor 2','guest2@example.com','support','waiting',NULL,'2026-08-06 03:45:01',NULL);
INSERT INTO `chat_sessions` VALUES (8,7,15,'Demo Customer 7','chat-customer-7@example.com','billing','closed',5,'2026-08-05 23:45:01','2026-08-06 00:04:01');
INSERT INTO `chat_sessions` VALUES (9,NULL,13,'Guest Visitor 4','guest4@example.com','technical','active',NULL,'2026-08-05 19:45:01',NULL);
INSERT INTO `chat_sessions` VALUES (10,9,14,'Demo Customer 9','chat-customer-9@example.com','sales','waiting',NULL,'2026-08-05 15:45:01',NULL);
/*!40000 ALTER TABLE `chat_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `credits`
--

DROP TABLE IF EXISTS `credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` enum('added','used','expired','refund') NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `credits_customer_id_index` (`customer_id`),
  CONSTRAINT `credits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `credits`
--

LOCK TABLES `credits` WRITE;
/*!40000 ALTER TABLE `credits` DISABLE KEYS */;
INSERT INTO `credits` VALUES (1,6,25.00,'added','Goodwill credit issued to demo customer 1','2026-08-08 04:54:11');
INSERT INTO `credits` VALUES (2,6,10.00,'used','Credit applied to a demo invoice for customer 1','2026-08-08 04:54:11');
INSERT INTO `credits` VALUES (3,7,50.00,'added','Goodwill credit issued to demo customer 2','2026-08-08 04:54:11');
INSERT INTO `credits` VALUES (4,8,75.00,'added','Goodwill credit issued to demo customer 3','2026-08-08 04:54:12');
INSERT INTO `credits` VALUES (5,8,30.00,'used','Credit applied to a demo invoice for customer 3','2026-08-08 04:54:12');
INSERT INTO `credits` VALUES (6,9,100.00,'added','Goodwill credit issued to demo customer 4','2026-08-08 04:54:12');
INSERT INTO `credits` VALUES (7,10,125.00,'added','Goodwill credit issued to demo customer 5','2026-08-08 04:54:13');
INSERT INTO `credits` VALUES (8,10,50.00,'used','Credit applied to a demo invoice for customer 5','2026-08-08 04:54:13');
INSERT INTO `credits` VALUES (9,6,150.00,'added','Demo referral bonus credit.','2026-08-09 00:19:02');
INSERT INTO `credits` VALUES (10,7,75.00,'used','Demo credit applied against balance.','2026-08-09 00:19:02');
INSERT INTO `credits` VALUES (11,8,200.00,'added','Demo loyalty reward credit.','2026-08-09 00:19:02');
/*!40000 ALTER TABLE `credits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cron_logs`
--

DROP TABLE IF EXISTS `cron_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(255) NOT NULL,
  `command` varchar(500) DEFAULT NULL,
  `status` enum('pending','running','success','failed') NOT NULL DEFAULT 'pending',
  `message` text DEFAULT NULL,
  `domains_processed` int(10) unsigned NOT NULL DEFAULT 0,
  `errors_count` int(10) unsigned NOT NULL DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cron_logs_status_index` (`status`),
  KEY `cron_logs_job_name_index` (`job_name`),
  KEY `cron_logs_started_at_index` (`started_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cron_logs`
--

LOCK TABLES `cron_logs` WRITE;
/*!40000 ALTER TABLE `cron_logs` DISABLE KEYS */;
INSERT INTO `cron_logs` VALUES (1,'billing:generate-invoices','php artisan billing:generate-invoices','success','Demo: Generated 4 invoices, skipped 1 suspended account.',5,0,'2026-07-01 08:01:00','2026-07-01 08:04:00','2026-07-01 08:04:00','2026-07-01 02:30:00');
INSERT INTO `cron_logs` VALUES (2,'billing:send-reminders','php artisan billing:send-reminders --days=7','success','Demo: Queued 3 overdue reminders.',3,0,'2026-07-01 09:01:00','2026-07-01 09:02:00','2026-07-01 09:02:00','2026-07-01 03:30:00');
INSERT INTO `cron_logs` VALUES (3,'domains:sync-expiry','php artisan domains:sync-expiry','failed','Demo: Registrar API returned HTTP 503 for 2 domains.',8,2,'2026-07-01 10:01:00','2026-07-01 10:07:00','2026-07-01 10:07:00','2026-07-01 04:30:00');
INSERT INTO `cron_logs` VALUES (4,'services:suspend-overdue','php artisan services:suspend-overdue','success','Demo: Suspended 1 hosting account past its grace period.',1,0,'2026-07-01 11:01:00','2026-07-01 11:03:00','2026-07-01 11:03:00','2026-07-01 05:30:00');
INSERT INTO `cron_logs` VALUES (5,'usage:collect','php artisan usage:collect --interval=hourly','running','Demo: Collecting counters from 4 nodes.',2,0,'2026-07-01 12:01:00',NULL,NULL,'2026-07-01 06:30:00');
INSERT INTO `cron_logs` VALUES (6,'ssl:renew-expiring','php artisan ssl:renew-expiring --within=30','pending','Demo: Scheduled, waiting for the next scheduler tick.',0,0,NULL,NULL,NULL,'2026-07-01 07:30:00');
INSERT INTO `cron_logs` VALUES (7,'backups:verify','php artisan backups:verify --all','success','Demo: Verified 4 nightly archives, all checksums matched.',4,0,'2026-07-01 14:01:00','2026-07-01 14:13:00','2026-07-01 14:13:00','2026-07-01 08:30:00');
/*!40000 ALTER TABLE `cron_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_contacts`
--

DROP TABLE IF EXISTS `customer_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `role` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_contacts_customer_id_index` (`customer_id`),
  CONSTRAINT `customer_contacts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_contacts`
--

LOCK TABLES `customer_contacts` WRITE;
/*!40000 ALTER TABLE `customer_contacts` DISABLE KEYS */;
INSERT INTO `customer_contacts` VALUES (1,6,'Asha','Verma','client1@example.com','+1-555-0101','Owner',1,'active','2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_contacts` VALUES (2,6,'Billing','Verma','billing1@example.com','+1-555-0201','Billing',0,'active','2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_contacts` VALUES (3,7,'Brian','Okafor','client2@example.com','+1-555-0102','Owner',1,'active','2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_contacts` VALUES (4,7,'Billing','Okafor','billing2@example.com','+1-555-0202','Billing',0,'active','2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_contacts` VALUES (5,8,'Chen','Liu','client3@example.com','+1-555-0103','Owner',1,'active','2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_contacts` VALUES (6,8,'Billing','Liu','billing3@example.com','+1-555-0203','Billing',0,'active','2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_contacts` VALUES (7,9,'Dana','Kowalski','client4@example.com','+1-555-0104','Owner',1,'active','2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_contacts` VALUES (8,9,'Billing','Kowalski','billing4@example.com','+1-555-0204','Billing',0,'active','2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_contacts` VALUES (9,10,'Emeka','Santos','client5@example.com','+1-555-0105','Owner',1,'active','2026-08-08 04:54:13','2026-08-09 01:49:35');
INSERT INTO `customer_contacts` VALUES (10,10,'Billing','Santos','billing5@example.com','+1-555-0205','Billing',0,'active','2026-08-08 04:54:13','2026-08-09 01:49:35');
/*!40000 ALTER TABLE `customer_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_notes`
--

DROP TABLE IF EXISTS `customer_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `note` text NOT NULL,
  `is_important` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_notes_customer_id_index` (`customer_id`),
  KEY `customer_notes_user_id_index` (`user_id`),
  CONSTRAINT `customer_notes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_notes`
--

LOCK TABLES `customer_notes` WRITE;
/*!40000 ALTER TABLE `customer_notes` DISABLE KEYS */;
INSERT INTO `customer_notes` VALUES (1,6,1,'Welcome call completed for Northwind Labs. Demo account 1.',0,'2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_notes` VALUES (2,6,1,'Prefers email contact at client1@example.com; billing queries go to billing1@example.com.',0,'2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_notes` VALUES (3,6,1,'Renewal review scheduled for northwind.test.',1,'2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_notes` VALUES (4,7,1,'Welcome call completed for Blue Harbor Media. Demo account 2.',0,'2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_notes` VALUES (5,7,1,'Prefers email contact at client2@example.com; billing queries go to billing2@example.com.',0,'2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_notes` VALUES (6,7,1,'Renewal review scheduled for blueharbor.test.',1,'2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customer_notes` VALUES (7,8,1,'Welcome call completed for Cedar Point Retail. Demo account 3.',0,'2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (8,8,1,'Prefers email contact at client3@example.com; billing queries go to billing3@example.com.',0,'2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (9,8,1,'Renewal review scheduled for cedarpoint.test.',1,'2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (10,9,1,'Welcome call completed for Driftwood Studios. Demo account 4.',0,'2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (11,9,1,'Prefers email contact at client4@example.com; billing queries go to billing4@example.com.',0,'2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (12,9,1,'Renewal review scheduled for driftwood.test.',1,'2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (13,10,1,'Welcome call completed for Everline Freight. Demo account 5.',0,'2026-08-08 04:54:13','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (14,10,1,'Prefers email contact at client5@example.com; billing queries go to billing5@example.com.',0,'2026-08-08 04:54:13','2026-08-09 01:49:35');
INSERT INTO `customer_notes` VALUES (15,10,1,'Renewal review scheduled for everline.test.',1,'2026-08-08 04:54:13','2026-08-09 01:49:35');
/*!40000 ALTER TABLE `customer_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_wallet`
--

DROP TABLE IF EXISTS `customer_wallet`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_wallet` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `type` enum('deposit','credit','debit','invoice_payment') NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_type` enum('account','credit') NOT NULL DEFAULT 'account',
  `description` text DEFAULT NULL,
  `admin_user_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `customer_wallet_customer_id_index` (`customer_id`),
  KEY `customer_wallet_type_index` (`type`),
  KEY `customer_wallet_admin_user_id_index` (`admin_user_id`),
  KEY `customer_wallet_invoice_id_index` (`invoice_id`),
  CONSTRAINT `customer_wallet_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_wallet`
--

LOCK TABLES `customer_wallet` WRITE;
/*!40000 ALTER TABLE `customer_wallet` DISABLE KEYS */;
INSERT INTO `customer_wallet` VALUES (1,6,'deposit',250.00,'account','Opening demo deposit for customer 1',NULL,NULL,'2026-08-08 04:54:11');
INSERT INTO `customer_wallet` VALUES (2,6,'invoice_payment',75.00,'account','Demo invoice settlement for customer 1',NULL,NULL,'2026-08-08 04:54:11');
INSERT INTO `customer_wallet` VALUES (3,7,'deposit',500.00,'account','Opening demo deposit for customer 2',NULL,NULL,'2026-08-08 04:54:11');
INSERT INTO `customer_wallet` VALUES (4,7,'invoice_payment',150.00,'account','Demo invoice settlement for customer 2',NULL,NULL,'2026-08-08 04:54:11');
INSERT INTO `customer_wallet` VALUES (5,8,'deposit',750.00,'account','Opening demo deposit for customer 3',NULL,NULL,'2026-08-08 04:54:12');
INSERT INTO `customer_wallet` VALUES (6,8,'invoice_payment',225.00,'account','Demo invoice settlement for customer 3',NULL,NULL,'2026-08-08 04:54:12');
INSERT INTO `customer_wallet` VALUES (7,9,'deposit',1000.00,'account','Opening demo deposit for customer 4',NULL,NULL,'2026-08-08 04:54:12');
INSERT INTO `customer_wallet` VALUES (8,9,'invoice_payment',300.00,'account','Demo invoice settlement for customer 4',NULL,NULL,'2026-08-08 04:54:12');
INSERT INTO `customer_wallet` VALUES (9,10,'deposit',1250.00,'account','Opening demo deposit for customer 5',NULL,NULL,'2026-08-08 04:54:13');
INSERT INTO `customer_wallet` VALUES (10,10,'invoice_payment',375.00,'account','Demo invoice settlement for customer 5',NULL,NULL,'2026-08-08 04:54:13');
INSERT INTO `customer_wallet` VALUES (11,6,'deposit',500.00,'account','Demo wallet top-up via bank transfer.',NULL,NULL,'2026-08-09 00:19:02');
INSERT INTO `customer_wallet` VALUES (12,7,'invoice_payment',250.00,'account','Demo wallet debit for invoice settlement.',NULL,2,'2026-08-09 00:19:02');
INSERT INTO `customer_wallet` VALUES (13,8,'deposit',1000.00,'credit','Demo credit wallet top-up.',NULL,NULL,'2026-08-09 00:19:02');
/*!40000 ALTER TABLE `customer_wallet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `company` varchar(255) DEFAULT NULL,
  `tax_id` varchar(255) DEFAULT NULL,
  `state_code` varchar(2) DEFAULT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_user_id_unique` (`user_id`),
  KEY `customers_status_index` (`status`),
  CONSTRAINT `customers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (5,6,'DTPL',NULL,NULL,0.00,0.00,'active','2026-08-07 09:32:08','2026-08-07 09:32:08');
INSERT INTO `customers` VALUES (6,7,'Northwind Labs','DEMO-TAX-0001','27',100.00,25.00,'active','2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customers` VALUES (7,8,'Blue Harbor Media','DEMO-TAX-0002','29',200.00,50.00,'active','2026-08-08 04:54:11','2026-08-09 01:49:34');
INSERT INTO `customers` VALUES (8,9,'Cedar Point Retail','DEMO-TAX-0003','09',300.00,75.00,'active','2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customers` VALUES (9,10,'Driftwood Studios','DEMO-TAX-0004','36',400.00,100.00,'active','2026-08-08 04:54:12','2026-08-09 01:49:35');
INSERT INTO `customers` VALUES (10,11,'Everline Freight','DEMO-TAX-0005','19',500.00,125.00,'active','2026-08-08 04:54:13','2026-08-09 01:49:35');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `datacenters`
--

DROP TABLE IF EXISTS `datacenters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `datacenters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(10) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `datacenters_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `datacenters`
--

LOCK TABLES `datacenters` WRITE;
/*!40000 ALTER TABLE `datacenters` DISABLE KEYS */;
INSERT INTO `datacenters` VALUES (1,'Primary DC','DC01',NULL,'New York',NULL,'US','America/New_York','active','2026-08-09 01:49:30','2026-08-09 01:49:30');
INSERT INTO `datacenters` VALUES (2,'Frankfurt Edge','DC02','22 Beispielstrasse','Frankfurt','Hessen','DE','Europe/Berlin','active','2026-08-08 04:54:01','2026-08-08 04:54:01');
INSERT INTO `datacenters` VALUES (3,'Mumbai South','DC03','404 Example Marg','Mumbai','Maharashtra','IN','Asia/Kolkata','maintenance','2026-08-08 04:54:01','2026-08-08 04:54:01');
/*!40000 ALTER TABLE `datacenters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dns_records`
--

DROP TABLE IF EXISTS `dns_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dns_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `zone_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('A','AAAA','CNAME','MX','NS','TXT','SRV','PTR','SOA') NOT NULL,
  `content` varchar(500) NOT NULL,
  `ttl` int(11) NOT NULL DEFAULT 3600,
  `priority` int(11) NOT NULL DEFAULT 0,
  `service_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dns_records_zone_id_name_type_content_unique` (`zone_id`,`name`,`type`,`content`),
  CONSTRAINT `dns_records_zone_id_foreign` FOREIGN KEY (`zone_id`) REFERENCES `dns_zones` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dns_records`
--

LOCK TABLES `dns_records` WRITE;
/*!40000 ALTER TABLE `dns_records` DISABLE KEYS */;
INSERT INTO `dns_records` VALUES (1,1,'@','A','192.0.2.10',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (2,1,'www','CNAME','@',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (3,1,'@','MX','mail.demoshop.test',3600,10,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (4,1,'@','TXT','v=spf1 include:_spf.demo.example ~all',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (5,1,'@','NS','ns1.demo.example',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (6,2,'@','A','192.0.2.11',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (7,2,'@','NS','ns1.demo.example',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (8,2,'mail','A','192.0.2.12',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (9,3,'@','A','192.0.2.13',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (10,3,'www','A','192.0.2.14',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (11,4,'10','PTR','demoshop.test.',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (12,4,'11','PTR','demoblog.test.',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (13,4,'12','PTR','mail.demoshop.test.',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (14,5,'10','PTR','demoagency.test.',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_records` VALUES (15,5,'11','PTR','demovps.test.',3600,0,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
/*!40000 ALTER TABLE `dns_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dns_zones`
--

DROP TABLE IF EXISTS `dns_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dns_zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `zone_type` enum('forward','reverse') NOT NULL DEFAULT 'forward',
  `serial` bigint(20) NOT NULL DEFAULT 0,
  `refresh` int(11) NOT NULL DEFAULT 3600,
  `retry` int(11) NOT NULL DEFAULT 900,
  `expire` int(11) NOT NULL DEFAULT 604800,
  `ttl` int(11) NOT NULL DEFAULT 86400,
  `master_nameserver` varchar(255) DEFAULT NULL,
  `admin_email` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dns_zones_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dns_zones`
--

LOCK TABLES `dns_zones` WRITE;
/*!40000 ALTER TABLE `dns_zones` DISABLE KEYS */;
INSERT INTO `dns_zones` VALUES (1,'demoshop.test','forward',2026080801,3600,900,604800,86400,'ns1.demo.example','hostmaster@demoshop.test','active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_zones` VALUES (2,'demoblog.test','forward',2026080801,3600,900,604800,86400,'ns1.demo.example','hostmaster@demoblog.test','active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_zones` VALUES (3,'demoagency.test','forward',2026080801,3600,900,604800,86400,'ns1.demo.example','hostmaster@demoagency.test','active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_zones` VALUES (4,'2.0.192.in-addr.arpa','reverse',2026080801,3600,900,604800,86400,NULL,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `dns_zones` VALUES (5,'100.51.198.in-addr.arpa','reverse',2026080801,3600,900,604800,86400,NULL,NULL,'active','2026-08-08 05:22:32','2026-08-08 05:22:32');
/*!40000 ALTER TABLE `dns_zones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `domain_pricing`
--

DROP TABLE IF EXISTS `domain_pricing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_pricing` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tld` varchar(255) NOT NULL,
  `register_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `renew_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `transfer_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `premium` tinyint(1) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_pricing_tld_unique` (`tld`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `domain_pricing`
--

LOCK TABLES `domain_pricing` WRITE;
/*!40000 ALTER TABLE `domain_pricing` DISABLE KEYS */;
INSERT INTO `domain_pricing` VALUES (1,'qa',850.00,850.00,850.00,'INR',0,1,NULL,NULL,'2026-08-07 06:26:47');
INSERT INTO `domain_pricing` VALUES (2,'com',899.00,999.00,899.00,'INR',0,1,NULL,'2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domain_pricing` VALUES (3,'net',1099.00,1199.00,1099.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (4,'org',999.00,1099.00,999.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (5,'in',649.00,749.00,649.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (6,'co.in',549.00,649.00,549.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (7,'info',1299.00,1499.00,1299.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (8,'biz',1199.00,1399.00,1199.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (9,'dev',1499.00,1599.00,1499.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (10,'io',3999.00,4299.00,3999.00,'INR',1,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing` VALUES (11,'store',2499.00,2799.00,2499.00,'INR',0,1,NULL,'2026-08-08 06:14:27','2026-08-08 06:14:27');
/*!40000 ALTER TABLE `domain_pricing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `domain_pricing_terms`
--

DROP TABLE IF EXISTS `domain_pricing_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_pricing_terms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `domain_pricing_id` bigint(20) unsigned NOT NULL,
  `term_years` smallint(5) unsigned NOT NULL,
  `register_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `renew_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_pricing_terms_domain_pricing_id_term_years_unique` (`domain_pricing_id`,`term_years`),
  CONSTRAINT `domain_pricing_terms_domain_pricing_id_foreign` FOREIGN KEY (`domain_pricing_id`) REFERENCES `domain_pricing` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `domain_pricing_terms`
--

LOCK TABLES `domain_pricing_terms` WRITE;
/*!40000 ALTER TABLE `domain_pricing_terms` DISABLE KEYS */;
INSERT INTO `domain_pricing_terms` VALUES (1,2,1,899.00,999.00,'2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domain_pricing_terms` VALUES (2,2,2,1762.04,1958.04,'2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domain_pricing_terms` VALUES (3,2,5,4135.40,4595.40,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (4,2,10,7641.50,8491.50,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (5,3,1,1099.00,1199.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (6,3,2,2154.04,2350.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (7,3,5,5055.40,5515.40,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (8,4,1,999.00,1099.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (9,4,2,1958.04,2154.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (10,4,5,4595.40,5055.40,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (11,5,1,649.00,749.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (12,5,2,1272.04,1468.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (13,5,5,2985.40,3445.40,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (14,5,10,5516.50,6366.50,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (15,6,1,549.00,649.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (16,6,2,1076.04,1272.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (17,7,1,1299.00,1499.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (18,7,2,2546.04,2938.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (19,8,1,1199.00,1399.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (20,8,2,2350.04,2742.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (21,9,1,1499.00,1599.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (22,9,2,2938.04,3134.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (23,10,1,3999.00,4299.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (24,10,2,7838.04,8426.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (25,11,1,2499.00,2799.00,'2026-08-08 06:14:27','2026-08-08 06:14:27');
INSERT INTO `domain_pricing_terms` VALUES (26,11,2,4898.04,5486.04,'2026-08-08 06:14:27','2026-08-08 06:14:27');
/*!40000 ALTER TABLE `domain_pricing_terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `domain_search_logs`
--

DROP TABLE IF EXISTS `domain_search_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_search_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `domain_name` varchar(255) NOT NULL,
  `results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`results`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `domain_search_logs_customer_id_index` (`customer_id`),
  KEY `domain_search_logs_domain_name_index` (`domain_name`),
  KEY `domain_search_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `domain_search_logs`
--

LOCK TABLES `domain_search_logs` WRITE;
/*!40000 ALTER TABLE `domain_search_logs` DISABLE KEYS */;
INSERT INTO `domain_search_logs` VALUES (1,6,'demoshop.test','{\"available\":false,\"currency\":\"INR\",\"domain\":\"demoshop.test\",\"price\":null,\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (2,6,'demoshop-store.test','{\"available\":true,\"currency\":\"INR\",\"domain\":\"demoshop-store.test\",\"price\":\"899.00\",\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (3,7,'demoblog.test','{\"available\":false,\"currency\":\"INR\",\"domain\":\"demoblog.test\",\"price\":null,\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (4,7,'demoblog-news.test','{\"available\":true,\"currency\":\"INR\",\"domain\":\"demoblog-news.test\",\"price\":\"899.00\",\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (5,8,'demoagency.test','{\"available\":false,\"currency\":\"INR\",\"domain\":\"demoagency.test\",\"price\":null,\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (6,8,'demoagency-hq.test','{\"available\":true,\"currency\":\"INR\",\"domain\":\"demoagency-hq.test\",\"price\":\"899.00\",\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (7,9,'demovps-cloud.test','{\"available\":true,\"currency\":\"INR\",\"domain\":\"demovps-cloud.test\",\"price\":\"899.00\",\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (8,10,'demolab-research.test','{\"available\":true,\"currency\":\"INR\",\"domain\":\"demolab-research.test\",\"price\":\"899.00\",\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (9,NULL,'demoanon-one.test','{\"available\":true,\"currency\":\"INR\",\"domain\":\"demoanon-one.test\",\"price\":\"899.00\",\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
INSERT INTO `domain_search_logs` VALUES (10,NULL,'demoanon-two.test','{\"available\":false,\"currency\":\"INR\",\"domain\":\"demoanon-two.test\",\"price\":null,\"registrar\":\"resellerclub\",\"tld\":\"test\"}','2026-08-08 06:14:27');
/*!40000 ALTER TABLE `domain_search_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `domain_sync_log`
--

DROP TABLE IF EXISTS `domain_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_sync_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) NOT NULL,
  `operation` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `domain_sync_log`
--

LOCK TABLES `domain_sync_log` WRITE;
/*!40000 ALTER TABLE `domain_sync_log` DISABLE KEYS */;
INSERT INTO `domain_sync_log` VALUES (1,'resellerclub','pricing_sync','success','{\"currency\":\"INR\",\"mode\":\"full\",\"tld_count\":10}',NULL,'2026-08-08 06:14:27');
INSERT INTO `domain_sync_log` VALUES (2,'resellerclub','domain_sync','success','{\"domains\":3,\"mode\":\"incremental\"}',NULL,'2026-08-08 06:14:27');
INSERT INTO `domain_sync_log` VALUES (3,'openprovider','pricing_sync','success','{\"currency\":\"INR\",\"mode\":\"full\",\"tld_count\":4}',NULL,'2026-08-08 06:14:27');
INSERT INTO `domain_sync_log` VALUES (4,'openprovider','transfer_poll','failed','{\"domains\":1,\"mode\":\"poll\"}','Registrar returned HTTP 503 (demo fixture).','2026-08-08 06:14:27');
INSERT INTO `domain_sync_log` VALUES (5,'cloudflare','nameserver_sync','success','{\"domains\":1,\"mode\":\"push\"}',NULL,'2026-08-08 06:14:27');
/*!40000 ALTER TABLE `domain_sync_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `domains`
--

DROP TABLE IF EXISTS `domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('register','transfer','existing') NOT NULL DEFAULT 'register',
  `registrar_id` varchar(255) DEFAULT NULL,
  `registrar` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `registration_period` int(11) NOT NULL DEFAULT 1,
  `expiry_date` date DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `next_invoice_date` date DEFAULT NULL,
  `recurring_amount` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `subscription_id` varchar(255) DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT 1,
  `privacy_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `nameservers` text DEFAULT NULL,
  `dns_records` text DEFAULT NULL,
  `auth_code` varchar(255) DEFAULT NULL,
  `lock_status` tinyint(1) NOT NULL DEFAULT 1,
  `dns_management` tinyint(1) NOT NULL DEFAULT 0,
  `email_forwarding` tinyint(1) NOT NULL DEFAULT 0,
  `id_protection` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('pending','active','suspended','expired','cancelled','transferred','pending_transfer','redemption') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domains_customer_id_index` (`customer_id`),
  KEY `domains_name_index` (`name`),
  KEY `domains_expiry_date_index` (`expiry_date`),
  KEY `domains_status_index` (`status`),
  CONSTRAINT `domains_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `domains`
--

LOCK TABLES `domains` WRITE;
/*!40000 ALTER TABLE `domains` DISABLE KEYS */;
INSERT INTO `domains` VALUES (1,6,1,'demoshop.test','register','DEMO-REG-0001','resellerclub','2026-01-20',1,'2027-01-20','2027-01-20','2026-12-21',899.00,'razorpay',NULL,1,1,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,1,1,0,1,'active','2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domains` VALUES (2,7,2,'demoblog.test','register','DEMO-REG-0002','resellerclub','2025-07-04',2,'2027-07-04','2027-07-04','2027-06-04',849.00,'razorpay',NULL,1,0,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,1,1,0,0,'active','2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domains` VALUES (3,8,3,'demoagency.test','transfer','DEMO-REG-0003','openprovider','2026-04-10',1,'2027-04-10','2027-04-10','2027-03-11',1299.00,'razorpay',NULL,1,1,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,1,1,0,1,'active','2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domains` VALUES (4,9,4,'demovps.test','register','DEMO-REG-0004','resellerclub','2026-08-05',1,'2027-08-05','2027-08-05','2027-07-06',899.00,'razorpay',NULL,0,0,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,1,1,0,0,'pending','2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domains` VALUES (5,10,5,'demolab.test','register','DEMO-REG-0005','openprovider','2025-09-12',1,'2026-09-12','2026-09-12','2026-08-13',899.00,'razorpay',NULL,0,0,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,1,1,0,0,'suspended','2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domains` VALUES (6,6,6,'demoarchive.test','existing','DEMO-REG-0006','resellerclub','2025-06-14',1,'2026-06-14','2026-06-14','2026-05-15',899.00,'razorpay',NULL,0,0,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,1,1,0,0,'expired','2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domains` VALUES (7,7,7,'demodedi.test','register','DEMO-REG-0007','cloudflare','2024-09-07',5,'2029-09-07','2029-09-07','2029-08-08',3999.00,'razorpay',NULL,1,1,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,1,1,0,1,'active','2026-08-08 06:14:26','2026-08-08 06:14:26');
INSERT INTO `domains` VALUES (8,8,8,'demoaddon.test','transfer','DEMO-REG-0008','openprovider','2026-07-29',1,'2027-07-29','2027-07-29','2027-06-29',1299.00,'razorpay',NULL,1,0,'[\"ns1.demo.example\",\"ns2.demo.example\"]',NULL,NULL,0,1,0,0,'pending_transfer','2026-08-08 06:14:26','2026-08-08 06:14:26');
/*!40000 ALTER TABLE `domains` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_queue`
--

DROP TABLE IF EXISTS `email_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_queue_status_index` (`status`),
  KEY `email_queue_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_queue`
--

LOCK TABLES `email_queue` WRITE;
/*!40000 ALTER TABLE `email_queue` DISABLE KEYS */;
INSERT INTO `email_queue` VALUES (1,'some@gmail.com','Your invoice DEMO-INV-2026-0001 is ready','Demo queued e-mail to some@gmail.com re: Your invoice DEMO-INV-2026-0001 is ready.\n\nThis is a seeded demo e-mail for testing.','pending',0,NULL,'2026-06-30 19:30:00',NULL);
INSERT INTO `email_queue` VALUES (2,'client1@example.com','Reminder: invoice DEMO-INV-2026-0003 is overdue','Demo queued e-mail to client1@example.com re: Reminder: invoice DEMO-INV-2026-0003 is overdue.\n\nThis is a seeded demo e-mail for testing.','pending',0,NULL,'2026-06-30 20:30:00',NULL);
INSERT INTO `email_queue` VALUES (3,'client2@example.com','Payment confirmation for DEMO-INV-2026-0005','Demo queued e-mail to client2@example.com re: Payment confirmation for DEMO-INV-2026-0005.\n\nThis is a seeded demo e-mail for testing.','sent',1,NULL,'2026-06-30 21:30:00','2026-07-02 08:00:00');
INSERT INTO `email_queue` VALUES (4,'client3@example.com','Your invoice DEMO-INV-2026-0006 is ready','Demo queued e-mail to client3@example.com re: Your invoice DEMO-INV-2026-0006 is ready.\n\nThis is a seeded demo e-mail for testing.','sent',2,NULL,'2026-06-30 22:30:00','2026-07-02 09:00:00');
INSERT INTO `email_queue` VALUES (5,'client4@example.com','Delivery failure notice for DEMO-INV-2026-0008','Demo queued e-mail to client4@example.com re: Delivery failure notice for DEMO-INV-2026-0008.\n\nThis is a seeded demo e-mail for testing.','failed',2,'SMTP 550: mailbox unavailable','2026-06-30 23:30:00',NULL);
INSERT INTO `email_queue` VALUES (6,'client1@example.com','Your invoice DEMO-INV-2026-0001 is ready','Demo queued e-mail to client1@example.com re: Your invoice DEMO-INV-2026-0001 is ready.\n\nThis is a seeded demo e-mail for testing.','pending',0,NULL,'2026-06-30 19:30:00',NULL);
INSERT INTO `email_queue` VALUES (7,'client2@example.com','Reminder: invoice DEMO-INV-2026-0003 is overdue','Demo queued e-mail to client2@example.com re: Reminder: invoice DEMO-INV-2026-0003 is overdue.\n\nThis is a seeded demo e-mail for testing.','pending',0,NULL,'2026-06-30 20:30:00',NULL);
INSERT INTO `email_queue` VALUES (8,'client3@example.com','Payment confirmation for DEMO-INV-2026-0005','Demo queued e-mail to client3@example.com re: Payment confirmation for DEMO-INV-2026-0005.\n\nThis is a seeded demo e-mail for testing.','sent',1,NULL,'2026-06-30 21:30:00','2026-07-02 08:00:00');
INSERT INTO `email_queue` VALUES (9,'client4@example.com','Your invoice DEMO-INV-2026-0006 is ready','Demo queued e-mail to client4@example.com re: Your invoice DEMO-INV-2026-0006 is ready.\n\nThis is a seeded demo e-mail for testing.','sent',2,NULL,'2026-06-30 22:30:00','2026-07-02 09:00:00');
INSERT INTO `email_queue` VALUES (10,'client5@example.com','Delivery failure notice for DEMO-INV-2026-0008','Demo queued e-mail to client5@example.com re: Delivery failure notice for DEMO-INV-2026-0008.\n\nThis is a seeded demo e-mail for testing.','failed',2,'SMTP 550: mailbox unavailable','2026-06-30 23:30:00',NULL);
/*!40000 ALTER TABLE `email_queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_templates`
--

DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_templates_name_unique` (`name`),
  KEY `email_templates_name_index` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_templates`
--

LOCK TABLES `email_templates` WRITE;
/*!40000 ALTER TABLE `email_templates` DISABLE KEYS */;
INSERT INTO `email_templates` VALUES (7,'welcome','Welcome to Demo Hosting!','Hi {{name}},\n\nWelcome to Demo Hosting! Your account is ready to use.\n\nThanks,\nDemo Hosting Team','active','2026-06-30 19:30:00','2026-06-30 19:30:00');
INSERT INTO `email_templates` VALUES (8,'invoice_created','Your demo invoice is ready','Hi {{name}},\n\nYour demo invoice {{invoice_no}} is ready. Please review and pay it by {{due_date}}.\n\nThanks,\nDemo Hosting Team','active','2026-06-30 20:30:00','2026-06-30 20:30:00');
INSERT INTO `email_templates` VALUES (9,'invoice_overdue_reminder','Demo invoice overdue reminder','Hi {{name}},\n\nYour demo invoice {{invoice_no}} is overdue. Please arrange payment to avoid service interruption.\n\nThanks,\nDemo Hosting Team','active','2026-06-30 21:30:00','2026-06-30 21:30:00');
INSERT INTO `email_templates` VALUES (10,'payment_received','Payment received - thank you!','Hi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team','active','2026-06-30 22:30:00','2026-06-30 22:30:00');
INSERT INTO `email_templates` VALUES (11,'password_reset','Reset your Demo Hosting password','Hi {{name}},\n\nUse the link below to reset your Demo Hosting password.\n\nThanks,\nDemo Hosting Team','inactive','2026-06-30 23:30:00','2026-06-30 23:30:00');
INSERT INTO `email_templates` VALUES (12,'support_ticket_reply','New reply on your support ticket','Hi {{name}},\n\nThere is a new reply on your support ticket {{ticket_no}}.\n\nThanks,\nDemo Hosting Team','active','2026-07-01 00:30:00','2026-07-01 00:30:00');
/*!40000 ALTER TABLE `email_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `emails`
--

DROP TABLE IF EXISTS `emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(500) NOT NULL,
  `body` text DEFAULT NULL,
  `template_name` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed','queued') NOT NULL DEFAULT 'sent',
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `emails_customer_id_index` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `emails`
--

LOCK TABLES `emails` WRITE;
/*!40000 ALTER TABLE `emails` DISABLE KEYS */;
INSERT INTO `emails` VALUES (1,5,'some@gmail.com','Your invoice DEMO-INV-2026-0001 is ready','Demo e-mail for some@gmail.com using the \"invoice_created\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is ready. Please review and pay it by {{due_date}}.\n\nThanks,\nDemo Hosting Team','invoice_created','sent',NULL,'2026-06-30 19:30:00','2026-06-30 19:30:00');
INSERT INTO `emails` VALUES (2,5,'some@gmail.com','Payment received for DEMO-INV-2026-0002','Demo e-mail for some@gmail.com using the \"payment_received\" template.\n\nHi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team','payment_received','sent',NULL,'2026-06-30 20:30:00','2026-06-30 20:30:00');
INSERT INTO `emails` VALUES (3,6,'client1@example.com','Welcome to Demo Hosting!','Demo e-mail for client1@example.com using the \"welcome\" template.\n\nHi {{name}},\n\nWelcome to Demo Hosting! Your account is ready to use.\n\nThanks,\nDemo Hosting Team','welcome','sent',NULL,'2026-06-30 21:30:00','2026-06-30 21:30:00');
INSERT INTO `emails` VALUES (4,6,'client1@example.com','Reminder: invoice DEMO-INV-2026-0003 is overdue','Demo e-mail for client1@example.com using the \"invoice_overdue_reminder\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is overdue. Please arrange payment to avoid service interruption.\n\nThanks,\nDemo Hosting Team','invoice_overdue_reminder','sent',NULL,'2026-06-30 22:30:00','2026-06-30 22:30:00');
INSERT INTO `emails` VALUES (5,7,'client2@example.com','New reply on support ticket #TKT-DEMO-0001','Demo e-mail for client2@example.com using the \"support_ticket_reply\" template.\n\nHi {{name}},\n\nThere is a new reply on your support ticket {{ticket_no}}.\n\nThanks,\nDemo Hosting Team','support_ticket_reply','sent',NULL,'2026-06-30 23:30:00','2026-06-30 23:30:00');
INSERT INTO `emails` VALUES (6,7,'client2@example.com','Payment received for DEMO-INV-2026-0005','Demo e-mail for client2@example.com using the \"payment_received\" template.\n\nHi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team','payment_received','queued',NULL,'2026-07-01 00:30:00','2026-07-01 00:30:00');
INSERT INTO `emails` VALUES (7,8,'client3@example.com','Your invoice DEMO-INV-2026-0006 is ready','Demo e-mail for client3@example.com using the \"invoice_created\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is ready. Please review and pay it by {{due_date}}.\n\nThanks,\nDemo Hosting Team','invoice_created','queued',NULL,'2026-07-01 01:30:00','2026-07-01 01:30:00');
INSERT INTO `emails` VALUES (8,8,'client3@example.com','Reset your Demo Hosting password','Demo e-mail for client3@example.com using the \"password_reset\" template.\n\nHi {{name}},\n\nUse the link below to reset your Demo Hosting password.\n\nThanks,\nDemo Hosting Team','password_reset','sent',NULL,'2026-07-01 02:30:00','2026-07-01 02:30:00');
INSERT INTO `emails` VALUES (9,9,'client4@example.com','Payment received for DEMO-INV-2026-0008','Demo e-mail for client4@example.com using the \"payment_received\" template.\n\nHi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team','payment_received','sent',NULL,'2026-07-01 03:30:00','2026-07-01 03:30:00');
INSERT INTO `emails` VALUES (10,10,'client5@example.com','Delivery failed: invoice DEMO-INV-2026-0010','Demo e-mail for client5@example.com using the \"invoice_overdue_reminder\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is overdue. Please arrange payment to avoid service interruption.\n\nThanks,\nDemo Hosting Team','invoice_overdue_reminder','failed','SMTP 550: recipient rejected','2026-07-01 04:30:00','2026-07-01 04:30:00');
INSERT INTO `emails` VALUES (11,6,'client1@example.com','Your invoice DEMO-INV-2026-0001 is ready','Demo e-mail for client1@example.com using the \"invoice_created\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is ready. Please review and pay it by {{due_date}}.\n\nThanks,\nDemo Hosting Team','invoice_created','sent',NULL,'2026-06-30 19:30:00','2026-06-30 19:30:00');
INSERT INTO `emails` VALUES (12,6,'client1@example.com','Payment received for DEMO-INV-2026-0002','Demo e-mail for client1@example.com using the \"payment_received\" template.\n\nHi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team','payment_received','sent',NULL,'2026-06-30 20:30:00','2026-06-30 20:30:00');
INSERT INTO `emails` VALUES (13,7,'client2@example.com','Welcome to Demo Hosting!','Demo e-mail for client2@example.com using the \"welcome\" template.\n\nHi {{name}},\n\nWelcome to Demo Hosting! Your account is ready to use.\n\nThanks,\nDemo Hosting Team','welcome','sent',NULL,'2026-06-30 21:30:00','2026-06-30 21:30:00');
INSERT INTO `emails` VALUES (14,7,'client2@example.com','Reminder: invoice DEMO-INV-2026-0003 is overdue','Demo e-mail for client2@example.com using the \"invoice_overdue_reminder\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is overdue. Please arrange payment to avoid service interruption.\n\nThanks,\nDemo Hosting Team','invoice_overdue_reminder','sent',NULL,'2026-06-30 22:30:00','2026-06-30 22:30:00');
INSERT INTO `emails` VALUES (15,8,'client3@example.com','New reply on support ticket #TKT-DEMO-0001','Demo e-mail for client3@example.com using the \"support_ticket_reply\" template.\n\nHi {{name}},\n\nThere is a new reply on your support ticket {{ticket_no}}.\n\nThanks,\nDemo Hosting Team','support_ticket_reply','sent',NULL,'2026-06-30 23:30:00','2026-06-30 23:30:00');
INSERT INTO `emails` VALUES (16,8,'client3@example.com','Payment received for DEMO-INV-2026-0005','Demo e-mail for client3@example.com using the \"payment_received\" template.\n\nHi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team','payment_received','queued',NULL,'2026-07-01 00:30:00','2026-07-01 00:30:00');
INSERT INTO `emails` VALUES (17,9,'client4@example.com','Your invoice DEMO-INV-2026-0006 is ready','Demo e-mail for client4@example.com using the \"invoice_created\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is ready. Please review and pay it by {{due_date}}.\n\nThanks,\nDemo Hosting Team','invoice_created','queued',NULL,'2026-07-01 01:30:00','2026-07-01 01:30:00');
INSERT INTO `emails` VALUES (18,9,'client4@example.com','Reset your Demo Hosting password','Demo e-mail for client4@example.com using the \"password_reset\" template.\n\nHi {{name}},\n\nUse the link below to reset your Demo Hosting password.\n\nThanks,\nDemo Hosting Team','password_reset','sent',NULL,'2026-07-01 02:30:00','2026-07-01 02:30:00');
INSERT INTO `emails` VALUES (19,10,'client5@example.com','Payment received for DEMO-INV-2026-0008','Demo e-mail for client5@example.com using the \"payment_received\" template.\n\nHi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team','payment_received','sent',NULL,'2026-07-01 03:30:00','2026-07-01 03:30:00');
INSERT INTO `emails` VALUES (20,6,'client1@example.com','Delivery failed: invoice DEMO-INV-2026-0010','Demo e-mail for client1@example.com using the \"invoice_overdue_reminder\" template.\n\nHi {{name}},\n\nYour demo invoice {{invoice_no}} is overdue. Please arrange payment to avoid service interruption.\n\nThanks,\nDemo Hosting Team','invoice_overdue_reminder','failed','SMTP 550: recipient rejected','2026-07-01 04:30:00','2026-07-01 04:30:00');
/*!40000 ALTER TABLE `emails` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gst_settings`
--

DROP TABLE IF EXISTS `gst_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gst_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gstin` varchar(15) DEFAULT NULL,
  `legal_name` varchar(255) DEFAULT NULL,
  `state_code` varchar(2) NOT NULL DEFAULT '27',
  `state_name` varchar(255) NOT NULL DEFAULT 'Maharashtra',
  `cgst_rate` decimal(5,2) NOT NULL DEFAULT 9.00,
  `sgst_rate` decimal(5,2) NOT NULL DEFAULT 9.00,
  `igst_rate` decimal(5,2) NOT NULL DEFAULT 18.00,
  `hsn_code` varchar(255) NOT NULL DEFAULT '998314',
  `sac_code` varchar(255) NOT NULL DEFAULT '998314',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `tax_mode` enum('global','per_product','mixed') NOT NULL DEFAULT 'global',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gst_settings`
--

LOCK TABLES `gst_settings` WRITE;
/*!40000 ALTER TABLE `gst_settings` DISABLE KEYS */;
INSERT INTO `gst_settings` VALUES (1,NULL,NULL,'27','Maharashtra',9.00,9.00,18.00,'998314','998314',0,'global',NULL,NULL);
/*!40000 ALTER TABLE `gst_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hosting_accounts`
--

DROP TABLE IF EXISTS `hosting_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hosting_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `server_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `disk_quota` int(10) unsigned NOT NULL DEFAULT 0,
  `disk_used` int(10) unsigned NOT NULL DEFAULT 0,
  `bandwidth_quota` int(10) unsigned NOT NULL DEFAULT 0,
  `bandwidth_used` int(10) unsigned NOT NULL DEFAULT 0,
  `panel_account_id` varchar(255) DEFAULT NULL,
  `username_prefix` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `status` enum('pending','active','suspended','terminated') NOT NULL DEFAULT 'pending',
  `next_due_date` date DEFAULT NULL,
  `suspended_reason` text DEFAULT NULL,
  `suspended_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hosting_accounts_customer_id_index` (`customer_id`),
  KEY `hosting_accounts_product_id_index` (`product_id`),
  KEY `hosting_accounts_server_id_index` (`server_id`),
  KEY `hosting_accounts_status_index` (`status`),
  KEY `hosting_accounts_next_due_date_index` (`next_due_date`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hosting_accounts`
--

LOCK TABLES `hosting_accounts` WRITE;
/*!40000 ALTER TABLE `hosting_accounts` DISABLE KEYS */;
INSERT INTO `hosting_accounts` VALUES (1,6,7,1,NULL,'demoshop','demoshop.test',10240,3872,102400,41230,'DEMO-PANEL-0001','demo',NULL,'active','2026-09-08',NULL,NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `hosting_accounts` VALUES (2,7,8,1,NULL,'demoblog','demoblog.test',51200,18944,512000,210500,'DEMO-PANEL-0002','demo',NULL,'active','2026-10-08',NULL,NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `hosting_accounts` VALUES (3,8,9,2,NULL,'demoagency','demoagency.test',102400,47311,1024000,388904,'DEMO-PANEL-0003','demo',NULL,'active','2026-11-08',NULL,NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `hosting_accounts` VALUES (4,9,10,3,NULL,'demovps','demovps.test',40960,12200,2048000,640000,'DEMO-PANEL-0004','demo',NULL,'active','2026-09-08',NULL,NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `hosting_accounts` VALUES (5,10,11,4,NULL,'demolab','demolab.test',163840,158900,4096000,4096000,'DEMO-PANEL-0005','demo',NULL,'suspended','2026-07-08','Demo data: disk and bandwidth quota exhausted.','2026-07-30 10:40:34','2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `hosting_accounts` VALUES (6,6,15,2,NULL,'demoarchive','demoarchive.test',5120,0,51200,0,'DEMO-PANEL-0006','demo',NULL,'pending','2026-09-08',NULL,NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `hosting_accounts` VALUES (7,7,12,3,NULL,'demodedi','demodedi.test',1024000,204800,10240000,1536000,'DEMO-PANEL-0007','demo',NULL,'active','2026-09-08',NULL,NULL,'2026-08-08 05:20:35','2026-08-08 05:20:35');
INSERT INTO `hosting_accounts` VALUES (8,8,14,1,NULL,'demoaddon','demoaddon.test',20480,6144,204800,81920,'DEMO-PANEL-0008','demo',NULL,'active','2027-02-08',NULL,NULL,'2026-08-08 05:20:35','2026-08-08 05:20:35');
/*!40000 ALTER TABLE `hosting_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `impersonation_tokens`
--

DROP TABLE IF EXISTS `impersonation_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `impersonation_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) unsigned NOT NULL,
  `customer_user_id` bigint(20) unsigned NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `impersonation_tokens_token_unique` (`token`),
  KEY `impersonation_tokens_admin_user_id_index` (`admin_user_id`),
  KEY `impersonation_tokens_customer_user_id_index` (`customer_user_id`),
  KEY `impersonation_tokens_expires_at_index` (`expires_at`),
  CONSTRAINT `impersonation_tokens_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `impersonation_tokens_customer_user_id_foreign` FOREIGN KEY (`customer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `impersonation_tokens`
--

LOCK TABLES `impersonation_tokens` WRITE;
/*!40000 ALTER TABLE `impersonation_tokens` DISABLE KEYS */;
INSERT INTO `impersonation_tokens` VALUES (1,1,7,'demo-impersonation-token-0000000000000001','2026-07-01 06:00:00',NULL,'2026-06-30 00:30:00');
INSERT INTO `impersonation_tokens` VALUES (2,1,8,'demo-impersonation-token-0000000000000002','2026-06-30 06:00:00','2026-06-30 04:00:00','2026-06-29 00:30:00');
/*!40000 ALTER TABLE `impersonation_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_assets`
--

DROP TABLE IF EXISTS `inventory_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `asset_tag` varchar(255) NOT NULL,
  `serial_number` varchar(255) DEFAULT NULL,
  `asset_type` enum('server','ram_module','cpu','ssd','hdd','gpu','raid_controller','nic','switch','pdu','other_hardware','software_license','ipv4_address','ipv6_address','ssl_certificate','domain') NOT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `model` varchar(255) DEFAULT NULL,
  `vendor` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(12,2) DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `datacenter_id` int(10) unsigned DEFAULT NULL,
  `rack_id` int(10) unsigned DEFAULT NULL,
  `rack_u_position` int(10) unsigned DEFAULT NULL,
  `parent_asset_id` int(10) unsigned DEFAULT NULL,
  `status` enum('ordered','received','in_stock','installed','assigned','maintenance','retired','disposed') NOT NULL DEFAULT 'in_stock',
  `lifecycle_state` enum('ordered','received','in_stock','installed','assigned','maintenance','retired','disposed') NOT NULL DEFAULT 'ordered',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_assets_asset_tag_unique` (`asset_tag`),
  KEY `inventory_assets_asset_type_status_index` (`asset_type`,`status`),
  KEY `inventory_assets_datacenter_id_index` (`datacenter_id`),
  KEY `inventory_assets_rack_id_index` (`rack_id`),
  KEY `inventory_assets_parent_asset_id_index` (`parent_asset_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_assets`
--

LOCK TABLES `inventory_assets` WRITE;
/*!40000 ALTER TABLE `inventory_assets` DISABLE KEYS */;
INSERT INTO `inventory_assets` VALUES (1,'DEMO-SRV-0001','SN-DELL-R650-000001','server','Dell','PowerEdge R650','Dell Technologies','2024-02-14',489000.00,'2027-02-14',NULL,NULL,12,NULL,'installed','installed','Demo primary hypervisor host.','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (2,'DEMO-SRV-0002','SN-HPE-DL380-000002','server','HPE','ProLiant DL380 Gen10','Hewlett Packard Enterprise','2024-05-02',421500.00,'2027-05-02',NULL,NULL,14,NULL,'installed','installed','Demo secondary hypervisor host.','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (3,'DEMO-SWT-0001','SN-JNPR-EX4300-000003','switch','Juniper','EX4300-48T','Juniper Networks','2023-11-20',315000.00,'2026-11-20',NULL,NULL,40,NULL,'installed','installed','Demo top-of-rack access switch.','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (4,'DEMO-SWT-0002','SN-ARISTA-7050X-000004','switch','Arista','7050SX3-48YC8','Arista Networks','2024-01-09',528000.00,'2027-01-09',NULL,NULL,41,NULL,'in_stock','received','Demo spare aggregation switch, not yet cabled.','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (5,'DEMO-RTR-0001','SN-MIKROTIK-CCR2004-000005','other_hardware','MikroTik','CCR2004-16G-2S+','MikroTik','2023-09-01',62500.00,'2026-09-01',NULL,NULL,42,NULL,'installed','installed','Role: edge router (enum has no `router` member).','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (6,'DEMO-UPS-0001','SN-APC-SMT3000-000006','pdu','APC','Smart-UPS SMT3000RMI2U','Schneider Electric','2023-07-18',148000.00,'2026-07-18',NULL,NULL,1,NULL,'installed','installed','Role: rack UPS / power feed (enum has no `ups` member).','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (7,'DEMO-STO-0001','SN-SAMSUNG-PM9A3-000007','ssd','Samsung','PM9A3 7.68TB U.2','Samsung Semiconductor','2024-02-14',78400.00,'2029-02-14',NULL,NULL,NULL,1,'assigned','assigned','Role: NVMe storage tier (enum has no `storage` member).','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (8,'DEMO-STO-0002','SN-SEAGATE-EXOSX18-000008','hdd','Seagate','Exos X18 18TB','Seagate Technology','2024-05-02',34900.00,'2029-05-02',NULL,NULL,NULL,2,'assigned','assigned','Role: backup storage tier.','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (9,'DEMO-NIC-0001','SN-INTEL-X710-000009','nic','Intel','X710-DA2 10GbE','Intel','2024-02-14',22600.00,'2027-02-14',NULL,NULL,NULL,1,'assigned','assigned','Dual-port 10GbE uplink card.','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
INSERT INTO `inventory_assets` VALUES (10,'DEMO-RAM-0001','SN-MICRON-32GDDR4-000010','ram_module','Micron','MTA36ASF4G72PZ 32GB DDR4-3200','Micron Technology','2024-02-14',11200.00,'2029-02-14',NULL,NULL,NULL,1,'assigned','assigned','Registered ECC DIMM.','2026-08-08 04:57:30','2026-08-08 04:57:30',NULL);
/*!40000 ALTER TABLE `inventory_assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_items`
--

DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `gst_rate` decimal(5,2) DEFAULT NULL,
  `gst_type` enum('standard','exempt','reverse_charge') DEFAULT NULL,
  `cgst_rate` decimal(5,2) DEFAULT NULL,
  `cgst_amount` decimal(12,2) DEFAULT NULL,
  `sgst_rate` decimal(5,2) DEFAULT NULL,
  `sgst_amount` decimal(12,2) DEFAULT NULL,
  `igst_rate` decimal(5,2) DEFAULT NULL,
  `igst_amount` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_items_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_items`
--

LOCK TABLES `invoice_items` WRITE;
/*!40000 ALTER TABLE `invoice_items` DISABLE KEYS */;
INSERT INTO `invoice_items` VALUES (1,1,7,'Demo Starter Shared Hosting',1,199.00,199.00,1,18.00,'standard',9.00,17.91,9.00,17.91,NULL,NULL);
INSERT INTO `invoice_items` VALUES (2,1,13,'Demo .com Domain Registration',1,899.00,899.00,0,0.00,'exempt',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `invoice_items` VALUES (3,2,8,'Demo Business Shared Hosting',1,499.00,499.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,89.82);
INSERT INTO `invoice_items` VALUES (4,2,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,269.82);
INSERT INTO `invoice_items` VALUES (5,3,10,'Demo Cloud VPS 2GB',1,899.00,899.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,161.82);
INSERT INTO `invoice_items` VALUES (6,3,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,269.82);
INSERT INTO `invoice_items` VALUES (7,4,15,'Demo Legacy Hosting Pack',1,349.00,349.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,62.82);
INSERT INTO `invoice_items` VALUES (8,4,13,'Demo .com Domain Registration',1,899.00,899.00,0,0.00,'exempt',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `invoice_items` VALUES (9,5,13,'Demo .com Domain Registration',1,899.00,899.00,0,0.00,'exempt',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `invoice_items` VALUES (10,5,NULL,'Demo Setup & Onboarding Fee',1,999.00,999.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,179.82);
INSERT INTO `invoice_items` VALUES (11,6,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,0,0.00,'exempt',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `invoice_items` VALUES (12,6,NULL,'Demo Setup & Onboarding Fee',1,999.00,999.00,0,0.00,'exempt',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `invoice_items` VALUES (13,7,11,'Demo Cloud VPS 8GB',1,2499.00,2499.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,449.82);
INSERT INTO `invoice_items` VALUES (14,7,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,269.82);
INSERT INTO `invoice_items` VALUES (15,8,9,'Demo Reseller Bronze',1,1299.00,1299.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,233.82);
INSERT INTO `invoice_items` VALUES (16,8,8,'Demo Business Shared Hosting',1,499.00,499.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,89.82);
INSERT INTO `invoice_items` VALUES (17,9,12,'Demo Dedicated E3 Server',1,8999.00,8999.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,1619.82);
INSERT INTO `invoice_items` VALUES (18,9,13,'Demo .com Domain Registration',2,899.00,1798.00,0,0.00,'exempt',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO `invoice_items` VALUES (19,10,8,'Demo Business Shared Hosting',2,499.00,998.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,179.64);
INSERT INTO `invoice_items` VALUES (20,10,NULL,'Demo Priority Support Retainer',1,1999.00,1999.00,1,18.00,'standard',NULL,NULL,NULL,NULL,18.00,359.82);
/*!40000 ALTER TABLE `invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_pdf_log`
--

DROP TABLE IF EXISTS `invoice_pdf_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_pdf_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `generated_by` bigint(20) unsigned DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `mime_title` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_pdf_log_invoice_id_index` (`invoice_id`),
  KEY `invoice_pdf_log_customer_id_index` (`customer_id`),
  KEY `invoice_pdf_log_generated_by_index` (`generated_by`),
  CONSTRAINT `invoice_pdf_log_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_pdf_log_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_pdf_log`
--

LOCK TABLES `invoice_pdf_log` WRITE;
/*!40000 ALTER TABLE `invoice_pdf_log` DISABLE KEYS */;
INSERT INTO `invoice_pdf_log` VALUES (1,1,6,1,'invoice-DEMO-INV-2026-0001.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (2,2,7,1,'invoice-DEMO-INV-2026-0002.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (3,3,8,1,'invoice-DEMO-INV-2026-0003.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (4,4,9,1,'invoice-DEMO-INV-2026-0004.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (5,5,10,1,'invoice-DEMO-INV-2026-0005.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (6,6,6,1,'invoice-DEMO-INV-2026-0006.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (7,7,7,1,'invoice-DEMO-INV-2026-0007.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (8,8,8,1,'invoice-DEMO-INV-2026-0008.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
INSERT INTO `invoice_pdf_log` VALUES (9,10,10,1,'invoice-DEMO-INV-2026-0010.pdf',28110,'','application/pdf','2026-08-09 01:25:04','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `invoice_pdf_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(255) NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `cgst_rate` decimal(5,2) DEFAULT NULL,
  `cgst_amount` decimal(12,2) DEFAULT NULL,
  `sgst_rate` decimal(5,2) DEFAULT NULL,
  `sgst_amount` decimal(12,2) DEFAULT NULL,
  `igst_rate` decimal(5,2) DEFAULT NULL,
  `igst_amount` decimal(12,2) DEFAULT NULL,
  `status` enum('draft','sent','paid','overdue','partial','void','cancelled') NOT NULL DEFAULT 'draft',
  `due_date` date NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `last_reminder_at` timestamp NULL DEFAULT NULL,
  `reminder_count` int(10) unsigned NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_invoice_no_unique` (`invoice_no`),
  KEY `invoices_invoice_no_index` (`invoice_no`),
  KEY `invoices_customer_id_index` (`customer_id`),
  KEY `invoices_order_id_index` (`order_id`),
  KEY `invoices_status_index` (`status`),
  KEY `invoices_due_date_index` (`due_date`),
  KEY `invoices_paid_at_index` (`paid_at`),
  CONSTRAINT `invoices_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES (1,'DEMO-INV-2026-0001',6,4,1098.00,35.82,18.00,0.00,1133.82,0.00,1,9.00,17.91,9.00,17.91,NULL,NULL,'sent','2026-08-21',NULL,NULL,0,'Initial invoice for the onboarding order; awaiting payment.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (2,'DEMO-INV-2026-0002',7,5,1998.00,359.64,18.00,50.00,2307.64,2307.64,1,NULL,NULL,NULL,NULL,18.00,359.64,'paid','2026-07-22','2026-07-21 11:30:00',NULL,0,'Settled by card on the day of issue; loyalty discount applied.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (3,'DEMO-INV-2026-0003',8,6,2398.00,431.64,18.00,0.00,2829.64,0.00,1,NULL,NULL,NULL,NULL,18.00,431.64,'overdue','2026-07-19',NULL,'2026-08-06 03:30:00',2,'Past due; two dunning reminders sent, suspension pending.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (4,'DEMO-INV-2026-0004',9,7,1248.00,62.82,18.00,25.00,1285.82,0.00,1,NULL,NULL,NULL,NULL,18.00,62.82,'overdue','2026-07-06',NULL,'2026-08-06 03:30:00',2,'Service already suspended for non-payment; escalated to accounts.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (5,'DEMO-INV-2026-0005',10,8,1898.00,179.82,18.00,0.00,2077.82,2077.82,1,NULL,NULL,NULL,NULL,18.00,179.82,'paid','2026-07-31','2026-07-30 11:30:00',NULL,0,'Annual domain registration paid in full via bank transfer.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (6,'DEMO-INV-2026-0006',6,9,2498.00,0.00,0.00,0.00,2498.00,0.00,0,NULL,NULL,NULL,NULL,NULL,NULL,'cancelled','2026-08-15',NULL,NULL,0,'Order cancelled inside the cooling-off window; invoice voided, no GST charged.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (7,'DEMO-INV-2026-0007',7,10,3998.00,719.64,18.00,100.00,4617.64,1847.06,1,NULL,NULL,NULL,NULL,18.00,719.64,'partial','2026-08-05',NULL,NULL,0,'Customer paid a part amount; balance promised for next week.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (8,'DEMO-INV-2026-0008',8,11,1798.00,323.64,18.00,150.00,1971.64,1971.64,1,NULL,NULL,NULL,NULL,18.00,323.64,'paid','2026-07-13','2026-07-12 11:30:00',NULL,0,'Reseller package renewal, paid on receipt.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (9,'DEMO-INV-2026-0009',9,12,10797.00,1619.82,18.00,500.00,11916.82,0.00,1,NULL,NULL,NULL,NULL,18.00,1619.82,'draft','2026-09-08',NULL,NULL,0,'Draft pro-forma for the hardware refresh; not yet issued to the customer.','2026-08-08 23:51:27','2026-08-08 23:51:27');
INSERT INTO `invoices` VALUES (10,'DEMO-INV-2026-0010',10,13,2997.00,539.46,18.00,0.00,3536.46,0.00,1,NULL,NULL,NULL,NULL,18.00,539.46,'sent','2026-08-29',NULL,NULL,0,'Semi-annual billing run; retainer billed alongside the hosting plan.','2026-08-08 23:51:27','2026-08-08 23:51:27');
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_addresses`
--

DROP TABLE IF EXISTS `ip_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subnet_id` bigint(20) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `ip_version` enum('4','6') NOT NULL DEFAULT '4',
  `type` enum('gateway','broadcast','network','reserved','available','assigned','floating','nat') NOT NULL DEFAULT 'available',
  `assigned_to_type` enum('service','server','customer','inventory','App\\Models\\HostingAccount') DEFAULT NULL,
  `assigned_to_id` int(10) unsigned DEFAULT NULL,
  `inventory_asset_id` int(10) unsigned DEFAULT NULL,
  `ptr_record` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_addresses_subnet_id_ip_address_unique` (`subnet_id`,`ip_address`),
  KEY `ip_addresses_type_index` (`type`),
  KEY `ip_addresses_assigned_to_type_assigned_to_id_index` (`assigned_to_type`,`assigned_to_id`),
  KEY `ip_addresses_inventory_asset_id_index` (`inventory_asset_id`),
  CONSTRAINT `ip_addresses_subnet_id_foreign` FOREIGN KEY (`subnet_id`) REFERENCES `ip_subnets` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_addresses`
--

LOCK TABLES `ip_addresses` WRITE;
/*!40000 ALTER TABLE `ip_addresses` DISABLE KEYS */;
INSERT INTO `ip_addresses` VALUES (1,1,'192.0.2.0','4','network',NULL,NULL,NULL,NULL,'Demo network address.',NULL,'2026-08-08 05:22:32','2026-08-09 02:41:15');
INSERT INTO `ip_addresses` VALUES (2,1,'192.0.2.1','4','gateway',NULL,NULL,NULL,NULL,'Demo gateway address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (3,1,'192.0.2.2','4','reserved',NULL,NULL,NULL,NULL,'Demo reserved address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (4,1,'192.0.2.10','4','assigned','App\\Models\\HostingAccount',1,NULL,'demoshop.test','Allocated to hosting account 1.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (5,1,'192.0.2.11','4','assigned','App\\Models\\HostingAccount',2,NULL,'demoblog.test','Allocated to hosting account 2.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (6,1,'192.0.2.12','4','assigned','App\\Models\\HostingAccount',3,NULL,'demoagency.test','Allocated to hosting account 3.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (7,1,'192.0.2.13','4','assigned','App\\Models\\HostingAccount',4,NULL,'demovps.test','Allocated to hosting account 4.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (8,1,'192.0.2.14','4','assigned','App\\Models\\HostingAccount',5,NULL,'demolab.test','Allocated to hosting account 5.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (9,1,'192.0.2.15','4','assigned','App\\Models\\HostingAccount',6,NULL,'demoarchive.test','Allocated to hosting account 6.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (10,1,'192.0.2.16','4','available',NULL,NULL,NULL,NULL,'Demo available address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (11,1,'192.0.2.17','4','available',NULL,NULL,NULL,NULL,'Demo available address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (12,2,'198.51.100.0','4','network',NULL,NULL,NULL,NULL,'Demo network address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (13,2,'198.51.100.1','4','gateway',NULL,NULL,NULL,NULL,'Demo gateway address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (14,2,'198.51.100.2','4','reserved',NULL,NULL,NULL,NULL,'Demo reserved address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (15,2,'198.51.100.10','4','assigned','App\\Models\\HostingAccount',1,NULL,NULL,'Allocated to hosting account 1.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (16,2,'198.51.100.11','4','assigned','App\\Models\\HostingAccount',2,NULL,NULL,'Allocated to hosting account 2.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (17,2,'198.51.100.12','4','available',NULL,NULL,NULL,NULL,'Demo available address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (18,3,'203.0.113.0','4','network',NULL,NULL,NULL,NULL,'Demo network address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (19,3,'203.0.113.1','4','gateway',NULL,NULL,NULL,NULL,'Demo gateway address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (20,3,'203.0.113.2','4','reserved',NULL,NULL,NULL,NULL,'Demo reserved address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (21,3,'203.0.113.10','4','assigned','App\\Models\\HostingAccount',3,NULL,NULL,'Allocated to hosting account 3.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (22,3,'203.0.113.11','4','assigned','App\\Models\\HostingAccount',4,NULL,NULL,'Allocated to hosting account 4.','2026-08-08 08:52:32','2026-08-08 05:22:32','2026-08-08 05:22:32');
INSERT INTO `ip_addresses` VALUES (23,3,'203.0.113.12','4','available',NULL,NULL,NULL,NULL,'Demo available address.',NULL,'2026-08-08 05:22:32','2026-08-08 05:22:32');
/*!40000 ALTER TABLE `ip_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_allocation_history`
--

DROP TABLE IF EXISTS `ip_allocation_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_allocation_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address_id` bigint(20) unsigned NOT NULL,
  `action` enum('allocated','released','reserved','unreserved','ptr_updated','assigned','override') NOT NULL,
  `previous_assigned_to_type` varchar(255) DEFAULT NULL,
  `previous_assigned_to_id` int(10) unsigned DEFAULT NULL,
  `new_assigned_to_type` varchar(255) DEFAULT NULL,
  `new_assigned_to_id` int(10) unsigned DEFAULT NULL,
  `changed_by_user_id` int(10) unsigned DEFAULT NULL,
  `ip_address_snapshot` text NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ip_allocation_history_ip_address_id_index` (`ip_address_id`),
  KEY `ip_allocation_history_changed_at_index` (`changed_at`),
  CONSTRAINT `ip_allocation_history_ip_address_id_foreign` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_allocation_history`
--

LOCK TABLES `ip_allocation_history` WRITE;
/*!40000 ALTER TABLE `ip_allocation_history` DISABLE KEYS */;
INSERT INTO `ip_allocation_history` VALUES (1,4,'allocated',NULL,NULL,'App\\Models\\HostingAccount',1,1,'{\"ip_address\":\"192.0.2.10\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":1}','2026-07-09 05:22:32','Initial allocation to hosting account.');
INSERT INTO `ip_allocation_history` VALUES (2,5,'allocated',NULL,NULL,'App\\Models\\HostingAccount',2,1,'{\"ip_address\":\"192.0.2.11\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":2}','2026-07-11 05:22:32','Initial allocation to hosting account.');
INSERT INTO `ip_allocation_history` VALUES (3,6,'allocated',NULL,NULL,'App\\Models\\HostingAccount',3,1,'{\"ip_address\":\"192.0.2.12\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":3}','2026-07-13 05:22:32','Initial allocation to hosting account.');
INSERT INTO `ip_allocation_history` VALUES (4,7,'allocated',NULL,NULL,'App\\Models\\HostingAccount',4,1,'{\"ip_address\":\"192.0.2.13\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":4}','2026-07-15 05:22:32','Initial allocation to hosting account.');
INSERT INTO `ip_allocation_history` VALUES (5,8,'allocated',NULL,NULL,'App\\Models\\HostingAccount',5,1,'{\"ip_address\":\"192.0.2.14\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":5}','2026-07-17 05:22:32','Initial allocation to hosting account.');
INSERT INTO `ip_allocation_history` VALUES (6,9,'allocated',NULL,NULL,'App\\Models\\HostingAccount',6,1,'{\"ip_address\":\"192.0.2.15\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":6}','2026-07-19 05:22:32','Initial allocation to hosting account.');
INSERT INTO `ip_allocation_history` VALUES (7,15,'released','App\\Models\\HostingAccount',1,NULL,NULL,1,'{\"ip_address\":\"198.51.100.10\",\"subnet_id\":2,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":1}','2026-07-21 05:22:32','Released due to account suspension.');
INSERT INTO `ip_allocation_history` VALUES (8,16,'released','App\\Models\\HostingAccount',2,NULL,NULL,1,'{\"ip_address\":\"198.51.100.11\",\"subnet_id\":2,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":2}','2026-07-23 05:22:32','Released due to account termination.');
INSERT INTO `ip_allocation_history` VALUES (9,4,'reserved',NULL,NULL,'inventory',0,1,'{\"ip_address\":\"192.0.2.10\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":1}','2026-07-09 05:22:32','Reserved for future assignment.');
INSERT INTO `ip_allocation_history` VALUES (10,7,'ptr_updated',NULL,NULL,NULL,NULL,1,'{\"ip_address\":\"192.0.2.13\",\"subnet_id\":1,\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":4}','2026-07-15 05:22:32','PTR record updated.');
INSERT INTO `ip_allocation_history` VALUES (11,1,'assigned',NULL,NULL,'App\\Models\\HostingAccount',8,1,'{\"id\":1,\"subnet_id\":1,\"ip_address\":\"192.0.2.0\",\"ip_version\":\"4\",\"type\":\"network\",\"assigned_to_type\":null,\"assigned_to_id\":null,\"inventory_asset_id\":null,\"ptr_record\":null,\"notes\":\"Demo network address.\",\"last_seen_at\":null,\"created_at\":\"2026-08-08 10:52:32\",\"updated_at\":\"2026-08-08 10:52:32\"}','2026-08-09 02:41:02','Assigned next available IP 192.0.2.0 to hosting account 8');
INSERT INTO `ip_allocation_history` VALUES (12,1,'released','App\\Models\\HostingAccount',8,NULL,NULL,1,'{\"id\":1,\"subnet_id\":1,\"ip_address\":\"192.0.2.0\",\"ip_version\":\"4\",\"type\":\"network\",\"assigned_to_type\":\"App\\\\Models\\\\HostingAccount\",\"assigned_to_id\":8,\"inventory_asset_id\":null,\"ptr_record\":null,\"notes\":\"Demo network address.\",\"last_seen_at\":null,\"created_at\":\"2026-08-08 10:52:32\",\"updated_at\":\"2026-08-09 08:11:02\"}','2026-08-09 02:41:15','Released IP 192.0.2.0 from hosting account 8');
/*!40000 ALTER TABLE `ip_allocation_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_subnets`
--

DROP TABLE IF EXISTS `ip_subnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_subnets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `subnet_cidr` varchar(43) NOT NULL,
  `gateway` varchar(255) DEFAULT NULL,
  `netmask` varchar(15) DEFAULT NULL,
  `ip_version` enum('4','6') NOT NULL DEFAULT '4',
  `network_type` enum('public','private','management','storage','dmz') NOT NULL DEFAULT 'private',
  `vlan_id` int(10) unsigned DEFAULT NULL,
  `datacenter_id` int(10) unsigned DEFAULT NULL,
  `total_addresses` int(10) unsigned NOT NULL DEFAULT 0,
  `used_addresses` int(10) unsigned NOT NULL DEFAULT 0,
  `reserved_count` int(10) unsigned NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `status` enum('active','exhausted','reserved','retired') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_subnets_subnet_cidr_unique` (`subnet_cidr`),
  KEY `ip_subnets_network_type_index` (`network_type`),
  KEY `ip_subnets_status_index` (`status`),
  KEY `ip_subnets_datacenter_id_index` (`datacenter_id`),
  KEY `ip_subnets_vlan_id_index` (`vlan_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_subnets`
--

LOCK TABLES `ip_subnets` WRITE;
/*!40000 ALTER TABLE `ip_subnets` DISABLE KEYS */;
INSERT INTO `ip_subnets` VALUES (1,'DEMO-Public-Production','192.0.2.0/24','192.0.2.1','255.255.255.0','4','public',1,1,254,6,1,'Demo subnet over RFC 5737 documentation range.','active','2026-08-08 05:22:32','2026-08-09 01:49:37');
INSERT INTO `ip_subnets` VALUES (2,'DEMO-Private-Backend','198.51.100.0/24','198.51.100.1','255.255.255.0','4','private',2,2,254,2,1,'Demo subnet over RFC 5737 documentation range.','active','2026-08-08 05:22:32','2026-08-09 01:49:37');
INSERT INTO `ip_subnets` VALUES (3,'DEMO-Storage-NAS','203.0.113.0/24','203.0.113.1','255.255.255.0','4','storage',3,1,254,2,1,'Demo subnet over RFC 5737 documentation range.','active','2026-08-08 05:22:32','2026-08-09 01:49:37');
/*!40000 ALTER TABLE `ip_subnets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `knowledge_base`
--

DROP TABLE IF EXISTS `knowledge_base`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `knowledge_base` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` enum('getting_started','hosting','domains','email','billing','technical') NOT NULL DEFAULT 'hosting',
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  `helpful` int(10) unsigned NOT NULL DEFAULT 0,
  `not_helpful` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `knowledge_base_slug_unique` (`slug`),
  KEY `knowledge_base_slug_index` (`slug`),
  KEY `knowledge_base_category_index` (`category`),
  KEY `knowledge_base_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `knowledge_base`
--

LOCK TABLES `knowledge_base` WRITE;
/*!40000 ALTER TABLE `knowledge_base` DISABLE KEYS */;
INSERT INTO `knowledge_base` VALUES (13,'getting_started','Getting Started with Your Hosting Account','getting-started-with-your-hosting-account','After sign-up you will receive your welcome email with control panel credentials. Log in at /dashboard, create your first site, and point your domain to our nameservers.',50,10,0,'published','2026-08-08 06:17:29','2026-08-09 01:49:37');
INSERT INTO `knowledge_base` VALUES (14,'hosting','How to Configure PHP Settings','how-to-configure-php-settings','Navigate to the hosting manager, select your domain, and open the PHP tab. Adjust memory_limit, upload_max_filesize, and max_execution_time, then click Apply.',100,11,0,'published','2026-08-08 06:17:29','2026-08-09 01:49:37');
INSERT INTO `knowledge_base` VALUES (15,'domains','Connecting an Existing Domain','connecting-an-existing-domain','Update your domain A record to the IP shown in your hosting panel. Allow up to 48 hours for DNS propagation. Verify with `dig` or an online propagation checker.',150,12,1,'draft','2026-08-08 06:17:29','2026-08-09 01:49:37');
INSERT INTO `knowledge_base` VALUES (16,'email','Setting Up Email Accounts','setting-up-email-accounts','From the email manager create a mailbox, then configure your client with the provided IMAP/SMTP settings. Use port 993 for IMAP and 587 for SMTP with STARTTLS.',200,13,2,'published','2026-08-08 06:17:29','2026-08-09 01:49:37');
INSERT INTO `knowledge_base` VALUES (17,'billing','Understanding Your Invoice','understanding-your-invoice','Invoices list line items with billing-cycle dates, amounts, and tax. Download a PDF from the invoice view or enable auto-pay under Payment Methods.',250,14,3,'published','2026-08-08 06:17:29','2026-08-09 01:49:37');
INSERT INTO `knowledge_base` VALUES (18,'technical','Installing an SSL Certificate','installing-an-ssl-certificate','Open the SSL/TLS section for your domain, choose AutoSSL or upload a custom certificate, and force HTTPS via the redirect toggle.',300,15,4,'draft','2026-08-08 06:17:29','2026-08-09 01:49:37');
/*!40000 ALTER TABLE `knowledge_base` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `license_assignments`
--

DROP TABLE IF EXISTS `license_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `license_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `license_id` bigint(20) unsigned NOT NULL,
  `assigned_to_type` enum('service','customer','server') NOT NULL,
  `assigned_to_id` int(10) unsigned NOT NULL,
  `assigned_at` datetime NOT NULL,
  `released_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `license_assignments_license_id_index` (`license_id`),
  CONSTRAINT `license_assignments_license_id_foreign` FOREIGN KEY (`license_id`) REFERENCES `licenses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `license_assignments`
--

LOCK TABLES `license_assignments` WRITE;
/*!40000 ALTER TABLE `license_assignments` DISABLE KEYS */;
INSERT INTO `license_assignments` VALUES (1,1,'server',1,'2026-06-08 00:00:00',NULL,'Installed on the host node.');
INSERT INTO `license_assignments` VALUES (2,1,'service',4,'2026-06-08 00:00:00',NULL,'Seat consumed by a customer service.');
INSERT INTO `license_assignments` VALUES (3,2,'server',2,'2026-06-08 00:00:00',NULL,'Installed on the host node.');
INSERT INTO `license_assignments` VALUES (4,2,'service',7,'2026-06-08 00:00:00',NULL,'Seat consumed by a customer service.');
INSERT INTO `license_assignments` VALUES (5,3,'server',1,'2026-06-08 00:00:00',NULL,'Installed on the host node.');
INSERT INTO `license_assignments` VALUES (6,4,'server',3,'2026-06-08 00:00:00',NULL,'Installed on the host node.');
INSERT INTO `license_assignments` VALUES (7,5,'server',4,'2026-06-08 00:00:00',NULL,'Installed on the host node.');
INSERT INTO `license_assignments` VALUES (8,3,'service',9,'2026-06-08 00:00:00',NULL,'Seat consumed by a customer service.');
INSERT INTO `license_assignments` VALUES (9,4,'service',10,'2026-06-08 00:00:00',NULL,'Seat consumed by a customer service.');
INSERT INTO `license_assignments` VALUES (10,5,'service',11,'2026-06-08 00:00:00',NULL,'Seat consumed by a customer service.');
/*!40000 ALTER TABLE `license_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `licenses`
--

DROP TABLE IF EXISTS `licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `licenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inventory_asset_id` bigint(20) unsigned NOT NULL,
  `license_type` enum('windows','cpanel','plesk','litespeed','cloudlinux','directadmin','virtualizor','solusvm','other') NOT NULL,
  `license_key` varchar(255) DEFAULT NULL,
  `seats` int(10) unsigned NOT NULL DEFAULT 1,
  `seats_available` int(10) unsigned NOT NULL DEFAULT 1,
  `vendor` varchar(255) DEFAULT NULL,
  `purchase_order` varchar(255) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `renewal_date` date DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT NULL,
  `status` enum('active','expired','revoked','pending') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `licenses_inventory_asset_id_unique` (`inventory_asset_id`),
  CONSTRAINT `licenses_inventory_asset_id_foreign` FOREIGN KEY (`inventory_asset_id`) REFERENCES `inventory_assets` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `licenses`
--

LOCK TABLES `licenses` WRITE;
/*!40000 ALTER TABLE `licenses` DISABLE KEYS */;
INSERT INTO `licenses` VALUES (1,1,'cpanel','DEMO-LIC-CPANEL-0001',5,3,'cPanel L.L.C.','DEMO-PO-0001','2027-06-08','2027-05-08',4250.00,'active','Demo license attached to inventory asset DEMO-SRV-0001.','2026-08-08 05:10:36','2026-08-08 05:10:36');
INSERT INTO `licenses` VALUES (2,2,'windows','DEMO-LIC-WINDOWS-0001',4,2,'Microsoft','DEMO-PO-0001','2027-06-08','2027-05-08',9800.00,'active','Demo license attached to inventory asset DEMO-SRV-0002.','2026-08-08 05:10:36','2026-08-08 05:10:36');
INSERT INTO `licenses` VALUES (3,3,'plesk','DEMO-LIC-PLESK-0001',10,9,'Plesk International GmbH','DEMO-PO-0001','2027-06-08','2027-05-08',6500.00,'active','Demo license attached to inventory asset DEMO-SWT-0001.','2026-08-08 05:20:35','2026-08-08 05:20:35');
INSERT INTO `licenses` VALUES (4,4,'litespeed','DEMO-LIC-LITESPEED-0001',8,7,'LiteSpeed Technologies','DEMO-PO-0001','2027-06-08','2027-05-08',3200.00,'active','Demo license attached to inventory asset DEMO-SWT-0002.','2026-08-08 05:20:35','2026-08-08 05:20:35');
INSERT INTO `licenses` VALUES (5,5,'cloudlinux','DEMO-LIC-CLOUDLINUX-0001',6,5,'CloudLinux Inc.','DEMO-PO-0001','2027-06-08','2027-05-08',4800.00,'active','Demo license attached to inventory asset DEMO-RTR-0001.','2026-08-08 05:20:35','2026-08-08 05:20:35');
/*!40000 ALTER TABLE `licenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `marketing_consent_log`
--

DROP TABLE IF EXISTS `marketing_consent_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_consent_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `contact_type` varchar(255) DEFAULT NULL,
  `consent_status` enum('opt_in','opt_out') NOT NULL DEFAULT 'opt_in',
  `source` varchar(255) DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_consent_log_customer_id_index` (`customer_id`),
  CONSTRAINT `marketing_consent_log_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `marketing_consent_log`
--

LOCK TABLES `marketing_consent_log` WRITE;
/*!40000 ALTER TABLE `marketing_consent_log` DISABLE KEYS */;
INSERT INTO `marketing_consent_log` VALUES (1,6,'marketing_email','opt_in','seeder','203.0.113.11','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `marketing_consent_log` VALUES (2,6,'marketing_sms','opt_out','seeder','203.0.113.11','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `marketing_consent_log` VALUES (3,7,'marketing_email','opt_out','seeder','203.0.113.12','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `marketing_consent_log` VALUES (4,7,'marketing_sms','opt_out','seeder','203.0.113.12','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:01','2026-08-09 01:49:34');
INSERT INTO `marketing_consent_log` VALUES (5,8,'marketing_email','opt_in','seeder','203.0.113.13','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:01','2026-08-09 01:49:35');
INSERT INTO `marketing_consent_log` VALUES (6,8,'marketing_sms','opt_out','seeder','203.0.113.13','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:01','2026-08-09 01:49:35');
INSERT INTO `marketing_consent_log` VALUES (7,9,'marketing_email','opt_out','seeder','203.0.113.14','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:02','2026-08-09 01:49:35');
INSERT INTO `marketing_consent_log` VALUES (8,9,'marketing_sms','opt_out','seeder','203.0.113.14','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:02','2026-08-09 01:49:35');
INSERT INTO `marketing_consent_log` VALUES (9,10,'marketing_email','opt_in','seeder','203.0.113.15','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:02','2026-08-09 01:49:35');
INSERT INTO `marketing_consent_log` VALUES (10,10,'marketing_sms','opt_out','seeder','203.0.113.15','DemoSeeder/1.0 (marketing consent demo record)','2026-08-09 01:25:02','2026-08-09 01:49:35');
/*!40000 ALTER TABLE `marketing_consent_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` VALUES (4,'2026_07_30_113249_create_adminlte_rbac_tables',1);
INSERT INTO `migrations` VALUES (5,'2026_07_30_113458_create_personal_access_tokens_table',1);
INSERT INTO `migrations` VALUES (6,'2026_07_30_120000_create_core_tables',1);
INSERT INTO `migrations` VALUES (7,'2026_07_30_120010_create_product_tables',1);
INSERT INTO `migrations` VALUES (8,'2026_07_30_120020_create_config_tables',1);
INSERT INTO `migrations` VALUES (9,'2026_07_30_120030_create_order_tables',1);
INSERT INTO `migrations` VALUES (10,'2026_07_30_120040_create_financial_tables',1);
INSERT INTO `migrations` VALUES (11,'2026_07_30_120050_create_support_tables',1);
INSERT INTO `migrations` VALUES (12,'2026_07_30_120060_create_audit_tables',1);
INSERT INTO `migrations` VALUES (13,'2026_07_30_120070_create_inventory_tables',1);
INSERT INTO `migrations` VALUES (14,'2026_07_30_120080_create_ipam_dns_tables',1);
INSERT INTO `migrations` VALUES (15,'2026_07_30_120090_create_resource_provisioning_tables',1);
INSERT INTO `migrations` VALUES (16,'2026_07_30_120100_create_service_tables',1);
INSERT INTO `migrations` VALUES (17,'2026_07_31_000001_add_billing_columns',1);
INSERT INTO `migrations` VALUES (18,'2026_07_31_000002_create_ssl_certificates_table',1);
INSERT INTO `migrations` VALUES (19,'2026_07_31_043415_add_two_factor_columns_to_users_table',1);
INSERT INTO `migrations` VALUES (20,'2026_07_31_043416_create_passkeys_table',1);
INSERT INTO `migrations` VALUES (21,'2026_08_01_000001_add_order_number_billing_cycle_to_orders_table',1);
INSERT INTO `migrations` VALUES (22,'2026_08_01_000001_add_product_submodule_permissions',1);
INSERT INTO `migrations` VALUES (23,'2026_08_01_000001_add_suspended_to_domains_status_enum',1);
INSERT INTO `migrations` VALUES (24,'2026_08_03_000001_create_payment_gateways_table',1);
INSERT INTO `migrations` VALUES (25,'2026_08_03_000002_widen_payments_method_enum',1);
INSERT INTO `migrations` VALUES (26,'2026_08_04_000001_add_paid_at_index_to_invoices_table',1);
INSERT INTO `migrations` VALUES (27,'2026_08_04_000002_add_body_error_to_emails_table',1);
INSERT INTO `migrations` VALUES (28,'2026_08_04_000003_add_next_due_date_to_hosting_accounts_table',1);
INSERT INTO `migrations` VALUES (29,'2026_08_04_000004_add_updated_at_to_emails_table',1);
INSERT INTO `migrations` VALUES (30,'2026_08_06_000001_create_asset_relationships_table',1);
INSERT INTO `migrations` VALUES (31,'2026_08_06_000002_widen_ipam_assignment_enums',1);
INSERT INTO `migrations` VALUES (32,'2026_08_07_000001_order_lifecycle_and_status_history',1);
INSERT INTO `migrations` VALUES (33,'2026_08_07_000002_create_sequences_table',1);
INSERT INTO `migrations` VALUES (34,'2026_08_08_000001_create_domain_pricing_tables',1);
INSERT INTO `migrations` VALUES (35,'2026_08_09_000001_create_notifications_table',1);
INSERT INTO `migrations` VALUES (36,'2026_08_09_000002_create_notification_preferences_table',1);
INSERT INTO `migrations` VALUES (37,'2022_12_14_083707_create_settings_table',2);
INSERT INTO `migrations` VALUES (38,'2026_08_12_000001_copy_legacy_settings_to_settings_properties',2);
INSERT INTO `migrations` VALUES (39,'2026_08_13_000001_create_tax_rates_table',2);
INSERT INTO `migrations` VALUES (40,'2026_08_13_000002_create_invoice_pdf_log_table',2);
INSERT INTO `migrations` VALUES (41,'2026_08_13_000003_create_marketing_consent_log_table',2);
INSERT INTO `migrations` VALUES (42,'2026_08_14_000001_create_product_bundles_table',2);
INSERT INTO `migrations` VALUES (43,'2026_08_14_000002_create_product_upgrade_paths_table',2);
INSERT INTO `migrations` VALUES (44,'2026_08_15_000001_seed_new_settings_groups_properties',2);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_preferences`
--

DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `preferrable_type` varchar(255) NOT NULL,
  `preferrable_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL DEFAULT 'database',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_preferences_unique` (`preferrable_type`,`preferrable_id`,`type`,`channel`),
  KEY `notification_preferences_preferrable_type_preferrable_id_index` (`preferrable_type`,`preferrable_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_preferences`
--

LOCK TABLES `notification_preferences` WRITE;
/*!40000 ALTER TABLE `notification_preferences` DISABLE KEYS */;
INSERT INTO `notification_preferences` VALUES (1,'App\\Models\\User',1,'App\\Notifications\\OrderConfirmed','mail',1,'2026-06-30 19:30:00','2026-06-30 19:30:00');
INSERT INTO `notification_preferences` VALUES (2,'App\\Models\\User',1,'App\\Notifications\\InvoicePaid','database',1,'2026-06-30 20:30:00','2026-06-30 20:30:00');
INSERT INTO `notification_preferences` VALUES (3,'App\\Models\\User',6,'App\\Notifications\\OrderConfirmed','mail',1,'2026-06-30 21:30:00','2026-06-30 21:30:00');
INSERT INTO `notification_preferences` VALUES (4,'App\\Models\\User',6,'App\\Notifications\\InvoicePaid','mail',1,'2026-06-30 22:30:00','2026-06-30 22:30:00');
INSERT INTO `notification_preferences` VALUES (5,'App\\Models\\User',6,'App\\Notifications\\TicketCreated','database',1,'2026-06-30 23:30:00','2026-06-30 23:30:00');
INSERT INTO `notification_preferences` VALUES (6,'App\\Models\\User',7,'App\\Notifications\\OrderConfirmed','database',1,'2026-07-01 00:30:00','2026-07-01 00:30:00');
INSERT INTO `notification_preferences` VALUES (7,'App\\Models\\User',7,'App\\Notifications\\ServiceSuspended','mail',0,'2026-07-01 01:30:00','2026-07-01 01:30:00');
INSERT INTO `notification_preferences` VALUES (8,'App\\Models\\User',8,'App\\Notifications\\TicketCreated','mail',1,'2026-07-01 02:30:00','2026-07-01 02:30:00');
INSERT INTO `notification_preferences` VALUES (9,'App\\Models\\User',8,'App\\Notifications\\ServiceSuspended','database',0,'2026-07-01 03:30:00','2026-07-01 03:30:00');
INSERT INTO `notification_preferences` VALUES (10,'App\\Models\\User',9,'App\\Notifications\\InvoicePaid','slack',1,'2026-07-01 04:30:00','2026-07-01 04:30:00');
INSERT INTO `notification_preferences` VALUES (11,'App\\Models\\User',7,'App\\Notifications\\OrderConfirmed','mail',1,'2026-06-30 21:30:00','2026-06-30 21:30:00');
INSERT INTO `notification_preferences` VALUES (12,'App\\Models\\User',7,'App\\Notifications\\InvoicePaid','mail',1,'2026-06-30 22:30:00','2026-06-30 22:30:00');
INSERT INTO `notification_preferences` VALUES (13,'App\\Models\\User',7,'App\\Notifications\\TicketCreated','database',1,'2026-06-30 23:30:00','2026-06-30 23:30:00');
INSERT INTO `notification_preferences` VALUES (14,'App\\Models\\User',8,'App\\Notifications\\OrderConfirmed','database',1,'2026-07-01 00:30:00','2026-07-01 00:30:00');
INSERT INTO `notification_preferences` VALUES (15,'App\\Models\\User',8,'App\\Notifications\\ServiceSuspended','mail',0,'2026-07-01 01:30:00','2026-07-01 01:30:00');
INSERT INTO `notification_preferences` VALUES (16,'App\\Models\\User',9,'App\\Notifications\\TicketCreated','mail',1,'2026-07-01 02:30:00','2026-07-01 02:30:00');
INSERT INTO `notification_preferences` VALUES (17,'App\\Models\\User',9,'App\\Notifications\\ServiceSuspended','database',0,'2026-07-01 03:30:00','2026-07-01 03:30:00');
INSERT INTO `notification_preferences` VALUES (18,'App\\Models\\User',10,'App\\Notifications\\InvoicePaid','slack',1,'2026-07-01 04:30:00','2026-07-01 04:30:00');
/*!40000 ALTER TABLE `notification_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES ('11111111-1111-4111-8111-111111111101','App\\Notifications\\OrderConfirmed','App\\Models\\User',7,'{\"title\":\"Order Confirmed\",\"message\":\"Demo: your order ORD-2026-0101 has been confirmed and is being processed.\"}',NULL,'2026-06-30 19:30:00','2026-06-30 19:30:00');
INSERT INTO `notifications` VALUES ('11111111-1111-4111-8111-111111111102','App\\Notifications\\InvoicePaid','App\\Models\\User',8,'{\"title\":\"Invoice Paid\",\"message\":\"Demo: payment received in full for invoice DEMO-INV-2026-0002.\"}',NULL,'2026-06-30 20:30:00','2026-06-30 20:30:00');
INSERT INTO `notifications` VALUES ('11111111-1111-4111-8111-111111111103','App\\Notifications\\TicketCreated','App\\Models\\User',9,'{\"title\":\"Support Ticket Created\",\"message\":\"Demo: support ticket TKT-DEMO-0001 has been created and assigned.\"}',NULL,'2026-06-30 21:30:00','2026-06-30 21:30:00');
INSERT INTO `notifications` VALUES ('11111111-1111-4111-8111-111111111104','App\\Notifications\\ServiceSuspended','App\\Models\\User',10,'{\"title\":\"Service Suspended\",\"message\":\"Demo: your service has been suspended for non-payment.\"}',NULL,'2026-06-30 22:30:00','2026-06-30 22:30:00');
INSERT INTO `notifications` VALUES ('11111111-1111-4111-8111-111111111105','App\\Notifications\\InvoiceOverdue','App\\Models\\User',11,'{\"title\":\"Invoice Overdue\",\"message\":\"Demo: invoice DEMO-INV-2026-0003 is past its due date.\"}',NULL,'2026-06-30 23:30:00','2026-06-30 23:30:00');
INSERT INTO `notifications` VALUES ('9132dec9-3603-4e61-9f46-76de8b813a2f','App\\Notifications\\TestNotification','App\\Models\\User',2,'{\"title\":\"Order Confirmed\",\"message\":\"Your QA order is ready.\"}',NULL,'2026-08-07 06:15:20','2026-08-07 06:15:20');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_order_id_index` (`order_id`),
  KEY `order_items_product_id_index` (`product_id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT INTO `order_items` VALUES (4,4,7,'Demo Starter Shared Hosting',1,199.00,199.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (5,5,8,'Demo Business Shared Hosting',1,499.00,499.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (6,5,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (7,6,10,'Demo Cloud VPS 2GB',1,899.00,899.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (8,7,15,'Demo Legacy Hosting Pack',1,349.00,349.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (9,8,13,'Demo .com Domain Registration',1,899.00,899.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (10,9,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (11,10,11,'Demo Cloud VPS 8GB',1,2499.00,2499.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (12,11,9,'Demo Reseller Bronze',1,1299.00,1299.00,'2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_items` VALUES (13,4,13,'Demo .com Domain Registration',1,899.00,899.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_items` VALUES (14,6,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_items` VALUES (15,7,13,'Demo .com Domain Registration',1,899.00,899.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_items` VALUES (16,10,14,'Demo SSL & Backup Addon',1,1499.00,1499.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_items` VALUES (17,11,8,'Demo Business Shared Hosting',1,499.00,499.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_items` VALUES (18,12,12,'Demo Dedicated E3 Server',1,8999.00,8999.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_items` VALUES (19,12,13,'Demo .com Domain Registration',1,899.00,899.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_items` VALUES (20,13,8,'Demo Business Shared Hosting',2,499.00,998.00,'2026-08-08 06:08:37','2026-08-08 06:08:37');
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_status_history`
--

DROP TABLE IF EXISTS `order_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `from_status` varchar(255) DEFAULT NULL,
  `to_status` varchar(255) NOT NULL,
  `changed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_status_history_order_id_index` (`order_id`),
  CONSTRAINT `order_status_history_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_status_history`
--

LOCK TABLES `order_status_history` WRITE;
/*!40000 ALTER TABLE `order_status_history` DISABLE KEYS */;
INSERT INTO `order_status_history` VALUES (1,4,NULL,'pending',1,'Order placed from client area.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (2,5,'provisioning','active',1,'Provisioned automatically after payment.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (3,6,'paid','provisioning',1,'Payment confirmed, provisioning started.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (4,7,'active','suspended',1,'Suspended for non-payment after dunning emails.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (5,8,'pending','paid',1,'Payment captured; awaiting registrar confirmation.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (6,9,'pending','cancelled',1,'Customer cancelled before service activation.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (7,10,'provisioning','failed',1,'Provisioning error — no free IPv4 in assigned pool.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (8,11,'provisioning','active',1,'Account ready, welcome email sent.','2026-08-08 05:19:21','2026-08-08 05:19:21');
INSERT INTO `order_status_history` VALUES (9,5,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (10,5,'pending','paid',1,'Payment captured and reconciled against the invoice.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (11,5,'paid','provisioning',1,'Handed to the provisioning queue.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (12,6,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (13,6,'pending','paid',1,'Payment captured and reconciled against the invoice.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (14,7,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (15,7,'pending','paid',1,'Payment captured and reconciled against the invoice.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (16,7,'paid','provisioning',1,'Handed to the provisioning queue.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (17,7,'provisioning','active',1,'Provisioning completed, welcome email sent.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (18,8,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (19,9,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (20,10,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (21,10,'pending','paid',1,'Payment captured and reconciled against the invoice.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (22,10,'paid','provisioning',1,'Handed to the provisioning queue.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (23,11,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (24,11,'pending','paid',1,'Payment captured and reconciled against the invoice.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (25,11,'paid','provisioning',1,'Handed to the provisioning queue.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (26,12,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (27,12,'pending','paid',1,'Payment captured and reconciled against the invoice.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (28,12,'paid','provisioning',1,'Handed to the provisioning queue.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (29,12,'provisioning','active',1,'Provisioning completed, welcome email sent.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (30,12,'active','suspended',1,'Suspended automatically after the dunning cycle expired.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (31,12,'suspended','terminated',1,'Terminated after the suspension grace period.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (32,13,NULL,'pending',1,'Order placed from the client area.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (33,13,'pending','paid',1,'Payment captured and reconciled against the invoice.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (34,13,'paid','provisioning',1,'Handed to the provisioning queue.','2026-08-08 06:08:37','2026-08-08 06:08:37');
INSERT INTO `order_status_history` VALUES (35,13,'provisioning','active',1,'Provisioning completed, welcome email sent.','2026-08-08 06:08:37','2026-08-08 06:08:37');
/*!40000 ALTER TABLE `order_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(32) DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `billing_cycle` enum('monthly','quarterly','semi_annual','annual','biennial','one_time') NOT NULL DEFAULT 'monthly',
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','active','suspended','cancelled','terminated','paid','provisioning','failed') NOT NULL DEFAULT 'pending',
  `domain_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `next_billing_date` date DEFAULT NULL,
  `last_billing_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_number_unique` (`order_number`),
  KEY `orders_customer_id_index` (`customer_id`),
  KEY `orders_product_id_index` (`product_id`),
  KEY `orders_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (4,'ORD-2026-0101',6,7,'monthly',2,1098.00,'pending','northwind.test','New customer onboarding - domain transfer pending.',NULL,NULL,'2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (5,'ORD-2026-0102',7,8,'monthly',2,1998.00,'active','blueharbor.test','Invoice paid; auto-renew enabled.','2026-09-01','2026-08-01','2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (6,'ORD-2026-0103',8,10,'monthly',2,2398.00,'provisioning','cedarpoint.test','OS selected: ubuntu-22.04. Provisioning via Virtualizor.',NULL,NULL,'2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (7,'ORD-2026-0104',9,15,'quarterly',2,1248.00,'suspended','driftwood.test','Payment overdue 14 days - service suspended per policy.','2026-11-01','2026-08-01','2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (8,'ORD-2026-0105',10,13,'annual',1,899.00,'paid','everline.test','Domain registration for the .com TLD, 1 year.',NULL,NULL,'2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (9,'ORD-2026-0106',6,14,'annual',1,1499.00,'cancelled',NULL,'Cancelled within the cooling-off period, full refund issued.',NULL,NULL,'2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (10,'ORD-2026-0107',7,11,'monthly',2,3998.00,'failed','blueharbor.test','Provisioning failed: insufficient IPs in pool. Retry queued.',NULL,NULL,'2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (11,'ORD-2026-0108',8,9,'monthly',2,1798.00,'active','cedarpoint.test','Reseller account with 25 cPanel slots.','2026-09-01','2026-08-01','2026-08-08 05:19:21','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (12,'ORD-2026-0109',9,12,'annual',2,9898.00,'terminated','driftwood.test','Hardware returned to inventory after non-payment.',NULL,NULL,'2026-08-08 06:08:37','2026-08-09 01:49:36');
INSERT INTO `orders` VALUES (13,'ORD-2026-0110',10,8,'semi_annual',2,998.00,'active','everline.test','Second site for the marketing team, billed semi-annually.','2027-02-01','2026-08-01','2026-08-08 06:08:37','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passkeys`
--

DROP TABLE IF EXISTS `passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `credential` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`credential`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  KEY `passkeys_user_id_index` (`user_id`),
  CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passkeys`
--

LOCK TABLES `passkeys` WRITE;
/*!40000 ALTER TABLE `passkeys` DISABLE KEYS */;
INSERT INTO `passkeys` VALUES (3,13,'Demo Passkey','demo-passkey-cred-0000000000000001','{\"public_key\":\"demo-key-0000000000000001\"}',NULL,'2026-08-08 04:55:28','2026-08-08 04:55:28');
INSERT INTO `passkeys` VALUES (4,7,'Demo Client Passkey','demo-passkey-cred-0000000000000002','{\"public_key\":\"demo-key-demo-passkey-cred-0000000000000002\"}',NULL,'2026-08-09 01:25:00','2026-08-09 01:25:00');
/*!40000 ALTER TABLE `passkeys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_gateways`
--

DROP TABLE IF EXISTS `payment_gateways`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateways` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `driver` varchar(255) NOT NULL,
  `mode` enum('test','live') NOT NULL DEFAULT 'test',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `credentials` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`credentials`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_gateways_code_unique` (`code`),
  KEY `payment_gateways_enabled_index` (`enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_gateways`
--

LOCK TABLES `payment_gateways` WRITE;
/*!40000 ALTER TABLE `payment_gateways` DISABLE KEYS */;
INSERT INTO `payment_gateways` VALUES (6,'bank_transfer','Bank Transfer','App\\Services\\Payments\\Drivers\\BankTransferDriver','test',1,10,NULL,'2026-08-09 00:13:04','2026-08-09 00:13:04');
INSERT INTO `payment_gateways` VALUES (7,'manual','Manual / Cash','App\\Services\\Payments\\Drivers\\ManualDriver','test',1,20,NULL,'2026-08-09 00:13:04','2026-08-09 00:13:04');
INSERT INTO `payment_gateways` VALUES (8,'stripe','Stripe','App\\Services\\Payments\\Drivers\\StripeDriver','test',0,30,NULL,'2026-08-09 00:13:04','2026-08-09 00:13:04');
INSERT INTO `payment_gateways` VALUES (9,'paypal','PayPal','App\\Services\\Payments\\Drivers\\PaypalDriver','test',0,40,NULL,'2026-08-09 00:13:04','2026-08-09 00:13:04');
INSERT INTO `payment_gateways` VALUES (10,'razorpay','Razorpay','App\\Services\\Payments\\Drivers\\RazorpayDriver','test',0,50,NULL,'2026-08-09 00:13:04','2026-08-09 00:13:04');
/*!40000 ALTER TABLE `payment_gateways` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `method` enum('razorpay','stripe','paypal','bank_transfer','cash','cheque','wallet','manual','credit','other') NOT NULL DEFAULT 'razorpay',
  `gateway_id` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payments_invoice_id_index` (`invoice_id`),
  KEY `payments_gateway_id_index` (`gateway_id`),
  KEY `payments_status_index` (`status`),
  CONSTRAINT `payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,2,2307.64,'razorpay','DEMO-RZP-0001','DEMO-PAY-2026-0001','completed','Card payment captured via Razorpay.','2026-06-30 20:30:00');
INSERT INTO `payments` VALUES (2,5,2077.82,'bank_transfer',NULL,'DEMO-PAY-2026-0002','completed','NEFT bank transfer, reconciled.','2026-06-30 22:30:00');
INSERT INTO `payments` VALUES (3,8,1971.64,'razorpay','DEMO-RZP-0003','DEMO-PAY-2026-0003','completed','Renewal paid in full via Razorpay.','2026-07-01 00:30:00');
INSERT INTO `payments` VALUES (4,7,1847.06,'bank_transfer',NULL,'DEMO-PAY-2026-0004','completed','Partial payment received; balance promised.','2026-07-01 02:30:00');
INSERT INTO `payments` VALUES (5,1,1133.82,'razorpay','DEMO-RZP-0005','DEMO-PAY-2026-0005','pending','Payment link sent, awaiting capture.','2026-07-01 04:30:00');
INSERT INTO `payments` VALUES (6,10,3536.46,'cash',NULL,'DEMO-PAY-2026-0006','pending','Cash collection scheduled.','2026-07-01 06:30:00');
INSERT INTO `payments` VALUES (7,3,2829.64,'razorpay','DEMO-RZP-0007','DEMO-PAY-2026-0007','failed','Card declined; customer notified.','2026-07-01 08:30:00');
INSERT INTO `payments` VALUES (8,6,2498.00,'bank_transfer',NULL,'DEMO-PAY-2026-0008','failed','Payment reversed after cancellation.','2026-07-01 10:30:00');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_addons`
--

DROP TABLE IF EXISTS `product_addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_addons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `billing_cycle` enum('one_time','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'one_time',
  `setup_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `welcome_email_template_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_addons_product_id_foreign` (`product_id`),
  CONSTRAINT `product_addons_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_addons`
--

LOCK TABLES `product_addons` WRITE;
/*!40000 ALTER TABLE `product_addons` DISABLE KEYS */;
INSERT INTO `product_addons` VALUES (1,7,'Demo SSL Certificate','Positive SSL certificate for your domain.','annual',0.00,899.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (2,8,'Demo Backup Service','Daily offsite backups with 30-day retention.','monthly',0.00,149.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (3,9,'Demo Dedicated IP','Additional dedicated IPv4 address.','one_time',0.00,199.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (4,10,'Demo Advanced Backup','Incremental backups with 90-day retention.','monthly',0.00,299.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (5,11,'Demo Priority Support','24/7 priority support with 1-hour SLA.','monthly',0.00,499.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (6,12,'Demo Malware Scan','Weekly malware scanning and removal.','monthly',0.00,99.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (7,13,'Demo CDN Booster','Global CDN for faster content delivery.','monthly',0.00,199.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (8,NULL,'Demo Global Security Addon','Global security addon available for all products.','annual',0.00,1499.00,NULL,'active','2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_addons` VALUES (9,7,'Extra 50GB SSD Storage','One-time add-on: 50 GB of additional SSD disk space.','one_time',0.00,499.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_addons` VALUES (10,7,'Dedicated IP Address','A dedicated IPv4 address for this hosting account.','one_time',0.00,299.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_addons` VALUES (11,8,'LiteSpeed Cache Pro','Enterprise LiteSpeed + LSCache for faster page loads.','annual',0.00,1299.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_addons` VALUES (12,8,'Priority Support','Jump the queue with 24h priority ticket handling.','monthly',0.00,199.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_addons` VALUES (13,10,'Additional IPv4 Address','One extra public IPv4 address routed to this VPS.','quarterly',0.00,399.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_addons` VALUES (14,11,'cPanel License','cPanel/WHM license added to this virtual server.','monthly',0.00,1499.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_addons` VALUES (15,14,'Extended Backup Retention','Extend offsite backup retention from 30 to 90 days.','monthly',0.00,299.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_addons` VALUES (16,15,'Legacy Migration Assistance','One-time assisted migration from the legacy platform.','one_time',0.00,1999.00,NULL,'active','2026-08-08 05:25:23','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `product_addons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_bundles`
--

DROP TABLE IF EXISTS `product_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_bundles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_product_id` bigint(20) unsigned NOT NULL,
  `component_product_id` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `discount_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_bundles_bundle_product_id_component_product_id_unique` (`bundle_product_id`,`component_product_id`),
  KEY `product_bundles_component_product_id_foreign` (`component_product_id`),
  CONSTRAINT `product_bundles_bundle_product_id_foreign` FOREIGN KEY (`bundle_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_bundles_component_product_id_foreign` FOREIGN KEY (`component_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_bundles`
--

LOCK TABLES `product_bundles` WRITE;
/*!40000 ALTER TABLE `product_bundles` DISABLE KEYS */;
INSERT INTO `product_bundles` VALUES (3,8,14,1,'fixed',1499.00,1,'2026-08-08 05:11:47','2026-08-09 01:49:36');
INSERT INTO `product_bundles` VALUES (4,11,14,1,'fixed',100.00,1,'2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_bundles` VALUES (5,9,13,1,'percent',15.00,2,'2026-08-08 05:11:47','2026-08-08 05:12:22');
INSERT INTO `product_bundles` VALUES (6,7,13,1,'percent',100.00,1,'2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_bundles` VALUES (7,11,13,1,'percent',50.00,1,'2026-08-08 05:25:23','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `product_bundles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_groups`
--

DROP TABLE IF EXISTS `product_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_groups_slug_unique` (`slug`),
  KEY `product_groups_parent_id_foreign` (`parent_id`),
  KEY `product_groups_slug_index` (`slug`),
  KEY `product_groups_status_index` (`status`),
  KEY `product_groups_sort_order_index` (`sort_order`),
  CONSTRAINT `product_groups_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `product_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_groups`
--

LOCK TABLES `product_groups` WRITE;
/*!40000 ALTER TABLE `product_groups` DISABLE KEYS */;
INSERT INTO `product_groups` VALUES (1,'Shared Hosting','shared-hosting','Shared hosting plans with cPanel',NULL,1,'active','2026-08-09 01:49:30','2026-08-09 01:49:30');
INSERT INTO `product_groups` VALUES (2,'Reseller Hosting','reseller-hosting','Reseller hosting plans',NULL,2,'active','2026-08-09 01:49:30','2026-08-09 01:49:30');
INSERT INTO `product_groups` VALUES (3,'VPS Hosting','vps-hosting','Virtual Private Server plans',NULL,3,'active','2026-08-09 01:49:30','2026-08-09 01:49:30');
INSERT INTO `product_groups` VALUES (4,'Dedicated Servers','dedicated-servers','Dedicated server plans',NULL,4,'active','2026-08-09 01:49:30','2026-08-09 01:49:30');
INSERT INTO `product_groups` VALUES (5,'Domain Registration','domain-registration','Domain name registration and transfer',NULL,5,'active','2026-08-09 01:49:30','2026-08-09 01:49:30');
INSERT INTO `product_groups` VALUES (6,'Addons & Extras','addons-extras','Product addons and extras',NULL,6,'active','2026-08-09 01:49:30','2026-08-09 01:49:30');
/*!40000 ALTER TABLE `product_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_meta`
--

DROP TABLE IF EXISTS `product_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_meta` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `meta_key` varchar(255) NOT NULL,
  `meta_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_meta_product_id_meta_key_unique` (`product_id`,`meta_key`),
  CONSTRAINT `product_meta_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_meta`
--

LOCK TABLES `product_meta` WRITE;
/*!40000 ALTER TABLE `product_meta` DISABLE KEYS */;
INSERT INTO `product_meta` VALUES (1,7,'datacenter','Mumbai (BOM1)','2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (2,7,'support_tier','standard','2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (3,8,'datacenter','Mumbai (BOM1)','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (4,8,'support_tier','priority','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (5,9,'whm_accounts','25','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (6,9,'support_tier','priority','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (7,10,'hypervisor','kvm','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (8,10,'os_templates','ubuntu-22.04,almalinux-9,debian-12','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (9,11,'hypervisor','kvm','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (10,11,'os_templates','ubuntu-22.04,rocky-9,windows-2022','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (11,12,'rack_location','BOM1 / R12','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (12,12,'ipmi','included','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (13,13,'tld','.com','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (14,13,'whois_privacy','free','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (15,14,'certificate_authority','Sectigo','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (16,14,'backup_retention_days','30','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (17,15,'legacy_plan_code','LEG-2019-A','2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_meta` VALUES (18,15,'support_tier','standard','2026-08-08 04:54:51','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `product_meta` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_option_groups`
--

DROP TABLE IF EXISTS `product_option_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_option_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `type` enum('dropdown','radio','quantity') NOT NULL DEFAULT 'dropdown',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_option_groups_product_id_index` (`product_id`),
  CONSTRAINT `product_option_groups_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_option_groups`
--

LOCK TABLES `product_option_groups` WRITE;
/*!40000 ALTER TABLE `product_option_groups` DISABLE KEYS */;
INSERT INTO `product_option_groups` VALUES (1,7,'Control Panel',1,'dropdown','2026-08-08 04:54:50');
INSERT INTO `product_option_groups` VALUES (2,7,'Backup Frequency',2,'radio','2026-08-08 04:54:50');
INSERT INTO `product_option_groups` VALUES (3,7,'Extra Disk (GB)',3,'quantity','2026-08-08 04:54:50');
INSERT INTO `product_option_groups` VALUES (4,8,'Control Panel',1,'dropdown','2026-08-08 04:54:50');
INSERT INTO `product_option_groups` VALUES (5,8,'Backup Frequency',2,'radio','2026-08-08 04:54:50');
INSERT INTO `product_option_groups` VALUES (6,8,'Additional IPs',3,'quantity','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (7,9,'Control Panel',1,'dropdown','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (8,9,'Backup Frequency',2,'radio','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (9,9,'Extra Disk (GB)',3,'quantity','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (10,10,'Control Panel',1,'dropdown','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (11,10,'Backup Frequency',2,'radio','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (12,10,'Additional IPs',3,'quantity','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (13,11,'Control Panel',1,'dropdown','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (14,11,'Backup Frequency',2,'radio','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (15,11,'Extra Disk (GB)',3,'quantity','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (16,12,'Control Panel',1,'dropdown','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (17,12,'Backup Frequency',2,'radio','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (18,12,'Additional IPs',3,'quantity','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (19,13,'Control Panel',1,'dropdown','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (20,13,'Backup Frequency',2,'radio','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (21,13,'Extra Disk (GB)',3,'quantity','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (22,14,'Control Panel',1,'dropdown','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (23,14,'Backup Frequency',2,'radio','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (24,14,'Additional IPs',3,'quantity','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (25,15,'Control Panel',1,'dropdown','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (26,15,'Backup Frequency',2,'radio','2026-08-08 04:54:51');
INSERT INTO `product_option_groups` VALUES (27,15,'Extra Disk (GB)',3,'quantity','2026-08-08 04:54:51');
/*!40000 ALTER TABLE `product_option_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_option_pricing`
--

DROP TABLE IF EXISTS `product_option_pricing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_option_pricing` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_value_id` bigint(20) unsigned NOT NULL,
  `billing_cycle` enum('free','one_time','monthly','quarterly','semi_annual','annual','biennial','triennial') NOT NULL,
  `price_modifier` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_option_pricing_option_value_id_billing_cycle_unique` (`option_value_id`,`billing_cycle`),
  CONSTRAINT `product_option_pricing_option_value_id_foreign` FOREIGN KEY (`option_value_id`) REFERENCES `product_option_values` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_option_pricing`
--

LOCK TABLES `product_option_pricing` WRITE;
/*!40000 ALTER TABLE `product_option_pricing` DISABLE KEYS */;
INSERT INTO `product_option_pricing` VALUES (1,1,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (2,2,'monthly',250.00);
INSERT INTO `product_option_pricing` VALUES (3,3,'monthly',149.00);
INSERT INTO `product_option_pricing` VALUES (4,4,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (5,5,'monthly',99.00);
INSERT INTO `product_option_pricing` VALUES (6,6,'monthly',399.00);
INSERT INTO `product_option_pricing` VALUES (7,7,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (8,8,'monthly',250.00);
INSERT INTO `product_option_pricing` VALUES (9,9,'monthly',149.00);
INSERT INTO `product_option_pricing` VALUES (10,10,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (11,11,'monthly',199.00);
INSERT INTO `product_option_pricing` VALUES (12,12,'monthly',699.00);
INSERT INTO `product_option_pricing` VALUES (13,13,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (14,14,'monthly',250.00);
INSERT INTO `product_option_pricing` VALUES (15,15,'monthly',149.00);
INSERT INTO `product_option_pricing` VALUES (16,16,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (17,17,'monthly',99.00);
INSERT INTO `product_option_pricing` VALUES (18,18,'monthly',399.00);
INSERT INTO `product_option_pricing` VALUES (19,19,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (20,20,'monthly',250.00);
INSERT INTO `product_option_pricing` VALUES (21,21,'monthly',149.00);
INSERT INTO `product_option_pricing` VALUES (22,22,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (23,23,'monthly',199.00);
INSERT INTO `product_option_pricing` VALUES (24,24,'monthly',699.00);
INSERT INTO `product_option_pricing` VALUES (25,25,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (26,26,'monthly',250.00);
INSERT INTO `product_option_pricing` VALUES (27,27,'monthly',149.00);
INSERT INTO `product_option_pricing` VALUES (28,28,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (29,29,'monthly',99.00);
INSERT INTO `product_option_pricing` VALUES (30,30,'monthly',399.00);
INSERT INTO `product_option_pricing` VALUES (31,31,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (32,32,'monthly',250.00);
INSERT INTO `product_option_pricing` VALUES (33,33,'monthly',149.00);
INSERT INTO `product_option_pricing` VALUES (34,34,'monthly',0.00);
INSERT INTO `product_option_pricing` VALUES (35,35,'monthly',199.00);
INSERT INTO `product_option_pricing` VALUES (36,36,'monthly',699.00);
INSERT INTO `product_option_pricing` VALUES (37,37,'annual',0.00);
INSERT INTO `product_option_pricing` VALUES (38,38,'annual',250.00);
INSERT INTO `product_option_pricing` VALUES (39,39,'annual',149.00);
INSERT INTO `product_option_pricing` VALUES (40,40,'annual',0.00);
INSERT INTO `product_option_pricing` VALUES (41,41,'annual',99.00);
INSERT INTO `product_option_pricing` VALUES (42,42,'annual',399.00);
INSERT INTO `product_option_pricing` VALUES (43,43,'annual',0.00);
INSERT INTO `product_option_pricing` VALUES (44,44,'annual',250.00);
INSERT INTO `product_option_pricing` VALUES (45,45,'annual',149.00);
INSERT INTO `product_option_pricing` VALUES (46,46,'annual',0.00);
INSERT INTO `product_option_pricing` VALUES (47,47,'annual',199.00);
INSERT INTO `product_option_pricing` VALUES (48,48,'annual',699.00);
INSERT INTO `product_option_pricing` VALUES (49,49,'quarterly',0.00);
INSERT INTO `product_option_pricing` VALUES (50,50,'quarterly',250.00);
INSERT INTO `product_option_pricing` VALUES (51,51,'quarterly',149.00);
INSERT INTO `product_option_pricing` VALUES (52,52,'quarterly',0.00);
INSERT INTO `product_option_pricing` VALUES (53,53,'quarterly',99.00);
INSERT INTO `product_option_pricing` VALUES (54,54,'quarterly',399.00);
/*!40000 ALTER TABLE `product_option_pricing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_option_values`
--

DROP TABLE IF EXISTS `product_option_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_option_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_group_id` bigint(20) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_option_values_option_group_id_foreign` (`option_group_id`),
  CONSTRAINT `product_option_values_option_group_id_foreign` FOREIGN KEY (`option_group_id`) REFERENCES `product_option_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_option_values`
--

LOCK TABLES `product_option_values` WRITE;
/*!40000 ALTER TABLE `product_option_values` DISABLE KEYS */;
INSERT INTO `product_option_values` VALUES (1,1,'cPanel',1);
INSERT INTO `product_option_values` VALUES (2,1,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (3,2,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (4,2,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (5,3,'10 GB block',1);
INSERT INTO `product_option_values` VALUES (6,3,'50 GB block',2);
INSERT INTO `product_option_values` VALUES (7,4,'cPanel',1);
INSERT INTO `product_option_values` VALUES (8,4,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (9,5,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (10,5,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (11,6,'1 IPv4 address',1);
INSERT INTO `product_option_values` VALUES (12,6,'4 IPv4 addresses',2);
INSERT INTO `product_option_values` VALUES (13,7,'cPanel',1);
INSERT INTO `product_option_values` VALUES (14,7,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (15,8,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (16,8,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (17,9,'10 GB block',1);
INSERT INTO `product_option_values` VALUES (18,9,'50 GB block',2);
INSERT INTO `product_option_values` VALUES (19,10,'cPanel',1);
INSERT INTO `product_option_values` VALUES (20,10,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (21,11,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (22,11,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (23,12,'1 IPv4 address',1);
INSERT INTO `product_option_values` VALUES (24,12,'4 IPv4 addresses',2);
INSERT INTO `product_option_values` VALUES (25,13,'cPanel',1);
INSERT INTO `product_option_values` VALUES (26,13,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (27,14,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (28,14,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (29,15,'10 GB block',1);
INSERT INTO `product_option_values` VALUES (30,15,'50 GB block',2);
INSERT INTO `product_option_values` VALUES (31,16,'cPanel',1);
INSERT INTO `product_option_values` VALUES (32,16,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (33,17,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (34,17,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (35,18,'1 IPv4 address',1);
INSERT INTO `product_option_values` VALUES (36,18,'4 IPv4 addresses',2);
INSERT INTO `product_option_values` VALUES (37,19,'cPanel',1);
INSERT INTO `product_option_values` VALUES (38,19,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (39,20,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (40,20,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (41,21,'10 GB block',1);
INSERT INTO `product_option_values` VALUES (42,21,'50 GB block',2);
INSERT INTO `product_option_values` VALUES (43,22,'cPanel',1);
INSERT INTO `product_option_values` VALUES (44,22,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (45,23,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (46,23,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (47,24,'1 IPv4 address',1);
INSERT INTO `product_option_values` VALUES (48,24,'4 IPv4 addresses',2);
INSERT INTO `product_option_values` VALUES (49,25,'cPanel',1);
INSERT INTO `product_option_values` VALUES (50,25,'Plesk Web Admin',2);
INSERT INTO `product_option_values` VALUES (51,26,'Daily backups',1);
INSERT INTO `product_option_values` VALUES (52,26,'Weekly backups',2);
INSERT INTO `product_option_values` VALUES (53,27,'10 GB block',1);
INSERT INTO `product_option_values` VALUES (54,27,'50 GB block',2);
/*!40000 ALTER TABLE `product_option_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_pricing`
--

DROP TABLE IF EXISTS `product_pricing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_pricing` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `billing_cycle` enum('free','one_time','monthly','quarterly','semi_annual','annual','biennial','triennial') NOT NULL,
  `setup_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `promo_price` decimal(12,2) DEFAULT NULL,
  `promo_start` date DEFAULT NULL,
  `promo_end` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_pricing_product_id_billing_cycle_unique` (`product_id`,`billing_cycle`),
  CONSTRAINT `product_pricing_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_pricing`
--

LOCK TABLES `product_pricing` WRITE;
/*!40000 ALTER TABLE `product_pricing` DISABLE KEYS */;
INSERT INTO `product_pricing` VALUES (1,1,'free',0.00,0.00,NULL,NULL,NULL,'2026-08-07 04:53:25','2026-08-07 04:53:25');
INSERT INTO `product_pricing` VALUES (2,1,'monthly',0.00,199.00,NULL,NULL,NULL,'2026-08-07 04:53:25','2026-08-07 04:53:25');
INSERT INTO `product_pricing` VALUES (3,1,'annual',0.00,1999.00,NULL,NULL,NULL,'2026-08-07 04:53:25','2026-08-07 04:53:25');
INSERT INTO `product_pricing` VALUES (8,6,'free',0.00,0.00,NULL,NULL,NULL,'2026-08-07 09:03:53','2026-08-07 09:03:53');
INSERT INTO `product_pricing` VALUES (9,6,'monthly',0.00,4999.00,NULL,NULL,NULL,'2026-08-07 09:03:53','2026-08-07 09:03:53');
INSERT INTO `product_pricing` VALUES (10,7,'monthly',0.00,199.00,NULL,NULL,NULL,'2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (11,7,'annual',0.00,1999.00,1799.10,NULL,NULL,'2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (12,7,'biennial',0.00,3799.00,NULL,NULL,NULL,'2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (13,8,'monthly',0.00,499.00,NULL,NULL,NULL,'2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (14,8,'quarterly',0.00,1399.00,NULL,NULL,NULL,'2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (15,8,'annual',0.00,4999.00,4499.10,NULL,NULL,'2026-08-08 04:54:50','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (16,9,'monthly',0.00,1299.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (17,9,'annual',0.00,12999.00,11699.10,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (18,10,'monthly',0.00,899.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (19,10,'semi_annual',0.00,4999.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (20,10,'annual',0.00,8999.00,8099.10,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (21,11,'monthly',0.00,2499.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (22,11,'annual',0.00,24999.00,22499.10,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (23,11,'triennial',0.00,69999.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (24,12,'monthly',0.00,8999.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (25,12,'annual',0.00,89999.00,80999.10,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (26,13,'annual',0.00,899.00,809.10,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (27,13,'biennial',0.00,1699.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (28,14,'annual',0.00,1499.00,1349.10,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (29,14,'one_time',0.00,1999.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (30,15,'quarterly',0.00,349.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (31,15,'annual',0.00,1299.00,1169.10,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
INSERT INTO `product_pricing` VALUES (32,15,'free',0.00,0.00,NULL,NULL,NULL,'2026-08-08 04:54:51','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `product_pricing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_quota_summary`
--

DROP TABLE IF EXISTS `product_quota_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_quota_summary` (
  `product_id` bigint(20) unsigned NOT NULL,
  `summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`summary_json`)),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `product_quota_summary_product_id_unique` (`product_id`),
  CONSTRAINT `product_quota_summary_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_quota_summary`
--

LOCK TABLES `product_quota_summary` WRITE;
/*!40000 ALTER TABLE `product_quota_summary` DISABLE KEYS */;
INSERT INTO `product_quota_summary` VALUES (7,'{\"disk_mb\":10240,\"bandwidth_mb\":102400,\"email_accounts\":10,\"databases\":2,\"cpu_cores\":1,\"cpu_mhz\":1000,\"ram_mb\":1024,\"ips\":0,\"ftp_accounts\":2,\"subdomains\":5}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (8,'{\"disk_mb\":51200,\"bandwidth_mb\":512000,\"email_accounts\":100,\"databases\":25,\"cpu_cores\":2,\"cpu_mhz\":2000,\"ram_mb\":2048,\"ips\":0,\"ftp_accounts\":10,\"subdomains\":50}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (9,'{\"disk_mb\":102400,\"bandwidth_mb\":1024000,\"email_accounts\":500,\"databases\":100,\"cpu_cores\":4,\"cpu_mhz\":2400,\"ram_mb\":4096,\"ips\":1,\"ftp_accounts\":50,\"subdomains\":200}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (10,'{\"disk_mb\":51200,\"bandwidth_mb\":2048000,\"email_accounts\":0,\"databases\":0,\"cpu_cores\":2,\"cpu_mhz\":2600,\"ram_mb\":2048,\"ips\":1,\"ftp_accounts\":0,\"subdomains\":0}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (11,'{\"disk_mb\":204800,\"bandwidth_mb\":5120000,\"email_accounts\":0,\"databases\":0,\"cpu_cores\":4,\"cpu_mhz\":3200,\"ram_mb\":8192,\"ips\":2,\"ftp_accounts\":0,\"subdomains\":0}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (12,'{\"disk_mb\":1048576,\"bandwidth_mb\":10240000,\"email_accounts\":0,\"databases\":0,\"cpu_cores\":8,\"cpu_mhz\":3400,\"ram_mb\":32768,\"ips\":5,\"ftp_accounts\":0,\"subdomains\":0}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (13,'{\"disk_mb\":0,\"bandwidth_mb\":0,\"email_accounts\":0,\"databases\":0,\"cpu_cores\":0,\"cpu_mhz\":0,\"ram_mb\":0,\"ips\":0,\"ftp_accounts\":0,\"subdomains\":0}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (14,'{\"disk_mb\":10240,\"bandwidth_mb\":0,\"email_accounts\":0,\"databases\":0,\"cpu_cores\":0,\"cpu_mhz\":0,\"ram_mb\":0,\"ips\":0,\"ftp_accounts\":0,\"subdomains\":0}','2026-08-09 01:49:36');
INSERT INTO `product_quota_summary` VALUES (15,'{\"disk_mb\":20480,\"bandwidth_mb\":204800,\"email_accounts\":25,\"databases\":10,\"cpu_cores\":1,\"cpu_mhz\":1200,\"ram_mb\":1024,\"ips\":0,\"ftp_accounts\":5,\"subdomains\":20}','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `product_quota_summary` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_resources`
--

DROP TABLE IF EXISTS `product_resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_resources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `resource_type_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_upgradable` tinyint(1) NOT NULL DEFAULT 0,
  `min_quantity` decimal(12,4) DEFAULT NULL,
  `max_quantity` decimal(12,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_resources_product_id_resource_type_id_unique` (`product_id`,`resource_type_id`),
  KEY `product_resources_product_id_index` (`product_id`),
  KEY `product_resources_resource_type_id_index` (`resource_type_id`),
  CONSTRAINT `product_resources_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_resources_resource_type_id_foreign` FOREIGN KEY (`resource_type_id`) REFERENCES `resource_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_resources`
--

LOCK TABLES `product_resources` WRITE;
/*!40000 ALTER TABLE `product_resources` DISABLE KEYS */;
INSERT INTO `product_resources` VALUES (1,7,1,1.0000,1,1,1.0000,4.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (2,7,3,1024.0000,1,1,512.0000,4096.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (3,8,1,2.0000,1,1,1.0000,8.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (4,8,3,2048.0000,1,1,1024.0000,8192.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (5,9,1,4.0000,1,1,2.0000,16.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (6,9,3,4096.0000,1,1,2048.0000,16384.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (7,10,1,2.0000,1,1,1.0000,4.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (8,10,3,2048.0000,1,1,1024.0000,4096.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (9,11,1,4.0000,1,1,2.0000,8.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (10,11,3,8192.0000,1,1,4096.0000,16384.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (11,12,1,8.0000,1,0,8.0000,8.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (12,12,3,32768.0000,1,0,32768.0000,32768.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (13,13,14,1.0000,1,0,1.0000,1.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (14,13,10,0.0000,0,1,0.0000,100.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (15,14,15,1.0000,1,0,1.0000,1.0000,'2026-08-08 05:25:23');
INSERT INTO `product_resources` VALUES (16,14,8,10240.0000,1,1,5120.0000,51200.0000,'2026-08-08 05:25:23');
/*!40000 ALTER TABLE `product_resources` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_upgrade_paths`
--

DROP TABLE IF EXISTS `product_upgrade_paths`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_upgrade_paths` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_product_id` bigint(20) unsigned NOT NULL,
  `to_product_id` bigint(20) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_upgrade_paths_from_product_id_to_product_id_unique` (`from_product_id`,`to_product_id`),
  KEY `product_upgrade_paths_to_product_id_foreign` (`to_product_id`),
  CONSTRAINT `product_upgrade_paths_from_product_id_foreign` FOREIGN KEY (`from_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_upgrade_paths_to_product_id_foreign` FOREIGN KEY (`to_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_upgrade_paths`
--

LOCK TABLES `product_upgrade_paths` WRITE;
/*!40000 ALTER TABLE `product_upgrade_paths` DISABLE KEYS */;
INSERT INTO `product_upgrade_paths` VALUES (2,7,8,1,'2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_upgrade_paths` VALUES (3,10,11,1,'2026-08-08 05:25:23','2026-08-09 01:49:36');
INSERT INTO `product_upgrade_paths` VALUES (4,8,9,1,'2026-08-08 05:25:23','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `product_upgrade_paths` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_upgrades`
--

DROP TABLE IF EXISTS `product_upgrades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_upgrades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_product_id` bigint(20) unsigned NOT NULL,
  `to_product_id` bigint(20) unsigned NOT NULL,
  `upgrade_type` enum('upgrade','downgrade','both') NOT NULL DEFAULT 'both',
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_upgrades_from_product_id_to_product_id_unique` (`from_product_id`,`to_product_id`),
  KEY `product_upgrades_to_product_id_foreign` (`to_product_id`),
  CONSTRAINT `product_upgrades_from_product_id_foreign` FOREIGN KEY (`from_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_upgrades_to_product_id_foreign` FOREIGN KEY (`to_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_upgrades`
--

LOCK TABLES `product_upgrades` WRITE;
/*!40000 ALTER TABLE `product_upgrades` DISABLE KEYS */;
INSERT INTO `product_upgrades` VALUES (1,7,8,'upgrade',1);
INSERT INTO `product_upgrades` VALUES (2,10,11,'upgrade',1);
INSERT INTO `product_upgrades` VALUES (3,8,9,'both',1);
/*!40000 ALTER TABLE `product_upgrades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('shared_hosting','reseller','vps','dedicated','domain','addon','bundle','hosting','other') NOT NULL DEFAULT 'shared_hosting',
  `product_group_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` enum('monthly','quarterly','semi_annual','annual','biennial','one_time') NOT NULL DEFAULT 'monthly',
  `setup_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `provisioning_module` enum('none','cpanel','plesk','directadmin','virtualizor','custom') NOT NULL DEFAULT 'none',
  `server_group_id` bigint(20) unsigned DEFAULT NULL,
  `welcome_email_template_id` bigint(20) unsigned DEFAULT NULL,
  `require_domain` tinyint(1) NOT NULL DEFAULT 1,
  `show_in_order` tinyint(1) NOT NULL DEFAULT 1,
  `show_in_affiliate` tinyint(1) NOT NULL DEFAULT 1,
  `only_admin` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `quota_disk` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_bandwidth` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_email` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_database` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_cpu_cores` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_cpu_speed` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_ram` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_ips` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_ftp_accounts` int(10) unsigned NOT NULL DEFAULT 0,
  `quota_subdomains` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `gst_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `gst_rate` decimal(5,2) DEFAULT NULL,
  `gst_type` enum('standard','exempt','reverse_charge') NOT NULL DEFAULT 'standard',
  `cgst_rate` decimal(5,2) DEFAULT NULL,
  `sgst_rate` decimal(5,2) DEFAULT NULL,
  `igst_rate` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `products_type_index` (`type`),
  KEY `products_status_index` (`status`),
  KEY `products_sort_order_index` (`sort_order`),
  KEY `products_product_group_id_index` (`product_group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'Linux shared hosting Lite','shared_hosting',1,NULL,199.00,'monthly',0.00,'plesk',1,NULL,1,1,1,1,0,0,0,0,0,0,0,0,0,0,0,'active',0,NULL,'standard',NULL,NULL,NULL,'2026-08-07 04:53:25','2026-08-07 04:53:25');
INSERT INTO `products` VALUES (6,'Windows VPS Lite','vps',3,NULL,4999.00,'monthly',0.00,'custom',2,NULL,1,1,1,1,0,0,0,0,0,0,0,0,0,0,0,'active',0,NULL,'standard',NULL,NULL,NULL,'2026-08-07 09:03:53','2026-08-07 09:03:53');
INSERT INTO `products` VALUES (7,'Demo Starter Shared Hosting','shared_hosting',1,'Single site cPanel plan for personal projects and portfolios.',199.00,'monthly',0.00,'cpanel',NULL,NULL,1,1,1,0,10,10240,102400,10,2,1,1000,1024,0,2,5,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:50','2026-08-08 04:54:50');
INSERT INTO `products` VALUES (8,'Demo Business Shared Hosting','shared_hosting',1,'Unlimited sites, LiteSpeed cache and free SSL for growing businesses.',499.00,'monthly',0.00,'cpanel',NULL,NULL,1,1,1,0,20,51200,512000,100,25,2,2000,2048,0,10,50,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:50','2026-08-08 04:54:50');
INSERT INTO `products` VALUES (9,'Demo Reseller Bronze','reseller',2,'WHM reseller account with 25 cPanel slots and white label nameservers.',1299.00,'monthly',500.00,'cpanel',NULL,NULL,1,1,1,0,30,102400,1024000,500,100,4,2400,4096,1,50,200,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:51','2026-08-08 04:54:51');
INSERT INTO `products` VALUES (10,'Demo Cloud VPS 2GB','vps',3,'KVM VPS with 2 vCPU, 2 GB RAM and NVMe storage.',899.00,'monthly',0.00,'virtualizor',NULL,NULL,0,1,1,0,40,51200,2048000,0,0,2,2600,2048,1,0,0,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:51','2026-08-08 04:54:51');
INSERT INTO `products` VALUES (11,'Demo Cloud VPS 8GB','vps',3,'KVM VPS with 4 vCPU, 8 GB RAM, ideal for staging and CI runners.',2499.00,'monthly',0.00,'virtualizor',NULL,NULL,0,1,1,0,50,204800,5120000,0,0,4,3200,8192,2,0,0,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:51','2026-08-08 04:54:51');
INSERT INTO `products` VALUES (12,'Demo Dedicated E3 Server','dedicated',4,'Bare metal Xeon E3 server with IPMI access and 5 usable IPv4 addresses.',8999.00,'monthly',2500.00,'custom',NULL,NULL,0,1,1,0,60,1048576,10240000,0,0,8,3400,32768,5,0,0,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:51','2026-08-08 04:54:51');
INSERT INTO `products` VALUES (13,'Demo .com Domain Registration','domain',5,'One year .com registration including free WHOIS privacy.',899.00,'annual',0.00,'none',NULL,NULL,1,1,1,0,70,0,0,0,0,0,0,0,0,0,0,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:51','2026-08-08 04:54:51');
INSERT INTO `products` VALUES (14,'Demo SSL & Backup Addon','addon',6,'Positive SSL certificate bundled with offsite daily backups.',1499.00,'annual',0.00,'none',NULL,NULL,1,1,1,0,80,10240,0,0,0,0,0,0,0,0,0,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:51','2026-08-08 04:54:51');
INSERT INTO `products` VALUES (15,'Demo Legacy Hosting Pack','hosting',1,'Grandfathered hosting bundle retained for migrated legacy accounts.',349.00,'quarterly',0.00,'directadmin',NULL,NULL,1,1,1,0,90,20480,204800,25,10,1,1200,1024,0,5,20,'active',1,18.00,'standard',9.00,9.00,18.00,'2026-08-08 04:54:51','2026-08-08 04:54:51');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `provisioning_adapters`
--

DROP TABLE IF EXISTS `provisioning_adapters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `provisioning_adapters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `adapter_class` varchar(255) NOT NULL,
  `method` enum('manual','cpanel','plesk','directadmin','proxmox','vmware','hyperv','solusvm','virtualizor','docker','kubernetes','api','custom_script') NOT NULL,
  `config_schema` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_schema`)),
  `api_endpoint_template` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provisioning_adapters_name_unique` (`name`),
  KEY `provisioning_adapters_method_index` (`method`),
  KEY `provisioning_adapters_is_enabled_index` (`is_enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `provisioning_adapters`
--

LOCK TABLES `provisioning_adapters` WRITE;
/*!40000 ALTER TABLE `provisioning_adapters` DISABLE KEYS */;
INSERT INTO `provisioning_adapters` VALUES (1,'cpanel','Integrations\\CPanel','cpanel',NULL,'https://{host}:2087/whm/json-api/cpanel',1,NULL,NULL);
INSERT INTO `provisioning_adapters` VALUES (2,'plesk','Integrations\\Plesk','plesk',NULL,'https://{host}:8443/enterprise/control/agent.php',1,NULL,NULL);
INSERT INTO `provisioning_adapters` VALUES (3,'directadmin','Integrations\\DirectAdmin','directadmin',NULL,'https://{host}:2222/CMD_API',1,NULL,NULL);
INSERT INTO `provisioning_adapters` VALUES (4,'virtualizor','Integrations\\Virtualizor','virtualizor',NULL,'https://{host}:4085',1,NULL,NULL);
INSERT INTO `provisioning_adapters` VALUES (5,'custom','Integrations\\CustomScript','custom_script',NULL,NULL,1,NULL,NULL);
/*!40000 ALTER TABLE `provisioning_adapters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `provisioning_events`
--

DROP TABLE IF EXISTS `provisioning_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `provisioning_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','processing','completed','failed','retrying') NOT NULL DEFAULT 'pending',
  `priority` enum('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `max_attempts` tinyint(4) NOT NULL DEFAULT 3,
  `last_error` text DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `locked_by` varchar(50) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `provisioning_events_status_priority_scheduled_at_index` (`status`,`priority`,`scheduled_at`),
  KEY `provisioning_events_event_type_index` (`event_type`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `provisioning_events`
--

LOCK TABLES `provisioning_events` WRITE;
/*!40000 ALTER TABLE `provisioning_events` DISABLE KEYS */;
INSERT INTO `provisioning_events` VALUES (1,'account.create','{\"adapter\":\"cpanel\",\"adapter_id\":1,\"service_id\":1,\"sequence\":1}','completed','high',1,3,NULL,'2026-08-08 19:00:00',NULL,NULL,'2026-08-08 04:54:17','2026-08-08 20:00:00');
INSERT INTO `provisioning_events` VALUES (2,'account.suspend','{\"adapter\":\"cpanel\",\"adapter_id\":1,\"service_id\":1,\"sequence\":2}','pending','normal',0,3,NULL,'2026-08-08 19:00:00',NULL,NULL,'2026-08-08 04:54:17',NULL);
INSERT INTO `provisioning_events` VALUES (3,'account.unsuspend','{\"adapter\":\"custom\",\"adapter_id\":5,\"service_id\":2,\"sequence\":1}','processing','normal',1,3,NULL,'2026-08-08 20:00:00','worker-2','2026-08-09 07:14:00','2026-08-08 04:54:17',NULL);
INSERT INTO `provisioning_events` VALUES (4,'account.terminate','{\"adapter\":\"custom\",\"adapter_id\":5,\"service_id\":2,\"sequence\":2}','failed','critical',3,3,'Adapter custom returned HTTP 500 for account.terminate','2026-08-08 20:00:00',NULL,NULL,'2026-08-08 04:54:17',NULL);
INSERT INTO `provisioning_events` VALUES (5,'package.change','{\"adapter\":\"directadmin\",\"adapter_id\":3,\"service_id\":3,\"sequence\":1}','retrying','low',2,3,'Adapter directadmin returned HTTP 500 for package.change','2026-08-08 21:00:00',NULL,NULL,'2026-08-08 04:54:17',NULL);
INSERT INTO `provisioning_events` VALUES (6,'server.sync','{\"adapter\":\"directadmin\",\"adapter_id\":3,\"service_id\":3,\"sequence\":2}','completed','normal',1,3,NULL,'2026-08-08 21:00:00',NULL,NULL,'2026-08-08 04:54:17','2026-08-08 22:00:00');
INSERT INTO `provisioning_events` VALUES (7,'ssl.install','{\"adapter\":\"plesk\",\"adapter_id\":2,\"service_id\":4,\"sequence\":1}','pending','high',0,3,NULL,'2026-08-08 22:00:00',NULL,NULL,'2026-08-08 04:54:17',NULL);
INSERT INTO `provisioning_events` VALUES (8,'dns.zone.create','{\"adapter\":\"plesk\",\"adapter_id\":2,\"service_id\":4,\"sequence\":2}','completed','low',1,3,NULL,'2026-08-08 22:00:00',NULL,NULL,'2026-08-08 04:54:17','2026-08-08 23:00:00');
INSERT INTO `provisioning_events` VALUES (9,'vm.provision','{\"adapter\":\"virtualizor\",\"adapter_id\":4,\"service_id\":5,\"sequence\":1}','processing','critical',1,3,NULL,'2026-08-08 23:00:00','worker-5','2026-08-09 07:14:00','2026-08-08 04:54:17',NULL);
INSERT INTO `provisioning_events` VALUES (10,'vm.rebuild','{\"adapter\":\"virtualizor\",\"adapter_id\":4,\"service_id\":5,\"sequence\":2}','failed','normal',2,3,'Adapter virtualizor returned HTTP 500 for vm.rebuild','2026-08-08 23:00:00',NULL,NULL,'2026-08-08 04:54:17',NULL);
/*!40000 ALTER TABLE `provisioning_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quote_items`
--

DROP TABLE IF EXISTS `quote_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `quote_id` bigint(20) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `qty` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gst_type` enum('standard','exempt','reverse_charge') DEFAULT NULL,
  `gst_rate` decimal(5,2) DEFAULT NULL,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `quote_items_quote_id_index` (`quote_id`),
  CONSTRAINT `quote_items_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quote_items`
--

LOCK TABLES `quote_items` WRITE;
/*!40000 ALTER TABLE `quote_items` DISABLE KEYS */;
INSERT INTO `quote_items` VALUES (1,1,'Demo Business Shared Hosting',2,499.00,998.00,'standard',18.00,179.64);
INSERT INTO `quote_items` VALUES (2,1,'Demo SSL & Backup Addon',1,1499.00,1499.00,'standard',18.00,269.82);
INSERT INTO `quote_items` VALUES (3,2,'Demo Cloud VPS 8GB',2,2499.00,4998.00,'standard',18.00,899.64);
INSERT INTO `quote_items` VALUES (4,2,'Demo Cloud VPS 2GB',1,899.00,899.00,'standard',18.00,161.82);
INSERT INTO `quote_items` VALUES (5,2,'Demo SSL & Backup Addon',2,1499.00,2998.00,'standard',18.00,539.64);
INSERT INTO `quote_items` VALUES (6,3,'Demo Reseller Bronze',1,1299.00,1299.00,'standard',18.00,233.82);
INSERT INTO `quote_items` VALUES (7,3,'Demo Starter Shared Hosting',3,199.00,597.00,'standard',18.00,107.46);
INSERT INTO `quote_items` VALUES (8,4,'Demo Dedicated E3 Server',1,8999.00,8999.00,'standard',18.00,1619.82);
INSERT INTO `quote_items` VALUES (9,4,'Demo .com Domain Registration',2,899.00,1798.00,'exempt',0.00,0.00);
INSERT INTO `quote_items` VALUES (10,5,'Demo Legacy Hosting Pack',4,349.00,1396.00,'standard',18.00,251.28);
INSERT INTO `quote_items` VALUES (11,5,'Demo Starter Shared Hosting',1,199.00,199.00,'reverse_charge',0.00,0.00);
/*!40000 ALTER TABLE `quote_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quotes`
--

DROP TABLE IF EXISTS `quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `quote_no` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `stage` enum('draft','delivered','accepted','rejected','dead') NOT NULL DEFAULT 'draft',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quotes_customer_id_index` (`customer_id`),
  CONSTRAINT `quotes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quotes`
--

LOCK TABLES `quotes` WRITE;
/*!40000 ALTER TABLE `quotes` DISABLE KEYS */;
INSERT INTO `quotes` VALUES (1,6,'DEMO-QT-2026-0001','Shared hosting bundle for corporate website','accepted',2497.00,200.00,449.46,2746.46,'Accepted by the customer; convert to order on approval.','2026-09-08','2026-08-08 05:21:12','2026-08-09 01:49:36');
INSERT INTO `quotes` VALUES (2,7,'DEMO-QT-2026-0002','Cloud VPS migration proposal','delivered',8895.00,0.00,1601.10,10496.10,'Sent to the customer, awaiting a decision.','2026-08-30','2026-08-08 05:21:12','2026-08-09 01:49:36');
INSERT INTO `quotes` VALUES (3,8,'DEMO-QT-2026-0003','Reseller programme starter package','draft',1896.00,100.00,341.28,2137.28,'Draft - pricing still under review by sales.','2026-08-23','2026-08-08 05:21:12','2026-08-09 01:49:36');
INSERT INTO `quotes` VALUES (4,9,'DEMO-QT-2026-0004','Dedicated server refresh with domain renewals','delivered',10797.00,500.00,1619.82,11916.82,'Hardware refresh proposal including a two-year domain renewal.','2026-09-23','2026-08-08 05:21:12','2026-08-09 01:49:36');
INSERT INTO `quotes` VALUES (5,10,'DEMO-QT-2026-0005','Legacy hosting consolidation','rejected',1595.00,0.00,251.28,1846.28,'Customer declined; kept for pipeline reporting.','2026-07-30','2026-08-08 05:21:12','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `quotes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `racks`
--

DROP TABLE IF EXISTS `racks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `racks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `datacenter_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `u_height` int(10) unsigned NOT NULL DEFAULT 42,
  `u_available` int(10) unsigned NOT NULL DEFAULT 42,
  `power_capacity_watts` int(10) unsigned DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `racks`
--

LOCK TABLES `racks` WRITE;
/*!40000 ALTER TABLE `racks` DISABLE KEYS */;
INSERT INTO `racks` VALUES (1,1,'Rack A1',42,42,NULL,'active','2026-08-09 01:49:30','2026-08-09 01:49:30');
INSERT INTO `racks` VALUES (2,1,'Rack A2',42,38,8000,'active','2026-08-08 04:54:01','2026-08-08 04:54:01');
INSERT INTO `racks` VALUES (3,2,'Rack B1',47,41,10000,'active','2026-08-08 04:54:01','2026-08-08 04:54:01');
INSERT INTO `racks` VALUES (4,2,'Rack B2',47,47,10000,'inactive','2026-08-08 04:54:01','2026-08-08 04:54:01');
INSERT INTO `racks` VALUES (5,3,'Rack C1',42,36,6000,'active','2026-08-08 04:54:01','2026-08-08 04:54:01');
INSERT INTO `racks` VALUES (6,3,'Rack C2',42,42,6000,'maintenance','2026-08-08 04:54:01','2026-08-08 04:54:01');
INSERT INTO `racks` VALUES (7,3,'Rack C3',42,40,6000,'active','2026-08-08 04:54:01','2026-08-08 04:54:01');
/*!40000 ALTER TABLE `racks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `registrar_settings`
--

DROP TABLE IF EXISTS `registrar_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `registrar_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `registrar` varchar(255) NOT NULL,
  `setting_key` varchar(255) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registrar_settings_registrar_setting_key_unique` (`registrar`,`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `registrar_settings`
--

LOCK TABLES `registrar_settings` WRITE;
/*!40000 ALTER TABLE `registrar_settings` DISABLE KEYS */;
INSERT INTO `registrar_settings` VALUES (1,'resellerclub','api_key','demo_rc_apikey_9f3a2b1c','2026-06-30 19:30:00','2026-06-30 19:30:00');
INSERT INTO `registrar_settings` VALUES (2,'resellerclub','test_mode','1','2026-06-30 20:30:00','2026-06-30 20:30:00');
INSERT INTO `registrar_settings` VALUES (3,'resellerclub','username','demo.reseller','2026-06-30 21:30:00','2026-06-30 21:30:00');
INSERT INTO `registrar_settings` VALUES (4,'godaddy','api_key','demo_gd_apikey_7c1e4d8f','2026-06-30 22:30:00','2026-06-30 22:30:00');
INSERT INTO `registrar_settings` VALUES (5,'godaddy','secret','demo_gd_secret_2b8f0a5e','2026-06-30 23:30:00','2026-06-30 23:30:00');
INSERT INTO `registrar_settings` VALUES (6,'godaddy','enabled','0','2026-07-01 00:30:00','2026-07-01 00:30:00');
/*!40000 ALTER TABLE `registrar_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `resource_allocations`
--

DROP TABLE IF EXISTS `resource_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resource_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` int(10) unsigned NOT NULL,
  `resource_type_id` bigint(20) unsigned NOT NULL,
  `pool_id` int(10) unsigned DEFAULT NULL,
  `inventory_asset_id` int(10) unsigned DEFAULT NULL,
  `quantity_allocated` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `allocated_at` datetime NOT NULL,
  `released_at` datetime DEFAULT NULL,
  `status` enum('allocated','released') NOT NULL DEFAULT 'allocated',
  PRIMARY KEY (`id`),
  KEY `resource_allocations_service_id_index` (`service_id`),
  KEY `resource_allocations_resource_type_id_status_index` (`resource_type_id`,`status`),
  CONSTRAINT `resource_allocations_resource_type_id_foreign` FOREIGN KEY (`resource_type_id`) REFERENCES `resource_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `resource_allocations`
--

LOCK TABLES `resource_allocations` WRITE;
/*!40000 ALTER TABLE `resource_allocations` DISABLE KEYS */;
INSERT INTO `resource_allocations` VALUES (1,1,1,1,NULL,2.0000,'2026-07-09 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (2,1,3,2,NULL,4096.0000,'2026-07-10 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (3,1,4,3,NULL,51200.0000,'2026-07-11 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (4,1,6,4,NULL,1.0000,'2026-07-12 10:00:00','2026-08-06 10:00:00','released');
INSERT INTO `resource_allocations` VALUES (5,2,1,1,NULL,2.0000,'2026-07-13 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (6,2,3,2,NULL,4096.0000,'2026-07-14 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (7,2,4,3,NULL,51200.0000,'2026-07-15 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (8,2,6,4,NULL,1.0000,'2026-07-16 10:00:00','2026-08-06 10:00:00','released');
INSERT INTO `resource_allocations` VALUES (9,3,1,1,NULL,2.0000,'2026-07-17 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (10,3,3,2,NULL,4096.0000,'2026-07-18 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (11,3,4,3,NULL,51200.0000,'2026-07-19 10:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (12,3,6,4,NULL,1.0000,'2026-07-20 10:00:00','2026-08-06 10:00:00','released');
INSERT INTO `resource_allocations` VALUES (13,4,1,1,NULL,2.0000,'2026-07-10 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (14,4,3,2,NULL,4096.0000,'2026-07-11 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (15,4,4,3,NULL,51200.0000,'2026-07-12 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (16,4,6,4,NULL,1.0000,'2026-07-13 07:00:00','2026-08-07 07:00:00','released');
INSERT INTO `resource_allocations` VALUES (17,5,1,1,NULL,2.0000,'2026-07-14 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (18,5,3,2,NULL,4096.0000,'2026-07-15 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (19,5,4,3,NULL,51200.0000,'2026-07-16 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (20,5,6,4,NULL,1.0000,'2026-07-17 07:00:00','2026-08-07 07:00:00','released');
INSERT INTO `resource_allocations` VALUES (21,6,1,1,NULL,2.0000,'2026-07-18 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (22,6,3,2,NULL,4096.0000,'2026-07-19 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (23,6,4,3,NULL,51200.0000,'2026-07-20 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (24,6,6,4,NULL,1.0000,'2026-07-21 07:00:00','2026-08-07 07:00:00','released');
INSERT INTO `resource_allocations` VALUES (25,7,1,1,NULL,2.0000,'2026-07-22 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (26,7,3,2,NULL,4096.0000,'2026-07-23 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (27,7,4,3,NULL,51200.0000,'2026-07-24 07:00:00',NULL,'allocated');
INSERT INTO `resource_allocations` VALUES (28,7,6,4,NULL,1.0000,'2026-07-25 07:00:00','2026-08-07 07:00:00','released');
/*!40000 ALTER TABLE `resource_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `resource_pools`
--

DROP TABLE IF EXISTS `resource_pools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resource_pools` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `pool_type` enum('hypervisor','network','storage','license') NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `total_capacity` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `server_id` int(10) unsigned DEFAULT NULL,
  `datacenter_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','maintenance','retired') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `resource_pools_pool_type_index` (`pool_type`),
  KEY `resource_pools_parent_id_index` (`parent_id`),
  KEY `resource_pools_server_id_index` (`server_id`),
  KEY `resource_pools_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `resource_pools`
--

LOCK TABLES `resource_pools` WRITE;
/*!40000 ALTER TABLE `resource_pools` DISABLE KEYS */;
INSERT INTO `resource_pools` VALUES (1,'Hypervisor Pool - Node A','hypervisor',NULL,128.0000,'cores',1,1,'active','2026-08-08 04:54:17','2026-08-09 01:49:36');
INSERT INTO `resource_pools` VALUES (2,'Hypervisor Pool - Node B','hypervisor',NULL,524288.0000,'MB',2,1,'active','2026-08-08 04:54:17','2026-08-09 01:49:36');
INSERT INTO `resource_pools` VALUES (3,'Storage Pool - SSD Tier 1','storage',NULL,8388608.0000,'MB',3,1,'active','2026-08-08 04:54:17','2026-08-09 01:49:36');
INSERT INTO `resource_pools` VALUES (4,'Network Pool - Public IPv4 /22','network',NULL,1024.0000,'count',4,1,'active','2026-08-08 04:54:17','2026-08-09 01:49:36');
INSERT INTO `resource_pools` VALUES (5,'License Pool - cPanel','license',NULL,50.0000,'count',1,1,'maintenance','2026-08-08 04:54:17','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `resource_pools` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `resource_types`
--

DROP TABLE IF EXISTS `resource_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resource_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `category` enum('capacity','discrete') NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_types_slug_unique` (`slug`),
  KEY `resource_types_category_index` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `resource_types`
--

LOCK TABLES `resource_types` WRITE;
/*!40000 ALTER TABLE `resource_types` DISABLE KEYS */;
INSERT INTO `resource_types` VALUES (1,'CPU Core','cpu_core','capacity','cores','Processing cores allocated to a service',NULL,NULL);
INSERT INTO `resource_types` VALUES (2,'CPU Speed','cpu_speed','capacity','MHz','Total CPU speed in megahertz',NULL,NULL);
INSERT INTO `resource_types` VALUES (3,'RAM','ram','capacity','MB','Random access memory in megabytes',NULL,NULL);
INSERT INTO `resource_types` VALUES (4,'Storage','storage','capacity','MB','Primary disk storage in megabytes',NULL,NULL);
INSERT INTO `resource_types` VALUES (5,'Bandwidth','bandwidth','capacity','GB','Monthly data transfer allowance in gigabytes',NULL,NULL);
INSERT INTO `resource_types` VALUES (6,'Public IPv4','public_ipv4','capacity','count','Public IPv4 addresses',NULL,NULL);
INSERT INTO `resource_types` VALUES (7,'Public IPv6','public_ipv6','capacity','count','Public IPv6 addresses or /64 blocks',NULL,NULL);
INSERT INTO `resource_types` VALUES (8,'Backup Storage','backup_storage','capacity','MB','Backup storage space in megabytes',NULL,NULL);
INSERT INTO `resource_types` VALUES (9,'GPU Memory','gpu_memory','capacity','MB','GPU VRAM in megabytes (GPU instances)',NULL,NULL);
INSERT INTO `resource_types` VALUES (10,'Email Accounts','email_accounts','discrete','count','Mailbox accounts per hosting plan',NULL,NULL);
INSERT INTO `resource_types` VALUES (11,'Databases','databases','discrete','count','Database instances (MySQL, PostgreSQL, etc.)',NULL,NULL);
INSERT INTO `resource_types` VALUES (12,'FTP Accounts','ftp_accounts','discrete','count','FTP/SFTP user accounts',NULL,NULL);
INSERT INTO `resource_types` VALUES (13,'Subdomains','subdomains','discrete','count','Allowed subdomains per plan',NULL,NULL);
INSERT INTO `resource_types` VALUES (14,'Domains','domains','discrete','count','Domain names hosted under this plan',NULL,NULL);
INSERT INTO `resource_types` VALUES (15,'SSL Certificates','ssl_certificates','discrete','count','SSL/TLS certificates included',NULL,NULL);
INSERT INTO `resource_types` VALUES (16,'Windows License','windows_license','discrete','count','Windows Server licenses for dedicated/VPS',NULL,NULL);
INSERT INTO `resource_types` VALUES (17,'cPanel License','cpanel_license','discrete','count','cPanel/WHM licenses per server or account',NULL,NULL);
INSERT INTO `resource_types` VALUES (18,'Dedicated Server Asset','dedicated_server_asset','discrete','count','Physical dedicated server units',NULL,NULL);
/*!40000 ALTER TABLE `resource_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sequences`
--

DROP TABLE IF EXISTS `sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequences` (
  `key` varchar(255) NOT NULL,
  `value` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sequences`
--

LOCK TABLES `sequences` WRITE;
/*!40000 ALTER TABLE `sequences` DISABLE KEYS */;
INSERT INTO `sequences` VALUES ('ORD',3,'2026-08-07 06:17:39','2026-08-07 06:19:19');
INSERT INTO `sequences` VALUES ('order_no',0,'2026-08-07 04:48:21','2026-08-07 04:48:21');
/*!40000 ALTER TABLE `sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `server_group_members`
--

DROP TABLE IF EXISTS `server_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_group_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_group_id` bigint(20) unsigned NOT NULL,
  `server_id` bigint(20) unsigned NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_group_members_server_group_id_server_id_unique` (`server_group_id`,`server_id`),
  CONSTRAINT `server_group_members_server_group_id_foreign` FOREIGN KEY (`server_group_id`) REFERENCES `server_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_group_members`
--

LOCK TABLES `server_group_members` WRITE;
/*!40000 ALTER TABLE `server_group_members` DISABLE KEYS */;
INSERT INTO `server_group_members` VALUES (1,1,1,10);
INSERT INTO `server_group_members` VALUES (2,1,2,20);
INSERT INTO `server_group_members` VALUES (3,2,3,10);
INSERT INTO `server_group_members` VALUES (4,2,4,20);
/*!40000 ALTER TABLE `server_group_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `server_groups`
--

DROP TABLE IF EXISTS `server_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `load_balancing` enum('round_robin','least_loaded','failover') NOT NULL DEFAULT 'round_robin',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `server_groups_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_groups`
--

LOCK TABLES `server_groups` WRITE;
/*!40000 ALTER TABLE `server_groups` DISABLE KEYS */;
INSERT INTO `server_groups` VALUES (1,'Primary cPanel Servers','Main cPanel/WHM server cluster','round_robin','active','2026-08-09 01:49:30');
INSERT INTO `server_groups` VALUES (2,'VPS Nodes','Virtualizor VPS host nodes','least_loaded','active','2026-08-09 01:49:30');
/*!40000 ALTER TABLE `server_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `servers`
--

DROP TABLE IF EXISTS `servers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `servers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `ip_address` varchar(255) NOT NULL,
  `panel_type` enum('cpanel','plesk','directadmin','custom') NOT NULL DEFAULT 'cpanel',
  `api_url` varchar(255) DEFAULT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_username` varchar(255) DEFAULT NULL,
  `max_accounts` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `servers_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `servers`
--

LOCK TABLES `servers` WRITE;
/*!40000 ALTER TABLE `servers` DISABLE KEYS */;
INSERT INTO `servers` VALUES (1,'web01.demo.example','192.0.2.11','cpanel','https://192.0.2.11:2087',NULL,'root',250,'active','2026-08-08 04:54:01','2026-08-09 01:49:36');
INSERT INTO `servers` VALUES (2,'web02.demo.example','192.0.2.12','cpanel','https://192.0.2.12:2087',NULL,'root',250,'active','2026-08-08 04:54:01','2026-08-09 01:49:36');
INSERT INTO `servers` VALUES (3,'vps01.demo.example','198.51.100.21','custom','https://198.51.100.21:4085',NULL,'apiuser',60,'active','2026-08-08 04:54:01','2026-08-09 01:49:36');
INSERT INTO `servers` VALUES (4,'vps02.demo.example','203.0.113.22','custom','https://203.0.113.22:4085',NULL,'apiuser',60,'inactive','2026-08-08 04:54:01','2026-08-09 01:49:36');
/*!40000 ALTER TABLE `servers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_instances`
--

DROP TABLE IF EXISTS `service_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_instances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `catalog_product_id` bigint(20) unsigned NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `server_id` int(10) unsigned DEFAULT NULL,
  `service_tag` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `provisioning_method` varchar(50) DEFAULT NULL,
  `provisioning_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provisioning_config`)),
  `provisioning_adapter_id` int(10) unsigned DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `status` enum('pending','provisioning','active','suspended','terminated','cancelled') NOT NULL DEFAULT 'pending',
  `suspension_reason` text DEFAULT NULL,
  `suspended_at` datetime DEFAULT NULL,
  `terminated_at` datetime DEFAULT NULL,
  `next_billing_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_instances_service_tag_unique` (`service_tag`),
  KEY `service_instances_customer_id_index` (`customer_id`),
  KEY `service_instances_catalog_product_id_index` (`catalog_product_id`),
  KEY `service_instances_status_index` (`status`),
  KEY `service_instances_next_billing_date_index` (`next_billing_date`),
  KEY `service_instances_deleted_at_index` (`deleted_at`),
  CONSTRAINT `service_instances_catalog_product_id_foreign` FOREIGN KEY (`catalog_product_id`) REFERENCES `catalog_products` (`id`),
  CONSTRAINT `service_instances_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_instances`
--

LOCK TABLES `service_instances` WRITE;
/*!40000 ALTER TABLE `service_instances` DISABLE KEYS */;
INSERT INTO `service_instances` VALUES (4,6,8,NULL,1,'DEMO-SVC-0001','demoshop','demoshop.test',NULL,'cpanel','{\"package\":\"DEMO-CAT-SHARED-START\",\"shell\":false}',NULL,'demo-svc-0001@demo','active',NULL,NULL,NULL,'2026-09-08','2026-08-08 05:10:34','2026-08-08 05:10:34',NULL);
INSERT INTO `service_instances` VALUES (5,7,10,NULL,2,'DEMO-SVC-0002','demoagency','demoagency.test',NULL,'cpanel','{\"package\":\"DEMO-CAT-RESELLER-BRZ\",\"shell\":false}',NULL,'demo-svc-0002@demo','active',NULL,NULL,NULL,'2026-09-08','2026-08-08 05:10:34','2026-08-08 05:10:34',NULL);
INSERT INTO `service_instances` VALUES (6,8,11,NULL,3,'DEMO-SVC-0003','demovps','demovps.test',NULL,'virtualizor','{\"package\":\"DEMO-CAT-VPS-2GB\",\"shell\":false}',NULL,'demo-svc-0003@demo','active',NULL,NULL,NULL,'2026-09-08','2026-08-08 05:10:34','2026-08-08 05:10:34',NULL);
INSERT INTO `service_instances` VALUES (7,9,12,NULL,4,'DEMO-SVC-0004','demolab','demolab.test',NULL,'virtualizor','{\"package\":\"DEMO-CAT-VPS-8GB\",\"shell\":false}',NULL,'demo-svc-0004@demo','suspended','Demo data: unpaid renewal invoice.','2026-07-30 10:40:34',NULL,'2026-09-08','2026-08-08 05:10:34','2026-08-08 05:10:34',NULL);
INSERT INTO `service_instances` VALUES (9,10,8,NULL,1,'DEMO-SVC-0005','demoblog','demoblog.test',NULL,'cpanel','{\"package\":\"DEMO-SVC-0005\",\"shell\":false}',NULL,'demo-svc-0005@demo','active',NULL,NULL,NULL,'2026-09-08','2026-08-08 05:56:12','2026-08-08 05:56:12',NULL);
INSERT INTO `service_instances` VALUES (10,6,11,NULL,3,'DEMO-SVC-0006','demopro','demopro.test',NULL,'virtualizor','{\"package\":\"DEMO-SVC-0006\",\"shell\":false}',NULL,'demo-svc-0006@demo','active',NULL,NULL,NULL,'2026-09-08','2026-08-08 05:56:12','2026-08-08 05:56:12',NULL);
INSERT INTO `service_instances` VALUES (11,7,10,NULL,2,'DEMO-SVC-0007','demoslvr','demoslvr.test',NULL,'cpanel','{\"package\":\"DEMO-SVC-0007\",\"shell\":false}',NULL,'demo-svc-0007@demo','provisioning',NULL,NULL,NULL,'2026-09-08','2026-08-08 05:56:12','2026-08-08 05:56:12',NULL);
INSERT INTO `service_instances` VALUES (12,8,12,NULL,4,'DEMO-SVC-0008','demotest','demotest.test',NULL,'virtualizor','{\"package\":\"DEMO-SVC-0008\",\"shell\":false}',NULL,'demo-svc-0008@demo','active',NULL,NULL,NULL,'2026-09-08','2026-08-08 05:56:12','2026-08-08 05:56:12',NULL);
/*!40000 ALTER TABLE `service_instances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('5NLQ0Tn6szBCuv6tJ6wM7eDkPjhe16QbMByQTNAh',11,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:153.0) Gecko/20100101 Firefox/153.0','eyJfdG9rZW4iOiJEY2t2UGVsSDJuazRvdkw5UGozOGtwT1VnWklLWWNVNnRNV1F6NjRtIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL21hbmFnZWhvc3RpbmcubG9jYWxcL2NsaWVudFwvaG9zdGluZyIsInJvdXRlIjoiY2xpZW50Lmhvc3RpbmcuaW5kZXgifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MTEsImltcGVyc29uYXRvcl9pZCI6MX0=',1786267145);
INSERT INTO `sessions` VALUES ('AFkS1RBj6iWiFnbSHQ9Kc8k1iyHv5s05e9mWyLyT',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJnSDR4Y2F0U1RGUXNKeVl0MXVhUExpb3hwSDBQSFFLOVV4Ukp6Z2wyIiwidXJsIjpbXSwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL21hbmFnZWhvc3RpbmcubG9jYWxcL2FkbWluXC9zZXJ2ZXJzIiwicm91dGUiOiJhZG1pbi5zZXJ2ZXJzLmluZGV4In0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjF9',1786262535);
INSERT INTO `sessions` VALUES ('mMYy5eZbeOoIPgomUY8tmDLEVJWqbXkksvXoQxgd',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT; Windows NT 10.0; en-IN) WindowsPowerShell/5.1.19041.6456','eyJfdG9rZW4iOiJvM2JzSUV4QlVCM3lpS1JZTm82eFppc0s3N3p2c0pRTURlR2RyRHByIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL21hbmFnZWhvc3RpbmcubG9jYWwiLCJyb3V0ZSI6ImNsaWVudC5sb2dpbiJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1786262218);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(255) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `group` varchar(50) NOT NULL DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_setting_key_unique` (`setting_key`),
  KEY `settings_setting_key_index` (`setting_key`),
  KEY `settings_group_index` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'company_name','Hosting Company','general',NULL,NULL);
INSERT INTO `settings` VALUES (2,'company_email','admin@localhost.com','general',NULL,NULL);
INSERT INTO `settings` VALUES (3,'company_phone','','general',NULL,NULL);
INSERT INTO `settings` VALUES (4,'company_address','','general',NULL,NULL);
INSERT INTO `settings` VALUES (5,'currency','INR','billing',NULL,NULL);
INSERT INTO `settings` VALUES (6,'tax_rate','18','billing',NULL,NULL);
INSERT INTO `settings` VALUES (7,'invoice_prefix','INV-','billing',NULL,NULL);
INSERT INTO `settings` VALUES (8,'invoice_next_number','1','billing',NULL,NULL);
INSERT INTO `settings` VALUES (9,'ticket_prefix','TKT-','support',NULL,NULL);
INSERT INTO `settings` VALUES (10,'ticket_next_number','1','support',NULL,NULL);
INSERT INTO `settings` VALUES (11,'smtp_host','','email',NULL,NULL);
INSERT INTO `settings` VALUES (12,'smtp_port','587','email',NULL,NULL);
INSERT INTO `settings` VALUES (13,'smtp_username','','email',NULL,NULL);
INSERT INTO `settings` VALUES (14,'smtp_password','','email',NULL,NULL);
INSERT INTO `settings` VALUES (15,'smtp_encryption','tls','email',NULL,NULL);
INSERT INTO `settings` VALUES (16,'timezone','Asia/Kolkata','general',NULL,NULL);
INSERT INTO `settings` VALUES (17,'date_format','Y-m-d','general',NULL,NULL);
INSERT INTO `settings` VALUES (18,'registration_enabled','yes','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (19,'default_currency','INR','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (20,'default_tax_rate','18','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (21,'quote_prefix','QT-','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (22,'auto_generate_invoice','yes','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (23,'due_days','7','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (24,'gst_enabled','yes','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (25,'mail_from_address',NULL,'general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (26,'mail_from_name',NULL,'general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (27,'session_timeout','120','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (28,'max_login_attempts','5','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (29,'lockout_duration','15','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (30,'force_2fa','no','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (31,'password_min_length','8','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (32,'notify_overdue_invoices','yes','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (33,'notify_domain_expiry','yes','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (34,'notify_new_tickets','yes','general',NULL,'2026-08-07 06:41:14');
INSERT INTO `settings` VALUES (35,'domain_expiry_warning_days','30','general',NULL,'2026-08-07 06:41:14');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings_properties`
--

DROP TABLE IF EXISTS `settings_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings_properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_properties_group_name_unique` (`group`,`name`)
) ENGINE=InnoDB AUTO_INCREMENT=312 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings_properties`
--

LOCK TABLES `settings_properties` WRITE;
/*!40000 ALTER TABLE `settings_properties` DISABLE KEYS */;
INSERT INTO `settings_properties` VALUES (1,'general','company_name',0,'\"Hosting Company\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (2,'general','company_email',0,'\"admin@localhost.com\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (3,'general','company_phone',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (4,'general','company_address',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (5,'billing','currency',0,'\"INR\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (6,'billing','tax_rate',0,'18','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (7,'billing','invoice_prefix',0,'\"INV-\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (8,'billing','invoice_next_number',0,'1','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (9,'support','ticket_prefix',0,'\"TKT-\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (10,'support','ticket_next_number',0,'1','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (11,'email','smtp_host',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (12,'email','smtp_port',0,'587','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (13,'email','smtp_username',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (14,'email','smtp_password',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (15,'email','smtp_encryption',0,'\"tls\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (16,'general','timezone',0,'\"Asia\\/Kolkata\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (17,'general','date_format',0,'\"Y-m-d\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (18,'domain','domain_default_registrar',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (19,'domain','domain_auto_registration',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (20,'domain','domain_transfer_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (21,'domain','domain_transfer_lock',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (22,'domain','domain_transfer_lock_days',0,'60','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (23,'domain','domain_nameserver1',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (24,'domain','domain_nameserver2',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (25,'domain','domain_nameserver3',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (26,'domain','domain_nameserver4',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (27,'domain','domain_dns_enabled',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (28,'domain','domain_dns_provider',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (29,'domain','domain_whois_privacy',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (30,'domain','domain_pricing_tier',0,'\"premium\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (31,'domain','domain_renewal_reminder_days',0,'30','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (32,'integration','cpanel_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (33,'integration','cpanel_host',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (34,'integration','cpanel_port',0,'2083','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (35,'integration','cpanel_api_token',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (36,'integration','plesk_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (37,'integration','plesk_host',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (38,'integration','plesk_port',0,'8443','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (39,'integration','plesk_username',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (40,'integration','plesk_password',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (41,'integration','resellerclub_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (42,'integration','resellerclub_api_id',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (43,'integration','resellerclub_api_key',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (44,'integration','resellerclub_username',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (45,'hosting','hosting_default_panel',0,'\"cpanel\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (46,'hosting','hosting_default_server_group',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (47,'hosting','hosting_auto_provision',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (48,'hosting','hosting_provision_retries',0,'3','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (49,'hosting','hosting_suspend_on_overdue',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (50,'hosting','hosting_suspend_after_days',0,'7','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (51,'hosting','hosting_terminate_after_days',0,'30','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (52,'hosting','hosting_unsuspend_on_payment',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (53,'hosting','hosting_allow_account_creation',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (54,'hosting','hosting_max_accounts_per_server',0,'0','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (55,'hosting','hosting_documentation_url',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (56,'hosting','hosting_terms_url',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (57,'hosting','hosting_welcome_email_enabled',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (58,'hosting','hosting_backup_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (59,'ipam','ipam_enabled',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (60,'ipam','ipam_auto_allocate',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (61,'ipam','ipam_default_ipv4_gateway',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (62,'ipam','ipam_default_ipv6_prefix',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (63,'ipam','ipam_allow_public_ipv6',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (64,'ipam','ipam_reservation_hold_days',0,'14','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (65,'ipam','ipam_scan_interval_minutes',0,'60','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (66,'ipam','ipam_dns_reverse_zone',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (67,'ipam','ipam_low_capacity_warning_percent',0,'20','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (68,'ipam','ipam_auto_release_unused',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (69,'ipam','ipam_unused_release_days',0,'90','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (70,'ipam','ipam_validate_networks',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (71,'ipam','ipam_vlan_tracking',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (72,'ipam','ipam_audit_retention_days',0,'365','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (73,'inventory','inventory_track_stock',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (74,'inventory','inventory_low_stock_threshold',0,'5','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (75,'inventory','inventory_auto_restock',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (76,'inventory','inventory_restock_min_quantity',0,'10','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (77,'inventory','inventory_notify_low_stock',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (78,'inventory','inventory_stock_unit',0,'\"units\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (79,'catalog','catalog_show_inactive',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (80,'catalog','catalog_require_domain_for_hosting',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (81,'catalog','catalog_display_prices_with_tax',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (82,'catalog','catalog_show_out_of_stock',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (83,'catalog','catalog_allow_preorders',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (84,'catalog','catalog_default_sort',0,'\"sort_order\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (85,'catalog','catalog_products_per_page',0,'12','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (86,'catalog','catalog_featured_product_ids',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (87,'catalog','catalog_hide_addons',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (88,'catalog','catalog_price_precision',0,'2','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (89,'catalog','catalog_currency_symbol',0,'\"\\u20b9\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (90,'catalog','catalog_show_reviews',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (91,'catalog','catalog_bundle_discount_default',0,'0','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (92,'product','product_sku_prefix',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (93,'product','product_require_domain',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (94,'product','product_enable_upgrades',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (95,'product','product_enable_downgrades',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (96,'product','product_allow_custom_pricing',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (97,'product','product_trial_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (98,'product','product_trial_days',0,'0','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (99,'product','product_default_billing_cycle',0,'\"monthly\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (100,'product','product_prorated_charges',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (101,'product','product_catalog_sync_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (102,'product','product_approval_required',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (103,'product','product_license_key_prefix',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (104,'product','product_show_in_order_form',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (105,'product','product_reseller_markup_percent',0,'0','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (106,'product','product_gst_applicable',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (107,'product','product_version_management',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (108,'analytics','analytics_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (109,'analytics','analytics_tracking_code',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (110,'analytics','analytics_track_admin',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (111,'analytics','analytics_retention_days',0,'180','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (112,'analytics','analytics_dashboard_widgets',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (113,'analytics','analytics_export_enabled',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (114,'analytics','analytics_anonymize_ip',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (115,'analytics','analytics_event_tracking',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (116,'analytics','analytics_privacy_consent',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (117,'analytics','analytics_report_email',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (118,'analytics','analytics_daily_report',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (119,'analytics','analytics_weekly_report',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (120,'automation','automation_workflows_enabled',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (121,'automation','automation_default_workflow',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (122,'automation','automation_auto_close_tickets',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (123,'automation','automation_auto_close_ticket_days',0,'5','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (124,'automation','automation_welcome_email',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (125,'automation','automation_invoice_reminders',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (126,'automation','automation_invoice_reminder_days',0,'3','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (127,'automation','automation_overdue_actions',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (128,'automation','automation_suspend_after_due_days',0,'7','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (129,'automation','automation_terminate_after_due_days',0,'30','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (130,'automation','automation_domain_expiry_notices',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (131,'automation','automation_domain_expiry_reminder_days',0,'30','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (132,'automation','automation_renewal_invoices',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (133,'cron','cron_scheduler_enabled',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (134,'cron','cron_heartbeat_enabled',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (135,'cron','cron_domain_expiry_check',0,'\"daily\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (136,'cron','cron_overdue_invoice_check',0,'\"daily\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (137,'cron','cron_backup_check',0,'\"weekly\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (138,'cron','cron_usage_sync',0,'\"hourly\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (139,'cron','cron_pricing_sync',0,'\"daily\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (140,'cron','cron_report_generation',0,'\"daily\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (141,'cron','cron_log_cleanup_days',0,'30','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (142,'cron','cron_lock_timeout_minutes',0,'60','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (143,'cron','cron_max_runtime_minutes',0,'30','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (144,'cron','cron_notify_on_failure',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (145,'cron','cron_notify_email',0,'\"\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (146,'role','role_default_role',0,'\"client\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (147,'role','role_allow_assignment',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (148,'role','role_show_permissions',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (149,'role','role_guard',0,'\"web\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (150,'role','role_protect_system_roles',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (151,'user','user_default_timezone',0,'\"Asia\\/Kolkata\"','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (152,'user','user_email_verification',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (153,'user','user_allow_social_login',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (154,'user','user_profile_editable',0,'true','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (155,'user','user_allow_self_delete',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (156,'user','user_password_expiry_days',0,'0','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (157,'user','user_session_timeout_minutes',0,'120','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (158,'user','user_two_factor_enforced',0,'false','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (159,'user','user_inactive_lock_days',0,'0','2026-08-07 06:00:37','2026-08-07 06:41:14');
INSERT INTO `settings_properties` VALUES (160,'user','user_max_login_attempts',0,'5','2026-08-07 06:00:37','2026-08-07 06:41:14');
/*!40000 ALTER TABLE `settings_properties` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ssl_certificates`
--

DROP TABLE IF EXISTS `ssl_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ssl_certificates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `domain_name` varchar(255) NOT NULL,
  `certificate_type` enum('single','wildcard','multidomain') NOT NULL DEFAULT 'single',
  `provider` varchar(255) DEFAULT NULL,
  `status` enum('active','pending','expired','revoked','failed') NOT NULL DEFAULT 'pending',
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ssl_certificates_order_id_foreign` (`order_id`),
  KEY `ssl_certificates_customer_id_index` (`customer_id`),
  KEY `ssl_certificates_domain_name_index` (`domain_name`),
  KEY `ssl_certificates_status_index` (`status`),
  CONSTRAINT `ssl_certificates_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ssl_certificates_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ssl_certificates`
--

LOCK TABLES `ssl_certificates` WRITE;
/*!40000 ALTER TABLE `ssl_certificates` DISABLE KEYS */;
INSERT INTO `ssl_certificates` VALUES (1,6,'secure.demoshop.test','single','Let\'s Encrypt','active','2026-06-08','2026-09-08',NULL,'Auto-renewing DV certificate.','2026-08-08 05:10:37','2026-08-08 05:10:37');
INSERT INTO `ssl_certificates` VALUES (2,7,'demoagency.test','wildcard','Sectigo','active','2026-04-08','2027-04-08',NULL,'Wildcard covering all reseller subdomains.','2026-08-08 05:10:37','2026-08-08 05:10:37');
INSERT INTO `ssl_certificates` VALUES (3,8,'mail.demoblog.test','single','Let\'s Encrypt','pending',NULL,NULL,NULL,'Domain control validation in progress.','2026-08-08 05:10:38','2026-08-08 05:10:38');
INSERT INTO `ssl_certificates` VALUES (4,9,'legacy.demolab.test','single','Sectigo','expired','2025-05-08','2026-05-08',NULL,'Left expired on purpose so the expiry report has a negative case.','2026-08-08 05:10:38','2026-08-08 05:10:38');
INSERT INTO `ssl_certificates` VALUES (5,10,'portal.demodedi.test','multidomain','Sectigo','active','2026-02-08','2027-02-08',NULL,'Multi-domain SAN cert covering portal and api labels.','2026-08-08 05:20:35','2026-08-08 05:20:35');
/*!40000 ALTER TABLE `ssl_certificates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_changes`
--

DROP TABLE IF EXISTS `subscription_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `from_subscription_period_id` int(10) unsigned DEFAULT NULL,
  `to_subscription_period_id` int(10) unsigned DEFAULT NULL,
  `change_type` enum('upgrade','downgrade','renewal','cancellation','addon') NOT NULL,
  `credit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `charge_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `proration_days` int(11) DEFAULT NULL,
  `invoice_id` int(10) unsigned DEFAULT NULL,
  `effective_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_changes_service_id_change_type_index` (`service_id`,`change_type`),
  CONSTRAINT `subscription_changes_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `service_instances` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_changes`
--

LOCK TABLES `subscription_changes` WRITE;
/*!40000 ALTER TABLE `subscription_changes` DISABLE KEYS */;
INSERT INTO `subscription_changes` VALUES (3,4,4,5,'renewal',0.00,499.00,NULL,NULL,'2026-07-01','2026-08-08 05:10:34');
INSERT INTO `subscription_changes` VALUES (4,5,6,7,'upgrade',1250.00,4400.00,42,NULL,'2025-08-01','2026-08-08 05:10:34');
INSERT INTO `subscription_changes` VALUES (5,6,8,9,'addon',0.00,350.00,NULL,NULL,'2026-05-01','2026-08-08 05:10:34');
/*!40000 ALTER TABLE `subscription_changes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_periods`
--

DROP TABLE IF EXISTS `subscription_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `billing_cycle` enum('free','one_time','hourly','daily','monthly','quarterly','semi_annual','annual','biennial','triennial','usage_based','custom') NOT NULL DEFAULT 'monthly',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `next_invoice_date` date DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'INR',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','expired','cancelled','upgraded','downgraded') NOT NULL DEFAULT 'active',
  `parent_period_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subscription_periods_service_id_status_index` (`service_id`,`status`),
  KEY `subscription_periods_next_invoice_date_index` (`next_invoice_date`),
  CONSTRAINT `subscription_periods_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `service_instances` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_periods`
--

LOCK TABLES `subscription_periods` WRITE;
/*!40000 ALTER TABLE `subscription_periods` DISABLE KEYS */;
INSERT INTO `subscription_periods` VALUES (4,4,'monthly','2026-06-01','2026-06-30','2026-07-01',499.00,'INR',18.00,'expired',NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (5,4,'monthly','2026-07-01','2026-07-31','2026-08-01',499.00,'INR',18.00,'active',4,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (6,5,'annual','2024-08-01','2025-07-31','2025-08-01',11988.00,'INR',18.00,'expired',NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (7,5,'annual','2025-08-01','2026-07-31','2026-08-01',11988.00,'INR',18.00,'active',6,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (8,6,'quarterly','2026-02-01','2026-04-30','2026-05-01',3597.00,'INR',18.00,'expired',NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (9,6,'quarterly','2026-05-01','2026-07-31','2026-08-01',3597.00,'INR',18.00,'active',8,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (10,7,'monthly','2026-06-01','2026-06-30','2026-07-01',2999.00,'INR',18.00,'expired',NULL,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (11,7,'monthly','2026-07-01','2026-07-31','2026-08-01',2999.00,'INR',18.00,'active',10,'2026-08-08 05:10:34','2026-08-08 05:10:34');
INSERT INTO `subscription_periods` VALUES (12,9,'monthly','2026-06-01','2026-06-30','2026-07-01',499.00,'INR',18.00,'expired',NULL,'2026-08-08 05:56:12','2026-08-08 05:56:12');
INSERT INTO `subscription_periods` VALUES (13,9,'monthly','2026-07-01','2026-07-31','2026-08-01',499.00,'INR',18.00,'active',12,'2026-08-08 05:56:12','2026-08-08 05:56:12');
INSERT INTO `subscription_periods` VALUES (14,10,'quarterly','2026-02-01','2026-04-30','2026-05-01',3597.00,'INR',18.00,'expired',NULL,'2026-08-08 05:56:12','2026-08-08 05:56:12');
INSERT INTO `subscription_periods` VALUES (15,10,'quarterly','2026-05-01','2026-07-31','2026-08-01',3597.00,'INR',18.00,'active',14,'2026-08-08 05:56:12','2026-08-08 05:56:12');
INSERT INTO `subscription_periods` VALUES (16,11,'semi_annual','2025-08-01','2026-01-31','2026-02-01',7500.00,'INR',18.00,'expired',NULL,'2026-08-08 05:56:12','2026-08-08 05:56:12');
INSERT INTO `subscription_periods` VALUES (17,11,'semi_annual','2026-02-01','2026-07-31','2026-08-01',7500.00,'INR',18.00,'active',16,'2026-08-08 05:56:12','2026-08-08 05:56:12');
INSERT INTO `subscription_periods` VALUES (18,12,'annual','2024-08-01','2025-07-31','2025-08-01',47999.00,'INR',18.00,'expired',NULL,'2026-08-08 05:56:12','2026-08-08 05:56:12');
INSERT INTO `subscription_periods` VALUES (19,12,'annual','2025-08-01','2026-07-31','2026-08-01',47999.00,'INR',18.00,'active',18,'2026-08-08 05:56:12','2026-08-08 05:56:12');
/*!40000 ALTER TABLE `subscription_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tax_rates`
--

DROP TABLE IF EXISTS `tax_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `rate` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax_rates`
--

LOCK TABLES `tax_rates` WRITE;
/*!40000 ALTER TABLE `tax_rates` DISABLE KEYS */;
INSERT INTO `tax_rates` VALUES (1,'GST 5%',5.00,1,'2026-06-30 19:30:00','2026-06-30 19:30:00');
INSERT INTO `tax_rates` VALUES (2,'GST 18%',18.00,1,'2026-06-30 20:30:00','2026-06-30 20:30:00');
INSERT INTO `tax_rates` VALUES (3,'Exempt',0.00,0,'2026-06-30 21:30:00','2026-06-30 21:30:00');
/*!40000 ALTER TABLE `tax_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_replies`
--

DROP TABLE IF EXISTS `ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_replies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `message` text NOT NULL,
  `is_staff` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_replies_user_id_foreign` (`user_id`),
  KEY `ticket_replies_ticket_id_index` (`ticket_id`),
  CONSTRAINT `ticket_replies_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_replies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_replies`
--

LOCK TABLES `ticket_replies` WRITE;
/*!40000 ALTER TABLE `ticket_replies` DISABLE KEYS */;
INSERT INTO `ticket_replies` VALUES (22,9,6,'I am seeing this issue on my account and need help resolving it.',0,'2026-07-31 07:15:00');
INSERT INTO `ticket_replies` VALUES (23,9,13,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-07-31 08:15:00');
INSERT INTO `ticket_replies` VALUES (24,10,7,'I am seeing this issue on my account and need help resolving it.',0,'2026-08-01 06:15:01');
INSERT INTO `ticket_replies` VALUES (25,10,14,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-08-01 07:15:01');
INSERT INTO `ticket_replies` VALUES (26,10,7,'This is affecting my production site. Please prioritise.',0,'2026-08-01 08:15:01');
INSERT INTO `ticket_replies` VALUES (27,11,8,'I am seeing this issue on my account and need help resolving it.',0,'2026-08-02 05:15:01');
INSERT INTO `ticket_replies` VALUES (28,11,15,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-08-02 06:15:01');
INSERT INTO `ticket_replies` VALUES (29,12,9,'I am seeing this issue on my account and need help resolving it.',0,'2026-08-03 04:15:01');
INSERT INTO `ticket_replies` VALUES (30,12,13,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-08-03 05:15:01');
INSERT INTO `ticket_replies` VALUES (31,12,9,'This is affecting my production site. Please prioritise.',0,'2026-08-03 06:15:01');
INSERT INTO `ticket_replies` VALUES (32,13,10,'I am seeing this issue on my account and need help resolving it.',0,'2026-08-04 03:15:01');
INSERT INTO `ticket_replies` VALUES (33,13,14,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-08-04 04:15:01');
INSERT INTO `ticket_replies` VALUES (34,14,11,'I am seeing this issue on my account and need help resolving it.',0,'2026-07-31 02:15:01');
INSERT INTO `ticket_replies` VALUES (35,14,15,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-07-31 03:15:01');
INSERT INTO `ticket_replies` VALUES (36,14,11,'This is affecting my production site. Please prioritise.',0,'2026-07-31 04:15:01');
INSERT INTO `ticket_replies` VALUES (37,15,6,'I am seeing this issue on my account and need help resolving it.',0,'2026-08-01 01:15:01');
INSERT INTO `ticket_replies` VALUES (38,15,13,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-08-01 02:15:01');
INSERT INTO `ticket_replies` VALUES (39,16,7,'I am seeing this issue on my account and need help resolving it.',0,'2026-08-02 00:15:01');
INSERT INTO `ticket_replies` VALUES (40,16,14,'We found the cause and applied a fix. Please check and confirm it is resolved.',1,'2026-08-02 01:15:01');
INSERT INTO `ticket_replies` VALUES (41,16,7,'This is affecting my production site. Please prioritise.',0,'2026-08-02 02:15:01');
/*!40000 ALTER TABLE `ticket_replies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_no` varchar(255) NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  `department` enum('sales','support','billing','technical') NOT NULL DEFAULT 'support',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `last_reply_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tickets_ticket_no_unique` (`ticket_no`),
  KEY `tickets_ticket_no_index` (`ticket_no`),
  KEY `tickets_customer_id_index` (`customer_id`),
  KEY `tickets_assigned_to_index` (`assigned_to`),
  KEY `tickets_status_index` (`status`),
  KEY `tickets_priority_index` (`priority`),
  CONSTRAINT `tickets_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
INSERT INTO `tickets` VALUES (9,'SUP-DEMO-0001',5,'Quote for annual shared hosting','low','open','sales',13,'2026-07-31 13:45:00','2026-07-31 06:15:00','2026-08-09 01:49:37');
INSERT INTO `tickets` VALUES (10,'SUP-DEMO-0002',6,'Site returns 500 after login','medium','pending','support',14,'2026-08-01 13:45:01','2026-08-01 05:15:01','2026-08-09 01:49:37');
INSERT INTO `tickets` VALUES (11,'SUP-DEMO-0003',7,'Duplicate charge on latest invoice','high','resolved','billing',15,'2026-08-02 11:45:01','2026-08-02 04:15:01','2026-08-09 01:49:37');
INSERT INTO `tickets` VALUES (12,'SUP-DEMO-0004',8,'SSL certificate renewal failing','urgent','closed','technical',13,'2026-08-03 11:45:01','2026-08-03 03:15:01','2026-08-09 01:49:37');
INSERT INTO `tickets` VALUES (13,'SUP-DEMO-0005',9,'Upgrade inquiry — premium plan','low','open','sales',14,'2026-08-04 09:45:01','2026-08-04 02:15:01','2026-08-09 01:49:37');
INSERT INTO `tickets` VALUES (14,'SUP-DEMO-0006',10,'Email not reaching recipients','medium','pending','support',15,'2026-07-31 09:45:01','2026-07-31 01:15:01','2026-08-09 01:49:37');
INSERT INTO `tickets` VALUES (15,'SUP-DEMO-0007',5,'Request to update card on file','high','resolved','billing',13,'2026-08-01 07:45:01','2026-08-01 00:15:01','2026-08-09 01:49:37');
INSERT INTO `tickets` VALUES (16,'SUP-DEMO-0008',6,'Domain transfer stuck in pending','urgent','closed','technical',14,'2026-08-02 07:45:01','2026-08-01 23:15:01','2026-08-09 01:49:37');
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'INR',
  `payment_method` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded','partially_refunded') NOT NULL DEFAULT 'completed',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transactions_customer_id_index` (`customer_id`),
  KEY `transactions_invoice_id_index` (`invoice_id`),
  CONSTRAINT `transactions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (1,7,2,2307.64,46.15,2261.49,'INR','razorpay','DEMO-TXN-2026-0001','completed','Demo ledger entry for DEMO-INV-2026-0002 (completed).','2026-06-30 21:30:00');
INSERT INTO `transactions` VALUES (2,10,5,2077.82,0.00,2077.82,'INR','bank_transfer','DEMO-TXN-2026-0002','completed','Demo ledger entry for DEMO-INV-2026-0005 (completed).','2026-06-30 23:30:00');
INSERT INTO `transactions` VALUES (3,8,8,1971.64,0.00,1971.64,'INR','cash','DEMO-TXN-2026-0003','completed','Demo ledger entry for DEMO-INV-2026-0008 (completed).','2026-07-01 01:30:00');
INSERT INTO `transactions` VALUES (4,7,7,1847.06,36.94,1810.12,'INR','razorpay','DEMO-TXN-2026-0004','completed','Demo ledger entry for DEMO-INV-2026-0007 (completed).','2026-07-01 03:30:00');
INSERT INTO `transactions` VALUES (5,6,1,1133.82,22.68,1111.14,'INR','razorpay','DEMO-TXN-2026-0005','pending','Demo ledger entry for DEMO-INV-2026-0001 (pending).','2026-07-01 05:30:00');
INSERT INTO `transactions` VALUES (6,10,10,3536.46,0.00,3536.46,'INR','bank_transfer','DEMO-TXN-2026-0006','pending','Demo ledger entry for DEMO-INV-2026-0010 (pending).','2026-07-01 07:30:00');
INSERT INTO `transactions` VALUES (7,8,3,2829.64,56.59,2773.05,'INR','razorpay','DEMO-TXN-2026-0007','failed','Demo ledger entry for DEMO-INV-2026-0003 (failed).','2026-07-01 09:30:00');
INSERT INTO `transactions` VALUES (8,6,1,1133.82,0.00,1133.82,'INR','razorpay','DEMO-TXN-2026-0008','pending','Pending Razorpay auth for INV-0001.','2026-08-09 00:19:02');
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usage_records`
--

DROP TABLE IF EXISTS `usage_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usage_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `resource_type_id` bigint(20) unsigned NOT NULL,
  `metric` enum('disk_bytes','bandwidth_bytes','cpu_seconds','memory_bytes','iops','network_packets','license_seat_hours') NOT NULL,
  `value` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(32) NOT NULL,
  `recorded_at` datetime NOT NULL,
  `source` enum('adapter_poll','api_webhook','manual','estimated') NOT NULL DEFAULT 'estimated',
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `invoiced` tinyint(1) NOT NULL DEFAULT 0,
  `invoice_item_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usage_records_resource_type_id_foreign` (`resource_type_id`),
  KEY `usage_records_service_id_recorded_at_index` (`service_id`,`recorded_at`),
  KEY `usage_records_billing_period_start_billing_period_end_index` (`billing_period_start`,`billing_period_end`),
  KEY `usage_records_invoiced_index` (`invoiced`),
  CONSTRAINT `usage_records_resource_type_id_foreign` FOREIGN KEY (`resource_type_id`) REFERENCES `resource_types` (`id`),
  CONSTRAINT `usage_records_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `service_instances` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usage_records`
--

LOCK TABLES `usage_records` WRITE;
/*!40000 ALTER TABLE `usage_records` DISABLE KEYS */;
INSERT INTO `usage_records` VALUES (3,4,4,'disk_bytes',3221225472.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:34');
INSERT INTO `usage_records` VALUES (4,4,5,'bandwidth_bytes',42949672960.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:34');
INSERT INTO `usage_records` VALUES (5,5,4,'disk_bytes',6442450944.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:34');
INSERT INTO `usage_records` VALUES (6,5,5,'bandwidth_bytes',85899345920.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:34');
INSERT INTO `usage_records` VALUES (7,6,4,'disk_bytes',9663676416.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:34');
INSERT INTO `usage_records` VALUES (8,6,5,'bandwidth_bytes',128849018880.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:34');
INSERT INTO `usage_records` VALUES (9,7,4,'disk_bytes',12884901888.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:35');
INSERT INTO `usage_records` VALUES (10,7,5,'bandwidth_bytes',171798691840.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:10:35');
INSERT INTO `usage_records` VALUES (11,9,4,'disk_bytes',16106127360.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
INSERT INTO `usage_records` VALUES (12,9,5,'bandwidth_bytes',214748364800.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
INSERT INTO `usage_records` VALUES (13,10,4,'disk_bytes',19327352832.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
INSERT INTO `usage_records` VALUES (14,10,5,'bandwidth_bytes',257698037760.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
INSERT INTO `usage_records` VALUES (15,11,4,'disk_bytes',22548578304.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
INSERT INTO `usage_records` VALUES (16,11,5,'bandwidth_bytes',300647710720.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
INSERT INTO `usage_records` VALUES (17,12,4,'disk_bytes',25769803776.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
INSERT INTO `usage_records` VALUES (18,12,5,'bandwidth_bytes',343597383680.0000,'bytes','2026-08-15 02:00:00','adapter_poll','2026-08-01','2026-08-31',0,NULL,'2026-08-08 05:56:12');
/*!40000 ALTER TABLE `usage_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `role` enum('admin','staff','client','support','sales','marketing') NOT NULL DEFAULT 'client',
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `remember_token` varchar(100) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_email_index` (`email`),
  KEY `users_role_index` (`role`),
  KEY `users_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin@localhost.com','$2y$12$j4O9VOZ9WmYqXtkRniy5tuuBEnIJG8lwibVWZzcHhky/S.ZIkrF6G',NULL,NULL,NULL,'admin','Sanat','Das',NULL,NULL,NULL,'active',NULL,NULL,'2026-08-07 04:48:21','2026-08-07 08:55:07');
INSERT INTO `users` VALUES (6,'some@gmail.com','$2y$12$RotpQDvjCcVtjVKpovGmJOs0mj8U9xfrryQq7XUg2orQGLdnUjTFW',NULL,NULL,NULL,'client','Somenath','Patra','9832155480','DTPL','Kkajdhkja hd jklsahdf kjsh','active',NULL,NULL,'2026-08-07 09:32:08','2026-08-07 09:32:08');
INSERT INTO `users` VALUES (7,'client1@example.com','$2y$12$tU/vhIL/v.uuw8XHb4DRou816cl2AtjZx6laYeJT0RXK.OtN3VATO',NULL,NULL,NULL,'client','Asha','Verma','+1-555-0101','Northwind Labs','101 Demo Street, Pune','active',NULL,NULL,'2026-08-08 04:54:11','2026-08-08 04:55:51');
INSERT INTO `users` VALUES (8,'client2@example.com','$2y$12$T4222RGUYXKagQx1k5pfpOV6AXfLdieDmi8UOti0B9ADjb2H/dcjC',NULL,NULL,NULL,'client','Brian','Okafor','+1-555-0102','Blue Harbor Media','102 Demo Street, Lagos','active',NULL,NULL,'2026-08-08 04:54:11','2026-08-08 04:55:51');
INSERT INTO `users` VALUES (9,'client3@example.com','$2y$12$rhRaAXItb3zMZRBdtt65FeHXdi2QeHeKzvrulD9s1EMWyD3WR0.2m',NULL,NULL,NULL,'client','Chen','Liu','+1-555-0103','Cedar Point Retail','103 Demo Street, Singapore','active',NULL,NULL,'2026-08-08 04:54:12','2026-08-08 04:55:52');
INSERT INTO `users` VALUES (10,'client4@example.com','$2y$12$jIW0ypHkPyu08T/CrTuUreGfmqlDvR28tBXyaf8mUSsn5fVZIJcWS',NULL,NULL,NULL,'client','Dana','Kowalski','+1-555-0104','Driftwood Studios','104 Demo Street, Krakow','active',NULL,NULL,'2026-08-08 04:54:12','2026-08-08 04:55:52');
INSERT INTO `users` VALUES (11,'client5@example.com','$2y$12$k/Rh/h1ROfvuprJCJ9szJejatd591Uw.RHOE.ymKQAPWPv4oErYCC',NULL,NULL,NULL,'client','Emeka','Santos','+1-555-0105','Everline Freight','105 Demo Street, Lisbon','active',NULL,NULL,'2026-08-08 04:54:13','2026-08-08 04:55:53');
INSERT INTO `users` VALUES (13,'support@example.com','$2y$12$rBuNBDGghfL9pT/DrFdw5OeDLhfKqqYOASEzQ4/q8h26WBPZoPao6',NULL,NULL,NULL,'support','Sophia','Support',NULL,NULL,NULL,'active',NULL,NULL,'2026-08-08 04:54:14','2026-08-08 04:54:14');
INSERT INTO `users` VALUES (14,'sales@example.com','$2y$12$mof7AkLcmpNWdsvFM981Ye6XkWkdmACgJMQUj.QGCihSRD5lfTlTu',NULL,NULL,NULL,'sales','Liam','Sales',NULL,NULL,NULL,'active',NULL,NULL,'2026-08-08 04:54:14','2026-08-08 04:54:14');
INSERT INTO `users` VALUES (15,'marketing@example.com','$2y$12$/ivVx1ufL2Mtw2S3/woF4O2W5Z/HcBo/8rqUtXrWLLl7QQD24xvUG',NULL,NULL,NULL,'marketing','Olivia','Marketing',NULL,NULL,NULL,'active',NULL,NULL,'2026-08-08 04:54:14','2026-08-08 04:54:14');
INSERT INTO `users` VALUES (16,'test1@example.com','$2y$12$fNI1.Z1cGO2hOPD4lBWRcOzF7312SU2WlfgSwKJidKHsUiQ/V/DHe',NULL,NULL,NULL,'staff','Test','One',NULL,NULL,NULL,'active',NULL,NULL,'2026-08-08 04:54:17','2026-08-08 04:54:17');
INSERT INTO `users` VALUES (17,'test2@example.com','$2y$12$1PUEM5imhnnfu03SId/iBOcyO4VT0NNi8RTcEI1yk9qpEUULkEK/2',NULL,NULL,NULL,'staff','Test','Two',NULL,NULL,NULL,'active',NULL,NULL,'2026-08-08 04:54:17','2026-08-08 04:54:17');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vlans`
--

DROP TABLE IF EXISTS `vlans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vlans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `vlan_id` int(10) unsigned NOT NULL,
  `description` text DEFAULT NULL,
  `datacenter_id` int(10) unsigned DEFAULT NULL,
  `subnet_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vlans_vlan_id_unique` (`vlan_id`),
  KEY `vlans_datacenter_id_index` (`datacenter_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vlans`
--

LOCK TABLES `vlans` WRITE;
/*!40000 ALTER TABLE `vlans` DISABLE KEYS */;
INSERT INTO `vlans` VALUES (1,'DEMO-VLAN-PROD',100,'Production public-facing network.',1,NULL,'2026-08-08 05:22:32');
INSERT INTO `vlans` VALUES (2,'DEMO-VLAN-MGMT',200,'Management & backend network.',2,NULL,'2026-08-08 05:22:32');
INSERT INTO `vlans` VALUES (3,'DEMO-VLAN-STORAGE',300,'Storage & backup network.',1,NULL,'2026-08-08 05:22:32');
/*!40000 ALTER TABLE `vlans` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-09 15:13:18
