<script setup>
import { ElCheckbox, ElIcon, ElTable, ElTableColumn, ElTag, ElTooltip } from 'element-plus'
import { fmtSize, fmtTime, isTextEditable } from '../../utils/format'

/**
 * 文件表格哑组件（列表/紧凑视图）：数据与选中态来自 props，操作统一 emit
 */
defineProps({
  rows: { type: Array, required: true },          // [{_key,id,file_name?,name?,path?,size,updated_at,note,active,blocked_rules,url}]
  selected: { type: Set, required: true },        // Set('f:1' / 'd:2')
  canWrite: { type: Boolean, default: false },
})
const emit = defineEmits(['click-row', 'ctx', 'dblclick', 'dragstart', 'drop-dir', 'toggle-sel', 'select-all', 'clear-all', 'action'])

function onAction(cmd, row) {
  emit('action', cmd, row)
}
</script>

<template>
  <el-table :data="rows" size="small" @row-contextmenu="(r, c, e) => emit('ctx', e, r)" @row-click="(r, e) => emit('click-row', r, e)" @row-dblclick="(r, e) => emit('dblclick', r, e)">
    <el-table-column width="40">
      <template #header>
        <el-checkbox :model-value="selected.size > 0" :indeterminate="selected.size > 0" @change="selected.size > 0 ? emit('clear-all') : emit('select-all')" />
      </template>
      <template #default="{ row }">
        <el-checkbox :model-value="selected.has(row._key)" @click.stop @change="emit('toggle-sel', row)" />
      </template>
    </el-table-column>
    <el-table-column label="文件名称" min-width="280">
      <template #default="{ row }">
        <div class="fm-name" draggable="true" @dragstart="emit('dragstart', $event, row)" @drop.stop="row._key.startsWith('d:') && emit('drop-dir', row)" @dragover.prevent>
          <el-icon v-if="row._key.startsWith('d:')" color="#e6a23c"><Folder /></el-icon>
          <el-icon v-else color="#909399"><Document /></el-icon>
          <span class="fm-name-text" :title="row.file_name || row.path">{{ row.file_name || row.name }}</span>
          <el-tooltip v-if="row.blocked_rules && row.blocked_rules.length" placement="top">
            <template #content>命中规则：{{ row.blocked_rules.map((r) => r.pattern).join('、') }}</template>
            <el-tag type="danger" size="small" effect="dark">已屏蔽</el-tag>
          </el-tooltip>
          <el-tag v-if="row._key.startsWith('f:') && row.active === 0" type="info" size="small">已停用</el-tag>
        </div>
      </template>
    </el-table-column>
    <el-table-column width="110" align="right">
      <template #default="{ row }">
        <span v-if="row._key.startsWith('f:')">{{ fmtSize(row.size) }}</span>
        <span v-else class="dir-size">—</span>
      </template>
    </el-table-column>
    <el-table-column width="170">
      <template #default="{ row }">{{ fmtTime(row.updated_at) }}</template>
    </el-table-column>
    <el-table-column label="备注" min-width="160" show-overflow-tooltip>
      <template #default="{ row }">
        <span v-if="row._key.startsWith('f:')" class="note-text" @click.stop="canWrite && onAction('note', row)">{{ row.note || '点击添加备注' }}</span>
      </template>
    </el-table-column>
    <el-table-column width="76" align="center">
      <template #header><span title="开关=是否下发到客户端更新列表（文件）；目录无此概念">启停</span></template>
      <template #default="{ row }">
        <el-switch
          v-if="row._key.startsWith('f:')"
          :model-value="row.active === 1"
          size="small"
          :disabled="!canWrite"
          @click.stop
          @change="onAction('toggle-active', row)"
        />
        <span v-else>—</span>
      </template>
    </el-table-column>
    <el-table-column width="64" align="center">
      <template #header><span title="客户端下载完成后自动运行该文件">自启</span></template>
      <template #default="{ row }">
        <el-switch
          v-if="row._key.startsWith('f:')"
          :model-value="row.auto_run === 1"
          size="small"
          :disabled="!canWrite"
          @click.stop
          @change="onAction('toggle-auto', row)"
        />
      </template>
    </el-table-column>
    <el-table-column width="64" align="center">
      <template #header><span title="开=下载后覆盖本地同名文件（默认）；关=本地已存在则跳过">覆盖</span></template>
      <template #default="{ row }">
        <el-switch
          v-if="row._key.startsWith('f:')"
          :model-value="row.force_overwrite === 1"
          size="small"
          :disabled="!canWrite"
          @click.stop
          @change="onAction('toggle-force', row)"
        />
      </template>
    </el-table-column>
    <el-table-column label="操作" width="240" class-name="row-actions">
      <template #default="{ row }">
        <template v-if="row._key.startsWith('f:')">
          <el-button v-if="isTextEditable(row.file_name)" link type="primary" @click.stop="onAction('edit', row)">编辑</el-button>
          <el-button link type="primary" @click.stop="onAction('download', row)">下载</el-button>
          <el-button link type="primary" @click.stop="onAction('copy', row)">复制</el-button>
          <el-button link type="primary" @click.stop="onAction('cut', row)">剪切</el-button>
          <el-button link type="primary" @click.stop="onAction('rename', row)">重命名</el-button>
          <el-button v-if="canWrite" link type="danger" @click.stop="onAction('del', row)">删除</el-button>
        </template>
        <template v-else>
          <el-button link type="primary" @click.stop="onAction('open', row)">打开</el-button>
          <el-button v-if="canWrite" link type="danger" @click.stop="onAction('del', row)">删除</el-button>
        </template>
      </template>
    </el-table-column>
  </el-table>
</template>

<style scoped>
.fm-name {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.fm-name-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.note-text {
  color: #67c23a;
  cursor: pointer;
  font-size: 12px;
}

.dir-size {
  color: #409eff;
  font-size: 12px;
}
</style>
