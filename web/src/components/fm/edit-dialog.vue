<script setup>
import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fsApi } from '../../api/fs'
import { useFmStore } from '../../stores/fm'
import { fmtSize } from '../../utils/format'

const props = defineProps({ file: { type: Object, required: true } })
const emit = defineEmits(['close', 'saved'])
const fm = useFmStore()
const loading = ref(true)
const saving = ref(false)
const content = ref('')
const origSize = ref(0)
const hash = ref('')

fsApi.fileRead(fm.softwareId, props.file.id)
  .then((res) => {
    content.value = res.content
    origSize.value = res.size
    hash.value = res.hash
  })
  .finally(() => { loading.value = false })

const curSize = computed(() => new Blob([content.value]).size)

async function save() {
  saving.value = true
  try {
    const res = await fsApi.fileWrite(fm.softwareId, props.file.id, content.value)
    ElMessage.success(res.changed ? `已保存（${fmtSize(origSize.value)} → ${fmtSize(res.size)}）` : '内容未变化，未产生新版本')
    emit('saved')
    emit('close')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog :model-value="true" :title="`在线编辑：${file.file_name}`" width="860px" destroy-on-close @update:model-value="emit('close')">
    <div class="meta">
      <span>原大小 {{ fmtSize(origSize) }}</span>
      <span>当前 {{ fmtSize(curSize) }}</span>
      <span>哈希 {{ hash.slice(0, 12) }}…</span>
    </div>
    <el-input v-model="content" type="textarea" :rows="20" v-loading="loading" spellcheck="false" class="editor" />
    <template #footer>
      <el-button @click="emit('close')">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存（内容相同则不产生新对象）</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.meta {
  display: flex;
  gap: 18px;
  color: #909399;
  font-size: 12px;
  margin-bottom: 8px;
}

.editor :deep(textarea) {
  font-family: Consolas, Monaco, monospace;
  font-size: 13px;
}
</style>
