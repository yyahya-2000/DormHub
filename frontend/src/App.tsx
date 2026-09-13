import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import { RequireSession } from '@/auth/require-session'
import { SessionProvider } from '@/auth/session-provider'
import { AppShell } from '@/components/app-shell'
import { AuditLogPage } from '@/pages/audit-log-page'
import { BuildingPage } from '@/pages/building-page'
import { BuildingPickerPage } from '@/pages/building-picker-page'
import { LoginPage } from '@/pages/login-page'
import { NotFoundPage } from '@/pages/not-found-page'

/**
 * Two zones: the sign-in screen, and everything behind a token. The guard is
 * routing, not authorisation — each page behind it still asks the API, and the
 * API is what refuses.
 */
export default function App() {
  return (
    <BrowserRouter>
      <SessionProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<RequireSession />}>
            <Route element={<AppShell />}>
              <Route index element={<BuildingPickerPage />} />
              <Route path="buildings/:buildingId" element={<BuildingPage />} />
              <Route path="audit-logs" element={<AuditLogPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </SessionProvider>
    </BrowserRouter>
  )
}
