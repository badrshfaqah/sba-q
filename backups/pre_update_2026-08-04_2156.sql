-- منصة متابعة جودة البث والمحتوى الإذاعي
-- نسخة احتياطية بتاريخ: 2026-08-04 21:56
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `sba_audit_log`;
CREATE TABLE `sba_audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `entity_id` int unsigned NOT NULL DEFAULT '0',
  `details` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_time` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_audit_log` (`id`,`user_id`,`action`,`entity`,`entity_id`,`details`,`ip`,`created_at`) VALUES
('1','1','login','user','1','تسجيل دخول ناجح','127.0.0.1','2026-08-04 21:11:53'),
('2','1','create','user','2','إضافة مستخدم: محمد المقيم','127.0.0.1','2026-08-04 21:12:14'),
('3','1','create','program','1','إضافة برنامج: صباح الخير','127.0.0.1','2026-08-04 21:13:35'),
('4','1','create','episode','1','إضافة حلقة: حلقة الافتتاح','127.0.0.1','2026-08-04 21:13:35'),
('5','2','login','user','2','تسجيل دخول ناجح','127.0.0.1','2026-08-04 21:13:55'),
('6','2','evaluate','episode','1','تقييم فني للحلقة: حلقة الافتتاح — الدرجة 8.2','127.0.0.1','2026-08-04 21:13:55'),
('7','2','evaluate','episode','1','تقييم محتوى للحلقة: حلقة الافتتاح — الدرجة 8.8','127.0.0.1','2026-08-04 21:13:55'),
('8','1','create','station','2','إضافة إذاعة: إذاعة جدة','127.0.0.1','2026-08-04 21:14:17'),
('9','2','create','compliance','0','تسجيل التزام عند 2026-08-04 08:00:00 لعدد 2 إذاعة','127.0.0.1','2026-08-04 21:14:17'),
('10','1','import','compliance','0','استيراد التزام ليوم 2026-08-04 — إضافة 5 سجلاً','127.0.0.1','2026-08-04 21:14:18'),
('11','1','restore','database','0','استعادة نسخة احتياطية (36 أمراً)','127.0.0.1','2026-08-04 21:15:14'),
('12','1','create','user','3','إضافة مستخدم: زائر مشاهد','127.0.0.1','2026-08-04 21:15:14'),
('13','3','login','user','3','تسجيل دخول ناجح','127.0.0.1','2026-08-04 21:15:14'),
('14','1','import','compliance','0','استيراد التزام ليوم 2026-08-04 — إضافة 4 سجلاً','127.0.0.1','2026-08-04 21:15:46'),
('15','1','login','user','1','تسجيل دخول ناجح','127.0.0.1','2026-08-04 21:16:37'),
('16','1','update','system','0','تحديث النظام من GitHub إلى إصدار اكتمل التحديث إلى الإصدار 1.1.0','127.0.0.1','2026-08-04 21:50:00');

DROP TABLE IF EXISTS `sba_compliance`;
CREATE TABLE `sba_compliance` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `station_id` int unsigned NOT NULL,
  `check_at` datetime NOT NULL,
  `status` tinyint(1) NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_check` (`station_id`,`check_at`),
  KEY `idx_comp_time` (`check_at`),
  KEY `fk_comp_user` (`user_id`),
  CONSTRAINT `fk_comp_station` FOREIGN KEY (`station_id`) REFERENCES `sba_stations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comp_user` FOREIGN KEY (`user_id`) REFERENCES `sba_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_compliance` (`id`,`station_id`,`check_at`,`status`,`user_id`,`created_at`) VALUES
('1','1','2026-08-04 08:00:00','1','2','2026-08-04 21:14:17'),
('2','2','2026-08-04 08:00:00','0','2','2026-08-04 21:14:17'),
('3','1','2026-08-04 10:00:00','1','1','2026-08-04 21:14:17'),
('4','2','2026-08-04 10:00:00','0','1','2026-08-04 21:14:17'),
('5','1','2026-08-04 12:30:00','1','1','2026-08-04 21:14:17'),
('6','2','2026-08-04 12:30:00','1','1','2026-08-04 21:14:17'),
('7','2','2026-08-04 14:00:00','1','1','2026-08-04 21:14:17'),
('10','1','2026-08-04 16:00:00','1','1','2026-08-04 21:15:46'),
('11','2','2026-08-04 16:00:00','0','1','2026-08-04 21:15:46'),
('12','1','2026-08-04 18:00:00','0','1','2026-08-04 21:15:46'),
('13','2','2026-08-04 18:00:00','1','1','2026-08-04 21:15:46');

DROP TABLE IF EXISTS `sba_criteria`;
CREATE TABLE `sba_criteria` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('technical','content') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort` int NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_criteria` (`id`,`type`,`name`,`sort`,`active`) VALUES
('1','technical','جودة الصوت ونقاء البث','1','1'),
('2','technical','مستوى الإشارة وثباتها','2','1'),
('3','technical','التوازن الصوتي (موسيقى/كلام)','3','1'),
('4','technical','خلو البث من الانقطاعات','4','1'),
('5','technical','جودة الفواصل والمؤثرات','5','1'),
('6','content','جودة المحتوى وقيمته','1','1'),
('7','content','الالتزام بفكرة البرنامج','2','1'),
('8','content','أداء المذيع وحضوره','3','1'),
('9','content','سلامة اللغة والأسلوب','4','1'),
('10','content','التفاعل مع الجمهور','5','1');

DROP TABLE IF EXISTS `sba_episode_evaluators`;
CREATE TABLE `sba_episode_evaluators` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `episode_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `type` enum('technical','content') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment` (`episode_id`,`user_id`,`type`),
  KEY `idx_ass_user` (`user_id`),
  CONSTRAINT `fk_ass_episode` FOREIGN KEY (`episode_id`) REFERENCES `sba_episodes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ass_user` FOREIGN KEY (`user_id`) REFERENCES `sba_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_episode_evaluators` (`id`,`episode_id`,`user_id`,`type`,`created_at`) VALUES
('1','1','2','technical','2026-08-04 21:13:35'),
('2','1','2','content','2026-08-04 21:13:35');

DROP TABLE IF EXISTS `sba_episodes`;
CREATE TABLE `sba_episodes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `program_id` int unsigned NOT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `air_date` date NOT NULL,
  `air_time` time NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ep_program` (`program_id`),
  KEY `idx_ep_date` (`air_date`),
  CONSTRAINT `fk_ep_program` FOREIGN KEY (`program_id`) REFERENCES `sba_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_episodes` (`id`,`program_id`,`title`,`air_date`,`air_time`,`notes`,`created_at`) VALUES
('1','1','حلقة الافتتاح','2026-08-03','08:00:00',NULL,'2026-08-04 21:13:35');

DROP TABLE IF EXISTS `sba_evaluation_items`;
CREATE TABLE `sba_evaluation_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `evaluation_id` int unsigned NOT NULL,
  `criterion_id` int unsigned NOT NULL,
  `score` tinyint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_eval` (`evaluation_id`),
  KEY `fk_item_criterion` (`criterion_id`),
  CONSTRAINT `fk_item_criterion` FOREIGN KEY (`criterion_id`) REFERENCES `sba_criteria` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `sba_evaluations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_evaluation_items` (`id`,`evaluation_id`,`criterion_id`,`score`) VALUES
('1','1','1','8'),
('2','1','2','9'),
('3','1','3','7'),
('4','1','4','8'),
('5','1','5','9'),
('6','2','6','9'),
('7','2','7','8'),
('8','2','8','9'),
('9','2','9','10'),
('10','2','10','8');

DROP TABLE IF EXISTS `sba_evaluations`;
CREATE TABLE `sba_evaluations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `episode_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `type` enum('technical','content') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `score` decimal(5,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eval` (`episode_id`,`user_id`,`type`),
  KEY `idx_eval_user` (`user_id`),
  CONSTRAINT `fk_eval_episode` FOREIGN KEY (`episode_id`) REFERENCES `sba_episodes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_user` FOREIGN KEY (`user_id`) REFERENCES `sba_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_evaluations` (`id`,`episode_id`,`user_id`,`type`,`score`,`notes`,`created_at`) VALUES
('1','1','2','technical','8.20','بث ممتاز','2026-08-04 21:13:55'),
('2','1','2','content','8.80',NULL,'2026-08-04 21:13:55');

DROP TABLE IF EXISTS `sba_programs`;
CREATE TABLE `sba_programs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `station_id` int unsigned NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `presenter` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prog_station` (`station_id`),
  CONSTRAINT `fk_prog_station` FOREIGN KEY (`station_id`) REFERENCES `sba_stations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_programs` (`id`,`station_id`,`name`,`presenter`,`active`,`created_at`) VALUES
('1','1','صباح الخير','سارة','1','2026-08-04 21:13:35');

DROP TABLE IF EXISTS `sba_settings`;
CREATE TABLE `sba_settings` (
  `skey` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `svalue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_settings` (`skey`,`svalue`) VALUES
('db_version','1.1.0'),
('per_page','20'),
('site_name','منصة متابعة جودة البث والمحتوى الإذاعي'),
('update_branch','main'),
('update_repo','badrshfaqah/sba-q'),
('weight_content','50'),
('weight_technical','50');

DROP TABLE IF EXISTS `sba_stations`;
CREATE TABLE `sba_stations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `frequency` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_station_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_stations` (`id`,`name`,`frequency`,`active`,`created_at`) VALUES
('1','إذاعة الرياض',NULL,'1','2026-08-04 21:11:33'),
('2','إذاعة جدة',NULL,'1','2026-08-04 21:14:17');

DROP TABLE IF EXISTS `sba_users`;
CREATE TABLE `sba_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','manager','employee','viewer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'employee',
  `perm_technical` tinyint(1) NOT NULL DEFAULT '0',
  `perm_content` tinyint(1) NOT NULL DEFAULT '0',
  `perm_compliance` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sba_users` (`id`,`name`,`username`,`password`,`role`,`perm_technical`,`perm_content`,`perm_compliance`,`active`,`created_at`) VALUES
('1','آنا بدر','admin','$2y$12$rbAIqjs1Ee42YXZIZkrwMe1J5g517YxsrpcXJgcsRrxJYquW20EdW','admin','0','0','0','1','2026-08-04 21:11:33'),
('2','محمد المقيم','evaluator1','$2y$12$sj7eKOMe0izXrsi1x/BPDuhCAFvnsFooo2sk.gjztTGktHPkJvGcS','employee','1','1','1','1','2026-08-04 21:12:14'),
('3','زائر مشاهد','viewer1','$2y$12$g3HMV1FSeqhq1GxSkgju4.YjY68ultaU.MZRhNg7C.HcWPP2y2KZK','viewer','0','0','0','1','2026-08-04 21:15:14');

SET FOREIGN_KEY_CHECKS=1;
