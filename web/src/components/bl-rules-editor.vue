<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { api, post } from '../api/request'

/**
 * 软件专属黑名单规则编辑器（防误传源码泄露）
 * props.softwareId 固定归属；编辑模式与系统设置一致（方式/对象/状态/操作）
 */
const props = defineProps({ softwareId: { type: String, required: true } })
const emit = defineEmits(['changed'])

const loading = ref(false)
const loadingDefault = ref(false)
const rules = ref([])
const ruleDlg = ref(false)
const ruleSaving = ref(false)
const ruleForm = reactive({ id: null, pattern: '', match_target: 'name', match_type: 'regex', case_sensitive: false, enabled: true, note: '' })

const TYPE_TEXT = { glob: 'glob', exact: '精确', contains: '包含', regex: '正则' }

async function load() {
  loading.value = true
  try {
    rules.value = (await api('rules.list', { software_id: props.softwareId })).rules
  } finally {
    loading.value = false
  }
}
async function loadDefault() {
  loadingDefault.value = true
  try {
    const res = await post('rules.loadDefault', { software_id: props.softwareId })
    ElMessage.success(`已载入 ${res.loaded} 条默认规则${res.skipped ? `（跳过 ${res.skipped} 条已存在）` : ''}`)
    load()
    emit('changed')
  } finally {
    loadingDefault.value = false
  }
}
function openCreate() {
  Object.assign(ruleForm, { id: null, pattern: '', match_target: 'name', match_type: 'regex', case_sensitive: false, enabled: true, note: '' })
  ruleDlg.value = true
}
function openEdit(r) {
  Object.assign(ruleForm, { ...r, case_sensitive: r.case_sensitive === 1, enabled: r.enabled === 1 })
  ruleDlg.value = true
}
async function saveRule() {
  if (!ruleForm.pattern.trim()) {
    ElMessage.warning('模式不能为空')
    return
  }
  ruleSaving.value = true
  try {
    await post('rules.save', { ...ruleForm, software_id: props.softwareId })
    ruleDlg.value = false
    ElMessage.success('规则已保存，立即生效')
    load()
    emit('changed')
  } finally {
    ruleSaving.value = false
  }
}
async function toggleRule(r) {
  await post('rules.toggle', { id: r.id, enabled: r.enabled !== 1 })
  ElMessage.success('已更新')
  load()
  emit('changed')
}
async function delRule(r) {
  await ElMessageBox.confirm(`删除规则「${r.pattern}」？`, '删除规则', { type: 'warning' })
  await post('rules.delete', { id: r.id })
  load()
  emit('changed')
}
async function reorderRules(id, dir) {
  const ids = rules.value.map((r) => r.id)
  const idx = ids.indexOf(id)
  const to = idx + dir
  if (to < 0 || to >= ids.length) return
  ids.splice(to, 0, ...ids.splice(idx, 1))
  await post('rules.reorder', { ids })
  load()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="bl-bar">
      <span class="bl-tip">命中的文件不出现在客户端更新列表（可正常上传与后台下载）。范围：仅本软件。</span>
      <el-button size="small" :icon="'Download'" @click="loadDefault" :loading="loadingDefault">载入默认规则</el-button>
      <el-button size="small" type="primary" :icon="'Plus'" @click="openCreate">新增规则</el-button>
    </div>
    <el-table :data="rules" size="small" v-loading="loading" empty-text="暂无本软件专属规则">
      <el-table-column label="模式" min-width="140" show-overflow-tooltip>
        <template #default="{ row }"><code>{{ row.pattern }}</code></template>
      </el-table-column>
      <el-table-column label="方式" width="70">
        <template #default="{ row }">{{ TYPE_TEXT[row.match_type] }}</template>
      </el-table-column>
      <el-table-column label="对象" width="70">
        <template #default="{ row }">{{ row.match_target === 'name' ? '文件名' : '路径' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="70">
        <template #default="{ row }">
          <el-switch :model-value="row.enabled === 1" size="small" @change="toggleRule(row)" />
        </template>
      </el-table-column>
      <el-table-column label="备注" min-width="100" show-overflow-tooltip>
        <template #default="{ row }">{{ row.note || '-' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="150" class-name="row-actions">
        <template #default="{ row }">
          <el-button link type="primary" @click="reorderRules(row.id, -1)">↑</el-button>
          <el-button link type="primary" @click="reorderRules(row.id, 1)">↓</el-button>
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="delRule(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="ruleDlg" :title="ruleForm.id ? '编辑规则' : '新增规则'" width="500px" destroy-on-close append-to-body>
      <el-form label-width="90px" size="small">
        <el-form-item label="模式">
          <el-input v-model="ruleForm.pattern" maxlength="200" placeholder="正则示例：\.(php|py|java)$　glob 示例：*.php" />
        </el-form-item>
        <el-form-item label="匹配方式">
          <el-radio-group v-model="ruleForm.match_type">
            <el-radio-button value="regex">正则</el-radio-button>
            <el-radio-button value="glob">glob</el-radio-button>
            <el-radio-button value="exact">精确</el-radio-button>
            <el-radio-button value="contains">包含</el-radio-button>
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
        <el-form-item label="状态">
          <el-switch v-model="ruleForm.enabled" active-text="启用" inactive-text="停用" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="ruleForm.note" maxlength="255" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="ruleDlg = false">取消</el-button>
        <el-button type="primary" :loading="ruleSaving" @click="saveRule">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.bl-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.bl-tip {
  font-size: 12px;
  color: #909399;
}
</style>
