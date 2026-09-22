<script setup>
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fsApi } from '../../api/fs'
import { useFmStore } from '../../stores/fm'
import { fmtSize, fmtTime } from '../../utils/format'

const emit = defineEmits(['close', 'goto'])
const fm = useFmStore()
const loading = ref(false)
const form = reactive({
  keyword: '',
  mode: 'name',
  scope: 'dir',
  exts: '',
  min_size: '',
  max_size: '',
  active: '',
})
const res = ref(null)
const page = ref(1)

async function doSearch(p = 1) {
  loading.value = true
  try {
    res.value = await fsApi.search({
      software_id: fm.softwareId,
      dir_path: fm.dirPath,
      keyword: form.keyword,
      mode: form.mode,
      scope: form.scope,
      exts: form.exts,
      min_size: form.min_size || undefined,
      max_size: form.max_size || undefined,
      active: form.active || undefined,
      page: p,
      page_size: 20,
    })
    page.value = p
    if (form.mode === 'content') ElMessage.info(`已扫描 ${res.value.scanned} 个文本文件`)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <el-dialog :model-value="true" title="高级搜索" width="860px" destroy-on-close @update:model-value="emit('close')">
    <el-form inline label-width="60px" size="small">
      <el-form-item label="关键字">
        <el-input v-model="form.keyword" style="width: 200px" @keyup.enter="doSearch(1)" />
      </el-form-item>
      <el-form-item label="方式">
        <el-radio-group v-model="form.mode">
          <el-radio-button value="name">按名称</el-radio-button>
          <el-radio-button value="content">按内容（文本≤512KB）</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="范围">
        <el-radio-group v-model="form.scope">
          <el-radio-button value="dir">当前目录</el-radio-button>
          <el-radio-button value="recursive">含子目录</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="类型">
        <el-input v-model="form.exts" placeholder="扩展名，逗号分隔：log,txt" style="width: 180px" />
      </el-form-item>
      <el-form-item label="大小">
        <el-input v-model="form.min_size" placeholder="最小字节" style="width: 110px" />
        <span style="margin: 0 4px">-</span>
        <el-input v-model="form.max_size" placeholder="最大字节" style="width: 110px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="form.active" clearable placeholder="全部" style="width: 100px">
          <el-option label="已启用" value="1" />
          <el-option label="已停用" value="0" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="loading" @click="doSearch(1)">搜索</el-button>
      </el-form-item>
    </el-form>

    <el-table v-if="res" :data="res.items" size="small" max-height="360">
      <el-table-column label="文件" min-width="240">
        <template #default="{ row }">
          <span>/{{ row.dir_path }}/{{ row.file_name }}</span>
          <el-tag v-if="row.blocked_rules && row.blocked_rules.length" type="danger" size="small" style="margin-left: 6px">已屏蔽</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="大小" width="100" align="right">
        <template #default="{ row }">{{ fmtSize(row.size) }}</template>
      </el-table-column>
      <el-table-column label="修改时间" width="160">
        <template #default="{ row }">{{ fmtTime(row.updated_at) }}</template>
      </el-table-column>
      <el-table-column v-if="form.mode === 'content'" label="命中片段" min-width="260">
        <template #default="{ row }">
          <span class="snippet">{{ row.snippet }}</span>
        </template>
      </el-table-column>
    </el-table>
    <div v-if="res" class="pager">
      <el-pagination small background layout="prev, pager, next, total" :total="res.total" :page-size="20" :current-page="page" @current-change="(p) => doSearch(p)" />
    </div>
    <template #footer>
      <el-button @click="emit('close')">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.snippet {
  color: #e6a23c;
  font-size: 12px;
  font-family: Consolas, monospace;
}

.pager {
  margin-top: 10px;
  justify-content: flex-end;
  display: flex;
}
</style>
