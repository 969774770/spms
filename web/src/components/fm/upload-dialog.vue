<script setup>
import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useUploadStore } from '../../stores/upload'
import { useFmStore } from '../../stores/fm'
import { fmtSize } from '../../utils/format'

const upload = useUploadStore()
const fm = useFmStore()
const filesInput = ref()
const folderInput = ref()

const title = computed(() => `上传文件到【/${upload.targetDir}】 -- 支持断点续传`)

const STATUS_TEXT = {
  pending: '等待上传',
  hashing: '计算哈希中',
  uploading: '上传中',
  paused: '已暂停',
  'paused-resume': '待续传（需重新选择文件）',
  done: '已完成',
  failed: '失败',
}

function trigger(cmd) {
  upload.targetDir = fm.dirPath
  if (cmd === 'files') filesInput.value?.click()
  else if (cmd === 'folder') folderInput.value?.click()
  else upload.dialogVisible = true
}
defineExpose({ trigger })

function onPick(ev) {
  const files = [...ev.target.files].map((f) => {
    f._relPath = f.webkitRelativePath || f.name
    return f
  })
  if (files.length) upload.add(files)
  ev.target.value = ''
}
function speedText(item) {
  return item.speed > 0 ? `${fmtSize(item.speed)}/s` : ''
}
</script>

<template>
  <el-dialog v-model="upload.dialogVisible" :title="title" width="880px" destroy-on-close :close-on-click-modal="false" top="6vh">
    <div class="up-head">
      <el-dropdown @command="trigger">
        <el-button type="primary" plain>上传文件 <el-icon><ArrowDown /></el-icon></el-button>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="files">选择文件</el-dropdown-item>
            <el-dropdown-item command="folder">选择文件夹</el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
      <div class="spacer" />
      <el-button @click="upload.retryAll">重试全部</el-button>
      <el-button @click="upload.clearDone">清空已完成</el-button>
      <el-button type="danger" plain @click="upload.clearAll">清空全部</el-button>
    </div>

    <el-table :data="upload.items" size="small" max-height="380" empty-text="队列为空，点击上方按钮或把文件拖入页面任意位置">
      <el-table-column label="文件名" min-width="200" show-overflow-tooltip>
        <template #default="{ row }">/{{ row.relPath }}</template>
      </el-table-column>
      <el-table-column label="大小" width="86" align="right">
        <template #default="{ row }">{{ fmtSize(row.size) }}</template>
      </el-table-column>
      <el-table-column width="130">
        <template #default="{ row }">
          <el-progress :percentage="row.progress" :status="row.status === 'done' ? 'success' : row.status === 'failed' ? 'exception' : undefined" :stroke-width="8" />
        </template>
      </el-table-column>
      <el-table-column label="状态" width="130">
        <template #default="{ row }">
          <span :class="['st', row.status]">
            {{ STATUS_TEXT[row.status] || row.status }}
            <template v-if="row.status === 'uploading'">{{ speedText(row) }}</template>
            <template v-if="row.status === 'done' && row.uploadedParts.length === 0 && row.progress === 100">秒传</template>
          </span>
          <div v-if="row.error" class="err">{{ row.error }}</div>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="130" fixed="right">
        <template #default="{ row }">
          <el-button v-if="['uploading', 'hashing'].includes(row.status)" link type="warning" @click="upload.pause(row.uid)">暂停</el-button>
          <el-button v-if="['paused', 'failed', 'paused-resume'].includes(row.status)" link type="primary" @click="upload.start(row.uid)">继续</el-button>
          <el-button v-if="row.status === 'failed'" link type="primary" @click="upload.start(row.uid)">重试</el-button>
          <el-button link type="danger" @click="upload.removeItem(row.uid)">取消</el-button>
        </template>
      </el-table-column>
    </el-table>
    <template #footer>
      <el-button @click="upload.dialogVisible = false">关闭</el-button>
    </template>
    <input ref="filesInput" type="file" multiple hidden @change="onPick" />
    <input ref="folderInput" type="file" webkitdirectory hidden @change="onPick" />
  </el-dialog>
</template>

<style scoped>
.up-head {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}

.spacer {
  flex: 1;
}

.st.uploading,
.st.hashing {
  color: #409eff;
}

.st.done {
  color: #67c23a;
}

.st.failed {
  color: #f56c6c;
}

.st.paused,
.st.paused-resume {
  color: #e6a23c;
}

.err {
  font-size: 11px;
  color: #f56c6c;
  line-height: 1.4;
}
</style>
