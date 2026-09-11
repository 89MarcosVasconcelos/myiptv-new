<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'

defineProps({ title: { type: String, default: '' } })

const sidebarOpen = ref(false)
const metadataOpen = ref(false)

const metadataLinks = [
  { type: 'paises', label: 'Países' },
  { type: 'modos', label: 'Modos' },
  { type: 'tipos', label: 'Tipos' },
  { type: 'generos', label: 'Gêneros' },
  { type: 'idiomas', label: 'Idiomas' },
  { type: 'legendas', label: 'Legendas' },
]

function isCurrent(name) {
  return route().current(name)
}
</script>

<template>
  <div class="min-h-screen bg-zinc-950 text-zinc-100">
    <!-- Overlay mobile -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-30 bg-black/60 md:hidden"
      @click="sidebarOpen = false"
    />

    <!-- Sidebar -->
    <aside
      class="fixed inset-y-0 left-0 z-40 w-64 transform border-r border-zinc-800 bg-zinc-900 transition-transform duration-200 ease-in-out md:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-full flex-col">
        <div class="flex items-center gap-2 border-b border-zinc-800 px-5 py-4">
          <span class="flex h-8 w-8 items-center justify-center rounded-md bg-gradient-to-br from-violet-500 to-fuchsia-600 font-bold text-white">M</span>
          <Link :href="route('dashboard')" class="text-lg font-semibold tracking-tight text-white">
            MP<span class="text-violet-400">IPTV</span>
          </Link>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4 text-sm">
          <Link
            :href="route('dashboard')"
            class="block rounded-lg px-3 py-2 font-medium transition"
            :class="isCurrent('dashboard') ? 'bg-violet-600/20 text-violet-300' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
          >
            Início
          </Link>

          <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">Listas</p>
          <Link
            :href="route('listas.importar')"
            class="block rounded-lg px-3 py-2 font-medium transition"
            :class="isCurrent('listas.importar') ? 'bg-violet-600/20 text-violet-300' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
          >
            Cadastrar listas
          </Link>
          <Link
            :href="route('listas.index')"
            class="block rounded-lg px-3 py-2 font-medium transition"
            :class="isCurrent('listas.index') ? 'bg-violet-600/20 text-violet-300' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
          >
            Listas carregadas
          </Link>
          <Link
            :href="route('canais.index')"
            class="block rounded-lg px-3 py-2 font-medium transition"
            :class="isCurrent('canais.index') ? 'bg-violet-600/20 text-violet-300' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
          >
            Itens / canais
          </Link>
          <Link
            :href="route('fila.index')"
            class="block rounded-lg px-3 py-2 font-medium transition"
            :class="isCurrent('fila.index') ? 'bg-violet-600/20 text-violet-300' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
          >
            Fila de processamento
          </Link>

          <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">Metadados</p>
          <button
            type="button"
            class="flex w-full items-center justify-between rounded-lg px-3 py-2 font-medium text-zinc-300 transition hover:bg-zinc-800 hover:text-white"
            @click="metadataOpen = !metadataOpen"
          >
            Taxonomias
            <svg class="h-4 w-4 transition-transform" :class="metadataOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
          </button>
          <div v-show="metadataOpen" class="space-y-1 pl-3">
            <Link
              v-for="m in metadataLinks"
              :key="m.type"
              :href="route('metadados.index', m.type)"
              class="block rounded-lg px-3 py-1.5 text-sm transition"
              :class="isCurrent('metadados.index') && $page.url.includes(m.type) ? 'bg-violet-600/20 text-violet-300' : 'text-zinc-400 hover:bg-zinc-800 hover:text-white'"
            >
              {{ m.label }}
            </Link>
          </div>

          <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">Exibição</p>
          <Link
            :href="route('player.show')"
            class="flex items-center gap-2 rounded-lg px-3 py-2 font-medium transition"
            :class="isCurrent('player.show') ? 'bg-violet-600/20 text-violet-300' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white'"
          >
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.34-5.89a1.5 1.5 0 000-2.54L6.3 2.84z" /></svg>
            Player
          </Link>
        </nav>

        <div class="border-t border-zinc-800 p-3">
          <div class="flex items-center justify-between rounded-lg px-3 py-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-white">{{ $page.props.auth.user.name }}</p>
              <p class="truncate text-xs text-zinc-500">{{ $page.props.auth.user.email }}</p>
            </div>
          </div>
          <div class="mt-1 flex gap-2 px-3">
            <Link :href="route('profile.edit')" class="text-xs text-zinc-400 hover:text-white">Perfil</Link>
            <Link :href="route('logout')" method="post" as="button" class="text-xs text-zinc-400 hover:text-white">Sair</Link>
          </div>
        </div>
      </div>
    </aside>

    <!-- Conteudo -->
    <div class="md:pl-64">
      <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-zinc-800 bg-zinc-950/95 px-4 py-3 backdrop-blur md:px-8">
        <button type="button" class="text-zinc-300 md:hidden" @click="sidebarOpen = true">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <h1 v-if="title" class="text-base font-semibold text-white md:text-lg">{{ title }}</h1>
        <slot name="header" />
      </header>

      <main class="px-4 py-6 md:px-8">
        <slot />
      </main>
    </div>
  </div>
</template>
