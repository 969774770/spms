<?php
declare(strict_types=1);
/**
 * 接口路由分发：action 白名单 → 处理函数
 * 约定：动作名「域.动作」；写操作必须 POST 且携带 X-CSRF-Token；guest=无需登录
 */
function api_routes(): array
{
    return [
        // 阶段1：认证
        'auth.init'           => ['fn' => 'h_auth_init',            'method' => ['GET'],  'guest' => true],
        'auth.login'          => ['fn' => 'h_auth_login',           'method' => ['POST'], 'guest' => true],
        'auth.logout'         => ['fn' => 'h_auth_logout',          'method' => ['POST'], 'guest' => false],
        'auth.me'             => ['fn' => 'h_auth_me',              'method' => ['GET'],  'guest' => false],
        'auth.changePassword' => ['fn' => 'h_auth_change_password', 'method' => ['POST'], 'guest' => false],
        'auth.updateProfile'  => ['fn' => 'h_auth_update_profile',    'method' => ['POST'], 'guest' => false],

        // 阶段2：软件管理
        'software.list'       => ['fn' => 'h_software_list',        'method' => ['GET'],  'guest' => false],
        'software.get'        => ['fn' => 'h_software_get',         'method' => ['GET'],  'guest' => false],
        'software.save'       => ['fn' => 'h_software_save',        'method' => ['POST'], 'guest' => false],
        'software.delete'     => ['fn' => 'h_software_delete',      'method' => ['POST'], 'guest' => false],
        'software.publish'    => ['fn' => 'h_software_publish',     'method' => ['POST'], 'guest' => false],
        'software.publishAll' => ['fn' => 'h_software_publish_all', 'method' => ['POST'], 'guest' => false],
        'software.reorder'    => ['fn' => 'h_software_reorder',     'method' => ['POST'], 'guest' => false],

        // 阶段3：上传
        'upload.probe'        => ['fn' => 'h_upload_probe',         'method' => ['POST'], 'guest' => false],
        'upload.init'         => ['fn' => 'h_upload_init',          'method' => ['POST'], 'guest' => false],
        'upload.part'         => ['fn' => 'h_upload_part',          'method' => ['POST'], 'guest' => false],
        'upload.status'       => ['fn' => 'h_upload_status',        'method' => ['GET'],  'guest' => false],
        'upload.finish'       => ['fn' => 'h_upload_finish',        'method' => ['POST'], 'guest' => false],
        'upload.abort'        => ['fn' => 'h_upload_abort',         'method' => ['POST'], 'guest' => false],

        // 阶段3+5：系统
        'system.cleanup'      => ['fn' => 'h_system_cleanup',       'method' => ['POST'], 'guest' => false],

        // 阶段4：文件管理
        'fs.tree'             => ['fn' => 'h_fs_tree',              'method' => ['GET'],  'guest' => false],
        'fs.ls'               => ['fn' => 'h_fs_ls',                'method' => ['GET'],  'guest' => false],
        'fs.stat'             => ['fn' => 'h_fs_stat',              'method' => ['GET'],  'guest' => false],
        'fs.search'           => ['fn' => 'h_fs_search',            'method' => ['GET'],  'guest' => false],
        'fs.mkdir'            => ['fn' => 'h_fs_mkdir',             'method' => ['POST'], 'guest' => false],
        'fs.dir.rename'       => ['fn' => 'h_fs_dir_rename',        'method' => ['POST'], 'guest' => false],
        'fs.dir.move'         => ['fn' => 'h_fs_dir_move',          'method' => ['POST'], 'guest' => false],
        'fs.dir.copy'         => ['fn' => 'h_fs_dir_copy',          'method' => ['POST'], 'guest' => false],
        'fs.dir.delete'       => ['fn' => 'h_fs_dir_delete',        'method' => ['POST'], 'guest' => false],
        'fs.file.rename'      => ['fn' => 'h_fs_file_rename',       'method' => ['POST'], 'guest' => false],
        'fs.file.note'        => ['fn' => 'h_fs_file_note',         'method' => ['POST'], 'guest' => false],
        'fs.file.active'      => ['fn' => 'h_fs_file_active',       'method' => ['POST'], 'guest' => false],
        'fs.file.autoRun'     => ['fn' => 'h_fs_file_autorun',      'method' => ['POST'], 'guest' => false],
        'fs.file.force'       => ['fn' => 'h_fs_file_force',        'method' => ['POST'], 'guest' => false],
        'fs.file.readText'    => ['fn' => 'h_fs_file_readtext',     'method' => ['GET'],  'guest' => false],
        'fs.file.writeText'   => ['fn' => 'h_fs_file_writetext',    'method' => ['POST'], 'guest' => false],
        'fs.file.move'        => ['fn' => 'h_fs_file_move',         'method' => ['POST'], 'guest' => false],
        'fs.file.copy'        => ['fn' => 'h_fs_file_copy',         'method' => ['POST'], 'guest' => false],
        'fs.batch.move'       => ['fn' => 'h_fs_batch_move',        'method' => ['POST'], 'guest' => false],
        'fs.batch.copy'       => ['fn' => 'h_fs_batch_copy',        'method' => ['POST'], 'guest' => false],
        'fs.batch.delete'     => ['fn' => 'h_fs_batch_delete',      'method' => ['POST'], 'guest' => false],
        'fs.batch.purge'      => ['fn' => 'h_fs_batch_purge',       'method' => ['POST'], 'guest' => false],
        'fs.batch.active'     => ['fn' => 'h_fs_batch_active',      'method' => ['POST'], 'guest' => false],
        'fs.trash.list'       => ['fn' => 'h_fs_trash_list',        'method' => ['GET'],  'guest' => false],
        'fs.trash.restore'    => ['fn' => 'h_fs_trash_restore',     'method' => ['POST'], 'guest' => false],
        'fs.trash.purge'      => ['fn' => 'h_fs_trash_purge',       'method' => ['POST'], 'guest' => false],
        'fs.trash.empty'      => ['fn' => 'h_fs_trash_empty',       'method' => ['POST'], 'guest' => false],

        // 对外客户端接口（guest，无需登录；可选 token 鉴权）
        'client.software'     => ['fn' => 'h_client_software',      'method' => ['GET'],  'guest' => true],
        'client.files'        => ['fn' => 'h_client_files',         'method' => ['GET'],  'guest' => true],

        // 阶段5：用户 / 审计 / 黑名单 / 设置
        'user.list'           => ['fn' => 'h_user_list',            'method' => ['GET'],  'guest' => false],
        'user.save'           => ['fn' => 'h_user_save',            'method' => ['POST'], 'guest' => false],
        'user.delete'         => ['fn' => 'h_user_delete',          'method' => ['POST'], 'guest' => false],
        'user.resetPassword'  => ['fn' => 'h_user_reset_password',  'method' => ['POST'], 'guest' => false],
        'role.list'           => ['fn' => 'h_role_list',            'method' => ['GET'],  'guest' => false],
        'audit.list'          => ['fn' => 'h_audit_list',           'method' => ['GET'],  'guest' => false],
        'audit.export'        => ['fn' => 'h_audit_export',         'method' => ['GET'],  'guest' => false],
        'rules.list'          => ['fn' => 'h_rules_list',           'method' => ['GET'],  'guest' => false],
        'rules.save'          => ['fn' => 'h_rules_save',           'method' => ['POST'], 'guest' => false],
        'rules.delete'        => ['fn' => 'h_rules_delete',         'method' => ['POST'], 'guest' => false],
        'rules.toggle'        => ['fn' => 'h_rules_toggle',         'method' => ['POST'], 'guest' => false],
        'rules.reorder'       => ['fn' => 'h_rules_reorder',        'method' => ['POST'], 'guest' => false],
        'rules.test'          => ['fn' => 'h_rules_test',           'method' => ['POST'], 'guest' => false],
        'rules.import'        => ['fn' => 'h_rules_import',         'method' => ['POST'], 'guest' => false],
        'rules.export'        => ['fn' => 'h_rules_export',         'method' => ['GET'],  'guest' => false],
        'rules.apply'         => ['fn' => 'h_rules_apply',          'method' => ['POST'], 'guest' => false],
        'rules.loadDefault'   => ['fn' => 'h_rules_load_default',   'method' => ['POST'], 'guest' => false],
        'system.stats'        => ['fn' => 'h_system_stats',         'method' => ['GET'],  'guest' => false],
        'system.settings.get' => ['fn' => 'h_system_settings_get',  'method' => ['GET'],  'guest' => false],
        'system.settings.save' => ['fn' => 'h_system_settings_save', 'method' => ['POST'], 'guest' => false],
    ];
}

function api_handle(): void
{
    $t0 = microtime(true);
    $action = (string)($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    try {
        if ($action === '') {
            fail('BAD_REQUEST', '缺少 action 参数');
        }
        if (!preg_match('/^[a-z][a-z0-9]*(\.[A-Za-z][A-Za-z0-9]*)+$/', $action)) {
            fail('BAD_REQUEST', 'action 参数不合法');
        }
        $route = api_routes()[$action] ?? null;
        if (!$route) {
            fail_not_found("未知接口：{$action}");
        }
        if (!in_array($method, $route['method'], true)) {
            fail('BAD_REQUEST', '请求方法不允许，应为 ' . implode('/', $route['method']));
        }
        if (empty($route['guest'])) {
            auth_require();
        }
        // 写操作（POST）一律校验 CSRF，登录接口也不例外（匿名会话先经 auth.init 取 token）
        if (in_array('POST', $route['method'], true)) {
            csrf_verify();
        }
        $fn = $route['fn'];
        if (!function_exists($fn)) {
            fail('SERVER_ERROR', "处理函数缺失：{$fn}", 500);
        }
        json_ok($fn());
    } catch (AppException $e) {
        json_fail($e->errCode, $e->errMsg, $e->http);
    } catch (PDOException $e) {
        app_log('error', ['type' => 'api.pdo', 'action' => $action, 'msg' => $e->getMessage()]);
        json_fail('SERVER_ERROR', '服务端错误', 500);
    } catch (Throwable $e) {
        app_log('error', [
            'type' => 'api.throw', 'action' => $action, 'msg' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
        json_fail('SERVER_ERROR', cfg('debug') ? $e->getMessage() : '服务端错误', 500);
    } finally {
        // 请求访问日志（结构化，UTC）
        app_log('info', [
            'type'   => 'api',
            'action' => $action,
            'method' => $method,
            'user'   => auth_current_user()['username'] ?? '',
            'ms'     => (int)round((microtime(true) - $t0) * 1000),
        ]);
    }
}
