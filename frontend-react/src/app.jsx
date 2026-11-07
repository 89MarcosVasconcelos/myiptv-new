import { useSession } from "./lib/session.js";
import { Switch, Route, navigate } from "./lib/router.jsx";
import { useEffect } from "preact/hooks";
import { Layout } from "./components/Layout.jsx";
import { Login } from "./components/Login.jsx";
import { lazy } from "./lib/lazy.jsx";
import { Home } from "./pages/Home.jsx";
import { Watch } from "./pages/Watch.jsx";
import { VodList } from "./pages/VodList.jsx";
import { LiveTV } from "./pages/LiveTV.jsx";
import { Stub } from "./pages/Stub.jsx";

// tipo de conteúdo exigido por tela (a conta só entra no que o admin liberou)
const ROUTE_KIND = { "/tv": "live", "/filmes": "movie", "/series": "series", "/animes": "anime" };
const VOD_KINDS = ["movie", "series", "anime"];

function Redirect({ to }) {
  useEffect(() => navigate(to, { replace: true }), [to]);
  return null;
}

const AdminApp = lazy(() => import("./pages/admin/AdminApp.jsx"), "AdminApp");

export function App() {
  const session = useSession();

  if (!session.ready) {
    return <div class="min-h-screen grid place-items-center text-muted text-sm">Carregando…</div>;
  }
  if (!session.authed) {
    return <Login onOk={session.refresh} />;
  }

  const types = session.isAdmin ? null : session.contentTypes; // null = tudo liberado
  const can = (kind) => !types || types.includes(kind);
  const canAnyVod = VOD_KINDS.some(can);
  // 1ª tela permitida (destino quando a conta abre uma rota que não é dela)
  const home = Object.keys(ROUTE_KIND).find((r) => can(ROUTE_KIND[r])) || "/";
  const gate = (ok, node) => (ok ? node : <Redirect to={home} />);

  return (
    <Layout
      isAdmin={session.isAdmin}
      username={session.name || session.username}
      role={session.role}
      contentTypes={session.contentTypes}
      onLogout={session.refresh}
    >
      <Switch fallback={<Stub />}>
        <Route path="/" component={Home} />
        <Route
          path="/assistir/:tipo/:id"
          render={({ params }) => gate(params.tipo === "canal" ? can("live") : canAnyVod, <Watch params={params} />)}
        />
        <Route path="/tv" render={() => gate(can("live"), <LiveTV />)} />
        <Route path="/filmes" render={() => gate(can("movie"), <VodList mode="movie" />)} />
        <Route path="/series" render={() => gate(can("series"), <VodList mode="series" />)} />
        <Route path="/animes" render={() => gate(can("anime"), <VodList mode="anime" />)} />
        <Route
          path="/genero/:nome"
          render={({ params }) => gate(canAnyVod, <VodList mode="genre" params={params} />)}
        />
        <Route path="/admin/*" render={() => gate(session.isAdmin, <AdminApp role={session.role} />)} />
      </Switch>
    </Layout>
  );
}
