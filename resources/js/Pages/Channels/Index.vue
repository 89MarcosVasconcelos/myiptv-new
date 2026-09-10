<script setup>
import { onMounted, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
  countries: Array,
  modes: Array,
  contentTypes: Array,
  genres: Array,
  languages: Array,
  subtitles: Array,
})

const channels = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  const { data } = await axios.get('/api/v1/channels')
  channels.value = data.channels.data
  loading.value = false
}

async function saveField(channel, field, value) {
  await axios.patch(`/api/v1/channels/${channel.id}`, { [field]: value || null })
}

async function recheck(channel) {
  channel._rechecking = true
  await axios.post(`/api/v1/channels/${channel.id}/recheck`)
  channel._rechecking = false
}

onMounted(load)
</script>

<template>
  <div class="max-w-6xl mx-auto p-6">
    <h1 class="text-xl font-semibold mb-4">Itens carregados</h1>

    <table class="w-full text-xs border-collapse">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2">Nome</th>
          <th>Status</th>
          <th>País</th>
          <th>Modo</th>
          <th>Tipo</th>
          <th>Gênero</th>
          <th>Idioma</th>
          <th>Legenda</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="c in channels" :key="c.id" class="border-b">
          <td class="py-2">{{ c.name }}</td>
          <td>{{ c.status }}</td>
          <td>
            <select :value="c.country_id" @change="saveField(c, 'country_id', $event.target.value)">
              <option value="">—</option>
              <option v-for="o in countries" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </td>
          <td>
            <select :value="c.mode_id" @change="saveField(c, 'mode_id', $event.target.value)">
              <option value="">—</option>
              <option v-for="o in modes" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </td>
          <td>
            <select :value="c.content_type_id" @change="saveField(c, 'content_type_id', $event.target.value)">
              <option value="">—</option>
              <option v-for="o in contentTypes" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </td>
          <td>
            <select :value="c.genre_id" @change="saveField(c, 'genre_id', $event.target.value)">
              <option value="">—</option>
              <option v-for="o in genres" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </td>
          <td>
            <select :value="c.language_id" @change="saveField(c, 'language_id', $event.target.value)">
              <option value="">—</option>
              <option v-for="o in languages" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </td>
          <td>
            <select :value="c.subtitle_id" @change="saveField(c, 'subtitle_id', $event.target.value)">
              <option value="">—</option>
              <option v-for="o in subtitles" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </td>
          <td>
            <button class="text-blue-600 underline" :disabled="c._rechecking" @click="recheck(c)">
              Reprocessar
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="!loading && channels.length === 0" class="text-gray-500 mt-4">
      Nenhum item carregado ainda.
    </p>
  </div>
</template>
