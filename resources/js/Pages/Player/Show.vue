<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import Hls from 'hls.js'

const props = defineProps({
  countries: Array,
  modes: Array,
  contentTypes: Array,
  genres: Array,
})

// Selects encadeados: cada mudanca reconsulta o endpoint faceted, que devolve
// so as opcoes que ainda tem canal correspondente.
const filters = ref({ country_id: '', mode_id: '', content_type_id: '', genre_id: '', q: '' })
const channels = ref([])
const facets = ref({ countries: [], modes: [], content_types: [], genres: [] })
const selected = ref(null)
const videoEl = ref(null)
let hls = null

async function search() {
  const params = { ...filters.value }
  Object.keys(params).forEach((k) => { if (!params[k]) delete params[k] })

  const { data } = await axios.get('/api/v1/channels', { params })
  channels.value = data.channels.data
  facets.value = data.facets
}

function selectChannel(channel) {
  selected.value = channel

  if (hls) {
    hls.destroy()
    hls = null
  }

  if (channel.stream_type === 'youtube') {
    return // renderizado via iframe no template
  }

  const video = videoEl.value
  if (channel.stream_type === 'hls' && Hls.isSupported()) {
    hls = new Hls()
    hls.loadSource(channel.url)
    hls.attachMedia(video)
  } else {
    video.src = channel.url
    video.play()
  }
}

watch(filters, search, { deep: true })
search()
</script>

<template>
  <div class="max-w-4xl mx-auto p-6 space-y-4">
    <h1 class="text-xl font-semibold">Exibição</h1>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
      <select v-model="filters.country_id">
        <option value="">País (todos)</option>
        <option v-for="o in countries" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
      <select v-model="filters.mode_id">
        <option value="">Modo (todos)</option>
        <option v-for="o in modes" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
      <select v-model="filters.content_type_id">
        <option value="">Tipo (todos)</option>
        <option v-for="o in contentTypes" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
      <select v-model="filters.genre_id">
        <option value="">Gênero (todos)</option>
        <option v-for="o in genres" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
    </div>

    <input
      v-model="filters.q"
      placeholder="Buscar canal..."
      class="w-full border rounded px-2 py-1"
    />

    <select class="w-full border rounded px-2 py-2" @change="selectChannel(channels.find(c => c.id == $event.target.value))">
      <option value="">Selecione um canal ({{ channels.length }} encontrados)</option>
      <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
    </select>

    <div class="aspect-video bg-black">
      <video v-if="selected && selected.stream_type !== 'youtube'" ref="videoEl" controls autoplay class="w-full h-full" />
      <iframe
        v-else-if="selected && selected.stream_type === 'youtube'"
        :src="selected.url.replace('watch?v=', 'embed/')"
        class="w-full h-full"
        allowfullscreen
      />
      <div v-else class="text-gray-400 flex items-center justify-center h-full">
        Selecione um canal para iniciar
      </div>
    </div>
  </div>
</template>
