<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({ type: String, label: String })

const items = ref([])
const meta = ref(null)
const loading = ref(true)
const q = ref('')
const page = ref(1)
const sort = ref('name')
const dir = ref('asc')
const newName = ref('')
const newCode = ref('')
const usesCode = ['paises', 'idiomas', 'legendas'].includes(props.type)
const codeLabel = usesCode ? 'Código' : 'Slug'

async function load() {
  loading.value = true
  try {
    const { data } = await axios.get(`/api/v1/metadata/${props.type}`, {
      params: { q: q.value || undefined, page: page.value, sort: sort.value, dir: dir.value },
    })
    items.value = data.data
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total }
  } finally {
    loading.value = false
  }
}

function toggleSort(key) {
  if (sort.value === key) {
    dir.value = dir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = key
    dir.value = 'asc'
  }
  page.value = 1
  load()
}

function goToPage(p) {
  if (p < 1 || (meta.value && p > meta.value.last_page)) return
  page.value = p
  load()
}

let searchTimer = null
watch(q, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 350)
})

// Troca de taxonomia (ex.: Países -> Tipos) sem sair da SPA: reseta filtros.
watch(() => props.type, () => {
  q.value = ''
  page.value = 1
  sort.value = 'name'
  dir.value = 'asc'
  load()
})

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

load()
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

      <div class="mt-4 flex items-center justify-between gap-2">
        <p class="text-xs text-zinc-500">{{ meta ? `${meta.total} registros` : '' }}</p>
        <input
          v-model="q"
          type="text"
          placeholder="Buscar..."
          class="w-48 rounded-md border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none"
        />
      </div>

      <div class="mt-2 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-zinc-800 text-left text-xs uppercase tracking-wider text-zinc-500">
              <th class="cursor-pointer select-none px-4 py-3 hover:text-zinc-300" @click="toggleSort('name')">
                Nome
                <span v-if="sort === 'name'" class="ml-1 text-violet-400">{{ dir === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="cursor-pointer select-none px-4 py-3 hover:text-zinc-300" @click="toggleSort(usesCode ? 'code' : 'slug')">
                {{ codeLabel }}
                <span v-if="sort === (usesCode ? 'code' : 'slug')" class="ml-1 text-violet-400">{{ dir === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id" class="border-b border-zinc-800/60 last:border-0 hover:bg-zinc-800/40">
              <td class="px-4 py-3 text-zinc-200">{{ item.name }}</td>
              <td class="px-4 py-3 text-zinc-400">{{ item.code ?? item.slug }}</td>
              <td class="px-4 py-3 text-right">
                <button class="text-xs text-red-400 hover:text-red-300" @click="remove(item)">remover</button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!loading && items.length === 0" class="px-4 py-6 text-center text-sm text-zinc-500">
          Nenhum registro{{ q ? ' para essa busca' : ' ainda' }}.
        </p>
      </div>

      <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center justify-center gap-3 text-sm text-zinc-400">
        <button class="rounded-md border border-zinc-700 px-2 py-1 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">Anterior</button>
        <span>Página {{ meta.current_page }} de {{ meta.last_page }} ({{ meta.total }})</span>
        <button class="rounded-md border border-zinc-700 px-2 py-1 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">Próxima</button>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
