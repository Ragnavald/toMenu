import { render, screen, fireEvent, act } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';
import { PwaInstallPrompt } from './PwaInstallPrompt';

// Mock do componente Image do Next.js
vi.mock('next/image', () => ({
  default: (props: any) => {
    // eslint-disable-next-line @next/next/no-img-element
    return <img {...props} />;
  },
}));

describe('PwaInstallPrompt Component', () => {
  const mockRegister = vi.fn();

  beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    mockRegister.mockReset().mockResolvedValue({ scope: '/' });

    // Mock Service Worker
    Object.defineProperty(navigator, 'serviceWorker', {
      writable: true,
      configurable: true,
      value: {
        register: mockRegister,
      },
    });

    // Mock matchMedia
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      configurable: true,
      value: vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  // Função auxiliar para mudar o User Agent usando spyOn do Vitest
  const setUserAgent = (userAgent: string) => {
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue(userAgent);
  };

  it('deve registrar o Service Worker no carregamento', () => {
    render(<PwaInstallPrompt />);

    // Simula o evento load da página
    act(() => {
      window.dispatchEvent(new Event('load'));
    });

    expect(mockRegister).toHaveBeenCalledWith('/sw.js');
  });

  it('não deve mostrar o banner por padrão', () => {
    render(<PwaInstallPrompt />);
    expect(screen.queryByText('Instalar ToMenu')).not.toBeInTheDocument();
  });

  describe('Instalação em Android/Chrome (beforeinstallprompt)', () => {
    let mockPrompt: any;
    let mockEvent: any;

    beforeEach(() => {
      mockPrompt = vi.fn().mockResolvedValue(undefined);
      mockEvent = new Event('beforeinstallprompt');
      (mockEvent as any).prompt = mockPrompt;
      (mockEvent as any).userChoice = Promise.resolve({ outcome: 'accepted' });
    });

    it('deve exibir o banner quando o evento beforeinstallprompt for disparado', () => {
      render(<PwaInstallPrompt />);
      
      act(() => {
        window.dispatchEvent(mockEvent);
      });

      expect(screen.getByText('Instalar ToMenu')).toBeInTheDocument();
      expect(screen.getByText('Adicionar')).toBeInTheDocument();
      expect(screen.getByText('Agora não')).toBeInTheDocument();
    });

    it('deve chamar o prompt nativo ao clicar em Adicionar', async () => {
      render(<PwaInstallPrompt />);
      
      act(() => {
        window.dispatchEvent(mockEvent);
      });

      const addButton = screen.getByText('Adicionar');
      
      // O prompt é assíncrono, então resolvemos a Promise choice do evento
      await act(async () => {
        fireEvent.click(addButton);
      });

      expect(mockPrompt).toHaveBeenCalled();
      expect(screen.queryByText('Instalar ToMenu')).not.toBeInTheDocument();
    });

    it('deve fechar o banner e guardar no localStorage ao clicar em Agora não', () => {
      render(<PwaInstallPrompt />);
      
      act(() => {
        window.dispatchEvent(mockEvent);
      });

      const dismissButton = screen.getByText('Agora não');
      act(() => {
        fireEvent.click(dismissButton);
      });

      expect(localStorage.getItem('pwa-install-dismissed')).toBe('true');
      expect(screen.queryByText('Instalar ToMenu')).not.toBeInTheDocument();
    });

    it('não deve exibir o banner se já foi dispensado anteriormente', () => {
      localStorage.setItem('pwa-install-dismissed', 'true');
      render(<PwaInstallPrompt />);
      
      act(() => {
        window.dispatchEvent(mockEvent);
      });

      expect(screen.queryByText('Instalar ToMenu')).not.toBeInTheDocument();
    });
  });

  describe('Instalação em iOS Safari', () => {
    const iosUserAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1';

    it('deve exibir instruções do iOS após um delay se for iPhone e rodar em Safari', () => {
      setUserAgent(iosUserAgent);
      
      render(<PwaInstallPrompt />);

      // Adianta o temporizador em 3 segundos (configurado no useEffect)
      act(() => {
        vi.advanceTimersByTime(3000);
      });

      expect(screen.getByText('Instalar ToMenu')).toBeInTheDocument();
      expect(screen.getByText('Instruções de instalação:')).toBeInTheDocument();
      expect(screen.getByText('Entendi')).toBeInTheDocument();
    });

    it('deve fechar o banner de instruções do iOS ao clicar em Entendi e salvar no localStorage', () => {
      setUserAgent(iosUserAgent);
      
      render(<PwaInstallPrompt />);
      
      act(() => {
        vi.advanceTimersByTime(3000);
      });

      const okButton = screen.getByText('Entendi');
      act(() => {
        fireEvent.click(okButton);
      });

      expect(localStorage.getItem('pwa-install-dismissed')).toBe('true');
      expect(screen.queryByText('Instalar ToMenu')).not.toBeInTheDocument();
    });

    it('não deve exibir instruções do iOS se o app já estiver rodando em standalone', () => {
      setUserAgent(iosUserAgent);
      
      // Mock do modo standalone do iOS
      Object.defineProperty(window.navigator, 'standalone', {
        value: true,
        configurable: true,
      });

      render(<PwaInstallPrompt />);
      
      act(() => {
        vi.advanceTimersByTime(3000);
      });

      expect(screen.queryByText('Instalar ToMenu')).not.toBeInTheDocument();
    });
  });
});
