<script setup>
import { ref } from 'vue'
import axios from 'axios'

const urls = ref('')
const csvFile = ref(null)
const sending = ref(false)
const result = ref(null)
const error = ref(null)

function onFileChange(e) {
  csvFile.value = e.target.files[0] ?? null
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
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Falha ao importar.'
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="max-w-3xl mx-auto p-6 space-y-6">
    <h1 class="text-xl font-semibold">Cadastrar listas</h1>

    <div class="space-y-2">
      <label class="block font-medium">Links separados por vírgula</label>
      <p class="text-sm text-gray-500">
        Aceita M3U, M3U8 e mídia direta (mp4, mkv, etc.) misturados — o sistema
        identifica cada link automaticamente ao processar.
      </p>
      <textarea
        v-model="urls"
        rows="6"
        class="w-full border rounded p-2 font-mono text-sm"
        placeholder="https://exemplo.com/lista.m3u8, https://exemplo.com/episodio.mp4"
      />
    </div>

    <div class="space-y-2">
      <label class="block font-medium">Importar CSV (delimitado por ;)</label>
      <p class="text-sm text-gray-500">
        Colunas: url;nome;pais;modo;tipo;genero;idioma;legenda — só a url é
        obrigatória, o resto fica pendente de edição manual se vier vazio.
      </p>
      <input type="file" accept=".csv" @change="onFileChange" />
    </div>

    <button
      class="bg-blue-600 text-white px-4 py-2 rounded disabled:opacity-50"
      :disabled="sending || (!urls.trim() && !csvFile)"
      @click="submit"
    >
      {{ sending ? 'Enviando...' : 'Importar' }}
    </button>

    <div v-if="error" class="text-red-600">{{ error }}</div>
    <div v-if="result" class="text-green-700">
      {{ result.created }} lista(s)/item(ns) enviados para processamento.
    </div>
  </div>
</template>
