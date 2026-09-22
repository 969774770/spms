<script setup>
import { onMounted, reactive, ref } from 'vue'
import { api } from '../api/request'
import { fmtTime } from '../utils/format'

const loading = ref(false)
const total = ref(0)
const items = ref([])
const query = reactive({ page: 1, page_size: 20, action_q: '', username: '', target_type: '', time_from: '', time_to: '' })

async function load() {
  loading.value = true
  try {
    const res = await api('audit.list', query)
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
function exportCsv() {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(query)) {
    if (v !== '' && v !== null && v !== undefined) q.set(k, v)
  }
  window.open(`/api.php?action=audit.export&${q.toString()}`, '_blank')
}

onMounted(load)
</script>

<template>
  <div class="page-card">
    <el-card shadow="never">
      <div class="toolbar">
        <el-input v-model="query.action" placeholder="操作类型前缀，如 software." clearable style="width: 180px" @keyup.enter="resetQuery" @clear="resetQuery" />
        <el-input v-model="query.username" placeholder="操作人" clearable style="width: 140px" @keyup.enter="resetQuery" @clear="resetQuery" />
        <el-input v-model="query.target_type" placeholder="目标类型，如 file" clearable style="width: 140px" @keyup.enter="resetQuery" @clear="resetQuery" />
        <el-date-picker v-model="query.time_from" type="datetime" placeholder="开始时间" format="YYYY-MM-DD HH:mm:ss" value-format="YYYY-MM-DD HH:mm:ss" style="width: 190px" />
        <el-date-picker v-model="query.time_to" type="datetime" placeholder="结束时间" format="YYYY-MM-DD HH:mm:ss" value-format="YYYY-MM-DD HH:mm:ss" style="width: 190px" />
        <el-button type="primary" :icon="'Search'" @click="resetQuery">查询</el-button>
        <div class="spacer" />
        <el-button :icon="'Download'" @click="exportCsv">导出 CSV（当前筛选）</el-button>
      </div>
      <el-table v-loading="loading" :data="items" stripe size="small">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="时间" width="170">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column prop="username" label="操作人" width="110" />
        <el-table-column prop="action" label="操作" min-width="150">
          <template #default="{ row }"><el-tag size="small" effect="plain">{{ row.action }}</el-tag></template>
        </el-table-column>
        <el-table-column label="目标" min-width="140">
          <template #default="{ row }">{{ [row.target_type, row.target_id].filter(Boolean).join(':') }}</template>
        </el-table-column>
        <el-table-column label="详情" min-width="240">
          <template #default="{ row }">
            <span v-if="row.detail" class="detail">{{ JSON.stringify(row.detail) }}</span>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="130" />
        <el-table-column prop="user_agent" label="UA" min-width="160" show-overflow-tooltip />
      </el-table>
      <el-pagination v-model:current-page="query.page" v-model:page-size="query.page_size" class="pager" background layout="total, prev, pager, next" :total="total" @current-change="load" />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  align-items: center;
}

.spacer {
  flex: 1;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.detail {
  font-size: 12px;
  color: #606266;
  word-break: break-all;
}

.muted {
  color: #c0c4cc;
}
</style>
