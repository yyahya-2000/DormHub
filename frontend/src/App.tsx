import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import { RequireSession } from '@/auth/require-session'
import { SessionProvider } from '@/auth/session-provider'
import { AppShell } from '@/components/app-shell'
import { AnnouncementPublishPage } from '@/pages/announcement-publish-page'
import { AnnouncementReadersPage } from '@/pages/announcement-readers-page'
import { AnnouncementsPage } from '@/pages/announcements-page'
import { AuditLogPage } from '@/pages/audit-log-page'
import { BuildingPage } from '@/pages/building-page'
import { BuildingRegisterPage } from '@/pages/building-register-page'
import { BuildingStaffPage } from '@/pages/building-staff-page'
import { CheckpointPage } from '@/pages/checkpoint-page'
import { ConsentHistoryPage } from '@/pages/consent-history-page'
import { ConsentPage } from '@/pages/consent-page'
import { FloorPlanPage } from '@/pages/floor-plan-page'
import { GuestQueuePage } from '@/pages/guest-queue-page'
import { GuestRequestsPage } from '@/pages/guest-requests-page'
import { LoginPage } from '@/pages/login-page'
import { MaintenanceQueuePage } from '@/pages/maintenance-queue-page'
import { MaintenanceRequestPage } from '@/pages/maintenance-request-page'
import { MaintenanceRequestsPage } from '@/pages/maintenance-requests-page'
import { NotFoundPage } from '@/pages/not-found-page'
import { NotificationSettingsPage } from '@/pages/notification-settings-page'
import { NotificationsPage } from '@/pages/notifications-page'
import { ResidentAccountPage } from '@/pages/resident-account-page'
import { ResidentCardPage } from '@/pages/resident-card-page'
import { RoomsPage } from '@/pages/rooms-page'
import { SetPasswordPage } from '@/pages/set-password-page'
import { VisitRegisterPage } from '@/pages/visit-register-page'

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
            {/*
              The consent of FR-35 is behind the token and outside the frame of
              the application, and both halves of that are deliberate. Behind
              the token, because a consent belongs to an account. Outside the
              frame, because art. 9 part 1 of Federal Law No. 152-FZ has consent
              «executed separately from other documents» — a row of section tabs
              above the text would put the document among the screens of a
              system instead of leaving it a document being signed.
            */}
            <Route path="consent" element={<ConsentPage />} />
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
              {/*
                The guest module. Two of the three screens are addressed by
                building, because the queue and the register belong to one
                dormitory and the capability is held in one. The third is not:
                the post terminal takes its building from the officer's own
                grant, so that nothing on a screen facing a lobby depends on
                what is in the address bar.
              */}
              <Route
                path="buildings/:buildingId/guest-requests"
                element={<GuestQueuePage />}
              />
              <Route
                path="buildings/:buildingId/visit-register"
                element={<VisitRegisterPage />}
              />
              <Route path="guests" element={<GuestRequestsPage />} />
              <Route path="checkpoint" element={<CheckpointPage />} />
              {/*
                The maintenance module, and the same split for the same reason.
                The queue is addressed by building, because it belongs to one
                dormitory and the capability is held in one. The resident's own
                list is not: the route filters by the identifier of the token,
                so there is no parameter by which one person could name another.
                The card sits outside both — a request is read by the person who
                filed it and by the staff who triage in that dormitory, and
                which of the two is looking is not a fact about the address.
              */}
              <Route
                path="buildings/:buildingId/maintenance"
                element={<MaintenanceQueuePage />}
              />
              <Route path="maintenance" element={<MaintenanceRequestsPage />} />
              <Route
                path="maintenance/:maintenanceRequestId"
                element={<MaintenanceRequestPage />}
              />
              {/*
                The announcement feed takes no building either, and there the
                absence is the horizontal boundary itself (FR-07): the audience
                is computed from the grants of the token, so no address can ask
                for another dormitory's feed. The readers report is addressed by
                announcement and narrowed by the server to the caller's own
                dormitory.
              */}
              <Route path="announcements" element={<AnnouncementsPage />} />
              <Route path="announcements/new" element={<AnnouncementPublishPage />} />
              <Route
                path="announcements/:announcementId/readers"
                element={<AnnouncementReadersPage />}
              />
              <Route path="residents/:residentId" element={<ResidentCardPage />} />
              {/*
                The personal account. Neither route takes a parameter naming a
                person: both are scoped to the account the token belongs to, so
                there is no capability to ask about and no tab to hide —
                everyone who can sign in has one.
              */}
              <Route path="notifications" element={<NotificationsPage />} />
              <Route
                path="notifications/settings"
                element={<NotificationSettingsPage />}
              />
              <Route path="consents" element={<ConsentHistoryPage />} />
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
