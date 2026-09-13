import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import { RequireSession } from '@/auth/require-session'
import { SessionProvider } from '@/auth/session-provider'
import { AppShell } from '@/components/app-shell'
import { AuditLogPage } from '@/pages/audit-log-page'
import { BuildingPage } from '@/pages/building-page'
import { BuildingRegisterPage } from '@/pages/building-register-page'
import { FloorPlanPage } from '@/pages/floor-plan-page'
import { LoginPage } from '@/pages/login-page'
import { NotFoundPage } from '@/pages/not-found-page'
import { ResidentCardPage } from '@/pages/resident-card-page'
import { RoomsPage } from '@/pages/rooms-page'

/**
 * Two zones: the sign-in screen, and everything behind a token. The guard is
 * routing, not authorisation — each page behind it still asks the API, and the
 * API is what refuses.
 *
 * The register of dormitories is both the landing page and a screen of its own,
 * because the list it draws is already scoped by the server: a resident is
 * shown the one building their grant names, the administrator all of them.
 */
export default function App() {
  return (
    <BrowserRouter>
      <SessionProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<RequireSession />}>
            <Route element={<AppShell />}>
              <Route index element={<BuildingRegisterPage />} />
              <Route path="buildings" element={<BuildingRegisterPage />} />
              <Route path="buildings/:buildingId" element={<BuildingPage />} />
              <Route path="buildings/:buildingId/rooms" element={<RoomsPage />} />
              <Route path="buildings/:buildingId/plan" element={<FloorPlanPage />} />
              <Route path="residents/:residentId" element={<ResidentCardPage />} />
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
