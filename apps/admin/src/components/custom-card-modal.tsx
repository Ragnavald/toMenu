import { useEffect, useRef, useState } from 'react';
import { API_BASE, loadSession } from '@/lib/api';

const GRADIENTS = [
  { name: 'Laranja / Amarelo (Original)', colors: ['#f59e0b', '#d97706'], css: 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)' },
  { name: 'Roxo Premium', colors: ['#6366f1', '#a855f7'], css: 'linear-gradient(135deg, #6366f1 0%, #a855f7 100%)' },
  { name: 'Azul Celeste', colors: ['#0ea5e9', '#2563eb'], css: 'linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%)' },
  { name: 'Verde Floresta', colors: ['#10b981', '#059669'], css: 'linear-gradient(135deg, #10b981 0%, #059669 100%)' },
  { name: 'Vermelho Cereja', colors: ['#ef4444', '#991b1b'], css: 'linear-gradient(135deg, #ef4444 0%, #991b1b 100%)' },
  { name: 'Cinza Escuro', colors: ['#3f3f46', '#18181b'], css: 'linear-gradient(135deg, #3f3f46 0%, #18181b 100%)' },
];

const PRESET_COLORS = [
  '#18181b', // Zinc
  '#dc2626', // Red
  '#ea580c', // Orange
  '#ca8a04', // Yellow
  '#16a34a', // Green
  '#0d9488', // Teal
  '#2563eb', // Blue
  '#7c3aed', // Purple
];

interface CustomCardModalProps {
  open: boolean;
  onClose: () => void;
  previewUrl: string | null; // QR Code blob URL from parent
  logoUrl: string | null;     // Logo URL from store settings
}

export function CustomCardModal({ open, onClose, previewUrl, logoUrl }: CustomCardModalProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  
  // Customization State
  const [backgroundType, setBackgroundType] = useState<'gradient' | 'color' | 'image'>('gradient');
  const [selectedGradientIdx, setSelectedGradientIdx] = useState<number>(0);
  const [solidColor, setSolidColor] = useState<string>('#18181b');
  const [uploadedBg, setUploadedBg] = useState<string | null>(null);
  const [textColor, setTextColor] = useState<'white' | 'black'>('white');
  const [headline, setHeadline] = useState<string>('CARDÁPIO ONLINE');
  const [subtitle, setSubtitle] = useState<string>('Escaneie e acesse nosso cardápio digital');
  
  // Process State
  const [downloading, setDownloading] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // Sync state with open prop
  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;

    if (open && !dialog.open) {
      dialog.showModal();
    } else if (!open && dialog.open) {
      dialog.close();
    }
  }, [open]);

  if (!open) return null;

  // Converts relative/external image URL to base64 Data URL to prevent canvas tainting (CORS)
  async function imageToDataUrl(url: string): Promise<string | null> {
    try {
      const session = loadSession();
      const headers: Record<string, string> = {};
      if (session) {
        headers['Authorization'] = `Bearer ${session.token}`;
        headers['X-Tenant'] = session.tenantSlug;
      }
      
      const fullUrl = url.startsWith('http') || url.startsWith('//')
        ? url
        : `${API_BASE}${url.startsWith('/') ? '' : '/'}${url}`;

      const response = await fetch(fullUrl, { headers });
      if (!response.ok) return null;
      const blob = await response.blob();
      
      return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(reader.result as string);
        reader.readAsDataURL(blob);
      });
    } catch (err) {
      console.error('Falha ao converter imagem para Data URL:', err);
      return null;
    }
  }

  // Helper to load HTMLImageElement from URL/DataURL
  function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = (e) => reject(e);
      img.src = src;
    });
  }

  // Handle custom background image upload
  function handleBgUpload(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (event) => {
      setUploadedBg(event.target?.result as string);
      setBackgroundType('image');
    };
    reader.readAsDataURL(file);
  }

  // Draw background cover image onto canvas keeping aspect ratio
  function drawCoverImage(ctx: CanvasRenderingContext2D, img: HTMLImageElement, x: number, y: number, w: number, h: number) {
    const imgRatio = img.width / img.height;
    const targetRatio = w / h;
    
    let sx = 0, sy = 0, sw = img.width, sh = img.height;

    if (imgRatio > targetRatio) {
      // Image is wider than target area
      sw = img.height * targetRatio;
      sx = (img.width - sw) / 2;
    } else {
      // Image is taller than target area
      sh = img.width / targetRatio;
      sy = (img.height - sh) / 2;
    }

    ctx.drawImage(img, sx, sy, sw, sh, x, y, w, h);
  }

  // Wrap long text lines in canvas
  function wrapText(ctx: CanvasRenderingContext2D, text: string, x: number, y: number, maxWidth: number, lineHeight: number) {
    const words = text.split(' ');
    let line = '';
    let currentY = y;
    
    for (let n = 0; n < words.length; n++) {
      const testLine = line + words[n] + ' ';
      const metrics = ctx.measureText(testLine);
      const testWidth = metrics.width;
      if (testWidth > maxWidth && n > 0) {
        ctx.fillText(line.trim(), x, currentY);
        line = words[n] + ' ';
        currentY += lineHeight;
      } else {
        line = testLine;
      }
    }
    ctx.fillText(line.trim(), x, currentY);
  }

  async function handleDownload() {
    setDownloading(true);
    setErrorMsg(null);

    try {
      const canvas = document.createElement('canvas');
      canvas.width = 1200;
      canvas.height = 1800;
      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('Falha ao obter contexto 2D do Canvas.');

      // 1. Draw outer white frame
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, 1200, 1800);

      // Inner area sizes (leaves a 40px white border)
      const borderSize = 40;
      const cardWidth = 1200 - borderSize * 2; // 1120
      const cardHeight = 1800 - borderSize * 2; // 1720

      // 2. Draw card background (inside borders)
      if (backgroundType === 'color') {
        ctx.fillStyle = solidColor;
        ctx.fillRect(borderSize, borderSize, cardWidth, cardHeight);
      } else if (backgroundType === 'gradient') {
        const gradientInfo = GRADIENTS[selectedGradientIdx] || GRADIENTS[0];
        const canvasGrad = ctx.createLinearGradient(borderSize, borderSize, borderSize + cardWidth, borderSize + cardHeight);
        canvasGrad.addColorStop(0, gradientInfo.colors[0]);
        canvasGrad.addColorStop(1, gradientInfo.colors[1]);
        ctx.fillStyle = canvasGrad;
        ctx.fillRect(borderSize, borderSize, cardWidth, cardHeight);
      } else if (backgroundType === 'image' && uploadedBg) {
        const bgImg = await loadImage(uploadedBg);
        drawCoverImage(ctx, bgImg, borderSize, borderSize, cardWidth, cardHeight);
      } else {
        // Fallback color
        ctx.fillStyle = '#18181b';
        ctx.fillRect(borderSize, borderSize, cardWidth, cardHeight);
      }

      // 3. Load & Draw Logo (if configured)
      if (logoUrl) {
        const logoDataUrl = await imageToDataUrl(logoUrl);
        if (logoDataUrl) {
          try {
            const logoImg = await loadImage(logoDataUrl);
            
            // Circular Logo centering coordinates
            const cx = 1200 / 2;
            const cy = 290;
            const radius = 100;

            ctx.save();
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI * 2);
            ctx.closePath();
            ctx.clip();
            
            // Draw image inside circle clip
            ctx.drawImage(logoImg, cx - radius, cy - radius, radius * 2, radius * 2);
            ctx.restore();

            // Draw clean white border around logo
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI * 2);
            ctx.closePath();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 6;
            ctx.stroke();
          } catch (logoErr) {
            console.error('Erro ao renderizar logo no canvas:', logoErr);
          }
        }
      }

      // 4. Draw Headline Text
      ctx.fillStyle = textColor === 'white' ? '#ffffff' : '#000000';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = 'bold 74px sans-serif';
      
      const textY = logoUrl ? 510 : 380;
      ctx.fillText(headline.toUpperCase(), 1200 / 2, textY);

      // 5. Draw QR Code Container & Image
      if (previewUrl) {
        const qrContainerX = 1200 / 2 - 270;
        const qrContainerY = 700;
        const qrContainerSize = 540;
        const qrRadius = 32;

        // Draw rounded white background card for QR Code
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        // Custom simple roundRect path for canvas compatibility
        ctx.roundRect(qrContainerX, qrContainerY, qrContainerSize, qrContainerSize, qrRadius);
        ctx.fill();

        // Draw QR Code image inside with padding
        try {
          const qrImg = await loadImage(previewUrl);
          const padding = 50;
          ctx.drawImage(
            qrImg, 
            qrContainerX + padding, 
            qrContainerY + padding, 
            qrContainerSize - padding * 2, 
            qrContainerSize - padding * 2
          );
        } catch (qrErr) {
          console.error('Erro ao carregar QR Code:', qrErr);
        }
      }

      // 6. Draw Bottom Subtitle Text (with auto line wrap)
      ctx.fillStyle = textColor === 'white' ? '#ffffff' : '#000000';
      ctx.font = '600 36px sans-serif';
      
      // Calculate font color opacity or style slightly lighter
      ctx.fillStyle = textColor === 'white' ? 'rgba(255, 255, 255, 0.9)' : 'rgba(0, 0, 0, 0.8)';
      
      const subtitleY = 1450;
      wrapText(ctx, subtitle, 1200 / 2, subtitleY, 800, 52);

      // 7. Download
      const dataUrl = canvas.toDataURL('image/png');
      const downloadLink = document.createElement('a');
      downloadLink.download = `cartao-cardapio-${headline.toLowerCase().replace(/\s+/g, '-')}.png`;
      downloadLink.href = dataUrl;
      downloadLink.click();

    } catch (err) {
      console.error(err);
      setErrorMsg('Ocorreu um erro ao gerar o cartão. Tente novamente.');
    } finally {
      setDownloading(false);
    }
  }

  // Get active background preview style
  function getPreviewBgStyle(): React.CSSProperties {
    if (backgroundType === 'color') {
      return { backgroundColor: solidColor };
    }
    if (backgroundType === 'gradient') {
      const gradient = GRADIENTS[selectedGradientIdx] || GRADIENTS[0];
      return { backgroundImage: gradient.css };
    }
    if (backgroundType === 'image' && uploadedBg) {
      return { backgroundImage: `url(${uploadedBg})`, backgroundSize: 'cover', backgroundPosition: 'center' };
    }
    return { backgroundColor: '#18181b' };
  }

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      onClick={(e) => e.target === dialogRef.current && onClose()}
      className="panel m-auto w-[calc(100%-2rem)] max-w-4xl p-0 backdrop:bg-black/75 shadow-2xl focus:outline-none"
    >
      {/* Title Bar */}
      <div className="flex items-center justify-between border-b border-line px-5 py-4">
        <h2 className="text-sm font-semibold">Criar Cartão Personalizado</h2>
        <button
          type="button"
          onClick={onClose}
          aria-label="Fechar"
          className="grid size-8 place-items-center rounded-lg text-muted transition-colors hover:bg-line/40 focus:outline-none"
        >
          ✕
        </button>
      </div>

      <div className="flex flex-col md:flex-row gap-6 p-6">
        
        {/* Left Side: Real-time Live Preview */}
        <div className="flex flex-col items-center justify-center flex-1">
          <span className="text-xs font-semibold uppercase tracking-wider text-muted mb-3">Prévia do Cartão (Proporção 2:3)</span>
          
          <div className="relative w-full max-w-[320px] aspect-[2/3] bg-white p-3 rounded-2xl shadow-xl border border-line flex flex-col justify-between overflow-hidden">
            
            {/* White border/frame matching inner design */}
            <div 
              style={getPreviewBgStyle()}
              className="absolute inset-3 rounded-xl overflow-hidden flex flex-col items-center justify-between p-4"
            >
              
              {/* Circular Logo Area */}
              {logoUrl ? (
                <div className="size-16 rounded-full overflow-hidden border-2 border-white shadow-md mt-2 flex items-center justify-center bg-white/10 shrink-0">
                  <img src={logoUrl} alt="Logo" className="size-full object-cover" />
                </div>
              ) : (
                <div className="size-16 rounded-full border-2 border-white border-dashed flex items-center justify-center text-[10px] text-white/60 mt-2 shrink-0">
                  Sem Logo
                </div>
              )}

              {/* Title / Headline */}
              <h3 
                className={`text-base font-extrabold tracking-wider uppercase text-center mt-3 line-clamp-2 px-1 ${
                  textColor === 'white' ? 'text-white' : 'text-zinc-950'
                }`}
              >
                {headline || 'CARDÁPIO ONLINE'}
              </h3>

              {/* QR Code Container */}
              <div className="bg-white p-3 rounded-xl shadow-lg w-[65%] aspect-square flex items-center justify-center mt-2">
                {previewUrl ? (
                  <img 
                    src={previewUrl} 
                    alt="QR Code" 
                    className="size-full object-contain [image-rendering:pixelated]" 
                  />
                ) : (
                  <span className="text-[10px] text-zinc-400">QR Code</span>
                )}
              </div>

              {/* Bottom Instruction Subtitle */}
              <p 
                className={`text-[10px] font-semibold text-center mt-3 max-w-[90%] leading-relaxed ${
                  textColor === 'white' ? 'text-white/90' : 'text-zinc-900/90'
                }`}
              >
                {subtitle || 'Escaneie e acesse nosso cardápio digital'}
              </p>
            </div>
          </div>
        </div>

        {/* Right Side: Options & Customization Controls */}
        <div className="flex-1 flex flex-col gap-4 border-t md:border-t-0 md:border-l border-line pt-6 md:pt-0 md:pl-6 max-h-[60vh] overflow-y-auto pr-1">
          
          {/* Section: Text Customization */}
          <div className="flex flex-col gap-3">
            <h4 className="text-xs font-bold text-muted uppercase tracking-wider">Textos do Cartão</h4>
            
            <label className="grid gap-1">
              <span className="text-xs font-medium text-muted">Título Principal</span>
              <input
                type="text"
                value={headline}
                onChange={(e) => setHeadline(e.target.value)}
                maxLength={30}
                placeholder="CARDÁPIO ONLINE"
                className="field"
              />
            </label>

            <label className="grid gap-1">
              <span className="text-xs font-medium text-muted">Instrução Rodapé</span>
              <textarea
                value={subtitle}
                onChange={(e) => setSubtitle(e.target.value)}
                maxLength={80}
                placeholder="Escaneie e acesse nosso cardápio digital"
                className="field min-h-16 resize-none"
              />
            </label>

            <div>
              <span className="text-xs font-medium text-muted block mb-1">Cor dos Textos</span>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setTextColor('white')}
                  className={`flex-1 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${
                    textColor === 'white' ? 'border-accent bg-accent/5 text-accent' : 'border-line hover:bg-line/45'
                  }`}
                >
                  Claro (Branco)
                </button>
                <button
                  type="button"
                  onClick={() => setTextColor('black')}
                  className={`flex-1 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${
                    textColor === 'black' ? 'border-accent bg-accent/5 text-accent' : 'border-line hover:bg-line/45'
                  }`}
                >
                  Escuro (Preto)
                </button>
              </div>
            </div>
          </div>

          <hr className="border-line my-1" />

          {/* Section: Background Options */}
          <div className="flex flex-col gap-3">
            <h4 className="text-xs font-bold text-muted uppercase tracking-wider">Fundo do Cartão</h4>
            
            <div className="flex gap-1.5 rounded-lg border border-line p-1 bg-line/10">
              {(['gradient', 'color', 'image'] as const).map((type) => (
                <button
                  key={type}
                  type="button"
                  onClick={() => setBackgroundType(type)}
                  className={`flex-1 rounded-md py-1.5 text-center text-xs font-medium transition-all ${
                    backgroundType === type
                      ? 'bg-panel shadow-sm text-accent font-semibold'
                      : 'text-muted hover:text-ink'
                  }`}
                >
                  {type === 'gradient' && 'Gradientes'}
                  {type === 'color' && 'Cor Sólida'}
                  {type === 'image' && 'Imagem'}
                </button>
              ))}
            </div>

            {/* Gradient Presets */}
            {backgroundType === 'gradient' && (
              <div className="grid grid-cols-2 gap-2 mt-1">
                {GRADIENTS.map((grad, index) => (
                  <button
                    key={grad.name}
                    type="button"
                    onClick={() => setSelectedGradientIdx(index)}
                    style={{ backgroundImage: grad.css }}
                    className={`h-12 rounded-lg border-2 text-left p-2 flex items-end overflow-hidden transition-all hover:scale-[1.02] ${
                      selectedGradientIdx === index ? 'border-accent shadow-md' : 'border-transparent opacity-85'
                    }`}
                  >
                    <span className="text-[9px] font-bold bg-black/60 text-white px-1.5 py-0.5 rounded leading-none">
                      {grad.name}
                    </span>
                  </button>
                ))}
              </div>
            )}

            {/* Solid Color Customizer */}
            {backgroundType === 'color' && (
              <div className="flex flex-col gap-2 mt-1">
                <div className="flex flex-wrap gap-2">
                  {PRESET_COLORS.map((color) => (
                    <button
                      key={color}
                      type="button"
                      onClick={() => setSolidColor(color)}
                      style={{ backgroundColor: color }}
                      className={`size-8 rounded-full border-2 transition-all hover:scale-110 ${
                        solidColor === color ? 'border-accent scale-105 shadow-md' : 'border-line'
                      }`}
                      aria-label={`Selecionar cor ${color}`}
                    />
                  ))}
                  {/* Custom Color Input */}
                  <label className="relative size-8 rounded-full border-2 border-line overflow-hidden cursor-pointer flex items-center justify-center bg-line/10 hover:scale-110 transition-transform">
                    <span className="text-xs">🎨</span>
                    <input
                      type="color"
                      value={solidColor}
                      onChange={(e) => setSolidColor(e.target.value)}
                      className="absolute inset-0 opacity-0 cursor-pointer"
                    />
                  </label>
                </div>
                <span className="text-[11px] text-muted">Cor selecionada: <code className="font-mono">{solidColor}</code></span>
              </div>
            )}

            {/* Image Upload */}
            {backgroundType === 'image' && (
              <div className="flex flex-col gap-2 mt-1">
                <div className="border-2 border-dashed border-line rounded-lg p-4 text-center bg-line/5 hover:bg-line/10 transition-colors">
                  <input
                    type="file"
                    accept="image/*"
                    onChange={handleBgUpload}
                    className="hidden"
                    id="bg-upload-file"
                  />
                  <label htmlFor="bg-upload-file" className="cursor-pointer flex flex-col items-center gap-1.5">
                    <span className="text-xl">📤</span>
                    <span className="text-xs font-semibold text-accent">Selecionar imagem de fundo</span>
                    <span className="text-[10px] text-muted">PNG, JPG ou WEBP</span>
                  </label>
                </div>
                {uploadedBg && (
                  <div className="flex items-center justify-between p-2 rounded-lg bg-line/20 border border-line text-xs">
                    <span className="truncate max-w-[200px] text-muted">Imagem carregada com sucesso</span>
                    <button
                      type="button"
                      onClick={() => setUploadedBg(null)}
                      className="text-red-500 font-semibold hover:underline"
                    >
                      Remover
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>

          <hr className="border-line mt-auto pt-2" />

          {/* Action Trigger Buttons */}
          <div className="flex flex-col gap-2">
            <button
              type="button"
              onClick={handleDownload}
              disabled={downloading}
              className="w-full rounded-lg bg-accent py-2.5 text-xs font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {downloading ? 'Gerando imagem de alta resolução...' : 'Baixar Cartão (PNG)'}
            </button>
            <p className="text-[10px] text-center text-muted">
              Ideal para impressão em papel cartão, displays de mesa ou cartazes (1200×1800 px).
            </p>
            {errorMsg && (
              <p className="text-xs text-red-500 text-center font-medium mt-1">{errorMsg}</p>
            )}
          </div>

        </div>
      </div>
    </dialog>
  );
}
