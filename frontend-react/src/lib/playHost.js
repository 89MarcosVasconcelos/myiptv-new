// Modo "play host" (HTTP puro p/ contornar conteúdo misto) DESATIVADO.
//
// O projeto original mandava o usuário pra um subdomínio sem HTTPS quando o
// link do provedor era http://. Aqui o site roda atrás do HTTPS do Coolify e o
// conteúdo misto é resolvido pelo proxy de mídia da própria API
// (/p/<token>/media, com proteção anti-SSRF) — ver lib/hls.js. Mantemos as
// funções com a mesma assinatura só pra não quebrar quem as importa.
export const onPlayHost = () => false;
export const needsPlayHost = () => false;
export async function goPlayHost() {
  throw new Error("play host desativado");
}
export const secureUrl = () => location.origin + location.pathname;
export async function consumeTicketFromUrl() {}
