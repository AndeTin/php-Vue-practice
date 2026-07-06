<template>
  <div class="dashboard-container">
    <h2>會員後台</h2>
    
    <div v-if="userInfo">
      <p><strong>帳號：</strong> {{ userInfo.account }}</p>
      <p><strong>姓名：</strong> {{ userInfo.name }}</p>
      <p><strong>信箱：</strong> {{ userInfo.email }}</p>
      <p><strong>加入時間：</strong> {{ userInfo.created_at }}</p>
      
      <button @click="handleLogout">登出</button>
    </div>
    
    <div v-else>
      <p>資料載入中或發生錯誤...</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const userInfo = ref(null)

onMounted(async () => {
  // 從 localStorage 取出登入時存的帳號
  const account = localStorage.getItem('userAccount')
  
  if (!account) {
    alert('找不到登入資訊，請重新登入')
    router.push('/login')
    return
  }

  // 向後端請求個人資料
  try {
    const response = await axios.get(`http://localhost:8000/profile.php?account=${account}`)
    if (response.data.success) {
      userInfo.value = response.data.user
    } else {
      alert('無法取得使用者資料')
    }
  } catch (error) {
    console.error('取得資料失敗', error)
  }
})

// 登出邏輯
const handleLogout = () => {
  // 清除 localStorage 的記錄
  localStorage.removeItem('userAccount')
  // 導向回登入頁
  router.push('/login')
}
</script>

<style scoped>
.dashboard-container { border: 1px solid #4CAF50; padding: 20px; border-radius: 8px; }
button { background-color: #f44336; color: white; border: none; padding: 8px 16px; cursor: pointer; }
</style>
