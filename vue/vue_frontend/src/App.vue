<template>
  <div id="app">
    <RouterView />
  </div>
</template>

<script setup>
import { RouterView } from 'vue-router'
</script>

<style>
/* 簡單的基本全域樣式 */
#app {
  font-family: sans-serif;
  max-width: 600px;
  margin: 40px auto;
  padding: 0 20px;
}
</style>
