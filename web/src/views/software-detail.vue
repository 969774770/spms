<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { softwareApi } from '../api/software'
import { fmtSize, fmtTime } from '../utils/format'
import { useUserStore } from '../stores/user'
import BlRulesEditor from '../components/bl-rules-editor.vue'

const route = useRoute()
const router = useRouter()
const store = useUserStore()
const softwareId = computed(() => String(route.params.id || ''))

const loading = ref(false)
const saving = ref(false)
const publishing = ref(false)
const info = reactive({ name: '', version: '', style_type: '', description: '', changelog: '', sort_order: 0, status: 1, software_key: '', published_at: null, created_at: null })
const stats = reactive({ files_count: 0, total_size: 0, visible: 0, blocked: 0 })
const formRef = ref()
const rules = { name: [{ required: true, message: '请输入软件名称', trigger: 'blur' }] }

const copyKey = async () => {
  await navigator.clipboard?.writeText(info.software_key).catch(() => {})
  ElMessage.success('software_key 已复制，用于客户端 client.files 接口')
}

async function load() {
  loading.value = true
  try {
    const res = await softwareApi.get(softwareId.value)
    const s = res.software
    Object.assign(info, {
      name: s.name, version: s.version, style_type: s.style_type,
      description: s.description, changelog: s.changelog,
      sort_order: s.sort_order, status: s.status,
      software_key: s.software_key || '',
      published_at: s.published_at, created_at: s.created_at,
    })
    Object.assign(stats, res.stats)
  } finally {
    loading.value = false
  }
}

async function save() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  saving.value = true
  try {
    await softwareApi.save({ id: softwareId.value, ...info, status: info.status === 1 })
    ElMessage.success('已保存')
    load()
  } finally {
    saving.value = false
  }
}

async function saveAndPublish() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  saving.value = true
  try {
    await softwareApi.save({ id: softwareId.value, ...info, status: info.status === 1 })
  } finally {
    saving.value = false
  }
  await doPublish()
}

async function doPublish() {
  publishing.value = true
  try {
    const res = await softwareApi.publish(softwareId.value)
    if (res.excluded_count > 0) {
      ElMessage.warning(`发布完成：对外 ${res.files_count} 项，已屏蔽 ${res.excluded_count} 项命中黑名单`)
    } else {
      ElMessage.success(`发布完成：对外 ${res.files_count} 项`)
    }
    load()
  } finally {
    publishing.value = false
  }
}

async function remove() {
  await ElMessageBox.confirm(
    `确定删除软件「${info.name}」(${softwareId.value})？其对外配置将立即撤下，文件记录保留可恢复。`,
    '删除软件',
    { type: 'warning' },
  )
  await softwareApi.remove(softwareId.value)
  ElMessage.success('已删除')
  router.push('/software')
}

onMounted(load)
</script>

<template>
  <div class="page-card">
    <el-row :gutter="16">
      <el-col :span="16">
        <el-card v-loading="loading" shadow="never">
          <template #header>
            <div class="card-head">
              <span>基础信息（{{ softwareId }}）</span>
              <div>
                <el-tooltip content="客户端 client.files 接口的软件密钥">
                  <el-button @click="copyKey">
                    <el-icon><Key /></el-icon>&nbsp;复制 software_key
                  </el-button>
                </el-tooltip>
                <el-button @click="router.push(`/software/${softwareId}/files`)">文件管理</el-button>
                <el-button type="danger" plain @click="remove">删除软件</el-button>
              </div>
            </div>
          </template>
          <el-form ref="formRef" :model="info" :rules="rules" label-width="86px">
            <el-row :gutter="12">
              <el-col :span="12">
                <el-form-item label="软件名称" prop="name">
                  <el-input v-model="info.name" maxlength="128" show-word-limit />
                </el-form-item>
              </el-col>
              <el-col :span="6">
                <el-form-item label="版本">
                  <el-input v-model="info.version" maxlength="64" />
                </el-form-item>
              </el-col>
              <el-col :span="6">
                <el-form-item label="样式类型">
                  <el-input v-model="info.style_type" maxlength="32" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="描述">
              <el-input v-model="info.description" type="textarea" :rows="3" maxlength="2000" />
            </el-form-item>
            <el-form-item label="更新日志">
              <el-input v-model="info.changelog" type="textarea" :rows="6" maxlength="5000" />
            </el-form-item>
            <el-row :gutter="12">
              <el-col :span="6">
                <el-form-item label="排序值">
                  <el-input-number v-model="info.sort_order" :min="-100000" :max="100000" />
                </el-form-item>
              </el-col>
              <el-col :span="6">
                <el-form-item label="状态">
                  <el-switch v-model="info.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="发布时间">
                  <span class="muted">{{ fmtTime(info.published_at) }}</span>
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="save">保存</el-button>
              <el-button type="success" :loading="publishing" @click="saveAndPublish">保存并发布</el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never">
          <template #header>发布统计</template>
          <div class="stat-row">
            <span>文件总数</span><b>{{ stats.files_count }}</b>
          </div>
          <div class="stat-row">
            <span>占用空间</span><b>{{ fmtSize(stats.total_size) }}</b>
          </div>
          <el-divider />
          <div class="stat-row">
            <span>对外可见</span>
            <b class="c-success">{{ stats.visible }} 项</b>
          </div>
          <div class="stat-row">
            <span>已屏蔽</span>
            <b :class="stats.blocked > 0 ? 'c-danger' : ''">{{ stats.blocked }} 项</b>
          </div>
          <div class="tip">对外可见 = 已启用 且 未命中黑名单。重新发布后生效。</div>
        </el-card>
        <el-card shadow="never" style="margin-top: 16px">
          <template #header>
            <div class="card-head"><span>本软件黑名单（防误传源码）</span></div>
          </template>
          <BlRulesEditor :software-id="softwareId" @changed="load" />
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<style scoped>
.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.tip {
  font-size: 12px;
  color: #909399;
  line-height: 1.5;
}

.stat-row {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  color: #606266;
}

.c-success {
  color: #67c23a;
}

.c-danger {
  color: #f56c6c;
}

.muted {
  color: #909399;
}

.tip2 {
  margin-top: 10px;
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}
</style>
