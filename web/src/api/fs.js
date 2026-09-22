import { api, post } from './request'
import http from './request'

/** fs 域接口封装 */
export const fsApi = {
  tree: (softwareId) => api('fs.tree', { software_id: softwareId }),
  ls: (softwareId, dirPath) => api('fs.ls', { software_id: softwareId, dir_path: dirPath }),
  stat: (softwareId, type, id) => api('fs.stat', { software_id: softwareId, target_type: type, target_id: id }),
  search: (params) => api('fs.search', params),
  mkdir: (softwareId, dirPath, name) => post('fs.mkdir', { software_id: softwareId, dir_path: dirPath, name }),
  dirRename: (softwareId, dirId, newName) => post('fs.dir.rename', { software_id: softwareId, dir_id: dirId, new_name: newName }),
  dirMove: (softwareId, dirId, targetDirId) => post('fs.dir.move', { software_id: softwareId, dir_id: dirId, target_dir_id: targetDirId }),
  dirCopy: (softwareId, dirId, targetDirId) => post('fs.dir.copy', { software_id: softwareId, dir_id: dirId, target_dir_id: targetDirId }),
  dirDelete: (softwareId, dirId) => post('fs.dir.delete', { software_id: softwareId, dir_id: dirId }),
  fileRename: (softwareId, fileId, newName) => post('fs.file.rename', { software_id: softwareId, file_id: fileId, new_name: newName }),
  fileNote: (softwareId, fileId, note) => post('fs.file.note', { software_id: softwareId, file_id: fileId, note }),
  fileActive: (softwareId, fileId, active) => post('fs.file.active', { software_id: softwareId, file_id: fileId, active }),
  fileAutoRun: (softwareId, fileId, autoRun) => post('fs.file.autoRun', { software_id: softwareId, file_id: fileId, auto_run: autoRun }),
  fileForce: (softwareId, fileId, forceOverwrite) => post('fs.file.force', { software_id: softwareId, file_id: fileId, force_overwrite: forceOverwrite }),
  fileRead: (softwareId, fileId) => api('fs.file.readText', { software_id: softwareId, file_id: fileId }),
  fileWrite: (softwareId, fileId, content) => post('fs.file.writeText', { software_id: softwareId, file_id: fileId, content }),
  fileMove: (softwareId, fileId, targetDirId) => post('fs.file.move', { software_id: softwareId, file_id: fileId, target_dir_id: targetDirId }),
  fileCopy: (softwareId, fileId, targetDirId) => post('fs.file.copy', { software_id: softwareId, file_id: fileId, target_dir_id: targetDirId }),
  batchMove: (softwareId, fileIds, dirIds, targetDirId) => post('fs.batch.move', { software_id: softwareId, file_ids: fileIds, dir_ids: dirIds, target_dir_id: targetDirId }),
  batchCopy: (softwareId, fileIds, targetDirId) => post('fs.batch.copy', { software_id: softwareId, file_ids: fileIds, target_dir_id: targetDirId }),
  batchDelete: (softwareId, fileIds, dirIds) => post('fs.batch.delete', { software_id: softwareId, file_ids: fileIds, dir_ids: dirIds }),
  batchPurge: (softwareId, fileIds, dirIds) => post('fs.batch.purge', { software_id: softwareId, file_ids: fileIds, dir_ids: dirIds }),
  batchActive: (softwareId, fileIds, active) => post('fs.batch.active', { software_id: softwareId, file_ids: fileIds, active }),
  trashList: (softwareId, type, page) => api('fs.trash.list', { software_id: softwareId, type, page, page_size: 50 }),
  trashRestore: (softwareId, fileIds, dirIds) => post('fs.trash.restore', { software_id: softwareId, file_ids: fileIds, dir_ids: dirIds }),
  trashPurge: (softwareId, fileIds, dirIds) => post('fs.trash.purge', { software_id: softwareId, file_ids: fileIds, dir_ids: dirIds }),
  trashEmpty: (softwareId) => post('fs.trash.empty', { software_id: softwareId }),
}

/** 上传域接口 */
export const uploadApi = {
  probe: (data) => post('upload.probe', data),
  init: (data) => post('upload.init', data),
  part: (uuid, part, blob) => http.post(`/api.php?action=upload.part&uuid=${uuid}&part=${part}`, blob, {
    headers: { 'Content-Type': 'application/octet-stream' },
  }),
  status: (uuid) => api('upload.status', { uuid }),
  finish: (uuid) => post('upload.finish', { uuid }),
  abort: (uuid) => post('upload.abort', { uuid }),
}
