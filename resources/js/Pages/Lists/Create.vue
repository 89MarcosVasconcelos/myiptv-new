<script setup>
import { ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const urls = ref('')
const csvFile = ref(null)
const csvFileName = ref('')
const sending = ref(false)
const result = ref(null)
const error = ref(null)

function onFileChange(e) {
  csvFile.value = e.target.files[0] ?? null
  csvFileName.value = csvFile.value?.name ?? ''
}

async function submit() {
  sending.value = true
  error.value = null
  result.value = null

  const form = new FormData()
  if (urls.value.trim()) form.append('urls', urls.value.trim())
  if (csvFile.value) form.append('csv', csvFile.value)

  try {
    const { data } = await axios.post('/api/v1/playlists', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    result.value = data
    urls.value = ''
    csvFile.value = null
    csvFileName.value = ''
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Falha ao importar.'
  } finally {
    sending.value = false
  }
}
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
          :disabled="sending || (!urls.trim() && !csvFile)"
          @click="submit"
        >
          {{ sending ? 'Enviando...' : 'Importar' }}
        </button>

        <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
        <p v-if="result" class="text-sm text-emerald-400">
          {{ result.created }} lista(s)/item(ns) enviados para processamento.
        </p>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
