<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const shortcuts = [
  { href: 'listas.importar', title: 'Cadastrar listas', desc: 'Cole links ou importe um CSV' },
  { href: 'listas.index', title: 'Listas carregadas', desc: 'Status, métricas e reprocessamento' },
  { href: 'canais.index', title: 'Itens / canais', desc: 'Edição manual de metadados' },
  { href: 'metadados.index', param: 'paises', title: 'Metadados', desc: 'País, modo, tipo, gênero, idioma, legenda' },
  { href: 'player.show', title: 'Player', desc: 'Buscar e assistir' },
];
</script>

<template>
    <Head title="Início" />

    <AuthenticatedLayout title="Início">
        <div class="mx-auto max-w-5xl">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="s in shortcuts"
                    :key="s.title"
                    :href="s.param ? route(s.href, s.param) : route(s.href)"
                    class="rounded-xl border border-zinc-800 bg-zinc-900 p-5 transition hover:border-violet-500 hover:bg-zinc-800/60"
                >
                    <p class="font-medium text-white">{{ s.title }}</p>
                    <p class="mt-1 text-sm text-zinc-400">{{ s.desc }}</p>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
