# 软件发布管理系统（SPMS）

原生 PHP 8.2+ / MySQL 8 / 本机磁盘 CAS 存储 / Vue 3 + Element Plus（hash 路由，零 CDN）。

运维在网页后台管理若干"软件"，向每个软件上传任意目录结构的文件，系统对外提供静态 JSON 配置
（`pub/software-list.json`、`pub/configs/{id}.json`）与签名下载直链（`download.php?t=`），供现场客户端拉取更新。

> 不依赖任何伪静态/重写规则；服务器无需 composer、无需 node。把重写规则清空，功能一个不少。

## 目录

```
站点根/
  index.html  assets/        前端构建产物（纯静态）
  api.php                     唯一接口入口 ?action=域.动作
  download.php                受控下载（HMAC token + Range 断点续传）
  pub/                        对外静态配置（客户端直读）
  storage/                    运行期数据（php-fpm 用户可写）
    objects/{hash前2位}/{hash}  内容寻址对象（无扩展名，天然不可当脚本执行）
    tmp/  logs/  cache/
  inc/                        后端手写库（bootstrap/config/db/response/request/auth/csrf/
                              audit/settings/storage/fs/visibility/publish/upload/token/cleanup/log
                              + handlers/ 各域处理器）
  tools/                      CLI：cleanup.php（清理，建议每 30 分钟） backup.php（备份）
  sql/schema.sql              建表脚本（含初始角色与 admin 账号）
web/                          前端源码（仅开发机，不部署）
```

## 环境要求

- Nginx + PHP **8.2+**（php-fpm），扩展：`pdo_mysql` `mbstring` `json` `zip` `fileinfo`
- MySQL 8（独立库 + 独立账号，仅本库权限）
- php-fpm 用户（www）对 `storage/` 可读写；其余代码只读
- 可选 php.ini 建议：`post_max_size` / `upload_max_filesize` ≥ 分片大小（默认 5MB）

## 部署步骤

1. 建库导表：创建数据库（utf8mb4），导入 `sql/schema.sql`。初始账号 **admin / admin123，登录后立即改密**。
2. 修改 `inc/config.local.php`：填入数据库连接信息（host/port/name/user/pass）。
3. 拷贝以下内容到站点根：`api.php` `download.php` `index.html` `assets/` `inc/` `tools/` `sql/`（`pub/`、`storage/` 首次访问自动创建）。
4. 权限：`chown -R www:www 站点根`；确认 `storage/` 可写。
5. 宝塔计划任务（Shell，每 30 分钟）：
   ```bash
   php /www/wwwroot/站点目录/tools/cleanup.php
   ```
6. 浏览器打开站点登录。对外契约自检：
   - `GET /pub/software-list.json`
   - `GET /pub/configs/{软件ID}.json`（发布后生成）

## 前端构建（仅开发机）

```bash
cd web
npm install
npm run build     # 产物 dist/（index.html + assets/），与后端一起放入站点根
```

开发调试：`npm run dev`，`API_PROXY=http://服务器 npm run dev` 可把接口代理到远端。

## 黑名单（下发过滤）

规则分三层（过滤唯一出口 `inc/visibility.php`，规则间"或"合并）：

1. **软件专属黑名单**（software_id=S0001）：软件详情页编辑；新建软件时自动载入常用规则库，也可点「载入默认规则」。
2. **兜底黑名单**（software_id=NULL）：系统设置 → 兜底黑名单页签；对全部软件生效，在软件黑名单过滤后**再次拦截**。
3. **常用规则默认库**（software_id='DEFAULT'）：系统设置 → 常用规则默认库页签；**不参与拦截**，仅作为模板——新建软件默认载入、软件黑名单处可一键载入。

- 匹配方式：glob（`*` 不跨 `/`、`**` 跨） / 精确 / 包含 / 正则；对象可限文件名或相对路径；可启停、排序（admin 维护兜底与默认库，软件专属 admin+publisher）。
- 语义：**对外可见 = 已启用(active) 且 未命中 ①② 两层黑名单**；命中文件允许上传、允许管理员在后台查看下载，
  仅在面向客户端的配置中隐藏，且其签名下载链接对外失效。
- 规则变更后点「按新规则重新发布全部软件」对客户端生效。

## 客户端更新接口（给后端更新程序）

无需登录；「系统设置 → 客户端 Token」非空时须携带 `?token=` 或请求头 `X-Client-Token`。

```
GET /api.php?action=client.software                              # 软件清单
GET /api.php?action=client.files&software_key=<32位密钥>          # 指定软件的文件列表
```

`software_key` 是每个软件独立的 32 位十六进制密钥，在后台「软件详情 → 复制 software_key」获取；
软件被停用或删除后该 key 自动失效。

`client.files` 响应（与 `/pub/configs/{id}.json` 完全一致，实时计算，黑名单过滤同一出口）：

```json
{
  "id": "S0001", "name": "...", "version": "1.0.0", "updated_at": "...",
  "excluded_count": 0,
  "files": [
    {
      "path": "",                  // 相对目录，""=根目录，"/" 分隔
      "name": "setup.exe",
      "size": 12345,
      "hash": "<sha256>",
      "url": "/download.php?t=...", // 签名下载直链（默认 24h 有效，支持 Range 断点续传）
      "active": true,
      "auto_run": true,            // 客户端下载完成后是否自动运行该文件
      "force_overwrite": true,     // true=下载后覆盖本地同名文件（默认）；false=本地已存在则跳过
      "modified_at": "...",
      "note": ""
    }
  ]
}
```

文件列表管理端操作语义：
- 「启停」开关：是否把该文件下发到客户端更新列表（停用后列表立即消失但文件保留在后台）
- 「自启」开关：`auto_run`，客户端把文件下载完毕后是否自动运行（不影响是否下发）
- 「覆盖」开关：`force_overwrite`，默认开启=下载后覆盖本地同名文件；关闭后客户端对本地已存在的同名文件跳过下载
- 文件名黑名单：命中者不出现在列表中、下载链接对外失效（管理员会话不受限）

## 备份与恢复

```bash
php tools/backup.php [输出目录] [--with-objects]
# 产出：spms_db_时间.sql（mysqldump，缺失时 PHP 兜底导出）
#       spms_objects_时间.json（对象清单：哈希/大小/引用计数）
#       --with-objects 时附加 storage/objects 实体 tar.gz
```

恢复：导入 SQL → 将 `objects/` 实体放回 `storage/objects/` →（可选）与清单核对哈希。

## 安全要点

- 密码 `password_hash()`；登录限流（同账号或同 IP 失败 5 次锁 15 分钟）
- 写操作强制 CSRF（`X-CSRF-Token`）；全部 SQL PDO 预处理
- 路径由 软件ID + 目录记录 + 对象哈希 推导，用户输入仅作白名单校验（禁止 `..\ / : * ? " < > |`）
- 上传对象以哈希落盘、无扩展名；`storage/objects/` 内文件永不被当作 PHP 执行
- 关键动作全部写 `audit_logs`（含 IP / UA）

## 默认账号

| 账号 | 密码 | 角色 |
|---|---|---|
| admin | admin123 | 管理员（首次登录请立即修改） |
