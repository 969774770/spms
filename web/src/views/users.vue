<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import http, { api, post } from '../api/request'
import { ROLE_NAMES, fmtTime } from '../utils/format'
import { useUserStore } from '../stores/user'

const user = useUserStore()
const loading = ref(false)
const total = ref(0)
const items = ref([])
const roles = ref([])
const softwareList = ref([])
const query = reactive({ page: 1, page_size: 20, keyword: '' })

const dlg = ref(false)
const saving = ref(false)
const formRef = ref()
const form = reactive({ id: null, username: '', nickname: '', password: '', status: true, roles: ['viewer'], scope: [] })
const rules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  roles: [{ required: true, type: 'array', min: 1, message: '至少一个角色', trigger: 'change' }],
}

async function load() {
  loading.value = true
  try {
    const res = await api('user.list', query)
    items.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

async function loadMeta() {
  roles.value = (await api('role.list')).roles
  const sw = await api('software.list', { page: 1, page_size: 100 })
  softwareList.value = sw.items.map((s) => ({ id: s.id, name: s.name }))
}

function openCreate() {
  Object.assign(form, { id: null, username: '', nickname: '', password: '', status: true, roles: ['viewer'], scope: [] })
  dlg.value = true
}
function openEdit(row) {
  Object.assign(form, { id: row.id, username: row.username, nickname: row.nickname, password: '', status: row.status === 1, roles: [...row.roles], scope: [...row.scope] })
  dlg.value = true
}
async function save() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  saving.value = true
  try {
    await post('user.save', { ...form })
    dlg.value = false
    ElMessage.success(form.id ? '已保存' : '已创建')
    load()
  } finally {
    saving.value = false
  }
}
async function remove(row) {
  await ElMessageBox.confirm(`确定删除用户「${row.username}」？`, '删除用户', { type: 'warning' })
  await post('user.delete', { id: row.id })
  ElMessage.success('已删除')
  load()
}
async function resetPwd(row) {
  const { value } = await ElMessageBox.prompt(`为「${row.username}」设置新密码（至少 6 位）`, '重置密码')
  await post('user.resetPassword', { id: row.id, password: value })
  ElMessage.success('密码已重置')
}
async function exportCsv() {
  const resp = await http.get('/api.php?action=audit.export', { responseType: 'blob' })
  const url = URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = `audit_${Date.now()}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

onMounted(() => {
  load()
  loadMeta()
})
</script>

<template>
  <div class="page-card">
    <el-card shadow="never">
      <div class="toolbar">
        <el-input v-model="query.keyword" placeholder="按用户名 / 昵称搜索" clearable style="width: 220px" @keyup.enter="query.page = 1; load()" @clear="query.page = 1; load()">
          <template #prefix><el-icon><Search /></el-icon></template>
        </el-input>
        <div class="spacer" />
        <el-button type="primary" :icon="'Plus'" @click="openCreate">新增用户</el-button>
      </div>
      <el-table v-loading="loading" :data="items" stripe>
        <el-table-column prop="username" label="用户名" min-width="120" />
        <el-table-column prop="nickname" label="昵称" min-width="120" />
        <el-table-column label="角色" min-width="140">
          <template #default="{ row }">
            <el-tag v-for="r in row.roles" :key="r" size="small" style="margin-right: 4px">{{ ROLE_NAMES[r] || r }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="软件授权" min-width="160">
          <template #default="{ row }">
            <span v-if="!row.scope.length" class="muted">不限制</span>
            <el-tag v-for="s in row.scope" v-else :key="s" size="small" type="info" style="margin-right: 4px">{{ s }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最近登录" width="170">
          <template #default="{ row }">{{ fmtTime(row.last_login_at) }}</template>
        </el-table-column>
        <el-table-column prop="last_login_ip" label="登录 IP" width="130" />
        <el-table-column label="操作" width="230" class-name="row-actions" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="resetPwd(row)">重置密码</el-button>
            <el-button link type="danger" :disabled="row.id === user.user?.id" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination v-model:current-page="query.page" v-model:page-size="query.page_size" class="pager" background layout="total, prev, pager, next" :total="total" @current-change="load" />
    </el-card>

    <el-dialog v-model="dlg" :title="form.id ? '编辑用户' : '新增用户'" width="540px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" maxlength="64" :disabled="!!form.id" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="form.nickname" maxlength="64" />
        </el-form-item>
        <el-form-item :label="form.id ? '新密码' : '初始密码'">
          <el-input v-model="form.password" type="password" show-password :placeholder="form.id ? '留空则不修改' : '至少 6 位'" />
        </el-form-item>
        <el-form-item label="角色" prop="roles">
          <el-checkbox-group v-model="form.roles">
            <el-checkbox v-for="r in roles" :key="r.code" :value="r.code">{{ r.name }}</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="软件授权">
          <el-select v-model="form.scope" multiple clearable placeholder="不选 = 不限制" style="width: 100%">
            <el-option v-for="s in softwareList" :key="s.id" :value="s.id" :label="`${s.id} ${s.name}`" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" active-text="启用" inactive-text="禁用" :disabled="form.id === user.user?.id" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dlg = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  gap: 10px;
  margin-bottom: 12px;
}

.spacer {
  flex: 1;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.muted {
  color: #909399;
  font-size: 12px;
}
</style>
