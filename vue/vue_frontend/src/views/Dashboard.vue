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
  const token = localStorage.getItem('userToken')
  
  if (!token) {
    alert('找不到登入憑證，請重新登入')
    router.push('/login')
    return
  }

  try {
    // 🔥 改變呼叫方式：不再傳遞 ?account=，而是把 Token 放在 Header 的 Authorization 裡
    const response = await axios.get('http://localhost:8000/profile.php', {
      headers: {
        'Authorization': `Bearer ${token}`
      }
    })

    if (response.data.success) {
      userInfo.value = response.data.user
    } else {
      alert(response.data.message) // 顯示後端退回的錯誤 (如過期、偽造)
      handleLogout() // 驗證失敗就踢回登入頁
    }
  } catch (error) {
    console.error('取得資料失敗', error)
    handleLogout()
  }
})

const handleLogout = () => {
  localStorage.removeItem('userToken') // 登出時清掉 Token
  router.push('/login')
}
</script>

<style scoped>
.dashboard-container { border: 1px solid #4CAF50; padding: 20px; border-radius: 8px; }
button { background-color: #f44336; color: white; border: none; padding: 8px 16px; cursor: pointer; }
</style>
