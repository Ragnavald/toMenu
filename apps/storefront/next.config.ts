import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Empacota só os arquivos que o build realmente usa, junto de um server.js
  // mínimo. A imagem final roda sem node_modules e sem o CLI do Next — o que
  // importa num droplet pequeno, onde a árvore completa de dependências
  // custaria centenas de MB por imagem.
  output: 'standalone',
};

export default nextConfig;
