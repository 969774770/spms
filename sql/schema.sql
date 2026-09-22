-- =====================================================================
-- 软件发布管理系统 建表脚本
-- 约定：时间字段统一存 UTC（展示层转 Asia/Shanghai）；主键自增 BIGINT
-- 例外：software.id 为业务编号（S0001，系统生成）
-- 注：不使用物理外键（便于导入与软删除），关联完整性由应用层保证
-- 初始账号：admin / admin123（部署后请立即在「个人资料」修改密码）
-- =====================================================================
SET NAMES utf8mb4;

-- ----------------------------
-- 用户
-- ----------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)  NOT NULL COMMENT '登录名',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash() 结果',
  `nickname`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '显示昵称',
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户';

-- ----------------------------
-- 角色（固定三种：admin/publisher/viewer，可扩展）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(32)  NOT NULL,
  `name`        VARCHAR(64)  NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色';

-- ----------------------------
-- 用户-角色
-- ----------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联';

-- ----------------------------
-- 登录尝试（限流依据：同账号或同 IP 连续失败 N 次锁 M 分钟）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(64) NOT NULL DEFAULT '',
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `success`    TINYINT     NOT NULL DEFAULT 0 COMMENT '1成功 0失败',
  `created_at` DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_la_username` (`username`, `created_at`),
  KEY `ix_la_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录尝试';

-- ----------------------------
-- 软件
-- ----------------------------
CREATE TABLE IF NOT EXISTS `software` (
  `id`           VARCHAR(8)   NOT NULL COMMENT '业务编号 S0001',
  `software_key` VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '客户端接口密钥（client.files 用）',
  `name`         VARCHAR(128) NOT NULL,
  `version`      VARCHAR(64)  NOT NULL DEFAULT '',
  `style_type`   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '样式类型（业务自定义）',
  `description`  TEXT         NULL,
  `changelog`    TEXT         NULL COMMENT '更新日志',
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `status`       TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `deleted_at`   DATETIME     NULL COMMENT '删除时间（删除=停用+标记，记录保留可恢复）',
  `source_exts`  VARCHAR(512) NOT NULL DEFAULT '' COMMENT '本软件专属源码后缀黑名单（逗号分隔，空=不启用）',
  `published_at` DATETIME     NULL COMMENT '最近发布时间',
  `created_at`   DATETIME     NOT NULL,
  `updated_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sw_key` (`software_key`),
  KEY `ix_sw_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='软件';

-- ----------------------------
-- 软件目录（显式目录，空目录也入库；path 用 / 分隔，根目录不入库）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `software_dirs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id` VARCHAR(8)   NOT NULL,
  `path`        VARCHAR(512) NOT NULL COMMENT '软件内相对路径，如 assets/css',
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1正常 0已删(回收站)',
  `deleted_at`  DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sd_sw_status` (`software_id`, `status`),
  KEY `ix_sd_path` (`software_id`, `path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='软件目录';

-- ----------------------------
-- 内容寻址存储对象（文件实体落 storage/objects/，数据库只存元数据）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `storage_objects` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hash`       CHAR(64)        NOT NULL COMMENT 'SHA-256 十六进制',
  `size`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `ref_count`  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '引用计数',
  `zeroed_at`  DATETIME        NULL COMMENT '引用归零时间（清理保留期起点）',
  `created_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_so_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='存储对象';

-- ----------------------------
-- 逻辑文件（dir_id 为空表示根目录）
-- active=人工启用/停用（对外可见性开关之一）
-- status=记录存废（1正常 0已删进回收站，配 deleted_at）
-- 对外可见 = status=1 且 active=1 且 未命中文件名黑名单
-- ----------------------------
CREATE TABLE IF NOT EXISTS `files` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id`   VARCHAR(8)   NOT NULL,
  `dir_id`        BIGINT UNSIGNED NULL,
  `file_name`     VARCHAR(255) NOT NULL COMMENT '当前文件名（目录内唯一）',
  `original_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '上传时的原始文件名',
  `size`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `object_id`     BIGINT UNSIGNED NOT NULL,
  `active`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用（人工）',
  `auto_run`      TINYINT      NOT NULL DEFAULT 0 COMMENT '1=客户端下载完成后自动运行该文件',
  `force_overwrite` TINYINT    NOT NULL DEFAULT 1 COMMENT '1=客户端下载后覆盖本地同名文件 0=本地已存在则跳过',
  `note`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注，随配置下发',
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1正常 0已删',
  `deleted_at`    DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_f_sw_dir_status` (`software_id`, `dir_id`, `status`),
  KEY `ix_f_object` (`object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='逻辑文件';

-- ----------------------------
-- 分片上传会话（断点续传；超过 24 小时未完成由计划任务清理）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `upload_sessions` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`           CHAR(36)     NOT NULL,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `software_id`    VARCHAR(8)   NOT NULL,
  `dir_path`       VARCHAR(512) NOT NULL DEFAULT '' COMMENT '目标目录路径，空=根目录',
  `file_name`      VARCHAR(255) NOT NULL,
  `size`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `hash`           CHAR(64)     NOT NULL DEFAULT '' COMMENT '整文件 SHA-256（客户端预计算）',
  `chunk_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `total_parts`    INT          NOT NULL DEFAULT 0,
  `uploaded_parts` TEXT         NULL COMMENT 'JSON 数组：已上传分片号',
  `status`         TINYINT      NOT NULL DEFAULT 1 COMMENT '1进行中 2完成 3中止',
  `created_at`     DATETIME     NOT NULL,
  `expires_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_us_uuid` (`uuid`),
  KEY `ix_us_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分片上传会话';

-- ----------------------------
-- 用户-软件授权（无任何记录 = 不限制；有记录 = 仅这些软件可见可操作）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `user_software_scope` (
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `software_id` VARCHAR(8) NOT NULL,
  PRIMARY KEY (`user_id`, `software_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='按软件授权';

-- ----------------------------
-- 审计日志（只增不改不删）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NULL,
  `username`    VARCHAR(64)  NOT NULL DEFAULT '',
  `action`      VARCHAR(64)  NOT NULL,
  `target_type` VARCHAR(32)  NOT NULL DEFAULT '',
  `target_id`   VARCHAR(64)  NOT NULL DEFAULT '',
  `detail`      TEXT         NULL COMMENT 'JSON',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_aud_created` (`created_at`),
  KEY `ix_aud_user_action` (`username`, `action`),
  KEY `ix_aud_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='审计日志';

-- ----------------------------
-- 系统设置（键值对）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(64) NOT NULL,
  `value`      TEXT        NULL,
  `updated_at` DATETIME    NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置';

-- ----------------------------
-- 文件名黑名单（下发过滤；只影响面向客户端的配置，不影响管理端）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `name_blacklist` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pattern`        VARCHAR(255) NOT NULL,
  `match_target`   VARCHAR(16)  NOT NULL DEFAULT 'name' COMMENT 'name=文件名 path=相对路径',
  `match_type`     VARCHAR(16)  NOT NULL DEFAULT 'glob' COMMENT 'glob/exact/contains/regex',
  `case_sensitive` TINYINT      NOT NULL DEFAULT 0 COMMENT '0不敏感 1敏感',
  `software_id`    VARCHAR(8)   NULL COMMENT '空=全局',
  `enabled`        TINYINT      NOT NULL DEFAULT 1,
  `note`           VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `created_by`     BIGINT UNSIGNED NULL,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_nb_enabled` (`enabled`, `software_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件名黑名单';

-- ----------------------------
-- 打包任务（首版同步打包，表只记录结果）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `zip_jobs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`           CHAR(36)  NOT NULL,
  `software_id`    VARCHAR(8) NOT NULL,
  `dir_path`       VARCHAR(512) NOT NULL DEFAULT '',
  `status`         TINYINT   NOT NULL DEFAULT 1 COMMENT '1处理中 2完成 3失败',
  `result_file_id` BIGINT UNSIGNED NULL,
  `message`        VARCHAR(512) NOT NULL DEFAULT '',
  `created_at`     DATETIME  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zj_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='打包任务';

-- =====================================================================
-- 初始数据
-- =====================================================================
INSERT INTO `roles` (`id`,`code`,`name`,`description`) VALUES
  (1,'admin','管理员','全部权限'),
  (2,'publisher','发布员','管理软件与文件，不能管用户/系统设置'),
  (3,'viewer','访客','只读浏览与下载')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 初始管理员 admin / admin123（bcrypt，部署后请立即修改）
INSERT INTO `users` (`id`,`username`,`password_hash`,`nickname`,`status`,`created_at`,`updated_at`) VALUES
  (1,'admin','$2y$10$9Fbr.IX6pSjAsmJqk5DRhOn3s3VK.nEny4Z9VwN47r5mI1UoyZpvW','管理员',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `username`=VALUES(`username`);

INSERT INTO `user_roles` (`user_id`,`role_id`) VALUES (1,1)
ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- 系统设置默认值
INSERT INTO `settings` (`key`,`value`,`updated_at`) VALUES
  ('site_name','软件发布管理系统',UTC_TIMESTAMP()),
  ('max_file_size','5368709120',UTC_TIMESTAMP()),
  ('upload_total_limit','21474836480',UTC_TIMESTAMP()),
  ('chunk_size','5242880',UTC_TIMESTAMP()),
  ('big_file_threshold','20971520',UTC_TIMESTAMP()),
  ('trash_retention_days','7',UTC_TIMESTAMP()),
  ('object_retention_days','7',UTC_TIMESTAMP()),
  ('login_fail_limit','5',UTC_TIMESTAMP()),
  ('login_lock_minutes','15',UTC_TIMESTAMP()),
  ('disk_alert_percent','10',UTC_TIMESTAMP()),
  ('download_domain','',UTC_TIMESTAMP()),
  ('client_api_token','',UTC_TIMESTAMP()),
  ('source_ext_blacklist','php,asp,aspx,asax,ascx,jsp,jspx,py,go,java,kt,swift,rs,rb,pl,lua,c,cpp,h,hpp,cc,cxx,cs,js,mjs,ts,tsx,jsx,vue,sql,sh,bash,bat,cmd,ps1,asm,s,cbl,cls,frm,vb,vbp,sln,csproj,vcxproj,gradle,classpath,project',UTC_TIMESTAMP()),
  ('dl_secret','',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `updated_at`=`updated_at`;

-- 常用规则默认库（software_id='DEFAULT'，不参与拦截；新建软件默认载入、软件黑名单处可载入）
INSERT INTO `name_blacklist` (`pattern`,`match_target`,`match_type`,`case_sensitive`,`software_id`,`enabled`,`note`,`sort_order`,`created_at`,`updated_at`) VALUES
  ('\\.(php|asp|aspx|asax|ascx|jsp|jspx|py|go|java|kt|swift|rs|rb|pl|lua|c|cpp|h|hpp|cc|cxx|cs|js|mjs|ts|tsx|jsx|vue|sql|sh|bash|bat|cmd|ps1|asm|vb|sln|csproj|vcxproj|gradle|classpath)$','name','regex',0,'DEFAULT',1,'常用源码后缀（防误传源码泄露）',1,UTC_TIMESTAMP(),UTC_TIMESTAMP());

-- 文件名黑名单内置示例规则（默认停用，避免误伤）
INSERT INTO `name_blacklist` (`pattern`,`match_target`,`match_type`,`case_sensitive`,`software_id`,`enabled`,`note`,`sort_order`,`created_at`,`updated_at`) VALUES
  ('*.log','name','glob',0,NULL,0,'内置示例：日志文件',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('*.tmp','name','glob',0,NULL,0,'内置示例：临时文件',2,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('*.bak','name','glob',0,NULL,0,'内置示例：备份文件',3,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('*.swp','name','glob',0,NULL,0,'内置示例：交换文件',4,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('Thumbs.db','name','exact',0,NULL,0,'内置示例：缩略图缓存',5,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('.DS_Store','name','exact',0,NULL,0,'内置示例：macOS 目录元数据',6,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('*_debug.*','name','glob',0,NULL,0,'内置示例：调试产物',7,UTC_TIMESTAMP(),UTC_TIMESTAMP());
