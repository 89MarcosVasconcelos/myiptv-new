import { useState, useEffect } from "preact/hooks";
import { adminApi } from "../../lib/api.js";
import { Panel, inp } from "./CsvImport.jsx";

const KINDS = [
  { id: "live", label: "TV ao vivo" },
  { id: "movie", label: "Filmes" },
  { id: "series", label: "Séries" },
  { id: "anime", label: "Anime" },
];
const KIND_LABEL = Object.fromEntries(KINDS.map((k) => [k.id, k.label]));

// multi-select em "chips": clique liga/desliga cada tipo de conteúdo
function KindPicker({ value, onChange, disabled }) {
  const toggle = (id) => onChange(value.includes(id) ? value.filter((x) => x !== id) : [...value, id]);
  return (
    <div class="flex flex-wrap gap-2">
      {KINDS.map((k) => (
        <button
          type="button"
          disabled={disabled}
          aria-pressed={value.includes(k.id)}
          class={"chip " + (value.includes(k.id) ? "active" : "")}
          onClick={() => toggle(k.id)}
        >
          {value.includes(k.id) ? "✓ " : ""}
          {k.label}
        </button>
      ))}
    </div>
  );
}

const EMPTY = { name: "", email: "", password: "", role: "user", content_types: ["live", "movie", "series", "anime"] };

// cadastro de contas — só o admin logado chega aqui (não existe registro público)
export function AdminUsers() {
  const [users, setUsers] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [err, setErr] = useState("");
  const [ok, setOk] = useState("");
  const [busy, setBusy] = useState(false);

  const load = () => adminApi.users().then((r) => setUsers(r.users)).catch(() => {});
  useEffect(() => {
    load();
  }, []);

  async function submit(e) {
    e.preventDefault();
    setErr("");
    setOk("");
    if (form.role === "user" && !form.content_types.length) return setErr("Escolha pelo menos um tipo de conteúdo.");
    setBusy(true);
    try {
      await adminApi.createUser(form);
      setOk(`Usuário ${form.name} cadastrado.`);
      setForm(EMPTY);
      load();
    } catch (e2) {
      setErr(e2.message || "Falha ao cadastrar.");
    } finally {
      setBusy(false);
    }
  }

  async function remove(u) {
    if (!confirm(`Apagar o usuário "${u.name || u.username}"? Ele perde o acesso imediatamente.`)) return;
    await adminApi.deleteUser(u.id).catch((e) => alert(e.message));
    load();
  }

  async function toggleAdult(u) {
    await adminApi.setUserAdultContent(u.id, !u.sees_adult_content).catch((e) => alert(e.message));
    load();
  }

  async function changeKinds(u, types) {
    if (!types.length) return alert("O usuário precisa ter pelo menos um tipo de conteúdo.");
    setUsers((list) => list.map((x) => (x.id === u.id ? { ...x, content_types: types } : x)));
    await adminApi.setUserContentTypes(u.id, types).catch((e) => {
      alert(e.message);
      load();
    });
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.currentTarget.value });

  return (
    <div class="p-4 md:p-8 max-w-3xl">
      <h1 class="text-lg text-accent font-display mb-1">Cadastro de usuários</h1>
      <p class="text-xs text-muted mb-4">
        Só você, logado como administrador, cadastra contas — não existe registro público.
      </p>

      <Panel title="Novo usuário">
        <form onSubmit={submit} class="space-y-3">
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="block text-[11px] text-muted mb-1">Nome</label>
              <input class={inp} value={form.name} onInput={set("name")} placeholder="Nome completo" required />
            </div>
            <div>
              <label class="block text-[11px] text-muted mb-1">E-mail (também é o login)</label>
              <input
                class={inp}
                type="email"
                value={form.email}
                onInput={set("email")}
                placeholder="pessoa@email.com"
                autocomplete="off"
                required
              />
            </div>
            <div>
              <label class="block text-[11px] text-muted mb-1">Senha (mín. 6 caracteres)</label>
              <input
                class={inp}
                type="text"
                value={form.password}
                onInput={set("password")}
                autocomplete="new-password"
                minLength={6}
                required
              />
            </div>
            <div>
              <label class="block text-[11px] text-muted mb-1">Papel</label>
              <select class={inp} value={form.role} onChange={set("role")}>
                <option value="user">Usuário (só assiste)</option>
                <option value="admin">Admin (assiste + painel)</option>
              </select>
            </div>
          </div>

          {form.role === "user" ? (
            <div>
              <label class="block text-[11px] text-muted mb-1">Pode assistir (selecione um ou mais)</label>
              <KindPicker value={form.content_types} onChange={(v) => setForm({ ...form, content_types: v })} />
            </div>
          ) : (
            <p class="text-xs text-muted">Contas admin veem todos os tipos de conteúdo.</p>
          )}

          <div class="flex items-center gap-3 flex-wrap">
            <button class="btn" disabled={busy}>
              {busy ? "Cadastrando…" : "Cadastrar usuário"}
            </button>
            {err && <span class="text-danger text-xs">{err}</span>}
            {ok && <span class="text-ok text-xs">{ok}</span>}
          </div>
        </form>
      </Panel>

      <Panel title={`Contas (${users?.length ?? "…"})`}>
        {!users ? (
          <div class="text-muted text-sm">Carregando…</div>
        ) : (
          <div class="space-y-2">
            {users.map((u) => (
              <div class="rounded-lg border border-border p-3 text-sm space-y-2">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium">{u.name || u.username}</span>
                  <span
                    class={
                      "text-[11px] px-1.5 py-0.5 rounded " +
                      (u.role === "admin" ? "bg-accent2/20 text-accent" : "bg-white/10 text-muted")
                    }
                  >
                    {u.role === "admin" ? "admin" : "usuário"}
                  </span>
                  {u.email && <span class="text-xs text-muted">{u.email}</span>}
                  <button class="ml-auto text-xs text-muted hover:text-danger" onClick={() => remove(u)}>
                    apagar
                  </button>
                </div>
                {u.content_types ? (
                  <div>
                    <div class="text-[11px] text-muted mb-1">Pode assistir</div>
                    <KindPicker value={u.content_types} onChange={(v) => changeKinds(u, v)} />
                  </div>
                ) : (
                  <div class="text-[11px] text-muted">Vê todos os tipos de conteúdo ({Object.values(KIND_LABEL).join(", ")})</div>
                )}
                {u.token && (
                  <label class="flex items-center gap-1.5 text-[11px] text-muted cursor-pointer">
                    <input type="checkbox" checked={u.sees_adult_content} onChange={() => toggleAdult(u)} />
                    vê conteúdo adulto
                  </label>
                )}
              </div>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}
