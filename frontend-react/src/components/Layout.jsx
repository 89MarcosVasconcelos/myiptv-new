import { useState, useEffect } from "preact/hooks";
import { Link, useLocation, navigate } from "../lib/router.jsx";
import { adminApi } from "../lib/api.js";

// Layout do projeto MPIPTV (herdado do front Laravel/Vue anterior): sidebar
// fixa de 16rem com seções, cabeçalho "sticky" translúcido com o título da
// página e gaveta no mobile. As CORES são os tokens do DevolpsTV
// (tailwind.config.js) — só a estrutura/classes são as nossas.
const SECTIONS = [
  {
    items: [["/", "Início", "▚"]],
  },
  {
    title: "Administração",
    admin: true,
    items: [
      ["/admin", "Dashboard", "▦"],
      ["/admin/banco", "Banco de dados", "🗄"],
      ["/admin/usuarios", "Cadastro de usuários", "👥"],
      ["/admin/fontes", "Importar fontes", "⬇"],
    ],
  },
  {
    title: "Listas",
    admin: true,
    items: [
      ["/admin/cadastro-canais", "Cadastrar listas", "＋"],
      ["/admin/canais", "Itens / canais", "📡"],
      ["/admin/cadastro-vod", "Cadastrar VOD", "🎞"],
      ["/admin/catalogo", "Catálogo VOD", "🗂"],
      ["/admin/sistema", "Fila de processamento", "⏱"],
    ],
  },
  {
    title: "Metadados",
    admin: true,
    items: [["/admin/generos", "Gêneros", "🏷"]],
  },
  {
    title: "Exibição",
    // 4º item = tipo de conteúdo exigido (conta só vê o que o admin liberou)
    items: [
      ["/tv", "TV ao vivo", "📡", "live"],
      ["/filmes", "Filmes", "🎬", "movie"],
      ["/series", "Séries", "📺", "series"],
      ["/animes", "Anime", "✦", "anime"],
    ],
  },
];

const TITLES = {
  "/": "Início",
  "/tv": "TV ao vivo",
  "/filmes": "Filmes",
  "/series": "Séries",
  "/animes": "Anime",
  "/admin": "Dashboard",
  "/admin/cadastro-canais": "Cadastrar listas",
  "/admin/canais": "Itens / canais",
  "/admin/cadastro-vod": "Cadastrar VOD",
  "/admin/catalogo": "Catálogo VOD",
  "/admin/sistema": "Fila de processamento",
  "/admin/generos": "Gêneros",
  "/admin/banco": "Banco de dados",
  "/admin/usuarios": "Cadastro de usuários",
  "/admin/fontes": "Importar fontes",
};

function titleFor(path) {
  if (TITLES[path]) return TITLES[path];
  if (path.startsWith("/assistir")) return "Assistir";
  if (path.startsWith("/genero")) return "Gênero";
  return "MPIPTV";
}

export function Brand({ big = false }) {
  return (
    <span class="flex items-center gap-2">
      <span
        class={
          "flex items-center justify-center rounded-md bg-gradient-to-br from-accent to-accent2 font-bold text-[#041018] " +
          (big ? "h-10 w-10" : "h-8 w-8")
        }
      >
        M
      </span>
      <span class={"font-display font-semibold tracking-tight text-text " + (big ? "text-2xl" : "text-lg")}>
        MP<span class="text-accent">IPTV</span>
      </span>
    </span>
  );
}

export function Layout({ isAdmin, username, role, contentTypes, onLogout, children }) {
  const { path } = useLocation();
  const [open, setOpen] = useState(false);

  // fecha a gaveta sempre que a rota muda (mobile)
  useEffect(() => setOpen(false), [path]);

  const link = ([href, label, icon]) => (
    <Link href={href} class={"nav-link " + (path === href ? "active" : "")}>
      <span class="w-5 text-center text-sm">{icon}</span>
      {label}
    </Link>
  );

  return (
    <div class="min-h-screen bg-bg text-text">
      {/* overlay mobile */}
      {open && <div class="fixed inset-0 z-30 bg-black/60 md:hidden" onClick={() => setOpen(false)} />}

      {/* sidebar */}
      <aside
        class={
          "fixed inset-y-0 left-0 z-40 w-64 transform border-r border-border bg-card transition-transform duration-200 ease-in-out md:translate-x-0 " +
          (open ? "translate-x-0" : "-translate-x-full")
        }
      >
        <div class="flex h-full flex-col">
          <div class="flex items-center gap-2 border-b border-border px-5 py-4">
            <Link href="/">
              <Brand />
            </Link>
          </div>

          <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-3 text-sm thin-scroll">
            {SECTIONS.filter((s) => !s.admin || isAdmin).map((s) => {
              const items = s.items.filter(([, , , kind]) => !kind || isAdmin || !contentTypes || contentTypes.includes(kind));
              if (!items.length) return null;
              return (
                <div class="space-y-1">
                  {s.title && <p class="nav-section">{s.title}</p>}
                  {items.map(link)}
                </div>
              );
            })}
          </nav>

          <div class="border-t border-border p-3">
            <div class="min-w-0 rounded-lg px-3 py-2">
              <p class="truncate text-sm font-medium text-text">{username || "—"}</p>
              <p class="truncate text-xs text-muted">{role === "admin" ? "administrador" : "usuário"}</p>
            </div>
            <div class="mt-1 flex gap-2 px-3">
              <button
                class="text-xs text-muted hover:text-danger"
                onClick={async () => {
                  await adminApi.logout();
                  onLogout?.();
                  navigate("/");
                }}
              >
                Sair
              </button>
            </div>
          </div>
        </div>
      </aside>

      {/* conteúdo */}
      <div class="md:pl-64">
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-border bg-bg/95 px-4 py-3 backdrop-blur md:px-8">
          <button type="button" class="text-muted md:hidden" onClick={() => setOpen(true)} aria-label="Menu">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <h1 class="text-base font-semibold text-text md:text-lg">{titleFor(path)}</h1>
        </header>
        <main class="min-h-[calc(100vh-3.25rem)]">{children}</main>
      </div>
    </div>
  );
}
