-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: seminary_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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
-- Table structure for table `academic_evaluation_questions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_evaluation_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(60) NOT NULL,
  `question_text` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aeq_active` (`is_active`),
  KEY `idx_aeq_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_evaluation_questions`
--

LOCK TABLES `academic_evaluation_questions` WRITE;
/*!40000 ALTER TABLE `academic_evaluation_questions` DISABLE KEYS */;
INSERT INTO `academic_evaluation_questions` VALUES (1,'Study Habits','Maintains a consistent daily study schedule.',1,1,'2026-06-06 08:18:55',NULL),(2,'Study Habits','Uses effective note-taking strategies during lectures.',2,1,'2026-06-06 08:18:55',NULL),(3,'Study Habits','Reviews lessons regularly even without upcoming exams.',3,1,'2026-06-06 08:18:55',NULL),(4,'Study Habits','Studies in a manner that promotes deep understanding, not memorization.',4,1,'2026-06-06 08:18:55',NULL),(5,'Study Habits','Sets clear academic goals for each semester.',5,1,'2026-06-06 08:18:55',NULL),(6,'Time Management','Plans weekly tasks and study time ahead of schedule.',6,1,'2026-06-06 08:18:55',NULL),(7,'Time Management','Prioritizes academic responsibilities over distractions.',7,1,'2026-06-06 08:18:55',NULL),(8,'Time Management','Balances formation activities and academic workload effectively.',8,1,'2026-06-06 08:18:55',NULL),(9,'Time Management','Avoids procrastination in completing academic tasks.',9,1,'2026-06-06 08:18:55',NULL),(10,'Time Management','Uses time efficiently during free periods.',10,1,'2026-06-06 08:18:55',NULL),(11,'Class Attendance','Attends classes consistently and on time.',11,1,'2026-06-06 08:18:55',NULL),(12,'Class Attendance','Shows attentiveness during class sessions.',12,1,'2026-06-06 08:18:55',NULL),(13,'Class Attendance','Participates actively in class discussions when appropriate.',13,1,'2026-06-06 08:18:55',NULL),(14,'Class Attendance','Comes to class prepared with required materials.',14,1,'2026-06-06 08:18:55',NULL),(15,'Class Attendance','Demonstrates respect for teachers and classmates during classes.',15,1,'2026-06-06 08:18:55',NULL),(16,'Academic Responsibility','Submits assignments and requirements on time.',16,1,'2026-06-06 08:18:55',NULL),(17,'Academic Responsibility','Follows academic policies and classroom rules.',17,1,'2026-06-06 08:18:55',NULL),(18,'Academic Responsibility','Demonstrates honesty and integrity in academic work.',18,1,'2026-06-06 08:18:55',NULL),(19,'Academic Responsibility','Accepts accountability for academic performance outcomes.',19,1,'2026-06-06 08:18:55',NULL),(20,'Academic Responsibility','Communicates responsibly with instructors regarding requirements.',20,1,'2026-06-06 08:18:55',NULL),(21,'Critical Thinking','Analyzes academic concepts with depth and clarity.',21,1,'2026-06-06 08:18:55',NULL),(22,'Critical Thinking','Applies learned concepts to new situations or cases.',22,1,'2026-06-06 08:18:55',NULL),(23,'Critical Thinking','Demonstrates sound problem-solving skills in academic tasks.',23,1,'2026-06-06 08:18:55',NULL),(24,'Critical Thinking','Forms independent and well-reasoned conclusions.',24,1,'2026-06-06 08:18:55',NULL),(25,'Critical Thinking','Asks thoughtful questions that reflect understanding.',25,1,'2026-06-06 08:18:55',NULL),(26,'Learning Attitude','Shows openness to feedback and correction.',26,1,'2026-06-06 08:18:55',NULL),(27,'Learning Attitude','Demonstrates desire for continuous academic improvement.',27,1,'2026-06-06 08:18:55',NULL),(28,'Learning Attitude','Shows intellectual curiosity beyond course requirements.',28,1,'2026-06-06 08:18:55',NULL),(29,'Seminary Academic Performance','Maintains consistent academic performance across subjects.',29,1,'2026-06-06 08:18:55',NULL),(30,'Seminary Academic Performance','Demonstrates academic discipline in meeting expectations.',30,1,'2026-06-06 08:18:55',NULL);
/*!40000 ALTER TABLE `academic_evaluation_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_evaluation_responses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_evaluation_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_aer` (`evaluation_id`,`question_id`),
  KEY `idx_aer_eval` (`evaluation_id`),
  KEY `idx_aer_q` (`question_id`),
  CONSTRAINT `fk_aer_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `academic_evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aer_q` FOREIGN KEY (`question_id`) REFERENCES `academic_evaluation_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_evaluation_responses`
--

LOCK TABLES `academic_evaluation_responses` WRITE;
/*!40000 ALTER TABLE `academic_evaluation_responses` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_evaluation_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_evaluation_results`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_evaluation_results` (
  `evaluation_id` int(11) NOT NULL,
  `total_score` int(11) NOT NULL,
  `average_score` decimal(4,2) NOT NULL,
  `rating_label` varchar(30) NOT NULL,
  `recommendation` varchar(80) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`evaluation_id`),
  CONSTRAINT `fk_aeres_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `academic_evaluations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_evaluation_results`
--

LOCK TABLES `academic_evaluation_results` WRITE;
/*!40000 ALTER TABLE `academic_evaluation_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_evaluation_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_evaluations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `formator_id` int(11) NOT NULL,
  `gpa` decimal(4,2) DEFAULT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `evaluation_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ae_student` (`student_id`),
  KEY `idx_ae_term` (`academic_year`,`semester`),
  KEY `idx_ae_formator` (`formator_id`),
  CONSTRAINT `fk_ae_formator` FOREIGN KEY (`formator_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_ae_student` FOREIGN KEY (`student_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_evaluations`
--

LOCK TABLES `academic_evaluations` WRITE;
/*!40000 ALTER TABLE `academic_evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_evaluations` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_academic_evaluations_after_update` AFTER UPDATE ON `academic_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('academic_evaluations', OLD.`id`, 'UPDATE', JSON_OBJECT('id', OLD.`id`, 'student_id', OLD.`student_id`, 'formator_id', OLD.`formator_id`, 'gpa', OLD.`gpa`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'remarks', OLD.`remarks`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), JSON_OBJECT('id', NEW.`id`, 'student_id', NEW.`student_id`, 'formator_id', NEW.`formator_id`, 'gpa', NEW.`gpa`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'remarks', NEW.`remarks`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`), LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') GPA: ', COALESCE(CAST(OLD.gpa AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_academic_evaluations_before_delete` BEFORE DELETE ON `academic_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('academic_evaluations', OLD.`id`, 'DELETE', JSON_OBJECT('id', OLD.`id`, 'student_id', OLD.`student_id`, 'formator_id', OLD.`formator_id`, 'gpa', OLD.`gpa`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'remarks', OLD.`remarks`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), NULL, LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') GPA: ', COALESCE(CAST(OLD.gpa AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `academic_notifications`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_user_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acad_notif_recipient` (`recipient_user_id`,`read_at`),
  KEY `idx_acad_notif_student` (`student_id`),
  CONSTRAINT `fk_acad_notif_recipient` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_acad_notif_student` FOREIGN KEY (`student_id`) REFERENCES `seminarians` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_notifications`
--

LOCK TABLES `academic_notifications` WRITE;
/*!40000 ALTER TABLE `academic_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_progress`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_progress` (
  `progress_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `previous_gpa` decimal(4,2) DEFAULT NULL,
  `current_gpa` decimal(4,2) DEFAULT NULL,
  `growth_status` enum('Growing','Maintaining','Declining') DEFAULT NULL,
  `growth_percentage` decimal(6,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`progress_id`),
  UNIQUE KEY `uniq_progress_record` (`student_id`,`semester`,`academic_year`),
  KEY `idx_progress_student` (`student_id`),
  CONSTRAINT `fk_progress_student` FOREIGN KEY (`student_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_progress`
--

LOCK TABLES `academic_progress` WRITE;
/*!40000 ALTER TABLE `academic_progress` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_progress` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_years`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(9) NOT NULL,
  `start_year` int(11) NOT NULL,
  `end_year` int(11) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_academic_years_label` (`label`),
  KEY `idx_academic_years_active` (`is_active`,`archived_at`),
  KEY `idx_academic_years_current` (`is_current`)
) ENGINE=InnoDB AUTO_INCREMENT=3930 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_years`
--

LOCK TABLES `academic_years` WRITE;
/*!40000 ALTER TABLE `academic_years` DISABLE KEYS */;
INSERT INTO `academic_years` VALUES (1,'2026-2027',2026,2027,NULL,NULL,1,1,NULL,NULL,'2026-06-04 15:48:27'),(2,'2016-2017',2016,2017,NULL,NULL,1,0,NULL,'2026-06-04 16:13:21','2026-06-04 16:11:36'),(3,'2009-2010',2009,2010,NULL,NULL,1,0,NULL,'2026-06-04 16:13:06','2026-06-04 16:12:18'),(4,'2010-2011',2010,2011,NULL,NULL,1,0,NULL,NULL,'2026-06-04 16:12:27'),(5,'2011-2012',2011,2012,NULL,NULL,1,0,NULL,NULL,'2026-06-04 16:14:10'),(6,'2012-2013',2012,2013,NULL,NULL,1,0,NULL,NULL,'2026-06-04 16:14:20'),(7,'2013-2014',2013,2014,NULL,NULL,1,0,NULL,NULL,'2026-06-04 16:14:30'),(8,'2014-2015',2014,2015,NULL,NULL,1,0,NULL,NULL,'2026-06-04 16:14:45'),(10,'2018-2019',2018,2019,NULL,NULL,1,0,NULL,NULL,'2026-06-04 16:15:05'),(11,'2027-2028',2027,2028,NULL,NULL,1,0,NULL,NULL,'2026-10-21 16:35:21'),(12,'2025-2026',2025,2026,NULL,NULL,1,0,NULL,NULL,'2026-10-21 16:45:33');
/*!40000 ALTER TABLE `academic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activities`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `field_name` varchar(80) DEFAULT NULL,
  `previous_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activities`
--

LOCK TABLES `activities` WRITE;
/*!40000 ALTER TABLE `activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `applicants`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `applicants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `dob` date NOT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `academic_history` text DEFAULT NULL,
  `parent_info` text DEFAULT NULL,
  `academic_year` varchar(9) DEFAULT NULL,
  `program` varchar(80) DEFAULT NULL,
  `status` enum('Pending','Enrolled') DEFAULT 'Pending',
  `user_id` int(11) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_applicants_user_id` (`user_id`),
  CONSTRAINT `fk_applicants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `applicants`
--

LOCK TABLES `applicants` WRITE;
/*!40000 ALTER TABLE `applicants` DISABLE KEYS */;
/*!40000 ALTER TABLE `applicants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashier_fee_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_fee_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cashier_fee_items_schedule` (`schedule_id`),
  CONSTRAINT `fk_cashier_fee_items_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `cashier_fee_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashier_fee_items`
--

LOCK TABLES `cashier_fee_items` WRITE;
/*!40000 ALTER TABLE `cashier_fee_items` DISABLE KEYS */;
INSERT INTO `cashier_fee_items` VALUES (21,7,'Board and Lodging Fee',20000.00,1,'2026-10-15 19:13:53'),(22,7,'Bus Fee',1500.00,2,'2026-10-15 19:13:53'),(23,7,'Computers Fee',1000.00,3,'2026-10-15 19:13:53'),(24,7,'Miscellaneous Fee',500.00,4,'2026-10-15 19:13:53'),(25,7,'Rice Fee',0.00,5,'2026-10-15 19:13:53'),(26,8,'Board and Lodging Fee',20000.00,1,'2026-10-15 19:13:53'),(27,8,'Bus Fee',1500.00,2,'2026-10-15 19:13:53'),(28,8,'Computers Fee',1000.00,3,'2026-10-15 19:13:53'),(29,8,'Miscellaneous Fee',500.00,4,'2026-10-15 19:13:53'),(30,8,'Rice Fee',0.00,5,'2026-10-15 19:13:53');
/*!40000 ALTER TABLE `cashier_fee_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashier_fee_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_fee_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(9) NOT NULL,
  `semester` enum('1st','2nd') NOT NULL,
  `program` varchar(80) DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `archived_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cashier_fee_sched_term` (`academic_year`,`semester`),
  KEY `idx_cashier_fee_sched_scope` (`program`,`year_level`),
  KEY `fk_cashier_fee_sched_created_by` (`created_by`),
  CONSTRAINT `fk_cashier_fee_sched_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashier_fee_schedules`
--

LOCK TABLES `cashier_fee_schedules` WRITE;
/*!40000 ALTER TABLE `cashier_fee_schedules` DISABLE KEYS */;
INSERT INTO `cashier_fee_schedules` VALUES (7,'2026-2027','1st',NULL,NULL,1,NULL,3,'2026-10-15 19:13:53'),(8,'2026-2027','2nd',NULL,NULL,1,NULL,3,'2026-10-15 19:13:53');
/*!40000 ALTER TABLE `cashier_fee_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashier_payment_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_payment_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `particular` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cashier_payment_items_payment` (`payment_id`),
  KEY `idx_cpi_sort` (`payment_id`,`sort_order`),
  CONSTRAINT `fk_cashier_payment_items_payment` FOREIGN KEY (`payment_id`) REFERENCES `lodging_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashier_payment_items`
--

LOCK TABLES `cashier_payment_items` WRITE;
/*!40000 ALTER TABLE `cashier_payment_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `cashier_payment_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashier_soa_requests`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_soa_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester` enum('1st','2nd') NOT NULL,
  `status` enum('Pending','Processing','Sent','Completed','Approved') NOT NULL DEFAULT 'Pending',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `requested_by` int(11) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cashier_soa_req_student` (`seminarian_id`),
  KEY `idx_cashier_soa_req_status` (`status`),
  KEY `idx_cashier_soa_req_term` (`academic_year`,`semester`),
  KEY `fk_cashier_soa_req_by` (`requested_by`),
  KEY `fk_cashier_soa_proc_by` (`processed_by`),
  CONSTRAINT `fk_cashier_soa_proc_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cashier_soa_req_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cashier_soa_req_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashier_soa_requests`
--

LOCK TABLES `cashier_soa_requests` WRITE;
/*!40000 ALTER TABLE `cashier_soa_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `cashier_soa_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curriculum_subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `curriculum_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `curriculum_version_id` int(11) NOT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `subject_id` int(11) NOT NULL,
  `prerequisite_subject_id` int(11) DEFAULT NULL,
  `year_level` varchar(40) NOT NULL,
  `semester` enum('1st','2nd') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_curriculum_subject` (`curriculum_version_id`,`subject_id`,`year_level`,`semester`),
  KEY `idx_curriculum_subjects_term` (`year_level`,`semester`),
  KEY `fk_curriculum_subjects_subject` (`subject_id`),
  KEY `fk_curr_sub_prereq` (`prerequisite_subject_id`),
  KEY `idx_curriculum_subjects_ay` (`academic_year_id`),
  CONSTRAINT `fk_curr_sub_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_curr_sub_prereq` FOREIGN KEY (`prerequisite_subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_curriculum_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_curriculum_subjects_version` FOREIGN KEY (`curriculum_version_id`) REFERENCES `curriculum_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=391933 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curriculum_subjects`
--

LOCK TABLES `curriculum_subjects` WRITE;
/*!40000 ALTER TABLE `curriculum_subjects` DISABLE KEYS */;
INSERT INTO `curriculum_subjects` VALUES (1,1,1,1,NULL,'First Year','1st',1,1,'2026-06-04 17:02:10',NULL),(2,1,1,2,NULL,'First Year','1st',1,2,'2026-06-04 17:02:10',NULL),(3,1,1,3,NULL,'First Year','1st',1,3,'2026-06-04 17:02:10',NULL),(4,1,1,4,NULL,'First Year','1st',1,4,'2026-06-04 17:02:10',NULL),(5,1,1,5,NULL,'First Year','1st',1,5,'2026-06-04 17:02:10',NULL),(6,1,1,6,NULL,'First Year','1st',1,6,'2026-06-04 17:02:10',NULL),(7,1,1,7,NULL,'First Year','1st',1,7,'2026-06-04 17:02:10',NULL),(8,1,1,8,NULL,'First Year','1st',1,8,'2026-06-04 17:02:10',NULL),(9,1,1,9,1,'First Year','2nd',1,9,'2026-06-04 17:02:10',NULL),(10,1,1,10,3,'First Year','2nd',1,10,'2026-06-04 17:02:10',NULL),(11,1,1,11,NULL,'First Year','2nd',1,11,'2026-06-04 17:02:10',NULL),(12,1,1,12,NULL,'First Year','2nd',1,12,'2026-06-04 17:02:10',NULL),(13,1,1,13,NULL,'First Year','2nd',1,13,'2026-06-04 17:02:10',NULL),(14,1,1,14,NULL,'First Year','2nd',1,14,'2026-06-04 17:02:10',NULL),(15,1,1,15,7,'First Year','2nd',1,15,'2026-06-04 17:02:10',NULL),(16,1,1,16,8,'First Year','2nd',1,16,'2026-06-04 17:02:10',NULL),(17,1,1,17,9,'Second Year','1st',1,17,'2026-06-04 17:02:10',NULL),(18,1,1,18,10,'Second Year','1st',1,18,'2026-06-04 17:02:10',NULL),(19,1,1,19,NULL,'Second Year','1st',1,19,'2026-06-04 17:02:10',NULL),(20,1,1,20,NULL,'Second Year','1st',1,20,'2026-06-04 17:02:10',NULL),(21,1,1,21,NULL,'Second Year','1st',1,21,'2026-06-04 17:02:10',NULL),(22,1,1,22,NULL,'Second Year','1st',1,22,'2026-06-04 17:02:10',NULL),(23,1,1,23,NULL,'Second Year','1st',1,23,'2026-06-04 17:02:10',NULL),(24,1,1,24,15,'Second Year','1st',1,24,'2026-06-04 17:02:10',NULL),(25,1,1,25,17,'Second Year','2nd',1,25,'2026-06-04 17:02:10',NULL),(26,1,1,26,18,'Second Year','2nd',1,26,'2026-06-04 17:02:10',NULL),(27,1,1,27,NULL,'Second Year','2nd',1,27,'2026-06-04 17:02:10',NULL),(28,1,1,28,NULL,'Second Year','2nd',1,28,'2026-06-04 17:02:10',NULL),(29,1,1,29,4,'Second Year','2nd',1,29,'2026-06-04 17:02:10',NULL),(30,1,1,30,NULL,'Second Year','2nd',1,30,'2026-06-04 17:02:10',NULL),(31,1,1,31,21,'Second Year','2nd',1,31,'2026-06-04 17:02:10',NULL),(32,1,1,32,24,'Second Year','2nd',1,32,'2026-06-04 17:02:10',NULL),(33,1,1,33,25,'Third Year','1st',1,33,'2026-06-04 17:02:10',NULL),(34,1,1,34,NULL,'Third Year','1st',1,34,'2026-06-04 17:02:10',NULL),(35,1,1,35,NULL,'Third Year','1st',1,35,'2026-06-04 17:02:10',NULL),(36,1,1,36,NULL,'Third Year','1st',1,36,'2026-06-04 17:02:10',NULL),(37,1,1,37,NULL,'Third Year','1st',1,37,'2026-06-04 17:02:10',NULL),(38,1,1,38,NULL,'Third Year','1st',1,38,'2026-06-04 17:02:10',NULL),(39,1,1,39,NULL,'Third Year','1st',1,39,'2026-06-04 17:02:10',NULL),(40,1,1,40,33,'Third Year','2nd',1,40,'2026-06-04 17:02:10',NULL),(41,1,1,41,34,'Third Year','2nd',1,41,'2026-06-04 17:02:10',NULL),(42,1,1,42,NULL,'Third Year','2nd',1,42,'2026-06-04 17:02:10',NULL),(43,1,1,43,37,'Third Year','2nd',1,43,'2026-06-04 17:02:10',NULL),(44,1,1,44,NULL,'Third Year','2nd',1,44,'2026-06-04 17:02:10',NULL),(45,1,1,45,NULL,'Third Year','2nd',1,45,'2026-06-04 17:02:10',NULL),(46,1,1,46,NULL,'Third Year','2nd',1,46,'2026-06-04 17:02:10',NULL),(47,1,1,47,NULL,'Fourth Year','1st',1,47,'2026-06-04 17:02:10',NULL),(48,1,1,48,NULL,'Fourth Year','1st',1,48,'2026-06-04 17:02:10',NULL),(49,1,1,49,NULL,'Fourth Year','1st',1,49,'2026-06-04 17:02:10',NULL),(50,1,1,50,NULL,'Fourth Year','1st',1,50,'2026-06-04 17:02:10',NULL),(51,1,1,51,NULL,'Fourth Year','2nd',1,51,'2026-06-04 17:02:10',NULL),(52,1,1,52,NULL,'Fourth Year','2nd',1,52,'2026-06-04 17:02:10',NULL),(53,1,1,53,NULL,'Fourth Year','2nd',1,53,'2026-06-04 17:02:10',NULL),(54,1,1,54,NULL,'Fourth Year','2nd',1,54,'2026-06-04 17:02:11',NULL),(1405,2,1,1,NULL,'First Year','1st',1,1,'2026-06-04 17:08:57',NULL),(1406,2,1,2,NULL,'First Year','1st',1,2,'2026-06-04 17:08:57',NULL),(1407,2,1,3,NULL,'First Year','1st',1,3,'2026-06-04 17:08:57',NULL),(1408,2,1,4,NULL,'First Year','1st',1,4,'2026-06-04 17:08:57',NULL),(1409,2,1,5,NULL,'First Year','1st',1,5,'2026-06-04 17:08:57',NULL),(1410,2,1,6,NULL,'First Year','1st',1,6,'2026-06-04 17:08:57',NULL),(1411,2,1,7,NULL,'First Year','1st',1,7,'2026-06-04 17:08:57',NULL),(1412,2,1,8,NULL,'First Year','1st',1,8,'2026-06-04 17:08:57',NULL),(1413,2,1,9,1,'First Year','2nd',1,9,'2026-06-04 17:08:57',NULL),(1414,2,1,10,3,'First Year','2nd',1,10,'2026-06-04 17:08:57',NULL),(1415,2,1,11,NULL,'First Year','2nd',1,11,'2026-06-04 17:08:57',NULL),(1416,2,1,12,NULL,'First Year','2nd',1,12,'2026-06-04 17:08:57',NULL),(1417,2,1,13,NULL,'First Year','2nd',1,13,'2026-06-04 17:08:57',NULL),(1418,2,1,14,NULL,'First Year','2nd',1,14,'2026-06-04 17:08:57',NULL),(1419,2,1,15,7,'First Year','2nd',1,15,'2026-06-04 17:08:57',NULL),(1420,2,1,16,8,'First Year','2nd',1,16,'2026-06-04 17:08:57',NULL),(1421,2,1,17,9,'Second Year','1st',1,17,'2026-06-04 17:08:57',NULL),(1422,2,1,18,10,'Second Year','1st',1,18,'2026-06-04 17:08:57',NULL),(1423,2,1,19,NULL,'Second Year','1st',1,19,'2026-06-04 17:08:57',NULL),(1424,2,1,20,NULL,'Second Year','1st',1,20,'2026-06-04 17:08:57',NULL),(1425,2,1,21,NULL,'Second Year','1st',1,21,'2026-06-04 17:08:57',NULL),(1426,2,1,22,NULL,'Second Year','1st',1,22,'2026-06-04 17:08:57',NULL),(1427,2,1,23,NULL,'Second Year','1st',1,23,'2026-06-04 17:08:57',NULL),(1428,2,1,24,15,'Second Year','1st',1,24,'2026-06-04 17:08:57',NULL),(1429,2,1,25,17,'Second Year','2nd',1,25,'2026-06-04 17:08:57',NULL),(1430,2,1,26,18,'Second Year','2nd',1,26,'2026-06-04 17:08:57',NULL),(1431,2,1,27,NULL,'Second Year','2nd',1,27,'2026-06-04 17:08:57',NULL),(1432,2,1,28,NULL,'Second Year','2nd',1,28,'2026-06-04 17:08:57',NULL),(1433,2,1,29,4,'Second Year','2nd',1,29,'2026-06-04 17:08:57',NULL),(1434,2,1,30,NULL,'Second Year','2nd',1,30,'2026-06-04 17:08:57',NULL),(1435,2,1,31,21,'Second Year','2nd',1,31,'2026-06-04 17:08:57',NULL),(1436,2,1,32,24,'Second Year','2nd',1,32,'2026-06-04 17:08:57',NULL),(1437,2,1,33,25,'Third Year','1st',1,33,'2026-06-04 17:08:57',NULL),(1438,2,1,34,NULL,'Third Year','1st',1,34,'2026-06-04 17:08:57',NULL),(1439,2,1,35,NULL,'Third Year','1st',1,35,'2026-06-04 17:08:57',NULL),(1440,2,1,36,NULL,'Third Year','1st',1,36,'2026-06-04 17:08:57',NULL),(1441,2,1,37,NULL,'Third Year','1st',1,37,'2026-06-04 17:08:57',NULL),(1442,2,1,38,NULL,'Third Year','1st',1,38,'2026-06-04 17:08:57',NULL),(1443,2,1,39,NULL,'Third Year','1st',1,39,'2026-06-04 17:08:57',NULL),(1444,2,1,40,33,'Third Year','2nd',1,40,'2026-06-04 17:08:57',NULL),(1445,2,1,41,34,'Third Year','2nd',1,41,'2026-06-04 17:08:57',NULL),(1446,2,1,42,NULL,'Third Year','2nd',1,42,'2026-06-04 17:08:57',NULL),(1447,2,1,43,37,'Third Year','2nd',1,43,'2026-06-04 17:08:57',NULL),(1448,2,1,44,NULL,'Third Year','2nd',1,44,'2026-06-04 17:08:57',NULL),(1449,2,1,45,NULL,'Third Year','2nd',1,45,'2026-06-04 17:08:57',NULL),(1450,2,1,46,NULL,'Third Year','2nd',1,46,'2026-06-04 17:08:57',NULL),(1451,2,1,47,NULL,'Fourth Year','1st',1,47,'2026-06-04 17:08:57',NULL),(1452,2,1,48,NULL,'Fourth Year','1st',1,48,'2026-06-04 17:08:57',NULL),(1453,2,1,49,NULL,'Fourth Year','1st',1,49,'2026-06-04 17:08:57',NULL),(1454,2,1,50,NULL,'Fourth Year','1st',1,50,'2026-06-04 17:08:57',NULL),(1455,2,1,51,NULL,'Fourth Year','2nd',1,51,'2026-06-04 17:08:57',NULL),(1456,2,1,52,NULL,'Fourth Year','2nd',1,52,'2026-06-04 17:08:57',NULL),(1457,2,1,53,NULL,'Fourth Year','2nd',1,53,'2026-06-04 17:08:57',NULL),(1458,2,1,54,NULL,'Fourth Year','2nd',1,54,'2026-06-04 17:08:57',NULL),(72416,1249,1,40663,NULL,'Propaedeutic Stage','1st',1,1,'2026-10-14 16:18:16','2026-10-21 15:17:01'),(110704,1249,1,79060,NULL,'Propaedeutic Stage','1st',1,2,'2026-10-21 15:17:01',NULL),(110705,1249,1,79061,NULL,'Propaedeutic Stage','1st',1,3,'2026-10-21 15:17:01',NULL),(110706,1249,1,79062,NULL,'Propaedeutic Stage','1st',1,4,'2026-10-21 15:17:01',NULL),(110707,1249,1,79063,NULL,'Propaedeutic Stage','1st',1,5,'2026-10-21 15:17:01',NULL),(110708,1249,1,79064,NULL,'Propaedeutic Stage','1st',1,6,'2026-10-21 15:17:01','2026-11-19 15:21:12'),(110709,1249,1,79065,NULL,'Propaedeutic Stage','1st',1,7,'2026-10-21 15:17:01',NULL),(110710,1249,1,79066,NULL,'Propaedeutic Stage','1st',1,8,'2026-10-21 15:17:01',NULL),(110711,1249,1,79067,NULL,'Propaedeutic Stage','1st',1,9,'2026-10-21 15:17:01',NULL),(110712,1249,1,79068,NULL,'Propaedeutic Stage','1st',1,10,'2026-10-21 15:17:01',NULL),(110713,1249,1,79069,NULL,'Propaedeutic Stage','1st',1,11,'2026-10-21 15:17:01',NULL),(110714,1249,1,79070,NULL,'Propaedeutic Stage','1st',1,12,'2026-10-21 15:17:01',NULL),(110715,1249,1,79071,NULL,'Propaedeutic Stage','1st',1,13,'2026-10-21 15:17:01',NULL),(110716,1249,1,79072,NULL,'Propaedeutic Stage','1st',1,14,'2026-10-21 15:17:01',NULL),(110717,1249,1,79073,NULL,'Propaedeutic Stage','1st',1,15,'2026-10-21 15:17:01',NULL),(118746,2100,12,1,NULL,'First Year','1st',1,1,'2026-10-21 16:55:09',NULL),(118747,2100,12,2,NULL,'First Year','1st',1,2,'2026-10-21 16:55:09',NULL),(118748,2100,12,3,NULL,'First Year','1st',1,3,'2026-10-21 16:55:09',NULL),(118749,2100,12,4,NULL,'First Year','1st',1,4,'2026-10-21 16:55:09',NULL),(118750,2100,12,5,NULL,'First Year','1st',1,5,'2026-10-21 16:55:09',NULL),(118751,2100,12,6,NULL,'First Year','1st',1,6,'2026-10-21 16:55:09',NULL),(118752,2100,12,7,NULL,'First Year','1st',1,7,'2026-10-21 16:55:09',NULL),(118753,2100,12,8,NULL,'First Year','1st',1,8,'2026-10-21 16:55:09',NULL),(118754,2100,12,9,1,'First Year','2nd',1,9,'2026-10-21 16:55:09',NULL),(118755,2100,12,10,3,'First Year','2nd',1,10,'2026-10-21 16:55:09',NULL),(118756,2100,12,11,NULL,'First Year','2nd',1,11,'2026-10-21 16:55:09',NULL),(118757,2100,12,12,NULL,'First Year','2nd',1,12,'2026-10-21 16:55:09',NULL),(118758,2100,12,13,NULL,'First Year','2nd',1,13,'2026-10-21 16:55:09',NULL),(118759,2100,12,14,NULL,'First Year','2nd',1,14,'2026-10-21 16:55:09',NULL),(118760,2100,12,15,7,'First Year','2nd',1,15,'2026-10-21 16:55:09',NULL),(118761,2100,12,16,8,'First Year','2nd',1,16,'2026-10-21 16:55:09',NULL),(118762,2100,12,17,9,'Second Year','1st',1,17,'2026-10-21 16:55:09',NULL),(118763,2100,12,18,10,'Second Year','1st',1,18,'2026-10-21 16:55:09',NULL),(118764,2100,12,19,NULL,'Second Year','1st',1,19,'2026-10-21 16:55:09',NULL),(118765,2100,12,20,NULL,'Second Year','1st',1,20,'2026-10-21 16:55:09',NULL),(118766,2100,12,21,NULL,'Second Year','1st',1,21,'2026-10-21 16:55:09',NULL),(118767,2100,12,22,NULL,'Second Year','1st',1,22,'2026-10-21 16:55:09',NULL),(118768,2100,12,23,NULL,'Second Year','1st',1,23,'2026-10-21 16:55:09',NULL),(118769,2100,12,24,15,'Second Year','1st',1,24,'2026-10-21 16:55:09',NULL),(118770,2100,12,25,17,'Second Year','2nd',1,25,'2026-10-21 16:55:09',NULL),(118771,2100,12,26,18,'Second Year','2nd',1,26,'2026-10-21 16:55:09',NULL),(118772,2100,12,27,NULL,'Second Year','2nd',1,27,'2026-10-21 16:55:09',NULL),(118773,2100,12,28,NULL,'Second Year','2nd',1,28,'2026-10-21 16:55:09',NULL),(118774,2100,12,29,4,'Second Year','2nd',1,29,'2026-10-21 16:55:09',NULL),(118775,2100,12,30,NULL,'Second Year','2nd',1,30,'2026-10-21 16:55:09',NULL),(118776,2100,12,31,21,'Second Year','2nd',1,31,'2026-10-21 16:55:09',NULL),(118777,2100,12,32,24,'Second Year','2nd',1,32,'2026-10-21 16:55:09',NULL),(118778,2100,12,33,25,'Third Year','1st',1,33,'2026-10-21 16:55:09',NULL),(118779,2100,12,34,NULL,'Third Year','1st',1,34,'2026-10-21 16:55:09',NULL),(118780,2100,12,35,NULL,'Third Year','1st',1,35,'2026-10-21 16:55:09',NULL),(118781,2100,12,36,NULL,'Third Year','1st',1,36,'2026-10-21 16:55:09',NULL),(118782,2100,12,37,NULL,'Third Year','1st',1,37,'2026-10-21 16:55:09',NULL),(118783,2100,12,38,NULL,'Third Year','1st',1,38,'2026-10-21 16:55:09',NULL),(118784,2100,12,39,NULL,'Third Year','1st',1,39,'2026-10-21 16:55:09',NULL),(118785,2100,12,40,33,'Third Year','2nd',1,40,'2026-10-21 16:55:09',NULL),(118786,2100,12,41,34,'Third Year','2nd',1,41,'2026-10-21 16:55:09',NULL),(118787,2100,12,42,NULL,'Third Year','2nd',1,42,'2026-10-21 16:55:09',NULL),(118788,2100,12,43,37,'Third Year','2nd',1,43,'2026-10-21 16:55:09',NULL),(118789,2100,12,44,NULL,'Third Year','2nd',1,44,'2026-10-21 16:55:09',NULL),(118790,2100,12,45,NULL,'Third Year','2nd',1,45,'2026-10-21 16:55:09',NULL),(118791,2100,12,46,NULL,'Third Year','2nd',1,46,'2026-10-21 16:55:09',NULL),(118792,2100,12,47,NULL,'Fourth Year','1st',1,47,'2026-10-21 16:55:09',NULL),(118793,2100,12,48,NULL,'Fourth Year','1st',1,48,'2026-10-21 16:55:09',NULL),(118794,2100,12,49,NULL,'Fourth Year','1st',1,49,'2026-10-21 16:55:09',NULL),(118795,2100,12,50,NULL,'Fourth Year','1st',1,50,'2026-10-21 16:55:09',NULL),(118796,2100,12,51,NULL,'Fourth Year','2nd',1,51,'2026-10-21 16:55:09',NULL),(118797,2100,12,52,NULL,'Fourth Year','2nd',1,52,'2026-10-21 16:55:09',NULL),(118798,2100,12,53,NULL,'Fourth Year','2nd',1,53,'2026-10-21 16:55:09',NULL),(118799,2100,12,54,NULL,'Fourth Year','2nd',1,54,'2026-10-21 16:55:09',NULL),(119431,2102,11,1,NULL,'First Year','1st',1,1,'2026-10-21 17:00:20','2026-10-21 17:02:08'),(119432,2102,11,2,NULL,'First Year','1st',1,2,'2026-10-21 17:00:20',NULL),(119433,2102,11,3,NULL,'First Year','1st',1,3,'2026-10-21 17:00:20',NULL),(119434,2102,11,4,NULL,'First Year','1st',1,4,'2026-10-21 17:00:20',NULL),(119435,2102,11,5,NULL,'First Year','1st',1,5,'2026-10-21 17:00:20',NULL),(119436,2102,11,6,NULL,'First Year','1st',1,6,'2026-10-21 17:00:20',NULL),(119437,2102,11,7,NULL,'First Year','1st',1,7,'2026-10-21 17:00:20',NULL),(119438,2102,11,8,NULL,'First Year','1st',1,8,'2026-10-21 17:00:20',NULL),(119439,2102,11,9,1,'First Year','2nd',1,9,'2026-10-21 17:00:20',NULL),(119440,2102,11,10,3,'First Year','2nd',1,10,'2026-10-21 17:00:20',NULL),(119441,2102,11,11,NULL,'First Year','2nd',1,11,'2026-10-21 17:00:20',NULL),(119442,2102,11,12,NULL,'First Year','2nd',1,12,'2026-10-21 17:00:20',NULL),(119443,2102,11,13,NULL,'First Year','2nd',1,13,'2026-10-21 17:00:20',NULL),(119444,2102,11,14,NULL,'First Year','2nd',1,14,'2026-10-21 17:00:20',NULL),(119445,2102,11,15,7,'First Year','2nd',1,15,'2026-10-21 17:00:20',NULL),(119446,2102,11,16,8,'First Year','2nd',1,16,'2026-10-21 17:00:20',NULL),(119447,2102,11,17,9,'Second Year','1st',1,17,'2026-10-21 17:00:20',NULL),(119448,2102,11,18,10,'Second Year','1st',1,18,'2026-10-21 17:00:20',NULL),(119449,2102,11,19,NULL,'Second Year','1st',1,19,'2026-10-21 17:00:20',NULL),(119450,2102,11,20,NULL,'Second Year','1st',1,20,'2026-10-21 17:00:20',NULL),(119451,2102,11,21,NULL,'Second Year','1st',1,21,'2026-10-21 17:00:20',NULL),(119452,2102,11,22,NULL,'Second Year','1st',1,22,'2026-10-21 17:00:20',NULL),(119453,2102,11,23,NULL,'Second Year','1st',1,23,'2026-10-21 17:00:20',NULL),(119454,2102,11,24,15,'Second Year','1st',1,24,'2026-10-21 17:00:20',NULL),(119455,2102,11,25,17,'Second Year','2nd',1,25,'2026-10-21 17:00:20',NULL),(119456,2102,11,26,18,'Second Year','2nd',1,26,'2026-10-21 17:00:20',NULL),(119457,2102,11,27,NULL,'Second Year','2nd',1,27,'2026-10-21 17:00:20',NULL),(119458,2102,11,28,NULL,'Second Year','2nd',1,28,'2026-10-21 17:00:20',NULL),(119459,2102,11,29,4,'Second Year','2nd',1,29,'2026-10-21 17:00:20',NULL),(119460,2102,11,30,NULL,'Second Year','2nd',1,30,'2026-10-21 17:00:20',NULL),(119461,2102,11,31,21,'Second Year','2nd',1,31,'2026-10-21 17:00:20',NULL),(119462,2102,11,32,24,'Second Year','2nd',1,32,'2026-10-21 17:00:20',NULL),(119463,2102,11,33,25,'Third Year','1st',1,33,'2026-10-21 17:00:20',NULL),(119464,2102,11,34,NULL,'Third Year','1st',1,34,'2026-10-21 17:00:20',NULL),(119465,2102,11,35,NULL,'Third Year','1st',1,35,'2026-10-21 17:00:20',NULL),(119466,2102,11,36,NULL,'Third Year','1st',1,36,'2026-10-21 17:00:20',NULL),(119467,2102,11,37,NULL,'Third Year','1st',1,37,'2026-10-21 17:00:20',NULL),(119468,2102,11,38,NULL,'Third Year','1st',1,38,'2026-10-21 17:00:20',NULL),(119469,2102,11,39,NULL,'Third Year','1st',1,39,'2026-10-21 17:00:20',NULL),(119470,2102,11,40,33,'Third Year','2nd',1,40,'2026-10-21 17:00:20',NULL),(119471,2102,11,41,34,'Third Year','2nd',1,41,'2026-10-21 17:00:20',NULL),(119472,2102,11,42,NULL,'Third Year','2nd',1,42,'2026-10-21 17:00:20',NULL),(119473,2102,11,43,37,'Third Year','2nd',1,43,'2026-10-21 17:00:20',NULL),(119474,2102,11,44,NULL,'Third Year','2nd',1,44,'2026-10-21 17:00:20',NULL),(119475,2102,11,45,NULL,'Third Year','2nd',1,45,'2026-10-21 17:00:20',NULL),(119476,2102,11,46,NULL,'Third Year','2nd',1,46,'2026-10-21 17:00:20',NULL),(119477,2102,11,47,NULL,'Fourth Year','1st',1,47,'2026-10-21 17:00:20',NULL),(119478,2102,11,48,NULL,'Fourth Year','1st',1,48,'2026-10-21 17:00:20',NULL),(119479,2102,11,49,NULL,'Fourth Year','1st',1,49,'2026-10-21 17:00:20',NULL),(119480,2102,11,50,NULL,'Fourth Year','1st',1,50,'2026-10-21 17:00:20',NULL),(119481,2102,11,51,NULL,'Fourth Year','2nd',1,51,'2026-10-21 17:00:20',NULL),(119482,2102,11,52,NULL,'Fourth Year','2nd',1,52,'2026-10-21 17:00:20',NULL),(119483,2102,11,53,NULL,'Fourth Year','2nd',1,53,'2026-10-21 17:00:20',NULL),(119484,2102,11,54,NULL,'Fourth Year','2nd',1,54,'2026-10-21 17:00:20',NULL),(125152,1249,1,93382,40663,'Propaedeutic Stage','2nd',1,1,'2026-10-22 16:18:26',NULL),(125153,1249,1,93383,79060,'Propaedeutic Stage','2nd',1,2,'2026-10-22 16:18:26',NULL),(125154,1249,1,93384,79061,'Propaedeutic Stage','2nd',1,3,'2026-10-22 16:18:26',NULL),(125155,1249,1,93385,NULL,'Propaedeutic Stage','2nd',1,4,'2026-10-22 16:18:26',NULL),(125156,1249,1,93386,NULL,'Propaedeutic Stage','2nd',1,5,'2026-10-22 16:18:26',NULL),(125157,1249,1,93387,NULL,'Propaedeutic Stage','2nd',1,6,'2026-10-22 16:18:26',NULL),(125158,1249,1,93388,NULL,'Propaedeutic Stage','2nd',1,7,'2026-10-22 16:18:26',NULL),(125159,1249,1,93389,79070,'Propaedeutic Stage','2nd',1,8,'2026-10-22 16:18:26',NULL),(125160,1249,1,93390,79068,'Propaedeutic Stage','2nd',1,9,'2026-10-22 16:18:26',NULL),(125161,1249,1,93391,NULL,'Propaedeutic Stage','2nd',1,10,'2026-10-22 16:18:26',NULL),(125162,1249,1,93392,79071,'Propaedeutic Stage','2nd',1,11,'2026-10-22 16:18:26',NULL),(125163,1249,1,93393,79064,'Propaedeutic Stage','2nd',1,12,'2026-10-22 16:18:26',NULL),(125164,1249,1,93394,NULL,'Propaedeutic Stage','2nd',1,13,'2026-10-22 16:18:26',NULL),(125165,1249,1,93395,NULL,'Propaedeutic Stage','2nd',1,14,'2026-10-22 16:18:26',NULL),(125166,1249,1,93396,79073,'Propaedeutic Stage','2nd',1,15,'2026-10-22 16:18:26',NULL);
/*!40000 ALTER TABLE `curriculum_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curriculum_versions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `curriculum_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program` varchar(120) NOT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `version_name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_curriculum_version_ay` (`program`,`academic_year_id`,`version_name`),
  KEY `idx_curriculum_versions_program` (`program`,`is_current`,`archived_at`),
  KEY `idx_curriculum_versions_ay` (`academic_year_id`),
  CONSTRAINT `fk_curr_ver_ay` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curriculum_versions`
--

LOCK TABLES `curriculum_versions` WRITE;
/*!40000 ALTER TABLE `curriculum_versions` DISABLE KEYS */;
INSERT INTO `curriculum_versions` VALUES (1,'Bachelor of Arts in Philosophy',1,'AB Philosophy v1',1,1,'2026-06-04 17:02:10',NULL,NULL),(2,'Bachelor of Arts in Philosophy',1,'AB Philosophy v1 (Legacy 2)',0,0,'2026-06-04 17:08:04',NULL,'2026-06-04 17:09:10'),(3,'Propaedeutic',1,'Propaedeutic Legacy 3',0,0,'2026-06-04 17:56:14',NULL,'2026-10-14 15:53:43'),(1249,'Propaedeutic',1,'Propaedeutic v1',1,1,'2026-10-14 15:51:33',NULL,NULL),(2100,'Bachelor of Arts in Philosophy',12,'AB Philosophy (AY 2025-2026)',1,1,'2026-10-21 16:55:09',NULL,NULL),(2101,'Bachelor of Arts in Philosophy',1,'AB Philosophy v1 (Legacy 2101)',0,0,'2026-10-21 16:59:28',NULL,'2026-10-21 16:59:54'),(2102,'Bachelor of Arts in Philosophy',11,'AB Philosophy (AY 2027-2028)',1,1,'2026-10-21 17:00:20',NULL,NULL);
/*!40000 ALTER TABLE `curriculum_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollment_subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollment_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `units` decimal(4,1) NOT NULL DEFAULT 3.0,
  `final_grade` varchar(20) DEFAULT NULL,
  `subject_status` enum('Enrolled','Completed','Dropped','Failed','INC') NOT NULL DEFAULT 'Enrolled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enrollment_subject` (`enrollment_id`,`subject_id`),
  KEY `idx_es_subject` (`subject_id`),
  CONSTRAINT `fk_es_enrollment_id` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollment_subjects`
--

LOCK TABLES `enrollment_subjects` WRITE;
/*!40000 ALTER TABLE `enrollment_subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `enrollment_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL DEFAULT '1st',
  `program` varchar(100) NOT NULL DEFAULT 'Bachelor of Arts in Philosophy',
  `year_level` varchar(50) NOT NULL DEFAULT 'First Year',
  `curriculum_version_id` int(11) DEFAULT NULL,
  `academic_standing` enum('Regular','Irregular','Probationary') NOT NULL DEFAULT 'Regular',
  `enrollment_status` enum('Pending','Enrolled','Partially Paid','Dropped','Withdrawn','Completed','Graduated') NOT NULL DEFAULT 'Enrolled',
  `total_assessed_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fee_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `enrolled_by` int(11) DEFAULT NULL,
  `enrolled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_term` (`student_id`,`academic_year`,`semester`),
  KEY `idx_enrollments_term` (`academic_year`,`semester`,`enrollment_status`),
  KEY `idx_enrollments_student` (`student_id`),
  CONSTRAINT `fk_enrollments_student_id` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollments`
--

LOCK TABLES `enrollments` WRITE;
/*!40000 ALTER TABLE `enrollments` DISABLE KEYS */;
/*!40000 ALTER TABLE `enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `evaluations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) DEFAULT NULL,
  `type` enum('Spiritual','Human','Pastoral') DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `date` date DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seminarian_id` (`seminarian_id`),
  CONSTRAINT `evaluations_ibfk_1` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `evaluations`
--

LOCK TABLES `evaluations` WRITE;
/*!40000 ALTER TABLE `evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `evaluations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gpa_records`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gpa_records` (
  `gpa_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester_gpa` decimal(4,2) DEFAULT NULL,
  `cumulative_gpa` decimal(4,2) DEFAULT NULL,
  `performance_category` varchar(30) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`gpa_id`),
  UNIQUE KEY `uniq_gpa_record` (`student_id`,`semester`,`academic_year`),
  KEY `idx_gpa_records_student` (`student_id`),
  CONSTRAINT `fk_gpa_records_student` FOREIGN KEY (`student_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gpa_records`
--

LOCK TABLES `gpa_records` WRITE;
/*!40000 ALTER TABLE `gpa_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `gpa_records` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_gpa_records_after_update` AFTER UPDATE ON `gpa_records`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('gpa_records', OLD.`gpa_id`, 'UPDATE', JSON_OBJECT('gpa_id', OLD.`gpa_id`, 'student_id', OLD.`student_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'semester_gpa', OLD.`semester_gpa`, 'cumulative_gpa', OLD.`cumulative_gpa`, 'performance_category', OLD.`performance_category`, 'computed_at', OLD.`computed_at`), JSON_OBJECT('gpa_id', NEW.`gpa_id`, 'student_id', NEW.`student_id`, 'semester', NEW.`semester`, 'academic_year', NEW.`academic_year`, 'semester_gpa', NEW.`semester_gpa`, 'cumulative_gpa', NEW.`cumulative_gpa`, 'performance_category', NEW.`performance_category`, 'computed_at', NEW.`computed_at`), LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Sem GPA: ', COALESCE(CAST(OLD.semester_gpa AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_gpa_records_before_delete` BEFORE DELETE ON `gpa_records`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('gpa_records', OLD.`gpa_id`, 'DELETE', JSON_OBJECT('gpa_id', OLD.`gpa_id`, 'student_id', OLD.`student_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'semester_gpa', OLD.`semester_gpa`, 'cumulative_gpa', OLD.`cumulative_gpa`, 'performance_category', OLD.`performance_category`, 'computed_at', OLD.`computed_at`), NULL, LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Sem GPA: ', COALESCE(CAST(OLD.semester_gpa AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `grade_edit_requests`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grade_edit_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `requested_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ger_status` (`status`),
  KEY `idx_ger_term` (`academic_year`,`semester`),
  KEY `idx_ger_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grade_edit_requests`
--

LOCK TABLES `grade_edit_requests` WRITE;
/*!40000 ALTER TABLE `grade_edit_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `grade_edit_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grades`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `units` decimal(3,1) NOT NULL DEFAULT 0.0,
  `grade` decimal(5,2) DEFAULT NULL,
  `grade_percent` decimal(5,2) DEFAULT NULL,
  `term` varchar(20) DEFAULT NULL,
  `school_year` varchar(9) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `assessed_by` int(11) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seminarian_id` (`seminarian_id`),
  CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grades`
--

LOCK TABLES `grades` WRITE;
/*!40000 ALTER TABLE `grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `grades` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_grades_after_update` AFTER UPDATE ON `grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('grades', OLD.`id`, 'UPDATE', JSON_OBJECT('id', OLD.`id`, 'seminarian_id', OLD.`seminarian_id`, 'subject', OLD.`subject`, 'units', OLD.`units`, 'grade', OLD.`grade`, 'grade_percent', OLD.`grade_percent`, 'term', OLD.`term`, 'school_year', OLD.`school_year`, 'remarks', OLD.`remarks`, 'assessed_by', OLD.`assessed_by`, 'date', OLD.`date`, 'archived_at', OLD.`archived_at`), JSON_OBJECT('id', NEW.`id`, 'seminarian_id', NEW.`seminarian_id`, 'subject', NEW.`subject`, 'units', NEW.`units`, 'grade', NEW.`grade`, 'grade_percent', NEW.`grade_percent`, 'term', NEW.`term`, 'school_year', NEW.`school_year`, 'remarks', NEW.`remarks`, 'assessed_by', NEW.`assessed_by`, 'date', NEW.`date`, 'archived_at', NEW.`archived_at`), LEFT(CONCAT('Seminarian #', COALESCE(CAST(OLD.seminarian_id AS CHAR), '?'), ' · ', COALESCE(OLD.subject, 'Subject'), ' Grade: ', COALESCE(CAST(OLD.grade AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_grades_before_delete` BEFORE DELETE ON `grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('grades', OLD.`id`, 'DELETE', JSON_OBJECT('id', OLD.`id`, 'seminarian_id', OLD.`seminarian_id`, 'subject', OLD.`subject`, 'units', OLD.`units`, 'grade', OLD.`grade`, 'grade_percent', OLD.`grade_percent`, 'term', OLD.`term`, 'school_year', OLD.`school_year`, 'remarks', OLD.`remarks`, 'assessed_by', OLD.`assessed_by`, 'date', OLD.`date`, 'archived_at', OLD.`archived_at`), NULL, LEFT(CONCAT('Seminarian #', COALESCE(CAST(OLD.seminarian_id AS CHAR), '?'), ' · ', COALESCE(OLD.subject, 'Subject'), ' Grade: ', COALESCE(CAST(OLD.grade AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `history_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `history_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(64) NOT NULL,
  `record_id` bigint(20) DEFAULT NULL,
  `action_type` enum('UPDATE','DELETE') NOT NULL,
  `old_data` longtext NOT NULL,
  `new_data` longtext DEFAULT NULL,
  `summary` varchar(255) DEFAULT NULL,
  `changed_by` varchar(100) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_history_table_record` (`table_name`,`record_id`),
  KEY `idx_history_action` (`action_type`),
  KEY `idx_history_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `history_logs`
--

LOCK TABLES `history_logs` WRITE;
/*!40000 ALTER TABLE `history_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `history_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `human_analytics`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `human_analytics` (
  `analytics_id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `previous_score` decimal(4,2) DEFAULT NULL,
  `current_score` decimal(4,2) DEFAULT NULL,
  `growth_status` enum('Improving','Stable','Declining') DEFAULT NULL,
  `growth_percentage` decimal(6,2) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`analytics_id`),
  UNIQUE KEY `uniq_ha_eval` (`evaluation_id`),
  KEY `idx_ha_student` (`seminarian_id`),
  CONSTRAINT `fk_ha_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `human_evaluations` (`evaluation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ha_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `human_analytics`
--

LOCK TABLES `human_analytics` WRITE;
/*!40000 ALTER TABLE `human_analytics` DISABLE KEYS */;
/*!40000 ALTER TABLE `human_analytics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `human_evaluations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `human_evaluations` (
  `evaluation_id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `formator_id` int(11) NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `evaluation_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`evaluation_id`),
  KEY `idx_he_student` (`seminarian_id`),
  KEY `idx_he_term` (`academic_year`,`semester`),
  KEY `idx_he_formator` (`formator_id`),
  CONSTRAINT `fk_he_formator` FOREIGN KEY (`formator_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_he_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `human_evaluations`
--

LOCK TABLES `human_evaluations` WRITE;
/*!40000 ALTER TABLE `human_evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `human_evaluations` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_human_evaluations_after_update` AFTER UPDATE ON `human_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('human_evaluations', OLD.`evaluation_id`, 'UPDATE', JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), JSON_OBJECT('evaluation_id', NEW.`evaluation_id`, 'seminarian_id', NEW.`seminarian_id`, 'formator_id', NEW.`formator_id`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`), LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_human_evaluations_before_delete` BEFORE DELETE ON `human_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('human_evaluations', OLD.`evaluation_id`, 'DELETE', JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), NULL, LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `human_questions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `human_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(80) NOT NULL,
  `question_text` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`question_id`),
  KEY `idx_hq_active` (`is_active`),
  KEY `idx_hq_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `human_questions`
--

LOCK TABLES `human_questions` WRITE;
/*!40000 ALTER TABLE `human_questions` DISABLE KEYS */;
INSERT INTO `human_questions` VALUES (1,'Personal Responsibility','Demonstrates accountability for his actions and decisions.',1,1,'2026-06-06 08:18:55',NULL),(2,'Personal Responsibility','Completes assigned responsibilities on time.',2,1,'2026-06-06 08:18:55',NULL),(3,'Personal Responsibility','Manages personal tasks without constant supervision.',3,1,'2026-06-06 08:18:55',NULL),(4,'Personal Responsibility','Shows initiative in carrying out assigned duties.',4,1,'2026-06-06 08:18:55',NULL),(5,'Personal Responsibility','Accepts correction with humility and openness.',5,1,'2026-06-06 08:18:55',NULL),(6,'Personal Responsibility','Demonstrates honesty in reporting accomplishments and failures.',6,1,'2026-06-06 08:18:55',NULL),(7,'Moral Character and Integrity','Acts according to moral and ethical principles.',7,1,'2026-06-06 08:18:55',NULL),(8,'Moral Character and Integrity','Demonstrates honesty in daily interactions.',8,1,'2026-06-06 08:18:55',NULL),(9,'Moral Character and Integrity','Shows consistency between words and actions.',9,1,'2026-06-06 08:18:55',NULL),(10,'Moral Character and Integrity','Respects institutional rules and policies.',10,1,'2026-06-06 08:18:55',NULL),(11,'Moral Character and Integrity','Avoids behaviors that may cause scandal or conflict.',11,1,'2026-06-06 08:18:55',NULL),(12,'Moral Character and Integrity','Displays integrity even when not being observed.',12,1,'2026-06-06 08:18:55',NULL),(13,'Emotional Maturity','Maintains emotional stability under pressure.',13,1,'2026-06-06 08:18:55',NULL),(14,'Emotional Maturity','Responds constructively to criticism.',14,1,'2026-06-06 08:18:55',NULL),(15,'Emotional Maturity','Handles disappointments appropriately.',15,1,'2026-06-06 08:18:55',NULL),(16,'Emotional Maturity','Demonstrates patience in challenging situations.',16,1,'2026-06-06 08:18:55',NULL),(17,'Emotional Maturity','Shows self-control during conflicts.',17,1,'2026-06-06 08:18:55',NULL),(18,'Emotional Maturity','Displays healthy emotional awareness and expression.',18,1,'2026-06-06 08:18:55',NULL),(19,'Interpersonal Relationships','Treats others with respect and dignity.',19,1,'2026-06-06 08:18:55',NULL),(20,'Interpersonal Relationships','Works effectively with fellow seminarians.',20,1,'2026-06-06 08:18:55',NULL),(21,'Interpersonal Relationships','Demonstrates empathy toward others.',21,1,'2026-06-06 08:18:55',NULL),(22,'Interpersonal Relationships','Resolves conflicts peacefully and maturely.',22,1,'2026-06-06 08:18:55',NULL),(23,'Interpersonal Relationships','Maintains healthy relationships within the seminary community.',23,1,'2026-06-06 08:18:55',NULL),(24,'Interpersonal Relationships','Communicates respectfully with superiors, peers, and staff.',24,1,'2026-06-06 08:18:55',NULL),(25,'Leadership and Community Participation','Demonstrates willingness to serve others.',25,1,'2026-06-06 08:18:55',NULL),(26,'Leadership and Community Participation','Participates actively in community activities.',26,1,'2026-06-06 08:18:55',NULL),(27,'Leadership and Community Participation','Shows leadership when opportunities arise.',27,1,'2026-06-06 08:18:55',NULL),(28,'Leadership and Community Participation','Encourages unity and cooperation among peers.',28,1,'2026-06-06 08:18:55',NULL),(29,'Leadership and Community Participation','Contributes positively to the seminary environment.',29,1,'2026-06-06 08:18:55',NULL),(30,'Leadership and Community Participation','Serves as a good example to fellow seminarians.',30,1,'2026-06-06 08:18:55',NULL);
/*!40000 ALTER TABLE `human_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `human_remarks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `human_remarks` (
  `remark_id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `strengths` text DEFAULT NULL,
  `weaknesses` text DEFAULT NULL,
  `behavioral_concerns` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `follow_up_actions` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`remark_id`),
  UNIQUE KEY `uniq_hrem_eval` (`evaluation_id`),
  CONSTRAINT `fk_hrem_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `human_evaluations` (`evaluation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `human_remarks`
--

LOCK TABLES `human_remarks` WRITE;
/*!40000 ALTER TABLE `human_remarks` DISABLE KEYS */;
/*!40000 ALTER TABLE `human_remarks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `human_responses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `human_responses` (
  `response_id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  PRIMARY KEY (`response_id`),
  UNIQUE KEY `uniq_hresp` (`evaluation_id`,`question_id`),
  KEY `idx_hresp_eval` (`evaluation_id`),
  KEY `idx_hresp_q` (`question_id`),
  CONSTRAINT `fk_hresp_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `human_evaluations` (`evaluation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hresp_q` FOREIGN KEY (`question_id`) REFERENCES `human_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `human_responses`
--

LOCK TABLES `human_responses` WRITE;
/*!40000 ALTER TABLE `human_responses` DISABLE KEYS */;
/*!40000 ALTER TABLE `human_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `human_results`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `human_results` (
  `evaluation_id` int(11) NOT NULL,
  `overall_score` int(11) NOT NULL,
  `average_rating` decimal(4,2) NOT NULL,
  `interpretation` varchar(60) NOT NULL,
  `responsibility_avg` decimal(4,2) DEFAULT NULL,
  `integrity_avg` decimal(4,2) DEFAULT NULL,
  `emotional_maturity_avg` decimal(4,2) DEFAULT NULL,
  `interpersonal_avg` decimal(4,2) DEFAULT NULL,
  `leadership_avg` decimal(4,2) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`evaluation_id`),
  CONSTRAINT `fk_hres_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `human_evaluations` (`evaluation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `human_results`
--

LOCK TABLES `human_results` WRITE;
/*!40000 ALTER TABLE `human_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `human_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `irregular_students`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `irregular_students` (
  `irregular_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `semester` enum('1st','2nd','Summer') DEFAULT NULL,
  `academic_year` varchar(9) DEFAULT NULL,
  `reason` text NOT NULL,
  `date_flagged` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Active','Resolved') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`irregular_id`),
  UNIQUE KEY `uniq_irregular_term` (`student_id`,`semester`,`academic_year`),
  KEY `idx_irregular_student` (`student_id`),
  KEY `idx_irregular_status` (`status`),
  CONSTRAINT `fk_irregular_student` FOREIGN KEY (`student_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `irregular_students`
--

LOCK TABLES `irregular_students` WRITE;
/*!40000 ALTER TABLE `irregular_students` DISABLE KEYS */;
/*!40000 ALTER TABLE `irregular_students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lodging_invoice_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lodging_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lodging_invoice_items_invoice` (`invoice_id`),
  CONSTRAINT `fk_lodging_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `lodging_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lodging_invoice_items`
--

LOCK TABLES `lodging_invoice_items` WRITE;
/*!40000 ALTER TABLE `lodging_invoice_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `lodging_invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lodging_invoices`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lodging_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `invoice_no` varchar(30) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `academic_year` varchar(9) DEFAULT NULL,
  `semester` enum('1st','2nd') DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `program` varchar(80) DEFAULT NULL,
  `invoice_type` varchar(40) DEFAULT NULL,
  `fee_schedule_id` int(11) DEFAULT NULL,
  `status` enum('draft','issued','partially_paid','paid','void') NOT NULL DEFAULT 'issued',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lodging_invoice_no` (`invoice_no`),
  KEY `idx_lodging_invoices_seminarian` (`seminarian_id`),
  KEY `idx_lodging_invoices_issue_date` (`issue_date`),
  KEY `fk_lodging_invoices_created_by` (`created_by`),
  KEY `idx_lodging_invoices_academic_year` (`academic_year`),
  KEY `idx_inv_fee_sched` (`fee_schedule_id`),
  CONSTRAINT `fk_lodging_invoices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_lodging_invoices_seminarian` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lodging_invoices`
--

LOCK TABLES `lodging_invoices` WRITE;
/*!40000 ALTER TABLE `lodging_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `lodging_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lodging_payment_allocations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lodging_payment_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lodging_payment_invoice` (`payment_id`,`invoice_id`),
  KEY `idx_lodging_alloc_payment` (`payment_id`),
  KEY `idx_lodging_alloc_invoice` (`invoice_id`),
  CONSTRAINT `fk_lodging_alloc_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `lodging_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lodging_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `lodging_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lodging_payment_allocations`
--

LOCK TABLES `lodging_payment_allocations` WRITE;
/*!40000 ALTER TABLE `lodging_payment_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `lodging_payment_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lodging_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lodging_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `or_number` varchar(50) DEFAULT NULL,
  `payer_type` varchar(40) DEFAULT NULL,
  `payer_name` varchar(120) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `seminarian_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `academic_year` varchar(9) DEFAULT NULL,
  `semester` enum('1st','2nd') DEFAULT NULL,
  `previous_balance` decimal(10,2) DEFAULT NULL,
  `remaining_balance` decimal(10,2) DEFAULT NULL,
  `overpayment` decimal(10,2) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `amount_tendered` decimal(10,2) DEFAULT NULL,
  `method` enum('cash','bank_deposit','online_transfer','bank','gcash','check','other') NOT NULL DEFAULT 'cash',
  `reference_no` varchar(80) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lodging_payments_or_number` (`or_number`),
  KEY `idx_lodging_payments_seminarian` (`seminarian_id`),
  KEY `idx_lodging_payments_payment_date` (`payment_date`),
  KEY `fk_lodging_payments_received_by` (`received_by`),
  KEY `idx_lodging_payments_academic_year` (`academic_year`),
  CONSTRAINT `fk_lodging_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_lodging_payments_seminarian` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lodging_payments`
--

LOCK TABLES `lodging_payments` WRITE;
/*!40000 ALTER TABLE `lodging_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `lodging_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lodging_rooms`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lodging_rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 1,
  `monthly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lodging_rooms`
--

LOCK TABLES `lodging_rooms` WRITE;
/*!40000 ALTER TABLE `lodging_rooms` DISABLE KEYS */;
INSERT INTO `lodging_rooms` VALUES (1,'Dormitory',1,0.00,1,'2026-06-06 08:24:35');
/*!40000 ALTER TABLE `lodging_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lodging_stays`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lodging_stays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lodging_stays_seminarian` (`seminarian_id`),
  KEY `idx_lodging_stays_room` (`room_id`),
  CONSTRAINT `fk_lodging_stays_room` FOREIGN KEY (`room_id`) REFERENCES `lodging_rooms` (`id`),
  CONSTRAINT `fk_lodging_stays_seminarian` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lodging_stays`
--

LOCK TABLES `lodging_stays` WRITE;
/*!40000 ALTER TABLE `lodging_stays` DISABLE KEYS */;
/*!40000 ALTER TABLE `lodging_stays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(120) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_user_ip` (`username`,`ip_address`,`attempted_at`),
  KEY `idx_login_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pastoral_analytics`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pastoral_analytics` (
  `analytics_id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `previous_score` decimal(4,2) DEFAULT NULL,
  `current_score` decimal(4,2) DEFAULT NULL,
  `growth_status` enum('Growing','Stable','Declining') DEFAULT NULL,
  `growth_percentage` decimal(6,2) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`analytics_id`),
  UNIQUE KEY `uniq_pa_eval` (`evaluation_id`),
  KEY `idx_pa_student` (`seminarian_id`),
  CONSTRAINT `fk_pa_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `pastoral_evaluations` (`evaluation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pastoral_analytics`
--

LOCK TABLES `pastoral_analytics` WRITE;
/*!40000 ALTER TABLE `pastoral_analytics` DISABLE KEYS */;
/*!40000 ALTER TABLE `pastoral_analytics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pastoral_evaluations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pastoral_evaluations` (
  `evaluation_id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `formator_id` int(11) NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `evaluation_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`evaluation_id`),
  KEY `idx_pe_student` (`seminarian_id`),
  KEY `idx_pe_term` (`academic_year`,`semester`),
  KEY `idx_pe_formator` (`formator_id`),
  CONSTRAINT `fk_pe_formator` FOREIGN KEY (`formator_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_pe_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pastoral_evaluations`
--

LOCK TABLES `pastoral_evaluations` WRITE;
/*!40000 ALTER TABLE `pastoral_evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `pastoral_evaluations` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_pastoral_evaluations_after_update` AFTER UPDATE ON `pastoral_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('pastoral_evaluations', OLD.`evaluation_id`, 'UPDATE', JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), JSON_OBJECT('evaluation_id', NEW.`evaluation_id`, 'seminarian_id', NEW.`seminarian_id`, 'formator_id', NEW.`formator_id`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`), LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_pastoral_evaluations_before_delete` BEFORE DELETE ON `pastoral_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('pastoral_evaluations', OLD.`evaluation_id`, 'DELETE', JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), NULL, LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `pastoral_questions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pastoral_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(80) NOT NULL,
  `question_text` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`question_id`),
  KEY `idx_pq_active` (`is_active`),
  KEY `idx_pq_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pastoral_questions`
--

LOCK TABLES `pastoral_questions` WRITE;
/*!40000 ALTER TABLE `pastoral_questions` DISABLE KEYS */;
INSERT INTO `pastoral_questions` VALUES (1,'Pastoral Ministry and Service','Demonstrates dedication in assigned pastoral ministries.',1,1,'2026-06-06 08:18:55',NULL),(2,'Pastoral Ministry and Service','Performs pastoral responsibilities faithfully.',2,1,'2026-06-06 08:18:55',NULL),(3,'Pastoral Ministry and Service','Shows concern for the spiritual welfare of others.',3,1,'2026-06-06 08:18:55',NULL),(4,'Pastoral Ministry and Service','Participates actively in ministry activities.',4,1,'2026-06-06 08:18:55',NULL),(5,'Pastoral Ministry and Service','Displays initiative in serving communities.',5,1,'2026-06-06 08:18:55',NULL),(6,'Pastoral Ministry and Service','Demonstrates commitment to pastoral assignments.',6,1,'2026-06-06 08:18:55',NULL),(7,'Leadership and Responsibility','Accepts leadership responsibilities willingly.',7,1,'2026-06-06 08:18:55',NULL),(8,'Leadership and Responsibility','Demonstrates accountability in ministry work.',8,1,'2026-06-06 08:18:55',NULL),(9,'Leadership and Responsibility','Makes sound decisions during pastoral activities.',9,1,'2026-06-06 08:18:55',NULL),(10,'Leadership and Responsibility','Inspires cooperation among peers.',10,1,'2026-06-06 08:18:55',NULL),(11,'Leadership and Responsibility','Organizes activities effectively.',11,1,'2026-06-06 08:18:55',NULL),(12,'Leadership and Responsibility','Serves as a positive role model in ministry.',12,1,'2026-06-06 08:18:55',NULL),(13,'Communication and Interpersonal Skills','Communicates clearly and respectfully.',13,1,'2026-06-06 08:18:55',NULL),(14,'Communication and Interpersonal Skills','Listens attentively to others.',14,1,'2026-06-06 08:18:55',NULL),(15,'Communication and Interpersonal Skills','Demonstrates empathy when interacting with people.',15,1,'2026-06-06 08:18:55',NULL),(16,'Communication and Interpersonal Skills','Handles conflicts constructively.',16,1,'2026-06-06 08:18:55',NULL),(17,'Communication and Interpersonal Skills','Shows confidence in public speaking situations.',17,1,'2026-06-06 08:18:55',NULL),(18,'Communication and Interpersonal Skills','Establishes healthy relationships with parishioners and community members.',18,1,'2026-06-06 08:18:55',NULL),(19,'Community Engagement and Collaboration','Participates actively in community outreach programs.',19,1,'2026-06-06 08:18:55',NULL),(20,'Community Engagement and Collaboration','Works effectively with teams and ministry groups.',20,1,'2026-06-06 08:18:55',NULL),(21,'Community Engagement and Collaboration','Demonstrates respect for diverse backgrounds and experiences.',21,1,'2026-06-06 08:18:55',NULL),(22,'Community Engagement and Collaboration','Supports fellow seminarians in ministry activities.',22,1,'2026-06-06 08:18:55',NULL),(23,'Community Engagement and Collaboration','Contributes positively to community-building efforts.',23,1,'2026-06-06 08:18:55',NULL),(24,'Community Engagement and Collaboration','Cooperates with formators and pastoral supervisors.',24,1,'2026-06-06 08:18:55',NULL),(25,'Apostolic Zeal and Mission Awareness','Demonstrates enthusiasm for evangelization.',25,1,'2026-06-06 08:18:55',NULL),(26,'Apostolic Zeal and Mission Awareness','Shows willingness to serve marginalized communities.',26,1,'2026-06-06 08:18:55',NULL),(27,'Apostolic Zeal and Mission Awareness','Displays genuine concern for the needs of others.',27,1,'2026-06-06 08:18:55',NULL),(28,'Apostolic Zeal and Mission Awareness','Promotes the mission of the Church through actions and words.',28,1,'2026-06-06 08:18:55',NULL),(29,'Apostolic Zeal and Mission Awareness','Demonstrates perseverance in ministry challenges.',29,1,'2026-06-06 08:18:55',NULL),(30,'Apostolic Zeal and Mission Awareness','Shows commitment to ongoing pastoral growth and development.',30,1,'2026-06-06 08:18:55',NULL);
/*!40000 ALTER TABLE `pastoral_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pastoral_remarks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pastoral_remarks` (
  `remark_id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `strengths` text DEFAULT NULL,
  `areas_for_improvement` text DEFAULT NULL,
  `challenges` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `ministry_development_plan` text DEFAULT NULL,
  `follow_up_actions` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`remark_id`),
  UNIQUE KEY `uniq_prem_eval` (`evaluation_id`),
  CONSTRAINT `fk_prem_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `pastoral_evaluations` (`evaluation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pastoral_remarks`
--

LOCK TABLES `pastoral_remarks` WRITE;
/*!40000 ALTER TABLE `pastoral_remarks` DISABLE KEYS */;
/*!40000 ALTER TABLE `pastoral_remarks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pastoral_responses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pastoral_responses` (
  `response_id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  PRIMARY KEY (`response_id`),
  UNIQUE KEY `uniq_presp` (`evaluation_id`,`question_id`),
  KEY `idx_presp_eval` (`evaluation_id`),
  KEY `idx_presp_q` (`question_id`),
  CONSTRAINT `fk_presp_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `pastoral_evaluations` (`evaluation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_presp_q` FOREIGN KEY (`question_id`) REFERENCES `pastoral_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pastoral_responses`
--

LOCK TABLES `pastoral_responses` WRITE;
/*!40000 ALTER TABLE `pastoral_responses` DISABLE KEYS */;
/*!40000 ALTER TABLE `pastoral_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pastoral_results`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pastoral_results` (
  `evaluation_id` int(11) NOT NULL,
  `total_score` int(11) NOT NULL,
  `average_rating` decimal(4,2) NOT NULL,
  `interpretation` varchar(60) NOT NULL,
  `pastoral_ministry_avg` decimal(4,2) DEFAULT NULL,
  `leadership_avg` decimal(4,2) DEFAULT NULL,
  `communication_avg` decimal(4,2) DEFAULT NULL,
  `community_engagement_avg` decimal(4,2) DEFAULT NULL,
  `apostolic_zeal_avg` decimal(4,2) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`evaluation_id`),
  CONSTRAINT `fk_pres_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `pastoral_evaluations` (`evaluation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pastoral_results`
--

LOCK TABLES `pastoral_results` WRITE;
/*!40000 ALTER TABLE `pastoral_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `pastoral_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schema_migrations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `version` varchar(100) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES ('2026_04_18_lodging_billing','2026-06-05 10:29:07'),('2026_04_20_applicants_user_id','2026-06-05 10:29:07'),('2026_04_20_user_profiles','2026-06-05 10:29:07'),('2026_09_28_module2_academics','2026-10-06 22:07:50'),('2026_09_28_timetable_calendar_fields','2026-09-28 21:54:43'),('2026_10_06_module3_cashier_payments','2026-10-06 23:54:41'),('2026_10_11_applicants_program_created_at','2026-10-11 15:17:51');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `semester_subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `semester_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `formation_stage` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_semester_subject` (`subject_id`,`semester`,`academic_year`,`year_level`),
  KEY `idx_semester_subjects_year_level` (`year_level`),
  KEY `idx_semester_subjects_ay_sem` (`academic_year`,`semester`),
  CONSTRAINT `fk_semester_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=268 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `semester_subjects`
--

LOCK TABLES `semester_subjects` WRITE;
/*!40000 ALTER TABLE `semester_subjects` DISABLE KEYS */;
INSERT INTO `semester_subjects` VALUES (1,1,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(2,2,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(3,3,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(4,4,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(5,5,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(6,6,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(7,7,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(8,8,'1st','2026-2027','First Year',NULL,'2026-06-06 08:46:35'),(9,9,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(10,10,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(11,11,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(12,12,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(13,13,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(14,14,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(15,15,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(16,16,'2nd','2026-2027','First Year',NULL,'2026-06-06 09:24:28'),(50,21,'1st','2026-2027','First Year',NULL,'2026-10-21 12:30:57'),(59,17,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(60,18,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(61,19,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(62,20,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(63,21,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(64,22,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(65,23,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(66,24,'1st','2026-2027','Second Year',NULL,'2026-10-21 13:25:54'),(67,40663,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(68,79060,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(69,79061,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(70,79062,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(71,79063,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(72,79064,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(73,79065,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(74,79066,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(75,79067,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(76,79068,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(77,79069,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(78,79070,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(79,79071,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(80,79072,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(81,79073,'1st','2026-2027','Propaedeutic Stage',NULL,'2026-10-21 15:17:01'),(97,1,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(98,2,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(99,3,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(100,4,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(101,5,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(102,6,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(103,7,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(104,8,'1st','2027-2028','First Year',NULL,'2026-10-21 17:34:14'),(113,79060,'1st','2026-2027','First Year',NULL,'2026-10-21 17:35:53'),(114,79060,'1st','2027-2028','First Year',NULL,'2026-10-21 17:44:13'),(145,93382,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(146,93383,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(147,93384,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(148,93385,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(149,93386,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(150,93387,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(151,93388,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(152,93389,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(153,93390,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(154,93391,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(155,93392,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(156,93393,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(157,93394,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(158,93395,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(159,93396,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:08:09'),(175,79060,'2nd','2026-2027','Propaedeutic Stage',NULL,'2026-10-22 17:35:01');
/*!40000 ALTER TABLE `semester_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seminarian_access_requests`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seminarian_access_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `assignment_type` varchar(30) NOT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `message` varchar(255) DEFAULT NULL,
  `request_scope` varchar(20) NOT NULL DEFAULT 'whole',
  `requested_subjects` text DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sar_student` (`seminarian_id`),
  KEY `idx_sar_type_status` (`assignment_type`,`status`),
  KEY `idx_sar_assignee_status` (`assigned_user_id`,`status`),
  KEY `fk_sar_req_by` (`requested_by`),
  KEY `fk_sar_res_by` (`resolved_by`),
  CONSTRAINT `fk_sar_assignee` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sar_req_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sar_res_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sar_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seminarian_access_requests`
--

LOCK TABLES `seminarian_access_requests` WRITE;
/*!40000 ALTER TABLE `seminarian_access_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `seminarian_access_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seminarian_assignments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seminarian_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `assignment_type` varchar(30) NOT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_assignment` (`seminarian_id`,`assignment_type`),
  KEY `idx_assignment_user` (`assigned_user_id`),
  KEY `idx_assignment_type` (`assignment_type`,`active`),
  KEY `fk_sa_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_sa_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_seminarian` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seminarian_assignments`
--

LOCK TABLES `seminarian_assignments` WRITE;
/*!40000 ALTER TABLE `seminarian_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `seminarian_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seminarian_documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seminarian_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `document_type` varchar(100) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(10) unsigned DEFAULT 0,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_seminarian_id` (`seminarian_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seminarian_documents`
--

LOCK TABLES `seminarian_documents` WRITE;
/*!40000 ALTER TABLE `seminarian_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `seminarian_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seminarians`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seminarians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `current_academic_year` varchar(9) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `program` varchar(80) DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `formation_stage` varchar(50) DEFAULT NULL,
  `academic_status` enum('Regular','Irregular') NOT NULL DEFAULT 'Regular',
  `enrollment_status` varchar(30) DEFAULT NULL,
  `formation_status` varchar(30) DEFAULT NULL,
  `is_graduate` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` date DEFAULT NULL,
  `academic_year_completed` varchar(9) DEFAULT NULL,
  `program_completed` varchar(80) DEFAULT NULL,
  `academic_remarks` text DEFAULT NULL,
  `academic_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `spiritual_eval_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `human_eval_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pastoral_eval_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `profile_picture` varchar(255) DEFAULT NULL,
  `last_profile_update` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `seminarians_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seminarians`
--

LOCK TABLES `seminarians` WRITE;
/*!40000 ALTER TABLE `seminarians` DISABLE KEYS */;
/*!40000 ALTER TABLE `seminarians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seminarians_coupled_backup`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seminarians_coupled_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `current_academic_year` varchar(9) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `program` varchar(80) DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `formation_stage` varchar(50) DEFAULT NULL,
  `academic_status` enum('Regular','Irregular') NOT NULL DEFAULT 'Regular',
  `enrollment_status` varchar(30) DEFAULT NULL,
  `formation_status` varchar(30) DEFAULT NULL,
  `is_graduate` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` date DEFAULT NULL,
  `academic_year_completed` varchar(9) DEFAULT NULL,
  `program_completed` varchar(80) DEFAULT NULL,
  `academic_remarks` text DEFAULT NULL,
  `academic_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `spiritual_eval_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `human_eval_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pastoral_eval_view_enabled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seminarians_coupled_backup`
--

LOCK TABLES `seminarians_coupled_backup` WRITE;
/*!40000 ALTER TABLE `seminarians_coupled_backup` DISABLE KEYS */;
/*!40000 ALTER TABLE `seminarians_coupled_backup` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spiritual_analytics`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spiritual_analytics` (
  `analytics_id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `previous_score` decimal(4,2) DEFAULT NULL,
  `current_score` decimal(4,2) DEFAULT NULL,
  `growth_status` enum('Growing','Stable','At Risk') DEFAULT NULL,
  `growth_percentage` decimal(6,2) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`analytics_id`),
  UNIQUE KEY `uniq_sa_eval` (`evaluation_id`),
  KEY `idx_sa_student` (`seminarian_id`),
  CONSTRAINT `fk_sa_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `spiritual_evaluations` (`evaluation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sa_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spiritual_analytics`
--

LOCK TABLES `spiritual_analytics` WRITE;
/*!40000 ALTER TABLE `spiritual_analytics` DISABLE KEYS */;
/*!40000 ALTER TABLE `spiritual_analytics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spiritual_evaluations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spiritual_evaluations` (
  `evaluation_id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `spiritual_director_id` int(11) NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `evaluation_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`evaluation_id`),
  KEY `idx_se_student` (`seminarian_id`),
  KEY `idx_se_term` (`academic_year`,`semester`),
  KEY `idx_se_director` (`spiritual_director_id`),
  CONSTRAINT `fk_se_director` FOREIGN KEY (`spiritual_director_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_se_student` FOREIGN KEY (`seminarian_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spiritual_evaluations`
--

LOCK TABLES `spiritual_evaluations` WRITE;
/*!40000 ALTER TABLE `spiritual_evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `spiritual_evaluations` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_spiritual_evaluations_after_update` AFTER UPDATE ON `spiritual_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('spiritual_evaluations', OLD.`evaluation_id`, 'UPDATE', JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'spiritual_director_id', OLD.`spiritual_director_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), JSON_OBJECT('evaluation_id', NEW.`evaluation_id`, 'seminarian_id', NEW.`seminarian_id`, 'spiritual_director_id', NEW.`spiritual_director_id`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`), LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_spiritual_evaluations_before_delete` BEFORE DELETE ON `spiritual_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('spiritual_evaluations', OLD.`evaluation_id`, 'DELETE', JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'spiritual_director_id', OLD.`spiritual_director_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`), NULL, LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `spiritual_questions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spiritual_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(80) NOT NULL,
  `question_text` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`question_id`),
  KEY `idx_sq_active` (`is_active`),
  KEY `idx_sq_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spiritual_questions`
--

LOCK TABLES `spiritual_questions` WRITE;
/*!40000 ALTER TABLE `spiritual_questions` DISABLE KEYS */;
INSERT INTO `spiritual_questions` VALUES (1,'Prayer Life','Demonstrates consistency in personal prayer.',1,1,'2026-06-06 08:18:55',NULL),(2,'Prayer Life','Participates actively in community prayer activities.',2,1,'2026-06-06 08:18:55',NULL),(3,'Prayer Life','Maintains a regular schedule for spiritual reflection.',3,1,'2026-06-06 08:18:55',NULL),(4,'Prayer Life','Shows attentiveness and reverence during prayer.',4,1,'2026-06-06 08:18:55',NULL),(5,'Prayer Life','Integrates prayer into daily decision-making.',5,1,'2026-06-06 08:18:55',NULL),(6,'Prayer Life','Demonstrates a sincere desire to deepen his prayer life.',6,1,'2026-06-06 08:18:55',NULL),(7,'Sacramental Participation','Participates faithfully in the Holy Eucharist.',7,1,'2026-06-06 08:18:55',NULL),(8,'Sacramental Participation','Approaches the Sacrament of Reconciliation regularly.',8,1,'2026-06-06 08:18:55',NULL),(9,'Sacramental Participation','Demonstrates reverence during liturgical celebrations.',9,1,'2026-06-06 08:18:55',NULL),(10,'Sacramental Participation','Understands the importance of sacramental life.',10,1,'2026-06-06 08:18:55',NULL),(11,'Sacramental Participation','Actively participates in liturgical ministries when assigned.',11,1,'2026-06-06 08:18:55',NULL),(12,'Sacramental Participation','Exhibits devotion and respect during worship.',12,1,'2026-06-06 08:18:55',NULL),(13,'Spiritual Discipline','Practices self-discipline in daily life.',13,1,'2026-06-06 08:18:55',NULL),(14,'Spiritual Discipline','Demonstrates commitment to spiritual exercises.',14,1,'2026-06-06 08:18:55',NULL),(15,'Spiritual Discipline','Fulfills assigned spiritual obligations responsibly.',15,1,'2026-06-06 08:18:55',NULL),(16,'Spiritual Discipline','Shows perseverance in spiritual commitments.',16,1,'2026-06-06 08:18:55',NULL),(17,'Spiritual Discipline','Uses time effectively for spiritual growth.',17,1,'2026-06-06 08:18:55',NULL),(18,'Spiritual Discipline','Demonstrates consistency in spiritual practices.',18,1,'2026-06-06 08:18:55',NULL),(19,'Relationship with God & Vocation','Shows a growing awareness of God\'s presence.',19,1,'2026-06-06 08:18:55',NULL),(20,'Relationship with God & Vocation','Demonstrates trust in God\'s providence.',20,1,'2026-06-06 08:18:55',NULL),(21,'Relationship with God & Vocation','Reflects seriously on his vocation.',21,1,'2026-06-06 08:18:55',NULL),(22,'Relationship with God & Vocation','Displays commitment to priestly formation.',22,1,'2026-06-06 08:18:55',NULL),(23,'Relationship with God & Vocation','Shows openness to God\'s will.',23,1,'2026-06-06 08:18:55',NULL),(24,'Relationship with God & Vocation','Demonstrates authenticity in his vocational discernment.',24,1,'2026-06-06 08:18:55',NULL),(25,'Christian Virtues & Community Spirituality','Demonstrates humility in daily interactions.',25,1,'2026-06-06 08:18:55',NULL),(26,'Christian Virtues & Community Spirituality','Practices charity toward others.',26,1,'2026-06-06 08:18:55',NULL),(27,'Christian Virtues & Community Spirituality','Shows patience during challenges.',27,1,'2026-06-06 08:18:55',NULL),(28,'Christian Virtues & Community Spirituality','Forgives others readily and sincerely.',28,1,'2026-06-06 08:18:55',NULL),(29,'Christian Virtues & Community Spirituality','Promotes peace and unity within the community.',29,1,'2026-06-06 08:18:55',NULL),(30,'Christian Virtues & Community Spirituality','Serves as a positive spiritual example to fellow seminarians.',30,1,'2026-06-06 08:18:55',NULL);
/*!40000 ALTER TABLE `spiritual_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spiritual_remarks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spiritual_remarks` (
  `remark_id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `strengths` text DEFAULT NULL,
  `areas_for_growth` text DEFAULT NULL,
  `challenges` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `formation_plan` text DEFAULT NULL,
  `follow_up_schedule` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`remark_id`),
  UNIQUE KEY `uniq_srem_eval` (`evaluation_id`),
  CONSTRAINT `fk_srem_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `spiritual_evaluations` (`evaluation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spiritual_remarks`
--

LOCK TABLES `spiritual_remarks` WRITE;
/*!40000 ALTER TABLE `spiritual_remarks` DISABLE KEYS */;
/*!40000 ALTER TABLE `spiritual_remarks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spiritual_responses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spiritual_responses` (
  `response_id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  PRIMARY KEY (`response_id`),
  UNIQUE KEY `uniq_sresp` (`evaluation_id`,`question_id`),
  KEY `idx_sresp_eval` (`evaluation_id`),
  KEY `idx_sresp_q` (`question_id`),
  CONSTRAINT `fk_sresp_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `spiritual_evaluations` (`evaluation_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sresp_q` FOREIGN KEY (`question_id`) REFERENCES `spiritual_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spiritual_responses`
--

LOCK TABLES `spiritual_responses` WRITE;
/*!40000 ALTER TABLE `spiritual_responses` DISABLE KEYS */;
/*!40000 ALTER TABLE `spiritual_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spiritual_results`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spiritual_results` (
  `evaluation_id` int(11) NOT NULL,
  `total_score` int(11) NOT NULL,
  `average_rating` decimal(4,2) NOT NULL,
  `interpretation` varchar(60) NOT NULL,
  `prayer_life_avg` decimal(4,2) DEFAULT NULL,
  `sacramental_participation_avg` decimal(4,2) DEFAULT NULL,
  `spiritual_discipline_avg` decimal(4,2) DEFAULT NULL,
  `relationship_vocation_avg` decimal(4,2) DEFAULT NULL,
  `christian_virtues_avg` decimal(4,2) DEFAULT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`evaluation_id`),
  CONSTRAINT `fk_sres_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `spiritual_evaluations` (`evaluation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spiritual_results`
--

LOCK TABLES `spiritual_results` WRITE;
/*!40000 ALTER TABLE `spiritual_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `spiritual_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_enrolled_subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_enrolled_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `curriculum_version_id` int(11) DEFAULT NULL,
  `program` varchar(120) DEFAULT NULL,
  `year_level` varchar(40) DEFAULT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `status` enum('Enrolled','Dropped','Completed') NOT NULL DEFAULT 'Enrolled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_enrolled` (`student_id`,`subject_id`,`semester`,`academic_year`),
  KEY `idx_student_enrolled_student` (`student_id`),
  KEY `idx_student_enrolled_term` (`academic_year`,`semester`),
  KEY `fk_student_enrolled_subject` (`subject_id`),
  KEY `fk_student_enrolled_curriculum` (`curriculum_version_id`),
  CONSTRAINT `fk_student_enrolled_curriculum` FOREIGN KEY (`curriculum_version_id`) REFERENCES `curriculum_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_student_enrolled_student` FOREIGN KEY (`student_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_enrolled_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_enrolled_subjects`
--

LOCK TABLES `student_enrolled_subjects` WRITE;
/*!40000 ALTER TABLE `student_enrolled_subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_enrolled_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_grades`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_grades` (
  `grade_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `prelim_grade` decimal(5,2) DEFAULT NULL,
  `midterm_grade` decimal(5,2) DEFAULT NULL,
  `final_grade` decimal(5,2) DEFAULT NULL,
  `final_computed_grade` decimal(5,2) DEFAULT NULL,
  `status` enum('Completed','Incomplete','Dropped') NOT NULL DEFAULT 'Completed',
  `remarks` text DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `assessed_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`grade_id`),
  UNIQUE KEY `uniq_student_subject_term` (`student_id`,`subject_id`,`semester`,`academic_year`),
  KEY `idx_student_grades_student` (`student_id`),
  KEY `idx_student_grades_ay_sem` (`academic_year`,`semester`),
  KEY `fk_student_grades_subject` (`subject_id`),
  KEY `fk_student_grades_assessor` (`assessed_by`),
  CONSTRAINT `fk_student_grades_assessor` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_student_grades_student` FOREIGN KEY (`student_id`) REFERENCES `seminarians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_grades_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_grades`
--

LOCK TABLES `student_grades` WRITE;
/*!40000 ALTER TABLE `student_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_grades` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_student_grades_after_update` AFTER UPDATE ON `student_grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('student_grades', OLD.`grade_id`, 'UPDATE', JSON_OBJECT('grade_id', OLD.`grade_id`, 'student_id', OLD.`student_id`, 'subject_id', OLD.`subject_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'prelim_grade', OLD.`prelim_grade`, 'midterm_grade', OLD.`midterm_grade`, 'final_grade', OLD.`final_grade`, 'final_computed_grade', OLD.`final_computed_grade`, 'status', OLD.`status`, 'remarks', OLD.`remarks`, 'is_locked', OLD.`is_locked`, 'assessed_by', OLD.`assessed_by`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`), JSON_OBJECT('grade_id', NEW.`grade_id`, 'student_id', NEW.`student_id`, 'subject_id', NEW.`subject_id`, 'semester', NEW.`semester`, 'academic_year', NEW.`academic_year`, 'prelim_grade', NEW.`prelim_grade`, 'midterm_grade', NEW.`midterm_grade`, 'final_grade', NEW.`final_grade`, 'final_computed_grade', NEW.`final_computed_grade`, 'status', NEW.`status`, 'remarks', NEW.`remarks`, 'is_locked', NEW.`is_locked`, 'assessed_by', NEW.`assessed_by`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`), LEFT(CONCAT('Student #', OLD.student_id, ' · Subject #', OLD.subject_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Final: ', COALESCE(CAST(OLD.final_computed_grade AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/  /*!50003 TRIGGER `trg_student_grades_before_delete` BEFORE DELETE ON `student_grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES ('student_grades', OLD.`grade_id`, 'DELETE', JSON_OBJECT('grade_id', OLD.`grade_id`, 'student_id', OLD.`student_id`, 'subject_id', OLD.`subject_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'prelim_grade', OLD.`prelim_grade`, 'midterm_grade', OLD.`midterm_grade`, 'final_grade', OLD.`final_grade`, 'final_computed_grade', OLD.`final_computed_grade`, 'status', OLD.`status`, 'remarks', OLD.`remarks`, 'is_locked', OLD.`is_locked`, 'assessed_by', OLD.`assessed_by`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`), NULL, LEFT(CONCAT('Student #', OLD.student_id, ' · Subject #', OLD.subject_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Final: ', COALESCE(CAST(OLD.final_computed_grade AS CHAR), 'N/A')), 255), LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100), NOW());
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `student_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seminarian_id` int(11) NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `subject_name` varchar(255) NOT NULL,
  `days` varchar(50) NOT NULL DEFAULT 'MWF',
  `start_time` varchar(20) NOT NULL DEFAULT '08:00 AM',
  `end_time` varchar(20) NOT NULL DEFAULT '09:30 AM',
  `room` varchar(100) NOT NULL DEFAULT 'St. Thomas Hall 201',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_subject` (`seminarian_id`,`subject_code`),
  KEY `idx_seminarian` (`seminarian_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_schedules`
--

LOCK TABLES `student_schedules` WRITE;
/*!40000 ALTER TABLE `student_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `student_no` varchar(40) NOT NULL,
  `first_name` varchar(100) NOT NULL DEFAULT '',
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL DEFAULT '',
  `suffix` varchar(20) DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `place_of_birth` varchar(150) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `nationality` varchar(60) DEFAULT NULL,
  `religion` varchar(80) DEFAULT NULL,
  `parish_priest` varchar(150) DEFAULT NULL,
  `parish_chaplaincy` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `email_address` varchar(150) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `home_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `province` varchar(80) DEFAULT NULL,
  `municipality` varchar(80) DEFAULT NULL,
  `barangay` varchar(80) DEFAULT NULL,
  `zip_code` varchar(15) DEFAULT NULL,
  `father_name` varchar(150) DEFAULT NULL,
  `father_occupation` varchar(120) DEFAULT NULL,
  `mother_name` varchar(150) DEFAULT NULL,
  `mother_occupation` varchar(120) DEFAULT NULL,
  `guardian_name` varchar(150) DEFAULT NULL,
  `guardian_contact` varchar(50) DEFAULT NULL,
  `previous_school` varchar(150) DEFAULT NULL,
  `educational_attainment` varchar(120) DEFAULT NULL,
  `year_graduated` varchar(20) DEFAULT NULL,
  `academic_records` text DEFAULT NULL,
  `admission_academic_year` varchar(20) NOT NULL DEFAULT '',
  `admission_date` date DEFAULT NULL,
  `default_program` varchar(100) NOT NULL DEFAULT 'Bachelor of Arts in Philosophy',
  `formation_status` varchar(40) NOT NULL DEFAULT 'Active',
  `is_graduate` tinyint(1) NOT NULL DEFAULT 0,
  `graduated_at` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_student_no` (`student_no`),
  KEY `idx_students_user_id` (`user_id`),
  KEY `idx_students_name` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_teacher_assignments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_teacher_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `teacher_user_id` int(11) DEFAULT NULL,
  `teacher_name_override` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sta_subject_term` (`subject_id`,`academic_year`,`semester`),
  KEY `idx_sta_teacher` (`teacher_user_id`),
  CONSTRAINT `fk_sta_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sta_teacher` FOREIGN KEY (`teacher_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_teacher_assignments`
--

LOCK TABLES `subject_teacher_assignments` WRITE;
/*!40000 ALTER TABLE `subject_teacher_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `subject_teacher_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subjects` (
  `subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(30) NOT NULL,
  `subject_name` varchar(150) NOT NULL,
  `units` decimal(3,1) NOT NULL DEFAULT 0.0,
  `prerequisite_subject_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`subject_id`),
  UNIQUE KEY `uniq_subject_code` (`subject_code`),
  KEY `idx_subjects_archived_at` (`archived_at`),
  KEY `fk_subjects_prereq` (`prerequisite_subject_id`),
  CONSTRAINT `fk_subjects_prereq` FOREIGN KEY (`prerequisite_subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=360164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES (1,'Theo 1','Revelation & Faith',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(2,'Philo 1','Logic',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(3,'Philo 2','Introduction to Philosophy',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(4,'Psy 3','Developmental Psychology',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(5,'GE 1','Understanding the Self',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(6,'GE 2','Readings in Philippine History',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(7,'PATHFit 1','Physical Activities Toward Health and Fitness 1',2.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(8,'NSTP 1','ROTC/CWTS 1',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(9,'Theo 2','Church History',3.0,1,'2026-06-06 08:24:35',NULL,NULL),(10,'Philo 3','History of Western Philosophy I',3.0,3,'2026-06-06 08:24:35',NULL,NULL),(11,'Philo 5','History of Chinese Philosophy',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(12,'GE 4','Mathematics in the Modern World',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(13,'GE 6','Art Appreciation',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(14,'GE 7','Science, Technology and Society',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(15,'PATHFit 2','Physical Activities Toward Health and Fitness 2',2.0,7,'2026-06-06 08:24:35',NULL,NULL),(16,'NSTP 2','ROTC/CWTS 2',3.0,8,'2026-06-06 08:24:35',NULL,NULL),(17,'Theo 3','Sacraments and Liturgy',3.0,9,'2026-06-06 08:24:35',NULL,NULL),(18,'Philo 4','History of Western Philosophy II',3.0,10,'2026-06-06 08:24:35',NULL,NULL),(19,'Philo 6','History of Indian Philosophy',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(20,'Philo 12','Theodicy / Philosophy of Religion',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(21,'ForLan 1','Foreign Language 1',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(22,'GE 5','Purposive Communication',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(23,'GE 9','Life and Works of Rizal',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(24,'PATHFit 3','Physical Activities Toward Health and Fitness 3',2.0,15,'2026-06-06 08:24:35',NULL,NULL),(25,'Theo 4','Christology',3.0,17,'2026-06-06 08:24:35',NULL,NULL),(26,'Philo 7','Existentialism / Phenomenology / Hermeneutics / Postmodernism',3.0,18,'2026-06-06 08:24:35',NULL,NULL),(27,'Philo 8','Cosmology / Philosophy of Science and Technology',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(28,'Philo 9','Advanced Philosophy of Man / Philosophical Anthropology',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(29,'Psy 5','Theories of Personality',3.0,4,'2026-06-06 08:24:35',NULL,NULL),(30,'GE 3','The Contemporary World',3.0,NULL,'2026-06-06 08:24:35',NULL,NULL),(31,'ForLan 2','Foreign Language 2',3.0,21,'2026-06-06 08:24:35',NULL,NULL),(32,'PATHFit 4','Physical Activities Toward Health and Fitness 4',2.0,24,'2026-06-06 08:24:35',NULL,NULL),(33,'Theo 5','Moral Theology',3.0,25,'2026-06-06 08:24:36',NULL,NULL),(34,'Philo 10','Epistemology / Theory of Knowledge',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(35,'Philo 18','Special Questions in Philosophy',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(36,'Philo 19','Seminar of Contemporary Philosophy',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(37,'Philo 23','Research in Philosophy I',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(38,'GE 8','Ethics',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(39,'GE 10','Philippine Indigenous Communities',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(40,'Theo 6','Social Teachings of the Church and Community Involvement',3.0,33,'2026-06-06 08:24:36',NULL,NULL),(41,'Philo 11','Metaphysics',3.0,34,'2026-06-06 08:24:36',NULL,NULL),(42,'Philo 16','Modern Asian Thoughts',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(43,'Philo 24','Research in Philosophy II',3.0,37,'2026-06-06 08:24:36',NULL,NULL),(44,'Philo 20','Special Questions in Ethics',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(45,'GE 11','Gender and Society',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(46,'GE 12','People and Earth\'s Ecology',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(47,'Philo 13','Political Philosophy / Social Philosophy',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(48,'Philo 14','Philosophy of Language',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(49,'Philo 15','Aesthetics / Theories of Art',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(50,'Psy 16','Introduction to Counseling',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(51,'Philo 17','Comparative Philosophy',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(52,'Philo 21','Seminar on Filipino Philosophy',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(53,'Philo 22','Seminar on Plato and Aristotle',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(54,'Psy 13','Culture and Psychology / Sikolohiyang Pilipino',3.0,NULL,'2026-06-06 08:24:36',NULL,NULL),(40663,'LAT 101','Latin 1a',1.5,NULL,'2026-10-14 16:17:13','2026-11-19 15:37:19',NULL),(79060,'WRIT 101','Introduction to Writing 1',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79061,'SPCH 101','Speech 1',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79062,'CL 101','Christian Living',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79063,'CH 101','Church History',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:45:16',NULL),(79064,'PRAY 101','Prayer Forms',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79065,'MUS 101','Music',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79066,'SPIR 101','Spirituality',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79067,'ENG 101','English Grammar 1',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79068,'LIT 101','Introduction to Liturgy 1',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79069,'READ 101','Spiritual Reading',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79070,'SCRIP 101','Introduction to Scripture 1',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79071,'VOC 101','Introduction to Vocation',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79072,'LITR 101','English Literature 1',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(79073,'SKILL 101','Skills Development 1',1.5,NULL,'2026-10-21 15:17:01','2026-11-19 15:37:19',NULL),(93382,'LAT 102','Latin 1b',1.5,40663,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93383,'WRIT 102','Introduction to Writing 2',1.5,79060,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93384,'SPCH 102','Speech 2',1.5,79061,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93385,'CCC 102','Catechism of the Catholic Church',1.5,NULL,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93386,'CHRI 102','Introduction to Christology',1.5,NULL,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93387,'ECCL 102','Introduction to Ecclesiology',1.5,NULL,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93388,'SACR 102','Introduction to the Sacraments',1.5,NULL,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93389,'SCRIP 102','Introduction to Scripture 2',1.5,79070,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93390,'LIT 102','Introduction to Liturgy 2',1.5,79068,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93391,'HAF 102','Human and Affective Formation',1.5,NULL,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93392,'VOC 102','Discernment of Vocation',1.5,79071,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93393,'PRAY 102','Lectio Divina and Biblical Prayer',1.5,79064,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93394,'PAST 102','Pastoral Exposure and Apostolic Service',1.5,NULL,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93395,'LANG 102','Filipino or Local Language for Pastoral Ministry',1.5,NULL,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL),(93396,'SKILL 102','Skills Development 2',1.5,79073,'2026-10-22 16:18:26','2026-11-19 15:37:19',NULL);
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `timetable`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timetable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event` varchar(255) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `type` enum('Academic','Formation','Church','Spiritual','Human','Pastoral') DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `visibility` enum('all','admin_only') NOT NULL DEFAULT 'all',
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_timetable_created_by` (`created_by`),
  KEY `idx_timetable_date` (`date`),
  CONSTRAINT `fk_timetable_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `timetable`
--

LOCK TABLES `timetable` WRITE;
/*!40000 ALTER TABLE `timetable` DISABLE KEYS */;
/*!40000 ALTER TABLE `timetable` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_event_notifications_read`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_event_notifications_read` (
  `user_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_event_notifications_read`
--

LOCK TABLES `user_event_notifications_read` WRITE;
/*!40000 ALTER TABLE `user_event_notifications_read` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_event_notifications_read` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_profiles`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(40) DEFAULT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `place_of_birth` varchar(150) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `nationality` varchar(60) DEFAULT NULL,
  `religion` varchar(80) DEFAULT NULL,
  `parish_priest` varchar(150) DEFAULT NULL,
  `parish_chaplaincy` varchar(150) DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `email_address` varchar(120) DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `province` varchar(80) DEFAULT NULL,
  `municipality` varchar(80) DEFAULT NULL,
  `barangay` varchar(80) DEFAULT NULL,
  `zip_code` varchar(15) DEFAULT NULL,
  `father_name` varchar(150) DEFAULT NULL,
  `father_occupation` varchar(120) DEFAULT NULL,
  `mother_name` varchar(150) DEFAULT NULL,
  `mother_occupation` varchar(120) DEFAULT NULL,
  `guardian_name` varchar(150) DEFAULT NULL,
  `guardian_contact_number` varchar(40) DEFAULT NULL,
  `previous_school` varchar(150) DEFAULT NULL,
  `educational_attainment` varchar(120) DEFAULT NULL,
  `year_graduated` varchar(10) DEFAULT NULL,
  `academic_records` text DEFAULT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `contact` varchar(40) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_profiles_user` (`user_id`),
  UNIQUE KEY `uniq_user_profiles_student_id` (`student_id`),
  CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_profiles`
--

LOCK TABLES `user_profiles` WRITE;
/*!40000 ALTER TABLE `user_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Student','Registrar','Dean','AcademicFormator','SpiritualDirector','HumanFormator','PastoralFormator','Cashier','Shrine','Rector') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (2,'registrar','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','Registrar'),(3,'admin','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','Dean'),(4,'academicformator','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','AcademicFormator'),(5,'spiritualdirector','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','SpiritualDirector'),(6,'humanformator','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','HumanFormator'),(7,'pastoralformator','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','PastoralFormator'),(8,'cashier','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','Cashier'),(9,'shrine','$2y$10$uYSKBG6kFcl0MA5rqyben.MMO7ndZBl4VA1G10LwXsVhNf9mqP4jy','Shrine'),(16,'rector','$2y$10$Zv81V89naV5WoQSl/mZ9W.WAl9vyfo.BkQYwC6h6Ddss4megy16Rq','Rector');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `v_student_current_status`
--

SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_student_current_status` AS SELECT
 1 AS `student_id`,
  1 AS `user_id`,
  1 AS `student_no`,
  1 AS `full_name`,
  1 AS `first_name`,
  1 AS `last_name`,
  1 AS `email`,
  1 AS `email_address`,
  1 AS `contact_number`,
  1 AS `home_address`,
  1 AS `admission_academic_year`,
  1 AS `is_graduate`,
  1 AS `current_enrollment_id`,
  1 AS `current_academic_year`,
  1 AS `current_semester`,
  1 AS `program`,
  1 AS `year_level`,
  1 AS `academic_status`,
  1 AS `enrollment_status`,
  1 AS `total_assessed_fee`,
  1 AS `total_paid`,
  1 AS `fee_balance` */;
SET character_set_client = @saved_cs_client;

--
-- Dumping routines for database 'seminary_db'
--

--
-- Final view structure for view `v_student_current_status`
--

/*!50001 DROP VIEW IF EXISTS `v_student_current_status`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50001 VIEW `v_student_current_status` AS select `s`.`id` AS `student_id`,`s`.`user_id` AS `user_id`,`s`.`student_no` AS `student_no`,`s`.`full_name` AS `full_name`,`s`.`first_name` AS `first_name`,`s`.`last_name` AS `last_name`,`s`.`email` AS `email`,coalesce(`s`.`email`,'') AS `email_address`,`s`.`contact_number` AS `contact_number`,`s`.`home_address` AS `home_address`,`s`.`admission_academic_year` AS `admission_academic_year`,`s`.`is_graduate` AS `is_graduate`,`e`.`id` AS `current_enrollment_id`,`e`.`academic_year` AS `current_academic_year`,`e`.`semester` AS `current_semester`,coalesce(`e`.`program`,`s`.`default_program`) AS `program`,`e`.`year_level` AS `year_level`,`e`.`academic_standing` AS `academic_status`,`e`.`enrollment_status` AS `enrollment_status`,`e`.`total_assessed_fee` AS `total_assessed_fee`,`e`.`total_paid` AS `total_paid`,`e`.`fee_balance` AS `fee_balance` from (`students` `s` left join `enrollments` `e` on(`e`.`id` = (select `e2`.`id` from `enrollments` `e2` where `e2`.`student_id` = `s`.`id` order by `e2`.`academic_year` desc,field(`e2`.`semester`,'Summer','2nd','1st'),`e2`.`id` desc limit 1))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

CREATE TABLE IF NOT EXISTS `php_sessions` (
  `id` varchar(128) NOT NULL,
  `data` mediumtext NOT NULL,
  `last_accessed` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_php_sessions_last_accessed` (`last_accessed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performance composite indexes for Vercel / Aiven MySQL (<5ms queries)
CREATE INDEX `idx_grades_sem_archived` ON `grades` (`seminarian_id`, `archived_at`);
CREATE INDEX `idx_eval_sem_arch_type` ON `evaluations` (`seminarian_id`, `archived_at`, `type`);
CREATE INDEX `idx_sar_sem_type_status_read` ON `seminarian_access_requests` (`seminarian_id`, `assignment_type`, `status`, `is_read`);
CREATE INDEX `idx_csr_sem_status_read` ON `cashier_soa_requests` (`seminarian_id`, `status`, `is_read`);
CREATE INDEX `idx_ses_ay_sem_status` ON `student_enrolled_subjects` (`academic_year`, `semester`, `status`);
CREATE INDEX `idx_ses_stu_ay_sem_status` ON `student_enrolled_subjects` (`student_id`, `academic_year`, `semester`, `status`);
CREATE INDEX `idx_sg_stu_sub_status` ON `student_grades` (`student_id`, `subject_id`, `status`);
CREATE INDEX `idx_li_sem_ay_sem_status` ON `lodging_invoices` (`seminarian_id`, `academic_year`, `semester`, `status`);
CREATE INDEX `idx_lp_sem_ay_sem` ON `lodging_payments` (`seminarian_id`, `academic_year`, `semester`);
CREATE INDEX `idx_cs_ay_yl_sem` ON `curriculum_subjects` (`academic_year_id`, `year_level`, `semester`);
CREATE INDEX `idx_activities_ts_id` ON `activities` (`timestamp`, `id`);
CREATE INDEX `idx_history_logs_changed_at` ON `history_logs` (`changed_at`);
CREATE INDEX `idx_ae_stu_term_arch` ON `academic_evaluations` (`student_id`, `academic_year`, `semester`, `archived_at`);
CREATE INDEX `idx_he_sem_term_arch` ON `human_evaluations` (`seminarian_id`, `academic_year`, `semester`, `archived_at`);
CREATE INDEX `idx_pe_sem_term_arch` ON `pastoral_evaluations` (`seminarian_id`, `academic_year`, `semester`, `archived_at`);
CREATE INDEX `idx_se_sem_term_arch` ON `spiritual_evaluations` (`seminarian_id`, `academic_year`, `semester`, `archived_at`);
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-23 15:49:47
