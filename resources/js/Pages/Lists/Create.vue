<script setup>
import { computed, onUnmounted, ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const urls = ref('')
const csvFile = ref(null)
const csvFileName = ref('')
const sending = ref(false)
const error = ref(null)

// Itens da importacao em andamento (poll ate cada um sair de "pending" com
// total_count 0 — ou seja, ate o ImportPlaylistJob/ImportCsvJob de fato
// terminar de baixar/expandir e criar os canais, nao so ser enfileirado).
const items = ref([])
let pollTimer = null

const importing = computed(() => items.value.length > 0 && items.value.some((i) => i.done === false))
const doneCount = computed(() => items.value.filter((i) => i.done).length)
const progressPercent = computed(() => {
  if (items.value.length === 0) return 0
  return Math.round((doneCount.value / items.value.length) * 100)
})
const allSucceeded = computed(() => items.value.length > 0 && items.value.every((i) => i.done && i.status !== 'failed'))
const anyFailed = computed(() => items.value.some((i) => i.done && i.status === 'failed'))

function onFileChange(e) {
  csvFile.value = e.target.files[0] ?? null
  csvFileName.value = csvFile.value?.name ?? ''
}

function isImportDone(p) {
  // Mesma logica da tela "Listas carregadas": total_count > 0 quer dizer que
  // os canais ja foram criados (import terminou); status "failed" com
  // total_count 0 quer dizer que o download/parse falhou antes de criar
  // qualquer canal. Enquanto nenhum dos dois acontece, ainda esta baixando.
  return p.total_count > 0 || p.status === 'failed'
}

async function pollOnce() {
  const pending = items.value.filter((i) => !i.done)
  if (pending.length === 0) {
    clearInterval(pollTimer)
    pollTimer = null
    return
  }

  await Promise.all(pending.map(async (item) => {
    try {
      const { data: p } = await axios.get(`/api/v1/playlists/${item.id}`)
      if (isImportDone(p)) {
        item.done = true
        item.status = p.status
        item.total_count = p.total_count
        item.error_message = p.error_message
        item.name = p.name || p.url
      }
    } catch (e) {
      // Se a lista sumiu (ex.: removida em outra aba) nao trava o polling.
      item.done = true
      item.status = 'failed'
      item.error_message = 'Nao foi possivel confirmar o status.'
    }
  }))
}

async function submit() {
  sending.value = true
  error.value = null
  items.value = []
  clearInterval(pollTimer)
  pollTimer = null

  const form = new FormData()
  if (urls.value.trim()) form.append('urls', urls.value.trim())
  if (csvFile.value) form.append('csv', csvFile.value)

  try {
    const { data } = await axios.post('/api/v1/playlists', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    items.value = data.playlists.map((p) => ({
      id: p.id,
      name: p.name || p.url,
      done: false,
      status: p.status,
      total_count: 0,
      error_message: null,
    }))

    urls.value = ''
    csvFile.value = null
    csvFileName.value = ''

    pollTimer = setInterval(pollOnce, 1500)
    pollOnce()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Falha ao importar.'
  } finally {
    sending.value = false
  }
}

onUnmounted(() => clearInterval(pollTimer))
</script>

<template>
  <AuthenticatedLayout title="Cadastrar listas">
    <div class="mx-auto max-w-3xl space-y-6">
      <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4 sm:p-6">
        <h2 class="font-medium text-white">Links separados por vírgula</h2>
        <p class="mt-1 text-sm text-zinc-400">
          Aceita M3U, M3U8 e mídia direta (mp4, mkv, etc.) misturados — o sistema
          identifica cada link automaticamente ao processar.
        </p>
        <textarea
          v-model="urls"
          rows="6"
          class="mt-3 w-full rounded-md border-zinc-700 bg-zinc-800 font-mono text-sm text-zinc-100 placeholder-zinc-500 focus:border-violet-500 focus:ring-violet-500"
          placeholder="https://exemplo.com/lista.m3u8, https://exemplo.com/episodio.mp4"
        />
      </div>

      <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4 sm:p-6">
        <h2 class="font-medium text-white">Importar CSV (delimitado por ;)</h2>
        <p class="mt-1 text-sm text-zinc-400">
          Colunas: <code class="rounded bg-zinc-800 px-1 py-0.5">url;nome;pais;modo;tipo;genero;idioma;legenda</code>
          — só a url é obrigatória, o resto fica pendente de edição manual se vier vazio.
        </p>
        <label class="mt-3 flex cursor-pointer flex-col items-center justify-center rounded-md border border-dashed border-zinc-700 bg-zinc-800/50 px-4 py-6 text-center hover:border-violet-500">
          <span class="text-sm text-zinc-300">{{ csvFileName || 'Clique para escolher um arquivo .csv' }}</span>
          <input type="file" accept=".csv" class="hidden" @change="onFileChange" />
        </label>
      </div>

      <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center">
        <button
          class="w-full rounded-md bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-500 disabled:opacity-50 sm:w-auto"
          :disabled="sending || importing || (!urls.trim() && !csvFile)"
          @click="submit"
        >
          {{ sending ? 'Enviando...' : 'Importar' }}
        </button>

        <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
      </div>

      <div v-if="items.length > 0" class="rounded-xl border border-zinc-800 bg-zinc-900 p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-medium text-zinc-200">
            <span v-if="importing">Importando listas...</span>
            <span v-else-if="allSucceeded">Importação concluída</span>
            <span v-else>Importação concluída (com falhas)</span>
          </h3>
          <span class="text-xs text-zinc-500">{{ doneCount }}/{{ items.length }}</span>
        </div>

        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-800">
          <div
            class="h-full rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 transition-all"
            :style="{ width: progressPercent + '%' }"
          />
        </div>

        <ul class="mt-4 space-y-2">
          <li v-for="item in items" :key="item.id" class="flex items-center justify-between text-sm">
            <span class="max-w-[70%] truncate text-zinc-300" :title="item.name">{{ item.name }}</span>
            <span v-if="!item.done" class="flex items-center gap-1.5 text-xs text-zinc-500">
              <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
              </svg>
              baixando...
            </span>
            <span v-else-if="item.status === 'failed'" class="text-xs text-red-400" :title="item.error_message">
              falhou{{ item.error_message ? ': ' + item.error_message : '' }}
            </span>
            <span v-else class="text-xs text-emerald-400">
              {{ item.total_count }} canal(is) importado(s)
            </span>
          </li>
        </ul>

        <p v-if="!importing" class="mt-4 text-sm text-zinc-400">
          Vá em
          <Link :href="route('listas.index')" class="text-violet-400 underline decoration-violet-400/40 underline-offset-2 hover:text-violet-300">Listas carregadas</Link>
          para iniciar a verificação dos canais.
        </p>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
