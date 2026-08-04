<?php
/**
 * مخطط قاعدة البيانات + البيانات الافتراضية
 */
if (!defined('SBA_INSTALL') && !defined('SBA')) exit;

function sba_schema(string $prefix): array
{
    $p = $prefix;
    return [
        "CREATE TABLE IF NOT EXISTS `{$p}users` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            username VARCHAR(60) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','manager','employee','viewer') NOT NULL DEFAULT 'employee',
            perm_technical TINYINT(1) NOT NULL DEFAULT 0,
            perm_content TINYINT(1) NOT NULL DEFAULT 0,
            perm_compliance TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}stations` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            frequency VARCHAR(60) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_station_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}programs` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            station_id INT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            presenter VARCHAR(150) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_prog_station (station_id),
            CONSTRAINT fk_prog_station FOREIGN KEY (station_id)
                REFERENCES `{$p}stations`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}episodes` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            program_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            air_date DATE NOT NULL,
            air_time TIME NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ep_program (program_id),
            KEY idx_ep_date (air_date),
            CONSTRAINT fk_ep_program FOREIGN KEY (program_id)
                REFERENCES `{$p}programs`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}episode_evaluators` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            episode_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            type ENUM('technical','content') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_assignment (episode_id, user_id, type),
            KEY idx_ass_user (user_id),
            CONSTRAINT fk_ass_episode FOREIGN KEY (episode_id)
                REFERENCES `{$p}episodes`(id) ON DELETE CASCADE,
            CONSTRAINT fk_ass_user FOREIGN KEY (user_id)
                REFERENCES `{$p}users`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}criteria` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            type ENUM('technical','content') NOT NULL,
            name VARCHAR(150) NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}evaluations` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            episode_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            type ENUM('technical','content') NOT NULL,
            score DECIMAL(5,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_eval (episode_id, user_id, type),
            KEY idx_eval_user (user_id),
            CONSTRAINT fk_eval_episode FOREIGN KEY (episode_id)
                REFERENCES `{$p}episodes`(id) ON DELETE CASCADE,
            CONSTRAINT fk_eval_user FOREIGN KEY (user_id)
                REFERENCES `{$p}users`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}evaluation_items` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            evaluation_id INT UNSIGNED NOT NULL,
            criterion_id INT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY idx_item_eval (evaluation_id),
            CONSTRAINT fk_item_eval FOREIGN KEY (evaluation_id)
                REFERENCES `{$p}evaluations`(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_criterion FOREIGN KEY (criterion_id)
                REFERENCES `{$p}criteria`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}compliance` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            station_id INT UNSIGNED NOT NULL,
            check_at DATETIME NOT NULL,
            status TINYINT(1) NOT NULL,
            user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_check (station_id, check_at),
            KEY idx_comp_time (check_at),
            CONSTRAINT fk_comp_station FOREIGN KEY (station_id)
                REFERENCES `{$p}stations`(id) ON DELETE CASCADE,
            CONSTRAINT fk_comp_user FOREIGN KEY (user_id)
                REFERENCES `{$p}users`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}settings` (
            skey VARCHAR(60) NOT NULL,
            svalue TEXT NULL,
            PRIMARY KEY (skey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}audit_log` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NULL,
            action VARCHAR(30) NOT NULL,
            entity VARCHAR(40) NOT NULL DEFAULT '',
            entity_id INT UNSIGNED NOT NULL DEFAULT 0,
            details VARCHAR(500) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audit_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** البيانات الافتراضية */
function sba_seed(PDO $pdo, string $prefix): void
{
    // معايير التقييم الافتراضية
    $criteria = [
        ['technical', 'جودة الصوت ونقاء البث', 1],
        ['technical', 'مستوى الإشارة وثباتها', 2],
        ['technical', 'التوازن الصوتي (موسيقى/كلام)', 3],
        ['technical', 'خلو البث من الانقطاعات', 4],
        ['technical', 'جودة الفواصل والمؤثرات', 5],
        ['content', 'جودة المحتوى وقيمته', 1],
        ['content', 'الالتزام بفكرة البرنامج', 2],
        ['content', 'أداء المذيع وحضوره', 3],
        ['content', 'سلامة اللغة والأسلوب', 4],
        ['content', 'التفاعل مع الجمهور', 5],
    ];
    $st = $pdo->prepare("INSERT INTO `{$prefix}criteria` (type, name, sort) VALUES (?,?,?)");
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}criteria`")->fetchColumn();
    if ($exists === 0) {
        foreach ($criteria as $c) $st->execute($c);
    }

    // الإعدادات الافتراضية
    $settings = [
        'site_name'        => 'منصة متابعة جودة البث والمحتوى الإذاعي',
        'weight_technical' => '50',
        'weight_content'   => '50',
        'per_page'         => '20',
        'update_repo'      => 'badrshfaqah/sba-q',
        'update_branch'    => 'main',
        'update_token'     => '',
        'db_version'       => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')) ?: '1.0.0',
    ];
    $st = $pdo->prepare("INSERT INTO `{$prefix}settings` (skey, svalue) VALUES (?,?)
        ON DUPLICATE KEY UPDATE skey = skey");
    foreach ($settings as $k => $v) $st->execute([$k, $v]);
}
