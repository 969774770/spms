<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { softwareApi } from '../api/software'
import { fmtSize, fmtTime } from '../utils/format'

const router = useRouter()

const loading = ref(false)
const total = ref(0)
const items = ref([])
const query = reactive({ page: 1, page_size: 20, keyword: '', status: '', sort: 'sort_order' })

const dlgVisible = ref(false)
const saving = ref(false)
const formRef = ref()
const form = reactive({ id: '', name: '', version: '', style_type: '', description: '', changelog: '', sort_order: 0, status: true })
const rules = {
  name: [{ required: true, message: '请输入软件名称', trigger: 'blur' }],
}

async function load() {
  loading.value = true
  try {
    const res = await softwareApi.list(query)
    items.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function resetQuery() {
  query.page = 1
  load()
}

function openCreate() {
  Object.assign(form, { id: '', name: '', version: '', style_type: '', description: '', changelog: '', sort_order: 0, status: true })
  dlgVisible.value = true
}

function openEdit(row) {
  Object.assign(form, { ...row, status: row.status === 1 })
  dlgVisible.value = true
}

async function save() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  saving.value = true
  try {
    const res = await softwareApi.save({ ...form })
    dlgVisible.value = false
    ElMessage.success(form.id ? '已保存' : `已创建 ${res.id}`)
    load()
  } finally {
    saving.value = false
  }
}

async function publish(row) {
  const res = await softwareApi.publish(row.id)
  const excluded = res.excluded_count
  ElMessage.success(
    excluded > 0 ? `发布完成：对外 ${res.files_count} 项，已屏蔽 ${excluded} 项` : `发布完成：对外 ${res.files_count} 项`,
  )
  load()
}

async function publishAll() {
  await ElMessageBox.confirm('将按当前规则重新发布全部软件的对外配置，确定执行？', '重新发布全部', { type: 'warning' })
  const res = await softwareApi.publishAll()
  const n = Object.keys(res.results || {}).length
  ElMessage.success(`已重新发布 ${n} 个软件`)
  load()
}

async function remove(row) {
  await ElMessageBox.confirm(
    `确定删除软件「${row.name}」(${row.id})？其对外配置将立即撤下，文件记录保留可恢复。`,
    '删除软件',
    { type: 'warning' },
  )
  await softwareApi.remove(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page-card">
    <el-card shadow="never">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-input
            v-model="query.keyword"
            placeholder="按名称 / ID / 版本搜索"
            clearable
            style="width: 240px"
            @keyup.enter="resetQuery"
            @clear="resetQuery"
          >
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px" @change="resetQuery">
            <el-option label="启用" value="1" />
            <el-option label="停用" value="0" />
          </el-select>
          <el-select v-model="query.sort" style="width: 140px" @change="load">
            <el-option label="按排序值" value="sort_order" />
            <el-option label="按名称" value="name" />
            <el-option label="按更新时间" value="updated_at" />
            <el-option label="按创建时间" value="created_at" />
          </el-select>
        </div>
        <div class="toolbar-right">
          <el-button :icon="'Refresh'" @click="publishAll">重新发布全部</el-button>
          <el-button type="primary" :icon="'Plus'" @click="openCreate">新建软件</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="items" stripe @row-dblclick="(row) => router.push(`/software/${row.id}`)">
        <el-table-column prop="id" label="ID" width="86" />
        <el-table-column prop="name" label="名称" min-width="160" show-overflow-tooltip />
        <el-table-column prop="version" label="版本" width="110" show-overflow-tooltip />
        <el-table-column prop="style_type" label="样式类型" width="110" show-overflow-tooltip />
        <el-table-column prop="files_count" label="文件数" width="90" align="right" />
        <el-table-column label="占用" width="100" align="right">
          <template #default="{ row }">{{ fmtSize(row.total_size) }}</template>
        </el-table-column>
        <el-table-column label="更新时间" width="170">
          <template #default="{ row }">{{ fmtTime(row.updated_at) }}</template>
        </el-table-column>
        <el-table-column label="发布时间" width="170">
          <template #default="{ row }">{{ fmtTime(row.published_at) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right" class-name="row-actions">
          <template #default="{ row }">
            <el-button link type="primary" @click="router.push(`/software/${row.id}/files`)">文件管理</el-button>
            <el-button link type="primary" @click="router.push(`/software/${row.id}`)">编辑</el-button>
            <el-button link type="success" :disabled="row.status !== 1" @click="publish(row)">发布</el-button>
            <el-button link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.page_size"
        class="pager"
        background
        layout="total, sizes, prev, pager, next"
        :total="total"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="load"
        @size-change="resetQuery"
      />
    </el-card>

    <el-dialog v-model="dlgVisible" :title="form.id ? '编辑软件' : '新建软件'" width="560px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="86px">
        <el-form-item label="软件名称" prop="name">
          <el-input v-model="form.name" maxlength="128" show-word-limit placeholder="如：XX客户端" />
        </el-form-item>
        <el-form-item label="版本">
          <el-input v-model="form.version" maxlength="64" placeholder="如：1.2.0" />
        </el-form-item>
        <el-form-item label="样式类型">
          <el-input v-model="form.style_type" maxlength="32" placeholder="业务自定义，如：正式版/内测版" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="form.description" type="textarea" :rows="3" maxlength="2000" />
        </el-form-item>
        <el-form-item label="更新日志">
          <el-input v-model="form.changelog" type="textarea" :rows="5" maxlength="5000" />
        </el-form-item>
        <el-form-item label="排序值">
          <el-input-number v-model="form.sort_order" :min="-100000" :max="100000" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" active-text="启用" inactive-text="停用" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dlgVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
  gap: 10px;
  flex-wrap: wrap;
}

.toolbar-left,
.toolbar-right {
  display: flex;
  gap: 10px;
  align-items: center;
}

.pager {
  margin-top: 14px;
  justify-content: flex-end;
}
</style>
