import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import { RequireSession } from '@/auth/require-session'
import { SessionProvider } from '@/auth/session-provider'
import { AppShell } from '@/components/app-shell'
import { AuditLogPage } from '@/pages/audit-log-page'
import { BuildingPage } from '@/pages/building-page'
import { BuildingRegisterPage } from '@/pages/building-register-page'
import { BuildingStaffPage } from '@/pages/building-staff-page'
import { FloorPlanPage } from '@/pages/floor-plan-page'
import { LoginPage } from '@/pages/login-page'
import { NotFoundPage } from '@/pages/not-found-page'
import { ResidentAccountPage } from '@/pages/resident-account-page'
import { ResidentCardPage } from '@/pages/resident-card-page'
import { RoomsPage } from '@/pages/rooms-page'
import { SetPasswordPage } from '@/pages/set-password-page'

/**
 * Two zones: what an anonymous browser may reach, and everything behind a
 * token. The first holds two screens rather than one: signing in, and setting a
 * first password with the one-time code of FR-42 — an account that has never
 * had a password cannot sign in to be let at the form that would give it one.
 *
 * The guard is routing, not authorisation — each page behind it still asks the
 * API, and the API is what refuses.
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
          <Route path="/set-password" element={<SetPasswordPage />} />
          <Route element={<RequireSession />}>
            <Route element={<AppShell />}>
              <Route index element={<BuildingRegisterPage />} />
              <Route path="buildings" element={<BuildingRegisterPage />} />
              <Route path="buildings/:buildingId" element={<BuildingPage />} />
              <Route path="buildings/:buildingId/rooms" element={<RoomsPage />} />
              <Route path="buildings/:buildingId/plan" element={<FloorPlanPage />} />
              <Route path="buildings/:buildingId/staff" element={<BuildingStaffPage />} />
              <Route
                path="buildings/:buildingId/accounts"
                element={<ResidentAccountPage />}
              />
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
