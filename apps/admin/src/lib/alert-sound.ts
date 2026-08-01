/**
 * Alerta sonoro de pedido novo.
 *
 * O som é sintetizado com a Web Audio API em vez de tocar um arquivo: são dois
 * bipes curtos que atravessam o barulho de cozinha, sem custo de rede e sem um
 * binário no repositório.
 *
 * O navegador bloqueia áudio até o usuário interagir com a página. Por isso o
 * AudioContext só é criado em `unlock()`, chamado a partir de um clique — criar
 * antes produziria um contexto suspenso que nunca emite som.
 */

let context: AudioContext | null = null;

/** Um AudioContext criado fora de gesto do usuário nasce suspenso. */
export function isUnlocked(): boolean {
  return context !== null && context.state === 'running';
}

/**
 * Libera o áudio. Precisa ser chamado de dentro de um handler de evento de
 * interação (clique, tecla) — é a condição que a política de autoplay exige.
 */
export async function unlock(): Promise<void> {
  context ??= new AudioContext();

  // Já existia mas o navegador suspendeu (aba em segundo plano, por exemplo).
  if (context.state === 'suspended') await context.resume();
}

/** Um bipe. `at` é o tempo absoluto na linha do tempo do AudioContext. */
function beep(ctx: AudioContext, at: number, duration: number): void {
  const oscillator = ctx.createOscillator();
  const gain = ctx.createGain();

  oscillator.type = 'sine';
  oscillator.frequency.value = 880;

  // Rampa em vez de valor fixo: o corte seco de um ganho constante produz um
  // clique audível no início e no fim da nota.
  gain.gain.setValueAtTime(0, at);
  gain.gain.linearRampToValueAtTime(0.3, at + 0.01);
  gain.gain.linearRampToValueAtTime(0, at + duration);

  oscillator.connect(gain).connect(ctx.destination);
  oscillator.start(at);
  oscillator.stop(at + duration);
}

/**
 * Toca o alerta. Silencioso se o áudio ainda não foi liberado — quem avisa o
 * usuário disso é a interface, não uma exceção aqui.
 */
export function playOrderAlert(): void {
  if (!context || context.state !== 'running') return;

  const now = context.currentTime;

  beep(context, now, 0.18);
  beep(context, now + 0.25, 0.18);
}
