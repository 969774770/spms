<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '../stores/user'
import { ROLE_NAMES } from '../utils/format'

const route = useRoute()
const router = useRouter()
const store = useUserStore()

const menus = computed(() => {
  const list = [
    { path: '/', title: '仪表盘', icon: 'Odometer' },
    { path: '/software', title: '软件管理', icon: 'Box' },
  ]
  if (store.isAdmin) {
    list.push(
      { path: '/users', title: '用户管理', icon: 'User' },
      { path: '/audit', title: '审计日志', icon: 'Document' },
      { path: '/settings', title: '系统设置', icon: 'Setting' },
    )
  }
  return list
})

const activePath = computed(() => {
  const p = route.path
  if (p.startsWith('/software')) return '/software'
  return p
})

const roleText = computed(() => store.roles.map((r) => ROLE_NAMES[r] || r).join(' / '))

function onUserCommand(cmd) {
  if (cmd === 'profile') router.push('/profile')
  else if (cmd === 'logout') store.logout()
}
</script>

<template>
  <el-container class="layout">
    <el-aside width="200px" class="aside">
      <div class="logo">
        <el-icon :size="22" color="#409eff"><Box /></el-icon>
        <span>{{ store.siteName || '软件发布管理系统' }}</span>
      </div>
      <el-menu :default-active="activePath" router background-color="#1f2d3d" text-color="#bfcbd9" active-text-color="#409eff">
        <el-menu-item v-for="m in menus" :key="m.path" :index="m.path">
          <el-icon><component :is="m.icon" /></el-icon>
          <span>{{ m.title }}</span>
        </el-menu-item>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="header" height="52px">
        <el-breadcrumb separator="/">
          <el-breadcrumb-item :to="{ path: '/' }">首页</el-breadcrumb-item>
          <el-breadcrumb-item>{{ route.meta.title || '' }}</el-breadcrumb-item>
        </el-breadcrumb>
        <div class="header-right">
          <el-dropdown @command="onUserCommand">
            <span class="user-chip">
              <el-icon><UserFilled /></el-icon>
              {{ store.user?.nickname || store.user?.username }}
              <span class="user-role">{{ roleText }}</span>
              <el-icon><ArrowDown /></el-icon>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="profile">个人资料</el-dropdown-item>
                <el-dropdown-item command="logout" divided>退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>
      <el-main class="main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped>
.layout {
  height: 100%;
}

.aside {
  background: #1f2d3d;
}

.logo {
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #fff;
  font-weight: 600;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.aside :deep(.el-menu) {
  border-right: none;
}

.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
}

.user-chip {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  color: #303133;
  outline: none;
}

.user-role {
  color: #909399;
  font-size: 12px;
}

.main {
  padding: 0;
  overflow: auto;
}
</style>
