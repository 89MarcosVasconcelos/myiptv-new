import { useState } from "preact/hooks";
import { adminApi } from "../lib/api.js";
import { Brand } from "./Layout.jsx";

// login obrigatório pra qualquer coisa no site. Visual do "GuestLayout" do
// front anterior: logo centralizado + card com borda e sombra.
export function Login({ onOk }) {
  const [u, setU] = useState("");
  const [p, setP] = useState("");
  const [err, setErr] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setErr("");
    try {
      await adminApi.login(u, p);
      onOk();
    } catch {
      setErr("Usuário ou senha inválidos.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div class="flex min-h-screen flex-col items-center justify-center bg-bg px-4 py-10 text-text">
      <div class="mb-6">
        <Brand big />
      </div>
      <form
        onSubmit={submit}
        class="w-full overflow-hidden rounded-xl border border-border bg-card px-6 py-6 shadow-xl sm:max-w-md"
      >
        <p class="mb-4 text-sm text-muted">Entre com seu usuário e senha para acessar.</p>
        <input
          value={u}
          onInput={(e) => setU(e.currentTarget.value)}
          placeholder="usuário"
          autofocus
          autocomplete="username"
          class="input mb-3"
        />
        <input
          type="password"
          value={p}
          onInput={(e) => setP(e.currentTarget.value)}
          placeholder="senha"
          autocomplete="current-password"
          class="input"
        />
        {err && <p class="mt-2 text-xs text-danger">{err}</p>}
        <button class="btn mt-4 w-full justify-center" disabled={busy}>
          {busy ? "Entrando…" : "Entrar"}
        </button>
      </form>
    </div>
  );
}
