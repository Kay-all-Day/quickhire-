CREATE DATABASE  IF NOT EXISTS `quickhire` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;
USE `quickhire`;
-- MySQL dump 10.13  Distrib 8.0.45, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: quickhire
-- ------------------------------------------------------
-- Server version	5.5.5-10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `booking_date` datetime NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','accepted','completed','cancelled') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`booking_id`),
  KEY `user_id` (`user_id`),
  KEY `provider_id` (`provider_id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `service_providers` (`provider_id`),
  CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
INSERT INTO `bookings` VALUES (1,1,1,1,'2026-03-25 10:00:00','East Legon, Accra','Need help redesigning my living room','completed','2026-03-26 10:31:08'),(2,2,2,3,'2026-03-26 09:00:00','Kumasi, Ashanti','Custom wardrobe for master bedroom','completed','2026-03-26 10:31:08'),(3,1,3,5,'2026-03-28 14:00:00','Cantonments, Accra','Catering for 50 people','completed','2026-03-26 10:31:08'),(4,7,1,1,'2026-05-05 13:18:00','labadi','','completed','2026-03-27 19:18:49'),(5,1,1,2,'2026-08-05 12:29:00','Lashibi','','completed','2026-03-27 20:29:33'),(6,7,3,5,'2026-03-30 17:21:00','Tesano Fourth Street','','completed','2026-03-28 15:22:07'),(7,7,6,7,'2026-04-01 14:20:00','Abeka','','completed','2026-03-30 14:21:34'),(8,11,3,6,'2026-04-03 20:53:00','Lashibi','','completed','2026-03-30 14:47:28'),(9,11,1,2,'2026-04-10 14:51:00','Lashibi','','completed','2026-03-30 14:51:21'),(10,11,2,3,'2026-04-01 15:12:00','Tema','','completed','2026-03-30 15:15:22'),(11,7,7,8,'2026-04-01 09:54:00','Dansoman','Student has ADHD','completed','2026-03-30 20:55:20'),(12,11,7,8,'2026-03-31 10:15:00','Tema','','cancelled','2026-03-30 21:15:40'),(13,11,6,7,'2026-04-02 11:33:00','East Legon','','completed','2026-03-31 11:34:12'),(14,7,7,8,'2026-04-02 07:08:00','Kwashieman','','completed','2026-03-31 18:08:00'),(15,7,1,1,'2026-04-07 10:00:00','Labadi','','completed','2026-04-06 17:55:16'),(16,11,1,1,'2026-04-08 06:15:00','Tema community 25','','completed','2026-04-06 18:15:55'),(17,7,6,7,'2026-04-08 06:35:00','Abeka','','completed','2026-04-06 18:35:16'),(18,7,1,1,'2026-04-08 12:13:00','Tema community 25','','completed','2026-04-07 21:15:02'),(19,11,3,6,'2026-04-08 10:30:00','Crystal palm  hotel','','completed','2026-04-07 21:28:21'),(20,15,6,7,'2026-04-09 09:14:00','Achimota','','completed','2026-04-08 18:15:05'),(21,16,3,5,'2026-04-24 02:42:00','Abeka','cook delicious food','completed','2026-04-09 14:41:46'),(22,16,3,5,'2026-04-24 02:42:00','Abeka','cook delicious food','pending','2026-04-09 14:41:47'),(23,7,3,6,'2026-04-18 15:55:00','Tema community 25','','completed','2026-04-17 14:54:56'),(24,7,6,7,'2026-04-18 05:17:00','Tesano','','completed','2026-04-17 17:18:22');
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `featured_requests`
--

DROP TABLE IF EXISTS `featured_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `featured_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider_id` int(11) NOT NULL,
  `duration_days` int(11) NOT NULL DEFAULT 30,
  `fee` decimal(10,2) NOT NULL,
  `payment_status` enum('pending','completed') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `request_status` enum('pending','approved','rejected','expired') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`),
  CONSTRAINT `featured_requests_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `service_providers` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `featured_requests`
--

LOCK TABLES `featured_requests` WRITE;
/*!40000 ALTER TABLE `featured_requests` DISABLE KEYS */;
INSERT INTO `featured_requests` VALUES (1,5,90,350.00,'pending',NULL,'pending','2026-03-28 14:38:56',NULL,NULL),(2,7,90,350.00,'completed','card','approved','2026-03-30 15:50:10','2026-03-31 05:52:05','2026-06-29 05:52:05');
/*!40000 ALTER TABLE `featured_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `homepage_categories`
--

DROP TABLE IF EXISTS `homepage_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `homepage_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `icon` varchar(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `filter_key` varchar(50) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_visible` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `homepage_categories`
--

LOCK TABLES `homepage_categories` WRITE;
/*!40000 ALTER TABLE `homepage_categories` DISABLE KEYS */;
INSERT INTO `homepage_categories` VALUES (2,'Electrical','⚡','Hire electricians for safe installations and fault repairs.','electrical',2,0,'2026-03-27 23:04:53'),(3,'Tutoring','?','Connect with qualified tutors for academic support.','tutoring',3,1,'2026-03-27 23:04:53'),(4,'Cleaning','?','Book professional home and office cleaning services.','cleaning',4,1,'2026-03-27 23:04:53'),(5,'Catering','?️','Elevate your next event with impeccable catering','catering',4,1,'2026-03-27 23:14:22'),(6,'Interior Design','?','Find the person to transform your home','interior design',1,1,'2026-03-30 16:04:29');
/*!40000 ALTER TABLE `homepage_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
INSERT INTO `messages` VALUES (1,7,3,NULL,'hey',1,'2026-03-28 15:07:46'),(2,3,7,NULL,'Hello',1,'2026-03-28 15:08:59'),(3,11,13,NULL,'hey',1,'2026-04-08 20:15:58'),(4,13,11,NULL,'hello',0,'2026-04-08 20:16:37'),(5,7,13,NULL,'Hey',0,'2026-04-08 20:17:14');
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,3,'message','New message from Sed','hey','messages.php?with=7',1,'2026-03-28 15:07:46'),(2,7,'message','New message from Efua Koomson','Hello','messages.php?with=3',1,'2026-03-28 15:08:59'),(3,5,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-28 15:22:08'),(4,10,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',1,'2026-03-28 15:43:52'),(5,10,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-30 14:21:34'),(6,7,'booking_accepted','Booking Accepted','Clare Jen has accepted your booking #7. Your service is confirmed!','dashboard.php',1,'2026-03-30 14:24:23'),(7,7,'booking_completed','Service Completed','Clare Jen has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-30 14:24:42'),(8,10,'payment','Payment Received','You received GH₵ 100.00 for booking #7.','dashboard.php',1,'2026-03-30 14:25:23'),(9,11,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',1,'2026-03-30 14:46:30'),(10,5,'booking','New Booking Request','Yaww has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-30 14:47:29'),(11,3,'booking','New Booking Request','Yaww has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-30 14:51:21'),(12,11,'booking_accepted','Booking Accepted','Efua Koomson has accepted your booking #9. Your service is confirmed!','dashboard.php',1,'2026-03-30 14:51:45'),(13,11,'booking_completed','Service Completed','Efua Koomson has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-30 14:52:03'),(14,3,'payment','Payment Received','You received GH₵ 800.00 for booking #9.','dashboard.php',1,'2026-03-30 14:54:00'),(15,4,'booking','New Booking Request','Yaww has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-30 15:15:22'),(16,11,'booking_accepted','Booking Accepted','Samuel Kojo has accepted your booking #10. Your service is confirmed!','dashboard.php',1,'2026-03-30 15:19:07'),(17,2,'booking_completed','Service Completed','Samuel Kojo has completed your service. You can now make payment and leave a review.','dashboard.php',0,'2026-03-30 15:19:17'),(18,11,'booking_completed','Service Completed','Samuel Kojo has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-30 15:19:24'),(19,4,'payment','Payment Received','You received GH₵ 480.00 for booking #10.','dashboard.php',0,'2026-03-30 15:20:08'),(20,12,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',1,'2026-03-30 15:46:34'),(21,13,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',1,'2026-03-30 15:49:14'),(22,11,'booking_accepted','Booking Accepted','Abena Asante has accepted your booking #8. Your service is confirmed!','dashboard.php',1,'2026-03-30 20:37:32'),(23,7,'booking_accepted','Booking Accepted','Abena Asante has accepted your booking #6. Your service is confirmed!','dashboard.php',1,'2026-03-30 20:37:49'),(24,1,'booking_accepted','Booking Accepted','Abena Asante has accepted your booking #3. Your service is confirmed!','dashboard.php',0,'2026-03-30 20:37:55'),(25,11,'booking_completed','Service Completed','Abena Asante has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-30 20:43:07'),(26,7,'booking_completed','Service Completed','Abena Asante has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-30 20:43:13'),(27,1,'booking_completed','Service Completed','Abena Asante has completed your service. You can now make payment and leave a review.','dashboard.php',0,'2026-03-30 20:43:21'),(28,5,'payment','Payment Received','You received GH₵ 50.00 for booking #6.','dashboard.php',1,'2026-03-30 20:44:31'),(29,5,'payment','Payment Received','You received GH₵ 900.00 for booking #8.','dashboard.php',1,'2026-03-30 20:45:53'),(30,13,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-30 20:55:20'),(31,7,'booking_accepted','Booking Accepted','Ken has accepted your booking #11. Your service is confirmed!','dashboard.php',1,'2026-03-30 20:55:52'),(32,7,'booking_completed','Service Completed','Ken has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-30 20:56:04'),(33,13,'payment','Payment Received','You received GH₵ 120.00 for booking #11.','dashboard.php',1,'2026-03-30 20:56:51'),(34,13,'booking','New Booking Request','Yaww has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-30 21:15:41'),(35,10,'booking','New Booking Request','Yaww has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-31 11:34:13'),(36,11,'booking_accepted','Booking Accepted','Clare Jen has accepted your booking #13. Your service is confirmed!','dashboard.php',1,'2026-03-31 11:35:19'),(37,11,'booking_completed','Service Completed','Clare Jen has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-31 11:40:06'),(38,10,'payment','Payment Received','You received GH₵ 120.00 for booking #13.','dashboard.php',1,'2026-03-31 11:40:35'),(39,13,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-03-31 18:08:01'),(40,7,'booking_accepted','Booking Accepted','Ken has accepted your booking #14. Your service is confirmed!','dashboard.php',1,'2026-03-31 18:11:03'),(41,11,'booking_declined','Booking Declined','Ken has declined your booking #12.','dashboard.php',1,'2026-03-31 18:11:08'),(42,7,'booking_completed','Service Completed','Ken has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-03-31 18:11:28'),(43,13,'payment','Payment Received','You received GH₵ 120.00 for booking #14.','dashboard.php',0,'2026-03-31 18:16:12'),(44,14,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',1,'2026-03-31 18:25:49'),(45,8,'verification','New Verification Request','Jedd has submitted documents for verification. Review and approve.','admin.php',1,'2026-03-31 18:29:15'),(46,3,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-04-06 17:55:17'),(47,7,'booking_accepted','Booking Accepted','Efua Koomson has accepted your booking #15. Your service is confirmed!','dashboard.php',1,'2026-04-06 17:56:39'),(48,7,'booking_completed','Service Completed','Efua Koomson has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-04-06 17:57:19'),(49,3,'payment','Payment Received','You received GH₵ 240.00 for booking #15.','dashboard.php',1,'2026-04-06 17:58:17'),(50,3,'booking','New Booking Request','Yaww has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-04-06 18:15:56'),(51,11,'booking_accepted','Booking Accepted','Efua Koomson has accepted your booking #16. Your service is confirmed!','dashboard.php',1,'2026-04-06 18:16:22'),(52,11,'booking_completed','Service Completed','Efua Koomson has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-04-06 18:17:33'),(53,3,'payment','Payment Received','You received GH₵ 240.00 for booking #16.','dashboard.php',1,'2026-04-06 18:17:56'),(54,3,'commission','Commission Due','A 10% platform commission of GH₵ 20.00 is due for cash booking #16. Pay from your dashboard.','dashboard.php',1,'2026-04-06 18:17:56'),(55,11,'support','QuickHire Support Response','We\'ve responded to your feedback: \"Under consideration...\"','dashboard.php',1,'2026-04-06 18:25:36'),(56,10,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-04-06 18:35:17'),(57,7,'booking_accepted','Booking Accepted','Clare Jen has accepted your booking #17. Your service is confirmed!','dashboard.php',1,'2026-04-06 18:35:43'),(58,7,'booking_completed','Service Completed','Clare Jen has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-04-06 18:38:46'),(59,10,'payment','Payment Received','You received GH₵ 120.00 for booking #17.','dashboard.php',1,'2026-04-06 18:39:38'),(60,10,'commission','Commission Due','A 10% platform commission of GH₵ 10.00 is due for cash booking #17. Pay from your dashboard.','dashboard.php',1,'2026-04-06 18:39:38'),(61,8,'commission','Commission Paid','Clare Jen paid GH₵ 10.00 commission for booking #17.','admin.php',1,'2026-04-06 18:41:41'),(62,3,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',0,'2026-04-07 21:15:04'),(63,7,'booking_accepted','Booking Accepted','Efua Koomson has accepted your booking #18. Your service is confirmed!','dashboard.php',1,'2026-04-07 21:16:55'),(64,7,'booking_completed','Service Completed','Efua Koomson has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-04-07 21:19:28'),(65,3,'payment','Payment Received','You received GH₵ 240.00 for booking #18.','dashboard.php',0,'2026-04-07 21:20:18'),(66,3,'commission','Commission Due','A 10% platform commission of GH₵ 20.00 is due for cash booking #18. Pay from your dashboard.','dashboard.php',0,'2026-04-07 21:20:18'),(67,5,'booking','New Booking Request','Yaww has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-04-07 21:28:22'),(68,11,'booking_accepted','Booking Accepted','Abena Asante has accepted your booking #19. Your service is confirmed!','dashboard.php',1,'2026-04-07 21:28:56'),(69,11,'booking_completed','Service Completed','Abena Asante has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-04-07 21:30:09'),(70,5,'payment','Payment Received','You received GH₵ 1,080.00 for booking #19.','dashboard.php',1,'2026-04-07 21:31:14'),(71,15,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',1,'2026-04-08 18:13:34'),(72,10,'booking','New Booking Request','bill has booked your service. Check your Manage Bookings tab.','dashboard.php',1,'2026-04-08 18:15:05'),(73,15,'booking_accepted','Booking Accepted','Clare Jen has accepted your booking #20. Your service is confirmed!','dashboard.php',1,'2026-04-08 18:16:32'),(74,15,'booking_completed','Service Completed','Clare Jen has completed your service. You can now make payment and leave a review.','dashboard.php',1,'2026-04-08 18:18:03'),(75,10,'payment','Payment Received','You received GH₵ 120.00 for booking #20.','dashboard.php',0,'2026-04-08 18:19:38'),(76,13,'message','New message from Yaww','hey','messages.php?with=11',0,'2026-04-08 20:15:58'),(77,11,'message','New message from Ken','hello','messages.php?with=13',0,'2026-04-08 20:16:37'),(78,13,'message','New message from Sed','Hey','messages.php?with=7',0,'2026-04-08 20:17:14'),(79,16,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',0,'2026-04-09 14:39:12'),(80,5,'booking','New Booking Request','Marcia Eklu has booked your service. Check your Manage Bookings tab.','dashboard.php',0,'2026-04-09 14:41:47'),(81,5,'booking','New Booking Request','Marcia Eklu has booked your service. Check your Manage Bookings tab.','dashboard.php',0,'2026-04-09 14:41:48'),(82,16,'booking_accepted','Booking Accepted','Abena Asante has accepted your booking #21. Your service is confirmed!','dashboard.php',0,'2026-04-09 14:43:07'),(83,16,'booking_completed','Service Completed','Abena Asante has completed your service. You can now make payment and leave a review.','dashboard.php',0,'2026-04-09 14:43:53'),(84,5,'payment','Payment Received','You received GH₵ 60.00 for booking #21.','dashboard.php',0,'2026-04-09 14:44:56'),(85,5,'commission','Commission Due','A 10% platform commission of GH₵ 5.00 is due for cash booking #21. Pay from your dashboard.','dashboard.php',0,'2026-04-09 14:44:56'),(86,7,'support','QuickHire Support Response','We\'ve responded to your feedback: \"Why does QuickHire charge taxes?\r\nQuickHire operates in Ghana and is required to...\"','dashboard.php',1,'2026-04-09 17:36:45'),(87,17,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',0,'2026-04-10 09:49:29'),(88,5,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',0,'2026-04-17 14:54:56'),(89,7,'booking_accepted','Booking Accepted','Abena Asante has accepted your booking #23. Your service is confirmed!','dashboard.php',0,'2026-04-17 14:57:10'),(90,7,'booking_completed','Service Completed','Abena Asante has completed your service. You can now make payment and leave a review.','dashboard.php',0,'2026-04-17 15:02:50'),(91,5,'payment','Payment Received','You received GH₵ 1,080.00 for booking #23.','dashboard.php',0,'2026-04-17 15:04:44'),(92,10,'booking','New Booking Request','Sed has booked your service. Check your Manage Bookings tab.','dashboard.php',0,'2026-04-17 17:18:23'),(93,7,'booking_accepted','Booking Accepted','Clare Jen has accepted your booking #24. Your service is confirmed!','dashboard.php',0,'2026-04-17 17:19:04'),(94,7,'booking_completed','Service Completed','Clare Jen has completed your service. You can now make payment and leave a review.','dashboard.php',0,'2026-04-17 17:19:33'),(95,10,'payment','Payment Received','You received GH₵ 120.00 for booking #24.','dashboard.php',0,'2026-04-17 17:20:08'),(96,18,'system','Welcome to QuickHire!','Your account is set up. Browse services or complete your profile to get started.','dashboard.php',0,'2026-04-22 11:58:47'),(97,8,'verification','New Verification Request','Elle Summer has submitted documents for verification. Smile ID auto-verified ✓','admin.php',0,'2026-04-22 11:59:26');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_activity_log`
--

DROP TABLE IF EXISTS `partner_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `partner_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL,
  `summary` varchar(255) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_partner_date` (`partner_id`,`created_at`),
  CONSTRAINT `partner_activity_log_ibfk_1` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_activity_log`
--

LOCK TABLES `partner_activity_log` WRITE;
/*!40000 ALTER TABLE `partner_activity_log` DISABLE KEYS */;
INSERT INTO `partner_activity_log` VALUES (1,1,'test_connection','verified','Sandbox active — using Smile ID test environment',NULL,'{\"mode\":\"sandbox\"}','2026-04-22 22:21:25');
/*!40000 ALTER TABLE `partner_activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_config`
--

DROP TABLE IF EXISTS `partner_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `partner_id` int(11) NOT NULL,
  `config_key` varchar(50) NOT NULL,
  `config_value` text DEFAULT NULL,
  `is_secret` tinyint(1) DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unq_partner_key` (`partner_id`,`config_key`),
  CONSTRAINT `partner_config_ibfk_1` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_config`
--

LOCK TABLES `partner_config` WRITE;
/*!40000 ALTER TABLE `partner_config` DISABLE KEYS */;
INSERT INTO `partner_config` VALUES (1,1,'mode','sandbox',0,'2026-04-22 22:22:24'),(2,1,'enabled','1',0,'2026-04-22 22:19:20'),(3,1,'partner_id','',0,'2026-04-22 22:19:21'),(4,1,'api_key','',1,'2026-04-22 22:19:21');
/*!40000 ALTER TABLE `partner_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partners`
--

DROP TABLE IF EXISTS `partners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `contact_email` varchar(120) DEFAULT NULL,
  `contact_url` varchar(255) DEFAULT NULL,
  `status` enum('active','paused','terminated') DEFAULT 'active',
  `started_at` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partners`
--

LOCK TABLES `partners` WRITE;
/*!40000 ALTER TABLE `partners` DISABLE KEYS */;
INSERT INTO `partners` VALUES (1,'smile_id','Smile ID','Identity Verification','Smile ID is Africa\'s leading digital identity verification and KYC partner, covering Ghana Card, Passport, Voter\'s ID, Driver\'s Licence and NHIS against authoritative government databases. Used by QuickHire to auto-verify new service-provider IDs before admin approval.','support@usesmileid.com','https://usesmileid.com','active','2026-04-22',NULL,'2026-04-22 22:19:20','2026-04-22 22:19:20');
/*!40000 ALTER TABLE `partners` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','mobile_money','card','bank_transfer') NOT NULL,
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `payment_date` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,1,200.00,'mobile_money','completed','2026-03-26 10:31:08'),(2,2,400.00,'cash','pending','2026-03-26 10:31:08'),(3,3,250.00,'mobile_money','pending','2026-03-26 10:31:08'),(4,4,200.00,'mobile_money','completed','2026-03-27 19:18:49'),(5,5,800.00,'cash','completed','2026-03-27 20:29:33'),(6,6,50.00,'cash','completed','2026-03-28 15:22:08'),(7,7,100.00,'mobile_money','completed','2026-03-30 14:21:34'),(8,8,900.00,'cash','completed','2026-03-30 14:47:29'),(9,9,800.00,'mobile_money','completed','2026-03-30 14:51:21'),(10,10,480.00,'cash','completed','2026-03-30 15:15:22'),(11,11,120.00,'cash','completed','2026-03-30 20:55:20'),(12,12,120.00,'mobile_money','pending','2026-03-30 21:15:40'),(13,13,120.00,'cash','completed','2026-03-31 11:34:12'),(14,14,120.00,'cash','completed','2026-03-31 18:08:00'),(15,15,240.00,'cash','completed','2026-04-06 17:55:16'),(16,16,240.00,'cash','completed','2026-04-06 18:15:55'),(17,17,120.00,'cash','completed','2026-04-06 18:35:17'),(18,18,240.00,'cash','completed','2026-04-07 21:15:04'),(19,19,1080.00,'mobile_money','completed','2026-04-07 21:28:21'),(20,20,120.00,'mobile_money','completed','2026-04-08 18:15:05'),(21,21,60.00,'cash','completed','2026-04-09 14:41:46'),(22,22,60.00,'mobile_money','pending','2026-04-09 14:41:48'),(23,23,1080.00,'mobile_money','completed','2026-04-17 14:54:56'),(24,24,120.00,'mobile_money','completed','2026-04-17 17:18:22');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `platform_feedback`
--

DROP TABLE IF EXISTS `platform_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` between 1 and 5),
  `category` varchar(50) DEFAULT 'general',
  `message` text NOT NULL,
  `admin_reply` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `platform_feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_feedback`
--

LOCK TABLES `platform_feedback` WRITE;
/*!40000 ALTER TABLE `platform_feedback` DISABLE KEYS */;
INSERT INTO `platform_feedback` VALUES (1,7,4,'usability','(No comment)',NULL,1,'2026-03-30 20:58:07'),(2,7,4,'features','(No comment)',NULL,1,'2026-03-31 18:20:04'),(3,7,1,'payment_issue','why the taxes','Why does QuickHire charge taxes?\r\nQuickHire operates in Ghana and is required to apply government-mandated taxes on transactions under the Value Added Tax Act, 1998 (Act 546) and its amendments, including Act 1151. The tax breakdown on your booking is:\r\n\r\nVAT – 15%\r\nNHIL (National Health Insurance Levy) – 2.5%\r\nGETFund Levy – 2.5%\r\n\r\nThis totals a 20% tax applied to the service fee.\r\nThese charges are not kept by QuickHire — they represent statutory levies that service platforms operating in Ghana are obligated to collect. The tax breakdown is shown transparently on every booking so you always know exactly what you\'re paying and why.',1,'2026-04-06 17:59:23'),(4,11,1,'payment_issue','Take more commission','Under consideration',1,'2026-04-06 18:18:28'),(5,7,5,'general','(No comment)',NULL,0,'2026-04-06 18:39:49'),(6,7,5,'general','Loved the service',NULL,1,'2026-04-07 21:21:22');
/*!40000 ALTER TABLE `platform_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `provider_commissions`
--

DROP TABLE IF EXISTS `provider_commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `provider_commissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('owed','paid') DEFAULT 'owed',
  `payment_method` varchar(50) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `provider_id` (`provider_id`),
  CONSTRAINT `provider_commissions_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`),
  CONSTRAINT `provider_commissions_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `service_providers` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `provider_commissions`
--

LOCK TABLES `provider_commissions` WRITE;
/*!40000 ALTER TABLE `provider_commissions` DISABLE KEYS */;
INSERT INTO `provider_commissions` VALUES (1,16,1,20.00,'owed',NULL,NULL,'2026-04-06 18:17:56'),(2,17,6,10.00,'paid','mobile_money','2026-04-06 18:41:41','2026-04-06 18:39:38'),(3,18,1,20.00,'owed',NULL,NULL,'2026-04-07 21:20:18'),(4,21,3,5.00,'owed',NULL,NULL,'2026-04-09 14:44:56');
/*!40000 ALTER TABLE `provider_commissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reviews`
--

DROP TABLE IF EXISTS `reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `rating` int(11) DEFAULT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`review_id`),
  KEY `booking_id` (`booking_id`),
  KEY `user_id` (`user_id`),
  KEY `provider_id` (`provider_id`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`),
  CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`provider_id`) REFERENCES `service_providers` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reviews`
--

LOCK TABLES `reviews` WRITE;
/*!40000 ALTER TABLE `reviews` DISABLE KEYS */;
INSERT INTO `reviews` VALUES (1,1,1,1,5,'Efua completely transformed my living room. Absolutely stunning work!','2026-03-26 10:31:09'),(2,7,7,6,4,'Did a good job','2026-03-30 14:26:29'),(3,5,3,1,5,'','2026-03-30 14:52:17'),(4,10,4,2,4,'','2026-03-30 15:19:35'),(5,10,11,2,4,'Was good','2026-03-30 15:21:03'),(6,11,7,7,4,'Was very respectful','2026-03-31 11:26:11'),(7,8,11,3,5,'Excellent Food','2026-04-07 21:35:11'),(8,20,15,6,5,'Not a speck of dust left','2026-04-08 18:20:28'),(9,21,16,3,5,'','2026-04-09 14:45:31');
/*!40000 ALTER TABLE `reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_providers`
--

DROP TABLE IF EXISTS `service_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_providers` (
  `provider_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `service_category` varchar(100) NOT NULL,
  `experience_years` int(11) DEFAULT 0,
  `bio` text DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT 0.00,
  `availability` varchar(100) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `is_featured` tinyint(1) DEFAULT 0,
  `joined_at` datetime DEFAULT current_timestamp(),
  `avg_response` varchar(50) DEFAULT 'Not set',
  `languages` varchar(255) DEFAULT 'English',
  PRIMARY KEY (`provider_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `service_providers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_providers`
--

LOCK TABLES `service_providers` WRITE;
/*!40000 ALTER TABLE `service_providers` DISABLE KEYS */;
INSERT INTO `service_providers` VALUES (1,3,'Interior Design',10,'10+ years transforming homes across Accra.',4.90,'Mon - Fri',1,1,'2026-03-26 10:31:08','Not set','English'),(2,4,'Carpentry',8,'Custom furniture and woodwork. Quality guaranteed.',4.00,'Mon - Sat',1,1,'2026-03-26 10:31:08','Not set','English'),(3,5,'Catering',7,'Professional catering for all occasions.',5.00,'All week',1,1,'2026-03-26 10:31:08','Not set','English'),(5,9,'Gardening',4,'I am a Gardner',0.00,'All Week',0,0,'2026-03-28 14:35:09','Not set','English'),(6,10,'Cleaning',2,'Hire me for a thorough cleaning job',4.50,'All Week',1,0,'2026-03-28 15:43:52','Not set','English'),(7,13,'Tutoring',8,'The best Math Tutor',4.00,'Fri',1,0,'2026-03-30 15:49:14','Same day','English,twi'),(8,14,'Electrician',4,'Bring all your electrical faults for fixing',0.00,'Mon - Fri',1,0,'2026-03-31 18:25:49','24 Hours','English,twi'),(9,17,'General',0,NULL,0.00,NULL,0,0,'2026-04-10 09:49:28','Not set','English'),(10,18,'General',0,NULL,0.00,NULL,0,0,'2026-04-22 11:58:46','Not set','English');
/*!40000 ALTER TABLE `service_providers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `service_id` int(11) NOT NULL AUTO_INCREMENT,
  `provider_id` int(11) NOT NULL,
  `service_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`service_id`),
  KEY `provider_id` (`provider_id`),
  CONSTRAINT `services_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `service_providers` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
INSERT INTO `services` VALUES (1,1,'Initial Consultation','One hour design consultation',200.00,1,'2026-03-26 10:31:08'),(2,1,'Single Room Design','Full design package for one room',800.00,1,'2026-03-26 10:31:08'),(3,2,'Custom Furniture','Bespoke furniture built to your spec',400.00,1,'2026-03-26 10:31:08'),(4,2,'Small Repairs','Minor woodwork repairs and fixes',120.00,1,'2026-03-26 10:31:08'),(5,3,'Basic Catering','Catering per head for events',50.00,1,'2026-03-26 10:31:08'),(6,3,'Event Coordination','Full day event coordination',900.00,1,'2026-03-26 10:31:08'),(7,6,'One room cleaning','Thoroughly clean one room',100.00,1,'2026-03-28 15:47:37'),(8,7,'Math Tutoring','Tutoring for 1 hour',100.00,1,'2026-03-30 15:56:08'),(9,8,'Consultation & Minor Fixes','Small electrical problems',50.00,1,'2026-04-01 15:01:02');
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('customer','provider','admin','both') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Kwame Boateng','kwame@email.com','0244000001','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','customer','2026-03-26 10:31:08'),(2,'Ama Serwaa','ama@email.com','0244000002','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','customer','2026-03-26 10:31:08'),(3,'Efua Koomson','efua@email.com','0244000003','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','provider','2026-03-26 10:31:08'),(4,'Samuel Kojo','samuel@email.com','0244000004','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','provider','2026-03-26 10:31:08'),(5,'Abena Asante','abena@email.com','0244000005','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','provider','2026-03-26 10:31:08'),(7,'Sed','ded@gmail.com','2555855452','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','customer','2026-03-27 19:17:10'),(8,'William','william360clarke@gmail.com','0550668987','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','admin','2026-03-27 21:10:02'),(9,'Percy Jackson','jackson@gmail.com','885552200','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','both','2026-03-28 14:35:09'),(10,'Clare Jen','jen@gmail.com','58400022','$2y$10$XlxRm3kbu3lk/notWDWoTuu9PdEGdTLTi5PtlwfPpZ8r0oXQkc55e','both','2026-03-28 15:43:52'),(11,'Yaww','agyeta44@gmail.com','033455566','$2y$10$NFOg4LPZjJLw1QqVRhiADu8aTjgq4RjJwalnmUXd/RVqg.f2SOUMW','customer','2026-03-30 14:46:30'),(12,'Ben Fuller','ben@email.com','888220258','$2y$10$H2gq.L7CN2yJs/mnPwhXv.du4QuvWdjBUuykPl6K5a8Q.ipF/EcTm','customer','2026-03-30 15:46:34'),(13,'Ken','ken@gmail.com','8885552200','$2y$10$uyHxGbW3BNZqeKbPpcgzVe2C789jPql/bz.aLGa8Up1mBOtK3XUpO','both','2026-03-30 15:49:14'),(14,'Jedd','jedd@gmail.com','055266584','$2y$10$IAu964htRXxItOXElL3F5O9u6O6OM80LUptfZNFXFvMX.9XNVlhry','provider','2026-03-31 18:25:49'),(15,'bill','bil@gmail.com','0554523695','$2y$10$qrfvUnkFNst0GjvJFDsmru0yjcFzqof9rvQ8lA.l0dH2DieAckseO','customer','2026-04-08 18:13:34'),(16,'Marcia Eklu','marciaekl@gmail.com','0263219251','$2y$10$uA1ELJ6CWiHoYB6T43Y28.I9CYTz7Zr0ai./a/lJN9.5yjRHzxHXK','customer','2026-04-09 14:39:11'),(17,'Sedem','sede@gmail.com','0244523265','$2y$10$uoF7Uphb.EsoIfl/JXQVguirmOidwwBKEL5bGq4zIibTpxwfml.Qu','provider','2026-04-10 09:49:27'),(18,'Elle Summer','summer@gmail.com','0263219251','$2y$10$Bf91zFUmsr..Zo4Sac54F.5royejTjUWwalkSEqIDrisIf063T8NO','both','2026-04-22 11:58:46');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `verification_requests`
--

DROP TABLE IF EXISTS `verification_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `verification_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider_id` int(11) NOT NULL,
  `id_type` varchar(50) NOT NULL,
  `id_number` varchar(100) NOT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `cert_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `smileid_status` varchar(20) DEFAULT 'pending',
  `smileid_summary` varchar(255) DEFAULT NULL,
  `smileid_reference` varchar(100) DEFAULT NULL,
  `smileid_response` text DEFAULT NULL,
  `smileid_checked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`),
  CONSTRAINT `verification_requests_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `service_providers` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verification_requests`
--

LOCK TABLES `verification_requests` WRITE;
/*!40000 ALTER TABLE `verification_requests` DISABLE KEYS */;
INSERT INTO `verification_requests` VALUES (1,6,'ghana_card','GHA -7422556881-2','uploads/verification/id_6_1774755897.png',NULL,'','approved',NULL,'pending',NULL,NULL,NULL,NULL,'2026-03-28 15:44:57','2026-03-29 05:48:44'),(2,7,'ghana_card','GHA -7422556881-3','uploads/verification/id_7_1774928995.png',NULL,'','approved',NULL,'pending',NULL,NULL,NULL,NULL,'2026-03-30 15:49:55','2026-03-31 05:51:53'),(3,8,'ghana_card','GHA -7422556881-2','uploads/verification/id_8_1775024955.jpg',NULL,'','approved',NULL,'pending',NULL,NULL,NULL,NULL,'2026-03-31 18:29:15','2026-04-01 09:47:03'),(4,10,'ghana_card','GHA -7422556888-4','uploads/verification/id_10_1776902366.jpeg',NULL,'','pending',NULL,'verified','Ghana Card verified — name matches authority record','SMJ-SANDBOX-abc38e350b','{\"mode\":\"sandbox\",\"country\":\"GH\",\"id_type\":\"GHANA_CARD\",\"id_number\":\"GHA -7422556888-4\",\"SmileJobID\":\"SMJ-SANDBOX-abc38e350b\",\"ResultCode\":\"1012\",\"ResultText\":\"ID Number Validated\",\"Actions\":{\"Verify_ID_Number\":\"Completed\"}}','2026-04-22 11:59:26','2026-04-22 11:59:26',NULL);
/*!40000 ALTER TABLE `verification_requests` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-24 15:23:17
