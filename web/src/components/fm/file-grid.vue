<script setup>
/** 网格视图（哑组件） */
import { ElIcon, ElTag } from 'element-plus'

defineProps({
  rows: { type: Array, required: true },
  selected: { type: Set, required: true },
})
const emit = defineEmits(['click-row', 'ctx', 'dblclick', 'dragstart', 'drop-dir'])
</script>

<template>
  <div class="fm-grid">
    <div
      v-for="row in rows"
      :key="row._key"
      class="grid-cell"
      :class="{ picked: selected.has(row._key) }"
      draggable="true"
      @dragstart="emit('dragstart', $event, row)"
      @dragover.prevent
      @drop.stop="row._key.startsWith('d:') && emit('drop-dir', row)"
      @click="emit('click-row', row, $event)"
      @contextmenu="emit('ctx', $event, row)"
      @dblclick="emit('dblclick', row)"
    >
      <el-icon :size="34" :color="row._key.startsWith('d:') ? '#e6a23c' : '#909399'">
        <Folder v-if="row._key.startsWith('d:')" /><Document v-else />
      </el-icon>
      <div class="grid-name" :title="row.file_name || row.name">{{ row.file_name || row.name }}</div>
      <el-tag v-if="row.blocked_rules && row.blocked_rules.length" type="danger" size="small">屏蔽</el-tag>
    </div>
  </div>
</template>

<style scoped>
.fm-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 10px;
  min-height: 300px;
}

.grid-cell {
  width: 108px;
  padding: 10px 6px;
  text-align: center;
  border-radius: 6px;
  cursor: pointer;
}

.grid-cell:hover {
  background: #f5f7fa;
}

.grid-cell.picked {
  background: #ecf5ff;
}

.grid-name {
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-top: 6px;
}
</style>
