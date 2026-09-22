<script setup>
/** 右键菜单（哑组件） */
defineProps({
  visible: { type: Boolean, default: false },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
  kind: { type: String, default: 'f' },   // 'f' | 'd'
  editable: { type: Boolean, default: false },
})
const emit = defineEmits(['action', 'close'])
</script>

<template>
  <teleport to="body">
    <div v-if="visible" class="ctx-menu" :style="{ left: x + 'px', top: y + 'px' }" @click.stop>
      <template v-if="kind === 'f'">
        <div v-if="editable" class="ctx-item" @click="emit('action', 'edit')">编辑</div>
        <div class="ctx-item" @click="emit('action', 'download')">下载</div>
      </template>
      <template v-else>
        <div class="ctx-item" @click="emit('action', 'open')">打开</div>
      </template>
      <div class="ctx-item" @click="emit('action', 'copy')">复制</div>
      <div class="ctx-item" @click="emit('action', 'cut')">剪切</div>
      <div class="ctx-item" @click="emit('action', 'paste')">粘贴</div>
      <div class="ctx-item" @click="emit('action', 'rename')">重命名</div>
      <div class="ctx-sep" />
      <div class="ctx-item" @click="emit('action', 'prop')">属性</div>
      <div class="ctx-item danger" @click="emit('action', 'del')">删除</div>
      <div class="ctx-item danger" @click="emit('action', 'purge')">彻底删除</div>
    </div>
  </teleport>
</template>

<style scoped>
.ctx-menu {
  position: fixed;
  z-index: 3000;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
  padding: 4px 0;
  min-width: 140px;
}

.ctx-item {
  padding: 7px 16px;
  font-size: 13px;
  cursor: pointer;
  color: #303133;
}

.ctx-item:hover {
  background: #f5f7fa;
  color: #409eff;
}

.ctx-item.danger {
  color: #f56c6c;
}

.ctx-sep {
  height: 1px;
  background: #e4e7ed;
  margin: 4px 0;
}
</style>
