<template>
  <div class="user-list-wrapper">
    <h3>帳號列表清單</h3>
    
    <!-- 顯示分頁資訊摘要 -->
    <div class="summary-box" v-if="paginationInfo">
      <span>帳號總數：<strong>{{ paginationInfo.total_count }}</strong></span> | 
      <span>總共頁數：<strong>{{ paginationInfo.total_pages }}</strong> 頁</span>
    </div>

    <!-- 資料表格 -->
    <table v-if="users.length > 0">
      <thead>
        <tr>
          <th>ID</th>
          <th>帳號</th>
          <th>姓名</th>
          <th>信箱</th>
          <th>建立時間</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="user in users" :key="user.id">
          <td>{{ user.id }}</td>
          <td>{{ user.account }}</td>
          <td>{{ user.name }}</td>
          <td>{{ user.email }}</td>
          <td>{{ user.created_at }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else class="no-data">目前沒有資料，或資料載入中...</p>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import axios from 'axios'

// 定義接收來自父元件的參數
const props = defineProps({
  page: { type: Number, required: true },
  limit: { type: Number, required: true }
})

// 定義要傳遞給父元件的事件 (向外廣播分頁資訊)
const emit = defineEmits(['update-pagination'])

const users = ref([])
const paginationInfo = ref(null)

// 獲取資料的方法
const fetchUserList = async () => {
  const token = localStorage.getItem('userToken')
  if (!token) return

  try {
    const response = await axios.get(`http://localhost:8000/users.php`, {
      params: { page: props.page, limit: props.limit },
      headers: { 'Authorization': `Bearer ${token}` }
    })

    if (response.data.success) {
      users.value = response.data.data
      paginationInfo.value = response.data.pagination
      
      // 成功取得資料後，將分頁資訊傳遞給 Dashboard.vue 以控制左右按鈕
      emit('update-pagination', response.data.pagination)
    } else {
      console.error(response.data.message)
    }
  } catch (error) {
    console.error('取得列表失敗', error)
  }
}

// 首次掛載時取得資料
onMounted(() => {
  fetchUserList()
})

// 監聽 Props 變化：當父元件改變 page 或 limit 時，自動重新打 API 拿資料
watch(
  () => [props.page, props.limit],
  () => {
    fetchUserList()
  }
)
</script>

<style scoped>
.user-list-wrapper { 
  margin-top: 15px; 
  padding: 15px; 
  border: 1px solid #555; 
  border-radius: 8px;
}

.summary-box { 
  background-color: rgba(255, 255, 255, 0.1); 
  color: inherit;
  padding: 10px; 
  margin-bottom: 15px; 
  border-radius: 4px;
}

table { width: 100%; border-collapse: collapse; margin-bottom: 10px;}
th, td { border: 1px solid #555; padding: 10px; text-align: left; }
th { background-color: rgba(255, 255, 255, 0.15); color: inherit;}
.no-data { color: #888; text-align: center; padding: 20px; }
</style>
