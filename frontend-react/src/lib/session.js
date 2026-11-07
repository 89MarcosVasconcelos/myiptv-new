import { useState, useEffect, useCallback } from "preact/hooks";
import { adminApi, setToken } from "./api.js";
import { consumeTicketFromUrl } from "./playHost.js";
import { invalidate } from "./useFetch.js";

// login é obrigatório pra tudo agora (não só pro painel admin). /admin/me
// devolve {username, role, token} quando a sessão (cookie) é válida — o
// token de conteúdo já vem resolvido, sem precisar colar link nenhum.
export function useSession() {
  const [s, setS] = useState({ ready: false, authed: false, role: null, token: null, username: null, name: null, contentTypes: null });

  const refresh = useCallback(async () => {
    // login/logout/troca de conta: nada do cache em memória da conta anterior pode sobrar
    invalidate();
    await consumeTicketFromUrl();
    try {
      const me = await adminApi.me();
      if (me.token) setToken(me.token);
      setS({
        ready: true, authed: true, role: me.role, token: me.token, username: me.username,
        name: me.name || null, contentTypes: me.content_types || null,
      });
    } catch {
      setS({ ready: true, authed: false, role: null, token: null, username: null, name: null, contentTypes: null });
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  return { ...s, isAdmin: s.role === "admin", refresh };
}
