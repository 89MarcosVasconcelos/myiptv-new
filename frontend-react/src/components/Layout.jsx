import { useState, useEffect } from "preact/hooks";
import { Link, useLocation, navigate } from "../lib/router.jsx";
import { adminApi, api, setAdultPin, hasAdultPin } from "../lib/api.js";

// Layout do projeto MPTV (herdado do front Laravel/Vue anterior): sidebar
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
  return "MPTV";
}

// Marca "MPTV": uma TV com antena, tela com o M de MP e o botão play (o P/TV).
// Mesmo desenho de public/favicon.svg.
export function BrandMark({ class: cls = "h-8 w-8" }) {
  return (
    <svg class={cls} viewBox="6 4 52 53" aria-hidden="true">
      <defs>
        <linearGradient id="mptv-g" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#00d9ff" />
          <stop offset="1" stop-color="#2f6fff" />
        </linearGradient>
      </defs>
      <path d="M32 15 L21 6 M32 15 L43 6" stroke="url(#mptv-g)" stroke-width="3.6" stroke-linecap="round" fill="none" />
      <rect x="6" y="15" width="52" height="42" rx="11" fill="url(#mptv-g)" />
      <path d="M15 45 V28 L23 38 L31 28 V45" stroke="#041018" stroke-width="4.4" stroke-linecap="round" stroke-linejoin="round" fill="none" />
      <path d="M39 29 V44 L52 36.5 Z" fill="#041018" stroke="#041018" stroke-width="3" stroke-linejoin="round" />
    </svg>
  );
}

export function Brand({ big = false }) {
  return (
    <span class="flex items-center gap-2">
      <BrandMark class={big ? "h-11 w-11" : "h-9 w-9"} />
      <span class={"font-display font-bold tracking-tight text-text " + (big ? "text-3xl" : "text-xl")}>
        MP<span class="text-accent">TV</span>
      </span>
    </span>
  );
}

// Cadeado do conteúdo adulto: só aparece pra conta que o admin liberou. A senha
// fica apenas na memória da aba -- recarregar a página bloqueia de novo.
function AdultLock({ onChange }) {
  const [allowed, setAllowed] = useState(false);
  const [open, setOpen] = useState(false);
  const [pin, setPin] = useState("");
  const [err, setErr] = useState("");
  const [busy, setBusy] = useState(false);
  const unlocked = hasAdultPin();

  useEffect(() => {
    let alive = true;
    api.adultStatus().then((s) => alive && setAllowed(!!s.allowed)).catch(() => {});
    return () => {
      alive = false;
    };
  }, []);

  if (!allowed) return null;

  async function submit(e) {
    e.preventDefault();
    setErr("");
    setBusy(true);
    try {
      await api.adultUnlock(pin);
      setAdultPin(pin);
      setPin("");
      setOpen(false);
      onChange();
    } catch (e2) {
      setErr(e2.message);
    } finally {
      setBusy(false);
    }
  }

  function lock() {
    setAdultPin(null);
    onChange();
  }

  return (
    <div class="relative ml-auto">
      {unlocked ? (
        <button type="button" class="chip active" onClick={lock} title="Bloquear de novo">
          🔓 Adulto liberado · Bloquear
        </button>
      ) : (
        <button type="button" class="chip" onClick={() => setOpen(true)}>
          🔒 Adulto
        </button>
      )}
      {open && (
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/60 px-4" onClick={() => setOpen(false)}>
          <form class="panel w-full max-w-xs p-5" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
            <p class="mb-3 text-sm font-semibold">Conteúdo adulto</p>
            <input
              class="input w-full"
              type="password"
              inputMode="numeric"
              autoComplete="off"
              autoFocus
              placeholder="Senha"
              value={pin}
              onInput={(e) => setPin(e.currentTarget.value)}
              data-testid="adult-pin"
            />
            {err && <p class="mt-2 text-xs text-danger">{err}</p>}
            <div class="mt-3 flex gap-2">
              <button class="btn" type="submit" disabled={busy || !pin}>
                Liberar
              </button>
              <button class="btn-ghost" type="button" onClick={() => setOpen(false)}>
                Cancelar
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}

export function Layout({ isAdmin, username, role, contentTypes, onLogout, children }) {
  const { path } = useLocation();
  const [open, setOpen] = useState(false);
  const [adultKey, setAdultKey] = useState(0); // muda ao liberar/bloquear: refaz as listagens

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
          <AdultLock onChange={() => setAdultKey((k) => k + 1)} />
        </header>
        <main key={adultKey} class="min-h-[calc(100vh-3.25rem)]">{children}</main>
      </div>
    </div>
  );
}
