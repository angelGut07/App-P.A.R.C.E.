import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.tsx'
import './index.css'
import { AuthProvider } from './controllers/AuthContext'
import { ServiceProvider } from './controllers/ServiceContext'
import { MechanicProvider } from './controllers/MechanicContext'

// Punto de entrada del frontend. Los proveedores comparten el estado global:
// la sesión (AuthProvider) envuelve a los demás porque el servicio en curso
// del cliente (ServiceProvider) y la disponibilidad del mecánico
// (MechanicProvider) dependen de quién inició sesión.
ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <AuthProvider>
      <ServiceProvider>
        <MechanicProvider>
          <App />
        </MechanicProvider>
      </ServiceProvider>
    </AuthProvider>
  </React.StrictMode>,
)
