<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'

/**
 * 清理任务接入指南（计划任务 / API 调用格式）
 * CLI 与接口共用 inc/cleanup.php 的 cleanup_run()，幂等可重复执行
 */
const cliCmd = '/usr/bin/php /www/wwwroot/updata.wwww.cab/tools/cleanup.php'
const apiSteps = `# 1) 初始化匿名会话（返回 csrf_token，同时收到 Cookie）
curl -c /tmp/spms.cookie "https://updata.wwww.cab/api.php?action=auth.init"

# 2) 登录管理员（Cookie + X-CSRF-Token）
curl -b /tmp/spms.cookie -H "X-CSRF-Token: <上一步返回的csrf_token>" \\
  -H "Content-Type: application/json" \\
  -d '{"username":"admin","password":"你的密码"}' \\
  "https://updata.wwww.cab/api.php?action=auth.login"

# 3) 触发清理（返回清理统计：purged_files / purged_objects / expired_sessions）
curl -b /tmp/spms.cookie -H "X-CSRF-Token: <登录后返回的新csrf_token>" \\
  -H "Content-Type: application/json" -d '{}' \\
  "https://updata.wwww.cab/api.php?action=system.cleanup"`

const btCmd = `# 宝塔面板 -> 计划任务 -> Shell 脚本（每 30 分钟）
${cliCmd} >> /www/wwwroot/updata.wwww.cab/storage/logs/cleanup.log 2>&1`

function urlCmd() {
  const key = secret.value || '<清理密钥>'
  return `# 宝塔面板 -> 计划任务 -> 访问URL（需先在左侧表单配置「清理密钥」）
https://updata.wwww.cab/tools/cleanup.php?key=${key}`
}

async function copy(text) {
  await navigator.clipboard?.writeText(text).catch(() => {})
  ElMessage.success('已复制')
}
const active = ref(['cli'])
const secret = defineModel('secret', { type: String, default: '' })
</script>

<template>
  <el-collapse v-model="active" class="clean-guide">
    <el-collapse-item name="cli">
      <template #title>
        <span class="g-title">计划任务（推荐，无需登录态）</span>
        <el-button link type="primary" size="small" class="g-copy" @click.stop="copy(btCmd)">复制</el-button>
      </template>
      <div class="g-tip">
        清理脚本幂等可重复执行；周期越短，回收站与待清理对象越接近「保留期 = 系统设置中的天数」。
        建议宝塔计划任务每 30 分钟执行一次：
      </div>
      <pre class="g-code">{{ btCmd }}</pre>
    </el-collapse-item>
    <el-collapse-item name="url">
      <template #title>
        <span class="g-title">访问 URL 方式（宝塔「访问URL」计划任务）</span>
        <el-button link type="primary" size="small" class="g-copy" @click.stop="copy(urlCmd())">复制</el-button>
      </template>
      <div class="g-tip">
        须先在上方表单配置「清理密钥」（留空 = 禁用 URL 触发，直接访问返回 403）。密钥以 URL 参数传递，
        安全性低于 CLI 方式，建议仅在无法使用 Shell 脚本时使用：
      </div>
      <pre class="g-code">{{ urlCmd() }}</pre>
    </el-collapse-item>
    <el-collapse-item name="api">
      <template #title>
        <span class="g-title">API 调用格式（system.cleanup，需管理员会话）</span>
        <el-button link type="primary" size="small" class="g-copy" @click.stop="copy(apiSteps)">复制</el-button>
      </template>
      <div class="g-tip">
        管理端接口需登录态与 CSRF Token；POST 写操作必须携带 X-CSRF-Token 头。
      </div>
      <pre class="g-code">{{ apiSteps }}</pre>
    </el-collapse-item>
    <el-collapse-item name="rule">
      <template #title><span class="g-title">清理规则与保留期</span></template>
      <ul class="g-list">
        <li>回收站条目超「回收站保留天数」→ 自动彻底删除记录行（引用计数 -1）</li>
        <li>对象引用归零且超过「对象保留天数」→ 物理删除 storage/objects/ 实体</li>
        <li>上传会话超 24 小时未完成、孤立分片、超期日志 → 一并清理</li>
        <li>同一实体被多个文件共享（秒传/复制）时，引用未归零绝不删除</li>
      </ul>
    </el-collapse-item>
  </el-collapse>
</template>

<style scoped>
.clean-guide {
  border-top: 1px solid #ebeef5;
  margin-top: 12px;
}

.g-title {
  font-size: 13px;
  color: #606266;
}

.g-copy {
  margin-left: auto;
  margin-right: 8px;
}

.g-tip {
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
  margin-bottom: 6px;
}

.g-code {
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  padding: 8px 10px;
  font-size: 12px;
  line-height: 1.7;
  overflow-x: auto;
  margin: 0;
  white-space: pre;
}

.g-list {
  margin: 0;
  padding-left: 18px;
  font-size: 12px;
  color: #606266;
  line-height: 1.9;
}
</style>
