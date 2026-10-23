import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.tsx'
import './index.css'
import { AuthProvider } from './controllers/AuthContext'
import { ServiceProvider } from './controllers/ServiceContext'
import { MechanicProvider } from './controllers/MechanicContext'

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
