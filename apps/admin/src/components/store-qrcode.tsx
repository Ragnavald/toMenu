import { useEffect, useRef, useState } from 'react';
import { API_BASE, ApiError, apiDownload, loadSession } from '@/lib/api';

/**
 * Tamanhos ofertados no download, espelhando a lista fechada do backend
 * (App\Services\StoreQrCode::SIZES). Um valor fora dela não é recusado: o
 * servidor cai no padrão.
 */
const SIZES = [
  { value: 512, label: 'Pequeno', hint: 'Cardápio, folheto' },
  { value: 1024, label: 'Médio', hint: 'Adesivo de mesa, balcão' },
  { value: 2048, label: 'Grande', hint: 'Cartaz, fachada' },
];

const DEFAULT_SIZE = 1024;

/**
 * QR Code do endereço da loja, com prévia e download em PNG.
 *
 * Aparece nos dois planos: o QR abre o cardápio, e no plano somente-cardápio
 * ele é justamente o caminho entre a mesa e o link.
 *
 * A prévia não usa `<img src>` direto na rota da API porque a requisição
 * precisa do bearer token e do X-Tenant — numa navegação de imagem o navegador
 * não envia nenhum dos dois e a rota responderia 401. Por isso a imagem é
 * buscada como blob e exibida por object URL, o mesmo motivo que fez o
 * `apiDownload` existir para o CSV.
 */
export function StoreQrCode({ storefrontUrl }: { storefrontUrl: string }) {
  const [size, setSize] = useState(DEFAULT_SIZE);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);

  /*
   * Guarda o object URL vigente para revogá-lo.
   *
   * Sem o revoke, cada nova prévia deixa o blob anterior retido na memória da
   * aba até o reload — e o de 2048px não é pequeno.
   */
  const objectUrl = useRef<string | null>(null);

  /*
   * A prévia é sempre a menor imagem, independente do tamanho escolhido para o
   * download.
   *
   * O QR é o mesmo desenho em qualquer tamanho: só muda quantos pixels cada
   * módulo ocupa. Buscar o de 2048px para exibir num quadro de 160px seria
   * baixar ~3 KB para jogar fora na hora de redimensionar — e obrigaria uma
   * nova requisição a cada troca de tamanho, que é justamente o que a lista de
   * botões faria o usuário fazer.
   */
  useEffect(() => {
    let cancelled = false;

    async function loadPreview() {
      setLoading(true);
      setError(null);

      try {
        const session = loadSession();

        const response = await fetch(
          `${API_BASE}/api/admin/store/qrcode?size=${SIZES[0].value}`,
          {
            headers: {
              ...(session ? { Authorization: `Bearer ${session.token}` } : {}),
              ...(session ? { 'X-Tenant': session.tenantSlug } : {}),
            },
          },
        );

        if (!response.ok) {
          throw new ApiError('Falha ao gerar o QR Code.', response.status);
        }

        const blob = await response.blob();

        // O componente pode ter desmontado (ou a loja mudado) enquanto o
        // download acontecia; publicar aqui vazaria o object URL.
        if (cancelled) return;

        if (objectUrl.current) URL.revokeObjectURL(objectUrl.current);

        const url = URL.createObjectURL(blob);
        objectUrl.current = url;
        setPreviewUrl(url);
      } catch {
        if (!cancelled) setError('Não foi possível gerar o QR Code.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    loadPreview();

    return () => {
      cancelled = true;
    };
  }, [storefrontUrl]);

  // Revoga o último object URL ao desmontar.
  useEffect(() => {
    return () => {
      if (objectUrl.current) URL.revokeObjectURL(objectUrl.current);
    };
  }, []);

  async function download() {
    setDownloading(true);
    setError(null);

    try {
      await apiDownload(
        `/admin/store/qrcode?size=${size}&download=1`,
        `qrcode-${size}.png`,
      );
    } catch {
      setError('Não foi possível baixar o QR Code.');
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
      {/* Fundo branco fixo: o QR precisa de contraste escuro-sobre-claro, e no
          tema escuro do painel um quadro transparente deixaria a leitura
          instável na prévia. */}
      <div className="flex size-40 shrink-0 items-center justify-center rounded-xl border border-line bg-white p-2">
        {loading ? (
          <span className="text-xs text-muted">Gerando…</span>
        ) : previewUrl ? (
          <img
            src={previewUrl}
            alt={`QR Code para ${storefrontUrl}`}
            /* `pixelated` preserva as bordas dos módulos ao ampliar. A
               interpolação suave do navegador borra o quadriculado e é o que
               mais atrapalha a leitura de uma prévia pequena. */
            className="size-full [image-rendering:pixelated]"
          />
        ) : (
          <span className="px-2 text-center text-xs text-muted">
            QR indisponível
          </span>
        )}
      </div>

      <div className="min-w-0 flex-1">
        <p className="text-xs text-muted">
          Imprima e deixe na mesa, no balcão ou na fachada. Quem apontar a
          câmera abre o seu cardápio direto no celular.
        </p>

        <div className="mt-3 grid gap-1.5">
          <span className="text-xs font-medium text-muted">
            Tamanho do arquivo
          </span>

          <div className="flex flex-wrap gap-2">
            {SIZES.map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => setSize(option.value)}
                aria-pressed={size === option.value}
                title={option.hint}
                className={`rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors ${
                  size === option.value
                    ? 'border-accent bg-accent/5 text-accent'
                    : 'border-line hover:bg-line/40'
                }`}
              >
                {option.label}
              </button>
            ))}
          </div>

          <span className="text-xs text-muted">
            {SIZES.find((option) => option.value === size)?.hint} · PNG{' '}
            {size}×{size}
          </span>
        </div>

        <button
          type="button"
          onClick={download}
          disabled={downloading || loading}
          className="mt-3 rounded-lg border border-line px-3 py-2 text-xs font-semibold transition-colors hover:bg-line/50 disabled:opacity-50"
        >
          {downloading ? 'Baixando…' : 'Baixar PNG'}
        </button>

        {error && (
          <p role="alert" className="mt-2 text-xs text-red-600">
            {error}
          </p>
        )}
      </div>
    </div>
  );
}
