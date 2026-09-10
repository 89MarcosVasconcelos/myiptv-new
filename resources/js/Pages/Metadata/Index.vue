<script setup>
import { onMounted, ref } from 'vue'
import axios from 'axios'

const props = defineProps({ type: String, label: String })

const items = ref([])
const newName = ref('')
const newCode = ref('')
const usesCode = ['paises', 'idiomas', 'legendas'].includes(props.type)

async function load() {
  const { data } = await axios.get(`/api/v1/metadata/${props.type}`)
  items.value = data.data
}

async function create() {
  const payload = { name: newName.value }
  payload[usesCode ? 'code' : 'slug'] = newCode.value
  await axios.post(`/api/v1/metadata/${props.type}`, payload)
  newName.value = ''
  newCode.value = ''
  await load()
}

async function remove(item) {
  await axios.delete(`/api/v1/metadata/${props.type}/${item.id}`)
  await load()
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl mx-auto p-6">
    <h1 class="text-xl font-semibold mb-4">Cadastro de {{ label }}</h1>

    <div class="flex gap-2 mb-6">
      <input v-model="newCode" :placeholder="usesCode ? 'código (ex: BR)' : 'slug (ex: acao)'" class="border rounded px-2 py-1 w-32" />
      <input v-model="newName" placeholder="nome" class="border rounded px-2 py-1 flex-1" />
      <button class="bg-blue-600 text-white px-3 py-1 rounded" @click="create">Adicionar</button>
    </div>

    <ul class="divide-y">
      <li v-for="item in items" :key="item.id" class="flex justify-between py-2">
        <span>{{ item.name }} <span class="text-gray-400 text-sm">({{ item.code ?? item.slug }})</span></span>
        <button class="text-red-600 text-sm" @click="remove(item)">remover</button>
      </li>
    </ul>
  </div>
</template>
