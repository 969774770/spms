<script setup>
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { fsApi } from '../../api/fs'
import { useFmStore } from '../../stores/fm'
import { fmtSize, fmtTime } from '../../utils/format'

const emit = defineEmits(['close', 'changed'])
const fm = useFmStore()
const loading = ref(false)
const type = ref('')
const items = ref([])
const total = ref(0)
const picked = ref([])

async function load() {
  loading.value = true
  try {
    const res = await fsApi.trashList(fm.softwareId, type.value || undefined, 1)
    items.value = res.items
    total.value = res.total
    picked.value = []
  } finally {
    loading.value = false
  }
}

function pickList() {
  return {
    file_ids: picked.value.filter((k) => k.startsWith('f:')).map((k) => Number(k.slice(2))),
    dir_ids: picked.value.filter((k) => k.startsWith('d:')).map((k) => Number(k.slice(2))),
  }
}

async function restore(rows) {
  const ids = rows?.length ? rows.map((r) => `${r.type === 'file' ? 'f' : 'd'}:${r.id}`) : picked.value
  if (!ids.length) return
  const payload = {
    file_ids: ids.filter((k) => k.startsWith('f:')).map((k) => Number(k.slice(2))),
    dir_ids: ids.filter((k) => k.startsWith('d:')).map((k) => Number(k.slice(2))),
  }
  const res = await fsApi.trashRestore(fm.softwareId, payload.file_ids, payload.dir_ids)
  ElMessage.success(`已恢复 ${res.restored} 项`)
  emit('changed')
  load()
}

async function purge(rows) {
  await ElMessageBox.confirm('彻底删除将同时清除存储实体，不可恢复。确定？', '彻底删除', { type: 'error' })
  const ids = rows?.length ? rows.map((r) => `${r.type === 'file' ? 'f' : 'd'}:${r.id}`) : picked.value
  const payload = {
    file_ids: ids.filter((k) => k.startsWith('f:')).map((k) => Number(k.slice(2))),
    dir_ids: ids.filter((k) => k.startsWith('d:')).map((k) => Number(k.slice(2))),
  }
  const res = await fsApi.trashPurge(fm.softwareId, payload.file_ids, payload.dir_ids)
  ElMessage.success(`已彻底删除 ${res.purged_files + res.purged_dirs} 项`)
  emit('changed')
  load()
}

async function empty() {
  await ElMessageBox.confirm('清空回收站将彻底删除全部条目并清除存储实体，不可恢复。确定？', '清空回收站', { type: 'error' })
  const res = await fsApi.trashEmpty(fm.softwareId)
  ElMessage.success(`已清空（${res.purged_files + res.purged_dirs} 项）`)
  emit('changed')
  load()
}

onMounted(load)
</script>

<template>
  <el-dialog :model-value="true" title="回收站" width="680px" destroy-on-close @update:model-value="emit('close')">
    <div class="bar">
      <el-radio-group v-model="type" size="small" @change="load">
        <el-radio-button value="">全部</el-radio-button>
        <el-radio-button value="file">文件</el-radio-button>
        <el-radio-button value="dir">目录</el-radio-button>
      </el-radio-group>
      <div class="spacer" />
      <el-button size="small" type="primary" :disabled="!picked.length" @click="restore()">恢复所选</el-button>
      <el-button size="small" type="danger" :disabled="!picked.length" @click="purge()">彻底删除</el-button>
      <el-button size="small" type="danger" plain :disabled="!total" @click="empty">清空回收站</el-button>
    </div>
    <el-table :data="items" v-loading="loading" size="small" max-height="380" empty-text="回收站是空的" @selection-change="(rows) => (picked = rows.map((r) => `${r.type === 'file' ? 'f' : 'd'}:${r.id}`))">
      <el-table-column type="selection" width="40" />
      <el-table-column label="名称" min-width="220">
        <template #default="{ row }">
          <el-icon v-if="row.type === 'dir'" color="#e6a23c"><Folder /></el-icon>
          <el-icon v-else color="#909399"><Document /></el-icon>
          {{ row.name }}
        </template>
      </el-table-column>
      <el-table-column label="类型" width="80">
        <template #default="{ row }">{{ row.type === 'file' ? '文件' : '目录' }}</template>
      </el-table-column>
      <el-table-column label="大小" width="100" align="right">
        <template #default="{ row }">{{ row.size === null ? '—' : fmtSize(row.size) }}</template>
      </el-table-column>
      <el-table-column label="删除时间" width="170">
        <template #default="{ row }">{{ fmtTime(row.deleted_at) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="130">
        <template #default="{ row }">
          <el-button link type="primary" @click="restore([row])">恢复</el-button>
          <el-button link type="danger" @click="purge([row])">彻底删除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <div class="tip">保留期 7 天，到期后由计划任务彻底删除。</div>
    <template #footer>
      <el-button @click="emit('close')">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.bar {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 10px;
}

.spacer {
  flex: 1;
}

.tip {
  margin-top: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
