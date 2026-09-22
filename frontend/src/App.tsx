import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import { RequireSession } from '@/auth/require-session'
import { SessionProvider } from '@/auth/session-provider'
import { AppShell } from '@/components/app-shell'
import { AnnouncementPublishPage } from '@/pages/announcement-publish-page'
import { AnnouncementsPage } from '@/pages/announcements-page'
import { AuditLogPage } from '@/pages/audit-log-page'
import { BuildingPage } from '@/pages/building-page'
import { BuildingRegisterPage } from '@/pages/building-register-page'
import { BuildingStaffPage } from '@/pages/building-staff-page'
import { ChangePasswordPage } from '@/pages/change-password-page'
import { CheckpointPage } from '@/pages/checkpoint-page'
import { FloorPage } from '@/pages/floor-page'
import { GuestQueuePage } from '@/pages/guest-queue-page'
import { GuestRequestsPage } from '@/pages/guest-requests-page'
import { HousingPage } from '@/pages/housing-page'
import { LoginPage } from '@/pages/login-page'
import { LostFoundItemPage } from '@/pages/lost-found-item-page'
import { LostFoundPage } from '@/pages/lost-found-page'
import { LostFoundPublishPage } from '@/pages/lost-found-publish-page'
import { MaintenanceQueuePage } from '@/pages/maintenance-queue-page'
import { MaintenanceRequestPage } from '@/pages/maintenance-request-page'
import { MaintenanceRequestsPage } from '@/pages/maintenance-requests-page'
import { NotFoundPage } from '@/pages/not-found-page'
import { NotificationsPage } from '@/pages/notifications-page'
import { ResidentAccountPage } from '@/pages/resident-account-page'
import { ResidentCardPage } from '@/pages/resident-card-page'
import { RoomPage } from '@/pages/room-page'
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
          <Route element={<RequireSession />}>
            {/*
              FR-42's second half, and it is behind the token because the route
              is: the password being replaced is checked against the signed-in
              account's own hash. It is outside the frame of the application
              because an account that still owes a password change is sent here
              and has nothing else to do.
            */}
            <Route path="password" element={<ChangePasswordPage />} />
            <Route element={<AppShell />}>
              <Route index element={<BuildingRegisterPage />} />
              <Route path="buildings" element={<BuildingRegisterPage />} />
              <Route path="buildings/:buildingId" element={<BuildingPage />} />
              {/*
                The housing stock, three screens deep: the floors of the
                building, the rooms of one floor, and one room with its places.
                A room is addressed inside its building because every capability
                over it is held in a building and not in a room.
              */}
              <Route path="buildings/:buildingId/rooms" element={<HousingPage />} />
              <Route path="buildings/:buildingId/rooms/floor/:floor" element={<FloorPage />} />
              <Route path="buildings/:buildingId/rooms/:roomId" element={<RoomPage />} />
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
                for another dormitory's feed. Publication addresses a dormitory
                and reads the same grants, so the form takes no parameter
                either.
              */}
              <Route path="announcements" element={<AnnouncementsPage />} />
              <Route path="announcements/new" element={<AnnouncementPublishPage />} />
              {/*
                The lost-and-found bureau, and none of its three routes takes a
                building. The feed computes the dormitories from the grants of
                the token, exactly as the announcement feed does, so FR-07's
                horizontal boundary here is a missing parameter rather than a
                check somebody has to remember to write. The card and the
                publication form follow it: an entry belongs to the dormitory
                it was published in, and which of the module's three readers is
                looking — a resident who may claim it, the person holding the
                object, the warden settling a refusal — is not a fact about the
                address.
              */}
              <Route path="lost-found" element={<LostFoundPage />} />
              <Route path="lost-found/new" element={<LostFoundPublishPage />} />
              <Route
                path="lost-found/:lostFoundItemId"
                element={<LostFoundItemPage />}
              />
              <Route path="residents/:residentId" element={<ResidentCardPage />} />
              {/*
                The personal account. The route takes no parameter naming a
                person: it is scoped to the account the token belongs to, so
                there is no capability to ask about and no tab to hide —
                everyone who can sign in has one.
              */}
              <Route path="notifications" element={<NotificationsPage />} />
              {/*
                FR-33. No parameter either, and for once not because the token
                narrows the answer: the log is not narrowed at all, and the
                route is the administrator's or a 403.
              */}
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
