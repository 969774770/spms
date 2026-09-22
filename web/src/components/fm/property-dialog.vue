<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fsApi } from '../../api/fs'
import { useFmStore } from '../../stores/fm'
import { fmtSize, fmtTime } from '../../utils/format'

const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['close'])
const fm = useFmStore()
const note = ref(props.data.type === 'file' ? props.data.file.note : '')
const noteSaving = ref(false)

const row = props.data.type === 'file' ? props.data.file : props.data.dir
const dirId = props.data.type === 'file' ? null : null

async function saveNote() {
  noteSaving.value = true
  try {
    await fsApi.fileNote(fm.softwareId, row.id, note.value.slice(0, 255))
    ElMessage.success('备注已保存')
  } finally {
    noteSaving.value = false
  }
}
</script>

<template>
  <el-dialog :model-value="true" :title="`属性 — ${row.file_name || row.name}`" width="520px" destroy-on-close @update:model-value="emit('close')">
    <el-descriptions :column="1" border size="small">
      <el-descriptions-item label="名称">{{ row.file_name || row.name }}</el-descriptions-item>
      <el-descriptions-item label="类型">{{ data.type === 'file' ? '文件' : '目录' }}</el-descriptions-item>
      <el-descriptions-item label="所在目录">{{ data.type === 'file' ? ('/' + (row.dir_path || '')) : ('/' + (row.path || '')) }}</el-descriptions-item>
      <el-descriptions-item label="完整路径">{{ data.type === 'file' ? '/' + row.full_path : '/' + row.path }}</el-descriptions-item>
      <el-descriptions-item label="大小">
        {{ data.type === 'file' ? fmtSize(row.size) : `${row.files} 个文件 / ${fmtSize(row.total_size)}（递归）` }}
      </el-descriptions-item>
      <el-descriptions-item v-if="data.type === 'file'" label="权限">只读展示（服务器 www 可读写）</el-descriptions-item>
      <el-descriptions-item v-if="data.type === 'file'" label="SHA-256">{{ row.hash }}</el-descriptions-item>
      <el-descriptions-item label="修改时间">{{ fmtTime(row.updated_at) }}</el-descriptions-item>
      <el-descriptions-item v-if="data.type === 'file'" label="下载链接">
        <el-link type="primary" :href="row.url" target="_blank">{{ '复制/打开签名链接' }}</el-link>
      </el-descriptions-item>
      <el-descriptions-item v-if="data.type === 'file'" label="客户端可见">
        <el-tag v-if="row.blocked_rules && row.blocked_rules.length" type="danger" size="small">已屏蔽（{{ row.blocked_rules.map((r) => r.pattern).join('、') }}）</el-tag>
        <el-tag v-else-if="row.active === 0" type="info" size="small">已停用</el-tag>
        <el-tag v-else type="success" size="small">可下发</el-tag>
      </el-descriptions-item>
      <el-descriptions-item v-if="data.type === 'file'" label="下载后自动运行">
        <el-tag :type="row.auto_run === 1 ? 'success' : 'info'" size="small">{{ row.auto_run === 1 ? '自启已开启' : '自启已关闭' }}</el-tag>
        <span class="muted">（在文件列表「自启」列切换）</span>
      </el-descriptions-item>
      <el-descriptions-item v-if="data.type === 'file'" label="备注">
        <div class="note-row">
          <el-input v-model="note" size="small" maxlength="255" />
          <el-button size="small" type="primary" :loading="noteSaving" @click="saveNote">保存</el-button>
        </div>
      </el-descriptions-item>
    </el-descriptions>
    <template #footer>
      <el-button type="primary" @click="emit('close')">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.note-row {
  display: flex;
  gap: 6px;
}

.muted {
  color: #909399;
  font-size: 12px;
}
</style>
