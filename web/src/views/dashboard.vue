<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api/request'
import { useUserStore } from '../stores/user'
import { fmtSize, fmtTime } from '../utils/format'

const store = useUserStore()
const loading = ref(true)
const stats = ref(null)

onMounted(async () => {
  try {
    stats.value = await api('system.stats')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page-card" v-loading="loading">
    <el-row v-if="stats" :gutter="16">
      <el-col :span="8">
        <el-card shadow="never">
          <template #header>
            <div class="head">
              <span>磁盘用量</span>
              <el-tag v-if="stats.disk.low" type="danger" size="small">剩余不足 {{ stats.disk.alert_percent }}%</el-tag>
            </div>
          </template>
          <el-progress type="dashboard" :percentage="stats.disk.percent" :width="130" :color="stats.disk.low ? '#f56c6c' : '#409eff'">
            <template #default><span class="pct">{{ stats.disk.percent }}%</span></template>
          </el-progress>
          <div class="kv"><span>已用</span><b>{{ fmtSize(stats.disk.used) }}</b></div>
          <div class="kv"><span>剩余</span><b>{{ fmtSize(stats.disk.free) }}</b></div>
          <div class="kv"><span>总量</span><b>{{ fmtSize(stats.disk.total) }}</b></div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" header="存储对象（去重后）">
          <div class="kv"><span>对象总数</span><b>{{ stats.objects.count }}</b></div>
          <div class="kv"><span>对象占用</span><b>{{ fmtSize(stats.objects.size) }}</b></div>
          <el-divider />
          <div class="kv"><span>逻辑文件数</span><b>{{ stats.files.count }}</b></div>
          <div class="kv"><span>逻辑总大小</span><b>{{ fmtSize(stats.files.size) }}</b></div>
          <div class="kv"><span>去重节省</span><b class="green">{{ fmtSize(stats.dedup_saved) }}</b></div>
          <div class="kv"><span>软件数</span><b>{{ stats.software_count }}</b></div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" header="待处理">
          <div class="kv"><span>回收站条目</span><b :class="{ warn: stats.pending.trash > 0 }">{{ stats.pending.trash }}</b></div>
          <div class="kv"><span>过期上传会话</span><b :class="{ warn: stats.pending.expired_uploads > 0 }">{{ stats.pending.expired_uploads }}</b></div>
          <el-divider />
          <div class="quick">
            <router-link to="/software" class="link">软件管理 →</router-link>
            <router-link to="/audit" class="link">审计日志 →</router-link>
          </div>
        </el-card>
        <el-card shadow="never" header="最近操作" style="margin-top: 16px">
          <div v-for="(r, i) in stats.recent" :key="i" class="op">
            <span class="op-user">{{ r.username }}</span>
            <el-tag size="small" effect="plain">{{ r.action }}</el-tag>
            <span class="op-time">{{ fmtTime(r.created_at, 'MM-DD HH:mm') }}</span>
          </div>
          <el-empty v-if="!stats.recent.length" description="暂无操作记录" :image-size="60" />
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.pct {
  font-size: 20px;
  font-weight: 600;
}

.kv {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  color: #606266;
}

.green {
  color: #67c23a;
}

.warn {
  color: #e6a23c;
}

.quick {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.link {
  color: #409eff;
  text-decoration: none;
  font-size: 13px;
}

.op {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 0;
  font-size: 12px;
}

.op-user {
  width: 60px;
  color: #303133;
  overflow: hidden;
  text-overflow: ellipsis;
}

.op-time {
  margin-left: auto;
  color: #c0c4cc;
}
</style>
