import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { initTheme } from '@/lib/theme'
import './index.css'
import App from './App.tsx'

// Aplica o tema antes do primeiro paint — evita flash de tema errado.
initTheme();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
