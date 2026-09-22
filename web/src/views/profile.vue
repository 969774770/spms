<script setup>
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { post } from '../api/request'
import { useUserStore } from '../stores/user'

const user = useUserStore()
const formRef = ref()
const saving = ref(false)
const nickSaving = ref(false)
const nickname = ref(user.user?.nickname || '')
const form = reactive({ old_password: '', new_password: '', confirm: '' })
const rules = {
  old_password: [{ required: true, message: '请输入旧密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, message: '至少 6 位', trigger: 'blur' },
  ],
  confirm: [{
    validator: (_r, v, cb) => (v === form.new_password ? cb() : cb(new Error('两次输入不一致'))),
    trigger: 'blur',
  }],
}

async function saveNickname() {
  nickSaving.value = true
  try {
    const res = await post('auth.updateProfile', { nickname: nickname.value })
    user.user.nickname = res.nickname
    ElMessage.success('昵称已修改')
  } finally {
    nickSaving.value = false
  }
}

async function save() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  saving.value = true
  try {
    await post('auth.changePassword', { old_password: form.old_password, new_password: form.new_password })
    ElMessage.success('密码已修改')
    form.old_password = form.new_password = form.confirm = ''
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page-card">
    <el-row :gutter="16">
      <el-col :span="10">
        <el-card shadow="never" header="昵称">
          <div class="nick-row">
            <el-input v-model="nickname" maxlength="64" style="max-width: 260px" />
            <el-button type="primary" :loading="nickSaving" @click="saveNickname">保存昵称</el-button>
          </div>
        </el-card>
      </el-col>
      <el-col :span="14">
        <el-card shadow="never" header="修改密码（须校验旧密码）">
          <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" style="max-width: 420px">
            <el-form-item label="旧密码" prop="old_password">
              <el-input v-model="form.old_password" type="password" show-password />
            </el-form-item>
            <el-form-item label="新密码" prop="new_password">
              <el-input v-model="form.new_password" type="password" show-password />
            </el-form-item>
            <el-form-item label="确认新密码" prop="confirm">
              <el-input v-model="form.confirm" type="password" show-password />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="save">修改密码</el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<style scoped>
.nick-row {
  display: flex;
  gap: 8px;
}
</style>
