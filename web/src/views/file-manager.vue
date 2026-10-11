<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { fsApi } from '../api/fs'
import { useFmStore } from '../stores/fm'
import { useUploadStore } from '../stores/upload'
import { useUserStore } from '../stores/user'
import { fmtSize } from '../utils/format'
import { collectFiles } from '../utils/drag-files'
import UploadDialog from '../components/fm/upload-dialog.vue'
import EditDialog from '../components/fm/edit-dialog.vue'
import PropertyDialog from '../components/fm/property-dialog.vue'
import TrashDialog from '../components/fm/trash-dialog.vue'
import SearchDialog from '../components/fm/search-dialog.vue'
import FileTable from '../components/fm/file-table.vue'
import FileGrid from '../components/fm/file-grid.vue'
import ContextMenu from '../components/fm/context-menu.vue'

const route = useRoute()
const fm = useFmStore()
const upload = useUploadStore()
const user = useUserStore()
const canWrite = computed(() => user.canManage)
const uploadDialogRef = ref()
const pickFilesRef = ref()
const pickFolderRef = ref()
const dialogs = reactive({ edit: null, prop: null, trash: false, search: false })
const state = reactive({ ctx: { visible: false, x: 0, y: 0, kind: 'f', item: null } })

function uploadTrigger(cmd) {
  upload.targetDir = fm.dirPath
  if (cmd === 'files') pickFilesRef.value?.click()
  else if (cmd === 'folder') pickFolderRef.value?.click()
  else upload.openDialog()
}

const crumbs = computed(() => {
  const parts = fm.dirPath ? fm.dirPath.split('/') : []
  const list = [{ name: '根目录', path: '' }]
  let acc = ''
  parts.forEach((p) => {
    acc = acc ? `${acc}/${p}` : p
    list.push({ name: p, path: acc })
  })
  if (list.length <= 5) return list
  return [list[0], { name: '…', path: list[list.length - 2].path }, list[list.length - 1]]
})

async function load() {
  if (!fm.softwareId) return
  fm.loading = true
  try {
    const res = await fsApi.ls(fm.softwareId, fm.dirPath)
    fm.dirs = res.dirs
    fm.files = res.files
    fm.stats = res.stats
    fm.clearSelection()
  } finally {
    fm.loading = false
  }
}
function go(path) {
  fm.search = null
  fm.dirPath = path
  load()
}
function goUp() {
  if (!fm.dirPath) return
  const idx = fm.dirPath.lastIndexOf('/')
  go(idx === -1 ? '' : fm.dirPath.slice(0, idx))
}
async function copyPath() {
  await navigator.clipboard?.writeText('/' + fm.dirPath).catch(() => {})
  ElMessage.success(`已复制路径 /${fm.dirPath}`)
}

// 排序与可见文件（目录恒在前）
const sortedDirs = computed(() => sortItems(fm.dirs, 'd'))
const sortedFiles = computed(() => sortItems(fm.files, 'f'))
const visibleFiles = computed(() => {
  if (fm.filter === 'blocked') return sortedFiles.value.filter((f) => f.blocked_rules.length > 0)
  if (fm.filter === 'ok') return sortedFiles.value.filter((f) => f.blocked_rules.length === 0)
  return sortedFiles.value
})
function sortItems(items, prefix) {
  const { key, dir } = fm.sort
  const mul = dir === 'asc' ? 1 : -1
  return [...items].sort((a, b) => {
    if (key === 'size') return ((a.size || 0) - (b.size || 0)) * mul
    if (key === 'mtime') return String(a.updated_at).localeCompare(String(b.updated_at)) * mul
    return String(a.file_name || a.name).localeCompare(String(b.file_name || b.name), 'zh-CN', { numeric: true }) * mul
  }).map((x) => ({ ...x, _key: `${prefix}:${x.id}` }))
}
function setSort(key) {
  if (fm.sort.key === key) fm.sort.dir = fm.sort.dir === 'asc' ? 'desc' : 'asc'
  else fm.sort = { key, dir: 'asc' }
}
const displayRows = computed(() => [...sortedDirs.value, ...visibleFiles.value])
const rowKeys = computed(() => displayRows.value.map((r) => r._key))

// 选择
function onRowClick(row, ev) {
  const kind = row._key.startsWith('d:') ? 'd' : 'f'
  if (ev.shiftKey && fm.lastPicked) {
    const i1 = rowKeys.value.indexOf(fm.lastPicked)
    const i2 = rowKeys.value.indexOf(row._key)
    if (i1 >= 0 && i2 >= 0) {
      fm.selectRange(rowKeys.value.slice(Math.min(i1, i2), Math.max(i1, i2) + 1))
      return
    }
  }
  fm.toggle(kind, row.id, ev.ctrlKey || ev.metaKey)
}
function findRow(key) {
  const [kind, id] = [key.slice(0, 1), Number(key.slice(2))]
  return kind === 'd' ? fm.dirs.find((d) => d.id === id) : fm.files.find((f) => f.id === id)
}
function openRow(row) {
  if (row._key.startsWith('d:')) go(row.path)
  else onAction('download', row)
}
function downloadOne(f) {
  window.open(f.url, '_blank')
}
async function downloadSelected() {
  if (fm.selectedDirs.length > 0) {
    ElMessage.info('目录不支持打包，请进入目录后逐个下载文件')
    return
  }
  if (fm.selectedFiles.length > 3) ElMessage.info(`将依次打开 ${fm.selectedFiles.length} 个下载窗口`)
  fm.selectedFiles.forEach((id) => {
    const f = fm.files.find((x) => x.id === id)
    if (f) setTimeout(() => downloadOne(f), 300)
  })
}
async function batchActive(active) {
  await fsApi.batchActive(fm.softwareId, fm.selectedFiles, active)
  ElMessage.success(active ? '已启用' : '已停用')
  load()
}
async function batchDelete() {
  await ElMessageBox.confirm(`确定删除选中的 ${fm.selected.size} 项？内容将进入回收站。`, '删除', { type: 'warning' })
  await fsApi.batchDelete(fm.softwareId, fm.selectedFiles, fm.selectedDirs)
  ElMessage.success('已移入回收站')
  load()
}
async function batchPurge() {
  await ElMessageBox.confirm('彻底删除将同时清除存储实体，不可恢复。确定？', '彻底删除', { type: 'error' })
  await fsApi.batchPurge(fm.softwareId, fm.selectedFiles, fm.selectedDirs)
  ElMessage.success('已彻底删除')
  load()
}
function clipCopy() { fm.setClipboard('copy'); ElMessage.success('已复制到剪贴板') }
function clipCut() { fm.setClipboard('cut'); ElMessage.success('已剪切到剪贴板') }
async function clipPaste() {
  if (!fm.clipboard) return
  const dirId = fm.dirPath ? (await fsApi.ls(fm.softwareId, fm.dirPath)).dir.id : null
  if (fm.clipboard.op === 'copy') await fsApi.batchCopy(fm.softwareId, fm.clipboard.files, dirId)
  else {
    for (const fid of fm.clipboard.files) await fsApi.fileMove(fm.softwareId, fid, dirId)
    for (const did of fm.clipboard.dirs) {
      if (!did) continue
      try { await fsApi.dirMove(fm.softwareId, did, dirId) } catch { /* 同名冲突跳过 */ }
    }
  }
  fm.clipboard = null
  ElMessage.success('已粘贴')
  load()
}
async function mkdir() {
  const { value } = await ElMessageBox.prompt('输入目录名（支持多级，如 assets/css）', '新建目录')
  await fsApi.mkdir(fm.softwareId, fm.dirPath, value.trim())
  ElMessage.success('目录已创建')
  load()
}
async function newFile() {
  const { value } = await ElMessageBox.prompt('输入文件名（含扩展名，如 config.json）', '新建文本文件')
  const name = value.trim()
  const f = new File([new Blob([''], { type: 'text/plain' })], name)
  f._relPath = (fm.dirPath ? fm.dirPath + '/' : '') + name
  upload.targetDir = fm.dirPath
  upload.add([f])
  ElMessage.info('空文件已加入上传队列，完成后可在线编辑')
}
async function renameRow(row) {
  const kind = row._key.startsWith('d:') ? 'd' : 'f'
  const { value } = await ElMessageBox.prompt('重命名', '重命名', { inputValue: row.file_name || row.name })
  if (kind === 'd') await fsApi.dirRename(fm.softwareId, row.id, value.trim())
  else await fsApi.fileRename(fm.softwareId, row.id, value.trim())
  ElMessage.success('已重命名（屏蔽标记按新文件名即时重判）')
  load()
}
async function editNote(f) {
  const { value } = await ElMessageBox.prompt('备注（最长 255 字，随配置下发）', '编辑备注', { inputValue: f.note })
  await fsApi.fileNote(fm.softwareId, f.id, value.slice(0, 255))
  f.note = value.slice(0, 255)
}
async function toggleActive(f) {
  await fsApi.fileActive(fm.softwareId, f.id, !f.active)
  f.active = f.active ? 0 : 1
}
async function toggleAutoRun(f) {
  const target = !f.auto_run
  await fsApi.fileAutoRun(fm.softwareId, f.id, target)
  f.auto_run = target ? 1 : 0
  ElMessage.success(target ? '已开启自启：客户端下载完成后将自动运行该文件' : '已关闭自启')
}
async function toggleForce(f) {
  const target = !f.force_overwrite
  await fsApi.fileForce(fm.softwareId, f.id, target)
  f.force_overwrite = target ? 1 : 0
  ElMessage.success(target ? '已开启覆盖：客户端下载后将覆盖本地同名文件' : '已关闭覆盖：本地已存在同名文件时跳过下载')
}
async function showProp(row) {
  const kind = row._key?.startsWith('d:') ? 'dir' : 'file'
  dialogs.prop = await fsApi.stat(fm.softwareId, kind, row.id)
}

// 行操作统一分发（表格/网格/右键菜单共用）
function onAction(cmd, row) {
  const single = () => { fm.selected = new Set([row._key]) }
  switch (cmd) {
    case 'open': return go(row.path)
    case 'download': return downloadSelected()
    case 'edit': return (dialogs.edit = fm.files.find((f) => f.id === row.id) || row)
    case 'copy': return fm.toggle('f', row.id), clipCopy()
    case 'cut': return fm.toggle('f', row.id), clipCut()
    case 'paste': return clipPaste()
    case 'rename': return renameRow(row)
    case 'prop': return showProp(row)
    case 'del': return single(), batchDelete()
    case 'purge': return single(), batchPurge()
    case 'note': return editNote(row)
    case 'toggle-active': return toggleActive(row)
    case 'toggle-auto': return toggleAutoRun(row)
    case 'toggle-force': return toggleForce(row)
  }
}

// 拖拽移动（行 → 目录）
function dragStart(ev, row) {
  if (!fm.selected.has(row._key)) fm.toggle(row._key.startsWith('d:') ? 'd' : 'f', row.id, false)
  ev.dataTransfer.effectAllowed = 'move'
}
async function dropToDir(dirRow) {
  const fileIds = fm.selectedFiles
  const dirIds = fm.selectedDirs.filter((d) => d !== dirRow.id)
  if (!fileIds.length && !dirIds.length) return
  await fsApi.batchMove(fm.softwareId, fileIds, dirIds, dirRow.id)
  ElMessage.success('已移动')
  load()
}

// 全窗口拖入文件 → 上传队列（dragenter/dragleave 计数器控制遮罩，避免子元素误触发）
const dragOverlay = ref(false)
let dragDepth = 0
function onWindowDragEnter(ev) {
  if (![...ev.dataTransfer?.types || []].includes('Files')) return
  dragDepth++
  dragOverlay.value = true
}
function onWindowDragLeave() {
  dragDepth = Math.max(0, dragDepth - 1)
  if (dragDepth === 0) dragOverlay.value = false
}
function onWindowDragOver(ev) {
  ev.preventDefault() // 必须：阻止浏览器默认打开文件
}
function onWindowDrop(ev) {
  ev.preventDefault() // 必须：阻止浏览器新标签打开/下载拖入文件
  dragDepth = 0
  dragOverlay.value = false
  if (!canWrite.value || !fm.softwareId) return
  collectFiles(ev.dataTransfer).then((files) => {
    if (files.length) {
      upload.targetDir = fm.dirPath
      upload.add(files)
    }
  })
}
async function pick(ev) {
  const files = [...ev.target.files].map((f) => { f._relPath = f.webkitRelativePath || f.name; return f })
  upload.targetDir = fm.dirPath
  upload.add(files)
  ev.target.value = ''
  upload.openDialog()
}

// 右键菜单
function onCtxMenu(ev, row) {
  if (row && !fm.selected.has(row._key)) fm.toggle(row._key.startsWith('d:') ? 'd' : 'f', row.id, false)
  const menuH = 320
  let x = ev.clientX
  let y = ev.clientY
  if (y + menuH > window.innerHeight) y = Math.max(8, window.innerHeight - menuH - 8)
  if (x + 150 > window.innerWidth) x = Math.max(8, window.innerWidth - 158)
  state.ctx = { visible: true, x, y, kind: row?._key?.startsWith('d:') ? 'd' : 'f', item: row }
  ev.preventDefault()
}
function ctxAction(cmd) {
  state.ctx.visible = false
  onAction(cmd, state.ctx.item || {})
}

// 快捷键
function onKeydown(ev) {
  if (['INPUT', 'TEXTAREA'].includes(ev.target.tagName) || ev.target.isContentEditable) return
  const ctrl = ev.ctrlKey || ev.metaKey
  const key = ev.key.toLowerCase()
  if (ctrl && key === 'a') { ev.preventDefault(); fm.selectAll(displayRows.value) }
  else if (ctrl && key === 'c' && fm.hasSelection) clipCopy()
  else if (ctrl && key === 'x' && fm.hasSelection) clipCut()
  else if (ctrl && key === 'v' && fm.clipboard) clipPaste()
  else if (ev.key === 'Delete' && fm.hasSelection) { ev.shiftKey ? batchPurge() : batchDelete() }
  else if (ev.key === 'F2' && fm.selected.size === 1) { const r = findRow([...fm.selected][0]); if (r) renameRow({ ...r, _key: [...fm.selected][0] }) }
  else if (ev.key === 'Escape') { state.ctx.visible = false; fm.clearSelection() }
  else if (ev.key === 'Backspace') goUp()
  else if (ev.key === 'Enter' && fm.selected.size === 1) { const r = findRow([...fm.selected][0]); if (r) openRow({ ...r, _key: [...fm.selected][0] }) }
}

onMounted(() => {
  fm.softwareId = String(route.params.id)
  fm.dirPath = ''
  load()
  upload.restoreFromFm(fm.resumable)
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('dragenter', onWindowDragEnter)
  window.addEventListener('dragleave', onWindowDragLeave)
  window.addEventListener('dragover', onWindowDragOver)
  window.addEventListener('drop', onWindowDrop)
  window.addEventListener('spms-uploaded', load)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('dragenter', onWindowDragEnter)
  window.removeEventListener('dragleave', onWindowDragLeave)
  window.removeEventListener('dragover', onWindowDragOver)
  window.removeEventListener('drop', onWindowDrop)
  window.removeEventListener('spms-uploaded', load)
})
watch(() => route.params.id, () => {
  fm.softwareId = String(route.params.id || '')
  fm.dirPath = ''
  load()
})
</script>

<template>
  <div class="fm-page" @click="state.ctx.visible = false">
    <div class="fm-topbar">
      <el-button :icon="'Back'" circle size="small" :disabled="!fm.dirPath" @click="goUp" />
      <el-breadcrumb separator=">" class="crumbs">
        <el-breadcrumb-item v-for="c in crumbs" :key="c.path">
          <span class="crumb-link" :title="c.path" @click="go(c.path)">{{ c.name }}</span>
        </el-breadcrumb-item>
      </el-breadcrumb>
      <el-button :icon="'Refresh'" circle size="small" @click="load" />
      <el-button :icon="'CopyDocument'" circle size="small" title="复制当前路径" @click="copyPath" />
      <span class="fm-stats">{{ fm.stats.file_count }} 个文件 / {{ fm.stats.dir_count }} 个目录 / {{ fmtSize(fm.stats.total_size) }}</span>
      <div class="spacer" />
      <el-radio-group v-model="fm.filter" size="small">
        <el-radio-button value="all">全部</el-radio-button>
        <el-radio-button value="blocked">仅已屏蔽</el-radio-button>
        <el-radio-button value="ok">仅可下发</el-radio-button>
      </el-radio-group>
      <el-button :icon="'Search'" circle size="small" @click="dialogs.search = true" />
      <el-tooltip content="回收站" placement="top">
        <el-button :icon="'DeleteFilled'" circle size="small" @click="dialogs.trash = true" />
      </el-tooltip>
      <el-button-group>
        <el-button size="small" :type="fm.view === 'grid' ? 'primary' : ''" :icon="'Grid'" @click="fm.setView('grid')" />
        <el-button size="small" :type="fm.view !== 'grid' ? 'primary' : ''" :icon="'Menu'" @click="fm.setView(fm.view === 'compact' ? 'list' : 'compact')" />
      </el-button-group>
    </div>

    <div class="fm-toolbar">
      <el-dropdown v-if="canWrite" @command="uploadTrigger">
        <el-button type="primary" :icon="'Upload'">上传/下载 <el-icon><ArrowDown /></el-icon></el-button>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="files">上传文件</el-dropdown-item>
            <el-dropdown-item command="folder">上传文件夹</el-dropdown-item>
            <el-dropdown-item command="queue">打开上传队列</el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
      <el-dropdown v-if="canWrite" @command="(c) => (c === 'dir' ? mkdir() : newFile())">
        <el-button :icon="'FolderAdd'">新建 <el-icon><ArrowDown /></el-icon></el-button>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="dir">新建目录</el-dropdown-item>
            <el-dropdown-item command="file">新建文本文件</el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
      <el-button :icon="'Download'" :disabled="!fm.hasSelection" @click="downloadSelected">下载</el-button>
      <el-button :icon="'Files'" :disabled="!fm.hasSelection" @click="clipCopy">复制</el-button>
      <el-button :icon="'Scissor'" :disabled="!fm.hasSelection" @click="clipCut">剪切</el-button>
      <el-button :icon="'BottomRight'" :disabled="!fm.clipboard" @click="clipPaste">粘贴</el-button>
      <input type="file" multiple hidden @change="pick" ref="pickFilesRef" />
      <input type="file" webkitdirectory hidden @change="pick" ref="pickFolderRef" />
    </div>
    <div class="fm-body" v-loading="fm.loading">
      <FileGrid v-if="fm.view === 'grid'" :rows="displayRows" :selected="fm.selected" @click-row="onRowClick" @ctx="onCtxMenu" @dblclick="openRow" @dragstart="dragStart" @drop-dir="dropToDir" />
      <FileTable v-else :rows="displayRows" :selected="fm.selected" :can-write="canWrite" @click-row="onRowClick" @dblclick="openRow" @ctx="onCtxMenu" @dragstart="dragStart" @drop-dir="dropToDir" @toggle-sel="(r) => fm.toggle(r._key.startsWith('d:') ? 'd' : 'f', r.id, true)" @select-all="() => fm.selectAll(displayRows)" @clear-all="fm.clearSelection" @action="onAction" />
    </div>

    <transition name="fade">
      <div v-if="fm.hasSelection" class="sel-bar">
        已选 {{ fm.selected.size }} 项
        <el-button size="small" text type="primary" @click="downloadSelected">下载</el-button>
        <el-button v-if="canWrite" size="small" text type="primary" @click="batchActive(true)">启用</el-button>
        <el-button v-if="canWrite" size="small" text type="primary" @click="batchActive(false)">停用</el-button>
        <el-button size="small" text type="primary" @click="clipCopy">复制</el-button>
        <el-button size="small" text type="primary" @click="clipCut">剪切</el-button>
        <el-button v-if="canWrite" size="small" text type="danger" @click="batchDelete">删除</el-button>
        <el-button v-if="canWrite" size="small" text type="danger" @click="batchPurge">彻底删除</el-button>
        <el-button size="small" text @click="fm.clearSelection">取消选择</el-button>
      </div>
    </transition>

    <!-- 拖入文件视觉反馈遮罩 -->
    <teleport to="body">
      <div v-if="dragOverlay" class="drag-overlay">
        <div class="drag-box">
          <el-icon :size="56" color="#409eff"><UploadFilled /></el-icon>
          <div class="drag-text">松开鼠标，上传到当前目录</div>
          <div class="drag-sub">/{{ fm.dirPath }}</div>
        </div>
      </div>
    </teleport>

    <ContextMenu :visible="state.ctx.visible" :x="state.ctx.x" :y="state.ctx.y" :kind="state.ctx.kind" :editable="true" @action="ctxAction" @close="state.ctx.visible = false" />

    <UploadDialog ref="uploadDialogRef" />
    <EditDialog v-if="dialogs.edit" :file="dialogs.edit" @close="dialogs.edit = null" @saved="load" />
    <PropertyDialog v-if="dialogs.prop" :data="dialogs.prop" @close="dialogs.prop = null" />
    <TrashDialog v-if="dialogs.trash" @close="dialogs.trash = false" @changed="load" />
    <SearchDialog v-if="dialogs.search" @close="dialogs.search = false" @goto="go($event)" />
  </div>
</template>

<style scoped>
@import './file-manager.css';
</style>
