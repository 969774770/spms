<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { api, post } from '../api/request'
import { fmtTime } from '../utils/format'
import CleanGuide from '../components/clean-guide.vue'

// ---------- 系统设置 ----------
const loading = ref(true)
const saving = ref(false)
const settings = reactive({
  site_name: '', max_file_size: '', upload_total_limit: '', chunk_size: '',
  trash_retention_days: '', object_retention_days: '', login_fail_limit: '',
  login_lock_minutes: '', disk_alert_percent: '', download_domain: '', client_api_token: '',
  cleanup_secret: '', storage_writable: false,
})
const MB = 1024 * 1024
const form = reactive({
  site_name: '', max_file_mb: '', upload_total_mb: '', chunk_mb: '',
  trash_days: '', object_days: '', login_fail_limit: '', login_lock_minutes: '',
  disk_alert_percent: '', download_domain: '', client_api_token: '', cleanup_secret: '',
})

async function load() {
  loading.value = true
  try {
    const res = await api('system.settings.get')
    Object.assign(settings, res.settings)
    form.site_name = settings.site_name
    form.max_file_mb = Math.round(Number(settings.max_file_size) / MB)
    form.upload_total_mb = Math.round(Number(settings.upload_total_limit) / MB)
    form.chunk_mb = Math.round(Number(settings.chunk_size) / MB)
    form.trash_days = settings.trash_retention_days
    form.object_days = settings.object_retention_days
    form.login_fail_limit = settings.login_fail_limit
    form.login_lock_minutes = settings.login_lock_minutes
    form.disk_alert_percent = settings.disk_alert_percent
    form.download_domain = settings.download_domain
    form.client_api_token = settings.client_api_token
    form.cleanup_secret = settings.cleanup_secret
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!settings.storage_writable) {
    ElMessage.error('storage 目录不可写，无法保存')
    return
  }
  saving.value = true
  try {
    await post('system.settings.save', { settings: {
      site_name: form.site_name,
      max_file_size: String(Number(form.max_file_mb) * MB),
      upload_total_limit: String(Number(form.upload_total_mb) * MB),
      chunk_size: String(Number(form.chunk_mb) * MB),
      trash_retention_days: form.trash_days,
      object_retention_days: form.object_days,
      login_fail_limit: form.login_fail_limit,
      login_lock_minutes: form.login_lock_minutes,
      disk_alert_percent: form.disk_alert_percent,
      download_domain: form.download_domain,
      client_api_token: form.client_api_token,
      cleanup_secret: form.cleanup_secret,
    } })
    ElMessage.success('设置已保存')
    load()
  } finally {
    saving.value = false
  }
}

async function manualCleanup() {
  await ElMessageBox.confirm('手动执行清理任务（过期会话/回收站/待清理对象）？', '清理')
  const res = await post('system.cleanup', {})
  ElMessage.success(`清理完成：文件 ${res.purged_files}，对象 ${res.purged_objects}，会话 ${res.expired_sessions}`)
}

// ---------- 黑名单：常用规则默认库 + 兜底黑名单 ----------
const rulesLoading = ref(false)
const rulesMap = reactive({ default: [], global: [] })
const ruleDlg = ref(false)
const ruleSaving = ref(false)
const ruleForm = reactive({ id: null, pattern: '', match_target: 'name', match_type: 'glob', case_sensitive: false, enabled: true, note: '', scope: 'global' })
const testInput = ref('')
const testResults = ref([])
const TYPE_TEXT = { glob: 'glob', exact: '精确', contains: '包含', regex: '正则' }
const blTab = ref('default')

async function loadRules() {
  rulesLoading.value = true
  try {
    const [d, g] = await Promise.all([api('rules.list', { scope: 'default' }), api('rules.list', { scope: 'global' })])
    rulesMap.default = d.rules
    rulesMap.global = g.rules
  } finally {
    rulesLoading.value = false
  }
}
function openRuleCreate(scope) {
  Object.assign(ruleForm, { id: null, pattern: '', match_target: 'name', match_type: 'glob', case_sensitive: false, enabled: true, note: '', scope })
  ruleDlg.value = true
}
function openRuleEdit(r, scope) {
  Object.assign(ruleForm, { ...r, case_sensitive: r.case_sensitive === 1, enabled: r.enabled === 1, scope })
  ruleDlg.value = true
}
async function saveRule() {
  if (!ruleForm.pattern.trim()) {
    ElMessage.warning('模式不能为空')
    return
  }
  ruleSaving.value = true
  try {
    await post('rules.save', {
      id: ruleForm.id, pattern: ruleForm.pattern, match_target: ruleForm.match_target,
      match_type: ruleForm.match_type, case_sensitive: ruleForm.case_sensitive,
      enabled: ruleForm.enabled, note: ruleForm.note,
      software_id: ruleForm.scope === 'default' ? 'DEFAULT' : undefined,
    })
    ruleDlg.value = false
    ElMessage.success(ruleForm.scope === 'default' ? '默认库规则已保存（不直接拦截，供软件载入）' : '兜底规则已保存，需「重新发布全部」对客户端生效')
    loadRules()
  } finally {
    ruleSaving.value = false
  }
}
async function toggleRule(r, scope) {
  await post('rules.toggle', { id: r.id, enabled: r.enabled !== 1 })
  ElMessage.success('已更新')
  loadRules()
}
async function delRule(r, scope) {
  await ElMessageBox.confirm(`删除规则「${r.pattern}」？`, '删除规则', { type: 'warning' })
  await post('rules.delete', { id: r.id })
  loadRules()
}
async function reorderRules(scope, id, dir) {
  const ids = rulesMap[scope].map((r) => r.id)
  const idx = ids.indexOf(id)
  const to = idx + dir
  if (to < 0 || to >= ids.length) return
  ids.splice(to, 0, ...ids.splice(idx, 1))
  await post('rules.reorder', { ids })
  loadRules()
}
async function runTest() {
  const lines = testInput.value.split('\n').map((s) => s.trim()).filter(Boolean)
  if (!lines.length) {
    ElMessage.warning('输入要测试的文件名（每行一条）')
    return
  }
  testResults.value = (await post('rules.test', { inputs: lines })).results
}
async function importRules() {
  const { value } = await ElMessageBox.prompt('粘贴规则（每行一条 glob，或以 { 开头的 JSON 全量）', '导入规则')
  const body = value.trim().startsWith('{') || value.trim().startsWith('[') ? { json: JSON.parse(value) } : { text: value }
  const res = await post('rules.import', body)
  ElMessage.success(`导入 ${res.imported} 条${res.failed.length ? `，失败 ${res.failed.length} 条` : ''}。需「重新发布全部」生效`)
  loadRules()
}
function exportRules(withMeta) {
  window.open(`/api.php?action=rules.export${withMeta ? '&with_meta=1' : ''}`)
}
async function applyRules() {
  await ElMessageBox.confirm(
    '将按当前规则重新发布全部软件的对外配置，客户端将立即按新规则获取文件列表。确定执行？',
    '重新发布全部', { type: 'warning' },
  )
  const res = await post('rules.apply', {})
  ElMessage.success(`已重新发布 ${Object.keys(res.results).length} 个软件`)
}

onMounted(async () => {
  await load()
  loadRules()
})
</script>

<template>
  <div class="page-card" v-loading="loading">
    <el-row :gutter="16">
      <el-col :span="14">
        <el-card shadow="never" header="系统设置">
          <el-form label-width="130px" size="small">
            <el-form-item label="站点名称"><el-input v-model="form.site_name" maxlength="64" /></el-form-item>
            <el-form-item label="单文件上限 (MB)"><el-input-number v-model="form.max_file_mb" :min="1" :max="1048576" /></el-form-item>
            <el-form-item label="单次上传上限 (MB)"><el-input-number v-model="form.upload_total_mb" :min="1" /></el-form-item>
            <el-form-item label="分片大小 (MB)"><el-input-number v-model="form.chunk_mb" :min="1" :max="50" /></el-form-item>
            <el-form-item label="回收站保留 (天)"><el-input-number v-model="form.trash_days" :min="1" :max="365" /></el-form-item>
            <el-form-item label="对象保留 (天)"><el-input-number v-model="form.object_days" :min="1" :max="365" /></el-form-item>
            <el-form-item label="登录失败阈值"><el-input-number v-model="form.login_fail_limit" :min="1" :max="100" /></el-form-item>
            <el-form-item label="锁定时长 (分钟)"><el-input-number v-model="form.login_lock_minutes" :min="1" :max="1440" /></el-form-item>
            <el-form-item label="磁盘告警 (%)"><el-input-number v-model="form.disk_alert_percent" :min="1" :max="90" /></el-form-item>
            <el-form-item label="下载域名前缀">
              <el-input v-model="form.download_domain" placeholder="如 https://dl.example.com（留空用当前域名）" />
            </el-form-item>
            <el-form-item label="客户端 Token">
              <el-input v-model="form.client_api_token" placeholder="client.files 接口鉴权，留空=开放" />
              <div class="tip2">非空时客户端调用 /api.php?action=client.files 须携带 ?token= 或 X-Client-Token 头</div>
            </el-form-item>
            <el-form-item label="清理密钥">
              <el-input v-model="form.cleanup_secret" placeholder="tools/cleanup.php 的 URL 触发密钥，留空=禁用" />
              <div class="tip2">非空时可用 https://域名/tools/cleanup.php?key=密钥 触发清理（宝塔「访问URL」计划任务）</div>
            </el-form-item>
            <el-form-item label="存储可写">
              <el-tag :type="settings.storage_writable ? 'success' : 'danger'" size="small">{{ settings.storage_writable ? '可写' : '不可写' }}</el-tag>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="save">保存设置</el-button>
              <el-button @click="manualCleanup">手动清理</el-button>
            </el-form-item>
            <CleanGuide v-model:secret="form.cleanup_secret" />
          </el-form>
        </el-card>
      </el-col>
      <el-col :span="10">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>文件名黑名单</span>
              <div>
                <el-button size="small" :icon="'Plus'" @click="openRuleCreate(blTab)">新增</el-button>
                <el-button size="small" @click="importRules">导入</el-button>
                <el-button size="small" @click="exportRules(false)">导出TXT</el-button>
                <el-button size="small" @click="exportRules(true)">导出JSON</el-button>
              </div>
            </div>
          </template>
          <div class="rules-tip">软件黑名单过滤后，兜底黑名单再次过滤。常用规则默认库不直接拦截，供新建软件默认载入、或在软件黑名单处「载入默认规则」。</div>
          <el-tabs v-model="blTab">
            <el-tab-pane label="常用规则默认库" name="default">
              <div class="bl-bar">
                <span class="tip2">规则模板库，不参与拦截。新建软件自动载入。</span>
                <el-button size="small" type="primary" :icon="'Plus'" @click="openRuleCreate('default')">新增</el-button>
              </div>
              <el-table :data="rulesMap.default" size="small" max-height="280" v-loading="rulesLoading">
                <el-table-column label="模式" min-width="130" show-overflow-tooltip>
                  <template #default="{ row }"><code>{{ row.pattern }}</code></template>
                </el-table-column>
                <el-table-column label="方式" width="66">
                  <template #default="{ row }">{{ TYPE_TEXT[row.match_type] }}</template>
                </el-table-column>
                <el-table-column label="对象" width="64">
                  <template #default="{ row }">{{ row.match_target === 'name' ? '文件名' : '路径' }}</template>
                </el-table-column>
                <el-table-column label="状态" width="66">
                  <template #default="{ row }">
                    <el-switch :model-value="row.enabled === 1" size="small" @change="toggleRule(row, 'default')" />
                  </template>
                </el-table-column>
                <el-table-column label="操作" width="150" class-name="row-actions">
                  <template #default="{ row }">
                    <el-button link type="primary" @click="reorderRules('default', row.id, -1)">↑</el-button>
                    <el-button link type="primary" @click="reorderRules('default', row.id, 1)">↓</el-button>
                    <el-button link type="primary" @click="openRuleEdit(row, 'default')">编辑</el-button>
                    <el-button link type="danger" @click="delRule(row, 'default')">删除</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </el-tab-pane>
            <el-tab-pane label="兜底黑名单（全局）" name="global">
              <div class="bl-bar">
                <span class="tip2">对全部软件生效，在软件黑名单过滤后再次拦截。变更后需重新发布。</span>
                <el-button size="small" type="primary" :icon="'Plus'" @click="openRuleCreate('global')">新增</el-button>
              </div>
              <el-table :data="rulesMap.global" size="small" max-height="280" v-loading="rulesLoading">
                <el-table-column label="模式" min-width="130" show-overflow-tooltip>
                  <template #default="{ row }"><code>{{ row.pattern }}</code></template>
                </el-table-column>
                <el-table-column label="方式" width="66">
                  <template #default="{ row }">{{ TYPE_TEXT[row.match_type] }}</template>
                </el-table-column>
                <el-table-column label="对象" width="64">
                  <template #default="{ row }">{{ row.match_target === 'name' ? '文件名' : '路径' }}</template>
                </el-table-column>
                <el-table-column label="状态" width="66">
                  <template #default="{ row }">
                    <el-switch :model-value="row.enabled === 1" size="small" @change="toggleRule(row, 'global')" />
                  </template>
                </el-table-column>
                <el-table-column label="操作" width="150" class-name="row-actions">
                  <template #default="{ row }">
                    <el-button link type="primary" @click="reorderRules('global', row.id, -1)">↑</el-button>
                    <el-button link type="primary" @click="reorderRules('global', row.id, 1)">↓</el-button>
                    <el-button link type="primary" @click="openRuleEdit(row, 'global')">编辑</el-button>
                    <el-button link type="danger" @click="delRule(row, 'global')">删除</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </el-tab-pane>
          </el-tabs>

          <el-divider content-position="left">规则测试（按实际拦截效果：软件专属 + 兜底）</el-divider>
          <el-input v-model="testInput" type="textarea" :rows="3" placeholder="每行一个文件名或相对路径" />
          <div style="margin-top: 6px">
            <el-button size="small" type="primary" plain @click="runTest">试跑</el-button>
            <el-button size="small" type="warning" plain @click="applyRules">按新规则重新发布全部软件</el-button>
          </div>
          <el-table v-if="testResults.length" :data="testResults" size="small" style="margin-top: 8px" max-height="200">
            <el-table-column prop="input" label="输入" min-width="140" show-overflow-tooltip />
            <el-table-column label="结论" width="90">
              <template #default="{ row }">
                <el-tag :type="row.blocked ? 'danger' : 'success'" size="small">{{ row.blocked ? '命中' : '通过' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="命中规则" min-width="120">
              <template #default="{ row }">{{ row.rules.map((r) => r.pattern).join('、') || '-' }}</template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>

    <el-dialog v-model="ruleDlg" :title="ruleForm.id ? '编辑规则' : '新增规则'" width="500px" destroy-on-close>
      <el-form label-width="90px" size="small">
        <el-form-item label="模式">
          <el-input v-model="ruleForm.pattern" maxlength="200" placeholder="如 *.log 或 ^debug_.*\.log$（正则）" />
        </el-form-item>
        <el-form-item label="匹配方式">
          <el-radio-group v-model="ruleForm.match_type">
            <el-radio-button value="glob">glob</el-radio-button>
            <el-radio-button value="exact">精确</el-radio-button>
            <el-radio-button value="contains">包含</el-radio-button>
            <el-radio-button value="regex">正则</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="匹配对象">
          <el-radio-group v-model="ruleForm.match_target">
            <el-radio-button value="name">文件名</el-radio-button>
            <el-radio-button value="path">相对路径</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="大小写">
          <el-switch v-model="ruleForm.case_sensitive" active-text="敏感" inactive-text="不敏感" />
        </el-form-item>
        <el-form-item label="适用范围">
          <el-tag size="small" :type="ruleForm.scope === 'default' ? 'warning' : 'info'">
            {{ ruleForm.scope === 'default' ? '常用规则默认库（不拦截）' : '兜底黑名单（全局拦截）' }}
          </el-tag>
        </el-form-item>
        <el-form-item label="备注"><el-input v-model="ruleForm.note" maxlength="255" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="ruleForm.enabled" active-text="启用" inactive-text="停用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="ruleDlg = false">取消</el-button>
        <el-button type="primary" :loading="ruleSaving" @click="saveRule">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.tip2 {
  font-size: 11px;
  color: #909399;
  line-height: 1.5;
}

.rules-tip {
  font-size: 12px;
  color: #909399;
  margin-bottom: 8px;
}

.bl-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
</style>
