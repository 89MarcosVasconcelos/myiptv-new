<script setup>
import { onMounted, ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

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
  if (!newName.value || !newCode.value) return
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
  <AuthenticatedLayout :title="`Cadastro de ${label}`">
    <div class="mx-auto max-w-2xl">
      <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4 sm:p-6">
        <div class="flex flex-col gap-2 sm:flex-row">
          <input
            v-model="newCode"
            :placeholder="usesCode ? 'código (ex: BR)' : 'slug (ex: acao)'"
            class="w-full rounded-md border-zinc-700 bg-zinc-800 text-sm text-zinc-100 placeholder-zinc-500 focus:border-violet-500 focus:ring-violet-500 sm:w-36"
          />
          <input
            v-model="newName"
            placeholder="nome"
            class="w-full flex-1 rounded-md border-zinc-700 bg-zinc-800 text-sm text-zinc-100 placeholder-zinc-500 focus:border-violet-500 focus:ring-violet-500"
          />
          <button class="w-full rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-500 sm:w-auto" @click="create">
            Adicionar
          </button>
        </div>
      </div>

      <ul class="mt-4 divide-y divide-zinc-800 rounded-xl border border-zinc-800 bg-zinc-900">
        <li v-for="item in items" :key="item.id" class="flex items-center justify-between px-4 py-3">
          <span class="text-zinc-200">
            {{ item.name }}
            <span class="ml-1 text-xs text-zinc-500">({{ item.code ?? item.slug }})</span>
          </span>
          <button class="text-xs text-red-400 hover:text-red-300" @click="remove(item)">remover</button>
        </li>
        <li v-if="items.length === 0" class="px-4 py-6 text-center text-sm text-zinc-500">
          Nenhum registro ainda.
        </li>
      </ul>
    </div>
  </AuthenticatedLayout>
</template>
